<?php

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_PaymentHood_Blocks extends AbstractPaymentMethodType
{
    protected $name = 'paymenthood';
    protected $gateway;

    public function initialize()
    {
        $gateways = WC()->payment_gateways()->payment_gateways();
        $this->gateway = $gateways['paymenthood'] ?? null;
    }

    public function needs_shipping_address()
    {
        return false;
    }

    public function is_active()
    {
        $is = $this->gateway && $this->gateway->is_available();

        return $is;
    }

    public function get_payment_method_script_handles()
    {
        wp_enqueue_style(
            'paymenthood-checkout',
            plugins_url('assets/css/paymenthood-checkout.css', dirname(__DIR__) . '/payment-gateway.php'),
            [],
            '1.0.3'
        );

        wp_enqueue_script(
            'wc-paymenthood-blocks-integration',
            plugins_url('src/index.js', __DIR__),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n'],
            '1.0.2',
            true
        );

        $settings = get_option('woocommerce_paymenthood_settings', []);
        wp_add_inline_script(
            'wc-paymenthood-blocks-integration',
            'window.wc = window.wc || {}; window.wc.wcSettings = window.wc.wcSettings || {}; window.wc.wcSettings["paymenthood_data"] = ' . wp_json_encode([
                'title' => $settings['title'] ?? 'PaymentHood',
                'description' => $settings['description'] ?? 'Pay securely using PaymentHood.',
                'ariaLabel' => $settings['title'] ?? 'PaymentHood',
                'logoUrl' => $this->get_logo_url(),
                'isSandbox' => ($settings['testmode'] ?? 'yes') === 'yes',
                'supportedMethods' => $this->gateway ? $this->gateway->get_supported_checkout_methods_for_display() : [],
            ]) . ';',
            'before'
        );
        return ['wc-paymenthood-blocks-integration'];
    }


    public function get_payment_method_data()
    {
        $settings = get_option('woocommerce_paymenthood_settings', []);
        return [
            'title' => $settings['title'] ?? 'PaymentHood',
            'description' => $settings['description'] ?? 'Pay securely using PaymentHood.',
            'ariaLabel' => $settings['title'] ?? 'PaymentHood',
            'logoUrl' => $this->get_logo_url(),
            'isSandbox' => ($settings['testmode'] ?? 'yes') === 'yes',
            'supportedMethods' => $this->gateway ? $this->gateway->get_supported_checkout_methods_for_display() : [],
            'supports' => ['products', 'subscriptions', 'default', 'virtual'],

        ];
    }

    public function enqueue_payment_method_script()
    {
        wp_enqueue_script(
            'wc-paymenthood-blocks-integration',
            plugins_url('src/index.js', __DIR__),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n'],
            null,
            true
        );
    }

    protected function get_logo_url()
    {
        $base_file = dirname(__DIR__) . '/payment-gateway.php';
        $logo_candidates = [
            'assets/images/paymenthood-blue.png',
            'assets/images/paymenthood-logo.svg',
            'assets/images/paymenthood-logo.png',
            'assets/images/paymenthood.webp',
        ];

        foreach ($logo_candidates as $relative_path) {
            $absolute_path = dirname(__DIR__) . '/' . $relative_path;

            if (file_exists($absolute_path)) {
                return plugins_url($relative_path, $base_file);
            }
        }

        return '';
    }
}