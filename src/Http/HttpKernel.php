<?php

declare(strict_types=1);

namespace Omnitrix\Http;

use Closure;
use JsonSerializable;
use LogicException;
use Omnitrix\Core\Http\HttpErrorInterface;
use Omnitrix\Core\Http\Server\IncomingRequest;
use Omnitrix\Core\Http\Server\ResponseWriter;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Core\Runtime\Exception\ExceptionHandlerInterface;
use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Middleware\Pipeline;
use Omnitrix\Routing\HandlerResolver;
use Omnitrix\Routing\Route;
use Omnitrix\Routing\Router;
use Throwable;

use function array_merge;
use function get_debug_type;
use function is_array;
use function is_string;

/**
 * The per-worker HTTP kernel: request → router → middleware pipeline → handler → response.
 *
 * Installed as the handler of {@see \Omnitrix\Core\Http\Server\HttpServerAdapter}. All throwables
 * are converted to responses by {@see ErrorResponseFactory} so middleware above the failure
 * point still sees a Response; non-HTTP exceptions are additionally reported.
 *
 * Handler return values: a {@see Response}; an array or JsonSerializable (→ JSON 200); a string
 * (→ text/plain 200); null (→ 204). Anything else is a programming error (500).
 */
final class HttpKernel
{
    /** @var list<MiddlewareInterface|callable(Request, Next): Response> */
    private array $middleware = [];

    private readonly ResponseEmitter $emitter;

    public function __construct(
        private readonly Router $router,
        private readonly HandlerResolver $resolver,
        private readonly ErrorResponseFactory $errors,
        private readonly ExceptionHandlerInterface $exceptionHandler,
    ) {
        $this->emitter = new ResponseEmitter();
    }

    /**
     * Global middleware, run for every matched route in registration order.
     *
     * @param MiddlewareInterface|callable(Request, Next): Response ...$middleware
     */
    public function middleware(MiddlewareInterface|callable ...$middleware): self
    {
        foreach ($middleware as $layer) {
            $this->middleware[] = $layer;
        }

        return $this;
    }

    public function router(): Router
    {
        return $this->router;
    }

    /** Entry point for the server adapter. */
    public function __invoke(IncomingRequest $incoming, ResponseWriter $writer, RequestContext $context): void
    {
        $request = new Request($incoming, $context);
        $response = $this->handle($request);
        $context->set('http.route', $request->routePattern() ?? 'unmatched');
        $this->emitter->emit($response, $writer);
    }

    /** Produces a Response for a Request (also usable without a server, e.g. in tests). */
    public function handle(Request $request): Response
    {
        try {
            $match = $this->router->dispatch($request->method(), $request->path());
            $request->setRoute($match->params, $match->route->getName(), $match->route->pattern);
            $pipeline = $this->router->pipelineFor($match->route, $this->compileRoute(...));

            return $pipeline($request);
        } catch (Throwable $e) {
            // Routing failures (404/405) and exceptions thrown by middleware itself land here.
            return $this->convert($e, $request, 'http.kernel');
        }
    }

    /**
     * Compiles global middleware + route middleware + handler. Exceptions raised by the handler
     * are converted to responses right here, so every middleware layer sees a Response (and can
     * add headers, log, measure) regardless of success or failure.
     *
     * @return Closure(Request): Response
     */
    private function compileRoute(Route $route): Closure
    {
        $handler = $this->resolver->resolve($route->handler);
        $invoke = function (Request $request) use ($handler): Response {
            try {
                return self::normalise($handler($request));
            } catch (Throwable $e) {
                return $this->convert($e, $request, 'http.handler');
            }
        };

        return Pipeline::compile(array_merge($this->middleware, $route->getMiddleware()), $invoke);
    }

    private function convert(Throwable $e, Request $request, string $origin): Response
    {
        if (!$e instanceof HttpErrorInterface || $e->status() >= 500) {
            $this->exceptionHandler->handle($e, ['origin' => $origin, 'request_id' => $request->id(), 'path' => $request->path(), 'route' => $request->routeName()]);
        }

        return $this->errors->fromThrowable($e, $request->id());
    }

    private static function normalise(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if ($result === null) {
            return Response::noContent();
        }
        if (is_array($result) || $result instanceof JsonSerializable) {
            return Response::json($result);
        }
        if (is_string($result)) {
            return Response::text($result);
        }

        throw new LogicException('Route handler returned ' . get_debug_type($result) . '; expected Response, array, JsonSerializable, string or null');
    }
}
