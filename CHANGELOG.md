# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
- **helcim.js card token consistency assertion**: charges always use the cardToken from the **hash-verified** response; if the front end sends a different card_token, the request is rejected (anti-tampering).
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
