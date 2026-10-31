# Changelog

All notable changes to WorkSuite CloudPanel.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Dates are the dates the work landed on `main`.

## [Unreleased]

### Added

- **Firewall** (FW-001, FW-002) — a new section at `/admin/firewall` with five
  tabs: rate limits, challenges, country block, reCAPTCHA and events.
  - `firewall_rules`, `firewall_countries`, `firewall_protected_paths` and
    `firewall_events`, each scoped by organisation and optionally by domain.
  - Rate-limit rules render into Traefik. A middleware cannot match a path, so
    a path-scoped rule gets its own router at a higher priority pointing at the
    same service; site-wide rules attach to the domain's router.
  - Challenge provider settings (Turnstile, hCaptcha, reCAPTCHA) with the
    secret encrypted on write and never read back, as SMTP already did.
  - Agent-side layer-3 enforcement through a dedicated `inet dockermgr`
    nftables table hooked at prerouting, with `firewall.apply` and
    `firewall.confirm` commands, a `never_block` safety set, refusal of an
    empty allow-only policy, and an armed rollback that removes the ruleset
    unless the panel confirms.
- **Monitoring completion** (MON-00x).
  - Notification cooldowns: an open incident re-notifies only after
    `notify_cooldown_minutes` (default 30). A resolution always notifies, and
    only if the opening was announced.
  - Maintenance windows now actually suppress. A window on a server covers its
    containers and the domains served from it; one with no server or group
    covers the whole tenant. Suppression stops escalation too.
  - Escalation: an incident still open after `escalate_after_minutes`
    (default 60) is raised to `emergency` once.
  - Auto-restart for containers that are running but **unhealthy** — the case
    Docker's own `unless-stopped` policy does not cover. Off by default and
    rate-limited.
  - `uptime_checks` records every probe, so `GET /uptime` reports measured
    uptime, check counts, average and p95 latency and incident history.
  - `GET /app/incidents` and `GET /app/uptime` for hosting customers.
  - WhatsApp (Cloud API, template-aware) and SMS (Twilio) channels.
  - Scheduler: `monitoring:follow-up` every five minutes, `monitoring:prune`
    daily.
- **Dashboard**: `Server::health_score` (0–100) reusing the thresholds
  `status` already applies, a fleet-health donut with a score distribution, and
  a "Needs attention" list ordered worst-first with reasons. `emergency` added
  as the top alert severity.
- **Hosting**: packages and customers, an application catalogue, per-site
  management tabs, and container limits defaulting to the customer's package.
- **Settings**: SMTP configurable from the panel; the panel URL is a setting.
- **Design system**: spacing, radius, elevation, z-index and motion tokens;
  `.btn-ghost` and `.btn-group`; `Card` gained a subtitle.

### Changed

- `Select` is now a Headless UI Listbox and `Icon` maps onto Heroicons, from
  the Tailwind Plus Application UI kit. Both keep their public API, so call
  sites are unchanged. Bundle grew from 179 kB to 215 kB gzipped.
- Action buttons have a hierarchy: a primary lifecycle action, a segmented
  group for related ones, and quiet row actions that only reveal danger
  colouring on hover or focus.
- UI rebuilt against the design canvas — header, icon rail and context-aware
  sidebar, full-width layouts, create and edit flows in overlays, and semantic
  colours taken from tokens rather than literals.

### Fixed

- `wordpress:detect` failed every night, and `wp.inventory` was never queued.
  Four `Container` queries filtered on a `status` column that does not exist —
  `containers` has `state` (created in `create_core_tables`, and
  `UptimeService` already queried it correctly). PostgreSQL rejected the query
  outright, so the scheduled command exited 1 before dispatching anything:

      SQLSTATE[42703]: column "status" does not exist
      HINT: Perhaps you meant to reference the column "containers.state".

  Fixed in `WordPressService::dispatchStep` and `::containerFor`,
  `MigrationService` and `ScheduledJobService`, so WordPress inventory, site
  migrations and container-scoped scheduled jobs can resolve a running
  container again. Each call site has an `?? $app->containerName()` fallback,
  which is why the failure surfaced as a hard error rather than a wrong
  container name.

