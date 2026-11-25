#!/usr/bin/env bash
set -euo pipefail
probe="$GITHUB_WORKSPACE/qa/workloads/database.php"
if [[ "$SCENARIO" != db-sqlserver-tls ]]; then
  if [[ "$DB_BACKEND" == mssql ]]; then
    ready=0
    for attempt in $(seq 1 60); do
      if php -r 'try {$pdo=new PDO("sqlsrv:Server=127.0.0.1,1433;Database=master;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=2","sa","Experiment-Only-42!"); $pdo->exec("CREATE DATABASE experiment");} catch (PDOException $e) {fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}'; then ready=1; break; fi
      sleep 2
    done
    test "$ready" = 1
  fi
fi
case "$SCENARIO" in
  db-active-backup) bash "$GITHUB_WORKSPACE/qa/workloads/database-active-backup.sh" ;;
  db-portable-backup) bash "$GITHUB_WORKSPACE/qa/workloads/database-portable-backup.sh" ;;
  db-physical-backup) bash "$GITHUB_WORKSPACE/qa/workloads/database-physical-backup.sh" ;;
  db-mysql-physical-backup) bash "$GITHUB_WORKSPACE/qa/workloads/database-mysql-physical-backup.sh" ;;
  db-query-cache-overlap) bash "$GITHUB_WORKSPACE/qa/workloads/database-cache-overlap.sh" ;;
  db-query-cache-race) bash "$GITHUB_WORKSPACE/qa/workloads/database-cache-race.sh" ;;
  db-sqlserver-ag) bash "$GITHUB_WORKSPACE/qa/workloads/database-sqlserver-ag.sh" ;;
  db-sqlserver-tls) bash "$GITHUB_WORKSPACE/qa/workloads/database-sqlserver-tls.sh" ;;
  db-tls) bash "$GITHUB_WORKSPACE/qa/workloads/database-tls.sh" ;;
  db-mysql-family-tls) bash "$GITHUB_WORKSPACE/qa/workloads/database-mysql-family-tls.sh" ;;
  db-commit-uncertain) bash "$GITHUB_WORKSPACE/qa/workloads/database-uncertain.sh" ;;
  db-proxy-mysql) bash "$GITHUB_WORKSPACE/qa/workloads/database-mysql-proxy.sh" ;;
  db-migration-fault|db-connection-loss) php "$probe" "${SCENARIO#db-}" | tee "$EVIDENCE/database.log" ;;
  db-proxy-*) bash "$GITHUB_WORKSPACE/qa/workloads/database-proxy.sh" ;;
  db-binlog-replica|db-maxscale|db-maxscale-failover) bash "$GITHUB_WORKSPACE/qa/workloads/database-binlog.sh" ;;
  db-predicate-contracts|db-pagination-contracts|db-chunk-checkpoint|db-connection-reuse|db-batch-boundaries)
    php "$probe" "${SCENARIO#db-}" | tee "$EVIDENCE/database.log" ;;
  db-driver|db-relations-advanced)
    php "$probe" "${SCENARIO#db-}" | tee "$EVIDENCE/database.log" ;;
  db-relations) php "$probe" relations | tee "$EVIDENCE/database.log" ;;
  db-sqlserver-lock-modes) php "$probe" sqlserver-lock-modes | tee "$EVIDENCE/database.log" ;;
  db-schema|db-query|db-repository|db-query-cache|db-stream|db-security|db-lock-wait|db-pool-lifecycle|db-budgets|db-isolation|db-wal|db-lock-modes|db-native-owner|db-callback-failure)
    php "$probe" "${SCENARIO#db-}" | tee "$EVIDENCE/database.log" ;;
  db-transactions) php "$probe" transactions | tee "$EVIDENCE/database.log" ;;
  db-pool) php "$probe" pool | tee "$EVIDENCE/database.log" ;;
  db-serialization)
    php "$probe" setup-locking
    pids=()
    for id in 1 2; do
      timeout 90s php "$probe" serialization "$id" > "$EVIDENCE/serialization-$id.log" 2>&1 &
      pids+=("$!")
    done
    failed=0
    for pid in "${pids[@]}"; do if ! wait "$pid"; then failed=1; fi; done
    cat "$EVIDENCE"/serialization-*.log
    if (( failed )); then exit 1; fi
    php "$probe" verify-serialization | tee "$EVIDENCE/database.log"
    ;;
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
  db-replicas|db-replica-fault|db-primary-promotion|db-pgpool|db-pgpool-failover)
    ports=(5433 5434)
    if [[ "$SCENARIO" == db-pgpool || "$SCENARIO" == db-pgpool-failover ]]; then ports=(5433); fi
    cleanup() {
      for port in "${ports[@]}"; do
        docker logs "lab-standby-$port" > "$EVIDENCE/standby-$port.log" 2>&1 || :
        docker rm -f "lab-standby-$port" >/dev/null 2>&1 || :
      done
    }
    trap cleanup EXIT
    docker exec "$POSTGRES_SERVICE" bash -e -c 'printf "\nhost replication experiment 0.0.0.0/0 scram-sha-256\n" >> "$PGDATA/pg_hba.conf"; gosu postgres pg_ctl reload -D "$PGDATA"'
    # Retain WAL between each base backup and the standby connection, including restart.
    docker exec "$POSTGRES_SERVICE" psql -v ON_ERROR_STOP=1 -U experiment -d experiment -c "ALTER SYSTEM SET wal_keep_size = '256MB'"
    docker exec "$POSTGRES_SERVICE" psql -v ON_ERROR_STOP=1 -U experiment -d experiment -c 'SELECT pg_reload_conf()'
    for port in "${ports[@]}"; do
      docker run -d --name "lab-standby-$port" --network host \
        -e PGPASSWORD=experiment -e STANDBY_PORT="$port" \
        --entrypoint bash postgres:17 -e -c '
          install -d -o postgres -g postgres /tmp/standby
          if [[ ! -s /tmp/standby/PG_VERSION ]]; then gosu postgres pg_basebackup -h 127.0.0.1 -p 5432 -U experiment -D /tmp/standby -R -X stream; fi
          exec gosu postgres postgres -D /tmp/standby -p "$STANDBY_PORT" -c listen_addresses=127.0.0.1
        '
    done
    for port in "${ports[@]}"; do
      ready=0
      for attempt in $(seq 1 60); do
        if docker exec "lab-standby-$port" pg_isready -h 127.0.0.1 -p "$port"; then ready=1; break; fi
        sleep 1
      done
      test "$ready" = 1
    done
    if [[ "$SCENARIO" == db-pgpool || "$SCENARIO" == db-pgpool-failover ]]; then
      bash "$GITHUB_WORKSPACE/qa/workloads/database-pgpool.sh"
    elif [[ "$SCENARIO" == db-replicas ]]; then
      php "$probe" replicas | tee "$EVIDENCE/database.log"
    elif [[ "$SCENARIO" == db-primary-promotion ]]; then
      php "$probe" promotion-seed | tee "$EVIDENCE/promotion-seed.log"
      docker stop -t 10 "$POSTGRES_SERVICE" >/dev/null
      docker exec -u postgres lab-standby-5433 pg_ctl promote -D /tmp/standby -w -t 30
      php "$probe" promotion-verify | tee "$EVIDENCE/promotion-verify.log"
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
