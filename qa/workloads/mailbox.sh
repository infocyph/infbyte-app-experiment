#!/usr/bin/env bash
set -Eeuo pipefail
source "$GITHUB_WORKSPACE/qa/workloads/fixture-container-evidence.sh"
docker build -t lab-mailbox - < "$GITHUB_WORKSPACE/qa/workloads/Mailbox.Dockerfile"
docker run -d --name lab-mailbox -p 127.0.0.1:11430:143 -p 127.0.0.1:11110:110 lab-mailbox
trap 'fixture_container_evidence lab-mailbox || :; docker rm -f lab-mailbox >/dev/null 2>&1 || :' EXIT
for i in $(seq 1 12); do
  printf 'From: billing@example.test\r\nTo: labmail@example.test\r\nSubject: Lab invoice %s\r\nMessage-ID: <invoice-%s@example.test>\r\nDate: Wed, 25 Nov 2026 00:00:00 +0000\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nInvoice %s\r\n.dot-prefixed line\r\n' "$i" "$i" "$i" |
    docker exec -i -u labmail lab-mailbox sh -c 'cat > "/home/labmail/Maildir/new/$1"' sh "fixture-$i"
done
ready=0
for attempt in $(seq 1 30); do
  if php -r '$s=@fsockopen("127.0.0.1",11430,$e,$m,1); if (!$s) exit(1); fclose($s); $s=@fsockopen("127.0.0.1",11110,$e,$m,1); if (!$s) exit(1); fclose($s);'; then ready=1; break; fi
  sleep 1
done
test "$ready" = 1
php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/ecosystem.log"
