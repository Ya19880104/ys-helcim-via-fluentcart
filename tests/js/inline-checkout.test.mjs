import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
import { describe, expect, it } from 'vitest';

const inlineScriptPath = fileURLToPath(
  new URL('../../assets/js/ys-helcim-js-checkout.js', import.meta.url),
);

describe('Helcim.js developer-account contract', () => {
  it('never creates or mutates the deprecated SDK test field', () => {
    const source = readFileSync(inlineScriptPath, 'utf8');

    expect(source).not.toContain("createHidden('test'");
    expect(source).not.toContain('paymentData.test_mode');
    expect(source).not.toContain("getElementById('test')");
  });

  it('forwards the server-signed transaction confirmation token', () => {
    const source = readFileSync(inlineScriptPath, 'utf8');

    expect(source).toContain("body.append('confirm_token', paymentData.confirm_token || '')");
  });

  it('normalizes the displayed card number before invoking the SDK', () => {
    const source = readFileSync(inlineScriptPath, 'utf8');

    expect(source).toContain('numberInput.value = validation.cardDigits;');
  });

  it('handles a rejected SDK promise immediately instead of waiting for the poll timeout', () => {
    const source = readFileSync(inlineScriptPath, 'utf8');

    expect(source).toContain('Promise.resolve(window.helcimProcess()).catch(function (err)');
  });

  it('checks the SDK before creating an order and preserves an active tokenize container', () => {
    const source = readFileSync(inlineScriptPath, 'utf8');
    const payStart = source.indexOf('function onPayClick(detail)');
    const sdkCheck = source.indexOf("typeof window.helcimProcess !== 'function'", payStart);
    const orderCall = source.indexOf('Promise.resolve(detail.orderHandler())', payStart);
    const loadStart = source.indexOf('function onLoadPayments(e)');
    const processingGuard = source.indexOf('if (state.processing)', loadStart);
    const containerRewrite = source.indexOf("container.innerHTML = '<p", loadStart);

    expect(sdkCheck).toBeGreaterThan(payStart);
    expect(sdkCheck).toBeLessThan(orderCall);
    expect(processingGuard).toBeGreaterThan(loadStart);
    expect(processingGuard).toBeLessThan(containerRewrite);
  });

  it('sends only the official XML proof envelope to the merchant confirmation endpoint', () => {
    const source = readFileSync(inlineScriptPath, 'utf8');

    expect(source).toContain("var proofFieldNames = ['xml', 'xmlHash'];");
    expect(source).toContain("body.append('response_fields', JSON.stringify(proofFields));");
    expect(source).not.toContain("body.append('card_token'");
  });

  it('uses the non-empty duplicate FluentCart billing inputs for Helcim AVS', async () => {
    const dom = new JSDOM(
      `<!doctype html>
      <html>
        <body>
          <input id="billing_address_1" value="">
          <input id="billing_postcode" value="">
          <input id="billing_address_1" value="123 Test Street">
          <input id="billing_postcode" value="100">
          <div class="fluent-cart-checkout_embed_payment_container_ys_helcim_js"></div>
        </body>
      </html>`,
      {
        runScripts: 'outside-only',
        url: 'https://example.test/checkout/',
      },
    );
    const { window } = dom;
    let sdkAvs = null;

    window.ys_helcim_js_fct_data = {
      ajax_url: 'https://example.test/wp-admin/admin-ajax.php',
      confirm_action: 'ys_helcim_js_confirm',
    };
    window.fetch = async () => ({
      json: async () => ({
        fc_customer: { full_name: 'Codex Inline QA' },
        payment_args: { button_text: 'Pay now' },
      }),
    });
    window.setInterval = () => 1;
    window.clearInterval = () => {};
    window.helcimProcess = () => {
      sdkAvs = {
        address: window.document.getElementById('cardHolderAddress').value,
        postcode: window.document.getElementById('cardHolderPostalCode').value,
      };
      return Promise.resolve();
    };

    window.eval(readFileSync(inlineScriptPath, 'utf8'));
    const loaded = new Promise((resolve) => {
      window.addEventListener('fluent_cart_payment_method_loading_success', resolve, { once: true });
    });
    window.dispatchEvent(
      new window.CustomEvent('fluent_cart_load_payments_ys_helcim_js', {
        detail: {
          paymentInfoUrl: 'https://example.test/payment-info',
          nonce: 'test-nonce',
          orderHandler: async () => ({
            payment_data: {
              js_token: 'test-js-token',
              transaction_uuid: 'transaction-1',
              confirm_nonce: 'confirm-nonce',
              confirm_token: 'confirm-token',
            },
          }),
          paymentLoader: {
            changeLoaderStatus() {},
          },
        },
      }),
    );

    await loaded;

    window.document.getElementById('cardNumber').value = '4111111111111111';
    window.document.getElementById('cardExpiry').value = '01 / 28';
    window.document.getElementById('cardCVV').value = '100';
    window.document.querySelector('.ys-helcim-pay-button').click();

    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();

    expect(sdkAvs).toEqual({
      address: '123 Test Street',
      postcode: '100',
    });
  });

  it('uses the authoritative order billing AVS when the selected address editor is stale', async () => {
    const dom = new JSDOM(
      `<!doctype html>
      <html>
        <body>
          <input id="billing_address_1" value="Stale Street">
          <input id="billing_postcode" value="999">
          <div class="fluent-cart-checkout_embed_payment_container_ys_helcim_js"></div>
        </body>
      </html>`,
      {
        runScripts: 'outside-only',
        url: 'https://example.test/checkout/',
      },
    );
    const { window } = dom;
    let sdkAvs = null;

    window.ys_helcim_js_fct_data = {
      ajax_url: 'https://example.test/wp-admin/admin-ajax.php',
      confirm_action: 'ys_helcim_js_confirm',
    };
    window.fetch = async () => ({
      json: async () => ({
        payment_args: { button_text: 'Pay now' },
      }),
    });
    window.setInterval = () => 1;
    window.clearInterval = () => {};
    window.helcimProcess = () => {
      sdkAvs = {
        address: window.document.getElementById('cardHolderAddress').value,
        postcode: window.document.getElementById('cardHolderPostalCode').value,
      };
      return Promise.resolve();
    };

    window.eval(readFileSync(inlineScriptPath, 'utf8'));
    const loaded = new Promise((resolve) => {
      window.addEventListener('fluent_cart_payment_method_loading_success', resolve, { once: true });
    });
    window.dispatchEvent(
      new window.CustomEvent('fluent_cart_load_payments_ys_helcim_js', {
        detail: {
          paymentInfoUrl: 'https://example.test/payment-info',
          nonce: 'test-nonce',
          orderHandler: async () => ({
            payment_data: {
              js_token: 'test-js-token',
              transaction_uuid: 'transaction-2',
              confirm_nonce: 'confirm-nonce',
              confirm_token: 'confirm-token',
              cardholder_address: '123 Test Street',
              cardholder_postal_code: '100',
            },
          }),
          paymentLoader: {
            changeLoaderStatus() {},
          },
        },
      }),
    );

    await loaded;

    window.document.getElementById('cardNumber').value = '4111111111111111';
    window.document.getElementById('cardExpiry').value = '01 / 28';
    window.document.getElementById('cardCVV').value = '100';
    window.document.querySelector('.ys-helcim-pay-button').click();

    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();

    expect(sdkAvs).toEqual({
      address: '123 Test Street',
      postcode: '100',
    });
  });

  it('ignores an incomplete success callback and confirms from the complete SDK DOM result', async () => {
    const dom = new JSDOM(
      `<!doctype html>
      <html>
        <body>
          <div class="fluent-cart-checkout_embed_payment_container_ys_helcim_js"></div>
        </body>
      </html>`,
      {
        runScripts: 'outside-only',
        url: 'https://example.test/checkout/',
      },
    );
    const { window } = dom;
    let poll = null;
    let confirmRequests = 0;
    let redirectedTo = '';

    window.ys_helcim_js_fct_data = {
      ajax_url: 'https://example.test/wp-admin/admin-ajax.php',
      confirm_action: 'ys_helcim_js_confirm',
    };
    window.fetch = async (url) => {
      if (url === 'https://example.test/payment-info') {
        return {
          json: async () => ({
            payment_args: { button_text: 'Pay now' },
          }),
        };
      }
      confirmRequests += 1;
      return {
        json: async () => ({
          status: 'success',
          redirect_url: 'https://example.test/receipt/',
        }),
      };
    };
    window.setInterval = (callback) => {
      poll = callback;
      return 1;
    };
    window.clearInterval = () => {};
    window.CheckoutHelper = {
      handleCheckoutRedirect(url) {
        redirectedTo = url;
      },
    };
    window.helcimProcess = () => {
      window.helcimJsCallback({ response: '1' });

      const results = window.document.getElementById('helcimResults');
      const responseInput = window.document.createElement('input');
      responseInput.name = 'response';
      responseInput.value = '1';
      results.appendChild(responseInput);

      return Promise.resolve();
    };

    window.eval(readFileSync(inlineScriptPath, 'utf8'));
    const loaded = new Promise((resolve) => {
      window.addEventListener('fluent_cart_payment_method_loading_success', resolve, { once: true });
    });
    window.dispatchEvent(
      new window.CustomEvent('fluent_cart_load_payments_ys_helcim_js', {
        detail: {
          paymentInfoUrl: 'https://example.test/payment-info',
          nonce: 'test-nonce',
          orderHandler: async () => ({
            payment_data: {
              js_token: 'test-js-token',
              transaction_uuid: 'transaction-3',
              confirm_nonce: 'confirm-nonce',
              confirm_token: 'confirm-token',
            },
          }),
          paymentLoader: {
            changeLoaderStatus() {},
            triggerPaymentCompleteEvent() {},
            hideLoader() {},
            enableCheckoutButton() {},
            disableCheckoutButton() {},
          },
        },
      }),
    );

    await loaded;

    window.document.getElementById('cardNumber').value = '4111111111111111';
    window.document.getElementById('cardExpiry').value = '01 / 28';
    window.document.getElementById('cardCVV').value = '100';
    window.document.querySelector('.ys-helcim-pay-button').click();

    await new Promise((resolve) => globalThis.setTimeout(resolve, 0));
    expect(typeof poll).toBe('function');
    poll();
    expect(confirmRequests).toBe(0);

    const results = window.document.getElementById('helcimResults');
    [
      ['cardNumber', '4XXXXXXXXXXX9990'],
      ['cardToken', 'test-card-token'],
      ['xmlHash', 'test-response-hash'],
      ['xml', '<message><response>1</response><cardToken>test-card-token</cardToken></message>'],
    ].forEach(([name, value]) => {
      const input = window.document.createElement('input');
      input.name = name;
      input.value = value;
      results.appendChild(input);
    });
    poll();
    await new Promise((resolve) => globalThis.setTimeout(resolve, 0));

    expect(confirmRequests).toBe(1);
    expect(redirectedTo).toBe('https://example.test/receipt/');
  });
});
