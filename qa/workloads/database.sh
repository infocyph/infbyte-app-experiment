#!/usr/bin/env bash
set -euo pipefail
probe="$GITHUB_WORKSPACE/qa/workloads/database.php"
case "$SCENARIO" in
  db-schema|db-query|db-repository|db-query-cache|db-stream|db-security|db-lock-wait|db-pool-lifecycle|db-budgets|db-isolation|db-wal)
    php "$probe" "${SCENARIO#db-}" | tee "$EVIDENCE/database.log" ;;
  db-transactions) php "$probe" transactions | tee "$EVIDENCE/database.log" ;;
  db-pool) php "$probe" pool | tee "$EVIDENCE/database.log" ;;
  db-locking)
    php "$probe" setup-locking
    pids=()
    for id in 1 2; do
      timeout 90s php "$probe" deadlock "$id" > "$EVIDENCE/deadlock-$id.log" 2>&1 &
      pids+=("$!")
    done
    failed=0
    for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
    cat "$EVIDENCE"/deadlock-*.log
    if (( failed )); then exit 1; fi
    php "$probe" verify-deadlock | tee "$EVIDENCE/database.log"
    ;;
  db-replicas|db-replica-fault)
    cleanup() {
      for port in 5433 5434; do
        docker logs "lab-standby-$port" > "$EVIDENCE/standby-$port.log" 2>&1 || :
        docker rm -f "lab-standby-$port" >/dev/null 2>&1 || :
      done
    }
    trap cleanup EXIT
    docker exec "$POSTGRES_SERVICE" bash -e -c 'printf "\nhost replication experiment 0.0.0.0/0 scram-sha-256\n" >> "$PGDATA/pg_hba.conf"; gosu postgres pg_ctl reload -D "$PGDATA"'
    for port in 5433 5434; do
      docker run -d --name "lab-standby-$port" --network host \
        -e PGPASSWORD=experiment -e STANDBY_PORT="$port" \
        --entrypoint bash postgres:17 -e -c '
          install -d -o postgres -g postgres /tmp/standby
          if [[ ! -s /tmp/standby/PG_VERSION ]]; then gosu postgres pg_basebackup -h 127.0.0.1 -p 5432 -U experiment -D /tmp/standby -R -X stream; fi
          exec gosu postgres postgres -D /tmp/standby -p "$STANDBY_PORT" -c listen_addresses=127.0.0.1
        '
    done
    for port in 5433 5434; do
      ready=0
      for attempt in $(seq 1 60); do
        if docker exec "lab-standby-$port" pg_isready -h 127.0.0.1 -p "$port"; then ready=1; break; fi
        sleep 1
      done
      test "$ready" = 1
    done
    if [[ "$SCENARIO" == db-replicas ]]; then
      php "$probe" replicas | tee "$EVIDENCE/database.log"
    else
      php "$probe" replica-lag | tee "$EVIDENCE/replica-lag.log"
      docker stop lab-standby-5433
      php "$probe" replica-partial | tee "$EVIDENCE/replica-partial.log"
      docker start lab-standby-5433
      ready=0
      for attempt in $(seq 1 60); do
        if docker exec lab-standby-5433 pg_isready -h 127.0.0.1 -p 5433; then ready=1; break; fi
        sleep 1
      done
      test "$ready" = 1
      php "$probe" replica-recover | tee "$EVIDENCE/replica-recover.log"
    fi
    ;;
  *) echo "Unknown DB scenario: $SCENARIO" >&2; exit 1 ;;
esac
