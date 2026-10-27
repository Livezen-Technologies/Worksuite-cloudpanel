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
| GET/POST | /servers | server: {id,name,hostname,ip,environment,region,tags[],status: pending|online|warning|maintenance|offline,os,kernel,docker_version,cpu_cores,ram_mb,disk_gb,cpu_pct,ram_pct,disk_pct,container_count,agent_version,last_heartbeat_at} |
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
| GET/POST | /applications ; GET/PATCH/DELETE /applications/{id} | source_type: git|image|dockerfile|compose; strategy: recreate|rolling|blue_green |
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
  network.create|remove, volume.create|remove, compose.up|down|restart, backup.run, restore.run, agent.update, agent.restart.
- `POST /agent/v1/commands/{id}/result` {status: running|succeeded|failed, progress?, message?, output?} — idempotent per id.
- Agent rejects unsigned/unknown/duplicate commands (local policy, no generic shell).
