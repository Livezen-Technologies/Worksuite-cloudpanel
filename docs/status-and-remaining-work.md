# WorkSuite CloudPanel — status and remaining work

## Status at 30 Oct 2026

```
Canvas / UI        100%   all 35 artboards done or verified aligned
Infrastructure     ~95%   7 sites live with TLS, limits, rate limiting,
                          monitoring, hardened SSH, backups in two places
Firewall           ~90%   enforced through CrowdSec (#14): rate limits,
                          countries, challenges, events; live sites still
                          on hand-written Traefik files
Antivirus          100%   all 14 (AV-1..14): ClamAV + YARA, real-time scan,
                          scan schedule, threats, encrypted quarantine,
                          WordPress core clean, false positives, brute-force
                          bans, Coraza WAF, file integrity, image CVEs,
                          outbound limits, security score, notifications;
                          AV-6 cleans WordPress core only (other files are
                          restored from a backup by hand)
Monitoring         ~85%   websites + containers + servers every 5 min,
                          cooldowns, escalation, auto-restart, uptime
                          reports, WhatsApp/SMS; no Grafana or status page
File manager       100%   all 19 features + customer page (#7-#13, #24,
                          #25): sharing links, transfer manager, syntax
                          highlighting, bulk extract, copy between volumes
DNS zones          100%   all 13 (DNS-001..013): Cloudflare and PowerDNS,
                          DNSSEC, zone settings, BIND import/export, zones
                          drive DNS-01 certificates (#16, #18, #20, #22)
WordPress toolkit  100%   all 12 (WP-001..012, #29, #30): inventory, settings,
                          tools, vulnerability scan, one-time login, updates
                          with restore points and automatic rollback, update
                          all, hardening, restore points, staging (clone,
                          refresh, push to live, password + noindex)
Migration wizard   ~80%   cPanel: fetch and inspect, pre-flight, mapping,
                          resumable copy, temporary hostnames, timeline, DNS
                          cutover with a health gate and rollback, delta
                          sync, bulk, customer requests with approval; left:
                          Plesk and Hestia sources, customer page in the web
Mail hosting       ~70%   by SRS: stack, domains, DKIM + rotation, mailboxes,
                          aliases, DNS + autoconfig, TLS from Traefik, limits,
                          relay, rDNS/blocklists with alerts, backups,
                          auto-replies, logs, customer page, package quotas;
                          now also (MCL): sending holds for mailboxes or
                          domains over their hourly limit or bouncing most
                          mail (postfix-send-access.cf, alert, release),
                          per-domain caps (mailboxes, aliases, total quota,
                          sends/hour), per-mailbox spam levels and
                          allow/block lists (Sieve on X-Rspamd-Score), and
                          a delivery log parsed from Postfix every 5 min
                          that each organisation searches for its domains;
                          one-click webmail sign-in (single-use link, a
                          Roundcube plugin redeems it for a master-user
                          login) and live import progress (N of M
                          messages from imapsync's ETA line);
                          left: a second mail server for failover
Public pages       ~90%   maintenance (platform/site, admin bypass), 404,
                          busy (502-504), suspended, expired, welcome pages
                          from an edge container; left: bot-check page
Customer pages     new      Email (mailboxes, forwarding, auto-replies, DNS
                          in plain words), Security (score, threats, scan
                          now), Scheduled jobs (app command or URL, run log,
                          plan limits), Move a site here (request + steps)
Email templates    ~97%   all 5 (EML-1..5): 20 templates in 7 groups, brand,
                          validated variables, HTML + text, 600/360 px and
                          dark mode, tests, user opt-outs; 17 of 20 wired to
                          their events (welcome, new sign-in, 2FA off, deploy
                          ok/failed incl. rollback, domain connected, cert
                          expiring, backup failed, restore done, server
                          unreachable, malware, invoice, payment failed, plan
                          limit, alert, invite, verify_email, password_reset).
                          Not wired: preview_ready and wordpress_updates
                          (no event yet)
WorkSuite Server     0%   blocked: no readable source for the image
Overall            ~70%   up from ~40%: file manager, firewall enforcement,
                          antivirus, DNS zones, WordPress, migrations
```

