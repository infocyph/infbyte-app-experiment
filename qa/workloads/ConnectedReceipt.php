<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Epicrypt\DataProtection\ProtectionAlgorithm;
use Infocyph\Epicrypt\DataProtection\ProtectionOptions;
use Infocyph\Epicrypt\DataProtection\StringProtector;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Security\KeyRingEntry;
use Infocyph\Epicrypt\Security\KeyStatus;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Filesystem\StorageRegistry;

final readonly class ConnectedReceipt
{
    private StringProtector $protector;
    private KeyRing $keys;

    public function __construct(private Application $app)
    {
        $algorithm = ProtectionAlgorithm::XCHACHA20_POLY1305;
        $key = hex2bin((string) getenv('LAB_CONNECTED_KEY'));
        ensure(is_string($key) && strlen($key) === 32, 'Missing disposable receipt key.');
        $this->protector = StringProtector::create($algorithm);
        $this->keys = new KeyRing([new KeyRingEntry('receipt', $key, KeyStatus::ACTIVE, KeyPurpose::DATA_PROTECTION, $algorithm->value)]);
    }

    public function __invoke(Receipt $message): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $db->transaction(function () use ($db, $message): void {
            $row = $db->select('SELECT quantity, notified FROM lab_connected_orders WHERE id = ? FOR UPDATE', [$message->id])[0];
            if ((int) $row['notified'] === 1) {
                return;
            }
            $plain = $this->document($message->id, (int) $row['quantity']);
            $encrypted = $this->protector->protectWithKeyRing($plain, $this->keys, $this->options($message->id));
            $disk = $this->app->make(StorageRegistry::class)->disk('local');
            $disk->write('experiment/connected/' . $message->id . '.ep2', $encrypted);
            $this->verifyDocument($message->id, (int) $row['quantity']);
            new Mail($this->app)->execute($message->id);
            $db->statement('UPDATE lab_connected_orders SET notified = 1 WHERE id = ?', [$message->id]);
            ensure($this->app->make(CacheManager::class)->store('shared')->set('connected.' . $message->id, hash('sha256', $plain), 3600), 'Receipt cache publication failed.');
        });
    }

    public function verifyDocument(string $id, int $quantity): void
    {
        $plain = $this->document($id, $quantity);
        $encrypted = $this->app->make(StorageRegistry::class)->disk('local')->read('experiment/connected/' . $id . '.ep2');
        ensure($encrypted !== $plain, 'Plaintext receipt persisted.');
        ensure(hash_equals($plain, $this->protector->unprotectWithKeyRing($encrypted, $this->keys, $this->options($id))->value), 'Receipt integrity failed.');
    }

    private function options(string $id): ProtectionOptions
    {
        return new ProtectionOptions(purpose: 'experiment/connected-receipt/v1', additionalAuthenticatedData: $id);
    }

    private function document(string $id, int $quantity): string
    {
        return json_encode(['order' => $id, 'quantity' => $quantity], JSON_THROW_ON_ERROR);
    }
}
