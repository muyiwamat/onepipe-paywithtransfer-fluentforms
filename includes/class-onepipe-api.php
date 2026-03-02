<?php
/**
 * OnePipe API Wrapper - Handles HTTP communication with the OnePipe v2 API.
 *
 * Uses wp_remote_post() for all requests. Supports the "send invoice"
 * request type which generates (or retrieves) a virtual bank account
 * for the customer to pay into.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OnePipe_PWT_API {

    const API_URL = 'https://api.onepipe.io/v2/transact';

    /**
     * @var string Bearer token.
     */
    private $api_key;

    /**
     * @var string Secret for MD5 signature generation.
     */
    private $api_secret;

    /**
     * @var string Biller code from OnePipe merchant dashboard.
     */
    private $biller_code;

    /**
     * @var bool Whether to use sandbox (inspect) mode.
     */
    private $is_sandbox;

    /**
     * @param string $api_key     Bearer token.
     * @param string $api_secret  Secret key.
     * @param string $biller_code Biller code.
     * @param bool   $is_sandbox  Sandbox mode flag.
     */
    public function __construct( $api_key, $api_secret, $biller_code, $is_sandbox = false ) {
        $this->api_key     = $api_key;
        $this->api_secret  = $api_secret;
        $this->biller_code = $biller_code;
        $this->is_sandbox  = $is_sandbox;
    }

    /**
     * Create a new API instance from saved plugin settings.
     *
     * @return self
     */
    public static function from_settings() {
        $settings = OnePipe_PWT_Payment_Method::getSettings();

        return new self(
            trim( $settings['api_key']     ?? '' ),
            trim( $settings['api_secret']  ?? '' ),
            trim( $settings['biller_code'] ?? '' ),
            'sandbox' === ( $settings['payment_mode'] ?? 'sandbox' )
        );
    }

    /**
     * Send a payment invoice request to OnePipe.
     *
     * OnePipe generates a virtual account for the customer (identified by
     * their phone number) and returns the account details for them to pay into.
     * Subsequent calls with the same phone number return the same account.
     *
     * @param string $phone         Customer phone (Nigerian format: 08012345678 or international: 2348012345678).
     * @param string $email         Customer email.
     * @param string $firstname     Customer first name.
     * @param string $surname       Customer last name.
     * @param int    $amount_kobo   Amount in kobo (smallest currency unit).
     * @param int    $submission_id Fluent Forms submission ID (used to build unique refs).
     * @return array|WP_Error Parsed response or error.
     */
    public function send_invoice( $phone, $email, $firstname, $surname, $amount_kobo, $submission_id, $form_title = '' ) {
        // request_ref must be unique per API call.
        $transaction_ref = 'ff_' . intval( $submission_id ) . '_' . time();
        error_log( '[OnePipe PWT] Submission #' . $submission_id . ' transaction_ref: ' . $transaction_ref );

        $payload = array(
            'request_ref'  => $transaction_ref,
            'request_type' => 'send invoice',
            'auth'         => array(
                'type'          => null,
                'secure'        => null,
                'auth_provider' => 'PaywithAccount',
                'route_mode'    => null,
            ),
            'transaction'  => array(
                'mock_mode'              => $this->is_sandbox ? 'inspect' : 'Live',
                'transaction_ref'        => $transaction_ref,
                'transaction_desc'       => ! empty( $form_title ) ? $form_title : 'Payment via Fluent Forms',
                'transaction_ref_parent' => null,
                'amount'                 => intval( $amount_kobo ),
                'customer'               => array(
                    'customer_ref' => $this->normalize_phone( $phone ),
                    'firstname'    => $firstname,
                    'surname'      => $surname,
                    'email'        => $email,
                    'mobile_no'    => $this->normalize_phone( $phone ),
                ),
                'meta'    => array(
                    'type'           => 'single_payment',
                    'expires_in'     => 30,
                    'skip_messaging' => false,
                    'biller_code'    => $this->biller_code,
                ),
                'details' => new \stdClass(),
            ),
        );

        return $this->make_request( $payload );
    }

    /**
     * Extract virtual account details from a successful send_invoice response.
     *
     * Response path: data.provider_response.meta.*
     *
     * @param array $response Decoded API response.
     * @return array Account details with keys: account_number, bank_name, account_name, payment_id.
     */
    public function parse_account_from_response( $response ) {
        $meta = $response['data']['provider_response']['meta'] ?? array();

        return array(
            'account_number' => (string) ( $meta['virtual_account_number'] ?? '' ),
            'bank_name'      => (string) ( $meta['virtual_account_bank_name'] ?? '' ),
            'account_name'   => (string) ( $meta['virtual_account_name'] ?? '' ),
            'payment_id'     => (string) ( $meta['payment_id'] ?? '' ),
        );
    }

    /**
     * Normalize a phone number to international format (2348012345678).
     *
     * @param string $phone Raw phone number.
     * @return string Normalized phone in international format without +.
     */
    public function normalize_phone( $phone ) {
        // Strip everything except digits.
        $phone = preg_replace( '/\D/', '', (string) $phone );

        // Convert Nigerian local format (0xxxxxxxxxx) to international (234xxxxxxxxxx).
        if ( 11 === strlen( $phone ) && '0' === $phone[0] ) {
            $phone = '234' . substr( $phone, 1 );
        }

        return $phone;
    }

    /**
     * Generate the Signature header value.
     *
     * Signature = MD5( request_ref + ';' + api_secret )
     *
     * @param string $request_ref The request_ref from the payload.
     * @return string MD5 hash signature.
     */
    public function generate_signature( $request_ref ) {
        return md5( $request_ref . ';' . $this->api_secret );
    }

    /**
     * Get the api_secret (used by webhook signature verification).
     *
     * @return string
     */
    public function get_api_secret() {
        return $this->api_secret;
    }

    /**
     * Send a POST request to the OnePipe API.
     *
     * @param array $payload Request payload.
     * @return array|WP_Error Decoded response body or WP_Error.
     */
    private function make_request( $payload ) {
        $json_body = wp_json_encode( $payload );

        if ( false === $json_body ) {
            return new \WP_Error( 'onepipe_pwt_json_error', __( 'Failed to encode request payload.', 'onepipe-pwt' ) );
        }

        $computed_sig = $this->generate_signature( $payload['request_ref'] );

        $response = wp_remote_post(
            self::API_URL,
            array(
                'timeout' => 15,
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Signature'     => $computed_sig,
                ),
                'body'    => $json_body,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $decoded     = json_decode( $body, true );

        if ( null === $decoded ) {
            return new \WP_Error(
                'onepipe_pwt_invalid_response',
                __( 'Invalid JSON response from OnePipe API.', 'onepipe-pwt' ),
                array( 'status_code' => $status_code, 'body' => $body )
            );
        }

        if ( $status_code < 200 || $status_code >= 300 ) {
            error_log( '[OnePipe PWT] API error ' . $status_code . ': ' . $body );
            return new \WP_Error(
                'onepipe_pwt_api_error',
                $decoded['message'] ?? __( 'OnePipe API request failed.', 'onepipe-pwt' ),
                $decoded
            );
        }

        // OnePipe sometimes returns HTTP 200 with "status":"Failed" in the body.
        if ( 'Failed' === ( $decoded['status'] ?? '' ) ) {
            $error_msg = $decoded['data']['error']['message']
                ?? $decoded['data']['errors'][0]['message']
                ?? $decoded['message']
                ?? __( 'OnePipe API request failed.', 'onepipe-pwt' );
            error_log( '[OnePipe PWT] API failed (HTTP 200): ' . $body );
            return new \WP_Error( 'onepipe_pwt_api_error', $error_msg, $decoded );
        }

        return $decoded;
    }
}
