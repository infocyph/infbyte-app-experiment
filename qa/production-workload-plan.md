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
