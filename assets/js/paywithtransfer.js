(function ($) {
    'use strict';

    if (typeof onepipePWT === 'undefined') {
        return;
    }

    var config = onepipePWT;
    var i18n   = config.i18n;
    var pollInterval = null;

    /**
     * OnePipe PWT handler class.
     * Follows the same pattern as Paystack/RazorPay in Fluent Forms.
     */
    function OnePipePwtHandler($form, formInstance) {
        this.$form = $form;
        this.formInstance = formInstance;
        this.formId = formInstance.settings.id;
    }

    OnePipePwtHandler.prototype.init = function () {
        var self = this;

        // Fluent Forms triggers this event when our payment method's
        // handlePaymentAction returns with nextAction = 'onepipe_pwt'.
        this.$form.on('fluentform_next_action_onepipe_pwt', function (e, actionData) {
            var data = actionData.response.data;

            // Show a message below the form.
            self.$form.parent().find('.ff_pwt_msg').remove();
            $('<div/>', {
                class: 'ff-message-success ff_pwt_msg'
            }).html(data.message).insertAfter(self.$form);

            if (data.actionName === 'initOnepipePwtModal') {
                self.showPaymentModal(data);
            }
        });
    };

    /**
     * Show the payment modal with loading state, then fetch account details.
     */
    OnePipePwtHandler.prototype.showPaymentModal = function (data) {
        var self = this;

        // Hide the form's progress indicator.
        this.formInstance.hideFormSubmissionProgress(this.$form);

        showLoadingModal();
        fetchAccountDetails(data, this.$form, function (account) {
            showAccountModal(account, data);
            startPolling(data.submission_id);
        });
    };

    /**
     * Initialize handler on all payment forms.
     */
    function initForms($) {
        $.each($('form.fluentform_has_payment'), function () {
            var $form = $(this);

            function onFormInit(e, formInstance) {
                new OnePipePwtHandler($form, formInstance).init();
            }

            $form.off('fluentform_init_single', '', onFormInit);
            $form.on('fluentform_init_single', onFormInit);
        });
    }

    initForms($);
    $(document).ready(function () {
        initForms($);
    });

    // ─── Helper functions ────────────────────────────────────────────

    function showLoadingModal() {
        removeExistingModal();

        var html = '<div class="onepipe-pwt-overlay" id="onepipe-pwt-modal">' +
            '<div class="onepipe-pwt-modal">' +
                '<div class="onepipe-pwt-modal-body onepipe-pwt-loading">' +
                    '<div class="onepipe-pwt-spinner"></div>' +
                    '<p>' + i18n.loadingAccount + '</p>' +
                '</div>' +
            '</div>' +
        '</div>';

        $('body').append(html);
    }

    function fetchAccountDetails(data, $form, onSuccess) {
        var formData = getFormFieldValues($form);

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action:        'onepipe_pwt_get_account',
                nonce:         config.nonce,
                submission_id: data.submission_id,
                email:         formData.email,
                firstname:     formData.firstname,
                surname:       formData.surname,
                phone:         formData.phone
            },
            success: function (response) {
                if (response.success && response.data) {
                    onSuccess(response.data);
                } else {
                    showErrorModal(response.data ? response.data.message : i18n.error);
                }
            },
            error: function () {
                showErrorModal(i18n.error);
            }
        });
    }

    function showAccountModal(account, paymentData) {
        var amount = formatAmount(paymentData.amount, paymentData.currency);

        var html = '<div class="onepipe-pwt-overlay" id="onepipe-pwt-modal">' +
            '<div class="onepipe-pwt-modal">' +
                '<div class="onepipe-pwt-modal-header">' +
                    '<h3>' + i18n.title + '</h3>' +
                '</div>' +
                '<div class="onepipe-pwt-modal-body">' +
                    '<p class="onepipe-pwt-instructions">' + i18n.instructions + '</p>' +
                    '<div class="onepipe-pwt-detail">' +
                        '<span class="onepipe-pwt-label">' + i18n.bankName + '</span>' +
                        '<span class="onepipe-pwt-value">' + escapeHtml(account.bank_name) + '</span>' +
                    '</div>' +
                    '<div class="onepipe-pwt-detail onepipe-pwt-account-row">' +
                        '<span class="onepipe-pwt-label">' + i18n.accountNumber + '</span>' +
                        '<span class="onepipe-pwt-value">' +
                            '<strong class="onepipe-pwt-acct-num">' + escapeHtml(account.account_number) + '</strong>' +
                            '<button type="button" class="onepipe-pwt-copy-btn" data-copy="' + escapeHtml(account.account_number) + '">' +
                                i18n.copyAccountNumber +
                            '</button>' +
                        '</span>' +
                    '</div>' +
                    '<div class="onepipe-pwt-detail">' +
                        '<span class="onepipe-pwt-label">' + i18n.accountName + '</span>' +
                        '<span class="onepipe-pwt-value">' + escapeHtml(account.account_name) + '</span>' +
                    '</div>' +
                    '<div class="onepipe-pwt-detail onepipe-pwt-amount-row">' +
                        '<span class="onepipe-pwt-label">' + i18n.amount + '</span>' +
                        '<span class="onepipe-pwt-value"><strong>' + amount + '</strong></span>' +
                    '</div>' +
                    '<div class="onepipe-pwt-waiting">' +
                        '<div class="onepipe-pwt-spinner"></div>' +
                        '<p>' + i18n.waitingMessage + '</p>' +
                    '</div>' +
                '</div>' +
                '<div class="onepipe-pwt-modal-footer">' +
                    '<button type="button" class="onepipe-pwt-close-btn">&times; Close</button>' +
                '</div>' +
            '</div>' +
        '</div>';

        removeExistingModal();
        $('body').append(html);

        $(document).on('click.onepipePwt', '.onepipe-pwt-copy-btn', function () {
            var text = $(this).data('copy');
            var $btn = $(this);

            function onCopied() {
                $btn.text(i18n.copied);
                setTimeout(function () { $btn.text(i18n.copyAccountNumber); }, 2000);
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(onCopied).catch(function () {
                    fallbackCopy(text, onCopied);
                });
            } else {
                fallbackCopy(text, onCopied);
            }
        });

        $(document).on('click.onepipePwt', '.onepipe-pwt-close-btn', function () {
            removeExistingModal();
        });
    }

    function showErrorModal(message) {
        var html = '<div class="onepipe-pwt-overlay" id="onepipe-pwt-modal">' +
            '<div class="onepipe-pwt-modal">' +
                '<div class="onepipe-pwt-modal-header">' +
                    '<h3>' + i18n.error + '</h3>' +
                '</div>' +
                '<div class="onepipe-pwt-modal-body">' +
                    '<p class="onepipe-pwt-error-msg">' + escapeHtml(message) + '</p>' +
                '</div>' +
                '<div class="onepipe-pwt-modal-footer">' +
                    '<button type="button" class="onepipe-pwt-close-btn">&times; Close</button>' +
                '</div>' +
            '</div>' +
        '</div>';

        removeExistingModal();
        $('body').append(html);

        $(document).on('click.onepipePwt', '.onepipe-pwt-close-btn', function () {
            removeExistingModal();
        });
    }

    function showSuccessState() {
        var $modal = $('#onepipe-pwt-modal');
        $modal.find('.onepipe-pwt-waiting').html(
            '<p class="onepipe-pwt-success">' + i18n.paymentConfirmed + '</p>'
        );
        setTimeout(function () { window.location.reload(); }, 2500);
    }

    function startPolling(submissionId) {
        if (pollInterval) { clearInterval(pollInterval); }

        pollInterval = setInterval(function () {
            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action:        'onepipe_pwt_check_status',
                    nonce:         config.nonce,
                    submission_id: submissionId
                },
                success: function (response) {
                    if (response.success && response.data && response.data.is_paid) {
                        clearInterval(pollInterval);
                        showSuccessState();
                    }
                }
            });
        }, 20000);
    }

    function getFormFieldValues($form) {
        var result = { email: '', firstname: '', surname: '', phone: '' };
        if (!$form || !$form.length) { return result; }

        var $email = $form.find('input[type="email"], input[name*="email"]').first();
        if ($email.length) { result.email = $email.val(); }

        var $first = $form.find('input[name*="first_name"], input[name*="names[first_name]"]').first();
        if ($first.length) { result.firstname = $first.val(); }

        var $last = $form.find('input[name*="last_name"], input[name*="names[last_name]"]').first();
        if ($last.length) { result.surname = $last.val(); }

        var $phone = $form.find('input[type="tel"], input[name*="phone"]').first();
        if ($phone.length) { result.phone = $phone.val(); }

        return result;
    }

    function formatAmount(amount, currency) {
        var num = parseFloat(amount);
        if (isNaN(num)) { return amount; }
        num = num / 100; // FF stores in minor units.
        try {
            return new Intl.NumberFormat('en-NG', {
                style: 'currency',
                currency: currency || 'NGN',
                minimumFractionDigits: 2
            }).format(num);
        } catch (e) {
            return (currency || 'NGN') + ' ' + num.toFixed(2);
        }
    }

    function fallbackCopy(text, callback) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.top = '0';
        textarea.style.left = '0';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        try {
            document.execCommand('copy');
            callback();
        } catch (e) {}
        document.body.removeChild(textarea);
    }

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function removeExistingModal() {
        $(document).off('click.onepipePwt');
        $('#onepipe-pwt-modal').remove();
        if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
    }

})(jQuery);
