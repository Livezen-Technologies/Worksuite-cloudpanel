import { useState } from 'react'
import { useAction, useOne } from '../../api/hooks'
import { Card, PageHeader, Toggle } from '../../components/Card'
import { Badge, type Tone } from '../../components/Badge'
import { useToast } from '../../components/Toasts'
import { ago } from '../../lib/format'
import { num, type R } from '../../lib/util'
import { CronManager } from '../../components/CronManager'
import { ImportDialog, ImportList } from '../../components/MailImport'
import { MailHolds, MailLog, SpamLevel } from '../../components/MailGuard'


/** UCRON-1..4, CRON-1..8: scheduled jobs in the customer's apps or calling a URL. */
export function UserCron() {
  return <CronManager base="/app/cron" title="Scheduled jobs" />
}

/** USEC-1..3: the customer's own sites only. */
export function UserSecurity() {
  const page = useOne<R>('/app/security', undefined, { refetchInterval: 15000 })
  const { run, busy } = useAction()
  const w = page.item ?? {}
  const score = Number(w.score ?? 0)
  const tone: Tone = score >= 80 ? 'g' : score >= 50 ? 'y' : 'r'
  return (
    <>
      <PageHeader title="Security" subtitle="Malware scanning and protection for your sites."
        actions={<button type="button" className="btn btn-primary" disabled={busy || !!w.scanning} onClick={() => run({ path: '/app/security/scan', label: 'Scan my sites', success: 'Scan started' }).then(() => page.refetch())}>{w.scanning ? 'Scanning…' : 'Scan now'}</button>} />
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(160px,1fr))]">
        <Card><div className="text-xs text-muted">Security score</div><div className="text-3xl font-bold"><Badge tone={tone}>{score}</Badge></div></Card>
        <Card><div className="text-xs text-muted">Open threats</div><div className="text-3xl font-bold">{String(w.open_threats ?? 0)}</div></Card>
        <Card><div className="text-xs text-muted">Quarantined</div><div className="text-3xl font-bold">{String(w.quarantined ?? 0)}</div></Card>
        <Card><div className="text-xs text-muted">Scans (30 days)</div><div className="text-3xl font-bold">{String(w.scans_30d ?? 0)}</div></Card>
      </div>
      <Card title="Threats">
        {((w.threats as R[]) ?? []).length === 0 ? <p className="m-0 text-sm text-muted">Nothing found. Scans run weekly and when you press Scan now.</p> : (
          <ul className="m-0 p-0 list-none flex flex-col gap-2 text-sm">
            {(w.threats as R[]).map((t) => <li key={String(t.id)} className="flex flex-wrap items-center gap-2">
              <span className="flex-1 min-w-0"><span className="font-semibold">{String(t.site)}</span> <span className="font-mono text-xs break-all">{String(t.path)}</span><br /><span className="text-xs text-muted">{String(t.signature)} · {ago(String(t.detected_at))}</span></span>
              <Badge tone={t.status === 'infected' ? 'r' : t.status === 'quarantined' ? 'y' : 'n'}>{String(t.status)}</Badge>
              {t.status === 'infected' && <button type="button" className="btn btn-sm btn-primary" disabled={busy} onClick={() => run({ path: `/app/security/threats/${t.id}/quarantine`, label: 'Quarantine' }).then(() => page.refetch())}>Quarantine</button>}
              {['infected', 'quarantined'].includes(String(t.status)) && <button type="button" className="btn btn-sm" disabled={busy} onClick={() => { if (window.confirm('Mark as not a threat? The file stays and is not flagged again.')) run({ path: `/app/security/threats/${t.id}/ignore`, label: 'Not a threat' }).then(() => page.refetch()) }}>Not a threat</button>}
            </li>)}
          </ul>
        )}
      </Card>
      {((w.sites as R[]) ?? []).some((s) => (s.fixes as R[]).length) && <Card title="How to raise your score">
        <ul className="m-0 pl-4 text-sm">{(w.sites as R[]).flatMap((s) => (s.fixes as R[]).map((f, i) => <li key={`${s.site}-${i}`}>{String(s.site)}: {String(f.fix)}</li>))}</ul>
      </Card>}
    </>
  )
}

