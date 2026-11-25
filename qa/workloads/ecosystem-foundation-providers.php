<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Http\JsonDispatch\Issue;
use Infocyph\Foundation\Http\JsonDispatch\JsonDispatchResponseFactory;
use Infocyph\Foundation\Http\JsonDispatch\JsonDispatchServiceProvider;
use Infocyph\Foundation\Logging\ExceptionReporter;
use Infocyph\Foundation\Logging\HttpExceptionLogger;
use Infocyph\Foundation\Logging\LoggingServiceProvider;
use Infocyph\Foundation\Notifications\Notification;
use Infocyph\Foundation\Notifications\NotificationRecipient;
use Infocyph\Foundation\Notifications\NotificationDispatcher;
use Infocyph\Foundation\Notifications\NotificationTemplateRegistry;
use Infocyph\Foundation\Notifications\EmailProfiles;
use Infocyph\Foundation\Auth\Notification\AuthNotificationType;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Psr\Log\LoggerInterface;

function foundationProviderApp(array $overrides, array $capabilities = [], array $providers = []): Application
{
    require_once __DIR__ . '/bootstrap.php';
    putenv('AUTH_TOKEN_SECRET=' . bin2hex(random_bytes(32)));
    $config = \Experiment\configuration();
    $config = array_replace_recursive($config, $overrides);
    $config['app']['capabilities'] = $capabilities;
    // Web-only built-ins can be explicitly selected for non-web provider
    // consumers; these scenarios do not claim production web-host qualification.
    $config['providers'] = ['common' => $providers, 'web' => [], 'cli' => [], 'worker' => [], 'scheduler' => []];
    return Foundation::worker($config)->boot();
}

