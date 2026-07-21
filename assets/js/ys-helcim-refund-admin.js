(function (window, document) {
  'use strict';

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
    let resolutionOperation = null;
    let resolutionInspection = null;
    let indeterminateTerminal = false;
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
      'refundNotCompleted',
      'operationStatusUnreadable',
      'refundStillReconciling',
      'noOperationToReconcile',
      'readingDurableOperation',
      'invalidRefundIntent',
      'submittingRefund',
      'refundStatusUnknownNoRetry',
      'refundStatusUnknown',
      'refundOptionsLoadFailed',
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
      };
    }

    function setStatus(message, kind) {
      const status = runtimeDocument.querySelector('#ys-helcim-refund-status');
      if (!status) {
        return;
      }
      status.hidden = false;
      status.className = 'notice inline notice-' + (kind || 'info');
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
      [elements.evidenceStatus, elements.source, elements.action, elements.phrase].forEach((node) => {
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
      if (config.canResolve !== true || candidate === null) {
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
      if (
        payload.status !== 'confirmation_required'
        || !isUuid(payload.operation_uuid)
        || payload.operation_uuid.toLowerCase() !== expectedOperation.operationUuid
        || transactionId(payload.candidate_transaction_id) !== expectedCandidate
        || source === null
        || source === expectedCandidate
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
        || candidate !== resolutionInspection.candidateTransactionId
        || typedPhrase !== resolutionInspection.confirmationPhrase
        || attested !== resolutionInspection.parentAttestationRequired;
    }

    async function inspectResolution() {
      const elements = resolutionElements();
      if (config.canResolve !== true || !resolutionOperation || !elements.candidate || !elements.inspect) {
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

    async function commitResolution() {
      const elements = resolutionElements();
      updateResolutionCommitReadiness();
      if (
        config.canResolve !== true
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
        setStatus(message('refundBlocked'), 'warning');
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

    async function loadOptions(orderIdValue) {
      const orderId = positiveInteger(orderIdValue);
      if (orderId === null) {
        throw new Error(message('invalidOrderId'));
      }
      const payload = await requestJson(
        endpoint('orders/' + orderId + '/refund-options'),
        {
          method: 'GET',
          credentials: 'same-origin',
          headers: { 'X-WP-Nonce': String(config.restNonce || '') },
        },
      );
      const optionsPayload = normalizeOptions(payload, orderId);
      currentOperationUuid = null;
      indeterminateTerminal = false;
      const submit = runtimeDocument.querySelector('#ys-helcim-refund-submit');
      if (submit) {
        submit.disabled = false;
      }
      currentOptions = optionsPayload;
      renderCanonicalOptions(optionsPayload);
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

    function isProviderTerminal(operation) {
      return operation
        && ['declined', 'failed', 'canceled', 'expired'].includes(operation.remote_status);
    }

    function completeOperation(operation) {
      const submit = runtimeDocument.querySelector('#ys-helcim-refund-submit');
      const reconcile = runtimeDocument.querySelector('#ys-helcim-refund-reconcile');
      renderOperation(operation);
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
        setStatus(message('refundCompleted'), 'success');
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
        setStatus(message('refundNotCompleted'), 'error');
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

    async function pollOperation(uuid) {
      const attempts = positiveInteger(config.pollAttempts) || 8;
      const interval = positiveInteger(config.pollIntervalMs) || 1500;
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

    async function submitRefund() {
      const submit = runtimeDocument.querySelector('#ys-helcim-refund-submit');
      const reconcile = runtimeDocument.querySelector('#ys-helcim-refund-reconcile');
      if (submit && submit.disabled) {
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
          navigate(canonicalUrl(spaOrderId));
          return;
        }
        if (!runtimeDocument.querySelector('[data-ys-helcim-refund-notice]')) {
          injectSpaNotice(spaFailureMessage || message('requestFailed'), spaClassification, true);
        }
      }, true);
      runtimeWindow.addEventListener('hashchange', function () {
        activeTask = syncSpa();
      });

      const appRoot = runtimeDocument.querySelector('#fluent_cart_plugin_app');
      if (appRoot && typeof runtimeWindow.MutationObserver === 'function') {
        spaObserver = new runtimeWindow.MutationObserver(function () {
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
      spaOrderId = orderId;
      spaClassification = orderId === null ? 'none' : 'unresolved';
      spaFailureMessage = '';
      cleanupSpaEnhancement();
      if (orderId === null) {
        return null;
      }
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
      }
    }

    async function start() {
      if (config.screen === 'canonical') {
        bindCanonicalLookup();
      }
      if (config.screen === 'spa') {
        bindSpaEvents();
        return syncSpa();
      }
      if (config.screen === 'canonical' && positiveInteger(config.initialOrderId) !== null) {
        try {
          return await loadOptions(config.initialOrderId);
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
      syncSpa,
      whenIdle: function () {
        return activeTask;
      },
    };
  }

  const api = { createController };
  window.YSHelcimRefundAdmin = api;

  const config = window.ysHelcimRefundAdminConfig;
  if (config && config.autoStart !== false) {
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
