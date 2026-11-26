# TalkingBytes — production feedback and upstream action plan

Status: **open — initial investigation and release planning** · 2026-11-26

Baseline: Exact installed specialist version pending inventory. Release target to be selected after root-cause isolation; no version bump authorized by this file.

## Findings

- [ ] **MSG-001 (P1 candidate reproduced independently):** [latest IMAP](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45756151480/job/139695177523) native authenticated Python/Dovecot proves 12 messages plus MOVE and UIDPLUS, but ImapSocketTransport.php:217 throws Safe IMAP move fallback requires MOVE or UIDPLUS capability. Inspect capability parsing, tagged/untagged responses, after-auth refresh and case normalization. Preserve safe MOVE/UID EXPUNGE behavior; never replace with broad EXPUNGE. Verify native MOVE, UIDPLUS fallback, missing-capability refusal, message identity and unrelated message retention, TLS/STARTTLS/IDLE.
- [ ] **MSG-TB-002 (P2 retest):** historical POP3, TLS and IDLE seed/readiness failures; latest POP3/mailbox TLS/IDLE controls pass. Re-run protocol variants and SMTP fault/retry/spool, MIME attachments, DKIM, negative credentials, mailbox state/cleanup. Separate peer fixture from protocol defect.
- [ ] **MSG-TB-003 (P1 integration):** provider HTTP retry/rate/circuit [hosted job](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376706284), [hosted job](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376708415), security peer binding [Epicrypt EPC-001](epicrypt.md) and [Runwire](runwire.md). Trace PSR transport and provider ownership; exact origin and peer, redirect credentials, body replay, timeout/deadline and cancellation. Don't duplicate transport logic unnecessarily.
- [ ] **MSG-TB-004 (P2 regression):** both native gRPC modes and inbound dispatch passed latest representative controls; requalify unary/streaming/client/server/bidi, TLS trust, unimplemented, deadlines, generated stubs, and native HTTP/2 distinction; webhook tamper/replay and outbound HTTP.

## Batches
A: IMAP capability source trace and fix; B: mailbox/SMTP and independent wire regression; C: communication/gRPC/HTTP integration, preserve positive controls and exact package evidence.

## Evidence and execution rules

Baseline: [run 45661843498](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498) (PHP 8.5, c5c5efa; 1,505/2,179 jobs passed), [run 45756151480](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45756151480) (6f73868; 45/54 passed, all 14 worker types operational), [defect register](../qa/production-defect-register.md), [capability inventory](../qa/capability-coverage.md). All 22 full-run job-summary pages were screened, representative logs reviewed, and existing source-traced findings carried forward. Counts and scenario names are not root-cause counts. Exact installed versions not explicitly established below must be taken from each job's package inventory. This is an initial upstream handoff, not completed root-cause or release qualification. Do not treat an uninvestigated failure as a library defect, or absence of a confirmed finding as proof of defect-free behavior.

Follow [PHPForge engineering principles](https://github.com/infocyph/PHPForge/blob/main/resources/engineering-principles.md); use GitHub-hosted workers, one logical batch per commit, update tracker with exact source/version/job evidence. No skips, vendor edits, weakened assertions, security overrides, dependency revision pins, or automatic merge. Keep frozen 2,177 routine tuples and two native gRPC modes; add only justified regression cases. Run published-package consumer tests on PHP 8.5 and supported upstream CI versions; final full matrix after upstream releases. Keep fixture/third-party fixes separate. No upstream implementation performed in this feedback batch.
