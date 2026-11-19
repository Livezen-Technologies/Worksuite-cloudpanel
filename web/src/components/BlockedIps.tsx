import { useState } from 'react'
import { useAction, useOne } from '../api/hooks'
import { Card, Empty } from './Card'
import { Badge } from './Badge'
import { Modal } from './Overlay'
import { ago } from '../lib/format'
import { num, type R } from '../lib/util'

type Act = { row: R; action: 'unblock' | 'extend' | 'permanent' | 'allow' }
const TITLES: Record<Act['action'], string> = { unblock: 'Unblock', extend: 'Extend block', permanent: 'Block permanently', allow: 'Move to the allow list' }
const when = (t?: string) => t ? new Date(t).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—'

/**
 * Firewall → Blocked IPs: manual block-list entries and automatic CrowdSec bans. Unblock, extend,
 * make permanent or allow; each asks for a reason, which goes into the audit log with the previous rule.
 */
export function BlockedIps() {
  const list = useOne<R>('/firewall/blocked', undefined, { refetchInterval: 30000 })
  const { run, busy } = useAction()
  const [act, setAct] = useState<Act | null>(null)
  const [reason, setReason] = useState('')
  const [duration, setDuration] = useState('24h')
  const items = (list.item?.items ?? []) as R[]
  const go = () => {
    if (!act) return
    const path = act.action === 'unblock' ? '/firewall/blocked/unblock' : `/firewall/blocked/${act.action}`
    run({ path, body: { key: act.row.key, reason: reason || null, ...(act.action === 'extend' ? { duration } : {}) }, label: TITLES[act.action], success: `${TITLES[act.action]}: ${act.row.ip}` })
      .then(() => { setAct(null); setReason(''); list.refetch() }).catch(() => {})
  }

  return (
    <Card title="Blocked IPs" pad={false} actions={<button type="button" className="btn" disabled={busy} onClick={() => run({ path: '/firewall/blocked/refresh', label: 'Refresh blocked IPs', success: 'Asked the proxies for their current bans' }).then(() => setTimeout(() => list.refetch(), 8000)).catch(() => {})}>Refresh</button>}>
      <p className="m-0 px-4 sm:px-5 pb-3 text-xs text-muted">Manual blocks and automatic bans (rate limits, suspicious activity, security rules). {list.item?.refreshed_at ? `Bans last read ${ago(String(list.item.refreshed_at))}.` : 'Bans not read yet.'} Unblocking an address does not unlock an app or change any rule.</p>
      {list.isLoading ? <p className="px-5 pb-4 m-0 text-sm text-muted">Loading…</p> : !items.length ? <div className="px-5 pb-5"><Empty>No blocked addresses.</Empty></div> :
        <ul className="list-none m-0 p-0">{items.map((r) => (
          <li key={r.key} className="flex flex-wrap items-center gap-3 px-4 sm:px-5 py-3 border-t border-line text-[13.5px]">
            <span className="flex-[1_1_220px] min-w-0">
              <span className="block font-mono text-xs">{r.ip}{r.country ? ` · ${r.country}` : ''}</span>
              <span className="block text-xs text-muted truncate">{r.reason}{r.scenario ? ` (${r.scenario})` : ''} · {r.scope}</span>
            </span>
            <span className="text-xs text-muted min-w-[150px]">{num(r.blocked_requests)} blocked request{num(r.blocked_requests) === 1 ? '' : 's'}{r.last_activity ? ` · last ${ago(r.last_activity)}` : ''}</span>
            <span className="text-xs min-w-[150px]">{when(r.started_at)} → {r.expires_at ? when(r.expires_at) : 'permanent'}</span>
            <Badge tone={r.kind === 'manual' ? 'n' : 'y'}>{r.kind}</Badge>
            <span className="flex flex-wrap gap-1.5 ml-auto">
              <button type="button" className="btn btn-sm btn-primary" onClick={() => setAct({ row: r, action: 'unblock' })}>Unblock</button>
              <button type="button" className="btn btn-sm" onClick={() => setAct({ row: r, action: 'extend' })}>Extend</button>
              {r.expires_at && <button type="button" className="btn btn-sm" onClick={() => setAct({ row: r, action: 'permanent' })}>Permanent</button>}
              <button type="button" className="btn btn-sm btn-ghost" onClick={() => setAct({ row: r, action: 'allow' })}>Allow</button>
            </span>
          </li>))}</ul>}
      <Modal open={!!act} onClose={() => setAct(null)} title={act ? `${TITLES[act.action]} ${String(act.row.ip)}` : ''} width={520}
        footer={<><button type="button" className="btn" onClick={() => setAct(null)}>Cancel</button><button type="button" className="btn btn-primary" disabled={busy} onClick={go}>{act ? TITLES[act.action] : ''}</button></>}>
        <div className="flex flex-col gap-3 text-sm">
          {act?.action === 'extend' && <label><span className="block text-xs text-muted mb-1">Extend by</span>
            <select className="input" value={duration} onChange={(e) => setDuration(e.target.value)}><option value="4h">4 hours</option><option value="24h">24 hours</option><option value="7d">7 days</option><option value="30d">30 days</option></select></label>}
          {act?.action === 'allow' && <p className="m-0">The block is lifted and the address is added to the allow list for all domains.</p>}
          <label><span className="block text-xs text-muted mb-1">Reason (kept in the audit log)</span><input className="input" value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} placeholder="e.g. customer office, false positive" /></label>
        </div>
      </Modal>
    </Card>
  )
}
