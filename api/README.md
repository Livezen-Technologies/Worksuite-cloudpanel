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
| `TERMINAL_WS_URL` | `/relay/terminal` | Base for terminal session `ws_url` (relative = panel origin) |
| `RELAY_SECRET` | — | Shared secret of `dockermgr-relay` for `/internal/relay/*` (≥ 16 chars; unset = terminals disabled, 503) |
| `RELAY_AGENT_URL` | `PANEL_URL/relay` | Relay base agents dial (signed into `terminal.open`, advertised in heartbeats) |
| `TERMINAL_SHELLS`, `TERMINAL_SHELL_USER`, `TERMINAL_RECORD` | `/bin/sh`, `app`, `true` | Fixed shell list (first used), non-root exec user, asciicast recording |
| `TERMINAL_RECORDING_DISK`, `TERMINAL_RECORDING_MAX_BYTES` | `local`, 8 MiB | Where recordings are stored (`terminal-recordings/Y/m/d/<uuid>.cast`) |
| `AGENT_MTLS` | `header` | `off` \| `header` (verify a forwarded client cert when present) \| `required` (SEC-003) |
| `AGENT_MTLS_VERIFY_HEADER`, `AGENT_MTLS_FINGERPRINT_HEADER`, `AGENT_MTLS_CERT_HEADER` | `X-SSL-Client-Verify`, `X-Client-Cert-Fingerprint`, `X-Client-Cert` | Headers set by the TLS proxy (Traefik: `X-Forwarded-Tls-Client-Cert`); see `docs/deploy/` |
| `AGENT_CERT_DAYS` | `90` | Agent client certificate lifetime (agents renew at 2/3) |
| `WEBAUTHN_RP_ID`, `WEBAUTHN_RP_NAME`, `WEBAUTHN_ORIGINS` | APP_URL host, APP_NAME, `CORS_ALLOWED_ORIGINS` | Passkeys relying party and allowed web origins |
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
- Terminal sessions go through `dockermgr-relay` (`../agent/cmd/relay`): `/internal/relay/*` (X-Relay-Secret) validates tokens, signs
  `terminal.open`, receives close reasons and asciicast recordings (`App\Services\TerminalService`). Live logs still poll (`container.logs`).
- Backup download returns a signed URL to a placeholder; archives stay in the restic repository.
- mTLS: CSRs (enrolment, `/agent/v1/certificate/renew`) are signed by a local CA in `storage/app/private/ca`
  (`App\Services\AgentCertificateService`); TLS is terminated by the proxy and the API checks the forwarded certificate per `AGENT_MTLS`.
  No CRL/OCSP: revocation is enforced by the API on each request. The CA key lives on the API host.
- Passkeys (`App\Services\WebAuthnService`, web-auth/webauthn-lib) accept `none` attestation only (no metadata service / attestation trust).
