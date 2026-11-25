#!/usr/bin/env bash
set -Eeuo pipefail
probe="$GITHUB_WORKSPACE/qa/workloads/database-cluster-ha.php"
password="$(php -r 'echo bin2hex(random_bytes(24));')"
recovery_password="$(php -r 'echo bin2hex(random_bytes(24));')"
cleanup() {
  for n in 1 2 3; do
    docker rm -f "lab-ha-$n" >/dev/null 2>&1 || :
    docker volume rm "lab-ha-data-$n" >/dev/null 2>&1 || :
  done
  docker network rm lab-ha >/dev/null 2>&1 || :
}
trap cleanup EXIT
docker network create lab-ha >/dev/null
case "$DB_BACKEND" in
  mysql) image=mysql:8.4; cli=mysql; root_env=MYSQL_ROOT_PASSWORD ;;
  mariadb) image=mariadb:11.4; cli=mariadb; root_env=MARIADB_ROOT_PASSWORD ;;
  *) exit 1 ;;
esac
sql() {
  local n="$1"
  docker exec -e MYSQL_PWD="$password" "lab-ha-$n" "$cli" -h 127.0.0.1 -uroot -N -B -e "$2"
}
wait_sql() {
  for attempt in {1..120}; do
    if sql "$1" 'SELECT 1' 2>/dev/null | grep -qx 1; then return; fi
    sleep 1
  done
  return 1
}
start_node() {
  local n="$1" bootstrap="$2" port="$((23305 + $1))"
  local args=()
  if [[ "$DB_BACKEND" == mysql ]]; then
    args=(
      --mysqlx=0 --server-id="$n" --report-host="lab-ha-$n" --log-bin=lab-binlog
      --gtid-mode=ON --enforce-gtid-consistency=ON --log-replica-updates=ON --binlog-format=ROW
      --plugin-load-add=group_replication.so
      --group-replication-group-name=8ae961e6-5ab5-4520-b2a1-4314509a3c36
      --group-replication-start-on-boot=OFF --group-replication-bootstrap-group=OFF
      --group-replication-local-address="lab-ha-$n:33061"
      --group-replication-group-seeds=lab-ha-1:33061,lab-ha-2:33061,lab-ha-3:33061
      --group-replication-single-primary-mode=ON --group-replication-communication-stack=XCOM
      --group-replication-ssl-mode=DISABLED --group-replication-recovery-get-public-key=ON
      --group-replication-recovery-reconnect-interval=2
      --group-replication-member-expel-timeout=5 --group-replication-unreachable-majority-timeout=5
      --group-replication-autorejoin-tries=0 --group-replication-exit-state-action=READ_ONLY
    )
  else
    args=(
      --server-id="$n" --binlog-format=ROW --innodb-autoinc-lock-mode=2
      --wsrep-on=ON --wsrep-provider=/usr/lib/galera/libgalera_smm.so
      --wsrep-cluster-name=lab-ha --wsrep-cluster-address=gcomm://lab-ha-1,lab-ha-2,lab-ha-3
      --wsrep-node-name="lab-ha-$n" --wsrep-node-address="lab-ha-$n"
      --wsrep-sst-method=rsync --wsrep-sync-wait=1
    )
    if [[ "$bootstrap" == yes ]]; then args+=(--wsrep-new-cluster); fi
  fi
  docker run -d --name "lab-ha-$n" --hostname "lab-ha-$n" --network lab-ha \
    -p "127.0.0.1:$port:3306" -v "lab-ha-data-$n:/var/lib/mysql" \
    -e "$root_env=$password" "$image" --bind-address=0.0.0.0 "${args[@]}" >/dev/null
  wait_sql "$n"
}
members() {
  sql "$1" 'SELECT MEMBER_HOST,MEMBER_STATE,MEMBER_ROLE FROM performance_schema.replication_group_members ORDER BY MEMBER_HOST'
}
galera_status() {
  sql "$1" "SHOW GLOBAL STATUS WHERE Variable_name IN ('wsrep_cluster_status','wsrep_cluster_size','wsrep_local_state_comment','wsrep_ready')"
}
wait_group() {
  local n="$1" expected="$2" data
  for attempt in {1..120}; do
    if [[ "$DB_BACKEND" == mysql ]]; then
      data="$(members "$n" 2>/dev/null || :)"
      if [[ "$(grep -c $'\tONLINE\t' <<< "$data" || :)" == "$expected" ]]; then return; fi
    else
      data="$(galera_status "$n" 2>/dev/null || :)"
      if grep -qx $'wsrep_cluster_status\tPrimary' <<< "$data" \
        && grep -qx $'wsrep_cluster_size\t'"$expected" <<< "$data" \
        && grep -qx $'wsrep_local_state_comment\tSynced' <<< "$data" \
        && grep -qx $'wsrep_ready\tON' <<< "$data"; then return; fi
    fi
    sleep 1
  done
  return 1
}
start_mysql_group() {
  sql "$1" "START GROUP_REPLICATION USER='lab_recovery', PASSWORD='$recovery_password';"
}
if [[ "$DB_BACKEND" == mysql ]]; then
  for n in 1 2 3; do
    start_node "$n" no
    sql "$n" "SET SESSION sql_log_bin=0; CREATE USER 'lab_recovery'@'%' IDENTIFIED BY '$recovery_password'; GRANT REPLICATION SLAVE, CONNECTION_ADMIN ON *.* TO 'lab_recovery'@'%'; CREATE USER 'experiment'@'%' IDENTIFIED BY 'experiment'; GRANT ALL PRIVILEGES ON experiment.* TO 'experiment'@'%'; SET PERSIST_ONLY super_read_only=ON;"
  done
  sql 1 'SET GLOBAL group_replication_bootstrap_group=ON;'
  start_mysql_group 1
  sql 1 'SET GLOBAL group_replication_bootstrap_group=OFF;'
  wait_group 1 1
  for n in 2 3; do
    start_mysql_group "$n"
    wait_group 1 "$n"
    wait_group "$n" "$n"
  done
  sql 1 'CREATE DATABASE experiment'
