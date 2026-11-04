import { useState } from 'react'
import { useAction, useOne } from '../../api/hooks'
import { Card, PageHeader, StatTile } from '../../components/Card'
import { Badge, StatusBadge } from '../../components/Badge'
import { ago } from '../../lib/format'

type Client = { id: string; name: string; key_id: string; scopes: string[]; active: boolean; webhook_url: string | null; last_used_at: string | null; deployments: number }
type Recent = { id: string; external_id: string; client: string | null; account: string | null; product: string; workspace_name: string; subdomain: string; custom_domain: string | null; status: string; size: string; storage_gb: number; organization_id: string; updated_at: string | null }
type Failure = { id: string; external_id: string; product: string; subdomain: string; reason: string | null; organization_id: string; updated_at: string | null }
type Overview = {
  clients: Client[]
  deployments: Record<string, number>
  recent: Recent[]
  failures: Failure[]
  webhooks: { pending: number; failed: number; recent_failures: { id: string; event: string; last_error: string | null; attempts: number; failed_at: string | null }[] }
}

const product = (p: string) => (p === 'business' ? 'WorkSuite Business' : p === 'cms' ? 'WorkSuite CMS' : p)

/**
 * Partner provisioning (docs/PARTNER_API.md): what WorkSuite Cloud has deployed here through /partner/v1,
 * the partner credentials and webhook delivery health. Platform staff only — the API refuses anyone else.
 */
