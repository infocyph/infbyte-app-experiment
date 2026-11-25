<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Omnibus\Serialization\MessageCodec;

final readonly class ReceiptCodec implements MessageCodec
{
    #[\Override]
    public function alias(): string
    {
        return 'experiment.receipt.v1';
    }

    #[\Override]
    public function type(): string
    {
        return Receipt::class;
    }

    #[\Override]
    public function decode(array $payload): object
    {
        $id = $payload['id'] ?? null;
        $kind = $payload['kind'] ?? null;
        ensure(is_string($id) && is_string($kind) && in_array($kind, ['normal', 'retry', 'fail', 'rollback'], true), 'Invalid receipt payload.');

        return new Receipt($id, $kind);
    }

    #[\Override]
    public function encode(object $message): array
    {
        ensure($message instanceof Receipt, 'Unexpected receipt type.');

        return ['id' => $message->id, 'kind' => $message->kind];
    }
}
