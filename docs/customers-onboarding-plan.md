# Customers & Onboarding — implementation plan

Phases, priorities, data model and API for the *Customer Management & Onboarding*
specification. Written against `main` at `19fdff8` (Finance & Billing deployed
2026-11-03). Nothing here is built yet.

---

## 0. What the spec assumes that is not true here

Four things have to be settled before any code, because they change the data
model rather than the amount of work.

### 0.1 There is no "customer" separate from "company"

CloudPanel has **one** account entity: `organizations`. The Hosting menu already
calls it "Hosting Customers", `CustomerController` manages it, and the panel's
whole tenancy (`BelongsToOrganization`) hangs off it. People are `users` joined
through `memberships` (`organization_id`, `user_id`, `role`, `status`).

The spec wants three: **Customer** (a person, with phone, country, type, account
manager), **Company** (a business a customer is linked to), and **Contact**
(billing/technical people under a company). Mapping them onto what exists:

| Spec entity | What it already is here |
| --- | --- |
| Company | `organizations` + `billing_profiles` (company name, reg. no., tax id, billing address — added with Finance) |
| Customer | `users` + `memberships` for the panel-login people; the *account* itself is the organisation |
| Contact | `memberships` for people with a login; nothing for people without one |

**Recommended reading:** an organisation **is** the customer account; "Company"
is the organisation's business identity (already modelled by `billing_profiles`,
extended); "Customer" becomes the person record (`users` extended with the
profile fields); "Contact" becomes a new `contacts` table for billing/technical
people who have no login. This keeps one identity and one billing record, which
§14 explicitly demands, and avoids a second customer table shadowing
`organizations`.

**The alternative** — a genuinely separate `customers` table — duplicates
identity and billing, and every existing module (Finance, Hosting, Deploy,
Resources, tenancy, RBAC) would need a second owner column. Not recommended.
Flagging it because the spec's wording leans that way and this is the one
decision that is expensive to reverse.

### 0.2 There is no CRM section

The spec's sidebar reads `… Finance → CRM → Customers → Settings`. CloudPanel has
seven sections and no CRM. CRM is a WorkSuite module, not this product. Plan
assumes **Customers lands eighth, directly after Finance**, and that CRM is out
of scope unless you say otherwise.

### 0.3 Account status lives in a JSON blob

`suspend` and `close` write into `organizations.settings` JSON
(`settings.suspension`, `settings.closed`). The spec wants Account Status as a
filterable, reportable column on the directory, and "Suspended Customers" as a
dashboard figure. That cannot be done from JSON at any sensible speed. Phase 1
promotes it to a real `organizations.status` column with a backfill from the
existing JSON, leaving the JSON in place for the suspension reason and history.

### 0.4 Provisioning already exists and must not be re-implemented

`HostingService::host()`, `DeploymentService`, `VpsService`, `DomainService`,
`DnsZoneService` and the agent command bus already provision. Onboarding step 8
("provisioning service creates the hosting account") is a **caller** of those,
driven by a queued job with an idempotency key — not new provisioning code.
`approvals` + `ApprovalService` already exist for the verification queue, and
`jobs`/`job_attempts` already carry retry and log semantics.

---

## 1. Phases and priorities

Eight phases. Each is independently shippable and leaves the panel working.
P1 = do first, P3 = only after the rest earns its keep.

| # | Phase | Priority | Ships |
| --- | --- | --- | --- |
| 1 | Foundations: data model + Customers section shell | **P1** | Sidebar entry, Overview, All Customers, status column, contacts, groups tables |
| 2 | Customer directory & unified profile | **P1** | Full directory, filters, bulk actions, export; the 14-tab profile reusing existing modules |
| 3 | Companies & contacts | **P1** | Company profile, contacts with roles, company-level permissions |
| 4 | Customer groups | **P2** | Static + dynamic groups, membership rules, group pricing/discount hook into Finance |
| 5 | Onboarding engine | **P1** | Templates, runs, steps, the stepper UI, admin actions, approvals queue |
| 6 | Automated provisioning | **P2** | Queued step runners against HostingService/VpsService/DomainService, idempotent, retried |
| 7 | Lifecycle automation & communications | **P2** | Reminder/recovery schedules, notification preferences, templates |
| 8 | Customer portal onboarding | **P3** | The customer-facing stepper in `web/src/pages/app/` |

Cross-cutting, done inside every phase rather than at the end: RBAC, audit via
`AuditLogger`, demo seeder rows, tests, light/dark + 375px.

