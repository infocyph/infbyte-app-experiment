#!/usr/bin/env bash
set -Eeuo pipefail
root="$(mktemp -d "$RUNNER_TEMP/proxysql.XXXXXXXX")"
probe="$GITHUB_WORKSPACE/qa/workloads/database.php"
trap 'docker logs lab-proxysql > "$EVIDENCE/proxy.log" 2>&1 || :; docker rm -f lab-proxysql >/dev/null 2>&1 || :; rm -rf "$root"' EXIT
cat > "$root/proxysql.cnf" <<'CONF'
datadir="/var/lib/proxysql"
admin_variables={
    admin_credentials="admin:admin"
    mysql_ifaces="127.0.0.1:16032"
}
mysql_variables={
    interfaces="127.0.0.1:16033"
    threads=2
    max_connections=100
    monitor_enabled=false
    default_authentication_plugin="caching_sha2_password"
}
mysql_servers=(
    { address="127.0.0.1", port=3306, hostgroup=0, max_connections=3 }
)
mysql_users=(
    { username="experiment", password="experiment", default_hostgroup=0, transaction_persistent=1, active=1 }
)
CONF
chmod 755 "$root"
chmod 644 "$root/proxysql.cnf"
docker run -d --name lab-proxysql --network host -v "$root/proxysql.cnf:/etc/proxysql.cnf:ro" proxysql/proxysql:3
ready=0
for i in $(seq 1 60); do
  if php -r 'try {$p=new PDO("mysql:host=127.0.0.1;port=16033;dbname=experiment","experiment","experiment");echo $p->query("SELECT 1")->fetchColumn();} catch (PDOException) {exit(1);}' | grep -qx 1; then ready=1; break; fi
  sleep 1
done
test "$ready" = 1
php "$probe" proxy-seed
pids=()
for id in $(seq 1 8); do
  timeout 90s php "$probe" mysql-proxy-worker "$id" > "$EVIDENCE/proxy-$id.log" 2>&1 &
  pids+=("$!")
done
failed=0
for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
cat "$EVIDENCE"/proxy-*.log
(( failed == 0 ))
php "$probe" proxy-verify | tee "$EVIDENCE/database.log"
