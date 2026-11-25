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
provision="$SCENARIO"
case "$SCENARIO" in
  ecosystem-webdav-http-publication-before|ecosystem-webdav-http-publication-after|ecosystem-async-s3-http-publication-before|ecosystem-async-s3-http-publication-after|ecosystem-azure-http-publication-before|ecosystem-azure-http-publication-after|ecosystem-gcs-http-publication-before|ecosystem-gcs-http-publication-after)
    provision="${SCENARIO%-http-publication-*}" ;;
  ecosystem-cache-redis-cluster-*) provision=ecosystem-redis-cluster ;;
  ecosystem-cache-mongodb-*) provision=ecosystem-mongodb ;;
  ecosystem-ftp-outage|ecosystem-ftp-copy-contracts|ecosystem-ftp-staged-contracts|ecosystem-webdav-outage|ecosystem-webdav-copy-contracts|ecosystem-webdav-staged-contracts|ecosystem-gridfs-outage|ecosystem-gridfs-copy-contracts|ecosystem-gridfs-staged-contracts|ecosystem-sftp2-outage|ecosystem-sftp2-copy-contracts|ecosystem-sftp2-staged-contracts|ecosystem-async-s3-outage|ecosystem-async-s3-copy-contracts|ecosystem-async-s3-staged-contracts|ecosystem-azure-outage|ecosystem-azure-copy-contracts|ecosystem-azure-staged-contracts|ecosystem-gcs-outage|ecosystem-gcs-copy-contracts|ecosystem-gcs-staged-contracts)
    provision="${SCENARIO%-outage}"
    provision="${provision%-copy-contracts}"
    provision="${provision%-staged-contracts}"
    ;;
