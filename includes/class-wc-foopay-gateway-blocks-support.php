<?php
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
        wp_enqueue_script(
            'wc-paymenthood-blocks-integration',
            plugins_url('src/index.js', __DIR__),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n'],
            null,
            true
        );

        $settings = get_option('woocommerce_paymenthood_settings', []);
        wp_add_inline_script(
            'wc-paymenthood-blocks-integration',
            'window.wc = window.wc || {}; window.wc.wcSettings = window.wc.wcSettings || {}; window.wc.wcSettings["paymenthood_data"] = ' . wp_json_encode([
                'title' => $settings['title'] ?? 'PaymentHood',
                'description' => $settings['description'] ?? 'Pay securely using PaymentHood.',
                'ariaLabel' => $settings['title'] ?? 'PaymentHood',

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
}