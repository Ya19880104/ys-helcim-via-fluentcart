/**
 * YS Helcim via FluentCart — helcim.js inline card-form checkout flow (ys_helcim_js)
 *
 * Flow (Verify tokenize -> server-side capture):
 * 1. Listen for `fluent_cart_load_payments_ys_helcim_js` -> render the card form inside the container
 *    (fields carry an id only, never a name attribute — sensitive values must not enter FluentCart's form serialization)
 * 2. On pay click -> basic client-side validation -> await orderHandler() to create the order
 *    -> read payment_data.{transaction_uuid, confirm_nonce, confirm_token, js_token}
 * 3. Populate #token and normalize SDK fields -> call helcimProcess() (helcim.js SDK tokenize)
 * 4. Completion detection: poll for input#response appearing inside #helcimResults (primary) plus window.helcimJsCallback (secondary)
 * 5. response != 1 -> show an error; success -> collect every hidden input inside #helcimResults
 *    -> AJAX confirm (server captures via /v2/payment/purchase using the cardToken) -> redirect to the receipt page
 *
 * Security notes:
 * - The merchant confirmation request contains only the SDK XML and xmlHash
 *   proof envelope. The server authenticates that envelope with the Helcim.js
 *   Secret Key and extracts the cardToken from the verified XML.
 * - The full card number and CVV never appear in the XML proof or the request.
 * - The server binds the request to the checkout transaction and treats the v2
 *   payment/purchase response as the authoritative charge proof.
 *
 * @package YangSheep\Helcim\FluentCart
 */
