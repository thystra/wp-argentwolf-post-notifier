#!/usr/bin/env bash
# File: scripts/run-plugin-check.sh
#
# Run blocking Plugin Check gates against an installed package candidate.

set -Eeuo pipefail

WP_ROOT="${1:-}"
REPORT_DIR="${2:-}"
SLUG='argentwolf-post-notifier'
PCP_CLI="$WP_ROOT/wp-content/plugins/plugin-check/cli.php"

fail() {
	printf 'ERROR: %s\n' "$*" >&2
	exit 1
}

[[ -n "$WP_ROOT" && -d "$WP_ROOT" ]] || \
	fail 'Usage: scripts/run-plugin-check.sh <wordpress-root> <report-dir>'
[[ -n "$REPORT_DIR" ]] || fail 'Plugin Check report directory is required.'
[[ -f "$PCP_CLI" ]] || fail "Plugin Check CLI bootstrap not found: $PCP_CLI"
mkdir -p "$REPORT_DIR"

run_check() {
	local label="$1"
	local runtime="$2"
	local mode="$3"
	local out="$REPORT_DIR/plugin-check-${label}.txt"
	local rc

	printf '\n===== Plugin Check: %s =====\n' "$label"
	set +e
	if [[ "$runtime" == 'yes' ]]; then
		wp \
			--path="$WP_ROOT" \
			--require="$PCP_CLI" \
			plugin check "$SLUG" \
			--format=strict-table \
			--mode="$mode" 2>&1 | tee "$out"
	else
		wp \
			--path="$WP_ROOT" \
			plugin check "$SLUG" \
			--format=strict-table \
			--mode="$mode" 2>&1 | tee "$out"
	fi
	rc="${PIPESTATUS[0]}"
	set -e

	printf 'plugin_check_exit=%s\n' "$rc"
	[[ "$rc" == 0 ]] || fail "Plugin Check $label returned exit code $rc."

	if grep -Eq '(^|[[:space:]|])(ERROR|WARNING)([[:space:]|]|$)' "$out"; then
		fail "Plugin Check $label emitted ERROR or WARNING findings."
	fi

	grep -Fq 'Checks complete. No errors found.' "$out" || \
		fail "Plugin Check $label did not report clean completion."

	printf 'PLUGIN_CHECK_FINDINGS_GATE=PASS mode=%s runtime=%s\n' "$mode" "$runtime"
}

run_check 'new-static' 'no' 'new'
run_check 'new-runtime' 'yes' 'new'
run_check 'update-runtime' 'yes' 'update'

# EOF: scripts/run-plugin-check.sh
