# YS Helcim via FluentCart

> Add Helcim credit card payments to FluentCart, with two collection modes — a **HelcimPay.js modal** and a **helcim.js inline card form** — plus online refunds and webhook reconciliation.

This plugin registers **two independent payment methods** through FluentCart's official Payment Gateway API. Merchants can enable either one, or both, depending on their needs:

| Payment method | Collection mode | Best for |
|----------------|-----------------|----------|
| **Credit Card (Helcim)** | HelcimPay.js secure payment **modal** (pop-up) | Getting live fast with the lowest PCI burden; card entry happens entirely inside Helcim's hosted window |
| **Credit Card (Helcim Inline Form)** | helcim.js card fields **embedded** directly in the checkout page | Keeping the native checkout experience with card fields shown right on the page (the card is still tokenized in the browser and never touches your server) |

---

## Requirements

| Item | Requirement |
|------|-------------|
| WordPress | 6.0 or higher |
| FluentCart | **1.5.2 or higher** |
| PHP | **8.1 or higher** |
| SSL / HTTPS | Recommended site-wide; **required for the helcim.js inline mode**, and webhooks are HTTPS-only |
| Helcim account | A Helcim merchant account (production or developer test account) |

> If FluentCart isn't installed and active, the plugin shows an admin notice and registers no payment methods.

---

## Supported Currencies

> ⚠️ **Helcim only supports `USD` and `CAD`.**

When your FluentCart store currency is anything other than USD or CAD, these two Helcim payment methods **won't appear at checkout** (the helcim.js inline mode also reports an unsupported-currency error while loading its payment block). This is a Helcim platform limitation, not a plugin defect.

If you have a special need to extend the supported currency list, you can adjust it with the `ys_helcim_fct_supported_currencies` filter (see `DEVELOPMENT.md`).

---

## Installation

1. Upload the `ys-helcim-via-fluentcart` folder to `/wp-content/plugins/` (or install the ZIP via **Plugins → Add New → Upload Plugin** in wp-admin).
2. Activate **YS Helcim via FluentCart** on the **Plugins** page.
3. Confirm that FluentCart (1.5.2 or higher) is installed and active.
4. Go to **FluentCart → Settings → Payment Methods**, where you'll find both **Credit Card (Helcim)** and **Credit Card (Helcim Inline Form)**.

---

## Configuration Guide

The two payment methods are configured independently and don't affect each other. Each is covered separately below.

### Shared concept: test vs. live is driven by your store's Order Mode

This plugin follows the same design as FluentCart's Stripe integration: **whether it uses your "test" or "live" credentials depends on the store's global Order Mode** — not on a toggle inside the payment method itself.

- The settings page has separate **Live Credentials** and **Test Credentials** tabs. Fill in both sets.
- When the store's Order Mode is set to **test** → the **Test Credentials** tab is used.
- When the store's Order Mode is set to **live** → the **Live Credentials** tab is used.
- When you save, the plugin validates whichever credential set matches the current store mode to make sure it's complete.

> Every secret field (API Token, Secret Key, Webhook Verifier Token) is encrypted before it's written to the database.

---

### Setup A: Credit Card (Helcim) — HelcimPay.js modal

This mode needs a single **API Token**.

1. Log in to your Helcim dashboard and go to **All Tools → Integrations → API Access**.
2. Create (or reuse an existing) **API Token**.
3. Back in WordPress, open the **Credit Card (Helcim)** payment method settings:
   - On the **Live Credentials** tab, enter the API Token from your production account.
   - On the **Test Credentials** tab, enter the API Token from your developer test account (see "Test Mode" below).
4. (Optional) Customize the **checkout button text**. The default is "Pay with credit card (Helcim)."
5. Enable the payment method and save.

At checkout, clicking the payment button opens Helcim's secure payment window, where the customer enters their card details to complete the payment.

---

### Setup B: Credit Card (Helcim Inline Form) — helcim.js

This mode needs **three** fields: **API Token**, **Helcim.js Token**, and **Helcim.js Secret Key**.

