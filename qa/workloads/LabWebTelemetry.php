<?php

declare(strict_types=1);

namespace Experiment;

use Closure;
use Infocyph\Webrick\Middleware\TelemetryMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\TraceContext;
use Psr\Log\LoggerInterface;

final class LabWebTelemetry
{
    public static function observe(Request $request, Closure $next): Response
    {
        return (new TelemetryMiddleware(
            emitTraceparentHeader: true,
            nelGroup: 'lab',
            nelEndpoint: 'https://report.example.test/nel',
            nelTtlSeconds: 300,
        ))($request, $next);
    }

    public static function trace(Request $request, LoggerInterface $logger, string $id): Response
    {
        $context = TraceContext::require($request);
        $data = [
            'id' => $id,
            'request_id' => $request->getAttribute('request_id'),
            'trace' => $context->traceId(),
            'parent' => $context->parentSpanId(),
            'span' => $context->spanId(),
            'sampled' => $context->sampled(),
            'pid' => getmypid(),
        ];
        $logger->info('lab-web-trace', [
            ...$data,
            'authorization' => $request->getHeaderLine('Authorization'),
            'nested' => ['cookie' => $request->getHeaderLine('Cookie')],
        ]);

        return Response::json($data);
    }

    public static function unavailable(Request $request, LoggerInterface $logger, string $id): Response
    {
        $context = TraceContext::require($request);
        $logger->warning('lab-web-unavailable', ['id' => $id, 'trace' => $context->traceId(), 'request_id' => $request->getAttribute('request_id')]);

        return Response::json(['id' => $id, 'trace' => $context->traceId(), 'span' => $context->spanId()], 503, ['Cache-Control' => 'no-store']);
    }

    public static function failure(string $id): Response
    {
        throw new \RuntimeException('synthetic-private-exception-' . $id);
    }
}
