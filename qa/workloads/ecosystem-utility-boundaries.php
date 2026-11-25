<?php

declare(strict_types=1);

use Infocyph\ArrayKit\Array\DotNotation;
use Infocyph\ArrayKit\Collection\Collection;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\UID\Configuration\RandflakeConfig;
use Infocyph\UID\Configuration\SonyflakeConfig;
use Infocyph\UID\Configuration\TBSLConfig;
use Infocyph\UID\CUID2;
use Infocyph\UID\Enums\RandflakeFormat;
use Infocyph\UID\Enums\SonyflakeFormat;
use Infocyph\UID\Id;
use Infocyph\UID\KSUID;
use Infocyph\UID\NanoID;
use Infocyph\UID\ObjectID;
use Infocyph\UID\Randflake;
use Infocyph\UID\Sonyflake;
use Infocyph\UID\TBSL;
use Infocyph\UID\TypeID;
use Infocyph\UID\XID;

function uidFamiliesWorkload(): void
{
    $secret = random_bytes(16);
    $start = time() - 5;
    $end = time() + 300;
    $families = [
        'cuid2' => [static fn() => Id::cuid2(), CUID2::isValid(...)],
        'nano' => [static fn() => Id::nanoId(), NanoID::isValid(...)],
        'ksuid' => [static fn() => Id::ksuid(), KSUID::isValid(...)],
        'object' => [static fn() => Id::objectId(), ObjectID::isValid(...)],
        'xid' => [Id::xid(...), XID::isValid(...)],
        'type' => [static fn() => Id::typeId('order'), TypeID::isValid(...)],
        'tbsl' => [static fn() => Id::tbsl(new TBSLConfig(machineId: 9)), TBSL::isValid(...)],
        'sony' => [static fn() => Id::sonyflake(new SonyflakeConfig(machineId: 42)), Sonyflake::isValid(...)],
    ];
    foreach ($families as $name => [$generate, $valid]) {
        $seen = [];
        for ($i = 0; $i < 300; ++$i) {
            $id = $generate();
            verify($valid($id) && !isset($seen[$id]), 'Invalid or duplicate ' . $name . ' identifier.');
            $seen[$id] = true;
            if ($name === 'type') {
                verify(TypeID::fromUuid('order', TypeID::toUuid($id)) === $id, 'TypeID UUID round trip failed.');
            }
            if ($name === 'object') {
                verify(ObjectID::fromBytes(ObjectID::toBytes($id)) === $id, 'ObjectID binary round trip failed.');
            }
        }
    }
    foreach ([SonyflakeFormat::UID, SonyflakeFormat::UPSTREAM] as $format) {
        $config = new SonyflakeConfig(machineId: 42, format: $format);
        $id = Id::sonyflake($config);
        verify(Sonyflake::parse($id, $format)['machine_id'] === 42, 'Sonyflake format machine mismatch.');
        verify(Sonyflake::fromBytes(Sonyflake::toBytes($id)) === $id, 'Sonyflake binary round trip failed.');
    }
    foreach ([RandflakeFormat::UID, RandflakeFormat::UPSTREAM] as $format) {
        $config = new RandflakeConfig(nodeId: 42, leaseStart: $start, leaseEnd: $end, secret: $secret, format: $format);
        $seen = [];
        for ($i = 0; $i < 300; ++$i) {
            $id = Id::randflake($config);
            verify(!isset($seen[$id]) && Randflake::parse($id, $secret, $format)['node_id'] === 42, 'Randflake format/node/uniqueness mismatch.');
            verify(Randflake::fromBytes(Randflake::toBytes($id, $format), $format) === $id, 'Randflake binary round trip failed.');
            $seen[$id] = true;
        }
    }
    verify(Id::deterministic('order', 24, 'tenant-a') === Id::deterministic('order', 24, 'tenant-a'), 'Deterministic ID changed.');
    verify(Id::deterministic('order', 24, 'tenant-a') !== Id::deterministic('order', 24, 'tenant-b'), 'Namespace separation failed.');
    verify(preg_match('/^[0-9a-f]{128}$/D', Id::random(128, '0123456789abcdef')) === 1, 'Random alphabet boundary failed.');
    echo "Remaining identifier families, two flake formats, namespace and binary round trips passed\n";
}

