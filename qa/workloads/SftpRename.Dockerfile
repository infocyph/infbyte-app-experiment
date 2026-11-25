FROM debian:trixie-slim
RUN apt-get update && apt-get install -y --no-install-recommends openssh-server python3 && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home --shell /bin/sh experiment \
    && install -d -m 0755 /run/sshd \
    && install -d -o experiment -g experiment -m 0700 /upload /signal
COPY sftp-rename-peer.py /usr/local/bin/sftp-rename-peer.py
RUN chmod 755 /usr/local/bin/sftp-rename-peer.py \
    && printf '%s\n' 'Port 22' 'PasswordAuthentication yes' 'KbdInteractiveAuthentication no' 'PermitRootLogin no' 'AllowUsers experiment' 'DisableForwarding yes' 'PermitTTY no' 'Subsystem sftp /usr/local/bin/sftp-rename-peer.py' 'ForceCommand /usr/local/bin/sftp-rename-peer.py' > /etc/ssh/sshd_config
CMD ["sh", "-ec", "printf '%s:%s\\n' experiment \"$LAB_SFTP_SECRET\" | chpasswd; unset LAB_SFTP_SECRET; ssh-keygen -A; exec /usr/sbin/sshd -D -e"]
