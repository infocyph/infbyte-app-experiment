# UID — production feedback and upstream action plan

Status: **open — initial investigation and release planning** · 2026-11-26

Baseline: 6.0.1 required by Foundation 4.0.1; verify exact installed version per job. Release target to be selected after root-cause isolation; no version bump authorized by this file.

## Findings

No confirmed UID-owned defect from reviewed failures.

- [ ] **UID-001 (corrected playground dispatch):** [provider boundaries](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376720495) undefined function after B56 fixture overwritten; restored original function and Redis provisioning. Targeted [run 45750743367](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45750743367) restored UID workload passed. Do not attribute fixture to UID.
- [ ] **UID-002 (P1 regression/investigation):** native sequence provider boundaries, shared Redis/filesystem state, lease ownership, counter exhaustion, regressed clock, restart, concurrency and cross-process uniqueness. Inspect direct provider/Randflake/UID scenarios and exact collision evidence, deterministic seeds and native time; never assume collision solely from application DB failure.
- [ ] **UID-003 (P2):** all documented UUID versions, ULID, TypeID, ObjectID, Snowflake/Sonyflake, TBSL, KSUID/XID, NanoID/CUID2, parsing, binary/base encoding and deterministic IDs. Foundation recovery factor width is Foundation's own ID/schema contract, not UID (see [Foundation](foundation.md)). Confirm DB uniqueness/length across drivers.

## Batches
A: verify direct inventory and restored provider controls; B: resolve any independently reproduced sequence/value bug; C: shared-provider/concurrency/application ID acceptance. No new upstream defect claim.

## Evidence and execution rules

Baseline: [run 45661843498](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498) (PHP 8.5, c5c5efa; 1,505/2,179 jobs passed), [run 45756151480](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45756151480) (6f73868; 45/54 passed, all 14 worker types operational), [defect register](../qa/production-defect-register.md), [capability inventory](../qa/capability-coverage.md). All 22 full-run job-summary pages were screened, representative logs reviewed, and existing source-traced findings carried forward. Counts and scenario names are not root-cause counts. Exact installed versions not explicitly established below must be taken from each job's package inventory. This is an initial upstream handoff, not completed root-cause or release qualification. Do not treat an uninvestigated failure as a library defect, or absence of a confirmed finding as proof of defect-free behavior.

Follow [PHPForge engineering principles](https://github.com/infocyph/PHPForge/blob/main/resources/engineering-principles.md); use GitHub-hosted workers, one logical batch per commit, update tracker with exact source/version/job evidence. No skips, vendor edits, weakened assertions, security overrides, dependency revision pins, or automatic merge. Keep frozen 2,177 routine tuples and two native gRPC modes; add only justified regression cases. Run published-package consumer tests on PHP 8.5 and supported upstream CI versions; final full matrix after upstream releases. Keep fixture/third-party fixes separate. No upstream implementation performed in this feedback batch.
