# ShaplaPay Payment Gateway with bKash for FluentCart

![ShaplaPay Payment Gateway with bKash for FluentCart](.wordpress-org/banner-1544x500.png)

**ShaplaPay** is an independent WordPress plugin by ShaplaPay. It adds **bKash** as a payment method to [FluentCart](https://wordpress.org/plugins/fluent-cart/), the self-hosted eCommerce plugin, using the bKash Payment Gateway **tokenized checkout** API. Customers pay on bKash's own hosted page, and the payment is confirmed server side before the order is marked paid.

This is a third-party integration. It is not affiliated with, endorsed by, or an official product of bKash Limited or the FluentCart team.

| | |
|---|---|
| Display name | ShaplaPay Payment Gateway with bKash for FluentCart |
| Directory slug | `shaplapay-payment-gateway-bkash-fluentcart` |
| Text domain | `shaplapay-payment-gateway-bkash-fluentcart` |
| Admin menu | bKash (reports screen) |
| GitHub | [tishasub/shaplapay-bkash-payment-gateway-for-fluentcart](https://github.com/tishasub/shaplapay-bkash-payment-gateway-for-fluentcart) |

The WordPress.org plugin directory uses `readme.txt`. This file is the GitHub overview.

## Why it exists

FluentCart ships Stripe, PayPal, Paystack, Mollie, Paddle and a few others, but no bKash. Stores in Bangladesh that sell through FluentCart had no supported way to take bKash payments.

This plugin is a real gateway integration, not a "send me the money" workaround:

- It creates a bKash payment session through the **bKash Payment Gateway API** and redirects the customer to bKash's hosted checkout.
- It confirms the payment **server side** by calling the bKash Execute API, with a Query API fallback when Execute times out.
- It refuses to trust the browser return on its own: the order is only marked paid when bKash reports the transaction as `Completed`, and the amount bKash reports is compared against the order total.
- It supports **refunds** through the bKash refund API from the FluentCart order screen.

## What it does

- bKash **tokenized checkout** (mode `0011`), so guests can pay and no prior bKash agreement is needed
- **Sandbox and live** credentials, selected automatically from the FluentCart store Order Mode
- Credentials stored **encrypted**, the same way FluentCart stores Stripe and PayPal keys, and verified with bKash before the gateway can be switched on
- **Query API fallback** when Execute times out, plus a second check when the customer lands back on the receipt page
- **Refunds** from the FluentCart order screen
- bKash **trxID** and the masked payer wallet number recorded in the order activity log
- A **bKash reports screen** with collected, pending, failed and refunded totals, a transaction list, manual transfers awaiting confirmation, and recorded refunds
- Subscription carts are handled like cash on delivery: the subscription moves to manual billing and each renewal is a one-time bKash payment

## Manual wallet transfer mode

Stores without bKash API credentials can still use the gateway. In manual mode the confirmation page shows the customer the exact amount to send, your bKash wallet number and the order reference to quote. The order stays pending and you confirm each transfer in FluentCart after you see it in your bKash app.

Two settings shape that flow:

- **Payment confirmation** — confirm transfers yourself, or also ask the customer to submit the bKash Transaction ID they received. Submitting an ID never marks the order paid.
- **Where to show the payment details** — the confirmation page always shows them; you can additionally show a notice under the bKash option at checkout.

The message wording is editable with `{wallet}`, `{amount}` and `{reference}` placeholders.

## Requirements

`Requires Plugins` lists the WordPress.org slug for FluentCart:

- WordPress 6.7+
- PHP 7.4+
- FluentCart (`fluent-cart`) 1.6+
- Store currency set to **BDT** — the only currency bKash settles in
- A bKash merchant account with Payment Gateway credentials (API mode only)
- HTTPS for live payments

## Install

1. Copy the `shaplapay-payment-gateway-bkash-fluentcart` folder into `wp-content/plugins/`, or install the ZIP through **Plugins → Add New → Upload Plugin**.
2. Activate **ShaplaPay Payment Gateway with bKash for FluentCart**.
3. Open **FluentCart → Settings → Payments** and select bKash.
4. Enter the App Key, App Secret, Username and Password issued by bKash, then save. The plugin verifies them with bKash before enabling the gateway.

Sandbox credentials are published by bKash at [developer.bka.sh](https://developer.bka.sh/).

## Plugin structure

```
shaplapay-payment-gateway-bkash-fluentcart.php
uninstall.php
src/
  Bkash.php                  gateway + settings fields
  BkashSettings.php          settings accessors
  BkashProcessor.php         payment creation + manual mode
  CallbackHandler.php        server side confirmation (Execute/Query)
  ManualPaymentNotice.php    confirmation page notice + trxID capture
  Api/BkashApi.php           bKash HTTP client
  Admin/BkashReport.php      reports screen
assets/
  css/admin-report.css       reports screen styles (enqueued)
  css/manual-notice.css      confirmation page notice styles (enqueued)
  js/bkash-checkout.js       enables the Place Order button at checkout
  images/bkash-label.svg     plain text label (not bKash's logo)
.github/workflows/           WordPress.org deploy + asset sync
.wordpress-org/              banner and icon assets
```

PHP prefix: `ShaplaPayBkash`. Constants: `SHAPLAPAY_BKASH_*`. Text domain: `shaplapay-payment-gateway-bkash-fluentcart`.

## Directory assets

`.wordpress-org/` holds the WordPress.org listing art:

| File | Size | Status |
|---|---|---|
| `banner-1544x500.png` | 1544 × 500 | included |
| `banner-772x250.png` | 772 × 250 | included |
| `icon-128x128.png` | 128 × 128 | included |
| `icon-256x256.png` | 256 × 256 | included |
| `screenshot-1.png` | any | **to add** — capture the FluentCart checkout showing bKash, or the bKash reports screen |

## Releases

The version lives in two places and both must match: the `Version:` plugin header and `Stable tag:` in `readme.txt`.

- Pushing a tag (`1.0.4`) runs `.github/workflows/deploy-to-wordpress-org.yml`, which lints the PHP, assembles a clean build using `.distignore`, and deploys it to WordPress.org SVN.
- Pushing to `main` with changes to `readme.txt` or `.wordpress-org/**` runs `.github/workflows/update-wordpress-org-assets.yml` to sync the listing assets.

Both workflows skip deployment and print a notice until `SVN_USERNAME` and `SVN_PASSWORD` are set under **Settings → Secrets and variables → Actions**.

## License

GPL-2.0-or-later. See [license.txt](license.txt).
