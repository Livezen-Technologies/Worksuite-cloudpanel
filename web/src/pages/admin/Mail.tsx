import { useState } from 'react'
import { useAction, useList, useOne } from '../../api/hooks'
import { Card, PageHeader } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { Drawer } from '../../components/Overlay'
import { ago } from '../../lib/format'
import type { R } from '../../lib/util'

/** Mail hosting (SRS MAIL): a mail stack per server, domains, mailboxes, aliases and DNS. */
export default function Mail() {
  const list = useOne<R>('/mail', undefined, { refetchInterval: 15000 })
  const servers = useList<R>('/servers')
  const { run, busy } = useAction()
  const [install, setInstall] = useState({ server_id: '', hostname: '' })
  const [domain, setDomain] = useState({ mail_server_id: '', name: '' })
  const [open, setOpen] = useState<string | null>(null)
  const mailServers = (list.item?.servers as R[]) ?? []
  const domains = (list.item?.domains as R[]) ?? []

  return (
    <>
      <PageHeader title="Mail" subtitle="Hosted email: Postfix, Dovecot, OpenDKIM and Rspamd in one container per mail server." />

      <Card title="Mail servers">
        {mailServers.length > 0 && <ul className="m-0 mb-3 p-0 list-none flex flex-col gap-1 text-sm">
          {mailServers.map((m) => <li key={String(m.id)}>
            <span className="font-semibold">{String(m.hostname)}</span> on {String(m.server)} <Badge tone={m.status === 'running' ? 'g' : m.status === 'failed' ? 'r' : 'y'}>{String(m.status)}</Badge>
            {m.synced_at ? <span className="text-muted"> · synced {ago(String(m.synced_at))}</span> : null}
            {m.error ? <span className="text-danger"> · {String(m.error)}</span> : null}
          </li>)}
        </ul>}
        <div className="flex flex-wrap items-end gap-2 text-sm">
          <label className="flex flex-col gap-1">Server
            <select className="input" value={install.server_id} onChange={(e) => setInstall({ ...install, server_id: e.target.value })}>
              <option value="">Choose…</option>
              {servers.rows.filter((s) => !mailServers.some((m) => m.server_id === s.id)).map((s) => <option key={String(s.id)} value={String(s.id)}>{String(s.name)}</option>)}
            </select>
          </label>
          <label className="flex flex-col gap-1">Mail hostname
            <input className="input" placeholder="mail.example.com" value={install.hostname} onChange={(e) => setInstall({ ...install, hostname: e.target.value })} />
          </label>
          <button type="button" className="btn btn-primary" disabled={busy || !install.server_id || !install.hostname} onClick={() => run({ path: '/mail/servers', body: install, label: 'Install mail', success: 'Installing the mail stack' }).then(() => { setInstall({ server_id: '', hostname: '' }); list.refetch() })}>Install mail</button>
        </div>
        <p className="m-0 mt-2 text-xs text-muted">Opens ports 25, 465, 587 and 993. Point the hostname's A record at the server, and ask the provider to set reverse DNS to it.</p>
      </Card>

      <Card title="Domains" pad={false}>
        <div className="p-4 flex flex-wrap items-end gap-2 text-sm">
          <label className="flex flex-col gap-1">Mail server
            <select className="input" value={domain.mail_server_id} onChange={(e) => setDomain({ ...domain, mail_server_id: e.target.value })}>
              <option value="">Choose…</option>
              {mailServers.filter((m) => m.status === 'running').map((m) => <option key={String(m.id)} value={String(m.id)}>{String(m.hostname)}</option>)}
            </select>
          </label>
          <label className="flex flex-col gap-1">Domain
            <input className="input" placeholder="example.com" value={domain.name} onChange={(e) => setDomain({ ...domain, name: e.target.value })} />
          </label>
          <button type="button" className="btn" disabled={busy || !domain.mail_server_id || !domain.name} onClick={() => run<{ data: R }>({ path: '/mail/domains', body: domain, label: 'Add mail domain' }).then((r) => { setDomain({ ...domain, name: '' }); list.refetch(); setOpen(String(r.data.id)) })}>Add domain</button>
        </div>
        <DataTable label="Mail domains" rows={domains} loading={list.isLoading} rowKey={(r) => String(r.id)} onRowClick={(r) => setOpen(String(r.id))}
          columns={[
            { key: 'name', header: 'Domain', render: (r) => <span className="font-semibold">{String(r.name)}</span> },
            { key: 'mailboxes', header: 'Mailboxes', value: (r) => Number(r.mailboxes) },
            { key: 'aliases', header: 'Aliases', value: (r) => Number(r.aliases) },
            { key: 'dns_ok', header: 'DNS', render: (r) => r.dns_ok == null ? <Badge>not checked</Badge> : <Badge tone={r.dns_ok ? 'g' : 'y'}>{r.dns_ok ? 'ok' : 'incomplete'}</Badge> },
          ]} />
      </Card>

      {open && <DomainDrawer id={open} onClose={() => { setOpen(null); list.refetch() }} />}
    </>
  )
}

