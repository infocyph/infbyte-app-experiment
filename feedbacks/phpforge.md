# PHPForge — production feedback and upstream action plan

Status: **open — initial investigation and release planning** · 2026-11-26

Baseline: Engineering-only; not installed in no-dev production workload. Release target to be selected after root-cause isolation; no version bump authorized by this file.

## Evidence

No confirmed PHPForge defect from the reviewed playground evidence. The shared setup explicitly checks no vendor/infocyph/phpforge and no vendor/bin/pest in production. [run 45661843211](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843211) 12/12 consumer acceptance, [run 45661843358](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843358) 41/41 PHPProbe engineering jobs, latest CodeQL [run 45756150722](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45756150722) passed.

- [ ] **FORGE-001:** keep the 13-section engineering principles and workflow quality gates, accurate exit behavior and skips policy; verify generated configs and supported PHP versions separately from application runtime.
- [ ] **FORGE-002:** historical CodeQL/QUIC/Cassandra setup and evidence-path issues are playground/toolchain, representative controls now pass; do not classify them as PHPForge without direct reproduction.
- [ ] **FORGE-003:** verify published dependency policy, no-dev separation, analyzer/QA workflow consistency and no suppressed findings across upstream release PRs. Record actual tool version and failing check before recommending changes.

## Batches
A: validate engineering boundary; B: independently reproduce any tool defect; C: release policy and no-dev consumer regression.

## Evidence and execution rules

Baseline: [run 45661843498](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498) (PHP 8.5, c5c5efa; 1,505/2,179 jobs passed), [run 45756151480](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45756151480) (6f73868; 45/54 passed, all 14 worker types operational), [defect register](../qa/production-defect-register.md), [capability inventory](../qa/capability-coverage.md). All 22 full-run job-summary pages were screened, representative logs reviewed, and existing source-traced findings carried forward. Counts and scenario names are not root-cause counts. Exact installed versions not explicitly established below must be taken from each job's package inventory. This is an initial upstream handoff, not completed root-cause or release qualification. Do not treat an uninvestigated failure as a library defect, or absence of a confirmed finding as proof of defect-free behavior.

Follow [PHPForge engineering principles](https://github.com/infocyph/PHPForge/blob/main/resources/engineering-principles.md); use GitHub-hosted workers, one logical batch per commit, update tracker with exact source/version/job evidence. No skips, vendor edits, weakened assertions, security overrides, dependency revision pins, or automatic merge. Keep frozen 2,177 routine tuples and two native gRPC modes; add only justified regression cases. Run published-package consumer tests on PHP 8.5 and supported upstream CI versions; final full matrix after upstream releases. Keep fixture/third-party fixes separate. No upstream implementation performed in this feedback batch.
