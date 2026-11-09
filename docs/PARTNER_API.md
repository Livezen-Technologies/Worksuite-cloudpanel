# Partner API v1 — provisioning for WorkSuite Cloud

Base `/partner/v1`, JSON. This is how **WorkSuite Cloud** (`worksuitecloud.com`)
deploys and manages WorkSuite Business and WorkSuite CMS on this panel for its
customers. Customers never sign in here: Cloud is their only screen, and this
API is the only door it uses.

Endpoint: `https://panel.livezentech.com/partner/v1`. (One request spelled the
host `panel.liventech.com`; `livezentech.com` is assumed. Confirm the DNS name
before issuing the production credential.)

## Credentials and scopes

A partner client is a scoped server-to-server credential, created on the panel
host and shown once:

```bash
php artisan partner:client "WorkSuite Cloud" --webhook=https://api.worksuitecloud.com/livezen/v1/webhook
```

It prints `LIVEZEN_KEY_ID`, `LIVEZEN_SECRET` and `LIVEZEN_WEBHOOK_SECRET` for
Cloud's `api/.env`. `--rotate` issues new secrets for an existing client.
Both secrets are stored encrypted (Laravel `encrypted` cast). Staff can disable
a client, change its webhook URL or pin it to source IPs with
`PATCH /api/v1/partner-clients/{id}` (`settings.manage`).

| Scope | Routes |
| --- | --- |
| `deployments:read` | `GET /summary`, `GET /deployments`, `GET /deployments/{ext}`, `GET /deployments/{ext}/logs` |
| `deployments:write` | `POST /deployments`, `POST/DELETE /deployments/{ext}/domain`, `POST /deployments/{ext}/domain/verify` |
| `deployments:operate` | `POST /deployments/{ext}/actions` |
| `deployments:lifecycle` | `POST /deployments/{ext}/lifecycle` |

**Infrastructure permissions stay separate.** Each partner customer account
becomes its own panel organisation, owned by a service user with the plain
customer `owner` role, a random unusable password and a reserved
`@partner.invalid` address. Partner customers get no panel login, no staff
role, no server, firewall or terminal access, and their apps are confined to
their organisation like any customer's. Panel staff see everything through the
existing admin API plus `GET /api/v1/partner-overview`.

## Request signing

Every request carries:

| Header | Value |
| --- | --- |
| `X-Livezen-Key` | the key id (`lzp_…`) |
| `X-Livezen-Timestamp` | unix seconds; ±300 s (`PARTNER_SIGNATURE_TOLERANCE`) |
| `X-Livezen-Nonce` | 16–128 random characters, single use (remembered for twice the window) |
| `X-Livezen-Signature` | `v1=` + hex HMAC-SHA256(secret, canonical) |
| `Idempotency-Key` | required on every POST; same key + same body within 24 h replays the first response (`Idempotent-Replayed: true`), a different body is 409 |

```
canonical = timestamp "\n" METHOD "\n" path?query "\n" sha256hex(raw body) "\n" nonce
```

`App\Support\PartnerSignature` implements it here; `api/src/deployments/livezen.ts`
in worksuite-cloud implements the same thing. A wrong key, signature, stale
timestamp or reused nonce is 401 `unauthenticated`; a missing scope 403.

## Deployments

`{ext}` is the partner's own id for the deployment (Cloud sends its deployment
UUID). A partner only ever sees its own deployments; anything else is 404.

### `POST /deployments` → 202 (200 if `external_id` already exists)

```json
{
  "external_id": "8f1c…", "product": "business",
  "account": { "id": "42", "name": "Acme Ltd" },
  "workspace_name": "Acme", "subdomain": "acme", "custom_domain": "work.acme.com",
  "region": "eu-central", "storage_gb": 20, "version": null,
  "admin": { "email": "owner@acme.com", "name": "Ada" },
  "sso": { "issuer": "https://worksuitecloud.com/oauth", "client_id": "ws-dep-…", "client_secret": "…" },
  "env": { "WORKSUITE_CLOUD_URL": "https://worksuitecloud.com", "WORKSUITE_CLOUD_ORG_ID": "42" },
  "entitlement": { "plan": "WORKSUITE_BUSINESS", "storage_gb": 20, "instances": { "BUSINESS": 1, "CMS": 1 } }
}
```