/** UMAIL-001, 002, 004..006: email on the customer's own domains. */
export function UserMail() {
  const page = useOne<R>('/app/mail')
  const { run, busy } = useAction()
  const toast = useToast()
  const w = page.item ?? {}
  const [domain, setDomain] = useState('')
  const [box, setBox] = useState<Record<string, { local: string; password: string }>>({})
  const [importing, setImporting] = useState<{ domain: string; box: R } | null>(null)
  const refetch = () => page.refetch()
  if (page.item && !w.available) return <><PageHeader title="Email" /><Card><p className="m-0 text-sm">Email hosting is not available yet. Contact support to turn it on.</p></Card></>
  const s = w.settings as R | null
  return (
    <>
      <PageHeader title="Email" subtitle={`Mailboxes on your own domains. ${w.used ?? 0} of ${w.limit ?? '…'} mailboxes used.`}
        actions={w.webmail_url ? <a className="btn" href={String(w.webmail_url)} target="_blank" rel="noopener noreferrer">Open webmail</a> : undefined} />
      <Card title="Add email to a domain">
        <div className="flex flex-wrap gap-2 text-sm">
          <input className="input" placeholder="example.com" value={domain} onChange={(e) => setDomain(e.target.value)} />
          <button type="button" className="btn btn-primary" disabled={busy || !domain} onClick={() => run({ path: '/app/mail/domains', body: { name: domain.trim() }, label: 'Add email domain', success: 'Email added; set the DNS records below' }).then(() => { setDomain(''); refetch() }).catch(() => {})}>Add</button>
        </div>
        <p className="m-0 mt-2 text-xs text-muted">The domain must already be connected to one of your apps under Domains.</p>
      </Card>
      {s && <Card title="Email app settings">
        <dl className="m-0 grid gap-1 text-sm [grid-template-columns:max-content_1fr]">
          <dt className="text-muted">Incoming (IMAP)</dt><dd className="m-0 font-mono">{String((s.imap as R).host)} · port {String((s.imap as R).port)} · {String((s.imap as R).security)}</dd>
          <dt className="text-muted">Outgoing (SMTP)</dt><dd className="m-0 font-mono">{String((s.smtp as R).host)} · port {String((s.smtp as R).port)} · {String((s.smtp as R).security)}</dd>
          <dt className="text-muted">Username</dt><dd className="m-0">{String(s.username)}</dd>
        </dl>
      </Card>}
      {((w.domains as R[]) ?? []).map((d) => {
        const f = box[String(d.id)] ?? { local: '', password: '' }
        return (
          <Card key={String(d.id)} title={String(d.name)}>
            <div className="flex flex-col gap-4 text-sm">
              <section>
                <div className="flex items-center justify-between mb-1"><h3 className="m-0 text-sm font-semibold">DNS records</h3>
                  <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/app/mail/domains/${d.id}/dns/check`, label: 'Check DNS', silent: true }).then(refetch)}>Check</button></div>
                <ul className="m-0 p-0 list-none flex flex-col gap-2">
                  {(d.records as R[]).map((r) => <li key={`${r.type}${r.name}`}>
                    <Badge tone={r.status === 'set' ? 'g' : r.status === 'not checked' ? 'n' : r.status === 'recommended' ? 'y' : 'r'}>{String(r.status)}</Badge> {String(r.purpose)}
                    <div className="font-mono text-xs break-all text-muted">{String(r.type)} {String(r.name)} → {r.priority ? `${r.priority} ` : ''}{String(r.value)}</div>
                  </li>)}
                </ul>
              </section>
              <section>
                <h3 className="m-0 mb-1 text-sm font-semibold">Mailboxes</h3>
                <ul className="m-0 mb-2 p-0 list-none flex flex-col gap-1">
                  {(d.mailboxes as R[]).map((b) => <li key={String(b.id)} className="flex flex-wrap items-center gap-2">
                    <span className="flex-1 font-mono text-xs">{String(b.address)}</span>
                    {(b.autoresponder as R)?.enabled ? <Badge tone="b">auto-reply</Badge> : null}
                    {b.held_reason ? <Badge tone="r">sending on hold</Badge> : null}
                    <SpamLevel path={`/app/mail/domains/${d.id}/mailboxes/${b.id}`} spam={b.spam as R | null} onSaved={refetch} />
                    {(b.client as R)?.webmail_url ? <a className="btn btn-sm" href={String((b.client as R).webmail_url)} target="_blank" rel="noopener noreferrer">Webmail</a> : null}
                    <button type="button" className="btn btn-sm" disabled={busy} onClick={() => setImporting({ domain: String(d.id), box: b })}>Import mail</button>
                    <button type="button" className="btn btn-sm" disabled={busy} onClick={() => { const p = window.prompt(`New password for ${b.address} (10+ characters):`); if (p) run({ path: `/app/mail/domains/${d.id}/mailboxes/${b.id}`, method: 'PATCH', body: { password: p }, label: 'Reset password', success: 'Password changed' }) }}>Reset password</button>
                    <button type="button" className="btn btn-sm" disabled={busy} onClick={() => {
                      if ((b.autoresponder as R)?.enabled) { run({ path: `/app/mail/domains/${d.id}/mailboxes/${b.id}/autoresponder`, method: 'PATCH', body: { enabled: false }, label: 'Auto-reply off' }).then(refetch); return }
                      const subject = window.prompt('Auto-reply subject:', 'Out of office'); if (!subject) return
                      const body = window.prompt('Message:', 'I am away and will reply when I am back.'); if (!body) return
                      run({ path: `/app/mail/domains/${d.id}/mailboxes/${b.id}/autoresponder`, method: 'PATCH', body: { enabled: true, subject, body }, label: 'Auto-reply on' }).then(refetch)
                    }}>{(b.autoresponder as R)?.enabled ? 'Auto-reply off' : 'Auto-reply'}</button>
                    <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => { if (window.confirm(`Delete ${b.address}?`)) run({ path: `/app/mail/domains/${d.id}/mailboxes/${b.id}`, method: 'DELETE', label: 'Delete mailbox' }).then(refetch) }}>Delete</button>
                  </li>)}
                </ul>
                <div className="flex flex-wrap gap-2">
                  <input className="input w-32" placeholder="name" value={f.local} onChange={(e) => setBox({ ...box, [String(d.id)]: { ...f, local: e.target.value } })} />
                  <span className="self-center">@{String(d.name)}</span>
                  <input className="input w-44" type="password" autoComplete="new-password" placeholder="password (10+)" value={f.password} onChange={(e) => setBox({ ...box, [String(d.id)]: { ...f, password: e.target.value } })} />
                  <button type="button" className="btn btn-sm btn-primary" disabled={busy || !f.local || f.password.length < 10} onClick={() => run({ path: `/app/mail/domains/${d.id}/mailboxes`, body: { local_part: f.local, password: f.password }, label: 'Create mailbox', success: 'Mailbox created' }).then(() => { setBox({ ...box, [String(d.id)]: { local: '', password: '' } }); refetch() }).catch((e) => toast.error(e, 'Mailbox not created'))}>Create mailbox</button>
                </div>
              </section>
              <ImportList base="/app" domainId={String(d.id)} boxes={d.mailboxes as R[]} />
              <section>
                <h3 className="m-0 mb-1 text-sm font-semibold">Forwarding</h3>
                <ul className="m-0 mb-2 p-0 list-none flex flex-col gap-1">{(d.aliases as R[]).map((a) => <li key={String(a.id)} className="flex items-center gap-2"><span className="flex-1 font-mono text-xs">{String(a.address)} → {(a.destinations as string[]).join(', ')}</span>
                  <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => run({ path: `/app/mail/domains/${d.id}/aliases/${a.id}`, method: 'DELETE', label: 'Delete forwarding' }).then(refetch)}>Delete</button></li>)}</ul>
                <button type="button" className="btn btn-sm" disabled={busy} onClick={() => { const local = window.prompt(`Forward which address? (name before @${d.name})`); if (!local) return; const to = window.prompt('Forward to (comma-separated addresses):'); if (!to) return; run({ path: `/app/mail/domains/${d.id}/aliases`, body: { local_part: local, destinations: to.split(',').map((x) => x.trim()).filter(Boolean) }, label: 'Add forwarding' }).then(refetch) }}>Add forwarding</button>
              </section>
            </div>
          </Card>
        )
      })}
      <MailHolds base="/app" canRelease={false} />
      <MailLog base="/app" />
      <ImportDialog base="/app" domainId={importing?.domain ?? ''} box={importing ? String(importing.box.id) : null} address={String(importing?.box.address ?? '')} onClose={() => { setImporting(null); refetch() }} />
    </>
  )
}

const MIG_STEPS: [string, string][] = [['requested', 'Received'], ['fetching', 'Approved, copying'], ['migrating', 'Copying'], ['migrated', 'Ready on a test address'], ['live', 'Switched']]

/** UMIG-001, 003, 004: ask for a move and follow it. */
export function UserMigrations() {
  const page = useOne<R[]>('/app/migrations', undefined, { refetchInterval: 20000 })
  const { run, busy } = useAction()
  const [f, setF] = useState({ archive_url: '', notes: '' })
  const step = (m: R) => {
    const sites = (m.sites as R[]) ?? []
    if (m.status === 'rejected') return -1
    if (sites.some((s) => s.status === 'live')) return 4
    if (m.status === 'migrated') return 3
    return Math.max(0, MIG_STEPS.findIndex(([k]) => k === m.status))
  }
  return (
    <>
      <PageHeader title="Move a site here" subtitle="We copy your site from your current host. Your old site keeps running until you switch." />
      <Card title="Request a move">
        <div className="flex flex-col gap-3 text-sm">
          <label className="flex flex-col gap-1">Link to a full cPanel backup<input className="input font-mono text-xs" placeholder="https://…/cpmove-user.tar.gz" value={f.archive_url} onChange={(e) => setF({ ...f, archive_url: e.target.value })} /></label>
          <label className="flex flex-col gap-1">Anything we should know? (optional)<textarea className="input min-h-20" value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} /></label>
          <p className="m-0 text-xs text-muted">In cPanel: Backup → Download a Full Account Backup, then share a download link to it. Your old site keeps running while we copy. A busy site is copied again just before the switch, and you can switch back for a while if anything looks wrong.</p>
          <button type="button" className="btn btn-primary self-start" disabled={busy || !f.archive_url} onClick={() => run({ path: '/app/migrations', body: { archive_url: f.archive_url, notes: f.notes || null }, label: 'Request a move', success: 'Request sent; we will review it shortly' }).then(() => { setF({ archive_url: '', notes: '' }); page.refetch() })}>Send request</button>
        </div>
      </Card>
      {((page.item as R[]) ?? []).map((m) => {
        const at = step(m)
        return (
          <Card key={String(m.id)} title={`Requested ${ago(String(m.created_at))}`}>
            {at < 0 ? <p className="m-0 text-sm"><Badge tone="r">not accepted</Badge> {String(m.decision ?? '')}</p> : (
              <ol className="m-0 p-0 list-none flex flex-wrap gap-2 text-sm">
                {MIG_STEPS.filter(([k]) => k !== 'fetching').map(([k, label], i) => <li key={k}><Badge tone={i <= (at >= 2 ? at - 1 : at) ? 'g' : 'n'}>{i + 1}. {label}</Badge></li>)}
              </ol>
            )}
            {((m.sites as R[]) ?? []).length > 0 && <ul className="m-0 mt-3 pl-4 text-sm">{(m.sites as R[]).map((s) => <li key={String(s.domain)}>{String(s.domain)}: {String(s.status)}{s.temp_url ? <> · <a href={String(s.temp_url)} target="_blank" rel="noopener noreferrer" className="text-[var(--accent)]">test address</a></> : null}</li>)}</ul>}
          </Card>
        )
      })}
    </>
  )
}

const FW_COUNTRIES: [string, string][] = [['CN', 'China'], ['RU', 'Russia'], ['KP', 'North Korea'], ['IR', 'Iran'], ['BR', 'Brazil'], ['IN', 'India'], ['US', 'United States'], ['GB', 'United Kingdom'], ['DE', 'Germany'], ['SG', 'Singapore'], ['AU', 'Australia'], ['LK', 'Sri Lanka']]

/** FW-015: challenge-protected paths and country rules on the customer's own domains. */
export function UserFirewall() {
  const page = useOne<R>('/app/firewall')
  const { run, busy } = useAction()
  const w = page.item ?? {}
  const limits = (w.limits ?? {}) as R
  const used = (w.used ?? {}) as R
  const [path, setPath] = useState<Record<string, string>>({})
  const [code, setCode] = useState<Record<string, string>>({})
  const refetch = () => page.refetch()
  const saveCountries = (d: R, next: R[]) => run({ path: `/app/firewall/domains/${d.id}/countries`, method: 'PATCH', body: { countries: next }, label: 'Country rules', success: 'Country rules saved' }).then(refetch).catch(() => {})
  const cycle = (d: R, c: string) => {
    const list = ((d.countries as R[]) ?? []).map((x) => ({ code: String(x.code), state: String(x.state) }))
    const cur = list.find((x) => x.code === c)
    const next = !cur ? [...list, { code: c, state: 'challenge' }] : cur.state === 'challenge' ? list.map((x) => (x.code === c ? { ...x, state: 'block' } : x)) : list.filter((x) => x.code !== c)
    saveCountries(d, next)
  }
  return (
    <>
      <PageHeader title="Firewall" subtitle={`Ask visitors to prove they are human on sensitive pages, and block or challenge countries. ${num(used.paths)} of ${num(limits.paths)} protected paths and ${num(used.countries)} of ${num(limits.countries)} country rules used.`} />
      {w.enforced === false && <Card><p className="m-0 text-sm text-muted">These settings are saved but not applied yet: the platform's firewall is being set up. Ask support for details.</p></Card>}
      {((w.domains as R[]) ?? []).length === 0 && page.item ? <Card><p className="m-0 text-sm text-muted">Connect a domain to one of your sites first, under Domains.</p></Card> : null}
      {((w.domains as R[]) ?? []).map((d) => {
        const countries = new Map(((d.countries as R[]) ?? []).map((x) => [String(x.code), String(x.state)]))
        const extra = [...countries.keys()].filter((c) => !FW_COUNTRIES.some(([k]) => k === c))
        return (
          <Card key={String(d.id)} title={String(d.name)}>
            <div className="grid gap-6 [grid-template-columns:repeat(auto-fit,minmax(300px,1fr))] text-sm">
              <section>
                <h3 className="m-0 mb-1 text-sm font-semibold">Protected pages</h3>
                <p className="m-0 mb-2 text-xs text-muted">Visitors must pass a quick check (CAPTCHA) before these pages load. Use * at the end for a whole section, e.g. /wp-admin/*.</p>
                <ul className="m-0 mb-2 p-0 list-none flex flex-col gap-1">
                  {((d.paths as R[]) ?? []).map((p) => <li key={String(p.id)} className="flex items-center gap-2 py-1 border-t border-line first:border-t-0">
                    <span className="flex-1 font-mono text-xs break-all">{String(p.path)}</span>
                    <Toggle checked={!!p.enabled} label={`Protect ${String(p.path)}`} onChange={(v) => run({ path: `/app/firewall/domains/${d.id}/paths/${p.id}`, method: 'PATCH', body: { enabled: v }, label: 'Protected page', success: v ? 'Protection on' : 'Protection off' }).then(refetch).catch(() => {})} />
                    <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => run({ path: `/app/firewall/domains/${d.id}/paths/${p.id}`, method: 'DELETE', label: 'Remove protected page' }).then(refetch).catch(() => {})}>Remove</button>
                  </li>)}
                </ul>
                <div className="flex flex-wrap gap-2">
                  <input className="input flex-1 min-w-[160px] font-mono" placeholder="/wp-login.php" aria-label={`Page to protect on ${d.name}`} value={path[String(d.id)] ?? ''} onChange={(e) => setPath({ ...path, [String(d.id)]: e.target.value })} />
                  <button type="button" className="btn" disabled={busy || !(path[String(d.id)] ?? '').startsWith('/')} onClick={() => run({ path: `/app/firewall/domains/${d.id}/paths`, body: { path: path[String(d.id)] }, label: 'Protect page', success: 'Page protected' }).then(() => { setPath({ ...path, [String(d.id)]: '' }); refetch() }).catch(() => {})}>Protect</button>
                </div>
              </section>
              <section>
                <h3 className="m-0 mb-1 text-sm font-semibold">Countries</h3>
                <p className="m-0 mb-2 text-xs text-muted">Click a country to cycle: allowed → challenge → blocked.</p>
                <div className="grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(150px,1fr))]">
                  {[...FW_COUNTRIES, ...extra.map((c) => [c, c] as [string, string])].map(([c, name]) => {
                    const st = countries.get(c)
                    return <button key={c} type="button" disabled={busy} onClick={() => cycle(d, c)} className="flex items-center gap-2 min-h-[40px] px-2.5 rounded-lg border border-line bg-card text-left text-[13px] cursor-pointer hover:bg-head">
                      <span className="font-mono text-xs text-muted w-6">{c}</span><span className="flex-1 truncate">{name}</span>
                      <Badge tone={st === 'block' ? 'r' : st === 'challenge' ? 'y' : 'g'}>{st === 'block' ? 'blocked' : st === 'challenge' ? 'challenge' : 'allowed'}</Badge>
                    </button>
                  })}
                </div>
                <div className="flex flex-wrap gap-2 mt-2">
                  <input className="input w-24 font-mono uppercase" maxLength={2} placeholder="FR" aria-label="Another country code" value={code[String(d.id)] ?? ''} onChange={(e) => setCode({ ...code, [String(d.id)]: e.target.value.toUpperCase().replace(/[^A-Z]/g, '') })} />
                  <button type="button" className="btn btn-sm" disabled={busy || (code[String(d.id)] ?? '').length !== 2} onClick={() => { cycle(d, code[String(d.id)]); setCode({ ...code, [String(d.id)]: '' }) }}>Add country</button>
                </div>
              </section>
            </div>
          </Card>
        )
      })}
    </>
  )
}
