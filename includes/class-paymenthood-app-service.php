<?php

class PaymentHood_App_Service
{
    private string $base_url;
    private $logger;

    public function __construct(string $base_url, callable $logger)
    {
        $this->base_url = rtrim($base_url, '/');
        $this->logger = $logger;
    }

    public function get_token(string $license_id, string $authorization_code)
    {
        $this->log('Start getting token', 'info');

        $response = wp_remote_get(
            $this->base_url . '/api/licenses/' . rawurlencode($license_id),
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $authorization_code,
                ),
            )
        );

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (is_wp_error($response) || $status_code !== 200) {
            $this->log('Error in getting token', 'error', array(
                'status_code' => $status_code,
                'body' => $body,
            ));

            return new WP_Error(
                'error_getting_token',
                is_array($body) ? wp_json_encode($body) : (string) $body
            );
        }

        $sandbox_app_id = '';
        $sandbox_token = '';
        $live_app_id = '';
        $live_token = '';

        foreach ($body as $item) {
            if (!empty($item['isSandbox'])) {
                $sandbox_app_id = $item['appId'] ?? '';
                $sandbox_token = $item['authorizationCode'] ?? '';
                continue;
            }

            $live_app_id = $item['appId'] ?? '';
            $live_token = $item['authorizationCode'] ?? '';
        }

        if (empty($sandbox_app_id) || empty($sandbox_token)) {
            $this->log('Sandbox app details are missing', 'error');

            return new WP_Error(
                'invalid_sandbox_app_details',
                'Sandbox app ID or token is missing'
            );
        }

        if (empty($live_app_id) || empty($live_token)) {
            $this->log('Live app details are missing', 'error');

            return new WP_Error(
                'invalid_live_app_details',
                'Live app ID or token is missing'
            );
        }

        return array(
            'sandbox_app_id' => $sandbox_app_id,
            'sandbox_token' => $sandbox_token,
            'live_app_id' => $live_app_id,
            'live_token' => $live_token,
        );
    }

    public function set_payment_webhook(string $webhook_url, string $webhook_token, string $app_id, string $token)
    {
        $this->log('Start setting payment webhook in payment service. AppId: ' . $app_id, 'info');

        $payload = array(
            'paymentWebhookUrl' => array(
                'value' => $webhook_url,
            ),
            'webhookAuthorizationHeaderScheme' => array(
                'value' => 'Bearer',
            ),
            'webhookAuthorizationHeaderParameter' => array(
                'value' => $webhook_token,
            ),
        );

        $response = wp_remote_request(
            $this->base_url . '/api/apps/' . rawurlencode($app_id),
            array(
                'method' => 'PATCH',
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ),
                'body' => wp_json_encode($payload),
                'timeout' => 20,
            )
        );

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if (is_wp_error($response) || $status_code !== 200) {
            $this->log('Error in setting webhook URL', 'error', array(
                'status_code' => $status_code,
                'body' => $body,
            ));

            if (is_wp_error($response)) {
                return $response;
            }

            return new WP_Error('error_setting_webhook_url', (string) $body);
        }

        $this->log('Webhook token set in payment service. AppId: ' . $app_id, 'info');

        return true;
    }

    public function get_supported_checkout_methods(string $app_id, string $token, string $hosted_page_id, bool $is_sandbox)
    {
        $environment = $is_sandbox ? 'sandbox' : 'live';

        if ($app_id === '' || $token === '' || $hosted_page_id === '') {
            return array();
        }

        $cache_key = 'paymenthood_checkout_methods_' . md5($app_id . '|' . $hosted_page_id . '|' . ($is_sandbox ? 'sandbox' : 'live'));
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            $this->log('Returning cached checkout methods', 'info', array(
                'environment' => $environment,
                'hosted_page_id' => $hosted_page_id,
                'methods_count' => count($cached),
                'cache_key' => $cache_key,
            ));

            return $cached;
        }

        $request_url = $this->base_url . '/api/apps/' . rawurlencode($app_id) . '/hosted-page/' . rawurlencode($hosted_page_id) . '/payment-checkout-methods';

        $this->log('Requesting checkout methods from PaymentHood API', 'info', array(
            'environment' => $environment,
            'app_id' => $app_id,
            'hosted_page_id' => $hosted_page_id,
            'request_url' => $request_url,
        ));

        $response = wp_remote_get(
            $request_url,
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                ),
            )
        );

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $body = json_decode($raw_body, true);

        $this->log('Checkout methods API response received', 'info', array(
            'environment' => $environment,
            'app_id' => $app_id,
            'hosted_page_id' => $hosted_page_id,
            'status_code' => $status_code,
            'body' => $raw_body,
        ));

        if (is_wp_error($response) || $status_code !== 200 || !is_array($body)) {
            $this->log('Error fetching checkout methods', 'error', array(
                'environment' => $environment,
                'app_id' => $app_id,
                'hosted_page_id' => $hosted_page_id,
                'status_code' => $status_code,
                'body' => $body,
            ));

            return array();
        }

        $methods = array();

        foreach ($body as $group) {
            $checkout_method = $group['checkoutMethod'] ?? '';

            if ($checkout_method === 'CreditCard') {
                $methods[] = array(
                    'type' => 'credit_card',
                    'label' => 'Credit card',
                    'icon_light' => '',
                    'icon_dark' => '',
                );
                continue;
            }

            if ($checkout_method !== 'ProviderHostedPage' || empty($group['paymentCheckoutMethodItems']) || !is_array($group['paymentCheckoutMethodItems'])) {
                continue;
            }

            foreach ($group['paymentCheckoutMethodItems'] as $item) {
                $provider = $item['paymentProfile']['paymentProvider'] ?? array();
                $provider_name = $provider['provider'] ?? '';
                $icon_light = $provider['iconUri1'] ?? '';
                $icon_dark = $provider['iconUri2'] ?? '';

                if ($provider_name === '' || ($icon_light === '' && $icon_dark === '')) {
                    continue;
                }

                $methods[] = array(
                    'type' => 'provider_hosted_page',
                    'icon_light' => $icon_light,
                    'icon_dark' => $icon_dark,
                );
            }
        }

        $methods = $this->deduplicate_checkout_methods($methods);
        set_transient($cache_key, $methods, 15 * MINUTE_IN_SECONDS);

        $this->log('Checkout methods normalized', 'info', array(
            'environment' => $environment,
            'hosted_page_id' => $hosted_page_id,
            'methods_count' => count($methods),
            'methods' => $methods,
        ));

        $this->log('Checkout methods loaded successfully', 'info', array(
            'environment' => $environment,
            'hosted_page_id' => $hosted_page_id,
            'methods_count' => count($methods),
            'cache_key' => $cache_key,
        ));

        return $methods;
    }

    /**
     * Fetch all available payment providers from the PaymentHood public providers API.
     * Result is cached for 6 hours — the list changes infrequently.
     *
     * @return array  Each element: ['id' => string, 'title' => string, 'icon_light' => string, 'icon_dark' => string]
     */
    public function get_all_providers(bool $force_refresh = false): array
    {
        $cache_key = 'paymenthood_all_providers';
        $cached = $force_refresh ? false : get_transient($cache_key);

        if (is_array($cached)) {
            $this->log('Returning cached providers list', 'info', array(
                'providers_count' => count($cached),
                'cache_key' => $cache_key,
            ));

            return $cached;
        }

        if ($force_refresh) {
            $this->log('Bypassing cached providers list for refresh', 'info', array(
                'cache_key' => $cache_key,
            ));
        }

        $request_url = $this->base_url . '/api/apps/providers';

        $response = wp_remote_get(
            $request_url,
            array(
                'timeout' => 15,
            )
        );

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $body = json_decode($raw_body, true);

        if (is_wp_error($response) || $status_code !== 200 || !is_array($body)) {
            $this->log('Error fetching providers list', 'error', array(
                'status_code' => $status_code,
                'body' => $raw_body,
            ));

            return array();
        }

        $providers = array();

        foreach ($body as $item) {
            $provider_name = $item['provider'] ?? '';

            if (empty($provider_name)) {
                continue;
            }

            $providers[] = array(
                'id'         => sanitize_key(strtolower($provider_name)),
                'title'      => $provider_name,
                'icon_light' => $item['iconUri1'] ?? '',
                'icon_dark'  => $item['iconUri2'] ?? '',
            );
        }

        set_transient($cache_key, $providers, 6 * HOUR_IN_SECONDS);
        return $providers;
    }

    private function deduplicate_checkout_methods(array $methods): array
    {
        $seen = array();
        $deduplicated = array();

        foreach ($methods as $method) {
            $key = $method['type'] . '|' . $method['label'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduplicated[] = $method;
        }

        return $deduplicated;
    }

    /**
     * Fetch details for a single app from GET /api/apps/{appId}.
     * Cached for 5 minutes. Returns an empty array on failure.
     *
     * @param string $app_id App ID.
     * @param string $token  Bearer token for this app.
     * @return array
     */
    public function get_app_details(string $app_id, string $token): array
    {
        if ($app_id === '' || $token === '') {
            return array();
        }

        $request_url = $this->base_url . '/api/apps/' . rawurlencode($app_id);

        $this->log('Requesting app details from PaymentHood API', 'info', array(
            'app_id'      => $app_id,
            'request_url' => $request_url,
        ));

        $response    = wp_remote_get(
            $request_url,
            array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                ),
            )
        );

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body    = wp_remote_retrieve_body($response);
        $body        = json_decode($raw_body, true);

        if (is_wp_error($response) || $status_code !== 200 || !is_array($body)) {
            $this->log('Error fetching app details', 'error', array(
                'app_id'      => $app_id,
                'status_code' => $status_code,
                'body'        => $raw_body,
            ));
            return array();
        }

        $this->log('App details fetched successfully', 'info', array(
            'app_id'             => $app_id,
            'is_setup_completed' => $body['isSetupCompleted'] ?? null,
        ));

        return $body;
    }

    private function log(string $message, string $level = 'info', array $context = array()): void
    {
        call_user_func($this->logger, $message, $level, $context);
    }
}
