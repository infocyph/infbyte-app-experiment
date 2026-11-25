# Production workload laboratory — rolling acceptance plan

Goal: independently qualify real published Infbyte/Foundation applications, not library-only unit tests. GitHub hosted workers only; disposable services and synthetic accounts; correctness before speed. No upstream changes or production dependency pins. Each batch one commit. Use native library contracts. Record exact dependency graph, failures, operation counts, wall time, concurrency and latency percentiles. Shared runner numbers are diagnostic, not a controlled throughput baseline (PERF-01/03/05/07).

## Capability inventory and execution tracker

| Area | Production scenario | Status |
|---|---|---|
| Core DB-independent auth, identity, account, session, token, reset, verification | Persistent account onboarding and password/refresh replay rejection, SQL storage, Redis | B1 implemented; hosted pending |
| DBLayer | Concurrent inventory/orders, multi-table transactions, joins/aggregates, rollback, integrity; real PostgreSQL and MySQL | B1 implemented; hosted pending; joins, migrations/replicas/deadlocks still extend |
| CacheLayer | Shared Redis claim/replay + file state | B1 implemented; hosted pending; locks, TTL, outbox, cluster/Valkey, cache failure still extend |
| ReqShield | Real exists/range checks before database changes | B1 implemented; hosted pending; complex nested/unique/conditional payloads still extend |
| Pathwise | Receipt persistence/readback | B1 implemented; hosted pending; upload/download streaming, traversal, chunking, remote disks still extend |
| Epicrypt | Selected password hashing through auth | B1 implemented; hosted pending; JWT, keys, encryption, OAuth/OIDC/DPoP still extend |
| TalkingBytes / notifications | Parallel SMTP via real Mailpit, exact unique receipt count | B1 implemented; hosted pending; attachments, retries, HTTP, signed webhook, gRPC, email receive still extend |
| OTP MFA | Real TOTP/HOTP/AOTP, recovery, replay, concurrent step-up | Pending B2 |
| WebAuthn passkeys | Signed software authenticator registration/assertion, one-time challenge, counter contention | Pending B2 |
| Omnibus | Durable SQL/Redis jobs, after-commit, idempotent business handler, parallel drain, retries, dead letters, kill/restart | Pending B2 |
| Authorization and browser | Roles, permission grants, tenant isolation, cookie/CSRF, session locks, risk admission and OAuth flows | Pending B2/B3 |
| Webrick and Runwire | Real business API routes, validation/auth middleware, mixed load, native and FPM hosts, persistent worker isolation, cancellation, slow client | Pending B3 |
| InterMix, ArrayKit, UID, Core resource/logging | Scope isolation, typed config, unique IDs, contextual logs and correlation, bounded memory | Pending B3 |
| CLI, scheduling, operations | Real migrations, singleton scheduling, worker restart, release/rollback, backup and restore | Pending B4 |
| Stress and failure qualification | Repeat concurrency 1/4/8/16+; contention, sustained soak, service restart and resource limits; verify all domain invariants | B1 bounded load implemented; sustained/chaos pending |

## B1 acceptance
- [x] Inventory public capability documentation and implementation examples at published Foundation 4.0.1.
- [x] Install all eight optional features via real create-project no-dev, verify dependency isolation.
- [x] Add five independent jobs on PHP 8.6: orders and persisted auth on MySQL/PostgreSQL, SMTP on Mailpit. Parallel independent processes with repeated execution scopes; 1/4/8 concurrency, 520 order or mail operations per job and 104 password-heavy account operations per job. Each phase reconciles cumulative state and reports timings.
- [ ] Hosted results; repair API/fixture errors separately from real upstream failures. No skipped failures.
- [ ] Complete remaining B2–B4 scenarios in batches; add full application-level HTTP stress (B1 is CLI business-operation load, not HTTP RPM).

## Limits
The B1 workers use application composition and native services, not a compiled HTTP host. Disposable backend credentials, loopback only. No production secrets or real email recipients. Schema/records reset by fresh hosted service containers. No production-equivalent throughput claim or arbitrary latency gate. CI cannot establish every library capability by mere package installation; every untested capability remains open in the tracker.

B1a: initial workflow run 45544003087 was rejected before workers registered. Move runner.temp-based path initialization out of job-level env and into a step using RUNNER_TEMP/GITHUB_ENV. Runtime evidence still pending; do not count the rejected run as workload execution.

## B2 — security and durable delivery
- [x] FLOW-02: run 45544050115 failed all five at shared auth cache configuration; explicitly configure native Redis atomic counter. Retain the guard and shared state, no fallback to memory.
- [x] Add security workloads on both databases: persisted RBAC, scoped delegation/revocation, actual OTP enrollment/replay and signed native passkey authentication with one-time challenge. Software authenticator seeded credential; registration ceremony and other OTP factor types remain open.
- [x] Add Omnibus SQL queue workloads on both databases: after-commit dispatch, rollback exclusion, duplicate messages, transient retries, permanent failure records, four separate drain processes, exact effect and attempt reconciliation.
- [x] CONFIG-01: Composer-optimized autoload for application fixtures instead of manual class loading; joined SQL reconciliation.
- [ ] Hosted runtime results for B1/B2; verify before claiming acceptance. Queue crash/reclaim, HTTP and operations remain open.

## B3 — real HTTP and independent security scenarios
- [x] B2 run 45544534242: account lifecycle (both databases) and SMTP passed; order and queue tests failed because DBLayer correctly wraps the deliberate DomainException in TransactionException. Assert exact previous cause and retain final reconciliation. Both security lanes fail with the native SQL recovery-code factor ID width mismatch (77 generated characters vs VARCHAR(64)); upstream blocker remains, no schema override or skips.
- [x] Split signed passkey assertions into separate MySQL/PostgreSQL jobs while retaining mandatory OTP recovery failure tests.
- [x] Install actual HTTP business routes in disposable create-project application; compile production configuration, JWT issuance and principal middleware, native role/permission denial, multi-table writes and exact receipt reads. Five HTTP exchanges per successful business cycle; Native Runwire four-worker on both databases and four-worker PHP-FPM/Nginx on PostgreSQL; 1/4/8/16 client concurrency, host restart, persistent state reconciliation. Report cycle RPM (not raw HTTP RPM), p50/p95/p99, and no false successes. All three lanes pending hosted verification.
- [ ] Full browser CSRF/session, HTTP risk policy, OAuth/OIDC, streaming/upload and other network protocols still open. Runwire replacement is graceful stop/restart, not sudden process death or multi-host failover.

