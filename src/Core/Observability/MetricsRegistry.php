<?php

declare(strict_types=1);

namespace Omnitrix\Core\Observability;

use OpenSwoole\Table;

use function array_push;
use function count;
use function explode;
use function implode;
use function is_int;
use function is_string;
use function max;
use function md5;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function strlen;
use function strpos;
use function substr;

/**
 * Request metrics in OpenSwoole shared memory: every worker records into the same table (create
 * the registry in the master, before the server starts), so `/metrics` served by any worker
 * reports the whole server. Labels are route pattern, method and status — bounded cardinality.
 */
final class MetricsRegistry
{
    /** Histogram bucket upper bounds in seconds. */
    public const array BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    private readonly Table $table;

    private readonly Table $labels;

    public function __construct(int $maxSeries = 4096)
    {
        $this->table = new Table($maxSeries);
        $this->table->column('count', Table::TYPE_INT);
        $this->table->column('sum_us', Table::TYPE_INT);
        foreach (self::BUCKETS as $i => $_) {
            $this->table->column('b' . $i, Table::TYPE_INT);
        }
        $this->table->create();
        $this->labels = new Table($maxSeries);
        $this->labels->column('label', Table::TYPE_STRING, 512);
        $this->labels->create();
    }

    public function observe(string $route, string $method, int $status, float $seconds): void
    {
        $label = $route . "\x1f" . $method . "\x1f" . $status;
        $key = strlen($label) <= 60 ? $label : md5($label);
        if (!$this->table->exists($key)) {
            $row = ['count' => 0, 'sum_us' => 0];
            foreach (self::BUCKETS as $i => $_) {
                $row['b' . $i] = 0;
            }
            if (!$this->table->set($key, $row)) {
                return; // table full: drop rather than fail the request
            }
            $this->labels->set($key, ['label' => $label]);
        }
        $this->table->incr($key, 'count');
        $this->table->incr($key, 'sum_us', (int) ($seconds * 1e6));
        foreach (self::BUCKETS as $i => $bound) {
            if ($seconds <= $bound) {
                $this->table->incr($key, 'b' . $i);
                break; // cumulative sums are computed at render time
            }
        }
    }

    /** @return list<array{route: string, method: string, status: string, count: int, sum: float, buckets: list<int>}> */
    public function series(): array
    {
        $out = [];
        foreach ($this->table as $key => $row) {
            $labelRow = $this->labels->get((string) $key, 'label');
            $parts = explode("\x1f", is_string($labelRow) ? $labelRow : '');
            if (count($parts) !== 3) {
                continue;
            }
            $buckets = [];
            foreach (self::BUCKETS as $i => $_) {
                $v = $row['b' . $i] ?? 0;
                $buckets[] = is_int($v) ? $v : 0;
            }
            $count = $row['count'] ?? 0;
            $sum = $row['sum_us'] ?? 0;
            $out[] = ['route' => $parts[0], 'method' => $parts[1], 'status' => $parts[2], 'count' => is_int($count) ? $count : 0, 'sum' => (is_int($sum) ? $sum : 0) / 1e6, 'buckets' => $buckets];
        }

        return $out;
    }

    /**
     * Prometheus text exposition (v0.0.4).
     *
     * @param array<string, int|float> $gauges extra gauges (name => value)
     */
    public function render(array $gauges = []): string
    {
        $lines = [
            '# HELP omnitrix_http_requests_total HTTP requests by route pattern, method and status.',
            '# TYPE omnitrix_http_requests_total counter',
        ];
        $series = $this->series();
        foreach ($series as $s) {
            $lines[] = sprintf('omnitrix_http_requests_total{%s} %d', self::labels($s), $s['count']);
        }
        $lines[] = '# HELP omnitrix_http_request_duration_seconds Request handling time.';
        $lines[] = '# TYPE omnitrix_http_request_duration_seconds histogram';
        foreach ($series as $s) {
            $cumulative = 0;
            foreach (self::BUCKETS as $i => $bound) {
                $cumulative += $s['buckets'][$i];
                $lines[] = sprintf('omnitrix_http_request_duration_seconds_bucket{%s,le="%s"} %d', self::labels($s), (string) $bound, $cumulative);
            }
            $lines[] = sprintf('omnitrix_http_request_duration_seconds_bucket{%s,le="+Inf"} %d', self::labels($s), $s['count']);
            $lines[] = sprintf('omnitrix_http_request_duration_seconds_sum{%s} %.6F', self::labels($s), $s['sum']);
            $lines[] = sprintf('omnitrix_http_request_duration_seconds_count{%s} %d', self::labels($s), $s['count']);
        }
        // One TYPE line per metric family (the name without labels), with every series of the
        // family on adjacent lines, as the exposition format requires. `*_total` names are counters.
        $families = [];
        foreach ($gauges as $series => $value) {
            $brace = strpos($series, '{');
            $families[$brace === false ? $series : substr($series, 0, $brace)][] = $series . ' ' . $value;
        }
        foreach ($families as $family => $samples) {
            $lines[] = '# TYPE ' . $family . (str_ends_with($family, '_total') ? ' counter' : ' gauge');
            array_push($lines, ...$samples);
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param array{route: string, method: string, status: string} $s */
    private static function labels(array $s): string
    {
        return sprintf('route="%s",method="%s",status="%s"', self::escape($s['route']), self::escape($s['method']), self::escape($s['status']));
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    public function maxSeen(): int
    {
        return max(0, $this->table->count());
    }
}
