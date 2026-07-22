# YS Helcim via FluentCart

Helcim payment integration for FluentCart with durable payment operations, remote-first refunds, and signed webhook recovery.

> **Release candidate — 1.1.0-rc.2**
>
> This is the dual-gateway v1.1.0 candidate: the hosted HelcimPay.js modal and the Helcim.js inline form are both registered only when their current-mode credentials and the shared durable recovery runtime are available. RC status still means pre-release; promote it only after both browser flows pass the release gates below on the development and client test environments.

## Payment methods

| Payment method | Collection mode | v1.1.0-rc.2 status |
|---|---|---|
| **Credit card (Helcim)** (`ys_helcim`) | HelcimPay.js hosted modal; lowest PCI scope and the path for supported digital wallets | Registered for RC testing through the durable two-phase hosted coordinator |
| **Credit card (Helcim inline form)** (`ys_helcim_js`) | Helcim.js Verify tokenization in the browser, followed by a server-side v2 purchase | Registered for RC testing when all current-mode credentials and recovery prerequisites are present |

Both payment methods use the same safety invariants: a durable operation is claimed before the hosted session can be exposed or an inline purchase can be sent, provider success requires a valid transaction ID and exact proof, and a lost response remains reconcilable without creating a second charge.

## Requirements

| Item | Requirement |
|---|---|
| WordPress | 6.0 or later |
| FluentCart | 1.5.2 or later |
| PHP | 8.1 or later |
| Currency | USD or CAD |
| Database | Transaction-safe InnoDB tables for FluentCart and the plugin operation tables |
| HTTPS | Required for inline card entry and webhook delivery |
| Scheduled jobs | Working WordPress Cron or an equivalent server cron that runs due WordPress events at least once per minute |
| Helcim | A production account or dedicated Developer Test Account with the required API, Helcim.js, and webhook credentials; the hosted API token must permit `GET /card-transactions` |

The plugin fails closed when FluentCart is unavailable, storage is not transaction-safe, required credentials are missing, or durable recovery cannot be scheduled.

## Installation

1. Install the release ZIP so WordPress creates `/wp-content/plugins/ys-helcim-via-fluentcart/`.
2. Activate **YS Helcim via FluentCart** while FluentCart is active.
3. Confirm the operation, outbox, webhook receipt, and refund-resolution tables were created successfully.
4. Set the FluentCart store currency to USD or CAD.
5. Configure the credentials belonging to the store's current Order Mode and prove that the hosted API token can read `GET /card-transactions`.
6. Configure the mandatory clean webhook route and verify WordPress Cron before enabling checkout.

## Test and live credential isolation

FluentCart's global Order Mode selects the credential set:

- `test` uses only the Developer Test Account credentials from the Test tab.
- `live` uses only the production account credentials from the Live tab.
- A transaction keeps its original mode. Refund and reconciliation operations resolve credentials for that recorded mode rather than silently switching to the store's current mode.
- Never copy a test account's verifier, API token, Helcim.js token, or secret into a live credential slot.

Secret fields are encrypted with FluentCart's key helpers before storage. A missing or corrupt encrypted value is treated as unavailable.

## Inline Helcim.js configuration

The inline gateway requires all four values for the active mode:

1. **API Token** with transaction-processing access.
2. **Helcim.js Token** from a Helcim.js configuration created as **Card Verify / Tokenize Only**.
3. **Helcim.js Secret Key** from the same configuration.
4. **Webhook Verifier Token** belonging to the same Helcim account and mode.

The browser uses Helcim.js only to verify/tokenize the card. WordPress then calls the v2 `payment/purchase` endpoint with the verified card token. The v2 purchase transaction ID—not a legacy Verify ID—is the refundable provider identifier stored for the FluentCart transaction.

### Developer Test Account behavior

Use Helcim's official test cards only with a dedicated Developer Test Account. The account's terminal enforces its test status.

The inline Verify-to-v2-purchase flow intentionally **omits** the legacy Helcim.js `test=1` field. Sending that flag can produce a demonstration token that the v2 Payment API rejects as unverified. FluentCart Order Mode still selects the test credential set; it does not turn the deprecated SDK flag back on.

## Hosted HelcimPay.js configuration and two-phase flow

The hosted gateway requires the active mode's **API Token** and **Webhook Verifier Token**. The API Access configuration must permit both hosted initialization and Card Transaction reads through `GET /card-transactions`; the latter is required to recover a lost browser callback. Hosted checkout fails closed if that read capability or the recurring recovery schedule cannot be proven. It provides the lowest PCI scope and supports Helcim-hosted payment experiences such as eligible digital wallets.

Hosted checkout uses a durable two-phase boundary:

