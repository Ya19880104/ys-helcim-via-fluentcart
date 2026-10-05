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
const adminPagePath = fileURLToPath(
  new URL('../../src/Admin/YSHelcimRefundAdminPage.php', import.meta.url),
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
  refundCompleted: 'Helcim refunded the payment and FluentCart recorded the refund. The money usually reaches the card within 5 to 10 business days.',
  paymentVoided: 'The payment had not settled yet, so Helcim cancelled (voided) it instead of refunding it, and FluentCart recorded the refund. No processing fee applies, and the pending charge disappears from the card within 1 to 2 days.',
  refundNotCompleted: 'The refund was not completed. Review the result before trying again.',
  openBatchPartialRefund: 'This payment has not settled yet, so Helcim can only cancel the full amount. No money was moved. Enter the full amount to cancel the payment now, or make this partial refund after the payment settles (Helcim settles once a day).',
  openBatchUnproven: 'Helcim did not accept the refund, and the plugin could not confirm that the payment is still unsettled, so nothing was sent. Wait a few minutes and try again.',
  operationStatusUnreadable: 'Operation status could not be read.',
  refundStillReconciling: 'The refund is still reconciling. Do not submit it again; reconcile this operation.',
  noOperationToReconcile: 'There is no valid operation to reconcile.',
  readingDurableOperation: 'Reading the durable refund operation…',
  invalidRefundIntent: 'Refund intent is invalid.',
  submittingRefund: 'Submitting the Helcim refund…',
  refundStatusUnknownNoRetry: 'Refund status is unknown. Do not submit it again.',
  refundStatusUnknown: 'Refund status is unknown.',
  refundOptionsLoadFailed: 'Refund options could not be loaded.',
  classificationPending: 'Checking whether this payment was taken through Helcim. Please wait a moment and try again.',
  syncingProviderRefunds: 'Checking Helcim for refunds or voids made outside this plugin…',
  providerRefundsRecorded: 'Recorded refunds or voids from Helcim: %1$s. FluentCart now matches Helcim.',
  providerRefundsNothingNew: 'Helcim has no refunds or voids for this order that are missing from FluentCart.',
  providerRefundsNoHelcimPayment: 'This order has no completed Helcim payment to check.',
  providerRefundsNeedReview: 'Some refunds in Helcim could not be recorded automatically (%1$s). Compare this order with Helcim before refunding again.',
  providerRefundsRetryLater: 'Another refund for this order is still in progress, or Helcim could not be reached. Try again in a few minutes.',
  providerRefundsSyncFailed: 'Refunds could not be synced from Helcim.',
  providerRefundsLegacyPayment: 'This order was paid before this plugin started keeping a payment journal, so a refund or void made in Helcim cannot be synced automatically. Compare this order with Helcim before refunding.',
  batchClosedRefundRejected: 'Helcim rejected this refund, and the payment has already settled, so nothing was sent. Check in Helcim whether this payment was already refunded or voided. If it was, use “Sync refunds from Helcim” to record it in FluentCart.',
  providerRefundPending: 'A refund or void made directly in Helcim for this order is not recorded in FluentCart yet. Use “Sync refunds from Helcim” to finish recording it before refunding again.',
  refundModalBusy: 'The refund request is still being processed. Wait for the result before closing this window.',
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

// 狀態列標記直接取自 PHP renderPanelBody() 的 echo 字面值（不手寫），
// FluentCart 掛載清除 .notice／.error 的測試才會反映伺服器真正輸出的 class。
function serverStatusMarkup() {
  const source = readFileSync(adminPagePath, 'utf8');
  const matches = [...source.matchAll(/echo '(<div id="ys-helcim-refund-status"[^']*><\/div>)';/g)];
  if (matches.length !== 1) {
    throw new Error('renderPanelBody() must echo the status row exactly once as a single-quoted literal.');
  }
  return matches[0][1];
}

// 面板本體：獨立頁面與訂單頁彈窗共用同一份標記（與 PHP renderPanelBody() 相同的 id）。
function panelBodyMarkup() {
  return `
      <form id="ys-helcim-refund-order-lookup">
        <input id="ys-helcim-refund-order-id" type="number" value="42">
        <button type="submit">Load</button>
      </form>
      ${serverStatusMarkup()}
      <section id="ys-helcim-refund-sync" hidden>
        <button id="ys-helcim-refund-sync-button" type="button">Sync</button>
      </section>
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
            <dd id="ys-helcim-refund-resolution-candidate-type"></dd>
            <dd id="ys-helcim-refund-resolution-candidate-amount"></dd>
            <dd id="ys-helcim-refund-resolution-invoice"></dd>
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
`;
}

function canonicalHtml() {
  return `<!doctype html><html><body>
    <div id="ys-helcim-refund-admin">${panelBodyMarkup()}</div>
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

function spaOrderMarkup() {
  return `<div class="fct-single-order-page">
      <div class="single-page-header">
        <div class="fct-btn-group sm">
          <button class="el-button bulk-action-hide-only-mobile">Refund</button>
          <button class="el-button bulk-action-hide-only-mobile">Edit</button>
        </div>
      </div>
    </div>`;
}

function spaListHtml() {
  return `<!doctype html><html><body>
    <div id="fluent_cart_plugin_app">
      <div class="fct-orders-list"><a href="#/orders/42/view">#42</a></div>
    </div>
  </body></html>`;
}

function spaConfig() {
  return {
    screen: 'spa',
    restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
    restNonce: 'rest-nonce',
    adminPageUrl: 'https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds',
    labels: { nativeRefund: 'Refund', helcimRefund: 'Helcim Refund' },
  };
}

function helcimOnlyOptions(orderId) {
  return {
    order_id: orderId,
    classification: 'helcim_only',
    currency: 'USD',
    order_remaining: 123,
    transactions: [
      { id: 60, gateway: 'ys_helcim', payment_mode: 'test', remaining_refundable: 123 },
    ],
    items: [],
  };
}

function nextTask(dom) {
  return new Promise((resolve) => dom.window.setTimeout(resolve, 0));
}

// 頁面自動啟動（沒有 controller 可 await）時，逐個 macrotask 等條件成立。
async function waitFor(dom, predicate, attempts = 50) {
  for (let attempt = 0; attempt < attempts; attempt += 1) {
    if (predicate()) {
      return;
    }
    await nextTask(dom);
  }
  throw new Error('The expected browser state was not reached.');
}

function jsonResponse(body, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
  };
}

// 訂單頁退款彈窗：結構與 PHP renderModal() 輸出相同，掛在 #fluent_cart_plugin_app 之外。
function modalMarkup() {
  return `<div id="ys-helcim-refund-modal" class="ys-helcim-refund-modal" hidden aria-hidden="true">
      <div class="ys-helcim-refund-modal__backdrop" data-ys-helcim-refund-modal-close></div>
      <div class="ys-helcim-refund-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ys-helcim-refund-modal-title" tabindex="-1">
        <div class="ys-helcim-refund-modal__header">
          <h2 id="ys-helcim-refund-modal-title">Refund through Helcim</h2>
          <button type="button" class="ys-helcim-refund-modal__close" data-ys-helcim-refund-modal-close aria-label="Close">×</button>
        </div>
        <div class="ys-helcim-refund-admin ys-helcim-refund-modal__body" id="ys-helcim-refund-admin">${panelBodyMarkup()}</div>
      </div>
    </div>`;
}

function spaModalHtml() {
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
    ${modalMarkup()}
  </body></html>`;
}

function modalOrderOptions(orderId, overrides = {}) {
  return {
    order_id: orderId,
    classification: 'helcim_only',
    currency: 'USD',
    order_remaining: 2100,
    transactions: [
      { id: orderId === 43 ? 8 : 7, gateway: 'ys_helcim', payment_mode: 'test', remaining_refundable: 2100 },
    ],
    items: [
      { id: 9, title: 'Digital product', quantity: 1, refundable_quantity: 1 },
    ],
    ...overrides,
  };
}

function appliedRefund(operationUuid) {
  return {
    operation_uuid: operationUuid,
    effective_operation_uuid: operationUuid,
    provider_action: 'refund',
    provider_transaction_id: '81177094',
    refund_transaction_id: 88,
    remote_status: 'succeeded',
    local_status: 'applied',
    notification_status: 'delivered',
    retry_allowed: false,
  };
}

// 建立訂單頁（spa）控制器：navigate、reload 都是可注入相依，記錄呼叫次數。
function modalController(dom, api, respond, configOverrides = {}) {
  const navigations = [];
  const reloads = [];
  const requests = [];
  const controller = api.createController({
    window: dom.window,
    document: dom.window.document,
    navigate: (url) => navigations.push(url),
    reload: () => reloads.push(dom.window.location.href),
    uuid: () => '00000000-0000-4000-8000-000000000001',
    sleep: async () => {},
    fetch: async (url, options) => {
      requests.push({ url, options });
      return respond(url, options);
    },
    config: { ...spaConfig(), modalEnabled: true, ...configOverrides },
  });
  return { controller, navigations, reloads, requests };
}

function modalParts(dom) {
  const doc = dom.window.document;
  const modal = doc.querySelector('#ys-helcim-refund-modal');
  return {
    doc,
    modal,
    dialog: modal ? modal.querySelector('.ys-helcim-refund-modal__dialog') : null,
    close: modal ? modal.querySelector('.ys-helcim-refund-modal__close') : null,
    backdrop: modal ? modal.querySelector('.ys-helcim-refund-modal__backdrop') : null,
  };
}

function leftClick(dom, target, init = {}) {
  const event = new dom.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
  target.dispatchEvent(event);
  return event;
}

function pressKey(dom, target, key, init = {}) {
  const event = new dom.window.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init });
  target.dispatchEvent(event);
  return event;
}

function expectModalOpen(dom, open) {
  const { doc, modal } = modalParts(dom);
  expect(modal.hidden).toBe(!open);
  expect(modal.getAttribute('aria-hidden')).toBe(open ? 'false' : 'true');
  expect(doc.documentElement.classList.contains('ys-helcim-refund-modal-open')).toBe(open);
}

// 處理中：關閉鈕、背景、Escape 三種方式都不關，closeModal() 回 false，每一次都在狀態列顯示處理中提示。
function expectCloseRefused(dom, controller) {
  const { doc, dialog, close, backdrop } = modalParts(dom);
  const status = doc.querySelector('#ys-helcim-refund-status');
  [
    () => leftClick(dom, close),
    () => leftClick(dom, backdrop),
    () => pressKey(dom, dialog, 'Escape'),
  ].forEach((attemptClose) => {
    status.textContent = '';
    attemptClose();
    expectModalOpen(dom, true);
    expect(status.hidden).toBe(false);
    expect(status.className).toContain('notice-warning');
    expect(status.textContent).toBe(localizedMessages.refundModalBusy);
  });
  expect(controller.closeModal()).toBe(false);
  expectModalOpen(dom, true);
}

// WP_Scripts::localize() 真正印到頁面上的設定形狀：頂層純量都變成字串（true → "1"、false → ""、
// 1500 → "1500"），沒帶訂單編號時是 null，巢狀的 messages／labels 保留型別。值與 PHP refundAdminConfig() 相同。
function localizedConfig(overrides = {}) {
  return {
    restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
    restNonce: 'rest-nonce',
    adminPageUrl: 'https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds',
    initialOrderId: null,
    labels: { nativeRefund: 'Refund', helcimRefund: 'Helcim Refund', blocked: 'Helcim refund is blocked.' },
    pollIntervalMs: '1500',
    pollAttempts: '120',
    canResolve: '1',
    autoStart: '1',
    modalEnabled: '1',
    screen: 'spa',
    messages: localizedMessages,
    ...overrides,
  };
}

// 照真實頁面的順序啟動：先放全域設定與 fetch 替身，再執行腳本，讓腳本自己自動啟動（沒有可注入的相依）。
// setTimeout 替身記下腳本要求的等待毫秒數，實際以 0 ms 執行，輪詢不必真的等 1.5 秒。
function bootLocalizedPage(html, url, config, respond) {
  const dom = new JSDOM(html, { url, runScripts: 'outside-only' });
  const requests = [];
  const delays = [];
  dom.window.fetch = async (requestUrl, options) => {
    requests.push({ url: requestUrl, options });
    return respond(requestUrl, options);
  };
  const nativeSetTimeout = dom.window.setTimeout;
  dom.window.setTimeout = (callback, milliseconds, ...args) => {
    if (milliseconds > 0) {
      delays.push(milliseconds);
    }
    return nativeSetTimeout.call(dom.window, callback, 0, ...args);
  };
  dom.window.ysHelcimRefundAdminConfig = config;
  dom.window.eval(readFileSync(scriptPath, 'utf8'));
  return { dom, doc: dom.window.document, requests, delays };
}

// 退款作業讀回：processing 表示 Helcim 結果還沒定，indeterminate 表示結果不明、要人工判定。
function refundOperationRead(operationUuid, remoteStatus) {
  return {
    operation_uuid: operationUuid,
    effective_operation_uuid: operationUuid,
    provider_action: 'refund',
    provider_transaction_id: null,
    refund_transaction_id: null,
    remote_status: remoteStatus,
    local_status: 'pending',
    notification_status: 'pending',
    retry_allowed: false,
  };
}

function positiveInspection(operationUuid, candidate, challenge) {
  return {
    status: 'confirmation_required',
    operation_uuid: operationUuid,
    candidate_transaction_id: candidate,
    source_transaction_id: '81177061',
    candidate_type: 'refund',
    candidate_amount_cents: 2100,
    candidate_currency: 'USD',
    invoice_number: '3b0c6f2e-8d41-4a7e-9c55-1f2a3b4c5d6e',
    action: 'resolve_positive',
    parent_attestation_required: false,
    challenge,
    challenge_expires_at: '2026-10-05 08:05:00',
    confirmation_phrase: `RESOLVE ${operationUuid} WITH HELCIM ${candidate}`,
  };
}

function typeInto(dom, input, value) {
  input.value = value;
  input.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
}

