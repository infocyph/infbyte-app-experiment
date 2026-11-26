# Infbyte application skeleton — production feedback and upstream action plan

Status: **open — initial investigation and release planning** · 2026-11-26

Baseline: Published ^4.0 consumer; exact installed skeleton version per job pending. Release target to be selected after root-cause isolation; no version bump authorized by this file.

## Evidence and integration findings

[run 45661843211](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843211) all 12 consumer acceptance profiles passed; [run 45661843295](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843295) all four endurance jobs passed (MySQL/PostgreSQL, fused/sharded). Latest 14-worker targeted run qualifies basic host execution, not all authenticated app paths.

- [ ] **APP-001 (P1 integration):** [auth readiness](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376894784), [transfer readiness](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376788883), [cleanup readiness](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376894678); inspect skeleton defaults and published config versus Foundation scope/production-driver policy; no readiness override, no in-memory auth in production. Track actual owner in [Foundation](foundation.md).
- [ ] **APP-002 (P1 integration):** [browser-file HTTP 500](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376719489), [CSRF 419](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376788588); capture app error and runtime, route/cache/session/config state across six hosts and both matchers, trace first library boundary. Do not assume skeleton defect.
- [ ] **APP-003 (P2 integration):** module lifecycle [JSON error](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376745409); compare published create-project, module install/enable/disable/uninstall, config publication, CLI JSON, schema migration and release generations; preserve retained application data and real module dependencies.
- [ ] **APP-004 (release coordination):** Pathwise 4.3 explicitly breaks legacy SFTP compatibility; check Foundation module catalog, constraints, config migration and docs, actual Composer graph and no-dev acceptance. Track all upstream fixes through this folder; avoid locking consumers to unreleased revisions.
- [ ] **APP-005:** complete full matrix after corrected libraries published; 2,177 routine + two native gRPC, consumer profiles, 41 engineering modes, endurance, separate SQL Server HA only with dedicated fenced fixture. Distinguish app defaults, provider defects and fixture/infrastructure.

## Batches
A: app defaults and actual dependency graph; B: module/readiness/HTTP integrations; C: upgrade migration and final full production qualification. No confirmed independent skeleton defect currently.

## Evidence and execution rules

Baseline: [run 45661843498](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498) (PHP 8.5, c5c5efa; 1,505/2,179 jobs passed), [run 45756151480](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45756151480) (6f73868; 45/54 passed, all 14 worker types operational), [defect register](../qa/production-defect-register.md), [capability inventory](../qa/capability-coverage.md). All 22 full-run job-summary pages were screened, representative logs reviewed, and existing source-traced findings carried forward. Counts and scenario names are not root-cause counts. Exact installed versions not explicitly established below must be taken from each job's package inventory. This is an initial upstream handoff, not completed root-cause or release qualification. Do not treat an uninvestigated failure as a library defect, or absence of a confirmed finding as proof of defect-free behavior.

Follow [PHPForge engineering principles](https://github.com/infocyph/PHPForge/blob/main/resources/engineering-principles.md); use GitHub-hosted workers, one logical batch per commit, update tracker with exact source/version/job evidence. No skips, vendor edits, weakened assertions, security overrides, dependency revision pins, or automatic merge. Keep frozen 2,177 routine tuples and two native gRPC modes; add only justified regression cases. Run published-package consumer tests on PHP 8.5 and supported upstream CI versions; final full matrix after upstream releases. Keep fixture/third-party fixes separate. No upstream implementation performed in this feedback batch.
