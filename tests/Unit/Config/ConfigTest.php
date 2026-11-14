<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Config;

use Omnitrix\Config\Config;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    public function testEachFileBecomesASection(): void
    {
        $dir = sys_get_temp_dir() . '/omnitrix-config-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/server.php', "<?php return ['port' => 9000];");
        file_put_contents($dir . '/queue.php', "<?php return ['jobs' => ['a.b' => 'X']];");
        file_put_contents($dir . '/notes.txt', 'ignored');

        $config = Config::load($dir);

        self::assertSame(['queue' => ['jobs' => ['a.b' => 'X']], 'server' => ['port' => 9000]], $config);
        self::assertSame(9000, Config::get($config, 'server.port'));
        self::assertSame(['a.b' => 'X'], Config::get($config, 'queue.jobs'));
        self::assertSame('fallback', Config::get($config, 'server.host', 'fallback'));
        self::assertSame('fallback', Config::string($config, 'server.port', 'fallback'));
    }

    public function testFilesMustReturnArrays(): void
    {
        $dir = sys_get_temp_dir() . '/omnitrix-config-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/app.php', "<?php return 'oops';");

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must return an array');
        Config::load($dir);
    }

    public function testMissingDirectoryIsReported(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        Config::load('/nonexistent/omnitrix-config');
    }
}