## B4 — operational integrity and communication
- [x] Add real TalkingBytes loopback HTTP and signed webhook delivery, native signature validation and CacheLayer replay exclusion. Explicit receipt reconciliation (single peer, independent sender processes).
- [x] Add abrupt Omnibus reservation worker SIGKILL with expired visibility lease, independent consumer recovery and exact durable effect checks on both databases.
- [x] Add production CLI scheduled command with real shared lock and four simultaneous scheduler processes; reconcile SQL report. Quiesced PostgreSQL snapshot restored to separate database and compare five complete table digests. Build two immutable runtime generations and reactivate the original pointer; verify manifest. Stop Redis and require authoritative cache failure; restart and verify recovery. Hosted evidence pending.
- [ ] Long-duration soak, actual multi-host failover, native gRPC transport, OAuth/OIDC protocol, browser CSRF/session and risk admission, remaining OTP variants and registration, rich upload/download and remote backend coverage remain open. The operations recovery test is a SQL-only quiesced snapshot, not a coordinated multi-service/customer backup certification.

## B5 — separate confirmed defects from harness corrections
- [x] Run 45545106160: accounts both databases, Mailpit SMTP and MySQL signed passkey passed. Order and operations cache-key grammar rejected colon keys; use supported dot-separated keys. Fix duplicate route import and enable required production webhook replay configuration. FPM PHP 8.6 package unavailable on hosted apt; use matching PHP 8.4 CLI/FPM for that lane, retaining PHP 8.6 elsewhere.
- [x] Preserve two distinct Foundation 4.0.1 defect reproductions: OTP recovery-factor ID 77 bytes against VARCHAR(64) (MySQL job 138972714891); PostgreSQL authorization DISTINCT of JSON metadata (job 138972715049). Isolate authorization, OTP and passkey lanes so one defect cannot prevent exercising the others. No upstream edits, schema widening, ignored errors or acceptance waivers.
- [x] Queue failures now report actual native failure class/reason, attempted message identity and kind, with exact expected permanent-failure invariant. Record signed webhook delivery details on failure.
- [ ] Re-run all lanes on this commit; investigate remaining defects.

## B6 — expanded protocol, storage and sustained workloads
- [x] Add streamed 128 KiB file upload/read integrity, rename, directory reconciliation, parent traversal rejection, native atomic CAS/consume, shared lock-ledger contention, TTL expiry.
- [x] Add real published OAuth client credentials, introspection and revocation, public authorization code + PKCE, consent and code replay rejection on both SQL backends, with native auth schema installer and disposable signing keys.
- [x] Add independent native gRPC job: private PHP INI, real loopback HTTP/2 peer, selected published communication feature, 1/4/8 parallel clients, 1,300 successful unary exchanges plus matching UNIMPLEMENTED responses, channel cleanup and latency evidence.
- [x] Add 90-second concurrent business-cycle soak on PostgreSQL native HTTP host and periodic process RSS observations. This is a bounded shared-runner workload, not a dedicated-host capacity result.
- [ ] All B5/B6 hosted acceptance results and corrections. OIDC-specific, DPoP/PAT, browser session/CSRF/risk, HOTP/AOTP and registration, distributed multi-host/remote storage, advanced cluster/outbox/replica modes, full streaming HTTP, coordinated backup and long-duration endurance remain separate qualification gaps. No claims of exhaustive native library API coverage.

## B7 — reconcile actual hosted evidence and runner provisioning
- [x] B5 run [45545402715](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45545402715): 8/18 success (orders MySQL/PostgreSQL, accounts MySQL/PostgreSQL, SMTP, signed HTTP/webhook, MySQL authorization, PostgreSQL passkey). Prior MySQL passkey succeeded in B4; B5 stopped at incomplete operation evidence after 40 successful cycles; preserve failure and improve count diagnostics. Three security lanes still reproduce native OTP ID width and PostgreSQL authorization defects. Both queues drained and produced intentional dead letters but the default listing capped at 100 of 260; explicitly request 1000, maintain exact count and kind/reason reconciliation. HTTP/operations config validator required explicit finite route delay; set 0.0. Runner apt also lacks PHP 8.4 FPM; use actual PHP 8.4 FPM Docker image with native pdo_pgsql and redis, matching CLI version and four-worker pool, loopback service networking, non-root mounted application. Do not count these corrections as successful acceptance until hosted execution.
- [ ] Latest exact-head run qualification; no upstream package changes. Existing consumer suite passed on B4 (run 45545106211); new workload suite still red at B5.

## B8 — public capability register, multi-library import, native factors
- [x] Latest baseline [45545832270](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45545832270): 14/22 passed, original consumer 20/20. Verified both queue lanes including crash recovery, storage, operations, OAuth both databases, native gRPC, SMTP, signed webhooks, passkeys both databases, accounts both databases, MySQL authorization. Eight failed: SQL OTP enrollment twice; PostgreSQL role query; three authenticated HTTP readiness failures (scoped RuntimeExecutionState); two order timing failures with full operation counts and negative elapsed wall-clock durations. Preserve all required tests; use monotonic hrtime for elapsed, and supported full AuthSchemaInstaller (including revision migrations) in scoped setup; do not bypass app:ready. If readiness remains broken it requires an upstream fix.
- [x] Reviewed README and documentation trees of all 14 specialist/runtime libraries. Create explicit [capability register](capability-coverage.md), separate verified/implemented/blocked/open families; includes optional hosts and service backends, security and recovery.
- [x] Add independent two-DB data import with compiled ReqShield nested/wildcard normalization and unknown-field rejection, ArrayKit guarded DTO/dot-path/collection, InterMix execution-scope identity isolation, 14 UID families with durable unique-key collision detection, Epicrypt keyring fallback/renewal/context binding, protected SQL/file parity, native Redis atomic counters.
- [x] Add independent Redis-backed seven OTP protocol state machines and replay rejection, so Foundation recovery-code schema defect cannot obscure native OTP behavior. Legacy MobileOTP compatibility test only.
- [ ] Hosted verification and remaining O/B rows in capability register. No upstream changes, no skipped acceptance.

