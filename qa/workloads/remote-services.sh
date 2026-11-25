#!/usr/bin/env bash
set -Eeuo pipefail
cd "$PROJECT"
containers=()
cleanup() {
  for name in "${containers[@]}"; do
    # Service output may include disposable credentials; retain only protocol evidence.
    docker rm -f "$name" >/dev/null 2>&1 || :
  done
}
trap cleanup EXIT
case "$SCENARIO" in
  ecosystem-redis-cluster)
    for port in 17379 17380 17381; do
      name="lab-cluster-$port"
      containers+=("$name")
      docker run -d --name "$name" --network host redis:8 redis-server --port "$port" --bind 127.0.0.1 --cluster-enabled yes --cluster-config-file nodes.conf --cluster-announce-ip 127.0.0.1 --cluster-announce-port "$port" --cluster-announce-bus-port "$((port + 10000))" --appendonly no
    done
    for port in 17379 17380 17381; do
      for i in $(seq 1 30); do
        if docker exec "lab-cluster-$port" redis-cli -p "$port" ping | grep -qx PONG; then break; fi
        sleep 1
      done
      docker exec "lab-cluster-$port" redis-cli -p "$port" ping | grep -qx PONG
    done
    docker exec lab-cluster-17379 redis-cli --cluster create 127.0.0.1:17379 127.0.0.1:17380 127.0.0.1:17381 --cluster-replicas 0 --cluster-yes
    for i in $(seq 1 30); do
      if docker exec lab-cluster-17379 redis-cli -p 17379 cluster info | grep -q 'cluster_state:ok'; then break; fi
      sleep 1
    done
    docker exec lab-cluster-17379 redis-cli -p 17379 cluster info | tee "$EVIDENCE/cluster-info.txt" | grep -q 'cluster_state:ok'
    ;;
  ecosystem-mongodb)
    containers+=(lab-mongo)
    docker run -d --name lab-mongo -p 127.0.0.1:27017:27017 mongo:8
    for i in $(seq 1 60); do
      if docker exec lab-mongo mongosh --quiet --eval 'db.adminCommand({ping:1}).ok' | grep -qx 1; then break; fi
      sleep 1
    done
    docker exec lab-mongo mongosh --quiet --eval 'db.adminCommand({ping:1}).ok' | grep -qx 1
    composer require mongodb/mongodb --no-interaction --prefer-dist --no-progress
    ;;
  ecosystem-s3)
    containers+=(lab-s3)
    export LAB_S3_SECRET="$(php -r 'echo bin2hex(random_bytes(24));')"
    docker run -d --name lab-s3 -p 127.0.0.1:19000:9000 -e MINIO_ROOT_USER=experiment -e MINIO_ROOT_PASSWORD="$LAB_S3_SECRET" minio/minio:latest server /data
    for i in $(seq 1 60); do
      if curl -fsS --max-time 2 http://127.0.0.1:19000/minio/health/ready >/dev/null; then break; fi
      sleep 1
    done
    curl -fsS --max-time 2 http://127.0.0.1:19000/minio/health/ready >/dev/null
    composer require league/flysystem-aws-s3-v3 --no-interaction --prefer-dist --no-progress
    ;;
  ecosystem-sftp)
    containers+=(lab-sftp)
    export LAB_SFTP_SECRET="$(php -r 'echo bin2hex(random_bytes(24));')"
    docker run -d --name lab-sftp -p 127.0.0.1:12222:22 atmoz/sftp:latest "experiment:$LAB_SFTP_SECRET:1001::upload"
    for i in $(seq 1 30); do
      if ssh-keyscan -p 12222 127.0.0.1 > "$RUNNER_TEMP/lab-sftp-host.pub" 2>/dev/null; then break; fi
      sleep 1
    done
    test -s "$RUNNER_TEMP/lab-sftp-host.pub"
    composer require league/flysystem-sftp-v3 --no-interaction --prefer-dist --no-progress
    ;;
  *) exit 1 ;;
esac
composer check-platform-reqs --no-dev
composer show --format=json > "$EVIDENCE/packages.json"
php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/ecosystem.log"
