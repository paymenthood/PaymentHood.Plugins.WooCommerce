<?php

class PaymentHood_Payment_Service
{
    private string $base_url;
    private $logger;

    public function __construct(string $base_url, callable $logger)
    {
        $this->base_url = rtrim($base_url, '/');
        $this->logger = $logger;
    }

    public function create_hosted_payment(string $app_id, string $token, array $payload)
    {
        $response = wp_remote_post(
            $this->base_url . '/api/v1/apps/' . rawurlencode($app_id) . '/payments/hosted-page',
            array(
                'timeout' => 30,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ),
                'body' => wp_json_encode($payload),
            )
        );

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (is_wp_error($response) || $status_code !== 201) {
            $this->log('Error in creating payment', 'error', array(
                'status_code' => $status_code,
                'body' => $body,
            ));

            if (is_wp_error($response)) {
                return $response;
            }

            return new WP_Error('error_creating_payment', is_array($body) ? wp_json_encode($body) : (string) $body);
        }

        return $body;
    }

    public function get_payment_by_reference_id(string $app_id, string $token, $order_id)
    {
        $response = wp_remote_get(
            $this->base_url . '/api/v1/apps/' . rawurlencode($app_id) . '/payments/referenceId:' . rawurlencode((string) $order_id),
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                ),
            )
        );

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (is_wp_error($response) || $status_code !== 200) {
            $this->log('Error in fetching payment details', 'error', array(
                'status_code' => $status_code,
                'body' => $body,
            ));

            if (is_wp_error($response)) {
                return $response;
            }

            return new WP_Error('error_fetching_payment_details', is_array($body) ? wp_json_encode($body) : (string) $body);
        }

        return $body;
    }

    public function get_payment_by_id(string $app_id, string $token, string $payment_id)
    {
        $response = wp_remote_get(
            $this->base_url . '/api/v1/apps/' . rawurlencode($app_id) . '/payments/' . rawurlencode($payment_id),
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                ),
            )
        );

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (is_wp_error($response) || $status_code !== 200) {
            if (is_wp_error($response)) {
                return $response;
            }

            return new WP_Error('error_fetching_payment_by_id', is_array($body) ? wp_json_encode($body) : (string) $body);
        }

        return $body;
    }

    public function refund_payment(string $app_id, string $token, string $payment_id)
    {
        $response = wp_remote_post(
            $this->base_url . '/api/v1/apps/' . rawurlencode($app_id) . '/payments/' . rawurlencode($payment_id) . '/refund',
            array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ),
            )
        );

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (is_wp_error($response) || $status_code !== 200) {
            $this->log('Refund API error', 'error', array(
                'status_code' => $status_code,
                'body' => $body,
            ));

            if (is_wp_error($response)) {
                return $response;
            }

            return new WP_Error('refund_failed', is_array($body) ? wp_json_encode($body) : (string) $body);
        }

        return $body;
    }

    private function log(string $message, string $level = 'info', array $context = array()): void
    {
        call_user_func($this->logger, $message, $level, $context);
    }
}
