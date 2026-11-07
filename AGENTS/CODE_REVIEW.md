# CODE_REVIEW.md

## Purpose and authority

Review rules for Trigger F in `AGENTS/AI_BEHAVIOR.md`. Read this file with `AGENTS/ARCHITECTURE.md`
and `AGENTS/CODING_STANDARDS.md`. Use the applicable official WordPress skills
(`wp-plugin-development`, `wp-rest-api`, `wp-plugin-directory-guidelines`, `wp-phpstan`). If these
documents or a skill conflict, stop the affected work, explain the practical pros and cons, and ask the
operator which instruction to follow.

## Scope and coverage

Review first-party code and its call-sites: PHP under the plugin root and `includes/`, `uninstall.php`,
`bin/`, `.githooks/`, configuration files, test support code and, when they exist, admin JavaScript and
CSS. Exclude third-party internals (`vendor/`). Calls into excluded code remain in scope. Inspect
generated outputs (`.pot`, `.mo`) only against their source; do not duplicate a source finding against
its generated copy. Report inaccessible or unsupported files as coverage limits. Respect the exclusions
and plan below; propose scope changes rather than silently widening a review.

## Inventory, baseline, and phases

For a full review, begin with inventory and baseline before any code batch. Enumerate the tracked files
(`git ls-files`), account for untracked first-party files separately, and record every scoped path as
read, inspected, excluded or pending, with the reason for exclusions. Derive counts from actual files.

Baseline: run `composer run lint`, `composer run phpcs`, `composer run phpstan`,
`composer run test:unit`, `composer run test:integration` (when its setup is available) and
`composer audit`. Record command, time, exit status, tool versions when relevant, and totals. If a
check cannot run, record the reason; never infer a successful baseline. Distinguish pre-existing debt
from regressions against the recorded baseline. Consolidate repetitive lint findings by root cause.

Build phases from the project plan and the current inventory; split large phases into sub-batches of
roughly 3,000 reviewable lines. Review one authorized phase at a time. A scoped review uses the same
evidence rules without marking a full-run batch complete. Preserve the plan and completed phases
recorded in the progress file; reconcile new or changed paths rather than resetting history.

## Checks

- **Export correctness**: mapping of every source type; distinction between missing value, empty
  string, `0` and `false`; type conversions and their failures (omit + log); dates in ISO 8601 UTC and
  date-only values; relative-to-absolute URLs in HTML; image metadata; pagination arithmetic, stable
  ordering (modified GMT + ID), `modified_after` boundaries; behavior without the optional plugins.
- **Data exposure** (highest priority): only `publish` and non password-protected content, also for
  authenticated requests; only mapped keys; sensitive keys blocked; no author e-mail or login; feed
  isolation; disabled or missing feeds return 404.
- **Security**: trust boundaries, sanitization and unslashing, contextual escaping, prepared SQL,
  capabilities and nonces on admin actions, `permission_callback` and argument schemas on REST routes,
  401 vs 403, Application Password flow over HTTPS, import of configurations (validation, imported
  feeds disabled), no secrets in logs. A nonce is not authorization.
- **WordPress integration**: hook timing (nothing translated before `init`), activation/deactivation/
  uninstall idempotence, revisions of the feed meta, capability registration, multisite assumptions,
  PHP 8.3 / WordPress 6.4 compatibility (functions newer than 6.4 guarded).
- **WordPress.org compliance**: prefixes, GPL, no external calls, readme, Plugin Check findings.
- **Performance**: queries per request, unbounded results, meta queries, autoloaded options, work done
  on every request instead of on the plugin's own screens or routes. Explain workload and impact.
- **Accessibility** of admin screens: labels, keyboard, focus, error messages, headings.
- **Maintainability**: duplicated or dead logic, separation of pure logic from WordPress calls,
  naming, i18n (literal text domain, translator comments), documentation consistency.
- **Tooling and tests**: meaningful assertions, failure propagation in hooks and scripts, contract
  tests, test blind spots that hide a concrete risk.
- Report style or documentation findings only with concrete impact; no cosmetic sweeps.

## Evidence and deduplication

