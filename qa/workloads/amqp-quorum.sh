#!/usr/bin/env bash
set -Eeuo pipefail
cd "$PROJECT"
export LAB_QUORUM_ROOT="$(mktemp -d "$RUNNER_TEMP/amqp-quorum.XXXXXXXX")"
worker=""
cleanup() {
  if [[ -n "$worker" ]]; then kill "$worker" 2>/dev/null || :; wait "$worker" 2>/dev/null || :; fi
  for n in 1 2 3; do docker rm -f "lab-q$n" >/dev/null 2>&1 || :; done
  docker network rm lab-quorum >/dev/null 2>&1 || :
  rm -rf "$LAB_QUORUM_ROOT"
}
trap cleanup EXIT
docker network create lab-quorum >/dev/null
cookie="$(openssl rand -hex 24)"
for n in 1 2 3; do
  docker run -d --name "lab-q$n" --hostname "lab-q$n" --network lab-quorum \
    -p "127.0.0.1:$((15671+n)):5672" -e RABBITMQ_ERLANG_COOKIE="$cookie" \
    -e RABBITMQ_DEFAULT_USER=experiment -e RABBITMQ_DEFAULT_PASS=fixture-only-password rabbitmq:4 >/dev/null
  for attempt in {1..60}; do if docker exec "lab-q$n" rabbitmq-diagnostics -q check_running; then break; fi; sleep 1; done
  docker exec "lab-q$n" rabbitmq-diagnostics -q check_running
  if [[ "$n" != 1 ]]; then
    docker exec "lab-q$n" rabbitmqctl stop_app
    docker exec "lab-q$n" rabbitmqctl reset
    docker exec "lab-q$n" rabbitmqctl join_cluster rabbit@lab-q1
    docker exec "lab-q$n" rabbitmqctl start_app
  fi
done
docker exec lab-q1 rabbitmqctl cluster_status > "$EVIDENCE/quorum-cluster.txt"
composer require php-amqplib/php-amqplib --no-interaction --prefer-dist --no-progress
composer check-platform-reqs --no-dev
composer show --format=json > "$EVIDENCE/packages.json"
probe="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
timeout 180s php "$probe" amqp-quorum-seed > "$EVIDENCE/quorum-seed.log" 2>&1 &
worker=$!
for attempt in {1..600}; do
  [[ -f "$LAB_QUORUM_ROOT/seeded" ]] && break
  kill -0 "$worker"
  sleep 0.1
done
test -f "$LAB_QUORUM_ROOT/seeded"
docker exec lab-q1 rabbitmqctl -q list_queues name leader members online --formatter json > "$EVIDENCE/quorum-before.json"
leader="$(php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);foreach($a as $q){if($q["name"]==="quorum-contract"&&count($q["members"])===3&&count($q["online"])===3){echo $q["leader"];exit;}}exit(1);' "$EVIDENCE/quorum-before.json")"
case "$leader" in rabbit@lab-q1|rabbit@lab-q2|rabbit@lab-q3) ;; *) exit 1 ;; esac
target="${leader#rabbit@}"
if [[ "$SCENARIO" == ecosystem-amqp-quorum-follower ]]; then
  for n in 1 2 3; do if [[ "lab-q$n" != "$target" ]]; then target="lab-q$n"; break; fi; done
fi
docker kill "$target" >/dev/null
touch "$LAB_QUORUM_ROOT/member-stopped"
wait "$worker"
worker=""
for n in 1 2 3; do
  if [[ "lab-q$n" != "$target" ]]; then survivor="lab-q$n"; export LAB_QUORUM_PORT="$((15671+n))"; break; fi
done
ready=0
for attempt in {1..60}; do
  if docker exec "$survivor" rabbitmqctl -q list_queues name leader members online --formatter json > "$EVIDENCE/quorum-after.json" 2>/dev/null \
    && php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);foreach($a as $q){if($q["name"]==="quorum-contract"&&count($q["online"])===2&&$q["leader"]!=="rabbit@".$argv[2]&&$q["leader"]!==""){exit(0);}}exit(1);' "$EVIDENCE/quorum-after.json" "$target"; then ready=1; break; fi
  sleep 1
done
test "$ready" = 1
timeout 90s php "$probe" amqp-quorum-verify | tee "$EVIDENCE/quorum-recovery.log"
