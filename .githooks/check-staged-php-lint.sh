#!/usr/bin/env bash
#
# Runs `php -l` on the staged content (the index, not the working tree) of every staged PHP file
# and blocks the commit on a syntax error. Called by .githooks/pre-commit.

set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
staged="$(git diff --cached --name-only --diff-filter=ACMR | grep -E '\.php$' || true)"

if [ -z "$staged" ]; then
	exit 0
fi

echo "[KKCE pre-commit] Running php -l on the staged PHP files..." >&2

cd "$repo_root"
failed=0

while IFS= read -r file; do
	if ! output="$(git show ":$file" | php -l 2>&1)"; then
		echo "$file: $output" >&2
		failed=1
	fi
done <<< "$staged"

if [ "$failed" -ne 0 ]; then
	echo "[KKCE pre-commit] PHP syntax errors in the staged files. Fix them and stage the files again." >&2
	exit 1
fi
