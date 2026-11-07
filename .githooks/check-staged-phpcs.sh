#!/usr/bin/env bash
#
# Runs PHPCS (phpcs.xml.dist) on the staged PHP files and blocks the commit when any of them has
# an error. Warnings are not reported here and do not block; `composer run phpcs` reports them and
# the project target is zero warnings too. Called by .githooks/pre-commit.
#
# The staged content (the index) is checked, not the working tree, so a partially staged file is
# judged for what is actually being committed. Files excluded by phpcs.xml.dist (tests, bin,
# vendor, ...) are skipped by PHPCS itself.

set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
staged="$(git diff --cached --name-only --diff-filter=ACMR | grep -E '\.php$' || true)"

if [ -z "$staged" ]; then
	exit 0
fi

if [ ! -f "$repo_root/vendor/bin/phpcs" ]; then
	echo "[KKCE pre-commit] PHPCS is not installed. Run 'composer install' and commit again." >&2
	exit 1
fi

echo "[KKCE pre-commit] Running PHPCS (errors only) on the staged PHP files..." >&2

cd "$repo_root"
failed=0

while IFS= read -r file; do
	if ! git show ":$file" | php vendor/bin/phpcs --standard=phpcs.xml.dist --warning-severity=0 --no-cache --stdin-path="$file" --report=full -q -; then
		failed=1
	fi
done <<< "$staged"

if [ "$failed" -ne 0 ]; then
	echo "[KKCE pre-commit] PHPCS found errors in the staged files. Fix them and stage the files again." >&2
	exit 1
fi
