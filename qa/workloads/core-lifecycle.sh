#!/usr/bin/env bash
set -euo pipefail
cd "$PROJECT"
export APP_ENV=production APP_DEBUG=false APP_CAPABILITIES=
php infbyte optimize:clear
php infbyte config:validate --production
php infbyte optimize --json=1 > "$EVIDENCE/core-release.json"
php infbyte app:ready
php infbyte about --json=1 > "$EVIDENCE/core-about.json"
php infbyte route:list --json=1 > "$EVIDENCE/core-routes.json"
php infbyte serve --dry-run --json=1 --runtime=native --workers=4 > "$EVIDENCE/core-serve-plan.json"
php infbyte maintenance:disable
php infbyte serve --runtime=native --workers=4 --host=127.0.0.1 --port=18782 > "$EVIDENCE/core-host.log" 2>&1 &
host=$!
cleanup() {
  kill "$host" 2>/dev/null || :
  wait "$host" 2>/dev/null || :
  php infbyte maintenance:disable >/dev/null 2>&1 || :
}
trap cleanup EXIT
code() { curl --silent --show-error --max-time 3 -o /dev/null -w '%{http_code}' http://127.0.0.1:18782/json; }
wait_code() {
  for i in $(seq 1 40); do
    if [[ "$(code || :)" == "$1" ]]; then return; fi
    sleep 1
  done
  cat "$EVIDENCE/core-host.log" >&2
  return 1
}
wait_code 200
php infbyte maintenance:enable --retry=17 --message='Laboratory maintenance'
wait_code 503
curl --silent --show-error --max-time 3 -D "$EVIDENCE/core-maintenance-headers.txt" -o /dev/null http://127.0.0.1:18782/json
grep -Eiq '^Retry-After:[[:space:]]*17' "$EVIDENCE/core-maintenance-headers.txt"
php infbyte maintenance:status
php infbyte maintenance:disable
wait_code 200
seq 1 200 | xargs -P 16 -I '{}' bash -c 'test "$(curl --silent --show-error --max-time 5 -o /dev/null -w "%{http_code}" http://127.0.0.1:18782/json)" = 200'
echo "Core compiled readiness, live maintenance transition, recovery and 200 concurrent responses passed" | tee "$EVIDENCE/core-result.txt"
