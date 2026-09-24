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

= Automatic installation =

Automatic installation is the easiest option as WordPress handles the file transfers itself and you don’t need to leave your web browser. To do an automatic install of, log in to your WordPress dashboard, navigate to the Plugins menu and click Add New.

In the search field type "CHIP for Sprout Invoices" and click Search Plugins. Once you’ve found our plugin you can view details about it such as the point release, rating and description. Most importantly of course, you can install it by simply clicking “Install Now”.

= Manual installation =

The manual installation method involves downloading our plugin and uploading it to your webserver via your favorite FTP application. The
WordPress codex contains [instructions on how to do this here](http://codex.wordpress.org/Managing_Plugins#Manual_Plugin_Installation).

= Updating =

Automatic updates should work like a charm; as always though, ensure you backup your site just in case.

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
* Fixed - Purchases were rejected by CHIP with "due cannot be in the past" whenever the Timing setting was left empty, so no payment could complete. The due limit is now omitted when it is not configured.
* Fixed - A failed CHIP API call during checkout surfaced as a fatal error instead of a message the customer could act on. Every API response is now validated before it is read.
* Fixed - The payment page showed a PayPal icon and hardcoded line items ("test product name", "test name") instead of the invoice's own line items and the paying customer.
* Fixed - Calling the payment processor raised a fatal error on the undefined `PAYER_ID` constant.

== Links ==

[CHIP Website](https://www.chip-in.asia)

[Terms of Service](https://www.chip-in.asia/terms-of-service)

[Privacy Policy](https://www.chip-in.asia/privacy-policy)

[API Documentation](https://developer.chip-in.asia/)

[CHIP Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)
