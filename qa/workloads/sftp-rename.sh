#!/usr/bin/env bash
set -Eeuo pipefail
cd "$PROJECT"
probe="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
root="$GITHUB_WORKSPACE/qa/workloads"
cleanup() { docker rm -f lab-sftp-rename >/dev/null 2>&1 || :; }
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
composer require league/flysystem-sftp-v3 --no-interaction --prefer-dist --no-progress
composer check-platform-reqs --no-dev
docker exec -u experiment lab-sftp-rename sh -c 'printf off > /signal/phase'
for mode in before after; do
  timeout 60s php "$probe" sftp-rename-seed "$mode"
  docker exec -u experiment lab-sftp-rename sh -c 'printf "%s" "$1" > /signal/phase' sh "$mode"
  timeout 60s php "$probe" sftp-rename-fault "$mode" | tee "$EVIDENCE/sftp-rename-$mode.log"
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
  timeout 60s php "$probe" sftp-rename-recover "$mode" | tee -a "$EVIDENCE/sftp-rename-$mode.log"
done