## B9 — browser and risk boundaries independent of authenticated readiness
- [x] Add 3 compiled browser HTTP lanes (native fused/generated, PHP-FPM sharded) with actual Redis session storage, four host workers, same-session 200 concurrent writes, replacement and exact count, separate-cookie identity, missing/query/foreign-origin/foreign-session CSRF rejection, secure cookie attribute assertions, regeneration, flash and invalidation. Browser lane intentionally excludes account auth (a separate mandatory blocked lane); loopback HTTP is not TLS or browser-engine certification. Explicit Cookie headers emulate browser requests without exposing credentials in output.
- [x] Add named/static/typed routing, method rejection/Allow/OPTIONS/HEAD, negotiation rejection, gzip/Vary and ETag revalidation, Pathwise public file resolution, native range/416 and NDJSON streaming over actual compiled wire host.
- [x] Add independent native RiskLogin/CacheLayer shared-state scenarios on MySQL/PostgreSQL: direct/attested signal classification, real persisted authentication, failure-driven challenge, preverified synthetic clearance proof, deny and shadow policy, single-use clearance, capacity exhaustion and release. Edge claims are synthetic, not a real proxy/CDN attestation or challenge provider wire test.
- [ ] Hosted verification, plus explicit remaining capability register gaps. No disabled readiness checks or upstream modifications.

## B10 — advanced identity flows and harness corrections
- [x] B8 run [45547317671](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45547317671): 17/25 passed, including both order lanes after monotonic timing repair and all seven independent native OTP protocols. Two import lanes fail at unsupported instance compile invocation (ReqShield static compile requires a rules array); use the public CompiledValidator constructor to preserve configured casts/strip policy. Auth-enabled readiness continues to fail after full scoped schema setup; keep mandatory lanes red. Recovery ID width and PostgreSQL DISTINCT JSON remain.
- [x] B9 browser compilation failed on unsupported `web` alias; use published Foundation `session` and `csrf` middleware aliases in order. No middleware removal.
- [x] Eight independent advanced identity lanes: OAuth refresh narrowing, widening denial and family replay revocation; OIDC signature and claims validation with independent signing keys, nonce/hash and UserInfo; DPoP proof-bound resource acceptance, replay/foreign-key/missing-proof rejection; PAT scoped abilities, durable token metadata, single and bulk revocation, reissue. Each on MySQL and PostgreSQL. Native application execution, not OAuth HTTP-wire acceptance.
- [ ] Hosted verification and remaining open register rows. No upstream code modifications or bypassed checks.

## B11 — real cache backend matrix and receipt reconciliation
- [x] B9 run [45547585121](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45547585121): 18/30 passed. Both risk admission lanes passed, both order and passkey lanes passed, independent native OTP passed. Three browser jobs blocked by unknown `web` alias (corrected B10); imports and six upstream-failure lanes remain mandatory. SMTP acknowledged all 520 messages but immediate Mailpit enumeration did not reconcile the expected count (job 138980801267). Retry bounded snapshots of the actual inbox with exact count/subject uniqueness and explicit diagnostic; do not presume mail loss is fixed or reduce count.
- [x] Add seven real native CacheLayer adapter workflows: file, PHP files, SQLite, PDO/MySQL, PDO/PostgreSQL, Valkey and Memcached. Each independent job validates PSR-16 bulk, PSR-6 deferred persistence, tag invalidation, shared stampede load per concurrency phase, namespace isolation, TTL, and exact 520-operation cross-process durable/backend reconciliation; Valkey explicitly validates CAS/consume/replay. Memcached and Valkey are real isolated service containers only in selected lanes. Existing Redis tests retained. No fallback or mocked backend.
- [ ] Hosted acceptance; remaining adapters, tiered/node/cluster, distributed invalidation/outbox and backend outage cases remain open.

## B12 — database mechanics expansion (test-first)
- [x] Add independent MySQL/PostgreSQL transaction/savepoint/callback, real cross-process deadlock retry, and pool ownership lanes; add PostgreSQL physical standby routing and weighted selection lane.
- [x] Add detailed [production capability expansion register](production-capability-expansion.md) with granular DBLayer acceptance work and remaining ecosystem tracks. Preserve existing failures; no upstream library fixes.
- [ ] Hosted verification of all new lanes; implement every open DBLayer and ecosystem scenario before library remediation.

## B13 — database data plane and SQLite lanes
- [x] Add six independent scenarios (schema/migrations, query builder, repositories, cache consistency, streaming/keyset, security) across MySQL/PostgreSQL/SQLite, 18 jobs.
- [x] Follow direct-main workflow; remove PR-only trigger introduced in B12. Keep previous mandatory scenarios.
- [ ] Hosted evidence and remaining detailed DBLayer cases. No upstream fixes.

## B14 — database operational mechanics
- [x] Add ten independent hosted lanes for locking/isolation, pool lifecycle, query budgets, SQLite WAL and physical standby replay/outage/restart.
- [ ] Hosted evidence and remaining detailed cases. Library remediation deferred.

## B15 — database relations and five-driver capability lanes
- [x] Add eight independent hosted jobs: three bounded relation/declared eager-loading lanes and five real driver capability/query/transaction lanes (MariaDB and SQL Server added).
- [ ] Hosted evidence, full relation/dialect expansion and remaining ecosystem work. No library modifications.

## B16 — fused/sharded production routing matrix
- [x] Replace generated-only browser lane with fused/sharded parity across native/FPM; add both matchers to all authenticated HTTP deployment combinations. Explicit matcher configuration applies to both workloads.
- [ ] Hosted qualification. Preserve historical failures, defer upstream fixes.

## B17 — core and ecosystem data capabilities
- [x] Add independent Foundation core, InterMix, ArrayKit config/stream and UID format lanes.
- [ ] Hosted verification and remaining full native capability coverage; no upstream fixes.

## B18 — cache/security native lanes
- [x] Add nine independent CacheLayer, OTP, ReqShield and Epicrypt jobs.
- [ ] Hosted evidence and remaining full capabilities. No vendor changes or suppressed upstream defects.

## B19 — runtime and remaining ecosystem native lanes
- [x] Add eleven independent runtime, Webrick, Pathwise, Omnibus and TalkingBytes jobs; separate PHPProbe engineering acceptance.
- [ ] Hosted evidence and remaining granular capabilities across all packages.

## B20 — connected checkout workload
- [x] Four real native HTTP-to-SQL-to-outbox-to-queue-to-encrypted-storage-to-SMTP lanes, with idempotent replay and rollback.
- [ ] Hosted verification and remaining scenario families; no upstream changes.

