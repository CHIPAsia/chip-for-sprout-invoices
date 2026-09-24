=== CHIP for Sprout Invoices ===
Contributors: chipasia, wanzulnet
Tags: chip, sprout invoices, payment gateway, fpx, duitnow
Requires at least: 6.3
Tested up to: 7.1
Stable tag: 1.9.7
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

* WordPress 6.3 or greater
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
4. Go to **Sprout Invoices &rarr; Settings &rarr; Currency Formatting** and set **Currency Symbol** to `RM` and **International Currency Symbol** to `MYR`. Sprout Invoices ships these as `$` and `USD` and only follows a few currencies automatically, so without this your invoices render MYR amounts with a dollar sign.

== Frequently Asked Questions ==

= Which currency is supported? =

MYR. CHIP settles Malaysian merchants in Malaysian Ringgit. An invoice in any other currency is refused with a message rather than charged, because the gateway would read its minor units as sen and take the wrong amount.

= Why does my invoice show a dollar sign? =

Sprout Invoices ships `USD` and `$` as its default currency formatting and does not switch automatically for MYR. Set **Currency Symbol** to `RM` and **International Currency Symbol** to `MYR` under **Sprout Invoices &rarr; Settings &rarr; Currency Formatting**. This is a Sprout Invoices setting, not something the plugin changes for you.

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

= 1.9.7 2026-09-24 =
* Stop the release workflow shipping an empty changelog

== Links ==

[CHIP Website](https://www.chip-in.asia)

[Terms of Service](https://www.chip-in.asia/terms-of-service)

[Privacy Policy](https://www.chip-in.asia/privacy-policy)

[API Documentation](https://developer.chip-in.asia/)

[CHIP Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)
