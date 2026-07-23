import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
import { describe, expect, it } from 'vitest';

const hostedScript = readFileSync(
  fileURLToPath(new URL('../../assets/js/ys-helcim-pay-checkout.js', import.meta.url)),
  'utf8',
);

const paymentData = {
  checkout_token: 'checkout-token-741',
  transaction_uuid: 'fc-transaction-741',
  operation_uuid: '00000000-0000-4000-8000-000000000741',
  confirm_token: 'confirm-token-741-with-enough-entropy',
  confirm_nonce: 'nonce-confirm-741',
  mode: 'test',
};

function jsonResponse(payload) {
  return { json: () => Promise.resolve(payload) };
}

function flushPromises() {
  return new Promise((resolve) => setTimeout(resolve, 0));
}

function createCheckout(fetchHandler) {
  const dom = new JSDOM(
    '<!doctype html><div class="fluent-cart-checkout_embed_payment_container_ys_helcim"></div>',
    { runScripts: 'outside-only', url: 'https://shop.test/checkout' },
  );
  const { window } = dom;
  window.ys_helcim_fct_data = {
    ajax_url: 'https://shop.test/wp-admin/admin-ajax.php',
    confirm_action: 'ys_helcim_fct_confirm_pay',
    translations: {},
  };
  window.fetch = fetchHandler;
  window.eval(hostedScript);
  return window;
}

function detail(orderHandler, counters = {}) {
  return {
    paymentInfoUrl: 'https://shop.test/payment-info',
    nonce: 'fluent-cart-nonce',
    orderHandler,
    paymentLoader: {
      changeLoaderStatus() {},
      triggerPaymentCompleteEvent() {},
      hideLoader() {},
      enableCheckoutButton() { counters.enabled = (counters.enabled || 0) + 1; },
      disableCheckoutButton() { counters.disabled = (counters.disabled || 0) + 1; },
    },
  };
}

async function render(window, checkoutDetail) {
  window.dispatchEvent(new window.CustomEvent(
    'fluent_cart_load_payments_ys_helcim',
    { detail: checkoutDetail },
  ));
  await flushPromises();
  await flushPromises();
}

async function begin(window) {
  window.document.querySelector('.ys-helcim-pay-button').click();
  await flushPromises();
  await flushPromises();
}

function providerMessage(window, status, origin = 'https://secure.helcim.app') {
  return new window.MessageEvent('message', {
    origin,
    data: {
      eventName: 'helcim-pay-js-' + paymentData.checkout_token,
      eventStatus: status,
      eventMessage: {
        data: {
          hash: 'provider-hash-741',
          data: {
            status: 'APPROVED',
            type: 'purchase',
            transactionId: '51177991',
            amount: '21.00',
            currency: 'USD',
            invoiceNumber: paymentData.operation_uuid,
          },
        },
      },
    },
  });
}

