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

## B16 — route matcher acceptance policy

I: use fused and sharded exclusively, with both matchers on native and PHP-FPM browser lanes and on every existing authenticated HTTP host/database combination. Apply explicit matcher configuration to both scenarios, reject other laboratory matcher values. Historical generated-lane findings remain in previous run records; typed-route and authenticated-readiness reproductions remain mandatory. Hosted verification pending.

## B17 — core and data ecosystem workloads

I: five independent jobs: Foundation production core compiled readiness, live maintenance entry/exit and concurrent health after recovery; InterMix strict compiled/dynamic DI with 2,000 scoped jobs, nested and borrowed input, aliases, lifetime and invocation; ArrayKit immutable lazy config generations, partial/failed rebuild; renewable 300k-row lazy pipeline and hooked collection isolation; UID UUID1–8, six bases, binary/ULID roundtrip and 4k uniqueness. PHPProbe is an engineering tool, not runtime diagnostics (separate dev-only qualification required). All broader library rows remain open; hosted evidence pending.

## B18 — cache, validation and security ecosystem

I: nine isolated hosted lanes: CacheLayer tier promotion/bulk convergence, memory/weak/APCu/SHM/null, signed and unsigned integrity policy, invalid keys and serialization bounds, twelve-worker Redis atomic claims/counters; native OTP twelve-worker same-proof replay and attempt exhaustion plus recovery regeneration/consumption; ReqShield 2,000 compiled valid/invalid inputs, unknown-field projection after JSON decode, casts and schema export; Epicrypt 16 MiB authenticated file streaming/tamper and envelope AAD/key isolation. Shared durable recovery adapter, cluster, outbox, other security algorithms/protocols and all broader capability rows remain open. Hosted verification pending.

## B19 — runtime, routing, filesystem, communication and queue ecosystem

I: eleven independent lanes covering fused/sharded compiled routing corpus (1500 routes, nested groups, domain, literal/typed precedence and 404/405), Runwire bounded channels and semaphore plus child failure drain, real off-loop filesystem workers and watcher cleanup, Pathwise borrowed streams/chunks/scanner and download range/symlink/stale preparation, real TalkingBytes SMTP multipart/MIME privacy/parser, Omnibus native SQL chain/batch/cancel and Redis delayed transport with SQL effects (MySQL/PostgreSQL). Dedicated PHPProbe published engineering workflow tests syntax gate and deterministic source graph outside production no-dev app. Remaining middleware/protocol/remote storage/broker/QA feature matrices still open. No upstream fixes; hosted results pending.

## B20 — connected checkout, outbox and receipt workload

I: four independent native compiled HTTP jobs (MySQL/PostgreSQL × fused/sharded): 160 unique orders and 160 idempotent replays across eight clients, invalid quantity and conflicting replay rejection, stock conservation, rollback of queue publication and outbox marker, concurrent relay and durable consumers, Epicrypt-authenticated Pathwise files, Redis receipt cache publication, repeated delivery and exact Mailpit subject reconciliation. This is an isolated loopback checkout without account authentication; existing authenticated acceptance remains mandatory. No exactly-once SMTP guarantee under ambiguous acknowledgement or process death is asserted. Hosted evidence pending; additional capability rows remain open.

## B21 — advanced DB transaction contracts

I: nine additional jobs: two real PostgreSQL SERIALIZABLE snapshot workers with actual retry and durable callbacks; PostgreSQL/MySQL raw NOWAIT, SKIP LOCKED and native advisory lock ownership; native PDO transaction ownership (query cache bypass, callback and cache-aware write rejection, rollback); post-commit failure propagation, later callback execution and durable commit preservation across MySQL/PostgreSQL/SQLite. Native lock SQL is deliberately passed through DBLayer, not a claim of a public portable lock-builder API. Other DB rows remain open; hosted results pending.

## B22 — real optional service backends

I: four isolated hosted jobs for a three-primary Redis Cluster (all slots, no HA replica election), MongoDB primary (PHP 8.4 extension and published client), S3-compatible MinIO and SFTP v3 (loopback disposable credentials, published optional adapters). Cache scenarios exercise 12-process atomic claims, 1,200 keys, bulk/tag/namespace/CAS/consume; storage scenarios exercise real stream/range/prefix/move/delete reconciliation. Remote service logs excluded from artifacts because they can contain disposable credentials. SFTP test is a local protocol fixture, not authenticated-host-key production certification. Scylla/Cassandra native client compatibility, other cloud/FTP adapters, cluster failover and remote outages remain open. Hosted results pending.

