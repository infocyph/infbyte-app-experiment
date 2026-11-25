#!/usr/bin/env bash
set -Eeuo pipefail
probe="$GITHUB_WORKSPACE/qa/workloads/database.php"
root="$(mktemp -d "$RUNNER_TEMP/active-backup.XXXXXXXX")"
export LAB_BACKUP_ROOT="$root"
pids=()
cleanup() {
  for pid in "${pids[@]}"; do kill "$pid" 2>/dev/null || :; done
  for pid in "${pids[@]}"; do wait "$pid" 2>/dev/null || :; done
  rm -rf "$root"
}
trap cleanup EXIT
await_file() {
  for attempt in {1..300}; do
    [[ -s "$root/$1" ]] && return
    kill -0 "$2"
    sleep 0.1
  done
  return 1
}
php "$probe" active-backup-seed
timeout 120s php "$probe" active-backup-gate > "$EVIDENCE/backup-gate.log" 2>&1 &
gate=$!
pids+=("$gate")
await_file gate "$gate"
timeout 120s php "$probe" active-backup-snapshot > "$EVIDENCE/backup-snapshot.log" 2>&1 &
snapshot_pid=$!
pids+=("$snapshot_pid")
await_file snapshot "$snapshot_pid"
snapshot="$(cat "$root/snapshot")"
docker exec -e PGAPPNAME=lab-active-backup "$POSTGRES_SERVICE" pg_dump -U experiment -d experiment -Fc --snapshot="$snapshot" --lock-wait-timeout=60000 > "$root/backup.dump" &
dump=$!
pids+=("$dump")
php "$probe" active-backup-wait | tee "$EVIDENCE/backup-overlap.log"
writers=()
for worker in {0..7}; do
  timeout 50s php "$probe" active-backup-write "$worker" > "$EVIDENCE/backup-writer-$worker.log" 2>&1 &
  writers+=("$!")
  pids+=("$!")
done
failed=0
for pid in "${writers[@]}"; do if ! wait "$pid"; then failed=1; fi; done
((failed == 0))
kill -0 "$dump"
touch "$root/gate-release"
wait "$gate"
wait "$dump"
test -s "$root/backup.dump"
touch "$root/snapshot-release"
wait "$snapshot_pid"
pids=()
docker exec "$POSTGRES_SERVICE" createdb -U experiment experiment_active_backup
docker exec -i "$POSTGRES_SERVICE" pg_restore -U experiment -d experiment_active_backup --exit-on-error < "$root/backup.dump"
php "$probe" active-backup-verify | tee "$EVIDENCE/backup-verify.log"
