import { useState } from 'react'
import { useAction, useList, useOne } from '../../api/hooks'
import { Card, PageHeader } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, Modal } from '../../components/Overlay'
import { ago } from '../../lib/format'
import type { R } from '../../lib/util'

const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS']
const TTLS: [number, string][] = [[1, 'Auto'], [300, '5 min'], [3600, '1 hour'], [86400, '1 day']]
const PROXIABLE = ['A', 'AAAA', 'CNAME']
const EMPTY_CONN = { type: 'cloudflare', name: '', token: '', account_id: '', api_url: '', api_key: '', server_id: '', nameservers: '' }
const PROVIDER_NAMES: Record<string, string> = { cloudflare: 'Cloudflare', powerdns: 'PowerDNS' }
const EMPTY_RECORD = { type: 'A', name: '', content: '', ttl: 1, priority: 10, proxied: false }

/** DNS zones (SRS 4.18): Cloudflare and PowerDNS accounts, zones, nameservers, DNSSEC and records. */
export default function DnsZones() {
  const providers = useList<R>('/dns/providers')
  const zones = useList<R>('/dns/zones')
  const { run, busy } = useAction()
  const [selected, setSelected] = useState<string | null>(null)
  const [connectOpen, setConnectOpen] = useState(false)
  const [zoneOpen, setZoneOpen] = useState(false)
  const [conn, setConn] = useState(EMPTY_CONN)
  const [newZone, setNewZone] = useState({ dns_provider_id: '', name: '', defaults_to: '' })
  const zoneId = selected ?? (zones.rows[0]?.id as string | undefined) ?? null

  return (
    <>
      <PageHeader title="DNS zones" subtitle="Zones hosted at your DNS provider. Changes go to the provider first; Sync shows anything edited outside the panel."
        actions={<>
          <button type="button" className="btn" onClick={() => setConnectOpen(true)}>Connect provider</button>
          <button type="button" className="btn btn-primary" disabled={!providers.rows.length} onClick={() => { setNewZone({ dns_provider_id: String(providers.rows[0]?.id ?? ''), name: '', defaults_to: '' }); setZoneOpen(true) }}>Add zone</button>
        </>} />

      {!providers.isLoading && !providers.rows.length && (
        <Card title="Get started">
          <p className="m-0 text-sm">Connect a Cloudflare API token (Zone:Read, Zone:Edit, DNS:Edit) or your own PowerDNS server's API to manage zones here.</p>
        </Card>
      )}

      <div className="grid gap-4 lg:[grid-template-columns:minmax(260px,1fr)_2.4fr]">
        <Card title="Zones" pad={false}>
          <DataTable label="Zones" rows={zones.rows} loading={zones.isLoading} rowKey={(r) => r.id} onRowClick={(r) => setSelected(String(r.id))}
            columns={[
              { key: 'name', header: 'Zone', render: (r) => <span className={r.id === zoneId ? 'font-semibold' : ''}>{String(r.name)}</span> },
              { key: 'status', header: 'Status', render: (r) => <Badge tone={r.status === 'active' ? 'g' : 'y'}>{String(r.status)}</Badge> },
              { key: 'records_count', header: 'Records', value: (r) => Number(r.records_count) },
            ]} />
        </Card>
        {zoneId ? <ZoneDetail id={zoneId} onRemoved={() => setSelected(null)} /> : <Card title="Records"><p className="m-0 text-sm text-muted">No zones yet.</p></Card>}
      </div>

      {providers.rows.length > 0 && (
        <Card title="Provider accounts" pad={false}>
          <DataTable label="Provider accounts" rows={providers.rows} rowKey={(r) => r.id}
            columns={[
              { key: 'name', header: 'Name' },
              { key: 'type', header: 'Provider', render: (r) => PROVIDER_NAMES[String(r.type)] ?? String(r.type) },
              { key: 'account', header: 'Account' },
              { key: 'actions', header: '', sortable: false, render: (r) => (
                <button type="button" className="btn btn-sm btn-danger" disabled={busy}
                  onClick={() => run({ path: `/dns/providers/${r.id}`, method: 'DELETE', label: `Disconnect ${r.name}` })}>Disconnect</button>
              ) },
            ]} />
        </Card>
      )}

      <Modal open={connectOpen} onClose={() => setConnectOpen(false)} title="Connect a DNS provider"
        footer={<button type="button" className="btn btn-primary" disabled={busy || !conn.name || (conn.type === 'cloudflare' ? !conn.token : !conn.api_url || !conn.api_key || !conn.nameservers.trim())}
          onClick={() => run({ path: '/dns/providers', label: `Connect ${PROVIDER_NAMES[conn.type]}`, body: conn.type === 'cloudflare'
            ? { type: conn.type, name: conn.name, token: conn.token, account_id: conn.account_id || null }
            : { type: conn.type, name: conn.name, api_url: conn.api_url, api_key: conn.api_key, server_id: conn.server_id || null, nameservers: conn.nameservers.split(/[\s,]+/).filter(Boolean) } })
            .then(() => { setConnectOpen(false); setConn(EMPTY_CONN) })}>Verify and connect</button>}>
        <label className="label mb-3 block">Provider
          <select className="input" value={conn.type} onChange={(e) => setConn({ ...conn, type: e.target.value })}>
            <option value="cloudflare">Cloudflare</option>
            <option value="powerdns">PowerDNS</option>
          </select>
        </label>
        <label className="label mb-3 block">Name<input className="input" value={conn.name} onChange={(e) => setConn({ ...conn, name: e.target.value })} placeholder={conn.type === 'cloudflare' ? 'Main account' : 'Own nameservers'} /></label>
        {conn.type === 'cloudflare' ? <>
          <label className="label mb-3 block">API token<input className="input" type="password" autoComplete="off" value={conn.token} onChange={(e) => setConn({ ...conn, token: e.target.value })} /></label>
          <label className="label mb-3 block">Account ID (optional, for new zones)<input className="input" value={conn.account_id} onChange={(e) => setConn({ ...conn, account_id: e.target.value })} /></label>
        </> : <>
          <label className="label mb-3 block">API URL<input className="input" value={conn.api_url} onChange={(e) => setConn({ ...conn, api_url: e.target.value })} placeholder="https://ns1.example.net:8081" /></label>
          <label className="label mb-3 block">API key<input className="input" type="password" autoComplete="off" value={conn.api_key} onChange={(e) => setConn({ ...conn, api_key: e.target.value })} /></label>
          <label className="label mb-3 block">Server ID (optional)<input className="input" value={conn.server_id} onChange={(e) => setConn({ ...conn, server_id: e.target.value })} placeholder="localhost" /></label>
          <label className="label mb-3 block">Nameservers for new zones<input className="input font-mono" value={conn.nameservers} onChange={(e) => setConn({ ...conn, nameservers: e.target.value })} placeholder="ns1.example.net, ns2.example.net" /></label>
        </>}
        <p className="text-xs text-muted m-0">The credentials are checked with the provider, stored encrypted and never shown again.</p>
      </Modal>

      <Modal open={zoneOpen} onClose={() => setZoneOpen(false)} title="Add zone"
        footer={<button type="button" className="btn btn-primary" disabled={busy || !newZone.name}
          onClick={() => run<{ data: R }>({ path: '/dns/zones', body: { ...newZone, defaults_to: newZone.defaults_to || null }, label: `Add ${newZone.name}` }).then((res) => { setZoneOpen(false); setSelected(String(res.data.id)) })}>Add zone</button>}>
        <label className="label mb-3 block">Provider
          <select className="input" value={newZone.dns_provider_id} onChange={(e) => setNewZone({ ...newZone, dns_provider_id: e.target.value })}>
            {providers.rows.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </label>
        <label className="label mb-3 block">Domain<input className="input" value={newZone.name} onChange={(e) => setNewZone({ ...newZone, name: e.target.value })} placeholder="example.com" /></label>
        <label className="label mb-3 block">Default records point at (optional IPv4)<input className="input" value={newZone.defaults_to} onChange={(e) => setNewZone({ ...newZone, defaults_to: e.target.value })} placeholder="203.0.113.10" /></label>
        <p className="text-xs text-muted m-0">With an address, the zone gets an apex A record and a www CNAME. An existing zone at the provider is adopted as it is.</p>
      </Modal>
    </>
  )
}

function ZoneDetail({ id, onRemoved }: { id: string; onRemoved: () => void }) {
  const zone = useOne<R>(`/dns/zones/${id}`)
  const { run, busy } = useAction()
  const [editing, setEditing] = useState<R | null>(null)
  const [form, setForm] = useState<R>(EMPTY_RECORD)
  const [drift, setDrift] = useState<R | null>(null)
  const [ns, setNs] = useState<R | null>(null)
  const [confirm, setConfirm] = useState<R | 'zone' | null>(null)
  const z = zone.item ?? {}
  const records = (z.records as R[] | undefined) ?? []
  const canProxy = !!z.supports_proxy
  const dnssec = (z.dnssec as R | undefined) ?? { status: 'disabled', ds: [] }
  const [dnssecConfirm, setDnssecConfirm] = useState(false)
  const open = (r: R | null) => { setEditing(r ?? {}); setForm(r ? { ...EMPTY_RECORD, ...r, name: r.name === z.name ? '@' : String(r.name).replace(`.${z.name}`, '') } : EMPTY_RECORD) }
  const save = () => {
    const body = { ...form, priority: ['MX', 'SRV'].includes(String(form.type)) ? form.priority : null, proxied: canProxy && PROXIABLE.includes(String(form.type)) && !!form.proxied }
    return run(editing?.id
      ? { path: `/dns/zones/${id}/records/${editing.id}`, method: 'PATCH', body, label: `Update ${form.type} record` }
      : { path: `/dns/zones/${id}/records`, body, label: `Add ${form.type} record` }).then(() => setEditing(null))
  }

  return (
    <Card title={String(z.name ?? 'Zone')} pad={false} actions={<>
      <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run<{ data: R }>({ path: `/dns/zones/${id}/check-nameservers`, label: 'Nameserver check', silent: true }).then((r) => setNs(r.data))}>Check nameservers</button>
      <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run<{ data: R }>({ path: `/dns/zones/${id}/sync`, label: 'Sync', silent: true }).then((r) => setDrift(r.data))}>Sync</button>
      <button type="button" className="btn btn-sm btn-primary" onClick={() => open(null)}>Add record</button>
    </>}>
      {z.status === 'pending' && (
        <div className="p-4 text-sm border-b border-line">
          <Badge tone="y">pending</Badge> Point the domain's nameservers at your registrar to:
          <ul className="m-0 mt-1 font-mono text-xs">{((z.nameservers as string[]) ?? []).map((n) => <li key={n}>{n}</li>)}</ul>
        </div>
      )}
      {ns && <div className="p-4 text-sm border-b border-line">{ns.delegated ? 'Nameservers are in place; the zone is active.' : `Not delegated yet. Public DNS shows: ${((ns.found as string[]) ?? []).join(', ') || 'no NS records'}.`}</div>}
      {drift && <div className="p-4 text-sm border-b border-line">{drift.in_sync ? 'In sync with the provider.' : `Adopted changes made at the provider: ${(drift.added as R[]).length} added, ${(drift.removed as R[]).length} removed, ${(drift.changed as R[]).length} changed.`}</div>}
      <div className="p-4 text-sm border-b border-line flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <span className="font-semibold">DNSSEC</span>{' '}
          <Badge tone={dnssec.status === 'active' ? 'g' : dnssec.status === 'pending' ? 'y' : 'n'}>{String(dnssec.status)}</Badge>
          {dnssec.status !== 'disabled' && <>
            <p className="m-0 mt-1 text-xs text-muted">{dnssec.status === 'pending' ? 'Add this DS record at your registrar to finish turning DNSSEC on:' : 'The DS record your registrar should have:'}</p>
            {((dnssec.ds as string[]) ?? []).map((d) => <code key={d} className="block font-mono text-xs break-all mt-1">{d}</code>)}
          </>}
        </div>
        {dnssec.status === 'disabled'
          ? <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/dns/zones/${id}/dnssec`, method: 'PATCH', body: { enabled: true }, label: 'Turn on DNSSEC' })}>Turn on DNSSEC</button>
          : <button type="button" className="btn btn-sm" disabled={busy} onClick={() => setDnssecConfirm(true)}>Turn off</button>}
      </div>
      <DataTable label="Records" rows={records} loading={zone.isLoading} rowKey={(r) => r.id}
        columns={[
          { key: 'type', header: 'Type', render: (r) => <Badge tone="n">{String(r.type)}</Badge> },
          { key: 'name', header: 'Name', render: (r) => <span className="font-mono text-xs">{String(r.name)}</span> },
          { key: 'content', header: 'Content', render: (r) => <span className="font-mono text-xs break-all">{r.priority != null ? `${r.priority} ` : ''}{String(r.content)}</span> },
          { key: 'ttl', header: 'TTL', render: (r) => TTLS.find(([v]) => v === r.ttl)?.[1] ?? `${r.ttl}s` },
          { key: 'proxied', header: 'Proxy', render: (r) => canProxy && PROXIABLE.includes(String(r.type)) ? <Badge tone={r.proxied ? 'g' : 'n'}>{r.proxied ? 'proxied' : 'DNS only'}</Badge> : '' },
          { key: 'actions', header: '', sortable: false, render: (r) => (
            <span className="flex gap-1 justify-end">
              <button type="button" className="btn btn-sm" onClick={() => open(r)}>Edit</button>
              <button type="button" className="btn btn-sm btn-danger" onClick={() => setConfirm(r)}>Delete</button>
            </span>
          ) },
        ]} />
      <div className="p-4 flex justify-between text-xs text-muted">
        <span>{z.last_synced_at ? `Synced ${ago(String(z.last_synced_at))}` : ''}</span>
        <button type="button" className="btn btn-sm" onClick={() => setConfirm('zone')}>Remove from panel</button>
      </div>

      <Modal open={!!editing} onClose={() => setEditing(null)} title={editing?.id ? 'Edit record' : 'Add record'}
        footer={<button type="button" className="btn btn-primary" disabled={busy || !form.content} onClick={save}>Save</button>}>
        <label className="label mb-3 block">Type
          <select className="input" value={String(form.type)} disabled={!!editing?.id} onChange={(e) => setForm({ ...form, type: e.target.value })}>
            {TYPES.map((t) => <option key={t}>{t}</option>)}
          </select>
        </label>
        <label className="label mb-3 block">Name (@ for {String(z.name)})<input className="input" value={String(form.name)} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
        <label className="label mb-3 block">Content<input className="input" value={String(form.content)} onChange={(e) => setForm({ ...form, content: e.target.value })} /></label>
        {['MX', 'SRV'].includes(String(form.type)) && <label className="label mb-3 block">Priority<input className="input" type="number" min={0} value={Number(form.priority)} onChange={(e) => setForm({ ...form, priority: Number(e.target.value) })} /></label>}
        <label className="label mb-3 block">TTL
          <select className="input" value={Number(form.ttl)} disabled={canProxy && !!form.proxied} onChange={(e) => setForm({ ...form, ttl: Number(e.target.value) })}>
            {TTLS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </label>
        {canProxy && PROXIABLE.includes(String(form.type)) && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!form.proxied} onChange={(e) => setForm({ ...form, proxied: e.target.checked })} /> Proxy through Cloudflare</label>}
      </Modal>

      <ConfirmDialog open={dnssecConfirm} onClose={() => setDnssecConfirm(false)} busy={busy} danger title="Turn off DNSSEC?" confirmLabel="Turn off"
        onConfirm={() => run({ path: `/dns/zones/${id}/dnssec`, method: 'PATCH', body: { enabled: false }, label: 'Turn off DNSSEC' }).then(() => setDnssecConfirm(false))}>
        <p className="m-0 text-sm">Remove the DS record at your registrar first. While the registrar still has it, resolvers that check DNSSEC will fail to resolve this domain.</p>
      </ConfirmDialog>

      <ConfirmDialog open={!!confirm} onClose={() => setConfirm(null)} busy={busy} danger
        title={confirm === 'zone' ? 'Remove this zone from the panel?' : 'Delete this record?'} confirmLabel={confirm === 'zone' ? 'Remove' : 'Delete'}
        onConfirm={() => confirm === 'zone'
          ? run({ path: `/dns/zones/${id}`, method: 'DELETE', label: `Remove ${z.name}` }).then(() => { setConfirm(null); onRemoved() })
          : confirm && run({ path: `/dns/zones/${id}/records/${confirm.id}`, method: 'DELETE', label: `Delete ${confirm.type} record` }).then(() => setConfirm(null))}>
        <p className="m-0 text-sm">{confirm === 'zone' ? 'The zone and its records stay at the provider; only the panel forgets it.' : 'The record is deleted at the provider straight away.'}</p>
      </ConfirmDialog>
    </Card>
  )
}