- The agent segfaulted on the first command that shells out. `Executor.Run` is
  a `Runner` func field, and `newAgent` never set it, so it was a nil func
  value. `handlers.go` has an `e.run` wrapper that falls back to `ExecRunner`
  when the field is nil, but 34 call sites across 12 files call the exported
  `e.Run(...)` directly and bypass that fallback — `crowdsec`, `firewall` and
  `egress` (nft), `cron`, `antivirus`, `realtime`, `wordpress`, `mailimport`
  and `sysservices`. Enabling CrowdSec made the panel poll `crowdsec.alerts`
  on a timer, which turned the panic into a restart loop: the agent on the
  ingress host crashed 15 times in roughly 80 minutes, every few minutes,
  taking the heartbeat and every queued command with it. `newAgent` now sets
  `Run: executor.ExecRunner`, which fixes all 34 sites at once and leaves the
  `e.run` fallback in place as a backstop. Customer traffic was unaffected
  throughout, since Traefik and CrowdSec run as containers independent of the
  agent.

- `crowdsec.apply` refused its own payload on an installation with no firewall
  rules, so a fresh CrowdSec install never received its `profiles.yaml`. The
  panel dispatches `'scenarios' => (object) $cs['scenarios']` to force a JSON
  object, but `AgentCommand` casts `payload` to `encrypted:array`: Laravel
  decodes it with `assoc=true` and re-encodes an empty PHP array as `[]`,
  undoing the cast. The agent's `map[string]string` then failed with
  `cannot unmarshal array into Go struct field crowdsecApply.scenarios`.
  The agent now accepts an empty array as "no scenarios" while still rejecting
  a populated one, so the fix needs no panel-side change and works with the
  panel as it stands.

- `Lists::paginate` treated any filter column as a callable, and
  `is_callable()` is true for every string naming a global function — a filter
  called `action` invoked Laravel's `action()` helper and returned a 500.
  `GET /scheduled-jobs?action=…` had been failing on the deployed panel. Only a
  `Closure` is a custom filter now.
- Traefik: never emit an empty `routers` map. Traefik discards the entire
  file-provider configuration when it sees one, which took every site on the
  ingress down.
- Traefik's Docker provider removed: it requested API 1.24 against Docker 29,
  which requires 1.40.
- Malformed id filters return 422 rather than 500, across 13 endpoints.
- Staff can see domains across tenants; subscriptions resolve without the
  organisation scope when staff act for another tenant.
- The ingress is protected from one-click removal.
- Option labels that are not a single string are read correctly, so a select
  no longer shows a UUID in place of its label.
- Container limits and volume size/usage are reported by the agent.

### Security

- Challenge and SMTP secrets are encrypted at rest and never returned by the
  API; only a `*_set` boolean is exposed.
- Firewall rules whose action the proxy cannot enforce are reported as such in
  the API and badged "not enforced" in the UI, rather than appearing active.

### Known limitations

- Only rate limiting reaches live traffic. Country blocking and challenges are
  configurable but unenforced until the agent carrying the nftables commands is
  published to each server.
- The seven production sites are served by hand-written Traefik files, so the
  firewall renderer is covered by tests rather than by live traffic.
- The terminal relay is not configured on the current host; in-browser terminal
  sessions fail to connect.
- Five of 115 tests fail on the production host from environment mismatches
  (`CERT_DRIVER`, relay URL, export driver). Running the suite requires
  `php artisan config:clear` first — a cached config makes `DNS_DRIVER=fake`
  inert and produces roughly 34 false failures.

## [1.0.0] — 2026-10-28

Initial Docker Manager Portal release: Laravel 12 API, Go agent, React admin
portal and customer panel.

### Added

- Multi-tenancy: every model scoped by organisation, with RBAC, roles and
  permissions, and cross-tenant isolation covered by tests.
- Agent fleet control over mTLS with enrolment tokens, signed commands and an
  at-most-once command journal.
- Containers, images, networks, volumes, databases and Compose stacks.
- Deployment pipeline with releases, rollback, blue-green and health gates.
- Domains and TLS through Traefik with ACME HTTP-01 and DNS-01.
- Backups with restic, scheduled policies, restore and export; invoice PDFs;
  a Stripe driver; Prometheus metrics.
- Approvals for destructive and production actions, a verifiable audit log,
  recorded terminal sessions, passkeys and TOTP.

[Unreleased]: https://github.com/Livezen-Technologies/Worksuite-cloudpanel/compare/fb6dab8...HEAD
[1.0.0]: https://github.com/Livezen-Technologies/Worksuite-cloudpanel/releases/tag/v1.0.0
