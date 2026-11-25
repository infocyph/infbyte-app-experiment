<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Release\ActiveGeneration;
use Infocyph\Foundation\Release\FoundationReleaseBootstrap;

require __DIR__ . '/bootstrap.php';

$mode = $argv[1] ?? '';
if ($mode === 'setup' || $mode === 'migrate') {
    $app = application();
    $probe = new ReleaseProbe($app);
    $app->execution()->run($mode === 'setup' ? $probe->setup(...) : $probe->migrate(...));
} elseif ($mode === 'activate') {
    $release = json_decode((string) file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    new ActiveGeneration()->activate($release['release_root'], $release['generation']);
    ensure(new ActiveGeneration()->current($release['release_root'])['generation'] === $release['generation'], 'Rollback generation identity mismatch.');
    ensure(hash_equals($release['manifest_sha256'], hash_file('sha256', $release['manifest'])), 'Immutable manifest changed.');
} elseif ($mode === 'reject') {
    $release = json_decode((string) file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    try {
        new FoundationReleaseBootstrap($release['release_root'], str_repeat('0', 64))->web(['base_path' => getenv('PROJECT')]);
    } catch (\UnexpectedValueException | \RuntimeException $failure) {
        echo "Untrusted release digest rejected\n";
        return;
    }
    throw new \LogicException('Untrusted manifest digest accepted.');
} else {
    throw new \InvalidArgumentException('Unknown release fixture mode.');
}
