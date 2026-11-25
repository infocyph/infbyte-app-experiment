<?php

declare(strict_types=1);

use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Middleware\RiskAdmissionMiddleware;
use Infocyph\Webrick\Middleware\RiskDecision;
use Infocyph\Webrick\Middleware\TelemetryMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Support\TelemetryOptions;
use Infocyph\Webrick\Support\TraceContext;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Psr\Log\AbstractLogger;

final class LabAccessLogger extends AbstractLogger
{
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }
}

function telemetryRiskWorkload(): void
{
    $log = new LabAccessLogger();
    $telemetry = TelemetryMiddleware::fromOptions(new TelemetryOptions(
        log: $log, emitTraceparentHeader: true, nelGroup: 'lab', nelEndpoint: 'https://report.example.test/nel', nelTtlSeconds: 300,
    ));
    $errors = new ErrorHandler($log, exceptionMap: [DomainException::class => 409]);
    $traceId = '0123456789abcdef0123456789abcdef';
    $parent = '0123456789abcdef';
    $calls = 0;
    $contexts = [];
    $statuses = ['allow' => 200, 'challenge' => 429, 'deny' => 403, 'unavailable' => 503];
    foreach ([RiskAdmissionMiddleware::ENFORCE, RiskAdmissionMiddleware::SHADOW] as $mode) {
        foreach ($statuses as $action => $expected) {
            $decision = match ($action) {
                'allow' => RiskDecision::allow(0, ['low_risk']),
                'challenge' => RiskDecision::challenge(30, ['signature_window']),
                'deny' => RiskDecision::deny(90, ['policy_deny']),
                'unavailable' => RiskDecision::unavailable(reasonCodes: ['backend_unavailable']),
            };
            $risk = new RiskAdmissionMiddleware(static fn(Request $request): RiskDecision => $decision, $mode);
            $id = "$mode-$action";
            $request = Request::fake(headers: ['Accept' => 'application/json', 'X-Request-Id' => $id,
                'traceparent' => "00-$traceId-$parent-01", 'tracestate' => 'lab=one',
                'Authorization' => 'Bearer synthetic-private-auth', 'Cookie' => 'session=synthetic-private-cookie'],
                uri: 'https://lab.example/login?marker=synthetic-private-query');
            $response = $telemetry($request, static function (Request $active) use ($risk, $decision, &$calls, &$contexts): Response {
                $context = TraceContext::require($active);
                $contexts[] = $context;
                return $risk($active, static function (Request $admitted) use ($decision, &$calls): Response {
                    ++$calls;
                    verify($admitted->getAttribute(RiskDecision::ATTRIBUTE) === $decision, 'Risk decision identity lost.');
                    return Response::json(['accepted' => true]);
                });
            });
            $status = $mode === RiskAdmissionMiddleware::SHADOW ? 200 : $expected;
            verify($response->getStatusCode() === $status && $response->getHeaderLine('X-Request-Id') === $id
                && $response->getHeaderLine('Trace-Id') === $traceId && $response->getHeaderLine('tracestate') === 'lab=one', 'Risk/trace response contract differs.');
            verify(preg_match('/^00-' . $traceId . '-[0-9a-f]{16}-01$/D', $response->getHeaderLine('traceparent')) === 1
                && $response->getHeaderLine('traceparent') !== "00-$traceId-$parent-01", 'Current span identity not emitted.');
            verify(str_contains($response->getHeaderLine('Server-Timing'), 'app;dur=') && $response->getHeaderLine('X-Response-Time') !== '', 'Timing metadata missing.');
            $nel = json_decode($response->getHeaderLine('NEL'), true, flags: JSON_THROW_ON_ERROR);
            verify($nel['max_age'] === 300 && $nel['group'] === 'lab' && $nel['success_fraction'] === 0, 'NEL policy mismatch.');
            if ($status !== 200) {
                verify($response->getHeaderLine('Cache-Control') === 'no-store', 'Risk denial became cacheable.');
            }
        }
    }
    verify($calls === 5 && count($contexts) === 8 && count(array_unique(array_map(static fn($context) => $context->spanId(), $contexts))) === 8, 'Risk admission or per-request span isolation failed.');
    foreach ($contexts as $context) {
        verify($context->traceId() === $traceId && $context->parentSpanId() === $parent && $context->sampled()
            && !$context->otelAvailable() && $context->propagationHeaders()['tracestate'] === 'lab=one'
            && !isset($context->propagationHeaders(false)['X-Request-Id']), 'Propagation or immutable context mismatch.');
    }
    $logText = implode("\n", array_column($log->records, 1));
    verify(count($log->records) === 8 && !str_contains($logText, 'synthetic-private-'), 'Access logs included query or credentials.');
    $request = Request::fake(headers: ['X-Request-Id' => 'bad id', 'traceparent' => '00-' . str_repeat('0', 32) . '-' . $parent . '-01',
        'tracestate' => 'foreign=discard', 'Accept' => 'application/problem+json'], uri: 'https://lab.example/error');
    $failed = $telemetry($request, static fn(Request $active): Response => $errors->handle($active, static fn() => throw new DomainException('private-fixture-detail')));
    $problem = json_decode((string) $failed->getStringBody(), true, flags: JSON_THROW_ON_ERROR);
    verify($failed->getStatusCode() === 409 && $problem['status'] === 409 && $problem['instance'] === '/error'
        && !str_contains((string) $failed->getStringBody(), 'private-fixture-detail') && $failed->getHeaderLine('Cache-Control') === 'no-store'
        && $failed->getHeaderLine('tracestate') === '' && $failed->getHeaderLine('Trace-Id') !== $traceId, 'Production error or invalid trace boundary failed.');
    verify($problem['request_id'] === $failed->getHeaderLine('X-Request-Id') && $problem['request_id'] !== 'bad id', 'Error request correlation lost.');
    $custom = new RiskAdmissionMiddleware(static fn() => RiskDecision::deny(), renderer: static fn() => new Response(403, 'denied', ['Cache-Control' => 'public']));
    verify($custom(Request::fake(), static fn() => throw new LogicException('Handler reached.'))->getHeaderLine('Cache-Control') === 'no-store', 'Custom risk denial cache policy bypassed.');
    echo "W3C trace propagation, request-local isolation, safe access logs, risk enforcement/shadow and negotiated production errors passed\n";
}

