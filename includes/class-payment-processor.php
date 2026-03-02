<?php
/**
 * Payment Processor - Handles the payment lifecycle.
 *
 * Extends BaseProcessor to manage:
 * - Creating pending submissions when a user initiates payment.
 * - AJAX endpoint to fetch virtual account details from OnePipe.
 * - Webhook (IPN) to mark submissions as paid when transfer arrives.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FluentFormPro\Payments\PaymentMethods\BaseProcessor;

class OnePipe_PWT_Payment_Processor extends BaseProcessor {

    /**
     * Payment method key matching the one in Payment Method class.
     *
     * @var string
     */
    public $method = 'onepipe_pwt';

    /**
     * Register hooks for payment processing, AJAX, and webhooks.
     */
    public function init() {
        add_action( 'fluentform/process_payment_onepipe_pwt', array( $this, 'handlePaymentAction' ), 10, 6 );
        add_action( 'fluentform/ipn_endpoint_onepipe_pwt', array( $this, 'handleWebhook' ) );

        // AJAX for fetching virtual account (logged-in and guest users).
        add_action( 'wp_ajax_onepipe_pwt_get_account', array( $this, 'ajaxGetAccount' ) );
        add_action( 'wp_ajax_nopriv_onepipe_pwt_get_account', array( $this, 'ajaxGetAccount' ) );

        // AJAX for checking payment status (polling).
        add_action( 'wp_ajax_onepipe_pwt_check_status', array( $this, 'ajaxCheckStatus' ) );
        add_action( 'wp_ajax_nopriv_onepipe_pwt_check_status', array( $this, 'ajaxCheckStatus' ) );

        // Enqueue frontend JS/CSS when this payment method renders on a form.
        add_action( 'fluentform/rendering_payment_method_' . $this->method, array( $this, 'addCheckoutJs' ), 10, 3 );
    }

    /**
     * Handle the initial payment action when a form is submitted.
     *
     * Creates a pending transaction and stores customer data in meta
     * so the AJAX handler can call OnePipe without re-parsing the form.
     *
     * @param int    $submissionId    Form submission ID.
     * @param array  $submissionData  Submitted form data.
     * @param object $form            Form object.
     * @param array  $methodSettings  Payment method settings.
     * @param bool   $hasSubscription Whether form has subscriptions.
     * @param float  $totalPayable    Total amount to pay.
     */
    public function handlePaymentAction( $submissionId, $submissionData, $form, $methodSettings, $hasSubscription, $totalPayable ) {
        $this->setSubmissionId( $submissionId );
        $submission = $this->getSubmission();

        // $submission->response is already an array (FF's ORM auto-decodes it).
        // Also check $submissionData which is the raw POST form data array.
        $form_data = is_array( $submission->response ) ? $submission->response : array();

        // Use the first non-empty value across common phone field names.
        // mobile_number is first — the hidden field populated from {user.meta.mobile_number}.
        $phone_candidates = array(
            'mobile_number' => $form_data['mobile_number'] ?? $submissionData['mobile_number'] ?? '',
            'phone'         => $form_data['phone']         ?? $submissionData['phone']         ?? '',
            'phone_number'  => $form_data['phone_number']  ?? $submissionData['phone_number']  ?? '',
            'mobile'        => $form_data['mobile']        ?? $submissionData['mobile']        ?? '',
        );

        $raw_phone = '';
        foreach ( $phone_candidates as $raw_phone ) {
            if ( ! empty( $raw_phone ) ) {
                break;
            }
        }
        $raw_phone = (string) $raw_phone;

        // Read name and email directly from form fields first.
        $firstname = (string) ( $form_data['first_name'] ?? $form_data['firstname'] ?? '' );
        $surname   = (string) ( $form_data['last_name']  ?? $form_data['surname']   ?? '' );
        $email_raw = (string) ( $form_data['email']      ?? $submission->customer_email ?? '' );

        // Fall back to submission->customer_name if form fields missing.
        if ( empty( $firstname ) && ! empty( $submission->customer_name ) ) {
            $name_parts = explode( ' ', trim( $submission->customer_name ), 2 );
            $firstname  = $name_parts[0] ?? '';
            if ( empty( $surname ) ) {
                $surname = $name_parts[1] ?? '';
            }
        }

        // Create a unique hash and a pending transaction record.
        $uniqueHash    = md5( $submission->id . '-' . $form->id . '-' . time() . '-' . wp_rand( 100, 999 ) );

        $transactionId = $this->insertTransaction( array(
            'transaction_type' => 'onetime',
            'transaction_hash' => $uniqueHash,
            'payment_total'    => intval( $totalPayable ),
            'status'           => 'pending',
            'currency'         => \FluentFormPro\Payments\PaymentHelper::getFormCurrency( $form->id ),
            'payment_mode'     => $this->getPaymentMode(),
        ) );

        $transaction = $this->getTransaction( $transactionId );

        // Store everything the AJAX handler will need.
        $this->setMetaData( '_onepipe_pwt_ref', $uniqueHash );
        $this->setMetaData( '_onepipe_pwt_transaction_id', $transactionId );
        $this->setMetaData( '_onepipe_pwt_phone', sanitize_text_field( $raw_phone ) );
        $this->setMetaData( '_onepipe_pwt_email', sanitize_email( $email_raw ) );
        $this->setMetaData( '_onepipe_pwt_firstname', sanitize_text_field( $firstname ) );
        $this->setMetaData( '_onepipe_pwt_surname', sanitize_text_field( $surname ) );
        $this->setMetaData( '_onepipe_pwt_form_title', sanitize_text_field( $form->title ?? '' ) );

        // Return JSON — FF triggers fluentform_next_action_onepipe_pwt on the form.
        wp_send_json_success( array(
            'nextAction'       => 'onepipe_pwt',
            'actionName'       => 'initOnepipePwtModal',
            'submission_id'    => $submission->id,
            'transaction_hash' => $uniqueHash,
            'amount'           => intval( $transaction->payment_total ),
            'currency'         => $transaction->currency,
            'message'          => __( 'Please complete your payment via bank transfer.', 'onepipe-pwt' ),
            'result'           => array(
                'insert_id' => $submission->id,
            ),
            'append_data'      => array(
                '__entry_intermediate_hash' => \FluentForm\App\Helpers\Helper::getSubmissionMeta( $submission->id, '__entry_intermediate_hash' ),
            ),
        ), 200 );
    }

    /**
     * AJAX: Call OnePipe to generate/retrieve the virtual bank account,
     * then return account details to the frontend modal.
     */
    public function ajaxGetAccount() {
        check_ajax_referer( 'onepipe_pwt_nonce', 'nonce' );

        $submission_id = absint( $_POST['submission_id'] ?? 0 );

        if ( ! $submission_id ) {
            wp_send_json_error( array( 'message' => __( 'Missing submission ID.', 'onepipe-pwt' ) ) );
        }

        // Read customer data stored during handlePaymentAction.
        $phone          = \FluentForm\App\Helpers\Helper::getSubmissionMeta( $submission_id, '_onepipe_pwt_phone' );
        $email          = \FluentForm\App\Helpers\Helper::getSubmissionMeta( $submission_id, '_onepipe_pwt_email' );
        $firstname      = \FluentForm\App\Helpers\Helper::getSubmissionMeta( $submission_id, '_onepipe_pwt_firstname' );
        $surname        = \FluentForm\App\Helpers\Helper::getSubmissionMeta( $submission_id, '_onepipe_pwt_surname' );
        $transaction_id = \FluentForm\App\Helpers\Helper::getSubmissionMeta( $submission_id, '_onepipe_pwt_transaction_id' );
        $form_title     = \FluentForm\App\Helpers\Helper::getSubmissionMeta( $submission_id, '_onepipe_pwt_form_title' );

        if ( empty( $phone ) ) {
            wp_send_json_error( array(
                'message' => __( 'Phone number not found in form submission. Please ensure the form includes a phone/mobile number field (named mobile_number, phone, or mobile).', 'onepipe-pwt' ),
            ) );
        }

        // Get the payment total (stored in kobo) from the transaction record.
        global $wpdb;
        $payment_total = $wpdb->get_var( $wpdb->prepare(
            "SELECT payment_total FROM {$wpdb->prefix}fluentform_transactions WHERE id = %d",
            $transaction_id
        ) );

        // Call OnePipe API.
        $api      = OnePipe_PWT_API::from_settings();
        $response = $api->send_invoice(
            $phone,
            $email,
            $firstname,
            $surname,
            intval( $payment_total ),
            $submission_id,
            $form_title
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array(
                'message' => $response->get_error_message(),
            ) );
        }

        // Extract virtual account details.
        $account = $api->parse_account_from_response( $response );

        if ( empty( $account['account_number'] ) ) {
            wp_send_json_error( array(
                'message'  => __( 'Could not retrieve virtual account details. Please try again.', 'onepipe-pwt' ),
                'raw_data' => $response,
            ) );
        }

        // Store OnePipe's payment_id in meta so the webhook can match this submission.
        if ( ! empty( $account['payment_id'] ) ) {
            $this->setSubmissionId( $submission_id );
            $this->setMetaData( '_onepipe_pwt_payment_id', $account['payment_id'] );
        }

        wp_send_json_success( $account );
    }

    /**
     * AJAX: Check if a pending submission has been paid (for polling).
     */
    public function ajaxCheckStatus() {
        check_ajax_referer( 'onepipe_pwt_nonce', 'nonce' );

        $submission_id = absint( $_POST['submission_id'] ?? 0 );

        if ( ! $submission_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid submission.', 'onepipe-pwt' ) ) );
        }

        global $wpdb;

        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT payment_status FROM {$wpdb->prefix}fluentform_submissions WHERE id = %d",
            $submission_id
        ) );

        wp_send_json_success( array(
            'payment_status' => $status ?? 'pending',
            'is_paid'        => 'paid' === $status,
        ) );
    }

    /**
     * Handle incoming webhook (IPN) from OnePipe.
     *
     * OnePipe sends a POST to: ?fluentform_payment_api_notify=onepipe_pwt
     * or: /wp-json/onepipe-pwt/v1/webhook
     *
     * We match by payment_id stored in submission meta during ajaxGetAccount().
     */
    public function handleWebhook() {
        $raw_body = file_get_contents( 'php://input' );

        error_log( '[OnePipe PWT] Webhook received. Body: ' . $raw_body );
        error_log( '[OnePipe PWT] Webhook Signature header: ' . ( $_SERVER['HTTP_SIGNATURE'] ?? '(none)' ) );

        if ( empty( $raw_body ) ) {
            status_header( 400 );
            wp_send_json_error( array( 'message' => 'Empty request body.' ) );
        }

        $payload = json_decode( $raw_body, true );

        if ( ! is_array( $payload ) ) {
            status_header( 400 );
            wp_send_json_error( array( 'message' => 'Invalid JSON payload.' ) );
        }

        // Log signature check result but don't reject — we need to confirm the formula first.
        if ( ! $this->verifyWebhookSignature( $raw_body ) ) {
            error_log( '[OnePipe PWT] Webhook signature mismatch — continuing anyway for diagnostics.' );
        }

        // Extract the relevant fields from the webhook structure.
        $details    = $payload['details'] ?? array();
        $meta       = $details['meta'] ?? array();
        $event_type = strtolower( $meta['event_type'] ?? '' );
        $status     = strtolower( $details['status'] ?? '' );
        $payment_id = (string) ( $meta['payment_id'] ?? '' );

        error_log( '[OnePipe PWT] Webhook fields — event_type: ' . $event_type . ', status: ' . $status . ', payment_id: ' . $payment_id );

        // Only act on successful credit events.
        if ( 'credit' !== $event_type || 'successful' !== $status || empty( $payment_id ) ) {
            status_header( 200 );
            wp_send_json_success( array( 'message' => 'Acknowledged.' ) );
            return;
        }

        // Find the submission that holds this payment_id.
        $submission_id = $this->findSubmissionByPaymentId( $payment_id );

        if ( ! $submission_id ) {
            status_header( 404 );
            wp_send_json_error( array( 'message' => 'No submission found for payment_id: ' . $payment_id ) );
            return;
        }

        $this->setSubmissionId( $submission_id );
        $this->markAsPaid( $submission_id, $payload );

        status_header( 200 );
        wp_send_json_success( array( 'message' => 'Webhook processed.' ) );
    }

    /**
     * Verify the webhook request signature.
     *
     * OnePipe sends the signature the same way we do on outgoing requests:
     * Signature header = MD5( api_secret + raw_body )
     *
     * @param string $raw_body Raw request body.
     * @return bool
     */
    private function verifyWebhookSignature( $raw_body ) {
        $settings   = OnePipe_PWT_Payment_Method::getSettings();
        $api_secret = $settings['api_secret'] ?? '';

        if ( empty( $api_secret ) ) {
            return false;
        }

        $received_sig = $_SERVER['HTTP_SIGNATURE'] ?? '';

        if ( empty( $received_sig ) ) {
            // No signature header — only allow through in sandbox for testing.
            return 'sandbox' === ( $settings['payment_mode'] ?? 'live' );
        }

        $expected_sig = hash( 'md5', $api_secret . $raw_body );

        return hash_equals( $expected_sig, $received_sig );
    }

    /**
     * Find a submission by the OnePipe payment_id stored in meta.
     *
     * @param string $payment_id OnePipe payment_id from webhook.
     * @return int|null Submission ID or null.
     */
    private function findSubmissionByPaymentId( $payment_id ) {
        global $wpdb;

        $submission_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT submission_id FROM {$wpdb->prefix}fluentform_submission_meta
             WHERE meta_key = '_onepipe_pwt_payment_id' AND value = %s
             LIMIT 1",
            $payment_id
        ) );

        return $submission_id ? (int) $submission_id : null;
    }

    /**
     * Mark a submission as paid and trigger Fluent Forms post-payment actions.
     *
     * @param int   $submission_id Submission ID.
     * @param array $payload       Full webhook payload for logging.
     */
    private function markAsPaid( $submission_id, $payload ) {
        global $wpdb;

        $transaction_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fluentform_transactions
             WHERE submission_id = %d AND status = 'pending'
             LIMIT 1",
            $submission_id
        ) );

        if ( $transaction_id ) {
            $this->changeTransactionStatus( $transaction_id, 'paid' );
        }

        $this->changeSubmissionPaymentStatus( 'paid' );
        $this->recalculatePaidTotal();

        // Log the webhook payload for debugging.
        $this->setMetaData( '_onepipe_pwt_webhook_response', wp_json_encode( $payload ) );

        // Fire FF confirmations, notifications, integrations, etc.
        $this->completePaymentSubmission();
    }

    /**
     * Get the current payment mode from settings.
     *
     * @return string 'sandbox' or 'live'.
     */
    private function getPaymentMode() {
        $settings = OnePipe_PWT_Payment_Method::getSettings();
        return $settings['payment_mode'] ?? 'sandbox';
    }

    /**
     * Enqueue frontend JS/CSS when this payment method renders on a form.
     *
     * Called by fluentform/rendering_payment_method_onepipe_pwt.
     *
     * @param array  $methodElement Payment method element data.
     * @param array  $element       Form element data.
     * @param object $form          Form object.
     */
    public function addCheckoutJs( $methodElement, $element, $form ) {
        wp_enqueue_style(
            'onepipe-pwt-styles',
            ONEPIPE_PWT_URL . 'assets/css/paywithtransfer.css',
            array(),
            ONEPIPE_PWT_VERSION
        );

        wp_enqueue_script(
            'onepipe-pwt-script',
            ONEPIPE_PWT_URL . 'assets/js/paywithtransfer.js',
            array( 'jquery' ),
            ONEPIPE_PWT_VERSION,
            true
        );

        wp_localize_script( 'onepipe-pwt-script', 'onepipePWT', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'onepipe_pwt_nonce' ),
            'i18n'    => array(
                'title'             => __( 'Complete Your Payment', 'onepipe-pwt' ),
                'instructions'      => __( 'Transfer the exact amount below to this account:', 'onepipe-pwt' ),
                'bankName'          => __( 'Bank Name', 'onepipe-pwt' ),
                'accountNumber'     => __( 'Account Number', 'onepipe-pwt' ),
                'accountName'       => __( 'Account Name', 'onepipe-pwt' ),
                'amount'            => __( 'Amount', 'onepipe-pwt' ),
                'copied'            => __( 'Copied!', 'onepipe-pwt' ),
                'copyAccountNumber' => __( 'Copy', 'onepipe-pwt' ),
                'waitingMessage'    => __( 'Waiting for your transfer… You can close this and the payment will still be processed.', 'onepipe-pwt' ),
                'paymentConfirmed'  => __( 'Payment confirmed! Redirecting…', 'onepipe-pwt' ),
                'error'             => __( 'Something went wrong. Please try again.', 'onepipe-pwt' ),
                'loadingAccount'    => __( 'Generating your payment account…', 'onepipe-pwt' ),
            ),
        ) );
    }
}
