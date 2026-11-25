#!/usr/bin/env bash
set -Eeuo pipefail
cd "$PROJECT"
containers=()
pids=()
cleanup() {
  for pid in "${pids[@]}"; do kill "$pid" 2>/dev/null || :; done
  for pid in "${pids[@]}"; do wait "$pid" 2>/dev/null || :; done
  for name in "${containers[@]}"; do
    # Service output may include disposable credentials; retain only protocol evidence.
    docker rm -f "$name" >/dev/null 2>&1 || :
  done
}
trap cleanup EXIT
provision="$SCENARIO"
case "$SCENARIO" in
  ecosystem-*-remote-order-recovery) provision="${SCENARIO%-remote-order-recovery}" ;;
  ecosystem-gridfs-order-publication-*) provision=ecosystem-gridfs ;;
  ecosystem-*-order-http-publication-*) provision="${SCENARIO%-order-http-publication-*}" ;;
  ecosystem-*-archive-*) provision="${SCENARIO%-archive-*}" ;;
  ecosystem-gridfs-publication-before|ecosystem-gridfs-publication-after) provision=ecosystem-gridfs ;;
  ecosystem-ftp-rename-before|ecosystem-ftp-rename-after|ecosystem-ftp-interrupted-upload) provision=ecosystem-ftp ;;
  ecosystem-sftp2-interrupted-upload) provision=ecosystem-sftp2 ;;
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
    if [[ "$SCENARIO" == ecosystem-ftp-rename-* ]]; then
      export LAB_FTP_SIGNAL="$RUNNER_TEMP/ftp-rename-signal"
      mkdir -m 700 "$LAB_FTP_SIGNAL"
      printf off > "$LAB_FTP_SIGNAL/phase"
    fi
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
if [[ "$SCENARIO" == *-remote-order-recovery || "$SCENARIO" == *-order-http-publication-* || "$SCENARIO" == ecosystem-gridfs-order-publication-* ]]; then
  containers+=(lab-remote-orders-broker)
  composer require php-amqplib/php-amqplib --no-interaction --prefer-dist --no-progress
  docker run -d --name lab-remote-orders-broker -p 127.0.0.1:15672:5672 -e RABBITMQ_DEFAULT_USER=experiment -e RABBITMQ_DEFAULT_PASS=fixture-only-password rabbitmq:4
  broker_ready() {
    for attempt in {1..90}; do
      if docker exec lab-remote-orders-broker rabbitmq-diagnostics -q ping >/dev/null; then return; fi
      sleep 1
    done
    return 1
  }
  broker_ready
fi
composer check-platform-reqs --no-dev
composer show --format=json > "$EVIDENCE/packages.json"
if [[ "$SCENARIO" == ecosystem-cache-mongodb-outage-* ]]; then
  bash "$GITHUB_WORKSPACE/qa/workloads/remote-cache-outage.sh" lab-mongo mongodb
elif [[ "$SCENARIO" == ecosystem-gridfs-publication-* || "$SCENARIO" == ecosystem-gridfs-order-publication-* ]]; then
  python3 -m venv "$RUNNER_TEMP/gridfs-peer-venv"
  python="$RUNNER_TEMP/gridfs-peer-venv/bin/python"
  "$python" -m pip install 'pymongo>=4,<5'
  "$python" "$GITHUB_WORKSPACE/qa/workloads/gridfs-fault-peer.py" "$EVIDENCE" "${SCENARIO##*-}" > "$EVIDENCE/gridfs-proxy.log" 2>&1 &
  fault_peer=$!
  trap 'kill "$fault_peer" 2>/dev/null || :; wait "$fault_peer" 2>/dev/null || :; cleanup' EXIT
  for attempt in {1..100}; do
    [[ -f "$EVIDENCE/gridfs-ready" ]] && break
    kill -0 "$fault_peer"
    sleep 0.1
  done
  test -f "$EVIDENCE/gridfs-ready"
  if [[ "$SCENARIO" == ecosystem-gridfs-order-publication-* ]]; then
    for phase in seed fault recover consume; do
      timeout 180s php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" remote-publication-order gridfs "${SCENARIO##*-}" "$phase" | tee "$EVIDENCE/publication-order-$phase.log"
    done
  else
    timeout 120s php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/ecosystem.log"
  fi
  test -s "$EVIDENCE/gridfs-fault.json" && test ! -e "$EVIDENCE/gridfs-replayed"
elif [[ "$SCENARIO" == *-http-publication-* ]]; then
  backend="${provision#ecosystem-}"
  mode="${SCENARIO##*-}"
  case "$backend" in
    webdav) port=18085 ;;
    s3|async-s3) port=19000 ;;
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
  if [[ "$SCENARIO" == *-order-http-publication-* ]]; then
    for phase in seed fault recover consume; do
      timeout 180s php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" remote-publication-order "$backend" "$mode" "$phase" | tee "$EVIDENCE/publication-order-$phase.log"
    done
  else
    timeout 180s php "$GITHUB_WORKSPACE/qa/workloads/ecosystem.php" "${SCENARIO#ecosystem-}" | tee "$EVIDENCE/ecosystem.log"
  fi
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
elif [[ "$SCENARIO" == ecosystem-ftp-rename-* ]]; then
  probe="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
  export LAB_STORAGE_BACKEND=ftp
  mode="${SCENARIO##*-}"
  for attempt in {1..60}; do
    if timeout 2 bash -c 'exec 3<>/dev/tcp/127.0.0.1/12121' 2>/dev/null; then break; fi
    sleep 1
  done
  timeout 2 bash -c 'exec 3<>/dev/tcp/127.0.0.1/12121'
  timeout 60s php "$probe" sftp-rename-seed "$mode"
  printf '%s' "$mode" > "$LAB_FTP_SIGNAL/phase"
  timeout 60s php "$probe" sftp-rename-fault "$mode" | tee "$EVIDENCE/ftp-rename-fault.log"
  cp "$LAB_FTP_SIGNAL/$mode" "$EVIDENCE/ftp-rename-boundary.txt"
  prefix="$RUNNER_TEMP/storage-peer/tenant-a/receipts/$mode"
  if [[ "$mode" == before ]]; then
    grep -Fx 'rename request withheld' "$EVIDENCE/ftp-rename-boundary.txt"
    test -f "$prefix-stage.bin" && test ! -e "$prefix-published.bin"
  else
    grep -Fx 'native rename success response withheld' "$EVIDENCE/ftp-rename-boundary.txt"
    test ! -e "$prefix-stage.bin" && test -f "$prefix-published.bin"
  fi
  test ! -e "$LAB_FTP_SIGNAL/replayed-$mode"
  printf off > "$LAB_FTP_SIGNAL/phase"
  timeout 60s php "$probe" sftp-rename-recover "$mode" | tee "$EVIDENCE/ftp-rename-recovery.log"
