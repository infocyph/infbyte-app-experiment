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

function validationSanitizersWorkload(): void
{
    $cases = [
        ['alpha', 'A1-b!', 'Ab'],
        ['alphaDash', 'A1-b_!', 'A1-b_'],
        ['alphanumeric', 'A1-b_!', 'A1b'],
        ['alphanumericSpace', 'A1 b_!', 'A1 b'],
        ['numeric', 'Order 001-23', '00123'],
        ['array', ['x' => [' <b>one</b> ', 0, false, null]], ['x' => ['one', 0, false, null]]],
        ['base64Decode', '%%%not-base64', ''],
        ['base64Encode', "\x00\xff", 'AP8='],
        ['boolean', ' YES ', true],
        ['boolean', 'unknown', false],
        ['camelCase', 'Order total', 'orderTotal'],
        ['pascalCase', 'Order total', 'OrderTotal'],
        ['kebabCase', 'Order total', 'order-total'],
        ['snakeCase', 'Order total', 'order_total'],
        ['titleCase', 'order total', 'Order Total'],
        ['sentenceCase', 'oRDER TOTAL', 'Order total'],
        ['currency', '$1,234.50', 1234.5],
        ['formatCurrency', 1234.5, '$1,234.50'],
        ['domain', 'https://SHOP.EXAMPLE.TEST/path', 'shop.example.test'],
        ['email', ' a @example.test ', 'a@example.test'],
        ['escapeLike', 'a%b_c', 'a\\%b\\_c'],
        ['filename', "../a b\0.txt", 'a_b.txt'],
        ['float', 'USD 12.50', 12.5],
        ['integer', 'USD -12', -12],
        ['htmlEncode', '<b>&</b>', '&lt;b&gt;&amp;&lt;/b&gt;'],
        ['htmlDecode', '&lt;b&gt;&amp;&lt;/b&gt;', '<b>&</b>'],
        ['jsonDecode', '{"zero":0,"false":false,"nil":null}', ['zero' => 0, 'false' => false, 'nil' => null]],
        ['jsonDecode', '{bad', []],
        ['jsonEncode', ['path' => '/orders', 'zero' => 0], '{"path":"/orders","zero":0}'],
        ['lowercase', 'ÉCOLE', 'école'],
        ['uppercase', 'école', 'ÉCOLE'],
        ['normalizeWhitespace', " a\t b\n c ", 'a b c'],
        ['stripWhitespace', " a\t b\n c ", 'abc'],
        ['removeLineBreaks', "a\r\nb\rc\nd", 'a b c d'],
        ['phone', '+1 (202) 555-0100', '+12025550100'],
        ['slug', 'Order #42 / Paid', 'order-42-paid'],
        ['string', ' <b>Order</b> ', 'Order'],
        ['stripTags', '<b>Order</b>', 'Order'],
        ['trim', '  Order  ', 'Order'],
        ['url', 'https://example.test/a b', 'https://example.test/ab'],
    ];
    foreach ($cases as [$method, $input, $expected]) {
        verify(Sanitizer::{$method}($input) === $expected, 'Direct sanitizer mismatch: ' . $method);
        verify(Sanitizer::apply($input, [$method]) === $expected, 'Named pipeline mismatch: ' . $method);
        verify(Sanitizer::applyCompiled($input, Sanitizer::compile([$method])) === $expected, 'Compiled pipeline mismatch: ' . $method);
    }
    verify(Sanitizer::currency('EUR 1.234,50', 'EUR') === 1234.5, 'European currency normalization failed.');
    verify(Sanitizer::formatCurrency(1234.5, 'EUR', 2) === '€1.234,50', 'European currency projection failed.');
    verify(Sanitizer::slug('Order #42 / Paid', '_') === 'order_42_paid', 'Custom slug separator failed.');
    verify(Sanitizer::stripTags('<b>x</b><i>y</i>', ['b']) === '<b>x</b>y', 'Allowed-tag option failed.');
    verify(Sanitizer::truncate('école', 3, '!') === 'éco!', 'Multibyte truncation failed.');
    verify(Sanitizer::truncateWords('one two three', 2, '!') === 'one two!', 'Word truncation failed.');
    verify(Sanitizer::jsonDecode('{bad', false) === null, 'Invalid object JSON did not return null.');
    $object = new stdClass();
    $object->self = $object;
    verify(Sanitizer::jsonEncode($object) === '', 'Recursive JSON did not fail safely.');
    verify(Sanitizer::batch([' A ', ' B '], 'trim') === ['A', 'B'], 'Batch sanitizer failed.');
    $first = Sanitizer::compile(['trim', 'lowercase']);
    for ($i = 0; $i < 300; ++$i) {
        $pipeline = [];
        for ($bit = 0; $bit < 9; ++$bit) {
            $pipeline[] = ($i & (1 << $bit)) !== 0 ? 'trim' : 'lowercase';
        }
        $pipeline[] = 'trim';
        $pipeline[] = 'lowercase';
        verify(Sanitizer::apply(' A ', $pipeline) === 'a', 'Pipeline cache churn changed semantics.');
    }
    Sanitizer::clearCache();
    verify(Sanitizer::applyCompiled(' B ', $first) === 'b', 'Clearing cache invalidated an existing compiled pipeline.');
    expectFailure(static fn() => Sanitizer::compile(['lab_nonexistent_sanitizer']), InvalidArgumentException::class, 'Unknown pipeline sanitizer accepted.');

    $builder = Validator::make(['groups.*.members.*.code' => 'required|string'])
        ->setSanitizers([
            'groups.0.members.0.code' => [static fn(string $value): string => $value . '-direct'],
            'groups.*.members.*.code' => ['trim', 'lowercase'],
        ]);
    $data = ['groups' => [
        ['members' => [['code' => ' A'], ['code' => ' B ']]],
        ['members' => [['code' => ' C ']]],
    ]];
    $expected = ['groups' => [
        ['members' => [['code' => 'a-direct'], ['code' => 'b']]],
        ['members' => [['code' => 'c']]],
    ]];
    foreach ([$builder, new CompiledValidator($builder)] as $validator) {
        $result = $validator->validate($data);
        verify($result->passes() && $result->safe() === $expected, 'Nested direct/wildcard sanitizer composition failed.');
        verify($data['groups'][0]['members'][0]['code'] === ' A', 'Sanitization mutated caller-owned input.');
        verify($validator->validate($data)->safe() === $expected, 'Nested sanitizer state leaked between validations.');
    }
    echo count($cases) . " sanitizer input contracts, optional parameters, pipeline churn and nested overlap passed\n";
}
