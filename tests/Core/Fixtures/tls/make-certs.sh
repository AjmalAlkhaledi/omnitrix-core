#!/usr/bin/env bash
# Generates a private test CA and a localhost server certificate into $1 (default var/tls).
set -euo pipefail
OUT="${1:-var/tls}"; mkdir -p "$OUT"; cd "$OUT"
[ -f ca.crt ] && [ -f server.crt ] && exit 0
openssl req -x509 -newkey rsa:2048 -nodes -keyout ca.key -out ca.crt -days 3650 -subj "/CN=Omnitrix Test CA" >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -keyout server.key -out server.csr -subj "/CN=localhost" >/dev/null 2>&1
printf "subjectAltName=DNS:localhost,IP:127.0.0.1\n" > ext.cnf
openssl x509 -req -in server.csr -CA ca.crt -CAkey ca.key -CAcreateserial -out server.crt -days 3650 -extfile ext.cnf >/dev/null 2>&1
echo "certificates written to $OUT"
