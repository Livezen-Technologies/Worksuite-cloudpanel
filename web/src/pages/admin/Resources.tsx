import { useEffect, useState } from 'react'
import { api } from '../../api/client'
import { useAction, useList, useServerName } from '../../api/hooks'
import type { Server } from '../../api/types'
import { Card, Meter, PageHeader, StatTile, Empty } from '../../components/Card'
import { Badge, StatusBadge, toneFor } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, Modal } from '../../components/Overlay'
import { FormModal } from '../../components/FormModal'
import { useToast } from '../../components/Toasts'
import { ago, daysUntil, mb, str, unwrap } from '../../lib/format'
import { num, type R } from '../../lib/util'
import Select from '../../components/Select'

const mono = (v: unknown) => <span className="font-mono text-[11.5px] text-muted">{str(v)}</span>
function useServerOptions() {
  const { rows } = useList<Server>('/servers')
  return rows.map((s) => ({ value: s.id, label: s.name }))
}

function DeleteConfirm({ target, onClose, path, noun }: { target: R | null; onClose: () => void; path: (r: R) => string; noun: string }) {
  const { run, busy } = useAction()
  return (
    <ConfirmDialog open={!!target} onClose={onClose} danger confirmText={target?.name ?? target?.hostname} title={`Delete ${noun} ${target?.name ?? target?.hostname ?? ''}?`} confirmLabel={`Delete ${noun}`} busy={busy}
      onConfirm={() => run({ method: 'DELETE', path: path(target!), label: `Delete ${noun}`, success: `${noun[0].toUpperCase()}${noun.slice(1)} deleted` }).then(onClose).catch(() => {})}>
      <p className="m-0">This cannot be undone.</p>
    </ConfirmDialog>
  )
}

function PrunePreview({ open, onClose, previewPath, prunePath, noun }: { open: boolean; onClose: () => void; previewPath: string; prunePath: string; noun: string }) {
  const serverName = useServerName()
  const [items, setItems] = useState<R[] | null>(null)
  const [meta, setMeta] = useState<R>({})
  const { run, busy } = useAction()
  const toast = useToast()
  useEffect(() => {
    if (!open) return
    api.post(previewPath).then((r) => { const d = unwrap<R>(r); setMeta(d); setItems((Array.isArray(d) ? d : d.images ?? d.items ?? []) as R[]) }).catch((e) => { toast.error(e, 'Preview failed'); onClose() })
  }, [open]) // eslint-disable-line react-hooks/exhaustive-deps
  const close = () => { setItems(null); onClose() }
  return (
    <Modal open={open} onClose={close} title={`Prune unused ${noun}`}
      footer={<><button type="button" className="btn" onClick={close}>Cancel</button><button type="button" className="btn btn-primary" disabled={busy || !items?.length} onClick={() => run({ path: prunePath, body: { ids: (items ?? []).map((x) => x.id).filter(Boolean) }, label: `Prune ${noun}` }).then(close).catch(() => {})}>Prune {items?.length ?? 0}</button></>}>
      {!items ? 'Building preview…' : items.length === 0 ? 'Nothing to prune.' : (
        <>
          <p className="mt-0 text-muted">These will be removed:</p>
          <ul className="m-0 pl-5">{items.map((x, i) => <li key={x.id ?? i}><span className="font-mono text-xs">{x.repository ?? x.name ?? x.id}:{x.tag ?? ''}</span> <span className="text-muted text-xs">{x.size ?? mb(x.size_mb)} · {serverName(x)}</span></li>)}</ul>
          {meta.reclaimable && <p className="font-semibold">Reclaimable: {str(meta.reclaimable)}</p>}
        </>
      )}
    </Modal>
  )
}

/** Image rows carry `tags: ["repo:tag", ...]` (docs/API.md); show just the tag parts. */
function imageTags(r: R): string {
  const tags = (r.tags ?? []) as string[]
  return tags.map((t) => (t.includes(':') ? t.slice(t.lastIndexOf(':') + 1) : t)).join(', ') || '<none>'
}

