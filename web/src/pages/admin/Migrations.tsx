import { useEffect, useState } from 'react'
import { api } from '../../api/client'
import { useAction, useList, useOne } from '../../api/hooks'
import { Card, Empty, PageHeader } from '../../components/Card'
import { Badge, type Tone } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { useToast } from '../../components/Toasts'
import { ago } from '../../lib/format'
import type { R } from '../../lib/util'
import Select from '../../components/Select'

const tone = (s: string): Tone => (['migrated', 'ready', 'live'].includes(s) ? 'g' : s === 'failed' ? 'r' : ['fetching', 'migrating', 'deploying', 'copying', 'requested'].includes(s) ? 'y' : 'n')
const STEPS = ['Connect', 'Choose sites', 'Pre-flight', 'Copy', 'Test', 'Cut over']
/** Which wizard step a migration is on, from its real status. */
const stepOf = (status: string, sites: R[], checks: R[]): number => {
  if (sites.some((s) => s.status === 'live')) return sites.filter((s) => s.enabled).every((s) => s.status === 'live') ? 6 : 5
  if (status === 'migrated') return 4
  if (['migrating', 'copying', 'deploying'].includes(status)) return 3
  if (status === 'ready') return checks.length > 0 ? 2 : 1
  return 0
}
const mb = (b: unknown) => `${(Number(b ?? 0) / 1048576).toFixed(1)} MB`

/** Migration wizard (SRS MIG): cPanel, Plesk and HestiaCP accounts into apps on this platform. */
const SOURCE: Record<string, string> = { cpanel: 'cPanel', plesk: 'Plesk', hestia: 'HestiaCP' }
const sourceName = (s: unknown) => SOURCE[String(s)] ?? 'cPanel'
export default function Migrations() {
  const list = useList<R>('/migrations', undefined, { refetchInterval: 10000 })
  const servers = useList<R>('/servers')
  const { run, busy } = useAction()
  const [open, setOpen] = useState<string | null>(null)
  const [showNew, setShowNew] = useState(false)
  const [closed, setClosed] = useState(false)
  // The newest migration is shown by default; a row click picks another.
  const sel = open ?? (closed ? null : list.rows[0] ? String(list.rows[0].id) : null)
  const [form, setForm] = useState({ server_id: '', archive_url: '', sha256: '' })

  const create = () => run<{ data: R }>({ path: '/migrations', body: { ...form, sha256: form.sha256 || null }, label: 'Start migration', success: 'Fetching the backup' })
    .then((r) => { setOpen(String(r.data.id)); setForm({ ...form, archive_url: '', sha256: '' }); list.refetch() })

  return (
    <>
      <PageHeader title="Migrations" subtitle="Move cPanel, Plesk or HestiaCP accounts onto the platform: each site becomes an app, checked on a temporary address first."
        actions={<button type="button" className="btn" aria-expanded={showNew || list.rows.length === 0} onClick={() => setShowNew(!showNew)}>New migration</button>} />

      {(showNew || (!list.isLoading && list.rows.length === 0)) && <Card title="New migration" subtitle="Step 1 · Connect a full account backup">
        <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] text-sm">
          <label className="flex flex-col gap-1">Target server
            <Select className="input" value={form.server_id} onChange={(e) => setForm({ ...form, server_id: e.target.value })}>
              <option value="">Choose…</option>
              {servers.rows.map((s) => <option key={String(s.id)} value={String(s.id)}>{String(s.name)}</option>)}
            </Select>
          </label>
          <label className="flex flex-col gap-1">Backup URL (cPanel, Plesk or HestiaCP)
            <input className="input font-mono text-xs" placeholder="https://…/cpmove-user.tar.gz" value={form.archive_url} onChange={(e) => setForm({ ...form, archive_url: e.target.value })} />
          </label>
          <label className="flex flex-col gap-1">SHA-256 (optional)
            <input className="input font-mono text-xs" value={form.sha256} onChange={(e) => setForm({ ...form, sha256: e.target.value })} />
          </label>
        </div>
        <p className="m-0 mt-2 text-xs text-muted">cPanel: Backup → Download a Full Account Backup. Plesk: Backup Manager → Back Up (full, not encrypted, one file) → download. HestiaCP: Backups → download the user backup. The format is recognised from the file. Put the file where the server can download it, such as a sharing link or object storage.</p>
        <button type="button" className="btn btn-primary mt-3" disabled={busy || !form.server_id || !form.archive_url} onClick={create}>Fetch and inspect</button>
      </Card>}

      {sel && <MigrationWizard key={sel} id={sel} onClose={() => { setOpen(null); setClosed(true); list.refetch() }} />}

      <Card title="Migrations" pad={false}>
        <DataTable label="Migrations" rows={list.rows} loading={list.isLoading} rowKey={(r) => String(r.id)} onRowClick={(r) => { setOpen(String(r.id)); window.scrollTo({ top: 0, behavior: 'smooth' }) }}
          columns={[
            { key: 'account', header: 'Account', render: (r) => <span className="font-semibold">{String(r.account ?? '…')}</span> },
            { key: 'source', header: 'From', render: (r) => (r.requested ? `${sourceName(r.source)} (customer request)` : sourceName(r.source)) },
            { key: 'server', header: 'To server' },
            { key: 'sites', header: 'Sites', render: (r) => `${r.migrated}/${r.sites}` },
            { key: 'status', header: 'Status', render: (r) => <Badge tone={tone(String(r.status))}>{String(r.status)}</Badge> },
            { key: 'created_at', header: 'Started', render: (r) => ago(String(r.created_at)) },
          ]} />
      </Card>

    </>
  )
}

