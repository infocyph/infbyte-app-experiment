#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
root="$(mktemp -d "$RUNNER_TEMP/recovery.XXXXXXXX")"
trap 'rm -rf "$root"' EXIT
export LAB_OTP_FIXTURE="$root/proofs.json"
export AUTH_TOKEN_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')"
export AUTH_OTP_RECOVERY_HMAC_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
export AUTH_OTP_PROTECTION_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
probe="$GITHUB_WORKSPACE/qa/workloads/otp-durable.php"
php "$probe" setup
pids=()
for i in $(seq 1 12); do
  php "$probe" race > "$EVIDENCE/recovery-$i.txt" &
  pids+=("$!")
done
failed=0
for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
(( failed == 0 ))
awk 'BEGIN{total=0} /^[01]$/{total+=$1;next}{exit 1} END{if (NR!=12 || total!=1) exit 1}' "$EVIDENCE"/recovery-*.txt
php "$probe" verify
echo 'Exactly one recovery winner; regenerated/account isolation and remaining code exhaustion passed'
