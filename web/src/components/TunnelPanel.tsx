import { useState } from 'react'
import { useAction, useOne } from '../api/hooks'
import { Card, Empty, Field } from './Card'
import { StatusBadge } from './Badge'
import { ConfirmDialog } from './Overlay'
import Select from './Select'
import { ago } from '../lib/format'

type Tunnel = { id: string; tunnel_id: string; name: string; status: string; connections: number; hostnames: string[]; cname_target: string; error: string | null; close_ports: boolean; ports_closed: boolean; last_synced_at: string | null }
type Page = { tunnel: Tunnel | null; hostnames: string[]; providers: { id: string; name: string; has_account_id: boolean }[] }

/** CFT-1..6: publish this server's apps through a Cloudflare Tunnel. */
export function TunnelPanel({ serverId }: { serverId: string }) {
  const path = `/servers/${serverId}/tunnel`
  const page = useOne<Page>(path, undefined, { refetchInterval: 15000 })
  const { run, busy } = useAction()
  const [provider, setProvider] = useState('')
  const [confirm, setConfirm] = useState(false)
  const p = page.item
  if (!p) return <Card>Loading tunnel…</Card>
  const t = p.tunnel
  const usable = p.providers.filter((x) => x.has_account_id)
  if (!t) {
    return (
      <Card title="Cloudflare Tunnel" subtitle="Serve this server's sites through an outbound tunnel; no inbound ports 80/443 needed">
        {usable.length === 0 ? (
          <Empty>Connect a Cloudflare account with its account ID under DNS → Providers. The API token needs Cloudflare Tunnel: Edit and DNS: Edit.</Empty>
        ) : (
          <div className="flex flex-wrap items-end gap-3">
            <Field label="Cloudflare account"><Select className="input" value={provider} onChange={(e) => setProvider(e.target.value)}><option value="">Choose…</option>{usable.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</Select></Field>
            <button type="button" className="btn btn-primary" disabled={busy || !provider} onClick={() => run({ path, body: { dns_provider_id: provider }, label: 'Create tunnel', success: 'Tunnel created' }).catch(() => {})}>Create tunnel</button>
          </div>
        )}
        <p className="text-xs text-muted mt-3 mb-0">Would publish: {p.hostnames.length ? p.hostnames.join(', ') : 'no domains yet'}</p>
      </Card>
    )
  }
  return (
    <Card title={<span className="flex items-center gap-2">Cloudflare Tunnel <StatusBadge status={t.status} /></span>}
      subtitle={`${t.connections} connection${t.connections === 1 ? '' : 's'} · synced ${t.last_synced_at ? ago(t.last_synced_at) : 'never'}`}
      actions={<>
        <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `${path}/sync`, label: 'Sync tunnel', success: 'Tunnel synced' }).catch(() => {})}>Sync</button>
        <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `${path}/restart`, label: 'Restart connector', success: 'Connector restarting' }).catch(() => {})}>Restart connector</button>
        <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => setConfirm(true)}>Remove</button>
      </>}>
      <p className="m-0 text-sm">Hostnames point at <code className="font-mono">{t.cname_target}</code> and reach Traefik on this server.</p>
      <ul className="list-none m-0 mt-3 p-0">
        {t.hostnames.map((h) => <li key={h} className="py-1.5 border-t border-line text-sm font-mono">{h}</li>)}
        {t.hostnames.length === 0 && <li className="text-sm text-muted">No hostnames yet. Add domains to apps on this server, then sync.</li>}
      </ul>
      <label className="flex items-center gap-2 mt-3 text-sm">
        <input type="checkbox" checked={t.close_ports} disabled={busy}
          onChange={(e) => run({ path, method: 'PATCH', body: { close_ports: e.target.checked }, label: 'Port lockdown', success: e.target.checked ? 'Ports close while the tunnel is healthy' : 'Ports 80/443 open' }).catch(() => {})} />
        Close ports 80/443 while the tunnel is healthy
        <span className="text-xs text-muted">({t.ports_closed ? 'closed now' : 'open now'})</span>
      </label>
      {t.error && <pre role="alert" className="mt-3 mb-0 text-xs text-danger whitespace-pre-wrap">{t.error}</pre>}
      <ConfirmDialog open={confirm} onClose={() => setConfirm(false)} danger confirmLabel="Remove tunnel" title="Remove the Cloudflare Tunnel?"
        onConfirm={() => { setConfirm(false); run({ path, method: 'DELETE', label: 'Remove tunnel', success: 'Tunnel removed' }).catch(() => {}) }}>
        The DNS records it created are deleted; sites stop resolving until they point at the server again.
      </ConfirmDialog>
    </Card>
  )
}
