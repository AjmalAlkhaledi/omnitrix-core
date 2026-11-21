<?php

declare(strict_types=1);

namespace Omnitrix\Http;

use Omnitrix\Core\Http\Server\IncomingRequest;
use Omnitrix\Core\Runtime\Context\ContextStorage;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Validation\Validator;

use function array_key_exists;
use function is_array;
use function preg_match;
use function strncasecmp;
use function substr;
use function trim;

/**
 * The request object handed to middleware and route handlers.
 *
 * Wraps the transport-level {@see IncomingRequest} and adds routing results (route parameters,
 * route name) and per-request attributes set by middleware (authenticated user, tenant…).
 * Attributes are stored on the object itself — it lives exactly as long as the request.
 */
final class Request
{
    /** @var array<string, string> */
    private array $routeParams = [];

    private ?string $routeName = null;

    private ?string $routePattern = null;

    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @internal requests are created by the framework's HTTP kernel */
    /** Request-context key holding the current request. */
    public const string CONTEXT_KEY = 'http.request';

    /** @var array<string, string>|null cookies after middleware transformed them */
    private ?array $cookies = null;

    public function __construct(private readonly IncomingRequest $incoming, private readonly RequestContext $context) {}

    /** @internal transport-level request; application code uses the accessors on this class */
    /** The request being handled in this coroutine (or an ancestor), if any. */
    public static function current(): ?self
    {
        $request = ContextStorage::current()?->get(self::CONTEXT_KEY);

        return $request instanceof self ? $request : null;
    }

    public function incoming(): IncomingRequest
    {
        return $this->incoming;
    }

    public function context(): RequestContext
    {
        return $this->context;
    }

    public function id(): string
    {
        return $this->context->id;
    }

    // --- transport accessors (delegated) -------------------------------------------------

    public function method(): string
    {
        return $this->incoming->method();
    }

    public function isMethod(string $method): bool
    {
        return $this->incoming->isMethod($method);
    }

    public function path(): string
    {
        return $this->incoming->path();
    }

    public function queryString(): string
    {
        return $this->incoming->queryString();
    }

    /** @return array<string, mixed> */
    public function query(): array
    {
        return $this->incoming->query();
    }

    public function queryParam(string $name, mixed $default = null): mixed
    {
        return $this->incoming->queryParam($name, $default);
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->incoming->headers();
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->incoming->header($name, $default);
    }

    public function hasHeader(string $name): bool
    {
        return $this->incoming->hasHeader($name);
    }

    /** Bearer token from the Authorization header, or null. */
    public function bearerToken(): ?string
    {
        $auth = $this->incoming->header('authorization');
        if ($auth === null || strncasecmp($auth, 'Bearer ', 7) !== 0) {
            return null;
        }
        $token = trim(substr($auth, 7));

        return $token === '' ? null : $token;
    }

    /** @return array<string, string> */
    public function cookies(): array
    {
        return $this->cookies ?? $this->incoming->cookies();
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        if ($this->cookies !== null) {
            return $this->cookies[$name] ?? $default;
        }

        return $this->incoming->cookie($name, $default);
    }

    /**
     * Replaces the cookies the application sees (middleware that decrypts cookies).
     *
     * @param array<string, string> $cookies
     */
    public function replaceCookies(array $cookies): void
    {
        $this->cookies = $cookies;
    }

    public function contentType(): ?string
    {
        return $this->incoming->contentType();
    }

    public function isJson(): bool
    {
        return $this->incoming->isJson();
    }

    public function body(): string
    {
        return $this->incoming->body();
    }

    /** @see IncomingRequest::json() */
    public function json(): mixed
    {
        return $this->incoming->json();
    }

    /** @return array<string, mixed> @see IncomingRequest::jsonObject() */
    public function jsonObject(): array
    {
        return $this->incoming->jsonObject();
    }

    /** @return array<string, mixed> */
    public function form(): array
    {
        return $this->incoming->form();
    }

    /**
     * OpenSwoole's raw upload entries; prefer {@see file()} and {@see allFiles()}.
     *
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return $this->incoming->files();
    }

    /** @var array<string, UploadedFile|list<UploadedFile>>|null */
    private ?array $uploads = null;

    /**
     * Uploaded files by field name; `photos[]` gives a list.
     *
     * @return array<string, UploadedFile|list<UploadedFile>>
     */
    public function allFiles(): array
    {
        return $this->uploads ??= UploadedFile::normalize($this->incoming->files());
    }

    /** The uploaded file of a field (the first one for `photos[]`), or null. */
    public function file(string $name): ?UploadedFile
    {
        $file = $this->allFiles()[$name] ?? null;

        return is_array($file) ? ($file[0] ?? null) : $file;
    }

    public function hasFile(string $name): bool
    {
        return $this->file($name)?->isValid() === true;
    }

    public function remoteAddress(): ?string
    {
        return $this->incoming->remoteAddress();
    }

    public function protocolVersion(): string
    {
        return $this->incoming->protocolVersion();
    }

    /**
     * A single input value: JSON object field (for JSON requests), then form field, then
     * query parameter.
     */
    public function input(string $name, mixed $default = null): mixed
    {
        if ($this->incoming->isJson()) {
            $json = $this->incoming->json();
            if (is_array($json) && array_key_exists($name, $json)) {
                return $json[$name];
            }
        }
        $form = $this->incoming->form();
        if (array_key_exists($name, $form)) {
            return $form[$name];
        }

        return $this->incoming->queryParam($name, $default);
    }

    /**
     * All input merged (query < form < JSON object), later sources overriding earlier ones.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $all = $this->incoming->query() + [];
        foreach ($this->incoming->form() as $k => $v) {
            $all[$k] = $v;
        }
        if ($this->incoming->isJson()) {
            $json = $this->incoming->json();
            if (is_array($json)) {
                foreach ($json as $k => $v) {
                    $all[(string) $k] = $v;
                }
            }
        }

        return $all;
    }

    /**
     * Validates `all()` input and the uploaded files against rule strings and returns only the
     * validated fields (files as {@see UploadedFile}).
     *
     * @param array<string, string> $rules e.g. ['email' => 'required|email', 'age' => 'int|min:18']
     * @return array<string, mixed>
     *
     * @throws \Omnitrix\Validation\ValidationException (422)
     */
    public function validate(array $rules): array
    {
        return (new Validator($rules))->validate($this->allFiles() + $this->all());
    }

    // --- routing ----------------------------------------------------------------------------

    /**
     * @internal set by the router after matching
     *
     * @param array<string, string> $params
     */
    public function setRoute(array $params, ?string $name, ?string $pattern = null): void
    {
        $this->routeParams = $params;
        $this->routeName = $name;
        $this->routePattern = $pattern;
    }

    /** The matched route pattern (bounded cardinality; safe as a metrics label), or null. */
    public function routePattern(): ?string
    {
        return $this->routePattern;
    }

    /** Route parameter (e.g. `{id}`) as the raw URL segment, or `$default`. */
    public function route(string $name, mixed $default = null): mixed
    {
        return $this->routeParams[$name] ?? $default;
    }

    /** Route parameter cast to int; throws a 404 when it is not an integer. */
    public function routeInt(string $name): int
    {
        $value = $this->routeParams[$name] ?? null;
        if ($value === null || preg_match('/^-?\d{1,18}$/', $value) !== 1) {
            throw new Exception\NotFoundException();
        }

        return (int) $value;
    }

    /** @return array<string, string> */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    public function routeName(): ?string
    {
        return $this->routeName;
    }

    // --- attributes -------------------------------------------------------------------------

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    public function hasAttribute(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return $this->attributes;
    }
}
