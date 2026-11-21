<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Security;

use Omnitrix\Console\Commands\KeyGenerateCommand;
use Omnitrix\Console\Console;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;
use Omnitrix\Encryption\AesEncrypter;
use Omnitrix\Encryption\DecryptException;
use Omnitrix\Hashing\HashManager;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function uniqid;

#[CoversClass(HashManager::class)]
#[CoversClass(AesEncrypter::class)]
#[CoversClass(KeyGenerateCommand::class)]
final class HashingAndEncryptionTest extends TestCase
{
    public function testBcryptAndArgonHashesVerifyAcrossDrivers(): void
    {
        $bcrypt = HashManager::fromConfig(['bcrypt' => ['rounds' => 4]]);
        $hash = $bcrypt->make('secret');
        self::assertStringStartsWith('$2y$04$', $hash);
        self::assertTrue($bcrypt->check('secret', $hash));
        self::assertFalse($bcrypt->check('wrong', $hash));
        self::assertFalse($bcrypt->check('secret', ''));
        self::assertFalse($bcrypt->needsRehash($hash));
        self::assertTrue(HashManager::fromConfig(['bcrypt' => ['rounds' => 5]])->needsRehash($hash), 'other cost');

        $argon = HashManager::fromConfig(['driver' => 'argon2id', 'argon' => ['memory' => 1024, 'time' => 1]]);
        $argonHash = $argon->make('secret');
        self::assertStringStartsWith('$argon2id$', $argonHash);
        self::assertTrue($argon->check('secret', $argonHash));
        self::assertTrue($argon->check('secret', $hash), 'bcrypt hashes stay valid after switching drivers');
        self::assertTrue($argon->needsRehash($hash));
        self::assertSame('argon2id', $argon->info($argonHash)['algo']);
    }

    public function testHashingConfigurationIsValidated(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('hashing.bcrypt.rounds');
        HashManager::fromConfig(['bcrypt' => ['rounds' => 2]]);
    }

    public function testEncryptionRoundTripsDetectsTamperingAndRotatesKeys(): void
    {
        $old = AesEncrypter::generateKey();
        $new = AesEncrypter::generateKey();
        $before = new AesEncrypter($old);
        $payload = $before->encrypt(['user' => 10, 'roles' => ['admin']]);
        self::assertSame(['user' => 10, 'roles' => ['admin']], $before->decrypt($payload));
        self::assertNotSame($payload, $before->encrypt(['user' => 10, 'roles' => ['admin']]), 'a fresh IV each time');
        self::assertSame('héllo', $before->decryptString($before->encryptString('héllo')));

        $rotated = new AesEncrypter($new, [$old]);
        self::assertSame(['user' => 10, 'roles' => ['admin']], $rotated->decrypt($payload), 'previous keys still decrypt');
        self::assertNotSame(substr($payload, 0, 8), substr($rotated->encrypt(1), 0, 8), 'new payloads name the new key');

        $bad = ['', 'nodot', substr($payload, 0, -2) . 'xx', 'deadbeef.' . substr($payload, 9)];
        $rejected = 0;
        foreach ($bad as $payloadText) {
            try {
                $rotated->decrypt($payloadText);
            } catch (DecryptException) {
                $rejected++;
            }
        }
        self::assertSame(4, $rejected, 'malformed, changed and unknown-key payloads are rejected');
        $this->expectException(DecryptException::class);
        (new AesEncrypter($new))->decrypt($payload);
    }

    public function testKeysAreValidatedAndGeneratedIntoDotEnv(): void
    {
        self::assertNull(AesEncrypter::fromConfig(['key' => '']));
        self::assertSame(51, strlen(AesEncrypter::generateKey()), "'base64:' + 44 characters");
        try {
            AesEncrypter::fromConfig(['key' => 'base64:c2hvcnQ=']);
            self::fail('a short key must be rejected');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('32 bytes', $e->getMessage());
        }

        $dir = sys_get_temp_dir() . '/omnitrix-key-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/.env', "APP_NAME=Demo\nAPP_KEY=\n");
        $console = new Console($dir);
        $run = static function (array $args) use ($console): int {
            $command = $console->application()->find('key:generate');

            return $command->run(new ArrayInput($args), new BufferedOutput());
        };
        self::assertSame(0, $run([]));
        $env = (string) file_get_contents($dir . '/.env');
        self::assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=$/m', $env);
        self::assertStringContainsString('APP_NAME=Demo', $env);
        self::assertSame(1, $run([]), 'an existing key is kept without --force');
        self::assertSame($env, file_get_contents($dir . '/.env'));
        self::assertSame(0, $run(['--force' => true]));
        self::assertNotSame($env, file_get_contents($dir . '/.env'));
    }
}