esac
case "$provision" in
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
  ecosystem-mongodb|ecosystem-gridfs)
    containers+=(lab-mongo)
    docker run -d --name lab-mongo -p 127.0.0.1:27017:27017 mongo:8
    for i in $(seq 1 60); do
      if docker exec lab-mongo mongosh --quiet --eval 'db.adminCommand({ping:1}).ok' | grep -qx 1; then break; fi
      sleep 1
    done
    docker exec lab-mongo mongosh --quiet --eval 'db.adminCommand({ping:1}).ok' | grep -qx 1
    composer require mongodb/mongodb --no-interaction --prefer-dist --no-progress
    if [[ "$provision" == ecosystem-gridfs ]]; then composer require league/flysystem-gridfs --no-interaction --prefer-dist --no-progress; fi
    ;;
  ecosystem-s3|ecosystem-async-s3|ecosystem-s3-outage|ecosystem-s3-publication-fault)
    containers+=(lab-s3)
    export LAB_S3_SECRET="$(php -r 'echo bin2hex(random_bytes(24));')"
    docker run -d --name lab-s3 -p 127.0.0.1:19000:9000 -e MINIO_ROOT_USER=experiment -e MINIO_ROOT_PASSWORD="$LAB_S3_SECRET" minio/minio:latest server /data
    for i in $(seq 1 60); do
      if curl -fsS --max-time 2 http://127.0.0.1:19000/minio/health/ready >/dev/null; then break; fi
      sleep 1
    done
    curl -fsS --max-time 2 http://127.0.0.1:19000/minio/health/ready >/dev/null
    if [[ "$provision" != ecosystem-async-s3 ]]; then composer require league/flysystem-aws-s3-v3 --no-interaction --prefer-dist --no-progress; else composer require league/flysystem-async-aws-s3 --no-interaction --prefer-dist --no-progress; fi
    ;;
  ecosystem-sftp|ecosystem-sftp2|ecosystem-sftp-trust|ecosystem-sftp-outage|ecosystem-sftp-publication-fault)
    containers+=(lab-sftp)
    export LAB_SFTP_SECRET="$(php -r 'echo bin2hex(random_bytes(24));')"
    docker run -d --name lab-sftp -p 127.0.0.1:12222:22 atmoz/sftp:latest "experiment:$LAB_SFTP_SECRET:1001::upload"
    for i in $(seq 1 30); do
      if ssh-keyscan -p 12222 127.0.0.1 > "$RUNNER_TEMP/lab-sftp-host.pub" 2>/dev/null; then break; fi
      sleep 1
    done
    test -s "$RUNNER_TEMP/lab-sftp-host.pub"
    export LAB_SFTP_HOST_KEYS="$(docker exec lab-sftp sh -c 'for f in /etc/ssh/ssh_host_*_key.pub; do ssh-keygen -lf "$f" -E sha256; done' | awk '{print $2}')"
    if [[ "$provision" != ecosystem-sftp2 ]]; then composer require league/flysystem-sftp-v3 --no-interaction --prefer-dist --no-progress; else composer require league/flysystem-sftp-v2 --no-interaction --prefer-dist --no-progress; fi
    ;;
  ecosystem-storage-local-modes)
    composer require league/flysystem-memory league/flysystem-read-only league/flysystem-path-prefixing league/flysystem-ziparchive --no-interaction --prefer-dist --no-progress
    ;;
  ecosystem-ftp|ecosystem-webdav)
    python3 -m venv "$RUNNER_TEMP/storage-peer-venv"
    python="$RUNNER_TEMP/storage-peer-venv/bin/python"
    "$python" -m pip install 'pyftpdlib>=2,<3' 'wsgidav>=4,<5' 'cheroot>=10,<12'
    mkdir -p "$RUNNER_TEMP/storage-peer/tenant-a" "$RUNNER_TEMP/storage-peer/tenant-b"
    "$python" "$GITHUB_WORKSPACE/qa/workloads/storage-peer.py" "${provision#ecosystem-}" "$RUNNER_TEMP/storage-peer" > "$EVIDENCE/storage-peer.log" 2>&1 &
    storage_peer=$!
    trap 'kill "$storage_peer" 2>/dev/null || :; wait "$storage_peer" 2>/dev/null || :; cleanup' EXIT
    if [[ "$provision" == ecosystem-ftp ]]; then
      composer require league/flysystem-ftp --no-interaction --prefer-dist --no-progress
    else
      composer require league/flysystem-webdav --no-interaction --prefer-dist --no-progress
    fi
    kill -0 "$storage_peer"
    ;;
  ecosystem-azure)
    containers+=(lab-azure)
    export LAB_AZURE_KEY="$(php -r 'echo base64_encode(random_bytes(64));')"
    docker run -d --name lab-azure -p 127.0.0.1:11000:10000 -e AZURITE_ACCOUNTS="experiment:$LAB_AZURE_KEY" mcr.microsoft.com/azure-storage/azurite azurite-blob --blobHost 0.0.0.0 --loose --skipApiVersionCheck
    composer require league/flysystem-azure-blob-storage --no-interaction --prefer-dist --no-progress
    for i in $(seq 1 30); do
      if curl -sS --max-time 2 -o /dev/null http://127.0.0.1:11000/experiment; then break; fi
      sleep 1
    done
    ;;
  ecosystem-gcs)
    containers+=(lab-gcs)
    docker run -d --name lab-gcs -p 127.0.0.1:14443:4443 fsouza/fake-gcs-server:latest -scheme http -external-url http://127.0.0.1:14443
    composer require league/flysystem-google-cloud-storage --no-interaction --prefer-dist --no-progress
    for i in $(seq 1 30); do
      if curl -fsS --max-time 2 'http://127.0.0.1:14443/storage/v1/b?project=experiment' >/dev/null; then break; fi
      sleep 1
    done
    ;;
  *) exit 1 ;;
esac
composer check-platform-reqs --no-dev
composer show --format=json > "$EVIDENCE/packages.json"
if [[ "$SCENARIO" == *-http-publication-* ]]; then
  backend="${provision#ecosystem-}"
  mode="${SCENARIO##*-}"
  case "$backend" in
    webdav) port=18085 ;;
    async-s3) port=19000 ;;
    azure) port=11000 ;;
    gcs) port=14443 ;;
    *) exit 1 ;;
  esac
  ready=0
  for attempt in {1..60}; do
    if timeout 2 bash -c "exec 3<>/dev/tcp/127.0.0.1/$port" 2>/dev/null; then ready=1; break; fi
    sleep 1
  done
  test "$ready" = 1
  python3 "$GITHUB_WORKSPACE/qa/workloads/storage-http-fault.py" "$EVIDENCE" "$backend" "$mode" "$port" > "$EVIDENCE/storage-http-proxy.log" 2>&1 &
  fault_peer=$!
  trap 'kill "$fault_peer" 2>/dev/null || :; wait "$fault_peer" 2>/dev/null || :; if [[ -n "${storage_peer:-}" ]]; then kill "$storage_peer" 2>/dev/null || :; wait "$storage_peer" 2>/dev/null || :; fi; cleanup' EXIT
  for attempt in {1..100}; do
    [[ -f "$EVIDENCE/storage-http-ready" ]] && break
    kill -0 "$fault_peer"
    sleep 0.1
  done
  test -f "$EVIDENCE/storage-http-ready"
  timeout 180s php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/ecosystem.log"
