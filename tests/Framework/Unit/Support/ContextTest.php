<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Support;

use Omnitrix\Core\Queue\Job;
use Omnitrix\Core\Runtime\Context\ContextStorage;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Core\Runtime\Context\SharedContext;
use Omnitrix\Facades\Context;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Context::class)]
#[CoversClass(SharedContext::class)]
final class ContextTest extends TestCase
{
    public function testValuesAreRequestScopedAndSplitIntoVisibleAndHidden(): void
    {
        Context::add('outside', 1);
        self::assertNull(Context::get('outside'), 'no context outside a request or job');

        ContextStorage::bind(RequestContext::create());
        try {
            Context::add('tenant', 7);
            Context::addMany(['region' => 'eu']);
            Context::addHidden('token_id', 'abc');
            Context::push('trail', 'login');
            Context::push('trail', 'checkout', 'pay');
            self::assertSame(['tenant' => 7, 'region' => 'eu', 'trail' => ['login', 'checkout', 'pay']], Context::all());
            self::assertSame(['token_id' => 'abc'], Context::allHidden());
            self::assertTrue(Context::has('tenant'));
            self::assertFalse(Context::has('token_id'), 'hidden values are separate');
            self::assertSame('abc', Context::getHidden('token_id'));
            Context::forget('region');
            Context::forgetHidden('token_id');
            self::assertFalse(Context::has('region'));
            self::assertSame([], Context::allHidden());
            self::assertSame(['visible' => ['tenant' => 7, 'trail' => ['login', 'checkout', 'pay']], 'hidden' => []], SharedContext::dehydrate());
        } finally {
            ContextStorage::unbind();
        }
    }

    public function testJobsCarryTheContextAndOlderJobsStillDecode(): void
    {
        $job = Job::create('x', ['a' => 1], 'default', 3, ['visible' => ['tenant' => 7], 'hidden' => []]);
        $decoded = Job::fromJson($job->toJson());
        self::assertSame(['visible' => ['tenant' => 7], 'hidden' => []], $decoded->context);
        self::assertSame($decoded->context, $decoded->withAttempt('err')->context, 'retries keep the context');
        self::assertStringNotContainsString('"context"', Job::create('y', [])->toJson(), 'no context, no field');
        self::assertSame([], Job::fromJson('{"id":"1","name":"old","payload":{}}')->context);
    }
}
