#!/usr/bin/env bash
# Reproduce WASM PHP curl crash: MySQL I/O inside gzip WRITEFUNCTION.
#
# Requires: Docker (for MySQL), npm (for @wp-playground/cli)
#
# The crash is Asyncify stack corruption: MySQL's async network I/O
# inside curl's WRITEFUNCTION triggers stack unwinding while zlib's
# inflate() is mid-execution, corrupting zlib's internal state.
set -euo pipefail
cd "$(dirname "$0")"

# Start MySQL if not running
if ! mysql -u root -ptest -h 127.0.0.1 -e "SELECT 1" &>/dev/null; then
    echo "Starting MySQL via Docker..."
    docker rm -f wasm-repro-mysql 2>/dev/null || true
    docker run -d --name wasm-repro-mysql -p 3306:3306 \
        -e MYSQL_ROOT_PASSWORD=test -e MYSQL_DATABASE=repro mysql:8.0
    echo "Waiting for MySQL..."
    for i in $(seq 1 30); do
        mysql -u root -ptest -h 127.0.0.1 -e "SELECT 1" &>/dev/null && break
        sleep 1
    done
fi

# Start test server
kill $(lsof -ti:18787) 2>/dev/null || true
sleep 1
node server.js &
SERVER_PID=$!
sleep 1

echo "Running until crash (usually within 5 runs)..."
for i in $(seq 1 20); do
    OUTPUT=$(npx @wp-playground/cli php \
        --mount="$(pwd):$(pwd)" \
        -- "$(pwd)/repro.php" 2>&1)
    if echo "$OUTPUT" | grep -q "no crash"; then
        echo "Run $i: OK"
    else
        echo "Run $i: CRASHED"
        echo "$OUTPUT"
        kill $SERVER_PID 2>/dev/null
        exit 0
    fi
done

echo "No crash in 20 runs (unusual)"
kill $SERVER_PID 2>/dev/null