## Coverage against the SRS (re-audited after the SRS added sections 4.30–4.36)

The SRS now has 381 numbered requirements. Counting a partly built one as half, about
**71%** are met (270 of 381). Before the new sections it was about 79% of 326. The
percentages in the table above are rough feature-area estimates, so the two numbers differ.

New in the SRS, and what exists for each:

| Group | Reqs | Met | What exists / what is missing |
|---|---|---|---|
| SCL scaling and load balancing (4.30) | 10 | 100% | Copies, autoscaling, schedules, health checks, sticky sessions, shared-state warnings and plan limits (#51). Placement of copies over several servers through the private network with a Traefik pool service (SCL-6) and weighted canary releases with promote/abort (SCL-7). DNS failover between ingress servers (SCL-8, `EDGE_FAILOVER`): `edge:failover` every minute keeps each verified domain's A records on the online ingress servers, only for records already pointing at one, never removing the last, with an alert per server taken out. A least-busy strategy (Traefik `p2c`) beside round robin. Autoscaling on requests per second per copy from Traefik's `traefik_service_requests_total` (3-minute rate over the app's services, `METRICS_DRIVER=prometheus`) beside CPU, and extra copies billed by the hour at the app's size (`replica_hours.<size>` usage, an "Extra copies" invoice line; SCL-10). |
| PNET private network and observability (4.31) | 9 | 100% | **WireGuard full mesh** between an organisation's servers (Resources → Private network; PNET-1): each agent generates and keeps its own X25519 key, the panel pushes peers, addresses come from `MESH_SUBNET` (default 10.88.0.0/16) on UDP `MESH_PORT` 51820, and peers are swapped live with `wg syncconf`. **CoreDNS** (`dmp-coredns`, bound to the mesh address only) serves `<server>.internal` and `<app>.internal` (PNET-2). **Admin VPN configs** for laptops and phones, with the private key shown once and never stored, and revocation (PNET-3). Handshakes and traffic every 5 minutes (`mesh:status`), with each device's last-seen time (PNET-5). Still from before: Traefik edge with automatic HTTPS (PNET-8 partly), per-container metrics and logs (PNET-9 partly), outbound SMTP limits (PNET-6 partly). Each apply also opens the mesh UDP port in UFW or firewalld when one is active, and SSH can be limited to the private network per server (PNET-7: an nftables table `dockermgr_mesh` allows the mesh subnet and listed addresses, refused until an admin device is connected or another address is listed, and removed by the agent unless confirmed within 5 minutes). Container networks are routed across the mesh (PNET-4): each server gets a `dmp-mesh` Docker network with its own /24 of `MESH_CONTAINER_SUPERNET` (10.89.0.0/16), peers route it over WireGuard, the agent turns on IP forwarding and adds a DOCKER-USER accept rule from the mesh, and apps with "reachable on the private network" get a fixed address there that `<app>.internal` resolves to. The admin API can be limited to `ADMIN_ALLOWED_CIDRS` (for example the mesh subnet plus a break-glass address); the customer panel, own account, jobs, agents and webhooks stay open. IPv6 beside IPv4: each member and VPN device gets the ULA with the same host number in `MESH_SUBNET6` (default `fd88::/64`, empty turns it off), on the WireGuard interface (also added live with `ip -6 address replace`), in every peer's AllowedIPs and the device config, AAAA records in the internal DNS, and the mesh prefix in the SSH lock. |
| BLD build pipeline (4.32) | 9 | ~90% | Signed push webhooks (BLD-1), isolated builders, the stage timeline, a registry, the Trivy gate (BLD-7) and rollback on a failed health check (BLD-8) exist. New: an **auto** builder that detects Node.js, Python, Go, PHP or static sites and generates the Dockerfile (Nixpacks/Railpack style, BLD-2), per-build layer cache stats with a monthly cache hit rate and a no-cache switch (BLD-3), per-build timeouts and monthly build-minute limits from the package (`build_minutes`, `build_timeout_minutes`; BLD-9), shown at `GET /builds/usage` and `GET /app/builds/usage`. Builds pull the app's last two pushed images and pass them as `cachefrom`, so any builder reuses layers (BLD-3), and `registry:gc` (daily, and Images & Registry → Clean up with a preview) deletes pushed images older than the last `BUILD_REGISTRY_KEEP` (5) releases through the Registry v2 API, marking those releases so a rollback to them is refused with a clear message. Missing: a BullMQ queue (Laravel queues are the MVP alternative); BuildKit's own registry cache export. |
| CLN server cleanup (4.33) | 5 | ~90% | Prune with preview and a scheduled prune job, plus a per-server cleanup policy (Servers → server → Cleanup): **safe / standard / aggressive** levels (CLN-1), automatic runs when disk use crosses a threshold with a one-hour cooldown (CLN-2), the images of the last N releases of every app protected (CLN-3), a run history with freed space (CLN-4), and alerts when a cleanup fails or the disk stays above the threshold (CLN-5). Volumes are never touched. Missing: log rotation of container logs. |
| CFT Cloudflare Tunnel (4.34) | 6 | 100% | Servers → server → Tunnel: a remotely managed tunnel in the Cloudflare account (CFT-1), a `dmp-cloudflared` connector on the proxy network that only dials out (CFT-2), every domain of the server's apps as an ingress rule to Traefik (CFT-3), proxied CNAMEs to `<tunnel>.cfargotunnel.com` replacing existing A records (CFT-4), `tunnels:sync` every 5 minutes with health, connection count and an alert when it goes down (CFT-5), and removal of the DNS records, connector and tunnel (CFT-6). The token needs Cloudflare Tunnel: Edit. An opt-in lockdown closes 80/443 on the host (agent `tunnel.lockdown`, nft table `dockermgr_tunnel`) only while the tunnel is healthy and routes every domain, and reopens them when it goes down, a domain can't be routed, or the tunnel is removed. Adding or removing a domain syncs the tunnel at once. |
| ENV environments (4.35) | 7 | ~95% | **Projects → environments** (Deploy → Projects; ENV-1), duplication of an environment with its apps and variables (ENV-2), shared variables and `${{shared.KEY}}` / `${{api.URL}}` / `${{api.KEY}}` references resolved at release time with warnings for missing ones (ENV-3), Git push webhooks per project (GitHub/Gitea HMAC, GitLab token) that deploy the environments mapped to the pushed branch (ENV-4), promotion of live images to another environment without rebuilding (ENV-5), approval per environment (ENV-6), and sleep after an idle window with wake (ENV-7). Pull requests (GitHub/Gitea `pull_request`, GitLab merge requests) on the project webhook open `PR #<n>`, a copy of the preview source environment on the PR's branch with a platform address per app, redeploy it on new pushes and remove it when the PR closes or merges. A sleeping app's domains show a waking page whose image request (`/__wake`, routed by Traefik to the panel) starts the app; the page reloads every 10 s. Missing: commenting the preview URLs back on the pull request. |
| TPL one-click templates (4.36) | 6 | ~92% | **102 templates** as manifests in `api/templates` (90 single images, now including AI and vector databases, search, observability, productivity and media apps, and 12 Compose stacks: WordPress + MariaDB, Ghost + MySQL, Nextcloud + PostgreSQL + Redis, n8n, Gitea, Matomo, Odoo, Umami, Wiki.js, BookStack, Directus, Mattermost; TPL-1/2), generated `SERVICE_PASSWORD_*`, `SERVICE_USER_*`, `SERVICE_HEX_*`, `SERVICE_BASE64_*`, `SERVICE_FQDN` and `SERVICE_URL` values kept per install (TPL-3), `templates:sync` daily from the bundled manifests and an optional remote index `TEMPLATES_REMOTE_URL` (TPL-4), updates that re-render an install on a newer template version with the same secrets (TPL-5), and `templates:validate`, run in the test suite, which renders and checks every template (TPL-6). Customers host any catalogue template, single images and Compose stacks alike (entries marked `customer: false` stay admin-only); a stack is placed on a server like any customer app, fronted by an app record whose restart, redeploy and delete act on the stack, with search, template variables, generated secrets stored as secret env vars and an install record for updates. Missing: the SRS's 280+ templates (the remote index is how to add more). |
| USCL customer scaling (5.12) | 3 | 0% | Depends on SCL. |

The reference architecture (SRS 2.6) names Temporal, BullMQ, Loki, Railpack, WireGuard and
CoreDNS. The platform currently uses Laravel queues and a Laravel job runner, Prometheus metrics
through the agent, no Loki, and Dockerfile builds; the SRS lists Laravel queues as the MVP
alternative.

Largest open groups, by requirements left: mail cluster (MCL, ~2 after the holds, limits, spam settings and log PR), scaling (SCL 6-8 + SCL-10 billing: placement, traffic splitting, edge nodes),
private network (PNET, 9), firewall (FW, done), environments (ENV, 7), templates (TPL, 6),
Cloudflare Tunnel (CFT, 6), cron engine (CRON, 1: dedicated runners), backup manager (BKM, 1: BKM-009 sandbox start), public pages
(PUB, done in #41).

**Next action: restore the four sites that are still down.** `shopexpress.lk`
was taking ~9,900 hits a day. Verified backups are on `51.89.192.43`. See the
warnings at the end of this file first — this work has gone wrong twice.

Panel: `https://panel.livezentech.com`, from `/opt/worksuite-cloudpanel` on
`51.89.7.73`, branch `main`.

---

## 1. Canvas rebuild — COMPLETE

Canvas: `claude.ai/artifact/FFTAAc3FPxWejM9jviUgpM` — "Docker Manager Portal",
35 artboards.

### The finding that shaped this work

**The canvas documents this panel rather than redesigning it.** All nineteen
admin content artboards already matched their pages, and the customer panel
matched down to byte-identical blurb text ("Node 22 on Alpine for JS and
TypeScript apps." is the same string in `NewSite.tsx` and the artboard). The
real differences were concentrated in the shell, which is why TopBar, Sidebar
and the header produced substantial changes and the content screens produced
none.

Nine of the ten `/app` artboards are 951-byte stubs importing one `UserPanel`
component, so the customer panel is one source file on the canvas, not ten
screens. Its sidebar is a flat six-item list identical to `PANEL_NAV` — there
is **no rail**, and the "two-level navigation for /app" task that appeared in
an earlier version of this plan was an extrapolation, not a requirement.

**Method, if a canvas is ever used again: check each artboard against the page
before assuming work exists.** Two batched label-extraction passes settled
fourteen artboards.

### What was actually built

| Artboard | Outcome |
| --- | --- |
| `TopBar` | `Shell.tsx` header — `71ca388` |
| `Sidebar` | `Shell.tsx` rail + context nav — `79620c2` |
| `Main` | `1de44e2` severity breakdown; `5333acb` fleet health + Needs attention |
| `Firewall` | built from nothing — `dc3185f` `3765744` `b506435` `4aa4af3` |
| 19 admin content screens | already aligned — `2fbefd8` `4d1ecb1` |
| 10 customer screens | already aligned — `87665f9` |

Two artboard labels were **not** adopted, because ours read better for a
hosting customer: "Container port" → "Port your app listens on",
"Persistent storage at" → "Folder inside your app".

Four `Main` tiles were deliberately **not** built — Requests/min, Uptime 30 d,
Image compliance, Quota usage. Nothing collects that data, so they would have
been invented numbers. Build them only alongside the collection.

---

## 1b. Cron engine (CRON-1..9)

Built: run modes (in the app container, a one-off container from the app's image with
its env and volumes, the server's shell for platform admins, HTTP with method, headers
and expected status); timezone with a plain-words preview and the next three runs;
overlap policy (skip / parallel / stop the old run via `cron.stop`); timeout; retries
with doubling backoff; run history with attempt, 64 KB output, search and download;
Run now, Test run (no alerts), Pause, Resume, Duplicate, Delete, all audited; alerts on
failure, missed runs (server offline, or the scheduler down at the scheduled time) and
long-running runs by email and webhook, and a Monitoring alert after three failures in
a row that a success resolves; plan limits for jobs, interval, runtime and modes;
secrets redacted from output; jobs deleted with their app; Operations → Cron &
scheduler lists every customer's job for platform admins. The agent runs `cron.exec`
beside its command queue so a long job never blocks other commands.

Left: the dedicated runner services from the SRS (an `lz-scheduler` supercronic
container or systemd timers per server, and a Swarm leader runner). The panel's own
scheduler dispatches each run to the agent, so a run happens once per minute
already, but scheduling stops while the panel is down (those runs are reported as
missed afterwards).

## 1c. Backup manager (BKM-001..009)

Built: Operations → Backups is the Backup manager with Policies, Snapshots, Restore,
Destinations and Restore tests tabs. Summary tiles (protected targets, 30-day success
rate, stored size, last restore test, next run) and the 14-day run chart; daily /
weekly / monthly retention (`restic forget --keep-daily/weekly/monthly`); pause and
resume per policy, Run now, Run all due, Rerun failed; snapshots filtered by target;
a restore wizard (everything, files or database; replace or side by side in
`/var/lib/dockermgr/restores/<id>`; or download; safety backup first; stop the app
around the restore and start it again even when the restore fails; health check
after); SFTP destinations through rclone with key authentication (write-only key);
a connection test (`backup.probe`: snapshots and stored bytes) with usage against a
stated capacity and a warning above 85 %; object lock for S3/R2/B2 (forget without
prune, lock no longer than retention).

Left: BKM-009 restore tests restore and validate in a scratch directory; starting the
app from the restored data in a sandbox container and counting database rows is not
built yet.

## 1d. Mail: IMAP import and webmail

Built: **IMAP import** (MCL-10, UMAIL) from Google Workspace / Gmail, Microsoft 365,
cPanel or any IMAP server, per mailbox, from the admin Mail page and the customer Email
page. The agent's `mail.import` runs imapsync in a one-off container on the mail
container's network and signs in to Dovecot as a master user (`dovecot-masters.cf`,
written with every sync), so the mailbox's own password is never needed. Passwords go
to imapsync in files, never on the command line; the source password is forgotten when
the import ends. Results: messages, folders, bytes, errors and the last 16 KB of log.
**Webmail**: Roundcube (`roundcube/roundcubemail`) beside the mail container on its own
hostname, routed and certified by Traefik; customers get "Open webmail" and per-mailbox
links with the address filled in, and the mail-app settings.

**One-click webmail**: "Webmail" on a mailbox asks the panel for a 60-second, single-use
link (`POST …/mailboxes/{box}/webmail`). The `dmp_sso` Roundcube plugin, written into its
own volume at install, redeems the token server to server at `/api/v1/webmail-sso/{token}`
with a per-server bearer and signs in as `box*dmpimport` (the Dovecot master user), so no
password is typed or stored in the browser. **Live progress**: while imapsync runs, the
agent reads its log every 10 s and reports "N of M messages copied" with a percentage,
shown on the import list.

Left: importing from the migration wizard (MIG-001).

## 1e. Scaling and load balancing (SCL, USCL)

Built: copies of an app on its server (SCL-1, USCL-1). The primary container is
`app-<name>`; copies are `app-<name>-r2…rN` from the current release with the same
Traefik service labels, so Traefik balances across them; health checks (path, interval,
timeout) take a failing copy out of rotation and sticky sessions pin visitors by cookie
(SCL-3/4). Scaling down stops a copy with a 30 s grace before removing it, and a deploy
replaces copies one at a time (SCL-5). Modes: fixed, autoscale on CPU (3 min above the
target adds a copy, 15 min under half removes one, the maximum is recorded as a warning)
and scheduled windows (SCL-2), evaluated every minute by `scaling:evaluate`; every change
is logged with reason and result. Shared-state warnings (SCL-9) and plan limits
`max_replicas` / `autoscale` for customers (SCL-10, USCL-2/3). Screens: Scaling tab on
the admin app page and the customer app page.

Placement (SCL-6): `servers` in the scaling settings lists more servers for copies; copies
are dealt round-robin over the app's server and those that are on the private network and
online. A copy elsewhere publishes its port on that server's mesh address only (port
`30000 + …`), and the file provider gives the app's routers an `app-<name>-pool` service
over every copy (health-checked, sticky when asked). Canaries (SCL-7): `POST
/applications/{id}/canary {image, weight}` runs `app-<name>-canary` from the live release's
settings with another image; `app-<name>-split` weighs it against the live app; `PATCH`
changes the share, `…/promote` deploys the image as the next release, `DELETE` aborts. Both
need the Traefik file provider (`CERT_DRIVER=traefik`) and are admin-only.

Left: edge nodes and DNS failover between ingress servers (SCL-8), least-connections,
requests-per-second autoscaling, and billing extra copies by usage.

## 2. Firewall — enforced through CrowdSec (#14)

> Update (FW-010..014): **under attack mode** (one switch, all domains or one) renders a
> CrowdSec trigger scenario that captchas every visitor; **IP allow and block lists**
> (`firewall_ip_lists`: CIDR, scope, note, expiry) render as a ban scenario on
> `IpInRange` and a `dmp-allowlist.yaml` whitelist parser, which the agent writes to
> `parsers/s02-enrich/` with the scenarios and rolls back with them; expired entries are
> skipped and `firewall:expire-ips` (every minute) removes them. The Events tab exports
> CSV (formula-safe) and has Allow IP / Block IP on each row. Blocking a range that
> holds your own address is refused with 409 until confirmed. FW-015: customers manage
> protected pages and country rules for their own domains at /app/firewall, within the
> plan's `firewall_paths` / `firewall_countries` limits. Left: a ban already issued for a removed block entry lasts until it times out.

> Update: rate limits, country blocks and challenges now reach traffic through
> CrowdSec and its Traefik bouncer, and `firewall_events` is filled from
> CrowdSec decisions. The table below is the state before #14.

Schema (`firewall_rules`, `firewall_countries`, `firewall_protected_paths`,
`firewall_events`), settings, renderer, API and page are all in place, at
`/admin/firewall`.

**What reaches traffic, and what does not:**

| | status |
| --- | --- |
| Rate limits | **enforced** — Traefik `rateLimit`, `sourceCriterion` from `counted_by` |
| Block vs throttle | both answer 429; a distinct hard block needs a challenge middleware |
| Challenge / reCAPTCHA | **renders nothing** — needs CrowdSec or Cloudflare Turnstile |
| Country block | **renders nothing** — needs a GeoIP plugin (traefik-geoip2) or CrowdSec |

The page says so explicitly: unenforceable rules are badged "not enforced" and
listed by name, each affected tab carries a note naming what is missing, and
`unsupported()` drives it from the API. **Keep that honesty if you extend it.**

Design note worth remembering: **a Traefik middleware cannot match a path**, so
a path-scoped rule gets its own router at a higher priority pointing at the
same service. Site-wide rules attach to the domain router.

- [ ] Decide the enforcement route: GeoIP plugin, CrowdSec, or Cloudflare at
      the edge. Everything above is blocked on that one choice.
- [ ] Nothing writes `firewall_events` yet — it needs a log shipper or a
      Traefik access-log reader on the ingress.
- [ ] The challenge keys must be entered by the operator in Settings. Do not
      handle them.

---

## 3. Operations — the real remaining value

- [ ] **Restore `shopexpress.lk` · `diygeek.cc` · `softkeymart.com` ·
      `livezenbiz.com`.** Check each for a live copy first (see warnings).
- [ ] Retire old copies on `51.89.192.43` once restored sites are verified
- [ ] **Bring the 7 live sites under panel management.** They are served by
      hand-written Traefik files (`giantforests.yml`, `security.yml`, …), so
      `dmp-domains.yml` renders empty and the firewall renderer is proven by
      tests, not live traffic. Until this is done the Firewall page cannot
      affect them.
- [ ] CloudPanel: `8433:8443/tcp` is open to the world on a self-signed cert
- [ ] A brute-force source was hammering `dazzlingtours.com/wp-login.php`
      (34 requests, not the outage cause). Blocking it is what the firewall is
      for, once enforcement exists.
- [ ] `dazzlingtours` memory is at 2 GB on a wrong diagnosis; the other six are
      at 1 GB. Consider putting it back.

## 4. Monitoring to 100%

- [x] Auto-restart policies for failed containers
- [x] Uptime reports and downtime history
- [x] Per-customer scoped incident views
- [x] Alert dedup, cooldowns, maintenance windows, escalation
- [x] WhatsApp and SMS channels (`6d361b1`)
- [ ] Telegram channel
- [ ] Grafana dashboard and a public status page

## 5. WorkSuite Server v3.2

- [ ] Blocked: no readable source for the image. Needs access before anything.

## 6. Housekeeping

- [ ] **`config:cache` breaks the test suite** — it makes `DNS_DRIVER=fake`
      from `phpunit.xml` inert, and the suite then reports ~34 failures that
      are not real. This cost time twice in one day. Run
      `php artisan config:clear` before testing and `config:cache` after, or
      better, give the suite its own `.env.testing`.
- [ ] 5 of 104 tests fail on this box from environment mismatches
      (`CERT_DRIVER`, relay URL, export driver). Pre-existing; verify any new
      failure against a reverted tree before chasing it.
- [ ] Wire sidebar and rail badges to live counts (the component supports them)
- [ ] Responsive audit at 360 / 768 / 1440
- [ ] Notification centre: dropdown, panel, history
- [x] `DataTable` bulk actions — already supported via `selectable` and
      `bulkActions`; no work needed

---

## Warnings — both of these have already gone wrong

> **Before any restore: check where the site currently lives and whether it is
> still being written to.** `seliknaent` was restored from a two-day-old backup
> while still trading on another server, losing 18 sales, 2 purchases and 13
> payments. Recovered by re-dumping the live database, but only because the
> customer noticed.

> **`docker compose` does not load `.env.secrets`, only `.env`.** Running
> `docker compose up -d` in `/opt/migrate/*` without `--env-file .env.secrets`
> recreates containers with **blank database credentials** and takes the site
> down. This happened to `dazzlingtours` on 30 Oct. A `.env` symlink now exists
> in all seven directories so a plain `up -d` resolves cleanly — but check
> `docker compose config` for "variable is not set" warnings before trusting
> any compose command there.

> **Read the diagnosis before acting on it.** The `dazzlingtours` outage looked
> like the 1 GB memory cap (memory pinned at the limit, Apache
> `MaxRequestWorkers`). It was actually a database connection failure; memory
> was a symptom. Raising the limit changed nothing and the real cause sat
> unexamined for two more steps.

## Verification

```bash
cd /opt/worksuite-cloudpanel/api && php artisan config:clear && vendor/bin/phpunit
cd ../web && npm run build                      # must be clean
```

Full deploy:

```bash
cd /opt/worksuite-cloudpanel/api
sudo php artisan migrate --force && sudo php artisan config:cache && sudo php artisan route:cache
cd ../web && sudo npm run build
sudo systemctl restart wscp-queue wscp-schedule php8.4-fpm && sudo systemctl reload nginx
```

Then confirm the panel and all seven sites return 200 and no container is
unhealthy:

```bash
for s in panel.livezentech.com giantforests.com seliknaent.livezencloud.com \
         magiccorn.lk dazzlingtours.com ontrocleaning.com.au arduino.lk \
         thingerbits.com; do
  printf '%-30s %s\n' "$s" "$(curl -s -o /dev/null -w '%{http_code}' -L https://$s/)"
done
sudo docker ps --filter health=unhealthy -q | wc -l
```
