<?php

declare(strict_types=1);

use Infocyph\ReqShield\CompiledValidator;
use Infocyph\ReqShield\Exceptions\CastException;
use Infocyph\ReqShield\Sanitizer;
use Infocyph\ReqShield\Validator;

function validationCastsWorkload(): void
{
    $schema = [
        'quantity' => 'required|string', 'enabled' => 'required|string',
        'price' => 'required|string', 'created' => 'required|string',
        'state' => 'required|string', 'meta' => 'required|string',
        'contacts.*.email' => 'required|email', 'contacts.*.quantity' => 'required|string',
    ];
    $builder = Validator::make($schema)
        ->setSanitizers(['contacts.*.email' => ['trim', 'lowercase']])
        ->setCasts([
            'quantity' => 'integer', 'enabled' => 'boolean', 'price' => 'float',
            'created' => 'datetime', 'state' => LabValidationStatus::class, 'meta' => 'json',
            'contacts.*.quantity' => 'integer',
        ])->stripUnknown();
    $data = [
        'quantity' => '0', 'enabled' => 'off', 'price' => '12.50',
        'created' => '2026-01-01T00:00:00+00:00', 'state' => 'paid', 'meta' => '{"count":2}',
        'contacts' => [
            ['email' => ' A@EXAMPLE.TEST ', 'quantity' => '2', 'admin' => true],
            ['email' => ' B@EXAMPLE.TEST ', 'quantity' => '3'],
        ], 'admin' => true,
    ];
    foreach ([$builder, new CompiledValidator($builder)] as $validator) {
        for ($i = 0; $i < 40; ++$i) {
            $result = $validator->validate($data);
            verify($result->passes(), 'Valid cast payload rejected.');
            $typed = $result->typed();
            verify($typed['quantity'] === 0 && $typed['enabled'] === false && $typed['price'] === 12.5
                && $typed['created'] instanceof DateTimeImmutable && $typed['created']->format('Y-m-d') === '2026-01-01'
                && $typed['state'] === LabValidationStatus::Paid && $typed['meta'] === ['count' => 2], 'Scalar cast mismatch.');
            verify($typed['contacts'] === [
                ['email' => 'a@example.test', 'quantity' => 2],
                ['email' => 'b@example.test', 'quantity' => 3],
            ] && !isset($typed['admin']), 'Wildcard normalization/cast or unknown-field isolation failed.');
            verify($result->safe()['quantity'] === '0', 'Typed projection mutated validated input.');
        }
        foreach (['quantity' => '9223372036854775808', 'enabled' => 'perhaps', 'price' => '1e9999', 'created' => 'not-a-date', 'state' => 'unknown', 'meta' => '{bad'] as $field => $value) {
            $invalid = $data;
            $invalid[$field] = $value;
            expectFailure(static fn() => $validator->validate($invalid)->typed(), CastException::class, 'Invalid cast was accepted: ' . $field);
        }
        verify($validator->validate($data)->typed()['quantity'] === 0, 'Cast failure contaminated next validation.');
    }
    $payload = "\x00\xfftest";
    verify(Sanitizer::base64Decode(Sanitizer::base64Encode($payload)) === $payload, 'Binary base64 normalization failed.');
    verify(Sanitizer::htmlDecode(Sanitizer::htmlEncode('<b>&</b>')) === '<b>&</b>', 'HTML utility roundtrip failed.');
    $pipeline = Sanitizer::compile(['trim', 'lowercase']);
    verify(Sanitizer::applyCompiled(' A@EXAMPLE.TEST ', $pipeline) === 'a@example.test', 'Compiled sanitizer pipeline mismatch.');
    Sanitizer::clearCache();
    verify(Sanitizer::apply(' B@EXAMPLE.TEST ', ['trim', 'lowercase']) === 'b@example.test', 'Sanitizer cache replacement failed.');
    echo "Compiled/interpreted wildcard sanitizers, scalar/enum/date/JSON casts, rejected values and isolation passed\n";
}
