import { useEffect, useState } from 'react'
import { api } from '../../api/client'
import { useAction, useList, useOne } from '../../api/hooks'
import { Card, PageHeader } from '../../components/Card'
import { Badge, type Tone } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { Drawer } from '../../components/Overlay'
import { useToast } from '../../components/Toasts'
import { ago } from '../../lib/format'
import type { R } from '../../lib/util'
import Select from '../../components/Select'

const tone = (s: string): Tone => (['migrated', 'ready', 'live'].includes(s) ? 'g' : s === 'failed' ? 'r' : ['fetching', 'migrating', 'deploying', 'copying', 'requested'].includes(s) ? 'y' : 'n')
const mb = (b: unknown) => `${(Number(b ?? 0) / 1048576).toFixed(1)} MB`

/** Migration wizard (SRS MIG): cPanel accounts into apps on this platform. */
export default function Migrations() {
  const list = useList<R>('/migrations', undefined, { refetchInterval: 10000 })
  const servers = useList<R>('/servers')
  const { run, busy } = useAction()
  const [open, setOpen] = useState<string | null>(null)
  const [form, setForm] = useState({ server_id: '', archive_url: '', sha256: '' })

  const create = () => run<{ data: R }>({ path: '/migrations', body: { ...form, sha256: form.sha256 || null }, label: 'Start migration', success: 'Fetching the backup' })
    .then((r) => { setOpen(String(r.data.id)); setForm({ ...form, archive_url: '', sha256: '' }); list.refetch() })

  return (
    <>
      <PageHeader title="Migrations" subtitle="Move cPanel accounts onto the platform: each site becomes an app, checked on a temporary address first." />

      <Card title="New migration">
        <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))] text-sm">
          <label className="flex flex-col gap-1">Target server
            <Select className="input" value={form.server_id} onChange={(e) => setForm({ ...form, server_id: e.target.value })}>
              <option value="">Choose…</option>
              {servers.rows.map((s) => <option key={String(s.id)} value={String(s.id)}>{String(s.name)}</option>)}
            </Select>
          </label>
          <label className="flex flex-col gap-1">cPanel full backup URL
            <input className="input font-mono text-xs" placeholder="https://…/cpmove-user.tar.gz" value={form.archive_url} onChange={(e) => setForm({ ...form, archive_url: e.target.value })} />
          </label>
          <label className="flex flex-col gap-1">SHA-256 (optional)
            <input className="input font-mono text-xs" value={form.sha256} onChange={(e) => setForm({ ...form, sha256: e.target.value })} />
          </label>
        </div>
        <p className="m-0 mt-2 text-xs text-muted">In cPanel: Backup → Download a Full Account Backup. Put the file where the server can download it, such as a sharing link or object storage.</p>
        <button type="button" className="btn btn-primary mt-3" disabled={busy || !form.server_id || !form.archive_url} onClick={create}>Fetch and inspect</button>
      </Card>

      <Card title="Migrations" pad={false}>
        <DataTable label="Migrations" rows={list.rows} loading={list.isLoading} rowKey={(r) => String(r.id)} onRowClick={(r) => setOpen(String(r.id))}
          columns={[
            { key: 'account', header: 'Account', render: (r) => <span className="font-semibold">{String(r.account ?? '…')}</span> },
            { key: 'source', header: 'From', render: (r) => (r.requested ? 'cPanel (customer request)' : 'cPanel') },
            { key: 'server', header: 'To server' },
            { key: 'sites', header: 'Sites', render: (r) => `${r.migrated}/${r.sites}` },
            { key: 'status', header: 'Status', render: (r) => <Badge tone={tone(String(r.status))}>{String(r.status)}</Badge> },
            { key: 'created_at', header: 'Started', render: (r) => ago(String(r.created_at)) },
          ]} />
      </Card>

      {open && <MigrationDrawer id={open} onClose={() => { setOpen(null); list.refetch() }} />}
    </>
  )
}

