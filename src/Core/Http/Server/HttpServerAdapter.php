<?php

declare(strict_types=1);

namespace Omnitrix\Core\Http\Server;

use Closure;
use LogicException;
use Omnitrix\Core\Http\HttpErrorInterface;
use Omnitrix\Core\Http\Status;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Core\Runtime\Coroutine\Timers;
use Omnitrix\Core\Runtime\Exception\ExceptionHandlerInterface;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use RuntimeException;
use Throwable;

use function json_encode;
use function max;
use function microtime;
use function round;

use const JSON_UNESCAPED_SLASHES;

/**
 * Bridges the runtime entry point to the HTTP transport layer.
 *
 * Per request: build {@see IncomingRequest} and {@see ResponseWriter}; set the request id
 * header; enforce the body size limit (413) before any handler code runs; arm the request
 * timeout (503 to the client while the handler keeps running, writes after that are ignored);
 * run the handler; map {@see HttpErrorInterface} errors to JSON error responses with safe messages. Any
 * other throwable is rethrown to the {@see \Omnitrix\Core\Runtime\Server\RequestEntrypoint}, which
 * logs it and answers 500.
 *
 * Installed with `$app->handle(new HttpServerAdapter(...))`.
 */
final class HttpServerAdapter
{
    /** @var Closure(IncomingRequest, ResponseWriter, RequestContext): void */
    private readonly Closure $handler;

    /** @param callable(IncomingRequest, ResponseWriter, RequestContext): void $handler */
    public function __construct(
        private readonly HttpServerConfig $config,
        callable $handler,
        private readonly ExceptionHandlerInterface $exceptionHandler,
    ) {
        $this->handler = $handler(...);
    }

    public function __invoke(Request $raw, Response $rawResponse, RequestContext $context): void
    {
        $request = new IncomingRequest($raw, $this->config->jsonMaxDepth);
        $writer = new ResponseWriter(new OpenSwooleResponseSink($rawResponse), $request->method() === 'HEAD', $this->config->jsonFlags);
        if ($this->config->requestIdHeader !== null) {
            $writer->header($this->config->requestIdHeader, $context->id);
        }
        $context->set('http.method', $request->method());
        $context->set('http.path', $request->path());

        $timer = $this->armTimeout($writer, $context);

        try {
            $declared = $request->declaredContentLength();
            if (($declared !== null && $declared > $this->config->maxBodySize) || $request->bodyLength() > $this->config->maxBodySize) {
                throw RequestRejectedException::payloadTooLarge($this->config->maxBodySize);
            }

            ($this->handler)($request, $writer, $context);

            if (!$writer->isEnded()) {
                // A handler that returns without ending is a programming error; do not leave the
                // client hanging until the timeout.
                $this->exceptionHandler->handle(new LogicException('Handler returned without ending the response'), ['origin' => 'http', 'request_id' => $context->id, 'path' => $request->path()]);
                $this->error($writer, $context, Status::INTERNAL_SERVER_ERROR, Status::phrase(Status::INTERNAL_SERVER_ERROR));
            }
        } catch (HttpErrorInterface $e) {
            if ($e->status() >= 500) {
                $this->exceptionHandler->handle($e, ['origin' => 'http', 'request_id' => $context->id, 'path' => $request->path()]);
            }
            $this->error($writer, $context, $e->status(), $e->getMessage(), $e->headers(), $e->details());
        } finally {
            if ($timer !== null) {
                Timers::clear($timer);
            }
            $context->set('http.status', $writer->statusCode());
            $context->set('http.bytes', $writer->bytesWritten());
        }
    }

    private function armTimeout(ResponseWriter $writer, RequestContext $context): ?int
    {
        $seconds = $this->config->requestTimeout;
        if ($seconds <= 0.0) {
            return null;
        }
        $context->setDeadline(microtime(true) + $seconds);
        $ms = max(1, (int) round($seconds * 1000));
        return Timers::after($ms, function () use ($writer, $context, $seconds): void {
            if ($writer->isEnded()) {
                return;
            }
            $this->exceptionHandler->handle(new RuntimeException("Request exceeded the {$seconds}s request_timeout"), ['origin' => 'http.timeout', 'request_id' => $context->id, 'path' => $context->get('http.path')]);
            $this->error($writer, $context, Status::SERVICE_UNAVAILABLE, 'Request timed out', ['Retry-After' => '1']);
        });
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $details
     */
    private function error(ResponseWriter $writer, RequestContext $context, int $status, string $message, array $headers = [], array $details = []): void
    {
        if ($writer->isEnded()) {
            return;
        }
        if ($writer->headersSent()) {
            // Streaming already started: we cannot change the status; just terminate the body.
            $writer->end();

            return;
        }
        $body = json_encode(['error' => ['status' => $status, 'message' => $message] + $details, 'request_id' => $context->id], JSON_UNESCAPED_SLASHES);
        try {
            $writer->status($status)->headers($headers)->header('Content-Type', 'application/json');
            $writer->end($body === false ? '{"error":{"status":' . $status . '}}' : $body);
        } catch (Throwable $e) {
            $this->exceptionHandler->handle($e, ['origin' => 'http.error', 'request_id' => $context->id]);
        }
    }
}
