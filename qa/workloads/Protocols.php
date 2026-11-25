<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Communication\CommunicationProfiles;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Communication\CacheLayerWebhookReplayStore;
use Infocyph\TalkingBytes\Webhook\Webhook;
use Infocyph\TalkingBytes\Webhook\WebhookMessage;

final readonly class Protocols
{
    public function __construct(private Application $app) {}

    public function setup(): void {}

    /** @throws \Throwable */
    public function execute(string $id): void
    {
        $profiles = $this->app->make(CommunicationProfiles::class);
        $http = $profiles->http();
        $url = 'http://127.0.0.1:18888';
        $result = $http->get($url . '/health');
        ensure($result->successful && $result->statusCode === 200, 'Native HTTP request failed.');
        $secret = getenv('AUTH_TOKEN_SECRET');
        ensure(is_string($secret), 'Missing disposable webhook signing key.');
        $message = WebhookMessage::event('orders.created')->deliveryId(hash('sha256', $id))->url($url . '/hook')->payload(['order' => $id]);
        $sender = Webhook::sender($http)->withSecret($secret);
        $delivery = $sender->send($message);
        ensure($delivery->delivery?->delivered === true, 'Signed webhook was not received: ' . json_encode($delivery, JSON_THROW_ON_ERROR));
        ensure($sender->send($message)->delivery?->delivered === false, 'Duplicate webhook accepted.');
        $cache = $this->app->make(CacheManager::class)->store('shared');
        ensure($cache->get('webhook-received.' . $id) === $id, 'Receiver did not persist verified effect.');
    }

    /** @throws \Throwable */
    public function verify(int $expected): void
    {
        $cache = $this->app->make(CacheManager::class)->store('shared');
        $atomic = $cache->atomic();
        ensure($atomic !== null, 'Atomic counter resource unavailable.');
        ensure(countValue($cache->get('webhook-count', 0)) === $expected, 'Verified webhook receipt count mismatch.');
    }
}
