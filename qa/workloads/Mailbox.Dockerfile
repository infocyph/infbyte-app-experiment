FROM ubuntu:24.04
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends dovecot-imapd dovecot-pop3d && rm -rf /var/lib/apt/lists/*
RUN useradd -m -u 1500 -s /usr/sbin/nologin labmail && echo 'labmail:fixture-only-password' | chpasswd && install -d -o labmail -g labmail /home/labmail/Maildir/cur /home/labmail/Maildir/new /home/labmail/Maildir/tmp
RUN printf '%s\n' 'protocols = imap pop3' 'listen = *' 'ssl = no' 'disable_plaintext_auth = no' 'mail_location = maildir:~/Maildir' 'auth_mechanisms = plain login' 'passdb {' '  driver = pam' '}' 'userdb {' '  driver = passwd' '}' 'first_valid_uid = 1000' > /etc/dovecot/lab.conf
CMD ["/usr/sbin/dovecot", "-F", "-c", "/etc/dovecot/lab.conf"]
