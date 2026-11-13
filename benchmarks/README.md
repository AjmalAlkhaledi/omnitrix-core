# Benchmarks

A reproducible harness comparing an Omnitrix application with an equivalent Go `net/http`
service on two database-backed API workloads. No results are published in this repository;
run the harness on hardware representative of your deployment.

## Requirements

`wrk` (4.x), Go (for the reference service and the upstream stub), Python 3, `taskset`
(util-linux), and the local services from `bin/services`.

## Running

```sh
bin/services up
(cd benchmarks/go-ref && go build -o go-ref .) && (cd benchmarks/upstream && go build -o upstream .)
php benchmarks/setup.php                      # creates and seeds bench_users (10 000 rows)

export BENCH_CPUS_SINGLE=2 BENCH_CPUS_MULTI=2-3   # cores for the system under test
export BENCH_DB_CPUS=0-1                          # optional: pin MariaDB away from the SUT
python3 benchmarks/run.py --duration 20 --warmup 8 --reps 3 --levels 16,64,256 --configs single,multi
python3 benchmarks/soak.py --minutes 10           # optional: memory and latency drift over time
```

Set `OMNITRIX_PHP` to use a PHP binary other than `php`. Raw results are written to
`benchmarks/results/` (git-ignored): one JSON line per stack × benchmark × configuration ×
concurrency level, with every repetition's throughput and the median run's full metrics.

## Applications

| Component | Code | Notes |
|---|---|---|
| Omnitrix app | `php/server.php` | routing, kernel, `DB` facade (pooled PDO, cached prepared statements), `Http` facade (pooled coroutine client) |
| Go reference | `go-ref/main.go` | `net/http`, `database/sql` + go-sql-driver/mysql, statements prepared once, HTTP transport sized to the pool |
| Upstream | `upstream/main.go` | `/quote?id=` sleeps 20 ms and returns a small JSON document |
| Data | `setup.php` | `bench_users`, 10 000 rows, re-seeded before every matrix run |

**Benchmark A** — `POST /a {"id":N,"amount":1}`: parse JSON → `SELECT … WHERE id = ?` →
`UPDATE … SET visits = visits + 1, balance = balance + ? WHERE id = ?` → JSON.

**Benchmark B** — `GET /b?id=N`: `SELECT` → `GET upstream/quote?id=N` (20 ms) → compute a
score → `UPDATE … SET score = ?` → JSON.

Ids are uniformly random over the 10 000 rows (`a.lua`, `b.lua`).

## Equivalence

| Setting | single | multi |
|---|---|---|
| PHP workers / Go `GOMAXPROCS` | 1 / 1 | 2 / 2 |
| Database connections (total) | 32 | 64 (PHP: 32 per worker) |
| HTTP connections to the upstream | same as the database pool | same |

Both stacks use the same machine, database, upstream, SQL text and payloads, and run
back-to-back. Security features are not disabled for either stack.

## Procedure

For each combination the harness starts the system under test pinned to its cores, warms up
with the same load, measures with `wrk -t2 -cN --timeout 10s`, stops the service and repeats;
the median-throughput run is reported together with all repetitions. Recorded per run:
requests/s, p50/p90/p95/p99/max latency, errors (connect/read/write/timeout/non-2xx), CPU time
of the service's process tree over the measured window and its RSS before and after.

## Limitations

- `wrk` is closed-loop, so latency percentiles under saturation suffer from coordinated
  omission. Prefer an open-loop generator (wrk2, k6 constant-arrival) for tail latency.
- A single machine running load generator, database and service measures relative
  behaviour, not production capacity. Keep the load generator on separate cores or hosts.
- Small kernel accept queues (`net.core.somaxconn`) distort results at high connection
  counts; raise them before measuring.
