#!/usr/bin/env bash
set -euo pipefail
umask 077
probe="$GITHUB_WORKSPACE/qa/workloads/database.php"
root="$(mktemp -d)"
secondary=lab-ag-secondary
cleanup() {
  docker rm -f "$secondary" >/dev/null 2>&1 || :
  rm -rf "$root"
}
trap cleanup EXIT
network="$(docker inspect -f '{{range $name,$v := .NetworkSettings.Networks}}{{$name}}{{end}}' "$MSSQL_SERVICE")"
test -n "$network"
docker run -d --name "$secondary" --hostname "$secondary" --network "$network" \
  -p 127.0.0.1:1434:1433 -e ACCEPT_EULA=Y -e MSSQL_PID=Developer \
  -e MSSQL_ENABLE_HADR=1 -e 'MSSQL_SA_PASSWORD=Experiment-Only-42!' \
  mcr.microsoft.com/mssql/server:2022-latest >/dev/null
sql() {
  LAB_SQL_PORT="$1" LAB_SQL_TEXT="$2" php -r '
    $pdo = new PDO("sqlsrv:Server=127.0.0.1,".getenv("LAB_SQL_PORT").";Database=master;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=3","sa","Experiment-Only-42!");
    $pdo->exec(getenv("LAB_SQL_TEXT"));
  '
}
scalar() {
  LAB_SQL_PORT="$1" LAB_SQL_TEXT="$2" php -r '
    $pdo = new PDO("sqlsrv:Server=127.0.0.1,".getenv("LAB_SQL_PORT").";Database=master;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=3","sa","Experiment-Only-42!");
    echo $pdo->query(getenv("LAB_SQL_TEXT"))->fetchColumn();
  '
}
await_scalar() {
  local port="$1" statement="$2" expected="$3"
  for attempt in $(seq 1 90); do
    if [[ "$(scalar "$port" "$statement" 2>/dev/null || :)" == "$expected" ]]; then return; fi
    sleep 2
  done
  echo "SQL Server AG readiness failed: $port" >&2
  return 1
}
await_scalar 1433 "SELECT SERVERPROPERTY('IsHadrEnabled')" 1
await_scalar 1434 "SELECT SERVERPROPERTY('IsHadrEnabled')" 1
primary_name="$(scalar 1433 'SELECT @@SERVERNAME')"
secondary_name="$(scalar 1434 'SELECT @@SERVERNAME')"
[[ "$primary_name" =~ ^[a-zA-Z0-9_-]+$ && "$secondary_name" =~ ^[a-zA-Z0-9_-]+$ && "$primary_name" != "$secondary_name" ]]
primary_ip="$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$MSSQL_SERVICE")"
secondary_ip="$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$secondary")"
[[ "$primary_ip" =~ ^[0-9.]+$ && "$secondary_ip" =~ ^[0-9.]+$ ]]
key="Lab!4$(openssl rand -hex 24)"
sql 1433 "CREATE MASTER KEY ENCRYPTION BY PASSWORD='$key'; CREATE CERTIFICATE lab_dbm WITH SUBJECT='Disposable AG'; BACKUP CERTIFICATE lab_dbm TO FILE='/var/opt/mssql/data/lab_dbm.cer' WITH PRIVATE KEY (FILE='/var/opt/mssql/data/lab_dbm.pvk', ENCRYPTION BY PASSWORD='$key');"
for file in lab_dbm.cer lab_dbm.pvk; do
  docker cp "$MSSQL_SERVICE:/var/opt/mssql/data/$file" "$root/$file"
  docker cp "$root/$file" "$secondary:/var/opt/mssql/data/$file"
  docker exec -u 0 "$secondary" chown mssql:mssql "/var/opt/mssql/data/$file"
  docker exec -u 0 "$secondary" chmod 600 "/var/opt/mssql/data/$file"
done
sql 1434 "CREATE MASTER KEY ENCRYPTION BY PASSWORD='$key'; CREATE CERTIFICATE lab_dbm FROM FILE='/var/opt/mssql/data/lab_dbm.cer' WITH PRIVATE KEY (FILE='/var/opt/mssql/data/lab_dbm.pvk', DECRYPTION BY PASSWORD='$key');"
unset key
for port in 1433 1434; do
  sql "$port" 'CREATE ENDPOINT lab_hadr STATE=STARTED AS TCP (LISTENER_PORT=5022) FOR DATABASE_MIRRORING (ROLE=ALL, AUTHENTICATION=CERTIFICATE lab_dbm, ENCRYPTION=REQUIRED ALGORITHM AES);'
done
sql 1433 "ALTER DATABASE experiment SET RECOVERY FULL; BACKUP DATABASE experiment TO DISK='/var/opt/mssql/data/lab_ag.bak' WITH INIT;"
sql 1433 "CREATE AVAILABILITY GROUP lab_ag WITH (CLUSTER_TYPE=NONE) FOR REPLICA ON N'$primary_name' WITH (ENDPOINT_URL=N'tcp://$primary_ip:5022', AVAILABILITY_MODE=SYNCHRONOUS_COMMIT, FAILOVER_MODE=MANUAL, SEEDING_MODE=AUTOMATIC, SECONDARY_ROLE(ALLOW_CONNECTIONS=ALL)), N'$secondary_name' WITH (ENDPOINT_URL=N'tcp://$secondary_ip:5022', AVAILABILITY_MODE=SYNCHRONOUS_COMMIT, FAILOVER_MODE=MANUAL, SEEDING_MODE=AUTOMATIC, SECONDARY_ROLE(ALLOW_CONNECTIONS=ALL));"
sql 1434 'ALTER AVAILABILITY GROUP lab_ag JOIN WITH (CLUSTER_TYPE=NONE); ALTER AVAILABILITY GROUP lab_ag GRANT CREATE ANY DATABASE;'
sql 1433 'ALTER AVAILABILITY GROUP lab_ag ADD DATABASE experiment;'
sync="SELECT synchronization_state_desc FROM sys.dm_hadr_database_replica_states WHERE is_local=1 AND DB_NAME(database_id)='experiment'"
await_scalar 1434 "$sync" SYNCHRONIZED
php "$probe" sqlserver-ag-seed | tee "$EVIDENCE/ag-primary.log"
await_scalar 1434 "$sync" SYNCHRONIZED
php "$probe" sqlserver-ag-read | tee "$EVIDENCE/ag-secondary.log"
# Fence the old primary first. This fixture tests manual read-scale promotion, not cluster HA.
docker stop -t 10 "$MSSQL_SERVICE" >/dev/null
sql 1434 'ALTER AVAILABILITY GROUP lab_ag FORCE_FAILOVER_ALLOW_DATA_LOSS;'
await_scalar 1434 "SELECT sys.fn_hadr_is_primary_replica('experiment')" 1
sql 1434 "ALTER AVAILABILITY GROUP lab_ag REMOVE REPLICA ON N'$primary_name';"
php "$probe" sqlserver-ag-promoted | tee "$EVIDENCE/ag-promoted.log"
