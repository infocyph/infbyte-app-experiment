#!/usr/bin/env bash
set -Eeuo pipefail
cd "$PROJECT"
export LAB_STORAGE_BACKEND=s3
export LAB_S3_SECRET="$(php -r 'echo bin2hex(random_bytes(24));')"
pids=()
cleanup() {
  for pid in "${pids[@]}"; do kill "$pid" 2>/dev/null || :; done
  for pid in "${pids[@]}"; do wait "$pid" 2>/dev/null || :; done
  docker rm -f lab-remote-orders-s3 lab-remote-orders-broker >/dev/null 2>&1 || :
}
trap cleanup EXIT
composer require league/flysystem-aws-s3-v3 php-amqplib/php-amqplib --no-interaction --prefer-dist --no-progress
composer check-platform-reqs --no-dev
composer show --format=json > "$EVIDENCE/packages.json"
docker run -d --name lab-remote-orders-s3 -p 127.0.0.1:19000:9000 -e MINIO_ROOT_USER=experiment -e MINIO_ROOT_PASSWORD="$LAB_S3_SECRET" minio/minio:latest server /data
docker run -d --name lab-remote-orders-broker -p 127.0.0.1:15672:5672 -e RABBITMQ_DEFAULT_USER=experiment -e RABBITMQ_DEFAULT_PASS=fixture-only-password rabbitmq:4
ready() {
  for attempt in {1..90}; do
    if curl -fsS --max-time 2 http://127.0.0.1:19000/minio/health/ready >/dev/null \
      && docker exec lab-remote-orders-broker rabbitmq-diagnostics -q ping >/dev/null; then return; fi
    sleep 1
  done
  return 1
}
ready
probe="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
timeout 90s php "$probe" remote-order-seed | tee "$EVIDENCE/remote-order-seed.log"
docker stop -t 5 lab-remote-orders-s3 lab-remote-orders-broker >/dev/null
for worker in {0..7}; do
  timeout 60s php "$probe" remote-order-offline "$worker" > "$EVIDENCE/remote-order-worker-$worker.log" 2>&1 &
  pids+=("$!")
done
failed=0
for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
pids=()
((failed == 0))
docker start lab-remote-orders-s3 lab-remote-orders-broker >/dev/null
ready
timeout 120s php "$probe" remote-order-recover | tee "$EVIDENCE/remote-order-recover.log"
