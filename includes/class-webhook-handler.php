<?php
/**
 * Webhook Handler - Registers the REST API webhook endpoint.
 *
 * Provides a secondary webhook URL via wp-json in case the
 * Fluent Forms built-in IPN route is not usable. The actual
 * processing logic is in OnePipe_PWT_Payment_Processor::handleWebhook().
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OnePipe_PWT_Webhook_Handler {

    /**
     * REST namespace.
     */
    const REST_NAMESPACE = 'onepipe-pwt/v1';

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register the webhook REST route.
     *
     * URL: /wp-json/onepipe-pwt/v1/webhook
     */
    public function register_routes() {
        register_rest_route( self::REST_NAMESPACE, '/webhook', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_rest_webhook' ),
            'permission_callback' => '__return_true', // Public endpoint, verified by signature.
        ) );
    }

    /**
     * Handle webhook via the REST API route.
     *
     * Delegates to the Fluent Forms IPN system by calling
     * the same action the built-in route triggers.
     *
     * @param WP_REST_Request $request The REST request.
     * @return WP_REST_Response
     */
    public function handle_rest_webhook( $request ) {
        // Trigger the same hook Fluent Forms uses for IPN.
        do_action( 'fluentform/ipn_endpoint_onepipe_pwt' );

        return new \WP_REST_Response( array( 'status' => 'processed' ), 200 );
    }
}
