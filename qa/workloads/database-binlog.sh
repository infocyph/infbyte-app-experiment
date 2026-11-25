#!/usr/bin/env bash
set -Eeuo pipefail
probe="$GITHUB_WORKSPACE/qa/workloads/database.php"
case "$DB_BACKEND" in
  mysql) image=mysql:8.4; command=mysql; root_env=MYSQL_ROOT_PASSWORD; status='SHOW BINARY LOG STATUS'; start='START REPLICA'; pause='STOP REPLICA SQL_THREAD'; resume='START REPLICA SQL_THREAD' ;;
  mariadb) image=mariadb:11.4; command=mariadb; root_env=MARIADB_ROOT_PASSWORD; status='SHOW MASTER STATUS'; start='START SLAVE'; pause='STOP SLAVE SQL_THREAD'; resume='START SLAVE SQL_THREAD' ;;
  *) exit 1 ;;
esac
extra=()
if [[ "$DB_BACKEND" == mysql ]]; then extra+=(--mysqlx=0); fi
root_password="$(php -r 'echo bin2hex(random_bytes(24));')"
replica_password="$(php -r 'echo bin2hex(random_bytes(24));')"
cleanup() {
  docker rm -f lab-binlog-source lab-binlog-reader >/dev/null 2>&1 || :
}
trap cleanup EXIT
for spec in source:23306:41 reader:23307:42; do
  IFS=: read -r role port id <<< "$spec"
  docker run -d --name "lab-binlog-$role" --network host \
    -e "$root_env=$root_password" "$image" \
    --port="$port" --bind-address=127.0.0.1 --server-id="$id" \
    --log-bin=lab-binlog --binlog-format=ROW --relay-log=lab-relay "${extra[@]}"
done
sql() {
  local port=23306
  if [[ "$1" == reader ]]; then port=23307; fi
  docker exec -e MYSQL_PWD="$root_password" "lab-binlog-$1" "$command" -h 127.0.0.1 -P "$port" -uroot -N -B -e "$2"
}
for role in source reader; do
  for attempt in $(seq 1 90); do
    if sql "$role" 'SELECT 1' 2>/dev/null | grep -qx 1; then break; fi
    sleep 1
  done
  sql "$role" 'SELECT 1' | grep -qx 1
  sql "$role" "CREATE DATABASE experiment; CREATE USER 'experiment'@'%' IDENTIFIED BY 'experiment'; GRANT ALL PRIVILEGES ON experiment.* TO 'experiment'@'%';"
done
sql source "CREATE USER 'lab_replica'@'%' IDENTIFIED BY '$replica_password'; GRANT REPLICATION SLAVE ON *.* TO 'lab_replica'@'%';"
read -r file position _ <<< "$(sql source "$status")"
[[ "$file" =~ ^lab-binlog\.[0-9]+$ && "$position" =~ ^[0-9]+$ ]]
if [[ "$DB_BACKEND" == mysql ]]; then
  sql reader "CHANGE REPLICATION SOURCE TO SOURCE_HOST='127.0.0.1', SOURCE_PORT=23306, SOURCE_USER='lab_replica', SOURCE_PASSWORD='$replica_password', SOURCE_LOG_FILE='$file', SOURCE_LOG_POS=$position, GET_SOURCE_PUBLIC_KEY=1;"
else
  sql reader "CHANGE MASTER TO MASTER_HOST='127.0.0.1', MASTER_PORT=23306, MASTER_USER='lab_replica', MASTER_PASSWORD='$replica_password', MASTER_LOG_FILE='$file', MASTER_LOG_POS=$position;"
fi
sql reader "$start; SET GLOBAL read_only=ON;"
php "$probe" binlog-seed | tee "$EVIDENCE/binlog-seed.log"
sql reader "$pause"
php "$probe" binlog-lag | tee "$EVIDENCE/binlog-lag.log"
sql reader "$resume"
php "$probe" binlog-catchup | tee "$EVIDENCE/binlog-catchup.log"
docker stop lab-binlog-reader >/dev/null
php "$probe" binlog-outage | tee "$EVIDENCE/binlog-outage.log"
docker start lab-binlog-reader >/dev/null
for attempt in $(seq 1 90); do
  if sql reader 'SELECT 1' 2>/dev/null | grep -qx 1; then break; fi
  sleep 1
done
sql reader 'SELECT 1' | grep -qx 1
sql reader 'SET GLOBAL read_only=ON;'
php "$probe" binlog-recover | tee "$EVIDENCE/binlog-recover.log"