function MigrationDrawer({ id, onClose }: { id: string; onClose: () => void }) {
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

  return (
    <Drawer open onClose={onClose} title={`Migration ${inv.user ? `of ${inv.user}` : ''}`} width={760}>
      <div className="flex flex-col gap-5 text-sm">
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={tone(String(w.status))}>{String(w.status ?? '…')}</Badge>
          {inv.files != null && <span className="text-muted">{Number(inv.files).toLocaleString()} files · {mb(inv.bytes)} · databases: {((inv.databases as string[]) ?? []).join(', ') || 'none'}</span>}
        </div>
        {w.error && <p role="alert" className="m-0 text-danger">{String(w.error)}</p>}
        {w.notes ? <p className="m-0"><span className="text-muted">Customer notes:</span> {String(w.notes)}</p> : null}
        {w.decision ? <p className="m-0"><span className="text-muted">Decision:</span> {String(w.decision)}</p> : null}
        {w.status === 'requested' && <section className="flex flex-wrap items-end gap-2">
          <label className="flex flex-col gap-1">Approve onto
            <Select className="input" value={approveTo} onChange={(e) => setApproveTo(e.target.value)}>
              <option value="">Choose a server…</option>
              {servers.rows.map((s) => <option key={String(s.id)} value={String(s.id)}>{String(s.name)}</option>)}
            </Select>
          </label>
          <button type="button" className="btn btn-primary" disabled={busy || !approveTo} onClick={() => run({ path: `/migrations/${id}/approve`, body: { server_id: approveTo }, label: 'Approve migration' }).then(() => m.refetch())}>Approve</button>
          <button type="button" className="btn btn-danger" disabled={busy} onClick={() => { const reason = window.prompt('Reason, shown to the customer:'); if (reason !== null) run({ path: `/migrations/${id}/reject`, body: { reason }, label: 'Reject migration' }).then(() => m.refetch()) }}>Reject</button>
        </section>}

        {sites.length > 0 && <section>
          <h3 className="m-0 mb-2 text-sm font-semibold">Sites</h3>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead><tr className="text-left text-muted"><th className="p-1">Move</th><th className="p-1">Domain</th><th className="p-1">Folder</th><th className="p-1">App name</th><th className="p-1">Database</th><th className="p-1">Import into</th><th className="p-1">Status</th></tr></thead>
              <tbody>
                {sites.map((s, i) => (
                  <tr key={String(s.domain)} className="border-t border-[var(--line)]">
                    <td className="p-1"><input type="checkbox" aria-label={`Move ${s.domain}`} disabled={!ready} checked={!!s.enabled} onChange={(e) => edit(i, { enabled: e.target.checked })} /></td>
                    <td className="p-1"><span className="font-semibold">{String(s.domain)}</span>{s.wordpress ? <> <Badge tone="b">WordPress</Badge></> : null}<br /><span className="text-muted">{String(s.kind)}</span></td>
                    <td className="p-1"><input className="input font-mono text-xs w-28" disabled={!ready} value={String(s.docroot ?? '')} onChange={(e) => edit(i, { docroot: e.target.value })} /></td>
                    <td className="p-1"><input className="input font-mono text-xs w-28" disabled={!ready} value={String(s.app_name ?? '')} onChange={(e) => edit(i, { app_name: e.target.value })} /></td>
                    <td className="p-1"><Select className="input text-xs" disabled={!ready} value={String(s.database ?? '')} onChange={(e) => edit(i, { database: e.target.value || null })}>
                      <option value="">None</option>{((inv.databases as string[]) ?? []).map((d) => <option key={d}>{d}</option>)}
                    </Select></td>
                    <td className="p-1"><Select className="input text-xs" disabled={!ready || !s.database} value={String(s.target_database_id ?? '')} onChange={(e) => edit(i, { target_database_id: e.target.value || null })}>
                      <option value="">Choose…</option>{targets.map((d) => <option key={String(d.id)} value={String(d.id)}>{String(d.name)} ({String(d.engine)})</option>)}
                    </Select></td>
                    <td className="p-1">
                      <Badge tone={tone(String(s.status))}>{String(s.status)}</Badge>
                      {s.temp_url ? <><br /><a href={String(s.temp_url)} target="_blank" rel="noopener noreferrer" className="text-[var(--accent)]">temporary address</a></> : null}
                      {s.error ? <><br /><span className="text-danger">{String(s.error)}</span></> : null}
                      {s.status === 'migrated' ? <><br /><button type="button" className="btn btn-sm btn-primary mt-1" disabled={busy} onClick={() => { if (window.confirm(`Point ${s.domain} at the new app? In a DNS zone managed here, the old records are saved and replaced.`)) run({ path: `/migrations/${id}/sites/${i}/cutover`, label: `Cut over ${s.domain}` }).then(() => m.refetch()) }}>Cut over DNS</button></> : null}
                      {s.status === 'live' ? <><br /><button type="button" className="btn btn-sm mt-1" disabled={busy} onClick={() => { if (window.confirm(`Roll ${s.domain} back to the old server?`)) run({ path: `/migrations/${id}/sites/${i}/rollback`, label: `Roll back ${s.domain}` }).then(() => m.refetch()) }}>Roll back</button></> : null}
                      {((s.dns_records as R[]) ?? []).length > 0 ? <><br /><span className="text-muted">Set at your DNS host: {(s.dns_records as R[]).map((r) => `${r.type} ${r.name} → ${r.value}`).join('; ')}</span></> : null}
                      {s.app_id && ['failed', 'migrated'].includes(String(s.status)) ? <><br /><button type="button" className="btn btn-sm mt-1" disabled={busy} onClick={() => run({ path: `/migrations/${id}/sites/${i}/retry`, label: `Copy ${s.domain} again` }).then(() => m.refetch())}>Copy again</button></> : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {ready && <div className="flex gap-2 mt-2">
            <button type="button" className="btn btn-sm" onClick={save}>Save and check</button>
            <button type="button" className="btn btn-sm btn-primary" disabled={busy || checks.some((c) => !c.ok)} onClick={() => run({ path: `/migrations/${id}/start`, label: 'Migrate', success: 'Deploying the apps' }).then(() => m.refetch())}>Migrate</button>
          </div>}
          <p className="m-0 mt-1 text-xs text-muted">Each site gets a new app on the target server and a temporary platform address. Your DNS is not changed.</p>
        </section>}

        {checks.length > 0 && <section>
          <h3 className="m-0 mb-2 text-sm font-semibold">Pre-flight checks</h3>
          <ul className="m-0 p-0 list-none flex flex-col gap-1">
            {checks.map((c) => <li key={String(c.domain)}><Badge tone={c.ok ? 'g' : 'r'}>{c.ok ? 'ok' : 'blocked'}</Badge> {String(c.domain)}{(c.issues as string[]).length ? `: ${(c.issues as string[]).join(' ')}` : ''}</li>)}
          </ul>
        </section>}

        {['migrated', 'migrating'].includes(String(w.status)) && <section>
          <h3 className="m-0 mb-2 text-sm font-semibold">Delta sync</h3>
          <div className="flex flex-wrap gap-2">
            <input className="input font-mono text-xs flex-1 min-w-[240px]" placeholder="URL of a newer backup of the same account" value={resyncUrl} onChange={(e) => setResyncUrl(e.target.value)} />
            <button type="button" className="btn btn-sm" disabled={busy || !resyncUrl} onClick={() => { if (window.confirm('Copy the newer backup over every migrated site? Changes made on the new apps since the copy are overwritten.')) run({ path: `/migrations/${id}/resync`, body: { archive_url: resyncUrl }, label: 'Delta sync' }).then(() => { setResyncUrl(''); m.refetch() }) }}>Sync again</button>
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
    </Drawer>
  )
}
