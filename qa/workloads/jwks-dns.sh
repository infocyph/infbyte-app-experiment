#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
cd "$PROJECT"
composer require 'nyholm/psr7:^1.8' 'guzzlehttp/guzzle:^7.0' --no-interaction --prefer-dist --no-progress
composer check-platform-reqs --no-dev
composer show --format=json > "$EVIDENCE/packages.json"
root="$(mktemp -d "$RUNNER_TEMP/jwks-dns.XXXXXXXX")"
trap 'rm -rf "$root"' EXIT
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=issuer.example.test' \
  -addext 'subjectAltName=DNS:issuer.example.test' -keyout "$root/key.pem" -out "$root/cert.pem" >/dev/null 2>&1
printf 'public-peer' > "$root/phase"
printf 'nameserver 127.0.0.1\noptions timeout:1 attempts:1 ndots:1\n' > "$root/resolv.conf"
mode="${SCENARIO#ecosystem-jwks-dns-}"
sudo unshare --net --mount --propagation private -- bash -Eeuo pipefail -s -- "$root" "$PROJECT" "$GITHUB_WORKSPACE" "$(command -v php)" "$mode" "${LAB_RUNWIRE_CANDIDATE_REFERENCE:-}" <<'NAMESPACE' | tee "$EVIDENCE/jwks-dns.log"
export LAB_JWKS_DNS_ROOT="$1" PROJECT="$2"
work="$3"; php="$4"; mode="$5"; candidate="$6"
root="$LAB_JWKS_DNS_ROOT"
test "$(ip -o link show | wc -l)" = 1
test -z "$(ip route show default)"
ip link set lo up
ip address add 93.184.216.34/32 dev lo
mount --bind "$root/resolv.conf" /etc/resolv.conf
unset http_proxy https_proxy HTTP_PROXY HTTPS_PROXY ALL_PROXY all_proxy
python3 "$work/qa/workloads/jwks-dns-peer.py" "$root" > "$root/peer.log" 2>&1 &
peer=$!
trap 'kill "$peer" 2>/dev/null || :; wait "$peer" 2>/dev/null || :; cat "$root/peer.log"' EXIT
for attempt in {1..100}; do
  [[ -f "$root/ready" ]] && break
  kill -0 "$peer"
  sleep 0.1
done
test -f "$root/ready"
guzzle_status=0
runwire_status=0
timeout 30s "$php" "$work/qa/workloads/jwks-dns-probe.php" "$mode" guzzle || guzzle_status=$?
if [[ -n "$candidate" ]]; then
  printf 'public-peer' > "$root/phase"
  rm -f "$root/public.requests" "$root/private.requests" "$root/connected-peers"
  timeout 30s "$php" "$work/qa/workloads/jwks-dns-probe.php" "$mode" runwire || runwire_status=$?
fi
printf 'JWKS transport outcomes: guzzle=%s runwire=%s\n' "$guzzle_status" "$runwire_status"
test "$guzzle_status" -eq 0 && test "$runwire_status" -eq 0
NAMESPACE
