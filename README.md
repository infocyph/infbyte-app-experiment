# Infbyte published consumer acceptance

This repository tests public Infbyte 4 installations using GitHub-hosted runners. It is not an application distribution and never modifies the published package.

## Initial evidence

[Run 45435877095](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45435877095) found all six default-install core/all jobs and the three no-dev Core jobs passing. The eleven no-dev optional-feature jobs failed their development dependency isolation check after successful feature installation and activation.

Foundation 4's [ModuleFeatureComposerExecutor](https://github.com/infocyph/Foundation/blob/4.0/src/Module/ModuleFeatureComposerExecutor.php) calls Composer require/remove without `--update-no-dev`. An initial `create-project --no-dev` does not persist that option across later Composer commands. PHPForge and Pest can therefore be reinstalled during module changes. This is an upstream lifecycle/usage gap, not a feature resolution failure. No upstream change is made here.

## Explicit production-mode requalification

Set `COMPOSER_NO_DEV=1` for the entire no-dev consumer job, including all module operations. Composer maps that variable to no-dev/update-no-dev; keep default-install jobs at normal dev mode. Assert the absence of PHPForge and Pest immediately after initial installation, after every feature install/removal and before deployment. Do not uninstall development packages after the fact to conceal this behavior.

The new matrix qualifies the documented explicit production environment, not automatic persistence of a one-time --no-dev flag. Original failed-run evidence remains available. Exact-head requalification pending.

## Published Foundation 4.0.1 requalification

The explicit production-mode workaround passed [20/20 jobs](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45436509096). Foundation's upstream correction is in [PR #23](https://github.com/infocyph/Foundation/pull/23), merged and tagged 4.0.1. Remove the workflow-level COMPOSER_NO_DEV override; unset it before each lifecycle step. Use normal Packagist resolution of the Infbyte 4 skeleton, require an installed stable Foundation version of at least 4.0.1, and assert the recorded Composer dev mode and existing PHPForge/Pest isolation after install/removal. This is a public package test, not a branch override or pinned revision. The new run must establish availability and results; GitHub tag creation alone is not publication evidence.

## Phase 2 workflow dispatch

Open [Actions](https://github.com/infocyph/infbyte-app-experiment/actions) and use **Run workflow** on the selected repository-managed workflow. The full production laboratory is manual only; choose `all`, a capability group, `application`, `ecosystem`, or the native gRPC group. New dispatches replace older runs of the same selected group; a different group has its own concurrency key. To reproduce one existing routine scenario, dispatch the matching dedicated Production worker directly with the exact scenario, backend, host, matcher from the frozen matrix (PHP 8.5 is fixed); invalid combinations fail before PHP setup. Each worker remains reusable by the full matrix. PHPProbe runs on its own implementation/workflow changes and supports all or one of its 41 modes. Consumer acceptance runs automatically only when its self-contained workflow changes and is otherwise manually dispatched. Endurance remains weekly/manual with its load-profile selector. External SQL Server HA remains explicitly confirmed/manual and requires its dedicated environment. GitHub-managed CodeQL is separate from these YAML workflows; its settings and manual rerun availability are managed by GitHub.

Do not restart full qualification merely to update a Markdown tracker. Use a targeted worker for corrected scenarios and retain all hosted evidence. A selected group or worker result does not establish full-matrix acceptance.


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


## Explicit full qualification request

Changing `qa/qualification-request.json` on `main` deliberately launches the full production laboratory (all capability shards and both native gRPC modes), consumer acceptance, all PHPProbe modes and the standard soak profile. Ordinary code and Markdown tracker commits do not trigger this full run. Native manual dispatch remains supported; duplicate full requests share the same cancellation key as a full manual dispatch. Do not edit the request while collecting evidence unless explicitly starting a fresh qualification. External SQL Server HA fencing remains separately authorized and is never triggered by this file.
