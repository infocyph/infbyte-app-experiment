#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
export APP_ENV=production APP_DEBUG=false
export AUTH_TOKEN_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')"
export AUTH_OTP_RECOVERY_HMAC_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
export AUTH_OTP_PROTECTION_KEY="$(php -r 'echo rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");')"
cd "$PROJECT"
worker="$GITHUB_WORKSPACE/qa/workloads/tenant-worker.php"
php "$GITHUB_WORKSPACE/qa/workloads/prepare-http.php"
php "$worker" setup
php infbyte config:validate --production
php infbyte optimize --json=1 > "$RUNNER_TEMP/tenant-release.json"
php infbyte app:ready
export INFOCYPH_FOUNDATION_RELEASE_ROOT="$(php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); echo $r["release_root"];' "$RUNNER_TEMP/tenant-release.json")"
export INFOCYPH_FOUNDATION_RELEASE_MANIFEST_SHA256="$(php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); echo $r["manifest_sha256"];' "$RUNNER_TEMP/tenant-release.json")"
php infbyte serve --runtime=native --workers=4 --host=127.0.0.1 --port=18781 > "$EVIDENCE/tenant-host.log" 2>&1 &
host=$!
trap 'kill "$host" 2>/dev/null || :; wait "$host" 2>/dev/null || :' EXIT
ready=0
for attempt in $(seq 1 40); do
  if [[ "$(curl -sSf --max-time 2 http://127.0.0.1:18781/api/health || :)" == '{"status":"ok"}' ]]; then ready=1; break; fi
  sleep 1
done
(( ready == 1 ))
pids=()
for i in $(seq 1 8); do
  php "$GITHUB_WORKSPACE/qa/workloads/tenant-client.php" "$i" > "$EVIDENCE/tenant-$i.jsonl" &
  pids+=("$!")
done
failed=0
for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
(( failed == 0 ))
php "$worker" verify 160
echo '320 authenticated orders, identical per-tenant keys, replay, wrong-tenant authorization/read and stock isolation verified.' >> "$GITHUB_STEP_SUMMARY"
