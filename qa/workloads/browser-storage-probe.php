<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Session\SessionDatabaseSchema;
use PDO;

require __DIR__ . '/bootstrap.php';

$driver = getenv('LAB_SESSION_DRIVER');
ensure(in_array($driver, ['file', 'database'], true), 'Unexpected browser session storage fixture.');
$mode = $argv[1] ?? '';
if ($mode === 'install') {
    if ($driver === 'database') {
        $app = application();
        $app->execution()->run(static function () use ($app): void {
            $schema = $app->make(SessionDatabaseSchema::class);
            $schema->install();
            ensure($schema->readiness() === ['installed' => true, 'table' => 'lab_browser_sessions', 'connection' => 'main'],
                'Database-backed browser session schema unavailable.');
        });
    }
    return;
}
ensure($mode === 'observe', 'Unknown session storage fixture mode.');
$fixture = getenv('RUNNER_TEMP') . '/browser-session.json';
$session = json_decode((string) file_get_contents($fixture), true, flags: JSON_THROW_ON_ERROR);
$id = substr($session['cookie'], strlen('lab_session='));
ensure(preg_match('/^[a-f0-9]{64}$/D', $id) === 1, 'Invalid browser session fixture identity.');
if ($driver === 'file') {
    $path = getenv('PROJECT') . '/storage/lab-sessions/' . hash('sha3-256', "foundation.session.file\0" . $id) . '.json';
    $encoded = file_get_contents($path);
    ensure(is_string($encoded), 'Independent session file missing.');
} else {
    $db = getenv('DB_BACKEND');
    $dsn = match ($db) {
        'pgsql' => 'pgsql:host=127.0.0.1;port=5432;dbname=experiment',
        'mysql' => 'mysql:host=127.0.0.1;port=3306;dbname=experiment;charset=utf8mb4',
        'mariadb' => 'mysql:host=127.0.0.1;port=3307;dbname=experiment;charset=utf8mb4',
        default => throw new \RuntimeException('Unexpected SQL session fixture.'),
    };
    $pdo = new PDO($dsn, 'experiment', 'experiment', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $query = $pdo->prepare('SELECT payload, expires_at FROM lab_browser_sessions WHERE id = ?');
    $query->execute([$id]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    ensure(is_array($row) && (int) $row['expires_at'] > time(), 'Independent SQL session record missing or expired.');
    $encoded = $row['payload'];
}
$payload = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
ensure(($payload['data']['count'] ?? null) === (int) $argv[2] && ($payload['expires_at'] ?? 0) > time(),
    'Persisted browser session did not reconcile concurrent writes.');
echo json_encode(['driver' => $driver, 'backend' => getenv('DB_BACKEND'), 'count' => $payload['data']['count'],
    'unexpired' => true], JSON_THROW_ON_ERROR), PHP_EOL;