elif [[ "$SCENARIO" == ecosystem-s3-publication-fault ]]; then
  python3 "$GITHUB_WORKSPACE/qa/workloads/s3-publication-peer.py" "$EVIDENCE" > "$EVIDENCE/s3-peer.log" 2>&1 &
  storage_peer=$!
  trap 'kill "$storage_peer" 2>/dev/null || :; wait "$storage_peer" 2>/dev/null || :; cleanup' EXIT
  for attempt in {1..100}; do
    [[ -f "$EVIDENCE/s3-peer-ready" ]] && break
    kill -0 "$storage_peer"
    sleep 0.1
  done
  test -f "$EVIDENCE/s3-peer-ready"
  timeout 90s php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" s3-publication-fault | tee "$EVIDENCE/ecosystem.log"
elif [[ "$SCENARIO" == ecosystem-sftp-publication-fault ]]; then
  probe="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
  export LAB_SFTP_FAULT_ROOT="$(mktemp -d "$RUNNER_TEMP/sftp-fault.XXXXXXXX")"
  dd if=/dev/urandom of="$LAB_SFTP_FAULT_ROOT/payload.bin" bs=1M count=8 status=none
  timeout 60s php "$probe" sftp-publication-seed
  timeout 90s php "$probe" sftp-publication-interrupt > "$EVIDENCE/sftp-interrupt.log" 2>&1 &
  worker=$!
  trap 'kill "$worker" 2>/dev/null || :; wait "$worker" 2>/dev/null || :; rm -rf "$LAB_SFTP_FAULT_ROOT"; cleanup' EXIT
  for attempt in {1..300}; do
    [[ -f "$LAB_SFTP_FAULT_ROOT/source-paused" ]] && break
    kill -0 "$worker"
    sleep 0.1
  done
  test -f "$LAB_SFTP_FAULT_ROOT/source-paused"
  observed=0
  for attempt in {1..100}; do
    size="$(docker exec lab-sftp stat -c %s /home/experiment/upload/tenant-a/receipts/staging.bin 2>/dev/null || true)"
    if [[ "$size" =~ ^[0-9]+$ ]] && (( size > 0 && size < 8388608 )); then
      printf 'Origin staging bytes before disconnect: %s/8388608\n' "$size" > "$EVIDENCE/sftp-partial.txt"
      observed=1
      break
    fi
    kill -0 "$worker"
    sleep 0.1
  done
  test "$observed" = 1
  docker kill lab-sftp >/dev/null
  touch "$LAB_SFTP_FAULT_ROOT/source-release"
  wait "$worker"
  cat "$EVIDENCE/sftp-interrupt.log"
  docker start lab-sftp >/dev/null
  ready=0
  for attempt in {1..60}; do
    if ssh-keyscan -T 2 -p 12222 127.0.0.1 >/dev/null 2>&1; then ready=1; break; fi
    sleep 1
  done
  test "$ready" = 1
  timeout 90s php "$probe" sftp-publication-recover | tee "$EVIDENCE/sftp-recovery.log"