function MigrationWizard({ id, onClose }: { id: string; onClose: () => void }) {
  const m = useOne<R>(`/migrations/${id}`, undefined, { refetchInterval: 5000 })
  const w = m.item ?? {}
  const dbs = useList<R>('/databases')
  const servers = useList<R>('/servers')
  const [approveTo, setApproveTo] = useState('')
  const [resyncUrl, setResyncUrl] = useState('')
  const { run, busy } = useAction()
  const toast = useToast()
  const [sites, setSites] = useState<R[]>([])
  useEffect(() => { if (w.sites) setSites(w.sites as R[]) }, [JSON.stringify(w.sites)]) // eslint-disable-line react-hooks/exhaustive-deps
  const inv = (w.inventory as R) ?? {}
  const ready = w.status === 'ready'
  const targets = dbs.rows.filter((d) => d.server_id === w.server_id && ['mysql', 'mariadb'].includes(String(d.engine)))
  const edit = (i: number, patch: R) => setSites(sites.map((s, j) => (j === i ? { ...s, ...patch } : s)))
  const save = async () => {
    try {
      await api.patch(`/migrations/${id}/sites`, { sites: sites.map((s) => ({ domain: s.domain, enabled: s.enabled, app_name: s.app_name, docroot: s.docroot, database: s.database, target_database_id: s.target_database_id })) })
      await m.refetch()
    } catch (e) { toast.error(e, 'Saving the mapping failed') }
  }
  const checks = ((w.preflight as R[]) ?? [])

  const step = stepOf(String(w.status ?? ''), sites, checks)
  const check = new Map(checks.map((c) => [String(c.domain), c]))
  const source = inv.user ? `${sourceName(inv.source ?? w.source)} · ${String(inv.user)}` : sourceName(inv.source ?? w.source)
  const td = 'px-3 py-2 align-top'

  return (
    <div className="flex flex-col gap-4">
      <ol aria-label="Migration steps" className="m-0 p-0 list-none flex flex-wrap gap-2">
        {STEPS.map((label, i) => {
          const done = i < step, cur = i === step
          return <li key={label} aria-current={cur ? 'step' : undefined}
            className={`flex items-center gap-2 rounded-full border px-3 py-1.5 text-[13px] font-medium ${cur ? 'border-[var(--accent)] bg-sel text-[var(--accent)]' : 'border-[var(--line)] bg-card'} ${!done && !cur ? 'text-muted' : ''}`}>
            <span className={`inline-flex h-5 w-5 items-center justify-center rounded-full text-[11px] font-bold ${done ? 'bg-[var(--g-bg)] text-[var(--g-fg)]' : cur ? 'bg-[var(--accent)] text-[var(--accent-fg)]' : 'bg-track'}`}>{done ? '✓' : i + 1}</span>
            {label}
          </li>
        })}
      </ol>

      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px] items-start">
        <Card pad={false} title={`Accounts on ${source}`}
          subtitle={inv.files != null ? `${Number(inv.files).toLocaleString()} files · ${mb(inv.bytes)} · databases: ${((inv.databases as string[]) ?? []).join(', ') || 'none'}` : undefined}
          actions={<Badge tone={tone(String(w.status))}>{String(w.status ?? '…')}</Badge>}>
          {w.error && <p role="alert" className="m-0 px-5 pb-3 text-sm text-danger">{String(w.error)}</p>}
          {w.status === 'requested' && <div className="px-5 pb-4 flex flex-wrap items-end gap-2 text-sm">
            <label className="flex flex-col gap-1">Approve onto
              <Select className="input" value={approveTo} onChange={(e) => setApproveTo(e.target.value)}>
                <option value="">Choose a server…</option>
                {servers.rows.map((s) => <option key={String(s.id)} value={String(s.id)}>{String(s.name)}</option>)}
              </Select>
            </label>
            <button type="button" className="btn btn-primary" disabled={busy || !approveTo} onClick={() => run({ path: `/migrations/${id}/approve`, body: { server_id: approveTo }, label: 'Approve migration' }).then(() => m.refetch())}>Approve</button>
            <button type="button" className="btn btn-danger" disabled={busy} onClick={() => { const reason = window.prompt('Reason, shown to the customer:'); if (reason !== null) run({ path: `/migrations/${id}/reject`, body: { reason }, label: 'Reject migration' }).then(() => m.refetch()) }}>Reject</button>
          </div>}
          {sites.length === 0
            ? <Empty>{m.isLoading ? 'Loading…' : 'No sites found yet. The backup is still being fetched and inspected.'}</Empty>
            : <div className="overflow-x-auto">
              <table className="tbl min-w-[860px] text-[13px]">
                <thead><tr className="text-left text-xs text-muted border-b border-[var(--line)]">
                  <th className={td}>Move</th><th className={td}>Domain</th><th className={td}>Detected</th><th className={td}>Target app</th><th className={td}>Database</th><th className={td}>Pre-flight</th><th className={td}>Status</th>
                </tr></thead>
                <tbody>
                  {sites.map((s, i) => {
                    const c = check.get(String(s.domain))
                    return <tr key={String(s.domain)} className="border-t border-[var(--line)]">
                      <td className={td}><input type="checkbox" aria-label={`Move ${s.domain}`} disabled={!ready} checked={!!s.enabled} onChange={(e) => edit(i, { enabled: e.target.checked })} /></td>
                      <td className={td}><span className="font-semibold">{String(s.domain)}</span>
                        <div className="mt-1"><input aria-label={`Folder for ${s.domain}`} className="input font-mono text-xs w-32" disabled={!ready} value={String(s.docroot ?? '')} onChange={(e) => edit(i, { docroot: e.target.value })} /></div></td>
                      <td className={td}>{s.wordpress ? <Badge tone="b">WordPress</Badge> : null} <span className="text-muted">{String(s.kind ?? '')}</span></td>
                      <td className={td}><input aria-label={`App name for ${s.domain}`} className="input font-mono text-xs w-32" disabled={!ready} value={String(s.app_name ?? '')} onChange={(e) => edit(i, { app_name: e.target.value })} /></td>
                      <td className={td}><div className="flex flex-col gap-1">
                        <Select className="input text-xs" disabled={!ready} value={String(s.database ?? '')} onChange={(e) => edit(i, { database: e.target.value || null })}>
                          <option value="">None</option>{((inv.databases as string[]) ?? []).map((d) => <option key={d}>{d}</option>)}
                        </Select>
                        <Select className="input text-xs" disabled={!ready || !s.database} value={String(s.target_database_id ?? '')} onChange={(e) => edit(i, { target_database_id: e.target.value || null })}>
                          <option value="">Import into…</option>{targets.map((d) => <option key={String(d.id)} value={String(d.id)}>{String(d.name)} ({String(d.engine)})</option>)}
                        </Select>
                      </div></td>
                      <td className={td}>{c ? <><Badge tone={c.ok ? 'g' : 'r'}>{c.ok ? 'ok' : 'blocked'}</Badge>{(c.issues as string[]).length ? <div className="mt-1 text-xs text-muted max-w-[200px]">{(c.issues as string[]).join(' ')}</div> : null}</> : <span className="text-muted">—</span>}</td>
                      <td className={td}>
                        <Badge tone={tone(String(s.status))}>{String(s.status)}</Badge>
                        {s.temp_url ? <><br /><a href={String(s.temp_url)} target="_blank" rel="noopener noreferrer" className="text-[var(--accent)]">temporary address</a></> : null}
                        {s.error ? <><br /><span className="text-danger">{String(s.error)}</span></> : null}
                        {s.status === 'migrated' ? <><br /><button type="button" className="btn btn-sm btn-primary mt-1" disabled={busy} onClick={() => { if (window.confirm(`Point ${s.domain} at the new app? In a DNS zone managed here, the old records are saved and replaced.`)) run({ path: `/migrations/${id}/sites/${i}/cutover`, label: `Cut over ${s.domain}` }).then(() => m.refetch()) }}>Cut over DNS</button></> : null}
                        {s.status === 'live' ? <><br /><button type="button" className="btn btn-sm mt-1" disabled={busy} onClick={() => { if (window.confirm(`Roll ${s.domain} back to the old server?`)) run({ path: `/migrations/${id}/sites/${i}/rollback`, label: `Roll back ${s.domain}` }).then(() => m.refetch()) }}>Roll back</button></> : null}
                        {((s.dns_records as R[]) ?? []).length > 0 ? <><br /><span className="text-muted">Set at your DNS host: {(s.dns_records as R[]).map((r) => `${r.type} ${r.name} → ${r.value}`).join('; ')}</span></> : null}
                        {s.app_id && ['failed', 'migrated'].includes(String(s.status)) ? <><br /><button type="button" className="btn btn-sm mt-1" disabled={busy} onClick={() => run({ path: `/migrations/${id}/sites/${i}/retry`, label: `Copy ${s.domain} again` }).then(() => m.refetch())}>Copy again</button></> : null}
                      </td>
                    </tr>
                  })}
                </tbody>
              </table>
            </div>}
          <div className="flex flex-wrap items-center gap-2 px-5 py-3 border-t border-[var(--line)]">
            <span className="flex-1 min-w-[200px] text-xs text-muted">Each site gets a new app on the target server and a temporary platform address. Your DNS is not changed.</span>
            <button type="button" className="btn btn-sm" onClick={onClose}>Back</button>
            {ready && <button type="button" className="btn btn-sm" onClick={save}>Save and check</button>}
            {ready && <button type="button" className="btn btn-sm btn-primary" disabled={busy || checks.some((c) => !c.ok)} onClick={() => run({ path: `/migrations/${id}/start`, label: 'Migrate', success: 'Deploying the apps' }).then(() => m.refetch())}>Continue · Migrate</button>}
          </div>
        </Card>

        <Card title="Before cutover" className="text-sm">
          <div className="flex flex-col gap-4">
            {w.notes ? <p className="m-0"><span className="text-muted">Customer notes:</span> {String(w.notes)}</p> : null}
            {w.decision ? <p className="m-0"><span className="text-muted">Decision:</span> {String(w.decision)}</p> : null}
            <p className="m-0 text-muted">Check every site on its temporary address before cutting DNS over. A cut-over site can be rolled back.</p>
            {['migrated', 'migrating'].includes(String(w.status)) && <section>
              <h3 className="m-0 mb-2 text-sm font-semibold">Delta sync</h3>
              <div className="flex flex-col gap-2">
                <input className="input font-mono text-xs" placeholder="URL of a newer backup of the same account" value={resyncUrl} onChange={(e) => setResyncUrl(e.target.value)} />
                <button type="button" className="btn btn-sm self-start" disabled={busy || !resyncUrl} onClick={() => { if (window.confirm('Copy the newer backup over every migrated site? Changes made on the new apps since the copy are overwritten.')) run({ path: `/migrations/${id}/resync`, body: { archive_url: resyncUrl }, label: 'Delta sync' }).then(() => { setResyncUrl(''); m.refetch() }) }}>Sync again</button>
              </div>
              <p className="m-0 mt-1 text-xs text-muted">Take the newer backup after the old site stops changing (maintenance mode), then cut over. Files of the same size are skipped; databases are re-imported.</p>
            </section>}
            <section>
              <h3 className="m-0 mb-2 text-sm font-semibold">Timeline</h3>
              <ol className="m-0 pl-4 flex flex-col gap-1">
                {((w.timeline as R[]) ?? []).map((t, i) => <li key={i}><span className="text-muted text-xs">{ago(String(t.at))}</span> {String(t.event)}</li>)}
              </ol>
            </section>
          </div>
        </Card>
      </div>
    </div>
  )
}
