<?php
/**
 * Payment Method - Registers OnePipe PayWithTransfer in Fluent Forms
 * payment settings and manages admin configuration fields.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FluentFormPro\Payments\PaymentMethods\BasePaymentMethod;

class OnePipe_PWT_Payment_Method extends BasePaymentMethod {

    /**
     * Option key used by Fluent Forms to store this method's settings.
     */
    const SETTINGS_KEY = 'fluentform_payment_settings_onepipe_pwt';

    public function __construct() {
        parent::__construct( 'onepipe_pwt' );
        $this->init();
    }

    /**
     * Register all hooks for settings, validation, and availability.
     */
    public function init() {
        add_filter( 'fluentform/payment_method_settings_validation_onepipe_pwt', array( $this, 'validateSettings' ), 10, 2 );

        if ( ! $this->isEnabled() ) {
            return;
        }

        add_filter( 'fluentform/transaction_data_' . $this->key, array( $this, 'modifyTransaction' ), 10, 1 );
        add_filter( 'fluentform/available_payment_methods', array( $this, 'pushPaymentMethodToForm' ) );

        ( new OnePipe_PWT_Payment_Processor() )->init();
    }

    /**
     * Define the admin settings fields shown in Fluent Forms > Payment Settings.
     *
     * @return array Settings configuration.
     */
    public function getGlobalFields() {
        return array(
            'label'  => 'OnePipe PayWithTransfer',
            'fields' => array(
                array(
                    'settings_key'   => 'is_active',
                    'type'           => 'yes-no-checkbox',
                    'label'          => __( 'Status', 'onepipe-pwt' ),
                    'checkbox_label' => __( 'Enable OnePipe PayWithTransfer Payment Method', 'onepipe-pwt' ),
                ),
                array(
                    'settings_key' => 'payment_mode',
                    'type'         => 'input-radio',
                    'label'        => __( 'Payment Mode', 'onepipe-pwt' ),
                    'options'      => array(
                        'sandbox' => __( 'Sandbox (inspect)', 'onepipe-pwt' ),
                        'live'    => __( 'Live', 'onepipe-pwt' ),
                    ),
                    'inline_help'  => __( 'Sandbox uses mock_mode=inspect. Live uses mock_mode=Live. Same API key works for both.', 'onepipe-pwt' ),
                    'check_status' => 'yes',
                ),
                array(
                    'settings_key' => 'api_key',
                    'type'         => 'input-text',
                    'label'        => __( 'API Key', 'onepipe-pwt' ),
                    'placeholder'  => __( 'Bearer token from OnePipe dashboard', 'onepipe-pwt' ),
                    'inline_help'  => __( 'Your OnePipe API key (Bearer token). The same key is used for both sandbox and live mode.', 'onepipe-pwt' ),
                    'check_status' => 'yes',
                ),
                array(
                    'settings_key' => 'api_secret',
                    'type'         => 'input-text',
                    'label'        => __( 'API Secret', 'onepipe-pwt' ),
                    'placeholder'  => __( 'Secret key for signature generation', 'onepipe-pwt' ),
                    'inline_help'  => __( 'Your OnePipe secret key. Used to generate the MD5 request signature and verify incoming webhooks.', 'onepipe-pwt' ),
                    'check_status' => 'yes',
                ),
                array(
                    'settings_key' => 'biller_code',
                    'type'         => 'input-text',
                    'label'        => __( 'Biller Code', 'onepipe-pwt' ),
                    'placeholder'  => __( 'e.g. 000042', 'onepipe-pwt' ),
                    'inline_help'  => __( 'Your unique biller code from the OnePipe dashboard. Identifies your merchant account.', 'onepipe-pwt' ),
                    'check_status' => 'yes',
                ),
                array(
                    'settings_key' => 'webhook_info',
                    'type'         => 'html_attr',
                    'label'        => __( 'Webhook URL', 'onepipe-pwt' ),
                    'inline_help'  => sprintf(
                        /* translators: %s: webhook URL */
                        __( 'Set this URL as your webhook in the OnePipe dashboard: %s', 'onepipe-pwt' ),
                        '<br><code>' . esc_url( site_url( '/?fluentform_payment_api_notify=onepipe_pwt' ) ) . '</code>'
                    ),
                ),
            ),
        );
    }

    /**
     * Retrieve saved settings from the database.
     *
     * @return array
     */
    public function getGlobalSettings() {
        return OnePipe_PWT_Payment_Method::getSettings();
    }

    /**
     * Static helper to retrieve settings (used by other classes too).
     *
     * @return array
     */
    public static function getSettings() {
        $defaults = array(
            'is_active'    => 'no',
            'payment_mode' => 'sandbox',
            'api_key'      => '',
            'api_secret'   => '',
            'biller_code'  => '',
        );

        $settings = get_option( self::SETTINGS_KEY, array() );

        return wp_parse_args( $settings, $defaults );
    }

    /**
     * Check if this payment method is enabled.
     *
     * @return bool
     */
    public function isEnabled() {
        $settings = $this->getGlobalSettings();
        return 'yes' === ( $settings['is_active'] ?? 'no' );
    }

    /**
     * Add this payment method to the list available in the form editor.
     *
     * @param array $methods Existing payment methods.
     * @return array
     */
    public function pushPaymentMethodToForm( $methods ) {
        $methods['onepipe_pwt'] = array(
            'title'        => __( 'Pay with Bank Transfer', 'onepipe-pwt' ),
            'enabled'      => 'yes',
            'method_value' => 'onepipe_pwt',
            'settings'     => array(
                'option_label' => array(
                    'type'     => 'text',
                    'template' => 'inputText',
                    'value'    => __( 'Pay with Bank Transfer (OnePipe)', 'onepipe-pwt' ),
                    'label'    => __( 'Payment Option Label', 'onepipe-pwt' ),
                ),
            ),
        );

        return $methods;
    }

    /**
     * Validate admin settings before save.
     *
     * @param array $errors   Existing errors.
     * @param array $settings Submitted settings.
     * @return array Errors array.
     */
    public function validateSettings( $errors, $settings ) {
        if ( 'yes' !== ( $settings['is_active'] ?? 'no' ) ) {
            return $errors;
        }

        if ( empty( $settings['api_key'] ) ) {
            $errors['onepipe_pwt_api_key'] = __( 'Please provide your OnePipe API Key.', 'onepipe-pwt' );
        }

        if ( empty( $settings['api_secret'] ) ) {
            $errors['onepipe_pwt_api_secret'] = __( 'Please provide your OnePipe API Secret.', 'onepipe-pwt' );
        }

        if ( empty( $settings['biller_code'] ) ) {
            $errors['onepipe_pwt_biller_code'] = __( 'Please provide your OnePipe Biller Code.', 'onepipe-pwt' );
        }

        return $errors;
    }

    /**
     * Modify transaction data for admin display.
     *
     * @param object $transaction Transaction object.
     * @return object
     */
    public function modifyTransaction( $transaction ) {
        if ( ! empty( $transaction->charge_id ) ) {
            $transaction->action_url = '';
        }
        return $transaction;
    }
}
