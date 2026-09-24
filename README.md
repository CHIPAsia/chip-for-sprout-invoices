<img src="./assets/logo.svg" alt="drawing" width="50"/>

# CHIP for Sprout Invoices

This module adds CHIP payment method option to your [Sprout Invoices](https://wordpress.org/plugins/sprout-invoices/) installation.

## Compatibility

- Sprout Invoices 20.4 or newer
- WordPress 6.3 or newer
- PHP 7.4 or newer (8.0+ recommended)

## Installation

* [Download the latest release](https://github.com/CHIPAsia/chip-for-sprout-invoices/releases/latest/download/chip-for-sprout-invoices.zip).
  The zip is ready to install — it contains the plugin and nothing else.
* Log in to your WordPress admin panel and go: **Plugins** -> **Add New**
* Select **Upload Plugin**, choose the zip file you downloaded in step 1 and press **Install Now**
* Activate plugin

## Configuration

Go to **Sprout Invoices** -> **Settings** -> **Payments**, enable **CHIP**, then set the
**Brand ID** and **Secret Key** from your CHIP merchant dashboard (Developers section).

Then set the currency formatting: **Sprout Invoices** -> **Settings** -> **Currency
Formatting**, with **Currency Symbol** `RM` and **International Currency Symbol** `MYR`.
Sprout Invoices defaults to `$`/`USD` and only follows a few currencies automatically, so
without this step MYR invoices render with a dollar sign.

Optional settings on the same screen:

| Setting | Description |
|---------|-------------|
| **Payment Method Whitelist** | Restrict which methods are offered. Leave empty to offer everything the brand supports |
| **Due Strict / Due Strict Timing** | Stop an invoice being payable once the timing has passed |
| **Cancel URL** | Where a customer who cancels on the CHIP page is sent. Defaults to the invoice |
| **Save Logs** | Record gateway requests and callbacks for troubleshooting |

Any method your brand does not support is simply not shown at checkout: the plugin asks the
gateway which methods are available for the invoice amount before building the payment page.

## Refunds

Sprout Invoices' own refund action only changes a local payment status; it moves no money.
To refund at CHIP, use the **Refund** link in the CHIP column of **Sprout Invoices** ->
**Payments**. Leave the amount empty for a full refund, or enter a smaller amount in minor
units (sen) for a partial refund.

Only a payment the gateway reports as refundable can be refunded here. A payment settled
without an acquirer — for example one reconciled from a server-to-server callback rather than
a customer checkout — must be refunded from the CHIP dashboard instead; the plugin says so
rather than failing silently.

## Development

```bash
composer install    # dev dependencies (PHPUnit, PHPCS, WPCS)
composer test       # PHPUnit
composer lint       # PHPCS against phpcs.xml — must exit 0
composer lint:fix   # phpcbf autofix
composer compat     # PHPCompatibilityWP, 7.4+
```

## Release

Pushing a `vX.Y.Z` tag creates the GitHub release, builds the zip and attaches both the
versionless and versioned copies — so
`releases/latest/download/chip-for-sprout-invoices.zip` always resolves. The workflow refuses
to release when the tag, the plugin header and `readme.txt`'s `Stable tag` disagree, and it
verifies the archive before publishing it.

## Other

Facebook: [Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)