1. **API Token**: same as above — get it from **All Tools → Integrations → API Access** in your Helcim dashboard.
2. **Helcim.js Token + Secret Key**: create a **Helcim.js Configuration** in your Helcim dashboard:
   - **Be sure to choose the "Verify" type** (card tokenization only — it does not charge the card on the front end).
   - Once created, you'll get a **JS Token** and a **Secret Key**. Enter both.
3. Back in WordPress, open the **Credit Card (Helcim Inline Form)** payment method settings and enter the three fields on both the **Live Credentials** and **Test Credentials** tabs.
4. Enable the payment method and save.

> **Why does helcim.js use the Verify type?**
> Legacy helcim.js transaction IDs can't be used for refunds against the Helcim v2 API. So this plugin is designed to use helcim.js on the front end only for **card tokenization (obtaining a cardToken)**, while the actual charge is completed **server-side** via the v2 `payment/purchase` endpoint — which returns a refundable v2 transaction ID. That's what gives you a complete refund and reconciliation lifecycle.

---

### Setup C: Webhook (optional — payment reconciliation safety net)

The webhook isn't required — **payments work fine without it**. Its job is to be a safety net: if the customer pays but their browser drops the connection on the way back to your site, the webhook lets Helcim proactively notify your site so the order still gets marked as paid.

Setup steps (each payment method has its own Webhook URL and Verifier Token fields):

1. On the payment method settings page, copy the displayed **Webhook URL** (it looks like `https://your-site/?fluent-cart=fct_payment_listener_ipn&method=ys_helcim`).
2. Log in to your Helcim dashboard and go to **All Tools → Integrations → Webhooks**.
3. Paste in that Webhook URL (**HTTPS only**).
4. Helcim gives you a **Verifier Token** — copy it and paste it back into the "Webhook Verifier Token" field on the payment method settings page, then save.

> When a webhook arrives, the plugin first verifies the signature and timestamp with HMAC-SHA256 (to prevent forgery and replay), then queries the Helcim API to confirm the transaction's real amount and currency. Only when everything matches does it reconcile the order.

---

## Test Mode

**Helcim has no standalone sandbox environment.** To test without charging a real card, you'll need to:

1. Request a **Developer Test Account** from Helcim to get that account's dedicated test API Token (for helcim.js mode you'll also need the test account's JS Token and Secret Key).
2. Enter the test credentials on the **Test Credentials** tab of the settings page.
3. Set your FluentCart store **Order Mode to test**.
4. Use one of Helcim's official **test card numbers** at checkout.

In test mode, the helcim.js inline form automatically passes a `test=1` parameter to tell the Helcim SDK this is a test transaction.

---

## Comparing the two modes