**Why this order.** Phases 1–3 are mostly *surfacing and completing* data that
already exists, so they pay off immediately and carry little risk. Phase 5 is the
genuinely new machine and depends on 1. Phase 6 touches provisioning, which is
the part that can break a live customer's hosting, so it goes after the engine is
proven with manual steps. Phase 8 is last because the admin side must be right
before customers see it.

### Suggested slicing for delivery

- **Slice A (phases 1–2).** The section exists and the directory is genuinely
  better than today's Hosting Customers page. Biggest visible win per unit work.
- **Slice B (phases 3–4).** Companies, contacts, groups — the segmentation that
  Finance discounts and targeted notifications later depend on.
- **Slice C (phases 5–6).** Onboarding end to end, admin-driven first, then
  automated provisioning behind a feature flag per customer type.
- **Slice D (phases 7–8).** Automation and the portal.

---

## 2. Data model

New tables are tenant-stamped the same way the rest are (`$this->tenant($t)` →
`organization_id` + `BelongsToOrganization`), **except** `customer_groups`,
`onboarding_templates` and `customer_settings`, which are platform-wide
configuration owned by the operator.

### 2.1 Changed: `organizations` (the customer account)

```
status                varchar(20) default 'active'   -- active|pending|suspended|closed|trial
                                                     -- backfilled from settings.suspension/closed, indexed
customer_type         varchar(20) default 'individual' -- individual|business|enterprise|reseller
account_manager_id    uuid null  -> users.id         -- the assigned staff member, indexed
onboarding_status     varchar(20) default 'none'     -- none|in_progress|completed|failed|abandoned
registered_at         timestamp null                 -- distinct from created_at for imports
last_activity_at      timestamp null                 -- denormalised from customer_activities, indexed
archived_at           timestamp null
```

### 2.2 Changed: `users` (the person)

```
phone        varchar(40) null
country      char(2) null
locale       varchar(10) null
timezone     varchar(64) null
notification_preferences json null   -- {email:bool, in_app:bool, push:bool, per-topic overrides}
```

### 2.3 Changed: `billing_profiles` (the company identity)

Already carries company name, registration/tax number, address, country and
billing email. Add:

```
industry        varchar(80) null
website         varchar(190) null
phone           varchar(40) null
```

### 2.4 New: `contacts`

People attached to an account who may have no login.

```
id uuid pk, organization_id uuid
user_id        uuid null -> users.id      -- set when the contact can sign in
name           varchar(190)
email          varchar(190)
phone          varchar(40) null
role           varchar(20)                -- owner|admin|billing|technical|abuse
is_primary     boolean default false
notes          text null
timestamps
unique (organization_id, email, role)
index (organization_id, role)
```

### 2.5 New: `customer_groups` and `customer_group_members`

```
customer_groups
  id uuid pk
  name varchar(80), slug varchar(80) unique
  kind varchar(10)                        -- static|dynamic
  rules json null                         -- dynamic membership predicate (see 2.9)
  discount_percent tinyint default 0      -- read by FinanceService when pricing
  plan_overrides json null                -- {plan_id: price_cents}
  colour varchar(16) null, description text null
  is_system boolean default false         -- the twelve defaults cannot be deleted
  timestamps

customer_group_members
  id uuid pk
  customer_group_id uuid, organization_id uuid
  source varchar(10) default 'manual'     -- manual|rule
  added_by uuid null, timestamps
  unique (customer_group_id, organization_id)
```

An account may be in many groups. Where two groups both set a discount, the
**largest** wins — stated here because it is exactly the kind of rule that gets
decided accidentally in a controller.

### 2.6 New: `onboarding_templates` and `onboarding_template_steps`

```
onboarding_templates
  id uuid pk
  name varchar(120), slug varchar(120) unique
  customer_type varchar(20)               -- which type this is the default for
  version int default 1                   -- a published template is immutable; editing forks a version
  status varchar(10) default 'draft'      -- draft|published|retired
  is_default boolean default false
  completion_rules json null
  timestamps
  unique (slug, version)

onboarding_template_steps
  id uuid pk, onboarding_template_id uuid
  key varchar(40)                         -- email_verification|profile|company|billing|package|payment|provisioning|welcome|custom
  label varchar(120)
  position int
  required boolean default true
  condition json null                     -- shown only when this predicate holds
  handler varchar(60) null                -- the queued runner, null = manual
  config json null                        -- documents required, email template, assignee rule, timeout
  requires_approval boolean default false
  timestamps
  unique (onboarding_template_id, key)
```

