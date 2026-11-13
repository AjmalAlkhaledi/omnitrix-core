-- Benchmark A request generator: POST /a with a random existing id.
math.randomseed(os.time())
wrk.method = "POST"
wrk.headers["Content-Type"] = "application/json"
request = function()
  return wrk.format(nil, "/a", nil, string.format('{"id":%d,"amount":1}', math.random(1, 10000)))
end

-- Machine-readable summary (wrk's built-in output lacks p95).
done = function(summary, latency, requests)
  local errors = summary.errors.connect + summary.errors.read + summary.errors.write + summary.errors.status + summary.errors.timeout
  io.write(string.format('RESULT {"requests":%d,"duration_us":%d,"errors":%d,"non2xx":%d,"timeouts":%d,"p50_us":%d,"p90_us":%d,"p95_us":%d,"p99_us":%d,"max_us":%d,"mean_us":%.1f}\n',
    summary.requests, summary.duration, errors, summary.errors.status, summary.errors.timeout,
    latency:percentile(50), latency:percentile(90), latency:percentile(95), latency:percentile(99), latency.max, latency.mean))
end
