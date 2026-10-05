# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.3] - 2026-10-05

### Changed

- **Refund** on a FluentCart order page now opens the Helcim refund panel in a dialog on that page instead of leaving it. The dialog has the same form, checks and actions as the **Helcim Refunds** page, including **Sync refunds from Helcim**, and the refund itself still goes to Helcim first, exactly as before. FluentCart's own refund dialog stays disabled for Helcim payments.
- The dialog closes with its close button, a click outside it, or Escape. It stays open while a refund, void, sync or reconciliation is still being processed and says so. When something was refunded, voided or synced, closing it reloads the order page so the payment status is current.
- Ctrl-click, Cmd-click, Shift-click or middle-click on **Helcim remote-first refund** still opens the full **Helcim Refunds** page in a new tab. That page and its menu entry under FluentCart remain available, for example to look up an order by its number.
- The Traditional Chinese (zh_TW) translation now covers the text that was still in English: the Helcim refund panel and dialog, **Resolve an indeterminate Helcim refund**, checkout and payment messages, payment settings, and error messages.

### Fixed

- Administrators now see **Resolve an indeterminate Helcim refund** in the Helcim refund panel, on the **Helcim Refunds** page and in the order-page dialog, when Helcim's result for a refund is unknown. Since 1.1.0 this section stayed hidden on live sites because the panel did not recognize the administrator setting the way WordPress delivers it to the page. Inspecting and committing the Helcim evidence works as before, the server still requires an administrator with FluentCart refund permission, and other refund staff still do not see the section.
- **Resolve an indeterminate Helcim refund** accepts only a Helcim refund or void on the same Helcim invoice as the order's original payment. Helcim's transaction records do not name the transaction they refund, so the administrator's attestation used to be the only link between the two, and a same-amount refund of another order could be recorded on this one. The server now requires both Helcim records to carry the same invoice number, and the panel shows the candidate's type, amount and Helcim invoice number before you attest. A payment whose Helcim record has no invoice number cannot be resolved this way; compare it with Helcim instead.
- The resolution also reads every Helcim transaction on that invoice and applies the rules **Sync refunds from Helcim** already uses: a refund that Helcim later voided, or a void while the invoice also lists a refund, is rejected, and so is any candidate when Helcim's list cannot be read completely.
- **Sync refunds from Helcim** is unavailable while a refund, void, reconciliation or resolution is still being processed, and a refund cannot be submitted while a sync runs. After a refund or void completes, a sync no longer re-enables **Submit Helcim refund**; reload the order to start another refund. Before, a sync that finished after the refund could re-enable the button, prefill the remaining amount and replace the result message, which invited a second refund.
- Status messages now appear in the order-page refund dialog: the refund result, error messages, the reminder that a request is still being processed, and why a refund is blocked. FluentCart removes WordPress notices when its pages load, and that also removed the dialog's message area. The **Helcim Refunds** page was not affected.

## [1.1.2] - 2026-10-04

### Fixed

- Inline card declines are recognized immediately. A production decline (`Transaction Declined: SUSPECTED FRAUD`) arrived as HTTP 500 carrying the declined transaction record itself (status `DECLINED`, its own transaction ID and the error text) instead of the documented `response: 0` body, so the synchronous purchase recorded the attempt as indeterminate and the shopper waited on "still being verified" until the webhook arrived. Both shapes are now definitive declines; the record shape only when its status, type, transaction ID, amount and currency match the exact charge, and any mismatch stays indeterminate.
- Inline decline detection is stricter. A Helcim response that contradicts itself (a decline code next to an approved transaction record, or a success code next to a declined one), or a declined transaction record that does not carry this payment attempt's invoice number, is no longer accepted as a decline. The attempt stays unconfirmed until Helcim's own records settle it, so a declined earlier attempt on the same order can never be mistaken for the current one.
- Opening an order from the FluentCart Orders list now hands its Refund button over to the Helcim refund workflow. FluentCart 1.6 switches to the order without reloading the page, so the takeover did not run: the native refund dialog stayed available, and submitting it ended with "Refund amount exceeds the maximum refundable amount for this transaction." Opening or reloading the order page directly was not affected.
- The native Refund button is now really hidden while the Helcim refund action replaces it; FluentCart's button styling kept both buttons visible.
- Clicking Refund while the plugin is still checking whether the order was paid through Helcim now says so and asks you to try again in a moment, instead of showing "Request failed."
- Refunds that Helcim rejects are explained precisely, each with its own error code, and only after the original payment and its settlement batch have been checked with Helcim. Before the payment settles, a partial refund explains that only the full amount can be cancelled. If the plugin cannot confirm that the payment is still unsettled, no void is sent and you are asked to try again in a few minutes. If the payment has already settled, the panel says that no money moved and points to **Sync refunds from Helcim** in case the payment was already refunded or voided in Helcim.
- **Reconcile** keeps the plain-language explanation when it reads back a refund that failed.
- The decline reason written to the order activity log is cleaned first: markup is removed, long card-like numbers are masked, and the text is shortened.
- The Helcim Refunds panel always shows the order you loaded last; a slower, earlier lookup can no longer replace it.

