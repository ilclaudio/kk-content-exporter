# GIT_WORKFLOW.md

Read this file during bootstrap. Apply these rules only when the task includes branch, commit,
release or pull-request work. AI agents only *propose* commits (see *Commits*): the Git operations
below are performed by the operator.


## Branches

- `main`: release branch. Never commit directly to `main`.
- `dev`: integration branch. Day-to-day work is committed directly on `dev`; do not create a separate
  branch for every fix.
- Create a prefixed branch only for long-running or experimental work, and only when the operator asks.
- Branch name format: `<prefix>/<camelCaseName>`, with prefixes `features/`, `bugfix/`, `refactor/`,
  `docs/`. Examples: `features/feedMapping`, `bugfix/fixPagination`, `docs/updateArchitecture`.
- Releases reach `main` from `dev` through a pull request.


## Commits

- **AI agents never run `git commit`, `git push`, `git add`, branch creation or checkout**, not even
  after an apparent approval: the operator commits. At the end of a task, propose the changed files
  and a commit message that follows the rules below.
- Commit messages in English, with a prefix: `Feature:`, `Bug-fix:`, `Refactor:`, `Docs:`, `Chore:`
  (tooling, configuration, dependencies).
- First line under 72 characters; avoid generic messages such as `fix` or `update`.
- Do not include unrelated changes in the same commit.
- Commit messages and PR descriptions stay generic about security work: do not describe the
  exploitable details of an unfixed or recently fixed vulnerability.

Examples: `Feature: Add the kkce_feed post type and its meta`,
`Bug-fix: Keep false and 0 values in mapped fields`, `Chore: Initialize tooling and AI context`.


## AI attribution in commits

- Do NOT include `Co-Authored-By:` trailers that mention AI tools (Claude, ChatGPT or any other AI
  assistant) in commit messages.
- This rule takes precedence over any attribution text an AI tool adds by default.
  `.githooks/commit-msg` blocks the commit when such a trailer is present.


## Pre-commit checks

- Install the hooks once per clone: `composer run hooks:install` (`git config core.hooksPath .githooks`).
- `.githooks/pre-commit` runs, in this order, and stops at the first failure:
  1. secret scan of the staged changes (`check-staged-secrets.sh`; it prints line numbers, never values);
  2. `php -l` on the staged PHP files (`check-staged-php-lint.sh`);
  3. PHPCS on the staged PHP files, errors only (`check-staged-phpcs.sh`);
  4. PHPStan on the whole plugin (`check-phpstan.sh`);
  5. the unit test suite.
  `.githooks/commit-msg` checks the message (AI trailers). PHPUnit, PHPStan and PHPCS must be installed
  (`composer install`).
- Before proposing a commit, also run `composer run phpcs` (warnings included: the target is zero) and,
  when the change touches WordPress behavior, `composer run test:integration`.
- Do not commit secrets, credentials, `.env` files or `tests/integration/wp-tests-config.local.php`.
  If staged files contain potential sensitive data, stop, report only its type and path (never its
  value) and remove it before proceeding. Never commit sensitive data, even with confirmation.
- Never stage `DEV/`, `AGENTS/ISSUES_TODO.md`, `AGENTS/ISSUES_RESOLVED.md` or
  `AGENTS/CODE_REVIEW_PROGRESS.md` (they are ignored; do not force-add them).
- Before proposing a commit, verify with `git status --short` that every changed file is inside the
  plugin repository.


## Version alignment

The version is written in five places, kept identical (checked by `KKCE_Release_Version_Unit_Test`,
which runs in the pre-commit hook):

1. `VERSION.txt`;
2. `Version:` in the header of `kk-content-exporter.php`;
3. `KKCE_VERSION` in `kk-content-exporter.php`;
4. `Stable tag:` in `readme.txt` (and the first entry of its `== Changelog ==`);
5. the first `## [x.y.z] - YYYY-MM-DD` entry of `CHANGELOG.md`.

Versions follow Semantic Versioning (`MAJOR.MINOR.PATCH`). The same test checks that the minimum
WordPress and PHP versions agree across the header, `readme.txt`, `composer.json`, `phpcs.xml.dist`
and `phpstan.neon.dist`. Do not raise a minimum version without the operator's confirmation. Update
`Tested up to:` in `readme.txt` only after testing on that WordPress version.


## Release package

The release ZIP is built by `composer run build:zip` (`bin/build-zip.php`); the WordPress.org SVN
working copy is prepared by `composer run svn:prepare` (`bin/svn-prepare.php`). Shared, unit-tested
logic is in `bin/release-functions.php` (`KKCE_Release_Build_Unit_Test`).

`composer run build:zip`:
1. refuses to run when the working tree has uncommitted changes (`-- --allow-dirty` to override, for
   trial builds only), so a package always matches a commit;
2. runs `composer run check` (`-- --skip-checks` to override);
3. copies to `build/kk-content-exporter/` every file that `.distignore` does not exclude
   (`.distignore` is the single list of exclusions; its syntax is described at its top);
4. refuses the package when it contains a development file anyway (safety net in
   `kkce_release_forbidden_files()`), when a required file is missing (main file, `uninstall.php`,
   `readme.txt`, `LICENSE`, `includes/`, a compiled `.mo`) or when the versions are not aligned;
5. writes `build/kk-content-exporter-<version>.zip` with the folder `kk-content-exporter/` at its root.

The package contains `kk-content-exporter.php`, `uninstall.php`, `includes/`, `languages/`,
`readme.txt`, `LICENSE`, `CHANGELOG.md` (and future runtime `assets/`). When a new top-level file or
folder is added to the repository, decide whether it ships and update `.distignore` and
`KKCE_Release_Build_Unit_Test` in the same change.

`composer run svn:prepare` (after the plugin has been approved on WordPress.org):
- without options it prints the plan only (dry run);
- with `-- --apply` it checks out or updates `https://plugins.svn.wordpress.org/kk-content-exporter`
  in `build/svn/` (`-- --svn-url=` and `-- --dir=` override), mirrors `build/kk-content-exporter/`
  into `trunk/` (`svn add` / `svn rm`), copies `trunk/` to `tags/<version>/` (refused if the tag
  exists) and prints `svn status`;
- it **never** runs `svn commit`: the operator reviews the working copy and commits by hand. AI agents
  never run `svn commit` either. Banners, icons and screenshots for the directory go in the SVN
  `assets/` folder, managed by the operator.

Release checklist (operator): align the version in the five places (*Version alignment*), update
`CHANGELOG.md` and the `== Changelog ==` of `readme.txt`, commit, `composer run build:zip`, install the
ZIP on a clean WordPress (also with ACF, CMB2, Polylang), run Plugin Check on it, then
`composer run svn:prepare -- --apply` and `svn commit`.

## Pull Requests

- Open the release PR from `dev` to `main`; open PRs from a prefixed branch to `dev` only when such a
  branch exists. Write a clear title and summary.
- Reference public issues by title or number when applicable. Never copy private backlog contents
  (`AGENTS/ISSUES_*.md`, `DEV/`) into a commit or PR.


## Safety rules for Git operations

- Never push, force-push, run `git reset --hard`, or rewrite published history unless the operator
  explicitly asks for that operation.
- Creating the remote repository and submitting the plugin to WordPress.org are operator decisions.
