<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Auth;

use Omnitrix\Application;
use Omnitrix\Auth\Authenticatable;
use Omnitrix\Auth\Authenticate;
use Omnitrix\Auth\AuthRoutes;
use Omnitrix\Auth\HasApiTokens;
use Omnitrix\Auth\Passwords\PasswordBroker;
use Omnitrix\Auth\Verification\EnsureEmailIsVerified;
use Omnitrix\Auth\Verification\MustVerifyEmail;
use Omnitrix\Contracts\Authenticatable as AuthenticatableContract;
use Omnitrix\Contracts\MustVerifyEmail as MustVerifyEmailContract;
use Omnitrix\Core\Container\Container;
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
use Omnitrix\Facades\Facade;
use Omnitrix\Http\HttpKernel;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Mail\Mailer;
use Omnitrix\Mail\Transport\ArrayTransport;
use Omnitrix\Notifications\Notifiable;
use Omnitrix\Tests\Integration\Database\ModelsAndMigrationsTest;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Http\Request as SwooleRequest;
use PHPUnit\Framework\Attributes\CoversClass;

use function dirname;
use function implode;
use function json_encode;
use function parse_url;
use function preg_match;
use function str_replace;
use function strlen;

/**
 * @property int         $id
 * @property string      $email
 * @property string      $password
 * @property mixed       $email_verified_at
 * @property string|null $remember_token
 */
final class VerifiedUser extends Model implements AuthenticatableContract, MustVerifyEmailContract
{
    use Authenticatable;
    use HasApiTokens;
    use MustVerifyEmail;
    use Notifiable;

    protected static ?string $table = 'users';

    /** @var list<string> */
    protected static array $fillable = ['name', 'email', 'password'];

    /** @var array<string, string> */
    protected static array $casts = ['password' => 'hashed', 'email_verified_at' => 'datetime'];
}

