=== CHIP for Sprout Invoices ===
Contributors: chipasia, wanzulnet
Tags: chip, sprout invoices, payment gateway, fpx, duitnow
Requires at least: 5.9
Tested up to: 7.1
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

CHIP - Better Payment & Business Solutions. Securely accept payment with CHIP for Sprout Invoices.

== Description ==

This is a CHIP plugin for Sprout Invoices.

CHIP is a payment and business solutions platform that allow you to securely sell your products and get paid via multiple local and international payment methods.

This plugin will enable your Sprout Invoice installation to be integrated with CHIP as per documented in [API Documentation](https://developer.chip-in.asia/).

= Supported payment methods =

FPX B2C, FPX B2B1, DuitNow QR, ShopeePay, Visa, Mastercard, Maestro, Atome, GrabPay, Maybank QR, Touch 'n Go eWallet.

Any method your brand does not support is simply not shown at checkout: the plugin asks the gateway which methods are available for the invoice amount before building the payment page.

== Installation ==

= Minimum Requirements =

* WordPress 5.9 or greater
* PHP 7.4 or greater
* Sprout Invoices 20.4 or greater

= Installation =

1. Install and activate [Sprout Invoices](https://wordpress.org/plugins/sprout-invoices/) (20.4 or newer).
2. [Download the latest release](https://github.com/CHIPAsia/chip-for-sprout-invoices/releases/latest/download/chip-for-sprout-invoices.zip) and upload it via **Plugins &rarr; Add New &rarr; Upload Plugin**.
3. Activate **CHIP for Sprout Invoices**.

This plugin is distributed from GitHub, so WordPress cannot find or update it from the WordPress.org plugin directory.

= Configuration =

1. Go to **Sprout Invoices &rarr; Settings &rarr; Payments**.
2. Enable **CHIP** as a payment processor.
3. Enter your **Brand ID** and **Secret Key** from the CHIP merchant dashboard (Developers section).

== Frequently Asked Questions ==

= Where is the Brand ID and Secret Key located? =

Brand ID and Secret Key available through our merchant dashboard.

= Do I need to set public key for webhook? =

No. The plugin collects the account public key from the gateway on the first callback and verifies every later callback's `X-Signature` against it.

= How do refunds work? =

Sprout Invoices' own refund action only changes a local status and moves no money. To refund at CHIP, use the **Refund** link in the CHIP column of the payments screen (Sprout Invoices &rarr; Payments). Leaving the amount empty refunds the full payment; a smaller amount performs a partial refund.

= What CHIP API services used in this plugin? =

This plugin rely on CHIP API ([SI_CHIP_ROOT_URL](https://gate.chip-in.asia)) as follows:

  - **/purchases/**
    - This is for accepting payment
  - **/purchases/<id\>**
    - This is for getting payment status from CHIP
  - **/purchases/<id\>/refund/**
    - This is for refunding a payment
  - **/purchases/<id\>/capture/**
    - This is for capturing a pre-authorized payment
  - **/payment_methods/**
    - This is for checking which payment methods are available
  - **/public_key/**
    - This is for verifying webhook callbacks

== Changelog ==

= 1.1.0 - 2026-09-24 =
* Added - Refund and partial refund at CHIP from the payments screen, in a new CHIP column.
* Added - Payment method whitelist setting.
* Added - Due Strict and Due Strict Timing settings.
* Added - Save Logs setting, writing to Sprout Invoices' developer log.
* Added - Server-to-server callback handling, so an invoice is settled even when the customer never returns from the payment page.
* Added - `X-Signature` verification of every callback against the account public key.
* Added - Support for FPX B2B1, Maestro, Atome, GrabPay, Maybank QR and Touch 'n Go eWallet, alongside FPX B2C, DuitNow QR, ShopeePay, Visa and Mastercard.
* Fixed - Purchases were rejected by CHIP with "due cannot be in the past" whenever the Timing setting was left empty, so no payment could complete. The due limit is now omitted when it is not configured.
* Fixed - A failed CHIP API call during checkout surfaced as a fatal error instead of a message the customer could act on. Every API response is now validated before it is read.
* Fixed - The payment page showed a PayPal icon and hardcoded line items ("test product name", "test name") instead of the invoice's own line items and the paying customer.
* Fixed - Calling the payment processor raised a fatal error on the undefined `PAYER_ID` constant.
* Fixed - A non-MYR invoice would be charged as though it were MYR, collecting the wrong amount. The invoice's own currency is now authoritative and is refused when it is not the currency CHIP settles in.
* Fixed - Concurrent callbacks created two payment records for one gateway purchase. Recording a purchase now takes a lock around its check-then-create.
* Fixed - Refunding a payment that had no acquirer failed with a vague gateway error. The Refund action now checks the gateway's refund availability and says why.
* Fixed - The customer was returned to the invoice with no confirmation that the payment went through.
* Changed - The customer is returned to the invoice with a status message instead of an anonymous callback URL.
* Changed - Amounts are converted to minor units through string-based rounding, so a total such as RM 19.99 is no longer sent as 1998.9999999999998.

== Links ==

[CHIP Website](https://www.chip-in.asia)

[Terms of Service](https://www.chip-in.asia/terms-of-service)

[Privacy Policy](https://www.chip-in.asia/privacy-policy)

[API Documentation](https://developer.chip-in.asia/)

[CHIP Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)
