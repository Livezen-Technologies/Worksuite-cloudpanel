import { useState } from 'react'
import { useAction, useList, useOne } from '../../api/hooks'
import { Card, Empty, PageHeader, StatTile } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { ago } from '../../lib/format'
import type { R } from '../../lib/util'
import Select from '../../components/Select'
import { ImportDialog, ImportList } from '../../components/MailImport'
import { MailHolds, MailLog } from '../../components/MailGuard'

const size = (q: unknown) => (Number(q) >= 1024 ? `${Number(q) / 1024} GB` : `${q} MB`)

/** Mail hosting (SRS MAIL): a mail stack per server, domains, mailboxes, aliases and DNS. */
export default function Mail() {
  const list = useOne<R>('/mail', undefined, { refetchInterval: 15000 })
  const servers = useList<R>('/servers')
  const { run, busy } = useAction()
  const [install, setInstall] = useState({ server_id: '', hostname: '' })
  const [domain, setDomain] = useState({ mail_server_id: '', name: '' })
  const [picked, setPicked] = useState<string | null>(null)
  const [newBox, setNewBox] = useState(false)
  const mailServers = (list.item?.servers as R[]) ?? []
  const domains = (list.item?.domains as R[]) ?? []
  const open = picked && domains.some((d) => String(d.id) === picked) ? picked : domains[0] ? String(domains[0].id) : null
  const checked = mailServers.filter((m) => m.health)
  const listed = checked.flatMap((m) => ((m.health as R).listed as string[]) ?? [])
  const sum = (k: string) => domains.reduce((n, d) => n + Number(d[k] ?? 0), 0)

  return (
    <>
      <PageHeader title="Mail" subtitle="Hosted email: Postfix, Dovecot, OpenDKIM and Rspamd in one container per mail server."
        actions={<button type="button" className="btn btn-primary" disabled={!open} onClick={() => setNewBox(true)}>+ New mailbox</button>} />

      <div className="grid gap-4 grid-cols-2 lg:grid-cols-4">
        <StatTile label="Domains" value={domains.length} />
        <StatTile label="Mailboxes" value={sum('mailboxes')} />
        <StatTile label="Aliases" value={sum('aliases')} />
        <StatTile label="Blocklist status" value={checked.length === 0 ? '—' : listed.length ? `Listed (${listed.length})` : 'Clean'} valueTone={checked.length === 0 ? 'n' : listed.length ? 'r' : 'g'} />
      </div>

      {open
        ? <DomainPanel key={open} id={open} domains={domains} onPick={setPicked} newBox={newBox} setNewBox={setNewBox} onChange={() => list.refetch()} />
        : !list.isLoading && <Card><Empty>Add a mail domain below to manage its mailboxes and DNS.</Empty></Card>}

      <Card title="Domains" pad={false}>
        <div className="px-5 pb-4 flex flex-wrap items-end gap-2 text-sm">
          <label className="flex flex-col gap-1">Mail server
            <Select className="input" value={domain.mail_server_id} onChange={(e) => setDomain({ ...domain, mail_server_id: e.target.value })}>
              <option value="">Choose…</option>
              {mailServers.filter((m) => m.status === 'running').map((m) => <option key={String(m.id)} value={String(m.id)}>{String(m.hostname)}</option>)}
            </Select>
          </label>
          <label className="flex flex-col gap-1">Domain
            <input className="input" placeholder="example.com" value={domain.name} onChange={(e) => setDomain({ ...domain, name: e.target.value })} />
          </label>
          <button type="button" className="btn" disabled={busy || !domain.mail_server_id || !domain.name} onClick={() => run<{ data: R }>({ path: '/mail/domains', body: domain, label: 'Add mail domain' }).then((r) => { setDomain({ ...domain, name: '' }); list.refetch(); setPicked(String(r.data.id)) })}>Add domain</button>
        </div>
        <DataTable label="Mail domains" rows={domains} loading={list.isLoading} rowKey={(r) => String(r.id)} onRowClick={(r) => { setPicked(String(r.id)); window.scrollTo({ top: 0, behavior: 'smooth' }) }}
          columns={[
            { key: 'name', header: 'Domain', render: (r) => <span className="font-semibold">{String(r.name)}</span> },
            { key: 'mailboxes', header: 'Mailboxes', value: (r) => Number(r.mailboxes) },
            { key: 'aliases', header: 'Aliases', value: (r) => Number(r.aliases) },
            { key: 'dns_ok', header: 'DNS', render: (r) => r.dns_ok == null ? <Badge>not checked</Badge> : <Badge tone={r.dns_ok ? 'g' : 'y'}>{r.dns_ok ? 'ok' : 'incomplete'}</Badge> },
          ]} />
      </Card>

      <MailHolds base="" canRelease />
      <MailLog base="" />

      <Card title="Mail servers">
        {mailServers.length > 0 && <ul className="m-0 mb-3 p-0 list-none flex flex-col gap-3 text-sm">
          {mailServers.map((m) => <li key={String(m.id)}>
            <span className="font-semibold">{String(m.hostname)}</span> on {String(m.server)} <Badge tone={m.status === 'running' ? 'g' : m.status === 'failed' ? 'r' : 'y'}>{String(m.status)}</Badge>
            {m.synced_at ? <span className="text-muted"> · synced {ago(String(m.synced_at))}</span> : null}
            {m.error ? <span className="text-danger"> · {String(m.error)}</span> : null}
            {m.status === 'running' && <ServerTools m={m} onChange={() => list.refetch()} />}
          </li>)}
        </ul>}
        <div className="flex flex-wrap items-end gap-2 text-sm">
          <label className="flex flex-col gap-1">Server
            <Select className="input" value={install.server_id} onChange={(e) => setInstall({ ...install, server_id: e.target.value })}>
              <option value="">Choose…</option>
              {servers.rows.filter((s) => !mailServers.some((m) => m.server_id === s.id)).map((s) => <option key={String(s.id)} value={String(s.id)}>{String(s.name)}</option>)}
            </Select>
          </label>
          <label className="flex flex-col gap-1">Mail hostname
            <input className="input" placeholder="mail.example.com" value={install.hostname} onChange={(e) => setInstall({ ...install, hostname: e.target.value })} />
          </label>
          <button type="button" className="btn btn-primary" disabled={busy || !install.server_id || !install.hostname} onClick={() => run({ path: '/mail/servers', body: install, label: 'Install mail', success: 'Installing the mail stack' }).then(() => { setInstall({ server_id: '', hostname: '' }); list.refetch() })}>Install mail</button>
        </div>
        <p className="m-0 mt-2 text-xs text-muted">Opens ports 25, 465, 587 and 993. Point the hostname's A record at the server, and ask the provider to set reverse DNS to it.</p>
      </Card>
    </>
  )
}

