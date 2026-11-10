# Checks and tests

KK Content Exporter has two test levels, both on PHPUnit 9.6, plus static checks:

| Level | Folder | Command | Needs |
|---|---|---|---|
| Unit | `tests/unit/` | `composer run test:unit` | Only `composer install` (WordPress mocked with Brain Monkey) |
| Integration | `tests/integration/` | `composer run test:integration` | `wordpress-develop` and the empty database `kkce_wordpress_test` ([setup](integration/README.md)) |

There are no end-to-end or JavaScript tests. Which level to use for what, and the test-first rule for
bug fixes, are in `AGENTS/AI_BEHAVIOR.md` (*Test-driven workflow*).

## Commands

Run from the plugin root (PowerShell or Git Bash):

```sh
composer run lint              # php -l on every first-party PHP file
composer run phpcs             # WordPress Coding Standards (0 errors, 0 warnings)
composer run phpstan           # PHPStan level 8, no baseline
composer run test:unit         # unit suite
composer run test:integration  # integration suite
composer run test:all          # unit, then integration
composer run check             # lint + phpcs + phpstan + unit (the fast local gate)
```

Arguments after `--` reach PHPUnit:

```sh
composer run test:unit -- --filter KKCE_Autoloader_Unit_Test          # one class
composer run test:unit -- --filter test_version_has_semantic_format   # one test
composer run test:integration -- tests/integration/KKCE_Environment_Integration_Test.php
vendor/bin/phpunit -c phpunit-integration.xml --list-tests            # list the integration tests
```

If a filter matches nothing, PHPUnit prints `No tests executed!` and exits with `0`: always check the
number of tests run.

## What the commit checks

With the hooks active (`composer run hooks:install`), every commit runs: secret scan, `php -l` and
PHPCS (errors only) on the staged PHP files, PHPStan on the whole plugin and the unit suite; the
message is checked for AI attribution, the allowed prefix and the first-line length. The integration suite is not run by the hook:
run it before proposing a commit that touches WordPress behavior. Details:
`AGENTS/GIT_WORKFLOW.md` (*Pre-commit checks*).

## Contract tests

Some unit tests check the consistency of files rather than code: `KKCE_Release_Version_Unit_Test`
(version and requirements across header, `readme.txt`, `VERSION.txt`, `CHANGELOG.md`, Composer, PHPCS
and PHPStan), `KKCE_PHPStan_Config_Unit_Test` (every PHP file analyzed, level ≥ 8, no baseline) and
`KKCE_Agents_Docs_Unit_Test` (AI context documents and their references), `KKCE_Release_Build_Unit_Test`
(`.distignore` and package verification), `KKCE_Composer_Scripts_Unit_Test` (scripts work on a fresh
clone), `KKCE_Git_Ignore_Unit_Test` (credentials, build output and private files ignored by Git),
`KKCE_CI_Workflow_Unit_Test` (GitHub Actions pinned to a commit SHA, with the tag in a comment) and
`KKCE_Secret_Scan_Unit_Test` (runs `.githooks/check-staged-secrets.sh --stdin` through Bash on invented
secrets and on ordinary lines; Bash is needed, as for the Git hooks). When one fails, a file was
updated and another forgotten: fix the data, not the test.

On Windows, scanner tests prefer Git Bash in the standard system or per-user installation folders
over an unqualified `bash` that may launch WSL. Set `KKCE_TEST_BASH` to the full Bash executable path
for a custom installation (PowerShell: `$env:KKCE_TEST_BASH = 'D:/Tools/Git/bin/bash.exe'`).

## Folder map

| Path | Content |
|---|---|
| `bootstrap-unit.php` | Bootstrap of the unit suite (constants, autoloaders) |
| `bootstrap-integration.php` | Bootstrap of the integration suite (framework, database safety check, plugin loading) |
| `unit/` | Unit tests; `unit/support/` holds `KKCE_Unit_Test_Case` |
| `integration/` | Integration tests and the test-framework configuration |
| `phpstan/` | PHPStan bootstrap with the runtime constants |