(function () {
    'use strict';

    var SLUG = 'ys_helcim_js';
    var CONTAINER_SELECTOR = '.fluent-cart-checkout_embed_payment_container_' + SLUG;
    var POLL_INTERVAL_MS = 400;      // helcimResults polling interval
    var POLL_TIMEOUT_MS = 120000;    // tokenize wait limit (2 minutes)
    // Server status checks after an uncertain confirmation (about 2.5 minutes in total)
    var STATUS_POLL_DELAYS_MS = [1500, 2000, 3000, 4000, 5000, 6000, 8000, 10000, 10000, 15000, 15000, 20000, 30000, 30000];

    /** Server-side localized data (read defensively; abort and log if missing) */
    var cfg = window.ys_helcim_js_fct_data || null;

    /** Module-level flow state */
    var state = {
        processing: false,       // Whether a payment flow is currently in progress (guards against double clicks)
        resultHandled: false,    // Whether the tokenize result has been handled (single gate shared by callback and polling)
        pollTimer: null,         // Polling timer
        expiryDisplayValue: '',  // The expiry display value from before helcimProcess (used to restore on failure)
        reloadRequired: false,   // An unresolved provider request permits no same-page retry
        reloadMessage: ''        // Preserve the exact safe recovery instruction across fragment reloads
    };

    /** The result handler for the current flow (delegated to by the global helcimJsCallback) */
    var activeResultHandler = null;

    /**
     * Get a localized string (translations can be overridden by the server)
     *
     * @param {string} key Translation key
     * @returns {string}
     */
    function t(key) {
        var defaults = {
            button_text: 'Pay now',
            loading: 'Loading payment module…',
            init_failed: 'The payment module failed to load. Please refresh the page and try again.',
            order_failed: 'We couldn\'t create your order. Please try again in a moment.',
            no_token: 'We couldn\'t load the payment settings. Please try again in a moment.',
            sdk_missing: 'The payment component (Helcim SDK) hasn\'t loaded yet. Please refresh the page and try again.',
            card_number_label: 'Card number',
            card_expiry_label: 'Expiry (MM/YY)',
            card_cvv_label: 'Security code',
            card_name_label: 'Cardholder name',
            card_number_invalid: 'Please enter a valid card number.',
            card_expiry_invalid: 'Please enter a valid expiry date (MM/YY).',
            card_cvv_invalid: 'Please enter a valid security code.',
            processing_card: 'Processing your card details…',
            confirming: 'Confirming your payment…',
            redirecting: 'Payment complete. Redirecting to your receipt…',
            tokenize_failed_prefix: 'Payment failed: ',
            tokenize_failed: 'We couldn\'t verify your card. Please check your card details and try again.',
            timeout: 'The payment timed out. To prevent an incorrect charge, refresh the page before trying again.',
            confirm_failed: 'We couldn\'t confirm your payment. Please contact the store for help.',
            network_error: 'The payment result could not be confirmed. To prevent a duplicate charge, refresh the page or contact the store before trying again.',
            still_confirming: 'We are still confirming your payment. Please do not pay again. You will receive an email receipt once it is confirmed, or you can contact the store.',
            decline_store: 'This payment could not be processed right now. No payment was taken. Please try again later or contact the store.',
            decline_cvv: 'The card security code (CVV) did not match. No payment was taken. Please check the code and try again.',
            decline_expired: 'The card has expired or the expiry date is incorrect. No payment was taken. Please check the date or use a different card.',
            decline_invalid_card: 'The card number is not valid. No payment was taken. Please check the number or use a different card.',
            decline_funds: 'The card does not have enough available funds or credit. No payment was taken. Please use a different card.',
            decline_address: 'The billing address does not match the card. No payment was taken. Please check the billing address and try again.',
            decline_retry: 'The card could not be processed right now. No payment was taken. Please try again in a moment.',
            decline_issuer: 'Your bank declined this payment. No payment was taken. Please contact your bank or use a different card.',
            decline_generic: 'The card was declined and no payment was taken. Please check the details or use a different card.'
        };
        var translations = (cfg && cfg.translations) || {};
        return translations[key] || defaults[key] || key;
    }

    // Ordered like YSHelcimDeclineMessage::PATTERNS on the server; the first match wins.
    var DECLINE_PATTERNS = [
        ['store', /APPL TYPE|INVALID TERM|INVALID MERCHANT|AMOUNT ERROR|SVC LMT|SERVICE LIMIT|DUPLICATE|\bDUP\b/i],
        ['cvv', /\bCV[VCF]2?\b|\bCID\b|SECURITY CODE/i],
        ['expired', /EXPIRED CARD|CARD (?:HAS )?EXPIRED|EXPIRY|EXPIRATION/i],
        ['invalid_card', /\bINVALID CARD\b|INVALID (?:CARD )?NUMBER|INVALID ACCOUNT|NO SUCH (?:CARD|ACCOUNT|ISSUER)/i],
        ['funds', /INSUFFICIENT|\bNSF\b|EXCEEDS BAL|EXCEEDS (?:THE )?(?:CREDIT |WITHDRAWAL )?LIMIT|OVER (?:CREDIT )?LIMIT/i],
        ['address', /\bAVS\b|ADDRESS|POSTAL|\bZIP\b/i],
        ['retry', /PLEASE RETRY|RE-?ENTER|NETWORK ERROR|SYSTEM ERROR|TIME ?OUT|TRY AGAIN/i],
        ['issuer', /FRAUD|PICK ?UP|LOST|STOLEN|RESTRICTED|HONOU?R|REFER|\bCALL\b|SECURITY VIOLATION|NOT PERMITTED|NOT ALLOWED|BLOCKED/i]
    ];

    /**
     * Classify Helcim's decline text. Fraud and similar issuer reasons are only
     * ever shown to the shopper as a bank decline.
     *
     * @param {*} text Helcim response message
     * @returns {string|null} Decline category, or null when the text is not a card decline
     */
    function declineCategory(text) {
        var value = typeof text === 'string' ? text : '';
        for (var i = 0; i < DECLINE_PATTERNS.length; i++) {
            if (DECLINE_PATTERNS[i][1].test(value)) {
                return DECLINE_PATTERNS[i][0];
            }
        }
        return /DECLIN/i.test(value) ? 'generic' : null;
    }

    /**
     * @param {string|null} category Decline category from declineCategory()
     * @returns {string} Shopper message that always states no payment was taken
     */
    function declineMessage(category) {
        switch (category) {
            case 'store': return t('decline_store');
            case 'cvv': return t('decline_cvv');
            case 'expired': return t('decline_expired');
            case 'invalid_card': return t('decline_invalid_card');
            case 'funds': return t('decline_funds');
            case 'address': return t('decline_address');
            case 'retry': return t('decline_retry');
            case 'issuer': return t('decline_issuer');
            default: return t('decline_generic');
        }
    }

    /**
     * Broadcast the payment-module loading status to FluentCart
     *
     * @param {string} phase 'loading' | 'loading_success' | 'loading_failed'
     */
    function dispatchLoadingEvent(phase) {
        window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_' + phase, {
            detail: { payment_method: SLUG }
        }));
    }

    /**
     * Render an error message inside the container (clears the existing contents; used when the payment module fails to load)
     *
     * @param {Element} container Payment container
     * @param {string}  message   Error message
     */
    function renderContainerError(container, message) {
        if (!container) {
            return;
        }
        container.innerHTML = '';
        var error = document.createElement('div');
        error.className = 'ys-helcim-error';
        error.style.display = 'block'; // Hidden by default in CSS; container-level errors must be shown explicitly
        error.setAttribute('role', 'alert');
        error.textContent = message;
        container.appendChild(error);
    }

    /**
     * Show an error message below the form
     *
     * @param {string} message Error message (empty string = clear)
     */
    function showError(message) {
        var errorEl = document.querySelector(CONTAINER_SELECTOR + ' .ys-helcim-error');
        if (errorEl) {
            errorEl.textContent = message || '';
            errorEl.style.display = message ? 'block' : 'none';
        }
    }

    /**
     * Toggle the busy state of the pay button
     *
     * @param {boolean} busy Whether it is processing
     */
    function setButtonBusy(busy) {
        var button = document.querySelector(CONTAINER_SELECTOR + ' .ys-helcim-pay-button');
        if (!button) {
            return;
        }
        button.disabled = !!busy;
        button.classList.toggle('is-busy', !!busy);
        var spinner = button.querySelector('.ys-helcim-spinner');
        if (spinner) {
            spinner.style.display = busy ? 'inline-block' : 'none';
        }
    }

    /**
     * Stop the helcimResults polling
     */
    function stopPolling() {
        if (state.pollTimer) {
            clearInterval(state.pollTimer);
            state.pollTimer = null;
        }
    }

    /**
     * Full UI restore when the payment flow fails
     *
     * @param {Object} detail  e.detail from the load_payments event
     * @param {string} message Error message to show (empty string shows nothing)
     */
    function resetUi(detail, message) {
        stopPolling();
        activeResultHandler = null;
        state.processing = false;
        state.reloadRequired = false;
        state.reloadMessage = '';
        setButtonBusy(false);

        // Restore the expiry display format (it was converted to MMYY before helcimProcess)
        var expiryInput = document.getElementById('cardExpiry');
        if (expiryInput && state.expiryDisplayValue) {
            expiryInput.value = state.expiryDisplayValue;
        }
        state.expiryDisplayValue = '';

        if (message) {
            showError(message);
        }
        if (detail && detail.paymentLoader) {
            detail.paymentLoader.hideLoader();
            detail.paymentLoader.enableCheckoutButton();
        }
    }

    /**
     * Enter a terminal same-page state after the SDK wait limit expires.
     *
     * Helcim's SDK request cannot be aborted by this integration and may still
     * write a valid result into the shared #helcimResults element. Allowing a
     * second attempt in this document could therefore bind that stale result
     * to the next order. A full page reload is the only safe retry boundary.
     *
     * @param {Object} detail e.detail from the load_payments event
     */
    function requireReload(detail, message) {
        stopPolling();
        activeResultHandler = null;
        state.resultHandled = true;
        state.processing = true;
        state.reloadRequired = true;
        state.reloadMessage = message || t('network_error');
        state.expiryDisplayValue = '';
        setButtonBusy(false);
        var button = document.querySelector(CONTAINER_SELECTOR + ' .ys-helcim-pay-button');
        if (button) {
            button.disabled = true;
        }
        showError(state.reloadMessage);

        if (detail && detail.paymentLoader) {
            detail.paymentLoader.hideLoader();
            if (typeof detail.paymentLoader.disableCheckoutButton === 'function') {
                detail.paymentLoader.disableCheckoutButton();
            }
        }
    }

    function requireReloadAfterSdkTimeout(detail) {
        requireReload(detail, t('timeout'));
    }

    /**
     * Defensively pull our payment_data out of the order-creation response (same logic as the HelcimPay build)
     *
     * @param {Object} resp Order-creation response JSON
     * @returns {Object|null}
     */
    function extractPaymentData(resp) {
        if (!resp || typeof resp !== 'object') {
            return null;
        }
        if (resp.payment_data && typeof resp.payment_data === 'object') {
            return resp.payment_data;
        }
        if (resp.response && resp.response.payment_data && typeof resp.response.payment_data === 'object') {
            return resp.response.payment_data;
        }
        if (resp.data && resp.data.payment_data && typeof resp.data.payment_data === 'object') {
            return resp.data.payment_data;
        }
        return null;
    }

    /**
     * Card number input formatting: group every 4 digits with a space (aligned with the Woo build)
     *
     * @param {HTMLInputElement} input Card number field
     */
    function formatCardNumber(input) {
        var digits = input.value.replace(/\s+/g, '').replace(/[^0-9]/g, '');
        var groups = digits.match(/.{1,4}/g);
        input.value = groups ? groups.join(' ') : digits;
    }

    /**
     * Expiry input formatting: MM / YY (aligned with the Woo build)
     *
     * @param {HTMLInputElement} input Expiry field
     */
    function formatExpiry(input) {
        var digits = input.value.replace(/\s+/g, '').replace(/[^0-9]/g, '');
        if (digits.length >= 2) {
            input.value = digits.substring(0, 2) + ' / ' + digits.substring(2, 4);
        } else {
            input.value = digits;
        }
    }

    /**
     * Security code input formatting: digits only
     *
     * @param {HTMLInputElement} input Security code field
     */
    function formatCvv(input) {
        input.value = input.value.replace(/[^0-9]/g, '');
    }

    /**
     * Build a form field row (label + input)
     *
     * @param {Object} opts {id, label, autocomplete, maxLength, placeholder, inputMode}
     * @returns {{row: Element, input: HTMLInputElement}}
     */
    function createField(opts) {
        var row = document.createElement('div');
        row.className = 'ys-helcim-field';

        var label = document.createElement('label');
        label.setAttribute('for', opts.id);
        label.textContent = opts.label;

        var input = document.createElement('input');
        input.type = 'text';
        input.id = opts.id; // Set id only, never name: keeps the value out of FluentCart's form serialization to its own server
        if (opts.autocomplete) {
            input.setAttribute('autocomplete', opts.autocomplete);
        }
        if (opts.maxLength) {
            input.maxLength = opts.maxLength;
        }
        if (opts.placeholder) {
            input.placeholder = opts.placeholder;
        }
        if (opts.inputMode) {
            input.setAttribute('inputmode', opts.inputMode);
        }

        row.appendChild(label);
        row.appendChild(input);
        return { row: row, input: input };
    }

    /**
     * Build a hidden input (id only, no name)
     *
     * @param {string} id    Field id
     * @param {string} value Initial value
     * @returns {HTMLInputElement}
     */
    function createHidden(id, value) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.id = id;
        input.value = value || '';
        return input;
    }

    /**
     * Defensively pull the customer display info out of the paymentInfoUrl response (to prefill the cardholder fields)
     *
     * @param {Object} info paymentInfoUrl response
     * @returns {{name: string, address: string, postalCode: string}}
     */
    function extractCustomerInfo(info) {
        var customer = (info && (info.fc_customer || (info.payment_args && info.payment_args.fc_customer))) || {};
        var name = customer.full_name || customer.name || '';
        if (!name && (customer.first_name || customer.last_name)) {
            name = ((customer.first_name || '') + ' ' + (customer.last_name || '')).trim();
        }
        return {
            name: name,
            // Fallbacks for missing values: address defaults to '0', postal code to an empty string (matches the Woo build's behavior)
            address: customer.address || customer.address_1 || '0',
            postalCode: customer.postal_code || customer.postcode || customer.zip || ''
        };
    }

    /**
     * Render the card form inside the container (standard helcim.js SDK field ids)
     *
     * @param {Element} container Payment container
     * @param {Object}  detail    e.detail from the load_payments event
     * @param {Object}  info      paymentInfoUrl response
     */
    function renderForm(container, detail, info) {
        var customer = extractCustomerInfo(info);
        var buttonText = (info && info.payment_args && info.payment_args.button_text) || t('button_text');

        container.innerHTML = '';

        var wrapper = document.createElement('div');
        wrapper.className = 'ys-helcim-js-form';

        // --- Visible fields (the helcim.js SDK reads them by id; never add a name) ---
        var cardNumber = createField({
            id: 'cardNumber',
            label: t('card_number_label'),
            autocomplete: 'cc-number',
            maxLength: 23, // 19-digit card number + 4 spaces
            placeholder: '•••• •••• •••• ••••',
            inputMode: 'numeric'
        });
        cardNumber.input.addEventListener('input', function () {
            formatCardNumber(cardNumber.input);
        });

        var cardExpiry = createField({
            id: 'cardExpiry',
            label: t('card_expiry_label'),
            autocomplete: 'cc-exp',
            maxLength: 7, // "MM / YY"
            placeholder: 'MM / YY',
            inputMode: 'numeric'
        });
        cardExpiry.input.addEventListener('input', function () {
            formatExpiry(cardExpiry.input);
        });

        var cardCvv = createField({
            id: 'cardCVV',
            label: t('card_cvv_label'),
            autocomplete: 'cc-csc',
            maxLength: 4,
            placeholder: 'CVV',
            inputMode: 'numeric'
        });
        cardCvv.input.addEventListener('input', function () {
            formatCvv(cardCvv.input);
        });

        var cardName = createField({
            id: 'cardHolderName',
            label: t('card_name_label'),
            autocomplete: 'cc-name',
            maxLength: 60
        });
        cardName.input.value = customer.name;

        var expiryRow = document.createElement('div');
        expiryRow.className = 'ys-helcim-field-row';
        expiryRow.appendChild(cardExpiry.row);
        expiryRow.appendChild(cardCvv.row);

        wrapper.appendChild(cardNumber.row);
        wrapper.appendChild(expiryRow);
        wrapper.appendChild(cardName.row);

        // --- Hidden fields (helcim.js SDK contract) ---
        // token: filled from payment_data.js_token after the order is created
        wrapper.appendChild(createHidden('token', ''));
        wrapper.appendChild(createHidden('dontSubmit', '1'));
        // Belt-and-suspenders for the SDK field contract (Code Review 🟡-8): provide both the combined field cardExpiry(MMYY)
        // and the split fields cardExpiryMonth / cardExpiryYear (the Woo build was verified to use the split fields)
        wrapper.appendChild(createHidden('cardExpiryMonth', ''));
        wrapper.appendChild(createHidden('cardExpiryYear', ''));
        wrapper.appendChild(createHidden('cardHolderAddress', customer.address));
        wrapper.appendChild(createHidden('cardHolderPostalCode', customer.postalCode));

        // helcim.js writes its response into this container as hidden inputs
        var results = document.createElement('div');
        results.id = 'helcimResults';
        wrapper.appendChild(results);

        // --- Pay button and error region ---
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'ys-helcim-pay-button';

        var spinner = document.createElement('span');
        spinner.className = 'ys-helcim-spinner';
        spinner.style.display = 'none';
        spinner.setAttribute('aria-hidden', 'true');

        var label = document.createElement('span');
        label.className = 'ys-helcim-pay-button__label';
        label.textContent = buttonText;

        button.appendChild(spinner);
        button.appendChild(label);
        button.addEventListener('click', function () {
            onPayClick(detail);
        });

        var error = document.createElement('div');
        error.className = 'ys-helcim-error';
        error.style.display = 'none';
        error.setAttribute('role', 'alert');

        wrapper.appendChild(button);
        wrapper.appendChild(error);
        container.appendChild(wrapper);
    }

    /**
     * Collect every input inside #helcimResults into an object (SDK response fields only; defensively strips CVV-like keys)
     *
     * @returns {Object}
     */
    function collectResultFields() {
        var fields = {};
        var results = document.getElementById('helcimResults');
        if (!results) {
            return fields;
        }
        var inputs = results.querySelectorAll('input');
        for (var i = 0; i < inputs.length; i++) {
            var key = inputs[i].id || inputs[i].name;
            if (key) {
                fields[key] = inputs[i].value;
            }
        }
        sanitizeResultFields(fields);
        return fields;
    }

    /**
     * Defensively strip sensitive keys (the SDK should not return a CVV, but this is a fail-safe extra guard)
     *
     * @param {Object} fields Response fields object
     */
    function sanitizeResultFields(fields) {
        var banned = ['cardCVV', 'cvv', 'cardCvv', 'securityCode'];
        for (var i = 0; i < banned.length; i++) {
            if (Object.prototype.hasOwnProperty.call(fields, banned[i])) {
                delete fields[banned[i]];
            }
        }
    }

    /**
     * Normalize the helcimJsCallback response object into a fields object
     *
     * @param {Object} response callback response
     * @returns {Object}
     */
    function normalizeCallbackResponse(response) {
        var fields = {};
        if (response && typeof response === 'object') {
            for (var key in response) {
                if (Object.prototype.hasOwnProperty.call(response, key)) {
                    fields[key] = response[key];
                }
            }
        }
        sanitizeResultFields(fields);
        return fields;
    }

    /**
     * Decide whether an SDK surface contains a complete terminal result.
     * A success is actionable only after the provider-issued card token exists;
     * otherwise the primary DOM poller must keep waiting.
     *
     * @param {Object} fields Normalized callback response fields
     * @returns {boolean}
     */
    function isCompleteTokenizeResult(fields) {
        if (!Object.prototype.hasOwnProperty.call(fields, 'response')) {
            return false;
        }

        var response = String(fields.response);
        if (response === '') {
            return false;
        }

        if (response !== '1') {
            return true;
        }

        return !!(fields.cardToken && fields.xml && fields.xmlHash);
    }

    /**
     * Send the confirm AJAX request. The browser token is untrusted input; the
     * server-side v2 purchase and its strict provider response are authoritative.
     *
     * @param {Object} detail      e.detail from the load_payments event
     * @param {Object} paymentData payment_data from the order-creation response
     * @param {Object} fields      helcim.js response fields (includes cardToken, masked cardNumber, etc.)
     */
    function confirmPayment(detail, paymentData, fields) {
        var proofFields = {};
        var proofFieldNames = ['xml', 'xmlHash'];
        proofFieldNames.forEach(function (name) {
            if (Object.prototype.hasOwnProperty.call(fields, name)) {
                proofFields[name] = fields[name];
            }
        });
        var body = new URLSearchParams();
        body.append('action', cfg.confirm_action);
        body.append('transaction_uuid', paymentData.transaction_uuid || '');
        body.append('nonce', paymentData.confirm_nonce || '');
        body.append('confirm_token', paymentData.confirm_token || '');
        body.append('response_fields', JSON.stringify(proofFields));

        fetch(cfg.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'include',
            body: body.toString()
        }).then(function (response) {
            return response.json().catch(function () {
                return null;
            });
        }).then(function (resp) {
            var isSuccess = resp && (resp.status === 'success' || resp.success === true);
            if (isSuccess && resp.redirect_url) {
                completeRedirect(detail, resp);
                return;
            }
            var message = (resp && (resp.message || (resp.data && resp.data.message))) || t('confirm_failed');
            if (resp && resp.retry_allowed === true) {
                resetUi(detail, message);
                return;
            }
            pollPaymentStatus(detail, paymentData, message);
        }).catch(function () {
            pollPaymentStatus(detail, paymentData, t('network_error'));
        });
    }

    /**
     * Hand a confirmed payment to FluentCart and move to the receipt page.
     *
     * @param {Object} detail e.detail from the load_payments event
     * @param {Object} resp   Success payload with redirect_url and order.uuid
     */
    function completeRedirect(detail, resp) {
        if (detail.paymentLoader) {
            detail.paymentLoader.triggerPaymentCompleteEvent(resp);
            detail.paymentLoader.changeLoaderStatus(t('redirecting'));
        }
        if (window.CheckoutHelper && typeof window.CheckoutHelper.handleCheckoutRedirect === 'function') {
            window.CheckoutHelper.handleCheckoutRedirect(resp.redirect_url);
        } else {
            window.location.href = resp.redirect_url;
        }
    }

    /** @returns {number[]} Delays between server status checks */
    function statusPollDelays() {
        var configured = cfg && cfg.status_poll_delays_ms;
        if (Array.isArray(configured) && configured.length > 0) {
            return configured.map(function (value) {
                return Math.max(0, Number(value) || 0);
            });
        }
        return STATUS_POLL_DELAYS_MS;
    }

    /**
     * Ask the server what actually happened after a confirmation this page could
     * not trust (a Helcim webhook finishing the same payment first, a dropped
     * response, a failure before any charge). The server records every payment
     * attempt before charging, so it can tell a paid order (redirect), a definite
     * no-charge (allow another try) and a payment still being finalized apart.
     * Only a result that stays unknown keeps the page locked.
     *
     * @param {Object} detail          e.detail from the load_payments event
     * @param {Object} paymentData     payment_data from the order-creation response
     * @param {string} fallbackMessage Lock message when status checks are unavailable
     */
    function pollPaymentStatus(detail, paymentData, fallbackMessage) {
        if (!cfg.status_action || !paymentData || !paymentData.status_token || !paymentData.transaction_uuid) {
            requireReload(detail, fallbackMessage);
            return;
        }

        stopPolling();
        activeResultHandler = null;
        state.resultHandled = true;
        state.processing = true;
        setButtonBusy(true);
        showError('');
        if (detail.paymentLoader) {
            detail.paymentLoader.changeLoaderStatus(t('confirming'));
        }

        var delays = statusPollDelays();
        var attempt = 0;
        var noAttemptObservations = 0;
        var pendingMessage = '';

        function scheduleCheck() {
            if (attempt >= delays.length) {
                requireReload(detail, pendingMessage || t('still_confirming'));
                return;
            }
            setTimeout(checkStatus, delays[attempt]);
            attempt += 1;
        }

        function checkStatus() {
            var body = new URLSearchParams();
            body.append('action', cfg.status_action);
            body.append('transaction_uuid', paymentData.transaction_uuid);
            body.append('status_token', paymentData.status_token);

            fetch(cfg.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                credentials: 'include',
                body: body.toString()
            }).then(function (response) {
                return response.json().catch(function () {
                    return null;
                });
            }).then(function (resp) {
                if (resp && resp.status === 'success' && resp.redirect_url) {
                    completeRedirect(detail, resp);
                    return;
                }
                if (resp && resp.status === 'failed' && resp.retry_allowed === true) {
                    // A confirmation still in flight may not have recorded its attempt
                    // yet; only a repeated "no attempt" answer proves nothing was charged.
                    if (resp.code === 'no_payment_attempt' && noAttemptObservations < 1) {
                        noAttemptObservations += 1;
                        scheduleCheck();
                        return;
                    }
                    resetUi(detail, resp.message || t('confirm_failed'));
                    return;
                }
                if (resp && resp.status === 'failed') {
                    requireReload(detail, resp.message || fallbackMessage);
                    return;
                }
                noAttemptObservations = 0;
                if (resp && resp.message) {
                    pendingMessage = resp.message;
                }
                scheduleCheck();
            }).catch(function () {
                scheduleCheck();
            });
        }

        scheduleCheck();
    }

    /**
     * Handle the tokenize result (shared by the callback and the poller, single gate)
     *
     * @param {Object} detail      e.detail from the load_payments event
     * @param {Object} paymentData payment_data from the order-creation response
     * @param {Object} fields      the normalized response fields
     */
    function handleTokenizeResult(detail, paymentData, fields) {
        if (state.resultHandled) {
            return;
        }
        state.resultHandled = true;
        stopPolling();
        activeResultHandler = null;

        // helcim.js response: response == 1 means success
        if (String(fields.response) !== '1') {
            var reason = fields.responseMessage ? String(fields.responseMessage) : '';
            // 只有看起來是卡片拒絕的原文才套分類文案；設定類錯誤（例如 "Call support to enable this
            // feature"）含 CALL／ADDRESS 等字也不得被翻成拒絕文案，維持顯示 Helcim 原文。
            var category = /DECLIN|INVALID|EXPIR/i.test(reason) ? declineCategory(reason) : null;
            if (category) {
                resetUi(detail, declineMessage(category));
                return;
            }
            // Not a card decline (for example a Helcim.js configuration error): keep Helcim's text visible.
            resetUi(detail, t('tokenize_failed_prefix') + (reason || t('tokenize_failed')));
            return;
        }

        if (!fields.cardToken) {
            resetUi(detail, t('tokenize_failed_prefix') + t('tokenize_failed'));
            return;
        }

        if (detail.paymentLoader) {
            detail.paymentLoader.changeLoaderStatus(t('confirming'));
        }
        confirmPayment(detail, paymentData, fields);
    }

    /**
     * Start the helcimResults polling (helcim.js writes its result into the DOM; this is the primary completion-detection path)
     *
     * @param {Object} detail      e.detail from the load_payments event
     * @param {Object} paymentData payment_data from the order-creation response
     */
    function startPolling(detail, paymentData) {
        var startedAt = Date.now();
        stopPolling();
        state.pollTimer = setInterval(function () {
            if (state.resultHandled) {
                stopPolling();
                return;
            }
            var results = document.getElementById('helcimResults');
            var responseInput = results ? results.querySelector('input#response, input[name="response"]') : null;
            if (responseInput) {
                var fields = collectResultFields();
                if (isCompleteTokenizeResult(fields)) {
                    handleTokenizeResult(detail, paymentData, fields);
                    return;
                }
            }
            if (Date.now() - startedAt > POLL_TIMEOUT_MS) {
                requireReloadAfterSdkTimeout(detail);
            }
        }, POLL_INTERVAL_MS);
    }

    /**
     * Basic client-side validation (card number length / expiry / security code)
     *
     * @returns {{ok: boolean, message: string, cardDigits: string, expiryDigits: string}}
     */
    function validateCardFields() {
        var numberInput = document.getElementById('cardNumber');
        var expiryInput = document.getElementById('cardExpiry');
        var cvvInput = document.getElementById('cardCVV');

        var cardDigits = numberInput ? numberInput.value.replace(/\s+/g, '') : '';
        var expiryDigits = expiryInput ? expiryInput.value.replace(/[^0-9]/g, '') : '';
        var cvv = cvvInput ? cvvInput.value : '';

        if (!/^[0-9]{13,19}$/.test(cardDigits)) {
            return { ok: false, message: t('card_number_invalid'), cardDigits: '', expiryDigits: '' };
        }

        var month = parseInt(expiryDigits.substring(0, 2), 10);
        if (expiryDigits.length !== 4 || isNaN(month) || month < 1 || month > 12) {
            return { ok: false, message: t('card_expiry_invalid'), cardDigits: '', expiryDigits: '' };
        }

        if (!/^[0-9]{3,4}$/.test(cvv)) {
            return { ok: false, message: t('card_cvv_invalid'), cardDigits: '', expiryDigits: '' };
        }

        return { ok: true, message: '', cardDigits: cardDigits, expiryDigits: expiryDigits };
    }

    /**
     * Read the latest non-empty value from a FluentCart checkout input.
     *
     * FluentCart renders duplicate address-editor components with duplicate ids.
     * document.getElementById() can therefore return the hidden, empty instance
     * even when the active billing editor contains a valid value.
     *
     * @param {string} id Checkout input id
     * @returns {string}
     */
    function checkoutInputValue(id) {
        var inputs = document.querySelectorAll('[id="' + id + '"]');
        for (var i = inputs.length - 1; i >= 0; i--) {
            var value = typeof inputs[i].value === 'string' ? inputs[i].value.trim() : '';
            if (value) {
                return value;
            }
        }
        return '';
    }

    /**
     * Pay button click: validate -> create order -> tokenize -> confirm
     *
     * @param {Object} detail e.detail from the load_payments event
     */
    function onPayClick(detail) {
        if (state.processing || state.reloadRequired) {
            return;
        }
        showError('');

        var validation = validateCardFields();
        if (!validation.ok) {
            showError(validation.message);
            return;
        }

        if (typeof detail.orderHandler !== 'function') {
            showError(t('init_failed'));
            return;
        }

        // Do not create a pending FluentCart order when the PCI SDK is blocked
        // or unavailable in this browser.
        if (typeof window.helcimProcess !== 'function') {
            showError(t('sdk_missing'));
            return;
        }

        state.processing = true;
        state.resultHandled = false;
        setButtonBusy(true);

        Promise.resolve(detail.orderHandler()).then(function (resp) {
            if (!resp) {
                // Order creation failed: FluentCart already showed the validation error and restored the loader; here we only restore our own button
                state.processing = false;
                setButtonBusy(false);
                return;
            }

            var paymentData = extractPaymentData(resp);
            if (!paymentData || !paymentData.js_token) {
                resetUi(detail, t('no_token'));
                return;
            }

            // Populate the hidden values the helcim.js SDK needs (the order-creation response is authoritative for token / test)
            var tokenInput = document.getElementById('token');
            if (tokenInput) {
                tokenInput.value = paymentData.js_token;
            }

            // helcim.js rejects formatted PANs. The visible field is grouped for readability,
            // but the SDK contract requires digits only at invocation time.
            var numberInput = document.getElementById('cardNumber');
            if (numberInput) {
                numberInput.value = validation.cardDigits;
            }

            // Convert expiry to MMYY before submitting (keep the display value so we can restore it on failure)
            var expiryInput = document.getElementById('cardExpiry');
            if (expiryInput) {
                state.expiryDisplayValue = expiryInput.value;
                expiryInput.value = validation.expiryDigits; // MMYY
            }

            // Also populate the split fields (belt-and-suspenders for the SDK contract, Code Review 🟡-8)
            var expiryMonthInput = document.getElementById('cardExpiryMonth');
            var expiryYearInput = document.getElementById('cardExpiryYear');
            if (expiryMonthInput) {
                expiryMonthInput.value = validation.expiryDigits.substring(0, 2);
            }
            if (expiryYearInput) {
                expiryYearInput.value = validation.expiryDigits.substring(2, 4);
            }

            // The customer may enter or edit FluentCart billing fields after this gateway form was rendered.
            // The just-created order is authoritative. Fall back to the latest
            // non-empty editor only when the server could not resolve the order address.
            var checkoutAddress = typeof paymentData.cardholder_address === 'string'
                ? paymentData.cardholder_address.trim()
                : '';
            var checkoutPostalCode = typeof paymentData.cardholder_postal_code === 'string'
                ? paymentData.cardholder_postal_code.trim()
                : '';
            if (!checkoutAddress) {
                checkoutAddress = checkoutInputValue('billing_address_1');
            }
            if (!checkoutPostalCode) {
                checkoutPostalCode = checkoutInputValue('billing_postcode');
            }
            var cardHolderAddressInput = document.getElementById('cardHolderAddress');
            var cardHolderPostalCodeInput = document.getElementById('cardHolderPostalCode');
            if (checkoutAddress && cardHolderAddressInput) {
                cardHolderAddressInput.value = checkoutAddress;
            }
            if (checkoutPostalCode && cardHolderPostalCodeInput) {
                cardHolderPostalCodeInput.value = checkoutPostalCode;
            }

            // Clear any previous results so the poller doesn't misfire
            var results = document.getElementById('helcimResults');
            if (results) {
                results.innerHTML = '';
            }

            if (detail.paymentLoader) {
                detail.paymentLoader.changeLoaderStatus(t('processing_card'));
            }

            // Register the result handler for this flow (used by the callback fallback path)
            activeResultHandler = function (fields) {
                handleTokenizeResult(detail, paymentData, fields);
            };
            startPolling(detail, paymentData);

            try {
                Promise.resolve(window.helcimProcess()).catch(function (err) {
                    if (state.resultHandled) {
                        return;
                    }
                    state.resultHandled = true;
                    console.error('[YS Helcim.js FCT] helcimProcess rejected:', err);
                    resetUi(detail, t('tokenize_failed_prefix') + (err && err.message ? err.message : t('tokenize_failed')));
                });
            } catch (err) {
                state.resultHandled = true;
                console.error('[YS Helcim.js FCT] helcimProcess failed:', err);
                resetUi(detail, t('tokenize_failed_prefix') + (err && err.message ? err.message : t('tokenize_failed')));
            }
        }).catch(function (err) {
            console.error('[YS Helcim.js FCT] Order-creation flow error:', err);
            resetUi(detail, t('order_failed'));
        });
    }

    /**
     * FluentCart loads this payment method (fires on initial load, on switching, and after fragments are swapped;
     * the container may be brand-new DOM, so we re-render every time)
     *
     * @param {CustomEvent} e fluent_cart_load_payments_ys_helcim_js event
     */
    function onLoadPayments(e) {
        var detail = e.detail || {};
        var container = document.querySelector(CONTAINER_SELECTOR);
        if (!container) {
            return;
        }

        if (state.reloadRequired) {
            renderContainerError(container, state.reloadMessage || t('network_error'));
            if (detail.paymentLoader && typeof detail.paymentLoader.disableCheckoutButton === 'function') {
                detail.paymentLoader.disableCheckoutButton();
            }
            return;
        }

        // A FluentCart fragment event can fire while helcim.js owns the active
        // result container. Re-rendering here would disconnect #helcimResults
        // and make a valid SDK response look like a two-minute timeout.
        if (state.processing) {
            return;
        }

        stopPolling();
        activeResultHandler = null;
        state.resultHandled = false;
        state.expiryDisplayValue = '';

        dispatchLoadingEvent('loading');
        container.innerHTML = '<p class="ys-helcim-loading-text">' + t('loading') + '</p>';

        fetch(detail.paymentInfoUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': detail.nonce
            },
            credentials: 'include'
        }).then(function (response) {
            return response.json();
        }).then(function (info) {
            if (info && info.status === 'failed') {
                renderContainerError(container, info.message || t('init_failed'));
                dispatchLoadingEvent('loading_failed');
                return;
            }
            renderForm(container, detail, info);
            dispatchLoadingEvent('loading_success');
        }).catch(function () {
            renderContainerError(container, t('init_failed'));
            dispatchLoadingEvent('loading_failed');
        });
    }

    // ---- Entry point ----
    if (!cfg || !cfg.ajax_url || !cfg.confirm_action) {
        console.error('[YS Helcim.js FCT] Localized data ys_helcim_js_fct_data is missing; the helcim.js checkout flow cannot start.');
        return;
    }

    // Some helcim.js versions support a global callback; it coexists with the poller, with a single gate preventing double handling
    window.helcimJsCallback = function (response) {
        var fields = normalizeCallbackResponse(response);
        if (
            typeof activeResultHandler === 'function' &&
            isCompleteTokenizeResult(fields)
        ) {
            activeResultHandler(fields);
        }
    };

    window.addEventListener('fluent_cart_load_payments_' + SLUG, onLoadPayments);
})();
