#!/usr/bin/env bash
set -Eeuo pipefail
probe="$GITHUB_WORKSPACE/qa/workloads/database.php"
export LAB_BACKUP_ROOT="$(mktemp -d "$RUNNER_TEMP/mysql-physical-backup.XXXXXXXX")"
worker=''
copy=lab-mysql-physical-restore
cleanup() {
  if [[ -n "$worker" ]]; then kill "$worker" 2>/dev/null || :; wait "$worker" 2>/dev/null || :; fi
  docker logs "$copy" > "$EVIDENCE/physical-restore-container.log" 2>&1 || :
  docker rm -f "$copy" >/dev/null 2>&1 || :
  rm -rf "$LAB_BACKUP_ROOT"
}
trap cleanup EXIT
case "$DB_BACKEND" in
  mysql)
    source="$MYSQL_SERVICE"
    client=mysql
    server=mysqld
    docker exec -e MYSQL_PWD=experiment-root "$source" "$client" -uroot -e "INSTALL PLUGIN clone SONAME 'mysql_clone.so'"
    ;;
  mariadb)
    source="$MARIADB_SERVICE"
    client=mariadb
    server=mariadbd
    docker exec "$source" sh -ec 'command -v mariadb-backup'
    ;;
  *) exit 1 ;;
esac
php "$probe" portable-backup-seed
timeout 180s php "$probe" portable-backup-writer > "$EVIDENCE/physical-backup-writer.log" 2>&1 &
worker=$!
for attempt in {1..300}; do
  [[ -s "$LAB_BACKUP_ROOT/writer-held" ]] && break
  kill -0 "$worker"
  sleep 0.1
done
test -s "$LAB_BACKUP_ROOT/writer-held"
if [[ "$DB_BACKEND" == mysql ]]; then
  timeout 90s docker exec -e MYSQL_PWD=experiment-root "$source" "$client" -uroot -e "CLONE LOCAL DATA DIRECTORY = '/tmp/lab-physical-copy'" 2>&1 | tee "$EVIDENCE/native-backup.log"
else
  timeout 90s docker exec -e MYSQL_PWD=experiment-root "$source" mariadb-backup --backup --target-dir=/tmp/lab-physical-copy --user=root 2>&1 | tee "$EVIDENCE/native-backup.log"
  timeout 60s docker exec "$source" mariadb-backup --prepare --target-dir=/tmp/lab-physical-copy 2>&1 | tee "$EVIDENCE/native-prepare.log"
fi
kill -0 "$worker"
touch "$LAB_BACKUP_ROOT/writer-release"
if ! wait "$worker"; then cat "$EVIDENCE/physical-backup-writer.log"; exit 1; fi
worker=''
php "$probe" mysql-physical-source | tee "$EVIDENCE/physical-source.json"
image="$(docker inspect --format '{{.Config.Image}}' "$source")"
docker run -d --name "$copy" -p 127.0.0.1:3308:3308 --entrypoint sleep "$image" infinity
docker exec "$copy" mkdir -p /tmp/lab-restored
docker exec "$source" tar -C /tmp/lab-physical-copy -cf - . | docker exec -i "$copy" tar -xf - -C /tmp/lab-restored
docker exec "$copy" chown -R mysql:mysql /tmp/lab-restored
docker stop "$source" >/dev/null
docker exec -d "$copy" "$server" --user=mysql --datadir=/tmp/lab-restored --port=3308 --bind-address=0.0.0.0 --socket=/tmp/lab-restored.sock --pid-file=/tmp/lab-restored.pid --skip-log-bin --log-error=/tmp/lab-restored.log
ready=0
for attempt in {1..90}; do
  if docker exec -e MYSQL_PWD=experiment-root "$copy" "$client" -uroot --protocol=socket --socket=/tmp/lab-restored.sock -e 'SELECT 1' >/dev/null 2>&1; then ready=1; break; fi
  sleep 1
done
docker exec "$copy" cat /tmp/lab-restored.log | tee "$EVIDENCE/native-restore.log"
test "$ready" = 1
php "$probe" mysql-physical-verify | tee "$EVIDENCE/physical-restore-reconciliation.json"