Versioning rather than in-place edits: a run in flight must not have its own
definition change under it.

### 2.7 New: `onboarding_runs` and `onboarding_run_steps`

```
onboarding_runs
  id uuid pk, organization_id uuid
  onboarding_template_id uuid, template_version int
  user_id uuid                            -- the person going through it
  status varchar(15)                      -- pending|in_progress|awaiting_payment|awaiting_provisioning
                                          -- |awaiting_approval|completed|failed|abandoned
  current_step_key varchar(40) null
  percent_complete tinyint default 0
  assigned_to uuid null -> users.id
  started_at, last_activity_at, completed_at, failed_at timestamps null
  failure_reason text null
  meta json null                          -- selected plan, order/invoice ids
  timestamps
  index (status, last_activity_at), index (organization_id)

onboarding_run_steps
  id uuid pk, onboarding_run_id uuid, organization_id uuid
  key varchar(40), label varchar(120), position int
  status varchar(15)                      -- pending|in_progress|blocked|skipped|done|failed
  required boolean
  started_at, completed_at timestamps null
  completed_by uuid null                  -- set when an admin completed it by hand
  approval_id uuid null -> approvals.id
  attempts smallint default 0
  idempotency_key varchar(80) null unique -- the provisioning guard
  result json null, error text null
  timestamps
  unique (onboarding_run_id, key)
```

### 2.8 New: `customer_activities`

```
id uuid pk, organization_id uuid
user_id uuid null, actor_id uuid null     -- subject and who did it
kind varchar(40)                          -- registered|verified|step_completed|service_created
                                          -- |invoice_paid|suspended|note|…
subject_type varchar(40) null, subject_id uuid null
summary varchar(255)
meta json null
occurred_at timestamp, timestamps
index (organization_id, occurred_at desc), index (kind, occurred_at desc)
```

Distinct from `audit_logs`: the audit chain is tamper-evident and records *staff*
actions for compliance; this is the customer's readable timeline, including their
own actions, and is safe to prune.

### 2.9 Dynamic group rules and step conditions

Both use one small predicate shape, evaluated server-side only — never `eval`,
never raw SQL from the client:

```json
{"all": [
  {"field": "customer_type", "op": "eq",  "value": "business"},
  {"field": "active_services", "op": "gte", "value": 1},
  {"any": [{"field": "country", "op": "in", "value": ["LK", "SG"]}]}
]}
```

Allowed fields are a fixed whitelist mapped to columns or computed counts; `op`
is a fixed set. Dynamic group membership is recomputed by a scheduled job and
materialised into `customer_group_members` with `source='rule'`, so every read
path stays a plain join.

### 2.10 Reused unchanged

`plans`, `subscriptions`, `invoices`, `payments`, `refund_requests`,
`billing_profiles` (Finance); `applications`, `vps`, `wp_sites`, `domains`,
`dns_zones`, `databases` (services); `approvals` (verification queue);
`jobs`/`job_attempts` (provisioning runs); `email_templates`,
`notification_channels` (communications); `audit_logs`; `table_views` (saved
views). The profile tabs are joins onto these, not new storage.

---

## 3. API

All under `/api/v1`, `{data: …}` envelope, `Lists::paginate` conventions
(`?search=&sort=&page=&per_page=`, `_id` params UUID-validated).

### 3.1 Permissions

Two new permissions in `config/rbac.php`:

- `customers.manage` — write on customers, companies, contacts, groups.
- `onboarding.manage` — write on templates, runs and approvals.

Read is `customers.manage|onboarding.manage|users.manage|billing.manage`, so
Finance and support staff keep the visibility they have today. `super_admin`
gets both by `*`; `developer` gets neither.

### 3.2 Routes

