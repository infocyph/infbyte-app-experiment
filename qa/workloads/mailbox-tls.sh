#!/usr/bin/env bash
set -Eeuo pipefail
source "$GITHUB_WORKSPACE/qa/workloads/fixture-container-evidence.sh"
umask 077
export LAB_MAIL_ROOT="$(mktemp -d "$RUNNER_TEMP/mail-tls.XXXXXXXX")"
worker=""
cleanup() {
  if [[ -n "$worker" ]]; then kill "$worker" 2>/dev/null || :; wait "$worker" 2>/dev/null || :; fi
  fixture_container_evidence lab-mail-tls || :
  docker rm -f lab-mail-tls >/dev/null 2>&1 || :
  rm -rf "$LAB_MAIL_ROOT"
}
trap cleanup EXIT
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost' -keyout "$LAB_MAIL_ROOT/key.pem" -out "$LAB_MAIL_ROOT/cert.pem" >/dev/null 2>&1
cat > "$LAB_MAIL_ROOT/dovecot.conf" <<'CONF'
protocols = imap pop3
listen = *
ssl = required
ssl_cert = </tls/cert.pem
ssl_key = </tls/key.pem
disable_plaintext_auth = yes
mail_location = maildir:~/Maildir
auth_mechanisms = plain login
passdb {
  driver = pam
}
userdb {
  driver = passwd
}
first_valid_uid = 1000
CONF
chmod 644 "$LAB_MAIL_ROOT/dovecot.conf" "$LAB_MAIL_ROOT/cert.pem"
docker build -t lab-mailbox - < "$GITHUB_WORKSPACE/qa/workloads/Mailbox.Dockerfile"
docker run -d --name lab-mail-tls -p 127.0.0.1:11430:143 -p 127.0.0.1:11110:110 -p 127.0.0.1:11993:993 -p 127.0.0.1:11995:995 -v "$LAB_MAIL_ROOT:/tls:ro" lab-mailbox /usr/sbin/dovecot -F -c /tls/dovecot.conf
seed() {
  printf 'From: billing@example.test\r\nTo: labmail@example.test\r\nSubject: TLS fixture\r\nMessage-ID: <%s@example.test>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain\r\n\r\nFixture\r\n' "$1" |
    docker exec -i -u labmail lab-mail-tls sh -c 'cat > "/home/labmail/Maildir/tmp/$1" && mv "/home/labmail/Maildir/tmp/$1" "/home/labmail/Maildir/new/$1"' sh "$1"
}
seed initial
ready=0
for i in $(seq 1 30); do
  if openssl s_client -connect localhost:11993 -servername localhost -CAfile "$LAB_MAIL_ROOT/cert.pem" -verify_return_error </dev/null >/dev/null 2>&1; then ready=1; break; fi
  sleep 1
done
test "$ready" = 1
docker exec lab-mail-tls doveadm -c /tls/dovecot.conf mailbox status -u labmail messages INBOX | tee "$EVIDENCE/mailbox-tls-seed-status.txt"
python3 - "$LAB_MAIL_ROOT/cert.pem" <<'PY' | tee "$EVIDENCE/mailbox-tls-reference.txt"
import imaplib
import poplib
import ssl
import sys

context = ssl.create_default_context(cafile=sys.argv[1])
with imaplib.IMAP4_SSL("localhost", 11993, ssl_context=context, timeout=5) as imap:
    imap.login("labmail", "fixture-only-password")
    status, count = imap.select("INBOX", readonly=True)
    print("Independent TLS IMAP:", status, count)
    assert status == "OK" and count == [b"1"]
pop = poplib.POP3_SSL("localhost", 11995, context=context, timeout=5)
try:
    pop.user("labmail")
    pop.pass_("fixture-only-password")
    count, _ = pop.stat()
    print("Independent TLS POP3 count:", count)
    assert count == 1
finally:
    pop.quit()
PY
script="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
if [[ "$SCENARIO" == ecosystem-mailbox-tls ]]; then
  php "$script" mailbox-untrusted
  php -d "openssl.cafile=$LAB_MAIL_ROOT/cert.pem" "$script" mailbox-tls | tee "$EVIDENCE/mailbox-tls.log"
else
  php -d "openssl.cafile=$LAB_MAIL_ROOT/cert.pem" "$script" mailbox-idle > "$EVIDENCE/mailbox-idle.log" 2>&1 &
  worker=$!
  for i in $(seq 1 100); do
    if [[ -f "$LAB_MAIL_ROOT/watch-ready" ]]; then break; fi
    kill -0 "$worker"
    sleep 0.1
  done
  test -f "$LAB_MAIL_ROOT/watch-ready"
  sleep 2
  seed arrival
  wait "$worker"
  worker=""
  cat "$EVIDENCE/mailbox-idle.log"
fi