| Aspect | HelcimPay.js (modal) | helcim.js (inline form) |
|--------|----------------------|-------------------------|
| Payment method slug | `ys_helcim` | `ys_helcim_js` |
| Where the card is entered | Helcim-hosted **pop-up window** | **Embedded** fields right on the checkout page |
| Credentials needed | API Token | API Token + JS Token + Secret Key |
| Setup in Helcim dashboard | API Token only | Also requires a Helcim.js Configuration (Verify type) |
| PCI burden | Lowest (the card never touches your page's DOM) | Low (card fields are on your page, but tokenized directly in the browser — they **never reach your server**) |
| How the charge happens | Customer completes it in the window; the server verifies the result before recording the payment | Front end tokenizes to get a cardToken; the **server** charges via `payment/purchase` |
| HTTPS | Recommended | **Required** |
| User experience | Standard pop-up flow | No pop-up; the customer stays on the page |

Both modes support refunds and webhook reconciliation, with the same level of security verification.

---

## Refunds

You can issue online refunds for Helcim transactions right from the **order page in wp-admin**:

- Full and partial refunds are supported (the refund amount can't exceed the original transaction amount).
- Refunds call the Helcim v2 `payment/refund` API in real time; on success, FluentCart creates a matching refund transaction record.
- Every refund carries a **deterministic idempotency key**, so retrying the operation won't cause a duplicate refund.
- Before refunding, the plugin checks that the transaction mode (test/live) matches the current store mode, so you can't accidentally refund a test transaction with live credentials (or vice versa).

---

## Pre-launch checklist (important)

During development, this plugin's full functionality and security were verified against **mocked Helcim responses**. **Before going live, be sure to run a small real transaction with real Helcim credentials** and confirm all four items below work. This is because Helcim's hash serialization details need to be calibrated against a real response (see the FAQ below and `DEVELOPMENT.md`).

- [ ] **HelcimPay modal payment succeeds**: confirm that after the customer pays in the modal, the `hash` verification passes and the order is marked paid (this step validates that your `json_encode` serialization matches Helcim's).
- [ ] **helcim.js inline payment succeeds**: confirm that after front-end Verify tokenization, the server-side `payment/purchase` charge succeeds and the order is marked paid.
- [ ] **Refund succeeds**: issue a small refund against one of the transactions above and confirm a refund record appears on both the Helcim side and the FluentCart side.
- [ ] **Webhook is received correctly** (if enabled): trigger a test webhook from your Helcim dashboard and confirm your site responds 200 and reconciles the order.

> We recommend running the tests above with a minimal amount (e.g., $1) on your production account, and only opening up to real customers once everything passes.

---

## Frequently Asked Questions

**Q1. Why don't I see the Helcim payment methods at checkout?**
The most common reason is that your store currency isn't USD or CAD. Helcim only supports those two currencies, so the payment methods are hidden automatically for any other currency. Also confirm the payment method is enabled and that the credentials for the current store mode are filled in.

**Q2. Does Helcim have a test environment?**
There's no standalone sandbox. You need to request a Developer Test Account from Helcim to get test credentials, use them with Helcim's official test card numbers, and set your store Order Mode to test.

**Q3. Why are there two payment modes, and which should I pick?**
The two only differ in **how card entry is presented**: the modal (HelcimPay.js) is the simplest to set up and has the lowest PCI burden; the inline form (helcim.js) doesn't pop up and feels more seamless. If you have no particular preference, start with the HelcimPay.js modal. You can also enable both and let customers choose.

**Q4. Is customers' card data safe? Does it pass through my server?**
No. In both modes, the full card number and security code **never reach your server**:
- Modal mode: the card is entered inside Helcim's hosted window.
- Inline mode: the card fields deliberately have **only an `id` and no `name` attribute**, so they aren't serialized and submitted with the checkout form; the card is tokenized directly in the browser by the Helcim SDK, and your server only receives the masked card number and cardToken.
On top of that, all sensitive fields in the debug log (card number, Token, Secret, hash, etc.) are automatically masked.

**Q5. What does the "hash calibration" mentioned in the pre-launch section actually mean?**
After a successful payment in HelcimPay modal mode, the plugin uses a hash comparison to confirm the returned data wasn't tampered with. That hash is sensitive to how `json_encode` serializes data (for example, whether slashes are escaped). Since development was verified against mocked responses, you should run one real transaction with real Helcim credentials before launch to confirm the hash verification passes. If the comparison fails, see the "Real-credential launch calibration checklist" in `DEVELOPMENT.md`.

**Q6. Where is the debug log written?**
Once you enable **debug logging** in the payment method settings, entries are written via PHP `error_log` (typically to your site's `wp-content/debug.log`, depending on your host's configuration). Every log line is prefixed with `[ys-helcim-fct]` for easy searching, and sensitive values are always masked. **We recommend turning this off in production** (even when off, error-level messages are still logged, so payment errors are never silenced).

**Q7. Does it support subscriptions, pre-authorization (preauth/capture), or saved cards?**
Not in v1.0.0. Only one-time charges (purchase) and refunds are currently supported. See "Known Limitations" in `DEVELOPMENT.md`.

---

## License

GPL v2 or later

## Author

**YANGSHEEP DESIGN**
https://yangsheep.com.tw
