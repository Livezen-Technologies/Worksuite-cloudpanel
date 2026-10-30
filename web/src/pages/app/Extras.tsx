import { useState } from 'react'
import { api } from '../../api/client'
import { useAction, useOne } from '../../api/hooks'
import { Card, PageHeader } from '../../components/Card'
import { Badge, type Tone } from '../../components/Badge'
import { useToast } from '../../components/Toasts'
import { ago } from '../../lib/format'
import type { R } from '../../lib/util'

const PRESETS: [string, string][] = [['* * * * *', 'Every minute'], ['*/5 * * * *', 'Every 5 minutes'], ['0 * * * *', 'Hourly'], ['0 3 * * *', 'Daily at 03:00 UTC'], ['0 3 * * 1', 'Weekly (Monday 03:00 UTC)']]

/** UCRON-1..4: scheduled jobs in the customer's apps or calling a URL. */
export function UserCron() {
  const page = useOne<R>('/app/cron', undefined, { refetchInterval: 15000 })
  const { run, busy } = useAction()
  const w = page.item ?? {}
  const apps = (w.apps as R[]) ?? []
  const [f, setF] = useState<R>({ name: '', action: 'app_command', application_id: '', command: '', url: '', cron: '*/5 * * * *', custom: false })
  const [log, setLog] = useState<{ id: string; runs: R[] } | null>(null)
  const showRuns = async (id: string) => setLog({ id, runs: (await api.get<{ data: R[] }>(`/app/cron/${id}/runs`)).data })
  const create = () => run({ path: '/app/cron', body: { name: f.name, action: f.action, cron: f.cron, ...(f.action === 'app_command' ? { application_id: f.application_id || apps[0]?.id, command: f.command } : { url: f.url }) }, label: 'Add scheduled job', success: 'Scheduled job added' })
    .then(() => { setF({ ...f, name: '', command: '', url: '' }); page.refetch() }).catch(() => {})

  return (
    <>
      <PageHeader title="Scheduled jobs" subtitle={`Run a command in one of your apps, or call a URL, on a schedule. ${w.used ?? 0} of ${w.limit ?? '…'} used.`} />
      <Card title="New job">
        <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))] text-sm">
          <label className="flex flex-col gap-1">Name<input className="input" value={String(f.name)} onChange={(e) => setF({ ...f, name: e.target.value })} placeholder="Nightly cleanup" /></label>
          <label className="flex flex-col gap-1">Run
            <select className="input" value={String(f.action)} onChange={(e) => setF({ ...f, action: e.target.value })}><option value="app_command">A command in an app</option><option value="url">Call a URL</option></select>
          </label>
          {f.action === 'app_command' ? <>
            <label className="flex flex-col gap-1">App<select className="input" value={String(f.application_id || apps[0]?.id || '')} onChange={(e) => setF({ ...f, application_id: e.target.value })}>{apps.map((a) => <option key={String(a.id)} value={String(a.id)}>{String(a.name)}</option>)}</select></label>
            <label className="flex flex-col gap-1">Command<input className="input font-mono text-xs" value={String(f.command)} onChange={(e) => setF({ ...f, command: e.target.value })} placeholder="php artisan schedule:run" /></label>
          </> : <label className="flex flex-col gap-1">URL<input className="input font-mono text-xs" value={String(f.url)} onChange={(e) => setF({ ...f, url: e.target.value })} placeholder="https://example.com/cron.php" /></label>}
          <label className="flex flex-col gap-1">When
            {f.custom ? <input className="input font-mono text-xs" value={String(f.cron)} onChange={(e) => setF({ ...f, cron: e.target.value })} placeholder="m h dom mon dow" />
              : <select className="input" value={String(f.cron)} onChange={(e) => e.target.value === 'custom' ? setF({ ...f, custom: true }) : setF({ ...f, cron: e.target.value })}>{PRESETS.map(([c, l]) => <option key={c} value={c}>{l}</option>)}<option value="custom">Custom expression…</option></select>}
          </label>
        </div>
        <p className="m-0 mt-2 text-xs text-muted">Commands run inside the app as its own non-root user, with the app's environment. Times are UTC. The shortest interval on your plan is {String(w.min_interval_minutes ?? 1)} minute(s).</p>
        <button type="button" className="btn btn-primary mt-3" disabled={busy || !f.name || (f.action === 'app_command' ? !f.command || !apps.length : !f.url)} onClick={create}>Add job</button>
      </Card>

      <Card title="Your jobs">
        {((w.jobs as R[]) ?? []).length === 0 ? <p className="m-0 text-sm text-muted">No scheduled jobs yet.</p> : (
          <ul className="m-0 p-0 list-none flex flex-col gap-3 text-sm">
            {(w.jobs as R[]).map((j) => (
              <li key={String(j.id)} className="flex flex-col gap-1 border-b border-[var(--line)] pb-3">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-semibold flex-1">{String(j.name)}</span>
                  {!j.enabled && <Badge>paused</Badge>}
                  {j.last_status ? <Badge tone={j.last_status === 'succeeded' ? 'g' : j.last_status === 'failed' ? 'r' : 'y'}>{String(j.last_result ?? j.last_status)}</Badge> : null}
                </div>
                <span className="text-xs text-muted font-mono">{String(j.cron)} · {j.action === 'url' ? String(j.url) : `${j.app}: ${j.command}`}</span>
                <span className="text-xs text-muted">{j.last_run_at ? `Last run ${ago(String(j.last_run_at))}` : 'Not run yet'}{j.next_run_at ? ` · next ${ago(String(j.next_run_at))}` : ''}</span>
                <span className="flex flex-wrap gap-2">
                  <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/app/cron/${j.id}/run`, label: `Run ${j.name}` }).then(() => setTimeout(() => { page.refetch(); showRuns(String(j.id)) }, 3000))}>Run now</button>
                  <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/app/cron/${j.id}`, method: 'PATCH', body: { enabled: !j.enabled }, label: j.enabled ? 'Pause' : 'Resume' }).then(() => page.refetch())}>{j.enabled ? 'Pause' : 'Resume'}</button>
                  <button type="button" className="btn btn-sm" onClick={() => showRuns(String(j.id))}>Run log</button>
                  <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => { if (window.confirm(`Delete ${j.name}?`)) run({ path: `/app/cron/${j.id}`, method: 'DELETE', label: 'Delete job' }).then(() => page.refetch()) }}>Delete</button>
                </span>
                {log && log.id === j.id && <ul className="m-0 p-0 list-none flex flex-col gap-1">
                  {log.runs.length === 0 ? <li className="text-xs text-muted">No runs yet.</li> : log.runs.map((r) => <li key={String(r.id)} className="text-xs">
                    <Badge tone={r.status === 'succeeded' ? 'g' : r.status === 'failed' ? 'r' : 'y'}>{String(r.status)}</Badge> {ago(String(r.started_at))} · exit {String(r.exit_code ?? '–')} · {r.duration_ms != null ? `${(Number(r.duration_ms) / 1000).toFixed(1)} s` : ''} {r.source === 'manual' ? '· run now' : ''}
                    {r.output ? <pre className="m-0 mt-1 p-2 rounded bg-[var(--code-bg)] text-[var(--code-fg)] whitespace-pre-wrap max-h-40 overflow-auto">{String(r.output)}</pre> : null}
                  </li>)}
                </ul>}
              </li>
            ))}
          </ul>
        )}
      </Card>
    </>
  )
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
  const refetch = () => page.refetch()
  if (page.item && !w.available) return <><PageHeader title="Email" /><Card><p className="m-0 text-sm">Email hosting is not available yet. Contact support to turn it on.</p></Card></>
  const s = w.settings as R | null
  return (
    <>
      <PageHeader title="Email" subtitle={`Mailboxes on your own domains. ${w.used ?? 0} of ${w.limit ?? '…'} mailboxes used.`} />
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
                    <Badge tone={r.status === 'set' ? 'g' : r.status === 'not checked' ? 'n' : 'r'}>{String(r.status)}</Badge> {String(r.purpose)}
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
