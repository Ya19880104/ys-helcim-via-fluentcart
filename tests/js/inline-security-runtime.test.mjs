import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
import { describe, expect, it } from 'vitest';

const inlineScript = readFileSync(
  fileURLToPath(new URL('../../assets/js/ys-helcim-js-checkout.js', import.meta.url)),
  'utf8',
);

const paymentData = {
  transaction_uuid: 'fc-transaction-123',
  confirm_nonce: 'confirm-nonce',
  confirm_token: 'signed-confirm-token',
  js_token: 'helcim-js-token',
};

function jsonResponse(payload) {
  return {
    json: () => Promise.resolve(payload),
  };
}

function flushPromises() {
  return new Promise((resolve) => setTimeout(resolve, 0));
}

function createCheckout(fetchHandler) {
  const dom = new JSDOM(
    '<!doctype html><div class="fluent-cart-checkout_embed_payment_container_ys_helcim_js"></div>',
    {
      runScripts: 'outside-only',
      url: 'https://shop.test/checkout',
    },
  );
  const { window } = dom;

  window.ys_helcim_js_fct_data = {
    ajax_url: 'https://shop.test/wp-admin/admin-ajax.php',
    confirm_action: 'ys_helcim_fct_confirm_js',
    i18n: {},
  };
  window.fetch = fetchHandler;
  window.eval(inlineScript);

  return window;
}

function paymentLoader() {
  return {
    changeLoaderStatus() {},
    triggerPaymentCompleteEvent() {},
    hideLoader() {},
    enableCheckoutButton() {},
  };
}

function paymentDetail(orderHandler) {
  return {
    paymentInfoUrl: 'https://shop.test/payment-info',
    nonce: 'fluent-cart-nonce',
    orderHandler,
    paymentLoader: paymentLoader(),
  };
}

function dispatchLoad(window, detail) {
  window.dispatchEvent(
    new window.CustomEvent('fluent_cart_load_payments_ys_helcim_js', { detail }),
  );
}

async function renderCheckout(window, detail) {
  dispatchLoad(window, detail);
  await flushPromises();
  await flushPromises();
}

function submitValidCard(window) {
  window.document.getElementById('cardNumber').value = '5454545454545454';
  window.document.getElementById('cardExpiry').value = '12 / 29';
  window.document.getElementById('cardCVV').value = '123';
  window.document.querySelector('.ys-helcim-pay-button').click();
}

function writeSuccessfulTokenizeResult(window) {
  const results = window.document.getElementById('helcimResults');
  for (const [id, value] of Object.entries({
    response: '1',
    cardNumber: '5454****5454',
    cardToken: 'ephemeral-card-token',
    xmlHash: 'provider-proof-hash',
    xml: '<message><response>1</response><type>verify</type><cardToken>ephemeral-card-token</cardToken></message>',
  })) {
    const input = window.document.createElement('input');
    input.type = 'hidden';
    input.id = id;
    input.value = value;
    results.appendChild(input);
  }
}

