<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Server;

use Closure;
use Omnitrix\Core\Runtime\Context\ContextStorage;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Core\Runtime\Exception\ExceptionHandlerInterface;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Throwable;

use function json_encode;

/**
 * The single entry point of every HTTP request coroutine.
 *
 * Responsibilities, in order: create and bind the {@see RequestContext}; invoke the handler;
 * on failure report it and answer with a generic 500 (never leaking internals) if nothing was
 * sent yet; always dispose the context (running deferred callbacks) and unbind it. Nothing
 * escapes this method: an uncaught exception here would kill the worker.
 *
 * @internal
 */
final class RequestEntrypoint
{
    /** @var Closure(Request, Response, RequestContext): void */
    private readonly Closure $handler;

    /** @param callable(Request, Response, RequestContext): void $handler */
    public function __construct(callable $handler, private readonly ExceptionHandlerInterface $exceptionHandler)
    {
        $this->handler = $handler(...);
    }

    public function __invoke(Request $request, Response $response): void
    {
        $context = RequestContext::create();
        ContextStorage::bind($context);

        try {
            ($this->handler)($request, $response, $context);
        } catch (Throwable $e) {
            $this->exceptionHandler->handle($e, ['origin' => 'request', 'request_id' => $context->id]);
            $this->failSafely($response, $context);
        } finally {
            foreach ($context->dispose() as $failure) {
                $this->exceptionHandler->handle($failure, ['origin' => 'request.defer', 'request_id' => $context->id]);
            }
            ContextStorage::unbind();
        }
    }

    private function failSafely(Response $response, RequestContext $context): void
    {
        try {
            if (!$response->isWritable()) {
                return;
            }
            $body = json_encode(['error' => 'Internal Server Error', 'request_id' => $context->id]);
            $response->status(500);
            $response->header('Content-Type', 'application/json');
            $response->end($body === false ? '{"error":"Internal Server Error"}' : $body);
        } catch (Throwable $e) {
            $this->exceptionHandler->handle($e, ['origin' => 'request.failsafe', 'request_id' => $context->id]);
        }
    }
}
