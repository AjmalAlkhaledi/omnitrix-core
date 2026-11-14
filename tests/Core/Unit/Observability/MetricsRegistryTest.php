<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Observability;

use Omnitrix\Core\Observability\MetricsRegistry;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MetricsRegistry::class)]
final class MetricsRegistryTest extends TestCase
{
    public function testMetricsRegistryRendersPrometheusHistograms(): void
    {
        $m = new MetricsRegistry(64);
        $m->observe('/users/{id}', 'GET', 200, 0.003);
        $m->observe('/users/{id}', 'GET', 200, 0.2);
        $m->observe('/users/{id}', 'GET', 500, 0.02);
        $m->observe('unmatched', 'GET', 404, 0.001);
        $text = $m->render(['omnitrix_event_loop_lag_ms' => 1.5]);

        self::assertStringContainsString('# TYPE omnitrix_http_requests_total counter', $text);
        self::assertStringContainsString('omnitrix_http_requests_total{route="/users/{id}",method="GET",status="200"} 2', $text);
        self::assertStringContainsString('omnitrix_http_requests_total{route="/users/{id}",method="GET",status="500"} 1', $text);
        self::assertStringContainsString('omnitrix_http_request_duration_seconds_bucket{route="/users/{id}",method="GET",status="200",le="0.005"} 1', $text);
        self::assertStringContainsString('omnitrix_http_request_duration_seconds_bucket{route="/users/{id}",method="GET",status="200",le="0.25"} 2', $text, 'buckets are cumulative');
        self::assertStringContainsString('omnitrix_http_request_duration_seconds_bucket{route="/users/{id}",method="GET",status="200",le="+Inf"} 2', $text);
        self::assertStringContainsString('omnitrix_http_request_duration_seconds_sum{route="/users/{id}",method="GET",status="200"} 0.203000', $text);
        self::assertStringContainsString('omnitrix_event_loop_lag_ms 1.5', $text);
        self::assertSame(3, $m->maxSeen());
    }
}
