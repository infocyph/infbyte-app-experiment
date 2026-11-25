#!/usr/bin/env bash
set -Eeuo pipefail
: "${POSTGRES_SERVICE:?}" "${REDIS_SERVICE:?}" "${MAIL_SERVICE:?}"
url="$1"
root="$(mktemp -d "$RUNNER_TEMP/connected-active.XXXXXXXX")"
pids=()
cleanup() {
  docker start "$POSTGRES_SERVICE" "$REDIS_SERVICE" "$MAIL_SERVICE" >/dev/null 2>&1 || :
  for pid in "${pids[@]}"; do kill "$pid" 2>/dev/null || :; done
  for pid in "${pids[@]}"; do wait "$pid" 2>/dev/null || :; done
  rm -rf "$root"
}
trap cleanup EXIT
await_workers() {
  local marker="$1" ready
  for attempt in {1..400}; do
    ready=0
    for worker in {1..8}; do
      if [[ -f "$root/$marker-$worker" ]]; then ((ready+=1)); fi
    done
    if ((ready == 8)); then return; fi
    sleep 0.1
  done
  return 1
}
post_success() {
  local key="$1" expected="$2" output="$3" code
  code="$(curl -sS --max-time 15 -o "$output" -w '%{http_code}' -X POST "$url/api/connected/$key/2")"
  [[ "$code" == "$expected" ]]
  php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);if(!is_string($a["id"]??null)||$a["quantity"]!==2||$a["created"]!==($argv[2]==="201"))exit(1);echo $a["id"];' "$output" "$expected"
}
for worker in {1..8}; do
  (
    before="$(post_success "active-before-$worker" 201 "$root/before-$worker.json")"
    touch "$root/ready-$worker"
    for attempt in {1..400}; do [[ -f "$root/offline" ]] && break; sleep 0.1; done
    test -f "$root/offline"
    code=000
    result=0
    code="$(curl -sS --max-time 20 -o "$root/offline-$worker.json" -w '%{http_code}' -X POST "$url/api/connected/active-after-$worker/2")" || result=$?
    if ((result == 0)); then
      [[ "$code" == 500 || "$code" == 502 || "$code" == 503 || "$code" == 504 ]]
    fi
    printf 'worker=%s offline_http=%s curl_exit=%s\n' "$worker" "$code" "$result"
    touch "$root/attempted-$worker"
    for attempt in {1..600}; do [[ -f "$root/recovered" ]] && break; sleep 0.1; done
    test -f "$root/recovered"
    replay="$(post_success "active-before-$worker" 200 "$root/replay-$worker.json")"
    [[ "$replay" == "$before" ]]
    after="$(post_success "active-after-$worker" 201 "$root/after-$worker.json")"
    replay="$(post_success "active-after-$worker" 200 "$root/after-replay-$worker.json")"
    [[ "$after" == "$replay" && "$after" != "$before" ]]
    printf '%s\n%s\n' "$before" "$after" > "$root/ids-$worker"
  ) > "$EVIDENCE/active-worker-$worker.log" 2>&1 &
  pids+=("$!")
done
await_workers ready
docker stop -t 5 "$POSTGRES_SERVICE" "$REDIS_SERVICE" "$MAIL_SERVICE" >/dev/null
php -r 'foreach ([5432,6379,1025] as $port) {$s=@stream_socket_client("tcp://127.0.0.1:$port",$code,$message,1);if(is_resource($s)){fclose($s);exit(1);}}'
touch "$root/offline"
await_workers attempted
docker start "$POSTGRES_SERVICE" "$REDIS_SERVICE" "$MAIL_SERVICE" >/dev/null
ready=0
for attempt in {1..45}; do
  if docker exec "$POSTGRES_SERVICE" pg_isready -U experiment -d experiment >/dev/null &&
     docker exec "$REDIS_SERVICE" redis-cli ping | grep -qx PONG &&
     curl -fsS --max-time 2 http://127.0.0.1:8025/api/v1/messages >/dev/null; then ready=1; break; fi
  sleep 1
done
test "$ready" = 1
touch "$root/recovered"
failed=0
for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
pids=()
((failed == 0))
cat "$root"/ids-* | LC_ALL=C sort -u > "$EVIDENCE/active-order-ids.txt"
[[ "$(wc -l < "$EVIDENCE/active-order-ids.txt")" == 16 ]]
echo 'Eight active checkout workers crossed a real SQL/Redis/SMTP outage, rejected offline writes, and replayed sixteen unique orders.' >> "$GITHUB_STEP_SUMMARY"
