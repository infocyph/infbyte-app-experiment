#!/usr/bin/env bash
set -euo pipefail
root="$(mktemp -d "$RUNNER_TEMP/database-tls.XXXXXXXX")"
trap 'rm -rf "$root"' EXIT
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=localhost -addext subjectAltName=DNS:localhost -keyout "$root/server.key" -out "$root/server.crt" >/dev/null 2>&1
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=untrusted -keyout "$root/wrong.key" -out "$root/wrong.crt" >/dev/null 2>&1
docker cp "$root/server.key" "$POSTGRES_SERVICE:/tmp/lab-server.key"
docker cp "$root/server.crt" "$POSTGRES_SERVICE:/tmp/lab-server.crt"
docker exec -u root "$POSTGRES_SERVICE" sh -ec 'chown postgres:postgres /tmp/lab-server.key /tmp/lab-server.crt; chmod 600 /tmp/lab-server.key; chmod 644 /tmp/lab-server.crt'
for setting in "ssl_cert_file = '/tmp/lab-server.crt'" "ssl_key_file = '/tmp/lab-server.key'" "ssl = on"; do
  docker exec "$POSTGRES_SERVICE" psql -v ON_ERROR_STOP=1 -U experiment -d experiment -c "ALTER SYSTEM SET $setting"
done
docker exec "$POSTGRES_SERVICE" psql -v ON_ERROR_STOP=1 -U experiment -d experiment -c 'SELECT pg_reload_conf()'
ready=0
for attempt in {1..30}; do
  if docker exec "$POSTGRES_SERVICE" psql -At -U experiment -d experiment -c 'SHOW ssl' | grep -qx on; then ready=1; break; fi
  sleep 1
done
test "$ready" = 1
export LAB_DB_TLS_ROOT="$root"
export PGSSLROOTCERT="$root/server.crt"
php "$GITHUB_WORKSPACE/qa/workloads/database.php" tls | tee "$EVIDENCE/database.log"
