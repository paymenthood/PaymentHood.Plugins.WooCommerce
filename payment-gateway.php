<?php

/**
 * Plugin Name: PaymentHood
 * Plugin URI:  https://paymenthood.com
 * Author:      PaymentHood
 * Description: Pay with confidence. Paymenthood handles the rest.
 * Version:     1.0.0
 */

function paymenthood_bootstrap_debug_enabled()
{
	return defined('PAYMENTHOOD_DEBUG')
		? (bool) PAYMENTHOOD_DEBUG
		: (defined('WP_DEBUG') && WP_DEBUG);
}

function paymenthood_log_bootstrap($message, $level = 'info', $context = array())
{
	if (in_array($level, array('debug', 'info'), true) && !paymenthood_bootstrap_debug_enabled()) {
		return;
	}

	$context = array_merge(
		array('source' => 'paymenthood'),
		$context
	);

	if (function_exists('wc_get_logger')) {
		wc_get_logger()->log($level, $message, $context);
		return;
	}

	error_log(
		sprintf(
			'[paymenthood][%s] %s %s',
			strtoupper($level),
			$message,
			empty($context) ? '' : wp_json_encode($context)
		)
	);
}

register_shutdown_function(function () {
	$error = error_get_last();

	if ($error === null) {
		return;
	}

	$fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);

	if (!in_array($error['type'], $fatal_types, true)) {
		return;
	}

	paymenthood_log_bootstrap('Plugin shutdown after fatal error', 'error', array(
		'type' => $error['type'],
		'file' => $error['file'],
		'line' => $error['line'],
		'message' => $error['message'],
	));
});

paymenthood_log_bootstrap('Plugin file loaded');

// Enable WC block feature for plugin
add_action('before_woocommerce_init', function () {
	if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			__FILE__,
			true
		);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
});

// Add PaymentHood to WC payment gateways
add_filter('woocommerce_payment_gateways', 'paymenthood_add_gateway_class');
function paymenthood_add_gateway_class($gateways)
{
	$gateways[] = 'PaymentHood_Gateway';
	return $gateways;
}

// Add Blocks support for PaymentHood
add_action('woocommerce_blocks_loaded', function () {
	if (!class_exists('WC_PaymentHood_Blocks')) {
		require_once plugin_dir_path(__FILE__) . 'includes/class-wc-paymenthood-gateway-blocks-support.php';
	}

	add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
		if (
			class_exists('\\Automattic\\WooCommerce\\Blocks\\Payments\\PaymentMethodRegistry') &&
			class_exists('\\Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')
		) {

			$registry->register(new WC_PaymentHood_Blocks());
		}
	});
});

require_once plugin_dir_path(__FILE__) . 'includes/class-paymenthood-app-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-paymenthood-payment-service.php';

