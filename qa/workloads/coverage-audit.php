<?php

declare(strict_types=1);

use Composer\InstalledVersions;

require getenv('PROJECT') . '/vendor/autoload.php';

$repository = (string) getenv('GITHUB_WORKSPACE');
$workloads = $repository . '/qa/workloads';
$evidence = (string) getenv('EVIDENCE');

function auditAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function auditRead(string $path): string
{
    $contents = file_get_contents($path);
    auditAssert(is_string($contents), 'Unreadable inventory input: ' . $path);

    return $contents;
}

/** Inventory literal matrix rows and dispatch branches without claiming behavioral coverage. */
function auditMatrix(string $repository, string $workloads): array
{
    $workflow = auditRead($repository . '/.github/workflows/production-workloads.yml');
    preg_match_all('/^\h*-\h*\{scenario:\h*([^,}]+)([^}]*)\}/m', $workflow, $matches, PREG_SET_ORDER);
    auditAssert($matches !== [], 'No workload matrix rows found.');
    $ecosystem = auditRead($workloads . '/ecosystem.sh');
    $direct = auditRead($workloads . '/ecosystem.php');
    $database = auditRead($workloads . '/database.sh');
    $stress = auditRead($workloads . '/run.php');
    $stress = substr($stress, strpos($stress, '$scenario = match'), strpos($stress, '$mode =') - strpos($stress, '$scenario = match'));
    $rows = [];
    $scenarios = [];
    foreach ($matches as $match) {
        $scenario = trim($match[1]);
        $row = ['scenario' => $scenario, 'backend' => '', 'host' => 'worker', 'matcher' => 'fused', 'php' => '8.6'];
        foreach (explode(',', ltrim($match[2], ',')) as $part) {
            [$key, $value] = array_map('trim', explode(':', $part, 2));
            auditAssert(array_key_exists($key, $row), 'Unknown matrix key: ' . $key);
            $row[$key] = trim($value, "'\"");
        }
        auditAssert($row['backend'] !== '' && in_array($row['matcher'], ['fused', 'sharded'], true), 'Invalid backend or route matcher: ' . $scenario);
        $key = implode('|', $row);
        auditAssert(!isset($rows[$key]), 'Duplicate normalized matrix row: ' . $key);
        $rows[$key] = $row;
        $scenarios[$scenario] = true;
        if (str_starts_with($scenario, 'ecosystem-')) {
            $route = auditShellRoute($ecosystem, $scenario);
            auditAssert($route !== null, 'Missing ecosystem dispatch: ' . $scenario);
            if ($route === 'ecosystem-*') {
                auditAssert(str_contains($direct, "'" . substr($scenario, 10) . "' =>"), 'Missing PHP ecosystem branch: ' . $scenario);
            }
        } elseif (str_starts_with($scenario, 'db-')) {
            auditAssert(auditShellRoute($database, $scenario) !== null, 'Missing database dispatch: ' . $scenario);
        } elseif (!in_array($scenario, ['http', 'browser', 'operations', 'transfer', 'transfer-cleanup', 'oauth-http'], true)) {
            auditAssert(str_contains($stress, "'" . $scenario . "'"), 'Missing business workload branch: ' . $scenario);
        }
    }
    foreach ([$ecosystem, $database] as $dispatcher) {
        preg_match_all('~qa/workloads/([a-zA-Z0-9_.-]+\.(?:sh|php))~', $dispatcher, $scripts);
        foreach (array_unique($scripts[1]) as $script) {
            auditAssert(is_file($workloads . '/' . $script), 'Dispatcher script not found: ' . $script);
        }
    }
    preg_match_all('/^\h{2}(database|ecosystem|ecosystem-extra|ecosystem-residual|ecosystem-expansion|application):\h*\n(.*?)(?=^ {2}\S|\z)/ms', $workflow, $shards, PREG_SET_ORDER);
    $shardCounts = [];
    foreach ($shards as $shard) {
        preg_match_all('/^\h*-\h*\{scenario:\h*([^,}]+)/m', $shard[2], $entries);
        auditAssert($entries[1] !== [] && count($entries[1]) <= 256, 'Invalid matrix shard size: ' . $shard[1]);
        foreach ($entries[1] as $name) {
            $name = trim($name);
            $expected = str_starts_with($name, 'db-') ? 'database' : (str_starts_with($name, 'ecosystem-') ? 'ecosystem' : 'application');
            auditAssert($expected === $shard[1] || ($expected === 'ecosystem' && in_array($shard[1], ['ecosystem-extra', 'ecosystem-residual', 'ecosystem-expansion'], true)), 'Scenario assigned to wrong shard: ' . $name);
        }
        $shardCounts[$shard[1]] = count($entries[1]);
    }
    auditAssert(count($shardCounts) === 6 && array_sum($shardCounts) === count($rows), 'Incomplete matrix shard inventory.');
    ksort($scenarios);

    return ['matrix_rows' => count($rows), 'matrix_shards' => $shardCounts, 'unique_scenarios' => array_keys($scenarios), 'rows' => array_values($rows)];
}

