#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
root="$(mktemp -d "$RUNNER_TEMP/risk-provider.XXXXXXXX")"
trap 'rm -rf "$root"' EXIT
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=Fixture CA' -addext 'basicConstraints=critical,CA:TRUE' -keyout "$root/ca.key" -out "$root/ca.pem" >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -subj '/CN=verify.example.test' -keyout "$root/leaf.key" -out "$root/leaf.csr" >/dev/null 2>&1
printf '%s\n' 'subjectAltName=DNS:verify.example.test' 'extendedKeyUsage=serverAuth' > "$root/leaf.ext"
openssl x509 -req -in "$root/leaf.csr" -CA "$root/ca.pem" -CAkey "$root/ca.key" -CAcreateserial -days 1 -extfile "$root/leaf.ext" -out "$root/leaf.pem" >/dev/null 2>&1
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=Foreign Fixture CA' -keyout "$root/untrusted.key" -out "$root/untrusted.pem" >/dev/null 2>&1
# No external connectivity: the public-shaped IP exists solely in the isolated network namespace.
sudo unshare --net -- bash -Eeuo pipefail -s -- "$root" "$PROJECT" "$GITHUB_WORKSPACE" "$(command -v php)" <<'NAMESPACE' | tee "$EVIDENCE/risk-provider.log"
root="$1"
export PROJECT="$2"
work="$3"
php="$4"
[[ "$(ip -o link show | wc -l)" -eq 1 ]]
[[ -z "$(ip route show default)" ]]
ip link set lo up
ip address add 93.184.216.34/32 dev lo
python3 "$work/qa/workloads/risk-provider-peer.py" "$root/leaf.pem" "$root/leaf.key" > "$root/peer.log" 2>&1 &
peer=$!
trap 'kill "$peer" 2>/dev/null || :; wait "$peer" 2>/dev/null || :; cat "$root/peer.log"' EXIT
for attempt in {1..100}; do
  grep -qx ready "$root/peer.log" && break
  kill -0 "$peer"
  sleep 0.05
done
grep -qx ready "$root/peer.log"
for mode in accept reject foreign-tenant foreign-action foreign-hostname redirect oversize malformed wrong-host timeout cancel wrong-context empty-proof; do
  "$php" -d "openssl.cafile=$root/ca.pem" "$work/qa/workloads/risk-provider-probe.php" "$mode"
done
"$php" -d "openssl.cafile=$root/untrusted.pem" "$work/qa/workloads/risk-provider-probe.php" untrusted
for mode in accept reject foreign-tenant foreign-action foreign-hostname redirect oversize malformed timeout cancel; do
  [[ "$(grep -Fxc "/$mode" "$root/peer.log")" -eq 1 ]]
done
! grep -q should-not-follow "$root/peer.log"
NAMESPACE