1. The server reloads the exact FluentCart charge identity, creates a purchase operation with a one-time confirmation token, atomically claims its active scope, and reads the claim back before calling `helcim-pay/initialize`.
2. The operation UUID is the provider correlation value. The modal is exposed only after the initializer returns an exact checkout/secret-token pair for that claimed operation; one-time verification material is stored encrypted.
3. Browser callback data is treated as untrusted. Confirmation reloads the exact hosted charge and operation, consumes the short-lived confirmation token, verifies the provider hash with the one-time secret, and requires the exact operation correlation.
4. Approval or decline proof must match the original status/type/amount/currency identity; an approval also requires a valid positive v2 transaction ID. Only durably persisted proof may drive FluentCart payment effects.
5. Replays resume an already persisted success idempotently. A lost browser response is reconciled through the signed clean webhook and API proof without opening a second active payment attempt.

If the journal cannot be created, claimed, or read back, no hosted session is shown. If initialization fails and that failure cannot be durably recorded, the scope remains locked for reconciliation instead of inviting another charge.

### Hosted lost-callback recovery

When the browser callback is lost, the one-minute recovery worker begins provider lookup after the operation is five minutes old. During the early safety window, only one exact approved result bound to that operation can resolve the remote state; persisted success resumes local completion idempotently, and recovery never sends another purchase.

- An empty provider collection is never proof that no charge occurred and never releases the active payment scope.
- An empty or declined observation before the 70-minute checkout-material safety boundary does not clear the modal metadata or unlock another payment attempt.
- After that safety boundary, one exact declined result may resolve the operation as declined; an empty result still cannot do so.
- The automatic provider-lookup phase is bounded to seven claimed attempts with persisted backoff. If no exact proof is available, automatic recovery pauses while the operation and active scope remain locked.
- A paused or charged-but-locally-incomplete operation appears in a `manage_options` WordPress admin notice. An administrator may use **Check Helcim once** for one nonce-protected lookup; this does not reset the automatic retry budget, and an inconclusive result remains locked.

Before enabling hosted checkout on each test/live credential set, confirm that a harmless filtered `GET /card-transactions` request succeeds and returns the documented root JSON list. A `401`, `403`, timeout, malformed envelope, or missing recurring event disables new hosted checkout rather than weakening recovery.

## Mandatory clean webhook

The v1.1.0 recovery design requires a signed webhook for every enabled current-mode payment path. Settings validation and checkout for both hosted and inline payments fail closed when the current-mode verifier is missing.

The delivery route is:

```text
https://payments.example.com/wp-json/ys-fc-pay/v1/events/card
```

Requirements:

- HTTPS only.
- The complete hostname and path must not contain the provider name.
- Use the exact REST path `/wp-json/ys-fc-pay/v1/events/card`; the legacy FluentCart query listener is retired and returns `410`.
- If the WordPress site's normal hostname contains the prohibited term, use a neutral HTTPS alias or a narrowly scoped reverse proxy that forwards only this POST route.
- Store separate test/live verifier tokens when the accounts differ.
- Do not reuse a verifier from another site or environment without proving account ownership and event scope.

On receipt, the plugin verifies the signed timestamp/body, rejects stale or replayed deliveries, records a durable receipt, fetches the transaction from the API with the credential for the candidate mode, and binds the provider event to one durable payment attempt before changing FluentCart state.

## Durable purchase behavior

Both payment flows use a persistent operation journal and an active-scope lock:

1. FluentCart creates the order and charge transaction.
2. The plugin creates or reuses the durable operation for that exact payment attempt.
3. A one-time confirmation token authorizes the public confirm request.
4. The server claims and verifies the operation before exposing a hosted modal or sending the inline provider purchase with its persisted idempotency key.
5. Approved provider proof is recorded before local payment effects are finalized.
6. Terminal declines release the payment scope; indeterminate transport/provider outcomes retain the scope for reconciliation.

Do not tell a shopper to submit again while an operation is indeterminate. Resolve it from provider evidence or the signed webhook first.

## Remote-first refunds

FluentCart 1.5.2's native refund service records a local refund before calling the gateway and can leave a false local refund when the provider fails. This plugin therefore vetoes the native Helcim refund path and provides a dedicated **FluentCart → Helcim Refunds** workflow.

The replacement flow is remote-first:

1. Load and lock the refundable Helcim parent transaction.
2. Validate amount, currency, mode, remaining refundable total, and historical integrity.
3. Persist a deterministic 36-character provider-safe idempotency key.
4. Call the Helcim refund API.
5. Only after exact provider success, atomically record the FluentCart refund and its local effects.
6. Persist recoverable local effects in the outbox; never send another provider refund merely because a local effect must be retried.

For a full refund against a proven open batch, the narrow reverse fallback is allowed only after the original transaction and batch response prove the same approved purchase, amount, currency, batch ID, and `closed=false`. Refund and reverse are separate journaled operations with separate persisted keys.

An indeterminate refund remains locked for reconciliation. The positive-resolution UI requires fresh provider proof, an explicit candidate transaction ID, operator attestation, and an exact confirmation phrase. It never converts an unknown outcome to failure merely to permit another refund.