export function Images() {
  const serverName = useServerName()
  const regs = useList<R>('/registries')
  const images = useList<R>('/images')
  const { run } = useAction()
  const [addReg, setAddReg] = useState(false)
  const [pull, setPull] = useState(false)
  const serverOptions = useServerOptions()
  const [prune, setPrune] = useState(false)
  const [del, setDel] = useState<R | null>(null)
  const dangling = images.rows.filter((i) => i.dangling || i.tag === '<none>')
  return (
    <>
      <PageHeader title="Images & Registry" subtitle="Images on each server, connected registries and vulnerability scans"
        actions={<><button type="button" className="btn" onClick={() => setPull(true)}>Pull image</button><button type="button" className="btn btn-primary" onClick={() => setAddReg(true)}>Add registry</button></>} />
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(240px,1fr))]">
        {regs.rows.map((r) => (
          <div key={r.id} className="card p-4 flex flex-col gap-1.5">
            <div className="flex justify-between items-center gap-2"><span className="font-semibold">{r.name}</span><StatusBadge status={r.status ?? 'connected'} /></div>
            <div className="text-xs text-muted font-mono truncate">{r.url ?? r.type}</div>
            <span className="flex gap-2 mt-1">
              {r.type === 'private' && <button type="button" className="btn btn-sm" onClick={async () => {
                const prev = await run<R>({ path: `/registries/${r.id}/gc`, body: { dry_run: true }, label: 'Check old images', silent: true }).catch(() => null)
                const n = ((prev as R)?.data?.images as R[] | undefined)?.length ?? 0
                if (!n) { window.alert('Nothing to clean up: only the last releases of each app are stored.'); return }
                if (window.confirm(`Delete ${n} old app image${n === 1 ? '' : 's'} from ${r.name}? The last 5 releases of each app are kept; older ones can no longer be rolled back to.`)) {
                  run({ path: `/registries/${r.id}/gc`, body: { dry_run: false }, label: 'Clean up registry', success: 'Old images deleted' }).catch(() => {})
                }
              }}>Clean up</button>}
              <button type="button" className="btn btn-sm btn-danger" onClick={() => setDel(r)}>Remove</button>
            </span>
          </div>
        ))}
        {regs.rows.length === 0 && !regs.isLoading && <Card><Empty>No registries connected.</Empty></Card>}
      </div>
      <Card title="Images" pad={false} actions={<><span className="text-xs text-muted">{dangling.length} dangling</span><button type="button" className="btn btn-sm" onClick={() => setPrune(true)}>Prune unused</button></>}>
        <DataTable label="Images" rows={images.rows} loading={images.isLoading} rowKey={(r) => r.id} minWidth={900}
          filters={[{ id: 'vuln', label: 'Critical / high', test: (r) => num((r.scan_result ?? r.scan)?.critical ?? r.critical) + num((r.scan_result ?? r.scan)?.high ?? r.high) > 0 }, { id: 'dangling', label: 'Dangling', test: (r) => !!r.dangling || r.tag === '<none>' }]}
          columns={[
            { key: 'repository', header: 'Repository', value: (r) => r.repository ?? r.name, render: (r) => <span className="font-semibold">{r.repository ?? r.name}</span> },
            { key: 'tag', header: 'Tag', render: (r) => mono(r.tag ?? imageTags(r)) },
            { key: 'digest', header: 'Digest', render: (r) => mono(String(r.digest ?? '').slice(0, 19)) },
            { key: 'size', header: 'Size', value: (r) => num(r.size_mb), render: (r) => r.size ?? mb(r.size_mb) },
            { key: 'containers', header: 'Used by', value: (r) => num(r.users ?? r.container_count ?? r.containers), render: (r) => `${num(r.users ?? r.container_count ?? r.containers)} containers` },
            { key: 'server', header: 'Server', value: (r) => serverName(r) },
            { key: 'scan', header: 'Scan', render: (r) => { const sr = r.scan_result ?? r.scan; const c = num(sr?.critical ?? r.critical), h = num(sr?.high ?? r.high), m = num(sr?.medium ?? r.medium); const scanned = sr || r.scanned_at || (r.scan_status && r.scan_status !== 'not_scanned'); if (r.scan_status === 'scanning') return <Badge tone="b">scanning…</Badge>; if (r.scan_status === 'failed') return <Badge tone="y">scan failed</Badge>; return !scanned ? <Badge tone="n">not scanned</Badge> : <span className="inline-flex gap-1.5 flex-wrap"><Badge tone={c ? 'r' : h ? 'r' : m ? 'y' : 'g'}>{c || h || m ? `${c} critical · ${h} high · ${m} medium · ${num(sr?.low ?? r.low)} low` : 'clean'}</Badge>{r.blocks_deploy && <Badge tone="r">blocks deploys</Badge>}</span> } },
            { key: 'act', header: '', sortable: false, render: (r) => <button type="button" className="btn btn-sm btn-ghost" onClick={() => run({ path: `/images/${r.id}/scan`, label: `Scan ${r.repository ?? r.name}` }).catch(() => {})}>Scan</button> },
          ]} />
      </Card>
      <FormModal open={addReg} onClose={() => setAddReg(false)} title="Add registry" submitLabel="Connect registry"
        fields={[{ name: 'name', label: 'Name', required: true }, { name: 'type', label: 'Type', type: 'select', options: ['ghcr', 'dockerhub', 'private'], required: true }, { name: 'url', label: 'Registry URL', mono: true, placeholder: 'ghcr.io' }, { name: 'username', label: 'Username' }, { name: 'password', label: 'Password or token', type: 'password', hint: 'Stored encrypted; never shown again' }]}
        initial={{ type: 'ghcr' }} onSubmit={(v) => run({ path: '/registries', body: v, label: 'Add registry', success: 'Registry connected' })} />
      <FormModal open={pull} onClose={() => setPull(false)} title="Pull image" submitLabel="Pull image"
        fields={[{ name: 'server_id', label: 'Server', type: 'select', options: serverOptions, required: true }, { name: 'image', label: 'Image', mono: true, required: true, placeholder: 'nginx:1.27-alpine' }, { name: 'registry_id', label: 'Registry credentials', type: 'select', options: [...regs.rows.map((r) => ({ value: String(r.id), label: String(r.name) }))] }]}
        onSubmit={(v) => run({ path: '/images/pull', body: { ...v, registry_id: v.registry_id || null }, label: `Pull ${v.image}`, success: 'Pull started' })} />
      <PrunePreview open={prune} onClose={() => setPrune(false)} previewPath="/images/prune-preview" prunePath="/images/prune" noun="images" />
      <DeleteConfirm target={del} onClose={() => setDel(null)} path={(r) => `/registries/${r.id}`} noun="registry" />
    </>
  )
}