elif [[ "$SCENARIO" == ecosystem-sftp-publication-fault || "$SCENARIO" == ecosystem-sftp2-interrupted-upload || "$SCENARIO" == ecosystem-ftp-interrupted-upload ]]; then
  export LAB_STORAGE_BACKEND=sftp
  if [[ "$SCENARIO" == ecosystem-sftp2-interrupted-upload ]]; then LAB_STORAGE_BACKEND=sftp2; fi
  if [[ "$SCENARIO" == ecosystem-ftp-interrupted-upload ]]; then
    LAB_STORAGE_BACKEND=ftp
    for attempt in {1..60}; do
      if timeout 2 bash -c 'exec 3<>/dev/tcp/127.0.0.1/12121' 2>/dev/null; then break; fi
      sleep 1
    done
    timeout 2 bash -c 'exec 3<>/dev/tcp/127.0.0.1/12121'
  fi
  probe="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
  export LAB_SFTP_FAULT_ROOT="$(mktemp -d "$RUNNER_TEMP/sftp-fault.XXXXXXXX")"
  dd if=/dev/urandom of="$LAB_SFTP_FAULT_ROOT/payload.bin" bs=1M count=8 status=none
  timeout 60s php "$probe" sftp-publication-seed
  timeout 90s php "$probe" sftp-publication-interrupt > "$EVIDENCE/sftp-interrupt.log" 2>&1 &
  worker=$!
  trap 'kill "$worker" 2>/dev/null || :; wait "$worker" 2>/dev/null || :; if [[ -n "${storage_peer:-}" ]]; then kill "$storage_peer" 2>/dev/null || :; wait "$storage_peer" 2>/dev/null || :; fi; rm -rf "$LAB_SFTP_FAULT_ROOT"; cleanup' EXIT
  for attempt in {1..300}; do
    [[ -f "$LAB_SFTP_FAULT_ROOT/source-paused" ]] && break
    kill -0 "$worker"
    sleep 0.1
  done
  test -f "$LAB_SFTP_FAULT_ROOT/source-paused"
  observed=0
  for attempt in {1..100}; do
    if [[ "$LAB_STORAGE_BACKEND" == ftp ]]; then
      size="$(stat -c %s "$RUNNER_TEMP/storage-peer/tenant-a/receipts/staging.bin" 2>/dev/null || true)"
    else
      size="$(docker exec lab-sftp stat -c %s /home/experiment/upload/tenant-a/receipts/staging.bin 2>/dev/null || true)"
    fi
    if [[ "$size" =~ ^[0-9]+$ ]] && (( size > 0 && size < 8388608 )); then
      printf 'Origin staging bytes before disconnect: %s/8388608\n' "$size" > "$EVIDENCE/sftp-partial.txt"
      observed=1
      break
    fi
    kill -0 "$worker"
    sleep 0.1
  done
  test "$observed" = 1
  if [[ "$LAB_STORAGE_BACKEND" == ftp ]]; then
    kill -KILL "$storage_peer"
    wait "$storage_peer" || :
  else
    docker kill lab-sftp >/dev/null
  fi
  touch "$LAB_SFTP_FAULT_ROOT/source-release"
  wait "$worker"
  cat "$EVIDENCE/sftp-interrupt.log"
  if [[ "$LAB_STORAGE_BACKEND" == ftp ]]; then
    "$python" "$GITHUB_WORKSPACE/qa/workloads/storage-peer.py" ftp "$RUNNER_TEMP/storage-peer" >> "$EVIDENCE/storage-peer.log" 2>&1 &
    storage_peer=$!
  else
    docker start lab-sftp >/dev/null
  fi
  ready=0
  for attempt in {1..60}; do
    if [[ "$LAB_STORAGE_BACKEND" == ftp ]]; then
      if timeout 2 bash -c 'exec 3<>/dev/tcp/127.0.0.1/12121' 2>/dev/null; then ready=1; break; fi
    elif ssh-keyscan -T 2 -p 12222 127.0.0.1 >/dev/null 2>&1; then ready=1; break
    fi
    sleep 1
  done
  test "$ready" = 1
  timeout 90s php "$probe" sftp-publication-recover | tee "$EVIDENCE/sftp-recovery.log"
