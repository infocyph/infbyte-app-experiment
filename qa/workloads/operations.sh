#!/usr/bin/env bash
set -Eeuo pipefail
unset COMPOSER_NO_DEV
export AUTH_TOKEN_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')"
export AUTH_OTP_RECOVERY_HMAC_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
export AUTH_OTP_PROTECTION_KEY="$(php -r 'echo rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");')"
export APP_ENV=production APP_DEBUG=false
cd "$PROJECT"
probe="$GITHUB_WORKSPACE/qa/workloads/run.php"
ops="$GITHUB_WORKSPACE/qa/workloads/operations.php"
php "$GITHUB_WORKSPACE/qa/workloads/prepare-http.php"
php "$probe" setup
php "$ops" schema
php "$probe" worker 1-1 100 > "$EVIDENCE/orders.jsonl"
php "$probe" verify 100
php infbyte config:validate --production
php infbyte optimize --json=1 > "$RUNNER_TEMP/ops-release-1.json"
php infbyte app:ready
php infbyte schedule:list
pids=()
for worker in $(seq 1 4); do
  php infbyte schedule:run > "$EVIDENCE/schedule-$worker.log" 2>&1 &
  pids+=("$!")
done
failed=0
for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
if (( failed )); then cat "$EVIDENCE"/schedule-*.log >&2; exit 1; fi
php "$ops" verify
# Quiesced relational snapshot, restored into a different fresh database.
docker exec "$POSTGRES_SERVICE" pg_dump -U experiment -d experiment -Fc > "$RUNNER_TEMP/experiment.dump"
docker exec "$POSTGRES_SERVICE" createdb -U experiment experiment_restore
docker exec -i "$POSTGRES_SERVICE" pg_restore -U experiment -d experiment_restore --exit-on-error < "$RUNNER_TEMP/experiment.dump"
query="SELECT COUNT(*) || ':' || SUM(amount) FROM experiment_orders"
original="$(docker exec "$POSTGRES_SERVICE" psql -U experiment -d experiment -Atc "$query")"
restored="$(docker exec "$POSTGRES_SERVICE" psql -U experiment -d experiment_restore -Atc "$query")"
[[ "$original" == '100:30000' && "$restored" == "$original" ]]
for table in experiment_stock experiment_orders experiment_lines experiment_audit experiment_reports; do
  before="$(docker exec "$POSTGRES_SERVICE" psql -U experiment -d experiment -Atc "SELECT row_to_json(t) FROM $table t ORDER BY row_to_json(t)::text" | sha256sum)"
  after="$(docker exec "$POSTGRES_SERVICE" psql -U experiment -d experiment_restore -Atc "SELECT row_to_json(t) FROM $table t ORDER BY row_to_json(t)::text" | sha256sum)"
  [[ "$before" == "$after" ]]
  printf '%s %s\n' "$table" "$before" >> "$EVIDENCE/restored-table-digests.txt"
done
# Two immutable generations, independent manifest digests and pointer rollback.
php infbyte optimize --json=1 > "$RUNNER_TEMP/ops-release-2.json"
php infbyte app:ready
php -r '
  require "vendor/autoload.php";
  $a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);
  $b=json_decode(file_get_contents($argv[2]),true,flags:JSON_THROW_ON_ERROR);
  if ($a["generation"]===$b["generation"] || $a["manifest_sha256"]===$b["manifest_sha256"]) exit(1);
  (new Infocyph\Foundation\Release\ActiveGeneration())->activate($a["release_root"],$a["generation"]);
  $c=(new Infocyph\Foundation\Release\ActiveGeneration())->current($a["release_root"]);
  if ($c["generation"]!==$a["generation"] || !hash_equals($a["manifest_sha256"],hash_file("sha256",$a["manifest"]))) exit(1);
' "$RUNNER_TEMP/ops-release-1.json" "$RUNNER_TEMP/ops-release-2.json"
php infbyte app:ready
# Controlled loss of cache authority. A timeout or unexpected exception fails.
docker stop "$REDIS_SERVICE" >/dev/null
trap 'docker start "$REDIS_SERVICE" >/dev/null || :' EXIT
timeout 15 php "$ops" cache-fail
docker start "$REDIS_SERVICE" >/dev/null
trap - EXIT
for attempt in $(seq 1 30); do
  if docker exec "$REDIS_SERVICE" redis-cli ping | grep -qx PONG; then break; fi
  sleep 1
done
php "$ops" cache-recover
php "$probe" verify 100
echo "Verified concurrent scheduler, SQL snapshot restore, immutable pointer rollback, Redis outage and recovery." >> "$GITHUB_STEP_SUMMARY"
