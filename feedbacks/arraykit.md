# ArrayKit — production feedback and upstream action plan

Status: **open — initial investigation and release planning** · 2026-11-26

Baseline: 5.3.2 (full-run install evidence). Release target to be selected after root-cause isolation; no version bump authorized by this file.

## Findings

No confirmed ArrayKit-owned defect in reviewed evidence. Full-run summaries showed no failed ecosystem-array prefix in the screened pages; that does not establish every indirect caller or behavior is correct.

- [ ] **ARR-001 (P2 ownership investigation):** [import wildcard cast](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376650621) and [validation casts](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376720146) point primarily to ReqShield. Check ArrayKit only if direct nested path/collection/coercion behavior independently diverges; use direct same-input control before attribution.
- [ ] **ARR-002 (P2 integration):** Foundation compiled config, release generations and module JSON [hosted job](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376745409); verify ArrayKit config merge/typed dotenv, references, immutable/lazy namespace compilation, generation isolation and malformed artifact. Do not assume module CLI output is ArrayKit defect.
- [ ] **ARR-003 (regression):** direct DTO hydration, dot paths, helpers/set semantics, projections, hooks, lazy/streaming collections, large bounded inputs, typed coercion, cycles, key/zero/null preservation, config precedence and Runwire integration. Review exact frozen array/collection/lazy and integrated import/config jobs, independent values and memory profile; add findings only on reproducible divergence.

## Batches
A: inventory direct and indirect failures, independent controls; B: implement only proven fixes; C: preserve positive array contracts and application integration on published graph.

## Evidence and execution rules

Baseline: [run 45661843498](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498) (PHP 8.5, c5c5efa; 1,505/2,179 jobs passed), [run 45756151480](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45756151480) (6f73868; 45/54 passed, all 14 worker types operational), [defect register](../qa/production-defect-register.md), [capability inventory](../qa/capability-coverage.md). All 22 full-run job-summary pages were screened, representative logs reviewed, and existing source-traced findings carried forward. Counts and scenario names are not root-cause counts. Exact installed versions not explicitly established below must be taken from each job's package inventory. This is an initial upstream handoff, not completed root-cause or release qualification. Do not treat an uninvestigated failure as a library defect, or absence of a confirmed finding as proof of defect-free behavior.

Follow [PHPForge engineering principles](https://github.com/infocyph/PHPForge/blob/main/resources/engineering-principles.md); use GitHub-hosted workers, one logical batch per commit, update tracker with exact source/version/job evidence. No skips, vendor edits, weakened assertions, security overrides, dependency revision pins, or automatic merge. Keep frozen 2,177 routine tuples and two native gRPC modes; add only justified regression cases. Run published-package consumer tests on PHP 8.5 and supported upstream CI versions; final full matrix after upstream releases. Keep fixture/third-party fixes separate. No upstream implementation performed in this feedback batch.