export function Networks() {
  const serverName = useServerName()
  const { rows, isLoading } = useList<R>('/networks')
  const servers = useServerOptions()
  const { run } = useAction()
  const [create, setCreate] = useState(false)
  const [check, setCheck] = useState<{ ok: boolean; issues: string[] } | null>(null)
  const [del, setDel] = useState<R | null>(null)
  const runCheck = async (v: Record<string, unknown>) => {
    const r = unwrap<R>(await api.post('/networks/check', v))
    const issues = ((r.conflicts ?? r.issues ?? []) as (string | R)[]).map((x) => (typeof x === 'string' ? x : x.message ?? `${x.type}: ${x.detail ?? ''}`))
    setCheck({ ok: r.ok ?? issues.length === 0, issues })
  }
  return (
    <>
      <PageHeader title="Networks" subtitle="Bridge networks, approved host networking and the encrypted WireGuard mesh between servers"
        actions={<button type="button" className="btn btn-primary" onClick={() => { setCheck(null); setCreate(true) }}>Create network</button>} />
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
        <StatTile label="Networks" value={rows.length} />
        <StatTile label="Publicly exposed" value={rows.filter((n) => /public/i.test(String(n.exposure))).length} />
        <StatTile label="Host networking" value={rows.filter((n) => n.driver === 'host').length} delta="approved exceptions" />
        <StatTile label="Mesh peers" value={rows.filter((n) => /wireguard/i.test(String(n.driver))).length} />
      </div>
      <DataTable label="Networks" rows={rows} loading={isLoading} rowKey={(r) => r.id}
        columns={[
          { key: 'name', header: 'Name', render: (r) => <span className="font-semibold">{r.name}</span> },
          { key: 'driver', header: 'Driver' },
          { key: 'subnet', header: 'Subnet', render: (r) => mono(r.subnet) },
          { key: 'server', header: 'Server', value: (r) => serverName(r) },
          { key: 'containers', header: 'Containers', value: (r) => num(r.container_count ?? r.containers) },
          { key: 'exposure', header: 'Exposure', render: (r) => <Badge tone={/public/i.test(String(r.exposure)) ? 'b' : /approved|exception/i.test(String(r.exposure)) ? 'y' : 'g'}>{r.exposure ?? 'private'}</Badge> },
          { key: 'del', header: '', sortable: false, render: (r) => <button type="button" className="btn btn-sm btn-danger" onClick={() => setDel(r)}>Delete</button> },
        ]} />
      <p className="m-0 text-xs text-muted">Every network change is audited and checked for port conflicts, IP conflicts and accidental public exposure before it is applied.</p>
      <FormModal open={create} onClose={() => setCreate(false)} title="Create network" submitLabel="Create network" initial={{ driver: 'bridge' }}
        fields={[{ name: 'name', label: 'Name', required: true }, { name: 'server_id', label: 'Server', type: 'select', options: servers, required: true }, { name: 'driver', label: 'Driver', type: 'select', options: ['bridge', 'host'], required: true }, { name: 'subnet', label: 'Subnet', mono: true, placeholder: '172.30.0.0/24', show: (v) => v.driver !== 'host' }, { name: 'gateway', label: 'Gateway', mono: true, show: (v) => v.driver !== 'host' }]}
        extra={(v) => (
          <div className="flex flex-col gap-2">
            {v.driver === 'host' && <p className="m-0 text-sm" style={{ color: 'var(--y-fg)' }}>Host networking needs approval before it is applied.</p>}
            <div className="flex gap-2 items-center flex-wrap">
              <button type="button" className="btn btn-sm" onClick={() => runCheck(v).catch(() => setCheck({ ok: false, issues: ['Check failed — try again.'] }))}>Check for conflicts</button>
              {check && <Badge tone={check.ok ? 'g' : 'r'}>{check.ok ? 'no conflicts' : `${check.issues.length} conflicts`}</Badge>}
            </div>
            {check && !check.ok && <ul className="m-0 pl-5 text-sm text-danger">{check.issues.map((i) => <li key={i}>{i}</li>)}</ul>}
          </div>
        )}
        onSubmit={async (v) => { if (!check?.ok) { await runCheck(v); throw new Error('check first') } return run({ path: '/networks', body: v, label: `Create ${v.name}` }) }} />
      <DeleteConfirm target={del} onClose={() => setDel(null)} path={(r) => `/networks/${r.id}`} noun="network" />
    </>
  )
}

