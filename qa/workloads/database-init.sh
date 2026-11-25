#!/usr/bin/env bash
set -euo pipefail
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
