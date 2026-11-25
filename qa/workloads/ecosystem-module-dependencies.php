<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\Foundation\Module\ModuleArchitectureCatalog;
use Infocyph\Foundation\Module\ModuleFeaturePlanner;

function conditionalModulesWorkload(): void
{
    $project = (string) getenv('PROJECT');
    $manifest = json_decode((string) file_get_contents($project . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $catalog = new ModuleArchitectureCatalog();
    $planner = new ModuleFeaturePlanner();
    $features = [
        'data.database', 'data.filesystem', 'security.crypto', 'security.validation',
        'security.mfa', 'security.passkey', 'execution.messaging', 'execution.communication',
    ];
    $installed = [];
    foreach ($features as $feature) {
        foreach ($catalog->resolve($feature)['definition']['packages'] as $package => $constraint) {
            $version = InstalledVersions::getVersion($package);
            verify(is_string($version), 'Consumer package absent: ' . $package);
            verify(isset($manifest['require'][$package]), 'Consumer root ownership absent: ' . $package);
            $installed[$package] = $version;
        }
    }

    $context = [
        'root_known' => true,
        'root_requirements' => $manifest['require'],
        'installed' => $installed,
        'extensions' => array_map(strtolower(...), get_loaded_extensions()),
        'published_configs' => array_map(basename(...), glob($project . '/config/*.php') ?: []),
        'enabled' => [],
        'selected_features' => [],
        'config' => [],
    ];
    $manifestDigest = hash_file('sha256', $project . '/composer.json');
    $lockDigest = hash_file('sha256', $project . '/composer.lock');
    $checks = 0;

    for ($configuration = 0; $configuration < 8; ++$configuration) {
        $dependencies = [
            'security.validation' => ($configuration & 1) ? ['data.database'] : [],
            'security.mfa' => ($configuration & 2) ? ['security.crypto'] : [],
            'execution.messaging' => ($configuration & 4) ? ['data.database'] : [],
        ];
        $context['config'] = [
            'validation' => ['database_connection' => ($configuration & 1) ? 'primary' : ''],
            'auth' => ['drivers' => ['mfa' => ($configuration & 2) ? 'otp' : 'simple']],
            'messaging' => ['durable' => ['enabled' => (bool) ($configuration & 4)]],
        ];

        for ($selection = 1; $selection < 256; ++$selection) {
            $requested = [];
            foreach ($features as $index => $feature) {
                if ($selection & (1 << $index)) {
                    $requested[] = $feature;
                }
            }
            $required = $requested;
            foreach ($requested as $feature) {
                array_push($required, ...($dependencies[$feature] ?? []));
            }
            $required = array_values(array_intersect($features, array_unique($required)));
            $context['enabled'] = $requested;
            $context['selected_features'] = $requested;
            $enable = $planner->plan('enable', $requested, $context);
            verify($enable['required'] === $required, 'Wrong dependency closure');
            verify($enable['applicable'] === (array_diff($required, $requested) === []), 'Wrong enable readiness');
            $install = $planner->plan('install', $requested, $context);
            verify($install['applicable'] && $install['required'] === $required, 'Installed prerequisites rejected');
            verify($install['effects']['require'] === [] && $install['effects']['activate'] === [], 'Unexpected install effects');

            foreach (['data.database', 'security.crypto'] as $prerequisite) {
                $blocked = false;
                foreach ($requested as $feature) {
                    $blocked = $blocked || in_array($prerequisite, $dependencies[$feature] ?? [], true);
                }
                $disable = $planner->plan('disable', [$prerequisite], $context);
                verify($disable['applicable'] === !$blocked, 'Dependent disable guard differs');
                $remove = $planner->plan('remove', [$prerequisite], $context);
                verify($remove['applicable'] === (!$blocked && !in_array($prerequisite, $requested, true)), 'Dependent remove guard differs');
            }
            ++$checks;
        }
    }

    $context['enabled'] = ['data.database', 'security.crypto'];
    $context['selected_features'] = $context['enabled'];
    foreach (['security.validation', 'execution.messaging', 'security.mfa'] as $feature) {
        verify($planner->plan('enable', [$feature], $context)['applicable'], 'Eligible feature rejected: ' . $feature);
    }

    $missing = $context;
    unset($missing['installed']['infocyph/dblayer']);
    verify(!$planner->plan('enable', ['execution.messaging'], $missing)['applicable'], 'Missing database package accepted');
    $missing = $context;
    $missing['extensions'] = array_values(array_diff($missing['extensions'], ['pdo']));
    verify(!$planner->plan('install', ['security.validation'], $missing)['applicable'], 'Missing prerequisite PDO accepted');
    $missing = $context;
    $missing['root_known'] = false;
    verify(!$planner->plan('disable', ['data.database'], $missing)['applicable'], 'Unknown root ownership accepted');

    $context['enabled'] = [];
    $context['selected_features'] = ['security.passkey'];
    $remove = $planner->plan('remove', ['security.mfa'], $context);
    verify($remove['applicable'] && !in_array('infocyph/otp', $remove['effects']['remove'], true), 'Shared OTP ownership lost');

    verify(hash_file('sha256', $project . '/composer.json') === $manifestDigest, 'Planner changed root manifest');
    verify(hash_file('sha256', $project . '/composer.lock') === $lockDigest, 'Planner changed lockfile');
    echo json_encode(['configuration_selection_pairs' => $checks, 'installed_specialists' => $installed], JSON_THROW_ON_ERROR), PHP_EOL;
}
