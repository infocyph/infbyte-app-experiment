#!/usr/bin/env bash
set -euo pipefail
source "$GITHUB_WORKSPACE/qa/workloads/fixture-container-evidence.sh"
root="$(mktemp -d "$RUNNER_TEMP/amqp-tls.XXXXXXXX")"
cleanup() {
  fixture_container_evidence lab-broker-tls || :
  docker rm -f lab-broker-tls >/dev/null 2>&1 || :
  rm -rf "$root"
}
trap cleanup EXIT
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=lab-amqp-ca \
  -addext basicConstraints=critical,CA:TRUE -addext keyUsage=critical,keyCertSign,cRLSign \
  -keyout "$root/ca.key" -out "$root/ca.crt" >/dev/null 2>&1
openssl req -new -newkey rsa:2048 -nodes -subj /CN=localhost \
  -keyout "$root/server.key" -out "$root/server.csr" >/dev/null 2>&1
printf '%s\n' 'subjectAltName=DNS:localhost' 'extendedKeyUsage=serverAuth' 'basicConstraints=CA:FALSE' > "$root/server.ext"
openssl x509 -req -in "$root/server.csr" -CA "$root/ca.crt" -CAkey "$root/ca.key" \
  -CAcreateserial -days 1 -extfile "$root/server.ext" -out "$root/server.crt" >/dev/null 2>&1
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=wrong-ca -keyout "$root/wrong.key" -out "$root/wrong.crt" >/dev/null 2>&1
mkdir "$root/mount"
cp "$root/ca.crt" "$root/server.crt" "$root/server.key" "$root/mount/"
cat > "$root/mount/rabbitmq.conf" <<'CONF'
listeners.tcp = none
listeners.ssl.default = 5671
ssl_options.cacertfile = /lab-tls/ca.crt
ssl_options.certfile = /lab-tls/server.crt
ssl_options.keyfile = /lab-tls/server.key
ssl_options.verify = verify_none
ssl_options.fail_if_no_peer_cert = false
CONF
docker run -d --name lab-broker-tls -p 127.0.0.1:15671:5671 \
  -e RABBITMQ_DEFAULT_USER=experiment -e RABBITMQ_DEFAULT_PASS=fixture-only-password \
  --mount "type=bind,src=$root/mount,dst=/fixture,readonly" --entrypoint sh rabbitmq:4 -ec '
    install -d -o rabbitmq -g rabbitmq -m 700 /lab-tls
    for file in ca.crt server.crt server.key; do install -o rabbitmq -g rabbitmq -m 600 /fixture/$file /lab-tls/$file; done
    install -o rabbitmq -g rabbitmq -m 644 /fixture/rabbitmq.conf /etc/rabbitmq/rabbitmq.conf
    exec docker-entrypoint.sh rabbitmq-server
  ' >/dev/null
ready() {
  fixture_rabbit_ready lab-broker-tls 15671 90
  docker exec -u rabbitmq lab-broker-tls rabbitmq-diagnostics -q check_port_connectivity
}
cd "$PROJECT"
composer require php-amqplib/php-amqplib --no-interaction --prefer-dist --no-progress
composer check-platform-reqs --no-dev
composer show --format=json > "$EVIDENCE/packages.json"
ready
export LAB_AMQP_TLS_ROOT="$root"
probe="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
if [[ "$SCENARIO" == ecosystem-amqp-tls-settlement-* ]]; then
  contract="${SCENARIO##*-}"
  php "$probe" amqp-tls-settlement-phase seed "$contract" > "$EVIDENCE/amqp-tls-settlement-seed.log" 2>&1 &
  worker=$!
  trap 'kill "$worker" 2>/dev/null || :; wait "$worker" 2>/dev/null || :; cleanup' EXIT
  for attempt in {1..400}; do
    [[ -f "$root/seeded" ]] && break
    kill -0 "$worker"
    sleep 0.1
  done
  test -f "$root/seeded"
  docker kill lab-broker-tls >/dev/null
  if timeout 2 bash -c 'exec 3<>/dev/tcp/127.0.0.1/15671' 2>/dev/null; then exit 1; fi
  touch "$root/stopped"
  wait "$worker"
  test -f "$root/observed"
  cat "$EVIDENCE/amqp-tls-settlement-seed.log"
  docker start lab-broker-tls >/dev/null
  ready
  php "$probe" amqp-tls-settlement-phase recover "$contract" | tee "$EVIDENCE/amqp-tls-settlement-recover.log"
  exit 0
fi
php "$probe" amqp-tls-seed | tee "$EVIDENCE/amqp-seed.log"
docker stop -t 20 lab-broker-tls >/dev/null
if timeout 2 bash -c 'exec 3<>/dev/tcp/127.0.0.1/15671' 2>/dev/null; then exit 1; fi
docker start lab-broker-tls >/dev/null
ready
php "$probe" amqp-tls-recover | tee "$EVIDENCE/amqp-recovery.log"
