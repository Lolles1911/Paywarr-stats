#!/usr/bin/env bash
# Starts the Tracearr stub, runs the scenarios against it, stops the stub.
set -euo pipefail
cd "$(dirname "$0")"
PORT="${PORT:-8767}"

python3 tracearr_stub.py "$PORT" &
STUB_PID=$!
trap 'kill "$STUB_PID" 2>/dev/null || true' EXIT

for _ in $(seq 1 50); do
  curl -s --noproxy '*' -o /dev/null -X POST "http://127.0.0.1:$PORT/_test/reset" && break
  sleep 0.1
done

php run.php "$PORT"