function foundationJsonProviderContracts(string $mode): void
{
    $tunnel = $mode === 'tunneled';
    $app = foundationProviderApp([
        'responses' => ['json_dispatch' => ['vendor' => 'laboratory', 'application_version' => '2.3.4', 'tunnel_errors' => $tunnel]],
        'validation' => ['schemas' => ['lab.json' => ['sku' => 'required|integer|exists:lab_json_provider,id', 'items.*.quantity' => 'required|integer|min:1']], 'defaults' => ['fail_fast' => false]],
    ], in_array($mode, ['offset', 'cursor', 'validation'], true) ? ['database', 'validation'] : [], [JsonDispatchServiceProvider::class]);
    $app->execution()->run(static function () use ($app, $mode, $tunnel): void {
        $factory = $app->make(JsonDispatchResponseFactory::class);
        verify($app->make('foundation.responses') instanceof JsonDispatchResponseFactory, 'Response alias unavailable.');
        $decode = static function ($response): array {
            verify(str_starts_with($response->getHeaderLine('Content-Type'), 'application/vnd.laboratory.jd.v3+json'), 'Vendor media type missing.');
            verify($response->getHeaderLine('X-Api-Version-Selected') === '2.3.4', 'Application version missing.');
            verify(str_contains($response->getHeaderLine('Vary'), 'X-Api-Version'), 'Version variation missing.');
            return json_decode((string) $response->getStringBody(), true, flags: JSON_THROW_ON_ERROR);
        };
        if (in_array($mode, ['offset', 'cursor'], true)) {
            $db = $app->make(DBLayerFactory::class)->connection();
            $db->statement('CREATE TABLE lab_json_provider (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hidden VARCHAR(40) NOT NULL)');
            $rows = [];
            for ($id = 1; $id <= 12; ++$id) {
                $rows[] = ['id' => $id, 'tenant_id' => $id % 2, 'hidden' => 'not-exported'];
            }
            verify($db->table('lab_json_provider')->insert($rows), 'Pagination seed failed.');
            $query = $db->table('lab_json_provider')->where('tenant_id', '=', 0)->orderBy('id');
            $transform = static fn(array $row): array => ['id' => (int) $row['id']];
            if ($mode === 'offset') {
                $page = $query->paginate(2, 2);
                $body = $decode($factory->paginated($page, '/items?page=2', $transform));
                verify($body['data'] === [['id' => 6], ['id' => 8]], 'Offset projection crossed tenant or exposed hidden fields.');
                verify($body['_properties']['/data']['pagination'] === ['mode' => 'offset', 'offset' => 2, 'limit' => 2, 'count' => 2, 'total' => 6], 'Offset metadata disagrees.');
                verify($body['_links']['self'] === '/items?page=2', 'Self link disagrees.');
            } else {
                $first = $query->cursorPaginate(2, null, 'id');
                $body = $decode($factory->paginated($first, '/items?filter=active#top', $transform));
                verify($body['data'] === [['id' => 2], ['id' => 4]], 'First cursor projection differs.');
                $cursor = $body['_properties']['/data']['pagination']['next_cursor'];
                verify($cursor === $first->nextCursor() && $body['_properties']['/data']['pagination']['mode'] === 'cursor', 'Cursor metadata disagrees.');
                $link = parse_url($body['_links']['next']);
                parse_str($link['query'], $params);
                verify($params === ['filter' => 'active', 'cursor' => $cursor] && $link['fragment'] === 'top', 'Cursor link lost filter or fragment.');
                $second = $query->cursorPaginate(2, $cursor, 'id');
                verify($decode($factory->paginated($second, '/items?filter=active', $transform))['data'] === [['id' => 6], ['id' => 8]], 'Cursor continuation differs.');
            }
        } elseif ($mode === 'validation') {
            $db = $app->make(DBLayerFactory::class)->connection();
            $db->statement('CREATE TABLE lab_json_provider (id INTEGER PRIMARY KEY)');
            $db->table('lab_json_provider')->insert(['id' => 1]);
            $validator = $app->make(\Infocyph\Foundation\Validation\ValidatorFactory::class);
            $profile = $validator->compile('lab.json');
            $failure = $profile->validate(['sku' => 1, 'items' => [['quantity' => 0], ['quantity' => 2]]]);
            $response = $factory->failureFromValidation($failure);
            $body = $decode($response);
            verify($response->getStatusCode() === 422 && $body['status_code'] === 422 && $body['status'] === 'fail', 'Validation status lost.');
            verify($body['data'][0]['code'] === 'VALIDATION_FAILED' && $body['data'][0]['source']['pointer'] === '/items/0/quantity', 'Nested validation pointer lost.');
            expectFailure(static fn() => $factory->failureFromValidation($profile->validate(['sku' => 1, 'items' => [['quantity' => 1]]])), InvalidArgumentException::class, 'Success result accepted as failure.');
            // A separate real database read establishes the configured provider's
            // availability in the same execution scope.
            verify($db->table('lab_json_provider')->count() === 1 && $validator instanceof \Infocyph\Foundation\Validation\ValidatorFactory, 'Database/validation provider scope unavailable.');
        } else {
            $issue = new Issue('ORDER_CONFLICT', 'Order conflict', source: ['pointer' => '/id']);
            foreach ([['fail', 409], ['error', 503]] as [$type, $status]) {
                $response = $factory->{$type}([$issue], $status);
                $body = $decode($response);
                verify($body['status'] === $type && $body['status_code'] === $status && $body['data'] === [$issue->jsonSerialize()], 'Semantic error envelope differs.');
                verify($response->getStatusCode() === ($tunnel ? 200 : $status), 'Configured transport status differs.');
                verify($response->getHeaderLine('X-JD-Status-Code') === ($tunnel ? (string) $status : ''), 'Tunnel status header differs.');
                if ($tunnel) {
                    verify($response->getHeaderLine('Cache-Control') === 'no-store', 'Tunneled failure cache policy missing.');
                }
            }
            verify($decode($factory->success(null, 201)) === ['status' => 'success', 'data' => null], 'Null success projection differs.');
            expectFailure(static fn() => $factory->success([], 204), InvalidArgumentException::class, 'Body-bearing 204 accepted.');
            expectFailure(static fn() => $factory->fail([], 422), InvalidArgumentException::class, 'Empty issue list accepted.');
            expectFailure(static fn() => $factory->error([$issue], 409), InvalidArgumentException::class, 'Wrong error status accepted.');
        }
    });
    echo "Foundation JSON provider $mode passed\n";
}

