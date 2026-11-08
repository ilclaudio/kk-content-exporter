#!/usr/bin/env bash
#
# Blocks the commit when the staged changes contain high-signal secret patterns (private keys,
# cloud and GitHub tokens, credentials in URLs, hard-coded passwords). Called by .githooks/pre-commit;
# it can also be run by hand against the staged changes.
#
# With `--stdin` it scans the lines read from standard input instead of the staged changes (one
# added line per input line); tests/unit/KKCE_Secret_Scan_Unit_Test.php uses this mode.

set -euo pipefail

echo "[KKCE pre-commit] Running secret scan..." >&2

if [ "${1:-}" = "--stdin" ]; then
	added_lines="$(cat)"
else
	if git rev-parse --verify HEAD >/dev/null 2>&1; then
		diff_output="$(git diff --cached --unified=0 --no-color --diff-filter=ACMRTUXB)"
	else
		# Initial commit: compare against the empty tree.
		empty_tree="$(git hash-object -t tree /dev/null)"
		diff_output="$(git diff --cached --unified=0 --no-color --diff-filter=ACMRTUXB "$empty_tree")"
	fi

	if [ -z "$diff_output" ]; then
		exit 0
	fi

	# Compiled translation catalogues are binary-like: skip them.
	is_generated_file() {
		case "$1" in
			languages/*.mo) return 0 ;;
		esac
		return 1
	}

	added_lines="$(printf '%s\n' "$diff_output" | awk '
		/^\+\+\+ / {
			file = $2
			sub(/^b\//, "", file)
			next
		}
		/^\+/ {
			sub(/^\+/, "", $0)
			print file "\t" $0
		}
	' | while IFS="$(printf '\t')" read -r file line; do
		if is_generated_file "$file"; then
			continue
		fi
		printf '%s\n' "$line"
	done)"
fi

if [ -z "$added_lines" ]; then
	exit 0
fi

# High-signal patterns only, to keep false positives low. Every quote class holds both quotes: `q`
# is a single quote, because `grep -E` has no `\x27` escape.
q="'"
patterns=(
	'-----BEGIN (RSA |EC |DSA |OPENSSH |PGP )?PRIVATE KEY-----'
	'(AKIA|ASIA)[A-Z0-9]{16}'
	'gh[pousr]_[A-Za-z0-9]{36,255}'
	'github_pat_[A-Za-z0-9_]{20,}'
	'xox[baprs]-[A-Za-z0-9-]{10,}'
	'sk-[A-Za-z0-9]{20,}'
	'sk-ant-[A-Za-z0-9_-]{20,}'
	'[A-Za-z][A-Za-z0-9+.-]*://[^[:space:]]+:[^[:space:]]+@[^[:space:]]+'
	'Authorization:[[:space:]]*Basic[[:space:]]+[A-Za-z0-9+/=]{12,}'
	# Assignments and array items: `$password = '…'`, `"api_key": "…"`, `'secret' => '…'`.
	"(api[_-]?key|client[_-]?secret|access[_-]?token|refresh[_-]?token|password|passwd|pwd|secret)[\"${q}]?[[:space:]]*(=>|[:=])[[:space:]]*[\"${q}][^\"${q}]{8,}[\"${q}]"
	# WordPress constants: `define( 'DB_PASSWORD', '…' )`, `define( 'AUTH_KEY', '…' )`.
	"define[[:space:]]*\\([[:space:]]*[\"${q}][A-Z0-9_]*(PASSWORD|SECRET|_KEY|SALT|TOKEN)[\"${q}][[:space:]]*,[[:space:]]*[\"${q}][^\"${q}]{8,}[\"${q}]"
)

# WordPress Application Passwords: six groups of four letters or digits. Checked apart, case
# sensitive, and only when the match has an uppercase letter and a digit, so that six short words
# of a sentence do not match.
app_pass_shape='(^|[^A-Za-z0-9])[A-Za-z0-9]{4}( [A-Za-z0-9]{4}){5}([^A-Za-z0-9]|$)'

found=0

# Prints only the line numbers of the matches, never the matching values.
report() {
	if [ "$found" -eq 0 ]; then
		echo "[KKCE pre-commit] Potential secret detected in staged changes (line numbers refer to the added lines):" >&2
		found=1
	fi
	printf '%s\n' "$1" | cut -d: -f1 | sed 's/^/  added line /' >&2
}

for pattern in "${patterns[@]}"; do
	matches="$(grep -E -i -n -e "$pattern" <<< "$added_lines" 2>/dev/null || true)"
	if [ -n "$matches" ]; then
		report "$matches"
	fi
done

matches="$(grep -E -n -o -e "$app_pass_shape" <<< "$added_lines" 2>/dev/null | grep -E ':.*[A-Z]' | grep -E ':.*[0-9]' || true)"
if [ -n "$matches" ]; then
	report "$matches"
fi

if [ "$found" -eq 1 ]; then
	echo >&2
	echo "Commit blocked. Remove the secret from the staged changes." >&2
	echo "If this is a false positive, adjust the pattern list in .githooks/check-staged-secrets.sh." >&2
	exit 1
fi

exit 0
