# Infbyte published consumer acceptance

This repository tests public Infbyte 4 installations using GitHub-hosted runners. It is not an application distribution and never modifies the published package.

## Initial evidence

[Run 45435877095](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45435877095) found all six default-install core/all jobs and the three no-dev Core jobs passing. The eleven no-dev optional-feature jobs failed their development dependency isolation check after successful feature installation and activation.

Foundation 4's [ModuleFeatureComposerExecutor](https://github.com/infocyph/Foundation/blob/4.0/src/Module/ModuleFeatureComposerExecutor.php) calls Composer require/remove without `--update-no-dev`. An initial `create-project --no-dev` does not persist that option across later Composer commands. PHPForge and Pest can therefore be reinstalled during module changes. This is an upstream lifecycle/usage gap, not a feature resolution failure. No upstream change is made here.

## Explicit production-mode requalification

Set `COMPOSER_NO_DEV=1` for the entire no-dev consumer job, including all module operations. Composer maps that variable to no-dev/update-no-dev; keep default-install jobs at normal dev mode. Assert the absence of PHPForge and Pest immediately after initial installation, after every feature install/removal and before deployment. Do not uninstall development packages after the fact to conceal this behavior.

The new matrix qualifies the documented explicit production environment, not automatic persistence of a one-time --no-dev flag. Original failed-run evidence remains available. Exact-head requalification pending.
