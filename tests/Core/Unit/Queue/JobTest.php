<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Queue;

use InvalidArgumentException;
use Omnitrix\Core\Queue\Job;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function strlen;

#[CoversClass(Job::class)]
final class JobTest extends TestCase
{
    public function testJsonRoundTripAndAttempts(): void
    {
        $job = Job::create('emails.send', ['user_id' => 10, 'ratio' => 1.0], 'mail', 2);
        self::assertSame(32, strlen($job->id));
        $copy = Job::fromJson($job->toJson());
        self::assertEquals($job, $copy);
        self::assertSame(1.0, $copy->payload['ratio']);
        self::assertTrue($job->hasAttemptsLeft());
        $failed = $job->withAttempt('boom')->withAttempt('boom again');
        self::assertSame(2, $failed->attempts);
        self::assertSame('boom again', $failed->lastError);
        self::assertFalse($failed->hasAttemptsLeft());
        self::assertSame($job->id, $failed->id);
    }

    public function testRejectsInvalidJobs(): void
    {
        foreach (['not json', '[]', '{"id":"x"}'] as $bad) {
            try {
                Job::fromJson($bad);
                self::fail('accepted ' . $bad);
            } catch (InvalidArgumentException) {
            }
        }
        try {
            Job::create('', []);
            self::fail('empty name accepted');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(InvalidArgumentException::class);
        Job::create('x', ['bad' => "\xB1"])->toJson();
    }
}
