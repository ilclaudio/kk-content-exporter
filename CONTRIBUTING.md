# Contributing to KK Content Exporter

Thank you for your interest in the project. These rules keep the plugin ready for the
WordPress.org Plugin Directory.

## Before you start

- Install the tools with `composer install` and activate the Git hooks with
  `composer run hooks:install`.
- Work on the `dev` branch or on a prefixed branch (`features/`, `bugfix/`, `refactor/`, `docs/`);
  releases reach `main` from `dev` through a pull request.

## Rules

- Follow the WordPress Coding Standards: `composer run phpcs` must report no errors and no warnings.
- `composer run phpstan` must report no errors; the baseline stays empty.
- Prefix every global name with `kkce_` / `KKCE_`, or place it in the `KKCE` namespace.
- Sanitize input, escape output, check capabilities and nonces.
- Every user-facing string is translatable with the literal text domain `kk-content-exporter`.
- Bug fixes start with a test that fails without the fix. New logic comes with unit or
  integration tests (see `tests/README.md`).
- Code, comments, documentation and commit messages are in English.
- Commit messages: a prefix (`Feature:`, `Bug-fix:`, `Refactor:`, `Docs:`, `Chore:`) and a first line
  under 72 characters.

## Reporting security issues

Do not open a public issue for a vulnerability: follow [`SECURITY.md`](SECURITY.md).
