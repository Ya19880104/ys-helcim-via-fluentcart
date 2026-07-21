import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
import { describe, expect, it } from 'vitest';

const scriptPath = fileURLToPath(
  new URL('../../assets/js/ys-helcim-refund-admin.js', import.meta.url),
);
const stylePath = fileURLToPath(
  new URL('../../assets/css/ys-helcim-refund-admin.css', import.meta.url),
);

const localizedMessages = Object.freeze({
  restSameOrigin: 'The REST endpoint must use the same origin as WordPress.',
  requestFailed: 'Request failed.',
  invalidRefundOptions: 'Invalid refund options.',
  invalidCandidateTransactionId: 'Enter a valid candidate Helcim transaction ID.',
  inspectingPositiveEvidence: 'Inspecting positive Helcim evidence…',
  invalidPositiveEvidenceResponse: 'The positive evidence response is invalid.',
  positiveEvidenceInspected: 'Positive evidence inspected. Complete the exact confirmation to continue.',
  positiveEvidenceInspectionFailed: 'Positive evidence could not be inspected.',
  committingPositiveResolution: 'Committing the positive refund resolution…',
  invalidPositiveResolutionResponse: 'The positive resolution response is invalid.',
  positiveResolutionCommitted: 'Positive resolution committed. Reading the canonical refund operation…',
  positiveResolutionUnknown: 'Positive resolution status is unknown.',
  refundPageUnavailable: 'Refund page is unavailable.',
  noRefundableTransaction: 'No refundable Helcim transaction was found for this order.',
  refundBlocked: 'This Helcim refund is blocked until its accounting state is reconciled.',
  orderSummary: 'Order #%1$s · %2$s',
  refundOptionsLoaded: 'Refund options loaded.',
  invalidOrderId: 'Invalid order ID.',
  refundOptionsRequired: 'Refund options must be loaded first.',
  refundFormUnavailable: 'Refund form is unavailable.',
  invalidRefundAmount: 'Enter a valid refund amount.',
  operationLabel: 'Operation',
  effectiveOperationLabel: 'Effective operation',
  providerActionLabel: 'Provider action',
  remoteStatusLabel: 'Remote status',
  localStatusLabel: 'Local status',
  notificationLabel: 'Notification',
  effectStatusLabel: 'Effect status',
  warningsLabel: 'Warnings',
  errorCodeLabel: 'Error code',
  providerOutcomeIndeterminate: 'The provider outcome is indeterminate. Do not submit another refund; inspect positive evidence or reconcile this operation.',
  manualReconciliationRequired: 'The provider refund succeeded, but manual stock or local reconciliation is required. Do not submit another refund.',
  refundCompleted: 'The Helcim refund and local reconciliation completed.',
  refundNotCompleted: 'The refund was not completed. Review the result before trying again.',
  operationStatusUnreadable: 'Operation status could not be read.',
  refundStillReconciling: 'The refund is still reconciling. Do not submit it again; reconcile this operation.',
  noOperationToReconcile: 'There is no valid operation to reconcile.',
  readingDurableOperation: 'Reading the durable refund operation…',
  invalidRefundIntent: 'Refund intent is invalid.',
  submittingRefund: 'Submitting the Helcim refund…',
  refundStatusUnknownNoRetry: 'Refund status is unknown. Do not submit it again.',
  refundStatusUnknown: 'Refund status is unknown.',
  refundOptionsLoadFailed: 'Refund options could not be loaded.',
});

function loadApi(
  html = '<!doctype html><html><body></body></html>',
  url = 'https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds',
) {
  const dom = new JSDOM(html, {
    url,
    runScripts: 'outside-only',
  });
  dom.window.ysHelcimRefundAdminConfig = { autoStart: false };
  dom.window.eval(readFileSync(scriptPath, 'utf8'));

  const api = dom.window.YSHelcimRefundAdmin;
  const createController = api.createController;
  api.createController = (options = {}) => {
    const inputConfig = options.config && typeof options.config === 'object' ? options.config : {};
    const messages = Object.prototype.hasOwnProperty.call(inputConfig, 'messages')
      ? inputConfig.messages
      : localizedMessages;

    return createController({
      ...options,
      config: { ...inputConfig, messages },
    });
  };

  return { dom, api };
}

function canonicalHtml() {
  return `<!doctype html><html><body>
    <div id="ys-helcim-refund-admin">
      <form id="ys-helcim-refund-order-lookup">
        <input id="ys-helcim-refund-order-id" type="number" value="42">
        <button type="submit">Load</button>
      </form>
      <div id="ys-helcim-refund-status" hidden></div>
      <section id="ys-helcim-refund-context" hidden>
        <div id="ys-helcim-refund-summary"></div>
        <form id="ys-helcim-refund-form">
          <select id="ys-helcim-refund-transaction"></select>
          <input id="ys-helcim-refund-amount" type="number">
          <textarea id="ys-helcim-refund-reason"></textarea>
          <div id="ys-helcim-refund-items"></div>
          <input id="ys-helcim-refund-manage-stock" type="checkbox">
          <input id="ys-helcim-refund-cancel-subscription" type="checkbox" disabled>
          <button id="ys-helcim-refund-submit" type="submit">Submit</button>
          <button id="ys-helcim-refund-reconcile" type="button" hidden>Reconcile</button>
        </form>
        <dl id="ys-helcim-refund-operation" hidden></dl>
        <section id="ys-helcim-refund-resolution" hidden>
          <input id="ys-helcim-refund-resolution-candidate" type="text">
          <button id="ys-helcim-refund-resolution-inspect" type="button">Inspect</button>
          <dl id="ys-helcim-refund-resolution-evidence" hidden>
            <dd id="ys-helcim-refund-resolution-evidence-status"></dd>
            <dd id="ys-helcim-refund-resolution-source"></dd>
            <dd id="ys-helcim-refund-resolution-action"></dd>
          </dl>
          <div id="ys-helcim-refund-resolution-confirmation" hidden>
            <input id="ys-helcim-refund-resolution-attestation" type="checkbox">
            <code id="ys-helcim-refund-resolution-phrase"></code>
            <input id="ys-helcim-refund-resolution-typed-phrase" type="text">
            <button id="ys-helcim-refund-resolution-commit" type="button" disabled>Commit</button>
          </div>
        </section>
      </section>
    </div>
  </body></html>`;
}