async function settle(dom, ticks = 10) {
  for (let tick = 0; tick < ticks; tick += 1) {
    await nextTask(dom);
  }
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
    ['the localized non-admin flag ("")', '', {
      operation_uuid: '00000000-0000-4000-8000-000000000001',
      provider_action: 'refund',
    }],
    ['a canResolve string other than the localized "1"', 'true', {
      operation_uuid: '00000000-0000-4000-8000-000000000001',
      provider_action: 'refund',
    }],
    ['an invalid resolution operation under the localized "1"', '1', {
      operation_uuid: 'not-a-uuid',
      provider_action: 'decline',
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
    const candidate = '81177094';
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
          source_transaction_id: '81177061',
          candidate_type: 'refund',
          candidate_amount_cents: 2100,
          candidate_currency: 'USD',
          invoice_number: '3b0c6f2e-8d41-4a7e-9c55-1f2a3b4c5d6e',
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
      .toBe('81177061');
    expect(dom.window.document.querySelector('#ys-helcim-refund-resolution-action').textContent)
      .toBe('resolve_positive');
    // 安全審查 F1：attestation 前要看得到 Helcim 讀回的候選交易類型、金額與 invoiceNumber。
    expect(dom.window.document.querySelector('#ys-helcim-refund-resolution-candidate-type').textContent)
      .toBe('refund');
    expect(dom.window.document.querySelector('#ys-helcim-refund-resolution-candidate-amount').textContent)
      .toBe('21.00 USD');
    expect(dom.window.document.querySelector('#ys-helcim-refund-resolution-invoice').textContent)
      .toBe('3b0c6f2e-8d41-4a7e-9c55-1f2a3b4c5d6e');
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

  it.each([
    ['a missing candidate type', { candidate_type: undefined }],
    ['a candidate type that differs from the operation', { candidate_type: 'reverse' }],
    ['a string candidate amount', { candidate_amount_cents: '2100' }],
    ['a zero candidate amount', { candidate_amount_cents: 0 }],
    ['a fractional candidate amount', { candidate_amount_cents: 21.5 }],
    ['an unsupported candidate currency', { candidate_currency: 'EUR' }],
    ['a missing invoice number', { invoice_number: undefined }],
    ['an empty invoice number', { invoice_number: '' }],
    ['an untrimmed invoice number', { invoice_number: ' 3b0c6f2e ' }],
    ['an invoice number with a control character', { invoice_number: 'a\nb' }],
    ['an over-long invoice number', { invoice_number: 'a'.repeat(129) }],
  ])('rejects positive evidence with %s and keeps confirmation closed', async (label, override) => {
    void label;
    const { dom, api } = loadApi(canonicalHtml());
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const candidate = '81177094';
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async (url) => {
        if (url.endsWith('/refund-options')) {
          return jsonResponse({
            order_id: 42,
            classification: 'blocked',
            currency: 'USD',
            order_remaining: 2100,
            transactions: [],
            items: [],
            resolution_operation: { operation_uuid: operationUuid, provider_action: 'refund' },
          });
        }
        return jsonResponse({ ...positiveInspection(operationUuid, candidate, 'c'.repeat(64)), ...override });
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
    const doc = dom.window.document;
    doc.querySelector('#ys-helcim-refund-resolution-candidate').value = candidate;

    expect(await controller.inspectResolution()).toBeNull();

    expect(doc.querySelector('#ys-helcim-refund-resolution-evidence').hidden).toBe(true);
    expect(doc.querySelector('#ys-helcim-refund-resolution-confirmation').hidden).toBe(true);
    expect(doc.querySelector('#ys-helcim-refund-resolution-invoice').textContent).toBe('');
    expect(doc.querySelector('#ys-helcim-refund-resolution-commit').disabled).toBe(true);
    expect(doc.querySelector('#ys-helcim-refund-status').textContent)
      .toBe(localizedMessages.invalidPositiveEvidenceResponse);
  });

  // 安全審查 F1-j3：invoiceNumber 是 Helcim 讀回的資料，PHP 與 JS 的格式檢查都放行 <、>、=、(，
  // 所以畫面只能用 textContent 顯示；候選交易的類型、金額與幣別也一樣以純文字顯示。
  it('shows Helcim evidence as plain text, so markup in the invoice number never becomes elements', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const candidate = '81177094';
    const markup = '<img src=x onerror=alert(1)><b>x</b>';
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async (url) => {
        if (url.endsWith('/refund-options')) {
          return jsonResponse({
            order_id: 42,
            classification: 'blocked',
            currency: 'CAD',
            order_remaining: 123456,
            transactions: [],
            items: [],
            resolution_operation: { operation_uuid: operationUuid, provider_action: 'reverse' },
          });
        }
        return jsonResponse({
          ...positiveInspection(operationUuid, candidate, 'f'.repeat(64)),
          candidate_type: 'reverse',
          candidate_amount_cents: 123456,
          candidate_currency: 'CAD',
          invoice_number: markup,
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
    const doc = dom.window.document;
    doc.querySelector('#ys-helcim-refund-resolution-candidate').value = candidate;

    expect(await controller.inspectResolution()).not.toBeNull();

    // 格式檢查放行這個 invoice：證據區與確認區都打開，內容原樣以文字顯示。
    const evidence = doc.querySelector('#ys-helcim-refund-resolution-evidence');
    expect(evidence.hidden).toBe(false);
    expect(doc.querySelector('#ys-helcim-refund-resolution-confirmation').hidden).toBe(false);
    [
      ['#ys-helcim-refund-resolution-invoice', markup],
      ['#ys-helcim-refund-resolution-candidate-type', 'reverse'],
      ['#ys-helcim-refund-resolution-candidate-amount', '1234.56 CAD'],
    ].forEach(([selector, text]) => {
      const detail = doc.querySelector(selector);
      expect(detail.textContent).toBe(text);
      expect(detail.children).toHaveLength(0);
      expect(detail.childNodes).toHaveLength(1);
      expect(detail.firstChild.nodeType).toBe(dom.window.Node.TEXT_NODE);
    });
    evidence.querySelectorAll('dd').forEach((detail) => {
      expect(detail.children).toHaveLength(0);
    });
    expect(doc.querySelector('img')).toBeNull();
    expect(doc.querySelector('#ys-helcim-refund-resolution-evidence b')).toBeNull();
  });

  it('commits only inspected positive evidence and then polls the canonical operation', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const candidate = '81177094';
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
            source_transaction_id: '81177061',
            candidate_type: 'refund',
            candidate_amount_cents: 2100,
            candidate_currency: 'USD',
            invoice_number: '3b0c6f2e-8d41-4a7e-9c55-1f2a3b4c5d6e',
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
        provider_transaction_id: '81177094',
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
          provider_transaction_id: '81177094',
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
        provider_transaction_id: '81177094',
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
        provider_transaction_id: '81177094',
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
        provider_transaction_id: '81177094',
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

  it('explains a same-day full refund as a cancelled (voided) payment', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const rootUuid = '00000000-0000-4000-8000-000000000001';
    const reverseUuid = '00000000-0000-4000-8000-000000000002';
    const fetch = async (url, options) => {
      if (url.endsWith('/refund-options')) {
        return jsonResponse({
          order_id: 42,
          classification: 'helcim_only',
          currency: 'USD',
          order_remaining: 1500,
          transactions: [
            { id: 7, gateway: 'ys_helcim_js', payment_mode: 'live', remaining_refundable: 1500 },
          ],
          items: [],
        });
      }
      expect(options.method).toBe('POST');
      return jsonResponse({
        operation_uuid: rootUuid,
        effective_operation_uuid: reverseUuid,
        provider_action: 'reverse',
        provider_transaction_id: '85267563',
        refund_transaction_id: 90,
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
      uuid: () => rootUuid,
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
      },
    });
    await controller.start();

    await controller.submitRefund();

    expect(dom.window.document.querySelector('#ys-helcim-refund-status').textContent)
      .toBe(localizedMessages.paymentVoided);
    expect(dom.window.document.querySelector('#ys-helcim-refund-submit').disabled).toBe(true);
  });

  it('explains why an unsettled payment cannot be partially refunded yet', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const fetch = async (url, options) => {
      if (url.endsWith('/refund-options')) {
        return jsonResponse({
          order_id: 42,
          classification: 'helcim_only',
          currency: 'USD',
          order_remaining: 1500,
          transactions: [
            { id: 7, gateway: 'ys_helcim_js', payment_mode: 'live', remaining_refundable: 1500 },
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
        remote_status: 'failed',
        local_status: 'pending',
        notification_status: 'pending',
        retry_allowed: true,
        error_code: 'open_batch_partial_refund_unsupported',
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

    expect(dom.window.document.querySelector('#ys-helcim-refund-status').textContent)
      .toBe(localizedMessages.openBatchPartialRefund);
    expect(dom.window.document.querySelector('#ys-helcim-refund-submit').disabled).toBe(false);
  });

  it('explains a refund rejected on a settled payment and points to the Helcim sync', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async (url) => {
        if (url.endsWith('/refund-options')) {
          return jsonResponse({
            order_id: 42,
            classification: 'helcim_only',
            currency: 'USD',
            order_remaining: 1500,
            transactions: [
              { id: 7, gateway: 'ys_helcim', payment_mode: 'live', remaining_refundable: 1500 },
            ],
            items: [],
          });
        }
        return jsonResponse({
          operation_uuid: operationUuid,
          effective_operation_uuid: operationUuid,
          provider_action: 'refund',
          provider_transaction_id: null,
          refund_transaction_id: null,
          remote_status: 'failed',
          local_status: 'pending',
          notification_status: 'pending',
          retry_allowed: true,
          error_code: 'batch_closed_refund_rejected',
        }, 422);
      },
      uuid: () => operationUuid,
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
      },
    });
    await controller.start();

    await controller.submitRefund();

    expect(dom.window.document.querySelector('#ys-helcim-refund-status').textContent)
      .toBe(localizedMessages.batchClosedRefundRejected);
    expect(dom.window.document.querySelector('#ys-helcim-refund-status').textContent)
      .not.toBe(localizedMessages.openBatchPartialRefund);
  });

  it('keeps the plain-language failure when a failed operation is read back by reconciliation', async () => {
    // POST 回應遺失 → 店家按 Reconcile：GET 讀回的失敗列要帶 error_code，才不會退回泛用「未完成」文案。
    const { dom, api } = loadApi(canonicalHtml());
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const requests = [];
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async (url, options) => {
        requests.push([options.method, url]);
        if (url.endsWith('/refund-options')) {
          return jsonResponse({
            order_id: 42,
            classification: 'helcim_only',
            currency: 'USD',
            order_remaining: 1500,
            transactions: [
              { id: 7, gateway: 'ys_helcim', payment_mode: 'live', remaining_refundable: 1500 },
            ],
            items: [],
          });
        }
        if (options.method === 'POST') {
          throw new Error('The network connection was lost.');
        }
        return jsonResponse({
          operation_uuid: operationUuid,
          effective_operation_uuid: operationUuid,
          provider_action: 'refund',
          provider_transaction_id: null,
          refund_transaction_id: null,
          remote_status: 'failed',
          local_status: 'pending',
          notification_status: 'pending',
          retry_allowed: true,
          error_code: 'open_batch_partial_refund_unsupported',
        });
      },
      uuid: () => operationUuid,
      sleep: async () => {},
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

    await controller.reconcile();

    expect(requests.at(-1)).toEqual(['GET', `https://shop.test/wp-json/ys-fc-pay/v1/refund-operations/${operationUuid}`]);
    expect(dom.window.document.querySelector('#ys-helcim-refund-status').textContent)
      .toBe(localizedMessages.openBatchPartialRefund);
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
    const pendingNotice = dom.window.document.querySelector('[data-ys-helcim-refund-notice]');
    expect(pendingNotice.textContent).toBe(localizedMessages.classificationPending);
    expect(pendingNotice.textContent).not.toContain('Request failed.');

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

  it('takes over after an Orders-list click that only uses history.pushState (no hashchange)', async () => {
    const { dom, api } = loadApi(
      spaListHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders',
    );
    const requests = [];
    const navigations = [];
    let hashchanges = 0;
    dom.window.addEventListener('hashchange', () => {
      hashchanges += 1;
    });
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      navigate: (url) => navigations.push(url),
      fetch: async (url) => {
        requests.push(url);
        return jsonResponse(helcimOnlyOptions(42));
      },
      config: spaConfig(),
    });

    await controller.start();
    expect(requests).toEqual([]);

    // FluentCart 1.6.x：列表點進訂單＝pushState 換 hash＋Vue 重繪，不會發 hashchange。
    dom.window.history.pushState(null, '', '#/orders/42/view');
    dom.window.document.querySelector('#fluent_cart_plugin_app').innerHTML = spaOrderMarkup();
    await nextTask(dom);
    await controller.whenIdle();
    await nextTask(dom);

    expect(hashchanges).toBe(0);
    expect(requests).toEqual(['https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options']);
    const native = dom.window.document.querySelector('button.bulk-action-hide-only-mobile');
    expect(native.textContent).toBe('Refund');
    expect(native.hidden).toBe(true);
    expect(native.getAttribute('data-ys-helcim-native-refund-hidden')).toBe('true');
    const link = dom.window.document.querySelector('[data-ys-helcim-refund-order="42"]');
    expect(link).not.toBeNull();
    expect(link.getAttribute('href'))
      .toBe('https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds&order_id=42');

    const click = new dom.window.MouseEvent('click', { bubbles: true, cancelable: true });
    native.dispatchEvent(click);
    expect(click.defaultPrevented).toBe(true);
    expect(navigations).toEqual([
      'https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds&order_id=42',
    ]);
  });

  it('removes the takeover and restores the native action after leaving the order route', async () => {
    const { dom, api } = loadApi(
      spaHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const requests = [];
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      navigate: () => {
        throw new Error('Leaving the order route must not navigate.');
      },
      fetch: async (url) => {
        requests.push(url);
        return jsonResponse(helcimOnlyOptions(42));
      },
      config: spaConfig(),
    });
    await controller.start();
    const native = dom.window.document.querySelector('button.bulk-action-hide-only-mobile');
    expect(native.hidden).toBe(true);
    expect(dom.window.document.querySelector('[data-ys-helcim-refund-order="42"]')).not.toBeNull();

    dom.window.history.pushState(null, '', '#/orders');
    const listing = dom.window.document.createElement('div');
    listing.className = 'fct-orders-list';
    dom.window.document.querySelector('#fluent_cart_plugin_app').appendChild(listing);
    await nextTask(dom);
    await controller.whenIdle();

    expect(requests).toHaveLength(1);
    expect(dom.window.document.querySelector('[data-ys-helcim-refund-order]')).toBeNull();
    expect(dom.window.document.querySelector('[data-ys-helcim-refund-enhancement]')).toBeNull();
    expect(native.hidden).toBe(false);
    expect(native.hasAttribute('data-ys-helcim-native-refund-hidden')).toBe(false);
    expect(native.hasAttribute('aria-hidden')).toBe(false);
    const click = new dom.window.MouseEvent('click', { bubbles: true, cancelable: true });
    native.dispatchEvent(click);
    expect(click.defaultPrevented).toBe(false);
  });

  it('shows a pending notice, not a failure, while classification is unresolved and never opens the native dialog', async () => {
    const { dom, api } = loadApi(
      spaHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const requests = [];
    const navigations = [];
    let resolveOrder42;
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      navigate: (url) => navigations.push(url),
      fetch: (url) => {
        requests.push(url);
        return new Promise((resolve) => {
          resolveOrder42 = resolve;
        });
      },
      config: spaConfig(),
    });

    const startup = controller.start();
    const native = dom.window.document.querySelector('button.bulk-action-hide-only-mobile');
    const nativeDialogs = [];
    native.addEventListener('click', () => nativeDialogs.push('fluentcart-refund-dialog'));
    const click = new dom.window.MouseEvent('click', { bubbles: true, cancelable: true });
    native.dispatchEvent(click);

    expect(click.defaultPrevented).toBe(true);
    expect(nativeDialogs).toEqual([]);
    expect(navigations).toEqual([]);
    const notice = dom.window.document.querySelector('[data-ys-helcim-refund-notice]');
    expect(notice.getAttribute('data-ys-helcim-refund-notice')).toBe('pending');
    expect(notice.textContent).toBe(localizedMessages.classificationPending);
    expect(notice.textContent).not.toContain(localizedMessages.requestFailed);
    expect(notice.querySelector('[data-ys-helcim-refund-retry]')).toBeNull();
    // 已有分類查詢進行中，不得再送一次。
    expect(requests).toHaveLength(1);

    resolveOrder42(jsonResponse(helcimOnlyOptions(42)));
    await startup;

    expect(dom.window.document.querySelector('[data-ys-helcim-refund-notice]')).toBeNull();
    expect(native.hidden).toBe(true);
    expect(dom.window.document.querySelector('[data-ys-helcim-refund-order="42"]')).not.toBeNull();
    expect(nativeDialogs).toEqual([]);
  });

  it('re-runs the classification from an unresolved click when no lookup is in flight', async () => {
    const { dom, api } = loadApi(
      spaHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const requests = [];
    let resolveOrder42;
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      navigate: () => {
        throw new Error('An unresolved click must not navigate.');
      },
      fetch: (url) => {
        requests.push(url);
        if (url.includes('/orders/42/')) {
          return new Promise((resolve) => {
            resolveOrder42 = resolve;
          });
        }
        return Promise.resolve(jsonResponse(helcimOnlyOptions(43)));
      },
      config: spaConfig(),
    });

    const startup = controller.start();
    // 路由已換到 43，但畫面還沒重繪：沒有 DOM 變動、也沒有 hashchange。
    dom.window.history.pushState(null, '', '#/orders/43/view');
    resolveOrder42(jsonResponse(helcimOnlyOptions(42)));
    await startup;
    expect(requests).toHaveLength(1);

    const native = dom.window.document.querySelector('button.bulk-action-hide-only-mobile');
    const click = new dom.window.MouseEvent('click', { bubbles: true, cancelable: true });
    native.dispatchEvent(click);
    expect(click.defaultPrevented).toBe(true);
    expect(dom.window.document.querySelector('[data-ys-helcim-refund-notice]').textContent)
      .toBe(localizedMessages.classificationPending);

    await controller.whenIdle();
    expect(requests).toEqual([
      'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options',
      'https://shop.test/wp-json/ys-fc-pay/v1/orders/43/refund-options',
    ]);
    expect(dom.window.document.querySelector('[data-ys-helcim-refund-order="43"]')).not.toBeNull();
    expect(dom.window.document.querySelector('[data-ys-helcim-refund-notice]')).toBeNull();
    expect(native.hidden).toBe(true);
  });

  it('re-classifies on popstate (browser back or forward) even without a hashchange', async () => {
    const { dom, api } = loadApi(
      spaHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const requests = [];
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async (url) => {
        requests.push(url);
        if (url.includes('/orders/42/')) {
          return jsonResponse(helcimOnlyOptions(42));
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
      config: spaConfig(),
    });
    await controller.start();
    const native = dom.window.document.querySelector('button.bulk-action-hide-only-mobile');
    expect(native.hidden).toBe(true);

    dom.window.history.pushState(null, '', '#/orders/43/view');
    dom.window.dispatchEvent(new dom.window.PopStateEvent('popstate'));
    await controller.whenIdle();

    expect(requests).toEqual([
      'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options',
      'https://shop.test/wp-json/ys-fc-pay/v1/orders/43/refund-options',
    ]);
    expect(native.hidden).toBe(false);
    expect(dom.window.document.querySelector('[data-ys-helcim-refund-order]')).toBeNull();
  });

  it('fetches the classification once per browser back or forward although popstate and hashchange both fire', async () => {
    const { dom, api } = loadApi(
      spaHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const requests = [];
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async (url) => {
        requests.push(url);
        if (url.includes('/orders/42/')) {
          return jsonResponse(helcimOnlyOptions(42));
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
      config: spaConfig(),
    });
    await controller.start();
    const native = dom.window.document.querySelector('button.bulk-action-hide-only-mobile');
    expect(native.hidden).toBe(true);
    // 在控制器之後登記：確認兩個事件真的都發生，且順序是 popstate → hashchange。
    const events = [];
    dom.window.addEventListener('popstate', () => events.push('popstate'));
    dom.window.addEventListener('hashchange', () => events.push('hashchange'));

    // 同一次導覽同時派發 popstate 與 hashchange（真實瀏覽器上一頁／下一頁的順序）。
    dom.window.history.pushState(null, '', '#/orders/43/view');
    dom.window.dispatchEvent(new dom.window.PopStateEvent('popstate'));
    dom.window.dispatchEvent(new dom.window.HashChangeEvent('hashchange'));
    await controller.whenIdle();
    await nextTask(dom);

    expect(events).toEqual(['popstate', 'hashchange']);
    expect(requests).toEqual([
      'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options',
      'https://shop.test/wp-json/ys-fc-pay/v1/orders/43/refund-options',
    ]);
    expect(native.hidden).toBe(false);

    // jsdom 真的走一次歷史紀錄：history.back() 由瀏覽器引擎自己派發 popstate 與 hashchange。
    events.length = 0;
    dom.window.history.back();
    for (let attempt = 0; attempt < 20 && events.length < 2; attempt += 1) {
      await nextTask(dom);
    }
    await controller.whenIdle();
    await nextTask(dom);

    expect(events).toEqual(['popstate', 'hashchange']);
    expect(dom.window.location.hash).toBe('#/orders/42/view');
    expect(requests).toEqual([
      'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options',
      'https://shop.test/wp-json/ys-fc-pay/v1/orders/43/refund-options',
      'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options',
    ]);
    expect(native.hidden).toBe(true);
    expect(dom.window.document.querySelector('[data-ys-helcim-refund-order="42"]')).not.toBeNull();
  });

  it('ships an !important rule that really hides the taken-over native Refund button', () => {
    const source = readFileSync(stylePath, 'utf8');

    // .el-button 設了 display，單靠 hidden 屬性蓋不掉（1.1.2 前兩顆退款鈕會同時出現）。
    expect(source).toMatch(
      /\[data-ys-helcim-native-refund-hidden="true"\]\s*\{\s*display:\s*none\s*!important;\s*\}/,
    );
  });

  function syncController(dom, api, syncResponse, requests) {
    return api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async (url, options) => {
        requests.push({ url, method: options.method, headers: options.headers, body: options.body });
        if (url.endsWith('/sync-provider-refunds')) {
          return syncResponse;
        }
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
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
      },
    });
  }

  it('syncs Helcim-side refunds with an exact POST and reloads the order afterwards', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const requests = [];
    const controller = syncController(dom, api, jsonResponse({
      order_id: 42,
      status: 'recorded',
      helcim_payments: 1,
      recorded: [{ transaction_id: '85267563', provider_action: 'reverse', reason: 'recorded' }],
      already_recorded: [],
      skipped: [],
      retry: [],
      review: [],
    }), requests);

    await controller.start();
    const section = dom.window.document.querySelector('#ys-helcim-refund-sync');
    const button = dom.window.document.querySelector('#ys-helcim-refund-sync-button');
    expect(section.hidden).toBe(false);
    expect(button.disabled).toBe(false);

    button.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true, cancelable: true }));
    await controller.whenIdle();

    expect(requests.map((request) => [request.method, request.url])).toEqual([
      ['GET', 'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options'],
      ['POST', 'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/sync-provider-refunds'],
      ['GET', 'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options'],
    ]);
    expect(requests[1].headers).toEqual({ 'Content-Type': 'application/json', 'X-WP-Nonce': 'rest-nonce' });
    expect(requests[1].body).toBe('{}');
    const status = dom.window.document.querySelector('#ys-helcim-refund-status');
    expect(status.textContent).toBe('Recorded refunds or voids from Helcim: 1. FluentCart now matches Helcim.');
    expect(status.className).toContain('notice-success');
    expect(section.hidden).toBe(false);
    expect(button.disabled).toBe(false);
  });

  it.each([
    [
      'needs_review',
      1,
      [{ transaction_id: '1', provider_action: 'refund', reason: 'amount_exceeds_remaining' }, { transaction_id: '2', provider_action: 'reverse', reason: 'reverse_not_full' }, { transaction_id: '3', provider_action: 'refund', reason: '<b>x</b>' }],
      'Some refunds in Helcim could not be recorded automatically (amount_exceeds_remaining, reverse_not_full). Compare this order with Helcim before refunding again.',
      'notice-error',
    ],
    ['retry_later', 1, [], 'Another refund for this order is still in progress, or Helcim could not be reached. Try again in a few minutes.', 'notice-warning'],
    ['nothing_new', 1, [], 'Helcim has no refunds or voids for this order that are missing from FluentCart.', 'notice-info'],
    ['nothing_new', 0, [], 'This order has no completed Helcim payment to check.', 'notice-info'],
  ])('explains a %s sync result (Helcim payments: %s)', async (syncStatus, payments, review, expected, kind) => {
    const { dom, api } = loadApi(canonicalHtml());
    const requests = [];
    const controller = syncController(dom, api, jsonResponse({
      order_id: 42,
      status: syncStatus,
      helcim_payments: payments,
      recorded: [],
      already_recorded: [],
      skipped: [],
      retry: [],
      review,
    }), requests);
    await controller.start();

    await controller.syncProviderRefunds();

    const status = dom.window.document.querySelector('#ys-helcim-refund-status');
    expect(status.textContent).toBe(expected);
    expect(status.className).toContain(kind);
    expect(requests).toHaveLength(3);
  });

  it('reports a failed sync without reloading and lets the operator try again', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const requests = [];
    const controller = syncController(
      dom,
      api,
      jsonResponse({ error_code: 'ys_helcim_provider_refund_sync_unavailable', message: 'Refunds could not be synced from Helcim.' }, 503),
      requests,
    );
    await controller.start();

    await controller.syncProviderRefunds();

    expect(requests.map((request) => request.method)).toEqual(['GET', 'POST']);
    const status = dom.window.document.querySelector('#ys-helcim-refund-status');
    expect(status.textContent).toBe('Refunds could not be synced from Helcim.');
    expect(status.className).toContain('notice-error');
    expect(dom.window.document.querySelector('#ys-helcim-refund-sync-button').disabled).toBe(false);
  });

  it('rejects a sync summary that belongs to another order', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const requests = [];
    const controller = syncController(dom, api, jsonResponse({ order_id: 43, status: 'recorded', recorded: [{}] }), requests);
    await controller.start();

    await controller.syncProviderRefunds();

    expect(requests.map((request) => request.method)).toEqual(['GET', 'POST']);
    expect(dom.window.document.querySelector('#ys-helcim-refund-status').textContent)
      .toBe('Refunds could not be synced from Helcim.');
  });

  // 安全審查 F3：可控制回應時機的退款＋同步控制器（送出、同步各自用 deferred 決定何時回來）。
  function interleavingController(dom, api) {
    const requests = [];
    const pending = {};
    let remaining = 2100;
    const deferred = function (name) {
      return new Promise((resolve) => {
        pending[name] = resolve;
      });
    };
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      uuid: () => '00000000-0000-4000-8000-000000000001',
      sleep: async () => {},
      fetch: async (url, options) => {
        requests.push({ url, method: options.method });
        if (url.endsWith('/refund-options')) {
          return jsonResponse({
            order_id: 42,
            classification: 'helcim_only',
            currency: 'USD',
            order_remaining: remaining,
            transactions: [
              { id: 7, gateway: 'ys_helcim', payment_mode: 'test', remaining_refundable: remaining },
            ],
            items: [],
          });
        }
        if (url.endsWith('/refunds')) {
          return deferred('refund');
        }
        if (url.endsWith('/sync-provider-refunds')) {
          return deferred('sync');
        }
        throw new Error('unexpected request ' + url);
      },
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
      },
    });
    return {
      controller,
      requests,
      release(name, response) {
        pending[name](response);
      },
      setRemaining(value) {
        remaining = value;
      },
    };
  }

  function syncSummary(status) {
    return jsonResponse({
      order_id: 42,
      status,
      helcim_payments: 1,
      recorded: [],
      already_recorded: [],
      skipped: [],
      retry: [],
      review: [],
    });
  }

  it('keeps the Helcim sync unavailable while a refund is pending, so a late sync cannot reopen submission', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const { controller, requests, release, setRemaining } = interleavingController(dom, api);
    await controller.start();
    const doc = dom.window.document;
    const submit = doc.querySelector('#ys-helcim-refund-submit');
    const syncButton = doc.querySelector('#ys-helcim-refund-sync-button');
    const status = doc.querySelector('#ys-helcim-refund-status');
    doc.querySelector('#ys-helcim-refund-amount').value = '10.00';
    expect(syncButton.disabled).toBe(false);

    const pendingRefund = controller.submitRefund();
    await settle(dom);
    expect(submit.disabled).toBe(true);
    expect(syncButton.disabled).toBe(true);

    // 按鈕被停用也擋不住程式呼叫：同步本身也要拒絕，不送 POST。
    expect(await controller.syncProviderRefunds()).toBeNull();
    syncButton.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true, cancelable: true }));
    await settle(dom);
    expect(requests.some((request) => request.url.endsWith('/sync-provider-refunds'))).toBe(false);

    setRemaining(1100);
    release('refund', jsonResponse(appliedRefund('00000000-0000-4000-8000-000000000001')));
    await pendingRefund;
    await settle(dom);

    expect(submit.disabled).toBe(true);
    expect(status.textContent).toBe(localizedMessages.refundCompleted);
    expect(syncButton.disabled).toBe(false);
  });

  it('keeps submission locked while a Helcim sync is pending and refuses a programmatic submit', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const { controller, requests, release } = interleavingController(dom, api);
    await controller.start();
    const doc = dom.window.document;
    const submit = doc.querySelector('#ys-helcim-refund-submit');
    expect(submit.disabled).toBe(false);

    const pendingSync = controller.syncProviderRefunds();
    await settle(dom);
    expect(submit.disabled).toBe(true);
    expect(await controller.submitRefund()).toBeNull();
    expect(requests.some((request) => request.url.endsWith('/refunds'))).toBe(false);

    release('sync', syncSummary('nothing_new'));
    await pendingSync;

    // 同步的重新載入拿到最新選項：沒有退款記入時照常可以送出。
    expect(submit.disabled).toBe(false);
    expect(doc.querySelector('#ys-helcim-refund-sync-button').disabled).toBe(false);
  });

  it('restores submission when a Helcim sync fails without reloading the order', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const { controller, requests, release } = interleavingController(dom, api);
    await controller.start();
    const doc = dom.window.document;
    const submit = doc.querySelector('#ys-helcim-refund-submit');

    const pendingSync = controller.syncProviderRefunds();
    await settle(dom);
    expect(submit.disabled).toBe(true);
    release('sync', jsonResponse({ error_code: 'ys_helcim_provider_refund_sync_unavailable' }, 503));
    await pendingSync;

    expect(requests.map((request) => request.method)).toEqual(['GET', 'POST']);
    expect(submit.disabled).toBe(false);
    expect(doc.querySelector('#ys-helcim-refund-sync-button').disabled).toBe(false);
  });

  it('keeps submission locked after an applied refund when a later sync reloads the options, until the order is reloaded', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const { controller, requests, release, setRemaining } = interleavingController(dom, api);
    await controller.start();
    const doc = dom.window.document;
    const submit = doc.querySelector('#ys-helcim-refund-submit');
    const amount = doc.querySelector('#ys-helcim-refund-amount');
    amount.value = '10.00';

    const pendingRefund = controller.submitRefund();
    await settle(dom);
    setRemaining(1100);
    release('refund', jsonResponse(appliedRefund('00000000-0000-4000-8000-000000000001')));
    await pendingRefund;
    expect(submit.disabled).toBe(true);

    const pendingSync = controller.syncProviderRefunds();
    await settle(dom);
    release('sync', syncSummary('nothing_new'));
    await pendingSync;

    // 同步的重新載入有讀到最新可退金額，但不是操作者明確重新載入：送出鈕維持停用。
    expect(requests.filter((request) => request.url.endsWith('/refund-options'))).toHaveLength(2);
    expect(amount.value).toBe('11.00');
    expect(submit.disabled).toBe(true);
    expect(await controller.submitRefund()).toBeNull();
    expect(requests.filter((request) => request.url.endsWith('/refunds'))).toHaveLength(1);

    await controller.loadOptions(42);
    expect(submit.disabled).toBe(false);
  });

  it('disables positive resolution commit while a Helcim sync is pending', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const candidate = '81177094';
    const challenge = 'e'.repeat(64);
    let releaseSync = null;
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async (url) => {
        if (url.endsWith('/refund-options')) {
          return jsonResponse({
            order_id: 42,
            classification: 'blocked',
            currency: 'USD',
            order_remaining: 2100,
            transactions: [],
            items: [],
            resolution_operation: { operation_uuid: operationUuid, provider_action: 'refund' },
          });
        }
        if (url.endsWith('/inspect')) {
          return jsonResponse(positiveInspection(operationUuid, candidate, challenge));
        }
        if (url.endsWith('/sync-provider-refunds')) {
          return new Promise((resolve) => {
            releaseSync = resolve;
          });
        }
        throw new Error('unexpected request ' + url);
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
    const doc = dom.window.document;
    doc.querySelector('#ys-helcim-refund-resolution-candidate').value = candidate;
    await controller.inspectResolution();
    typeInto(dom, doc.querySelector('#ys-helcim-refund-resolution-typed-phrase'), `RESOLVE ${operationUuid} WITH HELCIM ${candidate}`);
    const commit = doc.querySelector('#ys-helcim-refund-resolution-commit');
    expect(commit.disabled).toBe(false);

    const pendingSync = controller.syncProviderRefunds();
    await settle(dom);
    expect(commit.disabled).toBe(true);
    expect(await controller.commitResolution()).toBeNull();

    releaseSync(syncSummary('nothing_new'));
    await pendingSync;
    expect(doc.querySelector('#ys-helcim-refund-sync-button').disabled).toBe(false);
    expect(commit.disabled).toBe(false);
  });

  // 安全審查 F3-5：Reconcile 的輪詢也是寫帳流程。送出的回應遺失後按 Reconcile，讀回作業期間不可同步；
  // 送出流程本身已經結束，這段停用只來自 Reconcile 的輪詢。
  it('keeps the Helcim sync unavailable while Reconcile is reading the refund operation', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const requests = [];
    let releaseOperation = null;
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      uuid: () => operationUuid,
      sleep: async () => {},
      fetch: async (url, options) => {
        requests.push({ url, method: options.method });
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
        if (url.endsWith('/refunds')) {
          // 送出的回應遺失（斷線）：結果不明，畫面改出 Reconcile。
          throw new TypeError('Failed to fetch');
        }
        if (url.endsWith('/refund-operations/' + operationUuid)) {
          return new Promise((resolve) => {
            releaseOperation = resolve;
          });
        }
        if (url.endsWith('/sync-provider-refunds')) {
          return syncSummary('nothing_new');
        }
        throw new Error('unexpected request ' + url);
      },
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
      },
    });
    await controller.start();
    const doc = dom.window.document;
    const syncButton = doc.querySelector('#ys-helcim-refund-sync-button');
    const reconcile = doc.querySelector('#ys-helcim-refund-reconcile');
    doc.querySelector('#ys-helcim-refund-amount').value = '5.00';

    await controller.submitRefund();
    expect(reconcile.hidden).toBe(false);
    expect(syncButton.disabled).toBe(false);

    reconcile.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true, cancelable: true }));
    const pendingReconcile = controller.whenIdle();
    await settle(dom);
    expect(requests.at(-1)).toEqual({
      url: `https://shop.test/wp-json/ys-fc-pay/v1/refund-operations/${operationUuid}`,
      method: 'GET',
    });
    expect(syncButton.disabled).toBe(true);
    expect(await controller.syncProviderRefunds()).toBeNull();
    syncButton.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true, cancelable: true }));
    await settle(dom);
    expect(requests.some((request) => request.url.endsWith('/sync-provider-refunds'))).toBe(false);

    releaseOperation(jsonResponse(appliedRefund(operationUuid)));
    await pendingReconcile;
    expect(doc.querySelector('#ys-helcim-refund-status').textContent).toBe(localizedMessages.refundCompleted);
    expect(syncButton.disabled).toBe(false);
  });

  // 安全審查 F3-6：resolution commit 的 POST 還沒回來之前（輪詢開始之前），同步鈕就要停用。
  it('keeps the Helcim sync unavailable while a positive resolution commit request is pending', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const candidate = '81177094';
    const challenge = 'd'.repeat(64);
    const requests = [];
    let releaseCommit = null;
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      sleep: async () => {},
      fetch: async (url, options) => {
        requests.push({ url, method: options.method });
        if (url.endsWith('/refund-options')) {
          return jsonResponse({
            order_id: 42,
            classification: 'blocked',
            currency: 'USD',
            order_remaining: 2100,
            transactions: [],
            items: [],
            resolution_operation: { operation_uuid: operationUuid, provider_action: 'refund' },
          });
        }
        if (url.endsWith('/inspect')) {
          return jsonResponse(positiveInspection(operationUuid, candidate, challenge));
        }
        if (url.endsWith('/commit')) {
          return new Promise((resolve) => {
            releaseCommit = resolve;
          });
        }
        if (url.endsWith('/refund-operations/' + operationUuid)) {
          return jsonResponse(appliedRefund(operationUuid));
        }
        if (url.endsWith('/sync-provider-refunds')) {
          return syncSummary('nothing_new');
        }
        throw new Error('unexpected request ' + url);
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
    const doc = dom.window.document;
    const syncButton = doc.querySelector('#ys-helcim-refund-sync-button');
    doc.querySelector('#ys-helcim-refund-resolution-candidate').value = candidate;
    await controller.inspectResolution();
    typeInto(dom, doc.querySelector('#ys-helcim-refund-resolution-typed-phrase'), `RESOLVE ${operationUuid} WITH HELCIM ${candidate}`);
    expect(doc.querySelector('#ys-helcim-refund-resolution-commit').disabled).toBe(false);
    expect(syncButton.disabled).toBe(false);

    const pendingCommit = controller.commitResolution();
    await settle(dom);
    expect(requests.at(-1)).toEqual({
      url: `https://shop.test/wp-json/ys-fc-pay/v1/refund-resolutions/${operationUuid}/commit`,
      method: 'POST',
    });
    expect(syncButton.disabled).toBe(true);
    expect(await controller.syncProviderRefunds()).toBeNull();
    syncButton.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true, cancelable: true }));
    await settle(dom);
    expect(requests.some((request) => request.url.endsWith('/sync-provider-refunds'))).toBe(false);

    releaseCommit(jsonResponse({
      status: 'resolved',
      operation_uuid: operationUuid,
      remote_status: 'succeeded',
      replayed: false,
      local_recording_status: 'continued',
      local_status: 'recorded',
    }, 202));
    await pendingCommit;
    expect(requests.filter((request) => request.url.endsWith('/refund-operations/' + operationUuid))).toHaveLength(1);
    expect(doc.querySelector('#ys-helcim-refund-status').textContent).toBe(localizedMessages.refundCompleted);
    expect(syncButton.disabled).toBe(false);
  });

  // 安全審查 F3-9：同步進行中，操作者用查詢表單明確重新載入訂單，送出鈕會依最新選項重新打開；
  // 這時只剩 syncBusy 擋住送出，按送出也不可發出退款 POST。
  it('refuses a refund submitted after an explicit order reload while a Helcim sync is still pending', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const { controller, requests, release } = interleavingController(dom, api);
    await controller.start();
    const doc = dom.window.document;
    const submit = doc.querySelector('#ys-helcim-refund-submit');
    const refundPosts = () => requests.filter((request) => request.url.endsWith('/refunds'));
    expect(submit.disabled).toBe(false);

    const pendingSync = controller.syncProviderRefunds();
    await settle(dom);
    expect(submit.disabled).toBe(true);

    doc.querySelector('#ys-helcim-refund-order-id').value = '42';
    doc.querySelector('#ys-helcim-refund-order-lookup').dispatchEvent(
      new dom.window.Event('submit', { bubbles: true, cancelable: true }),
    );
    await settle(dom);
    expect(requests.filter((request) => request.url.endsWith('/refund-options'))).toHaveLength(2);
    expect(submit.disabled).toBe(false);

    doc.querySelector('#ys-helcim-refund-form').dispatchEvent(
      new dom.window.Event('submit', { bubbles: true, cancelable: true }),
    );
    await settle(dom);
    expect(await controller.submitRefund()).toBeNull();
    expect(refundPosts()).toHaveLength(0);

    release('sync', syncSummary('nothing_new'));
    await pendingSync;
    expect(submit.disabled).toBe(false);

    // 同步結束後才送得出去：前面被擋下確實是同步守門，不是其他條件。
    const pendingRefund = controller.submitRefund();
    await settle(dom);
    expect(refundPosts()).toHaveLength(1);
    release('refund', jsonResponse(appliedRefund('00000000-0000-4000-8000-000000000001')));
    await pendingRefund;
    expect(doc.querySelector('#ys-helcim-refund-status').textContent).toBe(localizedMessages.refundCompleted);
  });

  it('explains a block caused by a Helcim-side refund that is not recorded yet and keeps the sync action available', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async () => jsonResponse({
        order_id: 42,
        classification: 'blocked',
        blocker: 'provider_refund_pending',
        currency: 'USD',
        order_remaining: 2100,
        transactions: [
          { id: 7, gateway: 'ys_helcim', payment_mode: 'test', remaining_refundable: 2100 },
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

    expect(dom.window.document.querySelector('#ys-helcim-refund-status').textContent)
      .toBe(localizedMessages.providerRefundPending);
    expect(dom.window.document.querySelector('#ys-helcim-refund-sync').hidden).toBe(false);
    expect(dom.window.document.querySelector('#ys-helcim-refund-sync-button').disabled).toBe(false);

    const unknown = loadApi(canonicalHtml());
    const plain = unknown.api.createController({
      window: unknown.dom.window,
      document: unknown.dom.window.document,
      fetch: async () => jsonResponse({
        order_id: 42,
        classification: 'blocked',
        blocker: 'something_else',
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
    await plain.start();
    expect(unknown.dom.window.document.querySelector('#ys-helcim-refund-status').textContent)
      .toBe(localizedMessages.refundBlocked);
  });

  it('keeps the sync action hidden until an order loads successfully', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const requests = [];
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async (url, options) => {
        requests.push({ url, method: options.method });
        return jsonResponse({ message: 'Order not found.' }, 404);
      },
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
      },
    });

    await controller.start();
    const result = await controller.syncProviderRefunds();

    expect(dom.window.document.querySelector('#ys-helcim-refund-sync').hidden).toBe(true);
    expect(dom.window.document.querySelector('#ys-helcim-refund-sync-button').disabled).toBe(true);
    expect(result).toBeNull();
    expect(requests.map((request) => request.method)).toEqual(['GET']);
  });

  it('explains that a payment taken before the payment journal cannot be synced automatically', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const requests = [];
    const controller = syncController(dom, api, jsonResponse({
      order_id: 42,
      status: 'legacy_payment',
      helcim_payments: 0,
      legacy_payments: 1,
      recorded: [],
      already_recorded: [],
      skipped: [],
      retry: [],
      review: [],
    }), requests);
    await controller.start();

    const summary = await controller.syncProviderRefunds();

    expect(summary.status).toBe('legacy_payment');
    const status = dom.window.document.querySelector('#ys-helcim-refund-status');
    expect(status.textContent).toBe(localizedMessages.providerRefundsLegacyPayment);
    expect(status.textContent).not.toBe(localizedMessages.providerRefundsNoHelcimPayment);
    expect(status.className).toContain('notice-warning');
    expect(requests.map((request) => request.method)).toEqual(['GET', 'POST', 'GET']);
    expect(dom.window.document.querySelector('#ys-helcim-refund-sync-button').disabled).toBe(false);
  });

  function twoOrderOptions(orderId) {
    return {
      order_id: orderId,
      classification: 'helcim_only',
      currency: 'USD',
      order_remaining: 2100,
      transactions: [
        { id: orderId === 43 ? 8 : 7, gateway: 'ys_helcim', payment_mode: 'test', remaining_refundable: 2100 },
      ],
      items: [],
    };
  }

  function loadOrderFromLookup(dom, controller, orderId) {
    dom.window.document.querySelector('#ys-helcim-refund-order-id').value = String(orderId);
    dom.window.document.querySelector('#ys-helcim-refund-order-lookup').dispatchEvent(
      new dom.window.Event('submit', { bubbles: true, cancelable: true }),
    );
    return controller.whenIdle();
  }

  it.each([
    [
      'a recorded result',
      jsonResponse({
        order_id: 42,
        status: 'recorded',
        helcim_payments: 1,
        recorded: [{ transaction_id: '85267563', provider_action: 'reverse', reason: 'recorded' }],
        already_recorded: [],
        skipped: [],
        retry: [],
        review: [],
      }),
    ],
    [
      'a failure',
      jsonResponse({ error_code: 'ys_helcim_provider_refund_sync_unavailable', message: 'Refunds could not be synced from Helcim.' }, 503),
    ],
  ])('keeps the order loaded during a Helcim sync when the previous order sync returns %s', async (_label, lateResponse) => {
    const { dom, api } = loadApi(canonicalHtml());
    const requests = [];
    let releaseSync;
    const syncResponse = new Promise((resolve) => {
      releaseSync = resolve;
    });
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async (url, requestOptions) => {
        requests.push([requestOptions.method, url]);
        if (url.endsWith('/sync-provider-refunds')) {
          return syncResponse;
        }
        return jsonResponse(twoOrderOptions(url.includes('/orders/43/') ? 43 : 42));
      },
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
      },
    });
    await controller.start();
    const doc = dom.window.document;
    const status = doc.querySelector('#ys-helcim-refund-status');
    const button = doc.querySelector('#ys-helcim-refund-sync-button');

    // 載入訂單 42 → 按同步（回應懸著）。
    button.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true, cancelable: true }));
    const pendingSync = controller.whenIdle();
    await nextTask(dom);
    expect(status.textContent).toBe(localizedMessages.syncingProviderRefunds);

    // 同步還沒回來，操作者改載訂單 43。
    await loadOrderFromLookup(dom, controller, 43);
    expect(doc.querySelector('#ys-helcim-refund-summary').textContent).toBe('Order #43 · USD');
    expect(status.textContent).toBe(localizedMessages.refundOptionsLoaded);

    // 42 的同步結果這時才回來：畫面必須仍是 43，狀態列不被 42 的結果覆蓋，也不重載 42。
    releaseSync(lateResponse);
    const result = await pendingSync;
    await nextTask(dom);

    expect(result).toBeNull();
    expect(requests).toEqual([
      ['GET', 'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options'],
      ['POST', 'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/sync-provider-refunds'],
      ['GET', 'https://shop.test/wp-json/ys-fc-pay/v1/orders/43/refund-options'],
    ]);
    expect(doc.querySelector('#ys-helcim-refund-summary').textContent).toBe('Order #43 · USD');
    expect(doc.querySelector('#ys-helcim-refund-transaction').value).toBe('8');
    expect(doc.querySelector('#ys-helcim-refund-context').hidden).toBe(false);
    expect(status.textContent).toBe(localizedMessages.refundOptionsLoaded);
    expect(status.className).toContain('notice-success');
    expect(doc.querySelector('#ys-helcim-refund-sync').hidden).toBe(false);
    expect(button.disabled).toBe(false);

    // 之後按同步，送的是目前畫面上的訂單 43。
    button.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true, cancelable: true }));
    expect(requests[3]).toEqual(['POST', 'https://shop.test/wp-json/ys-fc-pay/v1/orders/43/sync-provider-refunds']);
  });

  it.each([
    ['succeeds', () => jsonResponse(twoOrderOptions(42))],
    ['fails', () => jsonResponse({ message: 'Order not found.' }, 404)],
  ])('keeps the order loaded last when a slower earlier lookup %s afterwards', async (_label, lateResponse) => {
    const { dom, api } = loadApi(canonicalHtml());
    const requests = [];
    let releaseOrder42;
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async (url, requestOptions) => {
        requests.push([requestOptions.method, url]);
        if (url.includes('/orders/42/')) {
          return new Promise((resolve) => {
            releaseOrder42 = resolve;
          });
        }
        return jsonResponse(twoOrderOptions(43));
      },
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
      },
    });
    await controller.start();
    const doc = dom.window.document;
    const status = doc.querySelector('#ys-helcim-refund-status');

    // 先查 42（很慢），還沒回來就改查 43（先回來）。
    const slowLookup = loadOrderFromLookup(dom, controller, 42);
    await nextTask(dom);
    await loadOrderFromLookup(dom, controller, 43);
    expect(doc.querySelector('#ys-helcim-refund-summary').textContent).toBe('Order #43 · USD');

    // 42 的結果這時才回來：不論成功或失敗，都不可把畫面換回 42 或蓋掉狀態列。
    releaseOrder42(lateResponse());
    await slowLookup;
    await nextTask(dom);

    expect(requests.map((request) => request[1].replace('https://shop.test/wp-json/ys-fc-pay/v1/', ''))).toEqual([
      'orders/42/refund-options',
      'orders/43/refund-options',
    ]);
    expect(doc.querySelector('#ys-helcim-refund-summary').textContent).toBe('Order #43 · USD');
    expect(doc.querySelector('#ys-helcim-refund-transaction').value).toBe('8');
    expect(doc.querySelector('#ys-helcim-refund-context').hidden).toBe(false);
    expect(status.textContent).toBe(localizedMessages.refundOptionsLoaded);
    expect(status.className).toContain('notice-success');
    expect(doc.querySelector('#ys-helcim-refund-sync-button').disabled).toBe(false);
  });

  it('never lets the reload that follows a Helcim sync overwrite an order loaded meanwhile', async () => {
    const { dom, api } = loadApi(canonicalHtml());
    const requests = [];
    let order42Reads = 0;
    let releaseReload;
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async (url, requestOptions) => {
        requests.push([requestOptions.method, url]);
        if (url.endsWith('/sync-provider-refunds')) {
          return jsonResponse({
            order_id: 42,
            status: 'recorded',
            helcim_payments: 1,
            recorded: [{ transaction_id: '85267563', provider_action: 'reverse', reason: 'recorded' }],
            already_recorded: [],
            skipped: [],
            retry: [],
            review: [],
          });
        }
        if (url.includes('/orders/42/')) {
          order42Reads += 1;
          if (order42Reads === 2) {
            // 同步完成後的重新載入懸著不回。
            return new Promise((resolve) => {
              releaseReload = resolve;
            });
          }
          return jsonResponse(twoOrderOptions(42));
        }
        return jsonResponse(twoOrderOptions(43));
      },
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId: 42,
      },
    });
    await controller.start();
    const doc = dom.window.document;
    const status = doc.querySelector('#ys-helcim-refund-status');

    const pendingSync = controller.syncProviderRefunds();
    for (let attempt = 0; attempt < 20 && order42Reads < 2; attempt += 1) {
      await nextTask(dom);
    }
    expect(order42Reads).toBe(2);

    await loadOrderFromLookup(dom, controller, 43);
    expect(doc.querySelector('#ys-helcim-refund-summary').textContent).toBe('Order #43 · USD');

    releaseReload(jsonResponse(twoOrderOptions(42)));
    const result = await pendingSync;
    await nextTask(dom);

    expect(result).toBeNull();
    expect(requests.map((request) => request[0] + ' ' + request[1].replace('https://shop.test/wp-json/ys-fc-pay/v1/', ''))).toEqual([
      'GET orders/42/refund-options',
      'POST orders/42/sync-provider-refunds',
      'GET orders/42/refund-options',
      'GET orders/43/refund-options',
    ]);
    expect(doc.querySelector('#ys-helcim-refund-summary').textContent).toBe('Order #43 · USD');
    expect(doc.querySelector('#ys-helcim-refund-transaction').value).toBe('8');
    expect(status.textContent).toBe(localizedMessages.refundOptionsLoaded);
    expect(doc.querySelector('#ys-helcim-refund-sync-button').disabled).toBe(false);
  });

  it('ships scoped order-page modal styles that keep the hidden attribute effective', () => {
    const source = readFileSync(stylePath, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');

    // hidden 屬性會被框架的 display 樣式蓋掉：彈窗本身與彈窗內的隱藏區塊都要 !important。
    expect(source).toMatch(
      /\.ys-helcim-refund-modal\[hidden\],\s*\.ys-helcim-refund-modal \[hidden\]\s*\{\s*display:\s*none\s*!important;\s*\}/,
    );
    expect(source).toMatch(/\.ys-helcim-refund-modal\s*\{[^}]*position:\s*fixed;[^}]*inset:\s*0;[^}]*z-index:\s*100050;/);
    expect(source).toMatch(/\.ys-helcim-refund-modal \.ys-helcim-refund-modal__dialog\s*\{[^}]*max-width:\s*720px;[^}]*max-height:\s*90vh;/);
    expect(source).toMatch(/\.ys-helcim-refund-modal \.ys-helcim-refund-modal__body\s*\{[^}]*overflow:\s*auto;/);
    expect(source).toMatch(
      /\.ys-helcim-refund-modal \.ys-helcim-refund-modal__body \.ys-helcim-refund-lookup\s*\{\s*display:\s*none\s*!important;\s*\}/,
    );
    // 開啟時鎖住訂單頁捲動（JS 只加 class，真正鎖捲動的是這條）。
    expect(source).toMatch(
      /\.ys-helcim-refund-modal-open,\s*\.ys-helcim-refund-modal-open body\s*\{\s*overflow:\s*hidden;\s*\}/,
    );
    // FluentCart 後台樣式表的頂層規則 .notice:not(.fluent-cart){display:none!important}（優先權 0,2,0）
    // 會藏掉沿用 .notice 的狀態列：彈窗內要用更高優先權（1,2,0）的 !important 顯示回來，hidden 時仍隱藏。
    expect(source).toMatch(
      /\.ys-helcim-refund-modal #ys-helcim-refund-status:not\(\[hidden\]\)\s*\{\s*display:\s*block\s*!important;/,
    );
    // FluentCart 深色模式把 h1–h6、p、code 改成淺色字：彈窗的白底／淺黃底上要明確指定深色字，避開 p.description。
    expect(source).toMatch(
      /\.ys-helcim-refund-modal h2,\s*\.ys-helcim-refund-modal p:not\(\.description\),\s*\.ys-helcim-refund-modal code\s*\{\s*color:\s*#1d2327;\s*\}/,
    );
    // 不可對 FluentCart／Element Plus 的 .el-* 彈層或下拉選單下任何樣式。
    expect(source).not.toMatch(/\.el-/);

    const selectors = [];
    source.replace(/(?:^|[{}])\s*([^{}@]+?)\s*\{/g, (match, selector) => {
      selector.split(',').forEach((part) => selectors.push(part.trim()));
      return match;
    });
    const modalSelectors = selectors.filter((selector) => selector.includes('ys-helcim-refund-modal'));
    expect(modalSelectors.length).toBeGreaterThan(5);
    modalSelectors.forEach((selector) => {
      expect(selector.startsWith('.ys-helcim-refund-modal')).toBe(true);
    });
  });

  it('opens the order-page modal from the Helcim refund link without navigating', async () => {
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const { controller, navigations, reloads, requests } = modalController(
      dom,
      api,
      async () => jsonResponse(modalOrderOptions(42)),
    );
    await controller.start();
    const { doc, modal, dialog } = modalParts(dom);
    expectModalOpen(dom, false);

    const link = doc.querySelector('[data-ys-helcim-refund-order="42"]');
    const click = leftClick(dom, link);

    expect(click.defaultPrevented).toBe(true);
    expectModalOpen(dom, true);
    expect(doc.activeElement).toBe(dialog);
    await controller.whenIdle();

    expect(navigations).toEqual([]);
    expect(reloads).toEqual([]);
    expect(dom.window.location.href).toBe('https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view');
    expect(requests.map((request) => [request.options.method, request.url])).toEqual([
      ['GET', 'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options'],
      ['GET', 'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options'],
    ]);
    expect(requests[1].options.headers).toEqual({ 'X-WP-Nonce': 'rest-nonce' });
    const summary = doc.querySelector('#ys-helcim-refund-summary');
    expect(modal.contains(summary)).toBe(true);
    expect(summary.textContent).toBe('Order #42 · USD');
    expect(doc.querySelector('#ys-helcim-refund-context').hidden).toBe(false);
    expect(doc.querySelector('#ys-helcim-refund-transaction').value).toBe('7');
    expect(doc.querySelector('#ys-helcim-refund-amount').value).toBe('21.00');
    expect(link.getAttribute('href')).toBe('https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds&order_id=42');
  });

  it('opens the modal instead of navigating when the native Refund action is clicked for a helcim_only order', async () => {
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const { controller, navigations, requests } = modalController(
      dom,
      api,
      async () => jsonResponse(modalOrderOptions(42)),
    );
    await controller.start();
    const { doc } = modalParts(dom);
    const native = doc.querySelector('button.bulk-action-hide-only-mobile');
    expect(native.hidden).toBe(true);
    const nativeDialogs = [];
    native.addEventListener('click', () => nativeDialogs.push('fluentcart-refund-dialog'));
    native.hidden = false;

    const click = leftClick(dom, native);

    expect(click.defaultPrevented).toBe(true);
    expect(nativeDialogs).toEqual([]);
    expectModalOpen(dom, true);
    await controller.whenIdle();
    expect(navigations).toEqual([]);
    expect(requests[1].url).toBe('https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options');
    expect(doc.querySelector('#ys-helcim-refund-summary').textContent).toBe('Order #42 · USD');
  });

  it('submits the exact canonical refund POST from the modal and shows the result inside it', async () => {
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const { controller, navigations, requests } = modalController(dom, api, async (url, options) => (
      options.method === 'GET'
        ? jsonResponse(modalOrderOptions(42))
        : jsonResponse(appliedRefund(operationUuid))
    ));
    await controller.start();
    const { doc, modal } = modalParts(dom);
    leftClick(dom, doc.querySelector('[data-ys-helcim-refund-order="42"]'));
    await controller.whenIdle();

    doc.querySelector('#ys-helcim-refund-amount').value = '10.50';
    doc.querySelector('#ys-helcim-refund-reason').value = 'Customer request';
    doc.querySelector('.ys-helcim-refund-item').checked = true;
    doc.querySelector('#ys-helcim-refund-form').dispatchEvent(
      new dom.window.Event('submit', { bubbles: true, cancelable: true }),
    );
    await controller.whenIdle();

    expect(requests).toHaveLength(3);
    expect(requests[2].url).toBe('https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refunds');
    expect(requests[2].options.method).toBe('POST');
    expect(requests[2].options.credentials).toBe('same-origin');
    expect(requests[2].options.headers).toEqual({
      'Content-Type': 'application/json',
      'X-WP-Nonce': 'rest-nonce',
    });
    // 與獨立頁面測試的送出內容完全相同。
    expect(JSON.parse(requests[2].options.body)).toEqual({
      operation_uuid: operationUuid,
      transaction_id: 7,
      amount: '10.50',
      reason: 'Customer request',
      item_ids: [9],
      manage_stock: false,
      refunded_items: [],
      cancel_subscription: false,
    });
    const status = doc.querySelector('#ys-helcim-refund-status');
    const operation = doc.querySelector('#ys-helcim-refund-operation');
    expect(modal.contains(status)).toBe(true);
    expect(modal.contains(operation)).toBe(true);
    expect(status.textContent).toBe(localizedMessages.refundCompleted);
    expect(operation.hidden).toBe(false);
    expect(operation.textContent).toContain('succeeded');
    expect(operation.textContent).toContain('applied');
    expect(doc.querySelector('#ys-helcim-refund-submit').disabled).toBe(true);
    expectModalOpen(dom, true);
    expect(navigations).toEqual([]);
  });

  it('closes with the close button, the backdrop and Escape, but not while a refund response is pending', async () => {
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    let releaseRefund;
    const { controller, reloads } = modalController(dom, api, (url, options) => {
      if (options.method === 'GET') {
        return Promise.resolve(jsonResponse(modalOrderOptions(42)));
      }
      return new Promise((resolve) => {
        releaseRefund = resolve;
      });
    });
    await controller.start();
    const { doc, dialog, close, backdrop } = modalParts(dom);
    const link = doc.querySelector('[data-ys-helcim-refund-order="42"]');
    const openFromLink = async () => {
      leftClick(dom, link);
      expectModalOpen(dom, true);
      await controller.whenIdle();
    };

    await openFromLink();
    leftClick(dom, close);
    expectModalOpen(dom, false);

    await openFromLink();
    leftClick(dom, backdrop);
    expectModalOpen(dom, false);

    await openFromLink();
    const escape = pressKey(dom, dialog, 'Escape');
    expect(escape.defaultPrevented).toBe(true);
    expectModalOpen(dom, false);

    // 彈窗關著時不攔 Escape：其他外掛的對話框（例如 Change Order Status）照常收到並關閉。
    const otherDialogEscapes = [];
    doc.addEventListener('keydown', (event) => otherDialogEscapes.push(event.key));
    const closedEscape = pressKey(dom, doc.body, 'Escape');
    expect(closedEscape.defaultPrevented).toBe(false);
    expect(otherDialogEscapes).toEqual(['Escape']);

    // 送出後回應還沒回來：三種關閉方式都不關，顯示處理中提示。
    await openFromLink();
    doc.querySelector('#ys-helcim-refund-amount').value = '21.00';
    doc.querySelector('#ys-helcim-refund-form').dispatchEvent(
      new dom.window.Event('submit', { bubbles: true, cancelable: true }),
    );
    const pendingRefund = controller.whenIdle();
    await nextTask(dom);
    const status = doc.querySelector('#ys-helcim-refund-status');
    leftClick(dom, close);
    expectModalOpen(dom, true);
    expect(status.textContent).toBe(localizedMessages.refundModalBusy);
    expect(status.className).toContain('notice-warning');
    leftClick(dom, backdrop);
    expectModalOpen(dom, true);
    pressKey(dom, dialog, 'Escape');
    expectModalOpen(dom, true);
    expect(controller.closeModal()).toBe(false);

    releaseRefund(jsonResponse(appliedRefund(operationUuid)));
    await pendingRefund;
    expect(status.textContent).toBe(localizedMessages.refundCompleted);
    // 關閉時才重載：成功當下不可重載，店家要先看到彈窗裡的結果。
    expect(reloads).toEqual([]);
    leftClick(dom, close);
    expectModalOpen(dom, false);
    expect(reloads).toHaveLength(1);
  });

  it('handles Escape and Tab after a disabled button dropped focus to the body', async () => {
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    let releaseRefund;
    const { controller, reloads } = modalController(dom, api, (url, options) => {
      if (options.method === 'GET') {
        return Promise.resolve(jsonResponse(modalOrderOptions(42)));
      }
      return new Promise((resolve) => {
        releaseRefund = resolve;
      });
    });
    await controller.start();
    const { doc, close } = modalParts(dom);
    leftClick(dom, doc.querySelector('[data-ys-helcim-refund-order="42"]'));
    await controller.whenIdle();

    const submit = doc.querySelector('#ys-helcim-refund-submit');
    const status = doc.querySelector('#ys-helcim-refund-status');
    doc.querySelector('#ys-helcim-refund-amount').value = '21.00';
    submit.focus();
    doc.querySelector('#ys-helcim-refund-form').dispatchEvent(
      new dom.window.Event('submit', { bubbles: true, cancelable: true }),
    );
    const pendingRefund = controller.whenIdle();
    await nextTask(dom);
    expect(submit.disabled).toBe(true);
    // Chromium 對停用中的焦點元素做 focus fixup：焦點掉到 <body>，而且不觸發 focusin。
    // jsdom 不做 fixup，也不讓停用中的元素 blur：暫時解除停用、blur、再停用，手動重現。
    expect(doc.activeElement).toBe(submit);
    submit.disabled = false;
    submit.blur();
    submit.disabled = true;
    expect(doc.activeElement).toBe(doc.body);

    // 處理中：body 上的 Escape 也要收到，不關並顯示處理中提示。
    const busyEscape = pressKey(dom, doc.body, 'Escape');
    expect(busyEscape.defaultPrevented).toBe(true);
    expectModalOpen(dom, true);
    expect(status.textContent).toBe(localizedMessages.refundModalBusy);

    releaseRefund(jsonResponse(appliedRefund(operationUuid)));
    await pendingRefund;
    expect(status.textContent).toBe(localizedMessages.refundCompleted);
    expect(doc.activeElement).toBe(doc.body);

    // Tab 從 <body> 拉回對話框的第一個控制項。
    const tab = pressKey(dom, doc.body, 'Tab');
    expect(tab.defaultPrevented).toBe(true);
    expect(doc.activeElement).toBe(close);

    // 退款成功後焦點又在 <body>：單按 Escape 就關閉並重載。
    close.blur();
    expect(doc.activeElement).toBe(doc.body);
    const escape = pressKey(dom, doc.body, 'Escape');
    expect(escape.defaultPrevented).toBe(true);
    expectModalOpen(dom, false);
    expect(reloads).toHaveLength(1);

    // 彈窗關著：body 上的 Escape 不 preventDefault，照常傳到 window（其他外掛的對話框靠它關閉）。
    const seenByWindow = [];
    dom.window.addEventListener('keydown', (event) => seenByWindow.push(event.key));
    const closedEscape = pressKey(dom, doc.body, 'Escape');
    expect(closedEscape.defaultPrevented).toBe(false);
    expect(seenByWindow).toEqual(['Escape']);
    expect(reloads).toHaveLength(1);
  });

  it('keeps an open-modal Escape away from document and window keydown listeners registered before it', async () => {
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    // 比本外掛早註冊的頁面層監聽器（例如 Element Plus 對話框掛在 document 的 Escape 處理）。
    // 同一節點、同一階段的監聽器依註冊順序觸發：本外掛若掛在 bubble 階段，document 上這支會先收到；
    // 不 stopPropagation，兩支都會收到。兩種情況都會讓背後的對話框跟著被 Escape 關掉。
    const seenByDocument = [];
    const seenByWindow = [];
    dom.window.document.addEventListener('keydown', (event) => seenByDocument.push(event.key));
    dom.window.addEventListener('keydown', (event) => seenByWindow.push(event.key));
    const { controller } = modalController(dom, api, async () => jsonResponse(modalOrderOptions(42)));
    await controller.start();
    const { doc } = modalParts(dom);
    const link = doc.querySelector('[data-ys-helcim-refund-order="42"]');

    // 焦點掉到 <body> 時按 Escape：關閉彈窗，兩支監聽器都收不到。
    leftClick(dom, link);
    await controller.whenIdle();
    expectModalOpen(dom, true);
    const bodyEscape = pressKey(dom, doc.body, 'Escape');
    expect(bodyEscape.defaultPrevented).toBe(true);
    expectModalOpen(dom, false);
    expect(seenByDocument).toEqual([]);
    expect(seenByWindow).toEqual([]);

    // 在彈窗內的欄位上按 Escape：同樣關閉，兩支監聽器都收不到。
    leftClick(dom, link);
    await controller.whenIdle();
    expectModalOpen(dom, true);
    const amount = doc.querySelector('#ys-helcim-refund-amount');
    amount.focus();
    const fieldEscape = pressKey(dom, amount, 'Escape');
    expect(fieldEscape.defaultPrevented).toBe(true);
    expectModalOpen(dom, false);
    expect(seenByDocument).toEqual([]);
    expect(seenByWindow).toEqual([]);

    // 對照組：彈窗關著時同一組監聽器照常收到，證明上面的「收不到」不是監聽器沒生效。
    const closedEscape = pressKey(dom, doc.body, 'Escape');
    expect(closedEscape.defaultPrevented).toBe(false);
    expect(seenByDocument).toEqual(['Escape']);
    expect(seenByWindow).toEqual(['Escape']);
  });

  it('stays on the current order when openModal is called for another order while a refund is pending', async () => {
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    let releaseRefund;
    const { controller, requests } = modalController(dom, api, (url, options) => {
      if (options.method === 'GET') {
        return Promise.resolve(jsonResponse(modalOrderOptions(url.includes('/orders/43/') ? 43 : 42)));
      }
      return new Promise((resolve) => {
        releaseRefund = resolve;
      });
    });
    await controller.start();
    const { doc } = modalParts(dom);
    leftClick(dom, doc.querySelector('[data-ys-helcim-refund-order="42"]'));
    await controller.whenIdle();
    const summary = doc.querySelector('#ys-helcim-refund-summary');
    const status = doc.querySelector('#ys-helcim-refund-status');
    const amount = doc.querySelector('#ys-helcim-refund-amount');
    expect(summary.textContent).toBe('Order #42 · USD');

    amount.value = '21.00';
    doc.querySelector('#ys-helcim-refund-form').dispatchEvent(
      new dom.window.Event('submit', { bubbles: true, cancelable: true }),
    );
    const pendingRefund = controller.whenIdle();
    await nextTask(dom);
    const requestsBeforeReopen = requests.map((request) => [request.options.method, request.url]);
    expect(requestsBeforeReopen).toEqual([
      ['GET', 'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options'],
      ['GET', 'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options'],
      ['POST', 'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refunds'],
    ]);
    expect(status.textContent).toBe(localizedMessages.submittingRefund);

    // POST 還沒回來時要求開另一張訂單：回 true（不退回換頁），但不清面板、不載入 #43，只顯示處理中提示。
    expect(controller.openModal(43)).toBe(true);
    expectModalOpen(dom, true);
    expect(summary.textContent).toBe('Order #42 · USD');
    expect(doc.querySelector('#ys-helcim-refund-context').hidden).toBe(false);
    expect(amount.value).toBe('21.00');
    expect(status.hidden).toBe(false);
    expect(status.className).toContain('notice-warning');
    expect(status.textContent).toBe(localizedMessages.refundModalBusy);
    await nextTask(dom);
    expect(requests.map((request) => [request.options.method, request.url])).toEqual(requestsBeforeReopen);

    // 結果回來後仍是 #42 的結果，整個過程沒有任何 #43 的 refund-options GET。
    releaseRefund(jsonResponse(appliedRefund(operationUuid)));
    await pendingRefund;
    expect(status.textContent).toBe(localizedMessages.refundCompleted);
    expect(summary.textContent).toBe('Order #42 · USD');
    expect(requests.map((request) => [request.options.method, request.url])).toEqual(requestsBeforeReopen);
  });

  it('refuses to close while a Helcim sync is pending, then reloads after it recorded a refund', async () => {
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    let releaseSync;
    const { controller, reloads } = modalController(dom, api, (url, options) => {
      if (options.method === 'GET') {
        return Promise.resolve(jsonResponse(modalOrderOptions(42)));
      }
      return new Promise((resolve) => {
        releaseSync = resolve;
      });
    });
    await controller.start();
    const { doc, close } = modalParts(dom);
    leftClick(dom, doc.querySelector('[data-ys-helcim-refund-order="42"]'));
    await controller.whenIdle();

    leftClick(dom, doc.querySelector('#ys-helcim-refund-sync-button'));
    const pendingSync = controller.whenIdle();
    await nextTask(dom);
    expectCloseRefused(dom, controller);

    releaseSync(jsonResponse({
      order_id: 42,
      status: 'recorded',
      helcim_payments: 1,
      recorded: [{ transaction_id: '85267563', provider_action: 'reverse', reason: 'recorded' }],
      already_recorded: [],
      skipped: [],
      retry: [],
      review: [],
    }));
    await pendingSync;
    expect(doc.querySelector('#ys-helcim-refund-status').textContent)
      .toBe('Recorded refunds or voids from Helcim: 1. FluentCart now matches Helcim.');
    expect(reloads).toEqual([]);
    leftClick(dom, close);
    expectModalOpen(dom, false);
    expect(reloads).toHaveLength(1);
  });

  it('refuses to close while Reconcile is reading the refund operation', async () => {
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    let releaseOperation;
    const { controller, reloads, requests } = modalController(dom, api, (url) => {
      if (url.endsWith('/refund-options')) {
        return Promise.resolve(jsonResponse(modalOrderOptions(42)));
      }
      if (url.endsWith('/refunds')) {
        // 送出的回應遺失（斷線）：結果不明，畫面改出 Reconcile。
        return Promise.reject(new TypeError('Failed to fetch'));
      }
      return new Promise((resolve) => {
        releaseOperation = resolve;
      });
    });
    await controller.start();
    const { doc, close } = modalParts(dom);
    leftClick(dom, doc.querySelector('[data-ys-helcim-refund-order="42"]'));
    await controller.whenIdle();
    doc.querySelector('#ys-helcim-refund-amount').value = '21.00';
    doc.querySelector('#ys-helcim-refund-form').dispatchEvent(
      new dom.window.Event('submit', { bubbles: true, cancelable: true }),
    );
    await controller.whenIdle();
    const reconcile = doc.querySelector('#ys-helcim-refund-reconcile');
    expect(reconcile.hidden).toBe(false);

    leftClick(dom, reconcile);
    const pendingReconcile = controller.whenIdle();
    await nextTask(dom);
    expect(requests.at(-1).options.method).toBe('GET');
    expect(requests.at(-1).url).toBe(`https://shop.test/wp-json/ys-fc-pay/v1/refund-operations/${operationUuid}`);
    expectCloseRefused(dom, controller);

    releaseOperation(jsonResponse(appliedRefund(operationUuid)));
    await pendingReconcile;
    expect(doc.querySelector('#ys-helcim-refund-status').textContent).toBe(localizedMessages.refundCompleted);
    expect(reloads).toEqual([]);
    leftClick(dom, close);
    expectModalOpen(dom, false);
    expect(reloads).toHaveLength(1);
  });

  it('refuses to close while positive evidence is inspected or committed, and reloads after a committed resolution', async () => {
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const candidate = '81177094';
    const challenge = 'c'.repeat(64);
    const phrase = `RESOLVE ${operationUuid} WITH HELCIM ${candidate}`;
    let releaseInspect;
    let releaseCommit;
    const { controller, reloads, requests } = modalController(dom, api, (url) => {
      if (url.endsWith('/refund-options')) {
        return Promise.resolve(jsonResponse(modalOrderOptions(42, {
          classification: 'blocked',
          transactions: [],
          resolution_operation: { operation_uuid: operationUuid, provider_action: 'refund' },
        })));
      }
      if (url.endsWith('/inspect')) {
        return new Promise((resolve) => {
          releaseInspect = resolve;
        });
      }
      if (url.endsWith('/commit')) {
        return new Promise((resolve) => {
          releaseCommit = resolve;
        });
      }
      // 輪詢讀回的作業還沒在本地套用（不是 applied）：completeOperation 不會補設重載旗標，
      // 重載只能靠 resolution commit 成功本身。
      return Promise.resolve(jsonResponse({ ...appliedRefund(operationUuid), local_status: 'recorded' }));
    }, {
      canResolve: true,
      pollAttempts: 1,
      labels: { nativeRefund: 'Refund', helcimRefund: 'Helcim Refund', blocked: 'Helcim refund is blocked.' },
    });
    await controller.start();
    const { doc, close } = modalParts(dom);
    leftClick(dom, doc.querySelector('[data-ys-helcim-refund-order="42"]'));
    await controller.whenIdle();
    expect(doc.querySelector('#ys-helcim-refund-resolution').hidden).toBe(false);

    doc.querySelector('#ys-helcim-refund-resolution-candidate').value = candidate;
    leftClick(dom, doc.querySelector('#ys-helcim-refund-resolution-inspect'));
    const pendingInspect = controller.whenIdle();
    await nextTask(dom);
    expectCloseRefused(dom, controller);
    releaseInspect(jsonResponse({
      status: 'confirmation_required',
      operation_uuid: operationUuid,
      candidate_transaction_id: candidate,
      source_transaction_id: '81177061',
      candidate_type: 'refund',
      candidate_amount_cents: 2100,
      candidate_currency: 'USD',
      invoice_number: '3b0c6f2e-8d41-4a7e-9c55-1f2a3b4c5d6e',
      action: 'resolve_positive',
      parent_attestation_required: false,
      challenge,
      challenge_expires_at: '2026-07-21 08:05:00',
      confirmation_phrase: phrase,
    }));
    await pendingInspect;

    const typedPhrase = doc.querySelector('#ys-helcim-refund-resolution-typed-phrase');
    typedPhrase.value = phrase;
    typedPhrase.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
    const commit = doc.querySelector('#ys-helcim-refund-resolution-commit');
    expect(commit.disabled).toBe(false);
    leftClick(dom, commit);
    const pendingCommit = controller.whenIdle();
    await nextTask(dom);
    expectCloseRefused(dom, controller);

    releaseCommit(jsonResponse({
      status: 'resolved',
      operation_uuid: operationUuid,
      remote_status: 'succeeded',
      replayed: false,
      local_recording_status: 'continued',
      local_status: 'recorded',
    }, 202));
    await pendingCommit;
    expect(doc.querySelector('#ys-helcim-refund-status').textContent).toBe(localizedMessages.refundStillReconciling);
    expect(requests.filter((request) => request.options.method === 'POST').map((request) => request.url)).toEqual([
      `https://shop.test/wp-json/ys-fc-pay/v1/refund-resolutions/${operationUuid}/inspect`,
      `https://shop.test/wp-json/ys-fc-pay/v1/refund-resolutions/${operationUuid}/commit`,
    ]);
    expect(reloads).toEqual([]);
    leftClick(dom, close);
    expectModalOpen(dom, false);
    expect(reloads).toHaveLength(1);
  });

  it('keeps the modal open with the server error when the order cannot be loaded into it', async () => {
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    let optionReads = 0;
    const { controller, navigations, reloads } = modalController(dom, api, async () => {
      optionReads += 1;
      // 第一次是訂單頁的分類查詢；彈窗開啟後的載入回 403（例如後台分頁開太久、nonce 過期）。
      return optionReads === 1
        ? jsonResponse(modalOrderOptions(42))
        : jsonResponse({ code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed' }, 403);
    });
    await controller.start();
    const { doc, close } = modalParts(dom);
    leftClick(dom, doc.querySelector('[data-ys-helcim-refund-order="42"]'));
    expectModalOpen(dom, true);

    await expect(controller.whenIdle()).resolves.toBeNull();
    expectModalOpen(dom, true);
    const status = doc.querySelector('#ys-helcim-refund-status');
    expect(status.hidden).toBe(false);
    expect(status.className).toContain('notice-error');
    expect(status.textContent).toBe('Cookie check failed');
    expect(doc.querySelector('#ys-helcim-refund-context').hidden).toBe(true);
    expect(doc.querySelector('#ys-helcim-refund-sync').hidden).toBe(true);
    expect(optionReads).toBe(2);
    expect(navigations).toEqual([]);

    // 沒有任何變動：關閉不重載。
    leftClick(dom, close);
    expectModalOpen(dom, false);
    expect(reloads).toEqual([]);
  });

  // FluentCart 後台根元件 DashboardApplication 在 onMounted 時執行一次（1.5.2、1.6.0、1.6.3 都有）：
  // jQuery(".notice:not(.fluent-cart), .error:not(.fluent-cart), #ehp-admin-cb").remove()
  // 這裡用等效的 DOM 操作把符合的節點整個移出 DOM。
  function removeNoticesLikeFluentCartMount(doc) {
    const removed = [...doc.querySelectorAll('.notice:not(.fluent-cart), .error:not(.fluent-cart), #ehp-admin-cb')];
    removed.forEach((node) => node.remove());
    return removed;
  }

  // 狀態列沒在顯示訊息時（伺服器輸出、彈窗重設後）不可帶 notice／error class，否則會被清除整個移出 DOM，
  // 彈窗裡的錯誤、處理中提示、退款完成訊息全都不會出現。清除的時間點相對控制器不固定，三個時間點都要撐過。
  it.each([
    ['before the controller starts', 'beforeStart'],
    ['after the controller started and before the modal opens', 'beforeOpen'],
    ['right after the modal reset its panel while the order is still loading', 'whileLoading'],
  ])('keeps showing modal status messages when FluentCart removes WordPress notices %s', async (_label, timing) => {
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const doc = dom.window.document;
    // 頁面上真的有其他外掛與 WP 的通知：確認模擬的清除確實會移除它們，只留下 FluentCart 自己的。
    doc.body.insertAdjacentHTML(
      'afterbegin',
      '<div class="notice notice-warning"><p>Other plugin</p></div>'
        + '<div class="error"><p>Old error</p></div>'
        + '<div class="notice fluent-cart" id="fct-own-notice"><p>FluentCart</p></div>',
    );
    const purge = () => {
      const removed = removeNoticesLikeFluentCartMount(doc);
      expect(removed.length).toBeGreaterThanOrEqual(2);
      expect(doc.querySelector('#fct-own-notice')).not.toBeNull();
      expect(doc.querySelector('.notice:not(.fluent-cart), .error:not(.fluent-cart)')).toBeNull();
    };

    const operationUuid = '00000000-0000-4000-8000-000000000001';
    let optionReads = 0;
    let releaseFailedLoad;
    let releaseRefund;
    const { controller } = modalController(dom, api, (url, options) => {
      if (options.method !== 'GET') {
        return new Promise((resolve) => {
          releaseRefund = resolve;
        });
      }
      optionReads += 1;
      if (optionReads === 2) {
        // 第一次開彈窗的載入：先卡住，等測試決定清除時間點後再回 403。
        return new Promise((resolve) => {
          releaseFailedLoad = resolve;
        });
      }
      return Promise.resolve(jsonResponse(modalOrderOptions(42)));
    });

    if (timing === 'beforeStart') {
      purge();
    }
    await controller.start();
    if (timing === 'beforeOpen') {
      purge();
    }
    const { modal, close } = modalParts(dom);
    leftClick(dom, doc.querySelector('[data-ys-helcim-refund-order="42"]'));
    expectModalOpen(dom, true);
    if (timing === 'whileLoading') {
      purge();
    }
    releaseFailedLoad(jsonResponse({ code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed' }, 403));
    await controller.whenIdle();

    const status = doc.querySelector('#ys-helcim-refund-status');
    expect(status).not.toBeNull();
    expect(modal.contains(status)).toBe(true);
    expect(status.hidden).toBe(false);
    // 顯示訊息時才帶 WP 的 notice class（沿用 WP 通知樣式），伺服器輸出的 class 也保留。
    expect(status.classList.contains('notice')).toBe(true);
    expect(status.classList.contains('ys-helcim-refund-status')).toBe(true);
    expect(status.className).toContain('notice-error');
    expect(status.textContent).toBe('Cookie check failed');

    // 關閉後重新開啟（載入成功），送出後回應卡住：按關閉要在彈窗裡看到處理中提示。
    leftClick(dom, close);
    expectModalOpen(dom, false);
    leftClick(dom, doc.querySelector('[data-ys-helcim-refund-order="42"]'));
    await controller.whenIdle();
    expect(doc.querySelector('#ys-helcim-refund-context').hidden).toBe(false);
    doc.querySelector('#ys-helcim-refund-amount').value = '21.00';
    doc.querySelector('#ys-helcim-refund-form').dispatchEvent(
      new dom.window.Event('submit', { bubbles: true, cancelable: true }),
    );
    const pendingRefund = controller.whenIdle();
    await nextTask(dom);
    leftClick(dom, close);
    expectModalOpen(dom, true);
    expect(doc.querySelector('#ys-helcim-refund-status')).toBe(status);
    expect(status.hidden).toBe(false);
    expect(status.className).toContain('notice-warning');
    expect(status.textContent).toBe(localizedMessages.refundModalBusy);

    releaseRefund(jsonResponse(appliedRefund(operationUuid)));
    await pendingRefund;
    expect(doc.querySelector('#ys-helcim-refund-status')).toBe(status);
    expect(status.className).toContain('notice-success');
    expect(status.textContent).toBe(localizedMessages.refundCompleted);
  });

  it('reloads the order page on close only when a refund, void or sync changed it during that opening', async () => {
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    let syncStatus = 'recorded';
    const { controller, reloads, requests } = modalController(dom, api, async (url, options) => {
      if (options.method === 'GET') {
        return jsonResponse(modalOrderOptions(42));
      }
      if (url.endsWith('/sync-provider-refunds')) {
        return jsonResponse({
          order_id: 42,
          status: syncStatus,
          helcim_payments: 1,
          recorded: syncStatus === 'recorded'
            ? [{ transaction_id: '85267563', provider_action: 'reverse', reason: 'recorded' }]
            : [],
          already_recorded: [],
          skipped: [],
          retry: [],
          review: [],
        });
      }
      return jsonResponse({ ...appliedRefund(operationUuid), provider_action: 'reverse' });
    });
    await controller.start();
    const { doc, close } = modalParts(dom);
    const link = doc.querySelector('[data-ys-helcim-refund-order="42"]');
    const open = async () => {
      leftClick(dom, link);
      await controller.whenIdle();
    };

    // 沒有任何變動：不重載。
    await open();
    leftClick(dom, close);
    expectModalOpen(dom, false);
    expect(reloads).toEqual([]);

    // 退款（這裡是未結算改送的作廢）成功記入：關閉時重載一次。
    await open();
    doc.querySelector('#ys-helcim-refund-amount').value = '21.00';
    doc.querySelector('#ys-helcim-refund-form').dispatchEvent(
      new dom.window.Event('submit', { bubbles: true, cancelable: true }),
    );
    await controller.whenIdle();
    expect(doc.querySelector('#ys-helcim-refund-status').textContent).toBe(localizedMessages.paymentVoided);
    // 關閉時才重載：作廢成功當下不可重載。
    expect(reloads).toEqual([]);
    leftClick(dom, close);
    expect(reloads).toHaveLength(1);

    // 下一次開啟重新計算：同步結果 recorded → 關閉時重載。
    await open();
    leftClick(dom, doc.querySelector('#ys-helcim-refund-sync-button'));
    await controller.whenIdle();
    expect(doc.querySelector('#ys-helcim-refund-status').textContent)
      .toBe('Recorded refunds or voids from Helcim: 1. FluentCart now matches Helcim.');
    expect(reloads).toHaveLength(1);
    leftClick(dom, close);
    expect(reloads).toHaveLength(2);

    // 同步沒有新紀錄：不重載。
    syncStatus = 'nothing_new';
    await open();
    leftClick(dom, doc.querySelector('#ys-helcim-refund-sync-button'));
    await controller.whenIdle();
    leftClick(dom, close);
    expect(reloads).toHaveLength(2);
    expect(requests.filter((request) => request.options.method === 'POST').map((request) => request.url)).toEqual([
      'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refunds',
      'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/sync-provider-refunds',
      'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/sync-provider-refunds',
    ]);
  });

  it.each([
    ['needs review but recorded a void', 'needs_review', true, 1],
    ['must retry later but recorded a void', 'retry_later', true, 1],
    ['needs review and recorded nothing', 'needs_review', false, 0],
  ])('reloads on close after a Helcim sync that %s', async (_label, syncStatus, recordedOne, expectedReloads) => {
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const { controller, reloads } = modalController(dom, api, async (url, options) => {
      if (options.method === 'GET') {
        return jsonResponse(modalOrderOptions(42));
      }
      // 後端優先序 review > retry > recorded：同一次同步裡已寫進 FluentCart 的作廢仍列在 recorded。
      return jsonResponse({
        order_id: 42,
        status: syncStatus,
        helcim_payments: 2,
        recorded: recordedOne
          ? [{ transaction_id: '85267563', provider_action: 'reverse', reason: 'recorded' }]
          : [],
        already_recorded: [],
        skipped: [],
        retry: syncStatus === 'retry_later' ? [{ transaction_id: '85267564', reason: 'provider_lookup_failed' }] : [],
        review: syncStatus === 'needs_review' ? [{ transaction_id: '85267564', reason: 'invalid_purchase_operation' }] : [],
      });
    });
    await controller.start();
    const { doc, close } = modalParts(dom);
    leftClick(dom, doc.querySelector('[data-ys-helcim-refund-order="42"]'));
    await controller.whenIdle();
    leftClick(dom, doc.querySelector('#ys-helcim-refund-sync-button'));
    await controller.whenIdle();

    expect(doc.querySelector('#ys-helcim-refund-status').textContent).toBe(
      syncStatus === 'needs_review'
        ? 'Some refunds in Helcim could not be recorded automatically (invalid_purchase_operation). Compare this order with Helcim before refunding again.'
        : localizedMessages.providerRefundsRetryLater,
    );
    expect(reloads).toEqual([]);
    leftClick(dom, close);
    expectModalOpen(dom, false);
    expect(reloads).toHaveLength(expectedReloads);
  });

  it('closes an idle modal when the route moves to another order and reopens with that order', async () => {
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    let releaseRefund;
    const { controller, reloads, requests } = modalController(dom, api, (url, options) => {
      if (options.method === 'GET') {
        return Promise.resolve(jsonResponse(modalOrderOptions(url.includes('/orders/43/') ? 43 : 42)));
      }
      return new Promise((resolve) => {
        releaseRefund = resolve;
      });
    });
    await controller.start();
    const { doc } = modalParts(dom);
    leftClick(dom, doc.querySelector('[data-ys-helcim-refund-order="42"]'));
    await controller.whenIdle();
    expect(doc.querySelector('#ys-helcim-refund-summary').textContent).toBe('Order #42 · USD');
    const reason = doc.querySelector('#ys-helcim-refund-reason');
    const status = doc.querySelector('#ys-helcim-refund-status');
    reason.value = 'Order 42 reason';
    expect(status.hidden).toBe(false);

    dom.window.history.pushState(null, '', '#/orders/43/view');
    dom.window.dispatchEvent(new dom.window.HashChangeEvent('hashchange'));
    expectModalOpen(dom, false);
    await controller.whenIdle();

    leftClick(dom, doc.querySelector('[data-ys-helcim-refund-order="43"]'));
    expectModalOpen(dom, true);
    // 新訂單載入前，舊訂單的畫面已清掉：摘要、內容區、狀態列與退款原因（載入不會重設原因欄，
    // 留著會讓訂單 42 的原因跟著訂單 43 的退款一起送出）。
    expect(doc.querySelector('#ys-helcim-refund-summary').textContent).toBe('');
    expect(doc.querySelector('#ys-helcim-refund-context').hidden).toBe(true);
    expect(status.hidden).toBe(true);
    expect(status.textContent).toBe('');
    expect(reason.value).toBe('');
    await controller.whenIdle();
    expect(doc.querySelector('#ys-helcim-refund-summary').textContent).toBe('Order #43 · USD');
    expect(doc.querySelector('#ys-helcim-refund-transaction').value).toBe('8');
    expect(reason.value).toBe('');
    expect(requests.map((request) => request.url.replace('https://shop.test/wp-json/ys-fc-pay/v1/', ''))).toEqual([
      'orders/42/refund-options',
      'orders/42/refund-options',
      'orders/43/refund-options',
      'orders/43/refund-options',
    ]);

    // 處理中換路由：彈窗不動，等結果出來。
    doc.querySelector('#ys-helcim-refund-amount').value = '21.00';
    doc.querySelector('#ys-helcim-refund-form').dispatchEvent(
      new dom.window.Event('submit', { bubbles: true, cancelable: true }),
    );
    const pendingRefund = controller.whenIdle();
    await nextTask(dom);
    dom.window.history.pushState(null, '', '#/orders/42/view');
    dom.window.dispatchEvent(new dom.window.HashChangeEvent('hashchange'));
    expectModalOpen(dom, true);
    expect(doc.querySelector('#ys-helcim-refund-summary').textContent).toBe('Order #43 · USD');
    releaseRefund(jsonResponse({
      ...appliedRefund('00000000-0000-4000-8000-000000000001'),
      remote_status: 'declined',
      local_status: 'not_applied',
      retry_allowed: true,
    }));
    await pendingRefund;
    await controller.whenIdle();
    expect(doc.querySelector('#ys-helcim-refund-status').textContent).toBe(localizedMessages.refundNotCompleted);
    expect(reloads).toEqual([]);
  });

  it('lets Ctrl, Cmd, Shift and middle clicks on the Helcim link open the canonical page normally', async () => {
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const { controller, navigations, requests } = modalController(
      dom,
      api,
      async () => jsonResponse(modalOrderOptions(42)),
    );
    await controller.start();
    const { doc } = modalParts(dom);
    const link = doc.querySelector('[data-ys-helcim-refund-order="42"]');
    const seen = [];
    // 在外掛之後登記：記錄外掛是否攔下，再擋掉 jsdom 不支援的跨文件導覽。
    link.addEventListener('click', (event) => {
      seen.push(event.defaultPrevented);
      event.preventDefault();
    });

    [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { button: 1 }].forEach((init) => {
      leftClick(dom, link, init);
    });

    expect(seen).toEqual([false, false, false, false]);
    expectModalOpen(dom, false);
    expect(navigations).toEqual([]);
    expect(requests).toHaveLength(1);
    expect(link.getAttribute('href')).toBe('https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds&order_id=42');
  });

  it.each([
    ['no modalEnabled flag', spaModalHtml, {}],
    ['a modalEnabled string other than the localized "1"', spaModalHtml, { modalEnabled: 'true' }],
    ['the localized false flag ("")', spaModalHtml, { modalEnabled: '' }],
    ['no modal container', spaHtml, { modalEnabled: true }],
    ['no modal container and the localized "1" flag', spaHtml, { modalEnabled: '1' }],
  ])('keeps the 1.1.2 navigation fallback with %s', async (_label, html, configOverrides) => {
    const { dom, api } = loadApi(
      html(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const navigations = [];
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      navigate: (url) => navigations.push(url),
      reload: () => {
        throw new Error('The fallback must not reload.');
      },
      fetch: async () => jsonResponse(modalOrderOptions(42)),
      config: { ...spaConfig(), ...configOverrides },
    });
    await controller.start();
    const doc = dom.window.document;
    const native = doc.querySelector('button.bulk-action-hide-only-mobile');
    native.hidden = false;
    const nativeClick = leftClick(dom, native);
    expect(nativeClick.defaultPrevented).toBe(true);
    expect(navigations).toEqual(['https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds&order_id=42']);

    const link = doc.querySelector('[data-ys-helcim-refund-order="42"]');
    const seen = [];
    link.addEventListener('click', (event) => {
      seen.push(event.defaultPrevented);
      event.preventDefault();
    });
    leftClick(dom, link);
    expect(seen).toEqual([false]);
    expect(controller.openModal(42)).toBe(false);
    const modal = doc.querySelector('#ys-helcim-refund-modal');
    if (modal) {
      expect(modal.hidden).toBe(true);
    }
    expect(doc.documentElement.classList.contains('ys-helcim-refund-modal-open')).toBe(false);
  });

  it('opens the modal from the configuration shape that wp_localize_script really prints', async () => {
    // WP_Scripts::localize() 把頂層純量全部轉成字串（true → "1"、false → ""、1500 → "1500"），
    // 巢狀的 messages／labels 才保留型別。照這個形狀放進頁面全域設定，走腳本自己的自動啟動。
    const url = 'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view';
    const dom = new JSDOM(spaModalHtml(), { url, runScripts: 'outside-only' });
    const requests = [];
    dom.window.fetch = async (requestUrl, options) => {
      requests.push({ url: requestUrl, method: options.method });
      return jsonResponse(modalOrderOptions(42));
    };
    dom.window.ysHelcimRefundAdminConfig = {
      ...spaConfig(),
      initialOrderId: null,
      pollIntervalMs: '1500',
      pollAttempts: '120',
      canResolve: '',
      autoStart: '1',
      modalEnabled: '1',
      messages: localizedMessages,
    };
    dom.window.eval(readFileSync(scriptPath, 'utf8'));
    const { doc } = modalParts(dom);
    await waitFor(dom, () => doc.querySelector('[data-ys-helcim-refund-order="42"]') !== null);

    const native = doc.querySelector('button.bulk-action-hide-only-mobile');
    expect(native.hidden).toBe(true);
    native.hidden = false;
    const nativeDialogs = [];
    native.addEventListener('click', () => nativeDialogs.push('fluentcart-refund-dialog'));
    const click = leftClick(dom, native);

    expect(click.defaultPrevented).toBe(true);
    expect(nativeDialogs).toEqual([]);
    expectModalOpen(dom, true);
    await waitFor(dom, () => doc.querySelector('#ys-helcim-refund-summary').textContent === 'Order #42 · USD');
    expect(doc.querySelector('#ys-helcim-refund-amount').value).toBe('21.00');
    expect(dom.window.location.href).toBe(url);
    expect(requests).toEqual([
      { url: 'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options', method: 'GET' },
      { url: 'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options', method: 'GET' },
    ]);

    pressKey(dom, doc.body, 'Escape');
    expectModalOpen(dom, false);
    const linkClick = leftClick(dom, doc.querySelector('[data-ys-helcim-refund-order="42"]'));
    expect(linkClick.defaultPrevented).toBe(true);
    expectModalOpen(dom, true);
  });

  it.each([
    ['the localized administrator flag ("1")', '1', true],
    ['the localized non-admin flag ("")', '', false],
  ])('shows the positive resolution controls in the modal only for %s', async (_label, canResolve, shown) => {
    // 1.1.0～1.1.2 的 JS 只認布林 true，但 canResolve 經 wp_localize_script 會變成 "1"，
    // 正式環境因此從來看不到 resolution 介面。1.1.3 起 "1" 與 true 一樣顯示，"" 仍隱藏；
    // inspect／commit 送出的請求不變，權限由伺服器的 resolution REST 路由把關。
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const candidate = '81177094';
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const { controller, requests } = modalController(dom, api, async (url) => (
      url.endsWith('/inspect')
        ? jsonResponse(positiveInspection(operationUuid, candidate, 'd'.repeat(64)))
        : jsonResponse(modalOrderOptions(42, {
          classification: 'blocked',
          transactions: [],
          resolution_operation: { operation_uuid: operationUuid, provider_action: 'refund' },
        }))
    ), {
      modalEnabled: '1',
      canResolve,
      labels: { nativeRefund: 'Refund', helcimRefund: 'Helcim Refund', blocked: 'Helcim refund is blocked.' },
    });
    await controller.start();
    const { doc, modal } = modalParts(dom);
    leftClick(dom, doc.querySelector('[data-ys-helcim-refund-order="42"]'));
    expectModalOpen(dom, true);
    await controller.whenIdle();

    const resolution = doc.querySelector('#ys-helcim-refund-resolution');
    expect(modal.contains(resolution)).toBe(true);
    expect(resolution.hidden).toBe(!shown);
    expect(doc.querySelector('#ys-helcim-refund-context').hidden).toBe(!shown);
    expect(doc.querySelector('#ys-helcim-refund-status').textContent).toBe(localizedMessages.refundBlocked);

    doc.querySelector('#ys-helcim-refund-resolution-candidate').value = candidate;
    await controller.inspectResolution();
    await controller.commitResolution();
    const posts = requests.filter((request) => request.options.method === 'POST');
    if (!shown) {
      expect(posts).toEqual([]);
      return;
    }
    expect(posts).toHaveLength(1);
    expect(posts[0].url).toBe(`https://shop.test/wp-json/ys-fc-pay/v1/refund-resolutions/${operationUuid}/inspect`);
    expect(posts[0].options.headers).toEqual({ 'Content-Type': 'application/json', 'X-WP-Nonce': 'rest-nonce' });
    expect(JSON.parse(posts[0].options.body)).toEqual({ candidate_transaction_id: candidate });
    expect(doc.querySelector('#ys-helcim-refund-resolution-evidence').hidden).toBe(false);
    // 還沒打確認句：commit 保持停用，不送出。
    expect(doc.querySelector('#ys-helcim-refund-resolution-commit').disabled).toBe(true);
  });

  it.each([
    ['an administrator (canResolve "1")', '1', true],
    ['a refund operator without manage_options (canResolve "")', '', false],
  ])('runs a modal refund, its polling and the resolution panel from the localized configuration for %s', async (_label, canResolve, shown) => {
    const url = 'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view';
    const candidate = '81177094';
    const challenge = 'e'.repeat(64);
    let operationUuid = null;
    let operationReads = 0;
    let committed = false;
    const { dom, doc, requests, delays } = bootLocalizedPage(
      spaModalHtml(),
      url,
      localizedConfig({ canResolve }),
      async (requestUrl, options) => {
        if (requestUrl.endsWith('/orders/42/refund-options')) {
          return jsonResponse(modalOrderOptions(42));
        }
        if (requestUrl.endsWith('/orders/42/refunds') && options.method === 'POST') {
          operationUuid = JSON.parse(options.body).operation_uuid;
          return jsonResponse(refundOperationRead(operationUuid, 'processing'), 202);
        }
        if (requestUrl.endsWith(`/refund-resolutions/${operationUuid}/inspect`)) {
          return jsonResponse(positiveInspection(operationUuid, candidate, challenge));
        }
        if (requestUrl.endsWith(`/refund-resolutions/${operationUuid}/commit`)) {
          committed = true;
          return jsonResponse({
            status: 'resolved',
            operation_uuid: operationUuid,
            remote_status: 'succeeded',
            replayed: false,
            local_recording_status: 'continued',
            local_status: 'applied',
          });
        }
        if (requestUrl.endsWith(`/refund-operations/${operationUuid}`)) {
          operationReads += 1;
          if (committed) {
            return jsonResponse(appliedRefund(operationUuid));
          }
          // 前 9 次仍在處理、第 10 次結果不明：超過預設的 8 次，證明 "120" 被當成 120 次。
          return jsonResponse(refundOperationRead(operationUuid, operationReads < 10 ? 'processing' : 'indeterminate'));
        }
        throw new Error(`Unexpected request: ${options.method} ${requestUrl}`);
      },
    );
    await waitFor(dom, () => doc.querySelector('[data-ys-helcim-refund-order="42"]') !== null);
    const native = doc.querySelector('button.bulk-action-hide-only-mobile');
    native.hidden = false;
    expect(leftClick(dom, native).defaultPrevented).toBe(true);
    expectModalOpen(dom, true);
    await waitFor(dom, () => doc.querySelector('#ys-helcim-refund-summary').textContent === 'Order #42 · USD');

    doc.querySelector('#ys-helcim-refund-amount').value = '5.00';
    doc.querySelector('#ys-helcim-refund-form').dispatchEvent(
      new dom.window.Event('submit', { bubbles: true, cancelable: true }),
    );
    const status = doc.querySelector('#ys-helcim-refund-status');
    await waitFor(dom, () => status.textContent === localizedMessages.providerOutcomeIndeterminate, 400);

    // 輪詢用的是頁面上的 "1500"／"120"：每次間隔 1500 ms，第 9 次之後仍繼續讀。
    expect(operationReads).toBe(10);
    expect(delays).toEqual(Array(9).fill(1500));
    expect(doc.querySelector('#ys-helcim-refund-submit').disabled).toBe(true);
    const resolution = doc.querySelector('#ys-helcim-refund-resolution');
    expect(resolution.hidden).toBe(!shown);

    doc.querySelector('#ys-helcim-refund-resolution-candidate').value = candidate;
    leftClick(dom, doc.querySelector('#ys-helcim-refund-resolution-inspect'));
    if (!shown) {
      await settle(dom);
      expect(requests.filter((request) => request.url.includes('/refund-resolutions/'))).toEqual([]);
      expect(resolution.hidden).toBe(true);
      return;
    }

    await waitFor(dom, () => doc.querySelector('#ys-helcim-refund-resolution-evidence').hidden === false);
    const inspect = requests.find((request) => request.url.endsWith('/inspect'));
    expect(inspect.options.method).toBe('POST');
    expect(inspect.options.headers).toEqual({ 'Content-Type': 'application/json', 'X-WP-Nonce': 'rest-nonce' });
    expect(JSON.parse(inspect.options.body)).toEqual({ candidate_transaction_id: candidate });

    const commit = doc.querySelector('#ys-helcim-refund-resolution-commit');
    expect(commit.disabled).toBe(true);
    const phrase = `RESOLVE ${operationUuid} WITH HELCIM ${candidate}`;
    typeInto(dom, doc.querySelector('#ys-helcim-refund-resolution-typed-phrase'), phrase);
    expect(commit.disabled).toBe(false);
    leftClick(dom, commit);
    await waitFor(dom, () => status.textContent === localizedMessages.refundCompleted, 400);
    const commitRequest = requests.find((request) => request.url.endsWith('/commit'));
    expect(commitRequest.options.method).toBe('POST');
    expect(commitRequest.options.headers).toEqual({ 'Content-Type': 'application/json', 'X-WP-Nonce': 'rest-nonce' });
    expect(JSON.parse(commitRequest.options.body)).toEqual({
      candidate_transaction_id: candidate,
      challenge,
      confirmation_phrase: phrase,
      parent_attestation: false,
    });
    expect(operationReads).toBe(11);
    expect(dom.window.location.href).toBe(url);
  });

  it.each([
    ['an administrator (canResolve "1")', '1', true],
    ['a refund operator without manage_options (canResolve "")', '', false],
  ])('loads the standalone page from the localized configuration for %s', async (_label, canResolve, shown) => {
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const candidate = '81177094';
    const { dom, doc, requests } = bootLocalizedPage(
      canonicalHtml(),
      'https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds&order_id=42',
      // 獨立頁：PHP 給 modalEnabled false（頁面上是 ""），訂單編號來自網址（頁面上是 "42"）。
      localizedConfig({ screen: 'canonical', initialOrderId: '42', modalEnabled: '', canResolve }),
      async (requestUrl) => (
        requestUrl.endsWith('/inspect')
          ? jsonResponse(positiveInspection(operationUuid, candidate, 'f'.repeat(64)))
          : jsonResponse(modalOrderOptions(42, {
            classification: 'blocked',
            transactions: [],
            resolution_operation: { operation_uuid: operationUuid, provider_action: 'refund' },
          }))
      ),
    );
    const status = doc.querySelector('#ys-helcim-refund-status');
    await waitFor(dom, () => status.textContent === localizedMessages.refundBlocked);
    expect(requests.map((request) => [request.options.method, request.url])).toEqual([
      ['GET', 'https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options'],
    ]);
    expect(doc.querySelector('#ys-helcim-refund-modal')).toBeNull();
    expect(doc.querySelector('#ys-helcim-refund-resolution').hidden).toBe(!shown);
    expect(doc.querySelector('#ys-helcim-refund-context').hidden).toBe(!shown);

    doc.querySelector('#ys-helcim-refund-resolution-candidate').value = candidate;
    leftClick(dom, doc.querySelector('#ys-helcim-refund-resolution-inspect'));
    if (shown) {
      await waitFor(dom, () => doc.querySelector('#ys-helcim-refund-resolution-evidence').hidden === false);
    } else {
      await settle(dom);
    }
    expect(requests.filter((request) => request.url.endsWith('/inspect'))).toHaveLength(shown ? 1 : 0);
  });

  it.each([
    ['localized integer strings', '10', '2500', 10, 2500],
    ['integers', 10, 2500, 10, 2500],
    ['the production values', '120', '1500', 120, 1500],
    ['exponent notation', '1e1', '2.5e3', 8, 1500],
    ['hexadecimal', '0xa', '0x9c4', 8, 1500],
    ['surrounding whitespace', ' 10', '2500 ', 8, 1500],
    ['signs and fractions', '+10', '2500.5', 8, 1500],
    ['leading zeros', '010', '02500', 8, 1500],
    ['values above the bounds', '601', '60001', 8, 1500],
    ['values below the bounds', '0', '249', 8, 1500],
    ['booleans', true, true, 8, 1500],
    ['the localized false value', '', '', 8, 1500],
    ['missing values', undefined, undefined, 8, 1500],
  ])('bounds the polling configuration given as %s', async (_label, pollAttempts, pollIntervalMs, attempts, interval) => {
    const { dom, api } = loadApi(canonicalHtml());
    const operationUuid = '00000000-0000-4000-8000-000000000001';
    const delays = [];
    let operationReads = 0;
    const config = {
      screen: 'canonical',
      restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
      restNonce: 'rest-nonce',
      initialOrderId: 42,
    };
    if (pollAttempts !== undefined) {
      config.pollAttempts = pollAttempts;
    }
    if (pollIntervalMs !== undefined) {
      config.pollIntervalMs = pollIntervalMs;
    }
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      uuid: () => operationUuid,
      sleep: async (milliseconds) => {
        delays.push(milliseconds);
      },
      fetch: async (url, options) => {
        if (url.endsWith('/refund-options')) {
          return jsonResponse(helcimOnlyOptions(42));
        }
        if (options.method === 'POST') {
          return jsonResponse(refundOperationRead(operationUuid, 'processing'), 202);
        }
        operationReads += 1;
        return jsonResponse(refundOperationRead(operationUuid, 'processing'));
      },
      config,
    });
    await controller.start();
    dom.window.document.querySelector('#ys-helcim-refund-amount').value = '1.00';

    await controller.submitRefund();

    expect(operationReads).toBe(attempts);
    expect(delays).toEqual(Array(attempts - 1).fill(interval));
    expect(dom.window.document.querySelector('#ys-helcim-refund-status').textContent)
      .toBe(localizedMessages.refundStillReconciling);
  });

  it.each([
    ['the localized order number', '42', 42],
    ['an integer', 42, 42],
    ['no order number (null)', null, null],
    ['the localized empty value', '', null],
    ['exponent notation', '4.2e1', null],
    ['a leading zero', '042', null],
    ['a negative number', '-42', null],
    ['surrounding whitespace', ' 42', null],
  ])('loads the standalone page order from %s only when it is a plain positive integer', async (_label, initialOrderId, orderId) => {
    const { dom, api } = loadApi(canonicalHtml());
    const requests = [];
    const controller = api.createController({
      window: dom.window,
      document: dom.window.document,
      fetch: async (url) => {
        requests.push(url);
        return jsonResponse(helcimOnlyOptions(42));
      },
      config: {
        screen: 'canonical',
        restRoot: 'https://shop.test/wp-json/ys-fc-pay/v1/',
        restNonce: 'rest-nonce',
        initialOrderId,
      },
    });

    await controller.start();

    expect(requests).toEqual(
      orderId === null ? [] : [`https://shop.test/wp-json/ys-fc-pay/v1/orders/${orderId}/refund-options`],
    );
  });

  it.each([
    ['the localized true value ("1")', '1', true],
    ['true', true, true],
    ['no autoStart key', undefined, true],
    ['the localized false value ("")', '', false],
    ['false', false, false],
    ['the string "true"', 'true', false],
  ])('starts the page automatically with autoStart set to %s only when enabled', async (_label, autoStart, started) => {
    const config = localizedConfig({ screen: 'canonical', initialOrderId: '42', modalEnabled: '' });
    if (autoStart === undefined) {
      delete config.autoStart;
    } else {
      config.autoStart = autoStart;
    }
    const { dom, requests } = bootLocalizedPage(
      canonicalHtml(),
      'https://shop.test/wp-admin/admin.php?page=ys-helcim-refunds&order_id=42',
      config,
      async () => jsonResponse(helcimOnlyOptions(42)),
    );
    if (started) {
      await waitFor(dom, () => requests.length === 1);
    } else {
      await settle(dom);
    }

    expect(requests.map((request) => request.url)).toEqual(
      started ? ['https://shop.test/wp-json/ys-fc-pay/v1/orders/42/refund-options'] : [],
    );
  });

  it('leaves the native action alone for mixed orders and still opens the modal from the Helcim link', async () => {
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const { controller, navigations } = modalController(
      dom,
      api,
      async () => jsonResponse(modalOrderOptions(42, { classification: 'mixed' })),
    );
    await controller.start();
    const { doc } = modalParts(dom);
    const native = doc.querySelector('button.bulk-action-hide-only-mobile');
    expect(native.hidden).toBe(false);
    const nativeClick = leftClick(dom, native);
    expect(nativeClick.defaultPrevented).toBe(false);
    expectModalOpen(dom, false);

    const linkClick = leftClick(dom, doc.querySelector('[data-ys-helcim-refund-order="42"]'));
    expect(linkClick.defaultPrevented).toBe(true);
    expectModalOpen(dom, true);
    await controller.whenIdle();
    expect(doc.querySelector('#ys-helcim-refund-context').hidden).toBe(false);
    expect(doc.querySelector('#ys-helcim-refund-transaction').value).toBe('7');
    expect(navigations).toEqual([]);
  });

  it('shows the blocking reason in the modal for blocked orders and keeps the Helcim sync available', async () => {
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const { controller, navigations } = modalController(
      dom,
      api,
      async () => jsonResponse(modalOrderOptions(42, {
        classification: 'blocked',
        blocker: 'provider_refund_pending',
      })),
      { labels: { nativeRefund: 'Refund', helcimRefund: 'Helcim Refund', blocked: 'Helcim refund is blocked.' } },
    );
    await controller.start();
    const { doc, modal } = modalParts(dom);
    const native = doc.querySelector('button.bulk-action-hide-only-mobile');
    expect(native.hidden).toBe(true);
    native.hidden = false;
    const click = leftClick(dom, native);
    expect(click.defaultPrevented).toBe(true);
    expectModalOpen(dom, true);
    await controller.whenIdle();

    const status = doc.querySelector('#ys-helcim-refund-status');
    expect(modal.contains(status)).toBe(true);
    expect(status.textContent).toBe(localizedMessages.providerRefundPending);
    expect(status.className).toContain('notice-warning');
    expect(doc.querySelector('#ys-helcim-refund-sync').hidden).toBe(false);
    expect(doc.querySelector('#ys-helcim-refund-sync-button').disabled).toBe(false);
    expect(doc.querySelector('#ys-helcim-refund-context').hidden).toBe(true);
    expect(navigations).toEqual([]);
  });

  it('keeps Tab focus inside the dialog and restores focus after closing', async () => {
    const { dom, api } = loadApi(
      spaModalHtml(),
      'https://shop.test/wp-admin/admin.php?page=fluent-cart#/orders/42/view',
    );
    const { controller } = modalController(dom, api, async () => jsonResponse(modalOrderOptions(42)));
    await controller.start();
    const { doc, dialog, close } = modalParts(dom);
    const link = doc.querySelector('[data-ys-helcim-refund-order="42"]');
    link.focus();
    expect(doc.activeElement).toBe(link);
    // 鍵盤 Enter 啟動連結也是 click（button 0）。
    leftClick(dom, link);
    await controller.whenIdle();
    expect(doc.activeElement).toBe(dialog);

    const submit = doc.querySelector('#ys-helcim-refund-submit');
    submit.focus();
    const forward = pressKey(dom, submit, 'Tab');
    expect(forward.defaultPrevented).toBe(true);
    expect(doc.activeElement).toBe(close);

    const backward = pressKey(dom, close, 'Tab', { shiftKey: true });
    expect(backward.defaultPrevented).toBe(true);
    expect(doc.activeElement).toBe(submit);

    dialog.focus();
    pressKey(dom, dialog, 'Tab', { shiftKey: true });
    expect(doc.activeElement).toBe(submit);

    // 中間的控制項照瀏覽器原本的 Tab 順序走，不攔。
    const amount = doc.querySelector('#ys-helcim-refund-amount');
    amount.focus();
    expect(pressKey(dom, amount, 'Tab').defaultPrevented).toBe(false);

    // 焦點被移到彈窗外（被遮住的訂單頁）時拉回對話框。
    const edit = doc.querySelectorAll('button.bulk-action-hide-only-mobile')[1];
    edit.focus();
    expect(doc.activeElement).toBe(dialog);
    expect(dialog.contains(doc.activeElement) || doc.activeElement === dialog).toBe(true);

    pressKey(dom, dialog, 'Escape');
    expectModalOpen(dom, false);
    expect(doc.activeElement).toBe(link);
    // 關閉後不再攔焦點。
    edit.focus();
    expect(doc.activeElement).toBe(edit);
  });
});
