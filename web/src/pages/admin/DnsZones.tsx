import { useState } from 'react'
import { api } from '../../api/client'
import { useAction, useList, useOne } from '../../api/hooks'
import { Card, PageHeader, Field } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, Modal } from '../../components/Overlay'
import { ago } from '../../lib/format'
import type { R } from '../../lib/util'
import Select from '../../components/Select'

const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS']
const TTLS: [number, string][] = [[1, 'Auto'], [300, '5 min'], [3600, '1 hour'], [86400, '1 day']]
const PROXIABLE = ['A', 'AAAA', 'CNAME']
const EMPTY_CONN = { type: 'cloudflare', name: '', token: '', account_id: '', api_url: '', api_key: '', server_id: '', nameservers: '' }
const PROVIDER_NAMES: Record<string, string> = { cloudflare: 'Cloudflare', powerdns: 'PowerDNS' }
const SSL_MODES: [string, string][] = [['off', 'Off'], ['flexible', 'Flexible'], ['full', 'Full'], ['strict', 'Full (strict)']]
const CACHE_LEVELS: [string, string][] = [['basic', 'No query string'], ['simplified', 'Ignore query string'], ['aggressive', 'Standard']]
const EMPTY_RECORD = { type: 'A', name: '', content: '', ttl: 1, priority: 10, proxied: false }

