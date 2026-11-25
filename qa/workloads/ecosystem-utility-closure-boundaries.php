<?php

declare(strict_types=1);

use Infocyph\InterMix\Serializer\ClosureSerializer;
use Infocyph\ReqShield\CompiledValidator;
use Infocyph\ReqShield\Validator;

final class LabClosureWakeProbe
{
    public static int $wakeups = 0;

    public function __wakeup(): void
    {
        ++self::$wakeups;
    }
}

function closureBoundaryWorkload(string $contract): void
{
    $key = random_bytes(32);
    $signer = ClosureSerializer::signed($key);
    $tenant = 'tenant-a';
    $closure = static fn(string $receipt): string => $tenant . ':' . $receipt;
    $token = $signer->serialize($closure);
    [$prefix, $signature, $body] = explode('.', $token, 3);
    $serialized = base64_decode($body, true);
    verify($prefix === 'imxcs2' && is_string($serialized), 'Signed envelope shape mismatch.');
    verify(hash_equals(base64_encode(hash_hmac('sha3-256', $serialized, $key, true)), $signature), 'Independent HMAC mismatch.');
    $reject = static fn(string $input) => expectFailure(static fn() => $signer->unserialize($input), InvalidArgumentException::class, 'Invalid signed closure accepted.');
    switch ($contract) {
        case 'exact-bound':
            verify(ClosureSerializer::signed($key, strlen($token))->unserialize($token)('r') === 'tenant-a:r', 'Exact envelope size rejected.');
            expectFailure(static fn() => ClosureSerializer::signed($key, strlen($token) - 1)->unserialize($token), InvalidArgumentException::class, 'One-byte oversize accepted.');
            break;
        case 'prefix':
            foreach (['', 'imxcs1.' . $signature . '.' . $body, 'imxcs2.' . $signature, 'other.' . $signature . '.' . $body] as $input) {
                $reject($input);
            }
            break;
        case 'encoding':
            foreach (['imxcs2.!.'.$body, 'imxcs2.'.base64_encode(str_repeat('x', 31)).'.'.$body,
                'imxcs2.'.$signature.'.!', 'imxcs2.'.$signature.'.'] as $input) {
                $reject($input);
            }
            break;
        case 'pre-authentication':
            $probe = serialize(new LabClosureWakeProbe());
            $reject('imxcs2.' . base64_encode(random_bytes(32)) . '.' . base64_encode($probe));
            verify(LabClosureWakeProbe::$wakeups === 0, 'Unauthenticated executable body reached deserialization.');
            $native = unserialize($probe, ['allowed_classes' => [LabClosureWakeProbe::class]]);
            verify($native instanceof LabClosureWakeProbe && LabClosureWakeProbe::$wakeups === 1, 'Independent wakeup positive control failed.');
            break;
        case 'authenticated-nonclosure':
            foreach ([serialize(['receipt' => 1]), serialize('not a closure'), 'not serialized data'] as $data) {
                $reject('imxcs2.' . base64_encode(hash_hmac('sha3-256', $data, $key, true)) . '.' . base64_encode($data));
            }
            break;
        case 'unsigned-boundary':
            $unsigned = ClosureSerializer::serialize($closure);
            verify(ClosureSerializer::isEnvelope($unsigned) && !ClosureSerializer::isEnvelope($token), 'Envelope classification crossed trust boundary.');
            verify(ClosureSerializer::unserialize($unsigned, strlen($unsigned))('r') === 'tenant-a:r', 'Trusted unsigned exact-bound read failed.');
            expectFailure(static fn() => ClosureSerializer::unserialize($unsigned, strlen($unsigned) - 1), InvalidArgumentException::class, 'Oversized unsigned payload accepted.');
            expectFailure(static fn() => ClosureSerializer::unserialize($token), InvalidArgumentException::class, 'Signed envelope accepted by unsigned reader.');
            $reject($unsigned);
            break;
        default:
            throw new InvalidArgumentException('Unknown closure boundary.');
    }
    verify($signer->unserialize($token)('next') === 'tenant-a:next', 'Failure contaminated valid signed closure.');
    echo "Closure $contract independent authentication boundary passed\n";
}

function validationExpressionWorkload(string $contract, bool $compiled): void
{
    [$rules, $positive, $negative] = match ($contract) {
        'alternation' => ['required|regex:/^(?:order|receipt)-[0-9]+$/D|string', 'receipt-42', 'invoice-42'],
        'escaped-pipe' => ['required|regex:/^a\|b$/D|string', 'a|b', 'ab'],
        'character-class' => ['required|regex:/^[a|b]+$/D|string', 'a|b', 'abc'],
        'escaped-delimiter' => ['required|regex:/^api\/(?:one|two)$/D|string', 'api/two', 'api/three'],
        'alternate-delimiter' => ['required|regex:~^(?:a|b):[0-9]{2,3}$~D|string', 'b:42', 'b:4'],
        'negative-pattern' => ['required|not_regex:/^(?:root|admin)$/iD|string', 'member', 'ADMIN'],
        'unicode' => ['required|regex:/^(?:\p{L}+|[0-9]+)$/uD|string', 'বাংলা', 'বাংলা!'],
        'trailing-rule' => ['required|regex:/^(?:a|b)+$/D|max:3', 'aba', 'abab'],
        default => throw new InvalidArgumentException('Unknown expression boundary.'),
    };
    $builder = Validator::make(['v' => $rules])->setFailFast(false);
    $validator = $compiled ? new CompiledValidator($builder) : $builder;
    for ($i = 0; $i < 8; ++$i) {
        verify($validator->validate(['v' => $positive])->passes(), 'Valid expression rejected.');
        verify($validator->validate(['v' => $negative])->hasError('v'), 'Invalid expression accepted.');
        verify($validator->validate([])->hasError('v'), 'Trailing or leading required rule disappeared.');
    }
    verify($validator->validate(['v' => $positive])->passes(), 'Failed expression contaminated reused plan.');
    echo "Expression $contract " . ($compiled ? 'compiled' : 'direct') . " passed\n";
}