function telemetryOtelWorkload(): void
{
    $exporter = new InMemoryExporter();
    $provider = TracerProvider::builder()->addSpanProcessor(new SimpleSpanProcessor($exporter))->build();
    $scope = Sdk::builder()->setTracerProvider($provider)->setPropagator(TraceContextPropagator::getInstance())->buildAndRegisterGlobal();
    try {
        $telemetry = new TelemetryMiddleware(enableOtelIntegration: true, otelServiceName: 'lab-checkout', otelServiceVersion: '1.0.0');
        $trace = '0123456789abcdef0123456789abcdef';
        $parent = '0123456789abcdef';
        $before = Span::getCurrent()->getContext()->getSpanId();
        $start = (int) (microtime(true) * 1_000_000_000);
        foreach ([200, 503] as $status) {
            $request = Request::fake(headers: ['traceparent' => "00-$trace-$parent-01"], uri: 'https://lab.example/checkout')->withAttribute('route.name', 'checkout');
            $response = $telemetry($request, static function (Request $active) use ($status, $trace): Response {
                $context = TraceContext::require($active);
                verify($context->otelAvailable() && $context->traceId() === $trace
                    && Span::getCurrent()->getContext()->getSpanId() === $context->spanId(), 'SDK active request span mismatch.');
                return Response::json(['status' => $status], $status);
            });
            verify($response->getStatusCode() === $status && $response->getHeaderLine('Trace-Id') === $trace, 'SDK response correlation failed.');
            verify(Span::getCurrent()->getContext()->getSpanId() === $before, 'SDK scope leaked into next request.');
        }
        $failure = new DomainException('fixture exception');
        expectFailure(static fn() => $telemetry(Request::fake(), static fn() => throw $failure), DomainException::class, 'SDK exception boundary replaced application failure.');
        verify(Span::getCurrent()->getContext()->getSpanId() === $before, 'Exception leaked SDK scope.');
        $spans = $exporter->getSpans();
        verify(count($spans) === 3, 'SDK did not export each ended request span.');
        foreach (array_slice($spans, 0, 2) as $index => $span) {
            $status = $index === 0 ? 200 : 503;
            verify($span->getName() === 'GET checkout' && $span->getKind() === SpanKind::KIND_SERVER
                && $span->getTraceId() === $trace && $span->getParentSpanId() === $parent && $span->hasEnded(), 'Exported parent or span identity mismatch.');
            verify($span->getStartEpochNanos() >= $start - 1_000_000_000 && $span->getEndEpochNanos() >= $span->getStartEpochNanos(), 'SDK span timestamp is not wall-clock epoch nanoseconds.');
            verify($span->getAttributes()->get('http.status_code') === $status, 'Exported HTTP status differs.');
            if ($status === 503) {
                verify($span->getStatus()->getCode() === StatusCode::STATUS_ERROR, 'Failed server span status differs.');
            }
        }
        verify($spans[2]->getStatus()->getCode() === StatusCode::STATUS_ERROR && count($spans[2]->getEvents()) >= 1, 'Exception span missing error event/status.');
    } finally {
        $scope->detach();
        $provider->shutdown();
    }
    echo "Actual OpenTelemetry SDK spans, parent correlation, epoch time, HTTP status and exception/scope cleanup passed\n";
}
