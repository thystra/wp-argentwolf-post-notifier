#!/usr/bin/env bash
# File: tests/wpcli-publication.sh
# Exercise real wp post and wp cron commands against an installed AWPN package.
# Only for disposable WordPress CI sites; creates and deletes posts and campaigns.
set -euo pipefail

if [[ $# -ne 1 || ! -r "$1/wp-load.php" ]]; then
  printf 'Usage: bash tests/wpcli-publication.sh /path/to/disposable/wordpress\n' >&2
  exit 2
fi

wp_root="$(cd "$1" && pwd)"
worker="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/wpcli-publication-check.php"
post_ids=()

wp_test() {
  wp --path="$wp_root" "$@"
}

assert_post_status() {
  local post_id="$1"
  local expected="$2"
  local status
  status="$(wp_test post get "$post_id" --field=post_status)"
  if [[ "$status" != "$expected" ]]; then
    printf 'ERROR: post %s expected %s, got %s\n' "$post_id" "$expected" "$status" >&2
    return 1
  fi
}

check() {
  local mode="$1"
  local post_id="$2"
  local expected_id="${3:-0}"
  AWPN_CLI_TEST_MODE="$mode" \
  AWPN_CLI_TEST_POST_ID="$post_id" \
  AWPN_CLI_TEST_EXPECT_ID="$expected_id" \
    wp_test eval-file "$worker"
}

cleanup() {
  local previous_status="$?"
  local post_id
  trap - EXIT
  for post_id in "${post_ids[@]}"; do
    if [[ "$post_id" =~ ^[1-9][0-9]*$ ]]; then
      if ! check cleanup "$post_id" >/dev/null; then
        printf 'WARNING: could not remove campaign fixture for post %s\n' "$post_id" >&2
        previous_status=1
      fi
      if ! wp_test post delete "$post_id" --force --quiet; then
        printf 'WARNING: could not remove fixture post %s\n' "$post_id" >&2
        previous_status=1
      fi
    fi
  done
  exit "$previous_status"
}
trap cleanup EXIT

# Each actual command invokes a separate WordPress bootstrap via WP-CLI.
# Configure the draft before publishing to match an editor's saved intent.
# Keep fixture IDs in the caller's scope so the EXIT trap can always clean up.
new_draft() {
  local id
  id="$(wp_test post create --post_type=post --post_status=draft \
    --post_title='AWPN disposable WP-CLI qualification' --porcelain)"
  if [[ ! "$id" =~ ^[1-9][0-9]*$ ]]; then
    printf 'ERROR: wp post create did not return a numeric post ID.\n' >&2
    return 1
  fi
  post_ids+=( "$id" )
  wp_test post meta update "$id" _argentwolf_post_notifier_send_intent send --quiet
  wp_test post meta update "$id" _argentwolf_post_notifier_content_mode full --quiet
  created_id="$id"
}

# Immediate publication, published update, unpublish/republish.
new_draft
immediate="$created_id"
check none "$immediate"
wp_test post update "$immediate" --post_status=publish --quiet
assert_post_status "$immediate" publish
initial_id="$(check one "$immediate")"
wp_test post update "$immediate" --post_title='AWPN post-publication edit' --quiet
check one "$immediate" "$initial_id" >/dev/null
wp_test post update "$immediate" --post_status=draft --quiet
check one "$immediate" "$initial_id" >/dev/null
wp_test post update "$immediate" --post_status=publish --quiet
check one "$immediate" "$initial_id" >/dev/null

# Scheduling and a future-post edit must produce no initial campaign. Simulate
# due time with a DB-only fixture change, then invoke WP-CLI's real cron runner.
new_draft
scheduled="$created_id"
future_utc="$(date -u -d '+2 hours' '+%Y-%m-%d %H:%M:%S')"
wp_test post update "$scheduled" --post_status=future --post_date="$future_utc" --quiet
assert_post_status "$scheduled" future
check none "$scheduled"
wp_test post update "$scheduled" --post_title='AWPN scheduled edit' --quiet
assert_post_status "$scheduled" future
check none "$scheduled"
check backdate "$scheduled"
wp_test cron event run publish_future_post --quiet
assert_post_status "$scheduled" publish
check one "$scheduled" >/dev/null

# Manual early publication via separate WP-CLI commands should also reserve a
# campaign exactly at the actual publish action, not while still scheduled.
new_draft
early="$created_id"
wp_test post update "$early" --post_status=future --post_date="$future_utc" --quiet
assert_post_status "$early" future
check none "$early"
now_utc="$(date -u '+%Y-%m-%d %H:%M:%S')"
wp_test post update "$early" --post_status=publish --post_date="$now_utc" --quiet
assert_post_status "$early" publish
check one "$early" >/dev/null

printf 'WP_CLI_PUBLICATION_LIFECYCLE=PASS immediate=1 scheduled=1 manual_early=1 republish=1 recipients=0\n'
