#!/usr/bin/env bash
set -Eeuo pipefail
source "$GITHUB_WORKSPACE/qa/workloads/fixture-container-evidence.sh"
cd "$PROJECT"
probe="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
root="$GITHUB_WORKSPACE/qa/workloads"
cleanup() {
  fixture_container_evidence lab-sftp-rename || :
  fixture_container_evidence lab-remote-orders-broker || :
  docker rm -f lab-sftp-rename lab-remote-orders-broker >/dev/null 2>&1 || :
}
trap cleanup EXIT
export LAB_SFTP_SECRET="$(php -r 'echo bin2hex(random_bytes(24));')"
docker build -t lab-sftp-rename -f "$root/SftpRename.Dockerfile" "$root"
docker run -d --name lab-sftp-rename -p 127.0.0.1:12222:22 -e LAB_SFTP_SECRET lab-sftp-rename
for attempt in {1..60}; do
  if ssh-keyscan -T 2 -p 12222 127.0.0.1 >/dev/null 2>&1; then break; fi
  sleep 1
done
ssh-keyscan -T 2 -p 12222 127.0.0.1 >/dev/null 2>&1
export LAB_SFTP_HOST_KEYS="$(docker exec lab-sftp-rename sh -c 'for f in /etc/ssh/ssh_host_*_key.pub; do ssh-keygen -lf "$f" -E sha256; done' | awk '{print $2}')"
export LAB_STORAGE_BACKEND=sftp
adapter=league/flysystem-sftp-v3
if [[ "$SCENARIO" == ecosystem-sftp2-rename-* || "$SCENARIO" == ecosystem-sftp2-order-rename-* ]]; then
  LAB_STORAGE_BACKEND=sftp2
  adapter=league/flysystem-sftp
fi
packages=("$adapter")
combined=0
if [[ "$SCENARIO" == *-order-rename-* ]]; then
  combined=1
  packages+=(php-amqplib/php-amqplib)
  docker run -d --name lab-remote-orders-broker -p 127.0.0.1:15672:5672 -e RABBITMQ_DEFAULT_USER=experiment -e RABBITMQ_DEFAULT_PASS=fixture-only-password rabbitmq:4 >/dev/null
  fixture_rabbit_ready lab-remote-orders-broker 15672 90
fi
composer require "${packages[@]}" --with-all-dependencies --no-interaction --prefer-dist --no-progress
composer check-platform-reqs --no-dev
composer show --format=json > "$EVIDENCE/packages.json"
docker exec -u experiment lab-sftp-rename sh -c 'printf off > /signal/phase'
modes=(before after)
case "$SCENARIO" in
  *-before) modes=(before) ;;
  *-after) modes=(after) ;;
esac
for mode in "${modes[@]}"; do
  if ((combined)); then
    timeout 120s php "$probe" remote-publication-order "$LAB_STORAGE_BACKEND" "$mode" seed | tee "$EVIDENCE/publication-order-seed.log"
  else
    timeout 60s php "$probe" sftp-rename-seed "$mode"
  fi
  docker exec -u experiment lab-sftp-rename sh -c 'printf "%s" "$1" > /signal/phase' sh "$mode"
  if ((combined)); then
    timeout 120s php "$probe" remote-publication-order "$LAB_STORAGE_BACKEND" "$mode" fault | tee "$EVIDENCE/publication-order-fault.log"
  else
    timeout 60s php "$probe" sftp-rename-fault "$mode" | tee "$EVIDENCE/sftp-rename-$mode.log"
  fi
  docker exec lab-sftp-rename cat "/signal/$mode" > "$EVIDENCE/sftp-rename-$mode.txt"
  if [[ "$mode" == before ]]; then
    grep -Fx 'rename request withheld' "$EVIDENCE/sftp-rename-$mode.txt"
    docker exec lab-sftp-rename test -f /upload/tenant-a/receipts/before-stage.bin
    docker exec lab-sftp-rename test ! -e /upload/tenant-a/receipts/before-published.bin
  else
    grep -Fx 'native rename success status withheld' "$EVIDENCE/sftp-rename-$mode.txt"
    docker exec lab-sftp-rename test ! -e /upload/tenant-a/receipts/after-stage.bin
    docker exec lab-sftp-rename test -f /upload/tenant-a/receipts/after-published.bin
  fi
  docker exec lab-sftp-rename test ! -e "/signal/replayed-$mode"
  docker exec -u experiment lab-sftp-rename sh -c 'printf off > /signal/phase'
  if ((combined)); then
    for phase in recover consume; do
      timeout 120s php "$probe" remote-publication-order "$LAB_STORAGE_BACKEND" "$mode" "$phase" | tee "$EVIDENCE/publication-order-$phase.log"
    done
  else
    timeout 60s php "$probe" sftp-rename-recover "$mode" | tee -a "$EVIDENCE/sftp-rename-$mode.log"
  fi
done
