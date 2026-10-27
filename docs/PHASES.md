# Release phases

The SRS (`docs/SRS.txt`) leaves section 9 (release phases) open, so this file defines them.
Each phase lists the requirement IDs it delivers. Screens are in `docs/design/*.dc.html`.

| Phase | Theme | Requirement IDs | Screens |
| --- | --- | --- | --- |
| 1 | Foundation | SEC-001, SEC-004, SEC-006, SEC-009, LOG-003, job model, error format, idempotency, all core tables (SRS 7.1) | Shells, Sidebar |
| 2 | Servers, agent, containers | SRV-001..009, CTR-001..010, SEC-003, SEC-008, agent protocol (6.4), DASH-001..008 | Main, Servers, Containers |
| 3 | Deploy & resources | APP-001..007, CMP-001..005, IMG-001..005, NET-001..004, STO-001..004, DOM-001..006, DB-001..003 | Applications, Compose, Images, Networks, Volumes, Domains, Databases |
| 4 | Operations | BAK-001..006, MON-001..005, LOG-001..002, AUT-001..003, USR-001..002, SET-001..004 | Backups, Monitoring, Logs, Automation, Users, Settings |
| 5 | Customer panel | UAPP, UHOST, UDET, UDOM, UBAK, UBIL, UACC (all) | UserPanel and User* screens |

## Components

- `api/` — Laravel 12 business API (`/api/v1`), PostgreSQL (SQLite for tests), Redis queues.
- `agent/` — Go agent, one static binary; talks to Docker Engine over the local socket.
- `web/` — React + TypeScript + Tailwind CSS v4; admin portal at `/admin`, customer panel at `/app`.

The shared contract between them is `docs/API.md`.

## Known limits of this build

- Provider APIs (Hetzner etc.), Stripe, Let's Encrypt, Restic and alert channels are behind driver
  interfaces with a working `log`/`fake` driver; real drivers need credentials.
- Agent transport is HTTPS long-poll with a per-agent bearer secret and HMAC-signed commands;
  mTLS client certificates are issued at enrolment but enforcing them is a reverse-proxy setting.