export function Volumes() {
  const serverName = useServerName()
  const pools = useList<R>('/storage-pools')
  const { rows, isLoading } = useList<R>('/volumes')
  const servers = useServerOptions()
  const { run } = useAction()
  const [create, setCreate] = useState(false)
  const [del, setDel] = useState<R | null>(null)
  return (
    <>
      <PageHeader title="Volumes & Storage" subtitle="Named volumes, bind mounts, block storage, NFS and object storage for backups"
        actions={<button type="button" className="btn btn-primary" onClick={() => setCreate(true)}>Create volume</button>} />
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
        {pools.rows.map((p) => {
          const used = num(p.used_pct ?? (num(p.capacity_gb) ? (num(p.used_gb) / num(p.capacity_gb)) * 100 : 0))
          return (
            <div key={p.id ?? p.name} className="card p-5">
              <div className="flex justify-between text-sm"><span className="font-semibold">{p.name}</span><span className="font-semibold">{Math.round(used)}%</span></div>
              <div className="my-3"><Meter value={used} label={`${p.name} capacity used`} color={used >= 85 ? 'var(--r-fg)' : used >= 70 ? 'var(--y-fg)' : undefined} thick /></div>
              <div className="text-xs text-muted">{p.type} · {num(p.used_gb)} of {num(p.capacity_gb)} GB</div>
            </div>
          )
        })}
      </div>
      <DataTable label="Volumes" rows={rows} loading={isLoading} rowKey={(r) => r.id}
        filters={[{ id: 'nobackup', label: 'No backup policy', test: (r) => !r.backup_policy && !r.backup_policy_id }]}
        columns={[
          { key: 'name', header: 'Volume', render: (r) => <span className="font-mono text-xs font-semibold">{r.name}</span> },
          { key: 'type', header: 'Type' },
          { key: 'server', header: 'Server', value: (r) => serverName(r) },
          { key: 'size', header: 'Size', value: (r) => num(r.size_mb ?? r.size_gb), render: (r) => r.size ?? (r.size_gb ? `${r.size_gb} GB` : mb(r.size_mb)) },
          { key: 'consumers', header: 'Used by', value: (r) => str(r.consumers ?? r.containers) },
          { key: 'backup', header: 'Backup', render: (r) => (r.backup_policy || r.backup_policy_id) ? <StatusBadge status={r.backup_status ?? 'protected'} /> : <Badge tone="y">no policy</Badge> },
          { key: 'del', header: '', sortable: false, render: (r) => <button type="button" className="btn btn-sm btn-danger" onClick={() => setDel(r)}>Delete</button> },
        ]} />
      <FormModal open={create} onClose={() => setCreate(false)} title="Create volume" submitLabel="Create volume" initial={{ type: 'named' }}
        fields={[{ name: 'name', label: 'Name', required: true, mono: true }, { name: 'server_id', label: 'Server', type: 'select', options: servers, required: true }, { name: 'type', label: 'Type', type: 'select', options: [{ value: 'named', label: 'Named volume' }, { value: 'bind', label: 'Bind mount' }, { value: 'nfs', label: 'NFS mount' }], required: true }, { name: 'path', label: 'Host path', mono: true, show: (v) => v.type === 'bind' }, { name: 'nfs_server', label: 'NFS server', mono: true, show: (v) => v.type === 'nfs' }, { name: 'nfs_path', label: 'NFS export path', mono: true, show: (v) => v.type === 'nfs' }, { name: 'quota_gb', label: 'Quota (GB)', type: 'number' }]}
        onSubmit={(v) => run({ path: '/volumes', body: v, label: `Create ${v.name}` })} />
      <DeleteConfirm target={del} onClose={() => setDel(null)} path={(r) => `/volumes/${r.id}`} noun="volume" />
    </>
  )
}

