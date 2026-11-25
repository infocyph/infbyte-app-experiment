#!/usr/bin/env bash
set -Eeuo pipefail
mode="${SCENARIO#db-proxy-}"
[[ "$mode" == session || "$mode" == transaction ]]
probe="$GITHUB_WORKSPACE/qa/workloads/database.php"
root="$(mktemp -d "$RUNNER_TEMP/pgbouncer.XXXXXXXX")"
chmod 755 "$root"
trap 'docker rm -f lab-pgbouncer >/dev/null 2>&1 || :; rm -rf "$root"' EXIT
cat > "$root/pgbouncer.ini" <<CONF
[databases]
experiment = host=127.0.0.1 port=5432 dbname=experiment
[pgbouncer]
listen_addr = 127.0.0.1
listen_port = 16432
auth_type = plain
auth_file = /config/users.txt
pool_mode = $mode
default_pool_size = 3
max_client_conn = 100
max_prepared_statements = 100
unix_socket_dir =
server_reset_query = DISCARD ALL
CONF
printf '"experiment" "experiment"\n' > "$root/users.txt"
chmod 644 "$root/"*
docker build -t lab-pgbouncer - < "$GITHUB_WORKSPACE/qa/workloads/PgBouncer.Dockerfile"
docker run -d --name lab-pgbouncer --network host -v "$root:/config:ro" lab-pgbouncer /config/pgbouncer.ini
ready=0
for i in $(seq 1 40); do
  if php -r 'try {$pdo=new PDO("pgsql:host=127.0.0.1;port=16432;dbname=experiment","experiment","experiment");echo $pdo->query("SELECT 1")->fetchColumn();} catch (PDOException) {exit(1);}' | grep -qx 1; then ready=1; break; fi
  sleep 1
done
test "$ready" = 1
php "$probe" proxy-seed
pids=()
for id in $(seq 1 8); do
  timeout 90s php "$probe" proxy-worker "$id" > "$EVIDENCE/proxy-$id.log" 2>&1 &
  pids+=("$!")
done
failed=0
for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
cat "$EVIDENCE"/proxy-*.log
(( failed == 0 ))
php "$probe" proxy-verify | tee "$EVIDENCE/database.log"