function auditShellRoute(string $source, string $scenario): ?string
{
    preg_match_all('/^\h*([a-z][a-z0-9*|-]*)\)/m', $source, $branches);
    foreach ($branches[1] as $branch) {
        foreach (explode('|', $branch) as $pattern) {
            if (fnmatch($pattern, $scenario)) {
                return $pattern;
            }
        }
    }

    return null;
}

/**
 * Lexical declaration inventory only: does not instantiate classes, run vendor
 * code, infer inherited APIs or count a source-name mention as coverage.
 */
function auditDeclarations(string $path): array
{
    $tokens = array_values(array_filter(PhpToken::tokenize(auditRead($path)), static fn(PhpToken $token): bool => !$token->isIgnorable()));
    $namespace = '';
    $depth = 0;
    $pending = null;
    $types = [];
    $active = [];
    foreach ($tokens as $i => $token) {
        if ($token->id === T_NAMESPACE) {
            $name = $tokens[$i + 1] ?? null;
            if ($name instanceof PhpToken && in_array($name->id, [T_STRING, T_NAME_QUALIFIED], true)) {
                $namespace = $name->text;
            }
        }
        if (in_array($token->id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            $name = $tokens[$i + 1] ?? null;
            if ($name instanceof PhpToken && $name->id === T_STRING && ($tokens[$i - 1]->id ?? null) !== T_DOUBLE_COLON) {
                $pending = ($namespace === '' ? '' : $namespace . '\\') . $name->text;
                $types[$pending] = ['kind' => strtolower($token->text), 'declared_public_methods' => []];
            }
        }
        if ($token->id === T_FUNCTION && $active !== []) {
            [$type, $bodyDepth] = $active[array_key_last($active)];
            if ($depth === $bodyDepth) {
                $next = $i + 1;
                if (($tokens[$next]->text ?? '') === '&') {
                    ++$next;
                }
                $name = $tokens[$next] ?? null;
                $public = true;
                for ($previous = $i - 1; $previous >= 0; --$previous) {
                    if (in_array($tokens[$previous]->text, [';', '{', '}'], true)) {
                        break;
                    }
                    if (in_array($tokens[$previous]->id, [T_PRIVATE, T_PROTECTED], true)) {
                        $public = false;
                        break;
                    }
                    if ($tokens[$previous]->id === T_PUBLIC) {
                        break;
                    }
                }
                if ($public && $name instanceof PhpToken && $name->id === T_STRING) {
                    $types[$type]['declared_public_methods'][] = $name->text;
                }
            }
        }
        if ($token->text === '{' || in_array($token->id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            ++$depth;
            if ($pending !== null) {
                $active[] = [$pending, $depth];
                $pending = null;
            }
        } elseif ($token->text === '}') {
            if ($active !== [] && $active[array_key_last($active)][1] === $depth) {
                array_pop($active);
            }
            --$depth;
        }
    }
    foreach ($types as &$type) {
        sort($type['declared_public_methods']);
    }
    unset($type);

    return $types;
}

$matrix = auditMatrix($repository, $workloads);
$packages = [];
foreach (InstalledVersions::getInstalledPackages() as $package) {
    if (!str_starts_with(strtolower($package), 'infocyph/')) {
        continue;
    }
    $path = InstalledVersions::getInstallPath($package);
    if ($path === null || !is_dir($path . '/src')) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path . '/src', FilesystemIterator::SKIP_DOTS));
    $types = [];
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $types += auditDeclarations($file->getPathname());
        }
    }
    ksort($types);
    $packages[$package] = ['version' => InstalledVersions::getPrettyVersion($package), 'types' => $types];
}
auditAssert($packages !== [], 'No installed Infocyph source packages inventoried.');
ksort($packages);
$inventory = ['matrix' => $matrix, 'source_declarations' => $packages, 'qualification' => 'Lexical declarations and dispatch only; no behavioral coverage claims.'];
$encoded = json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
auditAssert(file_put_contents($evidence . '/public-api-inventory.json', $encoded, LOCK_EX) === strlen($encoded), 'Inventory evidence write failed.');
echo json_encode(['matrix_rows' => $matrix['matrix_rows'], 'scenario_count' => count($matrix['unique_scenarios']), 'packages' => array_map(static fn(array $package): array => ['version' => $package['version'], 'declared_types' => count($package['types'])], $packages)], JSON_THROW_ON_ERROR), PHP_EOL;