describe('HelcimPay hosted checkout runtime security boundaries', () => {
  it('does not create an order when the hosted SDK is unavailable', async () => {
    let orderCalls = 0;
    const window = createCheckout(() => Promise.resolve(jsonResponse({ payment_args: {} })));
    const checkoutDetail = detail(() => {
      orderCalls += 1;
      return Promise.resolve({ payment_data: paymentData });
    });

    try {
      await render(window, checkoutDetail);
      await begin(window);
      expect(orderCalls).toBe(0);
    } finally {
      window.close();
    }
  });

  it('ignores cross-origin messages and forwards the durable operation token only for Helcim origin', async () => {
    let confirmBody = '';
    const window = createCheckout((url, options = {}) => {
      if (String(url).includes('/payment-info')) {
        return Promise.resolve(jsonResponse({ payment_args: {} }));
      }
      confirmBody = String(options.body || '');
      return Promise.resolve(jsonResponse({ status: 'failed', retry_allowed: false }));
    });
    window.appendHelcimPayIframe = () => {};
    window.removeHelcimPayIframe = () => {};
    const checkoutDetail = detail(() => Promise.resolve({ payment_data: paymentData }));

    try {
      await render(window, checkoutDetail);
      await begin(window);
      window.dispatchEvent(providerMessage(window, 'SUCCESS', 'https://evil.example'));
      await flushPromises();
      expect(confirmBody).toBe('');

      window.dispatchEvent(providerMessage(window, 'SUCCESS'));
      await flushPromises();
      await flushPromises();

      const body = new URLSearchParams(confirmBody);
      expect(body.get('transaction_uuid')).toBe(paymentData.transaction_uuid);
      expect(body.get('operation_uuid')).toBe(paymentData.operation_uuid);
      expect(body.get('confirm_token')).toBe(paymentData.confirm_token);
      expect(body.get('nonce')).toBe(paymentData.confirm_nonce);
    } finally {
      window.close();
    }
  });

  it('locks checkout after an unconfirmed provider success instead of enabling another charge', async () => {
    const counters = {};
    let orderCalls = 0;
    const window = createCheckout((url) => {
      if (String(url).includes('/payment-info')) {
        return Promise.resolve(jsonResponse({ payment_args: {} }));
      }
      return Promise.reject(new Error('network lost after provider result'));
    });
    window.appendHelcimPayIframe = () => {};
    window.removeHelcimPayIframe = () => {};
    const checkoutDetail = detail(() => {
      orderCalls += 1;
      return Promise.resolve({ payment_data: paymentData });
    }, counters);

    try {
      await render(window, checkoutDetail);
      await begin(window);
      window.dispatchEvent(providerMessage(window, 'SUCCESS'));
      await flushPromises();
      await flushPromises();

      const button = window.document.querySelector('.ys-helcim-pay-button');
      expect(button.disabled).toBe(true);
      expect(counters.enabled || 0).toBe(0);
      expect(counters.disabled).toBe(1);
      expect(window.document.querySelector('.ys-helcim-error').textContent).toMatch(/refresh|contact|duplicate/i);

      button.click();
      await flushPromises();
      expect(orderCalls).toBe(1);
    } finally {
      window.close();
    }
  });

  it('treats modal hide without signed proof as indeterminate and keeps reload events locked', async () => {
    const counters = {};
    let orderCalls = 0;
    const window = createCheckout(() => Promise.resolve(jsonResponse({ payment_args: {} })));
    window.appendHelcimPayIframe = () => {};
    window.removeHelcimPayIframe = () => {};
    const checkoutDetail = detail(() => {
      orderCalls += 1;
      return Promise.resolve({ payment_data: paymentData });
    }, counters);

    try {
      await render(window, checkoutDetail);
      await begin(window);
      window.dispatchEvent(providerMessage(window, 'HIDE'));
      await flushPromises();

      expect(window.document.querySelector('.ys-helcim-pay-button').disabled).toBe(true);
      expect(counters.disabled).toBe(1);

      window.dispatchEvent(new window.CustomEvent(
        'fluent_cart_load_payments_ys_helcim',
        { detail: checkoutDetail },
      ));
      await flushPromises();
      expect(window.document.querySelector('.ys-helcim-error').textContent).toMatch(/refresh|contact|duplicate/i);
      expect(orderCalls).toBe(1);
    } finally {
      window.close();
    }
  });

  it('keeps an aborted decline without signed proof locked while its result is verified', async () => {
    const counters = {};
    let confirmCalls = 0;
    let orderCalls = 0;
    const window = createCheckout((url) => {
      if (String(url).includes('/payment-info')) {
        return Promise.resolve(jsonResponse({ payment_args: {} }));
      }
      confirmCalls += 1;
      return Promise.resolve(jsonResponse({ status: 'success' }));
    });
    window.appendHelcimPayIframe = () => {};
    window.removeHelcimPayIframe = () => {};
    const checkoutDetail = detail(() => {
      orderCalls += 1;
      return Promise.resolve({ payment_data: paymentData });
    }, counters);

    try {
      await render(window, checkoutDetail);
      await begin(window);
      const aborted = providerMessage(window, 'ABORTED');
      aborted.data.eventMessage = null;
      window.dispatchEvent(aborted);
      await flushPromises();

      const button = window.document.querySelector('.ys-helcim-pay-button');
      const message = window.document.querySelector('.ys-helcim-error').textContent;
      expect(confirmCalls).toBe(0);
      expect(button.disabled).toBe(true);
      expect(counters.enabled || 0).toBe(0);
      expect(counters.disabled).toBe(1);
      expect(message).toMatch(/declined/i);
      expect(message).toMatch(/being verified/i);
      expect(message).toMatch(/do not retry/i);

      button.click();
      await flushPromises();
      expect(orderCalls).toBe(1);
    } finally {
      window.close();
    }
  });

  it('re-enables checkout after an exact definitive decline response', async () => {
    const counters = {};
    const window = createCheckout((url) => {
      if (String(url).includes('/payment-info')) {
        return Promise.resolve(jsonResponse({ payment_args: {} }));
      }
      return Promise.resolve(jsonResponse({
        status: 'failed',
        retry_allowed: true,
        message: 'The card was declined.',
      }));
    });
    window.appendHelcimPayIframe = () => {};
    window.removeHelcimPayIframe = () => {};
    const checkoutDetail = detail(() => Promise.resolve({ payment_data: paymentData }), counters);

    try {
      await render(window, checkoutDetail);
      await begin(window);
      window.dispatchEvent(providerMessage(window, 'SUCCESS'));
      await flushPromises();
      await flushPromises();

      expect(window.document.querySelector('.ys-helcim-pay-button').disabled).toBe(false);
      expect(counters.enabled).toBe(1);
      expect(counters.disabled || 0).toBe(0);
    } finally {
      window.close();
    }
  });
});
