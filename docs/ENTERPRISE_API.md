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
| POST | `deployments/{ref}/actions/{restart\|stop\|start\|redeploy}` | redeploy: `{version?}` | 202 `{action, job_ids, status}` |
| DELETE | `deployments/{ref}` | | deployment, status `deleted` (stopped, all data kept) |
| POST | `deployments/{ref}/purge` | `{confirm: "<slug>"}` | deployment, status `purged` (containers, volumes, databases, domains, project removed) |

Statuses: `queued → provisioning → awaiting_setup → configuring → active`, plus `failed`, `stopped`,
`deleted`, `purged`. A failed bootstrap can be configured again.

Deployment: `{id, external_id, customer_name, slug, version, image, size, status, status_reason,
project_id, node{id,name,region}, urls{app,platform,custom}, domain{name,dns_status,https_status,records},
custom_domain{…}|null, steps[{name,label,status,started_at,finished_at,message}], errors[{step,message,at}],
containers[{role,name,state,health,cpu_pct,mem_mb,started_at}], resources{cpu_pct,ram_mb,ram_limit_mb,cpu_limit,disk_gb,node_disk_pct},
app_status (last enterprise:status JSON), bootstrap (bootstrap result JSON, secrets dropped), created_at, updated_at, activated_at, deleted_at, purged_at}`.

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