function DomainDrawer({ id, onClose }: { id: string; onClose: () => void }) {
  const d = useOne<R>(`/mail/domains/${id}`)
  const w = d.item ?? {}
  const { run, busy } = useAction()
  const [box, setBox] = useState({ local_part: '', password: '', quota_mb: 1024 })
  const [alias, setAlias] = useState({ local_part: '', destinations: '' })
  const refetch = () => d.refetch()
  const status = new Map(((w.dns as R[]) ?? []).map((r) => [`${r.type} ${r.name}`, String(r.status)]))

  return (
    <Drawer open onClose={onClose} title={String(w.name ?? 'Mail domain')} width={720}>
      <div className="flex flex-col gap-5 text-sm">
        <section>
          <div className="flex items-center justify-between mb-2">
            <h3 className="m-0 text-sm font-semibold">DNS records</h3>
            <span className="flex gap-2">
              <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/mail/domains/${id}/dns/publish`, label: 'Publish to DNS zone', success: 'Published where a managed zone holds the domain' }).then(refetch)}>Publish to zone</button>
              <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/mail/domains/${id}/dns/check`, label: 'Check DNS', silent: true }).then(refetch)}>Check</button>
            </span>
          </div>
          <ul className="m-0 p-0 list-none flex flex-col gap-2">
            {((w.records as R[]) ?? []).map((r) => {
              const s = status.get(`${r.type} ${r.name}`)
              return <li key={`${r.type} ${r.name}`} className="flex flex-col">
                <span><Badge>{String(r.type)}</Badge> <span className="font-mono text-xs">{String(r.name)}</span> {s ? <Badge tone={s === 'ok' ? 'g' : 'y'}>{s}</Badge> : null}</span>
                <span className="font-mono text-xs break-all text-muted">{r.priority ? `${r.priority} ` : ''}{String(r.value)}</span>
              </li>
            })}
          </ul>
        </section>

        <section>
          <h3 className="m-0 mb-2 text-sm font-semibold">Mailboxes</h3>
          <ul className="m-0 mb-2 p-0 list-none flex flex-col gap-1">
            {((w.mailbox_list as R[]) ?? []).map((b) => <li key={String(b.id)} className="flex flex-wrap items-center gap-2">
              <span className="flex-1 font-mono text-xs">{String(b.local_part)}@{String(w.name)}</span>
              <span className="text-muted text-xs">{Number(b.quota_mb) >= 1024 ? `${Number(b.quota_mb) / 1024} GB` : `${b.quota_mb} MB`}</span>
              <Badge tone={b.enabled ? 'g' : 'n'}>{b.enabled ? 'on' : 'off'}</Badge>
              <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/mail/domains/${id}/mailboxes/${b.id}`, method: 'PATCH', body: { enabled: !b.enabled }, label: b.enabled ? 'Disable mailbox' : 'Enable mailbox' }).then(refetch)}>{b.enabled ? 'Disable' : 'Enable'}</button>
              <button type="button" className="btn btn-sm" disabled={busy} onClick={() => { const password = window.prompt('New password (at least 10 characters):'); if (password) run({ path: `/mail/domains/${id}/mailboxes/${b.id}`, method: 'PATCH', body: { password }, label: 'Change password' }) }}>Password</button>
              <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => { if (window.confirm(`Delete ${b.local_part}@${w.name}? Stored mail stays on the server until it is cleaned up.`)) run({ path: `/mail/domains/${id}/mailboxes/${b.id}`, method: 'DELETE', label: 'Delete mailbox' }).then(refetch) }}>Delete</button>
            </li>)}
          </ul>
          <div className="flex flex-wrap gap-2">
            <input className="input w-32" placeholder="name" value={box.local_part} onChange={(e) => setBox({ ...box, local_part: e.target.value })} />
            <input className="input w-44" type="password" autoComplete="new-password" placeholder="password (10+)" value={box.password} onChange={(e) => setBox({ ...box, password: e.target.value })} />
            <select className="input" value={box.quota_mb} onChange={(e) => setBox({ ...box, quota_mb: Number(e.target.value) })}>
              {[512, 1024, 2048, 5120, 10240].map((q) => <option key={q} value={q}>{q >= 1024 ? `${q / 1024} GB` : `${q} MB`}</option>)}
            </select>
            <button type="button" className="btn btn-sm btn-primary" disabled={busy || !box.local_part || box.password.length < 10} onClick={() => run({ path: `/mail/domains/${id}/mailboxes`, body: box, label: 'Add mailbox' }).then(() => { setBox({ local_part: '', password: '', quota_mb: 1024 }); refetch() })}>Add mailbox</button>
          </div>
          <p className="m-0 mt-1 text-xs text-muted">IMAP: port 993 (SSL). SMTP: port 587 (STARTTLS) or 465 (SSL). Username: the full address.</p>
        </section>

        <section>
          <h3 className="m-0 mb-2 text-sm font-semibold">Aliases and forwarding</h3>
          <ul className="m-0 mb-2 p-0 list-none flex flex-col gap-1">
            {((w.alias_list as R[]) ?? []).map((a) => <li key={String(a.id)} className="flex flex-wrap items-center gap-2">
              <span className="flex-1 font-mono text-xs">{String(a.local_part)}@{String(w.name)} → {(a.destinations as string[]).join(', ')}</span>
              <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => run({ path: `/mail/domains/${id}/aliases/${a.id}`, method: 'DELETE', label: 'Delete alias' }).then(refetch)}>Delete</button>
            </li>)}
          </ul>
          <div className="flex flex-wrap gap-2">
            <input className="input w-32" placeholder="name" value={alias.local_part} onChange={(e) => setAlias({ ...alias, local_part: e.target.value })} />
            <input className="input flex-1 min-w-[200px]" placeholder="deliver to (comma-separated addresses)" value={alias.destinations} onChange={(e) => setAlias({ ...alias, destinations: e.target.value })} />
            <button type="button" className="btn btn-sm" disabled={busy || !alias.local_part || !alias.destinations} onClick={() => run({ path: `/mail/domains/${id}/aliases`, body: { local_part: alias.local_part, destinations: alias.destinations.split(',').map((s) => s.trim()).filter(Boolean) }, label: 'Save alias' }).then(() => { setAlias({ local_part: '', destinations: '' }); refetch() })}>Save alias</button>
          </div>
          <div className="flex flex-wrap items-center gap-2 mt-3">
            <span>Catch-all:</span>
            <span className="font-mono text-xs">{String(w.catch_all ?? 'off')}</span>
            <button type="button" className="btn btn-sm" disabled={busy} onClick={() => { const v = window.prompt('Deliver mail for unknown addresses to (empty turns it off):', String(w.catch_all ?? '')); if (v !== null) run({ path: `/mail/domains/${id}`, method: 'PATCH', body: { catch_all: v || null }, label: 'Catch-all' }).then(refetch) }}>Change</button>
          </div>
        </section>

        <button type="button" className="btn btn-danger self-start" disabled={busy} onClick={() => { if (window.confirm(`Stop hosting mail for ${w.name}? Its mailboxes and aliases are removed.`)) run({ path: `/mail/domains/${id}`, method: 'DELETE', label: 'Delete mail domain' }).then(onClose) }}>Delete domain</button>
      </div>
    </Drawer>
  )
}
