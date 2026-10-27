# API contract v1

Base `/api/v1`, JSON. Auth: `Authorization: Bearer <token>` (Laravel Sanctum personal access token).
The organisation is the token user's current organisation (`users.current_organization_id`).

## Conventions
- List endpoints: `?search=&sort=field|-field&page=&per_page=` plus resource filters.
  Response `{ "data": [...], "meta": { "page", "per_page", "total" } }`.
- Single resource: `{ "data": {...} }`.
- Long-running mutations return **202** `{ "data": { "job_id": "<uuid>", ... } }`.
- `Idempotency-Key` header on POST: same key + same user within 24 h returns the first response.
- Errors: `{ "error": { "code": "validation_failed", "message": "...", "fields": {"name": ["..."]}, "request_id": "..." } }`.
  Codes: `unauthenticated` 401, `forbidden` 403, `not_found` 404, `validation_failed` 422, `conflict` 409,
  `quota_exceeded` 402, `rate_limited` 429, `server_error` 500.
- Every mutation writes an audit event and (if async) a job.
- IDs are UUIDs. Timestamps ISO-8601 UTC.

## Jobs
`GET /jobs/{id}` → `{ id, type, status: queued|running|succeeded|failed|cancelled, progress 0-100, stages: [{name,status,started_at,finished_at,message}], result, error, created_at }`

## Auth
- `POST /auth/register` {name,email,password,organization} → {token,user}
- `POST /auth/login` {email,password,otp?} → {token,user} (otp required when MFA on)
- `POST /auth/logout`, `GET /me` → {user, organization, role, permissions[]}
- `POST /me/mfa/enable` → {secret, otpauth_url}; `POST /me/mfa/confirm` {otp}