## B21 — advanced DB transaction contracts
- [x] Nine hosted jobs for serialization retry, native lock modes, external ownership and post-commit failure.
- [ ] Hosted qualification and remaining DB/other library capabilities. No upstream fixes.

## B22 — real optional cache and storage backends
- [x] Redis Cluster, MongoDB, S3-compatible and SFTP v3 lanes using isolated service containers.
- [ ] Hosted qualification, remaining remote adapters and failure/HA matrices.

## B23 — native gRPC streaming and TLS
- [x] Independent TLS generated-stub native unary/server/client/bidi, deadline, cancellation and channel recovery lane.
- [ ] Hosted qualification and remaining inbound and protocol features.

## B24 — HTTPS security and retry workload
- [x] Independent real TLS two-origin TalkingBytes HTTP policy lane.
- [ ] Hosted verification and remaining transport features.

## B25 — sustained production load and crash recovery
- [x] Two mandatory smoke lanes plus four separate scheduled/manual workload combinations; 64-client spike, exact ledger, cache and tenant reconciliation and uncertain-response crash.
- [ ] Hosted results and production-equivalent resource/SLO qualification; remaining capabilities open.

## B26 — native data, sequence, trust and schema contracts
- [x] Six independent hosted lanes covering advanced UID, InterMix, ArrayKit, Epicrypt and ReqShield capabilities.
- [ ] Hosted qualification; remaining specific API families still open.

## B27 — real MySQL/MariaDB replication
- [x] Two source/reader jobs with actual binary-log lag, fallback, catchup and recovery.
- [ ] Hosted qualification and remaining proxy/driver/HA cases.

## B28 — Webrick middleware contracts and fixture review
- [x] Four independent native middleware jobs; harden HTTPS evidence and binlog fixtures.
- [ ] Hosted qualification; remaining middleware, deployment and library capabilities remain open.

## B29 — JOSE cryptographic algorithm matrix
- [x] Two native jobs for all published JWT/JWS and JWE algorithms, JWK and serialization/recipient/threshold contracts.
- [ ] Hosted qualification and remaining key lifecycle and remote trust capabilities.

## B30 — historical CI evidence and fixture repair
- [x] Record historical 70/88 results (run 45551568675); correct window ordering, provision SQL Server ODBC and retain primary WAL for replica fixtures.
- [ ] Qualify exact-head run; 152 routine production jobs, 20 consumer jobs, four scheduled/manual endurance jobs and one engineering job configured. Remaining open capability families and mandatory red reproductions retained.

## B31 — inbound mailbox protocols
- [x] Real IMAP and POP3 stateful mailbox acceptance lanes.
- [ ] Hosted verification and remaining TLS/IDLE/provider behavior.

## B32 — broker integration
- [x] RabbitMQ and SQS-compatible real backend lanes.
- [ ] Hosted verification and advanced provider policy/HA.

## B33 — Foundation module topology and lifecycle
- [x] 256 selection masks across eight hosted shards, plus actual removal/reinstall lifecycle.
- [ ] Hosted verification and conditional dependency/business permutation scenarios.

## B34 — Swoole-family hosted runtime parity
- [x] Four actual Swoole/OpenSwoole browser lanes, both compiled matchers.
- [ ] Hosted verification and remaining host/protocol options.

## B35 — distributed invalidation and outbox
- [x] Three independent SQL/Redis Streams coherence lanes.
- [ ] Hosted verification, multiprocess node and transport failure cases.

## B36 — native wire interoperability
- [x] Five real concurrent TCP/Unix/UDP/WebSocket/TLS-HTTP2 lanes.
- [ ] Hosted verification and remaining wire protocols/hosts.

## B37 — database proxy pooling
- [x] Actual PgBouncer session/transaction modes and concurrent DBLayer reconciliation.
- [ ] Hosted verification and other proxy/listener variants.

## B38 — official storage adapter matrix
- [x] Remaining documented official adapter keys have executable scenarios across eight independent jobs.
- [ ] Hosted verification, cloud provider/security and local-only feature contracts.

## B39 — Scylla and Redis Cluster HA
- [x] Native Scylla and real Redis Cluster replica-promotion scenarios.
- [ ] Hosted verification, native Cassandra compatibility and remaining cache contracts.

## B40 — RoadRunner and FrankenPHP worker parity
- [x] Four actual hosted browser lanes with compiled releases and host replacement.
- [ ] Hosted verification and remaining runtime-specific contracts.

## B41 — secure wire protocols
- [x] Add actual HTTP/3 multiplexing and required-client-certificate acceptance.
- [ ] Hosted qualification, outbound QUIC and remaining protocol variants.

## B42 — database failure and proxy contracts
- [x] Six real migration failure, connection termination and ProxySQL jobs.
- [ ] Hosted qualification and remaining HA/ambiguous acknowledgement cases.

## B43 — native runtime lifecycle and HTTP framing
- [x] Actual supervisor lifecycle/control and wire-level parser/resource boundary tests.
- [ ] Hosted verification, remaining cancellation/backpressure and resource policies.

## B44 — durable OTP and factor lifecycle
- [x] Five durable, backend matrix, drift and rotation lanes.
- [ ] Hosted qualification and remaining auth lifecycle/provider combinations.

## B45 — validation rule and database matrix
- [x] Representative fixtures for every current built-in rule token and three real database lanes.
- [ ] Hosted verification; expanded parameter/cast/sanitizer and wildcard variations remain open.

## B46 — cache fault and process coherence
- [x] Actual Redis outage policies/restart and multiprocess distributed cache invalidation.
- [ ] Hosted verification and remaining backend-specific failure contracts.

## B47 — mailbox trust and IDLE
- [x] Actual IMAP/POP3 implicit and negotiated TLS and new-message IDLE lane.
- [ ] Hosted verification and remaining communication/broker variants.

## B48 — storage trust and file transaction boundaries
- [x] Independent SFTP trust/reconnect and direct-local file transaction scenarios.
- [ ] Hosted verification and remaining remote failure variants.

## B49 — utility and DI boundaries
- [x] Three independent UID, ArrayKit and InterMix workloads.
- [ ] Hosted verification and method-option audit.

## B50 — Webrick semantic composition
- [x] Representation and signed-request scenarios.
- [ ] Hosted verification and remaining algorithm/streaming variants.

