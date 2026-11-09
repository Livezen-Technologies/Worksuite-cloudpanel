# Security audit record — WorkSuite Cloud Panel

Follows the "Security Audit & Penetration Testing Plan — Universal Template" (OWASP ASVS 5.0,
OWASP Top 10 / API Top 10, NIST SP 800-115). This is the **first pass**: automated scans, a
runtime test suite against a real stack, and code review. It is not an independent penetration
test, and it does not claim the system is free of vulnerabilities (see Limitations).

## 0. Project profile

| Field | Value |
|---|---|
| Project | WorkSuite Cloud Panel (`api/` Laravel 12 + PostgreSQL, `agent/` Go, `web/` React 19 + Tailwind v4) |
| Version | `main` at 2026-10-31 (after #80) |
| Type | SaaS / hosting & infrastructure control panel, with a customer portal |
| Tenancy | Multi-tenant (organisations) |
| Hosting | Customer VPS / dedicated servers via an outbound agent; Docker containers; Traefik |
| Payments | Yes (Stripe; card data never reaches the panel) |
| Personal data | Yes: names, emails, invoices, mailbox contents, server credentials |
| Compliance | PDPA (Sri Lanka); GDPR if EU customers |
| Risk tier | **High** (multi-tenant, payments, infrastructure platform): all phases apply |
| Audit window | 2026-10-31 (first pass) |

## Rules of engagement

| Item | Status |
|---|---|
| Written authorisation | Owner request in the working session (repository owner) — **record a signed copy** |
| In scope | This repository; a local stack (PostgreSQL, API, web, relay, real Go agent on local Docker) |
| Out of scope | Production hosts, customer servers, third-party services (Stripe, Cloudflare, DNS providers) |
| Environment | Isolated local stack, seeded test data. **No production testing was done.** |
| Test accounts | Seeded: super admin, owner, developer, viewer, billing |
| Destructive tests | None against anything but the throwaway stack |

## Methods and tools used

| Check | Tool | Where it runs |
|---|---|---|
| Secret scan, full git history (167 commits) | gitleaks 8.21 | local + CI (`security` job) |
| PHP dependencies | `composer audit` | local + CI |
| Web dependencies | `npm audit` | local + CI |
| Go dependencies | govulncheck 1.1.4 | CI only (vuln.go.dev is blocked in the audit environment) |
| SAST | Semgrep: local rules `.semgrep/rules.yml` (blocking) + `p/owasp-top-ten` (CI, report-only until triaged) | local + CI |
| Runtime security tests | `e2e/security-audit.js` (41 checks) | e2e stack (`e2e/run.sh`) |
| Tenant isolation | `e2e/api-checks.js` (34 checks: org B vs org A on every resource type, audit-chain tampering) | e2e stack |
| Agent / container hardening | `e2e/agent.sh` (41 checks on real Docker) | e2e stack |
| Auth & headers regression | `api/tests/Feature/SecurityHeadersTest.php`, existing PHPUnit suite (293 tests) | CI |
| UI / accessibility | `e2e/browser/ui-audit.js` (200 page views) | e2e stack |

## Results by phase

Status: 🟢 passed · 🔴 issues found (see findings) · 🟡 partly checked · ⬜ not checked · ➖ N/A

| Phase | Status | Evidence |
|---|---|---|
| 1 Asset discovery & threat model | 🟡 | Assets: API, web, agent, relay, Traefik, PostgreSQL, Redis-less queue (database), mail stack, backups (restic). Roles from `config/rbac.php`. No STRIDE document yet. |
| 2 Authentication & sessions | 🟡 | bcrypt hashing; min 8 chars; login limited 10/min per email+IP and 30/min per IP (429 verified); unknown email and wrong password answer identically; password reset does not reveal accounts and revokes all tokens; logout revokes the token server-side (verified); TOTP + passkeys available; session tokens expire after 30 days, API tokens after 90 days by default. Open: SEC-005, SEC-006, SEC-007, SEC-008. |
| 3 Authorization & tenancy | 🟢 | Permissions enforced in route middleware (`perm:`), default-deny groups. Role × endpoint matrix (12 cases for viewer, developer, billing) passes. Tenant isolation: global `BelongsToOrganization` scope; 34 cross-tenant checks pass; audit log hash chain detects a tampered row. |
| 4 Application & API | 🔴→🟢 | SEC-002 fixed (forged token caused a 500). Injection probes in search/sort inert; `per_page` capped at 200; mass assignment of role/organisation through profile updates rejected; CORS does not reflect foreign origins; agent heartbeat and git webhooks reject missing/forged credentials. Semgrep: no SQL built from variables, no shell/eval/unserialize in PHP. |
| 5 Payments | 🟡 | Stripe hosted payment; invoices paid via signed webhook (existing PHPUnit tests use webhook fixtures). Not re-tested in this pass. |
| 6 Frontend & headers | 🔴→🟡 | SEC-003 fixed for the API (headers middleware). The web app's own host (SPA) must add CSP/HSTS too: SEC-009. No source maps in the production build; no secrets in the bundle (gitleaks). |
| 7 Server, network & TLS | 🟡 | `.env`, `.git`, logs, `composer.json`, `phpinfo` not served by the API. Agent link: HMAC-signed commands, replay rejected, optional mTLS. SSH lock to the private network (PNET). TLS grade and host hardening need the real hosts (out of scope). |
| 8 Containers | 🟢 | Verified on real Docker: CapDrop ALL, no-new-privileges, never privileged, non-root users for scheduled commands, PID/memory limits, managed label; Compose validator blocks privileged mode, host networking, host bind mounts outside `/srv/dockermgr`, and extra capabilities. Images scanned with Trivy in the build pipeline. |
| 9 Data & storage | 🟢 | Secrets and MFA secrets encrypted at rest (Laravel `encrypted` casts); backups encrypted by restic with a separate recovery key; restore tests run on schedule and now start the data in a sandbox (BKM-009). DB is not exposed by the panel. |
| 10 Secrets & supply chain | 🔴→🟢 | SEC-001 fixed (CVE in symfony/mime). gitleaks: only 5 test fixtures (allow-listed by fingerprint in `.gitleaksignore`). Lock files committed. CI scans on every PR. Branch protection and 2FA on GitHub accounts: **owner to confirm** (not visible from the repository). |
| 11 Mobile | ➖ | No mobile app. |
| 12 IoT | ➖ | No devices. |
| 13 Logging & incident response | 🟡 | Tamper-evident audit log (hash chain, verified); admin actions audited; alerts on failed jobs, tunnels, mail, backups. No written incident-response runbook yet. |
| 14 Availability & abuse | 🟡 | Rate limits on auth and selected endpoints; heavy work queued; request size limits on uploads. No CAPTCHA on public signup. |
| 15 CI/CD automation | 🟢 | New `security` job in `.github/workflows/ci.yml`; e2e suite includes `security-audit.js`. |

## Findings register

| ID | Title | Severity | Area | Status |
|---|---|---|---|---|
| SEC-001 | symfony/mime CVE-2026-45070 (email header injection) | Medium | Dependencies | **Fixed** (7.4.19 → 7.4.22) |
| SEC-002 | A malformed bearer token caused a 500 (and, with debug on, an SQL error in the response) | Medium | Authentication | **Fixed** + regression test |
| SEC-003 | No security headers on API responses | Medium | Headers | **Fixed** (API) + test |
| SEC-004 | `.env.example` shipped `APP_DEBUG=true` | Low | Configuration | **Fixed** |
| SEC-005 | MFA is optional, also for admins and owners | Medium | Authentication | Open — owner decision |
| SEC-006 | No breached-password check; admins have the same 8-character minimum | Low | Authentication | Open |
| SEC-007 | Session tokens last 30 days with no idle timeout | Low | Sessions | Open |
| SEC-008 | The web app keeps its bearer token in `localStorage` | Low | Frontend | Open (accepted for an SPA if SEC-009 is done) |
| SEC-009 | The SPA host sets no CSP / HSTS / frame protection | Medium | Headers | Open — needs the web host's config |
| SEC-010 | Customer portal showed admin-only header links (`/admin/settings`, `/admin/catalog`) | Low | Authorization (UI) | **Fixed** (the API already refused the pages) |
| SEC-011 | `Template` model accepted any attribute (`$guarded = []`) | Info | Mass assignment | **Fixed** (explicit `$fillable`; only internal writes existed) |

### Reviewed and accepted (no change)

| Location | Rule | Why it is safe |
|---|---|---|
| `agent/internal/executor/cron.go` `sh -c` | command execution | Host-mode scheduled commands are the feature; the API allows host mode to platform admins only. |
| `api/resources/views/invoices/pdf.blade.php` `{!! nl2br(e(...)) !!}` | unescaped output | The value is escaped by `e()` first; only `<br>` is raw. |
| `web/src/components/FileEditor.tsx` `dangerouslySetInnerHTML` | XSS | `highlight()` escapes `&`, `<`, `>` in every segment; class names come from a fixed list. |
| gitleaks: 5 hits in `api/tests` | secrets | Public WireGuard keys, a placeholder SSH key (`AAAA`), a test-only relay secret. |

### Details of fixed findings

**SEC-002 — malformed token → 500.** `Authorization: Bearer 1|anything` made Sanctum look the token
up by id `1`; ids are UUIDs, so PostgreSQL raised `invalid input syntax for type uuid` and the API
answered 500 (with `APP_DEBUG=true`, including the SQL). Anyone could trigger server errors and log
noise. Fix: `AccessToken::findToken()` treats a non-UUID id as no token → 401. Test:
`SecurityHeadersTest::test_a_malformed_token_is_a_401_not_a_server_error`; runtime check
`auth: forged token -> 401`.

**SEC-003 — headers.** `App\Http\Middleware\SecurityHeaders` adds `X-Content-Type-Options: nosniff`,
`Referrer-Policy`, `Permissions-Policy`, `X-Frame-Options` (DENY for JSON, SAMEORIGIN for the HTML the
API serves), `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'` on JSON, HSTS on
HTTPS, and removes `X-Powered-By` (also PHP's own header).

## Recommended next steps

1. **SEC-009**: on the host that serves the web app, add (nginx example):
   ```nginx
   add_header Content-Security-Policy "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; connect-src 'self' wss:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'" always;
   add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
   add_header X-Content-Type-Options nosniff always;
   add_header Referrer-Policy strict-origin-when-cross-origin always;
   ```
2. **SEC-005**: require MFA for super admins, infrastructure admins and organisation owners.
3. **SEC-006 / SEC-007**: `Password::min(12)->uncompromised()` for admins; a 12–24 h idle timeout on session tokens.
4. Write the STRIDE model and the incident-response runbook (phases 1, 13).
5. Owner to confirm branch protection, required reviews and 2FA on GitHub and the registry (phase 10).
6. Before release: an independent penetration test of a staging environment, plus TLS (testssl.sh) and host (Lynis) checks on the real servers.

## Limitations

- Automated scans, a scripted runtime suite and code review only; no manual penetration testing.
- Run against a local stack with `APP_DEBUG=true` (the "no stack trace in errors" check is skipped there; `.env.example` now ships `false`).
- govulncheck and the Semgrep registry rules could not run in the audit environment (blocked network); they run in CI.
- Production TLS, DNS, host hardening, cloud IAM and GitHub organisation settings were not visible and were not checked.
- Not every API endpoint is in the role matrix; it covers the high-impact ones (servers, users, settings, audit logs).

## Audit progress tracker

| Area | Status | Date | Findings |
|---|---|---|---|
| Asset discovery & threat model | 🟡 In progress | 2026-10-31 | — |
| Authentication & sessions | 🔴 Issues found | 2026-10-31 | SEC-002 (fixed), 005–007 |
| Authorization & roles | 🟢 Passed | 2026-10-31 | SEC-010 (fixed) |
| Tenant isolation | 🟢 Passed | 2026-10-31 | — |
| Application & API | 🟢 Passed after fixes | 2026-10-31 | SEC-002, SEC-011 (fixed) |
| Payments | 🟡 Not re-tested | — | — |
| Frontend & headers | 🔴 Issues found | 2026-10-31 | SEC-003 (fixed), 008, 009 |
| Server, network & TLS | 🟡 Partly (no real hosts) | 2026-10-31 | SEC-004 (fixed) |
| Containers | 🟢 Passed | 2026-10-31 | — |
| Data & storage | 🟢 Passed | 2026-10-31 | — |
| Secrets & dependencies | 🟢 Passed after fixes | 2026-10-31 | SEC-001 (fixed) |
| Mobile | ➖ N/A | | |
| IoT / devices | ➖ N/A | | |
| Logging & incident response | 🟡 In progress | 2026-10-31 | — |
| Availability & abuse | 🟡 In progress | 2026-10-31 | — |
| CI/CD automation | 🟢 Passed | 2026-10-31 | — |
