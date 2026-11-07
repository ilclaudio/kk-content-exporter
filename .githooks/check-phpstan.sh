#!/usr/bin/env bash
#
# Runs PHPStan on the whole plugin at the level set in phpstan.neon.dist (the only place where the
# level is written; the same command as `composer run phpstan`). Blocks the commit on any error.
# Called by .githooks/pre-commit. The plugin is small, so the whole analysis is fast and catches
# errors that a staged-files-only run would miss (callers of a changed signature).

set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"

if [ ! -f "$repo_root/vendor/bin/phpstan" ]; then
	echo "[KKCE pre-commit] PHPStan is not installed. Run 'composer install' and commit again." >&2
	exit 1
fi

echo "[KKCE pre-commit] Running PHPStan on the whole plugin..." >&2
(cd "$repo_root" && php vendor/bin/phpstan analyse --no-progress --memory-limit=1G)