## B23 — generated-stub native gRPC over TLS

I: separate native gRPC job (unary lane preserved) using published grpc/grpc and protobuf, real Python HTTP/2 TLS peer with disposable SAN certificate and actual generated-style BaseStub methods. Four workers × 20 cycles, 64 messages per stream, trailers, deadline, cancellation, wrong trust anchor and channel recovery. Bidi follows documented outbound-then-inbound contract, not interactive full duplex. TLS secrets kept outside artifacts. Hosted evidence pending; inbound application dispatcher and other protocol lanes remain open.

## B24 — real HTTPS communication safety

I: actual two-origin TLS HTTP peer, self-signed trust rejection and explicit CA success, 503 recovery with exact attempt count and no 404 retry, sensitive header removal on cross-origin redirect, allow-host and private network rejection, redirect cap, secure cookie path containment, timeout and reuse. No live external destinations or credential artifacts. Hosted qualification pending. Remaining HTTP streaming/multi, Runwire transport and other protocol/provider variants remain open.

## B25 — sustained production traffic and fault injection

I: two mandatory smoke jobs and four separate scheduled/manual native-host jobs (MySQL/PostgreSQL × fused/sharded). Profiles: four 10-second smoke / 45-second stress / 450-second soak / 1800-second endurance phases, with an additional 64-client spike. Each cycle validates idempotent POST/replay/GET; cross-tenant durable/cache aggregates and exact ledger IDs reconcile. SIGKILL targets only the disposable host process group after observed durable commit but before response, followed by idempotent recovery and graceful replacement. Monotonic phase timing and RSS evidence, finite nonnegative operation timing and exact operation counts. Bounded offered load rather than capacity certification; no production memory limit claimed. Weekly soak and explicit manual stress/endurance, no long-duration job on routine push. Hosted qualification pending. Authenticated workload remains mandatory; this traffic uses isolated application tenant IDs, not account authentication.

## B26 — advanced data, sequence and trust contracts

I: six independent lanes: UID 12-process filesystem reservation/fork/replacement, 12k Snowflake uniqueness and fixed timestamp regression; InterMix signed optional closure serializer tamper, wrong signer and bounds; ArrayKit layering and snapshot restore; Epicrypt CA/CSR/leaf, portable inspection, key/purpose/expiry/trust, PFX export/import and wrong password; both published string-protection algorithms; ReqShield frozen named schema, conditional/custom rules, compiled reuse and wildcard limits. No keys, closures or certificate private material written to evidence. Hosted qualification pending; remaining generator/provider, JOSE, rule and utility matrix gaps remain open.

## B27 — MySQL and MariaDB binary-log replication

I: two additional hosted jobs with real isolated source/reader servers, unique server IDs, row binlogs, replica SQL-thread pause/resume, read-only reader, actual stale reads and sticky primary writes, stopped reader fallback and restart/catchup. This is single-replica binary-log replication, not physical storage replication or automatic primary election. Disposable credentials are not included in artifacts. Hosted verification pending; proxies, HA, per-driver and API gaps remain open.

## B28 — Webrick production middleware contracts

I: four independent native middleware jobs: HTTPS/host and CORS policy, header/body framing and count limits; real Redis response-cache reuse and host/query/language isolation plus authenticated and no-store bypass; 12-process atomic throttle exact five admissions with weighted cost and identity isolation; encrypted cookie rotation, tampering, secure attributes and oversized Redis fallback. These exercise public middleware invocation, not full socket-level framing. Existing native/FPM routing lanes remain mandatory. Correct HTTPS negative assertion to require both forbidden requests absent; isolate MySQL X Protocol port and verify binlog readiness via TCP, restore reader read-only after restart. Hosted evidence pending.

## B29 — JOSE algorithm and serialization matrix

I: two independent published Epicrypt lanes: all 3 symmetric and 10 asymmetric JWT/JWS algorithms, native disposable RSA/EC/Ed25519 keys, public/symmetric JWK roundtrip, kid lookup/thumbprint, audience, activation and signature rejection, compact/flattened detached JWS and mixed-signature threshold; all six JWE key-management algorithms with A256GCM, compact/flattened roundtrips and tag rejection, multi-recipient A256KW and nested signed/encrypted token. No keys or token bodies written to evidence. Remote JWKS trust/fetch, distributed replay and remaining key lifecycle/interop matrices remain open. Hosted evidence pending.

