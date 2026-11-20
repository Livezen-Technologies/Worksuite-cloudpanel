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
| GET | `versions` | | `{image, versions:[{version, published_at, image, created, size_mb, nodes:[name]}], nodes:[{id, name, inventory_at, inventory_stale, scanned_at}]}` — tags from the heartbeat inventory merged with a direct `docker images` on each node (host-mode `cron.exec`, re-run when the scan is older than `images_stale_after`, 300 s; a new tag appears on the next call) |
| GET | `nodes` | | `{nodes:[{id, name, region, tags[], status, eligible, reason, free{cpus, ram_mb, disk_gb}, cpu{allocatable,free,used_pct}, ram_mb{allocatable,free,used_pct}, disk_gb{total,free,used_pct}, containers, enterprise_deployments}]}` |
| POST | `deployments/{ref}/resize` | `{size}` or any of `{cpus 0.5–16, ram_mb 512–65536, disk_gb 10–2000}` (web container) | 202 deployment; web + worker redeploy with the new limits, `FPM_MAX_CHILDREN = max(4, ram_mb / 80)` for custom RAM; steps `resize` + `redeploy`; 409 `no_capacity` when the node cannot take the extra CPU/RAM. MariaDB/Redis memory is not changed |
| GET | `deployments/{ref}/metrics` | `range=1h\|6h\|24h\|7d` | `{range, series:[{at, cpu_percent, memory_mb, disk_gb}] (summed over the containers), containers:[{role, name, cpu:[{t,v}], ram:[{t,v}]}], disk:{used_mb, limit_gb, volumes:[{name,size_mb}]}}` (heartbeat samples; disk from volume sizes, measured every 10 min) |
| POST | `deployments/{ref}/domains` | `{domain}` | 201 domain view `{domain, name, kind: platform\|custom, primary, status: ok\|pending\|failed, tls_status: ok\|pending\|failed, dns_raw, https_raw, records:[{type,name,value,ttl}], checked_at, error}`; in the managed zone the records are published at once (a conflict sets status failed with `error`) |
| POST | `deployments/{ref}/domains/{domain}/verify` | | domain view (DNS check, then certificate) |
| POST | `deployments/{ref}/domains/{domain}/primary` | | 202 deployment; APP_URL switches and web redeploys; 409 until the domain is verified with TLS active |
| DELETE | `deployments/{ref}/domains/{domain}` | | deployment (removing the primary custom domain switches back to the platform address; the platform address cannot be removed) |
| POST | `deployments/{ref}/licence-refresh` | | 202 `{job_id, status}`; runs `worksuite-entrypoint spark license:refresh` in web; step `licence_refresh` (and a failed `licence` step turns succeeded) |
| POST | `deployments/{ref}/steps/{step}/retry` | step ∈ image, database, redis, app, worker, domain, custom_domain, licence, licence_refresh, redeploy, resize | 202 deployment; 409 when the step has not failed, 422 for other steps; a failed bootstrap is retried with `configure`. A failed deployment returns to `provisioning` (or `active` if it was activated) |
| POST | `deployments/{ref}/purge` | `{confirm: "<slug>"}` | deployment, status `purged` (containers, volumes, databases, domains, project removed) |

Statuses: `queued → provisioning → awaiting_setup → configuring → active`, plus `failed`, `stopped`,
`deleted`, `purged`. A failed bootstrap can be configured again.

Deployment: `{id, external_id, customer_name, slug, version, image, size, status, status_reason,
project_id, node{id,name,region}, urls{app,platform,custom,login,panel}, health (ok\|degraded\|down), domain{name,dns_status,https_status (ok\|pending\|failed),dns_raw,https_raw,records},
custom_domain{…}|null, steps[{name,label,status,started_at,finished_at,message}], errors[{step,message,at}],
containers[{role,name,state,health,cpu_pct,mem_mb,started_at}], resources{cpu_pct,ram_mb,ram_limit_mb,cpu_limit,disk_gb,node_disk_pct},
limits{size (preset or `custom`), cpus, ram_mb, worker_cpus, worker_ram_mb, disk_gb, fpm_max_children},
login{url (`<app url>/login`), admin_email}, domains[domain view], app_status (last enterprise:status JSON), bootstrap (bootstrap result JSON, secrets dropped), created_at, updated_at, activated_at, deleted_at, purged_at}`.

Steps: placement, image (waits until the node reports `worksuite-server:<version>`; images are
`docker load`ed, there is no registry), database, redis, app, worker, domain, setup, then bootstrap,
custom_domain, licence (exit 5), live, redeploy.

Bootstrap: `worksuite-entrypoint spark enterprise:bootstrap --json -` in the web container, JSON on
stdin (sent base64; the command is redacted from the agent command once it finishes), result = the
last JSON line. Exit 0 or 3 → active; 5 → active with the licence step failed; anything else → failed.
A custom domain sets APP_URL to it and redeploys web.

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
