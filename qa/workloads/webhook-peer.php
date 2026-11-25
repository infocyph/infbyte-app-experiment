<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Communication\CacheLayerWebhookReplayStore;
use Infocyph\Foundation\Communication\CommunicationProfiles;
use Infocyph\TalkingBytes\Webhook\Support\WebhookHeaders;

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');
if (($_SERVER['REQUEST_URI'] ?? '') === '/health') {
    echo '{"ok":true}';

    return;
}
if (($_SERVER['REQUEST_URI'] ?? '') !== '/hook' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(404);
    echo '{"ok":false}';

    return;
}
$app = application();
$app->execution()->run(static function () use ($app): void {
    $secret = getenv('AUTH_TOKEN_SECRET');
    ensure(is_string($secret), 'Missing loopback signing secret.');
    $body = file_get_contents('php://input');
    ensure(is_string($body), 'Missing request payload.');
    $headers = [];
    foreach ([WebhookHeaders::SIGNATURE, WebhookHeaders::TIMESTAMP, WebhookHeaders::EVENT, WebhookHeaders::DELIVERY] as $header) {
        $headers[$header] = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $header))] ?? '';
    }
    $cache = $app->make(CacheManager::class)->store('shared');
    $receiver = $app->make(CommunicationProfiles::class)->webhookReceiver(replayStore: new CacheLayerWebhookReplayStore($cache));
    try {
        $event = $receiver->receive($body, $headers);
    } catch (\RuntimeException $failure) {
        if (!str_contains($failure->getMessage(), 'already been processed') && !str_contains($failure->getMessage(), 'Webhook verification failed:')) {
            throw $failure;
        }
        http_response_code(409);
        echo '{"ok":false}';

        return;
    }
    $id = $event->payload['order'] ?? null;
    ensure(is_string($id), 'Verified webhook lacks order identity.');
    ensure($cache->set('webhook-received.' . $id, $id, 3600), 'Webhook effect write failed.');
    // The single loopback fixture serializes receipt counting. The replay claim
    // itself is native distributed Redis and also prevents concurrent duplicates.
    ensure($cache->set('webhook-count', countValue($cache->get('webhook-count', 0)) + 1, 3600), 'Webhook count write failed.');
    echo '{"ok":true}';
});
