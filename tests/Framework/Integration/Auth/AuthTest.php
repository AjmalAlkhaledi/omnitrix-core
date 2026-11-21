<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Auth;

use DateTimeImmutable;
use Omnitrix\Application;
use Omnitrix\Auth\Access\Authorizable;
use Omnitrix\Auth\Authenticatable;
use Omnitrix\Auth\Authenticate;
use Omnitrix\Auth\AuthManager;
use Omnitrix\Auth\HasApiTokens;
use Omnitrix\Auth\PersonalAccessToken;
use Omnitrix\Auth\SessionGuard;
use Omnitrix\Auth\TokenGuard;
use Omnitrix\Contracts\Authenticatable as AuthenticatableContract;
use Omnitrix\Core\Http\Server\IncomingRequest;
use Omnitrix\Core\Runtime\Context\ContextStorage;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Database\Migrations\Migrator;
use Omnitrix\Database\Model;
use Omnitrix\Database\Schema\Blueprint;
use Omnitrix\Database\Schema\Schema;
use Omnitrix\Encryption\AesEncrypter;
use Omnitrix\Facades\Auth;
use Omnitrix\Facades\DB;
use Omnitrix\Http\CookieJar;
use Omnitrix\Http\Middleware\Pipeline;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Security\Authorize;
use Omnitrix\Session\StartSession;
use Omnitrix\Tests\Integration\Database\ModelsAndMigrationsTest;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Http\Request as SwooleRequest;
use PHPUnit\Framework\Attributes\CoversClass;

use function dirname;
use function implode;
use function password_hash;
use function rawurlencode;
use function str_starts_with;

use const PASSWORD_BCRYPT;

/**
 * @property int         $id
 * @property string      $email
 * @property string      $password
 * @property string|null $remember_token
 */
final class AuthUser extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use Authorizable;
    use HasApiTokens;

    protected static ?string $table = 'users';

    /** @var list<string> */
    protected static array $fillable = ['name', 'email', 'password'];

    /** @var list<string> */
    protected static array $hidden = ['password', 'remember_token'];

    /** @var array<string, string> */
    protected static array $casts = ['password' => 'hashed'];
}

#[CoversClass(AuthManager::class)]
#[CoversClass(SessionGuard::class)]
#[CoversClass(TokenGuard::class)]
#[CoversClass(Authenticate::class)]
final class AuthTest extends TestCase
{
    /** @var array<string, string> */
    private array $jar = [];

    protected function setUp(): void
    {
        parent::setUp();
        ModelsAndMigrationsTest::createDatabase();
    }

