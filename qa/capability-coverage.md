# Production capability inventory and acceptance register

Scope: every public production-relevant capability family of the published Infbyte dependency graph, plus independently selectable backend/host integrations. Distinguish a native feature test, an integrated application scenario, a real wire/backend scenario, and a negative/failure scenario. Do not mark a feature covered merely because Composer installed the library, a library has unit tests, or a related feature passed. Sources are the library README and documentation trees under infocyph repositories on main, reviewed 2026-11-25; implementation must confirm APIs against the actual resolved published dependency graph. Each run records package versions. Version constraints remain public release ranges, not revisions.

Status: **V** = verified by latest applicable hosted execution; **I** = implemented, hosted acceptance pending; **B** = blocked by reproducible failure; **O** = open. V is limited to the specific scenario/backend, not blanket coverage of a family. Evidence baseline: [run 45545832270](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45545832270), 14/22, and [consumer acceptance 45545832219](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45545832219), 20/20. Earlier order verification run 45545402715 passed both SQL backends; latest failures are negative wall-clock durations, not domain invariant failures.

| ID | Library / production feature family | Verified or implemented | Remaining acceptance work |
|---|---|---|---|
| F01 | Foundation application lifecycle, modules, config, CLI, compiled artifacts, readiness | V: clean published project, all 8 modules installed/enabled, isolated consumer matrix; V: operations, generation rollback | B: auth-enabled readiness resolves scoped state outside active scope on 3 HTTP lanes; O: all feature disable/remove/re-enable combinations, cache invalidation and version upgrades with retained data |
| F02 | Foundation auth/account/session/token/password/verification | V: SQL login, password failure, verification/reset replay, refresh/session rotation | I: independent browser Redis session locking, rotation, invalidation, flash and CSRF; O: account locking, device/remember-me, credential change and session revocation across hosts; browser cookie auth |
| F03 | Foundation authorization and risk | V: MySQL RBAC/delegation/revocation, B: PostgreSQL DISTINCT JSON; V: native shared risk authority/edge claim classification, challenge/clearance/replay, shadow and capacity bulkhead (B9 jobs 138980801092, 138980801152) | O: route-specific deployment, trusted vs untrusted edge headers, independent tenant resource policies, failure and outage |
| F04 | Foundation OTP/passkey/OAuth/PAT | V: MySQL/PostgreSQL signed assertion, client credentials/PKCE/revocation; B: recovery factor ID width | O: full registration, all factor activation and step-up, OIDC ID token/UserInfo, refresh family, DPoP, PAT, key rotation and real OAuth HTTP endpoints |
| F05 | Foundation scheduling, queues, resources, telemetry | V: scheduler contention, crash recovery, SQL restore, Redis recovery | O: process cancellation/timeouts/history, command failures, resource exhaustion and correlated logs, multi-service recovery |
| W01 | Webrick routing, compiled dispatch, route cache | I: business API, 4-worker native and FPM blocked by readiness; V: consumer basic host | I: 404/405/OPTIONS/HEAD, named static/typed routes, fused/sharded browser and authenticated HTTP parity; O: groups/domain/resources/attributes and signed URL expiry/tampering |
| W02 | Webrick HTTP policies | I: negotiation, compression/Vary, ETag, cookie flags and browser CSRF | O: CORS/credentials, request limits, encrypted cookies, rate/throttle, maintenance, error/linter/telemetry, response caching and invalidation |
| W03 | Webrick file/stream/host boundaries | O | multipart safe upload, range/conditional attachment, HEAD, SSE/chunked/disconnect; FPM, native, Swoole, OpenSwoole, RoadRunner, Workerman, FrankenPHP where supported |
| R01 | Runwire process lifecycle | V: gRPC independent processes and worker fixture scopes; I: host restart/soak | O: portable vs prefork, crash/recycle/reload/control socket, drain, signal/deadline/timeout, worker limits/CPU quotas, child cleanup |
| R02 | Runwire inbound/outbound network | V: TalkingBytes HTTP loopback, gRPC through native extension (not Runwire HTTP/2) | O: actual HTTP/1.1 keepalive/pipelining/chunking, HTTP/2 ALPN/flow control, HTTP/3 QUIC, TLS/mTLS, WebSocket/SSE, TCP/UDP, SSRF peer/redirect/retry, slow/malformed clients |
| R03 | Runwire structured concurrency/filesystem | O | fibers/coroutines, task groups, cancellation, backpressure, bounded async file I/O, process execution, structured cleanup and host context forwarding |
| I01 | InterMix DI, invocation, lifecycle | V: Foundation scoped graph exercised; I: explicit scope reset in import workload | O: native compiled input/alias/tagged bindings, typed invocation, nested/borrowed scopes, cleanup failures, generated-artifact trust and host parity |
| I02 | InterMix utilities/integration | O | definition cache, signed closure serialization and invalid input, Fence, Remix/MacroMix/tap/pipeline helpers, optional Runwire/CacheLayer adapters |
| A01 | ArrayKit data processing | I: DTO hydration, dot paths, collection and typed nested import | O: lazy pipeline, hooks, helper/set operations, large streaming bounded graphs, coercion/cyclic input |
| A02 | ArrayKit configuration | O | dotenv precedence/references, typed config, compile/lazy namespaces, generation pinning/rotation, partial rebuild, immutable runtime and corrupt artifact |
| U01 | UID application IDs | I: UUID, ULID, TypeID, ObjectID, Snowflake, Sonyflake, TBSL, KSUID/XID, NanoID, CUID2, random/deterministic across SQL uniqueness | O: remaining UUID versions, Randflake, parsing/value objects and binary/base encoders, custom epochs, shared sequence providers/lease ownership, restart/regressed clock |
| D01 | DBLayer SQL persistence | V: PostgreSQL/MySQL transactions, joins, rollback, inventory, queues, OAuth, backup | O: SQLite file/WAL, additional officially supported SQL drivers, schema upgrades/rollback, repository/entity/relation loading, query builder, cursor/stream, bulk/upsert, JSON types |
| D02 | DBLayer operational modes | O | read/write/replica consistency, pooled leases, stale/discarded connections, deadlock/serialization retry, bind limits, observability, query cache invalidation and backend reconnect |
| C01 | CacheLayer direct caches | V: Redis atomic CAS/consume, TTL and shared locks; I: seven file/PHP-files/SQLite/PDO(MySQL/PostgreSQL)/Valkey/Memcached lanes, PSR-6/16 bulk/deferred, tag invalidation, shared remember, namespace isolation, TTL | O: integrity tamper, serialization-policy negatives, APCu/Mongo/Scylla/Redis cluster, supported-only SHM/weak-map/memory/null |
| C02 | CacheLayer node/cluster/counters | V: Redis lock topology; I: direct counter reconciliation in import workload | O: tiering, APCu/SQLite Node, clustered invalidation, transactional outbox, offline catch-up/replay, isolation of counter state, memoization, fail-open vs authoritative failure |
| P01 | Pathwise storage | V: local file streams, move, traversal rejection, sizes, SQL receipt roundtrip | O: upload/download processing pipeline, chunk assembly, signed/range streaming, retention/index/queue, scanner, symlink/root confinement, local/FTP/SFTP/S3 and supported remote adapters |
| E01 | Epicrypt cryptographic data protection | I: rotated keyring, context-bound encrypted SQL import records | O: file secretstream/envelope, key purposes and wrong keys, key/cert generation, detached signatures/MAC, AES support, PKI verification |
| E02 | Epicrypt tokens/auth standards | V: password hash via Foundation, asymmetric OAuth access tokens + PKCE | I: separate OIDC signed ID-token/UserInfo, DPoP proof binding/replay, PAT ability/revocation and refresh scope/family lanes on both SQL backends; O: JWT/JWE algorithm matrix, opaque tokens, remote JWKS caching/SSRF, key rollover, token storage, wire OAuth endpoints |
| Q01 | ReqShield schema and input pipeline | V: database exists/range; I: nested wildcard, sanitize, cast, strip unknown and compiled reuse in import | O: unique/conditional/custom/fragments, locale/error metadata, uploaded files, JSON Schema/OpenAPI, resource limits, cancellation, independent rule matrix |
| O01 | OTP native factor protocols | V: independent Redis-backed Generic/TOTP/HOTP/OCRA/AOTP/Grid/MobileOTP (B8 job 138979931498) state machines, separate from Foundation persistence blocker | O: durable recovery-code adapter, registration ceremony, cross-process same-proof race, rotation/provisioning, time drift/exhaustion and backend variations |
| M01 | Omnibus dispatch and durable workers | V: SQL after-commit, rollback, duplicate effect, retry/dead letter, SIGKILL reservation recovery | O: sync commands/events, middleware, delayed scheduling, Redis/Valkey/AMQP/SQS, poison payloads, dead-letter retry/pruning, workflow chains/batches, rate/uniqueness/circuit policies, broadcast |
| T01 | TalkingBytes HTTP/webhooks | V: real loopback HTTP signed webhook/replay | O: retry and timeout policy, concurrent requests, streaming, cookies, redirect safety, TLS and SSRF, tamper and delivery failure |
| T02 | TalkingBytes mail | V: real Mailpit SMTP | O: MIME attachments, DKIM, bounces, SMTP failure/retry, spool, real IMAP/POP3 receive, TLS/credentials |
| T03 | TalkingBytes gRPC | V: 1300 unary calls and UNIMPLEMENTED per matching native call | O: streaming (client/server/bidi), deadline/cancellation, TLS, inbound dispatch and generated stub integration |
| X01 | PHPProbe (transitive) / runtime diagnostics | O | verify public capability and runtime use; do not install dev-only PHPForge in the production consumer |
| X02 | End-to-end system | V: separate operations and domain workloads | O: connected browser/HTTP → validation → transaction → outbox → durable consumer → encrypted file → notification, multi-host failure, sustained soak, coordinated restore, memory and resource budgets |

