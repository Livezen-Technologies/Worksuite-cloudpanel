# Changelog

All notable changes to WorkSuite CloudPanel.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Dates are the dates the work landed on `main`.

## [Unreleased]

## [1.35.0] — 2026-11-22

### Added
- **Enterprise Updates: restore the pre-update data on an automatic rollback** (owner decision 2026-11-22). New setting `restore_data_on_rollback` (default on, in `update_settings`). When a run (manual, scheduled or auto) is unhealthy and has rolled back to the previous tag, it moves to a new active status `restoring_data` and restores its pre-update backup through the existing project restore (fresh safety backup of the current state first, then database + files), then finishes `rolled_back`. Runs carry `data_restore {state, backup_id, restore_job_ids, safety_backup_ids, error}` and `data_restored`, in `GET updates`, the run log, the `deployment.update` webhook and the audit (`enterprise.deployment.update.data_restore`). A failed or timed-out data restore (`ENTERPRISE_UPDATE_RESTORE_TIMEOUT`, 90 min) leaves the run `rolled_back` with `data_restore.state: failed`, an explicit error, and the safety backups kept. Migration `2026_11_29_090000_enterprise_update_data_restore` adds `enterprise_updates.data_restore`.

## [1.34.0] — 2026-11-22

### Added
- **Enterprise Updates: customer notice before an automatic update** (owner decision 2026-11-22). New setting `notice_hours` (0/6/24/48/72, default 24) on `PUT deployments/{ref}/updates/settings`. In `auto` mode `enterprise:updates` sends a new `deployment.update_scheduled` webhook (`version`, `current_version`, `window_start`, `window_end`, `timezone`, `notice_hours`, `notify_at`) once per version and window, for the first window that can still be announced `notice_hours` ahead; Cloud emails the customer contacts from it. An auto run now only starts in a window that was announced in time, so a customer is never updated without notice; `notice_hours: 0` keeps the old behaviour. `GET updates` adds `next_notice {version, window_start, notify_at, announced}`. No migration (kept in `update_settings`).

### Added
- **Enterprise Updates** (`docs/ENTERPRISE_API.md` "Updates"): `GET/PUT deployments/{ref}/updates[/settings]`, `POST updates` (now or at a time), `POST updates/{id}/cancel`, `POST updates/{id}/restore {confirm, reason}`. Every run takes a project backup first (DB dump + data volumes), redeploys on the new tag, waits for the health gate and rolls back to the previous tag on failure; nothing changes when the backup fails. Per-deployment settings off / notify / auto, channel `latest` or a `major.minor` line, and a maintenance window (days, time, time zone, length). `enterprise:updates` runs every minute; auto updates are idempotent per deployment and version. `deployment.update` and `deployment.update_available` webhooks, audit entries for every action. New table `enterprise_updates`, column `enterprise_deployments.update_settings` (migration `2026_11_28_090000_enterprise_updates`).

### Fixed
- **Project backups of enterprise deployments held no data**: an application backup snapshotted `app-<name>-data`, but enterprise apps keep their data in the named volumes of `source.mounts` (`ent-<slug>-writable`, `-uploads`), and the project's MariaDB was never dumped. Application backups now take the app's named mounts, a project backup also dumps the project's SQL/document databases, and web + worker sharing the same volumes are backed up once. Restores use the same paths.
- Enterprise API `POST deployments/{ref}/licence-activate {key}`: activates a WorkSuite Server key (WS-XXXX ×5) in the web container as root via `license:refresh --key -` (key on stdin; never in the command line, the stored command or the audit log, only its last four). `licence-refresh` and `licence-activate` now keep the app's JSON answer (masked key, status, edition, expiry, users) on the `licence_refresh` step as `result`, for Cloud's Licence tab. The default `ENTERPRISE_LICENCE_REFRESH_COMMAND` (`spark license:refresh`) needs WorkSuite Core 1.47.0, which adds that command.

## [1.33.1] — 2026-11-21

### Fixed

- **Enterprise "Configure WorkSuite" failed with exit 2 and an empty error** (computerage.worksuite.lk). The
  bootstrap, status and licence-refresh execs ran as 33:33, but `worksuite-entrypoint` must start as root (it
  writes `.env` and chowns, then drops to www-data itself). `enterprise.exec_user` now defaults to `0:0`; since the
  agent's container mode refuses root, a root exec is dispatched in host mode as `docker exec -u 0:0 <web>`.
  Note: the prod hot fix `ENTERPRISE_EXEC_USER=0:0` on 1.33.0 is refused by the agent's container mode.

### Added

- **Failure diagnostics on Enterprise deployments.** A failed step now keeps `code`, `hint` (fixing guide),
  `detail` (redacted 60-line output tail, base64 payload and credentials removed) and the bootstrap's `fields`;
  `errors[]` exposes them (docs/ENTERPRISE_API.md lists the codes). The step message is never empty.
