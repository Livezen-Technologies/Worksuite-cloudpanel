# Release phases

Mirrors SRS section 9.2 (the SRS doc is the source of truth: https://claude.ai/artifact/C7KZktb3vijZGErBrFnUT3,
version 1.1). Screens are in `docs/design/*.dc.html`.

| Phase | Weeks | Scope | Requirement IDs |
| --- | --- | --- | --- |
| 1 Foundation | 1–4 | Auth, organisations, RBAC, React shells, Laravel API, agent enrolment and mTLS, server inventory | SEC-001..010, SRV-001..009, DASH-001..008 |
| 2 Docker core | 5–8 | Containers, logs, images, registries, networks, volumes, Compose, terminal, audit | CTR, LOG, IMG, NET, STO, CMP |
| 3 Deployment + customer panel | 9–12 | Git builds, pipeline, Traefik, domains and SSL, rollback, templates, host-an-app wizard, customer domains; Dokploy parity for Git providers, build types, previews | APP, DOM, DB, UAPP, UHOST, UDET, UDOM, DPL-001..009, DPL-014 |
| 4 Operations | 13–16 | Backups and restore, Prometheus/Loki, alerts, agent upgrades, hardening; volume backups, more databases and notification providers, scheduled-job timezones | BAK, MON, AUT, UBAK, DPL-010..013 |
| 5 Multi-server + commercial | 17–22 | Scheduler and quotas, storage nodes, provider integrations, customer billing, multi-tenant tests; CLI, template library, private networking; migration wizard; mail hosting | USR, SET, UBIL, UACC, UHOST-003/004, DPL-015, DPL-016, DPL-018, MIG-001..014, MAIL-001..014 |
| 6 Later | — | Kubernetes backend, HA control plane, policy engine, autoscaling, Docker Swarm clusters | DPL-017; the rest out of scope for 1.0 |

The SRS does not yet give a phase to Firewall (FW), File manager (FM), DNS zones (DNS), WordPress toolkit (WP),
Backup manager (BKM) or Hosting (HOST, apart from UHOST-003/004). The 22-week estimate assumes a team of about 9;
security, recovery and multi-tenant isolation may need more time.

Current build status per area is in SRS section 10 and `docs/status-and-remaining-work.md`.

## Acceptance (SRS 9.1)

- [ ] Enrol a new VPS without exposing the Docker TCP socket
- [ ] Discover and manage containers with correct authorisation
- [ ] Deploy and update a Compose application
- [ ] A customer hosts an app from an image and reaches it on the free subdomain over HTTPS
- [ ] A customer connects their own domain; DNS verifies and the certificate is issued without a redeploy
- [ ] Stream logs and metrics with access controls
- [ ] Restart the agent without interrupting running applications
- [ ] Recover from an interrupted deployment job
- [ ] Back up and restore a real application with its database
- [ ] One organisation cannot read or change another organisation's resources
- [ ] Control plane and agents recover after a simulated outage
- [ ] Sensitive operations appear in the tamper-resistant audit log
- [ ] Image, build and agent security controls verified

Items needing a staging fleet (real HTTPS, real DNS, outage simulation) are verified there; the repo carries
automated tests for the logic behind each.

## Components

- `api/` — Laravel 12 business API (`/api/v1`), PostgreSQL (SQLite for tests), Redis queues.
- `agent/` — Go agent, one static binary; talks to Docker Engine over the local socket.
- `web/` — React + TypeScript + Tailwind CSS v4; admin portal at `/admin`, customer panel at `/app`.

The contract between them is `docs/API.md`. Default platform domain: `livezen.app` (SRS 9.4 open question).

## Known limits of this build

- Provider APIs, payment provider, Let's Encrypt, Restic and alert channels sit behind driver interfaces
  with working `log`/`fake` drivers; live drivers need credentials.
- Agent transport is HTTPS long-poll with a per-agent bearer secret and HMAC-signed commands; client
  certificates are issued at enrolment, enforcement is a reverse-proxy setting.
- Git/Dockerfile builds run on servers with the `builder` role through the agent (`image.build`, Docker classic
  builder); CVE scans use Trivy on the agent when `SCANNER_DRIVER=trivy` (the `fake` driver remains the default).
