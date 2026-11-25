#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
export AUTH_TOKEN_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')"
export AUTH_OTP_RECOVERY_HMAC_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
export AUTH_OTP_PROTECTION_KEY="$(php -r 'echo rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");')"
if [[ "$SCENARIO" == ecosystem-jwt-replay || "$SCENARIO" == ecosystem-consent-lifecycle || "$SCENARIO" == ecosystem-consent-permissions ]]; then
  mkdir -p "$PROJECT/storage/lab-oauth-keys"
  openssl ecparam -name prime256v1 -genkey -noout -out "$PROJECT/storage/lab-oauth-keys/private.pem"
  openssl ec -in "$PROJECT/storage/lab-oauth-keys/private.pem" -pubout -out "$PROJECT/storage/lab-oauth-keys/public.pem"
fi
if [[ "$SCENARIO" == ecosystem-consent-lifecycle || "$SCENARIO" == ecosystem-consent-permissions ]]; then
  mkdir -p "$PROJECT/storage/lab-oidc-keys"
  openssl ecparam -name prime256v1 -genkey -noout -out "$PROJECT/storage/lab-oidc-keys/private.pem"
  openssl ec -in "$PROJECT/storage/lab-oidc-keys/private.pem" -pubout -out "$PROJECT/storage/lab-oidc-keys/public.pem"
  export LAB_CONSENT_STATE="$(mktemp "$RUNNER_TEMP/consent.XXXXXXXX")"
  trap 'rm -f "$LAB_CONSENT_STATE"' EXIT
  probe="$GITHUB_WORKSPACE/qa/workloads/consent-lifecycle.php"
  phases=(seed revoke regrant verify)
  if [[ "$SCENARIO" == ecosystem-consent-permissions ]]; then phases=(permission-seed permission-revoke permission-verify); fi
  for phase in "${phases[@]}"; do
    timeout 90s php "$probe" "$phase" | tee "$EVIDENCE/consent-$phase.log"
  done
  exit
fi
probe="$GITHUB_WORKSPACE/qa/workloads/auth-lifecycle.php"
php "$probe" setup
if [[ "$SCENARIO" == ecosystem-auth-lifecycle ]]; then
  php "$probe" lifecycle | tee "$EVIDENCE/auth-lifecycle.log"
  exit
fi
export LAB_REPLAY_EXPIRY="$(php -r 'echo time()+600;')"
pids=()
for worker in $(seq 1 12); do
  php "$probe" replay-race > "$EVIDENCE/replay-$worker.txt" &
  pids+=("$!")
done
failed=0
for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
(( failed == 0 ))
awk 'BEGIN{total=0} /^[01]$/{total+=$1;next}{exit 1} END{if(NR!=12 || total!=1) exit 1}' "$EVIDENCE"/replay-*.txt
php "$probe" replay-verify | tee "$EVIDENCE/replay-verify.log"
