#!/usr/bin/env bash
set -euo pipefail
root="$(mktemp -d "$RUNNER_TEMP/cache-tls.XXXXXXXX")"
name="lab-cache-tls"
trap 'docker rm -f "$name" >/dev/null 2>&1 || :; rm -rf "$root"' EXIT
chmod 700 "$root"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=localhost -addext subjectAltName=DNS:localhost -keyout "$root/key.pem" -out "$root/cert.pem" >/dev/null 2>&1
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=untrusted -keyout "$root/wrong.key" -out "$root/wrong.pem" >/dev/null 2>&1
image=redis:8
server=redis-server
cli=redis-cli
export LAB_TLS_BACKEND=redis
case "$SCENARIO" in
  ecosystem-valkey-*|ecosystem-otp-valkey-tls-*) image=valkey/valkey:8; server=valkey-server; cli=valkey-cli; export LAB_TLS_BACKEND=valkey ;;
esac
docker run -d --name "$name" --user "$(id -u):$(id -g)" -p 127.0.0.1:17390:6379 -v "$root:/tls:ro" "$image" "$server" --port 0 --tls-port 6379 --tls-cert-file /tls/cert.pem --tls-key-file /tls/key.pem --tls-ca-cert-file /tls/cert.pem --tls-auth-clients no --save "" --appendonly yes --appendfsync always --dir /tmp >/dev/null
ready=0
for attempt in {1..40}; do
  if docker exec "$name" "$cli" --tls --cacert /tls/cert.pem -h localhost PING | grep -qx PONG; then ready=1; break; fi
  sleep 1
done
test "$ready" = 1
export LAB_CACHE_TLS_ROOT="$root"
probe="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
if [[ "$SCENARIO" == ecosystem-otp-*-tls-* ]]; then
  export LAB_OTP_ELECTION_STATE="$root/otp-state.json"
  umask 077
  if [[ "$SCENARIO" == *-live ]]; then
    if [[ "$SCENARIO" == *-factor-*-live ]]; then
      factor="${SCENARIO#*-factor-}"
      php "$probe" otp-tls-factor-live "${factor%-live}" > "$EVIDENCE/otp-live.log" 2>&1 &
    else
      php "$probe" otp-tls-live > "$EVIDENCE/otp-live.log" 2>&1 &
    fi
    otp_pid=$!
    trap 'kill "$otp_pid" 2>/dev/null || :; wait "$otp_pid" 2>/dev/null || :; docker rm -f "$name" >/dev/null 2>&1 || :; rm -rf "$root"' EXIT
    for attempt in {1..200}; do
      [[ -f "$root/otp-live-ready" ]] && break
      kill -0 "$otp_pid"
      sleep 0.1
    done
    test -f "$root/otp-live-ready"
  else
    php "$probe" otp-tls-seed | tee "$EVIDENCE/otp-seed.log"
  fi
  docker stop -t 10 "$name" >/dev/null
  if timeout 2 bash -c 'exec 3<>/dev/tcp/127.0.0.1/17390' 2>/dev/null; then exit 1; fi
  if [[ "$SCENARIO" == *-live ]]; then
    touch "$root/otp-live-offline"
    for attempt in {1..400}; do
      [[ -f "$root/otp-live-rejected" ]] && break
      kill -0 "$otp_pid"
      sleep 0.1
    done
    test -f "$root/otp-live-rejected"
  else
    php "$probe" otp-tls-offline | tee "$EVIDENCE/otp-offline.log"
  fi
  docker start "$name" >/dev/null
  ready=0
  for attempt in {1..40}; do
    if docker exec "$name" "$cli" --tls --cacert /tls/cert.pem -h localhost PING | grep -qx PONG; then ready=1; break; fi
    sleep 1
  done
  test "$ready" = 1
  if [[ "$SCENARIO" == *-live ]]; then
    touch "$root/otp-live-recovered"
    wait "$otp_pid"
    cat "$EVIDENCE/otp-live.log"
  else
    php "$probe" otp-tls-verify | tee "$EVIDENCE/otp-recovery.log"
  fi
elif [[ "$SCENARIO" == *-tls-live-* ]]; then
  php "$probe" "${SCENARIO#ecosystem-}" > "$EVIDENCE/cache-tls-live.log" 2>&1 &
  worker=$!
  trap 'kill "$worker" 2>/dev/null || :; wait "$worker" 2>/dev/null || :; docker rm -f "$name" >/dev/null 2>&1 || :; rm -rf "$root"' EXIT
  marker() {
    for attempt in {1..400}; do
      [[ -f "$root/$1" ]] && return
      kill -0 "$worker"
      sleep 0.1
    done
    return 1
  }
  marker seeded
  docker kill "$name" >/dev/null
  if timeout 2 bash -c 'exec 3<>/dev/tcp/127.0.0.1/17390' 2>/dev/null; then exit 1; fi
  touch "$root/stopped"
  marker observed
  docker start "$name" >/dev/null
  ready=0
  for attempt in {1..40}; do
    if docker exec "$name" "$cli" --tls --cacert /tls/cert.pem -h localhost PING | grep -qx PONG; then ready=1; break; fi
    sleep 1
  done
  test "$ready" = 1
  touch "$root/restarted"
  wait "$worker"
  cat "$EVIDENCE/cache-tls-live.log"
elif [[ "$SCENARIO" == *counter-restart ]]; then
  php "$probe" counter-tls-seed | tee "$EVIDENCE/counter-seed.log"
  docker stop -t 10 "$name" >/dev/null
  if timeout 2 bash -c 'exec 3<>/dev/tcp/127.0.0.1/17390' 2>/dev/null; then exit 1; fi
  php "$probe" counter-tls-offline | tee "$EVIDENCE/counter-offline.log"
  docker start "$name" >/dev/null
  ready=0
  for attempt in {1..40}; do
    if docker exec "$name" "$cli" --tls --cacert /tls/cert.pem -h localhost PING | grep -qx PONG; then ready=1; break; fi
    sleep 1
  done
  test "$ready" = 1
  php "$probe" counter-tls-recover | tee "$EVIDENCE/counter-recovery.log"
else
  php "$probe" cache-tls | tee "$EVIDENCE/ecosystem.log"
fi
