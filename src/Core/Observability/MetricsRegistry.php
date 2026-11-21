<?php

declare(strict_types=1);

namespace Omnitrix\Core\Observability;

use OpenSwoole\Table;

use function abs;
use function array_push;
use function count;
use function explode;
use function floor;
use function implode;
use function is_float;
use function is_int;
use function is_string;
use function ksort;
use function max;
use function md5;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function strlen;
use function strpos;
use function substr;
use function time;

/**
 * Request metrics in OpenSwoole shared memory: every worker records into the same table (create
 * the registry in the master, before the server starts), so `/metrics` served by any worker
 * reports the whole server. Per-worker statistics are published into a second table
 * ({@see publish()}) and rendered by every worker as well. Labels are route pattern, method and status — bounded cardinality.
 */
final class MetricsRegistry
{
    /** Histogram bucket upper bounds in seconds. */
    public const array BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    private readonly Table $table;

    private readonly Table $labels;

    /** Per-worker series (`name{labels}` => value), written by each worker for every worker to render. */
    private readonly Table $workerSeries;

    /** @var array<string, true> keys this process published (per process: each worker has its copy) */
    private array $published = [];

    public function __construct(int $maxSeries = 4096, int $maxWorkerSeries = 4096)
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
        $this->workerSeries = new Table($maxWorkerSeries);
        $this->workerSeries->column('series', Table::TYPE_STRING, 400);
        $this->workerSeries->column('value', Table::TYPE_FLOAT);
        $this->workerSeries->column('at', Table::TYPE_INT);
        $this->workerSeries->create();
    }

    /**
     * Stores this worker's series (labelled with the worker, e.g. pool and cache statistics) so
     * that `/metrics` served by any worker reports every worker. Series this worker published
     * before and no longer reports are removed.
     *
     * @param array<string, int|float> $series `name{labels}` => value
     */
    public function publish(array $series, ?int $now = null): void
    {
        $now ??= time();
        $current = [];
        foreach ($series as $name => $value) {
            $key = md5($name);
            if ($this->workerSeries->set($key, ['series' => $name, 'value' => (float) $value, 'at' => $now])) {
                $current[$key] = true;
            }
        }
        foreach ($this->published as $key => $_) {
            if (!isset($current[$key])) {
                $this->workerSeries->del($key);
            }
        }
        $this->published = $current;
    }

    /** Removes this worker's series (worker stop). */
    public function forget(): void
    {
        $this->publish([]);
    }

    /**
     * Series published by all workers within `$maxAge` seconds; older rows (a worker that died
     * without stopping) are removed.
     *
     * @return array<string, float>
     */
    public function workerSeries(int $maxAge = 30, ?int $now = null): array
    {
        $now ??= time();
        $out = [];
        $stale = [];
        foreach ($this->workerSeries as $key => $row) {
            $at = $row['at'] ?? 0;
            $series = $row['series'] ?? '';
            $value = $row['value'] ?? 0.0;
            if (!is_int($at) || $at < $now - $maxAge) {
                $stale[] = (string) $key;
                continue;
            }
            if (is_string($series) && $series !== '') {
                $out[$series] = is_float($value) || is_int($value) ? (float) $value : 0.0;
            }
        }
        foreach ($stale as $key) {
            $this->workerSeries->del($key);
        }
        ksort($out);

        return $out;
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
     * @param array<string, int|float> $gauges extra series (name => value); the series every worker
     *                                       published are added
     */
    public function render(array $gauges = []): string
    {
        $gauges += $this->workerSeries();
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
            $families[$brace === false ? $series : substr($series, 0, $brace)][] = $series . ' ' . self::number($value);
        }
        foreach ($families as $family => $samples) {
            $lines[] = '# TYPE ' . $family . (str_ends_with($family, '_total') ? ' counter' : ' gauge');
            array_push($lines, ...$samples);
        }

        return implode("\n", $lines) . "\n";
    }

    /** Integral values without a fraction (counters published as floats stay readable). */
    private static function number(int|float $value): string
    {
        return is_float($value) && floor($value) === $value && abs($value) < 1e15 ? (string) (int) $value : (string) $value;
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
