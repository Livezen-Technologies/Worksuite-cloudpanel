import Select from '../components/Select'
import { useEffect, useState } from 'react'
import { useAction, useOne } from '../api/hooks'
import { Card } from './Card'
import { Badge } from './Badge'
import { ConfirmDialog } from './Overlay'
import { ago } from '../lib/format'
import type { R } from '../lib/util'

const lines = (v: string) => v.split(/[\s,]+/).map((x) => x.trim()).filter(Boolean)

/**
 * Server → Firewall: the host firewall, applied by the agent as its own nftables table. UFW, Docker
 * and fail2ban rules are untouched. Private networks, the panel and admin ranges are always allowed,
 * and every apply rolls back by itself unless the agent reports back.
 */
export function ServerFirewallPanel({ serverId }: { serverId: string }) {
  const d = useOne<R>(`/servers/${serverId}/firewall`, undefined, { refetchInterval: 10000 })
  const { run, busy } = useAction()
  const fw = d.item ?? {}
  const [f, setF] = useState({ mode: 'block', block: '', allow: '', ports: '' })
  const [confirm, setConfirm] = useState<'apply' | 'disable' | null>(null)
  useEffect(() => {
    if (!d.item) return
    setF({ mode: String(fw.mode ?? 'block'), block: ((fw.block ?? []) as string[]).join('\n'), allow: ((fw.allow ?? []) as string[]).join('\n'), ports: ((fw.ports ?? []) as number[]).join(', ') })
  }, [d.item?.updated_at]) // eslint-disable-line react-hooks/exhaustive-deps
  const save = () => run({ method: 'PATCH', path: `/servers/${serverId}/firewall`, label: 'Save firewall', success: 'Firewall settings saved (not applied yet)',
    body: { mode: f.mode, block: lines(f.block), allow: lines(f.allow), ports: lines(f.ports).map(Number).filter(Boolean) } }).then(() => d.refetch())
  const status = String(fw.status ?? 'not_applied')

  return (
    <div className="flex flex-col gap-3">
      <Card title="Host firewall" actions={<Badge tone={status === 'applied' ? 'g' : status === 'failed' ? 'r' : status === 'applying' ? 'y' : 'n'}>{status.replace('_', ' ')}</Badge>}>
        <p className="m-0 mb-3 text-xs text-muted">Filters traffic to this server and its containers before Docker sees it, in its own table; UFW and Docker rules stay as they are. {fw.applied_at ? `Last applied ${ago(String(fw.applied_at))}.` : 'Never applied.'}{fw.message ? ` ${fw.message}` : ''}</p>
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="text-sm"><span className="block text-xs text-muted mb-1">Mode</span>
            <Select className="input" value={f.mode} onChange={(e) => setF({ ...f, mode: e.target.value })}>
              <option value="block">Block listed addresses</option><option value="allow_only">Allow only listed addresses (on the ports below)</option>
            </Select></label>
          <label className="text-sm"><span className="block text-xs text-muted mb-1">Ports (empty = all; required for "allow only")</span>
            <input className="input font-mono" value={f.ports} placeholder="3306, 5432" onChange={(e) => setF({ ...f, ports: e.target.value })} /></label>
          <label className="text-sm"><span className="block text-xs text-muted mb-1">Block (one IP or CIDR per line)</span>
            <textarea className="input font-mono text-xs min-h-[110px]" value={f.block} onChange={(e) => setF({ ...f, block: e.target.value })} /></label>
          <label className="text-sm"><span className="block text-xs text-muted mb-1">Allow (one IP or CIDR per line)</span>
            <textarea className="input font-mono text-xs min-h-[110px]" value={f.allow} onChange={(e) => setF({ ...f, allow: e.target.value })} /></label>
        </div>
        <p className="m-0 mt-3 text-xs text-muted">Always allowed: <span className="font-mono">{((fw.never_block ?? []) as string[]).join(', ')}</span></p>
        <div className="flex flex-wrap gap-2 mt-4">
          <button type="button" className="btn" disabled={busy} onClick={() => save().catch(() => {})}>Save</button>
          <button type="button" className="btn" disabled={busy} onClick={() => save().then(() => run({ path: `/servers/${serverId}/firewall/preview`, label: 'Preview firewall', success: 'Preview ready below' })).then(() => setTimeout(() => d.refetch(), 6000)).catch(() => {})}>Preview rules</button>
          <button type="button" className="btn btn-primary" disabled={busy || !fw.online} onClick={() => setConfirm('apply')}>Apply</button>
          {fw.enabled ? <button type="button" className="btn btn-danger" disabled={busy || !fw.online} onClick={() => setConfirm('disable')}>Turn off</button> : null}
        </div>
      </Card>
      {fw.ruleset ? <Card title="Rules on the server (or last preview)"><pre className="m-0 p-3 rounded-lg bg-sel text-xs font-mono overflow-auto max-h-[320px]">{String(fw.ruleset)}</pre></Card> : null}
      <ConfirmDialog open={!!confirm} onClose={() => setConfirm(null)} danger={confirm === 'disable'} busy={busy}
        title={confirm === 'apply' ? 'Apply the host firewall?' : 'Turn the host firewall off?'} confirmLabel={confirm === 'apply' ? 'Apply' : 'Turn off'}
        onConfirm={() => (confirm === 'apply' ? save() : Promise.resolve()).then(() => run({ path: `/servers/${serverId}/firewall/${confirm === 'apply' ? 'apply' : 'disable'}`, label: confirm === 'apply' ? 'Apply firewall' : 'Turn off firewall', success: 'Sent to the server' })).then(() => { setConfirm(null); setTimeout(() => d.refetch(), 6000) }).catch(() => {})}>
        <p className="m-0">{confirm === 'apply' ? 'The rules load at once. If the server does not report back within 2 minutes they are removed again automatically.' : 'The panel’s rules are removed from this server. UFW and Docker rules are not affected.'}</p>
      </ConfirmDialog>
    </div>
  )
}
