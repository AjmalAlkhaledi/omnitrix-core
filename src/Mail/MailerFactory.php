<?php

declare(strict_types=1);

namespace Omnitrix\Mail;

use Closure;
use InvalidArgumentException;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;
use Omnitrix\Mail\Transport\ArrayTransport;
use Omnitrix\Mail\Transport\LogTransport;
use Omnitrix\Mail\Transport\SmtpTransport;
use Psr\Log\LoggerInterface;

use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * Builds the mailer from the `mail` section:
 *
 * ```php
 * 'mail' => [
 *     'default' => 'smtp',
 *     'from' => ['address' => 'hello@example.com', 'name' => 'Example'],
 *     'mailers' => [
 *         'smtp' => ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => '…', 'password' => '…'],
 *         'log' => [],
 *         'array' => [],
 *     ],
 * ],
 * ```
 *
 * @internal
 */
final class MailerFactory
{
    private function __construct() {}

    /**
     * @param array<string, mixed>                                              $config
     * @param (Closure(string, array<string, mixed>, float, ?string): mixed)|null $queue
     */
    public static function make(array $config, LoggerInterface $logger, ?Closure $queue): Mailer
    {
        $default = $config['default'] ?? 'log';
        $mailers = $config['mailers'] ?? [];
        if (!is_string($default) || !is_array($mailers)) {
            throw InvalidConfigurationException::at('mail', "expected 'default' (a mailer name) and 'mailers'");
        }
        $settings = $mailers[$default] ?? ($default === 'log' || $default === 'array' ? ['transport' => $default] : null);
        if (!is_array($settings)) {
            throw InvalidConfigurationException::at("mail.mailers.{$default}", 'the default mailer is not configured');
        }
        $transport = $settings['transport'] ?? $default;
        $from = null;
        $fromConfig = $config['from'] ?? null;
        if (is_array($fromConfig) && is_string($fromConfig['address'] ?? null) && $fromConfig['address'] !== '') {
            try {
                $from = new Address($fromConfig['address'], is_string($fromConfig['name'] ?? null) ? $fromConfig['name'] : '');
            } catch (InvalidArgumentException $e) {
                throw InvalidConfigurationException::at('mail.from', $e->getMessage());
            }
        }

        return new Mailer(match ($transport) {
            'smtp' => self::smtp($settings, $default),
            'log' => new LogTransport($logger),
            'array' => new ArrayTransport(),
            default => throw InvalidConfigurationException::at("mail.mailers.{$default}.transport", "expected 'smtp', 'log' or 'array'"),
        }, $from, $queue);
    }

    /** @param array<mixed> $c */
    private static function smtp(array $c, string $name): SmtpTransport
    {
        $host = $c['host'] ?? null;
        $port = $c['port'] ?? 587;
        $encryption = $c['encryption'] ?? 'tls';
        $timeout = $c['timeout'] ?? 10;
        if (!is_string($host) || $host === '' || !is_int($port) || !in_array($encryption, ['tls', 'ssl', 'none'], true) || (!is_int($timeout) && !is_float($timeout))) {
            throw InvalidConfigurationException::at("mail.mailers.{$name}", "expected 'host', integer 'port', 'encryption' (tls, ssl, none) and 'timeout'");
        }
        $username = is_string($c['username'] ?? null) && $c['username'] !== '' ? $c['username'] : null;
        $password = is_string($c['password'] ?? null) ? $c['password'] : null;
        $local = is_string($c['local_domain'] ?? null) ? $c['local_domain'] : 'localhost';
        $verify = $c['verify_peer'] ?? true;

        return new SmtpTransport($host, $port, $encryption, $username, $password, (float) $timeout, $local, ['verify_peer' => is_bool($verify) ? $verify : true]);
    }
}
