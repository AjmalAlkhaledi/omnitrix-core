<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Auth;

use Omnitrix\Auth\Access\AuthorizationException;
use Omnitrix\Auth\Access\AuthorizationResponse;
use Omnitrix\Auth\Access\Can;
use Omnitrix\Auth\Access\Gate;
use Omnitrix\Contracts\Authenticatable;
use Omnitrix\Core\Container\Container;
use Omnitrix\Core\Http\Server\IncomingRequest;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Facades\Facade;
use Omnitrix\Http\Middleware\Pipeline;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Http\Request as SwooleRequest;
use PHPUnit\Framework\Attributes\CoversClass;

use function is_numeric;

final class GateUser implements Authenticatable
{
    public function __construct(public int $id, public bool $admin = false, public bool $banned = false) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken(?string $token): void {}
}

final class Article
{
    public function __construct(public int $authorId, public bool $published = false) {}
}

final class ArticlePolicy
{
    public function before(GateUser $user, string $ability): ?bool
    {
        return $user->banned ? false : null;
    }

    public function viewAny(?GateUser $user): bool
    {
        return true;
    }

    public function view(?GateUser $user, Article $article): bool
    {
        return $article->published || $user?->id === $article->authorId;
    }

    public function update(GateUser $user, Article $article): AuthorizationResponse
    {
        return $user->id === $article->authorId ? AuthorizationResponse::allow() : AuthorizationResponse::deny('You do not own this article');
    }

    public function forceDelete(GateUser $user, Article $article): AuthorizationResponse
    {
        return AuthorizationResponse::denyAsNotFound();
    }

    public function create(GateUser $user): bool
    {
        return $user->id > 0;
    }
}

#[CoversClass(Gate::class)]
#[CoversClass(Can::class)]
#[CoversClass(AuthorizationResponse::class)]
final class GateTest extends TestCase
{
    public function testAbilitiesPoliciesGuestsAndHooks(): void
    {
        $user = null;
        $gate = new Gate(static function () use (&$user): ?Authenticatable {
            return $user;
        });
        $gate->define('reports.view', static fn(GateUser $u): bool => $u->admin);
        $gate->define('status.view', static fn(?GateUser $u): bool => true);
        $gate->policy(Article::class, ArticlePolicy::class);
        $mine = new Article(authorId: 1);
        $public = new Article(authorId: 2, published: true);

        self::assertFalse($gate->allows('reports.view'), 'guests are denied unless the user parameter is nullable');
        self::assertTrue($gate->allows('status.view'));
        self::assertTrue($gate->allows('viewAny', Article::class));
        self::assertTrue($gate->allows('view', $public));
        self::assertFalse($gate->allows('view', $mine));
        self::assertFalse($gate->allows('update', $mine));
        self::assertFalse($gate->allows('unknown'), 'undefined abilities are denied');

        $user = new GateUser(1);
        self::assertTrue($gate->allows('view', $mine));
        self::assertTrue($gate->allows('update', $mine));
        self::assertTrue($gate->allows('create', Article::class), 'a class name argument is not passed to the policy');
        self::assertSame('You do not own this article', $gate->inspect('update', $public)->message);
        self::assertSame(404, $gate->inspect('force-delete', $mine)->status, 'ability names map to camelCase methods');
        self::assertFalse($gate->allows('reports.view'));
        self::assertTrue($gate->forUser(new GateUser(9, admin: true))->allows('reports.view'));
        self::assertFalse($gate->forUser(new GateUser(1, banned: true))->allows('view', $public), "the policy's before() decides first");
        self::assertTrue($gate->any(['reports.view', 'view'], $mine));
        self::assertFalse($gate->check(['reports.view', 'view'], $mine));

        $gate->before(static fn(GateUser $u, string $ability): ?bool => $u->admin ? true : null);
        self::assertTrue($gate->forUser(new GateUser(5, admin: true))->allows('update', $mine), 'a global before hook can allow');
        self::assertTrue($gate->allows('status.view'), 'hooks that do not accept guests are skipped for guests, not denying');
        $user = null;
        self::assertTrue($gate->allows('status.view'));

        $user = new GateUser(2);
        try {
            $gate->authorize('update', $mine);
            self::fail('authorize() must throw');
        } catch (AuthorizationException $e) {
            self::assertSame(403, $e->status());
            self::assertSame('You do not own this article', $e->getMessage());
        }
    }

    public function testTheCanMiddleware(): void
    {
        $gate = new Gate(static fn(): Authenticatable => new GateUser(1));
        $gate->policy(Article::class, ArticlePolicy::class);
        $container = new Container();
        $container->instance(Gate::class, $gate);
        Facade::setContainer($container);
        try {
            $articles = [1 => new Article(1), 2 => new Article(2)];
            $handler = static fn(): Response => Response::json(['ok' => true]);
            $update = Pipeline::compile([Can::ability('update', static fn(Request $r): Article => $articles[is_numeric($r->queryParam('id')) ? (int) $r->queryParam('id') : 0])], $handler);
            self::assertSame(200, $update(self::request('/articles?id=1'))->getStatus());
            $denied = $update(self::request('/articles?id=2'));
            self::assertSame(403, $denied->getStatus());
            self::assertStringContainsString('You do not own this article', $denied->getBody());
            self::assertSame(200, Pipeline::compile([Can::ability('create', Article::class)], $handler)(self::request('/articles'))->getStatus());
            self::assertSame(404, Pipeline::compile([Can::ability('forceDelete', static fn(): Article => $articles[1])], $handler)(self::request('/articles'))->getStatus());
        } finally {
            Facade::setContainer(null);
        }
    }

    private static function request(string $target): Request
    {
        $raw = SwooleRequest::create([]);
        self::assertInstanceOf(SwooleRequest::class, $raw);
        $raw->parse("GET {$target} HTTP/1.1\r\nHost: h\r\n\r\n");

        return new Request(new IncomingRequest($raw), RequestContext::create());
    }
}
