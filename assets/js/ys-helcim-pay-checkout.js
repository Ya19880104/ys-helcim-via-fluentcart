/**
 * YS Helcim via FluentCart — HelcimPay.js modal checkout flow (ys_helcim)
 *
 * Flow (Paddle-style custom checkout button):
 * 1. Listen for FluentCart's `fluent_cart_load_payments_ys_helcim` event
 *    (e.detail = { form, paymentInfoUrl, nonce, orderHandler, paymentLoader, ... })
 * 2. Fetch paymentInfoUrl for display info (no secrets) -> render the custom pay button inside the container
 * 3. On click -> await orderHandler() to create the order -> read payment_data.{checkout_token, transaction_uuid, confirm_nonce}
 * 4. Register a postMessage listener -> appendHelcimPayIframe(checkoutToken) to open the Helcim modal
 * 5. SUCCESS -> parse eventMessage (nested shape aligned with the Woo build) -> AJAX confirm (fail-closed on the server)
 *    -> on success, redirect to the receipt page via redirect_url
 * 6. ABORTED / HIDE -> restore the UI; the order stays pending and can be retried
 *
 * Contract basis (verified against fluent-cart v1.5.2 source):
 * - orderHandler is a function; on success it returns the entire order-creation response JSON (wp_send_json as-is), and a falsy value on failure
 * - Our data lives at the top-level `payment_data` key (with a defensive fallback to response/data nesting)
 * - paymentLoader exposes changeLoaderStatus()/showLoader()/hideLoader()/
 *   enableCheckoutButton()/triggerPaymentCompleteEvent()
 *
 * @package YangSheep\Helcim\FluentCart
 */
