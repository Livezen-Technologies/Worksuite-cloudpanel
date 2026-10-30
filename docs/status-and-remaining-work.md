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
Migration wizard   ~40%   cPanel backup fetch and inspect, pre-flight, site
                          mapping, resumable copy of files and databases,
                          temporary hostnames, timeline; left: DNS cutover
                          and rollback, delta sync, live-source guard, Plesk
                          and Hestia, bulk migrations, customer requests
Other new features   0%   mail hosting, email templates (specified only)
WorkSuite Server     0%   blocked: no readable source for the image
Overall            ~58%   up from ~40%: file manager, firewall enforcement,
                          antivirus, DNS zones, WordPress, migrations
```

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

## 2. Firewall — enforced through CrowdSec (#14)

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
