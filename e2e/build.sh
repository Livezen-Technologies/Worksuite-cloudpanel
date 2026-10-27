#!/usr/bin/env bash
# Build pipeline end-to-end test (APP-001/002, UHOST-006): a tiny Go web app is built from a local bare
# Git repository by the real agent (`image.build`: git clone + Docker Engine /build), deployed, health
# checked over HTTP on the container IP, then a broken revision fails its health check with log lines and
# is rolled back, and a revision with a bad Dockerfile fails in the build stage with the build log.
# Needs the stack from run.sh and the agent kept running by agent.sh (AGENT_KEEP=1, DOCKERMGR_ALLOW_LOCAL_GIT=1).
# Images are FROM scratch with a binary compiled here, so no registry pulls (Docker Hub answers 429).
set -uo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
API=${API:-http://127.0.0.1:8000}
OUT=${E2E_SCRATCH:-/tmp/dmp-e2e}/build; rm -rf "$OUT"; mkdir -p "$OUT"
PASS=0; FAIL=0
ok()   { echo "PASS  $1"; PASS=$((PASS+1)); }
bad()  { echo "FAIL  $1 ${2:-}"; FAIL=$((FAIL+1)); }
check(){ if eval "$2"; then ok "$1"; else bad "$1" "($2)"; fi; }
api()  { local m=$1 p=$2; shift 2; curl -sS -X "$m" "$API/api/v1$p" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' "$@"; }
wait_for(){ local t=$1; shift; for _ in $(seq 1 "$t"); do eval "$*" && return 0; sleep 1; done; return 1; }
job_status(){ api GET "/jobs/$1" | jq -r .data.status; }
job_done(){ wait_for "${2:-180}" "case \$(job_status $1) in succeeded|failed|cancelled) true;; *) false;; esac"; }
stage(){ api GET "/jobs/$1" | jq -r --arg n "$2" '.data.stages[]|select(.name==$n)|.status'; }
APP=e2e-gobuild; CTR=app-$APP
cleanup(){ docker rm -f "$CTR" >/dev/null 2>&1; docker images -q 'dmp-build/*' | sort -u | xargs -r docker rmi -f >/dev/null 2>&1; true; }
trap cleanup EXIT

TOKEN=$(curl -sS -X POST "$API/api/v1/auth/login" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"admin@example.com","password":"password"}' | jq -r .data.token)
check "admin login" '[ -n "$TOKEN" ] && [ "$TOKEN" != null ]'
SID=$(api GET '/servers?search=e2e-local' | jq -r '.data[0].id')
check "agent server from agent.sh is online" '[ "$(api GET /servers/$SID | jq -r .data.status)" = online ]'

echo "== local bare Git repository with three branches"
git_(){ git -C "$OUT/work" -c user.email=e2e@example.test -c user.name=e2e "$@" >/dev/null 2>&1; }
mkdir -p "$OUT/work" && git -C "$OUT/work" init -q -b main
cp "$HERE/gobuild/Dockerfile" "$OUT/work/Dockerfile"
build_bin(){ ( cd "$HERE/gobuild" && CGO_ENABLED=0 go build -trimpath -ldflags "-s -w -X main.mode=$1" -o "$OUT/work/app" . ); }
build_bin good && git_ add -A && git_ commit -qm good
git_ checkout -q -b broken && build_bin broken && git_ commit -qam broken
git_ checkout -q -b nofile main && git_ rm -q app && git_ commit -qm "drop binary"
git init -q --bare "$OUT/repo.git" && git_ push -q "$OUT/repo.git" main broken nofile
check "bare repo has main, broken, nofile" '[ "$(git --git-dir="$OUT/repo.git" branch --format="%(refname:short)" | sort | tr "\n" " ")" = "broken main nofile " ]'
REPO="file://$OUT/repo.git"

echo "== builder role (single-host test: the target is also the builder)"
check "server gets the builder role" '[ "$(api PATCH /servers/$SID -d "{\"roles\":[\"app\",\"builder\"]}" | jq -c .data.roles)" = "[\"app\",\"builder\"]" ]'
check "allow builds on deploy targets" 'api PATCH /settings -d "{\"security\":{\"allow_builds_on_deploy_targets\":true}}" | jq -e ".data.security.allow_builds_on_deploy_targets==true" >/dev/null'

src(){ jq -nc --arg r "$REPO" --arg b "$1" '{repo:$r,branch:$b,dockerfile_path:"Dockerfile",context:"."}'; }
AID=$(api POST /applications -d "$(jq -nc --arg s "$SID" --argjson src "$(src main)" --arg n "$APP" \
  '{name:$n,source_type:"git",source:$src,server_id:$s,environment:"development",strategy:"recreate",port:8080,health_path:"/healthz",health_timeout:20}')" | jq -r .data.id)
check "git application created" '[ ${#AID} -eq 36 ]'

echo "== deploy main: build on the agent, deploy, HTTP health check"
J=$(api POST /deployments -d "{\"application_id\":\"$AID\"}" | jq -r .data.job_id)
job_done "$J" 240; api GET "/jobs/$J" > "$OUT/deploy-good.json"
check "deployment succeeded" '[ "$(jq -r .data.status "$OUT/deploy-good.json")" = succeeded ] || { jq . "$OUT/deploy-good.json"; false; }'
check "checkout stage names the commit" 'jq -e ".data.stages[]|select(.name==\"checkout\" and .status==\"succeeded\")|.message|test(\"Checked out file://.*@main \\\\([0-9a-f]{12}\\\\)\")" "$OUT/deploy-good.json" >/dev/null'
check "build_scan succeeded on the builder" '[ "$(stage "$J" build_scan)" = succeeded ]'
check "push skipped (local image on the builder)" '[ "$(stage "$J" push)" = skipped ]'
check "build log streamed to the pipeline (Dockerfile steps)" 'jq -e "[.data.build_log[]|select(test(\"Step [0-9]/[0-9] : COPY app /app\"))]|length>0" "$OUT/deploy-good.json" >/dev/null'
check "health check was an HTTP GET to /healthz" 'jq -e ".data.stages[]|select(.name==\"health_check\")|.message|test(\"GET http://[0-9.]+:8080/healthz → 200\")" "$OUT/deploy-good.json" >/dev/null'
IMG=$(docker inspect -f '{{.Config.Image}}' "$CTR" 2>/dev/null)
check "container runs the locally built image" '[[ "$IMG" == dmp-build/* ]] && docker image inspect "$IMG" -f "{{index .Config.Labels \"io.dockermgr.commit\"}}" | grep -Eq "^[0-9a-f]{40}$"'
IP=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}' "$CTR" | awk '{print $1}')
check "built app serves HTTP" 'curl -sf "http://$IP:8080/" | grep -q "built by e2e (good)"'
check "release v1 records the image" '[ "$(api GET /applications/$AID/releases | jq -r ".data[0].image")" = "$IMG" ]'
check "build temp dir cleaned up" '[ -z "$(ls -A "${E2E_SCRATCH:-/tmp/dmp-e2e}/agent/state/builds" 2>/dev/null)" ]'

echo "== deploy broken: health check fails with the last log lines, rolled back"
api PATCH /applications/$AID -d "$(jq -nc --argjson src "$(src broken)" '{source:$src,health_timeout:10}')" >/dev/null
J=$(api POST /deployments -d "{\"application_id\":\"$AID\"}" | jq -r .data.job_id)
job_done "$J" 240; api GET "/jobs/$J" > "$OUT/deploy-broken.json"
DEP=$(api GET "/deployments?application_id=$AID" | jq -r --arg j "$J" '.data[]|select(.job_id==$j)|.id')
api GET "/deployments/$DEP" > "$OUT/deployment-broken.json"
check "broken deployment failed" '[ "$(jq -r .data.status "$OUT/deploy-broken.json")" = failed ]'
check "health_check stage failed with the HTTP status" 'jq -e ".data.stages[]|select(.name==\"health_check\" and .status==\"failed\")|.message|test(\"returned 500\")" "$OUT/deploy-broken.json" >/dev/null'
check "logs_tail has the app output (last 50 lines)" 'jq -e ".data.logs_tail as \$l | (\$l|length)<=50 and (\$l|index(\"broken: line 60\"))!=null and (\$l|index(\"broken: line 1\"))==null" "$OUT/deployment-broken.json" >/dev/null'
check "rolled back to release v1" 'wait_for 60 "[ \"\$(api GET /jobs/$J | jq -r \".data.stages[]|select(.name==\\\"rollback\\\")|.status\")\" = succeeded ]"'
check "previous image serving again" 'wait_for 30 "IP=\$(docker inspect -f \"{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}\" $CTR | cut -d\" \" -f1); curl -sf http://\$IP:8080/ | grep -q \"built by e2e (good)\""'

echo "== deploy nofile: build fails in the build stage with the build log"
api PATCH /applications/$AID -d "$(jq -nc --argjson src "$(src nofile)" '{source:$src}')" >/dev/null
J=$(api POST /deployments -d "{\"application_id\":\"$AID\"}" | jq -r .data.job_id)
job_done "$J" 240; api GET "/jobs/$J" > "$OUT/deploy-nofile.json"
check "build failure fails the deployment" '[ "$(jq -r .data.status "$OUT/deploy-nofile.json")" = failed ]'
check "build_scan stage failed" '[ "$(stage "$J" build_scan)" = failed ]'
check "build log shows the Docker error" 'jq -e "[.data.build_log[]|select(test(\"ERROR\"))]|length>0" "$OUT/deploy-nofile.json" >/dev/null'
check "running release untouched by a failed build" 'IP=$(docker inspect -f "{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}" $CTR | cut -d" " -f1); curl -sf http://$IP:8080/ | grep -q "built by e2e (good)"'

api DELETE /applications/$AID >/dev/null
api PATCH /servers/$SID -d '{"roles":["app"]}' >/dev/null
echo; echo "build e2e: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
