#!/usr/bin/env bash
set -Eeuo pipefail
export LAB_COMMIT_PROXY_ROOT="$(mktemp -d "$RUNNER_TEMP/commit-proxy.XXXXXXXX")"
export PGGSSENCMODE=disable
case "$DB_BACKEND" in
  pgsql) proxy=database-commit-proxy.py; port=5432 ;;
  mysql) proxy=database-mysql-commit-proxy.py; port=3306 ;;
  mariadb) proxy=database-mysql-commit-proxy.py; port=3307 ;;
  mssql) proxy=database-sqlserver-commit-proxy.py; port=1433 ;;
  *) echo 'Unsupported commit fault backend' >&2; exit 1 ;;
esac
python3 "$GITHUB_WORKSPACE/qa/workloads/$proxy" "$LAB_COMMIT_PROXY_ROOT" "$port" > "$EVIDENCE/commit-proxy.log" 2>&1 &
peer=$!
trap 'kill "$peer" 2>/dev/null || :; wait "$peer" 2>/dev/null || :; rm -rf "$LAB_COMMIT_PROXY_ROOT"' EXIT
for i in $(seq 1 100); do
  if [[ -s "$LAB_COMMIT_PROXY_ROOT/port" ]]; then break; fi
  kill -0 "$peer"
  sleep 0.1
done
export LAB_COMMIT_PROXY_PORT="$(cat "$LAB_COMMIT_PROXY_ROOT/port")"
probe="$GITHUB_WORKSPACE/qa/workloads/database.php"
if [[ "$DB_BACKEND" == mssql ]]; then
  timeout 90s php "$probe" commit-uncertain > "$EVIDENCE/database.log" 2>&1 &
  worker=$!
  trap 'kill "$worker" "$peer" 2>/dev/null || :; wait "$worker" "$peer" 2>/dev/null || :; rm -rf "$LAB_COMMIT_PROXY_ROOT"' EXIT
  timeout 60s php "$probe" commit-observe | tee "$EVIDENCE/commit-observer.log"
  if ! wait "$worker"; then cat "$EVIDENCE/database.log"; exit 1; fi
  cat "$EVIDENCE/database.log"
  test -f "$LAB_COMMIT_PROXY_ROOT/commit-observed"
  printf 'encrypted response held until independent durable COMMIT observation; then withheld\n' > "$EVIDENCE/fault-marker.txt"
else
  timeout 60s php "$probe" commit-uncertain | tee "$EVIDENCE/database.log"
  printf 'backend COMMIT acknowledgement observed and withheld\n' > "$EVIDENCE/fault-marker.txt"
fi
test -f "$LAB_COMMIT_PROXY_ROOT/ack-dropped"
