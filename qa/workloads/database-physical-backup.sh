#!/usr/bin/env bash
set -Eeuo pipefail
probe="$GITHUB_WORKSPACE/qa/workloads/database.php"
export LAB_BACKUP_ROOT="$(mktemp -d "$RUNNER_TEMP/physical-backup.XXXXXXXX")"
pids=()
cleanup() {
  for pid in "${pids[@]}"; do kill "$pid" 2>/dev/null || :; done
  for pid in "${pids[@]}"; do wait "$pid" 2>/dev/null || :; done
  docker logs lab-physical-copy > "$EVIDENCE/physical-restore.log" 2>&1 || :
  docker rm -f lab-physical-copy >/dev/null 2>&1 || :
  rm -rf "$LAB_BACKUP_ROOT"
}
trap cleanup EXIT
php "$probe" physical-backup-seed
docker exec "$POSTGRES_SERVICE" bash -e -c 'printf "\nhost replication experiment 0.0.0.0/0 scram-sha-256\n" >> "$PGDATA/pg_hba.conf"; gosu postgres pg_ctl reload -D "$PGDATA"'
docker run -d --name lab-physical-copy --network host --entrypoint sleep postgres:17 infinity
docker exec lab-physical-copy install -d -o postgres -g postgres -m 700 /tmp/physical
docker exec -e PGPASSWORD=experiment -e PGAPPNAME=lab-physical-backup lab-physical-copy \
  gosu postgres pg_basebackup -h 127.0.0.1 -p 5432 -U experiment -D /tmp/physical -X stream --max-rate=1M --progress \
  > "$EVIDENCE/physical-backup.log" 2>&1 &
backup=$!
pids+=("$backup")
php "$probe" physical-backup-wait
writers=()
for worker in {0..7}; do
  timeout 50s php "$probe" active-backup-write "$worker" > "$EVIDENCE/physical-writer-$worker.log" 2>&1 &
  writers+=("$!")
  pids+=("$!")
done
failed=0
for pid in "${writers[@]}"; do if ! wait "$pid"; then failed=1; fi; done
((failed == 0))
php "$probe" physical-backup-expected
wait "$backup"
pids=()
docker exec lab-physical-copy gosu postgres pg_verifybackup /tmp/physical | tee "$EVIDENCE/physical-manifest.log"
docker stop -t 10 "$POSTGRES_SERVICE" >/dev/null
docker exec -d lab-physical-copy gosu postgres postgres -D /tmp/physical -p 5435 -c listen_addresses=127.0.0.1
for attempt in {1..60}; do
  if docker exec lab-physical-copy pg_isready -h 127.0.0.1 -p 5435; then break; fi
  sleep 1
done
docker exec lab-physical-copy pg_isready -h 127.0.0.1 -p 5435
timeout 45s php "$probe" physical-backup-verify | tee "$EVIDENCE/physical-reconcile.log"
