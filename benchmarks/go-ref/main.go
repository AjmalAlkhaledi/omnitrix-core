// Go reference implementation of the two benchmark endpoints, for comparison only.
// Same SQL, same upstream, equivalent pool sizes. Idiomatic and tuned: statements are prepared
// once, the HTTP transport keeps enough idle connections per host.
package main

import (
	"database/sql"
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"net/http"
	"strconv"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

type user struct {
	ID      int64   `json:"id"`
	Name    string  `json:"name"`
	Balance int64   `json:"balance"`
	Visits  int64   `json:"visits"`
	Score   float64 `json:"score,omitempty"`
}

func main() {
	addr := flag.String("addr", "127.0.0.1:18710", "listen address")
	dsn := flag.String("dsn", "root:@tcp(127.0.0.1:33306)/omnitrix_test", "MySQL DSN")
	pool := flag.Int("pool", 64, "max open DB connections")
	upstream := flag.String("upstream", "http://127.0.0.1:18700", "upstream base URL")
	flag.Parse()

	db, err := sql.Open("mysql", *dsn)
	if err != nil {
		log.Fatal(err)
	}
	db.SetMaxOpenConns(*pool)
	db.SetMaxIdleConns(*pool)
	db.SetConnMaxLifetime(30 * time.Minute)

	selectStmt, err := db.Prepare("SELECT id, name, balance, visits FROM bench_users WHERE id = ?")
	if err != nil {
		log.Fatal(err)
	}
	updateA, err := db.Prepare("UPDATE bench_users SET visits = visits + 1, balance = balance + ? WHERE id = ?")
	if err != nil {
		log.Fatal(err)
	}
	updateB, err := db.Prepare("UPDATE bench_users SET score = ? WHERE id = ?")
	if err != nil {
		log.Fatal(err)
	}

	client := &http.Client{Timeout: 5 * time.Second, Transport: &http.Transport{MaxIdleConns: *pool, MaxIdleConnsPerHost: *pool, IdleConnTimeout: 60 * time.Second}}

	fail := func(w http.ResponseWriter, status int, msg string) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(status)
		_, _ = fmt.Fprintf(w, `{"error":%q}`, msg)
	}

	// Benchmark A: parse JSON → SELECT → UPDATE → JSON
	http.HandleFunc("/a", func(w http.ResponseWriter, r *http.Request) {
		var in struct {
			ID     int64 `json:"id"`
			Amount int64 `json:"amount"`
		}
		if err := json.NewDecoder(r.Body).Decode(&in); err != nil || in.ID <= 0 {
			fail(w, 400, "bad request")
			return
		}
		var u user
		if err := selectStmt.QueryRow(in.ID).Scan(&u.ID, &u.Name, &u.Balance, &u.Visits); err != nil {
			fail(w, 404, "not found")
			return
		}
		if _, err := updateA.Exec(in.Amount, in.ID); err != nil {
			fail(w, 500, "update failed")
			return
		}
		u.Balance += in.Amount
		u.Visits++
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(u)
	})

	// Benchmark B: SELECT → external HTTP → logic → UPDATE → JSON
	http.HandleFunc("/b", func(w http.ResponseWriter, r *http.Request) {
		id, err := strconv.ParseInt(r.URL.Query().Get("id"), 10, 64)
		if err != nil || id <= 0 {
			fail(w, 400, "bad request")
			return
		}
		var u user
		if err := selectStmt.QueryRow(id).Scan(&u.ID, &u.Name, &u.Balance, &u.Visits); err != nil {
			fail(w, 404, "not found")
			return
		}
		resp, err := client.Get(fmt.Sprintf("%s/quote?id=%d", *upstream, id))
		if err != nil {
			fail(w, 502, "upstream failed")
			return
		}
		var quote struct {
			Rate float64 `json:"rate"`
		}
		err = json.NewDecoder(resp.Body).Decode(&quote)
		resp.Body.Close()
		if err != nil || resp.StatusCode != 200 {
			fail(w, 502, "upstream invalid")
			return
		}
		u.Score = float64(u.Balance) * quote.Rate
		if _, err := updateB.Exec(u.Score, id); err != nil {
			fail(w, 500, "update failed")
			return
		}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(u)
	})

	srv := &http.Server{Addr: *addr, ReadTimeout: 10 * time.Second, WriteTimeout: 10 * time.Second}
	log.Fatal(srv.ListenAndServe())
}