    public function testPersonalAccessTokens(): void
    {
        $this->withApp(function (): void {
            $ann = AuthUser::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'secret']);
            self::assertTrue(str_starts_with($ann->password, '$2y$'), 'the hashed cast stores a hash');
            $reader = $ann->createToken('cli', ['orders.read']);
            $expired = $ann->createToken('old', ['*'], new DateTimeImmutable('-1 hour'));
            self::assertStringStartsWith($reader->accessToken->id . '|', $reader->plainTextToken);
            self::assertNotSame($reader->plainTextToken, PersonalAccessToken::findOrFail($reader->accessToken->id)->token, 'only the hash is stored');

            $api = Pipeline::compile([Authenticate::using('api')], static fn(): Response => Response::json(['id' => Auth::id(), 'can_read' => Auth::token()?->can('orders.read'), 'can_write' => Auth::token()?->can('orders.write')]));
            self::assertSame('{"id":' . $ann->id . ',"can_read":true,"can_write":false}', $this->send($api, 'GET', '/me', ['Authorization' => 'Bearer ' . $reader->plainTextToken])->getBody());
            self::assertNotNull(PersonalAccessToken::findOrFail($reader->accessToken->id)->last_used_at);
            foreach (['', $expired->plainTextToken, $reader->accessToken->id . '|wrong', 'garbage', '999|x'] as $bad) {
                self::assertSame(401, $this->send($api, 'GET', '/me', $bad === '' ? [] : ['Authorization' => 'Bearer ' . $bad])->getStatus(), "rejected: {$bad}");
            }
            $write = Pipeline::compile([Authenticate::using('api'), Authorize::ability('orders.write')], static fn(): Response => Response::json([]));
            self::assertSame(403, $this->send($write, 'POST', '/orders', ['Authorization' => 'Bearer ' . $reader->plainTextToken])->getStatus(), 'token abilities reach Authorize');
            self::assertSame(2, $ann->tokens()->count());
        });
    }

    public function testSessionLoginRememberAndLogout(): void
    {
        $this->withApp(function (): void {
            $ann = AuthUser::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'secret']);
            $app = Pipeline::compile([new StartSession()], static function (Request $r): Response {
                return match ($r->path()) {
                    '/login' => Response::json(['ok' => Auth::attempt(['email' => $r->queryParam('email'), 'password' => $r->queryParam('password')], remember: $r->queryParam('remember') === '1')]),
                    '/logout' => (static function (): Response {
                        Auth::logout();

                        return Response::json(['ok' => true]);
                    })(),
                    default => Response::json(['user' => Auth::guard('web')->id()]),
                };
            });
            self::assertSame('{"ok":false}', $this->send($app, 'GET', '/login?email=ann@example.com&password=wrong')->getBody());
            self::assertSame('{"user":null}', $this->send($app, 'GET', '/me')->getBody());
            self::assertSame('{"ok":true}', $this->send($app, 'GET', '/login?email=ann@example.com&password=secret&remember=1')->getBody());
            self::assertSame('{"user":' . $ann->id . '}', $this->send($app, 'GET', '/me')->getBody());
            self::assertNotNull(AuthUser::findOrFail($ann->id)->remember_token, 'a hashed remember token is stored');

            unset($this->jar['omnitrix_session']); // the session expired; the remember cookie is left
            self::assertSame('{"user":' . $ann->id . '}', $this->send($app, 'GET', '/me')->getBody(), 'logged in again from the remember cookie');
            self::assertArrayHasKey('omnitrix_session', $this->jar, 'with a new session');

            $this->send($app, 'GET', '/logout');
            self::assertNull(AuthUser::findOrFail($ann->id)->remember_token, 'logout revokes the remember token');
            self::assertArrayNotHasKey('remember_web', $this->jar);
            self::assertSame('{"user":null}', $this->send($app, 'GET', '/me')->getBody());
        });
    }

    public function testAbilitiesDefinedOnTheApplicationReachUserModels(): void
    {
        $this->withApp(function (): void {
            $ann = AuthUser::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'secret']);
            $bob = AuthUser::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret']);
            self::assertTrue($ann->can('profile.edit', $ann));
            self::assertFalse($bob->can('profile.edit', $ann));
            self::assertTrue($bob->cannot('profile.edit', $ann));
        }, static function (Application $app): void {
            $app->define('profile.edit', static fn(AuthUser $user, AuthUser $profile): bool => $user->id === $profile->id);
        });
    }

    public function testOutdatedHashesAreUpgradedAtLogin(): void
    {
        $this->withApp(function (): void {
            $old = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 5]);
            DB::connection()->table('users')->insert(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => $old, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
            self::assertTrue(Auth::guard('web')->validate(['email' => 'bob@example.com', 'password' => 'secret']));
            $stored = AuthUser::where('email', 'bob@example.com')->firstOrFail()->password;
            self::assertNotSame($old, $stored);
            self::assertStringStartsWith('$2y$04$', $stored, 'rehashed with the configured cost');
        });
    }

    /** @param (callable(Application): void)|null $configure */
    private function withApp(callable $body, ?callable $configure = null): void
    {
        $config = ModelsAndMigrationsTest::config() + [
            'app' => ['key' => AesEncrypter::generateKey()],
            'hashing' => ['bcrypt' => ['rounds' => 4]],
            'session' => ['driver' => 'array'],
            'auth' => [
                'default' => 'api',
                'guards' => ['api' => ['driver' => 'token', 'provider' => 'users'], 'web' => ['driver' => 'session', 'provider' => 'users']],
                'providers' => ['users' => ['model' => AuthUser::class]],
            ],
        ];
        $app = Application::create(['server' => ['port' => 18660]] + $config);
        if ($configure !== null) {
            $configure($app);
        }
        $app->withServices(static function () use ($body): void {
            (new Migrator(DB::manager(), dirname(__DIR__, 2) . '/Fixtures/migrations'))->fresh();
            $schema = new Schema(DB::connection());
            $schema->table('users', static function (Blueprint $table): void {
                $table->string('remember_token', 100)->nullable();
            });
            $schema->create('personal_access_tokens', static function (Blueprint $table): void {
                $table->id();
                $table->string('tokenable_type');
                $table->unsignedBigInteger('tokenable_id');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->json('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
            $body();
        });
    }

    /**
     * @param callable(Request): Response $pipeline
     * @param array<string, string>        $headers
     */
    private function send(callable $pipeline, string $method, string $target, array $headers = []): Response
    {
        $lines = ["{$method} {$target} HTTP/1.1", 'Host: h'];
        if ($this->jar !== []) {
            $pairs = [];
            foreach ($this->jar as $name => $value) {
                $pairs[] = $name . '=' . rawurlencode($value);
            }
            $lines[] = 'Cookie: ' . implode('; ', $pairs);
        }
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $raw = SwooleRequest::create([]);
        self::assertInstanceOf(SwooleRequest::class, $raw);
        $raw->parse(implode("\r\n", $lines) . "\r\n\r\n");
        $context = RequestContext::create();
        ContextStorage::bind($context);
        try {
            $request = new Request(new IncomingRequest($raw), $context);
            $context->set(Request::CONTEXT_KEY, $request);
            $response = CookieJar::flush($pipeline($request));
        } finally {
            ContextStorage::unbind();
        }
        foreach ($response->getCookies() as $cookie) {
            if ($cookie->expires === 1) {
                unset($this->jar[$cookie->name]);
            } else {
                $this->jar[$cookie->name] = $cookie->value;
            }
        }

        return $response;
    }
}