## B51 — authenticated multi-tenant application
- [x] Four backend/matcher combinations with real auth, checkout and tenant isolation.
- [ ] Hosted verification and expanded tenancy messaging/identity variants.

## B52 — live deployment cutover and rollback
- [x] Four backend/matcher combinations with live generation coexistence, additive schema and trusted activation rollback.
- [ ] Hosted verification; destructive migration and external deployment orchestration remain separate.

## B53 — connected dependency outage and recovery
- [x] Two matcher lanes with real reservation loss, simultaneous backend outage, restart and SQL snapshot reconciliation.
- [ ] Hosted verification and expanded recovery topologies.

## B54 — inventory and closure audit
- [x] Executable matrix dispatch/identity checks, published source inventory and consolidated residual register.
- [ ] Close remaining concrete gaps in production-gap-register.md; verify hosted scenario evidence. No upstream remediation in Phase 1.

## B55 — Pathwise lifecycle
- [x] Index/retention/deduplication, archive boundaries and live watcher scenarios.
- [ ] Hosted qualification and remote interruption/remaining adapter contracts.

## B56 — sequence and contextual contracts
- [x] UID provider boundary and InterMix contextual/tagged scenarios.
- [ ] Hosted qualification and remaining utility variants.

## B57 — remote JWKS
- [x] TLS fixture, shared cache, rotation, stale/response limits, DNS rejection and discovery scenario.
- [ ] Hosted verification; production transport DNS/peer binding and distributed replay still open.

## B58 — ambiguous database commit
- [x] Real PostgreSQL COMMIT acknowledgement loss and idempotent recovery lane.
- [ ] Hosted qualification, other driver protocols and remaining HA.

## B59 — broker DLQ and FIFO
- [x] RabbitMQ dead-letter/reconnection and SQS-compatible FIFO/deduplication lanes.
- [ ] Hosted verification, provider-specific TLS/outage and acknowledgement uncertainty.

## B60 — authentication and replay lifecycle
- [x] Four actual SQL/Redis auth lifecycle and distributed replay lanes.
- [ ] Hosted qualification and remaining HTTP/conditional auth variants.

## B61 — HTTP concurrency and transfer
- [x] Real HTTP multi pool and streaming/multipart transfer scenarios.
- [ ] Hosted qualification and remaining inbound/transport variants.

## B62 — native outbound HTTP/3
- [x] Independent real QUIC peer, upload/trailer, multiplex/cancel and HEAD cases.
- [ ] Hosted qualification and remaining transport variants.


## B63 — TLS-only Redis and concurrent cold-key computation

I: isolated Redis TLS-only listener; application-injected verified phpredis client (the built-in Redis DSN parser does not advertise TLS); wrong CA and plaintext rejection, 12 independent connections racing on a cold key, exactly one compute callback, fresh-client read and strict atomic claim/CAS/consume. Hosted verification pending. Other backend-specific TLS and stampede variants remain open.


## B64 — actual PostgreSQL TLS

I: enable SSL on the isolated PostgreSQL service with a generated certificate, verify-full using native libpq trust, assert pg_stat_ssl transport evidence, committed transfer and reconnect, wrong hostname/CA rejection, and required-TLS policy rejecting sslmode=disable. No private key artifact. Hosted verification pending. Other SQL driver TLS variants remain open.

## B65 — MySQL-family TLS and PostgreSQL policy fixture correction

I: isolated MySQL 8.4 and MariaDB 11.4 services with disposable CA-signed server certificates; mandatory verified TLS, server cipher evidence, committed transfer and reconnect, reachable wrong-IP identity and wrong-CA rejection, and required-TLS configuration without CA rejection. Correct B64 PostgreSQL required TLS to use the documented nested `security.require_tls` setting. No keys or certificates in artifacts. Two independent hosted jobs added (252 routine matrix rows); exact-head evidence pending. SQL Server TLS, query-cache stampede and other gap-register rows remain open. Before growing beyond 256 rows, partition the matrix without losing dispatcher audit or existing lanes.

## B66 — superseding CI and partitioned production workers

I: all 252 existing routine scenario rows retained, partitioned by scenario family (database 66, ecosystem 130, application 56); common reusable worker preserves the prior provisioning, assertions and artifact upload. Both independent native gRPC jobs retained. Workflow/ref concurrency cancels superseded production, consumer and engineering runs; endurance replaces earlier runs in its existing ref group. No shared concurrency group inside the reusable worker, so matrix siblings cannot cancel each other. Inventory checks global duplicates, complete shard assignment and per-matrix ceiling. Pre-existing runs that never entered a concurrency group cannot be canceled retroactively by YAML. Hosted qualification pending; capability gaps remain open.

## B67 — distributed DBLayer query-cache generation race

I: three independent MySQL/PostgreSQL/SQLite jobs with real shared Redis CacheLayer result cache. Twelve independently started PHP processes race each of two cold generations, each making cold and warm reads; require exactly one SQL execution per generation using DBLayer public statistics. Separate writer demonstrates transaction-local read bypass, rollback retaining the prior cached generation, committed balance conservation and cross-process invalidation. No library edits. Total routine rows 255 across shards; hosted verification pending. In-flight invalidation and further driver-specific cases remain open.

## B68 — multipart and SSE across compiled application hosts

I: four isolated native and FPM jobs (both fused/sharded). Real HTTP multipart text, binary and empty files, nested attachments, duplicate client filenames, random server-owned destinations, move ownership, exact digest/size, missing and oversized rejection and staging cleanup. Finite ordered SSE with media type, client abort and subsequent successful upload, repeated after host replacement. Does not claim server-side generator cancellation or end-to-end low-latency SSE flushing. 259 routine rows across three matrices plus two gRPC jobs; hosted qualification pending. No upstream changes.

## B69 — compiled route registration and complete content-coding families

I: two compiled attribute/resource registration jobs (fused/sharded), checking explicit deduplication, filtered directory discovery, grouped domain/name, repeated attributes, typed parameters, Produces/CORS metadata, resource only/custom parameter/name/PATCH action and middleware. Preserve a direct assertion for Route attribute middleware; current upstream loader source appears to omit Route::middleware when constructing options, so hosted failure is possible and must remain visible. Independent gzip/deflate/br/zstd lane provisions both optional codecs, requires all four decoder roundtrips, lengths, Vary, quality/identity/tie-break, 406 and no-transform/range/type bypass. B68 records upload size before transfer of ownership. Total routine rows 262. No upstream changes; hosted verification pending.

