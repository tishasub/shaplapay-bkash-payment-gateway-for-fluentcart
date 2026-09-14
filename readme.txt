=== ShaplaPay Payment Gateway with bKash for FluentCart ===
Contributors: tisha92
Tags: bkash, fluentcart, payment-gateway, bangladesh, mobile-banking
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: fluent-cart
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept bKash payments in FluentCart through the bKash Payment Gateway API. Independent integration by ShaplaPay.

== Description ==

This is an independent plugin by ShaplaPay. It is not affiliated with, endorsed by, or an official product of bKash Limited or the FluentCart team.

This plugin lets a FluentCart store in Bangladesh accept payments through bKash, the country's largest mobile financial service. When a customer chooses bKash at checkout, they are sent to the secure bKash hosted page, pay with their wallet, and are brought back to the store with the order marked as paid.

= How a payment runs =

1. The customer places an order and picks bKash on the FluentCart checkout.
2. The plugin opens a payment session with the bKash API and sends the customer to the bKash hosted page.
3. The customer enters their bKash wallet number, the OTP sent to their phone, and their wallet PIN.
4. bKash sends the customer back to the store. The plugin then confirms the payment server side with the bKash Execute and Query APIs and marks the order paid.

The browser return is never trusted on its own: an order is only marked paid after the bKash API reports the transaction as Completed, and the paid amount is checked against the order total.

= Features =

* Two connection modes, switchable in the gateway settings:
  * **API mode** - full automatic checkout through the bKash Payment Gateway credentials
  * **Manual mode** - for stores without bKash API credentials: the checkout shows your bKash wallet number and per-order payment instructions, the customer sends the money from their own bKash app, and you confirm each transfer in the FluentCart order screen, like a cash on delivery order
* bKash tokenized checkout (mode 0011), so guests can pay and no prior bKash agreement is needed
* Sandbox and live credentials, picked automatically from the FluentCart store Order Mode
* Credentials stored encrypted, the same way FluentCart stores Stripe and PayPal keys
* Credentials are verified with bKash before the gateway can be switched on
* Query API fallback when the execute call times out, plus a second check when the customer lands back on the receipt page
* Refunds from the FluentCart order screen through the bKash refund API
* bKash transaction ID written to the order activity log, with the transaction ID and a masked payer wallet number (for example 017****1234) stored on the transaction. Masking is also applied when old rows and the merchant wallet number are shown in the reports screen
* Subscription carts are handled like cash on delivery: the subscription moves to manual billing and each renewal is paid by the customer as a one-time bKash payment

= Requirements =

WordPress will not activate this plugin unless FluentCart (free) is installed (`Requires Plugins`):