/** Domain shape per docs/API.md appendix: name, app_name, target_port, dns_status, https_status, certificate{status,expires_at,days_left}. */
const certExpiry = (d: R): string | null => d.certificate?.expires_at ?? d.certificate_expires_at ?? null
const certDays = (d: R): number | null => (d.certificate?.days_left != null ? Math.floor(num(d.certificate.days_left)) : daysUntil(certExpiry(d)))
function certState(d: R): string {
  const days = certDays(d)
  if (d.dns_status && d.dns_status !== 'verified') return 'pending DNS'
  if (days !== null && days < 0) return 'expired'
  if (days !== null && days <= 14) return 'expiring'
  if (d.https_status === 'active' || d.certificate?.status === 'active') return 'valid'
  return d.https_status ?? d.certificate?.status ?? 'pending'
}
const domainName = (d: R): string => d.name ?? d.domain ?? d.hostname

export function Domains() {
  const { rows, isLoading } = useList<R>('/domains')
  const apps = useList<R>('/applications')
  const { run } = useAction()
  const [add, setAdd] = useState(false)
  const [del, setDel] = useState<R | null>(null)
  const expiring = rows.filter((d) => ['expiring', 'expired'].includes(certState(d)))
  return (
    <>
      <PageHeader title="Domains & SSL" subtitle="Traefik routes, Let’s Encrypt certificates and DNS readiness for every organisation"
        actions={<button type="button" className="btn btn-primary" onClick={() => setAdd(true)}>Add domain</button>} />
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
        <StatTile label="Domains" value={rows.length} />
        <StatTile label="Valid certificates" value={rows.filter((d) => certState(d) === 'valid').length} />
        <StatTile label="Expiring ≤ 14 days" value={expiring.length} deltaTone={expiring.length ? 'y' : 'n'} delta={expiring.length ? 'needs attention' : undefined} />
        <StatTile label="Pending DNS" value={rows.filter((d) => certState(d) === 'pending DNS').length} />
      </div>
      <DataTable label="Domains" rows={rows} loading={isLoading} rowKey={(r) => r.id} minWidth={900}
        filters={[
          { id: 'expiring', label: 'Expiring', test: (d) => ['expiring', 'expired'].includes(certState(d)) },
          { id: 'pending', label: 'Pending', test: (d) => certState(d).startsWith('pending') },
          { id: 'wildcard', label: 'Wildcard', test: (d) => String(domainName(d)).startsWith('*') || !!d.wildcard },
        ]}
        columns={[
          { key: 'hostname', header: 'Domain', value: (r) => domainName(r), render: (r) => <span className="font-mono text-xs font-semibold">{domainName(r)}</span> },
          { key: 'app', header: 'Application', value: (r) => r.app_name ?? r.application_name ?? r.application?.name ?? '—' },
          { key: 'https', header: 'HTTPS', render: (r) => <StatusBadge status={r.https_status ?? 'pending'} /> },
          { key: 'port', header: 'Port', value: (r) => r.target_port ?? r.port ?? '—' },
          { key: 'cert', header: 'Certificate', value: (r) => certState(r), render: (r) => <Badge tone={toneFor(certState(r).split(' ')[0])}>{certState(r)}</Badge> },
          { key: 'expires', header: 'Expires', value: (r) => certDays(r) ?? 9999, render: (r) => { const d = certDays(r); return d === null ? (r.https_status === 'active' ? 'n/a' : 'no certificate') : `${d} days` } },
          { key: 'waf', header: 'WAF', render: (r) => (
            <Select className="input h-8 text-sm" aria-label={`Web application firewall for ${domainName(r)}`} value={String(r.options?.waf?.mode ?? 'off')}
              onChange={(e) => run({ path: `/domains/${r.id}/waf`, method: 'PATCH', body: { mode: e.target.value }, label: `WAF for ${domainName(r)}` }).catch(() => {})}>
              <option value="off">Off</option><option value="detect">Detect only</option><option value="block">Block</option>
            </Select>
          ) },
          { key: 'challenge', header: 'Challenge', value: (r) => r.challenge ? `${r.challenge}${r.dns_zone ? ` via ${r.dns_zone.name}` : ''}` : '—' },
          { key: 'act', header: '', sortable: false, render: (r) => (
            <span className="flex gap-2">
              <button type="button" className="btn btn-sm btn-ghost" onClick={() => run({ path: `/domains/${r.id}/verify`, label: `Check DNS for ${domainName(r)}` }).catch(() => {})}>Check DNS</button>
              <button type="button" className="btn btn-sm btn-ghost" onClick={() => run({ path: `/domains/${r.id}/certificate`, label: `Issue certificate for ${domainName(r)}` }).catch(() => {})}>Issue cert</button>
              <button type="button" className="btn btn-sm btn-ghost btn-danger" onClick={() => setDel({ ...r, name: domainName(r) })}>Delete</button>
            </span>) },
        ]} />
      {expiring.map((d) => <div key={d.id} role="alert" className="card p-3 text-sm" style={{ background: 'var(--y-bg)', color: 'var(--y-fg)' }}>{domainName(d)}: the certificate expires in {certDays(d)} days{d.last_error ? ` — ${d.last_error}` : ''}.</div>)}
      <FormModal open={add} onClose={() => setAdd(false)} title="Add domain" submitLabel="Add domain" initial={{ challenge: 'auto', target_port: 80 }}
        fields={[{ name: 'name', label: 'Domain', required: true, mono: true, placeholder: 'app.example.com' }, { name: 'application_id', label: 'Application', type: 'select', options: apps.rows.map((a) => ({ value: a.id, label: a.name })), required: true }, { name: 'target_port', label: 'Internal port', type: 'number' }, { name: 'challenge', label: 'Certificate challenge', type: 'select', options: ['auto', 'http-01', 'dns-01'], required: true, hint: 'auto: DNS-01 through the DNS zone when the domain is in one, otherwise HTTP-01' }, { name: 'wildcard', label: 'Wildcard certificate', type: 'checkbox', hint: 'requires DNS-01' }, { name: 'dns_provider', label: 'DNS provider (DNS-01)', type: 'select', options: ['cloudflare', 'digitalocean', 'hetzner', 'route53', 'gandiv5'], show: (v) => v.challenge === 'dns-01' || !!v.wildcard }, { name: 'dns_token', label: 'DNS API token', type: 'password', hint: 'write-only, stored encrypted; route53: JSON {"AWS_ACCESS_KEY_ID":…,"AWS_SECRET_ACCESS_KEY":…}', show: (v) => v.challenge === 'dns-01' || !!v.wildcard }, { name: 'www_redirect', label: 'Redirect www', type: 'checkbox' }, { name: 'websocket', label: 'WebSocket routing', type: 'checkbox' }, { name: 'headers', label: 'Custom headers', type: 'textarea', mono: true, placeholder: 'X-Frame-Options: DENY' }]}
        onSubmit={(v) => run({ path: '/domains', body: { name: v.name, application_id: v.application_id, target_port: v.target_port ? Number(v.target_port) : null, ...(v.wildcard ? { challenge: 'dns-01' } : v.challenge !== 'auto' ? { challenge: v.challenge } : {}), www_redirect: !!v.www_redirect, ...(v.dns_provider ? { dns_provider: v.dns_provider } : {}), ...(v.dns_token ? { dns_token: v.dns_token } : {}), options: { websocket: !!v.websocket, headers: String(v.headers ?? '').split('\n').filter(Boolean) } }, label: `Add ${v.name}`, success: 'Domain added — DNS check started' })} />
      <DeleteConfirm target={del} onClose={() => setDel(null)} path={(r) => `/domains/${r.id}`} noun="domain" />
    </>
  )
}

