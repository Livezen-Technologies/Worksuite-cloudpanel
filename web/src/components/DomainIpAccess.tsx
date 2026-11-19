import { useState } from 'react'
import { isApiError } from '../api/client'
import { useAction, useList } from '../api/hooks'
import { Card, Empty } from './Card'
import { Badge } from './Badge'
import { ConfirmDialog } from './Overlay'
import type { R } from '../lib/util'

/**
 * Firewall → IP lists: per-domain access mode. "Open" admits everyone except the block list;
 * "Allow list only" admits only allowed addresses (Traefik answers 403 to the rest).
 */
export function DomainIpAccess() {
  const domains = useList<R>('/domains', { per_page: 500 })
  const { run, busy } = useAction()
  const [pending, setPending] = useState<{ id: string; message: string } | null>(null)
  const set = (id: string, ip_access: string, confirm = false) =>
    run({ method: 'PATCH', path: `/firewall/domains/${id}/access`, body: { ip_access, ...(confirm ? { confirm: true } : {}) }, label: 'Change IP access', success: ip_access === 'allowlist' ? 'Only allowed addresses can reach it now' : 'Open to all addresses again' })
      .then(() => { setPending(null); domains.refetch() })
      .catch((e) => { if (isApiError(e) && e.status === 409) setPending({ id, message: e.message }) })

  return (
    <Card title="Access per domain" pad={false}>
      <p className="m-0 px-4 sm:px-5 pb-3 text-xs text-muted">Open: everyone except the block list. Allow list only: only addresses on the allow list (for that domain or all domains) reach the site; everyone else gets 403. Emergency recovery on the panel host: <code>php artisan firewall:open</code>.</p>
      {domains.isLoading ? <p className="px-5 pb-4 m-0 text-sm text-muted">Loading…</p> : !domains.rows.length ? <div className="px-5 pb-5"><Empty>No domains.</Empty></div> :
        <ul className="list-none m-0 p-0">{domains.rows.map((d) => {
          const locked = d.ip_access === 'allowlist'
          return (
            <li key={d.id} className="flex flex-wrap items-center gap-3 px-4 sm:px-5 py-2.5 border-t border-line text-[13.5px]">
              <span className="flex-1 min-w-0 font-mono text-xs truncate">{d.name}</span>
              {locked ? <Badge tone="y">allow list only</Badge> : <Badge tone="g">open</Badge>}
              <select className="input !w-auto" aria-label={`IP access for ${d.name}`} disabled={busy} value={locked ? 'allowlist' : 'open'} onChange={(e) => set(String(d.id), e.target.value)}>
                <option value="open">Open</option><option value="allowlist">Allow list only</option>
              </select>
            </li>)
        })}</ul>}
      <ConfirmDialog open={!!pending} onClose={() => setPending(null)} danger title="Switch to allow list only?" confirmLabel="Switch anyway" busy={busy}
        onConfirm={() => pending && set(pending.id, 'allowlist', true)}>
        <p className="m-0">{pending?.message}</p>
      </ConfirmDialog>
    </Card>
  )
}