Read complete relevant functions and files and check call-sites before reporting. Every confirmed
finding needs a file and line, the triggering input or state, expected and observed behavior, impact,
and a practical correction proposal. Identify who controls sensitive input and the actual execution
path: an unescaped value or a search hit alone does not prove a vulnerability. Honor the intentional
patterns documented in `AGENTS/ARCHITECTURE.md` and `AGENTS/CODING_STANDARDS.md` (for example the
`load_plugin_textdomain()` call). Use the severity levels of `AGENTS/AI_BEHAVIOR.md` conservatively.
Keep unverified hypotheses in a separate "Assumptions and open questions" section; never file them.
Deduplicate against this run and `AGENTS/ISSUES_TODO.md`; extend an existing root cause instead of
creating duplicates. Propose the closure of stale backlog entries with evidence; do not close them
during a read-only review without authorization. Search `AGENTS/ISSUES_RESOLVED.md` only for requested
history or a suspected regression.

## Execution and persistence

Review code without changing it. Do not weaken lint rules or mutate Git state. The only permitted
writes are appending confirmed, deduplicated findings to `AGENTS/ISSUES_TODO.md` (under the section of
their category, with the issue template) after each phase, and updating `AGENTS/CODE_REVIEW_PROGRESS.md`
for a full run. Run relevant non-mutating checks. Runtime checks need an authorized target (the Local
site http://kkce.local); otherwise give the exact command to run and what would confirm or refute the
hypothesis. Never claim an unexecuted check passed. Use the operator timezone for dates. Mask secrets and
personal data in reports.

Progress file: a full run keeps its state in the ignored local file `AGENTS/CODE_REVIEW_PROGRESS.md`.
Bootstrap does not create or read it; Trigger F creates it when a full run starts and updates it at the
end of every authorized phase and whenever a phase is skipped. If it is missing or empty, the run has not
started unless the operator supplies a checkpoint, which takes precedence over the file. Structure:

```
# CODE REVIEW PROGRESS
Run started: <date> · Scope: full
Inventory: <date, tracked and untracked first-party file counts>
Baseline: <command, date, exit status, error totals>
Batches: <n> closed of <total>
- [x] <n>. <name> (<date>; findings: <n>; sub-batches: <3a/3b...>)
- [ ] <n>. <name>
Skipped: <batch and reason>
Agreed exclusions: <paths>
Next proposed phase: <batch>
```

Scoped reviews never update the batch list. Never invent past progress. Reset or overwrite a run only
with explicit authorization. `ISSUES_TODO.md`, `ISSUES_RESOLVED.md` and `CODE_REVIEW_PROGRESS.md` must
never be committed.

## Output

After each phase, report files read versus inspected, confirmed findings (numbered, with the issue
template including `Files affected`), unverified questions (chat only), checks and limitations, and the
next proposed phase. The final report includes category/severity counts for this run, the rationale of
CRITICAL/HIGH findings, up to five recommended corrections, assumptions, and coverage reconciliation;
keep "findings in this run" distinct from the total open entries. A full run is complete only when
coverage is reconciled and the final report is delivered.

## Project-specific plan

Use this plan for a new full run, adapting it to the folders that exist at that moment (empty batches
are recorded as skipped); when resuming, keep the batches recorded in the progress file:

1. Bootstrap and lifecycle: main file, autoloader, `KKCE\Plugin`, activation, deactivation, `uninstall.php`.
2. Feed storage: `kkce_feed` post type, meta registration, revisions, migrations of the configuration.
3. Admin: feed list and editor, validation, preview, duplication, export/import, capabilities and nonces.
4. Sources: core fields, post meta, taxonomies, featured image, language, optional ACF/CMB2/Polylang providers.
5. Output: normalization, dates, HTML and URL handling, missing-value policy, sensitive-key filter.
6. REST endpoints: routes, argument schema, permissions and authentication, queries, pagination, `modified_after`.
7. Logging and operations.
8. Translations and documentation consistency (`readme.txt`, `README.md`, `CHANGELOG.md`, guides).
9. Tooling: `bin/`, `.githooks/`, Composer scripts, PHPCS/PHPStan/PHPUnit configuration, test support.
10. Coverage reconciliation and final report.

Standing exclusions: `vendor/`, `DEV/`, `.phpunit.cache/`, `build/`, compiled translations (`.mo`),
the generated `.pot`, and the local issue and progress files.