function arrayTransformsWorkload(): void
{
    $data = ['service.name' => 'literal', 'service' => ['name' => 'nested', 'enabled' => false], 'users' => []];
    for ($i = 0; $i < 1000; ++$i) {
        $data['users'][] = ['id' => $i, 'group' => $i % 5, 'amount' => $i * 2, 'secret' => 'private'];
    }
    verify(DotNotation::get($data, 'service\.name') === 'literal' && DotNotation::get($data, 'service.name') === 'nested', 'Escaped path collision.');
    DotNotation::fill($data, 'service.enabled', true);
    verify(DotNotation::boolean($data, 'service.enabled') === false, 'Fill replaced false.');
    verify(DotNotation::get($data, 'users.*.id') === range(0, 999), 'Wildcard extraction lost ordering.');
    DotNotation::forget($data, 'users.*.secret');
    verify(DotNotation::get($data, 'users.*.secret', []) === [] || !array_key_exists('secret', $data['users'][999]), 'Wildcard removal failed.');
    $flat = DotNotation::flatten(['service' => ['enabled' => false, 'port' => 5432]]);
    verify(DotNotation::expand($flat) === ['service' => ['enabled' => false, 'port' => 5432]], 'Flatten/expand mismatch.');
    expectFailure(static fn() => DotNotation::integer($data, 'service.name'), InvalidArgumentException::class, 'Typed accessor accepted string.');
    $collection = Collection::make($data['users']);
    $result = $collection->copy()->filter(static fn(array $row): bool => $row['group'] === 2)->map(static fn(array $row): int => $row['amount'])->all();
    verify(count($result) === 200 && array_sum($result) === 199800 && count($collection) === 1000, 'Collection selection or isolation failed.');
    verify(json_decode($collection->toJson(), true, flags: JSON_THROW_ON_ERROR) === $data['users'], 'Collection JSON round trip failed.');
    echo "1000-record transformation, escaped paths, wildcard removal, typed reads and collection isolation passed\n";
}

interface LabEnvironmentService
{
    public function mode(): string;
}

final class LabLiveService implements LabEnvironmentService
{
    public function mode(): string { return 'production'; }
}

final class LabTestService implements LabEnvironmentService
{
    public function mode(): string { return 'test'; }
}

function diEnvironmentWorkload(): void
{
    $root = evidenceDirectory() . '/di-environments';
    verify(mkdir($root, 0700), 'DI directory unavailable.');
    foreach (['production', 'test'] as $environment) {
        $builder = ContainerBuilder::create('environment-lab')
            ->setEnvironment($environment)
            ->autowire(LabEnvironmentService::class, LabLiveService::class)
            ->bindInterfaceForEnv('test', LabEnvironmentService::class, LabTestService::class)
            ->value('disabled', false)
            ->value('zero', 0)
            ->value('nothing', null)
            ->alias('service', LabEnvironmentService::class);
        $path = $root . '/' . $environment . '.php';
        $builder->compile($path, strict: true);
        foreach ([$builder->build(), $builder->production($path)] as $runtime) {
            verify($runtime->get('service')->mode() === $environment, 'Environment binding mismatch.');
            verify($runtime->get('disabled') === false && $runtime->get('zero') === 0 && $runtime->get('nothing') === null, 'Literal value changed.');
            for ($i = 0; $i < 100; ++$i) {
                verify($runtime->invoke(static fn(LabEnvironmentService $service, int $number): string => $service->mode() . ':' . $number, ['number' => $i]) === $environment . ':' . $i, 'Environment-aware invocation failed.');
            }
        }
    }
    echo "Dynamic and compiled environment binding, literal values and typed invocation passed\n";
}
