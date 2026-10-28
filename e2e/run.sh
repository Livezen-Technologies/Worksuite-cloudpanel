#!/usr/bin/env bash
# End-to-end run for the Docker Manager Portal: API (PostgreSQL) + queue worker + web (Vite) + real agent
# on the local Docker daemon, then the agent, browser and API suites. See e2e/README.md.
#
#   e2e/run.sh                 fresh DB, start everything, run all suites, stop what it started
#   REUSE_STACK=1 e2e/run.sh   use an API on :8000 / web on :5173 that are already running (no migrate)
#   UNIT=1 e2e/run.sh          also run the api/agent/web unit suites at the end
set -uo pipefail
HERE=$(cd "$(dirname "$0")" && pwd); ROOT=$(cd "$HERE/.." && pwd)
# shellcheck source=env.sh
. "$HERE/env.sh"
export E2E_SCRATCH=${E2E_SCRATCH:-${TMPDIR:-/tmp}/dmp-e2e}
mkdir -p "$E2E_SCRATCH"; LOG=$E2E_SCRATCH
export API=${API:-http://127.0.0.1:8000} API_URL=${API_URL:-http://127.0.0.1:8000} WEB_URL=${WEB_URL:-http://localhost:5173}
PIDS=()
declare -A RESULT

need() { command -v "$1" >/dev/null || { echo "missing tool: $1"; exit 2; }; }
for t in php composer node npm go docker jq python3 psql curl; do need "$t"; done
node -e "require('playwright')" 2>/dev/null || { echo "the 'playwright' node library is required (preinstalled)"; exit 2; }

wait_http() { for _ in $(seq 1 60); do curl -fsS -o /dev/null "$1" && return 0; sleep 1; done; echo "timeout waiting for $1"; return 1; }
cleanup() {
  [ -f "$LOG/agent/agent.pid" ] && kill "$(cat "$LOG/agent/agent.pid")" 2>/dev/null
  for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null; done
  pkill -f "$LOG/agent/dockermgr-agent" 2>/dev/null
  # Only containers this run created: the keep-alive container and apps named e2e-*.
  docker ps -aq --filter name='^/e2e-web$' --filter name='^/e2e-term$' --filter name='^/app-e2e-' | xargs -r docker rm -f >/dev/null 2>&1
  true
}
trap cleanup EXIT

echo "== test image e2e/hello:1 (FROM scratch, built locally; no registry pulls)"
( cd "$HERE/hello" && CGO_ENABLED=0 go build -o hello . && docker build -q -t e2e/hello:1 . >/dev/null && rm -f hello ) || { echo "image build failed"; exit 1; }
echo "== test image e2e/shell:1 (static mini shell as /bin/sh, user app) for the terminal tests"
( cd "$HERE/shell" && CGO_ENABLED=0 go build -o sh . && docker build -q -t e2e/shell:1 . >/dev/null && rm -f sh ) || { echo "shell image build failed"; exit 1; }
docker network inspect proxy >/dev/null 2>&1 || docker network create proxy >/dev/null   # Traefik network apps join

if [ "${REUSE_STACK:-0}" != 1 ]; then
  echo "== API: migrate:fresh --seed on PostgreSQL ($DB_DATABASE)"
  ( cd "$ROOT/api" && php artisan migrate:fresh --seed --force >"$LOG/migrate.log" 2>&1 ) || { cat "$LOG/migrate.log"; exit 1; }
  ( cd "$ROOT/api" && exec php artisan serve --host=127.0.0.1 --port=8000 >"$LOG/api.log" 2>&1 ) & PIDS+=($!)
  if [ "$QUEUE_CONNECTION" != sync ]; then
    ( cd "$ROOT/api" && exec php artisan queue:work --sleep=1 >"$LOG/queue.log" 2>&1 ) & PIDS+=($!)
  fi
  [ -d "$ROOT/web/node_modules" ] || ( cd "$ROOT/web" && npm ci >"$LOG/npm-ci.log" 2>&1 )
  ( cd "$ROOT/web" && exec node node_modules/.bin/vite --port 5173 --strictPort >"$LOG/web.log" 2>&1 ) & PIDS+=($!)
fi
if ! curl -fsS -o /dev/null "http://$RELAY_LISTEN/relay/healthz" 2>/dev/null; then
  echo "== terminal relay on $RELAY_LISTEN"
  mkdir -p "$LOG/relay"
  ( cd "$ROOT/agent" && CGO_ENABLED=0 go build -o "$LOG/relay/dockermgr-relay" ./cmd/relay ) || { echo "relay build failed"; exit 1; }
  RELAY_API_URL=$API "$LOG/relay/dockermgr-relay" >"$LOG/relay.log" 2>&1 & PIDS+=($!)
fi
wait_http "$API/up" || wait_http "$API/api/v1/me" 2>/dev/null || true
wait_http "$WEB_URL/" || exit 1

run_suite() { # name, command...
  local name=$1; shift
  echo; echo "== $name"
  if "$@" 2>&1 | tee "$LOG/$name.out"; then RESULT[$name]=PASS; else RESULT[$name]=FAIL; fi
}
run_suite agent env AGENT_KEEP=1 DOCKERMGR_ALLOW_LOCAL_GIT=1 "$HERE/agent.sh"   # local Git repos for build.sh
run_suite browser-admin node "$HERE/browser/admin.js"
run_suite browser-customer node "$HERE/browser/customer.js"
run_suite browser-security node "$HERE/browser/security.js"   # terminal relay + passkeys
run_suite api-checks node "$HERE/api-checks.js"
run_suite backups "$HERE/backups.sh"
run_suite build "$HERE/build.sh"

if [ "${UNIT:-0}" = 1 ]; then
  # Unit suites run without the e2e environment overrides (env.sh), exactly as a developer would.
  CLEAN=(env -i PATH="$PATH" HOME="$HOME" GOPATH="${GOPATH:-}" GOCACHE="${GOCACHE:-$HOME/.cache/go-build}")
  run_suite unit-api "${CLEAN[@]}" bash -c "cd '$ROOT/api' && php artisan test"
  run_suite unit-agent "${CLEAN[@]}" bash -c "cd '$ROOT/agent' && go vet ./... && go test ./..."
  run_suite unit-web "${CLEAN[@]}" bash -c "cd '$ROOT/web' && npm run lint && npm test && npm run build"
fi

echo; echo "== summary (logs and screenshots in $LOG)"
FAILED=0
for k in agent browser-admin browser-customer browser-security api-checks backups build unit-api unit-agent unit-web; do
  [ -n "${RESULT[$k]:-}" ] || continue
  printf '%-18s %s\n' "$k" "${RESULT[$k]}"; [ "${RESULT[$k]}" = PASS ] || FAILED=1
done
exit $FAILED
