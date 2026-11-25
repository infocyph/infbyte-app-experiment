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
