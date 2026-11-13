// Controlled "external API" for Benchmark B: fixed latency, small JSON body.
// Usage: upstream -addr 127.0.0.1:18700 -latency 20ms
package main

import (
	"encoding/json"
	"flag"
	"log"
	"net/http"
	"strconv"
	"time"
)

func main() {
	addr := flag.String("addr", "127.0.0.1:18700", "listen address")
	latency := flag.Duration("latency", 20*time.Millisecond, "artificial response latency")
	flag.Parse()

	http.HandleFunc("/quote", func(w http.ResponseWriter, r *http.Request) {
		id, _ := strconv.Atoi(r.URL.Query().Get("id"))
		time.Sleep(*latency)
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{"id": id, "rate": 1.0 + float64(id%7)/10})
	})
	srv := &http.Server{Addr: *addr, ReadTimeout: 10 * time.Second, WriteTimeout: 10 * time.Second}
	log.Fatal(srv.ListenAndServe())
}
