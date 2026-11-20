import { useState } from 'react'
import { useAction, useOne } from '../api/hooks'
import { Card, Field } from './Card'
import { Badge } from './Badge'
import { DataTable, type Column } from './DataTable'
import Select from './Select'
import { ago } from '../lib/format'
import { type R } from '../lib/util'

/**
 * PWS-E: a project's firewall — allow/block lists, rate limits and app lock. Rules apply to the
 * project's own domains only. `base` is '' (admin) or '/app' (customer portal).
 */
export function ProjectFirewall({ projectId, base = '' }: { projectId: string; base?: string }) {
  const path = `${base}/projects/${projectId}/firewall`
  const q = useOne<R>(path)
  const { run, busy } = useAction()
  const d = q.item ?? {}
  const can = Boolean(d.can_manage)
  const [ip, setIp] = useState({ list: 'block', cidr: '', note: '' })
  const [rl, setRl] = useState({ name: '', path: '/', limit: '60', window_seconds: '60', action: 'block' })
  const refresh = () => q.refetch()

  const ipCols: Column<R>[] = [
    { key: 'cidr', header: 'Address', value: (e) => String(e.cidr), render: (e) => <code className="font-mono">{String(e.cidr)}</code> },
    { key: 'note', header: 'Note', value: (e) => String(e.note ?? '') },
    { key: 'expires', header: 'Expires', value: (e) => String(e.expires_at ?? ''), render: (e) => e.expires_at ? (e.active ? ago(String(e.expires_at)) : <Badge tone="n">expired</Badge>) : 'never' },
  ]
  const ipActions = can ? (e: R) => [{ label: 'Remove', icon: 'trash', danger: true, onClick: () => { run({ method: 'DELETE', path: `${path}/ips/${String(e.id)}`, label: 'Remove address', success: 'Removed' }).then(refresh).catch(() => {}) } }] : undefined
  const list = (label: string, rows: R[], empty: string) => <Card pad={false}><DataTable label={label} tableId={`project-fw-${label.toLowerCase().replace(/\W+/g, '-')}`} layouts={false} rows={rows} loading={q.isLoading} rowKey={(r) => String(r.id)} columns={ipCols} actions={ipActions} empty={empty} /></Card>
  const inherited = (d.inherited ?? {}) as R

  return (
    <div className="flex flex-col gap-4">
      <Card title="App lock" subtitle="A locked app answers only addresses on the allow list. Use it during an incident or before launch.">
        <DataTable label="Applications" tableId="project-fw-apps" layouts={false} rows={(d.apps ?? []) as R[]} rowKey={(a) => String(a.id)}
          columns={[
            { key: 'name', header: 'Application', value: (a) => String(a.name) },
            { key: 'state', header: 'Access', value: (a) => a.locked ? 'locked' : 'public', render: (a) => a.locked ? <Badge tone="r">locked</Badge> : <Badge tone="g">public</Badge> },
            { key: 'reason', header: 'Reason', value: (a) => String(a.lock_reason ?? '') },
          ]}
          actions={can ? (a) => [{ label: a.locked ? 'Unlock' : 'Lock', icon: a.locked ? 'play' : 'pause', onClick: () => { run({ path: `${path}/apps/${String(a.id)}/${a.locked ? 'unlock' : 'lock'}`, label: a.locked ? 'Unlock app' : 'Lock app', success: a.locked ? 'Unlocked' : 'Locked' }).then(refresh).catch(() => {}) } }] : undefined}
          empty="No applications in this project." />
      </Card>
      {list('Block list', (d.block ?? []) as R[], 'No blocked addresses for this project.')}
      {list('Allow list', (d.allow ?? []) as R[], 'No allowed addresses. A locked app with an empty allow list answers no one.')}
      {can && <Card title="Add an address">
        <div className="flex flex-wrap items-end gap-3">
          <Field label="List"><Select className="input" value={ip.list} onChange={(e) => setIp({ ...ip, list: e.target.value })}><option value="block">Block</option><option value="allow">Allow</option></Select></Field>
          <Field label="IP or CIDR"><input className="input" value={ip.cidr} placeholder="203.0.113.0/24" onChange={(e) => setIp({ ...ip, cidr: e.target.value })} /></Field>
          <Field label="Note"><input className="input" value={ip.note} onChange={(e) => setIp({ ...ip, note: e.target.value })} /></Field>
          <button type="button" className="btn btn-primary" disabled={busy || !ip.cidr} onClick={() => run({ path: `${path}/ips`, body: { list: ip.list, cidr: ip.cidr, note: ip.note || null }, label: 'Add address', success: 'Added' }).then(() => { setIp({ ...ip, cidr: '', note: '' }); refresh() }).catch(() => {})}>Add</button>
        </div>
      </Card>}
      <Card pad={false}>
        <DataTable label="Rate limits" tableId="project-fw-rate-limits" layouts={false} rows={(d.rate_limits ?? []) as R[]} rowKey={(r) => String(r.id)}
          columns={[
            { key: 'name', header: 'Rule', value: (r) => String(r.name) },
            { key: 'paths', header: 'Paths', value: (r) => ((r.paths ?? []) as string[]).join(', ') },
            { key: 'rate', header: 'Rate', value: (r) => `${String(r.limit)} / ${String(r.window_seconds)}s` },
            { key: 'action', header: 'Action', value: (r) => String(r.action) },
          ]}
          actions={can ? (r) => [{ label: 'Delete', icon: 'trash', danger: true, onClick: () => { run({ method: 'DELETE', path: `${path}/rate-limits/${String(r.id)}`, label: 'Delete rule', success: 'Deleted' }).then(refresh).catch(() => {}) } }] : undefined}
          empty="No project rate limits." />
      </Card>
      {can && <Card title="Add a rate limit">
        <div className="flex flex-wrap items-end gap-3">
          <Field label="Name"><input className="input" value={rl.name} onChange={(e) => setRl({ ...rl, name: e.target.value })} /></Field>
          <Field label="Path"><input className="input" value={rl.path} onChange={(e) => setRl({ ...rl, path: e.target.value })} /></Field>
          <Field label="Requests"><input className="input" type="number" min={1} value={rl.limit} onChange={(e) => setRl({ ...rl, limit: e.target.value })} /></Field>
          <Field label="Per seconds"><input className="input" type="number" min={1} value={rl.window_seconds} onChange={(e) => setRl({ ...rl, window_seconds: e.target.value })} /></Field>
          <Field label="Action"><Select className="input" value={rl.action} onChange={(e) => setRl({ ...rl, action: e.target.value })}><option value="block">Block</option><option value="throttle">Throttle</option><option value="log">Log only</option></Select></Field>
          <button type="button" className="btn btn-primary" disabled={busy || !rl.name || !rl.path} onClick={() => run({ path: `${path}/rate-limits`, body: { name: rl.name, paths: [rl.path], limit: Number(rl.limit), window_seconds: Number(rl.window_seconds), action: rl.action }, label: 'Add rate limit', success: 'Added' }).then(() => { setRl({ ...rl, name: '' }); refresh() }).catch(() => {})}>Add</button>
        </div>
      </Card>}
      {(Number(inherited.ip_entries) > 0 || Number(inherited.rules) > 0) && <p className="text-sm text-muted m-0">Also in force: {String(inherited.ip_entries)} organisation-wide address entries and {String(inherited.rules)} organisation-wide rules.</p>}
    </div>
  )
}
