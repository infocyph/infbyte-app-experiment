#!/usr/bin/env bash
set -Eeuo pipefail
probe="$GITHUB_WORKSPACE/qa/workloads/database.php"
export LAB_BACKUP_ROOT="$(mktemp -d "$RUNNER_TEMP/portable-backup.XXXXXXXX")"
worker=''
cleanup() {
  if [[ -n "$worker" ]]; then kill "$worker" 2>/dev/null || :; wait "$worker" 2>/dev/null || :; fi
  rm -rf "$LAB_BACKUP_ROOT"
}
trap cleanup EXIT
php "$probe" portable-backup-seed
timeout 150s php "$probe" portable-backup-writer > "$EVIDENCE/backup-writer.log" 2>&1 &
worker=$!
for attempt in {1..300}; do
  [[ -s "$LAB_BACKUP_ROOT/writer-held" ]] && break
  kill -0 "$worker"
  sleep 0.1
done
test -s "$LAB_BACKUP_ROOT/writer-held"
case "$DB_BACKEND" in
  mysql|mariadb)
    if [[ "$DB_BACKEND" == mysql ]]; then
      service="$MYSQL_SERVICE"; client=mysql; dump=mysqldump
    else
      service="$MARIADB_SERVICE"; client=mariadb; dump=mariadb-dump
    fi
    timeout 90s docker exec -e MYSQL_PWD=experiment-root "$service" "$dump" -uroot --single-transaction --quick --skip-lock-tables --no-tablespaces experiment > "$LAB_BACKUP_ROOT/backup.sql"
    test -s "$LAB_BACKUP_ROOT/backup.sql"
    docker exec -e MYSQL_PWD=experiment-root "$service" "$client" -uroot -e "CREATE DATABASE experiment_active_backup; GRANT ALL PRIVILEGES ON experiment_active_backup.* TO 'experiment'@'%';"
    timeout 45s docker exec -i -e MYSQL_PWD=experiment-root "$service" "$client" -uroot experiment_active_backup < "$LAB_BACKUP_ROOT/backup.sql"
    ;;
  sqlite) timeout 90s php "$probe" portable-backup-sqlite ;;
  mssql) timeout 90s php "$probe" portable-backup-mssql ;;
  *) exit 1 ;;
esac
kill -0 "$worker"
touch "$LAB_BACKUP_ROOT/writer-release"
if ! wait "$worker"; then cat "$EVIDENCE/backup-writer.log"; exit 1; fi
worker=''
php "$probe" portable-backup-verify | tee "$EVIDENCE/backup-reconciliation.log"
