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
    foreach (['mutable' => $builder, 'compiled' => new CompiledValidator($builder)] as $mode => $validator) {
        verify($validator->validate($positive)->passes(), "Positive $name input rejected ($mode).");
        verify($validator->validate($negative)->fails(), "Negative $name input accepted ($mode).");
        verify($validator->validate($positive)->passes(), "Repeated $name input inherited failure ($mode).");
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

function validationLocaleWorkload(): void
{
    $schema = ['name' => 'required|string|min:3', 'contacts.*.email' => 'required|email'];
    foreach (['es', 'es_MX'] as $locale) {
        $builder = Validator::make($schema)
            ->setFailFast(false)
            ->setFieldAliases(['name' => 'Nombre', 'contacts.*.email' => 'Correo'])
            ->addLocalePack('es', ['required' => 'Falta :field.', 'email' => ':field invalido.'])
            ->setLocale($locale)
            ->setCustomMessages(['name.min' => ':field necesita :min caracteres (:rule).']);
        foreach ([$builder, new CompiledValidator($builder)] as $validator) {
            $missing = $validator->validate(['contacts' => [['email' => 'bad-email']]]);
            verify($missing->first('name') === 'Falta Nombre.', 'Locale or base locale fallback failed.');
            verify($missing->first('contacts.0.email') === 'Correo invalido.', 'Wildcard locale alias failed.');
            $short = $validator->validate(['name' => 'ab', 'contacts' => [['email' => 'a@example.test']]]);
            verify($short->first('name') === 'Nombre necesita 3 caracteres (min).', 'Custom placeholder override failed.');
            verify($validator->validate(['name' => 'valid', 'contacts' => [['email' => 'a@example.test']]])->passes(), 'Localized failure leaked into next request.');
        }
    }
    echo "Base and regional locale fallback, wildcard aliases and custom message interpolation passed\n";
}

function validationRelatedWildcardContracts(): void
{
    $schema = [
        'policy.minimum' => 'required|integer',
        'policy.open' => 'required|date_format:Y-m-d',
        'items.*.quantity' => 'required|integer|gte:policy.minimum',
        'items.*.date' => 'required|date_format:Y-m-d|after_or_equal:policy.open',
        'items.*.sku' => 'required|string|distinct',
    ];
    $valid = ['policy' => ['minimum' => 3, 'open' => '2026-01-01'], 'items' => [
        ['quantity' => 3, 'date' => '2026-01-01', 'sku' => 'A'],
        ['quantity' => 4, 'date' => '2026-01-02', 'sku' => 'B'],
    ]];
    $invalid = $valid;
    $invalid['items'][1]['quantity'] = 2;
    $invalid['items'][1]['date'] = '2025-12-31';
    $invalid['items'][1]['sku'] = 'A';
    foreach (['targeted', 'all'] as $mode) {
        $builder = Validator::make($schema)->setNestedFlattenMode($mode)->setFailFast(false);
        foreach ([$builder, new CompiledValidator($builder)] as $validator) {
            verify($validator->validate($valid)->passes(), 'Nested dependency rejected valid batch.');
            $result = $validator->validate($invalid);
            foreach (['items.1.quantity', 'items.1.date', 'items.1.sku'] as $field) {
                verify($result->hasError($field), 'Nested dependency lost concrete field: ' . $field);
            }
            verify(!$result->hasError('items.0.quantity') && !$result->hasError('items.0.date'), 'Dependency error escaped affected row.');
            verify($validator->validate($valid)->passes(), 'Dependency failure survived reused validator.');
        }
    }
    echo "Nested wildcard scalar/date dependencies and independent concrete field errors passed\n";
}

function validationConditionalWildcardContracts(): void
{
    $schema = [
        'mode' => 'required|in:delivery,collection',
        'orders.*.address' => 'required_if:mode,delivery|string',
        'orders.*.pickup' => 'exclude_if:mode,delivery|string',
    ];
    $builder = Validator::make($schema)->setFailFast(false);
    foreach ([$builder, new CompiledValidator($builder)] as $validator) {
        $delivery = ['mode' => 'delivery', 'orders' => [['address' => 'one', 'pickup' => ['ignored']], ['address' => 'two', 'pickup' => ['ignored']]]];
        $result = $validator->validate($delivery);
        verify($result->passes(), 'Delivery conditional wildcard rejected valid orders.');
        $safe = $result->safe();
        verify(!isset($safe['orders.0.pickup'], $safe['orders.1.pickup']) && !isset($safe['orders'][0]['pickup'], $safe['orders'][1]['pickup']), 'Excluded wildcard payload leaked.');
        unset($delivery['orders'][1]['address']);
        $result = $validator->validate($delivery);
        verify($result->fails() && $result->hasError('orders.1.address') && !$result->hasError('orders.0.address'), 'Missing wildcard address was not isolated.');
        $collection = ['mode' => 'collection', 'orders' => [['pickup' => 'desk'], ['pickup' => 'locker']]];
        verify($validator->validate($collection)->passes(), 'Non-triggered wildcard requirement retained prior state.');
        $collection['orders'][0]['pickup'] = ['invalid'];
        verify($validator->validate($collection)->hasError('orders.0.pickup'), 'Visible wildcard failed to validate.');
    }
    echo "Conditional wildcard requirement, exclusion and mode replacement passed\n";
}

function validationUploadMetadataContracts(): void
{
    $root = evidenceDirectory() . '/metadata-options';
    verify(mkdir($root, 0700), 'Metadata fixture unavailable.');
    $path = $root . '/file.txt';
    verify(file_put_contents($path, 'receipt') === 7, 'Metadata fixture write failed.');
    $valid = ['name' => 'file.txt', 'type' => 'text/plain', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 7];
    $partial = array_replace($valid, ['error' => UPLOAD_ERR_PARTIAL]);
    validationPair('metadata shape permits partial', 'upload_meta', ['v' => $partial], ['v' => array_replace($partial, ['error' => 999])]);
    foreach (['success', 'strict'] as $mode) {
        foreach ([
            $partial,
            array_replace($valid, ['tmp_name' => '']),
            array_replace($valid, ['size' => -1]),
            array_replace($valid, ['type' => ['text/plain']]),
            array_replace($valid, ['name' => '../file']),
            array_replace($valid, ['name' => 'nine9.txt']),
        ] as $bad) {
            validationPair('metadata ' . $mode, 'upload_meta:' . $mode . ',8', ['v' => $valid], ['v' => $bad]);
        }
    }
    validationPair('secure filename width', 'secure_file:success,8', ['v' => $valid], ['v' => array_replace($valid, ['name' => 'nine9.txt'])]);
    echo "Upload shape/success/strict modes, metadata types and filename bounds passed\n";
}

function validationPathOptionsContracts(): void
{
    foreach ([
        ['path:absolute', 'C:\\reports\\file.txt', 'C:relative.txt'],
        ['path:absolute', '\\\\server\\share\\file.txt', 'reports/file.txt'],
        ['path:relative', 'reports/file.txt', '/reports/file.txt'],
        ['path:relative', 'C:relative.txt', 'C:\\reports\\file.txt'],
        ['safe_filename', 'report.txt', 'nested/report.txt'],
        ['safe_filename', 'report.txt', "bad\0name.txt"],
        ['upload_id:8', 'abc_1234', 'abc_12345'],
    ] as [$rule, $positive, $negative]) {
        validationPair($rule, $rule, ['v' => $positive], ['v' => $negative]);
    }
    echo "Portable absolute/relative path classification and filename/input boundaries passed\n";
}

function validationGranularOptionContracts(string $contract): void
{
    [$rule, $positive, $negative] = match ($contract) {
        'ipv4-global' => ['ip:v4,global', '8.8.8.8', '100.64.0.1'],
        'ipv6-global' => ['ip:v6,global', '2001:4860:4860::8888', '2001:db8::1'],
        'ipv4-no-reserved' => ['ip:v4,no_reserved', '10.0.0.1', '127.0.0.1'],
        'ipv6-no-reserved' => ['ip:v6,no_reserved', 'fd00::1', '::1'],
        'ipv4-no-private' => ['ip:v4,no_private', '8.8.8.8', '172.16.0.1'],
        'ipv6-no-private' => ['ip:v6,no_private', '2001:4860:4860::8888', 'fd00::1'],
        'ipv4-short-alias' => ['ip:4,priv', '192.168.1.1', 'fd00::1'],
        'ipv6-short-alias' => ['ip:6,priv', 'fd00::1', '10.0.0.1'],
        'uuid-v1' => ['uuid:1', '550e8400-e29b-11d4-a716-446655440000', '550e8400-e29b-21d4-a716-446655440000'],
        'uuid-v2' => ['uuid:2', '550e8400-e29b-21d4-a716-446655440000', '550e8400-e29b-31d4-a716-446655440000'],
        'uuid-v3' => ['uuid:3', '550e8400-e29b-31d4-a716-446655440000', '550e8400-e29b-51d4-a716-446655440000'],
        'uuid-v5' => ['uuid:5', '550e8400-e29b-51d4-a716-446655440000', '550e8400-e29b-41d4-a716-446655440000'],
        'uuid-reversed-range' => ['uuid:4-2', '550e8400-e29b-21d4-a716-446655440000', '550e8400-e29b-51d4-a716-446655440000'],
        'uuid-exclude-one' => ['uuid:!1', '550e8400-e29b-41d4-a716-446655440000', '550e8400-e29b-11d4-a716-446655440000'],
        'decimal-lower-bound' => ['decimal:2,4', '-10.00', '-10.0'],
        'decimal-upper-bound' => ['decimal:2,4', '10.0000', '10.00000'],
        'decimal-zero-range' => ['decimal:0,2', '0', '0.001'],
        'fractional-multiple' => ['multiple_of:0.25', '2.75', '2.6'],
        'negative-multiple' => ['multiple_of:5', '-15', '-14'],
        'min-digits-leading-zero' => ['min_digits:5', '00001', '0001'],
        'max-digits-leading-zero' => ['max_digits:5', '00001', '000001'],
        'hex-short' => ['hex_color', '#a0F', '#a0F1'],
        'hex-unprefixed' => ['hex_color', 'aabbcc', 'aabbcg'],
        'regex-alternation' => ['regex:/^(?:alpha|beta)$/', 'beta', 'alpha-beta'],
        default => throw new InvalidArgumentException('Unknown granular validation contract.'),
    };
    validationPair($contract, $rule, ['v' => $positive], ['v' => $negative]);
    echo "Granular validation $contract passed in mutable and compiled validators\n";
}