- Retrying the `bootstrap` step returns 409 `bootstrap_needs_payload` with a hint to restart the setup wizard;
  `configure` stays accepted from a bootstrap-failed deployment.

## [1.33.0] — 2026-11-21

### Added

- **Scheduled maintenance windows.** `applications.maintenance_scheduled_start`, `maintenance_scheduled_end` and
  `maintenance_notice`, set with `maintenance_schedule {start, end, message}` (or `null` to clear) on the Enterprise
  settings PATCH, or `PUT`/`DELETE /api/v1/{app/apps|applications}/{id}/maintenance-schedule` next to lock/unlock.
  Returned as `maintenance.schedule` (Enterprise settings) and `maintenance_schedule` (lock state). The new
  `maintenance:apply-schedules` command (every minute) locks the app at `start` the same way the maintenance toggle
  does (reason = the notice, expected back = `end`) and, at `end`, unlocks it only if it locked it itself
  (`maintenance_auto_locked`); a lock made by hand is left alone. Idempotent; audited as `app.maintenance.schedule`,
  `.schedule.clear`, `.auto_lock`, `.auto_unlock`, `.schedule.expired`. A manual unlock during an open window ends
  the window, so it is not re-locked a minute later.
- `GET /api/v1/hooks/maintenance-notice/{app}` (public, cached 60 s): `{scheduled: {start, end, message} | null,
  active}` for the app's in-app notice. CORS only for the app's own verified domains over https.
- Enterprise web apps get `WS_MAINTENANCE_NOTICE_URL` (panel-managed, read-only) pointing at that endpoint; older
  deployments get it the first time a window is scheduled (it then needs a redeploy to reach the container).
- `MAINTENANCE_PAGE_SUPPORT_EMAIL` and `MAINTENANCE_PAGE_STATUS_URL` (both unset by default): optional "Contact
  support" button and "System status" link on the maintenance page.

### Changed

- **Maintenance page redesigned** to the WorkSuite Cloud canvas (C01 desktop / C03 mobile): dark by default with a
  light variant, the WorkSuite mark inline as SVG, a "Maintenance in progress" badge, a maintenance-window card with
  started / expected back shown in the visitor's own time zone, the team's note, and a "your work is safe" callout.
  Still one self-contained 503 response with no external requests (system font stack, no web fonts). The mockup's
  progress percentage, service-status table, update timeline, notify-me form, calendar link and incident number are
  not shown: the panel has no real data for them.

## [1.32.0] — 2026-11-20

### Added

- **Branded maintenance page for locked apps** (Enterprise maintenance mode uses it). The public now gets an HTTP 503
  with `Retry-After` and a self-contained WorkSuite page — "We're making improvements", the admin's reason
  (HTML-escaped), when it started, the expected end, a status line, auto-refresh every 60 s, light/dark — instead of
  Traefik's bare 403. Served by the panel at `GET /api/v1/hooks/maintenance/{app}` (cached 15 s). With clients
  connecting directly, a takeover router `dmp-<domain>-maintenance` (priority 99000: above the app's routers, below IP
  blocks and the public-pages takeovers) sends everyone outside the allow list and `FIREWALL_ALWAYS_ALLOW` to it, so
  staff on kept ranges still reach the app; behind a CDN (`TRAEFIK_FORWARDED_DEPTH` > 0) an errors middleware wraps the
  allow list and swaps its 403 body for the page. Only the `websecure` routers change, so ACME and backend health
  checks are untouched. Unlocking removes the routing.
- `applications.maintenance_until`: optional expected end, accepted as `until` on app/project lock and as
  `maintenance_until` on the Enterprise settings PATCH, returned as `maintenance.until`; drives `Retry-After`
  (else `MAINTENANCE_PAGE_RETRY_AFTER`, default 300 s). Cleared on unlock. Re-locking a locked app keeps its start time.
- `MAINTENANCE_PAGE_ADMIN_LINK` (default off): shows "Admin? Sign in to CloudPanel" linking to `APP_URL`.

## [1.31.0] — 2026-11-20

### Added

- **Enterprise part F**: the deployment page's CloudPanel tabs for Cloud Admin, under
  `/api/v1/enterprise/deployments/{ref}/…` — `dns` (project DNS & Domains), `firewall` (+ ips, rate-limits, lock/unlock),
  `security` (TLS, access, CrowdSec/blocked IPs, firewall events, members, audit), `settings` (env with secrets masked
  and panel keys read-only, timezone, maintenance, redeploy), `backups` (+ run, schedule, details, restore, download),
  `monitor` (CPU/RAM/disk/network series, health, uptime, alerts), `resources` and `scale` (resize, web copies, quotas).
  Each delegates to the existing project controller for the deployment's project through a per-call
  `ProjectGrant`; what the panel cannot do answers 501 `PANEL_UNSUPPORTED`. See docs/ENTERPRISE_API.md.

