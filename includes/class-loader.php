<?php
/**
 * Loader - Bootstraps all plugin components.
 *
 * Checks for required Fluent Forms Pro classes, then loads and
 * initializes the payment method, processor, API wrapper, and webhook handler.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OnePipe_PWT_Loader {

    /**
     * Load and initialize all plugin components.
     */
    public function init() {
        if ( ! class_exists( 'FluentFormPro\Payments\PaymentMethods\BasePaymentMethod' ) ) {
            return;
        }

        $this->load_files();
        $this->init_components();
    }

    /**
     * Require all class files.
     */
    private function load_files() {
        $includes = ONEPIPE_PWT_PATH . 'includes/';

        require_once $includes . 'class-onepipe-api.php';
        require_once $includes . 'class-payment-method.php';
        require_once $includes . 'class-payment-processor.php';
        require_once $includes . 'class-webhook-handler.php';
    }

    /**
     * Instantiate and wire up components.
     */
    private function init_components() {
        ( new OnePipe_PWT_Payment_Method() );
        new OnePipe_PWT_Webhook_Handler();
    }
}
