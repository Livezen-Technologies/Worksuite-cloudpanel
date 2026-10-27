# Docker Manager Portal — business API

Laravel 12 control-plane API for the admin portal and customer panel (`/api/v1`) and the agent
protocol (`/agent/v1`). The contract is `../docs/API.md`; requirements are in `../docs/SRS.txt`.

## Setup

```sh
composer install
cp .env.example .env && php artisan key:generate
# PostgreSQL (production) — or set DB_CONNECTION=sqlite for a quick local run
php artisan migrate --seed          # roles/permissions, plans, regions + demo organisation
php artisan agent:publish           # copy ../agent/packaging/install.sh (+ ../agent/dist binaries) to storage/app/agent
php artisan serve                   # http://localhost:8000
php artisan queue:work              # unless QUEUE_CONNECTION=sync
php artisan schedule:work           # or cron: * * * * * php artisan schedule:run
```

Demo login: `admin@example.com` / `password` (Super Admin). Also `owner@`, `dev@`, `viewer@`,
`billing@example.com` (same password) with their roles.

Tests: `php artisan test` (SQLite in memory; also passes on PostgreSQL with `DB_CONNECTION=pgsql …`).

## Environment

| Variable | Default | Purpose |
| --- | --- | --- |
| `DB_CONNECTION`, `DB_*` | `pgsql` | PostgreSQL is the source of truth; SQLite works for tests/dev |
| `QUEUE_CONNECTION` | `database` | Deployment pipelines run as queued jobs (`sync` runs them inline) |
| `PANEL_URL` | `APP_URL` | Public panel URL used in the agent install command |
| `PLATFORM_DOMAIN` | `livezen.app` | Free customer subdomains `<app>.<domain>` |
| `PLATFORM_INGRESS_IP` | `203.0.113.10` | A-record target when an app has no placed server yet |
| `TERMINAL_WS_URL` | `wss://localhost/ws/terminal` | Base for terminal session `ws_url` |
| `CORS_ALLOWED_ORIGINS` | `http://localhost:5173,http://127.0.0.1:5173` | Strict CORS for the web app |
| `AGENT_INSTALL_SCRIPT` | `../agent/packaging/install.sh` | Served at `/agent/v1/install.sh` if not published |
| `AGENT_LATEST_VERSION` | `1.0.0` | Version sent with `agent.update` (signature from `storage/app/agent/release.json`) |
| `DNS_DRIVER` | `native` | `native` (dns_get_record) or `fake` |
| `SOURCE_VALIDATOR` | `http` | `http` (registry v2 API, `git ls-remote`) or `fake` |
| `CERT_DRIVER`, `PAYMENT_DRIVER` | `fake` | Only fake drivers ship in 1.0 |
| `SCANNER_DRIVER` | `fake` | `fake` (deterministic, in-process) or `trivy` (agent `image.scan` on the builder/target; needs `trivy` there) |
| `BUILD_PUSH_REGISTRY`, `…_USERNAME`, `…_PASSWORD` | — | Platform registry for built images when an app has no `push_registry_id` (e.g. customer Git apps) |
| `BUILD_LOCAL_REPOSITORY` | `dmp-build` | Repository prefix of images that are built but not pushed |
| `HEALTH_CHECK_TIMEOUT` | `60` | Default post-start health check timeout (s); per app `health_timeout` |

## Architecture

- **Tenancy** — `App\Models\Concerns\BelongsToOrganization` adds a global scope on `organization_id`
  from `App\Support\Tenant`, set by the `tenant` middleware (token org / `users.current_organization_id`).
  Rows of another organisation are invisible (404).
- **RBAC** — `config/rbac.php` is the SRS 3.1/3.2 matrix, seeded into `roles`/`permissions`/`role_permissions`.
  Route middleware `perm:x|y` plus `App\Services\Authorizer` (token abilities, `assigned` constraint for
  developers, read-only roles) on every request. `App\Services\ApprovalService` parks destructive production
  actions and terminals for a second approver when `security.require_approval_destructive` is on.
- **Audit** — `audit` middleware writes one event per successful mutation; `AuditLogger` hash-chains rows
  (`hash = sha256(prev_hash + canonical_json(row))`), rows are append-only, `GET /audit-logs/verify` re-checks.
- **Builds / scans / health** — `BuildService` picks a server with the `builder` role (never the deploy target unless it is a
  builder and `security.allow_builds_on_deploy_targets` is on) and builds the `image.build` payload (Git token resolved from
  `source.git_secret_id`; `source.git_token` on create/update is stored as an encrypted `Secret`). `DeploymentService` drives
  build → scan (`ScanService`, IMG-003 gate) → push/placement → `container.create` → `container.healthcheck`, with rollback per
  strategy; servers with only the `builder` role are never chosen by the `Scheduler`.
- **Jobs** — `jobs` table (queue table renamed to `queue_jobs`) with stages/progress via `JobService`.
  Agent work is an `AgentCommand` (id = operation id) signed on delivery by `CommandBus`; results are idempotent
  and continue the owning job (`DeploymentService`, `BackupService`, `ComposeService`, `DatabaseService`).
  `deployments:resume` re-drives interrupted pipelines without sending commands twice.
- **Errors / idempotency / rate limits** — `bootstrap/app.php` renders `{error:{code,message,fields,request_id}}`;
  `Idempotency-Key` replays per user for 24 h; limiters `api` (token+IP), `auth`, `agent`.
- **Secrets** — Laravel encrypted casts for env vars, registry/integration credentials, channel config, backup
  destination config and recovery keys, agent HMAC keys and command payloads. Enrolment tokens, agent secrets,
  terminal tokens and invite tokens are stored as SHA-256 only.

## Agent protocol notes

- Install: `curl -fsSL <panel>/agent/v1/install.sh | sudo sh -s -- --url <panel> --token <token>`.
- Signature: `hex(HMAC-SHA256(hmac_key, id.type.canonical_json(payload).issued_at))`; test vector in
  `tests/Unit/CommandSigningTest.php` (`b013b8a8…09596`).
- Heartbeat inventory lists that are `null` (Docker unreachable, `docker_error` set) leave stored inventory untouched.
- Server status is computed on read: offline after 90 s without heartbeat; `servers:check-heartbeats` records transitions.

## Scheduled commands (`routes/console.php`)

`scheduled-jobs:run-due`, `backups:run-due`, `alerts:evaluate`, `servers:check-heartbeats` (every minute),
`domains:check-dns` (2 min), `deployments:resume` (5 min), `backups:prune` (hourly), `apps:purge`,
`metrics:prune`, `audit:prune` (daily).

## Known limits of this build

- Certificates, CVE scanning and payments use fake drivers; Let's Encrypt/Trivy/Stripe need real drivers.
- Git/Dockerfile sources record build stages, but image builds on isolated runners are not implemented;
  the deployed image is `registry.<PLATFORM_DOMAIN>/<org>/<app>:<commit>`.
- Health check stage succeeds when the agent reports the container started (no HTTP probe yet).
- Metrics live in the `metrics` table (no Prometheus); `requests_24h` is null.
- Terminal sessions issue a token/ws_url and audit open/close; the WebSocket relay itself is not part of this API.
- Backup download returns a signed URL to a placeholder; archives stay in the restic repository.
- mTLS: CSRs sent at enrolment are signed by a local CA in `storage/app/private/ca`; enforcement is at the proxy.