elif [[ "$SCENARIO" == *-outage && "$provision" != "$SCENARIO" ]]; then
  probe="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
  export LAB_STORAGE_BACKEND="${provision#ecosystem-}"
  service=""
  case "$LAB_STORAGE_BACKEND" in
    ftp) port=12121 ;;
    webdav) port=18085 ;;
    gridfs) service=lab-mongo; port=27017 ;;
    sftp2) service=lab-sftp; port=12222 ;;
    async-s3) service=lab-s3; port=19000 ;;
    azure) service=lab-azure; port=11000 ;;
    gcs) service=lab-gcs; port=14443 ;;
    *) exit 1 ;;
  esac
  ready_port() {
    for attempt in {1..60}; do
      if timeout 2 bash -c "exec 3<>/dev/tcp/127.0.0.1/$port" 2>/dev/null; then return; fi
      sleep 1
    done
    return 1
  }
  ready_port
  timeout 90s php "$probe" storage-outage-seed | tee "$EVIDENCE/storage-seed.log"
  if [[ -n "$service" ]]; then
    docker stop -t 10 "$service" >/dev/null
  else
    kill "$storage_peer"
    wait "$storage_peer" || :
  fi
  if timeout 2 bash -c "exec 3<>/dev/tcp/127.0.0.1/$port" 2>/dev/null; then exit 1; fi
  timeout 120s php "$probe" storage-outage-denied | tee "$EVIDENCE/storage-denied.log"
  if [[ -n "$service" ]]; then
    docker start "$service" >/dev/null
  else
    "$python" "$GITHUB_WORKSPACE/qa/workloads/storage-peer.py" "$LAB_STORAGE_BACKEND" "$RUNNER_TEMP/storage-peer" >> "$EVIDENCE/storage-peer.log" 2>&1 &
    storage_peer=$!
  fi
  ready_port
  case "$LAB_STORAGE_BACKEND" in
    gridfs)
      for attempt in {1..60}; do
        if docker exec lab-mongo mongosh --quiet --eval 'db.adminCommand({ping:1}).ok' | grep -qx 1; then break; fi
        sleep 1
      done
      docker exec lab-mongo mongosh --quiet --eval 'db.adminCommand({ping:1}).ok' | grep -qx 1 ;;
    async-s3)
      for attempt in {1..60}; do
        if curl -fsS --max-time 2 http://127.0.0.1:19000/minio/health/ready >/dev/null; then break; fi
        sleep 1
      done
      curl -fsS --max-time 2 http://127.0.0.1:19000/minio/health/ready >/dev/null ;;
    sftp2)
      for attempt in {1..60}; do
        if ssh-keyscan -T 2 -p 12222 127.0.0.1 >/dev/null 2>&1; then break; fi
        sleep 1
      done
      ssh-keyscan -T 2 -p 12222 127.0.0.1 >/dev/null 2>&1 ;;
    gcs)
      for attempt in {1..60}; do
        if curl -fsS --max-time 2 'http://127.0.0.1:14443/storage/v1/b?project=experiment' >/dev/null; then break; fi
        sleep 1
      done
      curl -fsS --max-time 2 'http://127.0.0.1:14443/storage/v1/b?project=experiment' >/dev/null ;;
  esac
  timeout 90s php "$probe" storage-outage-recover | tee "$EVIDENCE/storage-recovery.log"
elif [[ "$SCENARIO" == ecosystem-s3-outage || "$SCENARIO" == ecosystem-sftp-outage ]]; then
  probe="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
  if [[ "$SCENARIO" == ecosystem-s3-outage ]]; then
    export LAB_STORAGE_BACKEND=s3
    service=lab-s3
    port=19000
  else
    export LAB_STORAGE_BACKEND=sftp
    service=lab-sftp
    port=12222
  fi
  timeout 60s php "$probe" storage-outage-seed | tee "$EVIDENCE/storage-seed.log"
  docker stop -t 10 "$service" >/dev/null
  if timeout 2 bash -c "exec 3<>/dev/tcp/127.0.0.1/$port" 2>/dev/null; then exit 1; fi
  timeout 60s php "$probe" storage-outage-denied | tee "$EVIDENCE/storage-denied.log"
  docker start "$service" >/dev/null
  ready=0
  for attempt in {1..60}; do
    if [[ "$service" == lab-s3 ]]; then
      if curl -fsS --max-time 2 http://127.0.0.1:19000/minio/health/ready >/dev/null; then ready=1; break; fi
    elif ssh-keyscan -T 2 -p 12222 127.0.0.1 >/dev/null 2>&1; then ready=1; break
    fi
    sleep 1
  done
  test "$ready" = 1
  timeout 60s php "$probe" storage-outage-recover | tee "$EVIDENCE/storage-recovery.log"
else
  php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/ecosystem.log"
fi
