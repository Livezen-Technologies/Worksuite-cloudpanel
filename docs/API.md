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
- `POST /auth/login` {email,password,otp?,passkey?{challenge_id,credential}} → {token,user} (otp **or** a passkey assertion required when MFA on;
  the 401 carries `error.mfa_required` and `error.passkey_available`)
- Passkeys: `POST /auth/passkey/options` {email?} → {challenge_id, options} (WebAuthn request options JSON; with email only that
  account's credentials are allowed, for the second factor); `POST /auth/passkey/login` {challenge_id, credential} → {token,user}
  (passwordless, discoverable credential, user verification required). See "Passkeys" below.
- `POST /auth/logout`, `GET /me` → {user, organization, role, permissions[]}
- `POST /me/mfa/enable` → {secret, otpauth_url}; `POST /me/mfa/confirm` {otp}
- `GET /me/passkeys` → [Passkey]; `POST /me/passkeys/options` → {challenge_id, options}; `POST /me/passkeys` {challenge_id, credential, name?} → 201 Passkey;
  `PATCH /me/passkeys/{id}` {name}; `DELETE /me/passkeys/{id}` → 204 (own passkeys only, both panels)

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
| POST | /containers/{id}/terminal-sessions | {approval_id?} → 201 {session_id, ws_url, expires_at, shell_user, shell, recorded} (container must be running; approval-gated) |
| GET | /terminal-sessions?container_id= ; GET /terminal-sessions/{id}/recording ; DELETE /terminal-sessions/{id} | history, asciicast recording, end session (see "Terminal relay") |
| GET/POST | /applications ; GET/PATCH/DELETE /applications/{id} | source_type: git|image|dockerfile|compose; strategy: recreate|rolling|blue_green; port, health_path (default `/`), health_timeout (s, default 60); git/dockerfile `source`: {repo (https:// or file:// / absolute path), branch|ref, dockerfile_path (Dockerfile), context (.), build_args{}, push_registry_id?, git_token? (write-only → encrypted secret, `git_secret_id`), git_username?} |
| POST | /deployments | {application_id} → 202 job; GET /deployments, /deployments/{id} |
| POST | /deployments/{id}/approve | |
| GET | /applications/{id}/releases ; POST /applications/{id}/rollback {release_id} | |
| CRUD | /compose-projects | + POST /{id}/validate, /{id}/diff, /{id}/deploy, /{id}/actions {stop|restart|remove}, GET /{id}/revisions, POST /{id}/rollback |
| GET | /images ; POST /images/{id}/scan ; POST /images/prune-preview, /images/prune | |
| CRUD | /registries | credentials write-only |
| CRUD | /networks ; POST /networks/check | conflict check |
| CRUD | /volumes ; GET /storage-pools | |
| GET | /volumes/{id}/files?path= | FM: list a folder → 202 {job_id}; job result {path, entries:[{name, type: dir|file|symlink|other, size, mode, modified}], truncated} |
| GET / PUT | /volumes/{id}/files/content | read ?path= → result {path, content, size, mode, modified} (text files up to 1 MiB); write {path, content, create?} |
| POST | /volumes/{id}/files/folders · /volumes/{id}/files/rename | {path} · {path, new_name} (a single name, no "/") |
| DELETE | /volumes/{id}/files?path=&recursive= | a folder needs recursive=true; the volume root is refused |
| POST | /volumes/{id}/files/upload | multipart: path (folder), files[], relative_paths[] (per file, its path inside an uploaded folder), overwrite? → 202 job. The panel keeps the bytes; the agent fetches each over a signed URL (FM-002) |
| POST | /volumes/{id}/files/download | {paths:[...]} → 202 job; result {format: file\|zip, name, bytes, files, download_url (signed, 1 h), expires_at}. One file is sent as is, anything else as a ZIP (FM-003) |
| POST | /volumes/{id}/files/copy · /files/move | {paths:[...], dest} — within the volume; a copy into the same folder becomes "name (copy)"; symlinks are skipped (FM-006) |
| POST | /volumes/{id}/files/compress · /files/extract | compress {paths, dest, format: zip\|tar\|tar.gz, name?}; extract {path, dest, overwrite?} — entries outside the folder and links are refused or skipped; 10 GB / 100,000 entry limits (FM-008) |
| POST · GET · DELETE | /volumes/{id}/files/trash | POST {paths} moves items to the agent's trash (outside the volume, kept 7 days); GET lists {items:[{id, path, type, size, deleted_at, expires_at}]}; DELETE {ids} deletes forever; POST /files/trash/restore {ids} puts items back (FM-007) |
| POST | /volumes/{id}/files/chmod | {paths, mode: "644" (three octal digits; setuid/setgid/sticky refused), recursive?} (FM-009) |
| POST | /volumes/{id}/files/chown | {paths, uid?, gid?, recursive?} — platform staff only (403 for organisation roles) (FM-010). Listings now include each entry's uid and gid |
| GET | /volumes/{id}/files/search?path=&name=&extensions[]=&min_size=&max_size=&modified_after=&modified_before=&type= | at least one condition; walks from path down without following links; result {hits:[{path, name, type, size, mode, modified}], truncated} capped at 500 hits / 200,000 entries (FM-011) |
| POST | /volumes/{id}/files/preview | {path} for images, PDF, audio and video only → job whose download_url is served inline with nosniff and a sandbox CSP. HTML, SVG and text are previewed through GET /files/content instead (FM-012) |
| POST | /volumes/{id}/files/scan | {path?, recent_days? (1–90, default 7)} → job result {findings:[{path, rule, severity: critical\|high\|medium\|info, detail, type, size, mode, modified}], counts, files, folders, truncated}. Rules: malware_pattern, script_in_uploads, double_extension, hidden_script, world_writable, sensitive_readable, recently_changed. Read-only; audited (FM-017) |
| GET · POST | /volumes/{id}/files/history?path= · /files/history/version?path=&id= · /files/history/restore {path, id} | the agent keeps the previous content before every edit, replacing upload and permanent delete (last 20 per file, 30 days, ≤ 10 MB, outside the volume); history returns {job_id, changes:[{action, actor, at}]} with the versions on the job; a restore saves the current file first (FM-014) |
| GET · POST · DELETE | /volumes/{id}/files/shares · /files/shares {paths, expires_in_hours 1–720, max_downloads?, password? (6+)} · /files/shares/{shareId} | File Sharing: a link shares a snapshot. The selection is downloaded from the agent once, kept on the panel until the link ends, and later edits are not served. Create returns `url` once; only a sha256 of its token is stored. List rows are `{id, paths, name, format, size, status active\|preparing\|failed\|expired\|revoked\|used up, expires_at, max_downloads, download_count, password}`. Revoking deletes the stored copy. Creating and revoking are audited with paths, never the token |
| POST | /volumes/{id}/files/copy · /files/move with `dest_id` | Copy or move into another volume on the **same server**. `dest_id` is addressed like the route id: a volume in the admin API, an app in the customer API (`/app/apps/{id}/files/...`). The agent opens both volumes through their own roots, refuses to overwrite an existing item, and removes a half-made copy on failure. A different server or an NFS volume returns 422; a volume the caller can't open returns 404. The audit records `dest_volume` |
| POST | /volumes/{id}/files/extract with `paths[]` | Bulk extract: up to 100 archives in one command, into `dest` or, with `separate: true`, each into a folder named after its archive. It stops at the first archive that fails and reports the ones already done. A single `path` still works as before |
| GET · POST | /shares/{token} (public, no auth) | Downloads the shared file or ZIP and counts it. A password goes in the POST body `{password}`. Responses: 401 `password_required` (no or wrong password), 409 while the copy is being prepared, 410 when the link is expired, revoked or used up. Ended links lose their copy in an hourly prune |
| GET · POST | /volumes/{id}/files/transfers · /files/transfers/{jobId}/retry · /files/transfers/{jobId}/cancel | Transfer Manager: the last 50 uploads, downloads, copies, moves, compresses and extracts on the volume as `{id, operation, status, progress, paths, error, retry_of, can_retry, can_cancel, created_at, finished_at}`. Retry runs a failed transfer again as a new job; a failed upload keeps its files on the panel for this until the hourly prune. Cancel works only while the server has not picked the command up (409 after). Both are audited |
| CRUD | /domains ; POST /domains/{id}/verify ; POST /domains/{id}/certificate | |
| CRUD | /databases ; POST /databases/{id}/backup, /databases/{id}/restore | |
| CRUD | /backup-policies ; GET /backup-jobs ; POST /backup-jobs {policy_id} ; POST /restore-jobs {artifact_id} | |
| GET | /metrics?server_id=&range= | |
| CRUD | /alert-rules ; GET /alerts ; POST /alerts/{id}/ack | |
| CRUD | /scheduled-jobs ; POST /scheduled-jobs/{id}/run ; CRUD /maintenance-windows | |
| GET | /users ; POST /users/invite ; PATCH /users/{id} {role} ; DELETE /users/{id} | |
| GET | /roles ; GET /roles/{name} → permission matrix | |
| GET/PATCH | /settings | general + security policies |
| POST | /settings/smtp/test | `{to?}` (defaults to your own email; 6 per minute). Sends a test email with the saved SMTP settings. Returns 409 without a host, or 502 with the SMTP server's error. Audited. |
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

### Customer file manager (User · Files)

`GET /app/files` lists the customer's apps that keep files: `[{id, name, volume_path, ready}]` (`ready` once the server has reported the app's volume). Every admin file route also exists under `/app/apps/{app_id}/files…` with the same bodies and results, addressed by app instead of volume, except `chown` and `scan`. The app is found through the tenant scope, so only the customer's own apps resolve; jobs belong to the customer's organisation even on shared-pool servers.

### Antivirus (SRS 4.25)

`GET /antivirus` summary {open, quarantined, resolved, last_detected_at, auto_quarantine, schedule} · `GET /antivirus/threats` list {id, path, signature, sha256, size, status: infected|quarantined|restored|deleted|cleaned|ignored, volume, server, detected_at} · `POST /antivirus/scan` {volume_id} → 202 job (no volume_id: fleet scan, platform staff only) · `POST /antivirus/threats/{id}/quarantine|restore|delete|ignore|clean` (ignore = "not a threat": allow-lists the sha256 for the organisation, restores a quarantined copy; audited). Threats in an app volume belong to the app's owner. Agent commands: av.scan (clamdscan via the `clamav` container, allow-listed hashes skipped), av.quarantine, av.restore, av.delete.

- **Schedule (AV-3):** each organisation sets `PATCH /settings {antivirus: {schedule: daily|weekly|off, day: 0–6, time: "HH:MM", notify}}`. The default is weekly, Sunday 02:00. `antivirus:scan-due` runs every 15 minutes and scans each volume at most once per day or week, by the schedule of the organisation that owns the volume.
- **Clean (AV-6):** `clean` works only on WordPress core files (`wp-admin/`, `wp-includes/`, root `wp-*.php`, `index.php`, `xmlrpc.php`, under any site folder); any other file returns 409 "restore from a backup". The job reads `wp-includes/version.php` (`fs.read`), fetches `api.wordpress.org/core/checksums/1.0` and the file from `core.svn.wordpress.org/tags/{version}`, checks the md5, and writes the file in place (`fs.upload`, overwrite). If the checksum doesn't match, nothing is written and the job fails. Success sets the threat to `cleaned`; the audit records the version and md5.
- **Score (AV-13):** `GET /antivirus/score` returns `{score, lowest, fixes[], volumes[{volume_id, volume, score, last_scan_at, fixes[{points, fix}]}]}`. Each volume starts at 100 and loses points for infected files (30 each, at most 60), undecided quarantined files (5 each, at most 15), no successful scan in 8 days (15), scheduled scans off (10), two-factor not required (10), and CrowdSec off (5). Each deduction comes with its fix.
- **Quarantine encryption (AV-5):** quarantined files are stored on the agent as `file.enc`. The format is `WSQ1` | IV | AES-256-CTR | HMAC-SHA256, encrypted then MACed and streamed. The keys come from a per-server master key at `<state>/quarantine.key` (0600, never sent to the panel). Restore checks the tag before writing anything and removes a partly written file on failure. Items quarantined before this change, stored as a plain `file`, still restore.
- **File integrity (AV-10):** the agent commands `fim.baseline` and `fim.check` hash the volume's code files (`.php`, `.js`, `.html`, `.htaccess`, `.user.ini`, `.py`, `.sh`, …; symlinks never followed) into a baseline kept outside the volume. A baseline is taken after every successful deployment of an app with storage, and by `POST /antivirus/integrity/{volumeId}/baseline`. `integrity:check-all` runs daily at 03:40, and `…/check` runs a check on demand. New added, changed or removed files become open `integrity_changes`, notified once. `…/accept` marks them accepted and takes a new baseline. `GET /antivirus/integrity` returns `{volumes[{id, name, baseline_at, checked_at, open}], changes[]}`. Open changes cost 10 points each (at most 20) in the score.
- **Image CVEs (AV-11):** image scanning (Trivy through `image.scan`), scan-on-deploy and the `block_critical_cves` policy already existed. `images:scan-stale` now rescans every image not scanned in the last day, nightly at 01:20, with the Trivy driver only. An image that newly has critical CVEs notifies the owning organisation. Each image with critical CVEs costs 10 points (at most 20) in the score.
- **Real-time scanning (AV-2) and outbound limits (AV-12):** `GET|PATCH /antivirus/servers/{id}` with `{realtime, egress: {enabled, block_mining, smtp_per_minute 0–10000}}`. Platform staff only (403 otherwise). Saving pushes two agent commands:
  - `egress.apply`: its own nftables table `ws_egress`, hooked on forward and output at priority −5 with policy accept. It drops stratum ports 3333, 4444, 5555, 6666, 7777, 9999, 14433, 14444, 45560 and 45700, drops the addresses in `ANTIVIRUS_POOL_ADDRESSES`, and limits new port-25 connections per source to N a minute. Turning it off deletes the table.
  - `av.realtime`: restarts `clamonacc` in the ClamAV container with the server's volumes as its watch list.

  `antivirus:realtime-events` (every 5 minutes) collects `av.events` detections into threats, using the allow-list, dedupe, auto-quarantine and notifications as for scans. `antivirus:reapply` (hourly) pushes both again after a reboot. Setup: `docs/deploy/clamav/README.md`.
- **WAF (AV-9):** `PUT|PATCH /domains/{id}/waf {mode: off|detect|block, exclusions?: [CRS rule ids]}`. With `WAF_ENABLED=true` and the `coraza` plugin in `traefik.yml`, the domain's routers get a Coraza middleware right after the CrowdSec bouncer. It loads the OWASP CRS with `SecRuleEngine On` (block) or `DetectionOnly` (detect), plus one `SecRuleRemoveById` per exclusion. The WAF setting is only changed here: the `options.waf` key in domain create and update is ignored, so editing other options never changes it. The change is audited from and to. When the WAF is installed, each domain not set to Block costs 5 points in the score.
- **Notifications (AV-14):** a scan with new detections sends one message to the owning organisation's enabled channels, unless `antivirus.notify` is off. Files already known are not reported again.

## Agent protocol (`/agent/v1`, outside the user API)
- `POST /agent/v1/enroll` {token, hostname, facts{os,kernel,docker_version,cpu_cores,ram_mb,disk_gb,arch}, csr? (PEM, EC P-256 or RSA ≥ 2048)}
  → {server_id, agent_secret, hmac_key, heartbeat_interval:15, certificate?}. Token is single use, 15 min.
- `POST /agent/v1/heartbeat` (Bearer agent_secret) {agent_version, stats{cpu_pct,ram_pct,disk_pct,net_rx,net_tx}, containers:[{docker_id,name,image,state,health,cpu_pct,mem_mb,ports,created}], images:[...], networks:[...], volumes:[...]}
  → {commands:[Command], relay_url?} (relay_url: terminal relay base for the agent's outbound control connection)
- Command: {id (operation id), type, version:1, payload{}, issued_at, signature} where
  signature = hex(HMAC-SHA256(hmac_key, id + "." + type + "." + canonical_json(payload) + "." + issued_at)).
  canonical_json = JSON with object keys sorted recursively, no whitespace.
- Types: terminal.open, container.start|stop|restart|pause|unpause|remove|create|logs|prune, image.pull|remove|prune,
  network.create|remove, volume.create|remove, fs.list|read|write|mkdir|rename|delete|upload|download|copy|move|compress|extract|trash|trash_list|trash_restore|trash_purge|chmod|chown|search|scan|history_list|history_read|history_restore (confined to one volume), crowdsec.apply (scenarios/dmp-*.yaml + profiles.yaml, checked with crowdsec -t, rolled back on failure, then SIGHUP), crowdsec.alerts (recent alerts for the firewall event log), compose.up|down|restart, backup.run, restore.run, agent.update, agent.restart,
  image.build, image.scan, container.healthcheck, backup.test, backup.export, proxy.config, proxy.certs (payloads in `agent/README.md`).
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
- **Invoice** `{id, number, period_start, period_end, period ("Sep 2026"), amount_cents, subtotal_cents, tax_cents, tax_rate, currency,
  status: open|paid|failed|void, line_items:[{description, quantity, unit, unit_cents, amount_cents, kind: plan|app, application_id?, size?}], paid_at,
  pdf_url, created_at}`. `pdf_url` is a signed link valid 30 min (`GET /api/v1/invoices/{id}/pdf?expires&signature`, no bearer needed) to the
  dompdf-rendered PDF; `GET /app/billing/invoices/{id}/pdf` serves it with the bearer token. Invoices come from `billing:invoice` (monthly).
- **GET /app/billing** `{plan:{code,name,vcpu,ram_mb,storage_gb,price_cents,currency,limits}, limits:{apps,ram_mb,storage_gb,bandwidth_gb,
  backups_per_day,backup_retention_days}, usage:{apps,ram_mb,storage_gb,bandwidth_gb}, usage_pct:{...}, month_to_date:{amount_cents,currency},
  payment_method:{brand,last4,exp_month,exp_year}|null, billing_email, payment_provider: fake|stripe, payment_portal_url}`.
  `POST /app/billing/portal` → `{url}`: a fresh Stripe Customer Portal session (or Checkout in setup mode when no card is on file).
  `POST /api/v1/webhooks/stripe` (no auth; `Stripe-Signature` verified, 400 otherwise; idempotent per event id) handles
  `checkout.session.completed`, `customer.subscription.created|updated|deleted`, `invoice.paid|payment_succeeded|payment_failed`, `payment_method.attached`. Payment method is updated with
  `POST /app/billing/payment-method {payment_method_token: "pm_…", billing_email?}` (card fields are rejected).
- **Customer app** `{id, name, image, source, state, status, primary_domain, address, cpu_pct, ram_mb, ram_limit_mb, storage_gb, size,
  region, port, volume_path, auto_deploy, auto_restart, last_deploy_at, created_at}`; detail adds `env:[{key, value (null when secret), secret}],
  domains, addresses, last_deployment:{id,status,job_id,logs_tail}, requests_24h`. PATCH accepts `restart: true` (Save & restart) and returns `job_id`.
- **Customer backups** `[{id, app_id, app_name, size_mb, verified, verified_at, encrypted, trigger, created_at, expires_at,
  restore_test:{status: running|passed|failed, tested_at, message}|null, export_status}]`. Download (customer `GET /app/backups/{id}/download`,
  admin `GET /backup-artifacts/{id}/download`): **202** `{data:{status:"exporting", job_id}}` while the agent exports the snapshot
  (`backup.export`, job stages `export, upload`), then **200** `{data:{status:"ready", url, expires_at, bytes, sha256}}` — `url` is a presigned
  S3 GET (S3/R2/B2 destinations) or a signed `/api/v1/backup-download/{id}` link (local), valid `BACKUP_DOWNLOAD_TTL_MINUTES`; the file is a
  `tar.gz` of the snapshot (database backups contain `…/dumps/<container>/postgres.dump` etc.).
- **Backup policy** adds `check_subset` (restic `--read-data-subset`, default `5%`), `restore_test_schedule` (cron|null),
  `last_restore_test_at`, `last_restore_test_status`, `destination_summary:{endpoint,bucket,region,prefix,path,account_id,has_access_key,has_secret_key}`.
  `destination_config` is write-only (`{endpoint, bucket, region, prefix, path, account_id, access_key, secret_key}`; blank keys keep the stored ones;
  S3/R2/B2 require bucket + keys). Backup job stages: `[dump,] snapshot, retention, verify` (a failed `restic check` fails `verify` and the job).
  `POST /backup-artifacts/{id}/restore-test` → 202 (`backup.test`, stage `restore_test`). Artifacts add `dump_engine, verified_at, verify_message,
  restore_tested_at, restore_test_status, restore_test_message, export_status, exported_at, export_bytes, export_sha256`.
- **Domain** (CERT_DRIVER=traefik) adds `dns_provider, has_dns_token`; admin create/update accept `dns_provider: cloudflare|digitalocean|hetzner|route53|gandiv5`
  and write-only `dns_token`. `https_status` stays `issuing` until `proxy.certs` reports the certificate from acme.json; `certificate` adds `resolver, checked_at`.
- **GET /metrics** and `GET /app/apps/{id}/metrics` add `source: "prometheus"` when `METRICS_DRIVER=prometheus` (same series shape); `requests_24h` is the
  Traefik `traefik_service_requests_total` increase over 24 h, else null.
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

## Terminal relay (CTR-008, LOG-002)
`dockermgr-relay` (`agent/cmd/relay`) pairs two **inbound** WebSockets; neither the API nor the relay ever connects to an agent.

1. `POST /containers/{id}/terminal-sessions` → `ws_url` = `TERMINAL_WS_URL/{session_id}?token=…` (default `/relay/terminal`, relative to the
   panel origin). The token is single use; the session expires after 15 min.
2. Browser opens `wss://panel/relay/terminal/{session_id}?token=…&cols=&rows=`. Binary frames = TTY bytes both ways; text frames are JSON:
   browser → relay `{type:"resize",cols,rows}` | `{type:"close"}`; relay → browser `{type:"status"|"ready"|"error"|"exit"|"closed", message?, code?, reason?}`.
3. Relay → API `POST /internal/relay/terminal-sessions/{id}/connect` {token, cols, rows, ip} (header `X-Relay-Secret: RELAY_SECRET`) →
   `{session_id, server_id, container_id (docker id), container_name, user_id, user_email, shell_user, shell, expires_at, recording, command}`.
   `command` is a signed `terminal.open` {session_id, container_id, shell_user, shell, expires_at, relay_url, cols, rows}; it is also queued for
   the next heartbeat (fallback), the agent journal drops the duplicate.
4. Agents keep `wss://…/relay/agent` open (Bearer agent_secret, verified with `POST /internal/relay/agents/verify` {agent_secret} incl. AGENT_MTLS);
   the relay pushes `{type:"command", command}` there. The agent verifies it like any command (HMAC, age, policy, journal), then dials
   `wss://…/relay/agent/session/{session_id}` and attaches a Docker exec (`Tty`, fixed shell from its allow-list, never root) to the container.
5. The relay polls `GET /internal/relay/terminal-sessions/{id}` → {ended, ended_at, expires_at} every 2 s ("End session" = `DELETE /terminal-sessions/{id}`),
   closes at `expires_at`, then `POST …/{id}/recording` (asciicast v2 JSON lines, output + resize events) and `POST …/{id}/closed` {reason}.
- **Terminal session** `{id, container_id, container_name, server_id, user_id, user_email, shell_user, shell, status: pending|active|ended|expired,
  close_reason, created_at, connected_at, expires_at, ended_at, recording:{bytes, format:"asciicast-v2"}|null, ip}`;
  recording → `{data:{session_id, format, cast}}`. Audit: `terminal.opened`, `terminal.attached`, `terminal.closed` {reason}.

## Agent mTLS (SEC-003)
- Enrolment CSR → client certificate (EKU clientAuth, `AGENT_CERT_DAYS`, default 90) from the local agent CA. `GET /agent/v1/ca.crt` serves the CA for the proxy.
- `POST /agent/v1/certificate/renew` {csr} (agent auth) → `{certificate, fingerprint, not_before, expires_at}`; the agent renews at 2/3 of the lifetime;
  earlier certificates of the agent are revoked.
- `AGENT_MTLS=off|header|required` (default `header`). The proxy terminates TLS and forwards `X-SSL-Client-Verify` (must be `SUCCESS` when sent),
  `X-Client-Cert` (URL-encoded PEM or base64 DER, first of a chain) and/or `X-Client-Cert-Fingerprint` (SHA-256 or SHA-1 hex, colons allowed); header
  names are configurable. `header`: verified when present; `required`: every `/agent/v1` call (except enrol/install/download/ca.crt) and every relay
  agent connection must carry a non-revoked, unexpired certificate of that agent, else 401. Examples: `docs/deploy/`.

## Passkeys (SEC-002, UACC-002)
- WebAuthn via web-auth/webauthn-lib; options use the WebAuthn JSON format (`@simplewebauthn/browser` `optionsJSON`). RP id `WEBAUTHN_RP_ID`
  (default APP_URL host), allowed origins `WEBAUTHN_ORIGINS` (default `CORS_ALLOWED_ORIGINS`). Registration requires a resident key and user
  verification, attestation `none`, ES256/RS256. Challenges are single use, 5 min.
- **Passkey** `{id, name, aaguid, transports[], backup_eligible, created_at, last_used_at}`. Audit `auth.login` carries `meta.method`
  (`password`, `password+totp`, `password+passkey`, `passkey`).

## DNS zones (SRS 4.18, DNS-001..013)

Providers: Cloudflare and PowerDNS. Every change is sent to the provider, and the panel's mirror is updated only once the provider accepts it. Reads need `resources.view|servers.view`; changes need `deploy|settings.manage`.

**Domains & SSL use these zones (DNS-013).** A domain whose name falls inside one of the organisation's zones (the longest match wins) is handled through that zone:

- With no `challenge` and no `dns_token` given, the domain defaults to DNS-01.
- On create and on every `verify`, the panel writes the records the domain needs (A, the `www` CNAME, the `_acme-challenge` TXT) into the zone, unproxied. Records already there are left alone. A different value under the same name is reported in the `verify` response as `zone_records[{name, type, status: created|present|conflict|failed, message?}]` and is never overwritten.
- Traefik gets the zone's credentials for the challenge: `CF_DNS_API_TOKEN` for Cloudflare, or `PDNS_API_URL`/`PDNS_API_KEY`/`PDNS_SERVER_NAME` with the `le-dns-pdns` resolver for PowerDNS.
- A token given on a domain still wins.
- Domain responses carry `dns_zone {id, name, provider}`, or `null`.

| Method | Path | Notes |
|---|---|---|
| GET | `/dns/providers` | Lists accounts as `{id, name, type, account, created_at}`. Credentials are never returned. |
| POST | `/dns/providers` | Cloudflare: `{name, type: cloudflare, token, account_id?}`. PowerDNS: `{name, type: powerdns, api_url, api_key, server_id? (localhost), nameservers[]}`; the nameservers are set as NS on new zones. The credentials are checked first; a rejection returns 502 `provider_error`. |
| DELETE | `/dns/providers/{id}` | Returns 409 while the account still has zones in the panel. |
| GET | `/dns/zones` | Lists zones as `{id, name, status pending\|active, nameservers[], records_count, provider, last_synced_at, dnssec {status, ds[]}, supports_proxy}`. |
| POST | `/dns/zones` | Body `{dns_provider_id, name, defaults_to?}`. A zone that already exists at the provider is adopted. `defaults_to` (IPv4) adds an apex A record and a `www` CNAME. |
| GET | `/dns/zones/{id}` | The zone plus its `records[]` `{id, type, name, content, ttl (1 = Auto), priority, proxied}`. |
| DELETE | `/dns/zones/{id}` | Removes the zone from the panel only; it stays at the provider. |
| POST | `/dns/zones/{id}/check-nameservers` | Returns `{status, expected[], found[], delegated}` and sets the zone active once its nameservers are delegated. |
| POST | `/dns/zones/{id}/sync` | Returns `{added[], removed[], changed[], in_sync}` comparing the panel with the provider, then adopts the provider's records. |
| GET | `/dns/zones/{id}/dnssec` | DNS-009. Returns `{status disabled\|pending\|active, ds[]}` and stores it on the zone. `pending` (Cloudflare) means the DS record is not yet at the registrar. |
| PUT/PATCH | `/dns/zones/{id}/dnssec` | Body `{enabled}`. Cloudflare turns DNSSEC on through its API. PowerDNS creates one active ECDSA P-256 CSK, rectifies the zone and signs it straight away; turning it off deletes the zone's keys. |
| GET | `/dns/zones/{id}/settings` | DNS-010, Cloudflare only (409 on PowerDNS). Returns `{ssl off\|flexible\|full\|strict, always_use_https, min_tls_version 1.0..1.3, cache_level basic\|simplified\|aggressive}`. |
| PUT/PATCH | `/dns/zones/{id}/settings` | Any subset of the settings above. The change is audited with old and new values. |
| GET | `/dns/zones/{id}/export` | DNS-011. The provider's current records as a BIND file (`text/dns` attachment), or `{filename, content}` with `Accept: application/json`. SOA is left out. Auto TTL is written as 300. |
| POST | `/dns/zones/{id}/import` | DNS-011. Body `{zone_file, dry_run?}`. Understands `$ORIGIN`, `$TTL`, relative and `@` names, blank names (same name as the line above), parenthesised continuations, comments and multi-string TXT. Records already in the zone are left as they are, and TTLs snap to the nearest panel value. Returns `{dry_run, created[], unchanged, skipped[{line, reason}]}`; every skipped line says why (SOA is skipped silently). |
| POST | `/dns/zones/{id}/records` | Body `{type, name, content, ttl?, priority?, proxied?}`. The name is relative to the zone (`@` = apex). Types: A, AAAA, CNAME, MX, TXT, SRV, CAA, NS. TTL is 1, 300, 3600 or 86400. Only A, AAAA and CNAME can be proxied, only on Cloudflare, and proxied records always use Auto TTL. PowerDNS has no Auto TTL, so Auto is stored as 3600. New PowerDNS zones use `SOA-EDIT-API: INCREASE`, so the serial goes up on every change (DNS-008); its record ids are derived from name, type and content, and each change rewrites the whole record set. A CNAME at the apex is refused. |
| PUT/PATCH | `/dns/zones/{id}/records/{recordId}` | Same body; the type cannot change. |
| DELETE | `/dns/zones/{id}/records/{recordId}` | |


## Migration wizard (SRS MIG)

Moves cPanel accounts onto the platform. The agent fetches a cPanel full backup (`cpmove-<user>.tar.gz`) into its state directory (`migrations/<id>/`) and reads it without running anything from it. Each site becomes a new app on the target server. The app is deployed on a temporary platform hostname and then filled with the site's files and database. DNS is not changed. Reads need `resources.view|servers.view`; actions need `deploy`.

| Method | Path | Notes |
|---|---|---|
| GET | `/migrations` | `[{id, source, status, server, account, sites, migrated, created_at}]` |
| POST | `/migrations` | MIG-001 `{server_id, archive_url, sha256?}` returns 202. `migrate.fetch` downloads the backup and checks the SHA-256 if one is given; a finished download is reused when the job is retried. Then `migrate.inspect` reads `userdata/main` (main, addon and sub domains), `userdata/<domain>` (document roots), `mysql/*.sql` and the file count and size, and flags document roots holding `wp-config.php`. Status: `fetching` → `ready`, or `failed` with `error`. The URL is stored encrypted and never returned. |
| GET | `/migrations/{id}` | `{status, inventory{user, sites, databases, files, bytes}, sites[], preflight[], timeline[{at, event}], error}`. Each site has `{domain, kind, docroot, wordpress, enabled, app_name, database, target_database_id, status: pending\|deploying\|copying\|migrated\|failed, app_id?, temp_url?, error?}`. |
| PATCH | `/migrations/{id}/sites` | MIG-003 `{sites[{domain, enabled?, app_name?, docroot?, database?, target_database_id?}]}`, allowed while the status is `ready`. `target_database_id` must be a MySQL or MariaDB database on the target server. Pre-flight (MIG-002) runs on each read: the domain or app name is already taken, there is no document root, the database is not in the backup, there is no target database, or WordPress has no database. |
| POST | `/migrations/{id}/start` | MIG-004/005 returns 409 while a pre-flight check fails. For each enabled site it creates an app (`wordpress:6` for WordPress, otherwise `php:8.3-apache`) with `/var/www/html` as its volume, adds `<app>.<platform domain>` as the temporary hostname, and deploys it. A WordPress app gets `WORDPRESS_DB_*` pointing at the target database. When a deployment succeeds, the copy job runs: `migrate.extract` unpacks `homedir/<docroot>/` (regular files and folders only, owned by 33:33; files already present with the same size are skipped, so a copy resumes), `migrate.import_db` creates the database (named after the app) and streams `mysql/<db>.sql` into it, and for WordPress `wp config set DB_HOST/DB_NAME/DB_USER/DB_PASSWORD`. The database password is added only when the agent command is sent; job payloads hold a placeholder. When every site is `migrated`, the migration is `migrated`. |
| POST | `/migrations/{id}/sites/{index}/retry` | Re-runs one site's copy (failed or migrated); it resumes. |
| POST | `/migrations/{id}/sites/{index}/cutover` | MIG-007/010: the site must be `migrated` and its temporary address must answer without a 5xx (409 otherwise). If the domain is in a DNS zone the panel manages, its A, AAAA and CNAME records are saved in `previous_records` and deleted, then the domain is added to the app, which writes the new records (DNS-013). Otherwise the domain is added and `dns_records` lists what to set at the DNS host. The site becomes `live`. |
| POST | `/migrations/{id}/sites/{index}/rollback` | MIG-008: deletes the domain from the app and puts the saved records back in the zone. The site returns to `migrated`; the new app is kept for another try. |
| POST | `/migrations/{id}/resync` | MIG-009 `{archive_url, sha256?}`: a newer backup of the same account. `migrate.cleanup` and `migrate.fetch` run, then every `migrated`, `live` or `failed` site is copied again (files of the same size are skipped, databases re-imported). A live site stays live. |
| POST | `/migrations/bulk` | MIG-011 `{server_id, archives[{url, sha256?}]}`, up to 50: one migration each, returns `{queued, ids}`. |
| POST | `/migrations/{id}/approve` \| `/reject` | UMIG-002: `approve {server_id}` starts the fetch of a customer's request; `reject {reason?}` stores the reason, which the customer sees. Only `requested` migrations. Platform administrators see every organisation's requests. |
| GET \| POST | `/app/migrations` | UMIG-001/003 (customer, `app.manage`): `POST {archive_url, sha256?, notes?}` returns 201 with `status: requested`, and nothing runs until approval. At most five may be waiting at once. `GET` lists the customer's requests with their sites, temporary addresses, timeline and decision. |

## WordPress toolkit (SRS WP)

Every action is WP-CLI, run by the agent in a short-lived `wordpress:cli` container. That container shares the site container's files (`--volumes-from`) and network (`--network container:`), so it uses the site's own `wp-config.php` and database. It runs as www-data (33) and never through a shell. Only these WP-CLI commands are allowed: `core plugin theme option config cache search-replace user cron maintenance-mode cli rewrite transient language redis db`, where `db` is only `db export|import wp-content/ws-restore/<id>.sql`. The options `--exec`, `--require`, `--ssh`, `--http`, `--path` and `--context` are refused. An action with several steps is one job; each step is dispatched after the one before it succeeds. Reads need `resources.view|servers.view`; actions need `deploy`.

| Method | Path | Notes |
|---|---|---|
| GET | `/wordpress` | WP-001/002: `{summary {sites, updates, security, outdated_php, staging}, sites[{id, url, app, server, version, php_version, core_update, updates, security, score, staging, settings, error, inventoried_at}]}` |
| POST | `/wordpress/detect` | Creates a site row for every app running a `wordpress*` image and inventories each (also daily at 04:10). |
| GET | `/wordpress/{id}` | Adds `plugins[]`, `themes[]` and `vulnerabilities[{type, slug, version, name, fixed_in}]`. |
| POST | `/wordpress/{id}/refresh` | `wp.inventory`: core and PHP version, plugins, themes, core update, blog_public, WP_DEBUG, WP_AUTO_UPDATE_CORE, DISABLE_WP_CRON, maintenance. A probe that fails leaves its field out. The vulnerability scan follows (WP-008): WPVulnerability.net for the installed core, plugin and theme versions. Score: 100, minus 25 per vulnerability (at most 75), minus 5 per waiting update (at most 15), minus 10 for PHP below 8.1. |
| PATCH | `/wordpress/{id}/settings` | WP-003 `{setting: maintenance\|debug\|indexing\|auto_minor\|platform_cron\|redis, on}`. `platform_cron` sets `DISABLE_WP_CRON`, and `wordpress:cron` runs due events every 5 minutes. `redis` needs `WORDPRESS_REDIS_HOST` (409 otherwise); it sets `WP_REDIS_HOST`, installs and activates `redis-cache`, then runs `wp redis enable`. |
| POST | `/wordpress/{id}/tools/{tool}` | WP-004/007: `flush_cache`, `verify_checksums`, `search_replace {search, replace, dry_run=true}` (skips `guid`; the dry run is the default), `plugin_activate\|plugin_deactivate {plugin}`, `theme_activate {theme}`, `rotate_salts` (`config shuffle-salts`; everyone is logged out). Slugs must match `^[a-z0-9][a-z0-9_.-]*$`. Activations refresh the site afterwards. The WP-CLI output is on the job. |
| POST | `/wordpress/{id}/update` | WP-005 `{scope: core\|plugins\|themes\|all, items?[]}`. Only items with an update are touched (409 if none). Steps: restore point (below), `core update` + `core update-db` and/or `plugin\|theme update <names>`, then an HTTP health check of the site URL (5xx, no answer, or WordPress's "critical error" page fails it), then a refresh. If an update command or the health check fails, the rest of the plan becomes a rollback: `db import` of the dump and `install <name> --version=<old> --force` for what was updated (`core update --version=<old> --force` for core). The job then fails with the reason and the point becomes `restored`. |
| POST | `/wordpress/update-all` | WP-006 `{scope}`: one update job per site with updates on an online server; returns `{queued, job_ids}`. |
| PATCH | `/wordpress/{id}/hardening` | WP-009 any of `{xmlrpc, file_edit, user_enum, security_headers, limit_logins}` (booleans). Writes one must-use plugin, `wp-content/mu-plugins/ws-hardening.php`, holding all switches; `file_edit` sets or deletes `DISALLOW_FILE_EDIT`. `limit_logins`: 5 failures per IP lock it for 15 minutes. State is in `hardening` on the site rows. |
| GET | `/wordpress/{id}/restore-points` | WP-011 `[{id, reason: manual\|update, status: pending\|ready\|failed\|restored, version, plugins, themes, created_at, restored_at}]`. |
| POST | `/wordpress/{id}/restore-points` | A manual point: `wp db export` into `wp-content/ws-restore/` (blocked from the web by `.htaccess` and an `index.php`) and the core, plugin and theme versions. Five points are kept per site; older dumps are deleted. |
| POST | `/wordpress/{id}/restore-points/{point}/restore` | Imports the dump and reinstalls the core, plugin and theme versions that differ from the point, then refreshes. |
| POST | `/wordpress/{id}/reset-password` | `{user}` returns `{job_id, password}`. A random 20-character password is shown once. The job payload holds only a redacted copy; the real one travels in the encrypted agent command. The audit has the user name only. |
| POST | `/wordpress/{id}/staging` | WP-010: returns `{staging_id, url, user: "staging", password}`. Needs `WORDPRESS_DB_HOST` in the app's environment (409 otherwise). Creates the app `<name>-staging` on the same server and image, with the same environment plus `WORDPRESS_TABLE_PREFIX=stg_` and a `WORDPRESS_CONFIG_EXTRA` guard (HTTP basic auth, user `staging`, password checked by its sha256; `X-Robots-Tag: noindex`). The domain is `staging.<first custom domain>`, or `<name>-staging.<platform domain>`. Then it deploys. When the deployment succeeds, the staging site is filled automatically (refresh, below). The staging site is its own row (`production_id`, `staging_status: provisioning\|cloning\|ready\|failed`); `GET /wordpress/{id}` of a staging site includes `staging_password`. |
| POST | `/wordpress/{id}/staging/refresh` | Run on the staging site. `wp.copy_tables` copies the live prefix to `stg_` in the same database (it drops the old `stg_` tables and renames the prefixed option and user-meta keys). The staging `wp-content` goes to the Trash and the live one is copied in (without `ws-restore`). Then `search-replace` live URL → staging URL, `blog_public=0`, and a refresh. |
| POST | `/wordpress/{id}/staging/push` | Run on the staging site; it must be `ready`. The live site gets a restore point (`reason: push`). Then `stg_` is copied to the live prefix, the live `wp-content` goes to the Trash (its restore dumps are kept), staging's `wp-content` is copied in, `search-replace` runs back, and `blog_public` returns to the live setting. |
| POST | `/wordpress/{id}/staging/delete` | `wp.drop_tables` (the agent drops only `stg…_` prefixes), then the staging app is retired and its row removed. |
| POST | `/wordpress/{id}/login-link` | WP-012 returns `{job_id, url, expires_in: 60}`. It writes the must-use plugin `wp-content/mu-plugins/ws-login.php` and stores `sha256(token):expiry` in the option `ws_login_token`. The plugin checks the token with `hash_equals`, deletes the option on any use, signs in the first administrator and redirects to wp-admin. Audited. |
