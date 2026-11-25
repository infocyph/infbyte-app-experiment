<?php

declare(strict_types=1);

namespace Experiment;

use Infocyph\Webrick\Request\Core\UploadedFile;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use RuntimeException;

final class Transfer
{
    public static function upload(Request $request): Response
    {
        $body = $request->getParsedBody();
        $document = $request->file('document');
        $attachments = $request->file('attachments');
        if (!is_array($body) || ($body['reference'] ?? null) !== 'lab-transfer'
            || !$document instanceof UploadedFile || !is_array($attachments)
            || count($attachments) !== 2 || !($attachments[0] ?? null) instanceof UploadedFile
            || !($attachments[1] ?? null) instanceof UploadedFile) {
            return Response::json(['error' => 'invalid multipart'], 422);
        }
        $files = [$document, $attachments[0], $attachments[1]];
        foreach ($files as $file) {
            $size = $file->getSize();
            if ($file->getError() !== UPLOAD_ERR_OK || $size === null || $size > 262144) {
                return Response::json(['error' => 'invalid file'], 422);
            }
        }

        $results = [];
        foreach ($files as $file) {
            $target = dirname(__DIR__) . '/storage/lab-transfers/' . bin2hex(random_bytes(16));
            $expectedSize = $file->getSize();
            $file->moveTo($target);
            try {
                $hash = hash_file('sha256', $target);
                $size = filesize($target);
                ensure(is_string($hash) && is_int($size) && $size === $expectedSize, 'Moved file bytes differ.');
                $rejected = false;
                try {
                    $file->getStream();
                } catch (RuntimeException) {
                    $rejected = true;
                }
                ensure($rejected, 'Moved upload remained readable.');
                $results[] = ['bytes' => $size, 'sha256' => $hash];
            } finally {
                unlink($target);
            }
        }

        return Response::json(['files' => $results], 201);
    }

    public static function events(): Response
    {
        return Response::stream(static function (): \Generator {
            for ($sequence = 1; $sequence <= 64; ++$sequence) {
                yield "id: $sequence\nevent: progress\ndata: " . json_encode(['sequence' => $sequence], JSON_THROW_ON_ERROR) . "\n\n";
                usleep(5000);
            }
        }, headers: ['Content-Type' => 'text/event-stream']);
    }

    public static function cleanupStream(Request $request): Response
    {
        $id = $request->getHeaderLine('X-Lab-Stream-Id');
        $mode = $request->getHeaderLine('X-Lab-Stream-Mode');
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1 || !in_array($mode, ['complete', 'abort'], true)) {
            return Response::json(['error' => 'invalid fixture'], 400);
        }
        $root = dirname(__DIR__) . '/storage/lab-transfers/';
        $total = $mode === 'complete' ? 16 : 512;
        return Response::stream(static function () use ($root, $id, $total): \Generator {
            $lease = fopen($root . $id . '.lease', 'x+b');
            ensure(is_resource($lease) && flock($lease, LOCK_EX | LOCK_NB), 'Stream lease acquisition failed.');
            $produced = 0;
            $closed = false;
            $cleanup = static function (string $owner) use ($root, $id, $lease, &$produced, &$closed): void {
                if ($closed) {
                    return;
                }
                $closed = true;
                flock($lease, LOCK_UN);
                fclose($lease);
                $record = json_encode(['chunks' => $produced, 'owner' => $owner, 'pid' => getmypid()], JSON_THROW_ON_ERROR);
                ensure(file_put_contents($root . $id . '.tmp', $record, LOCK_EX) !== false
                    && rename($root . $id . '.tmp', $root . $id . '.terminal'), 'Terminal evidence publication failed.');
            };
            // Classic FPM may terminate script execution on disconnect. Record that
            // distinct lifecycle path without retaining shutdown callbacks in workers.
            if (PHP_SAPI === 'fpm-fcgi') {
                register_shutdown_function(static fn() => $cleanup('sapi-shutdown'));
            }
            try {
                for ($sequence = 0; $sequence < $total; ++$sequence) {
                    ++$produced;
                    yield str_repeat(chr($sequence % 256), 8192);
                    usleep(10000);
                }
            } finally {
                $cleanup('generator-finally');
            }
        }, headers: ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store', 'X-Accel-Buffering' => 'no']);
    }
}