export default function Partners() {
  const q = useOne<Overview>('/partner-overview')
  const { run, busy } = useAction()
  const [editing, setEditing] = useState<string | null>(null)
  const [url, setUrl] = useState('')
  const o = q.item
  const d = o?.deployments ?? {}
  const live = ['provisioning', 'running', 'stopped', 'suspended', 'failed'].reduce((n, s) => n + (d[s] ?? 0), 0)

  const patch = (c: Client, body: Record<string, unknown>, label: string) =>
    run({ method: 'PATCH', path: `/partner-clients/${c.id}`, body, label, success: `${c.name} updated` }).then(() => { setEditing(null); q.refetch() }).catch(() => {})

  return (
    <div className="space-y-4">
      <PageHeader title="Partner provisioning" subtitle="WorkSuite Business and CMS instances that WorkSuite Cloud deploys here for its customers, the partner keys it uses and webhook delivery." />
      {q.isError && <Card><p className="text-sm text-muted">This screen is for platform staff. {String((q.error as Error)?.message ?? '')}</p></Card>}
      {q.isLoading && <Card><p className="text-muted">Loading…</p></Card>}
      {o && <>
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <StatTile label="Live deployments" value={live} />
          <StatTile label="Running" value={d.running ?? 0} valueTone="g" />
          <StatTile label="Failed" value={d.failed ?? 0} valueTone={(d.failed ?? 0) ? 'r' : 'n'} />
          <StatTile label="Suspended" value={d.suspended ?? 0} valueTone={(d.suspended ?? 0) ? 'y' : 'n'} delta={`${d.provisioning ?? 0} provisioning · ${d.terminated ?? 0} terminated`} deltaTone="n" />
        </div>

        <Card title="Partner keys" subtitle="Created on the server with php artisan partner:client. Secrets are shown once there and never here." pad={false}>
          {!o.clients.length ? <p className="p-5 text-sm text-muted">No partner keys yet.</p> :
            <ul className="divide-y divide-line">
              {o.clients.map((c) => <li key={c.id} className="p-4 space-y-2">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">{c.name}</span>
                  <Badge tone={c.active ? 'g' : 'n'}>{c.active ? 'Active' : 'Disabled'}</Badge>
                  <span className="font-mono text-xs text-muted">{c.key_id}</span>
                  <span className="text-xs text-muted">· {c.deployments} live · last used {c.last_used_at ? ago(c.last_used_at) : 'never'}</span>
                  <span className="flex-1" />
                  <button type="button" className="btn btn-sm" disabled={busy} onClick={() => { setEditing(c.id); setUrl(c.webhook_url ?? '') }}>Webhook URL</button>
                  <button type="button" className={`btn btn-sm ${c.active ? 'btn-danger' : ''}`} disabled={busy}
                    onClick={() => (!c.active || window.confirm(`Disable ${c.name}? Its requests will be refused until it is enabled again; nothing deployed is touched.`)) && patch(c, { active: !c.active }, c.active ? `Disable ${c.name}` : `Enable ${c.name}`)}>
                    {c.active ? 'Disable' : 'Enable'}</button>
                </div>
                <div className="text-xs text-muted">Scopes: {c.scopes.join(', ')} · Webhook: <span className="font-mono">{c.webhook_url ?? 'none'}</span></div>
                {editing === c.id && <form className="flex flex-wrap gap-2" onSubmit={(e) => { e.preventDefault(); patch(c, { webhook_url: url.trim() || null }, 'Save webhook URL') }}>
                  <input className="input flex-1 min-w-[16rem]" type="url" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://api.worksuitecloud.com/livezen/v1/webhook" aria-label="Webhook URL" />
                  <button type="submit" className="btn btn-primary btn-sm" disabled={busy}>Save</button>
                  <button type="button" className="btn btn-sm" onClick={() => setEditing(null)}>Cancel</button>
                </form>}
              </li>)}
            </ul>}
        </Card>

        <Card title="Recent deployments" pad={false}>
          {!o.recent.length ? <p className="p-5 text-sm text-muted">Nothing deployed through a partner yet.</p> :
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="text-left text-xs text-muted"><tr>
                  <th className="px-4 py-2">Workspace</th><th className="px-4 py-2">Customer</th><th className="px-4 py-2">Address</th>
                  <th className="px-4 py-2">Size</th><th className="px-4 py-2">Status</th><th className="px-4 py-2">Updated</th>
                </tr></thead>
                <tbody className="divide-y divide-line">
                  {o.recent.map((r) => <tr key={r.id}>
                    <td className="px-4 py-2"><div className="font-medium">{r.workspace_name}</div><div className="text-xs text-muted">{product(r.product)}</div></td>
                    <td className="px-4 py-2"><div>{r.account ?? '—'}</div><div className="text-xs text-muted">{r.client}</div></td>
                    <td className="px-4 py-2 font-mono text-xs">{r.custom_domain ?? r.subdomain}</td>
                    <td className="px-4 py-2">{r.size} · {r.storage_gb} GB</td>
                    <td className="px-4 py-2"><StatusBadge status={r.status} /></td>
                    <td className="px-4 py-2 text-muted">{r.updated_at ? ago(r.updated_at) : '—'}</td>
                  </tr>)}
                </tbody>
              </table>
            </div>}
        </Card>

        <div className="grid gap-4 lg:grid-cols-2">
          <Card title="Failed deployments" pad={false}>
            {!o.failures.length ? <p className="p-5 text-sm text-muted">None.</p> :
              <ul className="divide-y divide-line">{o.failures.map((f) => <li key={f.id} className="p-4 text-sm">
                <div className="font-medium">{f.subdomain} <span className="text-muted font-normal">· {product(f.product)}</span></div>
                <div className="text-muted">{f.reason ?? 'No reason recorded'} · {f.updated_at ? ago(f.updated_at) : ''}</div>
              </li>)}</ul>}
          </Card>
          <Card title="Webhooks to partners" subtitle={`${o.webhooks.pending} waiting · ${o.webhooks.failed} gave up in the last 7 days`} pad={false}>
            {!o.webhooks.recent_failures.length ? <p className="p-5 text-sm text-muted">No failed deliveries.</p> :
              <ul className="divide-y divide-line">{o.webhooks.recent_failures.map((w) => <li key={w.id} className="p-4 text-sm">
                <div className="font-medium">{w.event} <span className="text-muted font-normal">· {w.attempts} attempts</span></div>
                <div className="text-muted">{w.last_error ?? '—'} · {w.failed_at ? ago(w.failed_at) : ''}</div>
              </li>)}</ul>}
          </Card>
        </div>
      </>}
    </div>
  )
}
