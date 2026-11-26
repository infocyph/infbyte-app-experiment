# Infbyte production feedback — library handoff register

Updated 2026-11-26. Scope derived from [capability inventory](../qa/capability-coverage.md), [Foundation core composer requirements](https://github.com/infocyph/Foundation/blob/4.0.1/composer.json), [module catalog](https://github.com/infocyph/Foundation/blob/4.0.1/src/Module/ModuleCatalog.php) and [Infbyte skeleton](https://github.com/infocyph/Infbyte/blob/main/composer.json). 14 first-party runtime libraries plus application skeleton and 2 engineering packages; third-party providers are tracked under the consuming owner, not independent first-party release plans.

[run 45661843498](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498) all 22 job-summary pages screened: 2,179 jobs, 674 failed; [run 45756151480](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45756151480) 45/54 passed, all 14 worker types operational. Historical failures are not 674 bugs; representative logs and existing register source traces underpin these initial plans. Each file includes owners, evidence, priority/actions and verification; unresolved entries require exact installed version, independent control and source trace before promotion to confirmed. No upstream changes or new run in this documentation batch.

| Feedback | Focus | Initial disposition |
| --- | --- | --- |
| [Foundation](foundation.md) | MFA schema, authorization, PAT, readiness, provider lifecycle | Confirmed defects + candidates |
| [Epicrypt](epicrypt.md) | Remote JWKS approved peer, PKI | Confirmed security boundary + candidates |
| [DBLayer](dblayer.md) | CTE, derived query, transaction/HA | Candidates |
| [CacheLayer](cachelayer.md) | Bulk, atomicity, locks, remote providers | Candidates |
| [Runwire](runwire.md) | H2 settings, multiplexing, runtime | Reproduced candidate |
| [Webrick](webrick.md) | Attributes, signed and compiled routes | Reproduced candidate |
| [InterMix](intermix.md) | Contextual compilation, artifacts, scope | Reproduced candidate |
| [ReqShield](reqshield.md) | Wildcard cast/sanitization, expressions | Candidates |
| [OTP](otp.md) | Durable state locks, cluster recovery | Candidates |
| [Omnibus](omnibus.md) | Outbox/relay, broker settlement | Integration candidates; quorum representative fixed |
| [TalkingBytes](talkingbytes.md) | IMAP capability parsing, wire protocols | Reproduced candidate |
| [Pathwise](pathwise.md) | Streams, archive publication, SFTP | Source-confirmed defects; **4.3 compatibility-breaking** |
| [ArrayKit](arraykit.md) | Data/config processing | No confirmed defect; investigate |
| [UID](uid.md) | Sequence/provider boundaries | No confirmed defect; fixture corrected |
| [Infbyte](infbyte.md) | Skeleton and consumer integration | Integration candidates |
| [PHPProbe](phpprobe.md) | Engineering diagnostics | 41/41 passed; no confirmed defect |
| [PHPForge](phpforge.md) | Engineering and release gates | No confirmed defect |

## Coordination

Prioritize Epicrypt peer boundary, Foundation confirmed correctness, then reproduced protocol/compiler/state issues. Pathwise 4.3 authorized despite compatibility break: retire legacy SFTP v2 and establish secure supported SFTP route, communicate breaking changes and coordinate downstream module constraints; no security overrides. Other release targets not assigned. Owners should cross-link shared findings, resolve only their actual boundary, track regression and package version, and return published fixes to playground. Keep original frozen cases and genuine red results. Full published-package qualification remains the final gate. [Central defect register](../qa/production-defect-register.md) remains authoritative for historical worker evidence; per-library plans add actionable handoff, not blanket defect-free certification.