function foundationLoggingProviderContracts(string $mode): void
{
    $path = evidenceDirectory() . '/provider-log.jsonl';
    $config = [
        'logging' => [
            'driver' => 'file', 'level' => 'warning', 'path' => $path, 'redact' => ['authorization', 'token', 'secret'],
            'exceptions' => [
                'include_message' => false, 'include_trace' => false, 'ignore' => [DomainException::class],
                'sample_rate' => $mode === 'sample-zero' ? 0.0 : 1.0, 'throttle_seconds' => 120, 'throttle_limit' => 2,
            ],
        ],
    ];
    $app = foundationProviderApp($config, [], [LoggingServiceProvider::class]);
    $app->execution()->run(static function () use ($app, $path, $mode): void {
        $logger = $app->make(LoggerInterface::class);
        verify($app->make('foundation.logging') instanceof LoggerInterface, 'Logger alias missing.');
        if ($mode === 'concurrent') {
            $results = parallelRace(static function (int $worker) use ($logger): int {
                for ($i = 0; $i < 32; ++$i) {
                    $logger->warning('worker-record', ['id' => "$worker-$i", 'nested' => ['Token' => 'not-for-log']]);
                }
                return 32;
            }, 'foundation-log', 12);
            verify(array_sum($results) === 384, 'Concurrent log worker count differs.');
            $ids = [];
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                verify($row['context']['nested']['Token'] === '[REDACTED]' && $row['message'] === 'worker-record', 'Redaction or concurrent record integrity differs.');
                $id = $row['context']['id'];
                verify(!isset($ids[$id]), 'Duplicate log identity.');
                $ids[$id] = true;
            }
            verify(count($ids) === 384, 'Concurrent append lost records.');
            return;
        }
        $logger->debug('filtered');
        $logger->warning('seed', ['Authorization' => 'not-for-log', 'nested' => ['secret' => 'not-for-log', 'id' => 42]]);
        $reporter = $app->make(ExceptionReporter::class);
        $exception = new RuntimeException('not-for-log');
        for ($i = 0; $i < 4; ++$i) {
            $reporter->report('error', ['exception' => $exception, 'status' => 503]);
        }
        $reporter->report('error', ['exception' => new DomainException('ignored'), 'status' => 503]);
        $app->make(HttpExceptionLogger::class)->error('fixture', ['exception' => $exception, 'status' => 502]);
        $raw = (string) file_get_contents($path);
        verify(!str_contains($raw, 'not-for-log') && !str_contains($raw, 'ignored') && !str_contains($raw, 'filtered'), 'Sensitive or filtered text leaked.');
        $rows = array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        verify(count($rows) === ($mode === 'sample-zero' ? 1 : 4), 'Sampling/throttle/ignore cardinality differs.');
        verify($rows[0]['context']['Authorization'] === '[REDACTED]' && $rows[0]['context']['nested'] === ['secret' => '[REDACTED]', 'id' => 42], 'Nested redaction differs.');
        if ($mode !== 'sample-zero') {
            verify($rows[1]['context']['status'] === 503 && $rows[2]['context']['status'] === 503 && $rows[3]['context']['status'] === 502, 'Exception signature throttle/status boundary differs.');
            verify(!isset($rows[1]['context']['exception']['message']) && !isset($rows[1]['context']['exception']['trace']), 'Exception message/trace unexpectedly enabled.');
        }
    });
    echo "Foundation logging provider $mode passed\n";
}

