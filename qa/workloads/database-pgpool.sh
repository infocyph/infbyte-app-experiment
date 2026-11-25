#!/usr/bin/env bash
set -Eeuo pipefail
probe="$GITHUB_WORKSPACE/qa/workloads/database.php"
root="$(mktemp -d "$RUNNER_TEMP/pgpool.XXXXXXXX")"
chmod 755 "$root"
cleanup() {
  docker logs lab-pgpool > "$EVIDENCE/pgpool.log" 2>&1 || :
  docker rm -f lab-pgpool >/dev/null 2>&1 || :
  rm -rf "$root"
}
trap cleanup EXIT
cat > "$root/pgpool.conf" <<'CONF'
listen_addresses = '127.0.0.1'
port = 16433
unix_socket_directories = '/tmp'
pcp_socket_dir = '/tmp'
pcp_listen_addresses = ''
pid_file_name = '/tmp/pgpool.pid'
logdir = '/tmp'
backend_clustering_mode = 'streaming_replication'
backend_hostname0 = '127.0.0.1'
backend_port0 = 5432
backend_weight0 = 0
backend_flag0 = 'ALLOW_TO_FAILOVER'
backend_hostname1 = '127.0.0.1'
backend_port1 = 5433
backend_weight1 = 1
backend_flag1 = 'ALLOW_TO_FAILOVER'
load_balance_mode = on
disable_load_balance_on_write = 'transaction'
write_function_list = 'nextval,setval'
enable_pool_hba = off
allow_clear_text_frontend_auth = on
num_init_children = 8
max_pool = 2
health_check_period = 2
health_check_timeout = 5
health_check_user = 'experiment'
health_check_password = 'experiment'
health_check_database = 'experiment'
health_check_max_retries = 1
sr_check_period = 2
sr_check_user = 'experiment'
sr_check_password = 'experiment'
sr_check_database = 'experiment'
failover_on_backend_error = off
auto_failback = off
search_primary_node_timeout = 20
CONF
chmod 644 "$root/pgpool.conf"
docker build -t lab-pgpool - < "$GITHUB_WORKSPACE/qa/workloads/Pgpool.Dockerfile"
start_proxy() {
  docker run -d --name lab-pgpool --network host -v "$root:/config:ro" lab-pgpool
}
await_proxy() {
  local expected="$1"
  for attempt in {1..60}; do
    if php -r 'try {$pdo=new PDO("pgsql:host=127.0.0.1;port=16433;dbname=experiment;connect_timeout=2","experiment","experiment"); $rows=$pdo->query("SHOW POOL_NODES")->fetchAll(PDO::FETCH_ASSOC); if (count($rows)!==2 || $rows[0]["status"]!=="up" || $rows[0]["role"]!=="primary" || $rows[1]["status"]!==$argv[1]) exit(1); if ($argv[1]==="up" && $rows[1]["role"]!=="standby") exit(1); echo json_encode($rows, JSON_THROW_ON_ERROR),PHP_EOL;} catch (PDOException) {exit(1);}' "$expected" > "$EVIDENCE/pgpool-$expected.json"; then return; fi
    sleep 1
  done
  return 1
}
php "$probe" pgpool-seed
start_proxy
await_proxy up
timeout 90s php "$probe" pgpool-work | tee "$EVIDENCE/pgpool-work.log"
docker stop lab-standby-5433 >/dev/null
await_proxy down
timeout 60s php "$probe" pgpool-outage | tee "$EVIDENCE/pgpool-outage.log"
docker start lab-standby-5433 >/dev/null
for attempt in {1..60}; do
  if docker exec lab-standby-5433 pg_isready -h 127.0.0.1 -p 5433; then break; fi
  sleep 1
done
docker exec lab-standby-5433 pg_isready -h 127.0.0.1 -p 5433
docker logs lab-pgpool > "$EVIDENCE/pgpool-before-restart.log" 2>&1
docker rm -f lab-pgpool >/dev/null
start_proxy
await_proxy up
timeout 90s php "$probe" pgpool-recover | tee "$EVIDENCE/pgpool-recover.log"
