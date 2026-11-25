# Production capability expansion — implementation register

Phase 1 is test implementation and defect discovery only. No upstream changes, no patched vendors, no skipped failures. I = implementation present, hosted evidence pending; V = verified exact-head; O = open; B = blocked, keep the reproduction executable. An upstream failure does not block implementation of independent scenarios.

## DBLayer — detailed production inventory

Source: [DBLayer docs](https://github.com/infocyph/DBLayer/tree/main/docs), including connections, transactions, query builder, pooling, schema, migrations, repositories, caching, security, monitoring and driver capabilities.

| ID | Scenario / invariant | State |
|---|---|---|
| DB-01 | Native MySQL/PostgreSQL transfer, nested savepoint rollback/promotion, callback timing, rollback and read-only transaction read | I |
| DB-02 | Real two-process opposite row locking, deadlock victim retry, rolled-back effects discarded, exact committed balances | I |
| DB-03 | Shared locks, lock wait/timeouts, NOWAIT/SKIP LOCKED where supported, advisory locks, reader/writer and isolation levels, serialization failures | O |
| DB-04 | PostgreSQL two physical streaming standbys, round-robin, random, weighted, least-latency TTL, sticky reset and all-reader connection failure fallback | I |
| DB-05 | Replica partial outage, health cooldown/recovery, replay lag pause/resume, read-only enforcement, read reconnect mid-flight, write non-replay, MySQL/MariaDB replicas | O |
| DB-06 | Pool warmup/capacity, independent leases, reuse timeout sanitation, stale lease and active transaction discard | I |
| DB-07 | Pool expiry, health/reconnect, active cursor discard, callback exceptions, Fiber and worker-generation ownership, external proxy/listener modes | O |
| DB-08 | SQLite WAL file and contention, MariaDB, SQL Server, driver capability and SQL dialect matrix | O |
| DB-09 | Schema migration ledger, up/down, constraints, foreign keys, indexes, defaults, JSON, schema/table prefix isolation, failed migration recovery | O |
| DB-10 | Query builder joins/subqueries, aggregates, windows, CTE/union, predicates, expression/raw bindings, returning, insert ignore, upsert, bulk parameter ceilings | O |
| DB-11 | Repository casts, scopes, relation definitions, eager loading, pivot/through/one-of-many, aggregate relations, pagination and optimistic writes | O |
| DB-12 | Streaming/unbuffered cursor memory, cancellation/early close, keyset pagination, chunking and bounded data processing | O |
| DB-13 | Cache hits/misses, transaction bypass, commit invalidation, rollback stability, tenant scopes, stampede locks, external transaction ownership | O |
| DB-14 | Security rules, raw provenance, identifier/binding rejection, query budgets, connection hooks, monitoring/statistics/query comments, prepared statements | O |
| DB-15 | Failure injection, disconnect/reconnect, ambiguous commit/non-replay, timeout/deadline, native transaction ownership, after-commit exception behavior | O |
| DB-16 | Complex mixed business load, concurrent readers/writers, resource budgets, extended soak and recovery across drivers | O |

## Ecosystem expansion queue

Each row must be decomposed into independently executable public-capability scenarios, backend variants and positive/negative/recovery checks. Existing broad coverage remains in capability-coverage.md.

| Library | Remaining production tracks | State |
|---|---|---|
| Foundation | module permutations, deployment migrations/rollback, auth lifecycle, tenant isolation, risk edge integration, scheduler, resources, telemetry | O |
| Webrick | routing forms and cache parity, middleware/policy matrix, real upload/download, negotiation/streaming, security and hosts | O |
| Runwire | transport/protocol matrix, client safety, workers/control/drain, concurrency/cancellation, async IO, process lifecycle | O |
| InterMix | DI binding/invocation/scope and compiled artifacts, closure signing, helpers and runtime adapters | O |
| CacheLayer | every adapter, atomic operations, node/tier/cluster, distributed invalidation/outbox, counters, failure policies | O |
| ArrayKit | data helpers, lazy collections, typed hydration, config precedence/compilation/generation, bounded memory | O |
| UID | every generator and encoding, parse/value objects, sequence leases, clock anomalies and concurrency | O |
| Epicrypt | protection algorithms/key lifecycles, stream/file crypto, JWT/JWE/JWKS, PKI, OAuth and token security | O |
| ReqShield | rule and cast matrix, nested/wildcard, custom/DB rules, schemas, input limits and compiled parity | O |
| OTP | all factor registrations, shared replay and durable recovery, drift, rotation, backend matrix | O |
| Pathwise | storage adapters, streams/uploads/chunks, public-file policies, symlinks, remote faults and cleanup | O |
| Omnibus | transport matrix, dispatch/policies, batch/chain/delay, failure/dead letter/retry and duplicate effects | O |
| TalkingBytes | HTTP, mail send/receive, webhook, gRPC unary/streams, TLS, retries, cancellation, failure | O |
| PHPProbe | runtime diagnostic capability inventory and production observations | O |
| Cross-library | interconnected tenant/order/import/document/notification workloads; stress, chaos, restore and endurance | O |

B12 does not certify DBLayer exhaustively. The physical standbys are local isolated containers on a hosted runner; no production HA election, network-partition or throughput certification. Keep every previously failing lane required.

## B13 — data plane implementation detail

All items below are I (hosted verification pending), not closure of their broader DB IDs. Each runs independently on MySQL, PostgreSQL and SQLite.

| Lane | Implemented coverage | Still open |
|---|---|---|
| db-schema / DB-09 | Migration ledger, idempotent rerun, rollback/reapply, DDL defaults, unique and foreign keys, cascade, ALTER | Failed migrations, all column types, named/schema/prefix isolation, migration ownership, seeders |
| db-query / DB-10 | Nested filters, branch isolation, aggregates, derived joins, CTE, union, upsert, insert-ignore and bindings | Windows, recursive CTE, returning, parameter ceilings, remaining clauses and dialects |
| db-repository / DB-11 | Tenant read/write isolation, native casts, optimistic version CAS, soft-delete, restore, force delete | Relationship matrix, hooks, repository APIs and concurrent CAS |
| db-query-cache / DB-13 | Shared file cache hit, transaction bypass, rollback preservation, commit invalidation | Tag scopes, shared stampede, external transaction ownership, remote backend outage |
| db-stream / DB-12 | 10k row unbuffered traversal, 5k composite cursor rows, early close, memory and elapsed diagnostics | Bounded memory thresholds, concurrent modifications, cancellation, chunk and lazy variants |
| db-security / DB-14 | Bound hostile string, raw policy, identifier rejection, cursor signature/tamper/filter binding | TLS, rate budgets, hooks, monitoring, query cancellation and statement cache |

SQLite file storage now exercised in these six lanes; WAL and competing writer behavior remain open. No upstream changes.

## B14 — operational database paths

I: independent lanes for shared row locking, bounded real lock waits and release (DB-03); repeatable-read snapshot, phantom/dirty-read checks (DB-03); SQLite WAL reader/writer contention (DB-08); idle pool expiry, active cursor discard and callback/iterator ownership (DB-07); nested query timeout restoration, cancelled/expired mutation rejection, statistics and explicit reconnect (DB-14/15); physical standby replay pause/resume, sticky routing under actual lag, real reader stop/restart and alternate-reader fallback (DB-05). All hosted results pending. Remaining: serialization retry, advisory/NOWAIT/SKIP LOCKED, cooldown expiry in same instance, MySQL/MariaDB replication, full driver/proxy and feature coverage.

## B15 — relations and built-in driver matrix

I: DB-11 bounded one/many/many-to-many (duplicate parents, empty results and query-count ceilings), declared repository nested eager loading, one-of-many and counts on MySQL/PostgreSQL/SQLite. I: DB-08/10 driver capability, window rank, upsert, unsupported conflict policy, returning and savepoint on all five built-in drivers (real MariaDB and SQL Server services added; SQL Server PHP 8.4 extension lane). DB-10 PostgreSQL insert-ignore is explicitly documented unsupported; assert rejection instead of requiring unsupported behavior. Remaining: through/polymorphic relation variants, all driver operations per dialect, actual MariaDB/MySQL replication, proxy, extensions/TLS and other rows. Hosted results pending.
