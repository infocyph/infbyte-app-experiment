# Phase 2 — production defect discovery and remediation register

Baseline: B231, `974422a0504c57c963e565bc3392cf79adf02d03`, freeze approval `10fdcb408f994039c5c064eed4e00602ac3430c2`. Scope: 2,177 routine workloads, 41 PHPProbe modes and one separately provisioned external HA lane. The purpose is to discover bugs, shortcomings and production failure modes in published libraries before consumers experience them, and provide actionable upstream fixes.

## Evidence and disposition policy

- Record the exact playground revision, installed library versions, driver/runtime/topology, failing invariant, hosted job and log, root-cause source, production impact, proposed fix and regression criteria. Deduplicate by root cause, not failed job count.
- Classifications: library defect (confirmed on specified version), library shortcoming (contract/design candidate), playground defect, infrastructure/tooling, or unresolved. A historical reproduction is not exact-current-head acceptance. A source inspection is not a hosted test.
- Priorities: P0 confirmed security breach or data loss; P1 production-critical functional failure; P2 materially incorrect behavior or operational limitation; P3 minor/documentation. Reassess when evidence changes.
- Fix playground misuse without weakening test invariants. Preserve upstream failure reproductions; no vendor edits, test skips, dependency revision pins, assertion weakening or schema workarounds. Recommend upstream repairs first; separate upstream implementation and release from playground acceptance. Confirm the actual published dependency graph on each retest.
- Keep the frozen inventory intact; new cases require a concrete defect, uncovered material contract or owner-approved scope change. Prefer rerunning affected jobs before full requalification. Do not repeatedly supersede an incomplete full matrix for documentation-only progress; collect evidence from completed jobs and batch necessary corrections.
- CORE-01 / FLOW-02: root cause and unchanged contract; SEC / DATA: authorization, replay and durable integrity; OPS / OBS: actual hosted provenance and operational limits; EXEC-03: verification status explicitly tracked. No local execution performed.

## Initial findings (2026-11-25)

| ID | Priority | Owner | Classification | Status |
|---|---|---|---|---|
| FND-001 | P1 | Foundation | Confirmed published defect | Open; proposed upstream fix |
| FND-002 | P1 | Foundation | Confirmed published defect | Open; proposed upstream fix |
| FND-003 | P1 | Foundation / readiness | Historical integration failure; root cause not isolated | Reproduce on current published release |
| LAB-001 | P2 | Playground | Confirmed test API misuse | Corrected in first verification batch; hosted retest pending |
| CI-001 | P2 | CodeQL / workflow | Unresolved tooling failure | Investigate upload/diagnostics |
| INFRA-001 | P2 | External SQL HA | Infrastructure dependency | Blocked until dedicated fenced cluster exists |

### FND-001 — Recovery code factor identifier exceeds its durable schema

Published Foundation 4.0.1. MySQL and PostgreSQL fail real MFA enrollment at INSERT, SQLSTATE 22001. MySQL: data too long for column id; PostgreSQL: value too long for character varying(64). Stack: OtpManager::beginEnrollment → MfaManager::enrollFactor → OtpRecoveryCodeService::generate → OtpRecoveryCodeStore::replace → DBLayerMfaFactorStore::compareAndSwap. [MySQL job 138979931893](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45547317671/job/138979931893), [PostgreSQL job 138979932037](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45547317671/job/138979932037).