## Rules and evidence

- EXEC-01/02/03: one batch per commit, keep the register synchronized with hosted job evidence, no false completion, no upstream edits or production dependencies pinned to revisions.
- CORE/FLOW: retain reproduced upstream defects and mandatory failing lanes. Correct fixture and runner errors without weakening invariants.
- PERF/SEC: use monotonic duration for performance; wall-clock time only for protocol/expiry semantics; no hosted-runner production RPM claim.
- TYPE/STRUCT/REQUEST/MEM: use native public contracts, typed boundaries, bounded work and deterministic per-scope state; no parallel app-defined implementations of library algorithms.
- DATA/CONFIG/OPS/OBS: disposable real services, generated secret material, no vendor edits, no secret/key/token artifacts, close resources, reconcile exact effects and capture dependency versions.
- Public feature inventory is not an assertion of a completed exhaustive API-method or backend matrix. For each O, require concrete scenario, real dependencies where applicable, failure/recovery tests and a successful exact-head run before changing to V. Backend or external-service prerequisites are explicit blockers, not skipped acceptance.

## Sources
- [Foundation documentation tree](https://github.com/infocyph/Foundation/tree/main/docs)
- [Webrick documentation tree](https://github.com/infocyph/Webrick/tree/main/docs)
- [Runwire documentation tree](https://github.com/infocyph/Runwire/tree/main/docs)
- [InterMix documentation tree](https://github.com/infocyph/InterMix/tree/main/docs)
- [ArrayKit documentation tree](https://github.com/infocyph/ArrayKit/tree/main/docs)
- [CacheLayer documentation tree](https://github.com/infocyph/CacheLayer/tree/main/docs)
- [UID documentation tree](https://github.com/infocyph/UID/tree/main/docs)
- [DBLayer documentation tree](https://github.com/infocyph/DBLayer/tree/main/docs)
- [Pathwise documentation tree](https://github.com/infocyph/Pathwise/tree/main/docs)
- [Epicrypt documentation tree](https://github.com/infocyph/Epicrypt/tree/main/docs)
- [ReqShield documentation tree](https://github.com/infocyph/ReqShield/tree/main/docs)
- [OTP documentation tree](https://github.com/infocyph/OTP/tree/main/docs)
- [Omnibus documentation tree](https://github.com/infocyph/Omnibus/tree/main/docs)
- [TalkingBytes documentation tree](https://github.com/infocyph/TalkingBytes/tree/main/docs)

## Detailed expansion
See [production-capability-expansion.md](production-capability-expansion.md) for B12 granular DBLayer mechanics and remaining ecosystem tracks. New DBLayer scenarios are I (implemented, not verified) until exact-head hosted evidence is available. Existing mandatory failures are retained.

B13 adds 18 independent DBLayer data-plane jobs across MySQL/PostgreSQL/SQLite. See detailed expansion register for scenario-level I/O boundaries; no upstream remediation.

B14 adds database operational scenarios; see the detailed expansion register. Status I until hosted evidence.

B15 adds five-driver mechanics and bounded relationship lanes. Remaining relation variants and per-driver coverage are explicitly open in the detailed expansion register.

B16 sets fused/sharded as the only acceptance matchers for both production HTTP workloads. Historical generated matcher execution remains evidence, not a required current lane.

B17 adds five core/InterMix/ArrayKit/UID native capability jobs; PHPProbe belongs to engineering QA, not production runtime. Detailed tracker records implemented vs open.

B18 adds nine cache, validation and security jobs. Distinguish native OTP recovery from Foundation's existing durable recovery failure; no defect bypass.

B19 adds native runtime/routing/storage/communication/queue lanes and a separate PHPProbe engineering workflow. No generated matcher, no QA package added to no-dev production.

B20 adds four connected checkout jobs; existing authenticated HTTP and separate failure reproductions remain required. See detailed expansion register for boundaries and unresolved coverage.

B21 adds advanced DB transaction/lock contracts and native PDO ownership. No closure of all driver or deployment capabilities is asserted.

B22 adds real Redis Cluster, MongoDB, S3-compatible and SFTP v3 scenarios; see detailed register for topology and protocol limitations.

B23 adds native TLS streaming acceptance, preserving the independent unary lane. Full duplex and inbound framework dispatch are not certified.

B24 adds real HTTPS trust, retries, redirect credential boundaries, cookie path and timeout recovery acceptance.

B25 adds sustained workload profiles, controlled host crash/replay and exact durable/cache reconciliation, with weekly soak and manual endurance. Hosted evidence pending.

B26 expands shared UID sequence, signed closure, configuration precedence, PKI/AEAD and schema registry acceptance. Remaining detailed gaps are not closed.

B27 adds actual MySQL and MariaDB binlog source/reader routing, lag, outage and restart; not automatic HA election.

B28 adds native Webrick gateway/CORS/limits, Redis response cache, atomic throttle and protected cookie scenarios; fixes HTTPS negative evidence and binlog fixture isolation. Wire-level coverage remains separate.

B29 adds full published JWT/JWS algorithm enumeration and JWE key-management enumeration with JWK and serialization checks. This does not close all JOSE, key lifecycle or remote trust capabilities.

B30 records historical 70/88 hosted evidence (run 45551568675) and repairs three fixture failure groups. Latest B29 run 45554179175 (152 production jobs) and consumer 45554179122 (20 jobs) were queued; exact-head verification and remaining scenario implementation are open. See detailed expansion register for failure attribution.

B31 adds real IMAP and POP3 mailbox acceptance; TLS/IDLE remain open.

B32 adds RabbitMQ and SQS-compatible integration using documented application-owned backend boundaries; not a production AWS or full AMQP adapter certification.

B33 adds explicit 256-mask Foundation topology qualification and shared-package lifecycle; specialist driver permutations remain open.

B34 adds actual Swoole PHP 8.5 and OpenSwoole PHP 8.6 browser runtime acceptance (fused/sharded); no native fallback.

B35 adds real distributed invalidation logs, offline cursor/retention and transactional outbox scenarios; node caches are independent local SQLite stores.

B36 adds native concurrent Runwire wire acceptance with independent clients; HTTP/3 and remaining client/deployment features remain open.

B37 adds real PgBouncer session/transaction pooling, native prepared statements and exact concurrent transaction reconciliation.

B38 adds remaining documented official Pathwise adapters (cloud emulators explicitly distinct from production providers); hosted evidence pending.

B39 adds native Scylla and six-node Redis Cluster actual failover; Cassandra driver compatibility and hosted evidence pending.

B40 adds real RoadRunner and FrankenPHP workers through Foundation's trusted compiled release factory, both compiled matchers. Hosted evidence pending.

B41 adds native HTTP/3 and mTLS scenarios; ext-quic availability and hosted results pending.

B42 adds real migration recovery, lost transactions and ProxySQL concurrency. Hosted evidence pending.

B43 adds native runtime control/recycling/reload and HTTP wire framing/limits/recovery.