## B70 — real MySQL-family ambiguous commit acknowledgement

I: two independent MySQL/MariaDB jobs extend the B58 durable ledger and financial reconciliation. Protocol-aware loopback fixture forwards native MySQL frames, recognizes a real COM_QUERY COMMIT and withholds exactly one successful backend OK packet, without logging authentication or SQL. Require one attempted execution, no confirmed-commit callbacks, durable ledger and balances, fresh connection recovery and no duplicate idempotent operation. PostgreSQL lane retained. Total routine rows 264. SQL Server ambiguity and HA remain open. No upstream modifications; exact-head hosted evidence pending.

## B71 — advanced repository graphs on all five SQL drivers

I: five independent jobs for through many/one, nested eager loading, aggregates/existence, one-of-many with deterministic ties, explicit candidate scope versus winner filter, narrow projection, morph one/many/to and forward/inverse polymorphic pivot with type collision isolation. SQL Server uses the existing real ODBC/DB provisioning path. No ORM/lazy behavior inferred; remaining repository policy/driver-specific cases stay open. Total routine rows 269. Hosted verification pending, no upstream modifications.

## B72 — distributed counters and additional authentication-state backends

I: actual Redis and Valkey counter lane requires fixed/persistent TTL, exact 2^53+ integer behavior, overflow/no-mutation, malformed/invalid input, separate ordinary-cache clear, 1200 concurrent operations, exact one initialization and expiry/delete. Two additional OTP lanes exercise real Valkey and local PHP-files with strict integrity/fail-closed policy, concurrent single-use and attempt exhaustion, HOTP monotonic replay, factor generations and PHP_INT_MAX exhaustion, TOTP concurrent replay. Local PHP-files is explicitly not multi-host production durability certification. No keys or proof bodies in evidence. 272 routine rows. Hosted qualification pending; remaining factor/provider/failover variants open.

## B73 — independent PHPProbe CLI contract families

I: split engineering-only acceptance into five fail-independent hosted jobs: syntax/graph, reference resolution and repair, cross-directory duplicates with baseline roundtrip, comment findings/output/baseline roundtrip, and presets/init/config/doctor/combined check with deliberate syntax failure. Exact expected exit codes and JSON assertions retained; baselines are isolated fixture data only, not production suppressions. Published no-dev runtime remains unchanged; 272 routine rows plus two gRPC jobs and five separate engineering jobs. Hosted verification pending. Exhaustive API, graph semantics, changed-only and detector option variants remain open.

## B74 — actual SQL Server verified TLS

I: real SQL Server 2022/ODBC 18 hosted lane on PHP 8.4, generated disposable CA and DNS-only server certificate, forced encryption, trust rejection before/after runner CA installation, DBLayer verified connection and DMV encryption evidence, wrong-IP identity rejection, required encrypt policy, durable transfer and reconnect. Hosted only; CA removal in EXIT trap, no private key artifacts. CORE-01/SEC/DATA/OPS: real peer trust, isolated ownership and conservation; FLOW/EXEC-03: lint and hosted runtime evidence pending. 273 routine rows. SQL Server HA and ambiguous commit remain open.

## B75 — broker TLS, persistent restart and redrive

I: RabbitMQ TLS-only actual broker with isolated CA/DNS SAN and wrong trust rejection, durable queue and unacknowledged reservation across stop/start, exact identity/attempt and post-restart publication. Independent ElasticMQ SQS-compatible redrive lane requires broker-side maxReceiveCount, fresh receipts, dead-letter payload and subsequent healthy work. No real AWS certification claimed. Existing application adapters reused; no dependency pin or upstream edits. CORE/SEC/DATA/OPS: transport verification, bounded waits, independent service lifecycle, durable identities; hosted lint and execution pending. 275 routine rows.

## B76 — utility lifecycle and failure boundaries

I: three new independent lanes: UID filesystem fork reservation identity, replacement gaps, overflow/corruption, clock regression, symlink and lock timeout; ArrayKit dotenv raw/resolved references, invalid input, detached/cyclic snapshot and compiled generations; InterMix Fence singleton/multiton/limit/requirements and Remix macro/retry lifecycle. Reuses published public APIs and existing fork harness; no upstream changes. CORE/TYPE/SEC/DATA/OPS lifecycle and ownership assertions, FLOW/EXEC hosted syntax/runtime pending. 278 routine rows. Further variants remain explicitly open.

## B77 — actual remote storage outage and explicit recovery

I: independent MinIO S3 and pinned SFTP v3 jobs reuse actual adapter provisioning. Seed durable binary and foreign-tenant content, stop service and confirm port unavailable, require read/write/delete failure, restart same service, verify original digests and no phantom object, explicitly retry streamed publication and reconcile tenant isolation. No automatic mutation replay or cloud-provider certification claimed; mid-transfer acknowledgement ambiguity remains open. Hosted verification pending. 280 routine rows.

## B78 — inbound gRPC application boundary and typed validation pipelines

I: independent TalkingBytes host-controlled unary exchange lane tests cancellation before/after acceptance, exact response ownership, method/status/metadata/deadline boundaries, redacted exception events, authentication and SQL idempotent effects. This is the documented source/exchange API, not a native inbound streaming server (unsupported in 2.2). Independent ReqShield interpreted/compiled wildcard normalization, strict scalar/enum/date/JSON typed projections, overflow/invalid values, unknown-field isolation and repeat reuse. Hosted validation pending. 282 routine rows.

## B79 — Foundation-owned real HTTP OAuth endpoints

I: four isolated native/PostgreSQL and FPM/MySQL fused/sharded lanes enable OAuth/OIDC with disposable private keys, provision the real auth schema and confidential client, compile the release without duplicate routes, exercise metadata/JWKS, malformed form/query/credential combinations, no-store/error semantics, client credentials, durable introspection across host replacement and revocation/reissuance. Secret fixture in runner temp only; no secret response bodies in evidence. Also fixes the B68 matcher selection omission in bootstrap. Browser consent and edge integration remain open. 286 routine rows; hosted evidence pending.

## B80 — native HTTP/1 backpressure and request abort

