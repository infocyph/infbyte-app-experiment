#!/usr/bin/env bash
set -Eeuo pipefail
source "$GITHUB_WORKSPACE/qa/workloads/fixture-container-evidence.sh"
docker build -t lab-mailbox - < "$GITHUB_WORKSPACE/qa/workloads/Mailbox.Dockerfile"
docker run -d --name lab-mailbox -p 127.0.0.1:11430:143 -p 127.0.0.1:11110:110 lab-mailbox
trap 'fixture_container_evidence lab-mailbox || :; docker rm -f lab-mailbox >/dev/null 2>&1 || :' EXIT
for i in $(seq 1 12); do
  printf 'From: billing@example.test\r\nTo: labmail@example.test\r\nSubject: Lab invoice %s\r\nMessage-ID: <invoice-%s@example.test>\r\nDate: Wed, 25 Nov 2026 00:00:00 +0000\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nInvoice %s\r\n.dot-prefixed line\r\n' "$i" "$i" "$i" |
    docker exec -i -u labmail lab-mailbox sh -c 'cat > "/home/labmail/Maildir/tmp/$1" && mv "/home/labmail/Maildir/tmp/$1" "/home/labmail/Maildir/new/$1"' sh "fixture-$i"
done
ready=0
for attempt in $(seq 1 30); do
  if php -r '$s=@fsockopen("127.0.0.1",11430,$e,$m,1); if (!$s) exit(1); fclose($s); $s=@fsockopen("127.0.0.1",11110,$e,$m,1); if (!$s) exit(1); fclose($s);'; then ready=1; break; fi
  sleep 1
done
test "$ready" = 1
docker exec lab-mailbox doveadm -c /etc/dovecot/lab.conf mailbox status -u labmail messages INBOX | tee "$EVIDENCE/mailbox-seed-status.txt"
python3 - <<'PY' | tee "$EVIDENCE/mailbox-reference.txt"
import imaplib
import poplib

with imaplib.IMAP4("127.0.0.1", 11430, timeout=5) as imap:
    imap.login("labmail", "fixture-only-password")
    status, count = imap.select("INBOX", readonly=True)
    print("Independent IMAP:", status, count)
    assert status == "OK" and count == [b"12"]
pop = poplib.POP3("127.0.0.1", 11110, timeout=5)
try:
    pop.user("labmail")
    pop.pass_("fixture-only-password")
    count, _ = pop.stat()
    print("Independent POP3 count:", count)
    assert count == 12
finally:
    pop.quit()
PY
php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/ecosystem.log"