function foundationPathProviderContracts(bool $auto): void
{
    $root = evidenceDirectory() . '/provider-paths';
    $app = foundationProviderApp([
        'paths' => [
            'auto_create_runtime_directories' => $auto,
            'storage' => $root . '/storage', 'cache' => $root . '/cache',
            'logs' => $root . '/logs', 'sessions' => $root . '/sessions', 'uploads' => $root . '/uploads',
        ],
    ]);
    $paths = $app->make(PathManager::class);
    verify($app->make('foundation.paths') instanceof PathManager && $paths->cache('state.bin') === $root . '/cache/state.bin', 'Path alias or resolution differs.');
    foreach ($paths->runtimeDirectories() as $directory) {
        verify(str_starts_with($directory, $root . '/') && is_dir($directory) === $auto, 'Auto-create policy or root differs.');
    }
    $paths->ensureRuntimeDirectories(0700);
    foreach ($paths->runtimeDirectories() as $directory) {
        verify(is_dir($directory), 'Explicit runtime directory creation failed.');
    }
    $marker = $paths->uploads('marker');
    verify(file_put_contents($marker, 'retained') === 8, 'Path fixture write failed.');
    $paths->ensureRuntimeDirectories(0700);
    verify(file_get_contents($marker) === 'retained', 'Repeated runtime initialization erased stored data.');
    echo "Foundation path provider auto-create policy and idempotent initialization passed\n";
}

function foundationNotificationProviderContracts(string $mode): void
{
    $root = evidenceDirectory() . '/provider-notifications';
    $app = foundationProviderApp([
        'notifications' => [
            'auth' => ['templates' => ['login_alert' => ['subject' => 'Lab login', 'html' => '<b>Lab login</b>']]],
            'email' => [
                'default_sender' => 'lab-spool', 'default_from' => 'system@example.test',
                'senders' => ['lab-spool' => ['transport' => 'lab-spool']],
                'transports' => ['lab-spool' => ['driver' => 'spool', 'directory' => $root . '/outbox', 'writeMetadata' => false]],
                'receivers' => ['spool' => ['default' => [
                    'directory' => $root . '/outbox', 'processingDirectory' => $root . '/processing',
                    'moveAfterRead' => $root . '/received',
                ]]],
            ],
        ],
    ], ['notifications']);
    $app->execution()->run(static function () use ($app, $root, $mode): void {
        $templates = $app->make(NotificationTemplateRegistry::class);
        foreach (AuthNotificationType::cases() as $type) {
            $template = $templates->for($type);
            verify($template['subject'] !== '' && $template['text'] !== '', 'Notification template missing.');
        }
        verify($templates->for(AuthNotificationType::LOGIN_ALERT)['subject'] === 'Lab login' && $templates->for(AuthNotificationType::LOGIN_ALERT)['html'] === '<b>Lab login</b>', 'Configured template override lost.');
        if ($mode === 'templates') {
            return;
        }
        $dispatcher = $app->make(NotificationDispatcher::class);
        verify($app->make('foundation.notifications') instanceof NotificationDispatcher, 'Notification alias missing.');
        $notification = new class implements Notification {
            public function channels(NotificationRecipient $recipient): array
            {
                return ['mail' => EmailMessage::new()->subject('Receipt ready')->text('Order 42 confirmed')];
            }
        };
        $recipient = new class implements NotificationRecipient {
            public function routeNotificationFor(string $channel, Notification $notification): mixed
            {
                return ['buyer@example.test', 'buyer@example.test'];
            }
        };
        $results = $dispatcher->send($recipient, $notification);
        verify(array_keys($results) === ['mail'] && $results['mail']->successful, 'Configured spool channel did not deliver.');
        verify(count(glob($root . '/outbox/*.eml')) === 1, 'Notification produced duplicate or missing spool files.');
        $profiles = $app->make(EmailProfiles::class);
        $receiver = $profiles->spoolReceiver();
        $message = $receiver->peek();
        verify($message !== null && $message->subject === 'Receipt ready' && $message->fromEmail() === 'system@example.test' && $message->to->first()?->email === 'buyer@example.test' && str_contains((string) $message->textBody, 'Order 42 confirmed'), 'Independent message projection differs.');
        verify(count(glob($root . '/outbox/*.eml')) === 1, 'Peek consumed spool message.');
        $claimed = $receiver->receive();
        verify($claimed !== null && $claimed->subject === $message->subject && $receiver->receive() === null, 'Spool consumption was not exactly once.');
        verify(glob($root . '/outbox/*.eml') === [] && count(glob($root . '/received/*.eml')) === 1, 'Spool archive reconciliation differs.');
    });
    echo "Foundation notification provider $mode passed\n";
}