function ServerTools({ m, onChange }: { m: R; onChange: () => void }) {
  const { run, busy } = useAction()
  const set = (m.settings as R) ?? {}
  const h = m.health as R | null
  const [logs, setLogs] = useState<string[] | null>(null)
  const edit = () => {
    const per = window.prompt('Messages each mailbox may send per hour:', String(set.send_per_hour))
    if (per === null) return
    const reject = window.prompt('Reject mail with a spam score of at least:', String(set.spam_reject))
    if (reject === null) return
    const header = window.prompt('Mark as spam (header) from a score of:', String(set.spam_header))
    if (header === null) return
    run({ path: `/mail/servers/${m.id}`, method: 'PATCH', body: { send_per_hour: Number(per), spam_reject: Number(reject), spam_header: Number(header) }, label: 'Mail limits', success: 'Saved; the mail server restarts' }).then(onChange)
  }
  const relay = () => {
    const host = window.prompt('Relay (smarthost) hostname, e.g. email-smtp.eu-north-1.amazonaws.com. Empty turns the relay off:', String((set.relay as R)?.host ?? ''))
    if (host === null) return
    if (!host) { run({ path: `/mail/servers/${m.id}`, method: 'PATCH', body: { relay: null }, label: 'Relay off' }).then(onChange); return }
    const username = window.prompt('Relay username:', String((set.relay as R)?.username ?? ''))
    if (!username) return
    const password = window.prompt((set.relay as R)?.password_set ? 'Relay password (empty keeps the current one):' : 'Relay password:') ?? ''
    run({ path: `/mail/servers/${m.id}`, method: 'PATCH', body: { relay: { host, port: 587, username, ...(password ? { password } : {}) } }, label: 'Relay', success: 'Saved; the mail server restarts' }).then(onChange)
  }
  return (
    <div className="mt-1 ml-4 flex flex-col gap-1 text-xs">
      {m.webmail_hostname ? <span>Webmail <Badge tone={m.webmail_status === 'running' ? 'g' : m.webmail_status === 'failed' ? 'r' : 'y'}>{String(m.webmail_status)}</Badge>{m.webmail_status === 'running' ? <> · <a href={`https://${m.webmail_hostname}`} target="_blank" rel="noopener noreferrer">{String(m.webmail_hostname)}</a></> : null}</span> : null}
      <span className="text-muted">Limit {String(set.send_per_hour)} messages/hour per mailbox · spam header at {String(set.spam_header)}, reject at {String(set.spam_reject)} · relay: {set.relay ? String((set.relay as R).host) : 'none (direct delivery)'}</span>
      {m.tls ? <span>TLS <Badge tone={(m.tls as R).source === 'acme' ? 'g' : 'y'}>{(m.tls as R).source === 'acme' ? "Let's Encrypt (from Traefik)" : 'self-signed'}</Badge>{(m.tls as R).not_after ? <span className="text-muted"> until {String((m.tls as R).not_after).slice(0, 10)}</span> : null}{(m.tls as R).source !== 'acme' ? <span className="text-muted"> · add the mail hostname as a domain so Traefik issues a certificate</span> : null}</span> : null}
      {h && <span>
        Reverse DNS <Badge tone={h.ptr_ok ? 'g' : 'r'}>{h.ptr_ok ? 'ok' : `${((h.ptr as string[]) ?? []).join(', ') || 'missing'}`}</Badge>{' '}
        A record <Badge tone={h.a_ok ? 'g' : 'r'}>{h.a_ok ? 'ok' : 'missing'}</Badge>{' '}
        Blocklists <Badge tone={((h.listed as string[]) ?? []).length ? 'r' : 'g'}>{((h.listed as string[]) ?? []).join(', ') || 'clean'}</Badge>
        {m.health_checked_at ? <span className="text-muted"> · {ago(String(m.health_checked_at))}</span> : null}
      </span>}
      <span className="flex flex-wrap gap-2">
        <button type="button" className="btn btn-sm" disabled={busy} onClick={edit}>Limits and spam</button>
        <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/mail/servers/${m.id}/certs`, label: 'Refresh certificate', success: 'Checking Traefik for a certificate' }).then(onChange)}>Refresh certificate</button>
        <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/mail/servers/${m.id}/backups`, body: {}, label: 'Back up mailboxes nightly', success: 'Nightly backup policy saved; change its destination under Backups' }).then(onChange)}>Nightly backup</button>
        <button type="button" className="btn btn-sm" disabled={busy} onClick={relay}>Relay</button>
        <button type="button" className="btn btn-sm" disabled={busy} onClick={() => {
          const hostname = window.prompt('Webmail address (point its A record at this server):', String(m.webmail_hostname ?? 'webmail.' + String(m.hostname).replace(/^mail\./, '')))
          if (hostname) run({ path: `/mail/servers/${m.id}/webmail`, body: { hostname }, label: 'Install webmail', success: 'Installing Roundcube webmail' }).then(onChange)
        }}>{m.webmail_hostname ? 'Reinstall webmail' : 'Install webmail'}</button>
        <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/mail/servers/${m.id}/health`, label: 'Check reverse DNS and blocklists', silent: true }).then(onChange)}>Check health</button>
        <button type="button" className="btn btn-sm" onClick={async () => { const { api } = await import('../../api/client'); const r = await api.get<{ data: { lines: string[] } }>(`/mail/servers/${m.id}/logs`, { tail: 200 }); setLogs(r.data.lines) }}>Logs</button>
      </span>
      {logs && <pre className="m-0 p-2 rounded bg-[var(--code-bg)] text-[var(--code-fg)] text-xs whitespace-pre-wrap max-h-64 overflow-auto">{logs.join('\n') || 'Fetching… press Logs again in a moment.'}</pre>}
    </div>
  )
}

function DomainPanel({ id, domains, onPick, newBox, setNewBox, onChange }: { id: string; domains: R[]; onPick: (id: string) => void; newBox: boolean; setNewBox: (v: boolean) => void; onChange: () => void }) {
  const d = useOne<R>(`/mail/domains/${id}`)
  const w = d.item ?? {}
  const { run, busy } = useAction()
  const [box, setBox] = useState({ local_part: '', password: '', quota_mb: 1024 })
  const [alias, setAlias] = useState({ local_part: '', destinations: '' })
  const [importing, setImporting] = useState<R | null>(null)
  const refetch = () => { d.refetch(); onChange() }
  const status = new Map(((w.dns as R[]) ?? []).map((r) => [`${r.type} ${r.name}`, String(r.status)]))
  const boxes = (w.mailbox_list as R[]) ?? []
  const name = String(w.name ?? domains.find((x) => String(x.id) === id)?.name ?? '')

  return (
    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px] items-start">
      <div className="flex flex-col gap-4 min-w-0">
        <Card pad={false} title="Mailboxes" subtitle="IMAP: port 993 (SSL). SMTP: port 587 (STARTTLS) or 465 (SSL). Username: the full address."
          actions={<Select className="input" aria-label="Mail domain" value={id} onChange={(e) => onPick(e.target.value)}>
            {domains.map((x) => <option key={String(x.id)} value={String(x.id)}>{String(x.name)}</option>)}
          </Select>}>
          {newBox && <div className="px-5 pb-4 flex flex-wrap items-center gap-2 text-sm">
            <input className="input w-32" aria-label="Mailbox name" placeholder="name" value={box.local_part} onChange={(e) => setBox({ ...box, local_part: e.target.value })} />
            <span className="text-muted text-xs">@{name}</span>
            <input className="input w-44" type="password" autoComplete="new-password" aria-label="Password" placeholder="password (10+)" value={box.password} onChange={(e) => setBox({ ...box, password: e.target.value })} />
            <Select className="input" aria-label="Quota" value={box.quota_mb} onChange={(e) => setBox({ ...box, quota_mb: Number(e.target.value) })}>
              {[512, 1024, 2048, 5120, 10240].map((q) => <option key={q} value={q}>{size(q)}</option>)}
            </Select>
            <button type="button" className="btn btn-sm btn-primary" disabled={busy || !box.local_part || box.password.length < 10} onClick={() => run({ path: `/mail/domains/${id}/mailboxes`, body: box, label: 'Add mailbox' }).then(() => { setBox({ local_part: '', password: '', quota_mb: 1024 }); setNewBox(false); refetch() })}>Add mailbox</button>
            <button type="button" className="btn btn-sm" onClick={() => setNewBox(false)}>Cancel</button>
          </div>}
          {boxes.length === 0
            ? <Empty>{d.isLoading ? 'Loading…' : 'No mailboxes on this domain yet.'}</Empty>
            : <div className="overflow-x-auto">
              <table className="w-full min-w-[640px] text-[13px]">
                <thead><tr className="text-left text-xs text-muted border-b border-[var(--line)]">
                  <th className="px-5 py-2">Address</th><th className="px-3 py-2">Quota</th><th className="px-3 py-2">Status</th><th className="px-5 py-2 text-right">Actions</th>
                </tr></thead>
                <tbody>
                  {boxes.map((b) => <tr key={String(b.id)} className="border-t border-[var(--line)]">
                    <td className="px-5 py-2 font-mono text-xs">{String(b.local_part)}@{name}</td>
                    <td className="px-3 py-2 text-muted">{size(b.quota_mb)}</td>
                    <td className="px-3 py-2"><Badge tone={b.enabled ? 'g' : 'n'}>{b.enabled ? 'active' : 'disabled'}</Badge>{(b.autoresponder as R)?.enabled ? <> <Badge tone="b">auto-reply</Badge></> : null}</td>
                    <td className="px-5 py-2"><div className="flex flex-wrap justify-end gap-1.5">
                      <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/mail/domains/${id}/mailboxes/${b.id}`, method: 'PATCH', body: { enabled: !b.enabled }, label: b.enabled ? 'Disable mailbox' : 'Enable mailbox' }).then(refetch)}>{b.enabled ? 'Disable' : 'Enable'}</button>
                      <button type="button" className="btn btn-sm" disabled={busy} onClick={() => {
                        const ar = (b.autoresponder as R) ?? {}
                        if (ar.enabled) { run({ path: `/mail/domains/${id}/mailboxes/${b.id}/autoresponder`, method: 'PATCH', body: { enabled: false }, label: 'Auto-reply off' }).then(refetch); return }
                        const subject = window.prompt('Auto-reply subject:', 'Out of office'); if (!subject) return
                        const body = window.prompt('Auto-reply message:', 'I am away and will reply when I am back.'); if (!body) return
                        const until = window.prompt('Until (YYYY-MM-DD, empty for no end date):', '') ?? ''
                        run({ path: `/mail/domains/${id}/mailboxes/${b.id}/autoresponder`, method: 'PATCH', body: { enabled: true, subject, body, until: until || null }, label: 'Auto-reply on' }).then(refetch)
                      }}>{(b.autoresponder as R)?.enabled ? 'Auto-reply off' : 'Auto-reply'}</button>
                      <button type="button" className="btn btn-sm" disabled={busy} onClick={() => setImporting(b)}>Import</button>
                      <button type="button" className="btn btn-sm" disabled={busy} onClick={() => { const password = window.prompt('New password (at least 10 characters):'); if (password) run({ path: `/mail/domains/${id}/mailboxes/${b.id}`, method: 'PATCH', body: { password }, label: 'Change password' }) }}>Password</button>
                      <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => { if (window.confirm(`Delete ${b.local_part}@${w.name}? Stored mail stays on the server until it is cleaned up.`)) run({ path: `/mail/domains/${id}/mailboxes/${b.id}`, method: 'DELETE', label: 'Delete mailbox' }).then(refetch) }}>Delete</button>
                    </div></td>
                  </tr>)}
                </tbody>
              </table>
            </div>}
        </Card>

        <ImportList base="" domainId={id} boxes={boxes} />
        <ImportDialog base="" domainId={id} box={importing ? String(importing.id) : null} address={importing ? `${importing.local_part}@${name}` : ''} onClose={() => setImporting(null)} />

        <Card title="Aliases and forwarding" className="text-sm">
          <ul className="m-0 mb-2 p-0 list-none flex flex-col gap-1">
            {((w.alias_list as R[]) ?? []).map((a) => <li key={String(a.id)} className="flex flex-wrap items-center gap-2">
              <span className="flex-1 min-w-0 break-all font-mono text-xs">{String(a.local_part)}@{String(w.name)} → {(a.destinations as string[]).join(', ')}</span>
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
        </Card>
      </div>

      <div className="flex flex-col gap-4 min-w-0">
        <Card title={`DNS · ${name}`} className="text-sm"
          actions={<button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/mail/domains/${id}/dns/check`, label: 'Check DNS', silent: true }).then(refetch)}>Check</button>}>
          <ul className="m-0 p-0 list-none flex flex-col">
            {((w.records as R[]) ?? []).map((r) => {
              const s = status.get(`${r.type} ${r.name}`)
              return <li key={`${r.type} ${r.name}`} className="flex flex-col gap-1 py-2 border-t border-[var(--line)] first:border-t-0">
                <span className="flex items-center gap-2"><span className="font-semibold w-12">{String(r.type)}</span><span className="font-mono text-xs flex-1 min-w-0 break-all">{String(r.name)}</span>{s ? <Badge tone={s === 'ok' ? 'g' : 'r'}>{s === 'ok' ? 'OK' : s.toUpperCase()}</Badge> : null}</span>
                <span className="font-mono text-xs break-all text-muted">{r.priority ? `${r.priority} ` : ''}{String(r.value)}</span>
              </li>
            })}
          </ul>
          <button type="button" className="btn btn-primary w-full mt-3" disabled={busy} onClick={() => run({ path: `/mail/domains/${id}/dns/publish`, label: 'Publish to DNS zone', success: 'Published where a managed zone holds the domain' }).then(refetch)}>Write records to DNS zone</button>
        </Card>

        <Card title="DKIM key" className="text-sm">
          <p className="m-0">Signing with selector <span className="font-mono">{String(w.dkim_selector)}</span>{w.dkim_rotated_at ? `, rotated ${ago(String(w.dkim_rotated_at))}` : ''}.{w.dkim_previous_selector ? <> The old record <span className="font-mono">{String(w.dkim_previous_selector)}._domainkey</span> can be deleted after a few days.</> : null}</p>
          {w.dkim_next_selector
            ? <div className="mt-2 flex flex-wrap items-center gap-2"><span>New key <span className="font-mono">{String(w.dkim_next_selector)}</span> is waiting for its DNS record.</span>
                <button type="button" className="btn btn-sm btn-primary" disabled={busy} onClick={() => run({ path: `/mail/domains/${id}/dkim/complete`, label: 'Switch to the new DKIM key' }).then(refetch)}>Switch to new key</button></div>
            : <button type="button" className="btn btn-sm mt-2" disabled={busy} onClick={() => run({ path: `/mail/domains/${id}/dkim/rotate`, label: 'Rotate DKIM key', success: 'New key created; publish its record, then switch' }).then(refetch)}>Rotate key</button>}
          <button type="button" className="btn btn-sm btn-danger mt-4 block" disabled={busy} onClick={() => { if (window.confirm(`Stop hosting mail for ${w.name}? Its mailboxes and aliases are removed.`)) run({ path: `/mail/domains/${id}`, method: 'DELETE', label: 'Delete mail domain' }).then(onChange) }}>Delete domain</button>
        </Card>
      </div>
    </div>
  )
}
