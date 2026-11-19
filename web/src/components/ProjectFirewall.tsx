import { useState } from 'react'
import { isApiError } from '../api/client'
import { useAction, useOne } from '../api/hooks'
import { Card, Empty, StatTile } from './Card'
import { Badge } from './Badge'
import { ConfirmDialog } from './Overlay'
import { AppLockButton } from './AppLockButton'
import Select from './Select'
import { ago } from '../lib/format'
import { num, type R } from '../lib/util'

/**
 * Project → Firewall: everything protecting this project's apps. App lock/unlock, IP allow and block
 * entries for the whole project or one app, and read-only lists of the rate-limit and ISP rules and
 * recent blocked requests. `base` is '' for admin and '/app' for the customer portal.
 */
export function ProjectFirewall({ projectId, base = '' }: { projectId: string; base?: string }) {
  const d = useOne<R>(`${base}/projects/${projectId}/firewall`, undefined, { refetchInterval: 20000 })
  const { run, busy } = useAction()
  const [f, setF] = useState({ list: 'block', cidr: '', note: '', scope: '', hours: '' })
  const [self, setSelf] = useState<string | null>(null)
  const x = d.item ?? {}
  const apps = (x.apps ?? []) as R[]
  const ips = (x.ips ?? []) as R[]
  const rules = (x.rules ?? []) as R[]
  const isps = (x.isps ?? []) as R[]
  const events = (x.events ?? []) as R[]
  const org = (x.org_wide ?? {}) as R
  const add = (confirm = false) => run({ path: `${base}/projects/${projectId}/firewall/ips`, label: f.list === 'allow' ? 'Allow address' : 'Block address', success: `${f.cidr} ${f.list === 'allow' ? 'allowed' : 'blocked'}`,
    body: { list: f.list, cidr: f.cidr.trim(), note: f.note || null, application_id: f.scope || null, expires_hours: f.hours ? Number(f.hours) : null, ...(confirm ? { confirm: true } : {}) } })
    .then(() => { setF({ ...f, cidr: '', note: '' }); setSelf(null); d.refetch() })
    .catch((e) => { if (isApiError(e) && e.status === 409 && /own address/.test(e.message)) setSelf(e.message) })

  if (d.isLoading) return <p className="m-0 text-sm text-muted">Loading…</p>
  return (
    <div className="flex flex-col gap-3">
      <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
        <StatTile label="Blocked (24 h)" value={num(x.blocked_24h)} />
        <StatTile label="Blocked IPs" value={ips.filter((i) => i.list === 'block').length} />
        <StatTile label="Allowed IPs" value={ips.filter((i) => i.list === 'allow').length} />
        <StatTile label="Locked apps" value={apps.filter((a) => !a.public_access).length} />
      </div>

      <Card title="Application access" pad={false}>
        {!apps.length ? <div className="p-5"><Empty>No applications in this project.</Empty></div> :
          <ul className="list-none m-0 p-0">{apps.map((a) => (
            <li key={a.id} className="flex flex-wrap items-center gap-3 px-4 sm:px-5 py-2.5 border-t border-line first:border-t-0 text-[13.5px]">
              <span className="flex-1 min-w-0 font-semibold truncate">{a.name}</span>
              {a.public_access ? <Badge tone="g">public</Badge> : <Badge tone="r" >locked{a.lock_reason ? `: ${a.lock_reason}` : ''}</Badge>}
              <AppLockButton app={a} base={base ? '/app/apps' : '/applications'} onChange={() => d.refetch()} />
            </li>))}</ul>}
      </Card>

      <Card title="IP allow and block lists" pad={false}>
        <div className="flex flex-wrap items-end gap-2 px-4 sm:px-5 pb-3">
          <label className="text-sm"><span className="block text-xs text-muted mb-1">List</span><Select className="input !w-auto" value={f.list} onChange={(e) => setF({ ...f, list: e.target.value })}><option value="block">Block</option><option value="allow">Allow</option></Select></label>
          <label className="text-sm"><span className="block text-xs text-muted mb-1">Address or range</span><input className="input font-mono !w-48" placeholder="203.0.113.0/24" value={f.cidr} onChange={(e) => setF({ ...f, cidr: e.target.value })} /></label>
          <label className="text-sm"><span className="block text-xs text-muted mb-1">Applies to</span><Select className="input !w-auto" value={f.scope} onChange={(e) => setF({ ...f, scope: e.target.value })}>
            <option value="">Whole project</option>{apps.map((a) => <option key={a.id} value={a.id}>App · {a.name}</option>)}</Select></label>
          <label className="text-sm"><span className="block text-xs text-muted mb-1">Expires</span><Select className="input !w-auto" value={f.hours} onChange={(e) => setF({ ...f, hours: e.target.value })}>
            <option value="">Never</option><option value="1">1 hour</option><option value="24">24 hours</option><option value="168">7 days</option><option value="720">30 days</option></Select></label>
          <label className="text-sm flex-1 min-w-[140px]"><span className="block text-xs text-muted mb-1">Note</span><input className="input" value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} /></label>
          <button type="button" className="btn btn-primary" disabled={busy || !f.cidr.trim()} onClick={() => add()}>Add</button>
        </div>
        {!ips.length ? <div className="px-5 pb-5"><Empty>No addresses listed for this project.</Empty></div> :
          <ul className="list-none m-0 p-0">{ips.map((i) => (
            <li key={i.id} className="flex flex-wrap items-center gap-3 px-4 sm:px-5 py-2.5 border-t border-line text-[13.5px]">
              <span className="font-mono text-xs w-40">{i.cidr}</span>
              <Badge tone={i.list === 'allow' ? 'g' : 'r'}>{i.list}</Badge>
              <span className="flex-1 min-w-0 truncate text-muted text-xs">{i.scope}{i.note ? ` · ${i.note}` : ''}{i.expires_at ? ` · until ${new Date(String(i.expires_at)).toLocaleString()}` : ''}</span>
              <button type="button" className="btn btn-sm btn-ghost" disabled={busy} onClick={() => run({ method: 'DELETE', path: `${base}/projects/${projectId}/firewall/ips/${i.id}`, label: 'Remove address', success: `${i.cidr} removed` }).then(() => d.refetch()).catch(() => {})}>Remove</button>
            </li>))}</ul>}
      </Card>

      <div className="grid gap-3 lg:grid-cols-2">
        <Card title="Rate limits">{!rules.length ? <p className="m-0 text-sm text-muted">No rules for this project.{num(org.rules) ? ` ${org.rules} organisation-wide rule(s) also apply.` : ''}</p> :
          <ul className="list-none m-0 p-0 flex flex-col gap-1.5 text-[13px]">{rules.map((r) => <li key={r.id} className="flex gap-2"><span className="flex-1 truncate">{r.name} <span className="text-muted">· {r.scope}</span></span><span className="tabular-nums">{r.rate}</span><Badge tone={r.enabled ? 'b' : 'n'}>{r.action}</Badge></li>)}</ul>}</Card>
        <Card title="ISP rules">{!isps.length ? <p className="m-0 text-sm text-muted">No ISP rules for this project.</p> :
          <ul className="list-none m-0 p-0 flex flex-col gap-1.5 text-[13px]">{isps.map((i) => <li key={`${i.asn}${i.scope}`} className="flex gap-2"><span className="flex-1 truncate">AS{i.asn} {i.name ?? ''} <span className="text-muted">· {i.scope}</span></span><Badge tone={i.state === 'allow' ? 'g' : i.state === 'block' ? 'r' : 'y'}>{i.state}</Badge></li>)}</ul>}</Card>
      </div>

      <Card title="Recent security events (24 h)" pad={false}>
        {!events.length ? <div className="px-5 pb-5"><Empty>Nothing blocked or challenged in the last 24 hours.</Empty></div> :
          <ul className="list-none m-0 p-0">{events.map((e, n) => (
            <li key={n} className="flex flex-wrap items-center gap-3 px-4 sm:px-5 py-2 border-t border-line first:border-t-0 text-[13px]">
              <span className="font-mono text-xs w-32">{e.ip}{e.country ? ` · ${e.country}` : ''}</span>
              <span className="flex-1 min-w-0 truncate text-muted">{e.domain} {e.method} {e.path}{e.rule_name ? ` · ${e.rule_name}` : ''}</span>
              <Badge tone={e.action === 'block' ? 'r' : 'y'}>{e.action}</Badge>
              <span className="text-xs text-muted">{ago(e.occurred_at)}</span>
            </li>))}</ul>}
      </Card>

      <ConfirmDialog open={!!self} onClose={() => setSelf(null)} danger title="Block your own address?" confirmLabel="Block anyway" busy={busy} onConfirm={() => add(true)}>
        <p className="m-0">{self}</p>
      </ConfirmDialog>
    </div>
  )
}