I: actual socket lane sets a 4 KiB write budget, 4 KiB high watermark and 16 KiB send ceiling; a slow independent reader checks 128 ordered 8 KiB chunks, rejected oversized write, observed pressure and drain, bounded buffered bytes, exactly one successful terminal event. Incomplete request body disconnect must invoke cancellation once; subsequent requests prove continued service. HTTP/2, HTTP/3 and other transport/host variants stay open. 287 routine rows; hosted verification pending.

## B81 — InterMix factory recipes and finalized graph

I: independent published-package lane tests constructor/static recipes, detached reference-containing literal snapshots, graph metadata/freeze, strict compilation and digest, alias/tag identity, all three declarative and dynamic lifetimes, invalid recipes and strict opaque-factory rejection. Generate and parse preload artifact and verify builder finalization; actual OPcache startup and class coverage remain open. 288 routine rows; hosted verification pending.

## B82 — Git-aware PHPProbe scope and graph API semantics

I: two independent engineering-only modes. Real disposable Git repository proves committed, working-tree and untracked PHP selection, deleted file omission, exclusions, and unchanged invalid file isolation against full-scan failures. Programmatic graph extraction checks input-order/duplicate stability, provenance, external reference distinction, relationship edges, unresolved call semantics, and exact CLI/API agreement without executing source. Seven engineering jobs; 288 routine production scenarios unchanged. Hosted verification pending; no upstream changes.

## B83 — Valkey TLS and persistent Redis-compatible counter restart

I: three additional independent lanes exercise real Valkey TLS trust/name rejection, atomic operations and cold-key race, and Redis/Valkey TLS counter persistence with AOF, actual stop/restart, fail-closed fresh connection, exact integer and TTL state, and twelve concurrent recovery increments. Existing Redis TLS lane also asserts wrong peer name. This tests restart, not automatic cluster election or lost increment acknowledgements. 291 routine scenarios; hosted verification pending.

## B84 — active application writes across dependency outage

I: two additional compiled native host lanes (fused/sharded). Eight independent checkout workers commit pre-outage orders, remain live while PostgreSQL, Redis and SMTP stop, attempt new writes with all ports confirmed unavailable, and replay after restart without replacing the host. Sixteen extra unique orders, rejection of offline success, exact response/ledger identity, stock, transactional outbox, killed reservation, encrypted receipt/cache digests, SMTP count, and distinct SQL backup restoration. Existing 160-order flow retained. Does not yet certify external storage or external broker restore. 293 routine rows; hosted evidence pending.

## B85 — value types and cryptographic interoperability

I: two independent lanes cover UID value metadata, custom epochs/formats, six Sonyflake/TBSL bases, opaque integer and byte roundtrips, mixed ordering, invalid boundaries; Epicrypt RFC 8032 Ed25519 known vector, independent libsodium signature/MAC roundtrips, binary/large messages, malformed proofs/keys, opaque issuance/digest length and mutation rejection. No generated token or key material in evidence. Remaining Randflake variants and key lifecycle stay open. 295 routine rows; hosted verification pending.

## B86 — ArrayKit bounded DTO and transformation contracts

I: two independent lanes with native PHP reference membership/sort, adaptive input sizes, strict mixed sets and integer precision, group key rejection, immutable collection ownership, typed mapped nested DTO hydration, guarded cycle/depth/node rejection before mutation, shape validation, literal path control roundtrips and bounded wildcard/overlap operations. Remaining helper variants and configuration lifecycle stay open. 297 routine rows; hosted verification pending.

## B87 — InterMix persistent definition cache and runtime attribute diagnostics

I: independent real Redis and SQLite cache pools validate explicit singleton eligibility, bulk warmup, null/array persistence across builders, environment/generation isolation, non-persistence of objects and unrelated key retention. Another lane exercises custom property resolvers, explicit method arguments, dynamic/compiled dependency ownership and diagnostic graph/trace lifecycle. Actual startup preload, further attribute and cache outage variants remain open. 299 routine rows; hosted verification pending.

## B88 — ReqShield option and nested error contracts

I: two independent lanes exercise IP family/scope, UUID range/list/exclusion/variant, decimal precision, absolute path and upload bounds, invalid option constructors, targeted/all nested distinct isolation, mutable/compiled reuse, custom messages, typed failure metadata, JSON/API/problem projections and validation exceptions. Remaining locale and rule-option permutations stay open. 301 routine rows; hosted verification pending.

## B89 — Randflake conversion, validation locale and actual preload startup

I: three independent lanes cover signed/unsigned numeric boundaries, all six radices and byte ordering for both Randflake formats; regional locale fallback, wildcard aliases and custom interpolation with mutable/compiled reuse; and a fresh PHP CLI process with generated OPcache preload artifact, pre-autoload class visibility, preload statistics and DI singleton resolution. 304 routine rows; hosted qualification pending.

## B90 — OTP shared-state parity and archive lifecycle

I: five additional OTP lanes exercise the existing twelve-process challenge/HOTP/TOTP replay and attempt-exhaustion contract on Redis, file, SQLite, PostgreSQL PDO and MySQL PDO; independent archive lane verifies filter membership, selected extraction, iterator/progress parity, encrypted ZIP interoperability with native libzip, incorrect password failure, and entry-count/total-size/ratio limits without destination mutation. 310 routine rows; hosted qualification pending.

## B91 — SQL backend parity and in-flight query invalidation

I: 20 additional MariaDB workload rows and 12 additional SQL Server rows reuse existing assertions, including schema, relations, repository, streaming, transactions and shared cache. SQL Server database initialization is moved to the shared dispatcher for applicable scenarios. Independent PostgreSQL overlap lane confirms actual server-side advisory-lock wait before a concurrent committed transfer, releases the reader only after commit, and checks fresh/warm cached results against the new generation. Remaining topology and driver-specific variations stay open. 343 routine matrix rows; hosted verification pending.

## B92 — Foundation browser OAuth consent over compiled HTTP hosts

I: extend four existing native/FPM fused/sharded OAuth HTTP lanes using a real persisted auth session created by AuthActions, Foundation-owned authorization routes, separate browser cookies, CSRF/origin/cross-session rejection, no-store and frame/referrer policies, explicit deny and approve redirects with state and issuer, rejection of unregistered redirect, and durable public-client PKCE redemption and replay rejection after host replacement. Loopback transport carries fixture cookies explicitly; this is not browser TLS certification. 343 routine rows unchanged; hosted qualification pending.

## B93 — InterMix real definition cache outage

