# Changelog

All notable changes to WorkSuite CloudPanel.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Dates are the dates the work landed on `main`.

## [Unreleased]

## [1.11.0] — 2026-11-04

### Added

- Admin → Projects: a Show canvas button on each project draws all of the
  project's apps, in every environment, with the connections found in their
  variables. There is an environment filter, and staff can drag apps to
  arrange them; the layout is saved. Open app goes to the admin app page.
  Connecting apps and redeploying stay on the customer's canvas. (#171)

### Changed

- The staff and customer canvases now share one way of finding connections
  and saving layouts. Connections are read with one query instead of one per
  app. (#171)

## [1.10.0] — 2026-11-04

### Added

- Kubernetes: a Pods button on each deployment lists its pods, with
  readiness, restarts, the waiting reason (such as CrashLoopBackOff) and the
  node. Logs opens the last 300 lines of a pod's container, including from
  before its last restart. (#170)
- Kubernetes: a domains (ingresses) section lists each host, the service it
  points to, its address and whether it has HTTPS. Staff can add or delete a
  domain for a service. (#170)
- Kubernetes: when cert-manager is installed, a domain can get an HTTPS
  certificate from one of the cluster's issuers (such as Let's Encrypt),
  which cert-manager then renews on its own. (#170)

### Changed

- Kubernetes: the cluster page still loads when the token is not allowed to
  read ingresses or cert-manager; those sections are then hidden. (#170)

## [1.9.0] — 2026-11-04

### Added

- Projects → Environments tab for customers. They can add Development, Staging
  or Production environments (up to 5 per project), either empty or as a copy
  of another one's apps and variables. Copies are not deployed until the
  customer deploys them. Each environment can follow a Git branch. (#169)
- Customers can promote one environment to another. This deploys the image
  running in the source to the matching apps in the target, which keep their
  own variables; nothing is rebuilt. (#169)
- Customers can delete an empty environment. A project always keeps at least
  one. (#169)

## [1.8.0] — 2026-11-04

### Added

- Project canvas: drag apps to arrange them; the layout is saved for the
  project, with a Reset layout button. (#168)
- Project canvas: connect two apps from the side panel. This gives the
  calling app a variable with the other app's private address (for example
  `API_URL=http://api:8080`). Connections can be removed, which deletes that
  variable. Redeploy the app to apply either change. (#168)
- Project canvas: filter by environment (Production, Staging, …) when a
  project has more than one. (#168)

## [1.7.1] — 2026-11-04

### Changed

- Website: the Changelog page now shows the real release notes from this
  changelog, and the made-up customer logos, stats, team and blog posts are
  gone (the Blog says posts are coming soon). Add real ones in
  `web/src/site/content.ts`. (#167)
- Website: Legal now has full draft texts for Privacy, Acceptable Use, Data
  Processing, SLA and Security, marked as drafts until a lawyer reviews them. (#167)

## [1.7.0] — 2026-11-04

### Added

- A public marketing website at the panel's address: Home, Pricing (monthly or
  yearly prices, usage rates, a bill estimate and FAQ), Templates (search and
  categories), Docs, Changelog, Blog, About, Contact sales and Legal, with a
  light and dark theme. Signed-in visitors see a Dashboard button. Some copy
  (customer logos, stats, team, blog and changelog samples) is placeholder
  text kept in `web/src/site/content.ts`. (#166)
- Contact sales form: messages are saved, staff get a "New sales lead" email,
  and staff can read and close them under Admin → Sales leads. The form is
  rate-limited and uses the captcha and a hidden spam trap. (#166)

### Changed

- Opening `/` now shows the website instead of jumping straight to sign in. (#166)

## [1.6.0] — 2026-11-04

### Added

- **Swarm secrets, volumes and deploying from an app** — on the Cluster page:
  - Create and remove Swarm secrets. The value goes into the cluster's own
    encrypted store, is wiped from the panel's command record once the agent
    has answered, and is never shown again. Attach secrets to a service; each
    is mounted read-only at `/run/secrets/<name>`.
  - Mount a named volume into a service (named volumes only, never a bind
    mount of the host).
  - "Deploy from an app": pick an app that builds from Git and pushes to a
    registry, and its latest built image is deployed as a Swarm service.

## [1.5.0] — 2026-11-04

### Added

- **Deploy apps to a Swarm cluster** — the Cluster page can deploy an image
  as a replicated service (replicas, environment, a published port, placement
  constraints, memory/CPU limits), roll a new image out, and remove a service.
  Updates replace one replica at a time, start the new one before stopping the
  old, and roll back automatically if it fails to come up. Services run with
  all capabilities dropped and no privilege escalation, like single containers.
  The page refreshes the cluster after each change.

## [1.4.0] — 2026-11-04

### Added

- **Kubernetes** — connect existing clusters under Servers → Kubernetes, by
  pasting a kubeconfig or an API address with a service-account token (and the
  cluster CA). The panel checks the connection, then shows nodes (ready, roles,
  capacity), namespaces and deployments (ready/desired replicas), and can
  scale, restart (the same pod-template annotation `kubectl rollout restart`
  uses) and delete a deployment, or deploy an image as a Deployment with an
  optional ClusterIP Service. Tokens are stored encrypted and never returned.
  API addresses must be https; private networks are allowed, loopback,
  link-local and metadata addresses are not. Client-certificate and exec-plugin
  kubeconfigs aren't supported yet.

### Changed

- `symfony/yaml` is now a direct API dependency (it parses kubeconfigs).

## [1.3.0] — 2026-11-03

### Added

- **Docker Swarm cluster** — a Cluster page under Servers: create a swarm on a
  manager server, join servers to it (over their private network address when
  they have one), see nodes with their tasks and labels and services with
  running/desired replicas, drain or reactivate a node, scale a service, and
  remove nodes or the cluster. Everything runs through the agent on the
  servers' own Docker Engine API (new `swarm.*` commands); join tokens are
  stored encrypted and never returned by the API.

## [1.2.0] — 2026-11-03

### Added

- **Customers**, an eighth admin section between Finance and Settings, holding
  the customer directory, companies, contacts and customer groups. Hosting's
  "Hosting Customers" item moves here, the way Packages moved to Finance.

  - **Account status is a column, not a key in a JSON blob.** `suspend` and
    `close` wrote `organizations.settings`, which no index can reach, so the
    directory could not filter by it and the dashboard could not count it. It
    is now `organizations.status`, backfilled from the existing JSON and from
    suspended subscriptions, with the JSON keeping the suspension reason.
    `organizations` also gains a customer type, account manager, onboarding
    status, registration, last-activity and archive stamps; `users` gain phone,
    country, locale, timezone and notification preferences.
  - **A company is the account's billing profile**, extended with industry,
    website and registration number -- not a second record that could disagree
    with the one Finance addresses invoices to. New `contacts` holds the
    billing, technical and abuse people who have no login, with one primary
    contact per account enforced on write.
  - **Customer groups** with the twelve defaults, seeded once and undeletable
    thereafter; a customer may be in many, and where two carry a discount the
    largest applies. Rule-driven membership arrives with the next phase, and a
    manual edit already leaves rule-sourced rows alone.
  - `customer_activities` is the customer's readable timeline, kept separate
    from the tamper-evident `audit_logs` chain so it can be pruned.
  - New permission `customers.manage`, granted to Support; reading is open to
    anyone who already manages users or billing, so Finance and support keep
    the visibility they have.
  - CSV export honours whatever the directory is filtered to, and the archive
    is a separate view rather than a filter to remember.

- **Finance & Billing**, a seventh admin section between Operations and
  Settings, gathering invoices, payments, refunds, subscriptions, packages,
  billing profiles, transactions, gateways, reports and finance settings behind
  `billing.manage` (the `billing` role reads everything and writes, `developer`
  sees none of it).

  - New tables: `payments` (one row per collection attempt, with a unique
    `(gateway, gateway_reference)` so a webhook replay cannot bank the same
    money twice), `refund_requests` (request, decision and gateway outcome kept
    apart, so a refund that the gateway rejects parks as `failed` with its
    reason instead of writing a credit note), and `billing_profiles` (who an
    invoice is addressed to, the tax identity and an account balance).
    `subscriptions` gains a billing cycle, discount, trial end, cancellation and
    expiry dates, auto-renew, and a booked next-renewal package change.
  - `FinanceService` holds the money rules in one place: what an invoice still
    owes, what is left to refund once open requests are counted, MRR and ARR
    (a yearly subscription counts as a twelfth, after its discount), churn over
    a window, and the proration for a mid-term package change.
  - **Change package** quotes before it charges: the drawer shows the unused
    credit on the current package, the new package's share of the remaining
    days and the net to charge now, and can book the change for the next
    renewal instead. Every change is written to the audit chain.
  - Overview with fourteen figures over Today / 7 / 30 days / this month / this
    year / a custom range, revenue and refund trends, and breakdowns by gateway
    and package; reports with a CSV export.
  - `php artisan finance:demo` seeds a panel worth judging the screens by:
    100 customers, 6 packages, 150 subscriptions, 350 invoices (50 of them
    overdue), 300 payments including part payments, failures and disputes, and
    25 refunds across every state, over twelve months. It refuses to run on an
    installation with more than one organisation unless forced.

- **The ingress can now reach a container over its environment network**, which
  is what has to be true before anything can stop sharing `proxy`. This ingress
  runs with no Docker provider: routers come from the file provider and name the
  container (`http://app-foo:80`), so Traefik can only reach a container over a
  Docker network it shares with it. Traefik is therefore attached to each
  environment network as soon as that network exists.

  - New agent commands `network.connect` and `network.disconnect` (agent
    **1.2.5**), wrapping Docker's network attach and detach. A connect that
    finds the container already attached is success, so re-running is free;
    disconnect refuses the built-in networks the way `network.remove` does.
  - The attach is dispatched when a network goes active and recorded on
    `networks.ingress_attached_at`, so the reconcile repairs a missing one
    without re-queueing a command every fifteen minutes for ever.
  - Servers whose agent predates 1.2.5 are skipped rather than sent a command
    that cannot succeed, so the panel is safe to deploy before the fleet is
    updated; each server attaches by itself on the first reconcile after its
    agent reports a new enough version.

  Nothing is removed from `proxy` by this change, so no routing is altered.

### Fixed

- The customer dashboard's breakdowns no longer build SQL from a column name:
  the column is whitelisted and selected through the query builder.
- Losing the race to create an environment network no longer fails on
  PostgreSQL: the insert runs in a savepoint, so the lookup that recovers the
  winner's row isn't refused by an aborted transaction.
- **A customer's package and owner were hidden from the admin panel by the
  reader's own tenant scope.** `Organization::subscription()` and
  `memberships()` carried the organisation scope, so every customer but the
  reader's own showed a blank package, subscription state and e-mail in any
  cross-tenant list. Both relations are keyed by `organization_id` already, so
  dropping the scope cannot widen what they return.
- A new permission added to `config/rbac.php` was invisible until the reference
  seeder ran again, because the matrix is read from `role_permissions`. The
  seeder now runs from a migration, so a deploy does not need the extra step.
- A tenant-scoped child row no longer disappears from an admin list: a
  payment's or refund's invoice is read without the reader's own organisation
  scope, so the panel showed `—` for every invoice belonging to another
  customer, and reconciling such a payment silently skipped settling it.
- The area rail now follows the same longest-match rule as the section panel,
  so a section whose first item is an exact-match route stays lit on its own
  sub-pages.

- **An environment network could be created twice and break the deploy that hit
  it.** The existence check in `forEnvironment` was tenant-scoped, but a deploy
  driven by an agent command result runs with no tenant set: the lookup found
  nothing, created the network a second time and failed the
  `(server_id, name)` unique index as a 500 from inside the deployment
  pipeline. One environment on one server is one network globally, so the pair
  is now looked up unscoped, and `organization_id` is set explicitly rather
  than left to a scope that may not be active.

- **Two environments could be given the same network name.** The name used the
  first twelve hex characters of the environment's UUIDv7, which are exactly
  its 48-bit millisecond timestamp -- so any two environments created in the
  same millisecond, as duplicating one does, produced an identical name and
  collided. The full id is used now.

- **Two processes could race to create the same environment network.** A deploy
  and `networks:reconcile` resolve the same (environment, server) pair from
  different processes, which `withoutOverlapping` cannot prevent; on the live
  panel the scheduled reconcile died on `networks_server_id_name_unique` at
  11:30:07 on 2026-11-02 while a manual run was creating the same row. Losing
  the race now returns the winner's row, recovered by the key that collided.

### Added

- **Every environment now has its own private network.** Services in one
  project's environment could reach services in every other customer's
  environment: all containers shared the `proxy` network and resolved each
  other by name. Each environment now gets a Docker bridge network of its own
  (`wscp-env-<id>`, labelled with the project and environment), created on the
  server the environment's first application is placed on, and containers join
  it alongside `proxy` with their service name as a network alias — so
  `${{service.HOST}}` resolves over a network only that environment is on.

  This step is deliberately additive: nothing is removed from `proxy`, so
  routing is unchanged and no existing deployment can lose traffic. A container
  joins its environment network only once the network is reported `active` by
  the agent, so a deploy never waits on, or fails because of, network creation.
  `networks:reconcile` (every fifteen minutes) creates any network that is
  missing and re-dispatches ones stuck in `creating`. It resolves the server
  outside the tenant scope, because a server can belong to one organisation
  and host another's apps, and it reports anything it could not resolve
  instead of skipping it in silence.

  Dropping `proxy` for service-to-service traffic — attaching Traefik to the
  environment networks and moving databases off the shared `internal`
  network — is the next step and is not part of this change.

### Fixed

- **Customer applications now belong to a project and an environment.** They
  were created with `environment` set to the bare string `production` and
  `environment_id` left null, while every environment-shaped feature keys on
  the foreign key. That single omission cut customer apps off from four things
  at once, all of which now work:
  - **Deploy on Git push.** `onPush` matches apps by `environment_id`, so a
    push could never reach a customer app. An environment that names a branch
    still tracks it; one that does not — which is every environment the portal
    creates, since it never asks for a branch — now falls back to each app's
    own source branch.
  - **The "Auto-deploy when I push" toggle**, which was stored and displayed
    but never read by any deploy path. It gates customer apps only: on an admin
    application the same column means "deploy on a new release", a different
    promise, so admin push behaviour is unchanged. Hosting from a repository
    turns it on, making it an opt-out.
  - **`${{service.HOST}}` discovery between a customer's own apps**, which
    resolves siblings within an environment.
  - **Promotion, duplication and PR previews**, which select by environment.

  Apps created before this are backfilled. An app with no project of its own
  goes to a default project — an existing project is adopted rather than adding
  a second one beside it — and that project cannot be deleted, since it is
  where apps land. Taking an app out of a project returns it to the default one
  rather than leaving it with no environment, and moving an app between
  projects moves its environment with it. Hosting an app accepts `project_id`
  and `environment`, and the portal is given each project's Git webhook URL and
  a way to roll its signing secret.

### Added

- **Customers create a project in two steps.** The form asked only for a name
  and a colour; it is now "Create new project" → "Assign resources", asking
  also for the environment type, what the project is for and a description,
  and then offering every app that is not in a project yet. The project's
  **first environment is created with it**, because a project without one
  cannot hold an app at all, and a project's environments are now reported
  from the project itself rather than inferred from the apps inside it — so a
  new, empty project shows the environment it was just given. `purpose` is a
  new nullable column; editing a project can now change its description too.
- **An account menu of its own for the customer portal.** The menu was shared
  with the admin panel, so a customer's "Account" pointed at `/admin/account`,
  a page they cannot open. In the portal it is now **My Account** (`/app/account`),
  a **Theme** list offering Light, Dark and System rather than the header's
  two-state toggle, an **Organization** list of every organisation they belong
  to, and **Create an Organization**. The admin panel's menu is unchanged.
- **Anyone can create an organisation for themselves** — `POST /organizations`
  creates it with the caller as owner, and `POST /me/organization` acts as
  another one they belong to. Both move the access token as well as the user's
  `current_organization_id`, because tenancy reads the token first; a support
  session is refused both.
- **Theme can follow the operating system.** The stored preference is now
  `light`, `dark` or `system`, and `system` keeps following the OS while the
  page is open instead of only at load.

### Fixed

- **An application you cannot see no longer loads for ever.** `AppManage` showed
  its loading skeleton whenever it had no row, which included "the request
  finished and failed", so a 404 looked like a page that never loaded. It now
  shows the skeleton only while the request is in flight, and names the cause.
  A 404 is reported as what it almost always is — the application belongs to an
  organisation other than the selected one, since tenancy follows the
  organisation picker and there is no cross-organisation route for a single
  application — and any other failure shows the server's own message.
- **Every branded page answered HTTP 200** (PUB-1, PUB-3). The generated nginx
  config wrote `error_page 403 = @suspended`; the `=` hands the status to the
  named location, and `try_files` serving a file there makes it a 200. So a
  suspended site, a parked domain, a site in maintenance, one that was expired
  and the not-found catch-all all reported OK — crawlers indexed suspended
  sites and uptime monitors called them healthy. Dropping the `=` keeps each
  page's own code (403, 404, 503, 402) while still serving the body and
  `Retry-After`.
- **Public pages were written unreadable, so every branded page answered 404**
  (PUB-4). The pages container runs nginx as its own unprivileged uid, but the
  agent's unit sets `UMask=0077`, which intersects the mode `fs.write` and
  `fs.mkdir` pass to `open(2)` and `mkdir(2)`: the `0644` and `0755` they ask
  for landed as `0600` and `0700`, owned by root. nginx could not read a single
  page, and because its `try_files` falls through to `=404` the symptom was an
  ordinary 404 on every page - served behind a valid certificate, and
  indistinguishable from a routing mistake. Install and publish now follow the
  writes with an explicit `fs.chmod` (`0755` for folders, `0644` for files and
  the nginx config).
  - This silently disabled suspend, park and branded error pages: a
    `dmp-<host>-page` router at priority 100000 would 404 instead of showing a
    page, taking the host down rather than parking it.

### Added

- **Panel assets are cached correctly** — nginx sent no `Cache-Control` for
  the SPA, so browsers cached `index.html` heuristically and a deploy looked
  like it had not landed until someone hard-reloaded. The shell is now
  `no-cache` and the content-hashed `/assets/` files are `immutable`.
  `docs/deploy/nginx-agent-mtls.conf` carries the same blocks, with a note
  that `add_header` in a location replaces the server block's headers.

- **Per-mount disk reporting** — `disk_gb` and `disk_pct` describe the root
  filesystem only, so a server whose data sits on a second array was reported
  as though that array did not exist: a node with two 1.8 TB RAID1 arrays
  showed 498 GB free instead of 2.2 TB.
  - The agent now sends every mounted local filesystem on each heartbeat
    (`stats.disks`: mount, device, fstype, size and used in MB, used %), read from
    `/proc/mounts` and `statfs(2)`. Pseudo, RAM-backed and read-only image
    filesystems are skipped, Docker's own mounts are left out, and a device is
    reported once however many places it is mounted, so a bind mount never
    counts an array twice. The list is sorted largest first and capped at 32.
  - Stored on `servers.disks`, shaped and bounded on the way in. `disk_pct`
    keeps its meaning as the root figure the status thresholds are written
    against, so no server changes status because of this.
  - Sizes are carried in megabytes and rendered in the largest sensible unit,
    so a 1.8 TB array does not read as `1874560 MB` and a 988 MB `/boot` does
    not read as `0 GB`.
  - The server page gains a **Filesystems** table under Storage, and the Disk
    tile names the total alongside the root figure once a host has more than
    one filesystem.

## [1.1.0] — 2026-11-01

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

- **Billing** (#143, #144) — customers pay by card (Stripe) or PayPal; cancel
  a subscription; refunds and credit notes (`InvoiceService::refund`); dunning
  with retries (`billing:dunning`).
- **Servers** (#145) — drain a server (move its residents away), a delete
  guard while it still runs workloads, and revoking an agent by name.
- **Notifications** (#146) — signed webhooks (`X-DMP-Signature`, HMAC over
  timestamp and body), real delivery failures, and per-channel health.
- **Customer projects** (#147, #150) — group apps into coloured projects; a
  project canvas showing apps as connected cards (linked when one app's
  variables name another as a host), with monthly cost and environment badges.
- **AI & MCP** (#148, #152, #153) — an MCP server over the customer's apps with
  read and change tools; an AI level per account (read-only, ask before
  changes, autonomous) with approvals and an activity log; scoped MCP tokens;
  OAuth sign-in for MCP clients (discovery, dynamic registration, PKCE,
  rotating refresh tokens); a built-in assistant on the Claude API
  (`claude-opus-5-5`) that uses the same tools and approvals and streams its
  replies; an admin AI & MCP page.
- **Customer VPS** (#149) — container servers with root access under the
  Sysbox runtime on servers with the `vps` role; KVM plans listed as coming
  soon.
- **Git sources** (#151) — ed25519 deploy keys for SSH repositories; GitHub,
  GitLab, Gitea and Bitbucket connections (token, or OAuth sign-in for GitHub
  and GitLab); private repositories for customers; a webhook delivery log.
- **Monitoring, backups and audit** (#140, #142) — alerts, cleanup, audit
  export, audit filters and an integrity check.
- **Versioning** — a root `VERSION` file is the single source of the release
  number (API `auth/config`, `web/package.json`, the agent build), with
  `scripts/release.sh` to bump it and date this changelog, and a test that
  keeps them in step.

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

- Duplicate Stripe subscriptions on SQLite (a `LIKE` match) (#144).
- A connection's Git token is only ever sent to that provider's host, and
  repository paths with `.` or `..` segments are rejected (#151).
- Tests that depended on timing or row order (`DnsZoneTest`,
  `FileManagerTest`) now freeze the clock or order explicitly.
- **Public pages could take every managed domain down to a 404 on an ingress
  with no Traefik docker provider.** `PublicPagesService` referenced the pages
  container as `ws-pages@docker` and started it with no network, so it landed on
  the default bridge. Where Traefik routes purely through the file provider —
  as it does once the docker provider is removed, because Traefik's docker
  client negotiates API 1.24 and Docker 29 refuses it — that name resolves to
  nothing. Traefik drops any router whose middleware chain will not resolve, and
  since `dmp-pages-errors` is attached to *every* domain router, each one was
  dropped and its traffic fell through `dmp-pages-catchall` to the 404 page.
  The failure is silent: TLS still terminates with the right certificate, so a
  domain looks healthy and answers 404. Observed on a live ingress where all
  twelve adopted domains went to 404 the moment the panel took over routing.
  - `platform.traefik.docker_service_suffix` is now settable via
    `TRAEFIK_DOCKER_SERVICE_SUFFIX`. Set it to `@file` on such a server and the
    pages service is declared in the pushed file, where the reference resolves.
  - The container now joins `platform.traefik.network` (`TRAEFIK_NETWORK`,
    default `proxy`) instead of the bridge, so Traefik can reach it by name at
    all. Existing installs need the running container attached to that network
    once; the labels are kept so a docker-provider ingress is unaffected.
  - The default stays `@docker`, so nothing changes for an ingress that does run
    the docker provider.
- **The same unresolvable-service bug as the public pages one, for the apps
  themselves.** `TraefikConfigService::render()` points an application-backed
  domain at `app-<name><suffix>`, but the only services it declared in the
  pushed file were the adopted `*-upstream` ones, `ws-pages`, and a `-pool` or
  `-split` from `scalingServices()` — and those exist only when the app has
  copies on another server or a running canary. On an ingress with no docker
  provider there is nothing to turn the container's labels into
  `app-<name>@docker`, so a plain app-backed domain named a service that did
  not exist, Traefik dropped its router, and the domain fell through to the
  catch-all 404 with its certificate still terminating correctly. Attaching a
  site to an app in the panel is what performs that binding, so this was
  reachable from the UI on such a host, not theoretical.
  - `app-<name>` is now declared in the file whenever the suffix is not
    `@docker`, listing **every replica of the app on its own server** as the
    docker provider would have from their shared label. Copies on other servers
    keep going through `<app>-pool`, and a running canary now gets its
    `<app>-canary` declared too, so the weighted `-split` service resolves.
  - The port comes from the current release's config, falling back to the app's
    own port; with no container reported yet it falls back to the name the panel
    gives the container, so a freshly created app still routes.
  - The default stays `@docker`, so a docker-provider ingress is unchanged.
- An incident whose container was replaced stayed open for ever and kept
  re-notifying. `UptimeService::checkInfrastructure` iterates the container and
  server rows that exist now, opening or closing an incident keyed by each row's
  id. A container recreated under a new id leaves its old incident unvisited, so
  nothing ever resolves it while `followUpOpenIncidents` re-notifies it on every
  cycle. One alert on the ingress host had been open since 2026-10-29 and had
  notified **81 times**, long after the container recovered. `checkInfrastructure`
  now closes open container and server incidents whose resource row has gone.
  This matters more since the security e-mails landed: a dangling incident now
  sends mail rather than only writing an Ops-log line.

- `TestRunLoop` failed on any machine that actually runs the agent. The test
  never set `PolicyPath`, so `ApplyDefaults` pointed the policy store at the
  real `/etc/dockermgr/policy.json`. On an agent host that directory is
  `0700 root`, so `os.Stat` returns `EACCES` rather than `ENOENT`; the store
  fails closed (correctly) and rejected every command with
  `rejected: stat /etc/dockermgr/policy.json: permission denied`, three times
  over as the fake panel redelivered. The test now keeps its policy file in
  its own temp directory. No production code was involved: command
  de-duplication was working, and the repeated attempts were a consequence of
  the rejection, not a failure to de-duplicate.

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

- The assistant's stored history is encrypted JSON, not `serialize()`; MCP
  tokens cannot approve OAuth clients for themselves; a reused OAuth code or
  rotated refresh token revokes its whole family (#152, #153).
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
