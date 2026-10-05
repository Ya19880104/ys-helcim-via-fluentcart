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
const CVV_MESSAGE = 'The card security code (CVV) did not match. No payment was taken. Please check the code and try again.';
const EXPIRED_MESSAGE = 'The card has expired or the expiry date is incorrect. No payment was taken. Please check the date or use a different card.';
const ISSUER_MESSAGE = 'Your bank declined this payment. No payment was taken. Please contact your bank or use a different card.';

function jsonResponse(payload) {
  return { json: () => Promise.resolve(payload) };
}

function flushPromises() {
  return new Promise((resolve) => setTimeout(resolve, 0));
}

function wait(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function loader() {
  return {
    changeLoaderStatus() {},
    triggerPaymentCompleteEvent() {},
    hideLoader() {},
    enableCheckoutButton() {},
    disableCheckoutButton() {},
  };
}

function captureConsoleErrors(window) {
  const errors = [];
  window.console.error = (...args) => errors.push(args.join(' '));
  return errors;
}

// ---- Helcim.js inline form: a card declined during Helcim.js verification ----

function createInline(sdkResult) {
  const dom = new JSDOM(
    '<!doctype html><div class="fluent-cart-checkout_embed_payment_container_ys_helcim_js"></div>',
    { runScripts: 'outside-only', url: 'https://shop.test/checkout' },
  );
  const { window } = dom;
  const calls = { confirm: 0 };
  window.ys_helcim_js_fct_data = {
    ajax_url: 'https://shop.test/wp-admin/admin-ajax.php',
    confirm_action: 'ys_helcim_fct_confirm_js',
    status_action: STATUS_ACTION,
    translations: {},
  };
  window.fetch = (url) => {
    if (String(url).includes('/payment-info')) {
      return Promise.resolve(jsonResponse({ payment_args: {} }));
    }
    calls.confirm += 1;
    return Promise.reject(new Error('a declined verification must never reach the merchant server'));
  };
  window.helcimProcess = () => {
    const results = window.document.getElementById('helcimResults');
    for (const [id, value] of Object.entries(sdkResult)) {
      const input = window.document.createElement('input');
      input.type = 'hidden';
      input.id = id;
      input.value = value;
      results.appendChild(input);
    }
    return Promise.resolve();
  };
  window.eval(inlineScript);
  return { window, calls };
}

async function payInline(window) {
  const detail = {
    paymentInfoUrl: 'https://shop.test/payment-info',
    nonce: 'fluent-cart-nonce',
    orderHandler() {
      return Promise.resolve({
        payment_data: {
          transaction_uuid: 'fc-transaction-123',
          confirm_nonce: 'confirm-nonce',
          confirm_token: 'signed-confirm-token',
          status_token: 'signed-status-token',
          js_token: 'helcim-js-token',
        },
      });
    },
    paymentLoader: loader(),
  };
  window.dispatchEvent(new window.CustomEvent('fluent_cart_load_payments_ys_helcim_js', { detail }));
  await flushPromises();
  await flushPromises();
  window.document.getElementById('cardNumber').value = '4124939999999990';
  window.document.getElementById('cardExpiry').value = '01 / 28';
  window.document.getElementById('cardCVV').value = '200';
  window.document.querySelector('.ys-helcim-pay-button').click();
  await wait(600);
  await flushPromises();
}

describe('Helcim.js verification declines explain what to do next', () => {
  it('turns Helcim decline text into an actionable message and lets the shopper retry', async () => {
    const { window, calls } = createInline({
      response: '0',
      responseMessage: 'Transaction Declined: DECLINE CVF2 - Do not honor due to CVF2 mismatch\\failure',
    });
    try {
      await payInline(window);

      expect(window.document.querySelector('.ys-helcim-error').textContent).toBe(CVV_MESSAGE);
      expect(window.document.querySelector('.ys-helcim-pay-button').disabled).toBe(false);
      expect(calls.confirm).toBe(0);
    } finally {
      window.close();
    }
  });

  it('never tells the shopper about a fraud screen', async () => {
    const { window } = createInline({ response: '0', responseMessage: 'Transaction Declined: SUSPECTED FRAUD' });
    try {
      await payInline(window);

      const text = window.document.querySelector('.ys-helcim-error').textContent;
      expect(text).toBe(ISSUER_MESSAGE);
      expect(text).not.toMatch(/fraud/i);
    } finally {
      window.close();
    }
  });

  it('keeps Helcim text visible when the failure is not a card decline', async () => {
    const { window } = createInline({ response: '0', responseMessage: 'Invalid Helcim.js Token' });
    try {
      await payInline(window);

      expect(window.document.querySelector('.ys-helcim-error').textContent).toBe('Payment failed: Invalid Helcim.js Token');
    } finally {
      window.close();
    }
  });

  it.each([
    'Call support to enable this feature',
    'Address verification not configured',
  ])('never turns the configuration message "%s" into a card decline', async (responseMessage) => {
    const { window, calls } = createInline({ response: '0', responseMessage });
    try {
      await payInline(window);

      expect(window.document.querySelector('.ys-helcim-error').textContent).toBe('Payment failed: ' + responseMessage);
      expect(calls.confirm).toBe(0);
    } finally {
      window.close();
    }
  });

  it('still classifies a bare Helcim decline code such as DECLINE CVF2', async () => {
    const { window } = createInline({ response: '0', responseMessage: 'DECLINE CVF2 - Do not honor due to CVF2 mismatch' });
    try {
      await payInline(window);

      expect(window.document.querySelector('.ys-helcim-error').textContent).toBe(CVV_MESSAGE);
    } finally {
      window.close();
    }
  });
});

// ---- HelcimPay.js hosted window: Helcim emits ABORTED with plain decline text ----

const hostedPaymentData = {
  checkout_token: 'checkout-token-852',
  transaction_uuid: 'fc-transaction-852',
  operation_uuid: '00000000-0000-4000-8000-000000000852',
  confirm_token: 'confirm-token-852-with-enough-entropy',
  confirm_nonce: 'nonce-confirm-852',
  status_token: 'signed-status-token-852',
  mode: 'live',
};

function createHosted(statusResponses) {
  const dom = new JSDOM(
    '<!doctype html><div class="fluent-cart-checkout_embed_payment_container_ys_helcim"></div>',
    { runScripts: 'outside-only', url: 'https://shop.test/checkout' },
  );
  const { window } = dom;
  const calls = { status: 0, confirm: 0 };
  window.ys_helcim_fct_data = {
    ajax_url: 'https://shop.test/wp-admin/admin-ajax.php',
    confirm_action: 'ys_helcim_fct_confirm_pay',
    status_action: STATUS_ACTION,
    status_poll_delays_ms: [1, 1, 1],
    translations: {},
  };
  window.appendHelcimPayIframe = () => {};
  window.removeHelcimPayIframe = () => {};
  window.fetch = (url, options = {}) => {
    if (String(url).includes('/payment-info')) {
      return Promise.resolve(jsonResponse({ payment_args: {} }));
    }
    if (new URLSearchParams(String(options.body || '')).get('action') === STATUS_ACTION) {
      calls.status += 1;
      const next = statusResponses.length > 1 ? statusResponses.shift() : statusResponses[0];
      return Promise.resolve(jsonResponse(next));
    }
    calls.confirm += 1;
    return Promise.reject(new Error('plain decline text carries no proof to confirm'));
  };
  const consoleErrors = captureConsoleErrors(window);
  window.eval(hostedScript);
  return { window, calls, consoleErrors };
}

async function openHosted(window) {
  const detail = {
    paymentInfoUrl: 'https://shop.test/payment-info',
    nonce: 'fluent-cart-nonce',
    orderHandler() {
      return Promise.resolve({ payment_data: hostedPaymentData });
    },
    paymentLoader: loader(),
  };
  window.dispatchEvent(new window.CustomEvent('fluent_cart_load_payments_ys_helcim', { detail }));
  await flushPromises();
  await flushPromises();
  window.document.querySelector('.ys-helcim-pay-button').click();
  await flushPromises();
  await flushPromises();
}

function aborted(window, eventMessage) {
  return new window.MessageEvent('message', {
    origin: 'https://secure.helcim.app',
    data: {
      eventName: 'helcim-pay-js-' + hostedPaymentData.checkout_token,
      eventStatus: 'ABORTED',
      eventMessage,
    },
  });
}

describe('HelcimPay.js declines explain what to do next once the server proves them', () => {
  it('shows the reason from the payment window after the server confirms the decline', async () => {
    const { window, calls, consoleErrors } = createHosted([
      { status: 'pending', code: 'verifying' },
      { status: 'failed', retry_allowed: true, code: 'payment_declined', message: 'The card was declined and no payment was taken.' },
    ]);
    try {
      await openHosted(window);
      window.dispatchEvent(aborted(window, 'HelcimPay.js transaction failed - Transaction Declined: EXPIRED CARD - Expired Card'));
      await wait(60);

      expect(calls.status).toBe(2);
      expect(calls.confirm).toBe(0);
      expect(window.document.querySelector('.ys-helcim-error').textContent).toBe(EXPIRED_MESSAGE);
      expect(window.document.querySelector('.ys-helcim-pay-button').disabled).toBe(false);
      expect(consoleErrors).toEqual([]);
    } finally {
      window.close();
    }
  });

  it('follows the server when it proves a different no-charge outcome', async () => {
    const serverMessage = 'The previous payment window expired without a payment. Please try again.';
    const { window } = createHosted([
      { status: 'failed', retry_allowed: true, code: 'payment_window_expired', message: serverMessage },
    ]);
    try {
      await openHosted(window);
      window.dispatchEvent(aborted(window, 'HelcimPay.js transaction failed - Transaction Declined: EXPIRED CARD - Expired Card'));
      await wait(60);

      expect(window.document.querySelector('.ys-helcim-error').textContent).toBe(serverMessage);
    } finally {
      window.close();
    }
  });

  it('keeps the page locked while the server has not proven the decline', async () => {
    const { window } = createHosted([{ status: 'pending', code: 'verifying' }]);
    try {
      await openHosted(window);
      window.dispatchEvent(aborted(window, 'HelcimPay.js transaction failed - Transaction Declined: SUSPECTED FRAUD'));
      await wait(80);

      const text = window.document.querySelector('.ys-helcim-error').textContent;
      expect(text).not.toBe(ISSUER_MESSAGE);
      expect(text).not.toMatch(/fraud/i);
      expect(window.document.querySelector('.ys-helcim-pay-button').disabled).toBe(true);
    } finally {
      window.close();
    }
  });
});