const ENGINES: Record<string, string> = { postgresql: 'PG', mysql: 'MY', mariadb: 'MD', redis: 'RD', mongodb: 'MG' }
export function Databases() {
  const serverName = useServerName()
  const { rows, isLoading } = useList<R>('/databases', undefined, { refetchInterval: 20000 })
  const servers = useServerOptions()
  const { run, busy } = useAction()
  const [create, setCreate] = useState(false)
  const [restore, setRestore] = useState<R | null>(null)
  return (
    <>
      <PageHeader title="Databases" subtitle="Managed database containers with application-consistent backups"
        actions={<button type="button" className="btn btn-primary" onClick={() => setCreate(true)}>New database</button>} />
      {isLoading ? <Card>Loading…</Card> : rows.length === 0 ? <Card><Empty>No databases yet.</Empty></Card> : (
        <ul className="list-none m-0 p-0 grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(300px,1fr))]">
          {rows.map((d) => (
            <li key={d.id} className="card p-5 flex flex-col gap-3">
              <div className="flex items-center gap-3">
                <span aria-hidden="true" className="w-10 h-10 rounded-lg bg-track flex items-center justify-center font-bold text-sm">{ENGINES[String(d.engine).toLowerCase()] ?? String(d.engine ?? '?').slice(0, 2).toUpperCase()}</span>
                <div className="flex-1 min-w-0"><div className="font-semibold truncate">{d.name}</div><div className="text-xs text-muted">{d.engine} {d.version} · {serverName(d)}</div></div>
                <StatusBadge status={d.status ?? 'healthy'} />
              </div>
              <dl className="m-0 grid grid-cols-3 gap-2 text-sm">
                <div><dt className="text-xs text-muted">Size</dt><dd className="m-0 font-semibold">{d.size ?? mb(d.size_mb)}</dd></div>
                <div><dt className="text-xs text-muted">Connections</dt><dd className="m-0 font-semibold">{d.connections ?? '—'}</dd></div>
                <div><dt className="text-xs text-muted">Last backup</dt><dd className="m-0 font-semibold">{ago(d.last_backup_at)}</dd></div>
              </dl>
              <div className="flex gap-2 flex-wrap">
                <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/databases/${d.id}/backup`, label: `Back up ${d.name}` }).catch(() => {})}>Back up now</button>
                <button type="button" className="btn btn-sm" onClick={() => setRestore(d)}>Restore</button>
              </div>
              <div className="text-xs text-muted">Credentials: <span className="font-mono">{d.secret_ref ?? d.credentials_ref ?? 'secret://' + d.name}</span></div>
            </li>
          ))}
        </ul>
      )}
      <p className="m-0 text-xs text-muted">Credentials are stored as encrypted secrets and injected by reference — never shown in container environment listings.</p>
      <FormModal open={create} onClose={() => setCreate(false)} title="New database" submitLabel="Create database" initial={{ engine: 'postgresql' }}
        fields={[{ name: 'name', label: 'Name', required: true }, { name: 'engine', label: 'Template', type: 'select', required: true, options: [{ value: 'postgresql', label: 'PostgreSQL' }, { value: 'mysql', label: 'MySQL' }, { value: 'mariadb', label: 'MariaDB' }, { value: 'redis', label: 'Redis' }, { value: 'mongodb', label: 'MongoDB' }] }, { name: 'version', label: 'Version', placeholder: 'latest' }, { name: 'server_id', label: 'Server', type: 'select', options: servers, required: true }]}
        onSubmit={(v) => run({ path: '/databases', body: v, label: `Create ${v.name}` })} />
      <ConfirmDialog open={!!restore} onClose={() => setRestore(null)} danger confirmText={restore?.name} title={`Restore ${restore?.name}?`} confirmLabel="Restore" busy={busy}
        onConfirm={() => run({ path: `/databases/${restore!.id}/restore`, body: {}, label: `Restore ${restore!.name}` }).then(() => setRestore(null)).catch(() => {})}>
        <p className="m-0">The latest backup replaces current data. Connected apps may see errors until the restore finishes.</p>
      </ConfirmDialog>
    </>
  )
}
