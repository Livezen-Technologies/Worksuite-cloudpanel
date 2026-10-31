import { useState } from 'react'
import { useAction, useList, useOne } from '../api/hooks'
import { Card, Empty, Field } from './Card'
import { Badge } from './Badge'
import { DataTable } from './DataTable'
import Select from './Select'
import { ago } from '../lib/format'
import { type R } from '../lib/util'

const STATUS_TONE: Record<string, 'g' | 'y' | 'r' | 'n'> = { sent: 'g', deferred: 'y', bounced: 'r', rejected: 'r' }

/** MCL: mailboxes and domains whose sending is on hold. `base` is '' for admins, '/app' for customers. */
export function MailHolds({ base, canRelease }: { base: string; canRelease: boolean }) {
  const holds = useOne<{ domains: R[]; mailboxes: R[] }>(`${base}/mail/holds`, undefined, { refetchInterval: 30000 })
  const { run, busy } = useAction()
  const h = holds.item
  if (!h || (h.domains.length === 0 && h.mailboxes.length === 0)) return null
  const rows: R[] = [...h.domains.map((d) => ({ ...d, kind: 'domain', label: d.name, path: `/mail/domains/${d.id}/hold` })),
    ...h.mailboxes.map((b) => ({ ...b, kind: 'mailbox', label: b.address, path: `/mail/domains/${b.domain_id}/mailboxes/${b.id}/hold` }))]
  return (
    <Card title="Sending on hold" subtitle="Outgoing mail is blocked for these until released; incoming mail still arrives">
      <ul className="list-none m-0 p-0">
        {rows.map((r) => (
          <li key={`${r.kind}-${r.id}`} className="flex flex-wrap items-center gap-3 py-2 border-t border-line first:border-t-0 text-sm">
            <Badge tone="r">{r.kind}</Badge><span className="font-mono text-xs">{String(r.label)}</span>
            <span className="text-muted text-xs flex-1">{String(r.reason ?? '')} · {ago(String(r.held_at))}</span>
            {canRelease ? <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: r.path, method: 'DELETE', label: `Release ${r.label}`, success: 'Sending released' }).then(() => holds.refetch()).catch(() => {})}>Release</button>
              : <span className="text-xs text-muted">Contact support to release</span>}
          </li>
        ))}
      </ul>
    </Card>
  )
}

/** MCL: searchable delivery log (own domains for customers). */
export function MailLog({ base, mailServerId }: { base: string; mailServerId?: string }) {
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')
  const [direction, setDirection] = useState('')
  const query: R = { ...(q ? { q } : {}), ...(status ? { status } : {}), ...(direction ? { direction } : {}), ...(mailServerId ? { mail_server_id: mailServerId } : {}) }
  const { rows, isLoading } = useList<R>(`${base}/mail/log`, query)
  return (
    <Card title="Delivery log" subtitle="Updated every 5 minutes from the mail server">
      <div className="flex flex-wrap items-end gap-3 mb-3">
        <Field label="Search"><input className="input" value={q} onChange={(e) => setQ(e.target.value)} placeholder="address, queue id or error" /></Field>
        <Field label="Status"><Select className="input" value={status} onChange={(e) => setStatus(e.target.value)}><option value="">Any</option>{['sent', 'deferred', 'bounced', 'rejected'].map((s) => <option key={s}>{s}</option>)}</Select></Field>
        <Field label="Direction"><Select className="input" value={direction} onChange={(e) => setDirection(e.target.value)}><option value="">Both</option><option value="out">Outgoing</option><option value="in">Incoming</option></Select></Field>
      </div>
      {!isLoading && rows.length === 0 ? <Empty>No deliveries match.</Empty> : (
        <DataTable label="Mail deliveries" rows={rows} loading={isLoading} rowKey={(r) => String(r.id)} columns={[
          { key: 'at', header: 'When', render: (r) => ago(String(r.at)) },
          { key: 'direction', header: '', render: (r) => (r.direction === 'out' ? '↑' : '↓') },
          { key: 'sender', header: 'From', render: (r) => <span className="font-mono text-xs">{String(r.sender ?? '')}</span> },
          { key: 'recipient', header: 'To', render: (r) => <span className="font-mono text-xs">{String(r.recipient ?? '')}</span> },
          { key: 'status', header: 'Status', render: (r) => <Badge tone={STATUS_TONE[String(r.status)]}>{String(r.status)}</Badge> },
          { key: 'detail', header: 'Detail', render: (r) => <span className="text-xs text-muted">{String(r.detail ?? '')}</span> },
        ]} />
      )}
    </Card>
  )
}

/** MCL: per-mailbox spam level, as a compact select. */
export function SpamLevel({ path, spam, onSaved }: { path: string; spam: R | null | undefined; onSaved: () => void }) {
  const { run, busy } = useAction()
  const level = String(spam?.level ?? 'off')
  return (
    <Select className="input !w-auto !py-1 text-xs" value={level} aria-label="Spam filtering" disabled={busy}
      onChange={(e) => run({ path: `${path}/spam`, method: 'PATCH', body: { level: e.target.value, allow: spam?.allow ?? [], block: spam?.block ?? [] }, label: 'Spam filter', success: 'Spam filter saved' }).then(onSaved).catch(() => {})}>
      <option value="off">Spam: server default</option><option value="relaxed">Spam: relaxed</option><option value="normal">Spam: normal</option><option value="strict">Spam: strict</option>
    </Select>
  )
}
