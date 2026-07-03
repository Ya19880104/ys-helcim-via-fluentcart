/**
 * YS Helcim via FluentCart — helcim.js inline card-form checkout flow (ys_helcim_js)
 *
 * Flow (Verify tokenize -> server-side capture):
 * 1. Listen for `fluent_cart_load_payments_ys_helcim_js` -> render the card form inside the container
 *    (fields carry an id only, never a name attribute — sensitive values must not enter FluentCart's form serialization)
 * 2. On pay click -> basic client-side validation -> await orderHandler() to create the order
 *    -> read payment_data.{transaction_uuid, confirm_nonce, js_token, test_mode}
 * 3. Populate #token / #test -> call helcimProcess() (helcim.js SDK tokenize)
 * 4. Completion detection: poll for input#response appearing inside #helcimResults (primary) plus window.helcimJsCallback (secondary)
 * 5. response != 1 -> show an error; success -> collect every hidden input inside #helcimResults
 *    -> AJAX confirm (server captures via /v2/payment/purchase using the cardToken) -> redirect to the receipt page
 *
 * Security notes:
 * - The full card number / CVV never appear in the confirm request; response_fields only collects the
 *   response fields the SDK writes into #helcimResults (cardNumber is masked), and defensively strips CVV-like keys
 *
 * @package YangSheep\Helcim\FluentCart
 */
(function () {
    'use strict';

    var SLUG = 'ys_helcim_js';
    var CONTAINER_SELECTOR = '.fluent-cart-checkout_embed_payment_container_' + SLUG;
    var POLL_INTERVAL_MS = 400;      // helcimResults polling interval
    var POLL_TIMEOUT_MS = 120000;    // tokenize wait limit (2 minutes)

    /** Server-side localized data (read defensively; abort and log if missing) */
    var cfg = window.ys_helcim_js_fct_data || null;

    /** Module-level flow state */
    var state = {
        processing: false,       // Whether a payment flow is currently in progress (guards against double clicks)
        resultHandled: false,    // Whether the tokenize result has been handled (single gate shared by callback and polling)
        pollTimer: null,         // Polling timer
        expiryDisplayValue: ''   // The expiry display value from before helcimProcess (used to restore on failure)
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
            timeout: 'The payment timed out. Please try again in a moment.',
            confirm_failed: 'We couldn\'t confirm your payment. Please contact the store for help.',
            network_error: 'Connection error. Please try again in a moment.'
        };
        var translations = (cfg && cfg.translations) || {};
        return translations[key] || defaults[key] || key;
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
        var initialTestMode = !!(info && info.payment_args && (info.payment_args.mode === 'test' || info.payment_args.test_mode));

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
        // token: filled from payment_data.js_token after the order is created; test: overwritten from payment_data.test_mode after order creation
        wrapper.appendChild(createHidden('token', ''));
        wrapper.appendChild(createHidden('test', initialTestMode ? '1' : '0'));
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
     * Send the confirm AJAX request (the server validates the response hash -> captures via /v2/payment/purchase -> reconciles fail-closed)
     *
     * @param {Object} detail      e.detail from the load_payments event
     * @param {Object} paymentData payment_data from the order-creation response
     * @param {Object} fields      helcim.js response fields (includes cardToken, masked cardNumber, etc.)
     */
    function confirmPayment(detail, paymentData, fields) {
        var body = new URLSearchParams();
        body.append('action', cfg.confirm_action);
        body.append('transaction_uuid', paymentData.transaction_uuid || '');
        body.append('nonce', paymentData.confirm_nonce || '');
        body.append('card_token', fields.cardToken || '');
        body.append('response_fields', JSON.stringify(fields));

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
                if (detail.paymentLoader) {
                    detail.paymentLoader.triggerPaymentCompleteEvent(resp);
                    detail.paymentLoader.changeLoaderStatus(t('redirecting'));
                }
                if (window.CheckoutHelper && typeof window.CheckoutHelper.handleCheckoutRedirect === 'function') {
                    window.CheckoutHelper.handleCheckoutRedirect(resp.redirect_url);
                } else {
                    window.location.href = resp.redirect_url;
                }
                return;
            }
            var message = (resp && (resp.message || (resp.data && resp.data.message))) || t('confirm_failed');
            resetUi(detail, message);
        }).catch(function () {
            resetUi(detail, t('network_error'));
        });
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
            var reason = fields.responseMessage ? String(fields.responseMessage) : t('tokenize_failed');
            resetUi(detail, t('tokenize_failed_prefix') + reason);
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
                handleTokenizeResult(detail, paymentData, collectResultFields());
                return;
            }
            if (Date.now() - startedAt > POLL_TIMEOUT_MS) {
                stopPolling();
                resetUi(detail, t('timeout'));
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
     * Pay button click: validate -> create order -> tokenize -> confirm
     *
     * @param {Object} detail e.detail from the load_payments event
     */
    function onPayClick(detail) {
        if (state.processing) {
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

            if (typeof window.helcimProcess !== 'function') {
                resetUi(detail, t('sdk_missing'));
                return;
            }

            // Populate the hidden values the helcim.js SDK needs (the order-creation response is authoritative for token / test)
            var tokenInput = document.getElementById('token');
            var testInput = document.getElementById('test');
            if (tokenInput) {
                tokenInput.value = paymentData.js_token;
            }
            if (testInput) {
                testInput.value = paymentData.test_mode ? '1' : '0';
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
                window.helcimProcess();
            } catch (err) {
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

        // Reset flow state; while a payment flow is in progress (e.g. a background fragments swap) do not tear down the active poller
        if (!state.processing) {
            stopPolling();
            activeResultHandler = null;
            state.resultHandled = false;
            state.expiryDisplayValue = '';
        }

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
        if (typeof activeResultHandler === 'function') {
            activeResultHandler(normalizeCallbackResponse(response));
        }
    };

    window.addEventListener('fluent_cart_load_payments_' + SLUG, onLoadPayments);
})();