## B30 — hosted evidence and fixture corrections

Latest completed production run [45551568675](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45551568675), historical commit cf1c2ce: 70/88 succeeded, 18 failed. Four database driver jobs failed (MySQL/MariaDB/PostgreSQL window order incorrectly supplied an associative map rather than documented list of order strings; SQL Server missing Microsoft ODBC driver). Two PostgreSQL standby jobs failed with required WAL segment removed during initial replication. Correct the test order input to `['id asc']`, provision ODBC 18 on the SQL Server hosted lane, retain 256 MiB of primary WAL for isolated standby setup/restart. Retain all business assertions. Remaining 12 failures: two import, PostgreSQL PAT and authorization, three browser, three authenticated HTTP, two security; do not attribute these solely to upstream without exact log review. B29 production run [45554179175](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45554179175) queued (152 jobs), consumer [45554179122](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45554179122) queued (20 jobs) at observation. All B22–B29 newly added lanes remain I. Four weekly/manual endurance lanes and one independent PHPProbe engineering job additionally configured. No upstream edits, no failure skips. Full coverage remains open for unimplemented capability rows.

## B31 — inbound mailbox protocols

I: two independent hosted jobs using real Dovecot IMAP/POP3, twelve synthetic messages each. IMAP server search, MIME/raw fetch, seen/unseen flags, copy/move, expunge and folder lifecycle plus bad authentication; POP3 UIDL identity, dot transparency, reset and deletion commit/reconnect. Plaintext is restricted to disposable loopback fixtures; TLS/STARTTLS and IDLE remain open. No upstream edits; hosted evidence pending.

## B32 — broker integration boundaries

I: real RabbitMQ and ElasticMQ SQS-compatible services using application-owned adapters implementing Omnibus AmqpBackend/SqsBackend (library exposes contracts, not concrete vendor clients). AMQP requeue, acknowledgement, rejection, unsupported delayed delivery and 50-message reconciliation; SQS delay, visibility, receipt replacement, batch limit and 50-message reconciliation. AMQP test adapter represents first/redelivered attempt only; SQS emulator is not AWS production certification. FIFO, DLQ policy, TLS and outage remain open. No upstream fixes; hosted evidence pending.

## B33 — Foundation module selection and lifecycle

I: eight Gray-code shards × 32 distinct masks cover all 256 explicit optional-feature selections with default specialist dependencies inactive, using real module enable/disable, exact selected topology, production config validation, release compilation, readiness and manifest digest. Separate lifecycle lane checks shared OTP ownership when MFA is removed while passkey remains enabled, blocked removal of enabled feature, idempotent activation, all-to-core removal and reinstall, and no-dev dependency isolation. This is selection topology, not all conditional driver or business-operation permutations; existing specialized business workloads remain mandatory. Hosted verification pending.

## B34 — actual Swoole-family host acceptance

I: four new browser workload jobs (Swoole PHP 8.5 and OpenSwoole PHP 8.6 × fused/sharded), with actual extension checks, explicit Runwire SWOOLE host selection, existing 200 concurrent browser operations, host replacement and session/HTTP invariants. PHP 8.5 for Swoole follows Foundation's documented upstream 8.6 extension compilation limitation; no fake host or native fallback. RoadRunner, FrankenPHP and other separately supported direct adapters remain open. Hosted verification pending.

## B35 — distributed invalidation and transactional outbox

I: real MySQL/PostgreSQL and Redis Streams, independent local SQLite node stores, namespace isolation, key/namespace invalidation, offline catchup, durable cursor replacement and history rotation; SQL native outbox commit/rollback visibility and idempotent post-commit local application; actual stream trim retention-gap recovery. APCu disabled deliberately to isolate independent node stores within one process; multiprocess node/transport-outage coverage remains open. Hosted verification pending.

## B36 — native Runwire wire interoperability

I: five independent hosted lanes: framed TCP and Unix sockets with fragmented/coalesced writes and oversize-frame rejection; UDP datagram echo and size ceiling; real RFC6455 WebSocket text/binary, ping and origin rejection; TLS ALPN HTTP/2 using independent Python h2 client, 16 concurrent streams per connection × five batches × eight clients, HTTP/1.1 ALPN fallback and untrusted certificate rejection. Independent clients are external protocol oracles, not mocks. HTTP/3/QUIC, mTLS, HTTP/1 request framing, Runwire outbound client variants and remaining deployment hosts are open. Hosted verification pending.

