# Enterprise provisioning API

Cloud Admin (account.worksuitecloud.com/admin) deploys a dedicated WorkSuite Server per enterprise
customer. Base `/api/v1/enterprise`, `Authorization: Bearer <token>` with the `enterprise.deploy`
ability (`php artisan enterprise:service-account` creates the service account, role
`enterprise_service`, in the staff organisation, and prints the token once). Every POST needs an
`Idempotency-Key`. `{ref}` is the panel id or Cloud Admin's `external_id`. Image and container
settings live in `api/config/enterprise.php`.

| Method | Path | Body / query | Answer |
|---|---|---|---|
| GET | `options` | | `{image, domain, sizes:[{code,label,cpus,ram_mb,disk_gb}]}` |
| GET | `slug-availability?slug=` | | `{slug, valid, available, hostname}` |
| GET | `deployments?status=` | | list of deployments |
| POST | `deployments` | `{external_id, customer_name, slug, version, size: small\|medium\|large, callback_url?, callback_secret? (≥32), node_id?, region?}` | 202 deployment + `deployment_id` + `callback_secret` (only when generated, once); 200 for a repeated `external_id` |
| GET | `deployments/{ref}` | | deployment |
| GET | `deployments/{ref}/logs` | `container=app\|worker\|database\|redis`, `tail≤2000` | `{container, name, lines[], fetched_at}` (secrets redacted; lines refresh on the next poll) |
| POST | `deployments/{ref}/configure` | `{bootstrap: {…wizard JSON…}, custom_domain?}` | 202 deployment (status `configuring`) |
| POST | `deployments/{ref}/actions/{restart\|stop\|start\|redeploy}` | redeploy: `{version?}`; restart: `{container?: app\|worker\|database\|redis}` (only that one) | 202 `{action, job_ids, status}` |
| DELETE | `deployments/{ref}` | | deployment, status `deleted` (stopped, all data kept) |
| GET | `versions` | | `{image, versions:[{version, published_at, image, created, size_mb, nodes:[name], node_ids:[id]}], nodes:[{id, name, inventory_at, inventory_stale, scanned_at, deployment_node}]}` — tags on the enterprise nodes **and every node an enterprise deployment of the organisation runs on** (1.42.0), from the heartbeat inventory merged with a direct `docker images` on each node (host-mode `cron.exec`). `enterprise:images` (every 2 minutes) re-scans those nodes; a scan older than `images_stale_after` (300 s) is also re-run on a call (its answer arrives on a later call — the Updates tab's `updates/refresh` waits for it) |
| GET | `nodes` | | `{nodes:[{id, name, region, tags[], status, eligible, reason, free{cpus, ram_mb, disk_gb}, cpu{allocatable,free,used_pct}, ram_mb{allocatable,free,used_pct}, disk_gb{total,free,used_pct}, containers, enterprise_deployments}]}` |
| POST | `deployments/{ref}/resize` | `{size}`, `{package}` (1.39.0: a Cloud Admin package's explicit limits — code, label, app_cpus, app_ram_mb, worker_cpus, worker_ram_mb, db_ram_mb, redis_ram_mb, fpm_max_children, disk_gb; bounds in `enterprise.package_limits`; `limits.size = package`, `limits.package = code`; disk cannot shrink) or any of `{cpus 0.5–16, ram_mb 512–65536, disk_gb 10–2000}` (web container) | 202 deployment; web + worker redeploy with the new limits, `FPM_MAX_CHILDREN = max(4, ram_mb / 80)` for custom RAM; steps `resize` + `redeploy`; 409 `no_capacity` when the node cannot take the extra CPU/RAM. MariaDB/Redis memory is not changed |
| GET | `deployments/{ref}/metrics` | `range=1h\|6h\|24h\|7d` | `{range, series:[{at, cpu_percent, memory_mb, disk_gb}] (summed over the containers), containers:[{role, name, cpu:[{t,v}], ram:[{t,v}]}], disk:{used_mb, limit_gb, volumes:[{name,size_mb}]}}` (heartbeat samples; disk from volume sizes, measured every 10 min) |
| POST | `deployments/{ref}/domains` | `{domain}` | 201 domain view `{domain, name, kind: platform\|custom, primary, status: ok\|pending\|failed, tls_status: ok\|pending\|failed, dns_raw, https_raw, records:[{type,name,value,ttl}], checked_at, error}`; in the managed zone the records are published at once (a conflict sets status failed with `error`) |
| POST | `deployments/{ref}/domains/{domain}/verify` | | domain view (DNS check, then certificate) |
| POST | `deployments/{ref}/domains/{domain}/primary` | | 202 deployment; APP_URL switches and web redeploys; 409 until the domain is verified with TLS active |
| DELETE | `deployments/{ref}/domains/{domain}` | | deployment (removing the primary custom domain switches back to the platform address; the platform address cannot be removed) |
| POST | `deployments/{ref}/licence-refresh` | | 202 `{job_id, status}`; runs `worksuite-entrypoint spark license:refresh` in web (prints one JSON line; exit 0 ok, 2 bad key, 3 no licence, 4 failed); the parsed JSON (masked key only) lands on the step as `result`; step `licence_refresh` (and a failed `licence` step turns succeeded) |
| POST | `deployments/{ref}/licence-activate` | `{key}` (WS-XXXX-XXXX-XXXX-XXXX-XXXX) | 202 `{job_id, status}`; root exec of `license:refresh --key -` with the key on stdin (never in the process list, a log or the stored command); 422 for another format, 409 unless active/configuring — or awaiting_setup when the app's last `enterprise:status` says `bootstrapped: true` (1.41.0; the 409 says which); same step `licence_refresh` + `result` |
| POST | `deployments/{ref}/steps/{step}/retry` | step ∈ image, database, redis, app, worker, domain, custom_domain, licence, licence_refresh, redeploy, resize | 202 deployment; 409 when the step has not failed, 422 for other steps; `bootstrap` returns 409 `bootstrap_needs_payload` with `error.hint` (the panel never keeps the wizard payload; Cloud re-posts `configure`). A failed deployment returns to `provisioning` (or `active` if it was activated) |
| POST | `deployments/{ref}/purge` | `{confirm: "<slug>"}` | deployment, status `purged` (containers, volumes, databases, domains, project removed) |

Statuses: `queued → provisioning → awaiting_setup → configuring → active`, plus `failed`, `stopped`,
`deleted`, `purged`. A failed bootstrap can be configured again.

Deployment: `{id, external_id, customer_name, slug, version, image, size, status, status_reason,
project_id, node{id,name,region}, urls{app,platform,custom,login,panel}, health (ok\|degraded\|down), domain{name,dns_status,https_status (ok\|pending\|failed),dns_raw,https_raw,records},
custom_domain{…}|null, steps[{name,label,status,started_at,finished_at,message}], errors[{step,message,detail,code,hint,fields?,at}] (see Errors),
containers[{role,name,state,health,cpu_pct,mem_mb,started_at}], resources{cpu_pct,ram_mb,ram_limit_mb,cpu_limit,disk_gb,node_disk_pct},
limits{size (preset or `custom`), cpus, ram_mb, worker_cpus, worker_ram_mb, disk_gb, fpm_max_children},
login{url (`<app url>/login`), admin_email}, domains[domain view], app_status (last enterprise:status JSON), bootstrapped (true/false from app_status, null when unknown — 1.41.0), bootstrap (bootstrap result JSON, secrets dropped), created_at, updated_at, activated_at, deleted_at, purged_at}`.

Steps: placement, image (waits until the node reports `worksuite-server:<version>`; images are
`docker load`ed, there is no registry), database, redis, app, worker, domain, setup, then bootstrap,
custom_domain, licence (exit 5), live, redeploy.

Bootstrap: `worksuite-entrypoint spark enterprise:bootstrap --json -` in the web container, JSON on
stdin (sent base64; the command is redacted from the agent command once it finishes), result = the
last JSON line. Exit 0 or 3 → active; 5 → active with the licence step failed; anything else → failed.
A custom domain sets APP_URL to it and redeploys web.

Exec user: bootstrap, status and licence refresh run as root (`ENTERPRISE_EXEC_USER`, default `0:0`)
because the image's entrypoint writes `.env` and chowns before dropping to www-data for spark. The
agent's container mode refuses root, so a root exec is sent in host mode as `docker exec -u 0:0 <web>`.

Errors: every failed step in `errors[]` carries `message` (never empty), `detail` (redacted tail of the
output, last 60 lines: secrets, `*key*/password/secret/token=` values and base64 blobs such as the
bootstrap payload are removed; the administrator password is never stored), `code` and `hint` (fixing
guide). `fields` is kept when enterprise:bootstrap printed them. Codes:

| code | when |
|---|---|
| `EXEC_PERMISSION` | exit 2/126 with "Permission denied" (ran without root) |
| `EXEC_USER_REFUSED` | the agent refused the exec user |
| `INVALID_INPUT`, `ALREADY_BOOTSTRAPPED`, `MIGRATION_FAILED`, `ROLES_MISSING`, `BOOTSTRAP_FAILED` | bootstrap JSON `code`/`error` |
| `LICENCE_FAILED` | bootstrap exit 5 (step `licence`) or license:refresh failed |
| `DB_UNREACHABLE` | SQLSTATE 2002/2003/2006/1045, connection refused: the database did not answer |
| `TIMEOUT` | exit 124 |
| `CONTAINER_NOT_RUNNING` | no such / not running container |
| `IMAGE_MISSING` | app/worker deploy failed pulling or finding the image |
| `CONTAINER_UNHEALTHY` | app/worker not healthy; `detail` holds the container's last log lines |
| `DB_CONTAINER_FAILED` | database/redis container failed to start |
| `DNS_CONFLICT`, `TLS_FAILED` | domain / custom_domain step |
| `EXEC_FAILED` | any other failed exec |

Webhooks: `POST callback_url` on every status change, body `{id, event: "deployment.status",
occurred_at, previous_status, deployment}`, headers `X-Enterprise-Event`, `X-Enterprise-Event-Id`,
`X-Enterprise-Deployment`, `X-Enterprise-Signature: t=<unix>,v1=hex(HMAC-SHA256(callback_secret, t + "." + body))`.
Retried with backoff (`enterprise:webhooks`, every minute); `enterprise:sync` advances deployments every minute.

Redeploy of a `failed` deployment (action or step retry) puts it back on the step machine: status
`provisioning` with the app/worker steps running (or `active` when it had been activated).
Web and worker run with `cap_add` CHOWN, DAC_OVERRIDE, FOWNER, SETGID, SETUID (the entrypoint
prepares the uid-33 volumes as root under the agent's cap_drop ALL).

Not-founds answer 404 with their own codes: `deployment_not_found`, `domain_not_found` (a bare 404/405
means the endpoint is not deployed). Slugs are refused when the enterprise zone (`ENTERPRISE_DOMAIN`,
default `worksuite.lk`) already has a record at `<slug>.<domain>` or below it. The `domain` step
message carries the zone publish result (`DNS: A acme.worksuite.lk created`), and a conflict fails it.

## Part F — the deployment page's CloudPanel tabs

Every enterprise deployment is a panel project (`project_id`). These endpoints, all under
`/api/v1/enterprise/deployments/{ref}` with the `enterprise.deploy` token, resolve the deployment in
the tenant (the staff organisation) and call the **existing project endpoints** for that project under
a per-call grant (platform_admin on that one project, `App\Support\ProjectGrant`). Validation, audit
entries (`project.firewall.*`, `project.member.*`, `project.quotas.set`, …) and JSON shapes are the
project endpoints' own; the "same as" column names them. Mutations need an `Idempotency-Key`.

Errors: `deployment_not_found` 404; `project_unavailable` 409 (no project yet, or purged);
`backup_not_found` / `artifact_not_found` 404; **`PANEL_UNSUPPORTED` 501** for what the panel cannot
do (Cloud Admin hides that control/tab). Validation 422 `{error:{code,message,fields}}`.

### Domain & DNS — same as `/app/projects/{id}/dns…` (PDNS)

| Method | Path | Body | Answer `data` |
|---|---|---|---|
| GET | `dns` | | `{counts{total,verified,pending,ssl_active}, domains[…PDNS domain], apps[{id,name,status}], ingress{hostname,ips}, platform_domain, permissions[], is_owner, permission_labels}` (ProjectDomainService::overview) |
| POST | `dns/domains` | `{name, kind?=custom\|platform, app_id?=web app, method?=manual\|provider, www_redirect?}` | 201 PDNS domain + `published[]` |
| GET | `dns/domains/{domainId}` | | PDNS domain |
| PATCH | `dns/domains/{domainId}` | `{is_primary?: true, app_id?}` | PDNS domain |
| DELETE | `dns/domains/{domainId}` | `{confirm?}` | 204 (409 for the platform address `<slug>.<domain>`) |
| POST | `dns/domains/{domainId}/validate` | | DnsValidationService result |
| POST | `dns/domains/{domainId}/publish` | | `{records[]}` |
| POST | `dns/domains/{domainId}/certificate` | | renew result |
| GET | `dns/domains/{domainId}/zone-records` | | zone records |
| GET | `dns/history` | | change history |

`{domainId}` is the domain's uuid (from `dns`). The part E `domains` endpoints (by name, Cloud Admin
domain shape) stay; switching primary there also sets APP_URL and redeploys web.

### Firewall — same as `projects/{id}/firewall…` (ProjectFirewallController)

| Method | Path | Body | Answer `data` |
|---|---|---|---|
| GET | `firewall` | | `{can_manage, allow[{id,cidr,note,expires_at,active}], block[…], rate_limits[{id,name,paths,limit,window_seconds,action,enabled}], apps[{id,name,locked,lock_reason,locked_at}], inherited{ip_entries,rules}}` |
| POST | `firewall/ips` | `{list: allow\|block, cidr, note?, expires_at?, confirm?}` | 201 `{id,cidr,note,expires_at,active}` |
| DELETE | `firewall/ips/{entryId}` | | 204 |
| POST | `firewall/rate-limits` | `{name, paths[], limit, window_seconds?, action?=block\|throttle\|log, block_minutes?}` | 201 `{id}` |
| DELETE | `firewall/rate-limits/{ruleId}` | | 204 |
| POST | `firewall/lock` · `firewall/unlock` | `{reason?}` | the `firewall` view (locks the **web** app: only allow-listed addresses get in) |

### Security (composed)

`GET security` →
`{tls[{domain,kind,primary,tls_status (ok|pending|failed),https_raw}], access{public,locked,lock_reason,locked_at},
blocked_ips[BlockedIpService rows: {key,ip,kind (manual|automatic|by hand),reason,scope,country,started_at,expires_at,blocked_requests,last_activity,…}] (CrowdSec/automatic blocks + this project's manual blocks),
events[{id,occurred_at,action,ip,country,method,path,rule,domain}] (last 50 firewall events on the deployment's domains),
members[{user_id,name,email,role}], member_roles[], audit[{id,actor,action,resource_type,resource_id,ip,created_at}] (last 50 on the project, its apps and the deployment)}`.

| Method | Path | Body | Answer `data` |
|---|---|---|---|
| PUT/PATCH | `security/members/{userId}` | `{role: owner\|developer\|viewer}` | members[] (person must be in the staff organisation) |
| DELETE | `security/members/{userId}` | | members[] |

### Settings

`GET settings` →
`{env[{application: app|worker, key, value (null for secrets), secret, read_only}], reserved_keys[], app_url, timezone, timezone_key,
maintenance{enabled,reason,since}, restart_policy{value: "unless-stopped", editable: false}, version, image, status,
danger_zone{delete, purge}}`.

Read-only keys: the panel-provided ones (`APP_URL`, `DB_*`, `REDIS_*`, `FPM_MAX_CHILDREN`,
`WORKSUITE_ENTERPRISE_ID`) plus `enterprise.reserved_env` (`APP_KEY`, `ENCRYPTION_KEY`,
`CI_ENVIRONMENT`, `LICENSE_KEY`) and every secret. APP_URL follows the primary domain.

`PATCH settings` `{env?: [{key, value?, application?: app|worker|both (default both), delete?: bool}],
timezone? (IANA, written to `ENTERPRISE_TIMEZONE_ENV`, default `TZ`), maintenance?: bool, maintenance_reason?,
version?, redeploy?: bool}` → the settings view + `{changed[{application,key,deleted}], redeploy (action result)|null, needs_redeploy}`.
A reserved or secret key → 422 per entry. Env changes take effect on the next redeploy (`redeploy: true`,
or `version`, redeploys at once through the part E redeploy). Maintenance = firewall lock of the web app.
Delete / purge stay the part E `DELETE {ref}` / `POST {ref}/purge`.

### Backup — same as `projects/{id}/backups…` (ProjectBackupController)

| Method | Path | Body | Answer `data` |
|---|---|---|---|
| GET | `backups` | | `{schedule{id?,enabled,frequency,retention_days,destination,capacity_gb,encrypted,last_run_at?,last_status?,next_run_at?}, backups[{id,type,status,error,created_at,finished_at,size_mb,applications[]}]}` |
| POST | `backups` | `{application_ids?[]}` | 202 `{id,status}` |
| PUT/PATCH | `backups/schedule` | `{enabled, frequency?=hourly\|daily\|weekly, retention_days?, destination?=local\|s3\|b2\|r2\|gdrive\|sftp, capacity_gb?}` | schedule |
| GET | `backups/{backupId}` | | details `{id,project_id,type,status,…,size_mb,verified,log,applications[{id,name,status,error,artifacts[{id,size_mb,kind,verified,verify_message}]}],configuration,restores[]}` |
| POST | `backups/{backupId}/restore` | `{confirm: true, scope?=project\|applications\|database\|volumes, application_ids?, mode?=replace\|separate}` | 202 restore result; a fresh recovery point is always taken first |
| POST | `backups/{backupId}/artifacts/{artifactId}/download` | | 200 `{status: ready, url, expires_at, bytes, sha256}` or 202 `{status: exporting, job_id}` (call again) |

A project backup covers each application's data volumes (an enterprise deployment's `ent-<slug>-writable` and
`-uploads`, taken once for web + worker since they share them) **and** a dump of every managed SQL/document
database of the project (the deployment's MariaDB; Redis is a cache and is skipped). Earlier releases snapshotted
the unused `app-<name>-data` path and no database. Runs made by an update have `type: pre_update`.

### Monitor (composed)

`GET monitor?range=1h|6h|24h|7d` →
`{range, health, containers[present() containers], series[{at,cpu_percent,memory_mb,disk_gb}],
metrics[{role,name,cpu[{t,v}],ram[{t,v}]}], network[{role,name,rx[{t,v}],tx[{t,v}]}] (cumulative bytes per heartbeat),
disk{used_mb,limit_gb,volumes[]}, uptime[UptimeService report: {domain,domain_id,range,uptime_pct,checks,failed,avg_ms,p95_ms,incidents[]}] (24h for ≤24h ranges, else 7d),
alerts[{id,title,severity,status,resource_type,value,created_at,resolved_at}]}`.

### Resources (ProjectDashboard + ProjectResource)

`GET resources` →
`{health, counts{applications,containers,running,nodes,backups,databases}, applications[dashboard rows], containers[{id,name,image,state,health,cpu_pct,mem_mb,application,role,server}],
databases[{id,name,engine,version,environment,status,size_mb,last_backup_at,connection (password masked)}],
volumes[projects/{id}/volumes rows], deployment_volumes[{name,size_mb}], disk{used_mb,limit_gb}, nodes[]}`.

### Scale up

`GET scale` → `{limits (as the deployment), sizes[{code,label,cpus,ram_mb,worker_cpus,worker_ram_mb,disk_gb,fpm_max_children}],
custom_limits{cpus[min,max],ram_mb[…],disk_gb[…],mb_per_fpm_child}, replicas{web{replicas,supported,max},
worker{replicas: 1, supported: false, reason}}, quotas{quotas (ProjectQuotaService report), can_edit: true}}`.

`POST scale` `{size? | cpus?, ram_mb?, disk_gb?, web_replicas?}` → 202 the scale view + `replicas_change{from,to,result}|null`.
Limits go through the part E resize (records the `resize` step, FPM children follow, redeploys web);
web copies through ScalingService (needs a first release, respects quotas, records a `scale` step).
`worker_replicas` other than 1 → 501 `PANEL_UNSUPPORTED` (the worker runs the scheduler).

`PUT/PATCH scale/quotas` `{cpu?, ram_mb?, storage_gb?, apps?, containers?, replicas?, container_cpu?, container_ram_mb?}` → `{quotas, can_edit}` (same as `projects/{id}/quotas`).

## Updates — `deployments/{ref}/updates…` (EnterpriseUpdateService)

"New version released" = a newer `worksuite-server:<tag>` loaded on the deployment's node (`GET versions`; no
registry). Tags only on other nodes are listed with `on_node: false` and are never `latest` or an auto target. Tags that are not `major.minor[.patch][-build]` (e.g. `latest`) are never offered. A run is:
`scheduled → backing_up` (project backup, `pre_update`) `→ deploying` (part E redeploy on the new tag; the panel's
health gate decides) `→ succeeded`, or `→ rolling_back` (redeploy on the previous tag) `→ restoring_data` (when `settings.restore_data_on_rollback`, default true: the run's pre-update backup is restored, DB + files, each after a fresh safety backup of the current state) `→ rolled_back | failed`. A rolled-back run carries `data_restore {state: running|succeeded|failed|skipped, backup_id, restore_job_ids[], safety_backup_ids[], error?, started_at, finished_at}` and `data_restored` (true only when the data was put back); "rolled back, data restore failed" is `status: rolled_back` + `data_restore.state: failed`, with the safety backups kept. `ENTERPRISE_UPDATE_RESTORE_TIMEOUT` (90 min).
A failed or timed-out backup ends the run as `failed` with nothing changed. One active run per deployment.
`enterprise:updates` (every minute, panel scheduler) advances runs and applies the policy:
`notify` sends `deployment.update_available` once per version; `auto` starts one run per version (dedupe on
deployment + version), only inside the maintenance window. **Customer notice** (`settings.notice_hours`, one of
0/6/24/48/72, default 24): in `auto` mode the panel sends `deployment.update_scheduled` once per (version, window) for
the first window whose notice time (opening − notice_hours) has not yet passed, and an auto run only starts in a window
that was announced that way; a window too close to announce is skipped, never run unannounced. Cloud owns the email
(it knows the customer contacts). `notice_hours: 0` = no announcement and no gate (the old behaviour). Timeouts: `ENTERPRISE_UPDATE_BACKUP_TIMEOUT` (60 min),
`ENTERPRISE_UPDATE_DEPLOY_TIMEOUT` (20 min).

| Method | Path | Body | Answer `data` |
|---|---|---|---|
| GET | `updates` | | `{current_version, image, status, can_update, blocked_reason?, node?{id,name}, available[{version,image,created,size_mb,nodes[],node_ids[],on_node,not_on_node?}], latest? (newest on the channel that is on the deployment's node), settings{mode,channel,notice_hours,restore_data_on_rollback,window{days[1-7 ISO],start "HH:MM",duration_minutes,timezone}}, next_window?, next_notice?{version,window_start,notify_at,announced}, active?, last?, history[run]}` |
| POST | `updates/refresh` | | as GET, plus `refresh{at, nodes[{id,name,state: scanned\|failed\|timeout,error?,scanned_at}]}` (1.42.0): scans the deployment's node and the enterprise nodes now and waits up to `ENTERPRISE_IMAGES_REFRESH_TIMEOUT` (20 s) for the agents, so a just-loaded tag shows in this answer. A node that does not answer in time is `timeout`; its scan stays queued. 6/min |
| PUT/PATCH | `updates/settings` | `{mode?=off\|notify\|auto, channel?=latest\|"3.1", notice_hours?=0\|6\|24\|48\|72, restore_data_on_rollback?=bool, window?{days?,start?,duration_minutes? 30–720,timezone?}}` | as GET |
| POST | `updates` | `{version, scheduled_for? (ISO, future), reason?, requested_by? (display name)}` | 202 run; started at once unless scheduled. 409 when one is active, 422 when the tag is not loaded on the deployment's node (1.42.0: a tag seen only on another node is refused with that node named; when the deployment's node was not scanned in the last 30 s it is scanned first, up to 10 s) |
| POST | `updates/{updateId}/cancel` | | run (only `scheduled`) |
| POST | `updates/{updateId}/restore` | `{confirm: true, reason}` | 202 `{update, redeploy?, restore{restored[],skipped[]}}`: redeploys the run's previous tag when it differs, then restores its pre-update backup (DB + volumes) after a fresh recovery point |

run = `{id, from_version, to_version, trigger: manual|scheduled|auto, status, scheduled_for, started_at, finished_at, created_at, backup_id, backup_status, requested_by, reason, error, log[{at,status,message}], can_cancel, can_restore}`.

Webhooks: `deployment.update` on every transition, body `{…, deployment, update: run}` (plus `restore{backup_id,restores}`
for a restore); `deployment.update_available` `{…, update_available{version,current_version,mode}}`; `deployment.update_scheduled`
`{…, update_scheduled{version,current_version,window_start,window_end,timezone,notice_hours,notify_at}}` (UTC ISO).