### Changed

- Card declines explain what to do next. Helcim's decline text is mapped to one actionable category (security code, expired card, invalid card number, insufficient funds, billing address, temporary processing problem, bank decline, store-side problem, or a generic decline) and every message states that no payment was taken. Fraud, lost/stolen and similar reasons are shown only as a bank decline. This applies to server-side inline purchase declines, Helcim.js verification declines, and HelcimPay.js window declines after the server has proven them; a Helcim.js configuration error still shows Helcim's own text.
- An inline or hash-verified hosted decline writes Helcim's decline reason and declined transaction ID to the order activity log ("Helcim declined a payment attempt") so the store can see why a customer was declined.
- The Helcim Refunds panel explains unsettled payments in plain language: a full refund before Helcim settles the batch is reported as a cancelled (voided) payment with no processing fee, a partial refund before settlement explains that only the full amount can be cancelled until the payment settles, and a completed refund states the usual 5–10 business days to reach the card.

### Added

- Refunds and voids made directly in the Helcim dashboard are now recorded in FluentCart from Helcim's own transaction records: automatically when the signed Helcim webhook arrives, or on demand with **Sync refunds from Helcim** in the Helcim Refunds panel for the order shown. Each Helcim transaction is recorded once. The refund reason the customer can see only names the Helcim transaction; the amount, the Helcim user and the time go to the order activity log.
- A Helcim record is written only when it matches the original payment's order, account, remaining refundable amount, and FluentCart's refund accounting. A void when Helcim also lists a refund for the same payment (for example a refund that was voided right after it was made), a refund that was later voided, or anything else that does not line up is reported for review in the panel and the error log instead of changing the order; a Helcim transaction list too long to check completely is retried later.
- If Helcim has completed a refund or void that FluentCart could not finish recording, new refunds for that order stay blocked, the Helcim Refunds panel explains why, and an administrator notice lists the order with the next step: correct the order accounting if needed, then select **Sync refunds from Helcim**.
- For orders paid before the plugin kept its payment journal, **Sync refunds from Helcim** explains that refunds or voids made in Helcim cannot be synced automatically and asks you to compare the order with Helcim before refunding.

## [1.1.1] - 2026-09-16

### Fixed

