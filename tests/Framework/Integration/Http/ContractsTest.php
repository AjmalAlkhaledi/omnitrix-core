<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Http;

use Omnitrix\Application;
use Omnitrix\Contracts;
use Omnitrix\Encryption\AesEncrypter;
use Omnitrix\Facades\Facade;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/** Framework services are available under their contracts, so applications can depend on interfaces. */
#[CoversNothing]
final class ContractsTest extends TestCase
{
    public function testServicesResolveByContract(): void
    {
        $app = Application::create(['server' => ['port' => 18710], 'app' => ['key' => AesEncrypter::generateKey()], 'mail' => ['default' => 'array'], 'broadcasting' => ['default' => 'null']]);
        $app->withServices(static function (): void {
            $container = Facade::container();
            self::assertNotNull($container);
            foreach ([Contracts\Hasher::class, Contracts\Encrypter::class, Contracts\EventDispatcher::class, Contracts\UrlGenerator::class, Contracts\Gate::class, Contracts\Mailer::class, Contracts\Broadcaster::class] as $contract) {
                self::assertTrue($container->has($contract), $contract);
                self::assertInstanceOf($contract, $container->get($contract));
            }
        });
    }
}