/** DNS zones (SRS 4.18): Cloudflare and PowerDNS accounts, zones, nameservers, DNSSEC, settings, BIND files and records. */
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
            actions={(r) => [
            { label: `Disconnect ${r.name}`, icon: 'trash', danger: true, confirm: `${r.name} is disconnected. Zones it manages stop syncing.`, disabled: busy, onClick: () => run({ path: `/dns/providers/${r.id}`, method: 'DELETE', label: `Disconnect ${r.name}` }).catch(() => {}) },
          ]}
            columns={[
              { key: 'name', header: 'Name' },
              { key: 'type', header: 'Provider', render: (r) => PROVIDER_NAMES[String(r.type)] ?? String(r.type) },
              { key: 'account', header: 'Account' },
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
          <Select className="input" value={conn.type} onChange={(e) => setConn({ ...conn, type: e.target.value })}>
            <option value="cloudflare">Cloudflare</option>
            <option value="powerdns">PowerDNS</option>
          </Select>
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
          <Select className="input" value={newZone.dns_provider_id} onChange={(e) => setNewZone({ ...newZone, dns_provider_id: e.target.value })}>
            {providers.rows.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </Select>
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
  const [importOpen, setImportOpen] = useState(false)
  const [zoneFile, setZoneFile] = useState('')
  const [preview, setPreview] = useState<R | null>(null)
  const settings = useOne<R>(z.supports_settings ? `/dns/zones/${id}/settings` : null)
  const st = settings.item ?? {}
  const setSetting = (key: string, value: unknown) => run({ path: `/dns/zones/${id}/settings`, method: 'PATCH', body: { [key]: value }, label: 'Zone setting' })
  const download = async () => {
    const res = await api.get<{ data: { filename: string; content: string } }>(`/dns/zones/${id}/export`)
    const url = URL.createObjectURL(new Blob([res.data.content], { type: 'text/plain' }))
    const a = Object.assign(document.createElement('a'), { href: url, download: res.data.filename })
    a.click()
    URL.revokeObjectURL(url)
  }
  const importFile = (dryRun: boolean) => run<{ data: R }>({ path: `/dns/zones/${id}/import`, body: { zone_file: zoneFile, dry_run: dryRun }, label: dryRun ? 'Import preview' : 'Import zone file', silent: dryRun })
    .then((r) => { if (dryRun) setPreview(r.data); else { setImportOpen(false); setPreview(null); setZoneFile('') } })
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
      <button type="button" className="btn btn-sm" onClick={download}>Export</button>
      <button type="button" className="btn btn-sm" onClick={() => { setPreview(null); setImportOpen(true) }}>Import</button>
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
      {!!z.supports_settings && settings.item && (
        <div className="p-4 text-sm border-b border-line grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(170px,1fr))]">
          <Field label="SSL mode">
            <Select className="input h-9" value={String(st.ssl)} disabled={busy} onChange={(e) => setSetting('ssl', e.target.value)}>
              {SSL_MODES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </Select>
          </Field>
          <Field label="Minimum TLS">
            <Select className="input h-9" value={String(st.min_tls_version)} disabled={busy} onChange={(e) => setSetting('min_tls_version', e.target.value)}>
              {['1.0', '1.1', '1.2', '1.3'].map((v) => <option key={v} value={v}>TLS {v}</option>)}
            </Select>
          </Field>
          <Field label="Cache level">
            <Select className="input h-9" value={String(st.cache_level)} disabled={busy} onChange={(e) => setSetting('cache_level', e.target.value)}>
              {CACHE_LEVELS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </Select>
          </Field>
          <label className="flex items-center gap-2 self-end h-9">
            <input type="checkbox" checked={!!st.always_use_https} disabled={busy} onChange={(e) => setSetting('always_use_https', e.target.checked)} /> Always use HTTPS
          </label>
        </div>
      )}
      <DataTable label="Records" rows={records} loading={zone.isLoading} rowKey={(r) => r.id}
        actions={(r) => [
          { label: `Edit ${r.name ?? 'record'}`, icon: 'pencil', onClick: () => open(r) },
          { label: 'Delete', icon: 'trash', danger: true, confirm: false, onClick: () => setConfirm(r) },
        ]}
        columns={[
          { key: 'type', header: 'Type', render: (r) => <Badge tone="n">{String(r.type)}</Badge> },
          { key: 'name', header: 'Name', render: (r) => <span className="font-mono text-xs">{String(r.name)}</span> },
          { key: 'content', header: 'Content', render: (r) => <span className="font-mono text-xs break-all">{r.priority != null ? `${r.priority} ` : ''}{String(r.content)}</span> },
          { key: 'ttl', header: 'TTL', render: (r) => TTLS.find(([v]) => v === r.ttl)?.[1] ?? `${r.ttl}s` },
          { key: 'proxied', header: 'Proxy', render: (r) => canProxy && PROXIABLE.includes(String(r.type)) ? <Badge tone={r.proxied ? 'g' : 'n'}>{r.proxied ? 'proxied' : 'DNS only'}</Badge> : '' },
        ]} />
      <div className="p-4 flex justify-between text-xs text-muted">
        <span>{z.last_synced_at ? `Synced ${ago(String(z.last_synced_at))}` : ''}</span>
        <button type="button" className="btn btn-sm" onClick={() => setConfirm('zone')}>Remove from panel</button>
      </div>

      <Modal open={!!editing} onClose={() => setEditing(null)} title={editing?.id ? 'Edit record' : 'Add record'}
        footer={<button type="button" className="btn btn-primary" disabled={busy || !form.content} onClick={save}>Save</button>}>
        <label className="label mb-3 block">Type
          <Select className="input" value={String(form.type)} disabled={!!editing?.id} onChange={(e) => setForm({ ...form, type: e.target.value })}>
            {TYPES.map((t) => <option key={t}>{t}</option>)}
          </Select>
        </label>
        <label className="label mb-3 block">Name (@ for {String(z.name)})<input className="input" value={String(form.name)} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
        <label className="label mb-3 block">Content<input className="input" value={String(form.content)} onChange={(e) => setForm({ ...form, content: e.target.value })} /></label>
        {['MX', 'SRV'].includes(String(form.type)) && <label className="label mb-3 block">Priority<input className="input" type="number" min={0} value={Number(form.priority)} onChange={(e) => setForm({ ...form, priority: Number(e.target.value) })} /></label>}
        <label className="label mb-3 block">TTL
          <Select className="input" value={Number(form.ttl)} disabled={canProxy && !!form.proxied} onChange={(e) => setForm({ ...form, ttl: Number(e.target.value) })}>
            {TTLS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </Select>
        </label>
        {canProxy && PROXIABLE.includes(String(form.type)) && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!form.proxied} onChange={(e) => setForm({ ...form, proxied: e.target.checked })} /> Proxy through Cloudflare</label>}
      </Modal>

      <Modal open={importOpen} onClose={() => setImportOpen(false)} title={`Import into ${z.name}`} width={640}
        footer={<>
          <button type="button" className="btn" disabled={busy || !zoneFile.trim()} onClick={() => importFile(true)}>Preview</button>
          <button type="button" className="btn btn-primary" disabled={busy || !preview || !(preview.created as R[]).length} onClick={() => importFile(false)}>
            Import {preview ? (preview.created as R[]).length : ''} records
          </button>
        </>}>
        <p className="m-0 mb-3 text-sm">Paste a BIND zone file or choose one. New records are added; records already in the zone are left as they are, and SOA is skipped.</p>
        <input type="file" accept=".zone,.txt,.db,text/plain" className="mb-3 text-sm" aria-label="Zone file"
          onChange={(e) => { const f = e.target.files?.[0]; if (f) f.text().then((t) => { setZoneFile(t); setPreview(null) }) }} />
        <textarea className="input font-mono text-xs w-full" rows={10} aria-label="Zone file contents" value={zoneFile}
          onChange={(e) => { setZoneFile(e.target.value); setPreview(null) }} placeholder={'$ORIGIN example.com.\n@  3600 IN A 203.0.113.10'} />
        {preview && (
          <div className="mt-3 text-sm">
            <p className="m-0">{(preview.created as R[]).length} to add · {String(preview.unchanged)} already in the zone · {(preview.skipped as R[]).length} skipped</p>
            {(preview.skipped as R[]).length > 0 && <ul className="m-0 mt-1 text-xs text-muted">{(preview.skipped as R[]).map((k) => <li key={String(k.line)}>Line {String(k.line)}: {String(k.reason)}</li>)}</ul>}
          </div>
        )}
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
