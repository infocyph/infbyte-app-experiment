#!/usr/bin/env bash
set -Eeuo pipefail
export LAB_REMOTE_TLS_ROOT="$(mktemp -d "$RUNNER_TEMP/remote-tls.XXXXXXXX")"
root="$LAB_REMOTE_TLS_ROOT"
cleanup() {
  docker rm -f lab-remote-tls lab-remote-tls-edge >/dev/null 2>&1 || :
  rm -rf "$root"
}
trap cleanup EXIT
chmod 700 "$root"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=localhost \
  -addext subjectAltName=DNS:localhost -keyout "$root/key.pem" -out "$root/cert.pem" >/dev/null 2>&1
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=untrusted \
  -keyout "$root/wrong.key" -out "$root/wrong.pem" >/dev/null 2>&1
cat "$root/cert.pem" "$root/key.pem" > "$root/server.pem"
cd "$PROJECT"
probe="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
if [[ "$SCENARIO" == ecosystem-cache-mongodb-tls-* ]]; then
  composer require mongodb/mongodb --no-interaction --prefer-dist --no-progress
  docker run -d --name lab-remote-tls -p 127.0.0.1:27018:27017 \
    -v "$root:/fixture:ro" --entrypoint sh mongo:8 -ec '
      install -d -o mongodb -g mongodb -m 700 /lab-tls
      install -o mongodb -g mongodb -m 600 /fixture/server.pem /lab-tls/server.pem
      install -o mongodb -g mongodb -m 644 /fixture/cert.pem /lab-tls/cert.pem
      exec docker-entrypoint.sh mongod --bind_ip_all --tlsMode requireTLS --tlsCertificateKeyFile /lab-tls/server.pem
    ' >/dev/null
  ready() {
    for attempt in {1..90}; do
      if docker exec lab-remote-tls mongosh --host localhost --tls --tlsCAFile /lab-tls/cert.pem --quiet --eval 'db.adminCommand({ping:1}).ok' | grep -qx 1; then return; fi
      sleep 1
    done
    return 1
  }
  ready
  if [[ "$SCENARIO" == ecosystem-cache-mongodb-tls-restart ]]; then
    php "$probe" cache-mongodb-tls-seed | tee "$EVIDENCE/mongo-tls-seed.log"
    docker stop -t 15 lab-remote-tls >/dev/null
    if timeout 2 bash -c 'exec 3<>/dev/tcp/127.0.0.1/27018' 2>/dev/null; then exit 1; fi
    php "$probe" cache-mongodb-tls-offline | tee "$EVIDENCE/mongo-tls-offline.log"
    docker start lab-remote-tls >/dev/null
    ready
    php "$probe" cache-mongodb-tls-recover | tee "$EVIDENCE/mongo-tls-recovery.log"
  else
    php "$probe" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/mongo-tls.log"
  fi
else
  composer require aws/aws-sdk-php --no-interaction --prefer-dist --no-progress
  docker run -d --name lab-remote-tls -p 127.0.0.1:19324:9324 softwaremill/elasticmq-native:latest >/dev/null
  cat > "$root/nginx.conf" <<'CONF'
events {}
http {
  server {
    listen 127.0.0.1:19325 ssl;
    ssl_certificate /fixture/cert.pem;
    ssl_certificate_key /fixture/key.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    location / {
      proxy_pass http://127.0.0.1:19324;
      proxy_http_version 1.1;
      proxy_set_header Host $http_host;
    }
  }
}
CONF
  docker run -d --name lab-remote-tls-edge --network host -v "$root:/fixture:ro" \
    nginx:alpine nginx -c /fixture/nginx.conf -g 'daemon off;' >/dev/null
  for attempt in {1..60}; do
    if curl -fsS --max-time 2 --cacert "$root/cert.pem" 'https://localhost:19325/?Action=ListQueues' >/dev/null; then break; fi
    sleep 1
  done
  curl -fsS --max-time 2 --cacert "$root/cert.pem" 'https://localhost:19325/?Action=ListQueues' >/dev/null
  php "$probe" sqs-tls-contracts | tee "$EVIDENCE/sqs-tls.log"
fi
composer check-platform-reqs --no-dev
composer show --format=json > "$EVIDENCE/packages.json"