## B37 — real database proxy pooling

I: two separate PgBouncer session/transaction-pooling lanes, real PostgreSQL source and a three-backend pool, native prepared statement support explicitly configured, eight independent PHP workers × 50 commits with nested savepoint rollback, backend transaction affinity, post-commit effects and exact durable ledger reconciliation. Session mode additionally checks session affinity. No incompatible session features assumed in transaction mode. Statement pooling, Pgpool-II, ProxySQL/MaxScale and SQL Server listener HA remain open; hosted verification pending.

## B38 — Pathwise official adapter matrix

I: eight independent jobs cover memory, read-only, prefixing and ZIP plus actual FTP, WebDAV, GridFS, SFTP v2, AsyncAws S3, Azure-compatible Azurite and GCS-compatible fake-gcs-server. Combined with existing local, S3 and SFTP v3 jobs, all fourteen documented official adapter keys now have executable construction and I/O scenarios. Cloud-compatible emulators do not certify cloud production IAM, service behavior or availability; protocol TLS/host key and outage, custom adapter and local-only transaction/ownership capabilities remain open. Hosted verification pending.

## B39 — Scylla and real Redis Cluster election

I: native Cassandra-extension Scylla lane for binary/bulk values, TTL, tag invalidation and namespace isolation; six-node Redis Cluster with three replica links, replication offset catchup, actual primary stop and observed replica promotion, complete durable-value and post-election write reconciliation. The native PHP Cassandra driver is a mandatory prerequisite, not a mocked replacement. Hosted evidence pending; additional cache outage/security and provider-specific contracts remain open.

## B40 — RoadRunner and FrankenPHP worker hosts

I: four browser lanes on actual RoadRunner and FrankenPHP worker processes with fused/sharded compiled releases, explicit Runwire host drivers and Foundation trusted release factory, existing 200-cycle concurrent browser contract and generation replacement. No native-host substitution. Scylla/Redis HA now fail closed on backend exceptions. Hosted verification pending; host-specific advanced capabilities and protocol variants remain open.

## B41 — HTTP/3 and mutual TLS

I: two independent wire lanes: real native ext-quic HTTP/3 with aioquic independent client, eight connections × five batches × sixteen streams; actual TLS client certificates with missing/foreign certificate rejection and 160 authenticated HTTP/1.1 requests. No protocol fallback or extension bypass. Native ext-quic installation on hosted runner is a mandatory prerequisite; verification pending. Outbound QUIC and full network fault/flow-control coverage remain open.

## B42 — database migration, proxy and connection faults

I: six independent lanes: real migration failure and driver-specific transactional/nontransactional DDL recovery (MySQL/PostgreSQL/SQLite), actual MySQL/PostgreSQL connection termination mid-transaction with no replay or commit callback and explicit reconnect, real ProxySQL with eight workers and a three-connection backend ceiling (400 exact commits/savepoint effects). Pgpool-II, MaxScale, SQL Server HA and lost commit acknowledgement remain open. Hosted verification pending.

## B43 — runtime control, worker lifecycle and HTTP framing

I: two actual native-server lanes. Supervisor uses protected Unix control, oversized/stale requests, automatic request-limit recycling, explicit slot recycle, generation reload, graceful stop and socket cleanup while serving 120 requests; HTTP framing uses actual chunked/large-body digest reconciliation, conflicting lengths/transfer encodings, oversized headers/body/request line and slow-header timeout plus 40 concurrent recovery operations. These are isolated wire tests, not exhaustive resource saturation. Hosted evidence pending.

## B44 — durable OTP and factor lifecycle

I: five independent lanes: actual Foundation recovery-code store on MySQL/PostgreSQL with 12 separate consumers of one proof, regenerated/foreign account rejection and replacement-process exhaustion; GenericOtp shared replay, key and expiry across Redis/file/SQLite/SQL backends; TOTP SHA1/256/512 × 6/8 digits, bounded drift, non-regressing replay state and rotation enrollment/grace boundary. Durable recovery intentionally retains published schema behavior and may reproduce the known recovery ID-width defect. Proofs live only in runner-private temporary storage, never artifacts. Hosted qualification pending.
