# Contributing to CHIP for Sprout Invoices

Thank you for your interest in contributing! This document outlines how to set up the
development environment, our coding standards, and how to verify your changes.

## Development Setup

### Prerequisites

- PHP 7.4 or higher (8.0+ recommended)
- Composer (for PHPUnit/PHPCS/WPCS)
- A local WordPress installation with Sprout Invoices 20.4 or newer

### Installation

1. Clone the repository into your WordPress `plugins/` directory:

   ```bash
   cd wp-content/plugins
   git clone https://github.com/CHIPAsia/chip-for-sprout-invoices.git
   cd chip-for-sprout-invoices
   ```

2. Install PHP dependencies:

   ```bash
   composer install --no-interaction --prefer-dist
   ```

## Coding Standards

We follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).
Run the linter before submitting a PR:

```bash
composer lint      # PHPCS — must exit 0
composer lint:fix  # phpcbf autofix
composer compat    # PHPCompatibilityWP, 7.4+
```

### Key Rules

- Use tabs for indentation (not spaces)
- Maximum line length: 120 characters
- Prefix our own globals with `chip_si` / `Chip_Sprout_Invoices` / `CHIP_...`
- Always sanitize input and escape output
- Include an `ABSPATH` guard at the top of every PHP file
- Text domain: `chip-for-sprout-invoices`
- Never edit code on `main` — branch, then open a PR

## Tests

```bash
composer test
```

`tests/Unit/` holds the regression tests. A new test is only valid once it has been shown
to bite:

1. Confirm it FAILS against the code before the fix.
2. Mutate the fix and confirm the mutation turns the suite red.

A test that greps source text for a line proves the line exists, not that the behaviour is
correct — assert behaviour (call the code, compare results).

## Commits and PRs

- Commit as `Wan Zulkarnain <wanzulkarnain69@gmail.com>`.
- PRs are single-concern and small.
- Check `git branch --show-current` before editing; this checkout is shared.
- Push fast-forward only — never `--force`.

## Release

Do not tag a release without an explicit OK from the maintainer. See `AGENTS.md` for the
release flow.
