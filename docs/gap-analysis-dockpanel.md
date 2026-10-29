# Gap analysis — WorkSuite CloudPanel vs DockPanel

Compared 30 Oct 2026 against `github.com/ovexro/dockpanel` (Rust/Axum + React
19, PostgreSQL 16, agent + API + CLI + MCP binaries).

**Read this first.** The two panels are not the same kind of product, so not
every gap is a defect:

| | DockPanel | WorkSuite CloudPanel |
| --- | --- | --- |
| Shape | single-server Linux panel, services **native** on the host | **multi-tenant, Docker-first**, agent per server |
| Tenancy | admin → reseller → user | organisations with RBAC, packages, subscriptions, invoices |
| Sites | Nginx vhosts, PHP-FPM pools, PHP 8.1–8.5 | a container per site, Traefik routing |
| Reach | one box (plus remote servers) | a fleet, from one control plane |

Our multi-tenancy, approvals, verifiable audit log and billing are **ahead** of
DockPanel. The gaps below are where it is ahead of us.

---

## Tier 1 — gaps that cost you customers today

These are the ones a hosting customer notices and asks about.

### 1. No mail server
DockPanel: Postfix, Dovecot, OpenDKIM, Roundcube webmail, Rspamd, SMTP relay.
Us: **nothing.** We send mail; we cannot host it.
A hosting customer who wants `you@theirdomain.com` cannot be served at all.
This is the single largest functional hole for a shared-hosting business.

### 2. No file manager
DockPanel: a file manager in the panel.
Us: **nothing.** Files are reachable only through the container terminal.
Non-technical customers cannot edit a file, fix a permission or upload a theme
without us. Every such request becomes a support ticket.

### 3. No DNS zone management
DockPanel: Cloudflare and PowerDNS, zone templates, propagation checks,
DNSSEC, cache purge.
Us: DNS **checks** only (`domains:check-dns`, `DnsCheck`). We read DNS; we
never write it. Customers edit records at their registrar, which is where
most domain-attachment failures come from.

### 4. No migration path in
DockPanel: cPanel and HestiaCP wizards, Plesk in beta.
Us: **nothing** — every migration this session was done by hand over SSH, and
one of them (`seliknaent`) lost a day of transactions.
This directly blocks winning customers off other hosts.

### 5. WordPress toolkit
DockPanel: multi-site WordPress management with vulnerability scanning.
Us: WordPress is one catalogue entry. No bulk updates, no vulnerability
scanning, no per-site WP operations.
Most of the sites on this server are WordPress.

---

## Tier 2 — security and operations we claim but have not finished

### 6. WAF
DockPanel: ModSecurity3 + OWASP CRS v4 per site, CSP headers, bot rate limits.
Us: rate limiting only. Country blocking and challenges are built but render
nothing (see `docs/status-and-remaining-work.md`).
Note the brute-force traffic already hitting `dazzlingtours.com/wp-login.php`.

### 7. Host firewall and intrusion prevention in the panel
DockPanel: UFW management, Fail2Ban, one-click SSH hardening.
Us: fail2ban and SSH hardening were configured **by hand** on the host and are
invisible to the panel. Nothing shows a ban, and nothing manages rules.

### 8. Status page and incident lifecycle
DockPanel: incident lifecycle plus a public status page at `/status`.
Us: alerts open and close; no public page, no customer-visible incident view.
(The customer-scoped incident view is in progress.)

### 9. Grafana
DockPanel: Prometheus endpoint plus a Grafana dashboard.
Us: the Prometheus endpoint exists (`PrometheusMetricsTest`), no Grafana.
This is why the Monitoring artboard's "Open Grafana" was skipped.

### 10. CVE scanning depth
DockPanel: grype per image, optional deploy gating, syft SBOMs.
Us: Trivy scanning and `block_critical_cves` exist. **No SBOM.**
Closest to parity of anything in this tier.

---

## Tier 3 — product breadth

| Feature | DockPanel | Us |
| --- | --- | --- |
| App templates | 147 in 14 categories | ~5 (WordPress, Laravel, Ghost, n8n, Nextcloud) |
| CMS one-click | WP, Laravel, Drupal, Joomla, Symfony, CodeIgniter | WordPress, Laravel |
| Native PHP versions | 8.1–8.5 with FPM pools | container images only |
| SQL browser | yes, with schema viewer | no — databases are managed, not browsable |
| CDN | BunnyCDN + Cloudflare | no |
| Image optimisation | server-side WebP/AVIF | no |
| Redis object cache / FastCGI cache | per site | no |
| Git deploy | push-to-deploy, Nixpacks, **preview environments** | push-to-deploy, no Nixpacks, no preview envs |
| Container auto-sleep | idle containers sleep | no |
| Update detection | yes | no |
| GPU passthrough | yes | no |
| Backup destinations | S3, SFTP, B2, GCS, AES-256 | S3/B2/R2/gdrive/local |
| Themes / layouts | 6 themes, 3 layouts | light + dark, 1 layout |

## Tier 4 — business and platform

| Feature | DockPanel | Us |
| --- | --- | --- |
| Reseller tier | admin → reseller → user | organisations only; **no reseller layer** |
| White-labelling | yes | no |
| WHMCS hooks | provision / suspend / terminate | no |
| OAuth / SSO | Google, GitHub, GitLab | no (passkeys + TOTP instead) |
| CLI | `status sites apps diagnose export apply` | no |
| MCP server | ~40 read-only tools | no |
| Infrastructure as code | YAML export / apply | no |
| Extension API | HMAC-signed webhook events | webhooks exist, no extension API |
| Command palette | Ctrl+K | **yes** (`CommandPalette.tsx`) |
| Passkeys / MFA | WebAuthn + TOTP | **yes, both** |

---

## Where we are ahead

Worth stating, because these are expensive to build and DockPanel does not
have them:

- **True multi-tenancy** — every model is organisation-scoped by a global
  scope, with cross-tenant isolation covered by tests
- **Packages, subscriptions, usage records, invoices with PDFs, Stripe** — a
  billing system, not a quota field
- **Approvals** for destructive and production actions, with a second approver
- **Verifiable audit log** (`audit-logs/verify`)
- **Agent-based fleet control** with mTLS, enrolment tokens and signed
  commands, rather than SSH
- **Terminal session recording** tied to the audit trail
- **Deployment pipeline** with releases, rollback, blue-green and health gates

---

## Suggested order

1. **File manager** — smallest build, highest daily support relief
2. **DNS zone management (Cloudflare first)** — removes the commonest failure
   in attaching a domain
3. **Migration wizard (cPanel)** — directly wins customers, and would have
   prevented the `seliknaent` data loss
4. **WAF** — finishes the Firewall page, which currently enforces only rate
   limits
5. **Mail** — the biggest hole, but also the biggest build and the one with
   real deliverability risk; do it deliberately, not next

Tiers 3 and 4 are breadth. The app catalogue (5 → 147) is the cheapest visible
win there, since it is mostly Compose templates.
