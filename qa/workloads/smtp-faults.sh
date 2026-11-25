#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
export LAB_SMTP_ROOT="$(mktemp -d "$RUNNER_TEMP/smtp-faults.XXXXXXXX")"
root="$LAB_SMTP_ROOT"
pids=()
cleanup() {
  for pid in "${pids[@]}"; do kill "$pid" 2>/dev/null || :; done
  for pid in "${pids[@]}"; do wait "$pid" 2>/dev/null || :; done
  rm -rf "$root"
}
trap cleanup EXIT
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=localhost -addext subjectAltName=DNS:localhost -keyout "$root/key.pem" -out "$root/cert.pem" >/dev/null 2>&1
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=foreign -keyout "$root/foreign.key" -out "$root/foreign.pem" >/dev/null 2>&1
modes=(lost reject success)
if [[ "$SCENARIO" == ecosystem-smtp-tls-auth ]]; then modes=(tls downgrade implicit); fi
port=18125
for mode in "${modes[@]}"; do
  python3 "$GITHUB_WORKSPACE/qa/workloads/smtp-fault-peer.py" "$root" "$mode" "$port" > "$EVIDENCE/smtp-$mode.log" 2>&1 &
  pids+=("$!")
  ((port+=1))
done
for mode in "${modes[@]}"; do
  for attempt in {1..100}; do [[ -f "$root/$mode.ready" ]] && break; sleep 0.1; done
  test -f "$root/$mode.ready"
done
timeout 90s php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/ecosystem.log"