* WordPress 6.7 or later (FluentCart's own minimum)
* PHP 7.4 or later
* FluentCart (`fluent-cart`) 1.6 or newer, active on the same site
* Store currency set to BDT, the only currency bKash accepts
* A bKash merchant account with Payment Gateway credentials (App Key, App Secret, Username and Password) issued during bKash onboarding, needed for API mode only
* HTTPS on the store for live payments, because bKash returns the customer to a callback URL on your site

Manual wallet transfer mode needs no bKash API credentials at all, only your bKash wallet number.

= Third-party service and data =

This plugin uses the bKash Payment Gateway API, a third-party service operated by bKash Limited in Bangladesh.

During API checkout, the customer enters their bKash wallet number, OTP and PIN on bKash's own hosted payment page. This plugin never receives or stores the customer's OTP or PIN. Your server communicates with bKash server to server to create, execute, verify and refund payments.

Transaction details that bKash returns are stored in the FluentCart order records and the order activity log so the merchant can reconcile the payment. This is limited to the bKash payment ID and transaction ID, the amount and currency, the masked payer wallet number (for example 017****1234) where bKash provides it, the settlement time, and refund transaction IDs. The full raw API response is deliberately not stored, so anything bKash adds to its responses later cannot silently end up in your database. In manual wallet transfer mode, the wallet number, amount and order reference shown to the customer are stored, together with the transaction ID the customer submits.

bKash's own terms and privacy notice govern the information bKash processes:

* [bKash Terms and Conditions](https://www.bkash.com/en/page/terms-and-conditions)
* [bKash Payment Gateway Terms and Conditions](https://www.bkash.com/en/page/terms-of-use-checkout)
* [bKash Privacy Notice](https://www.bkash.com/en/page/privacy-notice)
* [bKash Developer Documentation](https://developer.bka.sh/)

This is an independent integration by ShaplaPay. It is not developed, endorsed or maintained by bKash Limited or by the FluentCart team.

== Installation ==

1. Install and activate FluentCart first.
2. Upload this plugin through Plugins > Add New > Upload Plugin, or copy the folder into wp-content/plugins.
3. Activate ShaplaPay Payment Gateway with bKash for FluentCart.
4. Open FluentCart > Settings > Payments and select bKash.
5. Enter the four credentials bKash issued for your merchant account. Use the sandbox tab while your store Order Mode is "test" and the live tab once it is "live".
6. Save. The plugin asks bKash for an API token with those credentials and only enables the gateway if bKash accepts them.

Sandbox credentials for testing are published by bKash in the developer portal at [developer.bka.sh](https://developer.bka.sh/) under the sandbox documentation.

== Frequently Asked Questions ==

= Which bKash product does this use? =

The tokenized checkout product of the bKash Payment Gateway, API version 1.2.0-beta, in mode 0011. That is the standard bKash hosted checkout where the customer enters wallet number, OTP and PIN. No stored agreement or token registration is required, so guest checkout works.

= Do I need to configure a bKash webhook? =

No. bKash confirms payments through the execute and query APIs, which is the flow bKash documents for this product. There is nothing extra to set up on the bKash side beyond your credentials.

= What if I do not have bKash API credentials yet? =

Set Connection mode to "Manual wallet transfer" in the gateway settings and enter your bKash wallet number. No API keys are needed. After the order is placed, the confirmation page shows the customer the exact amount to send, your bKash wallet number and the order reference to quote. The order stays pending, and you mark it paid from the FluentCart order screen after you see the transfer in your bKash app.

Two settings shape that message:

* **Payment confirmation** - "I confirm each transfer myself" only shows the payment details. "Ask the customer for the bKash Transaction ID" also shows a field where the customer enters the bKash Transaction ID they received, which is saved on the order and listed in the bKash report so you can match it against your bKash app. Submitting an ID never marks the order paid.
* **Where to show the payment details** - the confirmation page always shows them, since that is the first point at which the exact amount and the order reference exist. You can additionally show a short notice under the bKash option at checkout, before the order is placed.

You can also change the wording of the message with the {wallet}, {amount} and {reference} placeholders. When bKash later issues your Payment Gateway credentials, switch the mode back to API. Orders already placed in manual mode keep their manual workflow, including refunds.

= Why can I not refund more than the item total in the refund popup? =

That limit comes from FluentCart itself, not from this plugin. When line items are selected in the refund popup, FluentCart caps the amount at the total of those items, and shipping is not a line item. Clear the item selection to refund the full paid amount including shipping.

= My store currency is not BDT. Can I still use bKash? =

No. bKash only settles in Bangladeshi Taka, so the gateway hides itself on stores with any other currency.

= Where do I see the bKash transaction ID? =

On the order screen in FluentCart: the bKash transaction ID is written to the order activity log, and both the transaction ID and a masked payer wallet number are stored with the transaction. The bKash reports screen also lists the transaction ID and masked wallet.

== Changelog ==

= 1.0.4 =
* Renamed the plugin to "ShaplaPay Payment Gateway with bKash for FluentCart" and changed the permalink to "shaplapay-payment-gateway-bkash-fluentcart". The bKash mark now follows "with", so the name no longer reads as an official bKash or FluentCart product.
* Listed the correct WordPress.org contributor username.
* Moved the reports screen and manual transfer notice styles into enqueued stylesheets instead of inline style tags.
* Shortened the plugin description and the readme short description so they fit inside the WordPress limits instead of being cut off.
* Corrected the data disclosure. The readme no longer claims that no payment data is stored: it now states that bKash transaction details are kept on the order for reconciliation, that the customer's OTP and PIN are never collected, and it links bKash's terms and privacy notice.
* Stopped storing the full bKash API responses. Only the fields the store actually needs are kept, so anything bKash adds to its responses later cannot silently end up in the database.
* The payer wallet number is now masked, for example 017****1234, before it is stored, and masked again when shown in the bKash reports screen.
* Replaced the recreated bKash logo with a plain text label, because bKash's terms prohibit unauthorised reproduction or alteration of their name and logo.

= 1.0.3 =
* Adopted the display name "ShaplaPay Payment Gateway with bKash for FluentCart" and added a third-party disclaimer to the plugin and the readme.
* Requires Plugins now declares `fluent-cart`, and the minimum WordPress version matches FluentCart's own requirement (6.7).
* Added an uninstall routine that removes the gateway settings, the cached bKash API tokens and any leftover confirmation locks when the plugin is deleted.
* Added directory assets (banner, icon), a GitHub README, a license file, and GitHub Actions workflows for WordPress.org deployment.

= 1.0.2 =
* Manual wallet transfer now shows the customer real payment details - the exact amount, your bKash wallet number and the order reference - on the confirmation page. Previously this message was swallowed by the checkout and the customer was redirected without any instructions.
* New setting: ask the customer for the bKash Transaction ID they received, or confirm each transfer yourself. Either way the order stays pending until you confirm it.
* New setting: show a short payment notice under the bKash option at checkout as well as on the confirmation page.
* Submitted transaction IDs are recorded on the order, written to the order activity log, and listed in the bKash report for matching against your bKash app.

= 1.0.1 =
* Fixed the "Place order" button staying disabled when bKash was the selected payment method on first page load.
* Added a bKash reports screen under the admin menu: collected, pending, failed and refunded totals, a transaction list with bKash trxID, payment ID and payer wallet, manual transfers awaiting confirmation, and recorded refunds.
* The plugins screens now require WordPress 6.2 or newer.

= 1.0.0 =
* First release: bKash tokenized checkout for FluentCart with sandbox and live credentials, server side payment confirmation, query fallback, receipt page re-check, refunds and order activity logging, plus a manual wallet transfer mode for stores that do not have bKash API credentials yet.
