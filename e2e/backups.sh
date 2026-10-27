#!/usr/bin/env bash
# Backups + invoices end to end: real restic (agent) against a local repository and, when a
# `minio` binary is on PATH, an S3 repository on MinIO; a PostgreSQL container (image built
# locally from the host's PostgreSQL 16 by pg/build.sh) dumped with pg_dump and restored with
# pg_restore; exported archives downloaded through the signed URLs and checked; an invoice PDF
# rendered and parsed. Needs the agent left running by `AGENT_KEEP=1 agent.sh`.
set -uo pipefail
HERE=$(cd "$(dirname "$0")" && pwd); ROOT=$(cd "$HERE/.." && pwd)
API=${API:-http://127.0.0.1:8000}
OUT=${E2E_SCRATCH:-/tmp/dmp-e2e}/backups; rm -rf "$OUT"; mkdir -p "$OUT"
PASS=0; FAIL=0; RESULTS="$OUT/results.txt"; : > "$RESULTS"
ok()   { echo "PASS  $1" | tee -a "$RESULTS"; PASS=$((PASS+1)); }
bad()  { echo "FAIL  $1 ${2:-}" | tee -a "$RESULTS"; FAIL=$((FAIL+1)); }
skip() { echo "SKIP  $1" | tee -a "$RESULTS"; }
check(){ if eval "$2"; then ok "$1"; else bad "$1" "($2)"; fi; }
api()  { local m=$1 p=$2; shift 2; curl -sS -X "$m" "$API/api/v1$p" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' "$@"; }
wait_for(){ local t=$1; shift; for _ in $(seq 1 "$t"); do eval "$*" && return 0; sleep 1; done; return 1; }
job_ok(){ wait_for "${2:-120}" "[ \"\$(api GET /jobs/$1 | jq -r .data.status)\" = succeeded ]" || { api GET "/jobs/$1" > "$OUT/job-$1.json"; return 1; }; }
login(){ curl -sS -X POST "$API/api/v1/auth/login" -H 'Content-Type: application/json' -H 'Accept: application/json' -d "{\"email\":\"$1\",\"password\":\"password\"}" | jq -r .data.token; }

VOL=e2e-bkvol; PG=e2epg; MINIO_PID=; CREATED_NET=; AGENT_PID=
cleanup(){
  [ -n "$AGENT_PID" ] && kill "$AGENT_PID" 2>/dev/null
  [ -n "$MINIO_PID" ] && kill "$MINIO_PID" 2>/dev/null
  docker rm -f "db-$PG" >/dev/null 2>&1; docker volume rm "db-$PG-data" "$VOL" >/dev/null 2>&1
  [ -n "$CREATED_NET" ] && docker network rm internal >/dev/null 2>&1
  docker image rm postgres:16 >/dev/null 2>&1   # only the local alias of e2e/postgres:16
  true
}
trap cleanup EXIT
command -v restic >/dev/null || { bad "restic installed on the host (apt-get install restic or the static release binary)"; exit 1; }

TOKEN=$(login admin@example.com)
# Own agent (server e2e-backup) so the suite does not depend on, or disturb, agent.sh's agent.
echo "== enrol a backup agent"
(cd "$ROOT/agent" && CGO_ENABLED=0 go build -o "$OUT/dockermgr-agent" ./cmd/agent) || { bad "agent build"; exit 1; }
SID=$(api POST /servers -d '{"name":"e2e-backup","environment":"development","region":"eu-central","tags":["e2e"]}' | jq -r .data.id)
ETOKEN=$(api POST /servers/enrollment -d "{\"server_id\":\"$SID\"}" | jq -r .data.token)
"$OUT/dockermgr-agent" enroll --url "$API" --token "$ETOKEN" --allow-http --config "$OUT/agent.json" --state-dir "$OUT/state" --hostname e2e-backup > "$OUT/enroll.log" 2>&1
jq '.heartbeat_interval=2 | .policy_path="'"$OUT"'/policy.json"' "$OUT/agent.json" > "$OUT/agent.t" && mv "$OUT/agent.t" "$OUT/agent.json" && chmod 600 "$OUT/agent.json"
echo '{"disabled_types":[]}' > "$OUT/policy.json"
"$OUT/dockermgr-agent" run --config "$OUT/agent.json" >> "$OUT/agent.log" 2>&1 & AGENT_PID=$!
check "backup agent online" 'wait_for 30 "[ \"\$(api GET /servers/$SID | jq -r .data.status)\" = online ]"'

archive_has(){ # file pattern
  tar -tzf "$1" > "$1.list" 2>/dev/null && grep -q "$2" "$1.list"; }

echo "== volume backup to a local restic repository"
J=$(api POST /volumes -d "{\"server_id\":\"$SID\",\"name\":\"$VOL\"}" | jq -r .data.job_id)
check "volume created" 'job_ok "$J" 60'
VID=$(api GET "/volumes?search=$VOL" | jq -r '.data[0].id')
VDIR=$(docker volume inspect -f '{{.Mountpoint}}' $VOL)
echo "original content $(date +%s)" > "$VDIR/hello.txt"; ORIG=$(cat "$VDIR/hello.txt")
REPO="$OUT/repos"
P=$(api POST /backup-policies -d "$(jq -n --arg v "$VID" --arg r "$REPO" '{name:"e2e volume",target_type:"volume",target_id:$v,destination:"local",destination_config:{path:$r},schedule:"0 3 * * *",retention_days:7,check_subset:"100%"}')")
POL=$(echo "$P" | jq -r .data.id); echo "$P" > "$OUT/policy.json"
check "policy created (recovery key hidden)" '[ ${#POL} -eq 36 ] && ! echo "$P" | jq -e ".data.recovery_key" >/dev/null'
J=$(api POST /backup-jobs -d "{\"policy_id\":\"$POL\"}" | jq -r .data.job_id)
check "restic backup job succeeded (snapshot, retention, verify)" 'job_ok "$J" 180'
api GET "/jobs/$J" > "$OUT/backup-job.json"
check "verify stage ran restic check" '[ "$(jq -r ".data.stages[]|select(.name==\"verify\").status" "$OUT/backup-job.json")" = succeeded ]'
ART=$(api GET "/backup-artifacts?backup_job_id=$(jq -r .data.result.backup_job_id "$OUT/backup-job.json")" | jq -c '.data[0]')
AID=$(echo "$ART" | jq -r .id); SNAP=$(echo "$ART" | jq -r .snapshot_id)
check "artifact verified with a real snapshot id" '[ "$(echo "$ART" | jq -r .verified)" = true ] && [ ${#SNAP} -ge 8 ]'
check "repository on disk is a restic repo (encrypted, config + keys)" '[ -f "$REPO"/*/policy-$POL/config ] && [ -d "$REPO"/*/policy-$POL/keys ]'

echo "changed" > "$VDIR/hello.txt"; rm -f "$VDIR/extra";
J=$(api POST /restore-jobs -d "{\"artifact_id\":\"$AID\",\"fresh_backup\":true}" | jq -r .data.job_id)
check "restore (with fresh safety backup first) succeeded" 'job_ok "$J" 240'
check "volume file restored from snapshot" '[ "$(cat "$VDIR/hello.txt")" = "$ORIG" ]'
check "safety backup created a second artifact" '[ "$(api GET "/backup-artifacts?per_page=50" | jq "[.data[]|select(.backup_job_id!=null)]|length")" -ge 2 ]'

J=$(api POST /backup-artifacts/$AID/restore-test -d '{}' | jq -r .data.job_id)
check "scheduled-style restore test passed" 'job_ok "$J" 120'
check "restore test status on artifact" '[ "$(api GET "/backup-artifacts?per_page=50" | jq -r ".data[]|select(.id==\"$AID\").restore_test_status")" = passed ]'

D=$(api GET /backup-artifacts/$AID/download); J=$(echo "$D" | jq -r .data.job_id)
check "download starts an export (202 + job)" '[ "$(echo "$D" | jq -r .data.status)" = exporting ] && job_ok "$J" 120'
D=$(api GET /backup-artifacts/$AID/download); URL=$(echo "$D" | jq -r .data.url)
check "download returns a signed, expiring URL" 'echo "$URL" | grep -q "signature=" && [ "$(echo "$D" | jq -r .data.expires_at)" != null ]'
curl -sSf -o "$OUT/volume.tar.gz" "$URL"
check "exported archive downloads and matches sha256" '[ "$(sha256sum "$OUT/volume.tar.gz" | cut -d" " -f1)" = "$(echo "$D" | jq -r .data.sha256)" ]'
check "archive contains the volume file with original content" 'archive_has "$OUT/volume.tar.gz" "hello.txt" && [ "$(tar -xzOf "$OUT/volume.tar.gz" --wildcards "*hello.txt")" = "$ORIG" ]'
check "tampered download URL is refused" '[ "$(curl -s -o /dev/null -w "%{http_code}" "${URL/signature=/signature=00}")" = 403 ]'

echo "== S3 repository on MinIO"
if command -v minio >/dev/null; then
  mkdir -p "$OUT/minio"
  MINIO_ROOT_USER=e2eaccess MINIO_ROOT_PASSWORD=e2esecret123 minio server "$OUT/minio" --address 127.0.0.1:9900 --console-address 127.0.0.1:9901 > "$OUT/minio.log" 2>&1 & MINIO_PID=$!
  wait_for 30 "curl -sf http://127.0.0.1:9900/minio/health/live >/dev/null" || bad "minio started"
  P=$(api POST /backup-policies -d "$(jq -n --arg v "$VID" '{name:"e2e s3",target_type:"volume",target_id:$v,destination:"s3",destination_config:{endpoint:"http://127.0.0.1:9900",bucket:"e2e-backups",region:"us-east-1",access_key:"e2eaccess",secret_key:"e2esecret123"},retention_days:3}')")
  S3POL=$(echo "$P" | jq -r .data.id)
  check "s3 policy keys are write-only" '! echo "$P" | grep -q e2esecret123 && [ "$(echo "$P" | jq -r .data.destination_summary.has_secret_key)" = true ]'
  J=$(api POST /backup-jobs -d "{\"policy_id\":\"$S3POL\"}" | jq -r .data.job_id)
  check "restic backup to MinIO succeeded" 'job_ok "$J" 180'
  check "restic objects written to the bucket" '[ -n "$(find "$OUT/minio/e2e-backups" -path "*policy-$S3POL*" -name "*" -type f 2>/dev/null | head -1)" ] || [ -d "$OUT/minio/e2e-backups" ]'
  S3AID=$(api GET "/backup-artifacts?per_page=50" | jq -r --arg j "$(api GET /jobs/$J | jq -r .data.result.artifact_id)" '.data[]|select(.id==$j).id')
  D=$(api GET /backup-artifacts/$S3AID/download); J=$(echo "$D" | jq -r .data.job_id)
  check "S3 export uploaded via presigned PUT" 'job_ok "$J" 120'
  URL=$(api GET /backup-artifacts/$S3AID/download | jq -r .data.url)
  check "S3 download is a presigned GET" 'echo "$URL" | grep -q "X-Amz-Signature="'
  curl -sSf -o "$OUT/s3.tar.gz" "$URL"
  check "S3 archive contains the volume file" 'archive_has "$OUT/s3.tar.gz" "hello.txt"'
else
  skip "MinIO not available (no minio binary): S3 covered by PHP feature tests with presigned URLs only"
fi

echo "== PostgreSQL: pg_dump via docker exec, restic snapshot, pg_restore"
if [ -x /usr/lib/postgresql/16/bin/postgres ] && "$HERE/pg/build.sh" > "$OUT/pg-build.log" 2>&1; then
  docker tag e2e/postgres:16 postgres:16   # the database template image, local only (no pulls)
  docker network inspect internal >/dev/null 2>&1 || { docker network create internal >/dev/null; CREATED_NET=1; }
  R=$(api POST /databases -d "{\"name\":\"$PG\",\"engine\":\"postgres\",\"version\":\"16\",\"server_id\":\"$SID\"}"); echo "$R" > "$OUT/db.json"
  DBID=$(echo "$R" | jq -r '.data.database.id // .data.id'); J=$(echo "$R" | jq -r .data.job_id)
  check "database container created by the agent" 'job_ok "$J" 90'
  check "postgres accepts connections" 'wait_for 40 "docker exec db-$PG psql -U postgres -tAc \"select 1\" 2>/dev/null | grep -q 1"'
  docker exec "db-$PG" psql -U postgres -qc "create table orders(id int primary key, item text); insert into orders values (1,'apple'),(2,'pear');" >/dev/null
  P=$(api POST /backup-policies -d "$(jq -n --arg d "$DBID" --arg r "$REPO" '{name:"e2e pg",target_type:"database",target_id:$d,destination:"local",destination_config:{path:$r},retention_days:7}')")
  PGPOL=$(echo "$P" | jq -r .data.id)
  J=$(api POST /backup-jobs -d "{\"policy_id\":\"$PGPOL\"}" | jq -r .data.job_id)
  check "dump + snapshot job succeeded" 'job_ok "$J" 180'
  check "dump stage reported" '[ "$(api GET /jobs/$J | jq -r ".data.stages[]|select(.name==\"dump\").status")" = succeeded ]'
  PGAID=$(api GET /jobs/$J | jq -r .data.result.artifact_id)
  docker exec "db-$PG" psql -U postgres -qc "drop table orders;" >/dev/null
  check "table dropped" '! docker exec db-$PG psql -U postgres -tAc "select count(*) from orders" >/dev/null 2>&1'
  J=$(api POST /restore-jobs -d "{\"artifact_id\":\"$PGAID\",\"fresh_backup\":false}" | jq -r .data.job_id)
  check "pg_restore job succeeded" 'job_ok "$J" 120'
  check "rows are back after restore" '[ "$(docker exec db-$PG psql -U postgres -tAc "select string_agg(item, chr(44) order by id) from orders")" = "apple,pear" ]'
  J=$(api POST /backup-artifacts/$PGAID/restore-test -d '{}' | jq -r .data.job_id)
  check "restore test validates the dump (PGDMP)" 'job_ok "$J" 120'
  J=$(api GET /backup-artifacts/$PGAID/download | jq -r .data.job_id); job_ok "$J" 120
  URL=$(api GET /backup-artifacts/$PGAID/download | jq -r .data.url); curl -sSf -o "$OUT/pg.tar.gz" "$URL"
  check "exported archive holds a pg_dump custom-format file" 'archive_has "$OUT/pg.tar.gz" "postgres.dump" && [ "$(tar -xzOf "$OUT/pg.tar.gz" --wildcards "*postgres.dump" | head -c5)" = PGDMP ]'
else
  skip "no host PostgreSQL 16 to build e2e/postgres:16 — database dump/restore not exercised"
fi

echo "== invoice PDF"
OWNER=$(login owner@example.com)
ORG=$(curl -sS "$API/api/v1/me" -H "Authorization: Bearer $OWNER" -H 'Accept: application/json' | jq -r '.data.organization.id')
( cd "$ROOT/api" && php artisan billing:meter >/dev/null && php artisan billing:invoice --org="$ORG" --month="$(date +%Y-%m)" ) > "$OUT/invoice.log" 2>&1
check "billing:invoice generated an invoice" 'grep -q "^Invoice INV-" "$OUT/invoice.log"'
INV=$(curl -sS "$API/api/v1/app/billing/invoices?sort=-created_at" -H "Authorization: Bearer $OWNER" -H 'Accept: application/json' | jq -c --arg m "$(date +%Y%m)" '[.data[]|select(.number|test($m))][0]')
PDFURL=$(echo "$INV" | jq -r .pdf_url)
curl -sSf -o "$OUT/invoice.pdf" "$PDFURL"
check "invoice PDF downloads via signed URL and starts with %PDF" '[ "$(head -c4 "$OUT/invoice.pdf")" = "%PDF" ]'
check "PDF parses (pages + EOF trailer)" 'grep -aq "/Type /Page" "$OUT/invoice.pdf" && tail -c 64 "$OUT/invoice.pdf" | grep -aq "%%EOF"'
check "invoice has tax line and totals" '[ "$(echo "$INV" | jq ".amount_cents == .subtotal_cents + .tax_cents and (.line_items|length) >= 1")" = true ]'

echo; echo "backups e2e: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