## [1.30.0] — 2026-11-20

### Added

- **Enterprise part E** for Cloud Admin's deployment screens: `GET /enterprise/versions` (worksuite-server tags on the
  enterprise nodes, checked directly on the node when the inventory is stale), `GET /enterprise/nodes` (free CPU/RAM/disk),
  `resize` (preset or custom limits, FPM children follow), `metrics` (CPU/RAM series, disk), domains add / verify /
  primary / remove, `licence-refresh`, per-step `retry` and single-container restart. Deployments also report `limits`,
  `login`, `domains`, `health` and `urls.panel`/`urls.login`; DNS/TLS states read ok/pending/failed; not-founds carry
  `deployment_not_found` / `domain_not_found`.

### Changed

- Enterprise addresses default to `<slug>.worksuite.lk` (`ENTERPRISE_DOMAIN`). Slugs that already exist as records in that
  managed zone are refused, and the domain step shows the zone publish result (created / present / conflict).

### Fixed

- Enterprise web and worker containers start: they get back the capabilities (CHOWN, DAC_OVERRIDE, FOWNER, SETGID,
  SETUID) their entrypoint needs to prepare the writable volume under cap_drop ALL.
- Redeploying a failed enterprise deployment puts it back to provisioning instead of leaving it failed.
- Agent inventory on busy nodes: container stats are read one-shot (no second-sample wait in dockerd), the image,
  network and volume lists are taken first, stats stop when their time budget is spent, volume sizes are cached
  for 10 minutes, and a list Docker could not answer is sent as unknown instead of empty. Needs the agent rollout.
- The panel no longer empties a node's image list when a heartbeat reports no images while containers run,
  nor its networks on an empty network list (what older agents sent when the list call timed out).

## [1.29.0] — 2026-11-20

### Added