// Initialize PaymentHood plugin
add_action('plugins_loaded', 'paymenthood_init_gateway_class');
function paymenthood_init_gateway_class()
{
	paymenthood_log_bootstrap('plugins_loaded hook reached');

	if (!class_exists('WC_Payment_Gateway')) {
		paymenthood_log_bootstrap('WooCommerce payment gateway base class is not available yet', 'warning');
		return;
	}

	class PaymentHood_Gateway extends WC_Payment_Gateway
	{
		protected string $paymenthood_panel_url = "https://black-cliff-07cabf01e.7.azurestaticapps.net";
		protected string $paymenthood_payment_app_api_url = "https://payment-app-service-stage-eje7arhbe0crhpcu.westeurope-01.azurewebsites.net";
		protected string $paymenthood_payment_api_url = "https://payment-service-stage-baauhwa0ghbjdzc7.westeurope-01.azurewebsites.net";
		protected string $app_id;
		protected string $token;
		protected string $webhook_token;
		protected string $hosted_page_id;
		protected PaymentHood_App_Service $app_service;
		protected PaymentHood_Payment_Service $payment_service;

		public function __construct()
		{
			$this->id = 'paymenthood';
			$this->has_fields = false; // In case you need a custom credit card form
			$this->method_title = 'PaymentHood';
			$this->method_description = 'Pay with confidence. Paymenthood handles the rest.';
			$this->icon = $this->get_logo_url();

			// Gateways can support subscriptions, refunds, saved payment methods
			$this->supports = array(
				'products',
				'refunds'
			);

			$this->init_form_fields();
			$this->init_settings(); // Load the settings

			$this->title = $this->get_option('title');
			$this->description = $this->get_option('description');
			$this->enabled = $this->get_option('enabled');

			$this->testmode = 'yes' === $this->get_option('testmode');
			$this->app_id = $this->testmode ? $this->get_option('sandbox_app_id') : $this->get_option('live_app_id');
			$this->token = $this->testmode ? $this->get_option('sandbox_token') : $this->get_option('live_token');
			$this->webhook_token = $this->testmode ? $this->get_option('sandbox_webhook_token') : $this->get_option('live_webhook_token');
			$this->hosted_page_id = $this->testmode ? $this->get_option('sandbox_hosted_page_id') : $this->get_option('live_hosted_page_id');
			$service_logger = function ($message, $level = 'info', $context = array()) {
				$this->log($message, $level, $context);
			};
			$this->app_service = new PaymentHood_App_Service($this->paymenthood_payment_app_api_url, $service_logger);
			$this->payment_service = new PaymentHood_Payment_Service($this->paymenthood_payment_api_url, $service_logger);

			// Hook for saving settings
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'flush_checkout_methods_cache'), 20);
			add_action('admin_init', array($this, 'guard_paymenthood_enable_route'));
			add_action('admin_init', array($this, 'maybe_auto_enable_for_completed_setup'));
			add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
			add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_assets'));
			add_action('wp_ajax_paymenthood_provider_icon', array($this, 'handle_provider_icon_proxy'));
			add_action('wp_ajax_nopriv_paymenthood_provider_icon', array($this, 'handle_provider_icon_proxy'));

			// Hooks for handling webhooks
			add_action('woocommerce_api_paymenthood_setup', array($this, 'setup_handler'));
			add_action('woocommerce_api_payment_webhook', array($this, 'payment_webhook_handler'));

			// Admin notice on successful setup
			add_action('admin_notices', array($this, 'render_admin_notices'));

			// Handles payment state and order status on order received (thank you) page
			add_action('woocommerce_thankyou', array($this, 'thankyou_page_handler'));

			// Add sandbox badge to checkout title
			if ($this->testmode) {
				add_filter('woocommerce_gateway_title', array($this, 'add_sandbox_badge_to_title'), 10, 2);
			}
		}

		protected function get_active_environment_label(): string
		{
			return $this->testmode ? 'sandbox' : 'live';
		}

		protected function flush_checkout_methods_cache(string $sandbox_app_id = '', string $live_app_id = ''): void
		{
			$settings = get_option('woocommerce_' . $this->id . '_settings', array());

			$ids_to_flush = array_filter(array_unique(array(
				$sandbox_app_id,
				$live_app_id,
				$settings['sandbox_app_id'] ?? '',
				$settings['live_app_id'] ?? '',
			)));

			foreach ($ids_to_flush as $app_id) {
				delete_transient('paymenthood_profile_checkout_methods_' . md5($app_id));
			}

			$this->log('Checkout methods cache flushed', 'info', array('app_ids' => array_values($ids_to_flush)));
		}

		protected function get_missing_active_environment_settings(): array
		{
			$missing = array();

			if ($this->app_id === '') {
				$missing[] = 'app_id';
			}

			if ($this->token === '') {
				$missing[] = 'token';
			}

			return $missing;
		}

		protected function get_missing_environment_settings(string $environment): array
		{
			$settings = get_option('woocommerce_' . $this->id . '_settings', array());
			$prefix = $environment === 'live' ? 'live' : 'sandbox';
			$missing = array();

			if (empty($settings[$prefix . '_app_id'])) {
				$missing[] = 'app ID';
			}

			if (empty($settings[$prefix . '_token'])) {
				$missing[] = 'token';
			}

			return $missing;
		}

		public function render_admin_notices()
		{
			if (!$this->is_paymenthood_settings_page()) {
				return;
			}

			if (
				isset($_GET['paymenthood_setup']) &&
				$_GET['paymenthood_setup'] === 'success'
			) {
				include plugin_dir_path(__FILE__) . 'templates/admin-notice-success.php';
			}
		}

		public function is_available()
		{
			if (!parent::is_available()) {
				return false;
			}

			$missing = $this->get_missing_active_environment_settings();

			if (!empty($missing)) {
				$this->log('PaymentHood is unavailable because checkout configuration is incomplete', 'warning', array(
					'environment' => $this->get_active_environment_label(),
					'missing' => $missing,
				));

				return false;
			}

			return true;
		}

		public function needs_setup()
		{
			$settings = get_option('woocommerce_' . $this->id . '_settings', array());

			return empty($settings['sandbox_app_id'])
				|| empty($settings['sandbox_token'])
				|| empty($settings['live_app_id'])
				|| empty($settings['live_token']);
		}

		/**
		 * Signals to WooCommerce's Payments UI that the account is connected.
		 * WooCommerce uses this to decide whether to show an Enable toggle (not connected)
		 * or a Manage button (connected). When setup is complete, the account is connected.
		 */
		public function is_account_connected(): bool
		{
			return !$this->needs_setup();
		}

		/**
		 * Auto-enables the gateway the first time setup is complete so the WooCommerce
		 * Payments list shows Manage instead of Enable.
		 * Runs once and sets a flag so merchant manual changes are respected after that.
		 */
		public function maybe_auto_enable_for_completed_setup()
		{
			if ($this->needs_setup()) {
				return;
			}

			if (get_option('paymenthood_setup_auto_enabled', false)) {
				return;
			}

			$option_key = 'woocommerce_' . $this->id . '_settings';
			$settings = get_option($option_key, array());

			if (!is_array($settings)) {
				$settings = array();
			}

			update_option('paymenthood_setup_auto_enabled', '1');

			$this->log('Setup completion recorded (gateway not auto-enabled)', 'info');
		}

		// Plugin options
		public function init_form_fields()
		{
			$return_url = home_url('/?wc-api=paymenthood_setup');
			$setup_url = $this->paymenthood_panel_url . '/auth/signin?returnUrl=' . urlencode($return_url) . '&grantAuthorization=' . urlencode('true');

			$saved_settings = get_option('woocommerce_' . $this->id . '_settings', array());
			$sandbox_app_id = $saved_settings['sandbox_app_id'] ?? '';
			$sandbox_token = $saved_settings['sandbox_token'] ?? '';
			$live_app_id = $saved_settings['live_app_id'] ?? '';
			$live_token = $saved_settings['live_token'] ?? '';

			$setup_completed =
				!(empty($sandbox_app_id)) &&
				!(empty($sandbox_token)) &&
				!(empty($live_app_id)) &&
				!(empty($live_token));

			$this->form_fields = array(
				'general_section' => array(
					'title' => 'Checkout display',
					'type' => 'title',
				),
				'enabled' => array(
					'title' => 'Availability',
					'type' => 'checkbox',
					'label' => 'Enable PaymentHood at checkout',
					'default' => 'no'
				),
				'title' => array(
					'title' => 'Checkout title',
					'type' => 'text',
					'description' => 'Shown to customers in the list of payment methods during checkout.',
					'default' => 'PaymentHood',
					'desc_tip' => true,
				),
				'description' => array(
					'title' => 'Checkout description',
					'type' => 'textarea',
					'description' => 'Short helper text shown under the payment method title at checkout.',
					'default' => 'Simply pay via our payment gateway.',
					'css' => 'min-height: 90px;',
				),
				'setup_status' => array(
					'title' => 'Account connection',
					'type' => 'paymenthood_setup_status',
					'setup_completed' => $setup_completed,
					'setup_url' => $setup_url,
				),
				'supported_gateways' => array(
					'title' => 'Supported gateways',
					'type' => 'paymenthood_supported_gateways',
				),
			);

			if ($setup_completed) {
				$this->form_fields['app_setup'] = array(
					'title' => 'Gateway setup',
					'type' => 'paymenthood_app_setup',
					'sandbox_app_id' => $sandbox_app_id,
					'sandbox_token' => $sandbox_token,
					'live_app_id' => $live_app_id,
					'live_token' => $live_token,
				);

				$this->form_fields['testmode'] = array(
					'title' => 'Active environment',
					'type' => 'checkbox',
					'label' => 'Use sandbox credentials for checkout',
					'default' => 'yes',
					'description' => 'Keep this enabled while testing. When you are ready to run production payments, uncheck it to use your live configuration.',
				);
			}
		}

		public function generate_paymenthood_setup_status_html($key, $data)
		{
			$field_key = $this->get_field_key($key);
			$logo_url = $this->get_logo_url();
			$defaults = array(
				'title' => '',
				'setup_completed' => false,
				'setup_url' => '',
			);
			$data = wp_parse_args($data, $defaults);

			$button_text = $data['setup_completed'] ? 'Reconnect PaymentHood' : 'Complete setup';
			$hero_title = $data['setup_completed']
				? 'PaymentHood is connected and ready'
				: 'PaymentHood checkout is currently hidden';

			ob_start();
			?>
			<tr valign="top">
				<th scope="row" class="titledesc">
					<label for="<?php echo esc_attr($field_key); ?>"><?php echo esc_html($data['title']); ?></label>
				</th>
				<td class="forminp">
					<div class="paymenthood-settings-card">
						<div class="paymenthood-settings-card__compact">
							<div class="paymenthood-brand-lockup">
								<?php if ($logo_url) : ?>
									<img class="paymenthood-brand-lockup__logo" src="<?php echo esc_url($logo_url); ?>" alt="PaymentHood logo">
								<?php else : ?>
									<div class="paymenthood-brand-lockup__mark" aria-hidden="true">PH</div>
								<?php endif; ?>
							</div>
							<div class="paymenthood-settings-card__compact-content">
								<h2 class="paymenthood-settings-card__compact-title"><?php echo esc_html($hero_title); ?></h2>
							</div>
						</div>
						<?php if (!$data['setup_completed']) : ?>
							<div class="paymenthood-settings-card__actions paymenthood-settings-card__actions--below-banner">
								<a class="button button-primary paymenthood-settings-card__button" href="<?php echo esc_url($data['setup_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($button_text); ?></a>
							</div>
						<?php endif; ?>
					</div>
				</td>
			</tr>
			<?php

			return ob_get_clean();
		}

		public function validate_paymenthood_setup_status_field($key, $value)
		{
			return '';
		}

		public function validate_paymenthood_supported_gateways_field($key, $value)
		{
			return '';
		}

		public function validate_paymenthood_gateway_actions_field($key, $value)
		{
			return '';
		}

		public function validate_paymenthood_app_setup_field($key, $value)
		{
			return '';
		}

		public function generate_paymenthood_app_setup_html($key, $data)
		{
			$field_key = $this->get_field_key($key);
			$defaults = array(
				'title' => '',
				'sandbox_app_id' => '',
				'sandbox_token' => '',
				'live_app_id' => '',
				'live_token' => '',
			);
			$data = wp_parse_args($data, $defaults);

			$sandbox_details = $this->app_service->get_app_details($data['sandbox_app_id'], $data['sandbox_token']);
			$live_details = $this->app_service->get_app_details($data['live_app_id'], $data['live_token']);

			$environments = array(
				'sandbox' => array(
					'label' => 'Sandbox',
					'app_id' => $data['sandbox_app_id'],
					'details' => $sandbox_details,
					'css_mod' => 'sandbox',
				),
				'live' => array(
					'label' => 'Live',
					'app_id' => $data['live_app_id'],
					'details' => $live_details,
					'css_mod' => 'live',
				),
			);

			ob_start();
			?>
			<tr valign="top">
				<th scope="row" class="titledesc">
					<label for="<?php echo esc_attr($field_key); ?>"><?php echo esc_html($data['title']); ?></label>
				</th>
				<td class="forminp">
					<div class="paymenthood-env-cards">
						<?php foreach ($environments as $env_key => $env) : ?>
							<?php
							$details = $env['details'];
							$has_details = !empty($details);
							$is_setup_completed = $has_details && !empty($details['isConnectFirstGateway']);
							$friendly_name = $has_details ? ($details['friendlyName'] ?? $env['app_id']) : $env['app_id'];
							$status_mod = $is_setup_completed ? 'complete' : 'pending';

							if ($is_setup_completed) {
								$action_label = 'Manage gateways in ' . $env['label'];
								$action_url = rtrim($this->paymenthood_panel_url, '/') . '/' . rawurlencode($env['app_id']) . '/gateways';
								$action_class = 'button button-secondary';
								$status_text = 'Setup complete';
							} else {
								$action_label = 'PaymentHood Setup Complete &#8212; ' . $env['label'];
								$action_url = rtrim($this->paymenthood_panel_url, '/') . '/' . rawurlencode($env['app_id']);
								$action_class = 'button button-primary';
								$status_text = 'Setup incomplete';
							}
							?>
							<div class="paymenthood-env-card paymenthood-env-card--<?php echo esc_attr($env['css_mod']); ?> paymenthood-env-card--<?php echo esc_attr($status_mod); ?>">
								<div class="paymenthood-env-card__header">
									<span class="paymenthood-env-card__badge"><?php echo esc_html($env['label']); ?></span>
									<span class="paymenthood-env-card__status-dot" aria-hidden="true"></span>
								</div>
								<?php if ($has_details) : ?>
								<div class="paymenthood-env-card__body">
									<div class="paymenthood-env-card__status-text">
										<?php if ($is_setup_completed) : ?>
											<svg class="paymenthood-env-card__status-icon" width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="6" cy="6" r="6" fill="#22c55e"/><path d="M3.5 6l2 2 3-3" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
										<?php else : ?>
											<svg class="paymenthood-env-card__status-icon" width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="6" cy="6" r="6" fill="#f59e0b"/><path d="M6 3.5v3M6 8.5h.01" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/></svg>
										<?php endif; ?>
										<?php echo esc_html($status_text); ?>
									</div>
								</div>
								<div class="paymenthood-env-card__footer">
									<a class="<?php echo esc_attr($action_class); ?> paymenthood-env-card__action" href="<?php echo esc_url($action_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo wp_kses($action_label, array()); ?></a>
								</div>
								<?php else : ?>
								<div class="paymenthood-env-card__body paymenthood-env-card__error">
									Unable to load app details. Please check your connection.
								</div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</td>
			</tr>
			<?php

			return ob_get_clean();
		}

		public function generate_paymenthood_supported_gateways_html($key, $data)
		{
			$field_key = $this->get_field_key($key);
			$defaults = array(
				'title' => '',
			);
			$data = wp_parse_args($data, $defaults);
			$supported_methods = $this->get_admin_all_providers_for_display();

			ob_start();
			?>
			<tr valign="top">
				<th scope="row" class="titledesc">
					<label for="<?php echo esc_attr($field_key); ?>"><?php echo esc_html($data['title']); ?></label>
				</th>
				<td class="forminp">
					<div class="paymenthood-supported-gateways-row">
						<?php if (!empty($supported_methods)) : ?>
							<?php $this->render_supported_provider_chips($supported_methods, 'paymenthood-provider-strip--grid'); ?>
						<?php else : ?>
							<p class="paymenthood-supported-gateways-row__empty">No provider icons are available from PaymentHood yet.</p>
						<?php endif; ?>
					</div>
				</td>
			</tr>
			<?php

			return ob_get_clean();
		}

		public function generate_paymenthood_gateway_actions_html($key, $data)
		{
			$field_key = $this->get_field_key($key);
			$defaults = array(
				'title' => '',
				'manage_sandbox_url' => '',
				'manage_live_url' => '',
			);
			$data = wp_parse_args($data, $defaults);

			ob_start();
			?>
			<tr valign="top">
				<th scope="row" class="titledesc">
					<label for="<?php echo esc_attr($field_key); ?>"><?php echo esc_html($data['title']); ?></label>
				</th>
				<td class="forminp">
					<div class="paymenthood-settings-actions">
						<a class="button button-secondary paymenthood-settings-actions__button" href="<?php echo esc_url($data['manage_sandbox_url']); ?>" target="_blank" rel="noopener noreferrer" title="Manage Sandbox Gateways in PaymentHood Console">Manage Sandbox Gateways</a>
						<a class="button button-secondary paymenthood-settings-actions__button" href="<?php echo esc_url($data['manage_live_url']); ?>" target="_blank" rel="noopener noreferrer" title="Manage Live Gateways in PaymentHood Console">Manage Live Gateways</a>
					</div>
				</td>
			</tr>
			<?php

			return ob_get_clean();
		}

		protected function get_logo_url()
		{
			$logo_candidates = array(
				'assets/images/paymenthood-blue.png',
				'assets/images/paymenthood-logo.svg',
				'assets/images/paymenthood-logo.png',
				'assets/images/paymenthood.webp',
			);

			foreach ($logo_candidates as $relative_path) {
				$absolute_path = plugin_dir_path(__FILE__) . $relative_path;

				if (file_exists($absolute_path)) {
					return plugins_url($relative_path, __FILE__);
				}
			}

			return '';
		}

		protected function get_provider_icon_proxy_url(string $icon_url): string
		{
			if ($icon_url === '' || !$this->is_allowed_provider_icon_url($icon_url)) {
				return $icon_url;
			}

			$payload = rtrim(strtr(base64_encode($icon_url), '+/', '-_'), '=');

			return add_query_arg(
				array(
					'action' => 'paymenthood_provider_icon',
					'url' => $payload,
				),
				admin_url('admin-ajax.php')
			);
		}

		protected function get_provider_icon_data_uri(string $icon_url): string
		{
			if ($icon_url === '' || !$this->is_allowed_provider_icon_url($icon_url)) {
				return '';
			}

			$cache_key = 'paymenthood_icon_proxy_' . md5($icon_url);
			$cached = get_transient($cache_key);

			if (is_array($cached) && !empty($cached['content_type']) && isset($cached['body'])) {
				return 'data:' . $cached['content_type'] . ';base64,' . $cached['body'];
			}

			$response = wp_remote_get(
				$icon_url,
				array(
					'timeout' => 20,
					'redirection' => 3,
				)
			);

			$status_code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);
			$content_type = (string) wp_remote_retrieve_header($response, 'content-type');

			if (is_wp_error($response) || $status_code !== 200 || $body === '') {
				$this->log('Provider icon data URI request failed', 'warning', array(
					'icon_url' => $icon_url,
					'status_code' => $status_code,
				));

				return '';
			}

			$content_type = $this->detect_provider_icon_content_type($icon_url, $content_type, $body);
			$encoded_body = base64_encode($body);

			set_transient(
				$cache_key,
				array(
					'content_type' => $content_type,
					'body' => $encoded_body,
				),
				6 * HOUR_IN_SECONDS
			);

			return 'data:' . $content_type . ';base64,' . $encoded_body;
		}

		protected function is_allowed_provider_icon_url(string $icon_url): bool
		{
			$parts = wp_parse_url($icon_url);
			$scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
			$host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';

			if ($scheme !== 'https' || $host === '') {
				return false;
			}

			return (bool) preg_match('/(^|\.)blob\.core\.windows\.net$/', $host);
		}

		protected function detect_provider_icon_content_type(string $icon_url, string $content_type, string $body): string
		{
			$normalized_content_type = strtolower(trim(strtok($content_type, ';')));

			if (strpos($normalized_content_type, 'image/') === 0) {
				return $normalized_content_type;
			}

			$leading_content = ltrim(substr($body, 0, 256));

			if (stripos($leading_content, '<svg') !== false || preg_match('/\.svg(?:$|\?)/i', $icon_url)) {
				return 'image/svg+xml';
			}

			$path = wp_parse_url($icon_url, PHP_URL_PATH);
			$extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));

			switch ($extension) {
				case 'png':
					return 'image/png';
				case 'jpg':
				case 'jpeg':
					return 'image/jpeg';
				case 'gif':
					return 'image/gif';
				case 'webp':
					return 'image/webp';
			}

			return 'application/octet-stream';
		}

		public function handle_provider_icon_proxy()
		{
			$encoded_url = isset($_GET['url']) ? sanitize_text_field(wp_unslash($_GET['url'])) : '';
			$padding = strlen($encoded_url) % 4;

			if ($padding > 0) {
				$encoded_url .= str_repeat('=', 4 - $padding);
			}

			$icon_url = base64_decode(strtr($encoded_url, '-_', '+/'), true);

			if (!is_string($icon_url) || $icon_url === '' || !$this->is_allowed_provider_icon_url($icon_url)) {
				status_header(400);
				exit;
			}

			$cache_key = 'paymenthood_icon_proxy_' . md5($icon_url);
			$cached = get_transient($cache_key);

			if (is_array($cached) && !empty($cached['content_type']) && isset($cached['body'])) {
				status_header(200);
				header('Content-Type: ' . $cached['content_type']);
				header('Cache-Control: public, max-age=21600');
				echo base64_decode($cached['body']);
				exit;
			}

			$response = wp_remote_get(
				$icon_url,
				array(
					'timeout' => 20,
					'redirection' => 3,
				)
			);

			$status_code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);
			$content_type = (string) wp_remote_retrieve_header($response, 'content-type');

			if (is_wp_error($response) || $status_code !== 200 || $body === '') {
				$this->log('Provider icon proxy request failed', 'warning', array(
					'icon_url' => $icon_url,
					'status_code' => $status_code,
				));
				status_header(404);
				exit;
			}

			$content_type = $this->detect_provider_icon_content_type($icon_url, $content_type, $body);
			set_transient(
				$cache_key,
				array(
					'content_type' => $content_type,
					'body' => base64_encode($body),
				),
				6 * HOUR_IN_SECONDS
			);

			status_header(200);
			header('Content-Type: ' . $content_type);
			header('Cache-Control: public, max-age=21600');
			echo $body;
			exit;
		}

		public function add_sandbox_badge_to_title($title, $gateway_id)
		{
			if ($gateway_id !== $this->id) {
				return $title;
			}

			return esc_html($title) . ' <span class="paymenthood-sandbox-badge paymenthood-sandbox-badge--inline">Sandbox</span>';
		}

		public function payment_fields()
		{
			$supported_methods = $this->get_supported_checkout_methods_for_display();
			?>
			<div class="paymenthood-checkout-card paymenthood-checkout-card--classic">
				<?php if (!empty($supported_methods)) : ?>
					<?php $this->render_supported_provider_chips($supported_methods); ?>
				<?php endif; ?>
			</div>
			<?php
		}

		protected function render_supported_provider_chips(array $supported_methods, string $strip_class = ''): void
		{
			if (empty($supported_methods)) {
				return;
			}
			$strip_classes = trim('paymenthood-provider-strip ' . $strip_class);
			?>
			<div class="<?php echo esc_attr($strip_classes); ?>" aria-label="Supported payment providers">
				<?php foreach ($supported_methods as $method) : ?>
					<?php if (($method['type'] ?? '') === 'credit_card') : ?>
						<div class="paymenthood-provider-chip paymenthood-provider-chip--card" title="Credit card">
							<span class="paymenthood-provider-chip__card-icon" aria-hidden="true"></span>
							<span class="paymenthood-provider-chip__label">Credit card</span>
						</div>
					<?php else : ?>
						<?php
						$label = (string) ($method['label'] ?? '');
						$icon_url = !empty($method['icon_light']) ? (string) $method['icon_light'] : (string) ($method['icon_dark'] ?? '');
						if ($label === '' && $icon_url === '') {
							continue;
						}
						?>
						<div class="paymenthood-provider-chip" title="<?php echo esc_attr($label); ?>">
							<?php if ($icon_url !== '') : ?>
								<img class="paymenthood-provider-chip__logo"
									src="<?php echo esc_url($icon_url); ?>"
									alt="<?php echo esc_attr($label); ?>"
									width="84" height="26"
									onerror="this.style.display='none';var lb=this.parentNode.querySelector('.paymenthood-provider-chip__label');if(lb)lb.style.display='';">
							<?php endif; ?>
							<span class="paymenthood-provider-chip__label" <?php if ($icon_url !== '') : ?>style="display:none"<?php endif; ?>><?php echo esc_html($label); ?></span>
						</div>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
			<?php
		}

		public function get_supported_checkout_methods_for_display()
		{
			$methods = $this->app_service->get_payment_profiles_checkout_methods(
				$this->app_id,
				$this->token
			);

			foreach ($methods as &$method) {
				if (($method['type'] ?? '') === 'credit_card') {
					continue;
				}
				if (!empty($method['icon_light'])) {
					$method['icon_light'] = $this->get_provider_icon_proxy_url($method['icon_light']);
				}
				if (!empty($method['icon_dark'])) {
					$method['icon_dark'] = $this->get_provider_icon_proxy_url($method['icon_dark']);
				}
			}
			unset($method);

			return $methods;
		}

		protected function get_admin_all_providers_for_display(): array
		{
			$providers = $this->app_service->get_all_providers(false);
			$display_methods = array();

			foreach ($providers as $provider) {
				$title = isset($provider['title']) ? (string) $provider['title'] : '';
				$icon_light = isset($provider['icon_light']) ? (string) $provider['icon_light'] : '';
				$icon_dark = isset($provider['icon_dark']) ? (string) $provider['icon_dark'] : '';

				if ($title === '' || ($icon_light === '' && $icon_dark === '')) {
					continue;
				}

				$display_methods[] = array(
					'type' => 'provider_hosted_page',
					'label' => $title,
					'icon_light' => $icon_light,
					'icon_dark' => $icon_dark,
				);
			}

			return $display_methods;
		}

		protected function get_admin_provider_methods_for_display(bool $force_provider_refresh = false): array
		{
			$methods = $this->get_supported_checkout_methods_for_display();

			if (!empty($methods)) {
				return $methods;
			}

			return $this->get_admin_all_providers_for_display();
		}

		/**
		 * Returns available PaymentHood providers for display in the WooCommerce Payments list.
		 * WooCommerce's PaymentGateway provider reads this method and renders the icons.
		 * The React component shows the first ~5 icons and collapses the rest into a "+N" badge.
		 *
		 * @param string $country_code Optional ISO 3166-1 alpha-2 country code (unused, included for interface compatibility).
		 * @return array
		 */
		public function get_recommended_payment_methods(string $country_code = ''): array
		{
			// Must return empty array. WooCommerce's React Payments list uses a non-empty
			// recommended_payment_methods array as a signal to show the "WooPayments update
			// required" modal when the account is not connected — a WooPayments-exclusive
			// flow that must not apply to third-party gateways like PaymentHood.
			// Provider icons are injected independently via enqueue_payments_list_provider_logos().
			return array();
		}

		public function enqueue_admin_assets()
		{
			if (!$this->is_paymenthood_settings_page() && !$this->is_paymenthood_payments_list_page()) {
				return;
			}

			wp_enqueue_style(
				'paymenthood-admin-settings',
				plugins_url('assets/css/paymenthood-admin.css', __FILE__),
				array(),
				'1.0.8'
			);

			if ($this->is_paymenthood_payments_list_page()) {
				$this->enqueue_payments_list_provider_logos();
			}
		}

		protected function enqueue_payments_list_provider_logos()
		{
			$methods = $this->get_admin_provider_methods_for_display();

			$js_providers = array();
			$seen = array();

			foreach ($methods as $method) {
				$title = isset($method['label']) ? (string) $method['label'] : '';
				$icon = !empty($method['icon_light']) ? $method['icon_light'] : ($method['icon_dark'] ?? '');
				$icon = $this->get_provider_icon_proxy_url($icon);

				if ($title === '' || $icon === '' || isset($seen[$title])) {
					continue;
				}

				$seen[$title] = true;
				$js_providers[] = array(
					'title' => $title,
					'icon'  => $icon,
				);
			}

			$this->log('Payments list provider strip', 'info', array(
				'methods_count'   => count($methods),
				'providers_count' => count($js_providers),
			));

			$providers_json = wp_json_encode($js_providers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT);
			$max_visible = 5;
			$is_testmode = $this->testmode ? 'true' : 'false';

			$script = <<<JS
(function () {
	var providers = {$providers_json};
	var MAX_VISIBLE = {$max_visible};
	var isTestMode = {$is_testmode};
	console.log('[PaymentHood] provider strip script loaded with', providers.length, 'providers');

	function buildStrip() {
		var wrap = document.createElement('div');
		wrap.className = 'paymenthood-provider-strip-admin';

		var envBadge = document.createElement('span');
		envBadge.className = isTestMode
			? 'paymenthood-env-badge paymenthood-env-badge--sandbox'
			: 'paymenthood-env-badge paymenthood-env-badge--live';
		envBadge.textContent = isTestMode ? 'Test Mode' : 'Live';
		wrap.appendChild(envBadge);

		var visible = providers.slice(0, MAX_VISIBLE);
		var overflow = providers.length - MAX_VISIBLE;

		visible.forEach(function (p) {
			var img = document.createElement('img');
			img.src = p.icon;
			img.alt = p.title;
			img.title = p.title;
			img.className = 'paymenthood-provider-strip-admin__icon';
			wrap.appendChild(img);
		});

		if (overflow > 0) {
			var badge = document.createElement('span');
			badge.className = 'paymenthood-provider-strip-admin__overflow';
			badge.textContent = '+' + overflow;
			wrap.appendChild(badge);
		}

		return wrap;
	}

	function findPaymenthoodRow() {
		var row = document.getElementById('paymenthood');

		if (row) {
			return row;
		}

		var settingsLink = document.querySelector('a[href*="page=wc-settings"][href*="section=paymenthood"]');

		if (settingsLink) {
			return settingsLink.closest('.woocommerce-item__payment-gateway, .woocommerce-list__item');
		}

		return null;
	}

	function attachStrip() {
		var row = findPaymenthoodRow();

		if (!row) {
			return;
		}

		// Re-check on every mutation: if React replaced the row, our flag is gone.
		if (row.querySelector(':scope > .woocommerce-list__item-inner .paymenthood-provider-strip-admin')) {
			return;
		}

		var desc = row.querySelector('.woocommerce-list__item-content');

		if (!desc || !desc.parentNode) {
			return;
		}

		desc.parentNode.insertBefore(buildStrip(), desc.nextSibling);
		console.log('[PaymentHood] provider strip attached');
	}

	attachStrip();

	var observer = new MutationObserver(attachStrip);
	observer.observe(document.body, { childList: true, subtree: true });
})();
JS;

			wp_enqueue_script('jquery');
			wp_add_inline_script('jquery', $script, 'after');
		}

		public function enqueue_checkout_assets()
		{
			if (!function_exists('is_checkout')) {
				return;
			}

			$is_checkout = is_checkout();
			$is_checkout_pay = function_exists('is_checkout_pay_page') && is_checkout_pay_page();

			if (!$is_checkout && !$is_checkout_pay) {
				return;
			}

			wp_enqueue_style(
				'paymenthood-checkout',
				plugins_url('assets/css/paymenthood-checkout.css', __FILE__),
				array(),
				'1.0.3'
			);
		}

		protected function is_paymenthood_settings_page()
		{
			$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
			$tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '';
			$section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';

			return $page === 'wc-settings'
				&& in_array($tab, array('checkout', 'payment-gateways'), true)
				&& $section === $this->id;
		}

		protected function is_paymenthood_payments_list_page()
		{
			$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
			$tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '';
			$section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';

			return $page === 'wc-settings'
				&& in_array($tab, array('checkout', 'payment-gateways'), true)
				&& $section === '';
		}

		protected function is_paymenthood_enable_route()
		{
			$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
			$tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '';
			$section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';

			return $page === 'wc-settings'
				&& in_array($tab, array('checkout', 'payment-gateways'), true)
				&& $section === $this->id
				&& isset($_GET['toggle_enabled']);
		}

		public function guard_paymenthood_enable_route()
		{
			if (!$this->is_paymenthood_enable_route()) {
				return;
			}

			$this->log('Blocked PaymentHood enable toggle route from WooCommerce payments list', 'warning', array(
				'page' => 'wc-settings',
				'tab' => isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '',
			));

			$redirect_url = remove_query_arg('toggle_enabled');

			if (!empty($redirect_url) && wp_safe_redirect($redirect_url)) {
				exit;
			}
		}

		public function setup_handler()
		{
			// Read params
			$license_id = isset($_GET['licenseId']) ? wp_unslash($_GET['licenseId']) : '';
			$authorization_code = isset($_GET['authorizationCode']) ? wp_unslash($_GET['authorizationCode']) : '';

			$this->log('Starting setup process. LicenseId: ' . $license_id, 'info');

			// Validate params
			if (empty($authorization_code) || empty($license_id)) {
				$this->log('LicenseId or AuthorizationCode is empty', 'error');

				$this->paymenthood_render_admin_error_page(
					'missing_parameters',
					'authorizationCode and licenseId are required'
				);
				exit;
			}

			// Save license_id to DB
			$option_key = 'woocommerce_' . $this->id . '_settings';
			$settings = get_option($option_key, array());

			if (!is_array($settings)) {
				$settings = array();
			}

			$settings['license_id'] = $license_id;
			update_option($option_key, $settings);

			$this->log('LicenseId saved', 'info');

			// Get token
			$app_detail = $this->app_service->get_token(
				$license_id,
				sanitize_text_field($authorization_code)
			);

			if (is_wp_error($app_detail)) {
				$this->paymenthood_render_admin_error_page(
					$app_detail->get_error_code(),
					$app_detail->get_error_message()
				);
				exit;
			}

			// Save app details to DB
			$settings['sandbox_app_id'] = sanitize_text_field($app_detail['sandbox_app_id']);
			$settings['sandbox_token'] = sanitize_text_field($app_detail['sandbox_token']);
			$settings['live_app_id'] = sanitize_text_field($app_detail['live_app_id']);
			$settings['live_token'] = sanitize_text_field($app_detail['live_token']);
			update_option($option_key, $settings);
			update_option('paymenthood_setup_auto_enabled', '1');

			$this->log('App details saved; gateway not auto-enabled', 'info');
			$this->flush_checkout_methods_cache($app_detail['sandbox_app_id'], $app_detail['live_app_id']);

			// Set payment webhook in payment service
			$webhook_url = home_url('/?wc-api=payment_webhook');
			$webhook_tokens = $this->webhook_token_generator($option_key, $settings);

			// Sandbox
			$sandbox_webhook_result = $this->app_service->set_payment_webhook(
				$webhook_url,
				$webhook_tokens['sandbox_webhook_token'],
				$app_detail['sandbox_app_id'],
				$app_detail['sandbox_token']
			);

			if (is_wp_error($sandbox_webhook_result)) {
				$this->paymenthood_render_admin_error_page(
					$sandbox_webhook_result->get_error_code(),
					$sandbox_webhook_result->get_error_message()
				);
				exit;
			}

			// Live
			$live_webhook_result = $this->app_service->set_payment_webhook(
				$webhook_url,
				$webhook_tokens['live_webhook_token'],
				$app_detail['live_app_id'],
				$app_detail['live_token']
			);

			if (is_wp_error($live_webhook_result)) {
				$this->paymenthood_render_admin_error_page(
					$live_webhook_result->get_error_code(),
					$live_webhook_result->get_error_message()
				);
				exit;
			}

			$redirect_url = add_query_arg(
				array(
					'paymenthood_setup' => 'success',
				),
				admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $this->id)
			);

			wp_safe_redirect($redirect_url);

			$this->log('Setup completed successfully', 'info');

			exit;
		}

		// Method that processes the payment
		function process_payment($order_id)
		{
			$order = wc_get_order($order_id);

			$payment_id = $order->get_meta('_payment_service_payment_id');
			$redirect_url = $order->get_meta('_payment_service_redirect_url');

			if ($payment_id && $redirect_url) {
				$this->update_order_status($order_id);
				$order_status = $order->get_status();

				if ($order_status == 'failed') {
					return [
						'result' => 'failure',
						'redirect' => $order->get_checkout_payment_url(),
					];
				}

				return [
					'result' => 'success',
					'redirect' => $redirect_url,
				];
			}

			$customer_id = $order->get_customer_id();

			$this->log('Starting payment process. order_id: ' . $order_id, 'info');

			// Generate request body
			$items = [];

			foreach ($order->get_items() as $item) {
				/** @var WC_Order_Item_Product $item */
				$product = $item->get_product();

				$items[] = [
					'name' => $item->get_name(),
					'description' => $product ? $product->get_short_description() : '',
					'amount' => (float) $order->get_item_total($item, false),
					'quantity' => (int) $item->get_quantity(),
					'tax' => (float) $order->get_item_tax($item),
					'sku' => $product ? $product->get_sku() : '',
					'category' => $product && $product->is_virtual()
						? 'DigitalGoods'
						: 'PhysicalGoods',
				];
			}

			$body = array(
				'referenceId' => "$order_id",
				'amount' => $order->get_total(),
				'currency' => $order->get_currency(),
				'autoCapture' => true,
				//'webhookUrl' => home_url('/?wc-api=payment_webhook'),
				'returnUrl' => $this->get_return_url($order),
				'customerOrder' => [
					'customer' => [
						'customerId' => $customer_id > 0 ? "$customer_id" : wp_generate_uuid4(),
						'accountCreatedTime' => $order->get_date_created()
							? $order->get_date_created()->date('c')
							: null,
						'firstName' => $order->get_billing_first_name(),
						'lastName' => $order->get_billing_last_name(),
						'email' => $order->get_billing_email(),
						'phoneNumber' => $order->get_billing_phone(),
						'mobileNumber' => $order->get_billing_phone(),
						'address' => [
							'streetAddressLine1' => $order->get_billing_address_1(),
							'streetAddressLine2' => $order->get_billing_address_2(),
							'zipCode' => $order->get_billing_postcode(),
							'city' => $order->get_billing_city(),
							'state' => $order->get_billing_state(),
							'country' => WC()->countries->countries[$order->get_billing_country()] ?? '',
							'countryCode' => $order->get_billing_country(),
						]
					],
					'orderId' => "$order_id",
					'description' => sprintf('Order #%s from %s', $order->get_id(), get_bloginfo('name')),
					'customId' => (string) $order->get_order_key(),
					'amount' => [
						'total' => (float) $order->get_total(),
						'handling' => 0,
						'insurance' => 0,
						'discount' => (float) $order->get_total_discount(),
						'shipping' => (float) $order->get_shipping_total(),
						'shippingDiscount' => 0,
						'totalTax' => (float) $order->get_total_tax(),
					],
					'items' => $items
				]
			);

			$payment_result = $this->payment_service->create_hosted_payment($this->app_id, $this->token, $body);

			if (!is_wp_error($payment_result)) {
				$this->log('Payment created successfully. order_id: ' . $order_id, 'info');

				$this->log($payment_result['paymentId']);
				$order->update_meta_data('_payment_service_payment_id', $payment_result['paymentId']);
				$order->update_meta_data('_payment_service_redirect_url', $payment_result['redirectUrl']);

				// Save Fee details from API
				if (isset($payment_result['feeBreakdown'])) {
					$provider_fee = (float) $payment_result['feeBreakdown']['providerFee'];
					$app_fee = (float) $payment_result['feeBreakdown']['appFee'];
					
					$order->update_meta_data('_provider_fee', $provider_fee);
					$order->update_meta_data('_app_fee', $app_fee);
					$order->update_meta_data('_net_amount', (float) $payment_result['feeBreakdown']['netAmount']);
					
					$this->log('Provider Fee saved: ' . $provider_fee, 'info');
					$this->log('App Fee saved: ' . $app_fee, 'info');
				}

				$order->save();

				$redirect_url = $payment_result['redirectUrl'];

				$order->update_status('on-hold', 'Pending payment in PaymentHood.');

				return array(
					'result' => 'success',
					'redirect' => $redirect_url,
				);
			} else {
				wc_add_notice('Error in payment process', 'Call admin for support.');
				return;
			}
		}

		public function payment_webhook_handler()
		{
			// Log webhook entry point
			$this->log('Webhook endpoint hit', 'info', [
				'method' => $_SERVER['REQUEST_METHOD'] ?? null,
				'uri'    => $_SERVER['REQUEST_URI'] ?? null,
			]);

			// Validate webhook token
			$headers = getallheaders();
			$auth_header = trim($headers['Authorization'] ?? '');
			$token = preg_match('/^Bearer\s+(\S+)$/i', $auth_header, $m) ? $m[1] : '';

			$this->log('Webhook authorization header parsed', 'info', [
				'has_header' => !empty($auth_header),
				'token_valid' => !empty($token) && hash_equals($this->webhook_token, $token),
			]);

			if (empty($token) || !hash_equals($this->webhook_token, $token)) {
				$this->log('Invalid token in webhook request', 'error');
				status_header(401);
				exit('Unauthorized');
			}

			$raw_body = file_get_contents('php://input');

			$this->log('Webhook raw body received', 'info', [
				'body_length' => strlen($raw_body),
			]);

			if (empty($raw_body)) {
				$this->log('Empty webhook body', 'error');
				status_header(400);
				exit('Empty body');
			}

			$data = json_decode($raw_body, true);

			if (json_last_error() !== JSON_ERROR_NONE) {
				$this->log('Invalid JSON in webhook', 'error');
				status_header(400);
				exit('Invalid JSON');
			}

			$order_id = $data['payment']['referenceId'] ?? '';

			$this->log('Webhook payload parsed', 'info', [
				'order_id' => $order_id,
				'payment_state' => $data['payment']['paymentState'] ?? null,
			]);

			if (empty($order_id)) {
				$this->log('Missing referenceId in webhook', 'error');
				status_header(400);
				exit('Missing referenceId');
			}

			// Explicit log before calling GET sync
			$this->log('Calling update_order_status from webhook', 'info', [
				'order_id' => $order_id,
			]);

			$this->update_order_status($order_id);

			$this->log('Webhook processing completed', 'info', [
				'order_id' => $order_id,
			]);

			status_header(200);
			exit('OK');
		}

		protected function payment_state_handler($order_status, $payment_state)
		{
			switch ($payment_state) {

				case 'Refunding':
					return 'on-hold';

				case 'Refunded':
					return 'refunded';

				case 'Captured':
					return 'processing';

				case 'Failed':
				case 'Disputed':
					return 'failed';

				case 'Created':
				case 'Authorized':
				case 'Authorizing':
				case 'Approved':
				case 'Capturing':
				case 'SaleInProgress':
				case 'ProviderAuthorizedHold':
				case 'Cancelling':
				case 'CapturedHold':
					return 'on-hold';

				default:
					return $order_status;
			}
		}

		public function update_order_status($order_id)
		{
			$order = wc_get_order($order_id);

			if (!$order) {
				return;
			}

			$order_status = $order->get_status();

			// Do NOT block processing orders (refund requires this)
			if (in_array($order_status, ['completed', 'cancelled', 'failed'], true)) {
				return; // Allow processing & refunded to be updated
			}

			$payment_details = $this->payment_service->get_payment_by_reference_id($this->app_id, $this->token, $order_id);

			if (is_wp_error($payment_details)) {
				return;
			}

			$payment_state = $payment_details['paymentState'] ?? '';

			$this->log('Payment state fetched', 'info', [
				'order_id' => $order_id,
				'current_status' => $order_status,
				'payment_state' => $payment_state
			]);

			$new_order_status = $this->payment_state_handler(
				$order_status,
				$payment_state
			);

			if ($order_status === $new_order_status) {
				return;
			}

			if ($new_order_status === 'processing') {
				$order->payment_complete();
			} else {
				$order->update_status($new_order_status, 'Order status synced from PaymentHood.');
			}

			$this->log('Order status updated', 'info', [
				'order_id' => $order_id,
				'old_status' => $order_status,
				'new_status' => $new_order_status,
				'payment_state' => $payment_state
			]);
		}

		public function thankyou_page_handler($order_id)
		{
			$this->log('Payment service redirected to thnak you page successfully. order_id: ' . $order_id, 'info');

			$this->update_order_status($order_id);
		}

		public function process_refund($order_id, $amount = null, $reason = '')
		{
			$order = wc_get_order($order_id);

			if (!$order) {
				return new WP_Error('invalid_order', 'Invalid order ID');
			}

			$payment_id = $order->get_meta('_payment_service_payment_id');

			if (empty($payment_id)) {
				return new WP_Error('missing_payment_id', 'Payment ID not found.');
			}

			// Prevent duplicate refund calls
			$lock_key = 'paymenthood_refund_lock_' . $order_id;
			if (get_transient($lock_key)) {
				return new WP_Error('refund_in_progress', 'Refund already in progress.');
			}

			set_transient($lock_key, 1, 60);

			$this->log('Starting refund process', 'info', [
				'order_id' => $order_id,
				'payment_id' => $payment_id,
				'amount' => $amount,
				'reason' => $reason
			]);

			// Validate refund eligibility
			$check_body = $this->payment_service->get_payment_by_id($this->app_id, $this->token, $payment_id);

			if (is_wp_error($check_body) || empty($check_body['canRefund'])) {
				delete_transient($lock_key);
				return new WP_Error('refund_not_allowed', 'Refund not allowed by provider.');
			}

			// Call refund API
			$body = $this->payment_service->refund_payment($this->app_id, $this->token, $payment_id);

			if (is_wp_error($body)) {

				delete_transient($lock_key);

				return $body;
			}

			$order->add_order_note('PaymentHood refund requested.');
			$order->update_meta_data('_paymenthood_last_refund_time', current_time('mysql'));
			$order->save();

			$this->log('Refund API success', 'info', [
				'order_id' => $order_id,
				'payment_state' => $body['paymentState'] ?? null
			]);

			// Sync order status immediately
			$this->update_order_status($order_id);

			delete_transient($lock_key);

			return true;
		}

		protected function webhook_token_generator($option_key, $settings)
		{
			$sandbox_webhook_token = $settings['sandbox_webhook_token'] ?? '';
			$live_webhook_token = $settings['live_webhook_token'] ?? '';

			if (empty($sandbox_webhook_token)) {
				$sandbox_webhook_token = wp_generate_password(64, false);
				$settings['sandbox_webhook_token'] = $sandbox_webhook_token;
				update_option($option_key, $settings);
			}

			if (empty($live_webhook_token)) {
				$live_webhook_token = wp_generate_password(64, false);
				$settings['live_webhook_token'] = $live_webhook_token;
				update_option($option_key, $settings);
			}

			$this->log('webhook tokens saved', 'info');

			return [
				'sandbox_webhook_token' => $sandbox_webhook_token,
				'live_webhook_token' => $live_webhook_token,
			];
		}

		protected function paymenthood_render_admin_error_page($code, $message)
		{
			status_header(400);
			nocache_headers();

			$css_url    = plugins_url('assets/css/paymenthood-admin.css', __FILE__);
			$error_json = wp_json_encode(
				array(
					'error'   => $code,
					'message' => $message,
					'status'  => 400,
				),
				JSON_PRETTY_PRINT
			);

			include plugin_dir_path(__FILE__) . 'templates/admin-error-page.php';
			exit;
		}

		protected function log($message, $level = 'info', $context = [])
		{
			if (!isset($this->logger)) {
				$this->logger = wc_get_logger();
			}

			$context = array_merge(
				['source' => 'paymenthood'], // IMPORTANT
				$context
			);

			$this->logger->log($level, $message, $context);
		}
	}
}

// --- Display Fees next to order items (global scope) ---
add_action('woocommerce_admin_order_totals_after_total', function($order_id){
    $order = wc_get_order($order_id);
    $provider_fee = $order->get_meta('_provider_fee');
    $app_fee = $order->get_meta('_app_fee');

    if ($provider_fee || $app_fee) {
        $provider_fee_html = $provider_fee ? wc_price($provider_fee) : '';
        $app_fee_html      = $app_fee ? wc_price($app_fee) : '';
        include plugin_dir_path(__FILE__) . 'templates/order-fee-rows.php';
    }
});