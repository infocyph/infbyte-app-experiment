#!/usr/bin/env bash
set -euo pipefail
root="$(mktemp -d "$RUNNER_TEMP/mysql-family-tls.XXXXXXXX")"
container=lab-mysql-family-tls
cleanup() {
  docker rm -f "$container" >/dev/null 2>&1 || :
  rm -rf "$root"
}
trap cleanup EXIT
case "$DB_BACKEND" in
  mysql) image=mysql:8.4; daemon=mysqld ;;
  mariadb) image=mariadb:11.4; daemon=mariadbd ;;
  *) echo 'Unsupported TLS fixture backend' >&2; exit 1 ;;
esac
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=lab-db-ca \
  -addext basicConstraints=critical,CA:TRUE -addext keyUsage=critical,keyCertSign,cRLSign \
  -keyout "$root/ca.key" -out "$root/ca.crt" >/dev/null 2>&1
openssl req -new -newkey rsa:2048 -nodes -subj /CN=127.0.0.1 \
  -keyout "$root/server.key" -out "$root/server.csr" >/dev/null 2>&1
printf '%s\n' 'subjectAltName=IP:127.0.0.1' 'extendedKeyUsage=serverAuth' 'basicConstraints=CA:FALSE' > "$root/server.ext"
openssl x509 -req -in "$root/server.csr" -CA "$root/ca.crt" -CAkey "$root/ca.key" \
  -CAcreateserial -days 1 -extfile "$root/server.ext" -out "$root/server.crt" >/dev/null 2>&1
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=wrong-ca \
  -keyout "$root/wrong.key" -out "$root/wrong.crt" >/dev/null 2>&1
mkdir "$root/server"
cp "$root/ca.crt" "$root/server.crt" "$root/server.key" "$root/server/"
docker run -d --name "$container" \
  -p 127.0.0.1:3308:3306 -p 127.0.0.2:3308:3306 \
  -e MYSQL_DATABASE=experiment -e MYSQL_USER=experiment -e MYSQL_PASSWORD=experiment \
  -e MYSQL_ROOT_PASSWORD=experiment-root \
  --mount "type=bind,src=$root/server,dst=/lab-certs,readonly" \
  --entrypoint sh "$image" -ec '
    install -d -o mysql -g mysql -m 700 /tmp/lab-tls
    install -o mysql -g mysql -m 600 /lab-certs/server.key /tmp/lab-tls/server.key
    install -o mysql -g mysql -m 644 /lab-certs/ca.crt /tmp/lab-tls/ca.crt
    install -o mysql -g mysql -m 644 /lab-certs/server.crt /tmp/lab-tls/server.crt
    exec docker-entrypoint.sh "$1" --ssl-ca=/tmp/lab-tls/ca.crt --ssl-cert=/tmp/lab-tls/server.crt --ssl-key=/tmp/lab-tls/server.key --require-secure-transport=ON
  ' sh "$daemon" >/dev/null
ready=0
for attempt in {1..90}; do
  if timeout 2 bash -c 'exec 3<>/dev/tcp/127.0.0.1/3308; read -r -n 1 -u 3 _' 2>/dev/null &&
     timeout 2 bash -c 'exec 3<>/dev/tcp/127.0.0.2/3308; read -r -n 1 -u 3 _' 2>/dev/null; then
    ready=1
    break
  fi
  sleep 1
done
test "$ready" = 1
export LAB_DB_TLS_ROOT="$root"
php "$GITHUB_WORKSPACE/qa/workloads/database.php" tls | tee "$EVIDENCE/database.log"
