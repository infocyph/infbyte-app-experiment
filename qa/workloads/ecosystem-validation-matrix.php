<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\ReqShield\Bridge\DBLayerDatabaseProvider;
use Infocyph\ReqShield\CompiledValidator;
use Infocyph\ReqShield\Enums\BuiltinRule;
use Infocyph\ReqShield\Exceptions\DatabaseProviderRequiredException;
use Infocyph\ReqShield\Exceptions\DatabaseValidationException;
use Infocyph\ReqShield\Rule;
use Infocyph\ReqShield\Rules\Callback;
use Infocyph\ReqShield\Rules\CurrentPassword;
use Infocyph\ReqShield\Validator;

enum LabValidationStatus: string
{
    case Paid = 'paid';
}

/** @param string|array $rule */
function validationPair(string $name, string|array $rule, array $positive, array $negative): void
{
    $builder = Validator::make(['v' => $rule]);
    foreach ([$builder, new CompiledValidator($builder)] as $validator) {
        verify($validator->validate($positive)->passes(), "Positive $name input rejected.");
        verify($validator->validate($negative)->fails(), "Negative $name input accepted.");
        verify($validator->validate($positive)->passes(), "Repeated $name input inherited failure.");
    }
}

function validationRulesWorkload(): void
{
    $cases = [
        ['required', 'required', ['v' => 'x'], ['v' => '']],
        ['filled', 'filled', ['v' => 'x'], ['v' => '']],
        ['string', 'string', ['v' => 'x'], ['v' => 2]],
        ['integer', 'integer', ['v' => '2'], ['v' => '2.5']],
        ['numeric', 'numeric', ['v' => '2.5'], ['v' => 'x']],
        ['boolean', 'boolean', ['v' => true], ['v' => 'maybe']],
        ['array', 'array', ['v' => [1]], ['v' => 'x']],
        ['nullable', 'nullable|string', ['v' => null], ['v' => 2]],
        ['email', 'email', ['v' => 'customer@example.test'], ['v' => 'bad']],
        ['url', 'url', ['v' => 'https://example.test/path'], ['v' => 'not a url']],
        ['active_url', 'active_url', ['v' => 'https://example.com'], ['v' => 'https://does-not-exist.invalid']],
        ['ip', 'ip:v4,private', ['v' => '10.1.2.3'], ['v' => '8.8.8.8']],
        ['json', 'json', ['v' => '{"a":1}'], ['v' => '{bad']],
        ['uuid', 'uuid:4', ['v' => '550e8400-e29b-41d4-a716-446655440000'], ['v' => 'invalid']],
        ['ulid', 'ulid', ['v' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'], ['v' => 'invalid']],
        ['mac', 'mac', ['v' => '00:1A:2B:3C:4D:5E'], ['v' => 'bad']],
        ['hex_color', 'hex_color', ['v' => '#123abc'], ['v' => 'blue']],
        ['timezone', 'timezone', ['v' => 'Asia/Dhaka'], ['v' => 'not/a-zone']],
        ['enum', 'enum:LabValidationStatus', ['v' => 'paid'], ['v' => 'unknown']],
        ['alpha', 'alpha', ['v' => 'hello'], ['v' => 'hello1']],
        ['alpha_num', 'alpha_num', ['v' => 'hello1'], ['v' => 'hello-1']],
        ['alpha_dash', 'alpha_dash', ['v' => 'hello_1'], ['v' => 'hello 1']],
        ['ascii', 'ascii', ['v' => 'ASCII'], ['v' => 'é']],
        ['lowercase', 'lowercase', ['v' => 'lower'], ['v' => 'UPPER']],
        ['uppercase', 'uppercase', ['v' => 'UPPER'], ['v' => 'lower']],
        ['starts_with', 'starts_with:ab,cd', ['v' => 'abcd'], ['v' => 'zabc']],
        ['ends_with', 'ends_with:.pdf,.txt', ['v' => 'report.pdf'], ['v' => 'report.exe']],
        ['contains', 'contains:urgent', ['v' => 'urgent note'], ['v' => 'note']],
        ['doesnt_contain', 'doesnt_contain:admin,root', ['v' => 'member'], ['v' => 'admin-user']],
        ['doesnt_start_with', 'doesnt_start_with:admin,test', ['v' => 'member'], ['v' => 'admin-user']],
        ['doesnt_end_with', 'doesnt_end_with:.exe,.bat', ['v' => 'report.txt'], ['v' => 'report.exe']],
        ['min', 'integer|min:3', ['v' => 3], ['v' => 2]],
        ['max', 'integer|max:3', ['v' => 3], ['v' => 4]],
        ['between', 'integer|between:2,4', ['v' => 3], ['v' => 5]],
        ['size', 'string|size:3', ['v' => 'abc'], ['v' => 'abcd']],
        ['digits', 'digits:4', ['v' => '0012'], ['v' => '12e2']],
        ['digits_between', 'digits_between:3,5', ['v' => '0012'], ['v' => '01']],
        ['min_digits', 'min_digits:3', ['v' => '001'], ['v' => '01']],
        ['max_digits', 'max_digits:3', ['v' => '001'], ['v' => '0001']],
        ['decimal', 'decimal:2', ['v' => '19.99'], ['v' => '19.9']],
        ['multiple_of', 'multiple_of:5', ['v' => 10], ['v' => 11]],
        ['date', 'date', ['v' => '2025-06-01'], ['v' => 'not-a-date']],
        ['date_format', 'date_format:Y-m-d', ['v' => '2025-06-01'], ['v' => '01/06/2025']],
        ['date_equals', 'date_equals:2025-01-01', ['v' => '2025-01-01'], ['v' => '2025-01-02']],
        ['before', 'before:2025-12-31', ['v' => '2025-01-01'], ['v' => '2026-01-01']],
        ['before_or_equal', 'before_or_equal:2025-12-31', ['v' => '2025-12-31'], ['v' => '2026-01-01']],
        ['after', 'after:2025-01-01', ['v' => '2025-01-02'], ['v' => '2024-12-31']],
        ['after_or_equal', 'after_or_equal:2025-01-01', ['v' => '2025-01-01'], ['v' => '2024-12-31']],
        ['required_array_keys', 'required_array_keys:a,b', ['v' => ['a' => 1, 'b' => 2]], ['v' => ['a' => 1]]],
        ['path', 'path:relative', ['v' => 'reports/file.txt'], ['v' => '/reports/file.txt']],
        ['safe_filename', 'safe_filename', ['v' => 'invoice.pdf'], ['v' => '../invoice.pdf']],
        ['upload_id', 'upload_id:32', ['v' => 'chunk-001'], ['v' => '../chunk']],
        ['in', 'in:pending,paid', ['v' => 'paid'], ['v' => 'unknown']],
        ['not_in', 'not_in:admin,root', ['v' => 'member'], ['v' => 'root']],
        ['distinct', 'array|distinct', ['v' => ['a', 'b']], ['v' => ['a', 'a']]],
        ['is_list', 'is_list', ['v' => ['a', 'b']], ['v' => ['a' => 'a']]],
        ['regex', 'regex:/^[A-Z]{3}$/', ['v' => 'ABC'], ['v' => 'abc']],
        ['not_regex', 'not_regex:/[^A-Za-z0-9_]/', ['v' => 'ab_1'], ['v' => 'ab!']],
        ['accepted', 'accepted', ['v' => 'yes'], ['v' => 'no']],
        ['declined', 'declined', ['v' => 'no'], ['v' => 'yes']],
        ['present', 'present', ['v' => null], []],
        ['gt', 'gt:peer', ['v' => 3, 'peer' => 2], ['v' => 2, 'peer' => 2]],
        ['gte', 'gte:peer', ['v' => 2, 'peer' => 2], ['v' => 1, 'peer' => 2]],
        ['lt', 'lt:peer', ['v' => 1, 'peer' => 2], ['v' => 2, 'peer' => 2]],
        ['lte', 'lte:peer', ['v' => 2, 'peer' => 2], ['v' => 3, 'peer' => 2]],
        ['required_if', 'required_if:peer,yes', ['peer' => 'yes', 'v' => 'x'], ['peer' => 'yes']],
        ['required_unless', 'required_unless:peer,yes', ['peer' => 'no', 'v' => 'x'], ['peer' => 'no']],
        ['required_with', 'required_with:peer,other', ['peer' => 'x', 'v' => 'x'], ['peer' => 'x']],
        ['required_with_all', 'required_with_all:peer,other', ['peer' => 'x', 'other' => 'x', 'v' => 'x'], ['peer' => 'x', 'other' => 'x']],
        ['required_without', 'required_without:peer,other', ['peer' => 'x', 'v' => 'x'], ['peer' => 'x']],
        ['required_without_all', 'required_without_all:peer,other', ['v' => 'x'], []],
        ['required_if_accepted', 'required_if_accepted:peer', ['peer' => 'yes', 'v' => 'x'], ['peer' => 'yes']],
        ['required_if_declined', 'required_if_declined:peer', ['peer' => 'no', 'v' => 'x'], ['peer' => 'no']],
        ['present_if', 'present_if:peer,yes', ['peer' => 'yes', 'v' => null], ['peer' => 'yes']],
        ['present_unless', 'present_unless:peer,yes', ['peer' => 'no', 'v' => null], ['peer' => 'no']],
        ['present_with', 'present_with:peer', ['peer' => 'x', 'v' => null], ['peer' => 'x']],
        ['present_with_all', 'present_with_all:peer,other', ['peer' => 'x', 'other' => 'x', 'v' => null], ['peer' => 'x', 'other' => 'x']],
        ['missing', 'missing', [], ['v' => 'x']],
        ['missing_if', 'missing_if:peer,yes', ['peer' => 'yes'], ['peer' => 'yes', 'v' => 'x']],
        ['missing_unless', 'missing_unless:peer,yes', ['peer' => 'no'], ['peer' => 'no', 'v' => 'x']],
        ['prohibited', 'prohibited', [], ['v' => 'x']],
        ['prohibited_if', 'prohibited_if:peer,yes', ['peer' => 'yes'], ['peer' => 'yes', 'v' => 'x']],
        ['prohibited_unless', 'prohibited_unless:peer,yes', ['peer' => 'no'], ['peer' => 'no', 'v' => 'x']],
        ['prohibits', 'prohibits:peer', ['v' => 'x'], ['v' => 'x', 'peer' => 'x']],
        ['in_array', 'in_array:peer', ['v' => 'a', 'peer' => ['a', 'b']], ['v' => 'c', 'peer' => ['a', 'b']]],
        ['same', 'same:peer', ['v' => 'x', 'peer' => 'x'], ['v' => 'y', 'peer' => 'x']],
        ['different', 'different:peer', ['v' => 'y', 'peer' => 'x'], ['v' => 'x', 'peer' => 'x']],
        ['confirmed', 'confirmed', ['v' => 'x', 'v_confirmation' => 'x'], ['v' => 'x', 'v_confirmation' => 'y']],
        ['accepted_if', 'accepted_if:peer,yes', ['peer' => 'yes', 'v' => 'yes'], ['peer' => 'yes', 'v' => 'no']],
        ['declined_if', 'declined_if:peer,yes', ['peer' => 'yes', 'v' => 'no'], ['peer' => 'yes', 'v' => 'yes']],
    ];
    $covered = [];
    foreach ($cases as [$name, $rule, $positive, $negative]) {
        validationPair($name, $rule, $positive, $negative);
        $covered[] = $name;
    }
    $exclusions = [
        ['exclude', 'exclude', [], null],
        ['exclude_if', 'exclude_if:peer,yes', ['peer' => 'yes'], ['peer' => 'no']],
        ['exclude_unless', 'exclude_unless:peer,yes', ['peer' => 'no'], ['peer' => 'yes']],
        ['exclude_with', 'exclude_with:peer', ['peer' => 'x'], []],
        ['exclude_without', 'exclude_without:peer', [], ['peer' => 'x']],
    ];
    foreach ($exclusions as [$name, $rule, $hidden, $visible]) {
        $builder = Validator::make(['v' => $rule]);
        foreach ([$builder, new CompiledValidator($builder)] as $validator) {
            $result = $validator->validate(['v' => 'value', ...$hidden]);
            verify($result->passes() && !array_key_exists('v', $result->safe()), "$name did not exclude.");
            if ($visible !== null) {
                $result = $validator->validate(['v' => 'value', ...$visible]);
                verify($result->passes() && ($result->safe()['v'] ?? null) === 'value', "$name wrongly excluded.");
            }
        }
        $covered[] = $name;
    }

    $root = evidenceDirectory() . '/validation-fixture';
    verify(mkdir($root, 0700), 'Upload fixture directory unavailable.');
    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK3sAAAAASUVORK5CYII=', true);
    verify(is_string($image) && file_put_contents($root . '/pixel.png', $image) !== false, 'Image fixture unavailable.');
    file_put_contents($root . '/not-image.txt', 'ordinary text');
    $valid = ['name' => 'pixel.png', 'type' => 'image/png', 'tmp_name' => $root . '/pixel.png', 'error' => UPLOAD_ERR_OK, 'size' => strlen($image)];
    $spoof = array_replace($valid, ['tmp_name' => $root . '/not-image.txt', 'size' => 13]);
    $failed = array_replace($valid, ['error' => UPLOAD_ERR_PARTIAL]);
    $badName = array_replace($valid, ['name' => '../pixel.png']);
    foreach ([
        ['file', 'file', $failed],
        ['image', 'image', $spoof],
        ['mimes', 'mimes:png', $spoof],
        ['mimetypes', 'mimetypes:image/png', $spoof],
        ['extensions', 'extensions:png', array_replace($valid, ['name' => 'pixel.exe'])],
        ['dimensions', 'dimensions:1,1,1,1', $spoof],
        ['upload_meta', 'upload_meta:success', $badName],
        ['secure_file', 'secure_file:success,255', $failed],
    ] as [$name, $rule, $negative]) {
        validationPair($name, $rule, ['v' => $valid], ['v' => $negative]);
        $covered[] = $name;
    }

    validationPair('callback', [new Callback(static fn(mixed $value): bool => $value === 42)], ['v' => 42], ['v' => 41]);
    $hash = password_hash('fixture-only-value', PASSWORD_DEFAULT);
    validationPair('current_password', [new CurrentPassword(static fn(mixed $value): bool => is_string($value) && password_verify($value, $hash))], ['v' => 'fixture-only-value'], ['v' => 'incorrect']);
    $calls = 0;
    $bail = Validator::make(['v' => ['bail', 'integer', new Callback(static function () use (&$calls): bool { ++$calls; return false; })]])->setFailFast(false);
    verify($bail->validate(['v' => 'not-an-integer'])->fails() && $calls === 0, 'Bail did not suppress expensive next rule.');
    verify(new CompiledValidator($bail)->validate(['v' => 'not-an-integer'])->fails() && $calls === 0, 'Compiled bail did not suppress callback.');
    array_push($covered, 'callback', 'current_password', 'bail', 'exists', 'unique');
    $expected = array_map(static fn(BuiltinRule $rule): string => $rule->value, BuiltinRule::cases());
    sort($expected);
    sort($covered);
    verify($covered === $expected, 'Published built-in rule inventory drifted; add explicit cases.');
    echo count($covered) . " published built-in rule tokens mapped, with database rules independently executed\n";
}

function validationDatabaseWorkload(): void
{
    $driver = (string) getenv('DB_BACKEND');
    $config = $driver === 'sqlite'
        ? ['driver' => 'sqlite', 'database' => evidenceDirectory() . '/validation.sqlite']
        : ['driver' => $driver, 'host' => '127.0.0.1', 'port' => $driver === 'pgsql' ? 5432 : 3306, 'database' => 'experiment', 'username' => 'experiment', 'password' => 'experiment'];
    $db = new Connection(ConnectionConfig::fromArray($config));
    $db->statement('CREATE TABLE lab_validation_accounts (id INTEGER PRIMARY KEY, email VARCHAR(120) NOT NULL, deleted_at VARCHAR(40) NULL)');
    $rows = [];
    for ($i = 1; $i <= 260; ++$i) {
        $rows[] = ['id' => $i, 'email' => "customer-$i@example.test", 'deleted_at' => $i === 260 ? '2025-01-01' : null];
    }
    foreach (array_chunk($rows, 50) as $chunk) {
        $db->table('lab_validation_accounts')->insert($chunk);
    }
    $provider = DBLayerDatabaseProvider::fromConnection($db, 32);
    $builder = Validator::make([
        'ids.*' => 'required|integer|exists:lab_validation_accounts,id',
        'emails.*' => 'required|email|unique:lab_validation_accounts,email',
    ], $provider)->setFailFast(false);
    $valid = ['ids' => range(1, 260), 'emails' => array_map(static fn(int $i): string => "new-$i@example.test", range(1, 260))];
    $invalid = $valid;
    $invalid['ids'][65] = 999999;
    $invalid['emails'][129] = 'customer-130@example.test';
    foreach ([$builder, new CompiledValidator($builder)] as $validator) {
        verify($validator->validate($valid)->passes(), 'Multi-chunk database validation failed.');
        $result = $validator->validate($invalid);
        verify($result->fails() && $result->errorsFor('ids.65') !== [] && $result->errorsFor('emails.129') !== [], 'Database correlation lost concrete field errors.');
    }
    $unique = Validator::make(['email' => [Rule::unique('lab_validation_accounts', 'email')->ignore(1)->withoutTrashed()]], $provider);
    verify($unique->validate(['email' => 'customer-1@example.test'])->passes(), 'Ignore ID rejected own email.');
    verify($unique->validate(['email' => 'customer-260@example.test'])->passes(), 'Soft-deleted value did not honor policy.');
    verify($unique->validate(['email' => 'customer-2@example.test'])->fails(), 'Other active email was accepted.');
    expectFailure(static fn() => Validator::make(['id' => 'exists:lab_validation_accounts,id']), DatabaseProviderRequiredException::class, 'Missing database provider accepted.');
    $db->statement('DROP TABLE lab_validation_accounts');
    expectFailure(static fn() => $builder->validate($valid), DatabaseValidationException::class, 'Database failure converted to ordinary invalid input.');
    echo "Real database batching, correlation, ignore/soft-delete and provider failure passed\n";
}


function validationOptionsWorkload(): void
{
    foreach ([
        ['IPv4 public', 'ip:v4,public', '8.8.8.8', '10.0.0.1'],
        ['IPv6 private', 'ip:v6,private', 'fd00::1', '2001:4860:4860::8888'],
        ['IPv6 public', 'ip:v6,public', '2001:4860:4860::8888', 'fd00::1'],
        ['IPv4 family', 'ip:ipv4', '127.0.0.1', '::1'],
        ['IPv6 family', 'ip:ipv6', '::1', '127.0.0.1'],
        ['UUID range', 'uuid:2-4', '550e8400-e29b-41d4-a716-446655440000', '550e8400-e29b-11d4-a716-446655440000'],
        ['UUID list', 'uuid:135', '550e8400-e29b-31d4-a716-446655440000', '550e8400-e29b-41d4-a716-446655440000'],
        ['UUID exclusion', 'uuid:!4', '550e8400-e29b-51d4-a716-446655440000', '550e8400-e29b-41d4-a716-446655440000'],
        ['UUID variant', 'uuid:4', '550E8400-E29B-41D4-A716-446655440000', '550e8400-e29b-41d4-0716-446655440000'],
        ['decimal exact', 'decimal:2', '1.20', '1.2'],
        ['decimal zero', 'decimal:0', '12', '12.0'],
        ['decimal range', 'decimal:1,3', '-1.234', '1.2345'],
        ['absolute path', 'path:absolute', '/reports/file.txt', 'reports/file.txt'],
        ['upload length', 'upload_id:8', 'abc_1234', 'abc_12345'],
    ] as [$name, $rule, $positive, $negative]) {
        validationPair($name, $rule, ['v' => $positive], ['v' => $negative]);
    }
    foreach ([
        static fn() => new \Infocyph\ReqShield\Rules\Ip('v4', 'v6'),
        static fn() => new \Infocyph\ReqShield\Rules\Ip('public', 'private'),
        static fn() => new \Infocyph\ReqShield\Rules\Ip('unknown'),
        static fn() => new \Infocyph\ReqShield\Rules\Decimal(-1),
        static fn() => new \Infocyph\ReqShield\Rules\Decimal(3, 1),
    ] as $invalid) {
        expectFailure($invalid, InvalidArgumentException::class, 'Invalid rule options accepted.');
    }
    echo "Rule option boundaries and incompatible configuration rejected\n";
}

function validationNestedErrorsWorkload(): void
{
    $valid = ['groups' => [
        ['members' => [['email' => 'a@example.test'], ['email' => 'b@example.test']]],
        ['members' => [['email' => 'a@example.test']]],
    ]];
    $invalid = $valid;
    $invalid['groups'][0]['members'][1]['email'] = 'a@example.test';
    foreach (['targeted', 'all'] as $mode) {
        $builder = Validator::make(['groups.*.members.*.email' => 'required|email|distinct'])
            ->setNestedFlattenMode($mode)
            ->setFailFast(false);
        foreach ([$builder, new CompiledValidator($builder)] as $validator) {
            verify($validator->validate($valid)->passes(), 'Independent nested wildcard groups collided.');
            $result = $validator->validate($invalid);
            verify($result->fails() && $result->hasError('groups.0.members.1.email'), 'Nested sibling duplicate was not rejected.');
            verify(!$result->hasError('groups.1.members.0.email'), 'Distinct escaped its wildcard group.');
            verify($validator->validate($valid)->passes(), 'Nested validation retained previous failure.');
        }
    }
    $builder = Validator::make(['quantity' => 'required|integer|min:3'])
        ->setFailFast(false)
        ->setCustomMessages(['quantity.min' => 'Quantity below minimum.']);
    foreach ([$builder, new CompiledValidator($builder)] as $validator) {
        $result = $validator->validate(['quantity' => 1]);
        verify($result->fails() && $result->first('quantity') === 'Quantity below minimum.', 'Custom message not resolved.');
        verify($result->errorFieldCount() === 1 && $result->errorMessageCount() === 1, 'Error counts disagree.');
        $flat = $result->toFlatErrors();
        verify(count($flat) === 1 && $flat[0]['field'] === 'quantity' && $flat[0]['value'] === 1, 'Flat failure metadata lost.');
        verify($result->toApiErrors()['failures'] === $flat, 'API failure projection differs.');
        $jsonApi = $result->toJsonApiErrors(409)['errors'][0];
        verify($jsonApi['status'] === '409' && $jsonApi['source']['pointer'] === '/data/attributes/quantity', 'JSON API status/pointer mismatch.');
        $problem = $result->toProblemJson(status: 400);
        verify($problem['status'] === 400 && $problem['detail'] === $result->firstError() && $problem['failures'] === $flat, 'Problem response lost errors.');
        verify(json_decode($result->toJson(), true, flags: JSON_THROW_ON_ERROR) === $result->errors(), 'JSON errors diverged.');
        expectFailure(static fn() => $result->throw(), \Infocyph\ReqShield\Exceptions\ValidationException::class, 'Failed result did not throw.');
        verify($validator->validate(['quantity' => 3])->passes(), 'Result error state leaked into next validation.');
    }
    echo "Nested distinct scope, mutable/compiled traversal, custom messages and error projections verified\n";
}