## WordPress Cron requirement

Durable local effects and stale-claim recovery depend on scheduled events:

- A per-operation event retries incomplete local refund effects.
- A bounded one-minute sweep recovers abandoned claims and processes ready outbox rows.
- The same one-minute cadence claims due hosted lost-callback operations, applies persisted provider success locally, and schedules bounded provider lookups with durable backoff.
- Plugin preflight fails closed when the recurring recovery events cannot be installed or repaired; hosted checkout also checks that its recovery event remains healthy.

On low-traffic or production sites, configure a real server cron to run due WordPress events at least once per minute. If `DISABLE_WP_CRON` is enabled, an external scheduler is mandatory. Monitor the event queue and investigate repeated outbox errors; do not delete journal rows to make a report appear clean.

## Release package

Build from the repository root with Windows PowerShell 5.1 or newer and PHP 8.1 CLI available. The builder refuses to package mismatched checkout translation keys or stale POT/PO/MO catalogs:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\build-release.ps1 -RequireClean
```

By default the script writes to the ignored `outputs/release/` directory:

- `ys-helcim-via-fluentcart.zip`
- `ys-helcim-via-fluentcart.manifest.json`

The builder uses a strict runtime allowlist, a single `ys-helcim-via-fluentcart/` root, normalized forward-slash entry names, fixed ZIP timestamps, ordered entries, and sidecar SHA-256 hashes. It excludes tests, internal docs, scripts, manual probes, development dependencies, archives, logs, server paths, test card literals, and recognized secret formats.

Verify an existing artifact independently:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\verify-release-package.ps1 `
  -ZipPath .\outputs\release\ys-helcim-via-fluentcart.zip `
  -ManifestPath .\outputs\release\ys-helcim-via-fluentcart.manifest.json `
  -SourceRoot .
```

Run the executable package regression test:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\tests\package\ReleasePackage.Tests.ps1
```

Rebuild and verify the translation catalogs before the final package build:

```powershell
php .\scripts\check-frontend-translations.php
php .\scripts\update-translations.php
powershell -NoProfile -ExecutionPolicy Bypass -File .\tests\package\FrontendTranslationContract.Tests.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\tests\package\Translations.Tests.ps1
```

The final GitHub release should attach exactly one verified ZIP asset. Record the release commit, ZIP SHA-256, Hub package version, and deployed manifest as separate anchors.

## Release-candidate verification gates

Before replacing a client site's current payment gateway:

- Confirm the exact WordPress, PHP, FluentCart, currency, database engine, Order Mode, and credential ownership.
- Prove the current-mode hosted API token can call filtered `GET /card-transactions` and that the response is a root JSON list.
- Back up the current plugin directory and relevant settings/operation rows.
- Deploy the exact manifest-verified artifact that passed development testing.
- Prove an approved inline purchase, a terminal decline that remains unpaid, duplicate-confirm replay safety, and lost-response recovery.
- Prove full and partial remote-first refunds, open-batch reverse fallback, provider failure with no local refund, retry safety, and outbox recovery.
- Prove valid, invalid, stale, and replayed webhooks.
- Prove the hosted five-minute positive-only lookup, pre-70-minute empty/decline lock, seven-attempt pause, visible admin attention, and one-shot manual check without permitting a duplicate charge.
- Confirm no raw PAN, CVV, reusable card token, API token, secret, or verifier appears in logs or plaintext persistence.
- Prove both hosted and inline approved, declined, replay, lost-browser-response, webhook-recovery, and active-scope conflict paths against the same durable invariants.
- Confirm no new PHP fatal/error and verify provider, operation, FluentCart transaction, and order state agree.

## Security notes

- Full card numbers and CVV must never reach WordPress logs, request serialization, order metadata, or operation rows.
- Inline card inputs have no `name` attribute and are tokenized directly by Helcim.js.
- Reusable inline card material may exist only in the encrypted short-lived operation envelope required for same-operation recovery, and is purged at terminal resolution/expiry.
- Provider-changing requests require persistent idempotency keys and durable scope locks.
- Webhook content is never trusted without signature verification and an API lookup.
- The package verifier is a release hygiene control, not a substitute for credential rotation or a dedicated secret-scanning service.

## Known limitations

- `1.1.0-rc.2` is a pre-release dual-gateway candidate and must not be promoted until every release-candidate gate above has current environment evidence.
- Only one-time purchase and refund/reverse operations are supported.
- Subscriptions, pre-authorization/capture, and customer-facing saved cards are not supported.
- Only USD and CAD are supported unless the gateway filter is deliberately extended and provider support is independently confirmed.
- A settled refund gate depends on provider batch state; mocked or manually fabricated callbacks do not replace end-to-end evidence.

## License

GPL v2 or later

## Author

YANGSHEEP DESIGN — https://yangsheep.com.tw
