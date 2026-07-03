# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
