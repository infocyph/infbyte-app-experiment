<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\ArrayKit\Array\DotNotation;
use Infocyph\ArrayKit\Collection\Collection;
use Infocyph\CacheLayer\Counter\AtomicCounters;
use Infocyph\Epicrypt\DataProtection\ProtectionAlgorithm;
use Infocyph\Epicrypt\DataProtection\ProtectionOptions;
use Infocyph\Epicrypt\DataProtection\StringProtector;
use Infocyph\Epicrypt\Exception\Crypto\DecryptionException;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Security\KeyRingEntry;
use Infocyph\Epicrypt\Security\KeyStatus;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Filesystem\StorageRegistry;
use Infocyph\ReqShield\CompiledValidator;
use Infocyph\ReqShield\Validator;
use Infocyph\UID\Id;

final readonly class DataImport
{
    private CompiledValidator $validator;
    private StringProtector $protector;
    private KeyRing $initial;
    private KeyRing $rotated;

    public function __construct(private Application $app)
    {
        $this->validator = new CompiledValidator(Validator::make([
            'email' => ['rules' => 'required|email', 'sanitize' => ['trim', 'lowercase']],
            'items' => 'required|array',
            'items.*.sku' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1|max:20',
        ])->setCasts(['items.*.quantity' => 'integer'])->stripUnknown());
        $algorithm = ProtectionAlgorithm::XCHACHA20_POLY1305;
        $this->protector = StringProtector::create($algorithm);
        $old = hex2bin((string) getenv('LAB_IMPORT_OLD_KEY'));
        $new = hex2bin((string) getenv('LAB_IMPORT_NEW_KEY'));
        ensure(is_string($old) && strlen($old) === 32 && is_string($new) && strlen($new) === 32, 'Missing independent data protection keys.');
        $this->initial = new KeyRing([new KeyRingEntry('old', $old, KeyStatus::ACTIVE, KeyPurpose::DATA_PROTECTION, $algorithm->value)]);
        $this->rotated = new KeyRing([
            new KeyRingEntry('new', $new, KeyStatus::ACTIVE, KeyPurpose::DATA_PROTECTION, $algorithm->value),
            new KeyRingEntry('old', $old, KeyStatus::FALLBACK, KeyPurpose::DATA_PROTECTION, $algorithm->value),
        ]);
    }

    /** @throws \Throwable */
    public function setup(): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        $db->statement('CREATE TABLE experiment_imports (id VARCHAR(80) PRIMARY KEY, ciphertext TEXT NOT NULL, digest VARCHAR(64) NOT NULL)');
        $db->statement('CREATE TABLE experiment_ids (family VARCHAR(32) NOT NULL, value VARCHAR(128) NOT NULL, PRIMARY KEY (family, value))');
        AtomicCounters::redis('experiment-import')->delete('accepted');
    }

    /** @throws \Throwable */
    public function execute(string $id): void
    {
        $context = $this->app->make(CurrentPrincipalContext::class);
        ensure($context->get() === null, 'Previous import principal leaked into scope.');
        $context->set(new Principal($id, accountId: $id));

        $input = [
            'email' => ' IMPORT-' . $id . '@EXAMPLE.TEST ',
            'items' => [['sku' => 'A', 'quantity' => '2', 'internal_price' => 0], ['sku' => 'B', 'quantity' => '3']],
            'is_admin' => true,
        ];
        $result = $this->validator->validate($input);
        ensure($result->passes(), 'Valid import rejected.');
        $typed = $result->typed();
        ensure(!isset($typed['is_admin'], $typed['items'][0]['internal_price']) && !array_key_exists('is_admin', $typed) && !array_key_exists('internal_price', $typed['items'][0]), 'Unknown field leaked into validated data.');
        ensure(DotNotation::get($typed, 'items.0.quantity') === 2, 'Wildcard cast failed.');
        $bad = $input;
        $bad['items'][1]['quantity'] = 0;
        ensure($this->validator->validate($bad)->fails(), 'Invalid nested quantity accepted.');
        ensure($this->validator->validate($input)->passes(), 'Compiled validator retained prior request failure.');
        $record = new ImportRecord()->hydrateNestedGuarded($typed, maxDepth: 8, maxNodes: 64);
        ensure($record->email === 'import-' . $id . '@example.test', 'Sanitized DTO email mismatch.');
        $data = new Collection($record->toArrayDeepGuarded(maxDepth: 8, maxNodes: 64));
        ensure($data->get('items.1.quantity') === 3, 'Nested DTO/collection mismatch.');
        $plain = json_encode($data->all(), JSON_THROW_ON_ERROR);
        $options = new ProtectionOptions(purpose: 'experiment/import/v1', additionalAuthenticatedData: $id);
        $encrypted = $this->protector->protectWithKeyRing($plain, $this->initial, $options);

        $db = $this->app->make(DBLayerFactory::class)->connection();
        $db->transaction(function () use ($db, $id, $encrypted, $plain): void {
            $db->statement('INSERT INTO experiment_imports (id, ciphertext, digest) VALUES (?, ?, ?)', [$id, $encrypted, hash('sha256', $plain)]);
            foreach ([
                'uuid7' => Id::uuid7(), 'uuid4' => Id::uuid4(), 'ulid' => Id::ulid(), 'typeid' => Id::typeId('import'),
                'objectid' => Id::objectId(), 'snowflake' => Id::snowflake(), 'sonyflake' => Id::sonyflake(), 'tbsl' => Id::tbsl(),
                'ksuid' => Id::ksuid(), 'xid' => Id::xid(), 'nanoid' => Id::nanoId(), 'cuid2' => Id::cuid2(),
                'random' => Id::random(32), 'deterministic' => Id::deterministic($id, 32, 'import'),
            ] as $family => $value) {
                ensure($value !== '', 'Empty application identifier.');
                $db->statement('INSERT INTO experiment_ids (family, value) VALUES (?, ?)', [$family, $value]);
            }
        });
        $stored = $db->scalar('SELECT ciphertext FROM experiment_imports WHERE id = ?', [$id]);
        ensure(is_string($stored) && $stored !== $plain, 'Plaintext persisted.');
        $read = $this->protector->unprotectWithKeyRing($stored, $this->rotated, $options);
        ensure($read->keyId === 'old' && hash_equals($plain, $read->value), 'Fallback key failed.');
        $renewed = $this->protector->protectWithKeyRing($read->value, $this->rotated, $options);
        $db->statement('UPDATE experiment_imports SET ciphertext = ? WHERE id = ?', [$renewed, $id]);
        $disk = $this->app->make(StorageRegistry::class)->disk('local');
        $path = 'experiment/imports/' . $id . '.ep2';
        $disk->write($path, $renewed);
        $confirmed = $this->protector->unprotectWithKeyRing($disk->read($path), $this->rotated, $options);
        ensure($confirmed->keyId === 'new' && hash_equals($plain, $confirmed->value), 'Rotated protected file failed.');
        try {
            $this->protector->unprotectWithKeyRing($renewed, $this->rotated, new ProtectionOptions(purpose: 'experiment/import/v1', additionalAuthenticatedData: 'foreign-' . $id));
            throw new \RuntimeException('Foreign record context accepted.');
        } catch (DecryptionException) {
            // Authenticated data is bound to the original import, not the supplied record.
        }
        AtomicCounters::redis('experiment-import')->increment('accepted', ttlSeconds: 3600);
        ensure($context->get()?->accountId() === $id, 'Current scope identity changed.');
    }

    /** @throws \Throwable */
    public function verify(int $expected): void
    {
        $db = $this->app->make(DBLayerFactory::class)->connection();
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM experiment_imports')) === $expected, 'Import count mismatch.');
        ensure(countValue($db->scalar('SELECT COUNT(*) FROM experiment_ids')) === $expected * 14, 'Identifier uniqueness/count mismatch.');
        ensure(AtomicCounters::redis('experiment-import')->get('accepted')?->value === $expected, 'Atomic counter mismatch.');
        $disk = $this->app->make(StorageRegistry::class)->disk('local');
        foreach ($db->select('SELECT id, ciphertext, digest FROM experiment_imports') as $row) {
            $options = new ProtectionOptions(purpose: 'experiment/import/v1', additionalAuthenticatedData: $row['id']);
            $read = $this->protector->unprotectWithKeyRing($row['ciphertext'], $this->rotated, $options);
            ensure($read->keyId === 'new' && hash_equals($row['digest'], hash('sha256', $read->value)), 'Stored record authentication mismatch.');
            ensure($disk->read('experiment/imports/' . $row['id'] . '.ep2') === $row['ciphertext'], 'SQL/file protected record divergence.');
        }
    }
}
