<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Observability;

use Omnitrix\Core\Observability\MetricsRegistry;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function substr_count;

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

    public function testLabelledSeriesShareOneTypeLinePerFamily(): void
    {
        $text = (new MetricsRegistry(8))->render([
            'omnitrix_db_pool_size{connection="a",worker="0"}' => 2,
            'omnitrix_db_cache_hits_total{connection="a",worker="0"}' => 5,
            'omnitrix_db_pool_size{connection="b",worker="0"}' => 3,
            'omnitrix_db_cache_hits_total{connection="b",worker="0"}' => 7,
        ]);

        self::assertStringNotContainsString('# TYPE omnitrix_db_pool_size{', $text, 'TYPE lines name the family, not a series');
        self::assertSame(1, substr_count($text, '# TYPE omnitrix_db_pool_size gauge'));
        self::assertSame(1, substr_count($text, '# TYPE omnitrix_db_cache_hits_total counter'), '*_total families are counters');
        self::assertStringContainsString(
            "# TYPE omnitrix_db_pool_size gauge\nomnitrix_db_pool_size{connection=\"a\",worker=\"0\"} 2\nomnitrix_db_pool_size{connection=\"b\",worker=\"0\"} 3\n",
            $text,
            'all series of a family are adjacent',
        );
    }

    public function testWorkerSeriesAreSharedReplacedAndExpire(): void
    {
        $m = new MetricsRegistry(8, 64);
        $m->publish(['omnitrix_db_pool_size{worker="0"}' => 2, 'omnitrix_db_pool_idle{worker="0"}' => 1]);
        self::assertStringContainsString("omnitrix_db_pool_size{worker=\"0\"} 2\n", $m->render(), 'rendered by any process');
        $m->publish(['omnitrix_db_pool_size{worker="0"}' => 2, 'omnitrix_db_pool_idle{worker="0"}' => 1], 1000);
        self::assertSame(['omnitrix_db_pool_idle{worker="0"}' => 1.0, 'omnitrix_db_pool_size{worker="0"}' => 2.0], $m->workerSeries(30, 1000));

        $m->publish(['omnitrix_db_pool_size{worker="0"}' => 3], 1001);
        self::assertSame(['omnitrix_db_pool_size{worker="0"}' => 3.0], $m->workerSeries(30, 1001), 'series no longer reported are removed');

        self::assertSame([], $m->workerSeries(30, 1100), 'a worker silent for longer than maxAge is dropped');
        $m->publish(['omnitrix_db_pool_size{worker="0"}' => 3], 1200);
        $m->forget();
        self::assertSame([], $m->workerSeries(30, 1200), 'a stopping worker removes its series');
    }
}
