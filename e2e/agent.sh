#!/usr/bin/env bash
# Agent end-to-end test: real API + real agent binary + real Docker daemon.
# Requires: API on $API (default http://127.0.0.1:8000) with queue worker, docker, jq, python3, go,
# and the local image e2e/hello:1 (built by run.sh). Writes logs under $E2E_SCRATCH/agent.
set -uo pipefail
HERE=$(cd "$(dirname "$0")" && pwd); ROOT=$(cd "$HERE/.." && pwd)
API=${API:-http://127.0.0.1:8000}
OUT=${E2E_SCRATCH:-/tmp/dmp-e2e}/agent; rm -rf "$OUT"; mkdir -p "$OUT/state"
MITM_PORT=${MITM_PORT:-8765}; CTL="$OUT/mitm.ctl"
PASS=0; FAIL=0; RESULTS="$OUT/results.txt"; : > "$RESULTS"
ok()   { echo "PASS  $1" | tee -a "$RESULTS"; PASS=$((PASS+1)); }
bad()  { echo "FAIL  $1 ${2:-}" | tee -a "$RESULTS"; FAIL=$((FAIL+1)); }
check(){ if eval "$2"; then ok "$1"; else bad "$1" "($2)"; fi; }
api()  { local m=$1 p=$2; shift 2; curl -sS -X "$m" "$API/api/v1$p" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' "$@"; }
# wait_for <seconds> <shell condition>
wait_for(){ local t=$1; shift; for _ in $(seq 1 "$t"); do eval "$*" && return 0; sleep 1; done; return 1; }
job_ok(){ wait_for ${2:-60} "[ \"\$(api GET /jobs/$1 | jq -r .data.status)\" = succeeded ]"; }
started(){ docker inspect -f '{{.State.StartedAt}}' "$1" 2>/dev/null; }
cleanup(){ [ -n "${AGENT_PID:-}" ] && kill "$AGENT_PID" 2>/dev/null; [ -n "${MITM_PID:-}" ] && kill "$MITM_PID" 2>/dev/null
  docker rm -f e2e-hello >/dev/null 2>&1; docker compose -p e2estack down >/dev/null 2>&1; true; }
trap cleanup EXIT
docker rm -f e2e-hello >/dev/null 2>&1; docker compose -p e2estack down >/dev/null 2>&1

echo "== build agent"; (cd "$ROOT/agent" && CGO_ENABLED=0 go build -o "$OUT/dockermgr-agent" ./cmd/agent) || { bad build; exit 1; }
python3 "$HERE/mitm.py" "$MITM_PORT" "$API" "$CTL" "$OUT/mitm.log" & MITM_PID=$!
sleep 0.5

TOKEN=$(curl -sS -X POST "$API/api/v1/auth/login" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"admin@example.com","password":"password"}' | jq -r .data.token)
check "admin login" '[ -n "$TOKEN" ] && [ "$TOKEN" != null ]'

SID=$(api POST /servers -d '{"name":"e2e-local","environment":"development","region":"eu-central","tags":["e2e"]}' | jq -r .data.id)
check "create server" '[ ${#SID} -eq 36 ]'
ENR=$(api POST /servers/enrollment -d "{\"server_id\":\"$SID\"}")
ETOKEN=$(echo "$ENR" | jq -r .data.token)
check "enrolment token + install command" '[ -n "$ETOKEN" ] && echo "$ENR" | jq -e ".data.install_command|test(\"install.sh\")" >/dev/null'

CFG="$OUT/agent.json"
"$OUT/dockermgr-agent" enroll --url "http://127.0.0.1:$MITM_PORT" --token "$ETOKEN" --allow-http --config "$CFG" \
   --state-dir "$OUT/state" --hostname e2e-local > "$OUT/enroll.log" 2>&1
check "agent enroll" '[ -s "$CFG" ] && [ "$(jq -r .server_id "$CFG")" = "$SID" ]'
check "token single use" '! "$OUT/dockermgr-agent" enroll --url "http://127.0.0.1:$MITM_PORT" --token "$ETOKEN" --allow-http --config "$OUT/again.json" --state-dir "$OUT/s2" >/dev/null 2>&1'
# Faster loop for the test; policy file inside the temp dir.
jq '.heartbeat_interval=2 | .policy_path="'"$OUT"'/policy.json"' "$CFG" > "$CFG.t" && mv "$CFG.t" "$CFG" && chmod 600 "$CFG"
echo '{"disabled_types":[],"maintenance_mode":false}' > "$OUT/policy.json"

start_agent(){ "$OUT/dockermgr-agent" run --config "$CFG" >> "$OUT/agent.log" 2>&1 & AGENT_PID=$!; }
start_agent
check "heartbeat marks server online" 'wait_for 30 "[ \"\$(api GET /servers/$SID | jq -r .data.status)\" = online ]"'
SRV=$(api GET /servers/$SID)
check "real inventory facts (docker_version, cpu_cores, ram_mb)" '[ "$(echo "$SRV" | jq -r .data.docker_version)" = "$(docker version -f "{{.Server.Version}}")" ] && [ "$(echo "$SRV" | jq -r .data.cpu_cores)" -gt 0 ] && [ "$(echo "$SRV" | jq -r .data.ram_mb)" -gt 0 ]'
check "heartbeat stats stored (cpu_pct/ram_pct)" '[ "$(echo "$SRV" | jq -r ".data.ram_pct != null")" = true ]'

echo "== container create"
R=$(api POST /containers -d "{\"server_id\":\"$SID\",\"name\":\"e2e-hello\",\"image\":\"e2e/hello:1\",\"env\":{\"PORT\":\"8080\"},\"ports\":[{\"container_port\":8080,\"host_port\":18080}],\"restart_policy\":\"unless-stopped\",\"limits\":{\"memory_mb\":64}}")
J=$(echo "$R" | jq -r .data.job_id); echo "$R" > "$OUT/create.json"
check "create job succeeded" 'job_ok "$J" 60'
check "container running on Docker" '[ "$(docker inspect -f {{.State.Running}} e2e-hello 2>/dev/null)" = true ]'
check "container serves HTTP" 'wait_for 10 "curl -sf http://127.0.0.1:18080/ | grep -q hello"'
check "secure defaults (CapDrop ALL, no-new-privileges, managed label)" '[ "$(docker inspect -f "{{json .HostConfig.CapDrop}}{{json .HostConfig.SecurityOpt}}{{index .Config.Labels \"io.dockermgr.managed\"}}" e2e-hello)" = "[\"ALL\"][\"no-new-privileges\"]true" ]'
check "inventory shows container in API" 'wait_for 20 "api GET \"/containers?server_id=$SID&search=e2e-hello\" | jq -e \".data[0].state==\\\"running\\\"\" >/dev/null"'
CID=$(api GET "/containers?server_id=$SID&search=e2e-hello" | jq -r '.data[0].id')
echo "$CID" > "$OUT/container_id"

action(){ api POST /containers/$CID/actions -d "{\"action\":\"$1\"${2:-}}" | jq -r .data.job_id; }
J=$(action stop);    check "stop job" 'job_ok "$J"'; check "stopped on Docker" '[ "$(docker inspect -f {{.State.Running}} e2e-hello)" = false ]'
J=$(action start);   check "start job" 'job_ok "$J"'; check "running on Docker" '[ "$(docker inspect -f {{.State.Running}} e2e-hello)" = true ]'
S0=$(started e2e-hello); sleep 1
J=$(action restart); check "restart job" 'job_ok "$J"'; check "restart changed StartedAt" '[ "$(started e2e-hello)" != "$S0" ]'

echo "== duplicate command (replayed id) is not executed twice"
S1=$(started e2e-hello); echo replay > "$CTL"
check "mitm replayed last command" 'wait_for 20 "grep -q replayed $OUT/mitm.log"'
sleep 5
check "replayed restart not executed (StartedAt unchanged)" '[ "$(started e2e-hello)" = "$S1" ]'
check "duplicate not reported again (job still succeeded)" '[ "$(api GET /jobs/$J | jq -r .data.status)" = succeeded ]'

echo "== tampered signature rejected"
echo tamper > "$CTL"; S2=$(started e2e-hello)
J=$(action restart)
check "mitm tampered command" 'wait_for 20 "grep -q tampered $OUT/mitm.log"'
check "tampered job failed" 'wait_for 30 "[ \"\$(api GET /jobs/$J | jq -r .data.status)\" = failed ]"'
check "failure mentions signature" 'api GET /jobs/$J | jq -r ".data.error // .data.stages" | grep -qi signature'
check "tampered restart not executed" '[ "$(started e2e-hello)" = "$S2" ]'

echo "== agent restart keeps containers running"
S3=$(started e2e-hello)
kill "$AGENT_PID"; wait "$AGENT_PID" 2>/dev/null; sleep 2
check "container still running with agent stopped" '[ "$(docker inspect -f {{.State.Running}} e2e-hello)" = true ]'
start_agent; sleep 5
J=$(api POST /servers/$SID/agent/restart | jq -r .data.job_id)
check "agent.restart via API" 'job_ok "$J" 60'
sleep 4
check "agent alive after re-exec" 'kill -0 $AGENT_PID'
check "container StartedAt unchanged across agent restarts" '[ "$(started e2e-hello)" = "$S3" ]'

echo "== remove (keep volumes)"
docker volume create e2e-keep >/dev/null
J=$(action remove ',"keep_volumes":true'); check "remove job" 'job_ok "$J"'
check "container gone from Docker" '! docker inspect e2e-hello >/dev/null 2>&1'
check "volumes kept" 'docker volume inspect e2e-keep >/dev/null 2>&1'; docker volume rm e2e-keep >/dev/null

echo "== compose up/down"
YAML=$(printf 'services:\n  web:\n    image: e2e/hello:1\n    environment:\n      PORT: "8080"\n    ports:\n      - "18081:8080"\n')
P=$(api POST /compose-projects -d "$(jq -n --arg y "$YAML" --arg s "$SID" '{name:"e2estack",server_id:$s,environment:"development",source:"editor",yaml:$y}')")
PID_=$(echo "$P" | jq -r .data.id); echo "$P" > "$OUT/compose.json"
check "compose project created" '[ ${#PID_} -eq 36 ]'
check "compose validate ok" 'api POST /compose-projects/$PID_/validate -d "{}" | jq -e ".data.valid==true" >/dev/null'
J=$(api POST /compose-projects/$PID_/deploy -d '{}' | jq -r .data.job_id)
check "compose up job" 'job_ok "$J" 90'
check "compose service serving" 'wait_for 15 "curl -sf http://127.0.0.1:18081/ | grep -q hello"'
J=$(api POST /compose-projects/$PID_/actions -d '{"action":"remove"}' | jq -r .data.job_id)
check "compose down job" 'job_ok "$J" 60'
check "compose containers gone" '[ -z "$(docker ps -aq --filter label=com.docker.compose.project=e2estack)" ]'

echo; echo "agent e2e: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
