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
if [[ "$SCENARIO" == db-maxscale-failover ]]; then
  test "$DB_BACKEND" = mariadb
  extra+=(--gtid-strict-mode=ON --log-slave-updates=ON)
fi
root_password="$(php -r 'echo bin2hex(random_bytes(24));')"
replica_password="$(php -r 'echo bin2hex(random_bytes(24));')"
root="$(mktemp -d "$RUNNER_TEMP/binlog.XXXXXXXX")"
cleanup() {
  docker rm -f lab-maxscale lab-binlog-source lab-binlog-reader >/dev/null 2>&1 || :
  rm -rf "$root"
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
  prefix=""
  if [[ "$SCENARIO" == db-maxscale-failover ]]; then prefix="SET SESSION sql_log_bin=0;"; fi
  sql "$role" "$prefix CREATE DATABASE experiment; CREATE USER 'experiment'@'%' IDENTIFIED BY 'experiment'; GRANT ALL PRIVILEGES ON experiment.* TO 'experiment'@'%';"
done
if [[ "$SCENARIO" == db-maxscale-failover ]]; then
  for role in source reader; do
    sql "$role" "SET SESSION sql_log_bin=0; CREATE USER 'lab_replica'@'%' IDENTIFIED BY '$replica_password'; GRANT REPLICATION SLAVE ON *.* TO 'lab_replica'@'%';"
  done
  # Generate one actual GTID event on the source and let the replica receive it.
  sql source 'CREATE TABLE experiment.lab_gtid_seed (id INT PRIMARY KEY); INSERT INTO experiment.lab_gtid_seed VALUES (1);'
else
  sql source "CREATE USER 'lab_replica'@'%' IDENTIFIED BY '$replica_password'; GRANT REPLICATION SLAVE ON *.* TO 'lab_replica'@'%';"
fi
read -r file position _ <<< "$(sql source "$status")"
[[ "$file" =~ ^lab-binlog\.[0-9]+$ && "$position" =~ ^[0-9]+$ ]]
if [[ "$DB_BACKEND" == mysql ]]; then
  sql reader "CHANGE REPLICATION SOURCE TO SOURCE_HOST='127.0.0.1', SOURCE_PORT=23306, SOURCE_USER='lab_replica', SOURCE_PASSWORD='$replica_password', SOURCE_LOG_FILE='$file', SOURCE_LOG_POS=$position, GET_SOURCE_PUBLIC_KEY=1;"
elif [[ "$SCENARIO" == db-maxscale-failover ]]; then
  sql reader "CHANGE MASTER TO MASTER_HOST='127.0.0.1', MASTER_PORT=23306, MASTER_USER='lab_replica', MASTER_PASSWORD='$replica_password', MASTER_USE_GTID=slave_pos;"
else
  sql reader "CHANGE MASTER TO MASTER_HOST='127.0.0.1', MASTER_PORT=23306, MASTER_USER='lab_replica', MASTER_PASSWORD='$replica_password', MASTER_LOG_FILE='$file', MASTER_LOG_POS=$position;"
fi
sql reader "$start; SET GLOBAL read_only=ON;"
if [[ "$SCENARIO" == db-maxscale || "$SCENARIO" == db-maxscale-failover ]]; then
  test "$DB_BACKEND" = mariadb
  service_password="$(php -r 'echo bin2hex(random_bytes(24));')"
  for role in source reader; do
    sql "$role" "SET SESSION sql_log_bin=0; CREATE USER 'lab_maxscale'@'%' IDENTIFIED BY '$service_password'; GRANT SELECT ON mysql.* TO 'lab_maxscale'@'%'; GRANT SHOW DATABASES, REPLICA MONITOR ON *.* TO 'lab_maxscale'@'%';"
  done
  monitor_user=lab_maxscale
  monitor_password="$service_password"
  if [[ "$SCENARIO" == db-maxscale-failover ]]; then
    monitor_user=lab_monitor
    monitor_password="$(php -r 'echo bin2hex(random_bytes(24));')"
    for role in source reader; do
      sql "$role" "SET SESSION sql_log_bin=0; CREATE USER 'lab_monitor'@'%' IDENTIFIED BY '$monitor_password'; GRANT REPLICA MONITOR, REPLICATION SLAVE ADMIN, BINLOG ADMIN, RELOAD, PROCESS, SHOW DATABASES, EVENT, SET USER, READ_ONLY ADMIN ON *.* TO 'lab_monitor'@'%';"
    done
  fi
  chmod 755 "$root"
  cat > "$root/maxscale.cnf" <<CONF
[maxscale]
threads=2
admin_host=127.0.0.1
admin_port=18989
[source]
type=server
address=127.0.0.1
port=23306
[reader]
type=server
address=127.0.0.1
port=23307
[monitor]
type=monitor
module=mariadbmon
servers=source,reader
user=$monitor_user
password=$monitor_password
monitor_interval=500ms
[write-service]
type=service
router=readwritesplit
servers=source,reader
user=lab_maxscale
password=$service_password
transaction_replay=false
master_reconnection=true
[write-listener]
type=listener
service=write-service
protocol=MariaDBClient
address=127.0.0.1
port=16033
[reader-service]
type=service
router=readconnroute
router_options=slave
servers=reader
user=lab_maxscale
password=$service_password
[reader-listener]
type=listener
service=reader-service
protocol=MariaDBClient
address=127.0.0.1
port=16034
CONF
  if [[ "$SCENARIO" == db-maxscale-failover ]]; then
    # GTID monitor promotion is automatic; the old source stays fenced.
    sed -i '/^monitor_interval=/a auto_failover=true\nauto_rejoin=false\nfailcount=3' "$root/maxscale.cnf"
  fi
  chmod 644 "$root/maxscale.cnf"
  docker run -d --name lab-maxscale --network host -v "$root/maxscale.cnf:/etc/maxscale.cnf:ro" docker.mariadb.com/maxscale:latest
  ready=0
  for attempt in {1..90}; do
    if docker exec lab-maxscale maxctrl --hosts=127.0.0.1:18989 list servers > "$EVIDENCE/maxscale-initial.txt" 2>/dev/null &&
       grep -E 'source.*(Master|Primary).*Running' "$EVIDENCE/maxscale-initial.txt" >/dev/null &&
       grep -E 'reader.*(Slave|Replica).*Running' "$EVIDENCE/maxscale-initial.txt" >/dev/null; then
      ready=1; break
    fi
    sleep 1
  done
  test "$ready" = 1
  timeout 60s php "$probe" maxscale-seed | tee "$EVIDENCE/maxscale-seed.log"
  timeout 60s php "$probe" maxscale-work | tee "$EVIDENCE/maxscale-work.log"
  if [[ "$SCENARIO" == db-maxscale-failover ]]; then
    source_gtid="$(sql source 'SELECT @@gtid_binlog_pos')"
    reader_gtid="$(sql reader 'SELECT @@gtid_slave_pos')"
    test -n "$source_gtid"
    test "$source_gtid" = "$reader_gtid"
    printf '%s\n' "$source_gtid" > "$EVIDENCE/maxscale-confirmed-gtid.txt"
    docker stop -t 10 lab-binlog-source >/dev/null
    ready=0
    for attempt in {1..120}; do
      if docker exec lab-maxscale maxctrl --hosts=127.0.0.1:18989 list servers > "$EVIDENCE/maxscale-promoted.txt" &&
         grep -E 'source.*Down' "$EVIDENCE/maxscale-promoted.txt" >/dev/null &&
         grep -E 'reader.*(Master|Primary).*Running' "$EVIDENCE/maxscale-promoted.txt" >/dev/null; then ready=1; break; fi
      sleep 1
    done
    test "$ready" = 1
    timeout 60s php "$probe" maxscale-promoted | tee "$EVIDENCE/maxscale-promoted.log"
    test "$(docker inspect -f '{{.State.Running}}' lab-binlog-source)" = false
    exit 0
  fi
  docker stop lab-binlog-reader >/dev/null
  ready=0
  for attempt in {1..45}; do
    if docker exec lab-maxscale maxctrl --hosts=127.0.0.1:18989 list servers > "$EVIDENCE/maxscale-outage.txt" &&
       grep -E 'reader.*Down' "$EVIDENCE/maxscale-outage.txt" >/dev/null; then ready=1; break; fi
    sleep 1
  done
  test "$ready" = 1
  timeout 60s php "$probe" maxscale-outage | tee "$EVIDENCE/maxscale-outage.log"
  docker start lab-binlog-reader >/dev/null
  ready=0
  for attempt in {1..90}; do
    if sql reader 'SELECT 1' 2>/dev/null | grep -qx 1; then ready=1; break; fi
    sleep 1
  done
  test "$ready" = 1
  sql reader 'SET GLOBAL read_only=ON;'
  ready=0
  for attempt in {1..90}; do
    if docker exec lab-maxscale maxctrl --hosts=127.0.0.1:18989 list servers > "$EVIDENCE/maxscale-recovery.txt" &&
       grep -E 'reader.*(Slave|Replica).*Running' "$EVIDENCE/maxscale-recovery.txt" >/dev/null; then ready=1; break; fi
    sleep 1
  done
  test "$ready" = 1
  timeout 60s php "$probe" maxscale-recover | tee "$EVIDENCE/maxscale-recover.log"
  exit 0
fi
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
