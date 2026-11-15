# Checks and tests

The project has three test levels: PHPUnit unit tests, WordPress integration tests, and Playwright
end-to-end tests. Use the lowest level that verifies the behavior; use E2E tests for browser interactions
and complete administrative flows. The E2E suite runs separately from the fast checks and requires its
own isolated WordPress environment.

| Level | Folder | Command | Needs | Details |
|---|---|---|---|---|
| Unit | `tests/unit/` | `composer run test:unit` | `composer install` only | [unit/README.md](unit/README.md) |
| Integration | `tests/integration/` | `composer run test:integration` | Docker Desktop and `npm ci` | [integration/README.md](integration/README.md) |
| End-to-end | `tests/e2e/` | `composer run e2e` | Docker Desktop, `npm ci`, Chromium | [e2e/README.md](e2e/README.md) |

Which level to use for what, and the test-first rule for bug fixes, are in `AGENTS/AI_BEHAVIOR.md`
(*Test-driven workflow*). How to prepare the machine (Composer, Node, Docker, Chromium): root
[`README.md`](../README.md).

## Commands

Run from the plugin root (PowerShell or Git Bash):

```sh
composer run lint              # php -l on every first-party PHP file
composer run phpcs             # WordPress Coding Standards (0 errors, 0 warnings)
composer run phpstan           # PHPStan level 8, no baseline
composer run test:unit         # unit suite
composer run check             # lint + phpcs + phpstan + unit (the fast local gate)
composer run test:integration  # integration suite in the wp-env test environment (Docker)
composer run test:all          # unit, then integration
composer run e2e               # end-to-end suite (e2e:headed, e2e:ui, e2e:report for debugging)
```

Docker environments (each test command starts its own when needed; they stay on afterwards):

```sh
composer run env:start           # end-to-end environment (http://localhost:8890), to open it in a browser
composer run env:stop            # stop it, data kept
composer run env:destroy         # delete it: containers, database, volumes (no confirmation)
composer run env:tests:start     # integration environment (http://localhost:8891)
composer run env:tests:stop
composer run env:tests:destroy
composer run env:stop:all        # stop both environments
composer run env:destroy:all     # delete both environments
```

Delete or stop the environments only with these commands, not from Docker Desktop: wp-env keeps track of
their state. They hold test data only; the next start recreates what is missing. The Docker and
end-to-end scripts of Composer call the npm scripts of `package.json` of the same name.

Arguments after `--` reach the test runner (PHPUnit or Playwright):

```sh
composer run test:unit -- --filter KKCE_Autoloader_Unit_Test     # PHPUnit: one class or test
composer run test:integration -- --filter KKCE_Feed_Editor       # PHPUnit in Docker
composer run e2e -- tests/e2e/specs/lookup.spec.js               # Playwright: one file
composer run e2e -- -g "previewed"                               # Playwright: tests whose title matches
```

If a PHPUnit filter matches nothing, PHPUnit prints `No tests executed!` and exits with `0`: always check
the number of tests run.

## What runs where

| Check | `pre-commit` hook | CI on every push and pull request | CI started by hand |
|---|---|---|---|
| Lint, PHPCS, PHPStan, unit | Yes (lint and PHPCS on the staged files) | Yes, PHP 8.3 and 8.4 (`ci.yml`) | No |
| `composer audit` | No | Yes (`ci.yml`) | No |
| Integration (Docker) | No | Yes (`functional.yml`) | Yes (`functional.yml`) |
| End-to-end | No | No | Yes (`functional.yml`, *Run workflow*) |

## What the commit checks

With the hooks active (`composer run hooks:install`) the `pre-commit` hook also scans the staged changes
for secrets, and the `commit-msg` hook checks the message (AI attribution, allowed prefix, first-line
length). Run the integration and end-to-end suites by hand before proposing a commit that touches
WordPress behavior or the admin screens. Details: `AGENTS/GIT_WORKFLOW.md` (*Pre-commit checks*).

## Contract tests

Some unit tests check the consistency of files rather than code: `KKCE_Release_Version_Unit_Test`
(version and requirements across header, `readme.txt`, `VERSION.txt`, `CHANGELOG.md`, Composer, PHPCS
and PHPStan), `KKCE_PHPStan_Config_Unit_Test` (every PHP file analyzed, level ≥ 8, no baseline) and
`KKCE_Agents_Docs_Unit_Test` (AI context documents and their references), `KKCE_Release_Build_Unit_Test`
(`.distignore` and package verification), `KKCE_Composer_Scripts_Unit_Test` (scripts work on a fresh
clone), `KKCE_Git_Ignore_Unit_Test` (credentials, build output and private files ignored by Git),
`KKCE_CI_Workflow_Unit_Test` (GitHub Actions pinned to a commit SHA, with the tag in a comment),
`KKCE_E2E_Catalog_Unit_Test` (every end-to-end test listed in the *Test list* of `e2e/README.md`),
`KKCE_Integration_Config_Unit_Test` (the integration suite has only the Docker path, with the defaults of
wp-env) and
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
| `e2e/` | End-to-end tests: `specs/` (Playwright tests), `support/` (global setup and login, test data with WP-CLI, helpers of the feed editor, accessibility check) |
| `bin/` | Node scripts behind the Composer commands: `integration.mjs` (starts the wp-env test environment, runs the integration suite in it) and `e2e.mjs` (starts the development environment, runs Playwright); both first check that Docker answers (`docker.mjs`) |
| `routes/` | Request collections for manual checks of the admin REST routes |
| `phpstan/` | PHPStan bootstrap with the runtime constants |
