#!/usr/bin/env bash
# File: tests/concurrent-campaigns.sh
# Exercise publication observers in independent PHP/WordPress/MySQL processes.
# Must run against a disposable, installed WordPress site with AWPN activated.
set -euo pipefail

if [[ $# -ne 1 || ! -r "$1/wp-load.php" ]]; then
  printf 'Usage: bash tests/concurrent-campaigns.sh /path/to/disposable/wordpress\n' >&2
  exit 2
fi

wp_root="$(cd "$1" && pwd)"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
worker="$script_dir/concurrent-campaign-worker.php"
run_dir="$(mktemp -d "${TMPDIR:-/tmp}/awpn-campaign-race.XXXXXXXX")"
pids=()

cleanup() {
  local pid
  for pid in "${pids[@]}"; do
    kill "$pid" 2>/dev/null || true
  done
  if (( ${#pids[@]} > 0 )); then
    wait "${pids[@]}" 2>/dev/null || true
  fi
  php "$worker" cleanup "$wp_root" "$run_dir" >/dev/null 2>&1 || true
  rm -rf -- "$run_dir"
}
trap cleanup EXIT

wait_for_file() {
  local path="$1"
  local description="$2"
  local attempt
  for (( attempt = 0; attempt < 450; attempt++ )); do
    if [[ -f "$path" ]]; then
      return 0
    fi
    sleep 0.1
  done
  printf 'ERROR: timed out waiting for %s.\n' "$description" >&2
  for log in "$run_dir"/*.log; do
    if [[ -f "$log" ]]; then
      cat "$log" >&2
    fi
  done
  return 1
}

php "$worker" seed "$wp_root" "$run_dir"

# Keep the winning insert uncommitted so actual competing DB connections must
# wait on the unique-key conflict. This is not a sequential re-entry test.
php "$worker" holder "$wp_root" "$run_dir" >"$run_dir/holder.log" 2>&1 &
pids+=( "$!" )
wait_for_file "$run_dir/holder-ready" 'the uncommitted initial campaign'

contenders=5
for (( id = 1; id <= contenders; id++ )); do
  php "$worker" contender "$wp_root" "$run_dir" "$id" >"$run_dir/contender-$id.log" 2>&1 &
  pids+=( "$!" )
done

for (( id = 1; id <= contenders; id++ )); do
  wait_for_file "$run_dir/attempt-$id" "contender $id to enter the observer"
done

# Give the independent DB connections time to reach the held unique key.
# None may finish before the holder commits.
sleep 2
for (( id = 1; id <= contenders; id++ )); do
  if [[ -f "$run_dir/result-$id" ]]; then
    printf 'ERROR: contender %d finished before the unique-key holder committed.\n' "$id" >&2
    exit 1
  fi
done

touch "$run_dir/commit"

failed=0
for pid in "${pids[@]}"; do
  if ! wait "$pid"; then
    failed=1
  fi
done
if (( failed )); then
  for log in "$run_dir"/*.log; do
    printf '\n--- %s ---\n' "$(basename "$log")" >&2
    cat "$log" >&2
  done
  exit 1
fi

pids=()
php "$worker" verify "$wp_root" "$run_dir" "$contenders"
