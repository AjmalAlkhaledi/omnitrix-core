#!/usr/bin/env python3
"""Omnitrix vs Go benchmark harness (see benchmarks/README.md).

Usage: benchmarks/run.py [--duration 20] [--warmup 8] [--reps 3] [--only php|go] [--bench a|b]
                         [--levels 16,64,256] [--configs single,multi] [--out benchmarks/results/run.jsonl]
"""
import argparse, json, os, re, signal, subprocess, sys, time, statistics, urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHP = os.environ.get("OMNITRIX_PHP", "php")
# CPU sets for the system under test; isolate them from the load generator and database.
CORES = {"single": os.environ.get("BENCH_CPUS_SINGLE", "0"), "multi": os.environ.get("BENCH_CPUS_MULTI", "0-1")}
PORTS = {"php": 18720, "go": 18710}


def tree_pids(root):
    """All descendants of root (scans /proc; /proc/*/task/*/children is not available on every kernel)."""
    parents = {}
    for entry in os.listdir("/proc"):
        if entry.isdigit():
            try:
                with open(f"/proc/{entry}/stat") as f:
                    parents.setdefault(int(f.read().rsplit(")", 1)[1].split()[1]), []).append(int(entry))
            except (OSError, IndexError, ValueError):
                pass
    pids, frontier = [root], [root]
    while frontier:
        nxt = [c for p in frontier for c in parents.get(p, [])]
        pids += nxt
        frontier = nxt
    return pids


def cpu_seconds(pids):
    tck = os.sysconf("SC_CLK_TCK")
    total = 0
    for p in pids:
        try:
            with open(f"/proc/{p}/stat") as f:
                parts = f.read().rsplit(")", 1)[1].split()
            total += int(parts[11]) + int(parts[12])
        except OSError:
            pass
    return total / tck


def rss_mb(pids):
    total = 0
    for p in pids:
        try:
            with open(f"/proc/{p}/status") as f:
                m = re.search(r"VmRSS:\s+(\d+)", f.read())
                total += int(m.group(1)) if m else 0
        except OSError:
            pass
    return total / 1024


def wait_port(port, timeout=20):
    deadline = time.time() + timeout
    while time.time() < deadline:
        try:
            urllib.request.urlopen(f"http://127.0.0.1:{port}/nope", timeout=1)
            return
        except urllib.error.HTTPError:
            return
        except Exception:
            time.sleep(0.2)
    raise RuntimeError(f"port {port} did not open")


def start_sut(stack, config):
    workers = 1 if config == "single" else 2
    pool = 32 if config == "single" else 64
    env = dict(os.environ)
    if stack == "php":
        env.update(BENCH_WORKERS=str(workers), BENCH_POOL=str(pool // workers), BENCH_PORT=str(PORTS["php"]))
        cmd = ["taskset", "-c", CORES[config], PHP, os.path.join(ROOT, "benchmarks/php/server.php")]
    else:
        env.update(GOMAXPROCS=str(workers))
        cmd = ["taskset", "-c", CORES[config], os.path.join(ROOT, "benchmarks/go-ref/go-ref"), "-addr", f"127.0.0.1:{PORTS['go']}", "-pool", str(pool)]
    proc = subprocess.Popen(cmd, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
    wait_port(PORTS[stack])
    return proc, {"workers": workers, "db_pool_total": pool}


def wrk(port, bench, conns, seconds):
    script = os.path.join(ROOT, f"benchmarks/{bench}.lua")
    cmd = ["wrk", "-t2", f"-c{conns}", f"-d{seconds}s", "--timeout", "10s", "-s", script, f"http://127.0.0.1:{port}"]
    proc = subprocess.run(cmd, capture_output=True, text=True)
    m = re.search(r"RESULT (\{.*\})", proc.stdout)
    if not m:
        raise RuntimeError("wrk output without RESULT:\n" + proc.stdout + proc.stderr)
    return json.loads(m.group(1))


def measure(stack, bench, config, conns, duration, warmup):
    proc, meta = start_sut(stack, config)
    try:
        wrk(PORTS[stack], bench, conns, warmup)
        pids = tree_pids(proc.pid)
        rss_before = rss_mb(pids)
        cpu_before, t0 = cpu_seconds(pids), time.time()
        r = wrk(PORTS[stack], bench, conns, duration)
        elapsed = time.time() - t0
        pids = tree_pids(proc.pid)
        r.update(meta)
        r["rps"] = r["requests"] / (r["duration_us"] / 1e6)
        r["error_rate"] = (r["errors"]) / max(1, r["requests"])
        r["cpu_cores"] = (cpu_seconds(pids) - cpu_before) / elapsed
        r["rss_mb_start"] = round(rss_before, 1)
        r["rss_mb_end"] = round(rss_mb(pids), 1)
        return r
    finally:
        os.killpg(proc.pid, signal.SIGTERM)
        try:
            proc.wait(15)
        except subprocess.TimeoutExpired:
            os.killpg(proc.pid, signal.SIGKILL)
        time.sleep(1)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--duration", type=int, default=20)
    ap.add_argument("--warmup", type=int, default=8)
    ap.add_argument("--reps", type=int, default=3)
    ap.add_argument("--only", choices=["php", "go"])
    ap.add_argument("--bench", choices=["a", "b"])
    ap.add_argument("--levels", default="16,64,256")
    ap.add_argument("--configs", default="single,multi")
    ap.add_argument("--latency", default="20ms")
    ap.add_argument("--out", default=os.path.join(ROOT, "benchmarks/results/run.jsonl"))
    a = ap.parse_args()
    subprocess.run([PHP, os.path.join(ROOT, "benchmarks/setup.php")], check=True)
    db_cpus, db_pid_file = os.environ.get("BENCH_DB_CPUS"), os.path.join(ROOT, "var/mysql/mariadbd.pid")
    if db_cpus and os.path.exists(db_pid_file):  # optionally pin a database started by bin/services
        with open(db_pid_file) as f:
            subprocess.run(["taskset", "-a", "-p", "-c", db_cpus, f.read().strip()], check=True, stdout=subprocess.DEVNULL)
    upstream = subprocess.Popen([os.path.join(ROOT, "benchmarks/upstream/upstream"), "-addr", "127.0.0.1:18700", "-latency", a.latency], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    time.sleep(1)
    stacks = [a.only] if a.only else ["php", "go"]
    benches = [a.bench] if a.bench else ["a", "b"]
    try:
        with open(a.out, "a") as out:
            for bench in benches:
                for config in a.configs.split(","):
                    for conns in [int(x) for x in a.levels.split(",")]:
                        for stack in stacks:
                            runs = [measure(stack, bench, config, conns, a.duration, a.warmup) for _ in range(a.reps)]
                            med = sorted(runs, key=lambda r: r["rps"])[len(runs) // 2]
                            row = {"stack": stack, "bench": bench, "config": config, "conns": conns, "reps": a.reps,
                                   "rps_all": [round(r["rps"]) for r in runs], "median": med, "ts": time.strftime("%Y-%m-%dT%H:%M:%S")}
                            out.write(json.dumps(row) + "\n")
                            out.flush()
                            print(f"{bench} {config:6} c={conns:<4} {stack:3} rps={med['rps']:8.0f} p50={med['p50_us']/1000:7.2f}ms p95={med['p95_us']/1000:7.2f}ms p99={med['p99_us']/1000:7.2f}ms err={med['errors']} cpu={med['cpu_cores']:.2f} rss={med['rss_mb_end']}MB all={row['rps_all']}", flush=True)
    finally:
        upstream.terminate()


if __name__ == "__main__":
    main()
