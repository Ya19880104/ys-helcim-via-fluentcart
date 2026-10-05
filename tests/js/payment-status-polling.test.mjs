import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
import { describe, expect, it } from 'vitest';

const inlineScript = readFileSync(
  fileURLToPath(new URL('../../assets/js/ys-helcim-js-checkout.js', import.meta.url)),
  'utf8',
);
const hostedScript = readFileSync(
  fileURLToPath(new URL('../../assets/js/ys-helcim-pay-checkout.js', import.meta.url)),
  'utf8',
);

const STATUS_ACTION = 'ys_helcim_fct_payment_status';

function jsonResponse(payload) {
  return { json: () => Promise.resolve(payload) };
}

function flushPromises() {
  return new Promise((resolve) => setTimeout(resolve, 0));
}

function wait(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function actionOf(options = {}) {
  return new URLSearchParams(String(options.body || '')).get('action');
}

function loader(counters) {
  return {
    changeLoaderStatus() {},
    triggerPaymentCompleteEvent(resp) { counters.completed.push(resp); },
    hideLoader() {},
    enableCheckoutButton() { counters.enabled += 1; },
    disableCheckoutButton() { counters.disabled += 1; },
  };
}

function newCounters() {
  return { completed: [], redirects: [], enabled: 0, disabled: 0, statusCalls: 0, orderCalls: 0 };
}

// ---- Helcim.js inline form ----

const inlinePaymentData = {
  transaction_uuid: 'fc-transaction-123',
  confirm_nonce: 'confirm-nonce',
  confirm_token: 'signed-confirm-token',
  status_token: 'signed-status-token',
  js_token: 'helcim-js-token',
};

function createInline(counters, confirmHandler, statusResponses) {
  const dom = new JSDOM(
    '<!doctype html><div class="fluent-cart-checkout_embed_payment_container_ys_helcim_js"></div>',
    { runScripts: 'outside-only', url: 'https://shop.test/checkout' },
  );
  const { window } = dom;
  window.ys_helcim_js_fct_data = {
    ajax_url: 'https://shop.test/wp-admin/admin-ajax.php',
    confirm_action: 'ys_helcim_fct_confirm_js',
    status_action: STATUS_ACTION,
    status_poll_delays_ms: [1, 1, 1, 1],
    translations: {},
  };
  window.CheckoutHelper = { handleCheckoutRedirect(url) { counters.redirects.push(url); } };
  window.fetch = (url, options = {}) => {
    if (String(url).includes('/payment-info')) {
      return Promise.resolve(jsonResponse({ payment_args: {} }));
    }
    if (actionOf(options) === STATUS_ACTION) {
      const body = new URLSearchParams(String(options.body || ''));
      expect(body.get('transaction_uuid')).toBe(inlinePaymentData.transaction_uuid);
      expect(body.get('status_token')).toBe(inlinePaymentData.status_token);
      counters.statusCalls += 1;
      const next = statusResponses.length > 1 ? statusResponses.shift() : statusResponses[0];
      return Promise.resolve(jsonResponse(next));
    }
    return confirmHandler();
  };
  window.helcimProcess = () => {
    const results = window.document.getElementById('helcimResults');
    for (const [id, value] of Object.entries({
      response: '1',
      cardToken: 'ephemeral-card-token',
      xmlHash: 'provider-proof-hash',
      xml: '<message><response>1</response><type>verify</type></message>',
    })) {
      const input = window.document.createElement('input');
      input.type = 'hidden';
      input.id = id;
      input.value = value;
      results.appendChild(input);
    }
    return Promise.resolve();
  };
  window.eval(inlineScript);
  return window;
}

async function payInline(window, counters, paymentData = inlinePaymentData) {
  const detail = {
    paymentInfoUrl: 'https://shop.test/payment-info',
    nonce: 'fluent-cart-nonce',
    orderHandler() {
      counters.orderCalls += 1;
      return Promise.resolve({ payment_data: paymentData });
    },
    paymentLoader: loader(counters),
  };
  window.dispatchEvent(new window.CustomEvent('fluent_cart_load_payments_ys_helcim_js', { detail }));
  await flushPromises();
  await flushPromises();
  window.document.getElementById('cardNumber').value = '5454545454545454';
  window.document.getElementById('cardExpiry').value = '12 / 29';
  window.document.getElementById('cardCVV').value = '123';
  window.document.querySelector('.ys-helcim-pay-button').click();
  await wait(600);
  await flushPromises();
}

describe('Uncertain inline payment results are resolved from server state', () => {
  it('redirects to the receipt when the webhook finished the payment the browser was told is still verifying', async () => {
    // Production incident (order 53): the card was charged and the order paid, but the
    // browser request lost the race to Helcim's webhook and the shopper saw
    // "still being verified. Do not submit another payment." with no receipt.
    const counters = newCounters();
    const window = createInline(
      counters,
      () => Promise.resolve(jsonResponse({
        status: 'pending',
        retry_allowed: false,
        message: 'Your payment result is still being verified. Do not submit another payment.',
      })),
      [
        { status: 'pending', code: 'finalizing', message: 'Still confirming' },
        { status: 'success', redirect_url: 'https://shop.test/receipt/fc-transaction-123', order: { uuid: 'order-uuid' } },
      ],
    );

    try {
      await payInline(window, counters);
      await wait(50);

      expect(counters.statusCalls).toBe(2);
      expect(counters.redirects).toEqual(['https://shop.test/receipt/fc-transaction-123']);
      expect(counters.completed[0].order.uuid).toBe('order-uuid');
    } finally {
      window.close();
    }
  });

  it('lets the shopper try again only after the server twice proves no payment attempt exists', async () => {
    const counters = newCounters();
    const window = createInline(
      counters,
      () => Promise.reject(new Error('confirmation request never reached the server')),
      [{ status: 'failed', retry_allowed: true, code: 'no_payment_attempt', message: 'No payment was taken. Please check your card details and try again.' }],
    );

    try {
      await payInline(window, counters);
      await wait(50);

      const button = window.document.querySelector('.ys-helcim-pay-button');
      expect(counters.statusCalls).toBe(2);
      expect(button.disabled).toBe(false);
      expect(counters.disabled).toBe(0);
      expect(window.document.querySelector('.ys-helcim-error').textContent).toMatch(/No payment was taken/);

      button.click();
      await wait(50);
      expect(counters.orderCalls).toBe(2);
    } finally {
      window.close();
    }
  });

  it('keeps the page locked when the result stays unknown after every status check', async () => {
    const counters = newCounters();
    const window = createInline(
      counters,
      () => Promise.resolve(jsonResponse({ status: 'pending', retry_allowed: false, message: 'verifying' })),
      [{ status: 'pending', code: 'verifying', message: 'We are still confirming your payment. Please do not pay again.' }],
    );

    try {
      await payInline(window, counters);
      await wait(80);

      const button = window.document.querySelector('.ys-helcim-pay-button');
      expect(counters.statusCalls).toBe(4);
      expect(button.disabled).toBe(true);
      expect(counters.disabled).toBe(1);
      expect(window.document.querySelector('.ys-helcim-error').textContent).toMatch(/do not pay again/i);
      expect(counters.redirects).toEqual([]);
    } finally {
      window.close();
    }
  });

  it('falls back to the reload lock when the order response carries no status token', async () => {
    const counters = newCounters();
    const { status_token: omitted, ...withoutStatusToken } = inlinePaymentData;
    const window = createInline(
      counters,
      () => Promise.reject(new Error('lost')),
      [{ status: 'failed', retry_allowed: true, code: 'no_payment_attempt' }],
    );

    try {
      await payInline(window, counters, withoutStatusToken);
      await wait(50);

      expect(omitted).toBe('signed-status-token');
      expect(counters.statusCalls).toBe(0);
      expect(window.document.querySelector('.ys-helcim-pay-button').disabled).toBe(true);
    } finally {
      window.close();
    }
  });
});

// ---- HelcimPay.js hosted window ----

const hostedPaymentData = {
  checkout_token: 'checkout-token-741',
  transaction_uuid: 'fc-transaction-741',
  operation_uuid: '00000000-0000-4000-8000-000000000741',
  confirm_token: 'confirm-token-741-with-enough-entropy',
  confirm_nonce: 'nonce-confirm-741',
  status_token: 'signed-status-token-741',
  mode: 'live',
};

function createHosted(counters, confirmHandler, statusResponses) {
  const dom = new JSDOM(
    '<!doctype html><div class="fluent-cart-checkout_embed_payment_container_ys_helcim"></div>',
    { runScripts: 'outside-only', url: 'https://shop.test/checkout' },
  );
  const { window } = dom;
  window.ys_helcim_fct_data = {
    ajax_url: 'https://shop.test/wp-admin/admin-ajax.php',
    confirm_action: 'ys_helcim_fct_confirm_pay',
    status_action: STATUS_ACTION,
    status_poll_delays_ms: [1, 1, 1],
    translations: {},
  };
  window.CheckoutHelper = { handleCheckoutRedirect(url) { counters.redirects.push(url); } };
  window.appendHelcimPayIframe = () => {};
  window.removeHelcimPayIframe = () => {};
  window.fetch = (url, options = {}) => {
    if (String(url).includes('/payment-info')) {
      return Promise.resolve(jsonResponse({ payment_args: {} }));
    }
    if (actionOf(options) === STATUS_ACTION) {
      counters.statusCalls += 1;
      const next = statusResponses.length > 1 ? statusResponses.shift() : statusResponses[0];
      return Promise.resolve(jsonResponse(next));
    }
    return confirmHandler();
  };
  window.eval(hostedScript);
  return window;
}

async function openHosted(window, counters) {
  const detail = {
    paymentInfoUrl: 'https://shop.test/payment-info',
    nonce: 'fluent-cart-nonce',
    orderHandler() {
      counters.orderCalls += 1;
      return Promise.resolve({ payment_data: hostedPaymentData });
    },
    paymentLoader: loader(counters),
  };
  window.dispatchEvent(new window.CustomEvent('fluent_cart_load_payments_ys_helcim', { detail }));
  await flushPromises();
  await flushPromises();
  window.document.querySelector('.ys-helcim-pay-button').click();
  await flushPromises();
  await flushPromises();
}

function hostedMessage(window, status, eventMessage) {
  return new window.MessageEvent('message', {
    origin: 'https://secure.helcim.app',
    data: {
      eventName: 'helcim-pay-js-' + hostedPaymentData.checkout_token,
      eventStatus: status,
      eventMessage,
    },
  });
}

describe('Uncertain hosted payment results are resolved from server state', () => {
  it('redirects to the receipt instead of locking when confirmation needs reconciliation', async () => {
    const counters = newCounters();
    const window = createHosted(
      counters,
      () => Promise.resolve(jsonResponse({
        status: 'failed',
        error_code: 'ys_helcim_confirm_attention_required',
        retry_allowed: false,
        message: 'The payment result needs reconciliation. Do not submit another payment.',
      })),
      [
        { status: 'pending', code: 'finalizing' },
        { status: 'success', redirect_url: 'https://shop.test/receipt/fc-transaction-741', order: { uuid: 'order-741' } },
      ],
    );

    try {
      await openHosted(window, counters);
      window.dispatchEvent(hostedMessage(window, 'SUCCESS', {
        data: {
          hash: 'provider-hash-741',
          data: { status: 'APPROVED', type: 'purchase', transactionId: '81177991', invoiceNumber: hostedPaymentData.operation_uuid },
        },
      }));
      await wait(60);

      expect(counters.statusCalls).toBe(2);
      expect(counters.redirects).toEqual(['https://shop.test/receipt/fc-transaction-741']);
    } finally {
      window.close();
    }
  });

  it('re-enables the pay button when a declined window is proven to have taken no payment', async () => {
    const counters = newCounters();
    const window = createHosted(
      counters,
      () => Promise.reject(new Error('confirm must not be called without proof')),
      [
        { status: 'pending', code: 'verifying' },
        { status: 'failed', retry_allowed: true, code: 'payment_declined', message: 'The card was declined and no payment was taken.' },
      ],
    );

    try {
      await openHosted(window, counters);
      window.dispatchEvent(hostedMessage(window, 'ABORTED', null));
      await wait(60);

      const button = window.document.querySelector('.ys-helcim-pay-button');
      expect(counters.statusCalls).toBe(2);
      expect(button.disabled).toBe(false);
      expect(counters.disabled).toBe(0);
      expect(window.document.querySelector('.ys-helcim-error').textContent).toMatch(/declined/);
    } finally {
      window.close();
    }
  });
});
