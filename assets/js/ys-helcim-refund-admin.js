(function (window, document) {
  'use strict';

  // 頁面設定由 wp_localize_script() 輸出，WP_Scripts::localize() 會把頂層純量全部轉成字串：
  // true → "1"、false → ""、1500 → "1500"（巢狀的 messages／labels 保留原型別）。
  // 真實頁面讀到的形狀和直接傳布林、數字的單元測試不同，所以頂層的布林與整數設定一律經過
  // 下面兩個函式，不在各處自己比較。

  // 布林旗標：只有 true 與 localize 後的 "1" 算開啟；其他值（包含字串 "true"）一律視為關閉。
  function configFlag(value) {
    return value === true || value === '1';
  }

  // 整數設定：接受整數，或 localize 後的純十進位數字字串（不接受正負號、空白、小數、指數、
  // 十六進位或前導零）。格式不符或超出 [minimum, maximum] 時回傳 fallback。
  function configInteger(value, minimum, maximum, fallback) {
    let parsed = null;
    if (typeof value === 'number') {
      parsed = value;
    } else if (typeof value === 'string' && /^(?:0|[1-9][0-9]{0,15})$/.test(value)) {
      parsed = Number(value);
    }
    return Number.isSafeInteger(parsed) && parsed >= minimum && parsed <= maximum ? parsed : fallback;
  }

  function createController(options) {
    const settings = options || {};
    const runtimeWindow = settings.window || window;
    const runtimeDocument = settings.document || document;
    const config = settings.config || {};
    const request = settings.fetch || runtimeWindow.fetch.bind(runtimeWindow);
    const generateUuid = settings.uuid || function () {
      return runtimeWindow.crypto.randomUUID();
    };
    const navigate = settings.navigate || function (url) {
      runtimeWindow.location.assign(url);
    };
    const sleep = settings.sleep || function (milliseconds) {
      return new Promise((resolve) => runtimeWindow.setTimeout(resolve, milliseconds));
    };
    // 彈窗關閉時若這次開啟期間資料有變，重新載入訂單頁（FluentCart 是 Vue SPA，不重載會顯示舊的付款狀態）。
    const reload = settings.reload || function () {
      runtimeWindow.location.reload();
    };
    let canonicalBound = false;
    let currentOptions = null;
    let currentOperationUuid = null;
    let activeTask = Promise.resolve(null);
    let spaBound = false;
    let spaOrderId = null;
    let spaClassification = 'none';
    let spaFailureMessage = '';
    let spaObserver = null;
    let spaMutationQueued = false;
    let spaSequence = 0;
    let spaPendingRequests = 0;
    let resolutionOperation = null;
    let resolutionInspection = null;
    let indeterminateTerminal = false;
    let syncOrderId = null;
    // 面板最近一次要求載入的訂單與載入序號：較早發出的載入或同步晚回來時，
    // 不可把畫面蓋回舊訂單（操作者可能因此對錯的訂單送出退款）。
    let requestedOrderId = null;
    let optionsSequence = 0;
    // 訂單頁退款彈窗：是否已綁定、是否開啟、顯示中的訂單、開啟前的焦點元素。
    let modalBound = false;
    let modalOpen = false;
    let modalOrderId = null;
    let modalReturnFocus = null;
    // 進行中的送出／輪詢／同步／resolution 流程數；大於 0 時彈窗不可關閉。
    let modalBusy = 0;
    // 這次開啟期間是否有退款／作廢記入、同步記錄或 resolution 成功；關閉時據此重新載入訂單頁。
    let modalChanged = false;
    // 會寫帳的流程（送出、輪詢、Reconcile、resolution commit）進行中的數量。大於 0 時不可按同步：
    // 同步結束時的重新載入會打開送出鈕、改寫狀態列，若退款同時完成，會推翻「退款完成後要重新載入訂單才能再送」。
    let refundBusy = 0;
    // 同步進行中：送出鈕與 resolution commit 停用，反向交錯（先同步、再送出）同樣擋住。
    let syncBusy = false;
    // 上次明確載入訂單之後已有退款／作廢記入（含 resolution）：同步之後的重新載入不可再打開送出鈕，
    // 必須由操作者明確重新載入訂單（查詢表單、重開彈窗）才能再送。
    let refundAppliedSinceLoad = false;
    const messageKeys = new Set([
      'restSameOrigin',
      'requestFailed',
      'invalidRefundOptions',
      'invalidCandidateTransactionId',
      'inspectingPositiveEvidence',
      'invalidPositiveEvidenceResponse',
      'positiveEvidenceInspected',
      'positiveEvidenceInspectionFailed',
      'committingPositiveResolution',
      'invalidPositiveResolutionResponse',
      'positiveResolutionCommitted',
      'positiveResolutionUnknown',
      'refundPageUnavailable',
      'noRefundableTransaction',
      'refundBlocked',
      'orderSummary',
      'refundOptionsLoaded',
      'invalidOrderId',
      'refundOptionsRequired',
      'refundFormUnavailable',
      'invalidRefundAmount',
      'operationLabel',
      'effectiveOperationLabel',
      'providerActionLabel',
      'remoteStatusLabel',
      'localStatusLabel',
      'notificationLabel',
      'effectStatusLabel',
      'warningsLabel',
      'errorCodeLabel',
      'providerOutcomeIndeterminate',
      'manualReconciliationRequired',
      'refundCompleted',
      'paymentVoided',
      'refundNotCompleted',
      'openBatchPartialRefund',
      'openBatchUnproven',
      'operationStatusUnreadable',
      'refundStillReconciling',
      'noOperationToReconcile',
      'readingDurableOperation',
      'invalidRefundIntent',
      'submittingRefund',
      'refundStatusUnknownNoRetry',
      'refundStatusUnknown',
      'refundOptionsLoadFailed',
      'classificationPending',
      'syncingProviderRefunds',
      'providerRefundsRecorded',
      'providerRefundsNothingNew',
      'providerRefundsNoHelcimPayment',
      'providerRefundsNeedReview',
      'providerRefundsRetryLater',
      'providerRefundsSyncFailed',
      'providerRefundsLegacyPayment',
      'batchClosedRefundRejected',
      'providerRefundPending',
      'refundModalBusy',
    ]);
    const labelKeys = new Set(['nativeRefund', 'helcimRefund', 'blocked']);

    function localizedString(source, key, allowlist, maximumLength) {
      if (
        !allowlist.has(key)
        || !source
        || typeof source !== 'object'
        || !Object.prototype.hasOwnProperty.call(source, key)
      ) {
        return '';
      }
      const value = source[key];
      return typeof value === 'string'
        && value.length > 0
        && value.length <= maximumLength
        && !/[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/.test(value)
        ? value
        : '';
    }

    function message(key, replacements) {
      let value = localizedString(config.messages, key, messageKeys, 1000);
      (Array.isArray(replacements) ? replacements : []).forEach((replacement, index) => {
        value = value.split('%' + (index + 1) + '$s').join(String(replacement));
      });
      return value;
    }

    function label(key) {
      return localizedString(config.labels, key, labelKeys, 200);
    }

    // 彈窗旗標：PHP 給的是布林 true，真實頁面上拿到的是 localize 後的 "1"。只接受這兩種形式，其他值一律退回換頁。
    function modalFlagEnabled() {
      return configFlag(config.modalEnabled);
    }

    // resolution 介面旗標：伺服器只對 manage_options 管理員給 true（頁面上是 "1"）。
    // 這個旗標只決定要不要顯示 inspect／commit 介面，不是權限邊界：resolution REST 路由
    // 自己檢查登入、REST nonce、manage_options 與 FluentCart orders/can_refund。
    function resolutionEnabled() {
      return configFlag(config.canResolve);
    }

    // 用計數器包住會寫入或等待帳務結果的非同步流程（try/finally），彈窗據此判斷能否關閉，
    // 不靠按鈕 disabled 狀態猜。巢狀呼叫（例如送出後輪詢）各自加減，不會提早歸零。
    function trackBusy(task) {
      return async function (...args) {
        modalBusy += 1;
        try {
          return await task(...args);
        } finally {
          modalBusy -= 1;
        }
      };
    }

    // 會寫帳的流程另外計數（巢狀呼叫各自加減），期間停用同步鈕。
    function trackRefundBusy(task) {
      return trackBusy(async function (...args) {
        refundBusy += 1;
        refreshSyncButton();
        try {
          return await task(...args);
        } finally {
          refundBusy -= 1;
          refreshSyncButton();
        }
      });
    }

    function positiveInteger(value) {
      const parsed = Number(value);
      return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
    }

    function centsToDecimal(cents) {
      return (cents / 100).toFixed(2);
    }

    function decimalToCents(value) {
      const normalized = String(value || '').trim();
      if (!/^\d+(?:\.\d{1,2})?$/.test(normalized)) {
        return null;
      }
      const parts = normalized.split('.');
      const cents = Number(parts[0]) * 100 + Number((parts[1] || '').padEnd(2, '0'));
      return Number.isSafeInteger(cents) && cents > 0 ? cents : null;
    }

    function isUuid(value) {
      return typeof value === 'string'
        && /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);
    }

    function transactionId(value) {
      const normalized = typeof value === 'string' ? value.trim() : '';
      return /^[1-9][0-9]*$/.test(normalized) && normalized.length <= 64
        ? normalized
        : null;
    }

    function providerAction(value) {
      return ['refund', 'reverse'].includes(value) ? value : null;
    }

    function normalizeResolutionOperation(value) {
      if (!value || typeof value !== 'object' || !isUuid(value.operation_uuid)) {
        return null;
      }
      const action = providerAction(value.provider_action);
      if (action === null) {
        return null;
      }
      return {
        operationUuid: value.operation_uuid.toLowerCase(),
        providerAction: action,
      };
    }

    function endpoint(path) {
      const root = new runtimeWindow.URL(String(config.restRoot || ''), runtimeWindow.location.href);
      if (root.origin !== runtimeWindow.location.origin) {
        throw new Error(message('restSameOrigin'));
      }
      root.pathname = root.pathname.replace(/\/?$/, '/');
      return new runtimeWindow.URL(path.replace(/^\//, ''), root).toString();
    }

    function spaRouteOrderId() {
      const match = String(runtimeWindow.location.hash || '').match(
        /^#\/orders\/(\d+)\/view(?:[/?]|$)/,
      );
      return match ? positiveInteger(match[1]) : null;
    }

    function canonicalUrl(orderId) {
      const url = new runtimeWindow.URL(String(config.adminPageUrl || ''), runtimeWindow.location.href);
      url.searchParams.set('order_id', String(orderId));
      return url.toString();
    }

    async function requestJson(url, requestOptions) {
      const response = await request(url, requestOptions);
      const body = await response.json();
      if (!response.ok) {
        const error = new Error(typeof body.message === 'string' ? body.message : message('requestFailed'));
        error.status = response.status;
        error.data = body;
        throw error;
      }
      return body;
    }

    function normalizeOptions(payload, expectedOrderId) {
      if (!payload || typeof payload !== 'object') {
        throw new Error(message('invalidRefundOptions'));
      }
      const orderId = positiveInteger(payload.order_id);
      const classifications = ['none', 'helcim_only', 'mixed', 'blocked'];
      if (orderId !== expectedOrderId || !classifications.includes(payload.classification)) {
        throw new Error(message('invalidRefundOptions'));
      }

      const transactions = Array.isArray(payload.transactions)
        ? payload.transactions.map((transaction) => ({
          id: positiveInteger(transaction.id),
          gateway: transaction.gateway,
          paymentMode: transaction.payment_mode,
          remaining: positiveInteger(transaction.remaining_refundable),
        })).filter((transaction) => (
          transaction.id !== null
          && transaction.remaining !== null
          && ['ys_helcim', 'ys_helcim_js'].includes(transaction.gateway)
          && ['test', 'live'].includes(transaction.paymentMode)
        ))
        : [];
      if (['helcim_only', 'mixed'].includes(payload.classification) && transactions.length === 0) {
        throw new Error(message('invalidRefundOptions'));
      }

      const items = Array.isArray(payload.items)
        ? payload.items.map((item) => ({
          id: positiveInteger(item.id),
          title: typeof item.title === 'string' ? item.title : '',
          quantity: positiveInteger(item.quantity),
          refundableQuantity: positiveInteger(item.refundable_quantity),
        })).filter((item) => item.id !== null && item.quantity !== null && item.refundableQuantity !== null)
        : [];

      const resolutionOperationPayload = normalizeResolutionOperation(payload.resolution_operation);

      return {
        orderId,
        classification: payload.classification,
        currency: typeof payload.currency === 'string' ? payload.currency : '',
        orderRemaining: positiveInteger(payload.order_remaining) || 0,
        transactions,
        items,
        resolutionOperation: resolutionOperationPayload,
        blocker: payload.blocker === 'provider_refund_pending' ? 'provider_refund_pending' : null,
      };
    }

    function setStatus(message, kind) {
      const status = runtimeDocument.querySelector('#ys-helcim-refund-status');
      if (!status) {
        return;
      }
      status.hidden = false;
      // 顯示訊息時才加上 WP 的 notice class（套用通知樣式、保留 notice-xxx 語意）。
      // 伺服器輸出與重設時都不可帶 notice：FluentCart 掛載時會移除所有 .notice:not(.fluent-cart) 節點。
      status.className = 'ys-helcim-refund-status notice inline notice-' + (kind || 'info');
      status.textContent = message;
    }

    function resolutionElements() {
      return {
        section: runtimeDocument.querySelector('#ys-helcim-refund-resolution'),
        candidate: runtimeDocument.querySelector('#ys-helcim-refund-resolution-candidate'),
        inspect: runtimeDocument.querySelector('#ys-helcim-refund-resolution-inspect'),
        evidence: runtimeDocument.querySelector('#ys-helcim-refund-resolution-evidence'),
        evidenceStatus: runtimeDocument.querySelector('#ys-helcim-refund-resolution-evidence-status'),
        source: runtimeDocument.querySelector('#ys-helcim-refund-resolution-source'),
        candidateType: runtimeDocument.querySelector('#ys-helcim-refund-resolution-candidate-type'),
        candidateAmount: runtimeDocument.querySelector('#ys-helcim-refund-resolution-candidate-amount'),
        invoice: runtimeDocument.querySelector('#ys-helcim-refund-resolution-invoice'),
        action: runtimeDocument.querySelector('#ys-helcim-refund-resolution-action'),
        confirmation: runtimeDocument.querySelector('#ys-helcim-refund-resolution-confirmation'),
        attestation: runtimeDocument.querySelector('#ys-helcim-refund-resolution-attestation'),
        phrase: runtimeDocument.querySelector('#ys-helcim-refund-resolution-phrase'),
        typedPhrase: runtimeDocument.querySelector('#ys-helcim-refund-resolution-typed-phrase'),
        commit: runtimeDocument.querySelector('#ys-helcim-refund-resolution-commit'),
      };
    }

    function clearResolutionInspection(keepCandidate) {
      resolutionInspection = null;
      const elements = resolutionElements();
      if (!keepCandidate && elements.candidate) {
        elements.candidate.value = '';
      }
      if (elements.evidence) {
        elements.evidence.hidden = true;
      }
      if (elements.confirmation) {
        elements.confirmation.hidden = true;
      }
      [
        elements.evidenceStatus,
        elements.source,
        elements.candidateType,
        elements.candidateAmount,
        elements.invoice,
        elements.action,
        elements.phrase,
      ].forEach((node) => {
        if (node) {
          node.textContent = '';
        }
      });
      if (elements.attestation) {
        elements.attestation.checked = false;
        elements.attestation.disabled = true;
        elements.attestation.required = false;
      }
      if (elements.typedPhrase) {
        elements.typedPhrase.value = '';
      }
      if (elements.commit) {
        elements.commit.disabled = true;
      }
    }

    function hideResolution() {
      const elements = resolutionElements();
      resolutionOperation = null;
      clearResolutionInspection(false);
      if (elements.section) {
        elements.section.hidden = true;
      }
    }

    function operationResolutionCandidate(operation) {
      if (!operation || operation.remote_status !== 'indeterminate') {
        return null;
      }
      const uuid = operationUuid(operation);
      const action = providerAction(operation.provider_action);
      return uuid && action ? { operationUuid: uuid, providerAction: action } : null;
    }

    function syncResolutionVisibility(operation) {
      const candidate = operationResolutionCandidate(operation)
        || (currentOptions && currentOptions.resolutionOperation)
        || null;
      if (candidate) {
        currentOperationUuid = candidate.operationUuid;
        indeterminateTerminal = true;
      }
      if (!resolutionEnabled() || candidate === null) {
        hideResolution();
        return false;
      }

      const elements = resolutionElements();
      if (!elements.section || !elements.candidate || !elements.inspect || !elements.commit) {
        hideResolution();
        return false;
      }
      if (!resolutionOperation || resolutionOperation.operationUuid !== candidate.operationUuid) {
        clearResolutionInspection(false);
      }
      resolutionOperation = candidate;
      elements.section.hidden = false;
      elements.inspect.disabled = false;
      const submit = runtimeDocument.querySelector('#ys-helcim-refund-submit');
      if (submit) {
        submit.disabled = true;
      }
      return true;
    }

    function normalizeResolutionInspection(payload, expectedOperation, expectedCandidate) {
      if (!payload || typeof payload !== 'object') {
        return null;
      }
      const source = transactionId(payload.source_transaction_id);
      const challenge = typeof payload.challenge === 'string' ? payload.challenge : '';
      const phrase = typeof payload.confirmation_phrase === 'string' ? payload.confirmation_phrase : '';
      // 伺服器從 Helcim 讀回、給操作者核對的候選交易資料；形狀不對就整筆不採用。
      const candidateAmount = payload.candidate_amount_cents;
      const invoice = typeof payload.invoice_number === 'string' ? payload.invoice_number : '';
      if (
        payload.status !== 'confirmation_required'
        || !isUuid(payload.operation_uuid)
        || payload.operation_uuid.toLowerCase() !== expectedOperation.operationUuid
        || transactionId(payload.candidate_transaction_id) !== expectedCandidate
        || source === null
        || source === expectedCandidate
        || providerAction(payload.candidate_type) !== expectedOperation.providerAction
        || !Number.isSafeInteger(candidateAmount)
        || candidateAmount <= 0
        || !['USD', 'CAD'].includes(payload.candidate_currency)
        || !/^[\x20-\x7e]{1,128}$/.test(invoice)
        || invoice.trim() !== invoice
        || payload.action !== 'resolve_positive'
        || typeof payload.parent_attestation_required !== 'boolean'
        || !/^(?:[a-f0-9]{2}){32,64}$/.test(challenge)
        || phrase.length < 1
        || phrase.length > 200
        || /[\u0000-\u001f\u007f]/.test(phrase)
      ) {
        return null;
      }
      return {
        operationUuid: expectedOperation.operationUuid,
        candidateTransactionId: expectedCandidate,
        sourceTransactionId: source,
        candidateType: payload.candidate_type,
        candidateAmountCents: candidateAmount,
        candidateCurrency: payload.candidate_currency,
        invoiceNumber: invoice,
        action: payload.action,
        status: payload.status,
        parentAttestationRequired: payload.parent_attestation_required,
        challenge,
        confirmationPhrase: phrase,
      };
    }

    function updateResolutionCommitReadiness() {
      const elements = resolutionElements();
      if (!elements.commit) {
        return;
      }
      const candidate = transactionId(elements.candidate && elements.candidate.value);
      const typedPhrase = elements.typedPhrase ? elements.typedPhrase.value : '';
      const attested = elements.attestation ? elements.attestation.checked : false;
      elements.commit.disabled = !resolutionInspection
        || syncBusy
        || candidate !== resolutionInspection.candidateTransactionId
        || typedPhrase !== resolutionInspection.confirmationPhrase
        || attested !== resolutionInspection.parentAttestationRequired;
    }

    async function inspectResolutionTask() {
      const elements = resolutionElements();
      if (!resolutionEnabled() || !resolutionOperation || !elements.candidate || !elements.inspect) {
        return null;
      }
      const candidate = transactionId(elements.candidate.value);
      if (candidate === null) {
        setStatus(message('invalidCandidateTransactionId'), 'error');
        return null;
      }

      clearResolutionInspection(true);
      elements.inspect.disabled = true;
      setStatus(message('inspectingPositiveEvidence'), 'info');
      try {
        const payload = await requestJson(
          endpoint('refund-resolutions/' + resolutionOperation.operationUuid + '/inspect'),
          {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'X-WP-Nonce': String(config.restNonce || ''),
            },
            body: JSON.stringify({ candidate_transaction_id: candidate }),
          },
        );
        const inspection = normalizeResolutionInspection(payload, resolutionOperation, candidate);
        if (inspection === null) {
          throw new Error(message('invalidPositiveEvidenceResponse'));
        }
        resolutionInspection = inspection;
        if (elements.evidenceStatus) {
          elements.evidenceStatus.textContent = inspection.status;
        }
        if (elements.source) {
          elements.source.textContent = inspection.sourceTransactionId;
        }
        if (elements.candidateType) {
          elements.candidateType.textContent = inspection.candidateType;
        }
        if (elements.candidateAmount) {
          elements.candidateAmount.textContent = centsToDecimal(inspection.candidateAmountCents)
            + ' ' + inspection.candidateCurrency;
        }
        if (elements.invoice) {
          elements.invoice.textContent = inspection.invoiceNumber;
        }
        if (elements.action) {
          elements.action.textContent = inspection.action;
        }
        if (elements.phrase) {
          elements.phrase.textContent = inspection.confirmationPhrase;
        }
        if (elements.evidence) {
          elements.evidence.hidden = false;
        }
        if (elements.confirmation) {
          elements.confirmation.hidden = false;
        }
        if (elements.attestation) {
          elements.attestation.disabled = !inspection.parentAttestationRequired;
          elements.attestation.required = inspection.parentAttestationRequired;
        }
        elements.inspect.disabled = false;
        updateResolutionCommitReadiness();
        setStatus(message('positiveEvidenceInspected'), 'warning');
        return inspection;
      } catch (error) {
        clearResolutionInspection(true);
        elements.inspect.disabled = false;
        setStatus(error && error.message ? error.message : message('positiveEvidenceInspectionFailed'), 'error');
        return null;
      }
    }
    // 檢查進行中也不可關閉彈窗：避免舊訂單的檢查結果晚回來，寫進下一張訂單的 resolution 區塊。
    const inspectResolution = trackBusy(inspectResolutionTask);

    async function commitResolutionTask() {
      const elements = resolutionElements();
      updateResolutionCommitReadiness();
      if (
        !resolutionEnabled()
        || !resolutionOperation
        || !resolutionInspection
        || !elements.commit
        || elements.commit.disabled
      ) {
        return null;
      }

      const operationUuidValue = resolutionOperation.operationUuid;
      const body = {
        candidate_transaction_id: resolutionInspection.candidateTransactionId,
        challenge: resolutionInspection.challenge,
        confirmation_phrase: resolutionInspection.confirmationPhrase,
        parent_attestation: resolutionInspection.parentAttestationRequired,
      };
      elements.commit.disabled = true;
      if (elements.inspect) {
        elements.inspect.disabled = true;
      }
      setStatus(message('committingPositiveResolution'), 'info');
      try {
        const result = await requestJson(
          endpoint('refund-resolutions/' + operationUuidValue + '/commit'),
          {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'X-WP-Nonce': String(config.restNonce || ''),
            },
            body: JSON.stringify(body),
          },
        );
        if (
          !result
          || result.status !== 'resolved'
          || result.operation_uuid !== operationUuidValue
          || result.remote_status !== 'succeeded'
        ) {
          throw new Error(message('invalidPositiveResolutionResponse'));
        }
        // resolution 已成功寫入：關閉彈窗時要重新載入訂單頁。
        modalChanged = true;
        resolutionInspection = null;
        if (currentOptions) {
          currentOptions.resolutionOperation = null;
        }
        setStatus(message('positiveResolutionCommitted'), 'info');
        return pollOperation(operationUuidValue);
      } catch (error) {
        setStatus(error && error.message ? error.message : message('positiveResolutionUnknown'), 'error');
        return null;
      }
    }
    const commitResolution = trackRefundBusy(commitResolutionTask);

    function renderCanonicalOptions(optionsPayload) {
      const context = runtimeDocument.querySelector('#ys-helcim-refund-context');
      const transactionSelect = runtimeDocument.querySelector('#ys-helcim-refund-transaction');
      const amount = runtimeDocument.querySelector('#ys-helcim-refund-amount');
      const items = runtimeDocument.querySelector('#ys-helcim-refund-items');
      const summary = runtimeDocument.querySelector('#ys-helcim-refund-summary');
      const form = runtimeDocument.querySelector('#ys-helcim-refund-form');
      if (!context || !transactionSelect || !amount || !items || !summary) {
        throw new Error(message('refundPageUnavailable'));
      }

      context.hidden = true;
      if (form) {
        form.hidden = false;
      }
      const resolutionVisible = syncResolutionVisibility(null);
      if (optionsPayload.classification === 'none') {
        setStatus(message('noRefundableTransaction'), 'info');
        if (resolutionVisible) {
          summary.textContent = message('orderSummary', [optionsPayload.orderId, optionsPayload.currency]);
          context.hidden = false;
          if (form) {
            form.hidden = true;
          }
        }
        return;
      }
      if (optionsPayload.classification === 'blocked') {
        // 被「Helcim 後台已退款、FluentCart 還沒記錄」擋住時，直接說原因並指向同步鈕。
        setStatus(
          message(optionsPayload.blocker === 'provider_refund_pending' ? 'providerRefundPending' : 'refundBlocked'),
          'warning',
        );
        if (resolutionVisible) {
          summary.textContent = message('orderSummary', [optionsPayload.orderId, optionsPayload.currency]);
          context.hidden = false;
          if (form) {
            form.hidden = true;
          }
        }
        return;
      }

      transactionSelect.replaceChildren();
      optionsPayload.transactions.forEach((transaction) => {
        const option = runtimeDocument.createElement('option');
        option.value = String(transaction.id);
        option.textContent = transaction.gateway + ' #' + transaction.id;
        option.dataset.remaining = String(transaction.remaining);
        transactionSelect.appendChild(option);
      });
      const syncSelectedAmount = function () {
        const selected = transactionSelect.selectedOptions[0];
        const remaining = selected ? positiveInteger(selected.dataset.remaining) : null;
        if (remaining !== null) {
          amount.value = centsToDecimal(remaining);
          amount.max = centsToDecimal(remaining);
        }
      };
      transactionSelect.onchange = syncSelectedAmount;
      syncSelectedAmount();

      items.replaceChildren();
      optionsPayload.items.forEach((item) => {
        const row = runtimeDocument.createElement('label');
        row.className = 'ys-helcim-refund-item-row';
        const checkbox = runtimeDocument.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'ys-helcim-refund-item';
        checkbox.value = String(item.id);
        row.appendChild(checkbox);
        row.appendChild(runtimeDocument.createTextNode(' ' + item.title));
        items.appendChild(row);
      });

      summary.textContent = message('orderSummary', [optionsPayload.orderId, optionsPayload.currency]);
      context.hidden = false;
      setStatus(message('refundOptionsLoaded'), 'success');
    }

    function syncElements() {
      return {
        section: runtimeDocument.querySelector('#ys-helcim-refund-sync'),
        button: runtimeDocument.querySelector('#ys-helcim-refund-sync-button'),
      };
    }

    function showSync(orderId) {
      const elements = syncElements();
      syncOrderId = orderId;
      if (elements.section) {
        elements.section.hidden = orderId === null;
      }
      refreshSyncButton();
    }

    // 同步鈕只在有已載入訂單、沒有寫帳流程、也沒有其他同步進行中時可按。
    function refreshSyncButton() {
      const button = syncElements().button;
      if (button) {
        button.disabled = syncOrderId === null || refundBusy > 0 || syncBusy;
      }
    }

    function normalizeSyncSummary(payload, expectedOrderId) {
      if (
        !payload
        || typeof payload !== 'object'
        || positiveInteger(payload.order_id) !== expectedOrderId
        || !['recorded', 'nothing_new', 'needs_review', 'retry_later', 'legacy_payment'].includes(payload.status)
      ) {
        return null;
      }
      const entries = function (name) {
        return Array.isArray(payload[name])
          ? payload[name].filter((entry) => entry && typeof entry === 'object')
          : [];
      };
      return {
        status: payload.status,
        helcimPayments: Number.isInteger(payload.helcim_payments) && payload.helcim_payments >= 0
          ? payload.helcim_payments
          : 0,
        recorded: entries('recorded'),
        review: entries('review'),
      };
    }

    function reviewReasons(entries) {
      const reasons = [];
      entries.forEach((entry) => {
        const reason = typeof entry.reason === 'string' && /^[a-z0-9_-]{1,100}$/.test(entry.reason)
          ? entry.reason
          : '';
        if (reason !== '' && !reasons.includes(reason)) {
          reasons.push(reason);
        }
      });
      return reasons.join(', ');
    }

    // 把店家在 Helcim 後台做的作廢／退款記回 FluentCart，完成後重新讀取訂單。
    // 與寫帳流程互斥：送出、輪詢、Reconcile、resolution commit 進行中不可同步；同步進行中送出鈕與 commit 停用。
    async function syncProviderRefundsTask() {
      const elements = syncElements();
      const orderId = syncOrderId;
      if (orderId === null || !elements.button || elements.button.disabled || refundBusy > 0 || syncBusy) {
        return null;
      }
      const submit = runtimeDocument.querySelector('#ys-helcim-refund-submit');
      const submitWasEnabled = Boolean(submit && !submit.disabled);
      const sequenceBefore = optionsSequence;
      syncBusy = true;
      if (submit) {
        submit.disabled = true;
      }
      updateResolutionCommitReadiness();
      try {
        return await syncProviderRefundsBody(orderId, elements);
      } finally {
        syncBusy = false;
        // 同步期間沒有重新載入（例如同步失敗）：送出鈕回到同步前的狀態；
        // 有重新載入時，送出鈕已由那次載入依最新選項設定，這裡不再改動。
        if (submit && submitWasEnabled && sequenceBefore === optionsSequence) {
          submit.disabled = false;
        }
        refreshSyncButton();
        updateResolutionCommitReadiness();
      }
    }

    async function syncProviderRefundsBody(orderId, elements) {
      elements.button.disabled = true;
      setStatus(message('syncingProviderRefunds'), 'info');

      let summary;
      try {
        const payload = await requestJson(
          endpoint('orders/' + orderId + '/sync-provider-refunds'),
          {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'X-WP-Nonce': String(config.restNonce || ''),
            },
            body: '{}',
          },
        );
        summary = normalizeSyncSummary(payload, orderId);
        if (summary === null) {
          throw new Error(message('providerRefundsSyncFailed'));
        }
      } catch (error) {
        if (requestedOrderId !== orderId) {
          // 同步進行中操作者已改載別張訂單：舊訂單的失敗不可蓋掉目前畫面。
          return null;
        }
        elements.button.disabled = false;
        setStatus(error && error.message ? error.message : message('providerRefundsSyncFailed'), 'error');
        return null;
      }

      if (summary.status === 'recorded' || summary.recorded.length > 0) {
        // Helcim 端的退款／作廢已記入 FluentCart：關閉彈窗時要重新載入訂單頁。
        // 部分成功（needs_review／retry_later 但 recorded 不是空的）也算：後端優先回報 review 與 retry，
        // 已經寫進 FluentCart 的那幾筆仍在 recorded 裡。
        modalChanged = true;
      }

      if (requestedOrderId !== orderId) {
        // 同步進行中操作者已改載別張訂單：結果屬於舊訂單，不重新載入、也不覆蓋狀態列。
        return null;
      }

      let text = message('providerRefundsNothingNew');
      let kind = 'info';
      if (summary.status === 'legacy_payment') {
        // 作業日誌上線前付款的訂單沒有作業紀錄可比對：請店家先到 Helcim 核對再退款。
        text = message('providerRefundsLegacyPayment');
        kind = 'warning';
      } else if (summary.status === 'needs_review') {
        text = message('providerRefundsNeedReview', [reviewReasons(summary.review)]);
        kind = 'error';
      } else if (summary.status === 'retry_later') {
        text = message('providerRefundsRetryLater');
        kind = 'warning';
      } else if (summary.status === 'recorded') {
        text = message('providerRefundsRecorded', [summary.recorded.length]);
        kind = 'success';
      } else if (summary.helcimPayments === 0) {
        text = message('providerRefundsNoHelcimPayment');
      }

      try {
        // 同步的重新載入不是操作者明確重新載入訂單：保留「已退款、要重新載入才能再送」的鎖。
        await loadOptions(orderId, true);
      } catch (error) {
        const context = runtimeDocument.querySelector('#ys-helcim-refund-context');
        if (context) {
          context.hidden = true;
        }
      }
      if (requestedOrderId !== orderId) {
        // 重新載入期間操作者又改載別張訂單：畫面已屬於那張訂單，不再寫同步結果。
        return null;
      }
      showSync(orderId);
      setStatus(text, kind);
      return summary;
    }
    const syncProviderRefunds = trackBusy(syncProviderRefundsTask);

    async function loadOptions(orderIdValue, keepRefundLatch) {
      const sequence = ++optionsSequence;
      showSync(null);
      const orderId = positiveInteger(orderIdValue);
      requestedOrderId = orderId;
      if (keepRefundLatch !== true) {
        // 明確重新載入訂單（查詢表單、開彈窗、初次載入）：解除「已退款、要重新載入才能再送」的鎖。
        refundAppliedSinceLoad = false;
      }
      if (orderId === null) {
        throw new Error(message('invalidOrderId'));
      }
      let optionsPayload;
      try {
        const payload = await requestJson(
          endpoint('orders/' + orderId + '/refund-options'),
          {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': String(config.restNonce || '') },
          },
        );
        optionsPayload = normalizeOptions(payload, orderId);
      } catch (error) {
        if (sequence !== optionsSequence) {
          // 之後又發出了別的載入：這個舊請求的失敗不可蓋掉新畫面。
          return null;
        }
        throw error;
      }
      if (sequence !== optionsSequence) {
        // 之後又發出了別的載入：舊結果直接丟棄，畫面以最後一次載入為準。
        return null;
      }
      currentOperationUuid = null;
      indeterminateTerminal = false;
      const submit = runtimeDocument.querySelector('#ys-helcim-refund-submit');
      if (submit) {
        submit.disabled = refundAppliedSinceLoad;
      }
      currentOptions = optionsPayload;
      renderCanonicalOptions(optionsPayload);
      showSync(orderId);
      return optionsPayload;
    }

    function refundIntent() {
      if (!currentOptions || !['helcim_only', 'mixed'].includes(currentOptions.classification)) {
        throw new Error(message('refundOptionsRequired'));
      }
      const transactionSelect = runtimeDocument.querySelector('#ys-helcim-refund-transaction');
      const amountInput = runtimeDocument.querySelector('#ys-helcim-refund-amount');
      const reasonInput = runtimeDocument.querySelector('#ys-helcim-refund-reason');
      if (!transactionSelect || !amountInput || !reasonInput) {
        throw new Error(message('refundFormUnavailable'));
      }

      const transactionId = positiveInteger(transactionSelect.value);
      const transaction = currentOptions.transactions.find((candidate) => candidate.id === transactionId);
      const amountCents = decimalToCents(amountInput.value);
      if (!transaction || amountCents === null || amountCents > transaction.remaining) {
        throw new Error(message('invalidRefundAmount'));
      }

      const itemIds = [];
      runtimeDocument.querySelectorAll('.ys-helcim-refund-item:checked').forEach((checkbox) => {
        const itemId = positiveInteger(checkbox.value);
        if (itemId === null || itemIds.includes(itemId)) {
          return;
        }
        itemIds.push(itemId);
      });

      return {
        operation_uuid: generateUuid(),
        transaction_id: transactionId,
        amount: centsToDecimal(amountCents),
        reason: reasonInput.value,
        item_ids: itemIds,
        manage_stock: false,
        refunded_items: [],
        cancel_subscription: false,
      };
    }

    function renderOperation(operation) {
      const container = runtimeDocument.querySelector('#ys-helcim-refund-operation');
      if (!container || !operation || typeof operation !== 'object') {
        return;
      }
      const fields = [
        [message('operationLabel'), operation.operation_uuid],
        [message('effectiveOperationLabel'), operation.effective_operation_uuid],
        [message('providerActionLabel'), operation.provider_action],
        [message('remoteStatusLabel'), operation.remote_status],
        [message('localStatusLabel'), operation.local_status],
        [message('notificationLabel'), operation.notification_status],
        [message('effectStatusLabel'), operation.effect_status],
        [message('warningsLabel'), Array.isArray(operation.warnings) ? operation.warnings.join(', ') : null],
        [message('errorCodeLabel'), operation.error_code],
      ];
      container.replaceChildren();
      fields.forEach(([label, value]) => {
        if (typeof value !== 'string' && typeof value !== 'number') {
          return;
        }
        const term = runtimeDocument.createElement('dt');
        const detail = runtimeDocument.createElement('dd');
        term.textContent = label;
        detail.textContent = String(value);
        container.append(term, detail);
      });
      container.hidden = false;
    }

    function operationUuid(operation) {
      if (isUuid(operation && operation.effective_operation_uuid)) {
        return operation.effective_operation_uuid.toLowerCase();
      }
      if (isUuid(operation && operation.operation_uuid)) {
        return operation.operation_uuid.toLowerCase();
      }
      return null;
    }

    function isApplied(operation) {
      return operation
        && operation.remote_status === 'succeeded'
        && operation.local_status === 'applied';
    }

    function refundFailureKey(errorCode) {
      if (errorCode === 'open_batch_partial_refund_unsupported') {
        return 'openBatchPartialRefund';
      }
      if (errorCode === 'open_batch_unproven') {
        return 'openBatchUnproven';
      }
      if (errorCode === 'batch_closed_refund_rejected') {
        return 'batchClosedRefundRejected';
      }
      return 'refundNotCompleted';
    }

    function isProviderTerminal(operation) {
      return operation
        && ['declined', 'failed', 'canceled', 'expired'].includes(operation.remote_status);
    }

    function completeOperation(operation) {
      const submit = runtimeDocument.querySelector('#ys-helcim-refund-submit');
      const reconcile = runtimeDocument.querySelector('#ys-helcim-refund-reconcile');
      renderOperation(operation);
      if (isApplied(operation)) {
        // 退款／作廢已在 Helcim 成功並記入 FluentCart：關閉彈窗時要重新載入訂單頁；
        // 之後的同步重新載入也不可打開送出鈕，要操作者明確重新載入訂單。
        modalChanged = true;
        refundAppliedSinceLoad = true;
      }
      if (operation && operation.remote_status === 'indeterminate') {
        indeterminateTerminal = true;
        syncResolutionVisibility(operation);
        setStatus(
          message('providerOutcomeIndeterminate'),
          'error',
        );
        if (submit) {
          submit.disabled = true;
        }
        if (reconcile) {
          reconcile.hidden = true;
          reconcile.disabled = false;
        }
        return true;
      }
      syncResolutionVisibility(operation);
      if (
        operation
        && (
          operation.manual_reconciliation_required === true
          || operation.effect_status === 'stock_reconciliation_required'
          || (operation.remote_status === 'succeeded' && operation.local_status === 'failed')
        )
      ) {
        setStatus(
          message('manualReconciliationRequired'),
          'error',
        );
        if (submit) {
          submit.disabled = true;
        }
        if (reconcile) {
          reconcile.hidden = true;
          reconcile.disabled = false;
        }
        return true;
      }
      if (isApplied(operation)) {
        setStatus(message(operation.provider_action === 'reverse' ? 'paymentVoided' : 'refundCompleted'), 'success');
        if (submit) {
          // The rendered refund options are now stale. Require an explicit
          // order reload before another intent can receive a fresh UUID.
          submit.disabled = true;
        }
        if (reconcile) {
          reconcile.hidden = true;
          reconcile.disabled = false;
        }
        return true;
      }
      if (
        operation
        && operation.retry_allowed === true
        && (isProviderTerminal(operation) || operation.remote_status === 'not_started')
      ) {
        setStatus(message(refundFailureKey(operation.error_code)), 'error');
        if (submit) {
          submit.disabled = indeterminateTerminal;
        }
        if (reconcile) {
          reconcile.hidden = true;
          reconcile.disabled = false;
        }
        return true;
      }
      return false;
    }

    async function pollOperationTask(uuid) {
      // 伺服器給 120 次 × 1500 ms（頁面上是 "120"／"1500"）；格式不符或超出範圍時用預設值。
      const attempts = configInteger(config.pollAttempts, 1, 600, 8);
      const interval = configInteger(config.pollIntervalMs, 250, 60000, 1500);
      const reconcile = runtimeDocument.querySelector('#ys-helcim-refund-reconcile');
      for (let attempt = 0; attempt < attempts; attempt += 1) {
        try {
          const operation = await requestJson(
            endpoint('refund-operations/' + uuid),
            {
              method: 'GET',
              credentials: 'same-origin',
              headers: { 'X-WP-Nonce': String(config.restNonce || '') },
            },
          );
          const nextUuid = operationUuid(operation);
          if (nextUuid) {
            currentOperationUuid = nextUuid;
          }
          if (completeOperation(operation)) {
            return operation;
          }
          renderOperation(operation);
        } catch (error) {
          setStatus(error && error.message ? error.message : message('operationStatusUnreadable'), 'error');
          if (reconcile) {
            reconcile.hidden = false;
            reconcile.disabled = false;
          }
          return null;
        }
        if (attempt + 1 < attempts) {
          await sleep(interval);
        }
      }
      setStatus(message('refundStillReconciling'), 'warning');
      if (reconcile) {
        reconcile.hidden = false;
        reconcile.disabled = false;
      }
      return null;
    }
    const pollOperation = trackRefundBusy(pollOperationTask);

    async function reconcileOperation() {
      const reconcile = runtimeDocument.querySelector('#ys-helcim-refund-reconcile');
      if (!isUuid(currentOperationUuid)) {
        setStatus(message('noOperationToReconcile'), 'error');
        return null;
      }
      if (reconcile) {
        reconcile.disabled = true;
      }
      setStatus(message('readingDurableOperation'), 'info');
      return pollOperation(currentOperationUuid);
    }

    async function submitRefundTask() {
      const submit = runtimeDocument.querySelector('#ys-helcim-refund-submit');
      const reconcile = runtimeDocument.querySelector('#ys-helcim-refund-reconcile');
      if (syncBusy || (submit && submit.disabled)) {
        return null;
      }

      let intent;
      try {
        intent = refundIntent();
      } catch (error) {
        setStatus(error && error.message ? error.message : message('invalidRefundIntent'), 'error');
        return null;
      }
      currentOperationUuid = intent.operation_uuid;
      if (submit) {
        submit.disabled = true;
      }
      if (reconcile) {
        reconcile.hidden = true;
      }
      setStatus(message('submittingRefund'), 'info');

      try {
        const result = await requestJson(
          endpoint('orders/' + currentOptions.orderId + '/refunds'),
          {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'X-WP-Nonce': String(config.restNonce || ''),
            },
            body: JSON.stringify(intent),
          },
        );
        const nextUuid = operationUuid(result);
        if (nextUuid) {
          currentOperationUuid = nextUuid;
        }
        if (completeOperation(result)) {
          return result;
        }
        renderOperation(result);
        if (currentOperationUuid) {
          return pollOperation(currentOperationUuid);
        }
        setStatus(message('refundStatusUnknownNoRetry'), 'error');
        if (reconcile) {
          reconcile.hidden = false;
        }
        return result;
      } catch (error) {
        if (error && error.data && typeof error.data === 'object') {
          const nextUuid = operationUuid(error.data);
          if (nextUuid) {
            currentOperationUuid = nextUuid;
          }
          if (completeOperation(error.data)) {
            return error.data;
          }
          renderOperation(error.data);
        }
        setStatus(error && error.message ? error.message : message('refundStatusUnknown'), 'error');
        if (reconcile) {
          reconcile.hidden = false;
        }
        return null;
      }
    }
    const submitRefund = trackRefundBusy(submitRefundTask);

    function bindCanonicalLookup() {
      if (canonicalBound) {
        return;
      }
      const lookup = runtimeDocument.querySelector('#ys-helcim-refund-order-lookup');
      const input = runtimeDocument.querySelector('#ys-helcim-refund-order-id');
      if (!lookup || !input) {
        return;
      }
      canonicalBound = true;
      lookup.addEventListener('submit', async function (event) {
        event.preventDefault();
        try {
          activeTask = loadOptions(input.value);
          await activeTask;
        } catch (error) {
          const context = runtimeDocument.querySelector('#ys-helcim-refund-context');
          if (context) {
            context.hidden = true;
          }
          setStatus(error && error.message ? error.message : message('refundOptionsLoadFailed'), 'error');
        }
      });
      const form = runtimeDocument.querySelector('#ys-helcim-refund-form');
      if (form) {
        form.addEventListener('submit', function (event) {
          event.preventDefault();
          activeTask = submitRefund();
        });
      }
      const reconcile = runtimeDocument.querySelector('#ys-helcim-refund-reconcile');
      if (reconcile) {
        reconcile.addEventListener('click', function (event) {
          event.preventDefault();
          activeTask = reconcileOperation();
        });
      }
      const syncButton = syncElements().button;
      if (syncButton) {
        syncButton.addEventListener('click', function (event) {
          event.preventDefault();
          activeTask = syncProviderRefunds();
        });
      }
      const resolution = resolutionElements();
      if (resolution.inspect) {
        resolution.inspect.addEventListener('click', function (event) {
          event.preventDefault();
          activeTask = inspectResolution();
        });
      }
      if (resolution.commit) {
        resolution.commit.addEventListener('click', function (event) {
          event.preventDefault();
          activeTask = commitResolution();
        });
      }
      if (resolution.candidate) {
        resolution.candidate.addEventListener('input', function () {
          if (
            resolutionInspection
            && transactionId(resolution.candidate.value) !== resolutionInspection.candidateTransactionId
          ) {
            clearResolutionInspection(true);
          }
          updateResolutionCommitReadiness();
        });
      }
      if (resolution.typedPhrase) {
        resolution.typedPhrase.addEventListener('input', updateResolutionCommitReadiness);
      }
      if (resolution.attestation) {
        resolution.attestation.addEventListener('change', updateResolutionCommitReadiness);
      }
    }

    function modalElements() {
      const modal = runtimeDocument.querySelector('#ys-helcim-refund-modal');
      return {
        modal,
        dialog: modal ? modal.querySelector('.ys-helcim-refund-modal__dialog') : null,
      };
    }

    // 對話框內可用 Tab 到達的控制項：排除停用、tabindex=-1、隱藏區塊內，以及彈窗內以 CSS 隱藏的訂單查詢表單。
    function modalFocusable(dialog) {
      return Array.from(dialog.querySelectorAll(
        'a[href], button, input, select, textarea, [tabindex]',
      )).filter((node) => (
        !node.disabled
        && node.type !== 'hidden'
        && node.getAttribute('tabindex') !== '-1'
        && !node.closest('[hidden], #ys-helcim-refund-order-lookup')
      ));
    }

    // Tab／Shift+Tab 在對話框內循環，焦點不會跑到被遮住的訂單頁。
    function trapModalFocus(event, dialog) {
      const focusable = modalFocusable(dialog);
      if (focusable.length === 0) {
        event.preventDefault();
        dialog.focus();
        return;
      }
      const active = runtimeDocument.activeElement;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey) {
        if (active === first || active === dialog || !dialog.contains(active)) {
          event.preventDefault();
          last.focus();
        }
        return;
      }
      if (active === last || !dialog.contains(active)) {
        event.preventDefault();
        first.focus();
      }
    }

    // 清掉上一張訂單留在彈窗裡的畫面與狀態，避免新訂單載入前看到（或送出）舊資料。
    function resetModalPanel() {
      currentOptions = null;
      currentOperationUuid = null;
      indeterminateTerminal = false;
      hideResolution();
      showSync(null);
      const status = runtimeDocument.querySelector('#ys-helcim-refund-status');
      if (status) {
        status.hidden = true;
        // 隱藏時回到伺服器輸出的 class，不帶 notice／error（理由見 setStatus()）。
        status.className = 'ys-helcim-refund-status inline';
        status.textContent = '';
      }
      const context = runtimeDocument.querySelector('#ys-helcim-refund-context');
      if (context) {
        context.hidden = true;
      }
      const operation = runtimeDocument.querySelector('#ys-helcim-refund-operation');
      if (operation) {
        operation.replaceChildren();
        operation.hidden = true;
      }
      const reconcile = runtimeDocument.querySelector('#ys-helcim-refund-reconcile');
      if (reconcile) {
        reconcile.hidden = true;
        reconcile.disabled = false;
      }
      ['#ys-helcim-refund-summary', '#ys-helcim-refund-transaction', '#ys-helcim-refund-items'].forEach((selector) => {
        const node = runtimeDocument.querySelector(selector);
        if (node) {
          node.replaceChildren();
        }
      });
      ['#ys-helcim-refund-amount', '#ys-helcim-refund-reason'].forEach((selector) => {
        const node = runtimeDocument.querySelector(selector);
        if (node) {
          node.value = '';
        }
      });
    }

    // 在訂單頁彈窗載入指定訂單的退款面板。彈窗不可用時回傳 false，由呼叫端退回換頁。
    function openModal(orderIdValue) {
      const orderId = positiveInteger(orderIdValue);
      const elements = modalElements();
      if (
        orderId === null
        || config.screen !== 'spa'
        || !modalFlagEnabled()
        || !modalBound
        || !elements.modal
        || !elements.dialog
      ) {
        return false;
      }
      if (modalOpen && modalBusy > 0) {
        // 處理中：不換訂單、不清畫面，只提醒等結果。
        setStatus(message('refundModalBusy'), 'warning');
        elements.dialog.focus();
        return true;
      }
      if (!modalOpen) {
        modalReturnFocus = runtimeDocument.activeElement;
        modalChanged = false;
      }
      modalOpen = true;
      modalOrderId = orderId;
      elements.modal.hidden = false;
      elements.modal.setAttribute('aria-hidden', 'false');
      runtimeDocument.documentElement.classList.add('ys-helcim-refund-modal-open');
      resetModalPanel();
      elements.dialog.focus();
      activeTask = (async function () {
        try {
          return await loadOptions(orderId);
        } catch (error) {
          const context = runtimeDocument.querySelector('#ys-helcim-refund-context');
          if (context) {
            context.hidden = true;
          }
          setStatus(error && error.message ? error.message : message('refundOptionsLoadFailed'), 'error');
          return null;
        }
      }());
      return true;
    }

    // 關閉彈窗。處理中拒絕關閉並提示；關閉後解除捲動鎖、還原焦點，這次開啟期間資料有變就重新載入訂單頁。
    function closeModal() {
      if (!modalOpen) {
        return true;
      }
      if (modalBusy > 0) {
        setStatus(message('refundModalBusy'), 'warning');
        return false;
      }
      const elements = modalElements();
      modalOpen = false;
      modalOrderId = null;
      if (elements.modal) {
        elements.modal.hidden = true;
        elements.modal.setAttribute('aria-hidden', 'true');
      }
      runtimeDocument.documentElement.classList.remove('ys-helcim-refund-modal-open');
      const returnFocus = modalReturnFocus;
      modalReturnFocus = null;
      if (returnFocus && returnFocus.isConnected && typeof returnFocus.focus === 'function') {
        returnFocus.focus();
      }
      if (modalChanged) {
        modalChanged = false;
        reload();
      }
      return true;
    }

    function bindModalEvents() {
      if (modalBound) {
        return;
      }
      const elements = modalElements();
      if (!elements.modal || !elements.dialog) {
        return;
      }
      modalBound = true;
      elements.modal.querySelectorAll('[data-ys-helcim-refund-modal-close]').forEach((control) => {
        control.addEventListener('click', function (event) {
          event.preventDefault();
          closeModal();
        });
      });
      // 鍵盤事件綁在 document（capture）：送出、同步、檢查等流程會停用剛按下的按鈕，
      // Chromium 會把焦點移到 <body>（不觸發 focusin），綁在彈窗節點上就收不到 Escape／Tab。
      // 彈窗關著時第一行就 return，不攔任何按鍵，其他外掛的對話框照常用 Escape 關閉。
      runtimeDocument.addEventListener('keydown', function (event) {
        if (!modalOpen) {
          return;
        }
        if (event.key === 'Escape' || event.key === 'Esc') {
          event.preventDefault();
          event.stopPropagation();
          closeModal();
          return;
        }
        if (event.key === 'Tab') {
          trapModalFocus(event, elements.dialog);
        }
      }, true);
      // 焦點陷阱的保險：彈窗開著時焦點若跑到彈窗外，拉回對話框。
      runtimeDocument.addEventListener('focusin', function (event) {
        const target = event.target;
        if (
          modalOpen
          && target
          && typeof target.nodeType === 'number'
          && !elements.modal.contains(target)
        ) {
          elements.dialog.focus();
        }
      });
    }

    function normalizedText(node) {
      return String(node && node.textContent ? node.textContent : '').replace(/\s+/g, ' ').trim();
    }

    function nativeRefundControl(target) {
      if (!target || typeof target.closest !== 'function') {
        return null;
      }
      const control = target.closest('.bulk-action-hide-only-mobile, .bulk-action-only-mobile');
      if (!control || control.hasAttribute('data-ys-helcim-refund-order')) {
        return null;
      }
      const nativeLabel = label('nativeRefund');
      if (nativeLabel === '') {
        return null;
      }
      return normalizedText(control) === nativeLabel ? control : null;
    }

    function cleanupSpaEnhancement() {
      runtimeDocument.querySelectorAll(
        '[data-ys-helcim-refund-order], [data-ys-helcim-refund-enhancement]',
      ).forEach((node) => node.remove());
      runtimeDocument.querySelectorAll('[data-ys-helcim-native-refund-hidden]').forEach((node) => {
        node.hidden = false;
        node.removeAttribute('aria-hidden');
        node.removeAttribute('data-ys-helcim-native-refund-hidden');
      });
    }

    function injectSpaButton(orderId, classification) {
      const buttonGroup = runtimeDocument.querySelector(
        '.fct-single-order-page .single-page-header .fct-btn-group.sm',
      );
      if (!buttonGroup || !['helcim_only', 'mixed', 'blocked'].includes(classification)) {
        return;
      }
      const linkLabel = label('helcimRefund');
      if (linkLabel === '') {
        return;
      }
      const link = runtimeDocument.createElement('a');
      link.className = 'button ys-helcim-refund-link bulk-action-hide-only-mobile';
      link.href = canonicalUrl(orderId);
      link.dataset.ysHelcimRefundOrder = String(orderId);
      link.dataset.ysHelcimRefundEnhancement = 'link';
      link.textContent = linkLabel;
      link.addEventListener('click', function (event) {
        // 一般左鍵點擊在訂單頁開彈窗；Ctrl／Cmd／Shift／Alt／中鍵交給瀏覽器照 href 開獨立頁面。
        if (
          event.defaultPrevented
          || event.button !== 0
          || event.ctrlKey
          || event.metaKey
          || event.shiftKey
          || event.altKey
        ) {
          return;
        }
        if (openModal(orderId)) {
          event.preventDefault();
        }
      });
      buttonGroup.insertBefore(link, buttonGroup.firstChild);
    }

    function injectSpaNotice(text, state, retryable) {
      const buttonGroup = runtimeDocument.querySelector(
        '.fct-single-order-page .single-page-header .fct-btn-group.sm',
      );
      if (!buttonGroup || text === '') {
        return;
      }
      const notice = runtimeDocument.createElement('span');
      notice.className = 'ys-helcim-refund-spa-notice';
      notice.dataset.ysHelcimRefundNotice = state;
      notice.dataset.ysHelcimRefundEnhancement = 'notice';
      notice.setAttribute('role', 'status');
      notice.textContent = text;
      if (retryable) {
        const retry = runtimeDocument.createElement('button');
        retry.type = 'button';
        retry.dataset.ysHelcimRefundRetry = 'true';
        retry.textContent = '↻ ' + message('requestFailed');
        retry.addEventListener('click', function (event) {
          event.preventDefault();
          activeTask = syncSpa();
        });
        notice.append(' ', retry);
      }
      buttonGroup.insertBefore(notice, buttonGroup.firstChild);
    }

    function applySpaClassification(orderId, classification, failureMessage) {
      cleanupSpaEnhancement();
      if (['helcim_only', 'blocked'].includes(classification)) {
        runtimeDocument.querySelectorAll(
          '.fct-single-order-page .single-page-header .fct-btn-group.sm .bulk-action-hide-only-mobile',
        ).forEach((control) => {
          if (!nativeRefundControl(control)) {
            return;
          }
          control.hidden = true;
          control.setAttribute('aria-hidden', 'true');
          control.setAttribute('data-ys-helcim-native-refund-hidden', 'true');
        });
      }
      if (classification === 'blocked') {
        injectSpaNotice(label('blocked'), 'blocked', false);
      }
      if (classification === 'error') {
        injectSpaNotice(failureMessage || message('requestFailed'), 'error', true);
      }
      injectSpaButton(orderId, classification);
    }

    function bindSpaEvents() {
      if (spaBound) {
        return;
      }
      spaBound = true;
      runtimeDocument.addEventListener('click', function (event) {
        if (spaOrderId === null) {
          return;
        }
        if (!nativeRefundControl(event.target)) {
          return;
        }
        if (!['helcim_only', 'blocked', 'unresolved', 'error'].includes(spaClassification)) {
          return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        if (['helcim_only', 'blocked'].includes(spaClassification)) {
          // 優先在訂單頁開外掛彈窗；彈窗不可用時退回換頁到獨立面板。原生退款視窗一律不開。
          if (!openModal(spaOrderId)) {
            navigate(canonicalUrl(spaOrderId));
          }
          return;
        }
        if (spaClassification === 'unresolved') {
          // 分類還在確認中：擋下原生退款並說明「確認中」，不是請求失敗；
          // 若目前沒有進行中的分類查詢（例如路由剛換但還沒重新查），補觸發一次。
          if (spaPendingRequests === 0) {
            activeTask = syncSpa();
          }
          if (!runtimeDocument.querySelector('[data-ys-helcim-refund-notice]')) {
            injectSpaNotice(message('classificationPending'), 'pending', false);
          }
          return;
        }
        if (!runtimeDocument.querySelector('[data-ys-helcim-refund-notice]')) {
          injectSpaNotice(spaFailureMessage || message('requestFailed'), spaClassification, true);
        }
      }, true);
      runtimeWindow.addEventListener('hashchange', function () {
        // 瀏覽器上一頁／下一頁會先發 popstate 再發 hashchange：與 popstate 用同一個守衛，
        // 只在路由上的訂單真的改變時重新分類，同一次導覽只送一個 refund-options 請求。
        if (spaRouteOrderId() !== spaOrderId) {
          activeTask = syncSpa();
        }
      });
      runtimeWindow.addEventListener('popstate', function () {
        if (spaRouteOrderId() !== spaOrderId) {
          activeTask = syncSpa();
        }
      });

      const appRoot = runtimeDocument.querySelector('#fluent_cart_plugin_app');
      if (appRoot && typeof runtimeWindow.MutationObserver === 'function') {
        spaObserver = new runtimeWindow.MutationObserver(function () {
          // FluentCart 1.6.x 從訂單列表進訂單時用 history.pushState 換 hash，
          // 不會觸發 hashchange；路由變化只能在 Vue 重繪 DOM 時比對得知。
          if (spaRouteOrderId() !== spaOrderId) {
            activeTask = syncSpa();
            return;
          }
          if (spaMutationQueued || spaOrderId === null) {
            return;
          }
          spaMutationQueued = true;
          Promise.resolve().then(function () {
            spaMutationQueued = false;
            if (spaNeedsApply()) {
              applySpaClassification(spaOrderId, spaClassification);
            }
          });
        });
        spaObserver.observe(appRoot, { childList: true, subtree: true });
      }
    }

    function spaNeedsApply() {
      if (spaOrderId === null) {
        return false;
      }
      if (spaClassification === 'helcim_only') {
        const controls = Array.from(runtimeDocument.querySelectorAll(
          '.fct-single-order-page .single-page-header .fct-btn-group.sm .bulk-action-hide-only-mobile',
        )).filter((control) => nativeRefundControl(control));
        return controls.some((control) => !control.hidden)
          || !runtimeDocument.querySelector('[data-ys-helcim-refund-order="' + spaOrderId + '"]');
      }
      if (spaClassification === 'mixed') {
        return !runtimeDocument.querySelector('[data-ys-helcim-refund-order="' + spaOrderId + '"]');
      }
      if (spaClassification === 'blocked') {
        const controls = Array.from(runtimeDocument.querySelectorAll(
          '.fct-single-order-page .single-page-header .fct-btn-group.sm .bulk-action-hide-only-mobile',
        )).filter((control) => nativeRefundControl(control));
        return controls.some((control) => !control.hidden)
          || !runtimeDocument.querySelector('[data-ys-helcim-refund-notice]')
          || !runtimeDocument.querySelector('[data-ys-helcim-refund-order="' + spaOrderId + '"]');
      }
      if (spaClassification === 'error') {
        return !runtimeDocument.querySelector('[data-ys-helcim-refund-notice]');
      }
      return false;
    }

    async function syncSpa() {
      const sequence = ++spaSequence;
      const orderId = spaRouteOrderId();
      if (modalOpen && modalBusy === 0 && orderId !== modalOrderId) {
        // 路由換到別張訂單（或離開訂單頁）：關掉閒置中的彈窗；處理中則不動它。
        closeModal();
      }
      spaOrderId = orderId;
      spaClassification = orderId === null ? 'none' : 'unresolved';
      spaFailureMessage = '';
      cleanupSpaEnhancement();
      if (orderId === null) {
        return null;
      }
      spaPendingRequests += 1;
      try {
        const payload = await requestJson(
          endpoint('orders/' + orderId + '/refund-options'),
          {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': String(config.restNonce || '') },
          },
        );
        const optionsPayload = normalizeOptions(payload, orderId);
        if (sequence !== spaSequence || spaRouteOrderId() !== orderId) {
          return null;
        }
        spaOrderId = orderId;
        spaClassification = optionsPayload.classification;
        spaFailureMessage = '';
        applySpaClassification(orderId, optionsPayload.classification);
        return optionsPayload;
      } catch (error) {
        if (sequence !== spaSequence || spaRouteOrderId() !== orderId) {
          return null;
        }
        spaOrderId = orderId;
        spaClassification = 'error';
        spaFailureMessage = error && error.message ? error.message : message('requestFailed');
        applySpaClassification(orderId, spaClassification, spaFailureMessage);
        return null;
      } finally {
        spaPendingRequests -= 1;
      }
    }

    async function start() {
      if (config.screen === 'canonical') {
        bindCanonicalLookup();
      }
      if (config.screen === 'spa') {
        if (modalFlagEnabled() && runtimeDocument.querySelector('#ys-helcim-refund-modal')) {
          // 彈窗裡的面板在頁面載入時就存在：表單、同步、resolution 的事件綁一次即可。
          // 面板事件沒綁成功就不啟用彈窗（點 Refund 退回換頁），避免表單以原生方式送出。
          bindCanonicalLookup();
          if (canonicalBound) {
            bindModalEvents();
          }
        }
        bindSpaEvents();
        return syncSpa();
      }
      // 獨立頁網址帶的訂單編號：頁面上是字串（例如 "42"），沒帶時是 null。
      const initialOrderId = configInteger(config.initialOrderId, 1, Number.MAX_SAFE_INTEGER, null);
      if (config.screen === 'canonical' && initialOrderId !== null) {
        try {
          return await loadOptions(initialOrderId);
        } catch (error) {
          const context = runtimeDocument.querySelector('#ys-helcim-refund-context');
          if (context) {
            context.hidden = true;
          }
          setStatus(error && error.message ? error.message : message('refundOptionsLoadFailed'), 'error');
          return null;
        }
      }
      return null;
    }

    return {
      start,
      loadOptions,
      submitRefund,
      reconcile: reconcileOperation,
      inspectResolution,
      commitResolution,
      syncProviderRefunds,
      syncSpa,
      openModal,
      closeModal,
      whenIdle: function () {
        return activeTask;
      },
    };
  }

  const api = { createController };
  window.YSHelcimRefundAdmin = api;

  const config = window.ysHelcimRefundAdminConfig;
  // autoStart 預設開啟：沒有這個鍵時照舊自動啟動；有給值時只認 true 與 "1"。
  // localize 後的 false 是 ""，原本的 `!== false` 會把它當成啟動。
  if (
    config
    && typeof config === 'object'
    && (!Object.prototype.hasOwnProperty.call(config, 'autoStart') || configFlag(config.autoStart))
  ) {
    const start = function () {
      createController({ window, document, config }).start();
    };
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
      start();
    }
  }
})(window, document);