```
GET    customers/overview                        dashboard figures + charts (range param as Finance)
GET    customers                                 directory; filters: status, type, group_id,
                                                 company_id, account_manager_id, onboarding_status,
                                                 country, created from/to, has_expiring_services
POST   customers                                 create account + owner
GET    customers/{id}                            unified profile header
PATCH  customers/{id}                            profile fields, type, account manager
POST   customers/{id}/archive | restore
POST   customers/{id}/groups                     {group_ids:[…]} replace membership
POST   customers/{id}/notify                     {template, channel, vars}
GET    customers/export                          streaming CSV, honours the active filters
POST   customers/import                          202 + job id; CSV, dry-run flag, per-row report

GET    companies                                 billing_profiles joined to organizations
GET    companies/{organizationId}
PATCH  companies/{organizationId}
GET    companies/{organizationId}/contacts
POST   companies/{organizationId}/contacts
PATCH  contacts/{id}
DELETE contacts/{id}

GET    customer-groups
POST   customer-groups
PATCH  customer-groups/{id}
DELETE customer-groups/{id}                      refused when is_system
POST   customer-groups/{id}/preview-rules        returns the count + first 25 matches, writes nothing
POST   customer-groups/{id}/recompute            202, materialises dynamic membership

GET    onboarding/overview                       the eight funnel counts
GET    onboarding/runs                           filters: status, template, assignee, stalled_days
GET    onboarding/runs/{id}                      run + steps + logs
POST   onboarding/runs                           start a run for a customer
POST   onboarding/runs/{id}/assign
POST   onboarding/runs/{id}/remind
POST   onboarding/runs/{id}/resume               retry the failed step
POST   onboarding/runs/{id}/restart              new run, old one kept
POST   onboarding/runs/{id}/steps/{key}/complete manual completion; refused when handler is automated
                                                 and the step is not marked manually completable
POST   onboarding/runs/{id}/steps/{key}/skip     refused when required
GET    onboarding/templates
POST   onboarding/templates
PATCH  onboarding/templates/{id}                 draft only
POST   onboarding/templates/{id}/publish         freezes it and bumps the version
POST   onboarding/templates/{id}/duplicate
GET    onboarding/approvals                      the verification queue (approvals, filtered)
POST   onboarding/approvals/{id}/approve | reject

GET    customers/{id}/activities
GET    customers/{id}/services                   hosting accounts, VPS, apps, domains in one shape
GET    customers/{id}/communications
POST   customers/{id}/onboarding                 start onboarding for an existing customer
```

Portal side (phase 8), under the existing customer auth:

```
GET    me/onboarding                             the viewer's own run
POST   me/onboarding/steps/{key}                 submit a step's payload
GET    me/onboarding/packages                    selectable plans for their type/group
```

### 3.3 Rules the API must enforce

- **Idempotent provisioning.** Every automated step carries
  `onboarding_run_steps.idempotency_key` (unique). A runner that finds the key
  already resolved returns the first result instead of provisioning again — the
  same guard the Finance payments table uses for webhook replays.
- **No duplicate identity.** Creating a customer with an existing email attaches
  a membership to the existing user rather than making a second one.
- **Approval before provisioning.** A step with `requires_approval` creates an
  `approvals` row and parks the run at `awaiting_approval`; the runner refuses to
  fire without a decision.
- **Manual completion is audited, not silent.** `completed_by` is set and an
  `AuditLogger` entry written, because "admin marked payment received" is exactly
  the action an auditor asks about.
- **Suspension is reversible and recorded.** Keep the existing behaviour of
  remembering each application's previous status; the new `status` column is
  derived, not a second source of truth.
- **Rule previews never write.** `preview-rules` is a read, so a mis-typed
  predicate cannot sweep 250 accounts into a discount group.

---

## 4. Demo data

One seeder, `CustomersDemoSeeder`, behind `php artisan customers:demo`, guarded
the same way `finance:demo` is (refuses where more than one organisation exists
unless forced) and seeded from a fixed `Mt19937` so runs reproduce: 250
customers, 75 companies, 300 contacts, 12 groups, 6 templates, 180 runs (spread
deliberately across completed / pending / failed / abandoned), 35 pending
approvals, 150 hosting accounts, 75 VPS, 200 subscriptions, 500 activities, 200
notifications. It must compose with `finance:demo` rather than fight it — both
adopt existing organisations instead of assuming they own the table.

---

## 5. Risks

- **The identity decision (§0.1) is load-bearing.** Everything else is cheap to
  change; this is not.
- **Phase 6 touches live provisioning.** It should land behind a per-customer-type
  switch, default off, with the manual path still available.
- **Dynamic groups can move money.** A group that sets a discount and recruits by
  rule can change what customers are charged without anyone pressing a button.
  Recommend: rule-driven groups may *not* carry a discount until a human confirms
  the membership once.
- **250 customers × 14 profile tabs** is a lot of queries. The profile header must
  be one endpoint with counts, and each tab lazy.
