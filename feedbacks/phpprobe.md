# PHPProbe — production feedback and upstream action plan

Status: **open — initial investigation and release planning** · 2026-11-26

Baseline: Engineering-only, exact installed version pending workflow package evidence. Release target to be selected after root-cause isolation; no version bump authorized by this file.

## Evidence

[run 45661843358](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843358) completed **41/41 successful engineering jobs** (reference, syntax/options, report reference/GitHub, changed-only, comment options and other frozen modes). No confirmed defect from this run.

- [ ] **PROBE-001:** retain all 41 modes, collect exact package version and report schema; inspect failure/exit semantics, changed-file detection, reference baselines, syntax/comment options, policy reporting and malformed inputs. Investigate only reproduced divergence.
- [ ] **PROBE-002:** verify engineering diagnostics remain separate from production runtime; no dev tooling added to no-dev consumer.
- [ ] **PROBE-003:** verify PHP version support and cross-repository PHPForge integration, no false green from report-only checks or suppressed errors.

## Batches
A: preserve passing published engineering baseline; B: trace new failures if any; C: rerun all 41 modes. No release change recommended solely on this evidence.

## Evidence and execution rules

Baseline: [run 45661843498](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498) (PHP 8.5, c5c5efa; 1,505/2,179 jobs passed), [run 45756151480](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45756151480) (6f73868; 45/54 passed, all 14 worker types operational), [defect register](../qa/production-defect-register.md), [capability inventory](../qa/capability-coverage.md). All 22 full-run job-summary pages were screened, representative logs reviewed, and existing source-traced findings carried forward. Counts and scenario names are not root-cause counts. Exact installed versions not explicitly established below must be taken from each job's package inventory. This is an initial upstream handoff, not completed root-cause or release qualification. Do not treat an uninvestigated failure as a library defect, or absence of a confirmed finding as proof of defect-free behavior.

Follow [PHPForge engineering principles](https://github.com/infocyph/PHPForge/blob/main/resources/engineering-principles.md); use GitHub-hosted workers, one logical batch per commit, update tracker with exact source/version/job evidence. No skips, vendor edits, weakened assertions, security overrides, dependency revision pins, or automatic merge. Keep frozen 2,177 routine tuples and two native gRPC modes; add only justified regression cases. Run published-package consumer tests on PHP 8.5 and supported upstream CI versions; final full matrix after upstream releases. Keep fixture/third-party fixes separate. No upstream implementation performed in this feedback batch.