function spaHtml() {
  return `<!doctype html><html><body>
    <div id="fluent_cart_plugin_app">
      <div class="fct-single-order-page">
        <div class="single-page-header">
          <div class="fct-btn-group sm">
            <button class="bulk-action-hide-only-mobile">Refund</button>
            <button class="bulk-action-hide-only-mobile">Edit</button>
          </div>
        </div>
      </div>
    </div>
  </body></html>`;
}

function jsonResponse(body, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
  };
}

describe('Helcim refund admin browser application', () => {
  it('contains no hard-coded user-visible English status or fallback copy', () => {
    const source = readFileSync(scriptPath, 'utf8');

    Object.values(localizedMessages).forEach((message) => {
      expect(source).not.toContain(`'${message}'`);
    });
    expect(source).not.toContain("'Order #'");
    expect(source).not.toContain(": 'Refund'");
    expect(source).not.toContain(": 'Helcim Refund'");
    expect(source).not.toContain(": 'Helcim refund is blocked until reconciliation is complete.'");
  });

  it('uses only valid allowlisted localized messages and fails closed otherwise', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const translatedController = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async () => {
        throw new Error('A validation failure must not send a request.');
      },
      config: {
        messages: {
          invalidOrderId: '訂單編號無效。',
          apiToken: 'must-not-leak',
        },
      },
    });
    const translatedError = await translatedController.loadOptions(0).then(
      () => null,
      (error) => error,
    );

    expect(translatedError).toBeTruthy();
    expect(translatedError.message).toBe('訂單編號無效。');

    const invalidController = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async () => {
        throw new Error('A validation failure must not send a request.');
      },
      config: {
        messages: {
          invalidOrderId: 42,
          apiToken: 'must-not-leak',
        },
      },
    });
    const invalidError = await invalidController.loadOptions(0).then(
      () => null,
      (error) => error,
    );

    expect(invalidError).toBeTruthy();
    expect(invalidError.message).toBe('');
    expect(invalidError.message).not.toContain('must-not-leak');
  });

  it('exposes a controller factory without forcing automatic startup', () => {
    expect(existsSync(scriptPath)).toBe(true);
    const { api } = loadApi();

    expect(typeof api.createController).toBe('function');
  });

  it('ships scoped canonical and SPA adapter styles', () => {
    expect(existsSync(stylePath)).toBe(true);
    const source = readFileSync(stylePath, 'utf8');

    expect(source).toContain('.ys-helcim-refund-admin');
    expect(source).toContain('.ys-helcim-refund-spa-notice');
    expect(source).toContain('.ys-helcim-refund-resolution');
    expect(source).not.toMatch(/(^|})\s*(button|input|select|textarea)\s*\{/m);
  });

  it.each([
    ['a non-admin browser config', false, {
      operation_uuid: '00000000-0000-4000-8000-000000000001',
      provider_action: 'refund',
    }],
    ['an invalid resolution operation', true, {
      operation_uuid: 'not-a-uuid',
      provider_action: 'decline',
    }],
  ])('keeps positive resolution hidden for %s', async (label, canResolve, resolutionOperation) => {
    void label;
    const { dom, api } = loadApi(canonicalHtml());
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async () => jsonResponse({
        order_id: 42,
        classification: 'blocked',
        currency: 'USD',
        order_remaining: 2100,
        transactions: [],
        items: [],
        resolution_operation: resolutionOperation,
      }),
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
        canResolve,
      },
    });

    await controller.start();

    expect(dom.window.document.querySelector('#ys-helcim-refund-resolution').hidden).toBe(true);
  });

  it('inspects positive evidence with an exact request body before enabling confirmation', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const candidate = '51177094';
    const challenge = 'a'.repeat(64);
    const phrase = `ATTEST AND RESOLVE ${operationUuid} WITH HELCIM ${candidate}`;
    const requests = [];
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async (url, options) => {
        requests.push({ url, options });
        if (url.endsWith('/refund-options')) {
          return jsonResponse({
            order_id: 42,
            classification: 'blocked',
            currency: 'USD',
            order_remaining: 2100,
            transactions: [],
            items: [],
            resolution_operation: {
              operation_uuid: operationUuid,
              provider_action: 'refund',
            },
          });
        }
        return jsonResponse({
          status: 'confirmation_required',
          operation_uuid: operationUuid,
          candidate_transaction_id: candidate,
          source_transaction_id: '51177061',
          action: 'resolve_positive',
          parent_attestation_required: true,
          challenge,
          challenge_expires_at: '2026-07-21 08:05:00',
          confirmation_phrase: phrase,
        });
      },
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
        canResolve: true,
      },
    });
    await controller.start();
    const resolution = dom.window.document.querySelector('#ys-helcim-refund-resolution');
    const typedPhrase = dom.window.document.querySelector('#ys-helcim-refund-resolution-typed-phrase');
    const attestation = dom.window.document.querySelector('#ys-helcim-refund-resolution-attestation');
    const commit = dom.window.document.querySelector('#ys-helcim-refund-resolution-commit');

    expect(resolution.hidden).toBe(false);
    dom.window.document.querySelector('#ys-helcim-refund-resolution-candidate').value = candidate;
    await controller.inspectResolution();

    expect(requests[1].url).toBe(
      `https://shop.test/wp-json/ys-fc-pay/v1/refund-resolutions/${operationUuid}/inspect`,
    );
    expect(requests[1].options.method).toBe('POST');
    expect(requests[1].options.credentials).toBe('same-origin');
    expect(requests[1].options.headers).toEqual({
      'Content-Type': 'application/json',
      'X-WP-Nonce': 'rest-nonce',
    });
    expect(JSON.parse(requests[1].options.body)).toEqual({
      candidate_transaction_id: candidate,
    });
    expect(dom.window.document.querySelector('#ys-helcim-refund-resolution-evidence-status').textContent)
      .toBe('confirmation_required');
    expect(dom.window.document.querySelector('#ys-helcim-refund-resolution-source').textContent)
      .toBe('51177061');
    expect(dom.window.document.querySelector('#ys-helcim-refund-resolution-action').textContent)
      .toBe('resolve_positive');
    expect(dom.window.document.querySelector('#ys-helcim-refund-resolution-phrase').textContent)
      .toBe(phrase);
    expect(commit.disabled).toBe(true);

    typedPhrase.value = phrase;
    typedPhrase.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
    expect(commit.disabled).toBe(true);
    attestation.checked = true;
    attestation.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
    expect(commit.disabled).toBe(false);
  });

  it('commits only inspected positive evidence and then polls the canonical operation', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const candidate = '51177094';
    const challenge = 'b'.repeat(64);
    const phrase = `RESOLVE ${operationUuid} WITH HELCIM ${candidate}`;
    const requests = [];
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async (url, options) => {
        requests.push({ url, options });
        if (url.endsWith('/refund-options')) {
          return jsonResponse({
            order_id: 42,
            classification: 'blocked',
            currency: 'USD',
            order_remaining: 2100,
            transactions: [],
            items: [],
            resolution_operation: {
              operation_uuid: operationUuid,
              provider_action: 'refund',
            },
          });
        }
        if (url.endsWith('/inspect')) {
          return jsonResponse({
            status: 'confirmation_required',
            operation_uuid: operationUuid,
            candidate_transaction_id: candidate,
            source_transaction_id: '51177061',
            action: 'resolve_positive',
            parent_attestation_required: false,
            challenge,
            challenge_expires_at: '2026-07-21 08:05:00',
            confirmation_phrase: phrase,
          });
        }
        if (url.endsWith('/commit')) {
          return jsonResponse({
            status: 'resolved',
            operation_uuid: operationUuid,
            remote_status: 'succeeded',
            replayed: false,
            local_recording_status: 'continued',
            local_status: 'recorded',
          }, 202);
        }
        return jsonResponse({
          operation_uuid: operationUuid,
          effective_operation_uuid: operationUuid,
          provider_action: 'refund',
          provider_transaction_id: candidate,
          refund_transaction_id: 88,
          remote_status: 'succeeded',
          local_status: 'applied',
          notification_status: 'delivered',
          retry_allowed: false,
        });
      },
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
        canResolve: true,
        pollAttempts: 1,
      },
    });
    await controller.start();
    dom.window.document.querySelector('#ys-helcim-refund-resolution-candidate').value = candidate;
    await controller.inspectResolution();
    const typedPhrase = dom.window.document.querySelector('#ys-helcim-refund-resolution-typed-phrase');
    typedPhrase.value = phrase;
    typedPhrase.dispatchEvent(new dom.window.Event('input', { bubbles: true }));

    await controller.commitResolution();

    const commitRequest = requests.find((entry) => entry.url.endsWith('/commit'));
    expect(commitRequest.options.method).toBe('POST');
    expect(JSON.parse(commitRequest.options.body)).toEqual({
      candidate_transaction_id: candidate,
      challenge,
      confirmation_phrase: phrase,
      parent_attestation: false,
    });
    expect(requests.some((entry) => (
      entry.url.endsWith(`/refund-operations/${operationUuid}`)
      && entry.options.method === 'GET'
    ))).toBe(true);
    expect(dom.window.document.querySelector('#ys-helcim-refund-operation').textContent)
      .toContain('applied');
    expect(dom.window.document.querySelector('#ys-helcim-refund-submit').disabled).toBe(true);
  });

  it('treats indeterminate as terminal and never lets a later negative read unlock submission', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    let operationReads = 0;
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      uuid: () => operationUuid,
      fetch: async (url, options) => {
        if (url.endsWith('/refund-options')) {
          return jsonResponse({
            order_id: 42,
            classification: 'helcim_only',
            currency: 'USD',
            order_remaining: 2100,
            transactions: [
              { id: 7, gateway: 'ys_helcim', payment_mode: 'test', remaining_refundable: 2100 },
            ],
            items: [],
          });
        }
        if (options.method === 'POST') {
          return jsonResponse({
            operation_uuid: operationUuid,
            effective_operation_uuid: operationUuid,
            provider_action: 'refund',
            provider_transaction_id: null,
            refund_transaction_id: null,
            remote_status: 'indeterminate',
            local_status: 'pending',
            notification_status: 'pending',
            retry_allowed: false,
            error_code: 'provider_outcome_unresolved',
          }, 202);
        }
        operationReads += 1;
        return jsonResponse({
          operation_uuid: operationUuid,
          effective_operation_uuid: operationUuid,
          provider_action: 'refund',
          provider_transaction_id: null,
          refund_transaction_id: null,
          remote_status: 'declined',
          local_status: 'pending',
          notification_status: 'pending',
          retry_allowed: true,
          error_code: 'card_declined',
        });
      },
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
        canResolve: true,
        pollAttempts: 1,
      },
    });
    await controller.start();
    dom.window.document.querySelector('#ys-helcim-refund-amount').value = '5.00';

    await controller.submitRefund();

    expect(operationReads).toBe(0);
    expect(dom.window.document.querySelector('#ys-helcim-refund-submit').disabled).toBe(true);
    expect(dom.window.document.querySelector('#ys-helcim-refund-resolution').hidden).toBe(false);

    await controller.reconcile();

    expect(operationReads).toBe(1);
    expect(dom.window.document.querySelector('#ys-helcim-refund-submit').disabled).toBe(true);
    expect(dom.window.document.querySelector('#ys-helcim-refund-resolution').hidden).toBe(true);
  });

  it('clears an indeterminate lock only after a fresh options read for another order', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const operationUuid = '11111111-2222-4333-8444-555555555555';
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async (url) => {
        if (url.includes('/orders/42/')) {
          return jsonResponse({
            order_id: 42,
            classification: 'blocked',
            currency: 'USD',
            order_remaining: 2100,
            transactions: [
              { id: 7, gateway: 'ys_helcim_js', payment_mode: 'test', remaining_refundable: 2100 },
            ],
            items: [],
            resolution_operation: {
              operation_uuid: operationUuid,
              provider_action: 'refund',
            },
          });
        }
        return jsonResponse({
          order_id: 43,
          classification: 'helcim_only',
          currency: 'USD',
          order_remaining: 500,
          transactions: [
            { id: 8, gateway: 'ys_helcim_js', payment_mode: 'test', remaining_refundable: 500 },
          ],
          items: [],
          resolution_operation: null,
        });
      },
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
        canResolve: true,
      },
    });

    await controller.start();
    expect(dom.window.document.querySelector('#ys-helcim-refund-submit').disabled).toBe(true);

    await controller.loadOptions(43);

    expect(dom.window.document.querySelector('#ys-helcim-refund-resolution').hidden).toBe(true);
    expect(dom.window.document.querySelector('#ys-helcim-refund-form').hidden).toBe(false);
    expect(dom.window.document.querySelector('#ys-helcim-refund-submit').disabled).toBe(false);
  });

  it('loads and renders server-classified canonical refund options', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const requests = [];
    const fetch = async (url, options) => {
      requests.push({ url, options });
      return jsonResponse({
        order_id: 42,
        classification: 'helcim_only',
        currency: 'USD',
        order_remaining: 2100,
        transactions: [
          {
            id: 7,
            gateway: 'ys_helcim',
            payment_mode: 'test',
            remaining_refundable: 2100,
          },
        ],
        items: [
          {
            id: 9,
            variation_id: 19,
            title: 'Digital product',
            quantity: 1,
            refundable_quantity: 1,
          },
        ],
      });
    };
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch,
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        adminPageUrl: 'https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds',
        initialOrderId: 42,
      },
    });

    await controller.start();

    expect(requests).toHaveLength(1);
    expect(requests[0].url).toBe('https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options');
    expect(requests[0].options.method).toBe('GET');
    expect(requests[0].options.credentials).toBe('same-origin');
    expect(requests[0].options.headers['X-WP-Nonce']).toBe('rest-nonce');
    expect(dom.window.document.querySelector('#ys-helcim-refund-context').hidden).toBe(false);
    expect(dom.window.document.querySelector('#ys-helcim-refund-transaction').value).toBe('7');
    expect(dom.window.document.querySelector('#ys-helcim-refund-amount').value).toBe('21.00');
    expect(dom.window.document.querySelector('#ys-helcim-refund-amount').max).toBe('21.00');
    expect(dom.window.document.querySelector('#ys-helcim-refund-items').textContent).toContain('Digital product');
  });

  it('updates the amount value and maximum when the Helcim transaction changes', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async () => jsonResponse({
        order_id: 42,
        classification: 'helcim_only',
        currency: 'USD',
        order_remaining: 2600,
        transactions: [
          { id: 7, gateway: 'ys_helcim', payment_mode: 'test', remaining_refundable: 2100 },
          { id: 8, gateway: 'ys_helcim_js', payment_mode: 'test', remaining_refundable: 500 },
        ],
        items: [],
      }),
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
      },
    });
    await controller.start();
    const select = dom.window.document.querySelector('#ys-helcim-refund-transaction');
    const amount = dom.window.document.querySelector('#ys-helcim-refund-amount');

    select.value = '8';
    select.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

    expect(amount.value).toBe('5.00');
    expect(amount.max).toBe('5.00');
  });

  it.each([
    ['none', 'No refundable Helcim transaction'],
    ['blocked', 'blocked'],
  ])('keeps the refund form closed for %s server classification', async (classification, expectedMessage) => {
    const { dom, api } = loadApi(canonicalHtml());
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async () => jsonResponse({
        order_id: 42,
        classification,
        currency: 'USD',
        order_remaining: 0,
        transactions: [],
        items: [],
      }),
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
      },
    });

    await controller.start();

    expect(dom.window.document.querySelector('#ys-helcim-refund-context').hidden).toBe(true);
    expect(dom.window.document.querySelector('#ys-helcim-refund-status').textContent).toContain(expectedMessage);
  });

  it('fails closed when refund options cannot be authenticated or validated', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async () => jsonResponse({ message: 'Nonce expired.' }, 403),
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'expired',
        initialOrderId: 42,
      },
    });

    await controller.start();

    expect(dom.window.document.querySelector('#ys-helcim-refund-context').hidden).toBe(true);
    expect(dom.window.document.querySelector('#ys-helcim-refund-status').textContent).toContain('Nonce expired.');
  });

  it('never sends the REST nonce to a cross-origin endpoint', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    let requests = 0;
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async () => {
        requests += 1;
        return jsonResponse({});
      },
      config: {
        screen: 'canonical',
        restRoot: 'https://attacker.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
      },
    });

    await controller.start();

    expect(requests).toBe(0);
    expect(dom.window.document.querySelector('#ys-helcim-refund-context').hidden).toBe(true);
    expect(dom.window.document.querySelector('#ys-helcim-refund-status').textContent).toContain('same origin');
  });

  it('loads the order entered in the canonical lookup form', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const requests = [];
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async (url) => {
        requests.push(url);
        return jsonResponse({
          order_id: 51,
          classification: 'none',
          currency: 'USD',
          order_remaining: 0,
          transactions: [],
          items: [],
        });
      },
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 0,
      },
    });
    await controller.start();
    dom.window.document.querySelector('#ys-helcim-refund-order-id').value = '51';

    dom.window.document.querySelector('#ys-helcim-refund-order-lookup').dispatchEvent(
      new dom.window.Event('submit', { bubbles: true, cancelable: true }),
    );
    await new Promise((resolve) => dom.window.setTimeout(resolve, 0));

    expect(requests).toEqual(['https://shop.test/wp-json/ys-fc-pay/v1/orders/51/refund-options']);
  });

  it('posts the complete canonical refund intent and renders the reconciled result', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const requests = [];
    const fetch = async (url, options) => {
      requests.push({ url, options });
      if (options.method === 'GET') {
        return jsonResponse({
          order_id: 42,
          classification: 'helcim_only',
          currency: 'USD',
          order_remaining: 2100,
          transactions: [
            { id: 7, gateway: 'ys_helcim', payment_mode: 'test', remaining_refundable: 2100 },
          ],
          items: [
            {
              id: 9,
              variation_id: 19,
              title: 'Digital product',
              quantity: 1,
              refundable_quantity: 1,
            },
          ],
        });
      }
      return jsonResponse({
        operation_uuid: operationUuid,
        effective_operation_uuid: operationUuid,
        provider_action: 'refund',
        provider_transaction_id: '51177094',
        refund_transaction_id: 88,
        remote_status: 'succeeded',
        local_status: 'applied',
        notification_status: 'delivered',
        retry_allowed: false,
      });
    };
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch,
      uuid: () => operationUuid,
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
      },
    });
    await controller.start();
    dom.window.document.querySelector('#ys-helcim-refund-amount').value = '10.50';
    dom.window.document.querySelector('#ys-helcim-refund-reason').value = 'Customer request';
    dom.window.document.querySelector('.ys-helcim-refund-item').checked = true;
    expect(dom.window.document.querySelector('.ys-helcim-refund-item-quantity')).toBeNull();
    dom.window.document.querySelector('#ys-helcim-refund-manage-stock').checked = true;
    expect(dom.window.document.querySelector('#ys-helcim-refund-cancel-subscription').disabled).toBe(true);

    dom.window.document.querySelector('#ys-helcim-refund-form').dispatchEvent(
      new dom.window.Event('submit', { bubbles: true, cancelable: true }),
    );
    await controller.whenIdle();

    expect(requests).toHaveLength(2);
    expect(requests[1].url).toBe('https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refunds');
    expect(requests[1].options.method).toBe('POST');
    expect(requests[1].options.credentials).toBe('same-origin');
    expect(requests[1].options.headers['Content-Type']).toBe('application/json');
    expect(requests[1].options.headers['X-WP-Nonce']).toBe('rest-nonce');
    expect(JSON.parse(requests[1].options.body)).toEqual({
      operation_uuid: operationUuid,
      transaction_id: 7,
      amount: '10.50',
      reason: 'Customer request',
      item_ids: [9],
      manage_stock: false,
      refunded_items: [],
      cancel_subscription: false,
    });
    expect(dom.window.document.querySelector('#ys-helcim-refund-operation').hidden).toBe(false);
    expect(dom.window.document.querySelector('#ys-helcim-refund-operation').textContent).toContain('succeeded');
    expect(dom.window.document.querySelector('#ys-helcim-refund-operation').textContent).toContain('applied');
    expect(dom.window.document.querySelector('#ys-helcim-refund-submit').disabled).toBe(true);
    expect(dom.window.document.querySelector('#ys-helcim-refund-reconcile').hidden).toBe(true);

    dom.window.document.querySelector('#ys-helcim-refund-form').dispatchEvent(
      new dom.window.Event('submit', { bubbles: true, cancelable: true }),
    );
    await controller.whenIdle();
    expect(requests).toHaveLength(2);
  });

  it('polls the effective operation after a 202 response without repeating the refund POST', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const rootUuid = '00000000-0000-4000-8000-000000000001';
    const effectiveUuid = '00000000-0000-4000-8000-000000000002';
    const requests = [];
    let operationReads = 0;
    let sleeps = 0;
    const fetch = async (url, options) => {
      requests.push({ url, options });
      if (url.endsWith('/refund-options')) {
        return jsonResponse({
          order_id: 42,
          classification: 'helcim_only',
          currency: 'USD',
          order_remaining: 2100,
          transactions: [
            { id: 7, gateway: 'ys_helcim', payment_mode: 'test', remaining_refundable: 2100 },
          ],
          items: [],
        });
      }
      if (options.method === 'POST') {
        return jsonResponse({
          operation_uuid: rootUuid,
          effective_operation_uuid: effectiveUuid,
          provider_action: 'reverse',
          provider_transaction_id: '51177094',
          refund_transaction_id: 88,
          remote_status: 'succeeded',
          local_status: 'recorded',
          notification_status: 'pending',
          retry_allowed: false,
        }, 202);
      }
      operationReads += 1;
      return jsonResponse({
        operation_uuid: rootUuid,
        effective_operation_uuid: effectiveUuid,
        provider_action: 'reverse',
        provider_transaction_id: '51177094',
        refund_transaction_id: 88,
        remote_status: 'succeeded',
        local_status: operationReads === 1 ? 'recorded' : 'applied',
        notification_status: operationReads === 1 ? 'pending' : 'delivered',
        retry_allowed: false,
      });
    };
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch,
      uuid: () => rootUuid,
      sleep: async () => {
        sleeps += 1;
      },
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
        pollIntervalMs: 1,
        pollAttempts: 3,
      },
    });
    await controller.start();
    dom.window.document.querySelector('#ys-helcim-refund-amount').value = '5.00';

    await controller.submitRefund();

    expect(requests.filter((entry) => entry.options.method === 'POST')).toHaveLength(1);
    expect(
      requests.filter((entry) => entry.url.endsWith('/refund-operations/' + effectiveUuid)),
    ).toHaveLength(2);
    expect(sleeps).toBe(1);
    expect(dom.window.document.querySelector('#ys-helcim-refund-operation').textContent).toContain('applied');
    expect(dom.window.document.querySelector('#ys-helcim-refund-submit').disabled).toBe(true);
    expect(dom.window.document.querySelector('#ys-helcim-refund-reconcile').hidden).toBe(true);
  });

  it('stops polling and requires manual attention for stock reconciliation failure', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const requests = [];
    const fetch = async (url, options) => {
      requests.push({ url, options });
      if (url.endsWith('/refund-options')) {
        return jsonResponse({
          order_id: 42,
          classification: 'helcim_only',
          currency: 'USD',
          order_remaining: 2100,
          transactions: [
            { id: 7, gateway: 'ys_helcim', payment_mode: 'test', remaining_refundable: 2100 },
          ],
          items: [],
        });
      }
      if (options.method !== 'POST') {
        throw new Error('Manual stock state must not be polled automatically.');
      }
      return jsonResponse({
        operation_uuid: operationUuid,
        effective_operation_uuid: operationUuid,
        provider_action: 'refund',
        provider_transaction_id: '51177094',
        refund_transaction_id: 88,
        remote_status: 'succeeded',
        local_status: 'recorded',
        notification_status: 'pending',
        retry_allowed: false,
        effect_status: 'stock_reconciliation_required',
        warnings: ['stock_restore'],
        manual_reconciliation_required: true,
      }, 202);
    };
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch,
      uuid: () => operationUuid,
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
        pollAttempts: 3,
      },
    });
    await controller.start();
    dom.window.document.querySelector('#ys-helcim-refund-amount').value = '5.00';

    await controller.submitRefund();

    expect(requests).toHaveLength(2);
    expect(dom.window.document.querySelector('#ys-helcim-refund-submit').disabled).toBe(true);
    expect(dom.window.document.querySelector('#ys-helcim-refund-reconcile').hidden).toBe(true);
    expect(dom.window.document.querySelector('#ys-helcim-refund-status').textContent).toContain('manual stock');
  });

  it('locks duplicate submission after a lost POST response and reconciles by GET only', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const requests = [];
    const fetch = async (url, options) => {
      requests.push({ url, options });
      if (url.endsWith('/refund-options')) {
        return jsonResponse({
          order_id: 42,
          classification: 'helcim_only',
          currency: 'USD',
          order_remaining: 2100,
          transactions: [
            { id: 7, gateway: 'ys_helcim', payment_mode: 'test', remaining_refundable: 2100 },
          ],
          items: [],
        });
      }
      if (options.method === 'POST') {
        throw new Error('Connection closed after send.');
      }
      return jsonResponse({
        operation_uuid: operationUuid,
        effective_operation_uuid: operationUuid,
        provider_action: 'refund',
        provider_transaction_id: '51177094',
        refund_transaction_id: 88,
        remote_status: 'succeeded',
        local_status: 'applied',
        notification_status: 'delivered',
        retry_allowed: false,
      });
    };
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch,
      uuid: () => operationUuid,
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
        pollAttempts: 1,
      },
    });
    await controller.start();
    dom.window.document.querySelector('#ys-helcim-refund-amount').value = '5.00';

    await controller.submitRefund();

    expect(dom.window.document.querySelector('#ys-helcim-refund-submit').disabled).toBe(true);
    expect(dom.window.document.querySelector('#ys-helcim-refund-reconcile').hidden).toBe(false);
    dom.window.document.querySelector('#ys-helcim-refund-reconcile').dispatchEvent(
      new dom.window.Event('click', { bubbles: true, cancelable: true }),
    );
    await controller.whenIdle();

    expect(requests.filter((entry) => entry.options.method === 'POST')).toHaveLength(1);
    expect(
      requests.filter((entry) => entry.url.endsWith('/refund-operations/' + operationUuid)),
    ).toHaveLength(1);
    expect(dom.window.document.querySelector('#ys-helcim-refund-submit').disabled).toBe(true);
    expect(dom.window.document.querySelector('#ys-helcim-refund-reconcile').hidden).toBe(true);
    expect(dom.window.document.querySelector('#ys-helcim-refund-operation').textContent).toContain('applied');

    dom.window.document.querySelector('#ys-helcim-refund-form').dispatchEvent(
      new dom.window.Event('submit', { bubbles: true, cancelable: true }),
    );
    await controller.whenIdle();
    expect(requests.filter((entry) => entry.options.method === 'POST')).toHaveLength(1);
  });

  it('unlocks only when the server proves a terminal provider failure is retryable', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const fetch = async (url, options) => {
      if (url.endsWith('/refund-options')) {
        return jsonResponse({
          order_id: 42,
          classification: 'helcim_only',
          currency: 'USD',
          order_remaining: 2100,
          transactions: [
            { id: 7, gateway: 'ys_helcim', payment_mode: 'test', remaining_refundable: 2100 },
          ],
          items: [],
        });
      }
      expect(options.method).toBe('POST');
      return jsonResponse({
        operation_uuid: operationUuid,
        effective_operation_uuid: operationUuid,
        provider_action: 'refund',
        provider_transaction_id: null,
        refund_transaction_id: null,
        remote_status: 'declined',
        local_status: 'pending',
        notification_status: 'pending',
        retry_allowed: true,
        error_code: 'card_declined',
        message: 'The refund was declined.',
      }, 422);
    };
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch,
      uuid: () => operationUuid,
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
      },
    });
    await controller.start();
    dom.window.document.querySelector('#ys-helcim-refund-amount').value = '5.00';

    await controller.submitRefund();

    expect(dom.window.document.querySelector('#ys-helcim-refund-operation').textContent).toContain('declined');
    expect(dom.window.document.querySelector('#ys-helcim-refund-operation').textContent).toContain('card_declined');
    expect(dom.window.document.querySelector('#ys-helcim-refund-submit').disabled).toBe(false);
    expect(dom.window.document.querySelector('#ys-helcim-refund-reconcile').hidden).toBe(true);
  });

  it('unlocks when validation proves the provider operation was not started', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const fetch = async (url) => {
      if (url.endsWith('/refund-options')) {
        return jsonResponse({
          order_id: 42,
          classification: 'helcim_only',
          currency: 'USD',
          order_remaining: 2100,
          transactions: [
            { id: 7, gateway: 'ys_helcim', payment_mode: 'test', remaining_refundable: 2100 },
          ],
          items: [],
        });
      }
      return jsonResponse({
        operation_uuid: operationUuid,
        effective_operation_uuid: null,
        provider_action: null,
        provider_transaction_id: null,
        refund_transaction_id: null,
        remote_status: 'not_started',
        local_status: 'pending',
        notification_status: 'pending',
        retry_allowed: true,
        error_code: 'ys_helcim_invalid_refund_request',
        message: 'The refund request is invalid.',
      }, 422);
    };
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch,
      uuid: () => operationUuid,
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
      },
    });
    await controller.start();
    dom.window.document.querySelector('#ys-helcim-refund-amount').value = '5.00';

    await controller.submitRefund();

    expect(dom.window.document.querySelector('#ys-helcim-refund-operation').textContent).toContain('not_started');
    expect(dom.window.document.querySelector('#ys-helcim-refund-submit').disabled).toBe(false);
    expect(dom.window.document.querySelector('#ys-helcim-refund-reconcile').hidden).toBe(true);
  });

  it('replaces and intercepts only the native Refund action after helcim_only classification', async () => {
    const { dom, api } = loadApi(
      spaHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const navigations = [];
    const requests = [];
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      navigate: (url) => navigations.push(url),
      fetch: async (url, options) => {
        requests.push({ url, options });
        return jsonResponse({
          order_id: 42,
          classification: 'helcim_only',
          currency: 'USD',
          order_remaining: 2100,
          transactions: [
            { id: 7, gateway: 'ys_helcim', payment_mode: 'test', remaining_refundable: 2100 },
          ],
          items: [],
        });
      },
      config: {
        screen: 'spa',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        adminPageUrl: 'https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds',
        labels: { nativeRefund: 'Refund', helcimRefund: 'Helcim Refund' },
      },
    });

    await controller.start();

    const buttons = dom.window.document.querySelectorAll('button.bulk-action-hide-only-mobile');
    expect(requests).toHaveLength(1);
    expect(requests[0].url).toBe('https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options');
    expect(buttons[0].hidden).toBe(true);
    expect(buttons[1].hidden).toBe(false);
    const custom = dom.window.document.querySelector('[data-ys-helcim-refund-order="42"]');
    expect(custom).not.toBeNull();
    expect(custom.textContent).toContain('Helcim Refund');

    buttons[0].hidden = false;
    const refundClick = new dom.window.MouseEvent('click', { bubbles: true, cancelable: true });
    buttons[0].dispatchEvent(refundClick);
    const editClick = new dom.window.MouseEvent('click', { bubbles: true, cancelable: true });
    buttons[1].dispatchEvent(editClick);
    const mobileRefund = dom.window.document.createElement('li');
    mobileRefund.className = 'bulk-action-only-mobile';
    mobileRefund.textContent = 'Refund';
    dom.window.document.body.appendChild(mobileRefund);
    const mobileClick = new dom.window.MouseEvent('click', { bubbles: true, cancelable: true });
    mobileRefund.dispatchEvent(mobileClick);

    expect(refundClick.defaultPrevented).toBe(true);
    expect(editClick.defaultPrevented).toBe(false);
    expect(mobileClick.defaultPrevented).toBe(true);
    expect(navigations).toEqual([
      'https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds&order_id=42',
      'https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds&order_id=42',
    ]);
  });

  it('coexists with the native action for mixed-gateway orders', async () => {
    const { dom, api } = loadApi(
      spaHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const navigations = [];
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      navigate: (url) => navigations.push(url),
      fetch: async () => jsonResponse({
        order_id: 42,
        classification: 'mixed',
        currency: 'USD',
        order_remaining: 2100,
        transactions: [
          { id: 7, gateway: 'ys_helcim', payment_mode: 'test', remaining_refundable: 2100 },
        ],
        items: [],
      }),
      config: {
        screen: 'spa',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        adminPageUrl: 'https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds',
        labels: { nativeRefund: 'Refund', helcimRefund: 'Helcim Refund' },
      },
    });

    await controller.start();

    const native = dom.window.document.querySelector('button.bulk-action-hide-only-mobile');
    const click = new dom.window.MouseEvent('click', { bubbles: true, cancelable: true });
    native.dispatchEvent(click);
    expect(native.hidden).toBe(false);
    expect(click.defaultPrevented).toBe(false);
    expect(navigations).toEqual([]);
    expect(dom.window.document.querySelector('[data-ys-helcim-refund-order="42"]')).not.toBeNull();
  });

  it('blocks the native action and links to canonical reconciliation for blocked Helcim orders', async () => {
    const { dom, api } = loadApi(
      spaHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const navigations = [];
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      navigate: (url) => navigations.push(url),
      fetch: async () => jsonResponse({
        order_id: 42,
        classification: 'blocked',
        currency: 'USD',
        order_remaining: 0,
        transactions: [],
        items: [],
      }),
      config: {
        screen: 'spa',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        adminPageUrl: 'https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds',
        labels: {
          nativeRefund: 'Refund',
          helcimRefund: 'Helcim Refund',
          blocked: 'Helcim refund is blocked.',
        },
      },
    });

    await controller.start();

    const native = dom.window.document.querySelector('button.bulk-action-hide-only-mobile');
    expect(native.hidden).toBe(true);
    native.hidden = false;
    const click = new dom.window.MouseEvent('click', { bubbles: true, cancelable: true });
    native.dispatchEvent(click);
    expect(click.defaultPrevented).toBe(true);
    expect(navigations).toEqual([
      'https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds&order_id=42',
    ]);
    expect(dom.window.document.querySelector('[data-ys-helcim-refund-notice]').textContent)
      .toContain('Helcim refund is blocked.');
    const canonical = dom.window.document.querySelector('[data-ys-helcim-refund-order="42"]');
    expect(canonical).not.toBeNull();
    expect(canonical.textContent).toContain('Helcim Refund');
  });

  it('leaves the native action untouched for a proven non-Helcim order', async () => {
    const { dom, api } = loadApi(
      spaHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const navigations = [];
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      navigate: (url) => navigations.push(url),
      fetch: async () => jsonResponse({
        order_id: 42,
        classification: 'none',
        currency: 'USD',
        order_remaining: 0,
        transactions: [],
        items: [],
      }),
      config: {
        screen: 'spa',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        adminPageUrl: 'https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds',
        labels: { nativeRefund: 'Refund' },
      },
    });

    await controller.start();

    const native = dom.window.document.querySelector('button.bulk-action-hide-only-mobile');
    const click = new dom.window.MouseEvent('click', { bubbles: true, cancelable: true });
    native.dispatchEvent(click);
    expect(native.hidden).toBe(false);
    expect(click.defaultPrevented).toBe(false);
    expect(navigations).toEqual([]);
    expect(dom.window.document.querySelector('[data-ys-helcim-refund-order]')).toBeNull();
    expect(dom.window.document.querySelector('[data-ys-helcim-refund-notice]')).toBeNull();
  });

  it('fails closed with a retry notice when classification GET fails', async () => {
    const { dom, api } = loadApi(
      spaHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const navigations = [];
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      navigate: (url) => navigations.push(url),
      fetch: async () => jsonResponse({ message: 'Unavailable.' }, 503),
      config: {
        screen: 'spa',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        adminPageUrl: 'https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds',
        labels: { nativeRefund: 'Refund' },
      },
    });

    await controller.start();

    const native = dom.window.document.querySelector('button.bulk-action-hide-only-mobile');
    const click = new dom.window.MouseEvent('click', { bubbles: true, cancelable: true });
    native.dispatchEvent(click);
    expect(native.hidden).toBe(false);
    expect(click.defaultPrevented).toBe(true);
    expect(navigations).toEqual([]);
    expect(dom.window.document.querySelector('[data-ys-helcim-refund-order]')).toBeNull();
    expect(dom.window.document.querySelector('[data-ys-helcim-refund-notice]').textContent)
      .toContain('Unavailable.');
  });

  it('blocks native refund while classification is unresolved, then restores it after non-Helcim proof', async () => {
    const { dom, api } = loadApi(
      spaHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    let resolveClassification;
    const classification = new Promise((resolve) => {
      resolveClassification = resolve;
    });
    const navigations = [];
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      navigate: (url) => navigations.push(url),
      fetch: async () => classification,
      config: {
        screen: 'spa',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        adminPageUrl: 'https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds',
        labels: { nativeRefund: 'Refund' },
      },
    });

    const startup = controller.start();
    const native = dom.window.document.querySelector('button.bulk-action-hide-only-mobile');
    const unresolvedClick = new dom.window.MouseEvent('click', { bubbles: true, cancelable: true });
    native.dispatchEvent(unresolvedClick);
    expect(unresolvedClick.defaultPrevented).toBe(true);
    expect(dom.window.document.querySelector('[data-ys-helcim-refund-notice]').textContent)
      .toContain('Request failed.');

    resolveClassification(jsonResponse({
      order_id: 42,
      classification: 'none',
      currency: 'USD',
      order_remaining: 0,
      transactions: [],
      items: [],
    }));
    await startup;

    const provenNonHelcimClick = new dom.window.MouseEvent('click', {
      bubbles: true,
      cancelable: true,
    });
    native.dispatchEvent(provenNonHelcimClick);
    expect(provenNonHelcimClick.defaultPrevented).toBe(false);
    expect(native.hidden).toBe(false);
    expect(dom.window.document.querySelector('[data-ys-helcim-refund-notice]')).toBeNull();
    expect(navigations).toEqual([]);
  });

  it('reapplies a proven helcim_only classification after Vue replaces the header DOM', async () => {
    const { dom, api } = loadApi(
      spaHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    let reads = 0;
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async () => {
        reads += 1;
        return jsonResponse({
          order_id: 42,
          classification: 'helcim_only',
          currency: 'USD',
          order_remaining: 2100,
          transactions: [
            { id: 7, gateway: 'ys_helcim', payment_mode: 'test', remaining_refundable: 2100 },
          ],
          items: [],
        });
      },
      config: {
        screen: 'spa',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        adminPageUrl: 'https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds',
        labels: { nativeRefund: 'Refund', helcimRefund: 'Helcim Refund' },
      },
    });
    await controller.start();
    const group = dom.window.document.querySelector('.fct-btn-group.sm');

    group.replaceChildren();
    const newRefund = dom.window.document.createElement('button');
    newRefund.className = 'bulk-action-hide-only-mobile';
    newRefund.textContent = 'Refund';
    const newEdit = dom.window.document.createElement('button');
    newEdit.className = 'bulk-action-hide-only-mobile';
    newEdit.textContent = 'Edit';
    group.append(newRefund, newEdit);
    await new Promise((resolve) => dom.window.setTimeout(resolve, 0));

    expect(reads).toBe(1);
    expect(newRefund.hidden).toBe(true);
    expect(newEdit.hidden).toBe(false);
    expect(dom.window.document.querySelector('[data-ys-helcim-refund-order="42"]')).not.toBeNull();
  });

  it('discards a stale classification response after the FluentCart hash route changes', async () => {
    const { dom, api } = loadApi(
      spaHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    let resolveOrder42;
    const order42Response = new Promise((resolve) => {
      resolveOrder42 = resolve;
    });
    const requests = [];
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async (url) => {
        requests.push(url);
        if (url.includes('/orders/42/')) {
          return order42Response;
        }
        return jsonResponse({
          order_id: 43,
          classification: 'none',
          currency: 'USD',
          order_remaining: 0,
          transactions: [],
          items: [],
        });
      },
      config: {
        screen: 'spa',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        adminPageUrl: 'https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds',
        labels: { nativeRefund: 'Refund', helcimRefund: 'Helcim Refund' },
      },
    });

    const firstSync = controller.start();
    dom.reconfigure({ url: 'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/43/view' });
    dom.window.dispatchEvent(new dom.window.HashChangeEvent('hashchange'));
    await controller.whenIdle();
    resolveOrder42(jsonResponse({
      order_id: 42,
      classification: 'helcim_only',
      currency: 'USD',
      order_remaining: 2100,
      transactions: [
        { id: 7, gateway: 'ys_helcim', payment_mode: 'test', remaining_refundable: 2100 },
      ],
      items: [],
    }));
    await firstSync;

    expect(requests).toEqual([
      'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options',
      'https://shop.test/wp-json/ys-fc-pay/v1/orders/43/refund-options',
    ]);
    expect(dom.window.document.querySelector('button.bulk-action-hide-only-mobile').hidden).toBe(false);
    expect(dom.window.document.querySelector('[data-ys-helcim-refund-order]')).toBeNull();
  });
});