#[CoversClass(AuthRoutes::class)]
#[CoversClass(PasswordBroker::class)]
#[CoversClass(EnsureEmailIsVerified::class)]
final class VerificationAndPasswordResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ModelsAndMigrationsTest::createDatabase();
    }

    public function testEmailVerificationFlow(): void
    {
        $this->withApp(function (HttpKernel $kernel, ArrayTransport $mail): void {
            $ann = VerifiedUser::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'secret123']);
            $token = $ann->createToken('app')->plainTextToken;
            $auth = ['Authorization' => "Bearer {$token}"];
            self::assertSame(403, $this->send($kernel, 'GET', '/api/orders', $auth)->getStatus(), 'unverified users are kept out');

            self::assertSame(202, $this->send($kernel, 'POST', '/email/verification-notification', $auth)->getStatus());
            self::assertSame(401, $this->send($kernel, 'POST', '/email/verification-notification')->getStatus(), 'resending needs a login');
            self::assertCount(1, $mail->messages);
            self::assertSame(1, preg_match('#https?://[^\s]+/email/verify/[^\s]+#', (string) $mail->messages[0]->getText(), $m));
            $link = $m[0] ?? "";
            $parts = parse_url($link);
            $target = ($parts['path'] ?? '') . '?' . ($parts['query'] ?? '');

            self::assertSame(403, $this->send($kernel, 'GET', str_replace('/verify/' . $ann->id . '/', '/verify/' . $ann->id . '/x', $target))->getStatus(), 'a changed link is rejected');
            self::assertSame(403, $this->send($kernel, 'GET', $parts['path'] ?? '')->getStatus(), 'an unsigned link is rejected');
            self::assertSame('{"verified":true}', $this->send($kernel, 'GET', $target)->getBody(), 'the signed link verifies without a login');
            self::assertTrue(VerifiedUser::findOrFail($ann->id)->hasVerifiedEmail());
            self::assertSame(200, $this->send($kernel, 'GET', '/api/orders', $auth)->getStatus());
            self::assertSame('{"verified":true}', $this->send($kernel, 'POST', '/email/verification-notification', $auth)->getBody(), 'nothing to send once verified');
        });
    }

    public function testPasswordResetFlow(): void
    {
        $this->withApp(function (HttpKernel $kernel, ArrayTransport $mail): void {
            $ann = VerifiedUser::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'old-password']);
            self::assertSame(202, $this->send($kernel, 'POST', '/forgot-password', [], ['email' => 'nobody@example.com'])->getStatus(), 'unknown addresses get the same answer');
            self::assertCount(0, $mail->messages);
            self::assertSame(202, $this->send($kernel, 'POST', '/forgot-password', [], ['email' => 'ann@example.com'])->getStatus());
            self::assertSame(429, $this->send($kernel, 'POST', '/forgot-password', [], ['email' => 'ann@example.com'])->getStatus(), 'links are throttled');
            self::assertCount(1, $mail->messages);
            self::assertSame(1, preg_match('/token=([a-f0-9]{64})/', (string) $mail->messages[0]->getText(), $m), 'the configured URL with the token');
            $token = $m[1] ?? "";
            self::assertNotSame($token, DB::connection()->table('password_reset_tokens')->where('email', 'ann@example.com')->value('token'), 'only a hash is stored');

            self::assertSame(422, $this->send($kernel, 'POST', '/reset-password', [], ['email' => 'ann@example.com', 'token' => str_replace('a', 'b', $token), 'password' => 'new-password'])->getStatus());
            self::assertSame('{"reset":true}', $this->send($kernel, 'POST', '/reset-password', [], ['email' => 'ann@example.com', 'token' => $token, 'password' => 'new-password'])->getBody());
            self::assertTrue(Auth::guard('api')->validate(['email' => 'ann@example.com', 'password' => 'new-password']));
            self::assertFalse(Auth::guard('api')->validate(['email' => 'ann@example.com', 'password' => 'old-password']));
            self::assertSame(422, $this->send($kernel, 'POST', '/reset-password', [], ['email' => 'ann@example.com', 'token' => $token, 'password' => 'another-one'])->getStatus(), 'tokens are single use');
            unset($ann);
        });
    }

    /** @param callable(HttpKernel, ArrayTransport): void $body */
    private function withApp(callable $body): void
    {
        $config = ModelsAndMigrationsTest::config() + [
            'app' => ['key' => AesEncrypter::generateKey(), 'url' => 'https://api.example.com'],
            'hashing' => ['bcrypt' => ['rounds' => 4]],
            'mail' => ['default' => 'array', 'from' => ['address' => 'hello@example.com']],
            'auth' => [
                'guards' => ['api' => ['driver' => 'token', 'provider' => 'users']],
                'providers' => ['users' => ['model' => VerifiedUser::class]],
                'passwords' => ['url' => 'https://app.example.com/reset?token={token}&email={email}', 'throttle' => 60],
            ],
        ];
        $app = Application::create(['server' => ['port' => 18680]] + $config);
        AuthRoutes::verification($app->router());
        AuthRoutes::passwordReset($app->router());
        $app->get('/api/orders', static fn(): Response => Response::json([]))->middleware(Authenticate::using('api'), new EnsureEmailIsVerified());
        $app->withServices(static function () use ($app, $body): void {
            (new Migrator(DB::manager(), dirname(__DIR__, 2) . '/Fixtures/migrations'))->fresh();
            $schema = new Schema(DB::connection());
            $schema->table('users', static function (Blueprint $table): void {
                $table->timestamp('email_verified_at')->nullable();
                $table->string('remember_token', 100)->nullable();
            });
            $schema->create('password_reset_tokens', static function (Blueprint $table): void {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
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
            $container = Facade::container();
            self::assertInstanceOf(Container::class, $container);
            $mailer = $container->get(Mailer::class);
            self::assertInstanceOf(Mailer::class, $mailer);
            $transport = $mailer->transport();
            self::assertInstanceOf(ArrayTransport::class, $transport);
            $body($app->makeKernel($container), $transport);
        });
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $json
     */
    private function send(HttpKernel $kernel, string $method, string $target, array $headers = [], array $json = []): Response
    {
        $lines = ["{$method} {$target} HTTP/1.1", 'Host: api.example.com'];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $body = $json === [] ? '' : (string) json_encode($json);
        if ($body !== '') {
            $lines[] = 'Content-Type: application/json';
            $lines[] = 'Content-Length: ' . strlen($body);
        }
        $raw = SwooleRequest::create([]);
        self::assertInstanceOf(SwooleRequest::class, $raw);
        $raw->parse(implode("\r\n", $lines) . "\r\n\r\n" . $body);
        $context = RequestContext::create();
        ContextStorage::bind($context);
        try {
            $request = new Request(new IncomingRequest($raw), $context);
            $context->set(Request::CONTEXT_KEY, $request);

            return $kernel->handle($request);
        } finally {
            ContextStorage::unbind();
        }
    }
}