- **Enterprise provisioning API** for Cloud Admin (`/api/v1/enterprise`, token ability `enterprise.deploy`):
  create a dedicated WorkSuite Server deployment (project "Enterprise · <customer>" with managed MariaDB 10.11,
  Redis, the `web` and `cron` containers of the `worksuite-server:<version>` image on the least-loaded
  enterprise node, `<slug>.worksuitecloud.com` with automatic TLS), follow its steps, read redacted logs,
  run the setup wizard's `enterprise:bootstrap` inside the server (with an optional own domain),
  restart / stop / start / redeploy, delete (keeps data) and an explicit purge. Signed status webhooks
  (`X-Enterprise-Signature`, HMAC-SHA256, per-deployment secret) go to Cloud Admin on every status change.
  `php artisan enterprise:service-account` creates the service account and its token. (#222)
- Projects carry tags; enterprise deployments show an **Enterprise** badge in Projects.
- Apps can run a command other than the image default and share named volumes (used by the enterprise web and cron containers).

## [1.28.0] — 2026-11-20

### Added

- **Admin account sync with WorkSuite Cloud (staff SSO part 2).** Panel access for staff is now granted per app in
  Cloud Admin. Sign-in with WorkSuite Cloud requires the `wsc_app_role` claim (`super_admin`, `infra_admin` or
  `devops`), finds the user by `wsc_sub`, else links an existing user by email (case-insensitive), else creates
  one with no usable password, and sets their role in the staff organisation. A suspended membership still cannot
  sign in. Staff accounts are created in `WSC_STAFF_ORGANIZATION_ID` (default: the oldest super admin's organisation).
- **Provisioning endpoint** `POST /api/v1/auth/wsc/provision` for Cloud Admin's signed events (compact RS256 JWS,
  `application/jwt`): verified against the issuer's JWKS, `iss`, `aud` = `WSC_OIDC_CLIENT_ID`, `exp`, and a single-use
  `jti`. `upsert` links or creates the user and sets name and role (re-enabling only users WorkSuite Cloud
  disabled); `disable` suspends them in the staff organisation and ends their sessions, keeping their data.
  Users that are neither linked nor matched are never touched. Returns 204; 400/401/415 on bad input.
- **Auto sign-in.** With WorkSuite Cloud set up, the sign-in page tries the silent flow (`prompt=none`) on load,
  once per browser tab; never after an explicit sign-out (until the next sign-in) or with `?local=1`. The page
  the visitor was going to survives the round trip (same-origin paths only). `access_denied` returns to the
  sign-in page with a short message. Two-factor stays where it was.

### Changed

- Users & Teams shows a WorkSuite Cloud-managed member's role read-only ("Managed in WorkSuite Cloud"); the API
  refuses a local role change for them (409). A super admin can still suspend them locally.
- Migration `2026_11_23_090000_users_wsc_link` adds `users.wsc_sub` (unique), `managed_by_wsc` and `wsc_disabled_at`.

## [1.27.0] — 2026-11-20

### Added

- **Sign in with WorkSuite Cloud (staff SSO)** (#218). Platform staff (super admin, infra admin, DevOps) can sign in
  through OneAuth at account.worksuitecloud.com. The panel only matches an existing staff account by verified
  email (case-insensitive) and never creates one; anyone else sees "No CloudPanel admin account for <email>".
  State, nonce and PKCE are checked and the id_token is verified against the issuer's JWKS (signature, iss,
  aud, exp, nonce). Callback at `/auth/wsc/callback`. Configured with `WSC_OIDC_ISSUER`, `WSC_OIDC_CLIENT_ID`,
  `WSC_OIDC_CLIENT_SECRET` and `WSC_OIDC_REDIRECT_URI`; the button stays hidden until they are set. Password
  sign-in keeps working, and `/login?sso=1` tries a silent sign-in first.

### Changed

- **The 9-dot app launcher** (#218) now reads the shared WorkSuite Cloud app list (with a built-in fallback), highlights
  CloudPanel, opens apps in the same tab, returns focus on Esc, sits beside the user menu, and is shown to
  platform staff only.

## [1.26.0] — 2026-11-20

### Added

- **Customer team management.** Account → Team in the customer portal now lets an owner change a
  member's role (owner, developer, billing) and remove a member, through
  `PATCH /api/v1/app/account/team/{userId}` and `DELETE /api/v1/app/account/team/{userId}`
  (`app.team`). The organisation always keeps one active owner, owners cannot remove themselves,
  and removing someone also drops their project roles and API tokens in that organisation. Both
  actions are audited (`team.member_role_changed`, `team.member_removed`). Before, customers
  could only invite; changing or removing a member needed staff.

## [1.25.0] — 2026-11-19

### Added

- **Customer project workspace** (Project workspace slice H). The customer portal's project page
  (`/app/projects`) now uses the shared Tabs and the same workspace components as the admin side,
  against the `/app` endpoints: Deployments (progress, retry, rollback), Databases, Storage &
  volumes, Networks & ingress, Firewall & security and Limits & quotas, next to the existing
  Overview, Canvas, Apps, Environments, DNS & Domains and Backups.
- The page reads the caller's project role from the workspace endpoint: the role is shown, and
  Edit, Delete, Add/Remove app and every component's change controls follow its abilities. Without
  a project role (organisation members with no row) only the organisation-level views show. The
  server enforces all of it regardless (slices B–G).

## [1.24.0] — 2026-11-19

### Added

- **Deploy jobs in the project workspace** (Project workspace slice G). `GET projects/{id}/deploy-jobs`
  (admin and `/app`): the project's deployments with job progress, stages, the failed stage and
  its error, the log tail, and the releases available to roll back to.
  `POST .../deploy-jobs/{deployment}/retry` re-runs the newest failed deploy of an app (an older
  failure cannot be retried over newer work; a failed rollback retries the same release) and
  `POST .../deploy-jobs/rollback` deploys an earlier release, both through the existing
  `DeploymentService` pipeline. Developers may retry and roll back; viewers only watch. Audited.
- Catalogue installs opened from a project (`/admin/catalog?project=<id>`) go into that project:
  the wizard sends `project_id`, shows the project on the review step, and the template install
  accepts it (project role and quotas apply).
- Workspace Applications → Deployments uses the shared `ProjectDeployJobs` component.

## [1.23.0] — 2026-11-19

### Added

- **Project networks & ingress routing** (Project workspace slice F). `GET projects/{id}/networking`
  (admin and `/app`): the project's environment networks (the per-project Docker networks, with
  ingress attachment), each app's node and network with what is missing, and each domain's
  routing — through the central ingress (project DNS records, so moving an app between nodes
  needs no DNS change) or pinned to a node's IP (legacy A records). `POST .../networking/reconcile`
  (owner) runs the existing `networks:reconcile` logic for this project now (cross-organisation
  servers resolved unscoped), audited.
- Workspace Docker → Networks shows it (shared `ProjectNetworking` component).

### Decisions for the owner (not changed)

- Legacy domains still expect an A record at the **app's node IP** (`DomainService::ingressIp`).
  Switching them to the central ingress needs every customer's DNS changed and the ingress to
  route to other nodes, so it is reported, not changed.
- Containers are not taken off the shared `proxy` network (hand-written routers depend on it).

## [1.22.0] — 2026-11-19

### Added

- **Project firewall** (Project workspace slice E). `projects/{id}/firewall` (admin and `/app`):
  project allow/block lists (with expiry and a guard against blocking your own address), project
  rate limits (block / throttle / log), and lock/unlock of the project's apps. Stored as the
  existing project-scoped `firewall_ip_lists` / `firewall_rules` rows and rendered by the existing
  Traefik file-provider path, so they only add middlewares or `-blocked` / path routers to the
  panel's own `dmp-` routers for this project's domains. Hand-written routers and other tenants'
  routers are never touched (covered by a test). Every change is audited.
- Firewall scope now includes a domain's own `project_id` (project DNS domains), not only its app's.
- Workspace Firewall & security section (shared `ProjectFirewall` component).

### Not included (owner decision)

- CrowdSec "blocked IPs" (decisions) stay server-wide on the admin Firewall page: they are not
  attributable to one project, and showing them per project would expose other tenants' traffic.
- The block list renders only when `platform.traefik.forwarded_depth` is 0 (direct clients), as
  before; behind a CDN it is left to CrowdSec.

## [1.21.0] — 2026-11-19

### Added

- **Project quotas** (Project workspace slice D). `projects.quotas` (JSON; null = no limit, so
  existing projects are unchanged): project-wide `cpu`, `ram_mb`, `storage_gb`, `apps`,
  `containers`, `replicas`, and per-container `container_cpu`, `container_ram_mb`.
- `ProjectQuotaService` checks them when an app is created in or assigned to a project, on every
  deploy, on scale-up, and on project database / volume create; a refusal is `402 quota_exceeded`
  naming the limit, the requested value and the maximum. Only growth is refused, so lowering a
  quota below current usage never blocks a redeploy of what already runs.
- `GET/PUT projects/{id}/quotas` (admin and `/app`): usage vs limit for everyone with a project
  role; only platform administrators change limits (owners work within them); changes audited.
- Workspace dashboard and Settings show usage vs quota (shared `ProjectQuotas` component).

## [1.20.0] — 2026-11-19

### Added

- **Project databases and Storage & volumes** (Project workspace slice C). `databases.project_id`
  and `volumes.project_id`, backfilled where a link already exists (a database through its
  container's project or app; a volume when every container mounting it is in one project).
- `GET/POST projects/{id}/databases`, attach/detach, and `GET projects/{id}/databases/{db}/credentials`
  (owner only, audited as `project.database.credentials`); `GET/POST projects/{id}/volumes`,
  attach/detach. Same endpoints on admin and `/app`. Lists show connection details with the
  password masked; everything is looked up inside the project's own organisation.
- Provisioning is limited to nodes the project already runs on (staff may also use the
  organisation's servers); the database's container is tagged with the project.
- Workspace Databases and Storage & volumes sections use a shared `ProjectResources` component
  (the customer workspace reuses it).

### Note

- New databases still join the node's shared `internal` network; per-project networks are slice F.

## [1.19.0] — 2026-11-19

### Added

- **Project roles enforced on every existing endpoint** (Project workspace slice B). A new
  `project.role` middleware on the authenticated API group resolves the project behind any
  app, container, deployment, environment, project or backup route (admin and `/app`), and for
  creates the `project_id` / `application_id` in the body, then checks the caller's project role
  after the organisation RBAC: viewer is read only; developer may deploy, redeploy, restart,
  roll back, cancel, run container actions, read logs and change the permitted app settings
  (`health_path`, `auto_restart`, `restart`, non-secret env vars); owner may do everything inside
  the project. Deletes need `delete`, member/transfer/DNS-permission routes need `members`.
- A developer's env change can neither create a secret, change one's value, nor drop one (the env
  list is a full replace, so omitting a secret would delete it).
- Every refusal is audited as `project.access.denied` with the route, ability and role.
- New `settings` ability on owner, developer and platform admin.

### Decision recorded

- Organisation members **without** a `project_members` row keep the organisation RBAC they had
  before (nobody loses access on upgrade); an owner restricts someone by giving them a project role.
  The workspace page itself still requires a role, as in slice A. Changing that default is the owner's call.

## [1.18.0] — 2026-11-19

(Number may need renumbering: 1.17.0 is reserved for Project DNS slice 2.)

### Added

- **Projects list** (admin Deploy → Projects). One compact row per project on the shared
  DataTable (search, filters, sort, list/grid layouts, saved views): name, status
  (running/degraded/stopped with running/total), environments, apps, last deploy, owner, and row
  actions (Open, Show canvas, Settings, Hand over to customer). "New project" and the empty state kept.
- **Project workspace** at `/admin/projects/:id` (slice A). Breadcrumb Projects / name, health and
  role badges, Add application, Project settings, Show canvas and Hand over. Sections, each the
  existing capability scoped by project id: Dashboard (Applications / Containers / Active nodes /
  Domains tiles plus the existing project dashboard), Applications (Installed, Deployments,
  Catalog, GitHub integration), Environments (the old cards: Edit, Duplicate, Promote, Wake),
  Docker (Services & containers, Nodes & replicas, Networks), DNS & domains (domain list plus the
  existing ProjectDns UI, mounted unchanged), Backups & restore, Environment variables (keys only),
  Monitoring & logs (audit activity), Team & permissions, Canvas, Settings (webhook, PR previews,
  ownership, delete). Databases, Storage, Firewall and Quotas show "not yet available" states.
  Section and sub-page are in the URL (`?s=…&sub=…`); old `?tab=` links still resolve.
- **Project roles** (`project_members`): owner, developer, viewer, plus platform admin for
  super_admin / infra_admin / devops and owner for the owning organisation's owner. Enforced on the
  server for `GET projects/{id}/workspace` and `PUT|PATCH|DELETE projects/{id}/members/{user}`
  (admin and `/app` routes); member changes are audited.
- `organization` (id, name) on admin project responses.

### Changed

- Deleting a project is blocked while it has applications **or backups**, with the reason named;
  deletions are audited.
## [1.17.0] — 2026-11-19

### Added

- **Project DNS & Domains, slice 2 (PDNS-2).**
  - **Your own Cloudflare account** in the project's DNS provider page: a project owner, or a member
    granted the new *Connect a DNS provider* permission, pastes a Cloudflare API token. It is checked
    with Cloudflare's token-verify endpoint and the zone list before anything is stored, kept
    encrypted on the organisation's account (`dns_providers.source = customer`) and never returned.
    The customer picks the zone that holds the domain (only zones the token can see, and only one
    that contains the domain), sees the zone's records for that domain marked *Managed* or *Yours —
    kept*, and can disconnect: the panel forgets the token and its copy of the zones, and nothing is
    deleted at Cloudflare.
  - **Stricter "never overwrite"**: before writing, the zone is re-read from the provider, and a
    routing A/AAAA record is refused when the name already has an address record of its own (an
    existing website), alongside slice 1's CNAME and same-name refusals. MX, SPF, DKIM, DMARC and
    other records are only ever read.
  - **Apex vs subdomain from the Public Suffix List** (`jeremykendall/php-domain-parser`, ICANN
    section, list bundled in `api/resources/psl`, refreshed monthly by `dns:refresh-psl` into
    storage): `example.co.uk` is an apex (A/AAAA to the ingress addresses), `erp.example.co.uk` a
    subdomain (CNAME to the ingress host name). The API now says which (`subdomain`,
    `registrable_domain`); the SPA shows it and never guesses.
  - **State-change notifications**: `domains:check-dns` also re-checks live project domains every 30
    minutes, and e-mails (`domain_state_changed` template) the organisation owners and every member
    holding a DNS grant on the project when ownership is verified, DNS verifies or breaks again, or a
    certificate is issued or HTTPS breaks again. The last notified state is kept on the domain
    (`domains.notified_state`), so an unchanged state never notifies twice; each change is also in
    the project's change history.
  - **Staff view**: Hosting → *Project domains* lists every customer's project domains with
    customer, project, app, apex/subdomain, status, DNS, HTTPS and last check, filters, a link to the
    customer, and *Validate* (audited). New permission `project_domains.manage` (super admin, infra
    admin, DevOps, support); the migration re-runs the reference seeder so it takes effect.
  - **Revoke all** on the project's DNS permissions: one click, with confirmation, takes every DNS
    permission from a member (viewing stays) and writes `domain.permissions_revoked` to the audit log.

### Changed

- A project domain no longer sends the old *Domain connected* e-mail; the new notifications cover it.

## [1.16.0] — 2026-11-19

### Added

- **Project → DNS & Domains** (PDNS, first slice). A new project tab with sub-pages: Connected
  domains (counts, primary flag, verification and SSL status, Manage / Make primary / Remove),
  Add domain (wizard: own domain or Livezen Cloud subdomain, target app in the project, manual
  records or automatic through a connected DNS provider zone, then the exact records), DNS records,
  DNS validation, DNS provider, SSL certificates, Domain routing, Change history and Permissions.
- **Validate DNS** runs every check on the server, live, and reports each separately with expected
  and found values: ownership TXT, A/AAAA/CNAME resolution, resolved address vs the cluster ingress
  (and a clear message when it points at the node running the app), CNAME target, the ingress
  endpoint itself, propagation across Cloudflare, Google and Quad9 (DNS-over-HTTPS), certificate
  validity / names / expiry, HTTPS routing and the app's health. Each run is kept (`dns_checks.kind
  = validation`).
- **Ownership verification**: a custom domain added through a project carries a token proven by
  `_livezen-verify.<domain> TXT livezen-verify=<token>`; DNS is not verified and no certificate is
  issued until it is. The existing 2-minute `domains:check-dns` re-check detects when the customer
  has finished.
- **Ingress endpoint setting** `PLATFORM_INGRESS_HOSTNAME` / `PLATFORM_INGRESS_IPS` /
  `PLATFORM_INGRESS_IPV6`: project domains point at the cluster ingress (CNAME for subdomains,
  A/AAAA for apex), never the Docker node, so a Swarm move never changes customer DNS.
- **Per-project DNS permissions** the organisation owner grants: view, add + verify, update records,
  change primary, manage SSL, remove. Removing the active primary domain needs its name typed back.
  Every change is written to the audit log and shown in Change history.

### Changed

- Zone publishing (DNS-013) now also refuses a CNAME where any other record exists at that name,
  and any record beside an existing CNAME, so it can never replace a customer's website record.
  Project domains set to manual DNS are never written to a zone.
- Domains created before PDNS keep their records and behaviour unchanged.

## [1.15.0] — 2026-11-04

### Added

- **Partner provisioning API for WorkSuite Cloud** (`/partner/v1`,
  [docs/PARTNER_API.md](docs/PARTNER_API.md), #179) — worksuitecloud.com deploys
  WorkSuite Business and WorkSuite CMS here for its customers and manages them
  (start, stop, restart, redeploy, upgrade, backup, restore, resize, domains,
  logs, suspend, resume, terminate) without anybody signing in to the panel.
  - Scoped partner credentials (`php artisan partner:client`), HMAC-signed
    requests with timestamp and single-use nonce, mandatory `Idempotency-Key`
    on every POST, per-scope route checks, optional IP pinning.
  - Each partner customer gets its own organisation owned by a service user
    with the plain customer `owner` role — no panel login, no staff
    permissions. The hidden `partner` plan carries the limits; Cloud's
    subscription entitlements are the real ones.
  - `worksuite-business` and `worksuite-cms` app templates (partner only:
    hidden from the customer template picker and refused by `/app/apps`).
  - Signed status webhooks back to the partner from an outbox
    (`partner:sync`, `partner:webhooks`, both every minute), retried with
    backoff and parked after 12 attempts.
  - **Hosting → Partner provisioning** (`/admin/partners`, platform staff):
    deployments by state, the partner keys (enable/disable, webhook URL — never
    their secrets), recent and failed deployments, and webhook delivery
    failures. Backed by `GET /api/v1/partner-overview` and
    `PATCH /api/v1/partner-clients/{id}`.

### Fixed

- **A deployment on a shared-pool server in another organisation crashed at
  the health check.** `AgentCommand::server()` was tenant-scoped, so when the
  agent reported `container.create` for an app whose organisation does not own
  the server, the server resolved to null and dispatching
  `container.healthcheck` threw a TypeError (500 to the agent); the job stayed
  at "health check" forever. It is now unscoped like `Container::server()`.
  Any customer app placed on a shared-pool server was affected. (#179)
- **A customer projects test failed at random.** It checked that `8080` never
  appears in the canvas JSON, which a random UUID can contain; it now checks a
  marker that cannot occur in a UUID (#179).

## [1.14.0] — 2026-11-04

### Added

- `scripts/setup-relay.sh` sets up the terminal relay on the panel server in
  one command (`sudo scripts/setup-relay.sh`, plus `--ssh-port 2222` for SSH
  access). Running it again is safe: it keeps an existing secret and changes
  only what is missing. `--check` reports what is set up without changing
  anything. (#178)
  - It puts one shared `RELAY_SECRET` in the API's `.env` and in
    `/etc/dockermgr/relay.env` (mode 0600), and never shows it.
  - It builds and installs the `dockermgr-relay` binary (amd64 or arm64).
  - It installs and starts the systemd service.
  - It writes the nginx upstream and an include snippet for
    `/relay/terminal/`, then tells you the one `include` line to add to the
    panel's server block.
  - It rebuilds the API's config cache and checks that the relay answers.
- The agent build now also produces an arm64 relay binary. (#178)

### Changed

- The terminal's "relay not set up" and "relay not reachable" messages now
  point to `scripts/setup-relay.sh`. (#178)

## [1.13.2] — 2026-11-04

### Changed

- Containers list tidied up. (#177)
  - Names show their app, or their Compose stack and service, underneath.
  - Images given as a digest are shortened to `sha256:39d0d31dde66` or
    `repo@4c9b34c113e6`; the full image shows on hover.
  - State and health share one Status column: a "✓ healthy" mark, an
    unhealthy badge, and nothing when there is no health check. The separate
    Health column can still be turned on under Columns.
  - Ports show as one short tag, such as `18081→18081` or `local 8080→80`,
    with "+2" for more. IPv4/IPv6 duplicates are dropped and the full list
    shows on hover.
  - CPU and memory have small usage bars (memory against its limit when one
    is set). Stopped containers show "—" instead of "0% · 0 MB".

## [1.13.1] — 2026-11-04

### Changed

- Container side panel tidied up. (#176)
  - The actions fit on one row: Stop/Start, Restart, Pause and Terminal, with
    Duplicate and Remove under a "More" (⋯) menu.
  - The details show as a grid. Limits says "No limit" instead of
    "— vCPU · —", User says "Image default" when the image sets none, ports
    show as chips, and uptime appears next to the status.
- Live logs, in the container panel and everywhere else they appear: (#176)
  - Each line's timestamp is shown short and dimmed (the full time on hover),
    so messages get the width.
  - Long URLs wrap without splitting ordinary words.
  - Errors and warnings are highlighted.
  - The view stops following new lines while you scroll back to read, and
    "Jump to latest" brings it back.
  - The toolbar is compact: a Live/Paused indicator, a filter box, and pause
    and download icons.
  - In the container panel, the log fills the rest of the panel's height.

## [1.13.0] — 2026-11-04

### Added

- **SSH access with PuTTY or any SSH client.** Staff and customers can add or
  generate SSH keys under Account → SSH keys, then open a shell in their apps
  and containers. (#175)
  - Connect with `ssh -p 2222 <container-or-app>@<panel host>`. Use the user
    name `list` to see what you can open.
  - Each login is an ordinary terminal session: the same permissions and
    second-person approvals as the web terminal, a non-root user, the session
    time limit, an audit entry with the client's address, and a recording.
  - A generated key is downloaded once in OpenSSH format, and only the public
    half is kept. The card explains how to turn it into a PuTTY .ppk.
  - Only interactive shells are offered: no exec, scp, sftp or port
    forwarding.
- The terminal relay serves the SSH gateway when `RELAY_SSH_LISTEN` is set
  (for example `:2222`). Its host key is created on first start and kept in
  the state directory. Switch the feature on in the API with
  `SSH_GATEWAY_ENABLED=true`. `SSH_GATEWAY_HOST` and `SSH_GATEWAY_PORT` set
  what the panel shows. (#175)

### Changed

- Building the agent and relay now needs Go 1.26 or newer, because the SSH
  library (`golang.org/x/crypto` v0.58.0) requires it. That library version
  also has the fixes for all of its known SSH server vulnerabilities. (#175)

## [1.12.0] — 2026-11-04

### Added

- Applications → **Git & auto-deploy** tab: set an app up to deploy from Git
  on its own, in four steps. (#174)
  - Enter the repository and branch.
  - Generate a deploy key for the app, then copy the public key into the Git
    host. The tab says where, for GitHub, GitLab and Bitbucket.
  - Test the connection. It reads the branch with that key, as the build will.
  - Copy the webhook URL and secret into the repository. A new secret can be
    shown once.
  The tab lists recent webhook deliveries. A "Deploy on push" switch turns
  automatic deploys on or off. Saving a repository on an app that ran a fixed
  image makes it build from the repository.

### Changed

- Pushes still deploy staff apps by default, but the new "Deploy on push"
  switch can now turn that off per app. (#174)
- The repository check accepts `ssh://` addresses, and with a deploy key it
  reports when the repository refused the key. (#174)

## [1.11.2] — 2026-11-04

### Fixed

- Containers: search now also finds a container by its app, its Compose stack
  or service, its server, its container ID or a port. Before, it matched only
  the container's own name and image, so searching for an app or stack found
  nothing when Docker or Compose had named the container. (#173)
- Containers: the list loads up to 200 containers instead of 100, so search
  and filters see all of them on larger servers. (#173)
- Container terminal: opening one now first checks that the terminal relay
  is set up (`RELAY_SECRET`) and running (`RELAY_HEALTH_URL`, by default
  `http://127.0.0.1:8090/relay/healthz`). If not, it says which, and how to
  fix it. Before, the browser only reported that the connection failed.
  (#173)
- Container terminal: when the browser cannot open the connection, the
  message names the address it tried and explains that the web server must
  forward `/relay/` to the relay as a WebSocket. A connection that drops
  later points at the agent. (#173)
- Container terminal: images without the configured shell user (`app`), such
  as WordPress and most databases, now open a shell instead of failing. The
  agent tries the container's own non-root user, then `www-data`, `node` and
  `nobody`, and says which one it used. It never uses root. (#173)

## [1.11.1] — 2026-11-04

### Fixed

- Adding a passkey failed with "The given data was invalid" on panels
  served from their own domain. The server accepted passkeys only from the
  addresses in `WEBAUTHN_ORIGINS` or `CORS_ALLOWED_ORIGINS`, which default to
  the localhost development server. The panel's own address (`APP_URL`, or
  `PANEL_URL` when set) is now always allowed, for adding passkeys and for
  signing in with them. (#172)
- When a passkey is rejected, the message now says why instead of "The given
  data was invalid", and the reason is written to the log. (#172)

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