Current inspected Foundation source revision `250456f49339331b9115e8e1874066f70b967bef`: [OtpRecoveryCodeStore.php:171–178](https://github.com/infocyph/Foundation/blob/250456f49339331b9115e8e1874066f70b967bef/src/Auth/Adapter/Otp/OtpRecoveryCodeStore.php#L171-L178) creates a 77-character ID (13-character prefix plus 64 hexadecimal characters); [AuthSchema.php:152–163](https://github.com/infocyph/Foundation/blob/250456f49339331b9115e8e1874066f70b967bef/src/Database/AuthSchema/AuthSchema.php#L152-L163) defines a 64-character primary key. Root cause is still present in inspected source.

Impact: durable MFA enrollment/recovery-code provisioning fails on strict SQL databases. Other environments may not expose the same length enforcement. Do not attribute the correct SQL rejection to DBLayer or OTP.

Recommended fix: preserve existing deterministic IDs and full digest; expand the factor ID contract to at least 77 characters (recommend VARCHAR(128)), including fresh install schema and a registered upgrade migration. Audit dependent identifiers, foreign keys/indexes, adapter validation and alternate stores before choosing the final width. Do not silently truncate IDs or regenerate existing recovery records. Make migration dialect-safe, preserve metadata/revisions/ownership and define safe downgrade behavior for values longer than the original width.

Regression acceptance: fresh install and upgrade on every supported auth SQL backend; create recovery codes, consume each only once, rotate, reject replay, preserve unrelated account/factor state and concurrent CAS behavior. Retest original published-consumer security lanes after a release.

### FND-002 — PostgreSQL role permissions use DISTINCT over JSON

Published Foundation 4.0.1. PostgreSQL authorization fails with SQLSTATE 42883, no equality operator for type json, at SELECT DISTINCT p.*. [Hosted authorization job 138979931750](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45547317671/job/138979931750). Stack identifies DBLayerPermissionStore::permissionsForRoles. Current inspected Foundation [DBLayerPermissionStore.php:80–92](https://github.com/infocyph/Foundation/blob/250456f49339331b9115e8e1874066f70b967bef/src/Auth/Adapter/DBLayer/DBLayerPermissionStore.php#L80-L92) still selects DISTINCT over full permission rows.

Impact: role-based authorization fails on PostgreSQL when permission rows contain native JSON fields. DBLayer is surfacing a native database error; the query is owned by Foundation.

Recommended fix: use a correlated EXISTS semi-join (SELECT p.* FROM permissions p WHERE EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.permission_id = p.id AND rp.role_id IN (...))) with the existing safe table resolution and bound role IDs. Deduplicate by permission identity, not JSON equality; preserve empty-role early return and metadata. Review related DISTINCT projections for the same pattern. Prefer query correction over a database-specific JSON cast or schema conversion.

Regression acceptance: multiple roles grant the same permission; nullable and populated JSON metadata; empty roles; revoked grants and tenant/account isolation. Verify exact deduplicated permission set and authorization decisions on PostgreSQL/MySQL/MariaDB and other supported auth drivers. Retest the original authorization lane.

### FND-003 — Auth-enabled readiness resolves scoped runtime state outside an active scope

Historical published 4.0.1 native PostgreSQL readiness reports schema:auth unavailable: scoped RuntimeExecutionState requires an active scope. [Hosted job 138979932099](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45547317671/job/138979932099). Earlier plan records native MySQL and FPM PostgreSQL failures too, after schema setup corrections. Current exact-head reproduction and upstream lifecycle ownership inspection remain pending.

Potential impact: a healthy auth-enabled app can be marked unready, blocking deploy/health admission. Recommended investigation: determine which readiness provider resolves the scoped database/auth graph and which caller owns its execution scope. If confirmed upstream, execute readiness in an explicitly owned bounded scope or use a scope-independent diagnostic dependency as appropriate; do not make request state a singleton or bypass app:ready. Verify readiness before/during/after host replacement, absent schema remains unready, no scope leaks, and native/FPM parity.

### LAB-001 — Right outer join fixture uses unsupported inline select alias

Latest freeze-run jobs [SQL Server 139341130574](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45652645429/job/139341130574) and [SQLite 139341159191](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45652645429/job/139341159191) fail before SQL execution: DBLayer rejects column identifier t.id AS target_id. The public addSelectAs(column, alias) API validates identifiers separately. Correct the playground at database-residual-contracts.php to use addSelectAs for both columns. Preserve exact unmatched NULL rows, direct/derived joins, binding ownership, tenant isolation and durable counts. Retest all five drivers; further compiler/driver failures must be classified independently. No upstream issue filed for correct identifier validation.

### CI-001 — CodeQL Python and Actions job failures

[Python job 139339350227](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45652030861/job/139339350227) and [Actions job 139339350643](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45652030861/job/139339350643) fail in Analyze. Python logs show extraction of 32/32 files and query evaluation followed by SARIF upload and failed-run diagnostics. Available logs do not establish the final upload error or a specific security finding. Inspect code-scanning status, permissions/setup and diagnostic results; do not disable checks or assume a vulnerability from query names.

### INFRA-001 — External HA

The opted-in external clustered SQL Server acceptance requires the disposable three-replica Pacemaker fixture in [sqlserver-external-ha-fixture.md](sqlserver-external-ha-fixture.md). This is not a library defect and does not establish production RPO/RTO or SLO performance.

## Verification baseline and first batch

The B231 consumer acceptance [45652030631](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45652030631) passed. Full production run [45652645429](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45652645429) was not complete at inspection; two right-join failure logs were accessible. The API listed 2,179 jobs including non-matrix entries but did not expose a complete terminal outcome for the matrix. Numerous preceding production runs were cancelled by superseding commits. No complete frozen-suite result or exact-head pass count is claimed.

First correction: LAB-001 alias API only. All counts unchanged, source/API and unchanged assertion review complete; hosted lint, five-driver runtime retest and broader acceptance pending. No upstream modification or issue publication in this batch.

## Phase 2 workflow execution policy

Full production qualification is explicitly dispatched (all groups or one selected group); routine reproductions use direct dispatch of the same reusable worker and must match an existing frozen tuple. Consumer acceptance automatically runs only on its own workflow changes; PHPProbe retains its narrow push trigger and permits a single mode. Endurance and external HA retain their existing schedule/manual and confirmation boundaries. Tracker-only commits no longer start the full laboratory or consumer acceptance. No scenario, assertion, version policy or HA safety guard changed. Hosted verification of the new dispatch paths remains pending.


## Phase 2 execution topology — PHP 8.5 only

Owner-approved change: retain all 2,177 scenario/backend/host/matcher tuples, 1,336 distinct routine scenarios, 41 PHPProbe modes, both native gRPC modes and the external HA lane. All current execution uses PHP 8.5, including FPM, FrankenPHP, MongoDB/Scylla, SQL Server, Swoole/OpenSwoole and native gRPC; do not fall back to a different PHP series. Native-extension availability must be verified on hosted runners and failures classified rather than skipped. Historical PHP 8.4/8.6 evidence remains historical, not current acceptance. Consumer acceptance now contains 12 jobs (four core/all mode jobs plus eight specialist profiles). The ten logical routine group selectors remain; application is physically split into three, giving 12 routine matrices under the 256-job limit.

Dedicated workers: database (334), ecosystem (1,647), HTTP (158), business (37), operations (1), native gRPC (2 modes). Each supports manual dispatch and reusable calls with cancellation scoped to its exact workload tuple or gRPC mode, not unrelated workloads. Shared setup uses a local composite action. Database/application workers provision their selected SQL backend and required supporting services; ecosystem retains its existing cross-library service topology. Static inventory preservation and dispatch wiring checked; hosted service, extension, cancellation and runtime qualification pending. No assertions or upstream package constraints changed. External HA retains its non-cancellation safety boundary while fencing is active.


## Capability-owned execution topology

Replace the generic ecosystem worker with nine capability workers. All 2,177 frozen routine tuples and 1,336 distinct routine names remain unchanged on PHP 8.5; 2 native gRPC modes, 41 PHPProbe modes, endurance and the external HA lane remain separate. 16 routine matrix shards, each at most 256 entries. Capability ownership is explicit in `qa/workload-worker-types.json`; the inventory auditor and shared preparation action reject missing or ambiguous ownership. Choose a capability (or all/application/ecosystem compatibility umbrella) in the laboratory dispatch; each dedicated worker also supports individual tuple dispatch and cancels only an older identical tuple. Native gRPC retains mode-scoped cancellation. The old ecosystem shard selectors are replaced by capability selectors.

| Worker | Routine entries | Matrix shards |
| --- | ---: | ---: |
| database | 334 | 256 + 78 |
| cache | 197 | 197 |
| authentication | 200 | 200 |
| cryptography | 44 | 44 |
| storage | 280 | 256 + 24 |
| messaging | 180 | 180 |
| runtime | 307 | 256 + 51 |
| routing | 99 | 99 |
| foundation | 156 | 156 |
| utilities | 184 | 184 |
| http | 158 | 158 |
| business | 37 | 37 |
| operations | 1 | 1 |

Provisioning: pure utility/crypto/runtime paths no longer start the generic SQL/Redis/mail topology; explicitly integrated variants retain required supporting services. Integrated cache, auth, storage, messaging, routing and Foundation contracts retain conservative SQL/Redis topology where cross-driver or provider behavior is under test. Optional Valkey/Memcached and native extensions preserve the previous scenario conditions, restricted to each capability. Unrelated Mailpit service definitions removed or gated except where cross-layer tests may need it. Shared application installation and original workload assertions remain unchanged. CORE-01/STRUCT/OPS/FLOW: ownership, tuple equivalence, matrix cap, manual dispatch and cancellation verified by source inspection; hosted service/runtime and auditor execution pending. No PHP fallback or dependency pin added. External HA remains manually authorized and non-cancellable during fencing.


Phase 2 qualification launch: requested a full PHP 8.5 hosted run through the explicit qualification request path. Preserve results by exact commit; external HA remains blocked until separately provisioned and explicitly authorized. No result is claimed before hosted execution.

## Phase 2 full-run triage — 2026-11-26

[Full hosted PHP 8.5 run 45661843498](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498), exact revision `c5c5efa5071e734376f34201b298c26f1f43f987`: all 22 API pages reviewed; 2,179 jobs, 1,505 successful, 674 failed, no cancelled/skipped jobs in the retrieved pages. Consumer acceptance, PHPProbe, endurance and CodeQL passed on that revision. Both native gRPC modes passed. Job counts are not defect counts. Representative failure logs were traced, not all 674 logs individually. The full matrix remains frozen; no assertions weakened or upstream packages modified. Foundation 4.0.1, Runwire 3.1, InterMix 11.2.1, Webrick 6.1, CacheLayer 4.0.3, ArrayKit 5.3.2 verified in installation output; specialist exact versions must be obtained from per-job package inventories.

### Confirmed upstream defects and actionable security/contract gaps

| ID | Priority | Library | Evidence and disposition |
| --- | --- | --- | --- |
| FND-001 | P1 | Foundation 4.0.1 | Reproduced on PHP 8.5 [MySQL](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376635513), [PostgreSQL](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376649108); existing 77 vs 64-character diagnosis and fix unchanged. |
| FND-002 | P1 | Foundation 4.0.1 | Reproduced on PHP 8.5 [PostgreSQL](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376653500); existing DISTINCT over JSON diagnosis unchanged. |
| FND-003 | P1 | Foundation 4.0.1 | [HTTP readiness](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376894784) again reports scoped RuntimeExecutionState requires active scope. Confirmed integration failure; upstream scope-owner repair still requires source trace. |
| FND-004 | P1 | Foundation 4.0.1 | [PAT PostgreSQL](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376651584) SQLSTATE 25P02. [DBLayerEpicryptPersonalAccessTokenStore::serializeSubject](https://github.com/infocyph/Foundation/blob/4.0.1/src/Auth/Adapter/DBLayer/DBLayerEpicryptPersonalAccessTokenStore.php#L237-L266) catches insert exceptions and queries the same transaction; duplicate-key failure poisons PostgreSQL transaction. Use dialect-safe conflict handling or bounded savepoint/rollback, catch only expected uniqueness conflict, preserve revision/subject serialization. Regression: existing subject repeated issue, concurrent create/revoke-all, unrelated SQL errors propagate. |
| EPC-001 | P1 security | Epicrypt RemoteJwks | [DNS fixture](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376674746) recorded rejected=false, dispatches=1, public_requests=0, private_requests=1, connected_peers=[127.0.0.2]. [RemoteJoseResource](https://github.com/infocyph/Epicrypt/blob/main/src/Token/Jwt/Support/RemoteJoseResource.php) validates resolved addresses before a generic PSR-18 client resolves/connects independently. Security boundary gap confirmed in isolated lab; no real production compromise claimed. Require transport-level approved peer identity or fail closed when binding cannot be guaranteed; preserve TLS hostname verification and reject disallowed redirects. Verify independent peer evidence and no dispatch to denied peers. Exact installed Epicrypt version pending inventory. |
| DBL-001 | P2 | DBLayer | [Recursive CTE MariaDB](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376729437), same family all five drivers: bound legitimate recursive UNION ALL rejected by raw SQL heuristic. Inspect structured CTE API vs documented raw CTE contract, do not disable injection validation. Improve structural handling or expose safe structured builder; preserve tenant binding/depth and malicious-fragment rejection. |

### Worker and playground corrections in this batch

| ID | Evidence | Fix/status |
| --- | --- | --- |
| LAB-002 | [Bengali patterns](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376722328) | Unicode letters-only regex cannot match combining marks. Preserve a letter-only positive/negative case and test letter-plus-mark words separately; identify mutable/compiled case on failures. Retest pending. |
| LAB-003 | [Composite FK PostgreSQL](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376673433), [SQLite](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376674367) | Guard correctly throws MigrationException (not DBException); assert the exact guard exception and operation, continue checking durable cascade isolation. Five-driver retest pending. |
| LAB-004 | [Outbound H1 GET](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376982212) | Closure failed to capture numeric dialer; add capture, preserve dial cleanup assertion. All outbound cases need retest; this does not explain negotiation/multiplex failures. |
| LAB-005 | [AMQP TLS](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376685795) | Recovery recreated existing SQL tables; use existing messagingSetup(false) for recovery only, preserve all durable broker assertions. |
| CI-002 | [Cassandra build](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376759640) | Build fails unable to load libuv; provision libuv development dependency before setup. PHP 8.5 only; native extension verification stays mandatory. Hosted build pending; other toolchain issues may remain. |
| CI-003 | [QUIC](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376705048) | setup-php cannot install quic on PHP 8.5.13. Infrastructure/extension availability blocker, not Runwire defect. Preserve failure and collect runtime evidence; investigate compatible native build source, no version fallback or skip. |
| CI-004 | Same extension failures | Evidence path previously created only after extension verification. Initialize before setup and retain PHP/version/extension/INI diagnostics even on failed setup. |
| LAB-006 | [Negotiation](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376671393), [Multiplex](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376670704) | Preserve independent peer request/events files on failing exit and log actual terminal errors/statuses/settlement. No assertion change. Root cause remains open. |

### Prioritized unresolved failure families (not confirmed library bugs)

| ID | Candidate owner | Evidence | Next root-cause boundary |
| --- | --- | --- | --- |
| FND-005 | Foundation / Omnibus | [Relay rollback](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376675583) leaves queue records despite rolled-back outbox | Confirm whether dispatch shares transaction-owned connection; inspect configured transport, transaction contract, durable outbox/receipt behavior. |
| FND-006 | Foundation / Epicrypt | [Consent scope policy](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376672925) invalid_scope | Check seeded client allowed scopes, configured permission mapping and validation request. |
| LAB-007 | Playground DI | [Undefined diContextualWorkload](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376675670) | Restore missing implementation/dispatch, do not replace with a passing no-op. Check all DI dispatch definitions, including environment and preload. |
| RUN-001 | Runwire 3.1 / TLS peer fixture | [Strict negotiation](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376671393), [recovery](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376671689), [multiplex](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376671651) | Determine actual terminal errors, independent peer ALPN and same-socket stream observations; distinguish transport bug from peer setup. |
| RUN-002 | Runwire / Foundation | Runtime admission, pool crash, communication circuit/rate-limit failures; [example](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376705949) | Inspect real runtime process/worker ownership and provider policy; separate from extension setup. |
| DBL-002 | DBLayer | [Derived query](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376800229) expected [6,8,16,18] from known seed | Check compiled SQL and exact qualified pluck output, native reference query; verify actual tenant leakage vs projection bug. |
| CACHE-001 | CacheLayer 4.0.3 | [PDO bulk](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376700995), [Memcached CAS](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376700138), [mixed atomic](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376696779) | Capture result types, misses, winner counts and independent persisted state; inspect native adapters/fixture key namespaces before attribution. |
| OTP-001 | OTP / CacheLayer | [PDO OTP](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376679657) cannot acquire lock; Redis Cluster OTP families also fail | Separate lock acquisition, cache backend, and topology readiness. |
| REQ-001 | ReqShield | [Wildcard cast](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376650621), [nested sanitizer](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376717610), validation cast/size/registry/expression families | Check documented direct/wildcard composition order, mutable and compiled result differences, exact expected typed data. |
| WEB-001 | Webrick / Foundation | [Signed response](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376707130), [session CSRF 419](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376788588); routing/policy/browser failures | Inspect actual response, canonical request, cookie/session state and compiled routing; distinguish harness route identity from upstream defect. |
| EPC-002 | Epicrypt / certificate fixture | [PKI](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376665879), detached crypto | Inspect OpenSSL return values, certificate creation and chain verification semantics. |
| IMX-001 | InterMix / fixture | Factory, graph, environment, preload, InterMix scenarios; [factory job](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376685879) | Distinguish missing dispatch from actual graph/factory/runtime contract. |
| MSG-001 | Omnibus / TalkingBytes / fixture | [IMAP seed count](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376695215), POP3/TLS/IDLE; [AMQP quorum](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376837220) container stopped | Inspect peer seeds and independent broker/container logs before attributing protocol/settlement failures. |
| STORAGE-001 | Pathwise / third-party dependencies | [SFTP dependency conflict](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376702478) phpseclib 4.0.4 vs adapter ^3; [SFTP-v2 package unavailable](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376702476) | Verify real package name/API and mutually compatible public dependencies; don't force downgrade or vendor patch. Storage, archive, publication/recovery families need per-provider diagnostics. |
| CI-005 | Container fixtures | [SFTP fault container](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376666683), [Redis Cluster](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376755146), [PG replica](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139377011592), [Pgpool](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376901820), AMQP quorum | Repeated container-not-running errors; inspect retained container logs/exit status and mounted permissions; no library attribution until positive readiness. |
| CI-001 | CodeQL | [Latest pass](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661842069) | Historical failure not reproduced; no current blocker, no detector changes. |

Batch P2 triage: CORE-01 / FLOW-02 preserve published contracts and genuine test failures; TYPE/SEC/DATA keep native guard, replay and durable invariants; OPS/OBS retain failure evidence. Source and exact anchor checks completed. Hosted requalification of these corrections is pending; no upstream changes or full matrix restart requested. External HA remains INFRA-001 blocked. Further logs and per-library source traces are required before the unresolved families can be promoted to confirmed bugs.

### Follow-up: restored original B56 fixtures and bounded hosted retest

LAB-007: [B56](https://github.com/infocyph/infbyte-app-experiment/commit/1c7132146de8445eb4d15715bc9e9a3ebabeb5e1) originally defined both diContextualWorkload and uidProviderBoundariesWorkload in ecosystem-utility-contracts.php. A later batch overwrote that file. The [DI failure](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376675670) and [UID failure](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376720495) are playground defects. Restore the original B56 implementations and imports without removing newer functions; provision actual Redis only for UID provider boundary lane. No upstream change and no assertion weakening. Add a bounded PHP 8.5 targeted qualification workflow (manual JSON dispatch or explicit qa/triage-request.json change) calling existing dedicated workers; reject nonexistent/duplicate tuples, preserve cancellation and all 2,177 original rows. Independent dispatch inventory detects missing direct ecosystem function definitions even if no targeted case reaches them. Request 14 representative corrected/diagnostic tuples (five composite FK, Unicode patterns, UID/DI, AMQP TLS, Cassandra, H1/H2, negotiation and multiplex). Hosted results pending.
