# WorkSuite CloudPanel

A hosting control panel for Docker servers. Admins manage servers, apps, domains and
security from one portal. Customers host their own apps and sites from a self-service
panel. Each managed server runs a small agent that connects **outbound only** to the panel
and carries out typed, signed, policy-checked commands. There is no remote shell.

| Part | Stack | What it is |
|---|---|---|
| [`api/`](api/README.md) | Laravel 12, PHP 8.2+, PostgreSQL | Control-plane API (`/api/v1`) and agent protocol (`/agent/v1`) |
| [`web/`](web/README.md) | React 19, TypeScript, Vite, Tailwind CSS 4 | Admin portal (`/admin`) and customer panel (`/app`) |
| [`agent/`](agent/README.md) | Go 1.24, standard library only | Agent and terminal relay: static binaries for linux/amd64 and arm64 |
| [`e2e/`](e2e/README.md) | Node, Playwright | Full-stack tests: API, web and the real agent on a local Docker daemon |

## Features

- **Servers and apps**: enrol servers with a one-line install. Deploy from git, an image, a
  Dockerfile, Compose or a template. Recreate, blue-green and rolling deployments, with
  health checks and rollback. Releases, environment variables and secrets, databases,
  backups to two places, scheduled jobs, and a web terminal with recording.
- **Domains and TLS**: Traefik routing and automatic certificates (HTTP-01, or DNS-01
  through the panel's own DNS zones).
- **DNS zones**: Cloudflare and PowerDNS, DNSSEC, zone settings, and BIND import and export.
- **File manager**: browse, edit (with syntax highlighting), upload, compress and extract,
  Trash and version history, sharing links, a transfer manager, and copying between volumes.
- **Firewall**: rate limits, country blocks, challenges and reCAPTCHA, enforced through
  CrowdSec.
- **Antivirus and security**: ClamAV and YARA with real-time scanning, encrypted
  quarantine, file integrity checks, image CVE scans, the Coraza WAF (OWASP CRS), outbound
  limits, a security score and notifications.
- **WordPress toolkit**:
  - finds every WordPress site and lists its plugins, themes and known vulnerabilities
  - settings and tools, and a one-time admin login link
  - updates with a restore point and automatic rollback; "update all sites"
  - hardening, restore points, and staging sites (clone, refresh, push to live)
- **Monitoring**: websites, containers and servers every 5 minutes, alerts with
  escalation, auto-restart, uptime reports, and WhatsApp and SMS notifications.
- **Customers and billing**: organisations, roles and permissions, plans and quotas,
  invoices, approvals and an audit log.

## Quick start

```bash
cd api && composer install && cp .env.example .env && php artisan key:generate
```

```bash
cd api && php artisan migrate --seed && php artisan serve
```

```bash
cd web && npm install && npm run dev
```

Open http://localhost:5173 and sign in as `admin@example.com` / `password`. For a quick
local run without PostgreSQL, set `DB_CONNECTION=sqlite` in `api/.env`. The queue worker,
the scheduler, the agent install and every environment variable are described in
[`api/README.md`](api/README.md).

## Tests

```bash
cd api && vendor/bin/phpunit
```

```bash
cd agent && gofmt -l . && go vet ./... && go test ./...
```

```bash
cd web && npm run build && npx vitest run
```

```bash
e2e/run.sh
```

CI (`.github/workflows/ci.yml`) runs the agent, API and web jobs on every pull request.

## Status

About **56%** of the specification is built. Details are in
[`docs/status-and-remaining-work.md`](docs/status-and-remaining-work.md).

| Area | Done |
|---|---|
| Canvas / UI, antivirus, file manager, DNS zones, WordPress toolkit | 100% |
| Infrastructure | ~95% |
| Firewall | ~90% |
| Monitoring | ~85% |
| Migration wizard, mail hosting, email templates | not started |

## Documentation

- [`docs/API.md`](docs/API.md): the API and agent protocol contract
- [`docs/SRS.txt`](docs/SRS.txt): requirements
- [`docs/status-and-remaining-work.md`](docs/status-and-remaining-work.md): what is done and what is left
- [`docs/deploy/`](docs/deploy): Traefik, CrowdSec, ClamAV, mTLS and observability setup
- [`docs/design-system.md`](docs/design-system.md): UI design system
- [`CHANGELOG.md`](CHANGELOG.md)
