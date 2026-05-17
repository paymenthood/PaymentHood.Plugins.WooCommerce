<?php
/**
 * Plugin Name:          PaymentHood Payment Gateway for WooCommerce
 * Plugin URI:           https://paymenthood.com
 * Description:          Accept payments via PaymentHood — a flexible, multi-provider payment gateway for WooCommerce.
 * Version:              1.0.0
 * Author:               PaymentHood
 * Author URI:           https://paymenthood.com
 * Developer:            PaymentHood
 * Developer URI:        https://paymenthood.com
 * Text Domain:          paymenthood
 * Domain Path:          /languages
 *
 * Requires at least:    6.0
 * Requires PHP:         8.0
 * WC requires at least: 8.0
 * WC tested up to:      9.9
 *
 * License:              GNU General Public License v3.0
 * License URI:          https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package PaymentHood
 */

defined( 'ABSPATH' ) || exit;

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

			// Show PaymentHood payment ID in the admin order edit panel
			add_action('woocommerce_admin_order_data_after_order_details', array($this, 'render_admin_order_payment_id'));

			// Show manual refund warning banner at the top of the admin order edit page
			add_action('admin_notices', array($this, 'render_manual_refund_admin_notice'));

			// Handles payment state and order status on order received (thank you) page
			add_action('woocommerce_thankyou', array($this, 'thankyou_page_handler'));
			add_action('woocommerce_order_details_after_order_table', array($this, 'render_paymenthood_order_payment_box'));
			add_filter('woocommerce_available_payment_gateways', array($this, 'restrict_order_pay_gateways_to_paymenthood'), 20);
			add_filter('woocommerce_order_needs_payment', array($this, 'prevent_terminal_paymenthood_order_payment'), 20, 3);

			// Add sandbox badge to checkout title and customer-facing order detail
			if ($this->testmode) {
				add_filter('woocommerce_gateway_title', array($this, 'add_sandbox_badge_to_title'), 10, 2);
				add_filter('woocommerce_order_get_payment_method_title', array($this, 'add_sandbox_badge_to_order_payment_title'), 10, 2);
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

			if ($this->is_local_callback_base_url()) {
				$webhook_url = $this->build_public_wc_api_url('payment_webhook');
				?>
				<div class="notice paymenthood-warning-notice">
					<div class="paymenthood-warning-notice__header">
						<span class="paymenthood-warning-notice__icon" aria-hidden="true">&#9888;</span>
						<span class="paymenthood-warning-notice__eyebrow">Action required &mdash; Webhook unreachable on localhost</span>
					</div>
					<div class="paymenthood-warning-notice__body-wrap">
						<p class="paymenthood-warning-notice__title">PaymentHood cannot receive live payment webhooks.</p>
						<p class="paymenthood-warning-notice__body">
							Your store is currently using a <strong>localhost callback URL</strong>. Payment webhooks cannot reach localhost from outside your machine. Use a public domain or a tunnel such as Cloudflare Tunnel or ngrok before accepting live payments.
							<a href="https://github.com/paymenthood/paymenthood-docs/blob/main/CLOUDFLARE-TUNNEL-INSTRUCTIONS.md" target="_blank" rel="noopener noreferrer">Learn how to set up a Cloudflare Tunnel &rarr;</a>
						</p>
						<p class="paymenthood-warning-notice__meta">
							<span class="paymenthood-warning-notice__meta-label">Current webhook URL</span>
							<a class="paymenthood-warning-notice__link" href="<?php echo esc_url($webhook_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($webhook_url); ?></a>
						</p>
					</div>
				</div>
				<?php
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
			$return_url = $this->build_public_wc_api_url('paymenthood_setup');
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

			// Do not inject HTML during AJAX/REST requests (e.g. checkout order creation)
			// to prevent the raw <span> being persisted in the order's payment_method_title.
			if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
				return $title;
			}

			return esc_html($title) . ' <span class="paymenthood-sandbox-badge paymenthood-sandbox-badge--inline">Sandbox</span>';
		}

		public function add_sandbox_badge_to_order_payment_title($title, $order)
		{
			// Strip any HTML that may have been previously stored in the order record
			// (caused by the gateway title filter firing during order creation).
			$clean_title = wp_strip_all_tags($title);

			if (is_admin()) {
				return $clean_title;
			}

			if (!$order instanceof WC_Order || $order->get_payment_method() !== $this->id) {
				return $clean_title;
			}

			return esc_html($clean_title) . ' <span class="paymenthood-sandbox-badge paymenthood-sandbox-badge--inline">Sandbox</span>';
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

				if ($icon_light !== '') {
					$icon_light = $this->get_provider_icon_proxy_url($icon_light);
				}

				if ($icon_dark !== '') {
					$icon_dark = $this->get_provider_icon_proxy_url($icon_dark);
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
			$is_view_order = function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('view-order');

			if (!$is_checkout && !$is_checkout_pay && !$is_view_order) {
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

		protected function get_public_base_url(): string
		{
			$request_host = $this->get_request_public_host();
			$forwarded_proto = $this->get_forwarded_server_value('HTTP_X_FORWARDED_PROTO');
			$is_https = (
				(!empty($_SERVER['HTTPS']) && strtolower((string) wp_unslash($_SERVER['HTTPS'])) !== 'off')
				|| $forwarded_proto === 'https'
			);
			$home_path = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
			$home_path = $home_path !== '' ? '/' . trim($home_path, '/') : '';

			if ($request_host !== '' && !in_array($request_host, array('localhost', '127.0.0.1', '::1'), true)) {
				return ($is_https ? 'https://' : 'http://') . $request_host . $home_path;
			}

			$override = defined('PAYMENTHOOD_PUBLIC_BASE_URL') ? (string) PAYMENTHOOD_PUBLIC_BASE_URL : '';

			$override = trim((string) apply_filters('paymenthood_public_base_url', $override));

			if ($override !== '') {
				return untrailingslashit($override);
			}

			return untrailingslashit(home_url('/'));
		}

		protected function get_request_public_host(): string
		{
			$forwarded_host = $this->get_forwarded_server_value('HTTP_X_FORWARDED_HOST');

			if ($forwarded_host !== '') {
				return $forwarded_host;
			}

			$original_host = $this->get_forwarded_server_value('HTTP_X_ORIGINAL_HOST');

			if ($original_host !== '') {
				return $original_host;
			}

			$host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) wp_unslash($_SERVER['HTTP_HOST'])) : '';

			return $host !== '' ? preg_replace('/:\\d+$/', '', $host) : '';
		}

		protected function get_forwarded_server_value(string $key): string
		{
			if (!isset($_SERVER[$key])) {
				return '';
			}

			$value = strtolower(trim((string) wp_unslash($_SERVER[$key])));

			if ($value === '') {
				return '';
			}

			$parts = array_filter(array_map('trim', explode(',', $value)));
			$value = $parts !== array() ? (string) end($parts) : $value;

			return preg_replace('/:\\d+$/', '', $value);
		}

		protected function build_public_wc_api_url(string $endpoint): string
		{
			return add_query_arg(
				array(
					'wc-api' => $endpoint,
				),
				trailingslashit($this->get_public_base_url())
			);
		}

		protected function maybe_log_local_callback_url(string $url, string $context): void
		{
			$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

			if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
				$this->log('PaymentHood callback URL is not publicly reachable', 'warning', array(
					'context' => $context,
					'url' => $url,
				));
			}
		}

		protected function is_paymenthood_order($order): bool
		{
			return $order instanceof WC_Order && $order->get_payment_method() === $this->id;
		}

		protected function get_order_pay_page_order()
		{
			if (!function_exists('is_checkout_pay_page') || !is_checkout_pay_page()) {
				return null;
			}

			$order_id = function_exists('get_query_var') ? absint(get_query_var('order-pay')) : 0;

			if ($order_id <= 0) {
				return null;
			}

			return wc_get_order($order_id);
		}

		public function restrict_order_pay_gateways_to_paymenthood($gateways)
		{
			$order = $this->get_order_pay_page_order();

			if (!$this->is_paymenthood_order($order)) {
				return $gateways;
			}

			return isset($gateways[$this->id]) ? array($this->id => $gateways[$this->id]) : array();
		}

		public function prevent_terminal_paymenthood_order_payment($needs_payment, $order, $valid_order_statuses)
		{
			if (!$needs_payment || !$this->is_paymenthood_order($order)) {
				return $needs_payment;
			}

			$payment_details = $this->get_paymenthood_payment_details_for_order($order);

			if (is_wp_error($payment_details) || empty($payment_details)) {
				return $needs_payment;
			}

			$payment_state = (string) ($payment_details['paymentState'] ?? '');

			if (!$this->is_terminal_paymenthood_payment_state($payment_state)) {
				return $needs_payment;
			}

			$this->log('PaymentHood order payment blocked because payment state is terminal', 'info', array(
				'order_id' => $order->get_id(),
				'payment_state' => $payment_state,
			));

			return false;
		}

		protected function get_paymenthood_payment_details_for_order($order)
		{
			if (!$this->is_paymenthood_order($order)) {
				return array();
			}

			return $this->payment_service->get_payment_by_reference_id($this->app_id, $this->token, $order->get_id());
		}

		protected function is_terminal_paymenthood_payment_state(string $payment_state): bool
		{
			$normalized_state = strtolower(trim($payment_state));

			return in_array($normalized_state, array('capture', 'captured', 'failed', 'refunded', 'disputed'), true);
		}

		protected function is_processing_paymenthood_payment_state(string $payment_state): bool
		{
			return $payment_state !== '' && !$this->is_terminal_paymenthood_payment_state($payment_state);
		}

		public function render_paymenthood_order_payment_box($order): void
		{
			if (!$this->is_paymenthood_order($order)) {
				return;
			}

			$is_view_order    = function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('view-order');
			$is_order_received = function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received');

			if (!$is_view_order && !$is_order_received) {
				return;
			}

			$payment_details = $this->get_paymenthood_payment_details_for_order($order);

			if (is_wp_error($payment_details) || empty($payment_details)) {
				return;
			}

			$payment_state = (string) ($payment_details['paymentState'] ?? '');

			if (!$this->is_processing_paymenthood_payment_state($payment_state)) {
				return;
			}

			$redirect_url = (string) ($payment_details['redirectUrl'] ?? '');

			if ($redirect_url === '') {
				$redirect_url = (string) $order->get_meta('_payment_service_redirect_url');
			}

			if ($redirect_url === '') {
				return;
			}
			?>
			<section class="ph-pending-card" aria-label="Complete your PaymentHood payment">
				<div class="ph-pending-card__header">
					<div class="ph-pending-card__brand">
						<span class="ph-pending-card__brand-dot" aria-hidden="true"></span>
						<span class="ph-pending-card__brand-name">PaymentHood</span>
					</div>
					<span class="ph-pending-card__badge">
						<span class="ph-pending-card__badge-dot" aria-hidden="true"></span>
						Awaiting payment
					</span>
				</div>
				<div class="ph-pending-card__body">
					<div class="ph-pending-card__icon" aria-hidden="true">
						<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
					</div>
					<div class="ph-pending-card__copy">
						<h2 class="ph-pending-card__title">Your payment is pending</h2>
						<p class="ph-pending-card__desc">Your order is reserved and waiting for payment confirmation. Return to PaymentHood to securely complete this payment.</p>
					</div>
				</div>
				<div class="ph-pending-card__footer">
					<a class="ph-pending-card__cta" href="<?php echo esc_url($redirect_url); ?>">
						<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
						Continue to secure payment
						<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
					</a>
				</div>
			</section>
			<?php
		}

		public function render_manual_refund_admin_notice(): void
		{
			// Detect current order — supports both classic posts editor and HPOS
			$order_id = 0;
			if (isset($_GET['post']) && 'shop_order' === get_post_type((int) $_GET['post'])) {
				$order_id = (int) $_GET['post'];
			} elseif (
				isset($_GET['id'], $_GET['page'])
				&& 'wc-orders' === sanitize_key($_GET['page'])
			) {
				$order_id = (int) $_GET['id'];
			}

			if (!$order_id) {
				return;
			}

			$order = wc_get_order($order_id);
			if (!$order || !$this->is_paymenthood_order($order)) {
				return;
			}

			// Blue info box: automatic refund is supported by the provider.
			if (
				$order->get_meta('_paymenthood_can_refund') === '1'
				&& !in_array($order->get_status(), ['refunded', 'cancelled', 'failed'], true)
			) {
				$provider_name  = $order->get_meta('_paymenthood_provider_name');
				$provider_label = !empty($provider_name) ? $provider_name : 'the payment provider';
				?>
				<div class="notice notice-info" style="background-color:#e8f4fd;border-left-color:#2196f3;border-left-width:4px;">
					<p>
						<strong>&#8505; PaymentHood &mdash; Automatic refund supported</strong><br>
						This payment supports automatic refunds via <strong><?php echo esc_html($provider_label); ?></strong>. If you process a refund, the funds will be returned to the customer&rsquo;s original payment method or bank account automatically.
					</p>
				</div>
				<?php
			}

			// Yellow warning box: provider does not support automatic refunds — manual action needed.
			if ($order->get_meta('_paymenthood_manual_refund_required') === '1') {
				?>
				<div class="notice notice-warning" style="background-color:#fff8e1;border-left-color:#f0ad4e;border-left-width:4px;">
					<p>
						<strong>&#9888; PaymentHood &mdash; Manual refund required</strong><br>
						This payment provider does not support automatic refunds. The order has been marked as refunded in PaymentHood, but <strong>no money has been returned to the customer automatically</strong>. Please manually transfer the refund amount to the customer&rsquo;s bank account or original payment method.
					</p>
				</div>
				<?php
			}
		}

		public function render_admin_order_payment_id($order): void
		{
			if (!$this->is_paymenthood_order($order)) {
				return;
			}

			$payment_id = $order->get_meta('_payment_service_payment_id');

			if (empty($payment_id)) {
				return;
			}

			$detail_url = rtrim($this->paymenthood_panel_url, '/') . '/'
				. rawurlencode($this->app_id)
				. '/payments/detail?appId=' . rawurlencode($this->app_id)
				. '&paymentId=' . rawurlencode($payment_id);
			?>
			<p class="form-field form-field-wide">
				<label><?php esc_html_e('PaymentHood Payment ID', 'paymenthood'); ?></label>
				<a href="<?php echo esc_url($detail_url); ?>" target="_blank" rel="noopener noreferrer"
				   style="font-family:monospace;font-size:12px;word-break:break-all;"><?php echo esc_html($payment_id); ?></a>
			</p>
			<?php
		}

		protected function extract_provider_name(array $data): string
		{
			foreach (['providerName', 'paymentMethod', 'providerTitle'] as $field) {
				if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
					return $data[$field];
				}
			}
			if (isset($data['provider'])) {
				if (is_string($data['provider']) && $data['provider'] !== '') {
					return $data['provider'];
				}
				if (is_array($data['provider']) && isset($data['provider']['name']) && $data['provider']['name'] !== '') {
					return (string) $data['provider']['name'];
				}
			}
			return '';
		}

		protected function is_local_callback_base_url(): bool
		{
			$host = strtolower((string) wp_parse_url($this->get_public_base_url(), PHP_URL_HOST));

			return $host === '' || in_array($host, array('localhost', '127.0.0.1', '::1'), true);
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
			$webhook_url = $this->build_public_wc_api_url('payment_webhook');
			$this->maybe_log_local_callback_url($webhook_url, 'setup_webhook');
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
				$payment_details  = $this->get_paymenthood_payment_details_for_order($order);
				$payment_state    = !is_wp_error($payment_details) ? (string) ($payment_details['paymentState'] ?? '') : '';
				$fresh_redirect   = !is_wp_error($payment_details) ? (string) ($payment_details['redirectUrl'] ?? '') : '';

				if ($payment_state !== '' && $this->is_terminal_paymenthood_payment_state($payment_state)) {
					// Payment is in a terminal state – do not allow the customer to retry.
					return [
						'result'   => 'failure',
						'redirect' => $order->get_view_order_url(),
					];
				}

				if ($payment_state !== '' && $this->is_processing_paymenthood_payment_state($payment_state) && $fresh_redirect !== '') {
					// Payment is still in-progress – redirect back to PaymentHood using the fresh URL.
					return [
						'result'   => 'success',
						'redirect' => $fresh_redirect,
					];
				}

				// API unavailable – fall back to the stored values.
				$this->update_order_status($order_id);
				if ($order->get_status() === 'failed') {
					return [
						'result'   => 'failure',
						'redirect' => $order->get_view_order_url(),
					];
				}

				return [
					'result'   => 'success',
					'redirect' => $redirect_url,
				];
			}

			$customer_id = $order->get_customer_id();
			$return_url = $this->get_return_url($order);
			$webhook_url = $this->build_public_wc_api_url('payment_webhook');
			$this->maybe_log_local_callback_url($webhook_url, 'payment_webhook');

			$this->log('Starting payment process. order_id: ' . $order_id, 'info');

			// Generate request body
			$items = [];
			$has_subscription = false;

			foreach ($order->get_items() as $item) {
				/** @var WC_Order_Item_Product $item */
				$product = $item->get_product();

				if ($product && !$has_subscription) {
					$product_type = $product->get_type();
					if (
						in_array($product_type, ['subscription', 'variable-subscription', 'subscription_variation'], true)
						|| (class_exists('WC_Subscriptions_Product') && WC_Subscriptions_Product::is_subscription($product))
					) {
						$has_subscription = true;
					}
				}

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
				'showPayRecurringInCheckout' => $has_subscription,
				'webhookUrl' => $webhook_url,
				'returnUrl' => $return_url,
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
				$this->log('Payment created successfully', 'info', array(
					'order_id' => $order_id,
					'payment_id' => $payment_result['paymentId'] ?? '',
				));
				$order->update_meta_data('_payment_service_payment_id', $payment_result['paymentId']);
				$order->update_meta_data('_payment_service_redirect_url', $payment_result['redirectUrl']);
				$order->add_order_note(
					sprintf('PaymentHood payment initiated. Payment ID: %s', $payment_result['paymentId']),
					0,
					true
				);

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
			try {
				$headers = getallheaders();
				$auth_header = trim($headers['Authorization'] ?? '');
				$token = preg_match('/^Bearer\s+(\S+)$/i', $auth_header, $m) ? $m[1] : '';

				if (empty($token) || !hash_equals($this->webhook_token, $token)) {
					throw new \RuntimeException('Invalid authorization token', 401);
				}

				$raw_body = file_get_contents('php://input');

				if (empty($raw_body)) {
					throw new \RuntimeException('Empty request body', 400);
				}

				$data = json_decode($raw_body, true);

				if (json_last_error() !== JSON_ERROR_NONE) {
					throw new \RuntimeException('Invalid JSON payload: ' . json_last_error_msg(), 400);
				}

				$order_id = (string) ($data['payment']['referenceId'] ?? '');
				$payment_state = (string) ($data['payment']['paymentState'] ?? '');

				if ($order_id === '') {
					throw new \RuntimeException('Missing referenceId in payload', 400);
				}

				$this->log('Webhook state transition', 'info', [
					'order_id'      => $order_id,
					'payment_state' => $payment_state,
				]);

				$this->update_order_status($order_id);

				status_header(200);
				exit('OK');

			} catch (\Throwable $e) {
				$this->log('Webhook request failed', 'error', [
					'reason' => $e->getMessage(),
					'code'   => $e->getCode(),
				]);
				status_header($e->getCode() >= 400 ? $e->getCode() : 400);
				exit($e->getMessage());
			}
		}

		protected function payment_state_handler($order_status, $payment_state)
		{
			switch (strtolower($payment_state)) {

				case 'refunding':
					// Only move to on-hold if order hasn't already reached a further state.
					return in_array($order_status, ['processing', 'completed'], true)
						? $order_status
						: 'on-hold';

				case 'refunded':
					// Always reflect an internal PaymentHood refund, even from 'completed'.
					return 'refunded';

				case 'captured':
					// Never downgrade a completed order back to processing.
					return $order_status === 'completed' ? 'completed' : 'processing';

				case 'failed':
					// Terminal failure – no retry possible. Cancel so WooCommerce
					// restores reserved stock automatically.
					return 'cancelled';

				case 'disputed':
					// Chargeback filed. Mark as failed to flag for admin review.
					// Stock has likely already been fulfilled; do not restore it.
					return 'failed';

				case 'created':
				case 'authorized':
				case 'authorizing':
				case 'approved':
				case 'capturing':
				case 'saleinprogress':
				case 'providerauthorizedhold':
				case 'cancelling':
				case 'capturedhold':
					// Never walk back an already-paid or completed order.
					return in_array($order_status, ['processing', 'completed'], true)
						? $order_status
						: 'on-hold';

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

			// Block truly terminal statuses that should never be overwritten.
			// 'completed' is intentionally excluded so that a PaymentHood internal
			// refund can still transition a completed order to 'refunded'.
			if (in_array($order_status, ['cancelled', 'failed', 'refunded'], true)) {
				return;
			}

			$payment_details = $this->payment_service->get_payment_by_reference_id($this->app_id, $this->token, $order_id);

			if (is_wp_error($payment_details)) {
				return;
			}

			$payment_state = $payment_details['paymentState'] ?? '';

			// Always sync the latest fee breakdown from the API, regardless of status change.
			$this->sync_payment_fees($order, $payment_details);

			// Cache refund eligibility for the admin info notice (shown on order edit page).
			if (array_key_exists('canRefund', $payment_details)) {
				$order->update_meta_data('_paymenthood_can_refund', $payment_details['canRefund'] ? '1' : '0');
				$cached_provider = $this->extract_provider_name($payment_details);
				if ($cached_provider !== '') {
					$order->update_meta_data('_paymenthood_provider_name', $cached_provider);
				}
				$order->save_meta_data();
			}

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
			} elseif (strtolower($payment_state) === 'disputed') {
				$order->update_status(
					$new_order_status,
					__('Payment disputed (chargeback). Review this order immediately — do not ship if not yet fulfilled.', 'paymenthood')
				);
				$this->notify_admin_disputed_order($order);
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

		protected function sync_payment_fees(WC_Order $order, array $payment_details): void
		{
			$fee_breakdown = $payment_details['feeBreakdown'] ?? null;

			if (!is_array($fee_breakdown)) {
				return;
			}

			$changed = false;

			if (array_key_exists('providerFee', $fee_breakdown)) {
				$order->update_meta_data('_provider_fee', (float) $fee_breakdown['providerFee']);
				$changed = true;
			}

			if (array_key_exists('appFee', $fee_breakdown)) {
				$order->update_meta_data('_app_fee', (float) $fee_breakdown['appFee']);
				$changed = true;
			}

			if (array_key_exists('netAmount', $fee_breakdown)) {
				$order->update_meta_data('_net_amount', (float) $fee_breakdown['netAmount']);
				$changed = true;
			}

			if ($changed) {
				$order->save_meta_data();
			}
		}

		public function thankyou_page_handler($order_id)
		{
			$this->update_order_status($order_id);
		}

		protected function notify_admin_disputed_order(WC_Order $order): void
		{
			$order_id   = $order->get_id();
			$order_link = admin_url('post.php?post=' . $order_id . '&action=edit');
			$subject    = sprintf('[%s] Payment dispute on order #%d', get_bloginfo('name'), $order_id);
			$message    = implode("\n\n", [
				sprintf('A chargeback or payment dispute has been filed for order #%d.', $order_id),
				'Review this order immediately. If the goods have not yet been shipped, put the order on hold and do not fulfil it until the dispute is resolved.',
				'Order details: ' . $order_link,
				sprintf('Customer: %s %s (%s)', $order->get_billing_first_name(), $order->get_billing_last_name(), $order->get_billing_email()),
				sprintf('Order total: %s', wp_strip_all_tags(wc_price($order->get_total()))),
			]);

			wp_mail(get_option('admin_email'), $subject, $message);

			$this->log('Admin notified of disputed order', 'warning', [
				'order_id' => $order_id,
			]);
		}

		public function process_refund($order_id, $amount = null, $reason = '')
		{
			$order = wc_get_order($order_id);

			if (!$order) {
				return new WP_Error('invalid_order', 'Invalid order ID');
			}

			// PaymentHood only supports full-order refunds. Reject partial amounts.
			$order_total = (float) $order->get_total();
			$refund_amount = $amount !== null ? (float) $amount : $order_total;

			if (round($refund_amount, 2) !== round($order_total, 2)) {
				return new WP_Error(
					'partial_refund_not_supported',
					sprintf(
						'PaymentHood only supports full refunds. To refund this order, enter the full amount of %s.',
						html_entity_decode( wp_strip_all_tags( wc_price( $order_total ) ) )
					)
				);
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

			if (is_wp_error($check_body)) {
				delete_transient($lock_key);
				return new WP_Error('refund_check_failed', 'Unable to verify refund eligibility.');
			}

			$can_refund = !empty($check_body['canRefund']);

			$this->log('Refund eligibility check', 'info', [
				'order_id'    => $order_id,
				'payment_id'  => $payment_id,
				'can_refund'  => $can_refund,
				'check_body'  => $check_body,
			]);

			// Cache canRefund state for the admin info notice.
			$order->update_meta_data('_paymenthood_can_refund', $can_refund ? '1' : '0');
			$cached_provider = $this->extract_provider_name($check_body);
			if ($cached_provider !== '') {
				$order->update_meta_data('_paymenthood_provider_name', $cached_provider);
			}
			$order->save_meta_data();

			$base_description = sprintf('Refund for order #%s issued from WooCommerce.', $order_id);
			$description = trim($reason) !== ''
				? $base_description . ' ' . trim($reason)
				: $base_description;

			if ($can_refund) {
				// Provider supports a native refund — trigger it through the refund API.
				$body = $this->payment_service->refund_payment($this->app_id, $this->token, $payment_id, $description);
			} else {
				// Provider does not support native refunds — mark the payment as refunded manually.
				$body = $this->app_service->mark_as_refund($this->app_id, $this->token, $payment_id, $description);

				if (!is_wp_error($body)) {
					$order->update_meta_data('_paymenthood_manual_refund_required', '1');
					$order->save_meta_data();
				}
			}

			if (!$can_refund) {
				$order->add_order_note(
					'⚠️ MANUAL REFUND REQUIRED: This payment provider does not support automatic refunds. ' .
					'No money has been returned to the customer automatically. ' .
					'You must manually transfer the refund amount to the customer\'s bank account or original payment method.',
					1 // visible to admin
				);
			}
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
    $provider_fee = (float) $order->get_meta('_provider_fee');
    $app_fee      = (float) $order->get_meta('_app_fee');
    $net_amount   = $order->get_meta('_net_amount');

    $total_fee = $provider_fee + $app_fee;

    if ($total_fee > 0) {
        $fee_html        = wc_price($total_fee);
        $net_amount_html = $net_amount !== '' ? wc_price($net_amount) : '';
        include plugin_dir_path(__FILE__) . 'templates/order-fee-rows.php';
    }
});