describe('Helcim.js inline checkout runtime security boundaries', () => {
  it('does not create a FluentCart order when the Helcim SDK is unavailable', async () => {
    let orderCalls = 0;
    const window = createCheckout(() => Promise.resolve(jsonResponse({ payment_args: {} })));
    const detail = paymentDetail(() => {
      orderCalls += 1;
      return Promise.resolve({ payment_data: paymentData });
    });

    try {
      await renderCheckout(window, detail);
      submitValidCard(window);
      await flushPromises();
      await flushPromises();

      expect(orderCalls).toBe(0);
    } finally {
      window.close();
    }
  });

  it('preserves the active Helcim result container during a payment reload event', async () => {
    const window = createCheckout(() => Promise.resolve(jsonResponse({ payment_args: {} })));
    window.helcimProcess = () => new Promise(() => {});
    const detail = paymentDetail(() => Promise.resolve({ payment_data: paymentData }));

    try {
      await renderCheckout(window, detail);
      submitValidCard(window);
      await flushPromises();
      await flushPromises();

      const activeResults = window.document.getElementById('helcimResults');
      expect(activeResults).not.toBeNull();

      dispatchLoad(window, detail);

      expect(activeResults.isConnected).toBe(true);
      expect(window.document.getElementById('helcimResults')).toBe(activeResults);
    } finally {
      window.close();
    }
  });

  it('synchronizes current FluentCart billing fields before invoking Helcim.js', async () => {
    let observedAddress = '';
    let observedPostalCode = '';
    const window = createCheckout(() => Promise.resolve(jsonResponse({ payment_args: {} })));
    const address = window.document.createElement('input');
    address.id = 'billing_address_1';
    const postalCode = window.document.createElement('input');
    postalCode.id = 'billing_postcode';
    window.document.body.prepend(address, postalCode);
    window.helcimProcess = () => {
      observedAddress = window.document.getElementById('cardHolderAddress').value;
      observedPostalCode = window.document.getElementById('cardHolderPostalCode').value;
      return new Promise(() => {});
    };
    const detail = paymentDetail(() => Promise.resolve({ payment_data: paymentData }));

    try {
      await renderCheckout(window, detail);
      address.value = 'No. 1 Test Road';
      postalCode.value = '100';
      submitValidCard(window);
      await flushPromises();
      await flushPromises();

      expect(observedAddress).toBe('No. 1 Test Road');
      expect(observedPostalCode).toBe('100');
    } finally {
      window.close();
    }
  });

  it('locks checkout after an SDK timeout and requires a reload before another payment attempt', async () => {
    let now = 0;
    let orderCalls = 0;
    let sdkCalls = 0;
    let checkoutEnableCalls = 0;
    let checkoutDisableCalls = 0;
    const window = createCheckout(() => Promise.resolve(jsonResponse({ payment_args: {} })));
    window.Date.now = () => now;
    window.helcimProcess = () => {
      sdkCalls += 1;
      return new Promise(() => {});
    };
    const detail = {
      paymentInfoUrl: 'https://shop.test/payment-info',
      nonce: 'fluent-cart-nonce',
      orderHandler() {
        orderCalls += 1;
        return Promise.resolve({ payment_data: paymentData });
      },
      paymentLoader: {
        changeLoaderStatus() {},
        triggerPaymentCompleteEvent() {},
        hideLoader() {},
        enableCheckoutButton() {
          checkoutEnableCalls += 1;
        },
        disableCheckoutButton() {
          checkoutDisableCalls += 1;
        },
      },
    };

    try {
      await renderCheckout(window, detail);
      submitValidCard(window);
      await flushPromises();
      await flushPromises();

      now = 120001;
      await new Promise((resolve) => setTimeout(resolve, 450));

      const button = window.document.querySelector('.ys-helcim-pay-button');
      const error = window.document.querySelector('.ys-helcim-error');
      expect(orderCalls).toBe(1);
      expect(sdkCalls).toBe(1);
      expect(button.disabled).toBe(true);
      expect(checkoutEnableCalls).toBe(0);
      expect(checkoutDisableCalls).toBe(1);
      expect(error.textContent).toMatch(/refresh|reload/i);

      button.click();
      await flushPromises();
      await flushPromises();

      expect(orderCalls).toBe(1);
      expect(sdkCalls).toBe(1);
      expect(button.disabled).toBe(true);
    } finally {
      window.close();
    }
  });

  it('sends only the official XML proof envelope to the merchant confirm request', async () => {
    let confirmBody = '';
    const window = createCheckout((url, options = {}) => {
      if (String(url).includes('/payment-info')) {
        return Promise.resolve(jsonResponse({ payment_args: {} }));
      }

      confirmBody = String(options.body || '');
      return Promise.resolve(jsonResponse({ status: 'failed', message: 'Test complete' }));
    });
    window.helcimProcess = () => {
      const results = window.document.getElementById('helcimResults');
      const fields = {
        response: '1',
        cardNumber: '5454****5454',
        cardToken: 'ephemeral-card-token',
        xmlHash: 'provider-proof-hash',
        cardHolderName: 'Alice Example',
        cardExpiry: '1229',
        xml: '<transaction><customerName>Alice Example</customerName></transaction>',
      };

      for (const [id, value] of Object.entries(fields)) {
        const input = window.document.createElement('input');
        input.type = 'hidden';
        input.id = id;
        input.value = value;
        results.appendChild(input);
      }

      return Promise.resolve();
    };
    const detail = paymentDetail(() => Promise.resolve({ payment_data: paymentData }));

    try {
      await renderCheckout(window, detail);
      submitValidCard(window);
      await new Promise((resolve) => setTimeout(resolve, 500));

      expect(confirmBody).not.toBe('');
      const body = new URLSearchParams(confirmBody);
      expect(body.has('card_token')).toBe(false);
      const proof = JSON.parse(body.get('response_fields'));
      expect(Object.keys(proof).sort()).toEqual(['xml', 'xmlHash']);
      expect(proof.xmlHash).toBe('provider-proof-hash');
      expect(proof.xml).toContain('<transaction>');
      expect(body.has('cardNumber')).toBe(false);
      expect(body.has('response')).toBe(false);
    } finally {
      window.close();
    }
  });

  it('locks checkout when the capture confirmation response is lost', async () => {
    let orderCalls = 0;
    let checkoutEnableCalls = 0;
    let checkoutDisableCalls = 0;
    const window = createCheckout((url) => {
      if (String(url).includes('/payment-info')) {
        return Promise.resolve(jsonResponse({ payment_args: {} }));
      }
      return Promise.reject(new Error('response lost after capture'));
    });
    window.helcimProcess = () => {
      writeSuccessfulTokenizeResult(window);
      return Promise.resolve();
    };
    const detail = {
      paymentInfoUrl: 'https://shop.test/payment-info',
      nonce: 'fluent-cart-nonce',
      orderHandler() {
        orderCalls += 1;
        return Promise.resolve({ payment_data: paymentData });
      },
      paymentLoader: {
        changeLoaderStatus() {},
        triggerPaymentCompleteEvent() {},
        hideLoader() {},
        enableCheckoutButton() { checkoutEnableCalls += 1; },
        disableCheckoutButton() { checkoutDisableCalls += 1; },
      },
    };

    try {
      await renderCheckout(window, detail);
      submitValidCard(window);
      await new Promise((resolve) => setTimeout(resolve, 500));
      await flushPromises();

      const button = window.document.querySelector('.ys-helcim-pay-button');
      expect(button.disabled).toBe(true);
      expect(checkoutEnableCalls).toBe(0);
      expect(checkoutDisableCalls).toBe(1);
      expect(window.document.querySelector('.ys-helcim-error').textContent).toMatch(/refresh|contact|duplicate/i);

      button.click();
      await flushPromises();
      expect(orderCalls).toBe(1);
    } finally {
      window.close();
    }
  });

  it('re-enables checkout only when the server explicitly proves retry is safe', async () => {
    let checkoutEnableCalls = 0;
    let checkoutDisableCalls = 0;
    const window = createCheckout((url) => {
      if (String(url).includes('/payment-info')) {
        return Promise.resolve(jsonResponse({ payment_args: {} }));
      }
      return Promise.resolve(jsonResponse({
        status: 'failed',
        retry_allowed: true,
        message: 'The payment was declined.',
      }));
    });
    window.helcimProcess = () => {
      writeSuccessfulTokenizeResult(window);
      return Promise.resolve();
    };
    const detail = {
      paymentInfoUrl: 'https://shop.test/payment-info',
      nonce: 'fluent-cart-nonce',
      orderHandler: () => Promise.resolve({ payment_data: paymentData }),
      paymentLoader: {
        changeLoaderStatus() {},
        triggerPaymentCompleteEvent() {},
        hideLoader() {},
        enableCheckoutButton() { checkoutEnableCalls += 1; },
        disableCheckoutButton() { checkoutDisableCalls += 1; },
      },
    };

    try {
      await renderCheckout(window, detail);
      submitValidCard(window);
      await new Promise((resolve) => setTimeout(resolve, 500));
      await flushPromises();

      expect(window.document.querySelector('.ys-helcim-pay-button').disabled).toBe(false);
      expect(checkoutEnableCalls).toBe(1);
      expect(checkoutDisableCalls).toBe(0);
    } finally {
      window.close();
    }
  });
});