`product` is `business` or `cms` (templates `worksuite-business` /
`worksuite-cms`, images from `WORKSUITE_BUSINESS_IMAGE` / `WORKSUITE_CMS_IMAGE`;
`version` pins the tag). Storage picks the instance size (≤5 GB small, ≤10
standard, above that pro). The deployment runs through the same hosting
pipeline as a customer app: placement (own or shared-pool servers), the free
`<subdomain>.<PLATFORM_DOMAIN>` with HTTPS, the agent's health check, release
history. `sso.*` become `OIDC_ISSUER`/`OIDC_CLIENT_ID`/`OIDC_CLIENT_SECRET`
(secret env var); only `WORKSUITE_*` keys are accepted in `env`. Passwords are
never sent.

Response (also `GET /deployments/{ext}`, which adds `backups[]`):

```json
{ "data": {
  "id": "…", "external_id": "8f1c…", "account_id": "42", "product": "business", "workspace_name": "Acme",
  "status": "provisioning|running|stopped|suspended|failed|terminated", "status_reason": null,
  "url": "https://work.acme.com", "platform_url": "https://acme.livezen.app", "subdomain": "acme",
  "custom_domain": { "name": "work.acme.com", "dns_status": "verified", "https_status": "active" },
  "ssl": { "platform": "active", "custom": "active" }, "region": "eu-central", "size": "pro", "storage_gb": 20, "version": null,
  "resources": { "cpu_pct": 9.4, "ram_mb": 612, "ram_limit_mb": 4096, "storage_gb": 40 },
  "health": "running", "last_job": { "id": "…", "type": "deployment", "status": "succeeded", "progress": 100, "stages": [], "error": null },
  "suspended_at": null, "terminated_at": null, "created_at": "…", "updated_at": "…"
} }
```

### `POST /deployments/{ext}/actions` → 202 `{job_id, status, action}`

`{action: start|stop|restart|redeploy|upgrade|backup|restore|scale, version?, storage_gb?, backup_id?, backup_first?}`.
`upgrade` needs `version`, `scale` needs `storage_gb`, `restore` needs a
`backup_id` of this deployment (a fresh backup is taken first unless
`backup_first: false`). A suspended deployment accepts only `backup`; a
terminated one nothing (409).

### `POST /deployments/{ext}/lifecycle` → 200 (the deployment)

`{state: suspend|resume|terminate, reason?}`. **Suspend** stops the container
and keeps every volume. **Resume** starts it again. **Terminate** retires the
application (containers removed, volumes kept for
`deleted_app_retention_days`, then purged by `apps:purge`).

### Domains

`POST /deployments/{ext}/domain {domain}` → 201 `{domain, records[], dns_status, https_status}`
(replaces an earlier custom domain); `POST …/domain/verify` checks DNS and
issues the certificate once it resolves; `DELETE …/domain` → 204.

### `GET /deployments/{ext}/logs?tail=200`, `GET /summary`

The last deployment's log tail and the container's logs; counts by status and
webhook delivery health for the partner's own monitoring.

## Webhooks to the partner

`partner:sync` (every minute) refreshes each live deployment from its job,
container and domains and queues an event when anything visible changed;
every create, action and lifecycle change queues one too. `partner:webhooks`
(every minute) delivers them:

```
POST <webhook_url>
X-Livezen-Event: deployment.updated
X-Livezen-Event-Id: <uuid>          (also "id" in the body; receivers deduplicate on it)
X-Livezen-Signature: t=<unix>,v1=hex(HMAC-SHA256(webhook_secret, t + "." + body))

{ "id": "…", "event": "deployment.updated", "occurred_at": "…", "deployment": { …as above… } }
```

Events: `deployment.provisioning`, `deployment.updated`,
`deployment.action.<action>`, `deployment.suspend|resume|terminate`,
`deployment.domain`. Non-2xx is retried with exponential backoff (30 s base,
6 h cap) up to 12 attempts, then parked as failed and shown in the staff
overview. Cloud also re-reads every deployment on its own schedule, so a lost
webhook only delays an update.

## Audit

Every partner mutation is in the tamper-evident audit log of the customer's
organisation, actor `partner:<client name>` (`partner.deployment.*`,
`partner.domain.*`), and credential changes as `partner.client.*`.
