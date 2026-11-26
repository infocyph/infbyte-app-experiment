#!/usr/bin/env bash
set -Eeuo pipefail
source "$GITHUB_WORKSPACE/qa/workloads/fixture-container-evidence.sh"
probe="$GITHUB_WORKSPACE/qa/workloads/database.php"
root="$(mktemp -d "$RUNNER_TEMP/pgpool.XXXXXXXX")"
chmod 755 "$root"
controller=""
cleanup() {
  if [[ -n "$controller" ]]; then kill "$controller" 2>/dev/null || :; wait "$controller" 2>/dev/null || :; fi
  fixture_container_evidence lab-pgpool || :
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
pool_passwd = '/config/pool_passwd'
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
if [[ "$SCENARIO" == db-pgpool-failover ]]; then
  # The monitor can request promotion but has no access to the Docker socket.
  mkdir "$root/signal"
  chmod 777 "$root/signal"
  cat > "$root/failover.sh" <<'HOOK'
#!/bin/sh
set -eu
[ "$1" = 0 ] && [ "$2" = 1 ] && [ "$3" = 0 ] || exit 1
printf '%s %s %s\n' "$1" "$2" "$3" > /signal/request.tmp
mv /signal/request.tmp /signal/request
for attempt in $(seq 1 60); do
  [ ! -f /signal/promoted ] || exit 0
  sleep 1
done
exit 1
HOOK
  chmod 755 "$root/failover.sh"
  printf "%s\n" "failover_command = '/config/failover.sh %d %m %P'" "search_primary_node_timeout = 60" >> "$root/pgpool.conf"
else
  mkdir "$root/signal"
fi
printf "experiment:TEXTexperiment\n" > "$root/pool_passwd"
printf "experiment:%s\n" "$(printf experiment | md5sum | cut -d " " -f1)" > "$root/pcp.conf"
chmod 644 "$root/pgpool.conf" "$root/pool_passwd" "$root/pcp.conf"
docker build -t lab-pgpool - < "$GITHUB_WORKSPACE/qa/workloads/Pgpool.Dockerfile"
start_proxy() {
  docker run -d --name lab-pgpool --network host -v "$root:/config:ro" -v "$root/signal:/signal:rw" lab-pgpool
}
# SHOW POOL_NODES is a Pgpool control command, not PostgreSQL SQL.
# Use psql's simple-query path rather than PDO's native prepared protocol.
# The application below still uses the original DBLayer/PDO configuration.
await_proxy() {
  local primary="$1" standby="$2" label="$3"
  local csv="$EVIDENCE/pgpool-$label.csv" error="$EVIDENCE/pgpool-$label-error.log"
  for attempt in {1..60}; do
    if [[ "$(docker inspect -f '{{.State.Running}}' lab-pgpool)" != true ]]; then return 1; fi
    if timeout 5s docker exec -e PGPASSWORD=experiment lab-pgpool psql -X --csv -v ON_ERROR_STOP=1 \
      -h 127.0.0.1 -p 16433 -U experiment -d experiment -c 'SHOW POOL_NODES' > "$csv" 2> "$error" \
      && php -r '
        $file = fopen($argv[1], "r");
        $header = fgetcsv($file, escape: "");
        if ($header === false) exit(1);
        $nodes = [];
        while (($row = fgetcsv($file, escape: "")) !== false) {
            if (count($row) !== count($header)) exit(1);
            $node = array_combine($header, $row);
            $nodes[(int) $node["node_id"]] = $node;
        }
        fclose($file);
        echo json_encode($nodes, JSON_THROW_ON_ERROR), PHP_EOL;
        if (count($nodes) !== 2 || !isset($nodes[0], $nodes[1])) exit(1);
        foreach ([0 => $argv[2], 1 => $argv[3]] as $id => $expected) {
            [$status, $role] = explode(":", $expected);
            if ($nodes[$id]["status"] !== $status || ($role !== "*" && $nodes[$id]["role"] !== $role)) exit(1);
        }
      ' "$csv" "$primary" "$standby" > "$EVIDENCE/pgpool-$label.json"; then return; fi
    sleep 1
  done
  cat "$csv" "$error" "$EVIDENCE/pgpool-$label.json" >&2
  return 1
}
# A base backup inherits pg_hba.conf, but the primary sees the Docker bridge
# while the host-network standby sees loopback. Both must negotiate SCRAM.
docker exec "$POSTGRES_SERVICE" bash -ec '
  sed -i "1i host experiment experiment 0.0.0.0/0 scram-sha-256" "$PGDATA/pg_hba.conf"
  gosu postgres pg_ctl reload -D "$PGDATA"
'
docker exec lab-standby-5433 bash -ec '
  sed -i "1i host experiment experiment 127.0.0.1/32 scram-sha-256" /tmp/standby/pg_hba.conf
  gosu postgres pg_ctl reload -D /tmp/standby
'
# Independently check authenticated direct connections, role and password rejection.
php -r '
foreach ([5432 => false, 5433 => true] as $port => $standby) {
    $dsn = "pgsql:host=127.0.0.1;port=$port;dbname=experiment;connect_timeout=3";
    try {
        new PDO($dsn, "experiment", "fixture-invalid-password");
    } catch (PDOException $failure) {
        if (!str_contains($failure->getMessage(), "password authentication failed")) {
            throw $failure;
        }
        $rejected = true;
    }
    if (!($rejected ?? false)) {
        throw new RuntimeException("Backend accepted invalid authentication: $port");
    }
    unset($rejected);
    $pdo = new PDO($dsn, "experiment", "experiment");
    $actual = $pdo->query("SELECT pg_is_in_recovery()")->fetchColumn();
    if (in_array($actual, [true, "t", 1, "1"], true) !== $standby) {
        throw new RuntimeException("Unexpected native backend role: $port");
    }
    echo "Native SCRAM backend $port verified", PHP_EOL;
}' | tee "$EVIDENCE/pgpool-backend-auth.log"
php "$probe" pgpool-seed
start_proxy
await_proxy up:primary up:standby up
timeout 90s php "$probe" pgpool-work | tee "$EVIDENCE/pgpool-work.log"
if [[ "$SCENARIO" == db-pgpool-failover ]]; then
  # Pre-fault verification: both confirmed balances are already on the standby.
  (
    for attempt in {1..120}; do
      [[ ! -s "$root/signal/request" ]] || break
      sleep 1
    done
    test "$(cat "$root/signal/request")" = '0 1 0'
    test "$(docker inspect -f '{{.State.Running}}' "$POSTGRES_SERVICE")" = false
    docker exec -u postgres lab-standby-5433 pg_ctl promote -D /tmp/standby -w -t 30
    test "$(docker exec lab-standby-5433 psql -p 5433 -U experiment -d experiment -Atc 'SELECT pg_is_in_recovery()')" = f
    touch "$root/signal/promoted"
  ) > "$EVIDENCE/pgpool-promotion.log" 2>&1 &
  controller=$!
  test ! -e "$root/signal/request"
  docker stop -t 10 "$POSTGRES_SERVICE" >/dev/null
  if ! wait "$controller"; then cat "$EVIDENCE/pgpool-promotion.log"; exit 1; fi
  controller=""
  cat "$root/signal/request" > "$EVIDENCE/pgpool-failover-trigger.txt"
  await_proxy 'down:*' up:primary promoted
  timeout 60s php "$probe" pgpool-promoted | tee "$EVIDENCE/pgpool-promoted.log"
  test "$(docker inspect -f '{{.State.Running}}' "$POSTGRES_SERVICE")" = false
  exit 0
fi
docker stop lab-standby-5433 >/dev/null
await_proxy up:primary 'down:*' down
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
await_proxy up:primary up:standby up
timeout 90s php "$probe" pgpool-recover | tee "$EVIDENCE/pgpool-recover.log"