- A paid order no longer leaves the shopper on "Your payment result is still being verified. Do not submit another payment." Helcim delivers the purchase webhook within seconds, often before the synchronous purchase or browser confirmation finishes; the request that lost the durable compare-and-swap treated the identical persisted approval (or decline) as an unproven outcome. The purchase coordinator now converges on an identical persisted result in the synchronous purchase, webhook reconciliation, and lost-response paths. A different provider transaction ID is still never treated as convergence.
- Uncertain checkout results are resolved from server state instead of dead-ending the page. Both checkout runtimes now ask a read-only, transaction-token-bound status endpoint what happened: a paid order redirects to the receipt, a definitive no-charge result (no recorded attempt, declined, never-sent failure, closed expired window) lets the shopper try again, and only a result that stays unknown keeps the page locked. Because every charge attempt is journaled before any provider request, a pending transaction without an attempt provably had no charge.
- Abandoned hosted payment windows no longer become permanent administrator alarms or stranded orders. Once a HelcimPay.js session is past its 60-minute token life plus the 10-minute indexing grace, two authenticated empty lookups close it as `canceled`, free the purchase scope, and record an order note; the order stays unpaid (FluentCart's own gateways never auto-fail or auto-cancel abandoned payments). This runs in the recovery sweep even after automatic recovery paused, in **Check Helcim once**, and when the shopper returns to pay, which now opens a new payment window instead of asking them to contact the store. Exact late approval of a closed window still binds, and a conflicting second charge is persisted as a provider-ID mismatch for administrator review.
- Removed the empty-observation journal write whose unchanged-row update was reported by MySQL as zero affected rows and logged as `ys_helcim_journal_unavailable`.

### Changed

- This intentionally relaxes the 1.1.0 rule that a `canceled` checkout quarantined its transaction forever. Production evidence showed Helcim indexes transactions by invoice number within seconds (the invoice is created one second before the purchase) while the permanent quarantine stranded real shoppers. Legacy canceled rows that still hold the scope are released by the recovery sweep or the next checkout attempt.

## [1.1.0] - 2026-07-30

### Changed

- Promoted the independently reviewed rc.17 code and release artifact contract to 1.1.0 without changing payment runtime source.

## [1.1.0-rc.17] - 2026-07-29

### Fixed

- A concurrent webhook/recovery completion can no longer resurrect hosted checkout tokens and encrypted confirmation material on an already succeeded FluentCart transaction. Browser-session metadata is written and verified only while the exact transaction remains pending and unbound; terminal winners purge any stale material without disturbing a concurrent resume winner.
- An expired checkout with two empty provider reads is now quarantined as `canceled` while retaining its transaction scope; empty reads never authorize a successor. A durable purchase-family guard blocks Hosted or Inline successors for `created`, `processing`, `indeterminate`, `canceled`, and `succeeded` predecessors—including legacy scope-free rows—before any journal INSERT or second provider session. A successfully applied purchase also keeps its family reservation, so even a stale empty history snapshot still loses to the database UNIQUE constraint. Only definitive `declined`, never-sent `failed`, or pre-provider `expired` attempts may be followed by a new attempt.
- Exact late decline proof for a quarantined (`canceled`) checkout is now an acknowledged idempotent no-op through the purchase coordinator and webhook reconciler, preventing unnecessary provider retries while the transaction remains reserved for exact reconciliation.
- Every remotely succeeded purchase whose local state is still `pending`, `applying`, or `failed` remains visible in the administrator attention scan, including legacy rows whose active scope was already released. The succeeded fast-path also persists provider-ID mismatch evidence before returning.
- A scope-free remotely succeeded purchase can now resume the exact local binding directly, without another provider call. An already applied purchase that receives exact proof for a second provider transaction persists both IDs as an administrator-visible anomaly without downgrading the original local payment; a journal write failure returns retryable HTTP 503 so webhook redelivery can heal it.
- Canceled-checkout webhook acknowledgement now requires an exact declined outcome. Exact approved proof can no longer be suppressed by a canceled-shaped runtime result.
- Manual recovery of a quarantined checkout now verifies the exact purchase operation UUID and gateway before using that gateway's credentials.
- Provider-ID conflicts now show their persisted review detail in the administrator notice, use accurate scope-free/manual-review wording, and never offer a misleading automatic recovery action.
- README release-candidate metadata now follows the plugin header and is enforced by the release-package test.

### Added

- A host-agnostic deployment mtime gate plus integration coverage. Manual deployments must touch files after extraction; every deployed PHP file must meet the caller-provided epoch, and any file or directory symlink fails closed before runtime parity or browser evidence is accepted.
- Reproducible ZIP entries now use a source-commit-derived timestamp instead of the globally reused 1980 value. The manifest binds that timestamp to the source commit, preventing normal WordPress/Hub updates from repeatedly presenting OPcache with an unchanged archive mtime.

## [1.1.0-rc.16] - 2026-07-29

### Fixed

- A meta-restore failure during a session resume no longer releases the live Helcim session. The resume path now writes the browser session without any failure side effects; on error the blocker stays `processing` with its scope locked and the shopper gets the unresolved-attempt message, so a session that is still exposed at Helcim can never be marked failed by a local storage hiccup.
- The administrator's "Check Helcim once" action now works on a released (canceled) checkout: it bypasses the lease machinery (a released checkout owns no scope and no lease budget, so the paused-lease claim could never accept it and reported "not paused") and runs the read-only late-proof check directly.
- A provider-bound transaction id mismatch (remote money moved, local records point at a different charge) is now persisted as a durable `failed` local state with `provider_id_mismatch`, instead of evaporating with the request; both the released-checkout and the mismatch-failed shapes now surface in the administrator attention scan.
- Confirm-token rotation CAS now compares against the caller's own snapshot hash instead of a fresh re-read, closing the re-read race window; the canceled follow-up consume is an atomic compare-and-swap on the scheduled time, and both schedule and clear failures are logged, never swallowed.
- Envelope validation is strictly typed: non-string fields are refused before any cast, and a resume re-checks the fresh transaction is still pending and unbound before re-exposing the session.

### Added

- Tests: resume restore-failure keeps the session locked; malformed (non-string) envelope refused; stale-snapshot rotation loses the CAS; attention scan surfaces released and mismatch-failed operations; released-checkout manual check goes straight to recover.

## [1.1.0-rc.15] - 2026-07-29

### Fixed

- A resumed hosted checkout can now actually be confirmed. FluentCart wipes the transaction meta on every checkout retry, which silently destroyed the operation correlation and provider secret the confirmation service depends on; the resume envelope now carries the full material (checkout token AND provider secret), is operation-bound and self-describing, and the browser-session meta is rebuilt from it before the rotated confirm token is issued. A resumed payment confirms exactly like a fresh one; envelope material swapped in from another operation is refused untouched.
- Exact late approval proof now truly binds a released (canceled) checkout through every layer: the webhook reconciler state gate, bounded recovery eligibility, the purchase coordinator, and local binding. An empty read or a late decline leaves the released checkout untouched; each release also schedules exactly one automatic late-proof follow-up so an approval indexed after the release reads is still picked up without waiting for a webhook or an administrator.
- Confirm-token rotation is now a compare-and-swap on the previously observed hash, so two racing resume requests produce exactly one valid token.
- The 55-70 minute gray zone gets an honest message ("try again in about 15 minutes") instead of the generic wait-a-moment copy.

### Added

- Deterministic boundary tests at 54:59 / 55:00 / 69:59 / 70:00, an end-to-end resumed-payment confirmation test over wiped meta, material tamper/swap tests, and released-checkout late-proof tests across webhook, recovery, and coordinator layers.

## [1.1.0-rc.14] - 2026-07-29

### Fixed

- Replace the rc.13 close-and-release flow, which independent review proved unsafe: it could release an operation whose Helcim payment window was still open in another tab, allowing two provider sessions (and potentially two charges) for one transaction, with the first marked terminally failed and its late result unbindable.
- A blocked checkout now RESUMES the existing Helcim session instead: the same checkoutToken is re-exposed to the newest browser (Helcim permits at most one successful charge per token, so a second tab can never double-charge) after rotating the one-time confirm token so older tabs can no longer confirm. Closing the payment window simply re-enables the button; no release request is sent.
- Only a session past Helcim's own validity window may be released, after full identity/correlation verification and two authenticated charge-detection reads. Empty reads are treated as charge detection, never as proof of absence.
- A released checkout now becomes `canceled` instead of `failed`, and exact late approval proof still binds through the webhook resolver and purchase coordinator, so a pre-expiry charge can never become an unrecordable orphan.
- Removed the browser-triggered cancellation AJAX endpoint entirely; the release decision is now exclusively server-side and expiry-gated.

## [1.1.0-rc.13] - 2026-07-28

### Fixed

- Closing the hosted payment window no longer strands the checkout. The browser now asks the server to verify the closed attempt with Helcim; only after two consecutive authenticated reads prove no transaction exists is the attempt released, so the shopper can pay again immediately. Any found transaction, ambiguous response, or lookup failure keeps the original fail-closed lock.
- A checkout blocked by an abandoned earlier attempt (crashed browser, killed tab, or a leftover from before this release, including attempts that bounded recovery already marked indeterminate) now runs the same verify-then-release during payment initialization and retries once, instead of failing with "Another payment operation is already being reconciled." A blocker with any provider transaction stays locked and the shopper is told to contact the store.

### Changed

- The hosted checkout button no longer promises "credit card" only: it defaults to "Pay by card or Google Pay" when Google Pay is forced on, and "Continue to secure payment" otherwise, since the button opens Helcim's secure payment window.

## [1.1.0-rc.12] - 2026-07-26

### Fixed

- Sync the bundled YS Plugin Hub Client with the upstream fix that drops its WooCommerce HPOS compatibility declaration. The `before_woocommerce_init` hook is meaningless for a FluentCart-only plugin and was raising errors on sites without WooCommerce.

## [1.1.0-rc.11] - 2026-07-26

### Added

- Google Pay control for the hosted HelcimPay.js checkout, as a **Google Pay** setting on the *Credit card (Helcim)* gateway with three choices: use the Helcim account setting (default), always on, or always off. The default sends nothing, so existing sites keep whatever their Helcim account already does.

### Fixed

- The digital-wallet override is now serialized the way Helcim requires. `digitalWallet` must be a JSON-encoded **string**; sending a nested object is rejected with `HTTP 400 - digital Wallet must be a valid Non-empty String`, which prevents the checkout modal from opening at all.

## [1.1.0-rc.10] - 2026-07-26

### Changed

- Localized the bundled YS Plugin Hub Client (updater and marketplace admin UI) to English for the international market. All end-user-facing text — plugin-row description, admin menu and page titles, marketplace page, system-info/activity-log page, AJAX and installer messages, and JavaScript UI labels — now renders in English at source. The `ys-plugin-hub-client` textdomain, the payment/refund/recovery runtime, and the plugin's own translation catalog are unchanged, so the rc.8/rc.9 payment evidence still applies.

## [1.1.0-rc.9] - 2026-07-24

### Changed

- Generalize the public release-candidate evidence summary so the repository and release artifact do not name a specific test merchant account.
- Clarify that purchase operations which exhaust seven claimed automatic recovery attempts are intentionally retained and scope-locked. They are never auto-deleted or auto-failed from an empty result; exact signed-webhook/provider evidence or the administrator's read-only **Check Helcim once** action is required to resolve them.

### RC gate

- The rc.8 payment, decline/retry, webhook/replay, and refund/reverse evidence received independent review with no P0-P2 finding. This rc.9 candidate changes only release metadata and recovery operations documentation, and still requires a clean artifact plus post-deploy parity verification before promotion.

## [1.1.0-rc.8] - 2026-07-23

### Fixed

- Revalidate the current FluentCart refund accounting only after a refund operation owns the order scope and before any Helcim mutation. A stale or unavailable balance now fails closed, sends no provider request, records a terminal failed operation, and releases the scope for a fresh administrator retry.
- Bind the claimed refund to the original order-item quantity snapshot and revalidate it before the provider call. Removed items or reduced refundable quantities can no longer produce a remote refund followed by a stale local stock/accounting failure; large valid orders remain supported, and pre-send RC material v1 can resume only through a fresh server-owned context.
- Extend bounded purchase recovery to the Inline Helcim.js gateway. Recovery queries exact provider proof by the persisted operation UUID, never resends a purchase, keeps empty or ambiguous outcomes locked, and safely applies exact approvals or declines through the existing purchase coordinator.
- Make purchase recovery scan, lease, backoff, attention notices, and manual one-shot checks gateway-bound while preserving the Hosted compatibility entry points. Hosted and Inline each receive an independent bounded batch so one gateway cannot starve the other.
- Permit a capability- and nonce-protected read-only manual lookup for due or unscheduled attention rows, including attempt zero, without stealing an active lease or consuming the automatic retry budget.
- Require the Inline gateway to prove both the recurring recovery schedule and read-only card-transaction API access before card entry or order creation. Each automatic recovery row now receives a fresh full lease, and its backoff is calculated from the completed lookup time.
- Accept canonical numeric strings returned by `wpdb` in the new refund freshness gate while rejecting ambiguous, signed, fractional, or out-of-range transaction identifiers.
- Repair the documented release-builder default source-root path and cover direct Windows PowerShell invocation without an explicit `-SourceRoot`.

### RC gate

- An authorized Helcim Developer Test Account has current Inline, Hosted, signed-webhook, replay, decline, refund, reverse, and WordPress Cron evidence. This remains a pre-release until the rebuilt artifact is deployed and its final post-deploy browser regression and independent review are complete.

## [1.1.0-rc.7] - 2026-07-23

### Fixed

- While either Helcim method is enabled, serialize every fresh FluentCart checkout and every existing-order retry for the same cart before FluentCart can create or rewrite an order transaction. When both Helcim methods are disabled, fresh checkouts owned by another provider remain outside this plugin's scope.
- Reject payment-method changes once a Helcim transaction or durable purchase attempt exists. Journal-free retries are allowed only for the same gateway when the transaction is pending or failed and has no provider receipt.
- Treat Helcim's exact `POST payment/purchase` HTTP 400 `Card is not verified` response as a terminal pre-charge validation rejection. Near matches and responses with any contradictory or additional proof remain fail-closed and indeterminate.
- Add regression coverage for cross-provider retries, concurrent existing-order access, receipt/status inconsistencies, terminal validation replay, and fresh-token successor operations.

### RC gate

- This remains a pre-release until the approved, declined, replay, webhook, and refund/reverse gates pass in the authorized client test environment with its dedicated Developer Test Account credentials.

## [1.1.0-rc.6] - 2026-07-23

### Fixed

- Return the just-created order's billing street and postal code to the same checkout browser as the authoritative Helcim.js AVS source. This fixes normal saved-address checkout, where FluentCart exposes only an address id while its editor input remains empty.
- Resolve the live FluentCart editor case where duplicate address-field ids caused Helcim.js to read a hidden empty input instead of the populated editor. The browser now uses the latest non-empty editor only when the authoritative order AVS field is unavailable.
- Require a complete Helcim.js result surface before confirmation so an incomplete success response cannot strand a verified order in pending.
- Replace the incorrect field-concatenation hash check with Helcim's keyed full-XML `xmlHash` contract. Confirmation now accepts only `xml` plus `xmlHash`, authenticates the envelope before parsing, and extracts the card token exclusively from the verified XML.
- Document and enforce the Helcim.js configuration requirement to enable **Include XML on Response**.
- Add PHP and JSDOM regressions for both the normal selected-address flow and the duplicate-id editor structure, verifying the values observed by `helcimProcess()`.

### RC gate

- This remains a pre-release. Promotion still requires the full authorized client test environment approved, declined, refund/reverse, webhook, and replay gates.

## [1.1.0-rc.2] - 2026-07-23

### Fixed

- Refresh the Helcim.js AVS address and postal-code fields from the current FluentCart billing inputs immediately before tokenization. This prevents guest checkout details entered after the payment form renders from being submitted as stale or empty values.
- Add a browser-runtime regression test that reproduces the stale AVS field failure before the fix and proves the current billing values are supplied to Helcim.js.

### RC gate

- This remains a pre-release. Promotion still requires current approved, declined, replay, webhook, and duplicate-charge evidence from a dedicated Developer Test Account or an explicitly authorized live-card test.

## [1.1.0-rc.1] - 2026-07-22

Dual-gateway release candidate for the production-readiness architecture. This remains a pre-release until both browser flows and client test mode pass the documented gates.

### Added

- Durable purchase/refund operation journal with provider correlation, active-scope locking, persistent 36-character idempotency keys, and explicit remote/local state.
- Durable two-phase hosted HelcimPay.js initialization and confirmation. The server creates, atomically claims, and reads back the exact purchase operation before exposing a modal; confirmation requires a one-time token, provider hash, exact operation correlation, matching status/type/amount/currency, and a valid positive transaction ID for approval.
- Hosted lost-callback recovery with a five-minute positive-only lookup threshold, persisted seven-attempt backoff, lease-safe compare-and-swap claims, and a 70-minute checkout-material safety boundary. Empty collections never release the payment scope.
- A capability-gated administrator notice for unresolved hosted payments with a nonce-protected **Check Helcim once** action that does not reopen the automatic retry budget.
- Transaction-safe refund outbox with per-operation retry events and a bounded one-minute stale-claim recovery sweep.
- Remote-first refund administration under **FluentCart → Helcim Refunds**, including full/partial refunds, exact local accounting, historical-integrity blocking, and positive-only resolution of indeterminate provider outcomes.
- Narrow open-batch full-refund reverse fallback. A reverse is attempted only after fresh transaction and batch proof confirms the same approved purchase, amount, currency, batch ID, and `closed=false`.
- Clean signed webhook REST route at `/wp-json/ys-fc-pay/v1/events/card`, durable replay receipts, operation-bound correlation, API re-query, and lost-response purchase reconciliation.
- Mode-specific test/live webhook verifier storage and one-time migration of the legacy verifier field.
- Deterministic PowerShell release builder, sidecar SHA-256 manifest, independent package verifier, and executable package regression test.
- Deterministic PHP front-end translation-key contract check, translation-catalog generator, and executable POT/PO/MO completeness and compiled-table integrity tests; existing zh_TW translations are preserved while newly exposed messages receive an explicit fallback.

### Changed

- The Helcim.js inline flow now tokenizes in the browser and performs the v2 purchase through a claimed durable server operation. Provider success requires exact approval proof and a valid positive v2 transaction ID.
- The hosted HelcimPay.js flow now exposes a checkout session only after its durable operation is claimed. Callback replay resumes persisted success, while a lost browser response remains webhook-reconcilable without permitting a second active charge.
- Hosted recovery notices now show whether automatic checks are paused, the persisted attempt count, the next scheduled check when present, and the outcome of an administrator's one-shot manual check.
- Developer Test Account flows intentionally omit the legacy Helcim.js `test=1` field; FluentCart Order Mode selects the test credential set without requesting a demonstration token.
- A current-mode Webhook Verifier Token is mandatory for both gateways. Checkout fails closed when signed recovery is unavailable.
- Hosted checkout additionally fails closed when its recurring recovery event is unavailable or the current-mode API token cannot prove root-list read access to `GET /card-transactions`.
- The legacy FluentCart query webhook listener is retired. Configure the clean HTTPS REST route whose complete hostname/path does not contain the provider name.
- Native FluentCart Helcim refunds are vetoed because FluentCart 1.5.2 writes local refund state before the gateway confirms the remote outcome.
- The canonical refund page is registered independently and linked only after FluentCart builds its custom submenu, preserving the FluentCart dashboard target on FluentCart 1.5.2.
- A completed refund keeps the stale submit form locked until the administrator explicitly reloads current order options, preventing a fresh UUID from being sent from outdated refundable totals.
- Canonical refund side-effect payloads can be safely normalized across the REST builder, provider service, journal, and local recorder without rejecting their own version marker.
- Durable refund receipts compare exact JSON object key sets instead of insertion order, so MySQL JSON key canonicalization cannot strand an already successful provider refund.
- Refund-effect handlers safely normalize canonical integer strings returned by MySQL for outbox sequence columns while continuing to reject padded, malformed, or overflowing values.
- Open-batch reverse fallback recognizes Helcim's exact sanitized HTTP 400/422 `Card Transaction cannot be refunded` provider error in either scalar or field-map form; message-only and approximate errors remain ineligible.
- Refund retries resume local outbox effects without repeating a successful provider refund/reverse.
- Indeterminate purchases/refunds retain their scope lock until webhook or operator-reviewed provider evidence resolves them.
- Runtime initialization fails closed when schema installation, transactional storage, credential migration, or recurring recovery scheduling is unavailable.

### Security

- Added one-time purchase confirmation tokens and atomic hard claims for public confirmation endpoints.
- Added strict transaction ID, integer amount, currency, mode, provider action, and proof validation across purchase/refund/webhook flows.
- Added encrypted short-lived material handling and terminal purge behavior for reusable inline recovery tokens.
- Added package-time rejection of non-runtime paths, symlinks, non-deterministic timestamps, manifest/plugin version mismatches, development infrastructure markers, official test-card literals, and recognized secret formats embedded in text or binary runtime files.

### Operations

- WordPress Cron—or an external scheduler that runs due WordPress events at least once per minute—is now a release prerequisite for durable refund-effect and hosted lost-callback recovery.
- Release packages contain one forward-slash `ys-helcim-via-fluentcart/` root and only the strict runtime allowlist (`src`, `assets`, `languages`, shipped `vendor`, entry file, README, CHANGELOG, and LICENSE).

### RC gate

- `1.1.0-rc.1` includes both `ys_helcim` (durable hosted HelcimPay.js modal/digital-wallet path) and `ys_helcim_js` (durable inline form).
- Promote to final `1.1.0` only after both gateways pass real approved, declined, replay, lost-browser-response, webhook, active-scope, and duplicate-charge gates.
- The documentation, translations, manifest-verified artifact, deployed runtime, current-mode credentials, and client test-mode evidence must all agree before replacing the client site's installed gateway.

## [1.0.0] - 2026-07-03

Initial release: adds Helcim credit card payments to FluentCart (1.5.2+).

### Added

- **HelcimPay.js modal payment** (payment method `ys_helcim`): collects credit card payments through Helcim's hosted secure payment window, so the card never touches your store's pages. Uses a Paddle-style custom checkout button flow (create order → `helcim-pay/initialize` → front-end modal → confirm & verify → record payment).
- **helcim.js inline card form payment** (payment method `ys_helcim_js`): card fields are embedded in the checkout page, tokenized via Verify in the browser to obtain a cardToken, then charged **server-side** through the v2 `payment/purchase` endpoint, yielding a refundable v2 transaction ID.
- **Online refunds**: issue full or partial refunds for Helcim transactions from the FluentCart order page in wp-admin (v2 `payment/refund`); on success, FluentCart creates a refund transaction record. Refunds carry a **deterministic idempotency key** (bound to the original transaction ID + refund amount + existing refund count) to prevent duplicate refunds on retry.
- **Webhook (IPN) HMAC verification and reconciliation**: receives Helcim `cardTransaction` events, verifies the signature with Svix-style HMAC-SHA256, re-queries the API to confirm the transaction, and then reconciles payment for pending orders. Each payment method has its own Webhook URL and Verifier Token.
- **Two independently configured payment methods**: each mode extends FluentCart's `AbstractPaymentGateway` and is configured independently; test and live credentials are stored separately, and which set is used is determined by the FluentCart store's Order Mode (test/live), following the Stripe convention.
- **Currency gating**: for currencies other than USD/CAD, the payment methods don't appear at checkout; the helcim.js mode reports an unsupported-currency error while loading its payment block. Extensible via the `ys_helcim_fct_supported_currencies` filter.
- **Localized UI and copy**: admin settings, the checkout flow, and error messages are fully localized.
- **Debug logging with sensitive-data masking**: a toggleable debug log (via `error_log`, prefixed `[ys-helcim-fct]`); sensitive values — card number, CVV, cardToken, API Token, Secret, hash, cardholder name, approval code, billingAddress (PII), and more — are always masked before being written to the log; error-level messages are logged even when debug is off (payment errors are never silenced).

### Security

- **Fail-closed confirmation verification chain**: payment confirmation runs in a fixed order, rejects on any mismatched step, and never falsely marks a payment as successful:
  1. Load the transaction (restricted to this gateway's charge transactions, matched by an unguessable UUID)
  2. Idempotency check (if already successful, return the receipt page directly without reprocessing)
  3. Hash verification (`hash_equals` constant-time comparison; HelcimPay uses the secretToken, helcim.js uses the JS Secret Key; **a missing secret means rejection**)
  4. Transaction status `APPROVED` and type `purchase`
  5. Currency match
  6. Amount compared strictly in **integer cents** (`(int) round(amount * 100) === (int) transaction->total`)
- **Fixes known defects from the WooCommerce version** (relative to the existing `ys-helcim-gateway`):
  - Hash verification changed from "log only, don't block (fail-open)" to **fail-closed — reject if it doesn't verify**.
  - `payment/purchase` and `payment/refund` always send an `idempotency-key` header.
  - Amount comparison changed from floating-point tolerance to **strict integer-cent comparison**.
  - Added webhook reconciliation.
- **Encrypted secret storage**: API Token / JS Secret Key / Webhook Verifier Token are encrypted with FluentCart's `Helper::encryptKey` before being stored, and decrypted with `decryptKey` on read; corrupt ciphertext is always coerced to an empty string (fail-closed).
- **Webhook replay protection**: verification includes a ±5-minute timestamp tolerance check and strict base64 decoding; transaction IDs are filtered through a numeric-only allowlist; request bodies larger than 1MB are rejected outright.
- **Helcim.js authenticated token extraction**: the browser sends only the SDK `xml` and `xmlHash` proof envelope. Charges use the card token parsed exclusively from the keyed full-XML proof after it passes constant-time verification; sibling DOM token fields are never accepted as payment proof.
- Passed internal security review: **0 Critical / 0 High**.

### Technical Details

- Namespace `YangSheep\Helcim\FluentCart` (PSR-4, sub-namespaces `Support` / `HelcimPay` / `HelcimJs` / `Webhook`), minimum PHP 8.1.
- Depends on FluentCart 1.5.2 internal APIs (`AbstractPaymentGateway`, `BaseGatewaySettings`, `StatusHelper::syncOrderStatuses`, the `OrderTransaction` / `Order` models, `Helper::encryptKey/decryptKey`, `StoreSettings` order_mode, and more); these contracts must be re-verified when FluentCart is updated (see the checklist in `DEVELOPMENT.md`).
- Amounts are always stored and compared in cents, and converted to a decimal dollar string when sent to the Helcim API.
- Order status sync reuses FluentCart's `StatusHelper`, whose built-in atomic PAID transition prevents `OrderPaid` from being triggered more than once.

### Notes

- Helcim only supports the **USD** and **CAD** currencies.
- Helcim has no standalone sandbox environment; testing requires requesting Developer Test Account credentials from Helcim and using them with official test card numbers.
- This release was verified during development against mocked Helcim responses. **Before going live, please run one small real transaction with real credentials** (see the pre-launch checklist in `README.md`).
- **Not supported**: subscriptions, pre-authorization / capture (preauth/capture), and saved cards (the cardToken is already stored in the transaction meta for future extension).