(function () {
    'use strict';

    var SLUG = 'ys_helcim';
    var CONTAINER_SELECTOR = '.fluent-cart-checkout_embed_payment_container_' + SLUG;

    /** Server-side localized data (read defensively; abort and log if missing) */
    var cfg = window.ys_helcim_fct_data || null;

    /** Module-level flow state (only one payment flow is allowed at a time) */
    var state = {
        processing: false,        // Whether a payment flow is currently in progress (guards against double clicks)
        finished: false,          // Whether this modal flow has been settled (ignore HIDE after SUCCESS/ABORTED)
        reloadRequired: false,    // Uncertain provider result: never permit another charge in this page lifecycle
        checkoutToken: null,      // HelcimPay checkoutToken for the current flow
        messageHandler: null      // The postMessage listener currently attached (prevents leaks)
    };

    /**
     * Get a localized string (translations can be overridden by the server)
     *
     * @param {string} key Translation key
     * @returns {string}
     */
    function t(key) {
        var defaults = {
            button_text: 'Pay with card (Helcim)',
            loading: 'Loading payment module…',
            init_failed: 'The payment module failed to load. Please refresh the page and try again.',
            order_failed: 'We couldn\'t create your order. Please try again in a moment.',
            no_token: 'We couldn\'t start the payment. Please try again in a moment.',
            sdk_missing: 'The payment component (Helcim SDK) hasn\'t loaded yet. Please refresh the page and try again.',
            modal_hint: 'Please complete your payment in the pop-up window.',
            confirming: 'Confirming your payment…',
            redirecting: 'Payment complete. Redirecting to your receipt…',
            canceled: 'The payment was canceled or failed. Please try again.',
            confirm_failed: 'We couldn\'t confirm your payment. Please contact the store for help.',
            network_error: 'The payment result could not be confirmed. To prevent a duplicate charge, refresh the page or contact the store before trying again.',
            uncertain: 'The payment window closed before its result could be confirmed. To prevent a duplicate charge, refresh the page or contact the store before trying again.',
            declined_verifying: 'The payment was declined. Its final result is being verified. Do not retry this payment yet.',
            incomplete_data: 'The payment data was incomplete. Please try again.'
        };
        var translations = (cfg && cfg.translations) || {};
        return translations[key] || defaults[key] || key;
    }

    /**
     * Broadcast the payment-module loading status to FluentCart (drives the fct-payment-loading styling)
     *
     * @param {string} phase 'loading' | 'loading_success' | 'loading_failed'
     */
    function dispatchLoadingEvent(phase) {
        window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_' + phase, {
            detail: { payment_method: SLUG }
        }));
    }

    /**
     * Render an error message inside the container (clears the existing contents)
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
     * Show an error message below the button (without clearing the button)
     *
     * @param {string} message Error message
     */
    function showInlineError(message) {
        var errorEl = document.querySelector(CONTAINER_SELECTOR + ' .ys-helcim-error');
        if (errorEl) {
            errorEl.textContent = message || '';
            errorEl.style.display = message ? 'block' : 'none';
        }
    }

    /**
     * Toggle the busy state of the custom pay button
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
     * Detach the current postMessage listener (must be called at the end of every flow to prevent leaks)
     */
    function detachMessageListener() {
        if (state.messageHandler) {
            window.removeEventListener('message', state.messageHandler);
            state.messageHandler = null;
        }
    }

    /**
     * Safely remove the HelcimPay iframe (only call if the SDK function exists)
     */
    function safeRemoveIframe() {
        if (typeof window.removeHelcimPayIframe === 'function') {
            try {
                window.removeHelcimPayIframe();
            } catch (err) {
                // The iframe may already be gone; ignore.
            }
        }
    }

    /**
     * Full UI restore when the payment flow fails or is canceled
     *
     * @param {Object} detail  e.detail from the load_payments event
     * @param {string} message Error message to show (empty string shows nothing)
     */
    function resetUi(detail, message) {
        detachMessageListener();
        state.processing = false;
        state.finished = true;
        setButtonBusy(false);
        if (message) {
            showInlineError(message);
        }
        if (detail && detail.paymentLoader) {
            detail.paymentLoader.hideLoader();
            // Keep FluentCart's button state machine consistent (in custom-button mode the default button is hidden but must stay enabled)
            detail.paymentLoader.enableCheckoutButton();
        }
    }

    /**
     * Lock the page after a provider session may have produced a charge but the
     * exact server result is not yet proven. A reload is required before any
     * further checkout attempt.
     *
     * @param {Object} detail  e.detail from the load_payments event
     * @param {string} message Safe user-facing recovery instruction
     */
    function lockUi(detail, message) {
        detachMessageListener();
        state.processing = true;
        state.finished = true;
        state.reloadRequired = true;
        setButtonBusy(false);
        var button = document.querySelector(CONTAINER_SELECTOR + ' .ys-helcim-pay-button');
        if (button) {
            button.disabled = true;
        }
        showInlineError(message || t('uncertain'));
        if (detail && detail.paymentLoader) {
            detail.paymentLoader.hideLoader();
            if (typeof detail.paymentLoader.disableCheckoutButton === 'function') {
                detail.paymentLoader.disableCheckoutButton();
            }
        }
    }

    /**
     * Defensively pull our payment_data out of the order-creation response
     * (fluent-cart wp_send_json's the gateway's returned array as-is, so it normally
     *  lives at the top level; also tolerate the PayPal pattern where the server nests it under response/data)
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
     * Parse the HelcimPay SUCCESS eventMessage (tolerant of the multi-level nesting seen in the Woo build)
     * Possible shapes: JSON string or object; {data:{hash, data:{...tx}}} or {data:{...tx, hash}} or a flat object
     *
     * @param {*} eventMessage event.data.eventMessage from postMessage
     * @returns {{txData: Object|null, hash: string}}
     */
    function parseEventMessage(eventMessage) {
        var msg = eventMessage;
        if (typeof msg === 'string') {
            try {
                msg = JSON.parse(msg);
            } catch (err) {
                console.error('[YS Helcim FCT] Failed to parse eventMessage JSON');
                msg = null;
            }
        }

        var txData = null;
        var hash = '';

        if (msg && msg.data) {
            hash = msg.data.hash || '';
            txData = msg.data.data || msg.data;
        } else if (msg) {
            txData = msg;
            hash = msg.hash || '';
        }

        // txData is only meaningful if it is an object
        if (!txData || typeof txData !== 'object') {
            txData = null;
        }

        return { txData: txData, hash: hash };
    }

    /**
     * Redirect to the receipt page (modal-checkout compatible: prefer FluentCart's CheckoutHelper)
     *
     * @param {string} url Receipt page URL
     */
    function redirectTo(url) {
        if (window.CheckoutHelper && typeof window.CheckoutHelper.handleCheckoutRedirect === 'function') {
            window.CheckoutHelper.handleCheckoutRedirect(url);
        } else {
            window.location.href = url;
        }
    }

    /**
     * Send the confirm AJAX request and, on success, redirect to the receipt page
     * (the server validates hash/amount/currency fail-closed)
     *
     * @param {Object} detail      e.detail from the load_payments event
     * @param {Object} paymentData payment_data from the order-creation response
     * @param {Object} txData      Helcim transaction data object
     * @param {string} hash        Verification hash returned by Helcim
     */
    function confirmPayment(detail, paymentData, txData, hash) {
        var body = new URLSearchParams();
        body.append('action', cfg.confirm_action);
        body.append('transaction_uuid', paymentData.transaction_uuid || '');
        body.append('operation_uuid', paymentData.operation_uuid || '');
        body.append('confirm_token', paymentData.confirm_token || '');
        body.append('nonce', paymentData.confirm_nonce || '');
        body.append('event_data', JSON.stringify(txData));
        body.append('hash', hash || '');

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
                    // Tell FluentCart to run its post-order actions (takes effect when the response includes order.uuid)
                    detail.paymentLoader.triggerPaymentCompleteEvent(resp);
                    detail.paymentLoader.changeLoaderStatus(t('redirecting'));
                }
                redirectTo(resp.redirect_url);
                return;
            }
            var message = (resp && (resp.message || (resp.data && resp.data.message))) || t('confirm_failed');
            if (resp && resp.retry_allowed === true) {
                resetUi(detail, message);
                return;
            }
            lockUi(detail, message);
        }).catch(function () {
            lockUi(detail, t('network_error'));
        });
    }

    /**
     * Build the postMessage listener for the current payment flow
     *
     * @param {Object} detail        e.detail from the load_payments event
     * @param {Object} paymentData   payment_data from the order-creation response
     * @param {string} checkoutToken HelcimPay checkoutToken
     * @returns {Function}
     */
    function createMessageHandler(detail, paymentData, checkoutToken) {
        var identifier = 'helcim-pay-js-' + checkoutToken;

        return function onHelcimMessage(event) {
            if (event.origin !== 'https://secure.helcim.app'
                || !event.data
                || event.data.eventName !== identifier) {
                return;
            }

            if (event.data.eventStatus === 'SUCCESS') {
                if (state.finished) {
                    return;
                }
                state.finished = true;
                detachMessageListener();

                var parsed = parseEventMessage(event.data.eventMessage);
                safeRemoveIframe();

                if (!parsed.txData || !parsed.hash) {
                    lockUi(detail, t('uncertain'));
                    return;
                }

                if (detail.paymentLoader) {
                    detail.paymentLoader.changeLoaderStatus(t('confirming'));
                }
                confirmPayment(detail, paymentData, parsed.txData, parsed.hash);
                return;
            }

            if (event.data.eventStatus === 'ABORTED') {
                if (state.finished) {
                    return;
                }
                state.finished = true;
                detachMessageListener();
                var aborted = parseEventMessage(event.data.eventMessage);
                safeRemoveIframe();
                if (aborted.txData && aborted.hash) {
                    if (detail.paymentLoader) {
                        detail.paymentLoader.changeLoaderStatus(t('confirming'));
                    }
                    confirmPayment(detail, paymentData, aborted.txData, aborted.hash);
                    return;
                }
                lockUi(detail, t('declined_verifying'));
                return;
            }

            // HIDE does not prove whether the provider created a charge.
            if (event.data.eventStatus === 'HIDE') {
                if (state.finished) {
                    return;
                }
                safeRemoveIframe();
                lockUi(detail, t('uncertain'));
            }
        };
    }

    /**
     * Custom pay button click: create the order -> open the HelcimPay modal
     *
     * @param {Object} detail e.detail from the load_payments event
     */
    function onPayClick(detail) {
        if (state.processing || state.reloadRequired) {
            return;
        }
        state.processing = true;
        state.finished = false;
        showInlineError('');
        setButtonBusy(true);

        // orderHandler is a function (verified against fluent-cart source); on failure FluentCart already shows the error and cleans up
        if (typeof detail.orderHandler !== 'function') {
            resetUi(detail, t('init_failed'));
            return;
        }
        if (typeof window.appendHelcimPayIframe !== 'function') {
            resetUi(detail, t('sdk_missing'));
            return;
        }

        Promise.resolve(detail.orderHandler()).then(function (resp) {
            if (!resp) {
                // Order creation failed: FluentCart already showed the validation error and restored the loader; here we only restore our own button
                state.processing = false;
                setButtonBusy(false);
                return;
            }

            var paymentData = extractPaymentData(resp);
            if (!paymentData
                || !paymentData.checkout_token
                || !paymentData.transaction_uuid
                || !paymentData.operation_uuid
                || !paymentData.confirm_token
                || !paymentData.confirm_nonce) {
                lockUi(detail, t('uncertain'));
                return;
            }

            state.checkoutToken = paymentData.checkout_token;

            // Register the message listener before opening the modal (avoids a race that could drop the event)
            detachMessageListener();
            state.messageHandler = createMessageHandler(detail, paymentData, state.checkoutToken);
            window.addEventListener('message', state.messageHandler);

            // Hide the full-screen processing overlay so the Helcim modal takes focus
            if (detail.paymentLoader) {
                detail.paymentLoader.hideLoader();
            }

            try {
                window.appendHelcimPayIframe(state.checkoutToken);
            } catch (err) {
                console.error('[YS Helcim FCT] appendHelcimPayIframe failed:', err);
                lockUi(detail, t('uncertain'));
            }
        }).catch(function (err) {
            console.error('[YS Helcim FCT] Order-creation flow error:', err);
            resetUi(detail, t('order_failed'));
        });
    }

    /**
     * Render the custom pay button plus the status/error regions inside the container
     *
     * @param {Element} container Payment container
     * @param {Object}  detail    e.detail from the load_payments event
     * @param {Object}  info      paymentInfoUrl response (display info, no secrets)
     */
    function renderButton(container, detail, info) {
        var buttonText = (info && info.payment_args && info.payment_args.button_text) || t('button_text');

        container.innerHTML = '';

        var wrapper = document.createElement('div');
        wrapper.className = 'ys-helcim-pay-wrapper';

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

        // Hidden status region (kept for future use; current status is surfaced through the FluentCart loader)
        var status = document.createElement('div');
        status.className = 'ys-helcim-status';
        status.style.display = 'none';
        status.setAttribute('aria-live', 'polite');

        var error = document.createElement('div');
        error.className = 'ys-helcim-error';
        error.style.display = 'none';
        error.setAttribute('role', 'alert');

        wrapper.appendChild(button);
        wrapper.appendChild(status);
        wrapper.appendChild(error);
        container.appendChild(wrapper);
    }

    /**
     * FluentCart loads this payment method (fires on initial load, on switching payment method,
     * and after fragments are swapped; the container may be brand-new DOM, so we re-render every time)
     *
     * @param {CustomEvent} e fluent_cart_load_payments_ys_helcim event
     */
    function onLoadPayments(e) {
        var detail = e.detail || {};
        var container = document.querySelector(CONTAINER_SELECTOR);
        if (!container) {
            return;
        }

        if (state.reloadRequired) {
            renderContainerError(container, t('uncertain'));
            if (detail.paymentLoader && typeof detail.paymentLoader.disableCheckoutButton === 'function') {
                detail.paymentLoader.disableCheckoutButton();
            }
            return;
        }

        // Reset flow state; while a payment flow is in progress (e.g. a background fragments swap) do not tear down the active listener
        if (!state.processing) {
            detachMessageListener();
            state.finished = false;
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
            renderButton(container, detail, info);
            dispatchLoadingEvent('loading_success');
        }).catch(function () {
            renderContainerError(container, t('init_failed'));
            dispatchLoadingEvent('loading_failed');
        });
    }

    // ---- Entry point ----
    if (!cfg || !cfg.ajax_url || !cfg.confirm_action) {
        console.error('[YS Helcim FCT] Localized data ys_helcim_fct_data is missing; the HelcimPay checkout flow cannot start.');
        return;
    }

    window.addEventListener('fluent_cart_load_payments_' + SLUG, onLoadPayments);
})();
