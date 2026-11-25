#!/usr/bin/env bash
set -euo pipefail
root="$(mktemp -d "$RUNNER_TEMP/sqlserver-tls.XXXXXXXX")"
trust=/usr/local/share/ca-certificates/infbyte-lab-sqlserver.crt
cleanup() {
  sudo rm -f "$trust"
  sudo update-ca-certificates >/dev/null 2>&1 || :
  rm -rf "$root"
}
trap cleanup EXIT
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=lab-sql-ca \
  -addext basicConstraints=critical,CA:TRUE -addext keyUsage=critical,keyCertSign,cRLSign \
  -keyout "$root/ca.key" -out "$root/ca.crt" >/dev/null 2>&1
openssl req -new -newkey rsa:2048 -nodes -subj /CN=localhost \
  -keyout "$root/server.key" -out "$root/server.csr" >/dev/null 2>&1
printf '%s\n' 'subjectAltName=DNS:localhost' 'extendedKeyUsage=serverAuth' 'keyUsage=digitalSignature,keyEncipherment' 'basicConstraints=CA:FALSE' > "$root/server.ext"
openssl x509 -req -in "$root/server.csr" -CA "$root/ca.crt" -CAkey "$root/ca.key" \
  -CAcreateserial -days 1 -extfile "$root/server.ext" -out "$root/server.crt" >/dev/null 2>&1
# Wait for the real SQL service before changing its network configuration.
ready=0
for attempt in {1..60}; do
  if php -r 'new PDO("sqlsrv:Server=127.0.0.1,1433;Database=master;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=2","sa","Experiment-Only-42!");' 2>/dev/null; then ready=1; break; fi
  sleep 2
done
test "$ready" = 1
docker cp "$root/server.crt" "$MSSQL_SERVICE:/var/opt/mssql/lab-server.crt"
docker cp "$root/server.key" "$MSSQL_SERVICE:/var/opt/mssql/lab-server.key"
docker exec -u root "$MSSQL_SERVICE" sh -ec '
  chown mssql:mssql /var/opt/mssql/lab-server.crt /var/opt/mssql/lab-server.key
  chmod 600 /var/opt/mssql/lab-server.key
  chmod 644 /var/opt/mssql/lab-server.crt
  /opt/mssql/bin/mssql-conf set network.tlscert /var/opt/mssql/lab-server.crt
  /opt/mssql/bin/mssql-conf set network.tlskey /var/opt/mssql/lab-server.key
  /opt/mssql/bin/mssql-conf set network.forceencryption 1
'
docker restart "$MSSQL_SERVICE" >/dev/null
ready=0
for attempt in {1..60}; do
  if php -r 'new PDO("sqlsrv:Server=localhost,1433;Database=master;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=2","sa","Experiment-Only-42!");' 2>/dev/null; then ready=1; break; fi
  sleep 2
done
test "$ready" = 1
# A live service must reject the untrusted certificate before CA installation.
php -r 'try {new PDO("sqlsrv:Server=localhost,1433;Database=master;Encrypt=yes;TrustServerCertificate=no;LoginTimeout=3","sa","Experiment-Only-42!");} catch(PDOException $e) {exit(0);} exit(1);'
sudo install -m 644 "$root/ca.crt" "$trust"
sudo update-ca-certificates >/dev/null
php -r '$pdo=new PDO("sqlsrv:Server=localhost,1433;Database=master;Encrypt=yes;TrustServerCertificate=no;LoginTimeout=5","sa","Experiment-Only-42!"); $pdo->exec("CREATE DATABASE experiment");'
php "$GITHUB_WORKSPACE/qa/workloads/database.php" sqlserver-tls | tee "$EVIDENCE/database.log"
# Removing the CA must reject a new verified connection, not the already closed PHP process.
sudo rm -f "$trust"
sudo update-ca-certificates >/dev/null
php -r 'try {new PDO("sqlsrv:Server=localhost,1433;Database=experiment;Encrypt=yes;TrustServerCertificate=no;LoginTimeout=3","sa","Experiment-Only-42!");} catch(PDOException $e) {exit(0);} exit(1);'
echo 'Trust rejection before and after isolated CA installation passed' >> "$EVIDENCE/database.log"
