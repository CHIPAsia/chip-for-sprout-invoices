<img src="./assets/logo.svg" alt="drawing" width="50"/>

# CHIP for Sprout Invoices

This module adds CHIP payment method option to your Sprout Invoices installation.

## Installation

* [Download and activate the Sprout Invoices plugin](https://wordpress.org/plugins/sprout-invoices/) from WordPress.org.
* [Download the latest CHIP for Sprout Invoices zip.](https://github.com/CHIPAsia/chip-for-sprout-invoices/archive/refs/heads/main.zip)
* Log in to your Wordpress admin panel and go: **Plugins** -> **Add New**
* Select **Upload Plugin**, choose zip file you downloaded in step 2 and press **Install Now**
* Activate plugin

## Configuration

Set the **Brand ID** and **Secret Key** in the plugins settings, under **Sprout Invoices** -> **Settings** -> **Payments**.

## Refunds

Sprout Invoices' own refund action only changes a local payment status; it moves no money.
To refund at CHIP, use the **Refund** link in the CHIP column of **Sprout Invoices** ->
**Payments**. Leave the amount empty for a full refund, or enter a smaller amount in minor
units (sen) for a partial refund.

## Other

Facebook: [Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)
