# End-to-end tests

Full-stack tests for the Docker Manager Portal: the Laravel API on PostgreSQL with a queue worker,
the React app (Vite dev server, `/api` proxied to `:8000`), and the real Go agent driving the local
Docker daemon. Nothing is mocked except the drivers the API ships as fakes (DNS, certificates,
source validation, payments, image scanning).

```sh
e2e/run.sh                  # fresh DB, start the stack, run every suite, stop what it started
UNIT=1 e2e/run.sh           # ...then also api/agent/web unit suites (lint, tests, build)
REUSE_STACK=1 e2e/run.sh    # use an API on :8000 and web on :5173 you already started (no migrate)
ONLY='Compose|quota' node e2e/browser/admin.js   # run matching browser tests only
```

Logs, per-suite output and screenshots go to `$E2E_SCRATCH` (default `$TMPDIR/dmp-e2e`):
`agent/` (agent log, MITM log, results), `browser/*.png`, `<suite>.out`, `api.log`, `queue.log`, `web.log`.

## Requirements

PHP 8.3 + composer deps in `api/vendor`, PostgreSQL with database/user `dmp`/`dmp` (override with
`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`), Node 22 with `web/node_modules`, the `playwright` Node
library with Chromium (the plain library; `@playwright/test` is not used), Go 1.24, Docker with Compose v2,
`jq`, `python3`, `psql`. No registry access is needed: the test image `e2e/hello:1` is built locally
`FROM scratch` from `hello/main.go`, and the run creates the `proxy` Docker network apps join.

`env.sh` holds the environment overrides (PostgreSQL, `QUEUE_CONNECTION=database`, fake DNS/source
drivers). Real environment variables beat `api/.env`, so the checked-in `.env` is left alone.

**Warning:** the run executes `migrate:fresh` on the configured database and creates and removes
containers named `e2e-*` / `app-e2e-*` on the local Docker host.

## Suites

| File | What it covers |
| --- | --- |
| `agent.sh` | Builds the agent, creates a server + enrolment as `admin@example.com`, enrols (`--allow-http`) with a temp config/state dir, then: heartbeat marks the server online with real facts and stats; container create (local image, secure defaults checked on Docker), stop, start, restart, remove with volumes kept; compose up/down from a local-image compose file; agent killed and restarted plus `agent.restart` via API without the container restarting (`State.StartedAt` unchanged); a replayed command id is not executed twice; a tampered payload (signature unchanged) is rejected and reported failed. `AGENT_KEEP=1` leaves the agent and an `e2e-web` container running for the browser suites. |
| `mitm.py` | Pass-through proxy between agent and API used by `agent.sh` to tamper with / replay delivered commands. |
| `browser/admin.js` | Login form, Overview KPIs equal `/dashboard` numbers, Servers + add-server wizard to the install command (then enrols a second real agent with that token and sees the heartbeat), Containers shows the agent's container and Restart restarts it on Docker, Applications create (image) / deploy twice / roll back through the agent, Compose YAML load + Validate (valid/invalid) + Diff, and Domains, Backups, Monitoring, Users, Settings (incl. save), Images, Networks, Volumes, Databases, Logs, Automation with real data. |
| `browser/customer.js` | As `owner@example.com`: My apps, Host an app in 4 steps until live (real deploy), quota 402 upgrade prompt, app detail tabs + Save & restart, Domains add + Check DNS now, Backups restore confirm (202 job), Billing (cents/currency, card, invoices, plans), Account. |
| `build.sh` | Builds a tiny Go web app (`gobuild/`, static binary compiled in the test, `FROM scratch`) from a local bare Git repo (`file://`, agent run with `DOCKERMGR_ALLOW_LOCAL_GIT=1`) through the real agent (`image.build`: git clone + Engine `/build`), deploys it and checks the pipeline stages, the streamed build log, the HTTP health check on the container IP and the release image; a `broken` branch answers 500 on `/healthz`, fails the health check with the last 50 log lines and is rolled back to v1; a branch without the binary fails in `build_scan` with the Docker error in the build log while v1 keeps serving. The single local server is given the builder role and `allow_builds_on_deploy_targets` for the run. |
| `api-checks.js` | Registers org B and proves it cannot read or change org A's servers, containers, apps, domains, volumes, backups (404s, no ids in lists); audit chain verifies, a row tampered in PostgreSQL is detected (`broken_at`), restored afterwards. |
| `browser/lib.js` | Tiny runner: every test fails on page errors, console errors and unexpected 4xx/5xx API responses (`w.allow(status, /url/)` marks intended ones); full-page screenshot per step. |
| `browser/crawl.js`, `browser/dashes.js` | Dev helpers: visit every route and report errors / cells rendered as "—". |

## Not covered here

Pushing built images to a registry (unit-tested against a fake Engine), Trivy scans (needs `trivy` on the host; unit-tested with a fake runner), real HTTPS / Let's Encrypt, real DNS propagation, payments (Stripe), restic backups to remote
storage, the terminal WebSocket relay, mTLS enforcement at the proxy and multi-host outage simulation
need a staging fleet or credentials (see `docs/PHASES.md`).
