#!/usr/bin/env bash
# File: tests/concurrent-queue.sh
# Exercise delivery-queue leases in independent PHP/WordPress/MySQL processes.
# Must run against a disposable, installed WordPress site with AWPN activated.
# Mail is captured by a pre_wp_mail filter in each process; nothing is sent.
set -euo pipefail

if [[ $# -lt 1 || ! -r "$1/wp-load.php" ]]; then
  printf 'Usage: bash tests/concurrent-queue.sh /path/to/disposable/wordpress [recipients] [workers]\n' >&2
  exit 2
fi

wp_root="$(cd "$1" && pwd)"
recipients="${2:-200}"
workers="${3:-6}"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
worker="$script_dir/concurrent-queue-worker.php"
run_dir="$(mktemp -d "${TMPDIR:-/tmp}/awpn-queue-race.XXXXXXXX")"
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

dump_logs() {
  local log
  for log in "$run_dir"/*.log; do
    if [[ -f "$log" ]]; then
      printf '\n--- %s ---\n' "$(basename "$log")" >&2
      cat "$log" >&2
    fi
  done
}

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
  dump_logs
  return 1
}

php "$worker" seed "$wp_root" "$run_dir" "$recipients"

# A worker claims a batch, submits two rows, then dies holding the rest.
php "$worker" dier "$wp_root" "$run_dir" 5

# Boot every worker first, then release them together so their claims race.
for (( id = 1; id <= workers; id++ )); do
  php "$worker" worker "$wp_root" "$run_dir" "$id" >"$run_dir/worker-$id.log" 2>&1 &
  pids+=( "$!" )
done
for (( id = 1; id <= workers; id++ )); do
  wait_for_file "$run_dir/ready-$id" "worker $id to boot"
done
touch "$run_dir/go"

failed=0
for pid in "${pids[@]}"; do
  if ! wait "$pid"; then
    failed=1
  fi
done
pids=()
if (( failed )); then
  dump_logs
  exit 1
fi

# Deadlocks are expected under contention; QueueRepository retries them.
lock_retries="$(cat "$run_dir"/worker-*.log | grep -c 'Deadlock found' || true)"
printf 'CONCURRENT_QUEUE_LOCK_RETRIES=%s\n' "$lock_retries"

# The dead worker comes back with its stale lease; every write must be refused.
php "$worker" zombie "$wp_root" "$run_dir"
php "$worker" verify "$wp_root" "$run_dir" "$workers"