I: reuse disposable Redis AOF stop/restart fixture for a dedicated definition-cache lane. Pre-outage separate builders demonstrate strict rejection without factory execution, fail-open local singleton fallback, new generation computation during outage, exact original durable value after restart, non-publication of failed writes, replacement singleton persistence and unrelated key preservation. 344 routine rows; hosted qualification pending.

## B94 — Broker acknowledgement uncertainty and durable effects

I: two additional actual RabbitMQ/SQS-compatible lanes inject a failure immediately before or after native acknowledgement at the application-owned adapter boundary. The Omnibus consumer must propagate uncertainty, preserve committed SQL effects, permit redelivery only when unsettled, and avoid classifying settlement failures as handler failures. Replacement consumes with an idempotent application effect and independent execution counts. This is deliberate boundary fault injection, not an actual packet-loss test or provider exactly-once guarantee. 346 routine rows; hosted qualification pending. CORE-01/DATA/OPS: preserve existing adapter behavior when fault callback absent, use disposable broker and bounded recovery; verification pending.

## B95 — S3 interrupted transfer and uncertain publication

I: isolated MinIO lane routes two specific signed fixture PUTs through a bounded loopback peer. One forwards only 64 KiB then closes the upstream; the other forwards the entire body and observes a successful upstream response before withholding it. Pathwise must surface both failures; independent direct reads reconcile previous versus committed exact bytes, retry only the proven incomplete operation, and preserve another tenant. No external service or production credentials used. 347 routine rows; hosted verification pending (CORE-01, DATA, OPS).

## B96 — Physical PostgreSQL write-primary replacement

I: independent lane reuses the physical standby fixture, verifies replay through the primary's committed WAL flush LSN, stops the original primary, promotes the standby and opens a replacement DBLayer write connection. It reconciles prior idempotency identity, committed balances, new write/callback and fresh connection state. No claim of automatic DBLayer write discovery or zero-loss unplanned asynchronous failover. 348 routine rows; hosted qualification pending. DATA/OPS: explicit ownership and bounded readiness, original primary remains stopped on the disposable runner.

## B97 — Redis and Valkey counter primary election

I: two disposable real Sentinel lanes wait for replica discovery and explicit replication acknowledgement, stop the old primary, observe Sentinel-selected promotion, reconnect the published counter factory to the promoted endpoint and verify exact signed values, fixed TTL, persistent state, cache namespace independence and 200 concurrent increments. One Sentinel is a fixture, not production quorum qualification. AtomicCounters accepts native Redis clients, not RedisCluster; automatic endpoint discovery is application-owned. 350 routine rows; hosted qualification pending (DATA, OPS, COMPAT).

## B98 — Combined remote storage and broker outage with active database writers

I: real disposable S3-compatible storage and RabbitMQ stop together after four confirmed persistent messages; eight independent PHP processes prove remote unavailability and commit 32 new SQL orders/outbox rows while both are stopped. Restart reconciles four old messages, relays 36 pending operations, checks all 40 remote receipt bytes, and consumes 41 deliveries including an intentional duplicate into exactly 40 SQL effects. SQL remains available during the outage; no provider exactly-once guarantee or active backup claim. 351 routine rows; hosted verification pending (CORE-01, DATA, OPS).

## B99 — Consistent database backup under active writes

I: independent PostgreSQL lane exports a repeatable-read snapshot of 100 paired orders/ledger rows, observes actual pg_dump lock wait, commits another 160 paired operations across eight PHP writers before releasing the dump, restores the exported snapshot into a separate database and compares exact ordered row digests and financial invariants. Original database retains all 260 operations. This tests consistent logical backup, not physical PITR or production RPO/RTO. 352 routine rows; hosted qualification pending (DATA, OPS).

## B100 — Runwire native HTTP/2 flow control and cancellation

I: independent TLS/ALPN H2 client constrains stream credit to 4 KiB, proves sibling progress while a 256 KiB response is stalled, reconciles exact chunks after credit release and requires bounded writer pressure/drain evidence. Resets an incomplete response and partial upload, requires one server cancellation per reset, and confirms same-connection recovery. 353 routine rows; hosted qualification pending (REQUEST, MEM, OPS).

## B101 — Native HTTP/3 response pressure and directional cancellation

I: independent aioquic client uses verified TLS, constrained initial transport credit and concurrent streams to reconcile exact 256 KiB response bytes with server pressure/drain evidence, an incomplete response cancelled by STOP_SENDING, partial upload cancelled by RESET_STREAM, and healthy reuse of the same QUIC connection. Native ext-quic is mandatory; no TCP fallback. 354 routine rows; hosted qualification pending (REQUEST, MEM, OPS).

## B102 — Webrick telemetry, risk/error composition and actual OpenTelemetry SDK

I: one minimal W3C lane verifies all four risk decisions in enforcement/shadow, isolated spans, request/trace propagation, NEL, safe access logging, negotiated production error and custom-denial no-store. Another lane installs the real optional OpenTelemetry SDK and asserts parent IDs, active scope cleanup, exported span status, epoch timestamps and exception events. Source review found a likely upstream SDK contract mismatch: Webrick's status helper expects integers while current OpenTelemetry API StatusCode values are strings; start timestamp also uses monotonic hrtime rather than epoch nanos. No upstream fix or weakened assertion; runtime evidence pending. 356 routine rows (OBS, REQUEST, SEC).

## B103 — SMTP settlement ambiguity and verified authentication transport

I: two independent loopback SMTP lanes exercise full DATA acceptance with lost final reply versus explicit 451 rejection and confirmed delivery, stable prepared message identity and no automatic replay; actual STARTTLS/implicit TLS, PLAIN/LOGIN, untrusted CA, downgrade refusal and credential-redacted transcript. Disposable peer records bounded fixture payload digests only; no external email or exactly-once claim. 358 routine rows; hosted verification pending (DATA-01, SEC, OPS, EXEC-03).

## B104 — Actual Redis/Valkey atomic counter settlement ambiguity

I: independent bounded RESP2 relay drops one EVAL before forwarding and withholds one real successful EVAL response. Direct observer reconciles 10 versus 17, rejects automatic replay, verifies fixed TTL and explicitly retries only the proven uncommitted operation. Two hosted lanes; fixture is loopback and plaintext, not a provider exactly-once guarantee. 360 routine rows; hosted qualification pending (DATA, OPS, EXEC-03).