## Admin resources (role-checked)
| Method | Path | Notes |
| --- | --- | --- |
| GET | /dashboard | {kpis, series{cpu[],ram[]}, alerts, deployments, top, status} `?range=1h|6h|24h|7d` |
| GET/POST | /servers | server: {id,name,hostname,ip,environment,region,tags[],status: pending|online|warning|maintenance|offline,os,kernel,docker_version,cpu_cores,ram_mb,disk_gb,cpu_pct,ram_pct,disk_pct,container_count,agent_version,last_heartbeat_at,roles[]: app|builder (default ["app"]; PATCH `roles`)} |
| GET/PATCH/DELETE | /servers/{id} | |
| POST | /servers/enrollment | {server_id} → {token (single use, 15 min), expires_at, install_command} |
| POST | /servers/{id}/maintenance | {enabled} |
| POST | /servers/{id}/agent/{restart\|update} | 202 job |
| GET | /servers/{id}/verification | checks [{name, ok, detail}] |
| GET | /containers, /servers/{id}/containers | filter state, server_id, health |
| POST | /containers | create (CTR-006) 202 |
| GET | /containers/{id} | |
| POST | /containers/{id}/actions | {action: start|stop|restart|pause|unpause|remove, keep_volumes=true} 202 |
| POST | /containers/bulk-actions | {ids[], action} 202 |
| POST | /containers/prune-preview, /containers/prune | |
| GET | /containers/{id}/logs?tail=200 | |
| POST | /containers/{id}/terminal-sessions | → {session_id, ws_url, expires_at} |
| GET/POST | /applications ; GET/PATCH/DELETE /applications/{id} | source_type: git|image|dockerfile|compose; strategy: recreate|rolling|blue_green; port, health_path (default `/`), health_timeout (s, default 60); git/dockerfile `source`: {repo (https:// or file:// / absolute path), branch|ref, dockerfile_path (Dockerfile), context (.), build_args{}, push_registry_id?, git_token? (write-only → encrypted secret, `git_secret_id`), git_username?} |
| POST | /deployments | {application_id} → 202 job; GET /deployments, /deployments/{id} |
| POST | /deployments/{id}/approve | |
| GET | /applications/{id}/releases ; POST /applications/{id}/rollback {release_id} | |
| CRUD | /compose-projects | + POST /{id}/validate, /{id}/diff, /{id}/deploy, /{id}/actions {stop|restart|remove}, GET /{id}/revisions, POST /{id}/rollback |
| GET | /images ; POST /images/{id}/scan ; POST /images/prune-preview, /images/prune | |
| CRUD | /registries | credentials write-only |
| CRUD | /networks ; POST /networks/check | conflict check |
| CRUD | /volumes ; GET /storage-pools | |
| CRUD | /domains ; POST /domains/{id}/verify ; POST /domains/{id}/certificate | |
| CRUD | /databases ; POST /databases/{id}/backup, /databases/{id}/restore | |
| CRUD | /backup-policies ; GET /backup-jobs ; POST /backup-jobs {policy_id} ; POST /restore-jobs {artifact_id} | |
| GET | /metrics?server_id=&range= | |
| CRUD | /alert-rules ; GET /alerts ; POST /alerts/{id}/ack | |
| CRUD | /scheduled-jobs ; POST /scheduled-jobs/{id}/run ; CRUD /maintenance-windows | |
| GET | /users ; POST /users/invite ; PATCH /users/{id} {role} ; DELETE /users/{id} | |
| GET | /roles ; GET /roles/{name} → permission matrix | |
| GET/PATCH | /settings | general + security policies |
| CRUD | /notification-channels ; CRUD /integrations | |
| GET/POST/DELETE | /api-tokens | {name, abilities[], expires_at} → token shown once |
| GET | /audit-logs | |
| GET | /approvals ; POST /approvals/{id}/approve | |
| GET | /search?q= | command palette |

## Customer panel (`/app/...` under same base, role owner|developer|billing)
| Method | Path |
| --- | --- |
| GET/POST | /app/apps — POST body: {name, source:{type:image|git|compose|template, image?, registry_auth?, repo?, branch?, compose?, template?}, port, region, env:[{key,value,secret}], volume_path?, size: small|standard|pro} → 202 {app, job_id} |
| POST | /app/apps/validate-source |
| GET/PATCH/DELETE | /app/apps/{id} (PATCH env/size/auto_deploy/auto_restart) |
| POST | /app/apps/{id}/restart, /app/apps/{id}/redeploy, /app/apps/{id}/rollback {release_id} |
| GET | /app/apps/{id}/logs, /app/apps/{id}/releases, /app/apps/{id}/metrics |
| GET/POST/DELETE | /app/domains ; POST /app/domains/{id}/verify → {records:[{type,name,value,status: waiting|verified}], https_status} |
| GET | /app/backups?app_id= ; POST /app/backups {app_id} ; POST /app/backups/{id}/restore ; GET /app/backups/{id}/download |
| GET | /app/billing → {plan, limits, usage{apps,ram_mb,storage_gb,bandwidth_gb}, month_to_date, payment_method, billing_email}; GET /app/billing/plans; POST /app/billing/plan {plan}; GET /app/billing/invoices |
| GET/PATCH | /app/account ; POST /app/account/team {email, role} ; GET/POST/DELETE /app/account/tokens |
| GET | /app/templates |

## Agent protocol (`/agent/v1`, outside the user API)
- `POST /agent/v1/enroll` {token, hostname, facts{os,kernel,docker_version,cpu_cores,ram_mb,disk_gb,arch}, csr?}
  → {server_id, agent_secret, hmac_key, heartbeat_interval:15, certificate?}. Token is single use, 15 min.
- `POST /agent/v1/heartbeat` (Bearer agent_secret) {agent_version, stats{cpu_pct,ram_pct,disk_pct,net_rx,net_tx}, containers:[{docker_id,name,image,state,health,cpu_pct,mem_mb,ports,created}], images:[...], networks:[...], volumes:[...]}
  → {commands:[Command]}
- Command: {id (operation id), type, version:1, payload{}, issued_at, signature} where
  signature = hex(HMAC-SHA256(hmac_key, id + "." + type + "." + canonical_json(payload) + "." + issued_at)).
  canonical_json = JSON with object keys sorted recursively, no whitespace.
- Types: container.start|stop|restart|pause|unpause|remove|create|logs|prune, image.pull|remove|prune,
  network.create|remove, volume.create|remove, compose.up|down|restart, backup.run, restore.run, agent.update, agent.restart,
  image.build, image.scan, container.healthcheck (payloads in `agent/README.md`).
- `image.build` streams build log lines as `running` results (`message` = new lines, batched ~1/s); the API keeps the last 1000
  lines in the command output and exposes them as the deployment job's `build_log`.
- `POST /agent/v1/commands/{id}/result` {status: running|succeeded|failed, progress?, message?, output?} — idempotent per id.
- Agent rejects unsigned/unknown/duplicate commands (local policy, no generic shell).

## Implemented response shapes (api/ build 1.0)
Field names as returned by the Laravel API. Timestamps are ISO-8601 UTC; `{t,v}` is a series point.

- **Approval-gated action** (any destructive/terminal request whose role grant needs approval and
  `security.require_approval_destructive` is on) → **202** `{data:{approval_id, status:"pending_approval", action, message}}`.
  A second user approves with `POST /approvals/{id}/approve`; deletes then execute automatically. For terminals the
  requester repeats the request with `{approval_id}`.
- **Errors** add extras on some codes: `quota_exceeded` → `error.{limit, current, requested, max, upgrade_to, upgrade_url}`;
  login with MFA → `error.mfa_required: true`; placement failure → `409 conflict` with `error.reason: "no_capacity"`.
- **GET /dashboard** `{kpis:{servers_online, servers_total, containers_running, containers_total, cpu_pct, ram_pct, alerts_open},
  series:{range, cpu:[{t,v}], ram:[{t,v}]}, alerts:[{id, severity, title, status, resource_type, resource_id, created_at}],
  deployments:[{id, application_id, application, status, source, trigger, author, job_id, created_at, finished_at}],
  top:[{id, name, server_id, cpu_pct, mem_mb}], status:{servers:{<status>:count}, backups_24h:{succeeded, failed, running},
  certificates_expiring, image_scans:{scanned, critical, high, not_scanned}}, generated_at}`
- **GET /metrics** `{range, server_id, step_seconds, series:{cpu, ram, disk, net_rx, net_tx: [{t,v}]}}` (cpu/ram/disk in %, net cumulative bytes).
  `GET /app/apps/{id}/metrics` → `{range, cpu:[{t,v}], ram:[{t,v}]}` (ram in MB).
- **GET /search?q=** `[{type: page|server|container|application|domain, id, label, sublabel, url}]`
- **GET /containers/{id}/logs**, **GET /app/apps/{id}/logs** `{container_id, lines:[string], fetched_at, job_id}` — returns the last
  fetched lines and queues a fresh `container.logs` fetch (poll again after the job finishes).
- **Domain** (admin `/domains` and `/app/domains`) `{id, name, domain, application_id, app_id, app_name, target_port, wildcard,
  www_redirect, redirect_www, platform_subdomain, challenge: http-01|dns-01, dns_status: pending|verified, https_status:
  pending|issuing|active|failed, records:[{type,name,value}], certificate:{issuer,status,expires_at,days_left}|null, renewal_at,
  expiring, options, dns_checked_at, created_at}`. Verify → `{domain_id, records:[{type,name,value,status: waiting|verified}],
  dns_status, https_status}`. Admin list filter `?filter=expiring|pending_dns|wildcard`.
- **Invoice** `{id, number, period_start, period_end, period ("Sep 2026"), amount_cents, currency, status, pdf_url, created_at}`
- **GET /app/billing** `{plan:{code,name,vcpu,ram_mb,storage_gb,price_cents,currency,limits}, limits:{apps,ram_mb,storage_gb,bandwidth_gb,
  backups_per_day,backup_retention_days}, usage:{apps,ram_mb,storage_gb,bandwidth_gb}, usage_pct:{...}, month_to_date:{amount_cents,currency},
  payment_method:{brand,last4,exp_month,exp_year}|null, billing_email, payment_portal_url}`. Payment method is updated with
  `POST /app/billing/payment-method {payment_method_token: "pm_…", billing_email?}` (card fields are rejected).
- **Customer app** `{id, name, image, source, state, status, primary_domain, address, cpu_pct, ram_mb, ram_limit_mb, storage_gb, size,
  region, port, volume_path, auto_deploy, auto_restart, last_deploy_at, created_at}`; detail adds `env:[{key, value (null when secret), secret}],
  domains, addresses, last_deployment:{id,status,job_id,logs_tail}, requests_24h`. PATCH accepts `restart: true` (Save & restart) and returns `job_id`.
- **Customer backups** `[{id, app_id, app_name, size_mb, verified, encrypted, trigger, created_at, expires_at}]`; download → `{data:{url, expires_at}}`.
- **Deployment job stages**: admin `validate, checkout, build_scan, push, placement, deploy, health_check, complete` (+`approval` first when
  gated, +`rollback` on failure); customer `pull_image, start_container, health_check, certificate_issued, live` (+`build` first for Git sources).
  `GET /jobs/{id}` for a deployment adds `build_log: [string]|null`. Pipeline for git/dockerfile sources: `image.build` on a server with the
  `builder` role (never the deploy target unless it is a builder **and** `security.allow_builds_on_deploy_targets`; without a push registry the
  image is local, so the builder must be the target) → scan (`SCANNER_DRIVER=fake` in-process, `trivy` = agent `image.scan` on the builder or
  target; `security.block_critical_cves` fails the deploy on any critical, and on a failed scan) → push by digest (`repo@sha256:…`, deploy pulls
  with `pull: missing`) or local tag (`pull: never`) → `container.create` → `container.healthcheck`. A failed health check sets the deployment
  `logs_tail` (last 50 lines, also in the failed job's `result.logs_tail`); recreate rolls back to the previous release, rolling/blue-green
  remove the new container and keep the old one (`rolled_back`); a first deploy stops the container. Deployment rows add
  `image_digest, builder_server_id, scan_result{critical,high,medium,low,unknown,total,scanner}`.
- **Image** rows add `blocks_deploy` (critical CVEs while `block_critical_cves` is on); `scan_status: not_scanned|scanning|scanned|failed`;
  `scan_result {critical, high, medium, low, unknown, total, scanner, vulnerabilities:[{id,severity,package,installed,fixed,title}]}`.
  With `SCANNER_DRIVER=trivy`, `POST /images/{id}/scan` returns 202 and runs `image.scan` on the image's server.
- **Customer app** also accepts/returns `health_path` (POST /app/apps, PATCH /app/apps/{id}); Git sources accept `source.dockerfile_path`.
- **Extra endpoints**: `POST /images/pull {server_id, image, registry_id?}` (202), `POST /containers/prune` and `/images/prune` accept `{ids[]}`,
  `DELETE /terminal-sessions/{id}`, `POST /approvals/{id}/reject`, `GET /audit-logs/verify`, `GET /backup-artifacts`, `POST /me/mfa/disable`,
  `POST /notification-channels/{id}/test`, `GET /agent/v1/install.sh`, `GET /agent/v1/download/dockermgr-agent-linux-{amd64|arm64}`.
