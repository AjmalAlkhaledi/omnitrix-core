#!/usr/bin/env python3
"""Soak test: sustained Benchmark B load against Omnitrix with periodic sampling of RSS, open
connections, coroutines, event-loop lag and pool state (from /metrics).

Usage: benchmarks/soak.py [--minutes 10] [--conns 48] [--out benchmarks/results/soak.jsonl]
"""
import argparse, json, os, re, signal, subprocess, sys, time, urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from run import ROOT, PHP, tree_pids, rss_mb, cpu_seconds, wait_port  # noqa: E402


def metrics(port):
    text = urllib.request.urlopen(f"http://127.0.0.1:{port}/metrics", timeout=5).read().decode()
    out = {}
    for line in text.splitlines():
        if line.startswith("#"):
            continue
        m = re.match(r"^(omnitrix_(?:event_loop_lag_\w+|worker_coroutines|connections_active|worker_memory_bytes|db_pool_(?:in_use|idle|size|waiting|timeouts)|http_client_in_flight))(\{[^}]*\})? ([0-9.eE+-]+)$", line)
        if m:
            out[m.group(1) + (m.group(2) or "")] = float(m.group(3))
    return out


def fds(pids):
    total = 0
    for p in pids:
        try:
            total += len(os.listdir(f"/proc/{p}/fd"))
        except OSError:
            pass
    return total


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--minutes", type=float, default=10)
    ap.add_argument("--conns", type=int, default=48)
    ap.add_argument("--interval", type=int, default=30)
    ap.add_argument("--out", default=os.path.join(ROOT, "benchmarks/results/soak.jsonl"))
    a = ap.parse_args()
    port = 18721
    subprocess.run([PHP, os.path.join(ROOT, "benchmarks/setup.php")], check=True)
    upstream = subprocess.Popen([os.path.join(ROOT, "benchmarks/upstream/upstream"), "-addr", "127.0.0.1:18700", "-latency", "20ms"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    env = dict(os.environ, BENCH_WORKERS="2", BENCH_POOL="32", BENCH_PORT=str(port), BENCH_METRICS="1")
    sut = subprocess.Popen(["taskset", "-c", os.environ.get("BENCH_CPUS_MULTI", "0-1"), PHP, os.path.join(ROOT, "benchmarks/php/server.php")], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
    try:
        wait_port(port)
        total = int(a.minutes * 60)
        wrk = subprocess.Popen(["wrk", "-t2", f"-c{a.conns}", f"-d{total}s", "--timeout", "10s", "-s", os.path.join(ROOT, "benchmarks/b.lua"), f"http://127.0.0.1:{port}"], stdout=subprocess.PIPE, text=True)
        start = time.time()
        with open(a.out, "w") as out:
            while wrk.poll() is None:
                time.sleep(a.interval)
                pids = tree_pids(sut.pid)
                sample = {"t": round(time.time() - start), "rss_mb": round(rss_mb(pids), 1), "fds": fds(pids), "cpu_s": round(cpu_seconds(pids), 1)}
                try:
                    sample.update(metrics(port))
                except Exception as e:  # metrics endpoint unavailable counts as a finding
                    sample["metrics_error"] = str(e)
                out.write(json.dumps(sample) + "\n")
                out.flush()
                print(json.dumps(sample), flush=True)
            summary = re.search(r"RESULT (\{.*\})", wrk.stdout.read())
            result = json.loads(summary.group(1)) if summary else {"error": "no wrk summary"}
            result["rps"] = result.get("requests", 0) / max(1, result.get("duration_us", 1) / 1e6)
            out.write(json.dumps({"summary": result}) + "\n")
            print("SUMMARY " + json.dumps(result), flush=True)
    finally:
        os.killpg(sut.pid, signal.SIGTERM)
        sut.wait(20)
        print("sut exit code", sut.returncode, flush=True)
        upstream.terminate()


if __name__ == "__main__":
    main()
