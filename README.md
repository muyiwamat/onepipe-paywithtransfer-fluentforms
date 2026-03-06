# OnePipe PayWithTransfer for Fluent Forms

A WordPress plugin that adds **OnePipe PayWithTransfer** as a payment method in [Fluent Forms Pro](https://fluentforms.com). Customers pay by bank transfer into a virtual account that OnePipe generates for them — no redirect, no card details, just a simple bank transfer.

**Version:** 1.0.0
**Requires:** WordPress 5.6+, PHP 7.4+, Fluent Forms Pro
**License:** GPL-2.0-or-later

---

## How It Works

1. A customer fills out a Fluent Forms payment form and selects **Pay with Bank Transfer**.
2. On submission, the plugin calls the OnePipe API to generate a virtual bank account for that customer.
3. A modal pops up showing the bank name, account number, account name, and exact amount to transfer.
4. The customer makes the transfer from their banking app.
5. OnePipe sends a webhook to the site when the transfer is confirmed.
6. The plugin marks the Fluent Forms submission as **paid** and the form's confirmation/redirect fires automatically.

The virtual account is persistent per customer (same phone number always gets the same account).

---

## Requirements

| Requirement | Minimum Version |
|---|---|
| WordPress | 5.6 |
| PHP | 7.4 |
| [Fluent Forms](https://wordpress.org/plugins/fluentform/) | Latest |
| [Fluent Forms Pro](https://fluentforms.com) | Latest |
| OnePipe merchant account | — |

---

## Installation

1. Download or clone this repository into your `wp-content/plugins/` directory:
   ```
   git clone https://github.com/muiywamat/onepipe-paywithtransfer.git
   ```
2. Activate the plugin from **WordPress Admin → Plugins**.
3. Go to **Fluent Forms → Settings → Payment Settings → OnePipe PayWithTransfer**.
4. Enter your OnePipe API credentials (see [Configuration](#configuration) below).
5. Add a payment field to a Fluent Form and select **Pay with Bank Transfer** as the payment method.

---

## Configuration

### OnePipe API Credentials

Go to **Fluent Forms → Settings → Payment Settings → OnePipe PayWithTransfer** and fill in:

| Field | Description |
|---|---|
| **Payment Mode** | `Sandbox` for testing, `Live` for production |
| **API Key** | Your OnePipe Bearer token (from the OnePipe merchant dashboard) |
| **API Secret** | Your OnePipe secret key (used to sign requests) |
| **Biller Code** | Your PayWithAccount biller code |

> **Sandbox note:** Sandbox mode has transaction amount limits. Use small amounts (e.g. ₦100) when testing, or switch to Live mode with real credentials.

### Webhook URL

OnePipe must be configured to send payment notifications to your site. The webhook URL is:

```
https://yoursite.com/?fluentform_payment_api_notify=1&payment_method=onepipe_pwt
```

Configure this URL in your **PayWithAccount merchant dashboard** (separate from the OnePipe API dashboard). Contact OnePipe support if you cannot find the webhook settings.

---

## Plugin Structure

```
onepipe-paywithtransfer/
├── onepipe-paywithtransfer.php      # Main plugin bootstrap & dependency check
├── includes/
│   ├── class-loader.php             # Requires and instantiates all components
│   ├── class-payment-method.php     # Admin settings (extends BasePaymentMethod)
│   ├── class-payment-processor.php  # Payment flow & webhook handler (extends BaseProcessor)
│   └── class-onepipe-api.php        # OnePipe HTTP API wrapper
├── assets/
│   ├── js/
│   │   └── paywithtransfer.js       # Frontend modal, AJAX, polling
│   └── css/
│       └── paywithtransfer.css      # Modal styles
└── README.md
```

---

## API Integration Details

- **Endpoint:** `POST https://api.onepipe.io/v2/transact`
- **Request type:** `send invoice`
- **Auth provider:** `PaywithAccount`
- **Authorization header:** `Bearer {api_key}`
- **Signature header:** `MD5(request_ref + ';' + api_secret)`
- **Amount unit:** Kobo (₦1 = 100 kobo) — Fluent Forms stores amounts in minor units natively

### Payment Flow (Technical)

```
Form submit
  → handlePaymentAction()         Creates pending FF transaction, stores meta
  → JS: fluentform_next_action_onepipe_pwt triggered
  → JS: AJAX onepipe_pwt_get_account
  → ajaxGetAccount()              Calls OnePipe send_invoice API
  → Modal shown with account details
  → JS polls onepipe_pwt_check_status every 20 seconds

OnePipe webhook POST
  → handleWebhook()               Verifies payload, finds submission by payment_id
  → changeTransactionStatus('paid')
  → completePaymentSubmission()   Fires FF confirmation/redirect
```

---

## Changelog

### 1.0.0
- Initial release
- Virtual account modal with bank name, account number, account name, and amount
- Copy-to-clipboard for account number (with HTTP fallback)
- 20-second payment status polling
- Webhook handler for automatic payment confirmation
- Sandbox and Live mode support
- Gold (`#d4af37`) colour scheme

---

## Author

**Múyìwá Mátùlúkò** — [muyosan.com.ng](https://muyosan.com.ng)