elif [[ ( "$SCENARIO" == *-outage || "$SCENARIO" == *-archive-outage-* || "$SCENARIO" == *-remote-order-recovery ) && "$provision" != "$SCENARIO" ]]; then
  probe="$GITHUB_WORKSPACE/qa/workloads/ecosystem.php"
  export LAB_STORAGE_BACKEND="${provision#ecosystem-}"
  service=""
  case "$LAB_STORAGE_BACKEND" in
    ftp) port=12121 ;;
    webdav) port=18085 ;;
    gridfs) service=lab-mongo; port=27017 ;;
    sftp|sftp2) service=lab-sftp; port=12222 ;;
    s3|async-s3) service=lab-s3; port=19000 ;;
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
  if [[ "$SCENARIO" == *-remote-order-recovery ]]; then
    timeout 120s php "$probe" remote-order-seed | tee "$EVIDENCE/remote-order-seed.log"
    docker stop -t 10 lab-remote-orders-broker >/dev/null
  elif [[ "$SCENARIO" == *-archive-outage-* ]]; then
    contract="${SCENARIO##*-}"
    timeout 90s php "$probe" archive-outage-phase seed "$contract" | tee "$EVIDENCE/storage-seed.log"
    timeout 180s php "$probe" archive-outage-phase hold "$contract" > "$EVIDENCE/archive-held.log" 2>&1 &
    worker=$!
    for attempt in {1..300}; do
      [[ -f "$EVIDENCE/archive-outage/prepared" ]] && break
      kill -0 "$worker"
      sleep 0.1
    done
    test -f "$EVIDENCE/archive-outage/prepared"
  else
    timeout 90s php "$probe" storage-outage-seed | tee "$EVIDENCE/storage-seed.log"
  fi
  if [[ -n "$service" ]]; then
    docker stop -t 10 "$service" >/dev/null
  else
    kill "$storage_peer"
    wait "$storage_peer" || :
  fi
  if timeout 2 bash -c "exec 3<>/dev/tcp/127.0.0.1/$port" 2>/dev/null; then exit 1; fi
  if [[ "$SCENARIO" == *-remote-order-recovery ]]; then
    if timeout 2 bash -c 'exec 3<>/dev/tcp/127.0.0.1/15672' 2>/dev/null; then exit 1; fi
    for worker in {0..7}; do
      timeout 90s php "$probe" remote-order-offline "$worker" > "$EVIDENCE/remote-order-worker-$worker.log" 2>&1 &
      pids+=("$!")
    done
    failed=0
    for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
    pids=()
    ((failed == 0))
  elif [[ "$SCENARIO" == *-archive-outage-* ]]; then
    touch "$EVIDENCE/archive-outage/offline"
    result=0
    wait "$worker" || result=$?
    cat "$EVIDENCE/archive-held.log"
    test "$result" = 0
    test -s "$EVIDENCE/archive-outage/rejected"
  else
    timeout 120s php "$probe" storage-outage-denied | tee "$EVIDENCE/storage-denied.log"
  fi
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
    s3|async-s3)
      for attempt in {1..60}; do
        if curl -fsS --max-time 2 http://127.0.0.1:19000/minio/health/ready >/dev/null; then break; fi
        sleep 1
      done
      curl -fsS --max-time 2 http://127.0.0.1:19000/minio/health/ready >/dev/null ;;
    sftp|sftp2)
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
  if [[ "$SCENARIO" == *-remote-order-recovery ]]; then
    docker start lab-remote-orders-broker >/dev/null
    broker_ready
    timeout 180s php "$probe" remote-order-recover | tee "$EVIDENCE/remote-order-recover.log"
  elif [[ "$SCENARIO" == *-archive-outage-* ]]; then
    timeout 120s php "$probe" archive-outage-phase recover "$contract" | tee "$EVIDENCE/storage-recovery.log"
  else
    timeout 90s php "$probe" storage-outage-recover | tee "$EVIDENCE/storage-recovery.log"
  fi
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