else
  docker run --rm --entrypoint sh "$image" -ec 'test -f /usr/lib/galera/libgalera_smm.so; command -v rsync' >/dev/null
  start_node 1 yes
  wait_group 1 1
  sql 1 "CREATE DATABASE experiment; CREATE USER 'experiment'@'%' IDENTIFIED BY 'experiment'; GRANT ALL PRIVILEGES ON experiment.* TO 'experiment'@'%';"
  for n in 2 3; do
    start_node "$n" no
    wait_group 1 "$n"
    wait_group "$n" "$n"
  done
fi
run_phase() {
  timeout 90s php "$probe" "$@" | tee "$EVIDENCE/cluster-$1-$2.log"
}
run_phase seed 1
for n in 1 2 3; do
  wait_group "$n" 3
  run_phase verify "$n" 24
  if [[ "$DB_BACKEND" == mysql ]]; then members "$n" > "$EVIDENCE/members-initial-$n.txt"; else galera_status "$n" > "$EVIDENCE/members-initial-$n.txt"; fi
done
if [[ "$SCENARIO" == *-quorum ]]; then
  # Crash rather than graceful leave: preserve the actual quorum boundary.
  docker kill lab-ha-2 lab-ha-3 >/dev/null
  ready=0
  for attempt in {1..90}; do
    if [[ "$DB_BACKEND" == mysql ]]; then
      if sql 1 'SELECT @@super_read_only' 2>/dev/null | grep -qx 1; then ready=1; break; fi
    else
      if galera_status 1 2>/dev/null | grep -ixq $'wsrep_cluster_status\tnon-Primary'; then ready=1; break; fi
    fi
    sleep 1
  done
  test "$ready" = 1
  run_phase minority 1
  if [[ "$DB_BACKEND" == mysql ]]; then members 1 > "$EVIDENCE/members-minority.txt"; else galera_status 1 > "$EVIDENCE/members-minority.txt"; fi
  exit
fi
if [[ "$DB_BACKEND" == mysql ]]; then
  members 1 | grep -qx $'lab-ha-1\tONLINE\tPRIMARY'
  run_phase secondary 2 24
fi
docker kill lab-ha-1 >/dev/null
wait_group 2 2
wait_group 3 2
if [[ "$DB_BACKEND" == mysql ]]; then
  elected="$(members 2 | awk -F '\t' '$2 == "ONLINE" && $3 == "PRIMARY" { print $1 }')"
  case "$elected" in lab-ha-2) writer=2; reader=3 ;; lab-ha-3) writer=3; reader=2 ;; *) exit 1 ;; esac
  members 2 > "$EVIDENCE/members-elected.txt"
else
  writer=2; reader=3
  galera_status 2 > "$EVIDENCE/members-elected.txt"
fi
run_phase append "$writer"
run_phase verify "$reader" 48
if [[ "$DB_BACKEND" == mysql ]]; then
  run_phase secondary "$reader" 48
  docker start lab-ha-1 >/dev/null
  wait_sql 1
  start_mysql_group 1
else
  # Reuse durable data, never restart with the bootstrap flag.
  docker rm lab-ha-1 >/dev/null
  start_node 1 no
fi
for n in 1 2 3; do
  wait_group "$n" 3
  run_phase verify "$n" 48
  if [[ "$DB_BACKEND" == mysql ]]; then members "$n" > "$EVIDENCE/members-rejoined-$n.txt"; else galera_status "$n" > "$EVIDENCE/members-rejoined-$n.txt"; fi
done
if [[ "$DB_BACKEND" == mysql ]]; then run_phase secondary 1 48; fi
echo "Actual member crash, replacement writes, durable rejoin and exact ledger passed"
