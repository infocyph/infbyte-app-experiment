#!/usr/bin/env bash
set -Eeuo pipefail
: "${POSTGRES_SERVICE:?}" "${REDIS_SERVICE:?}" "${MAIL_SERVICE:?}"
if [[ "$1" == outage ]]; then
  recover() { docker start "$POSTGRES_SERVICE" "$REDIS_SERVICE" "$MAIL_SERVICE" >/dev/null 2>&1 || :; }
  trap recover EXIT
  docker stop "$POSTGRES_SERVICE" "$REDIS_SERVICE" "$MAIL_SERVICE" >/dev/null
  # All three endpoints must really be unavailable; no simulated failure.
  php -r 'foreach ([5432,6379,1025] as $port) { $s=@stream_socket_client("tcp://127.0.0.1:$port",$code,$message,1); if (is_resource($s)) {fclose($s);exit(1);} }'
  sleep 4
  recover
  ready=0
  for attempt in $(seq 1 45); do
    if docker exec "$POSTGRES_SERVICE" pg_isready -U experiment -d experiment >/dev/null &&
       docker exec "$REDIS_SERVICE" redis-cli ping | grep -qx PONG &&
       curl -fsS --max-time 2 http://127.0.0.1:8025/api/v1/messages >/dev/null; then ready=1; break; fi
    sleep 1
  done
  (( ready == 1 ))
  trap - EXIT
  echo 'Actual SQL, Redis and SMTP stopped simultaneously; all restored before recovery consumers.' >> "$GITHUB_STEP_SUMMARY"
elif [[ "$1" == restore ]]; then
  docker exec "$POSTGRES_SERVICE" pg_dump -U experiment -d experiment -Fc > "$RUNNER_TEMP/connected.dump"
  docker exec "$POSTGRES_SERVICE" createdb -U experiment experiment_recovery
  docker exec -i "$POSTGRES_SERVICE" pg_restore -U experiment -d experiment_recovery --exit-on-error < "$RUNNER_TEMP/connected.dump"
  for table in lab_connected_orders lab_connected_outbox lab_connected_stock; do
    before="$(docker exec "$POSTGRES_SERVICE" psql -U experiment -d experiment -Atc "SELECT row_to_json(t) FROM $table t ORDER BY row_to_json(t)::text" | sha256sum)"
    after="$(docker exec "$POSTGRES_SERVICE" psql -U experiment -d experiment_recovery -Atc "SELECT row_to_json(t) FROM $table t ORDER BY row_to_json(t)::text" | sha256sum)"
    [[ "$before" == "$after" ]]
    printf '%s %s\n' "$table" "$before" >> "$EVIDENCE/restore-digests.txt"
  done
  rm -f "$RUNNER_TEMP/connected.dump"
  echo 'Restored connected ledger/outbox/inventory snapshot into a distinct database; exact table digests matched.' >> "$GITHUB_STEP_SUMMARY"
else
  exit 1
fi
