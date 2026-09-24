# AGENTS.md

This file provides guidance to AI coding agents (Claude Code, opencode, Codex, …) working in
this repository.

## Project Overview

**CHIP for Sprout Invoices** adds CHIP (a Malaysian payment gateway) as a payment processor
for [Sprout Invoices](https://wordpress.org/plugins/sprout-invoices/). A Sprout invoice's
checkout sends the customer to a CHIP payment page, and CHIP's server-to-server callback
settles the invoice.

- Pure PHP WordPress plugin — no JS build step, no Composer runtime dependencies.
- PHP floor: **7.4**. Minimum WordPress: **6.3**. Minimum Sprout Invoices: **20.4**.
- Text domain: `chip-for-sprout-invoices`.
- Plugin file: `chip-for-sprout-invoices.php`.

The WordPress floor is **6.3**, matching the other CHIP WordPress plugins
(`chip-for-woocommerce`, `givewp`, `gravity-forms`, `formidable-forms` all declare 6.3). It is
declared in four places that must stay in step — the plugin header, `readme.txt` (twice: the
header field and Minimum Requirements), `README.md`, and `phpcs.xml`'s
`minimum_supported_wp_version`. `tests/Unit/Chip_Sprout_Invoices_MetadataTest.php` asserts they
agree; run it before changing any of them.

Do not set the floor to the lowest version the code happens to run on. The only WP function
used here that is not ancient is `wp_timezone_string()` (5.3), and Sprout Invoices itself
requires 5.1, so a literal reading gives 5.3 — but 5.9 and earlier are EOL and untested, and
nothing in this repo was ever exercised on them. `Tested up to` must name a version actually
tested: 7.1, which is what the end-to-end run uses.

Note the plugin is **not published on WordPress.org**: it is distributed as a GitHub release
zip, so there is no SVN deploy workflow. Do not add one.

## Commands

```bash
composer install    # dev dependencies (PHPUnit, PHPCS, WPCS)
composer test       # PHPUnit
composer lint       # PHPCS against phpcs.xml — MUST exit 0
composer lint:fix   # phpcbf autofix
composer compat     # PHPCompatibilityWP, 7.4+

npx wp-env start    # local WordPress + Sprout Invoices + Plugin Check (.wp-env.json)
```

`composer lint` exiting 0 is the bar, the same way `chip-for-woocommerce` passes its own
ruleset. Any change to a sniff, exclusion or file name in `phpcs.xml` must be re-verified
with a mutation: introduce the violation in a file the ruleset still covers and confirm the
sniff fires. An exclusion that does not actually exclude, and one that excludes more than
intended, both look like "clean".

`wp-env` cannot run as root; on such a host use CI's Plugin Check job instead.

## Architecture

```
chip-for-sprout-invoices.php   Entry point: plugin header, constants, bootstrap on
                               si_payment_processors_loaded
uninstall.php                  Deletes the plugin options and its cached transients
                               (option names are written literally — the plugin is not
                               loaded during uninstall)
includes/
  class-chip-si-api.php        CHIP Collect API client
  class-chip-si-helper.php     Shared helpers: minor units, whitelist resolution, due, logs
  class-chip-si-ec.php         SI_Chip_EC — the payment processor (extends
                               SI_Offsite_Processors): checkout, purchase recording, locks,
                               capture, void
  class-chip-si-listener.php   External callback + customer redirect, X-Signature verification
  class-chip-si-refund.php     Admin refund / partial refund (AJAX), CHIP column on the
                               payments screen
assets/
  logo.svg                     Payment option icon
  refund.js                    Refund action for the payments screen
scripts/bump-version.sh        Version bump across every file, self-verifying
scripts/make-assets.py         Regenerates .wordpress-org/ banner + icon
```

The processor registers itself with Sprout through the `si_payment_processor` option
(`array( 'SI_Chip_EC' )`) and `SI_Offsite_Processors`. Its class name is part of that
registry contract — do not rename it.

## Money-flow invariants (do not break these)

1. **The invoice owns the currency.** `get_currency_code()` reads the invoice's own currency
   (run through Sprout's `si_currency_code` filter so a client-level override still applies)
   and the Currency Code setting is only a fallback when the invoice has none. The gateway
   interprets the amount as minor units of MYR, so a non-MYR invoice must be refused, not
   charged — otherwise a USD invoice is charged as RM.
2. **Amounts are converted with rounding, not truncation.** `19.99 * 100` is
   `1998.9999999999998` in IEEE-754; truncating undercharges by one sen on every amount whose
   cents are 9, and the gateway rejects the float outright with "A valid integer is required".
3. **The amount the gateway collected must equal the invoice balance.** `record_purchase()`
   compares them in minor units and refuses to record a mismatch.
4. **Recording a purchase is idempotent.** CHIP retries callbacks and the customer's redirect
   can race the callback, so `record_purchase()` takes a named database lock
   (`GET_LOCK`/`RELEASE_LOCK`, `LOCK_TIMEOUT_SECONDS`) around a check-then-create. Without it,
   two concurrent deliveries create two payment records for one gateway purchase. The listener
   must NOT take a second lock — `record_purchase()` already serialises on the purchase.
5. **Every callback is verified before it is trusted.** The `X-Signature` header is checked
   against the account public key with `openssl_verify`; on failure the plugin falls back to
   asking CHIP for the payment status rather than acting on an unverified body.
6. **Refunds are gated on the gateway's own availability.** A purchase settled without an
   acquirer cannot be refunded by the API ("invalid enum for type Acquirer: None"), so the
   plugin refuses with the real reason instead of a vague error.
7. **A failed payment re-query must not mark anything paid.** The CHIP callback is the
   authority on final status.

## Rules that are not obvious

- **`uninstall.php` cannot use the plugin's constants** — the plugin is not loaded during
  uninstall. Write option names literally.
- **`readme.txt` carries only the current release.** `changelog.txt` keeps the full history.
  `scripts/bump-version.sh` enforces this and fails if the readme has more than one entry.
- **The payment page's line items come from the invoice**, never hardcoded: the original
  prototype sent "test product name" to the gateway.
- **The customer is returned to the invoice with a message**, not to an anonymous callback
  URL.
- **Redirecting to the checkout uses `wp_redirect`, not `wp_safe_redirect`.** The CHIP payment
  page is an external host by design and `wp_safe_redirect()` refuses hosts outside
  `allowed_redirect_hosts`, which would strand the customer in wp-admin.
- **A `phpcs:ignore` applies to the NEXT line.** Put the explanatory comment first, then the
  ignore immediately above the code it covers; reversed, it silently suppresses nothing.
- **Never exclude a whole file in `phpcs.xml`.** A file-level exclusion for one legitimate
  finding hides every other sniff in that file. Suppress per line at the call site.
- **`platform` is a gateway-side allow-list.** `sproutinvoices` is rejected with
  `"sproutinvoices" is not a valid choice.` — the plugin sends `api` and identifies itself
  through `creator_agent`.
- **CHIP returns validation errors as a bare field map at the top level**
  (`{"platform":[{"message":"…"}]}`), not only wrapped in `errors`.
  `describe_error_body()` must keep handling both, while never mistaking a successful payload
  for one.
- **Never tag or release without an explicit OK.**

## Tests

`tests/Unit/` holds the regression tests. A new test is only valid once it has been shown to
bite:

1. Confirm it FAILS against the code before the fix.
2. Mutate the fix and confirm each mutation turns the suite red.

A test that greps source text for a line proves the line exists, not that the behaviour is
correct — assert behaviour (call the code, compare results). Beware of picking input values
that do not actually distinguish the two implementations: `28.99 * 100` is exactly `2899.0`
in PHP, so it passes under both rounding and truncation. Use `19.99`, `0.29` or `70.10`.

`Chip_Sprout_Invoices_LoadTest` requires the classes together against stubs in
`tests/bootstrap.php`, because `php -l` cannot catch a rename that breaks a cross-file
reference.

## Commits and PRs

- Commit as `Wan Zulkarnain <wanzulkarnain69@gmail.com>`.
- PRs are single-concern and small.
- Check `git branch --show-current` before editing; this checkout is shared.
- Push fast-forward only — **never `--force`**.
- Do not name the deployment hosts, internal hosts or IPs in this repository.

## Release

Pushing a `vX.Y.Z` tag creates the GitHub release, builds the zip and attaches both the
versionless and versioned copies (`release.yml`), so
`releases/latest/download/chip-for-sprout-invoices.zip` always resolves. `prepare-release.yml`
(manual dispatch) bumps the version and opens the release PR; `bash scripts/bump-version.sh <version>`
does the bump locally. It verifies its own output and stages nothing when a file did not take
the change.

The tag workflow **builds and uploads the zip itself** — it cannot delegate that to
`release-zip.yml`'s `release: created` trigger, because an event raised by `GITHUB_TOKEN` does
not start a new workflow run. `release-zip.yml` exists only for releases created by hand in
the UI.

`.gitattributes` holds the `export-ignore` list. `git archive` — which the workflows use —
honours it, so anything added to the repo root (tests, tooling, docs) must be added there
too, or it ships to users. Both the Plugin Check job and the release workflow assert the dev
files are absent from the archive for exactly this reason.
