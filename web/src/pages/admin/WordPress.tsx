import { useState } from 'react'
import { api, errorMessage } from '../../api/client'
import { useAction, useOne } from '../../api/hooks'
import { Card, PageHeader, StatTile } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { Drawer } from '../../components/Overlay'
import { useToast } from '../../components/Toasts'
import { ago } from '../../lib/format'
import type { R } from '../../lib/util'

const SETTINGS: [string, string, string][] = [
  ['maintenance', 'Maintenance mode', 'Visitors see a maintenance page; logged-in admins see the site.'],
  ['debug', 'Debug mode', 'Shows PHP errors. Never leave on for a live site.'],
  ['indexing', 'Search engine indexing', 'Off asks search engines not to index the site.'],
  ['auto_minor', 'Automatic minor updates', 'Security and maintenance releases install themselves.'],
  ['platform_cron', 'Platform-run WP-Cron', 'Scheduled tasks run every 5 minutes instead of on page loads.'],
  ['redis', 'Redis object cache', 'Needs the platform Redis server.'],
]
const HARDENING: [string, string, string][] = [
  ['xmlrpc', 'Disable XML-RPC', 'Blocks xmlrpc.php, a common brute-force and pingback target.'],
  ['file_edit', 'Disable the file editor', 'Admins can no longer edit theme and plugin code from the dashboard.'],
  ['user_enum', 'Block user enumeration', 'Hides user names from ?author= and the public REST API.'],
  ['security_headers', 'Security headers', 'nosniff, SAMEORIGIN framing, a strict referrer policy and permissions policy.'],
  ['limit_logins', 'Limit login attempts', 'Five failed logins from one address lock it out for 15 minutes.'],
]
const scoreTone = (n: number) => (n >= 80 ? 'g' : n >= 50 ? 'y' : 'r') as 'g' | 'y' | 'r'

/** Waits for a job and returns it, so results such as a dry run's report can be shown. */
async function waitJob(id: string): Promise<R> {
  for (let i = 0; i < 120; i++) {
    const job = (await api.get<{ data: R }>(`/jobs/${id}`)).data
    if (['succeeded', 'failed', 'cancelled'].includes(String(job.status))) return job
    await new Promise((r) => setTimeout(r, 1500))
  }
  throw new Error('The server did not answer in time.')
}

/** WordPress toolkit (SRS WP): every WordPress site, its settings, tools, plugins and security. */
export default function WordPress() {
  const list = useOne<R>('/wordpress')
  const { run, busy } = useAction()
  const [open, setOpen] = useState<string | null>(null)
  const s = (list.item?.summary as R) ?? {}

  return (
    <>
      <PageHeader title="WordPress" subtitle="Every WordPress site on the platform, managed through WP-CLI on its server."
        actions={<div className="flex gap-2">
        <button type="button" className="btn" disabled={busy || !Number(s.updates)} onClick={() => { if (window.confirm('Update plugins, themes and core on every site with updates? Each site gets a restore point first and is rolled back if it stops working.')) run({ path: '/wordpress/update-all', body: { scope: 'all' }, label: 'Update all sites', success: 'Updates queued' }).then(() => setTimeout(() => list.refetch(), 4000)) }}>Update all sites</button>
        <button type="button" className="btn btn-primary" disabled={busy} onClick={() => run({ path: '/wordpress/detect', label: 'Find WordPress sites', success: 'Refreshing every WordPress site' }).then(() => setTimeout(() => list.refetch(), 4000))}>Refresh all</button></div>} />

      <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(160px,1fr))]">
        <StatTile label="Sites" value={String(s.sites ?? 0)} />
        <StatTile label="Updates waiting" value={String(s.updates ?? 0)} valueTone={Number(s.updates) ? 'y' : 'g'} />
        <StatTile label="Security fixes" value={String(s.security ?? 0)} valueTone={Number(s.security) ? 'r' : 'g'} />
        <StatTile label="PHP below 8.1" value={String(s.outdated_php ?? 0)} valueTone={Number(s.outdated_php) ? 'y' : 'g'} />
        <StatTile label="Staging copies" value={String(s.staging ?? 0)} />
      </div>

      <Card title="Sites" pad={false}>
        <DataTable label="WordPress sites" rows={(list.item?.sites as R[]) ?? []} loading={list.isLoading} rowKey={(r) => r.id} onRowClick={(r) => setOpen(String(r.id))}
          columns={[
            { key: 'url', header: 'Site', render: (r) => <span className="flex flex-col"><span className="font-semibold">{String(r.url ?? r.app)}</span><span className="text-xs text-muted">{String(r.app)} · {String(r.server ?? '')}</span></span> },
            { key: 'version', header: 'WordPress', render: (r) => <span>{String(r.version ?? '—')}{r.core_update ? <Badge tone="y">→ {String(r.core_update)}</Badge> : null}</span> },
            { key: 'php_version', header: 'PHP', render: (r) => r.php_version ? <Badge tone={String(r.php_version) < '8.1' ? 'y' : 'n'}>{String(r.php_version)}</Badge> : '—' },
            { key: 'updates', header: 'Updates', value: (r) => Number(r.updates) },
            { key: 'score', header: 'Security', render: (r) => r.score == null ? '—' : <Badge tone={scoreTone(Number(r.score))}>{String(r.score)}{Number(r.security) ? ` · ${r.security} known issue${Number(r.security) === 1 ? '' : 's'}` : ''}</Badge> },
            { key: 'inventoried_at', header: 'Checked', render: (r) => r.error ? <Badge tone="r">failed</Badge> : r.inventoried_at ? ago(String(r.inventoried_at)) : '—' },
          ]} />
      </Card>

      {open && <SiteDrawer id={open} onClose={() => { setOpen(null); list.refetch() }} />}
    </>
  )
}

function SiteDrawer({ id, onClose }: { id: string; onClose: () => void }) {
  const site = useOne<R>(`/wordpress/${id}`)
  const { run, busy } = useAction()
  const toast = useToast()
  const [sr, setSr] = useState({ search: '', replace: '' })
  const [report, setReport] = useState<string | null>(null)
  const [secret, setSecret] = useState<string | null>(null)
  const points = useOne<R[]>(`/wordpress/${id}/restore-points`)
  const w = site.item ?? {}
  const refetch = () => { site.refetch(); points.refetch() }
  const after = (r: unknown) => { const jid = (r as { data?: { job_id?: string } })?.data?.job_id; if (jid) waitJob(jid).then(refetch).catch(() => {}) }

  const toggle = (key: string, on: boolean) => run({ path: `/wordpress/${id}/settings`, method: 'PATCH', body: { setting: key, on }, label: SETTINGS.find(([k]) => k === key)?.[1] ?? key }).then(after)
  const tool = (t: string, body: R, label: string) => run({ path: `/wordpress/${id}/tools/${t}`, body, label }).then(after)
  const searchReplace = async (dry: boolean) => {
    try {
      const r = await api.post<{ data: { job_id: string } }>(`/wordpress/${id}/tools/search_replace`, { ...sr, dry_run: dry })
      const job = await waitJob(r.data.job_id)
      setReport(job.status === 'succeeded' ? String((job.result as R)?.output ?? 'No changes.') : String(job.error ?? 'Failed.'))
    } catch (e) { toast.error(e, 'Search and replace failed') }
  }
  const update = (scope: string, items: string[] = []) => run({ path: `/wordpress/${id}/update`, body: { scope, items }, label: 'Update', success: 'Updating with a restore point first' }).then(after)
  const harden = (key: string, on: boolean) => run({ path: `/wordpress/${id}/hardening`, method: 'PATCH', body: { [key]: on }, label: HARDENING.find(([k]) => k === key)?.[1] ?? key }).then(after)
  const loginLink = async () => {
    try {
      const r = await api.post<{ data: { job_id: string; url: string } }>(`/wordpress/${id}/login-link`)
      const job = await waitJob(r.data.job_id)
      if (job.status !== 'succeeded') throw new Error(String(job.error ?? 'The link could not be prepared.'))
      window.open(r.data.url, '_blank', 'noopener')
    } catch (e) { toast.error(e, 'Login link failed') }
  }
  const resetPassword = async () => {
    const user = window.prompt('Reset the password of which WordPress user (login name)?', 'admin')
    if (!user) return
    try {
      const r = await api.post<{ data: { password: string } }>(`/wordpress/${id}/reset-password`, { user })
      setSecret(`New password for ${user}: ${r.data.password}`)
    } catch (e) { toast.error(e, 'Password reset failed') }
  }

  return (
    <Drawer open onClose={onClose} title={String(w.url ?? w.app ?? 'WordPress site')} width={640}>
      {site.isLoading ? <p className="text-sm text-muted">Loading…</p> : (
        <div className="flex flex-col gap-5 text-sm">
          {w.error && <p role="alert" className="m-0 text-danger">Last refresh failed: {String(w.error)}</p>}
          <div className="flex flex-wrap gap-2">
            <button type="button" className="btn btn-sm btn-primary" disabled={busy} onClick={loginLink}>Log in as admin</button>
            <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/wordpress/${id}/refresh`, label: 'Refresh site' }).then(after)}>Refresh</button>
            <button type="button" className="btn btn-sm" disabled={busy} onClick={() => tool('flush_cache', {}, 'Flush cache')}>Flush cache</button>
            <button type="button" className="btn btn-sm" disabled={busy} onClick={() => tool('verify_checksums', {}, 'Verify core files')}>Verify core files</button>
            <button type="button" className="btn btn-sm" disabled={busy} onClick={resetPassword}>Reset a password</button>
          </div>
          {secret && <p role="status" className="m-0 p-3 rounded-lg bg-[var(--sel)] font-mono text-xs break-all">{secret} <span className="font-sans text-muted">(shown once)</span></p>}

          <section>
            <h3 className="m-0 mb-2 text-sm font-semibold">Settings</h3>
            <ul className="m-0 p-0 list-none flex flex-col gap-2">
              {SETTINGS.map(([key, label, hint]) => (
                <li key={key}><label className="flex items-start gap-2">
                  <input type="checkbox" className="mt-1" disabled={busy} checked={!!(w.settings as R)?.[key]} onChange={(e) => toggle(key, e.target.checked)} />
                  <span><span className="font-semibold">{label}</span><br /><span className="text-muted text-xs">{hint}</span></span>
                </label></li>
              ))}
            </ul>
          </section>

          <section>
            <h3 className="m-0 mb-2 text-sm font-semibold">Updates</h3>
            {Number(w.updates) ? <div className="flex flex-wrap gap-2">
              <button type="button" className="btn btn-sm btn-primary" disabled={busy} onClick={() => update('all')}>Update everything</button>
              {w.core_update ? <button type="button" className="btn btn-sm" disabled={busy} onClick={() => update('core')}>WordPress {String(w.core_update)}</button> : null}
              <button type="button" className="btn btn-sm" disabled={busy} onClick={() => update('plugins')}>All plugins</button>
              <button type="button" className="btn btn-sm" disabled={busy} onClick={() => update('themes')}>All themes</button>
            </div> : <p className="m-0 text-muted">Everything is up to date.</p>}
            <p className="m-0 mt-1 text-xs text-muted">A restore point is taken first. If the site errors afterwards, the update is rolled back automatically.</p>
          </section>

          <section>
            <h3 className="m-0 mb-2 text-sm font-semibold">Hardening</h3>
            <ul className="m-0 p-0 list-none flex flex-col gap-2">
              {HARDENING.map(([key, label, hint]) => (
                <li key={key}><label className="flex items-start gap-2">
                  <input type="checkbox" className="mt-1" disabled={busy} checked={!!(w.hardening as R)?.[key]} onChange={(e) => harden(key, e.target.checked)} />
                  <span><span className="font-semibold">{label}</span><br /><span className="text-muted text-xs">{hint}</span></span>
                </label></li>
              ))}
            </ul>
            <button type="button" className="btn btn-sm mt-2" disabled={busy} onClick={() => { if (window.confirm('Rotate the security keys and salts? Everyone, including you, is logged out.')) tool('rotate_salts', {}, 'Rotate salts') }}>Rotate salts</button>
          </section>

          <section>
            <div className="flex items-center justify-between mb-2">
              <h3 className="m-0 text-sm font-semibold">Restore points</h3>
              <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/wordpress/${id}/restore-points`, label: 'Create restore point' }).then(after)}>Create</button>
            </div>
            {(points.item ?? []).length === 0 ? <p className="m-0 text-muted">None yet. The last five are kept.</p> : (
              <ul className="m-0 p-0 list-none flex flex-col gap-1">
                {(points.item ?? []).map((p) => (
                  <li key={String(p.id)} className="flex flex-wrap items-center gap-2">
                    <span className="flex-1 min-w-0">{ago(String(p.created_at))} · {p.reason === 'update' ? 'before an update' : 'manual'} · WordPress {String(p.version ?? '?')}</span>
                    <Badge tone={p.status === 'ready' ? 'g' : p.status === 'restored' ? 'b' : p.status === 'failed' ? 'r' : 'n'}>{String(p.status)}</Badge>
                    {['ready', 'restored'].includes(String(p.status)) && <button type="button" className="btn btn-sm" disabled={busy} onClick={() => { if (window.confirm('Restore the database and the plugin, theme and core versions from this point? Changes since then are lost.')) run({ path: `/wordpress/${id}/restore-points/${p.id}/restore`, label: 'Restore' }).then(after) }}>Restore</button>}
                  </li>
                ))}
              </ul>
            )}
          </section>

          {((w.vulnerabilities as R[]) ?? []).length > 0 && <section>
            <h3 className="m-0 mb-2 text-sm font-semibold">Known vulnerabilities</h3>
            <ul className="m-0 pl-4">{(w.vulnerabilities as R[]).map((v, i) => <li key={i}><span className="font-mono text-xs">{String(v.slug)} {String(v.version)}</span>: {String(v.name)}{v.fixed_in ? <span className="text-muted"> (fixed in {String(v.fixed_in)})</span> : null}</li>)}</ul>
          </section>}

          <section>
            <h3 className="m-0 mb-2 text-sm font-semibold">Plugins</h3>
            <ul className="m-0 p-0 list-none flex flex-col gap-1">
              {((w.plugins as R[]) ?? []).map((p) => (
                <li key={String(p.name)} className="flex flex-wrap items-center gap-2">
                  <span className="font-mono text-xs flex-1 min-w-0">{String(p.name)} {String(p.version ?? '')}</span>
                  {p.update === 'available' && <button type="button" className="btn btn-sm" disabled={busy} onClick={() => update('plugins', [String(p.name)])}>Update to {String(p.update_version)}</button>}
                  <Badge tone={p.status === 'active' ? 'g' : 'n'}>{String(p.status)}</Badge>
                  <button type="button" className="btn btn-sm" disabled={busy} onClick={() => tool(p.status === 'active' ? 'plugin_deactivate' : 'plugin_activate', { plugin: p.name }, `${p.status === 'active' ? 'Deactivate' : 'Activate'} ${p.name}`)}>{p.status === 'active' ? 'Deactivate' : 'Activate'}</button>
                </li>
              ))}
            </ul>
          </section>

          <section>
            <h3 className="m-0 mb-2 text-sm font-semibold">Themes</h3>
            <ul className="m-0 p-0 list-none flex flex-col gap-1">
              {((w.themes as R[]) ?? []).map((t) => (
                <li key={String(t.name)} className="flex flex-wrap items-center gap-2">
                  <span className="font-mono text-xs flex-1 min-w-0">{String(t.name)} {String(t.version ?? '')}</span>
                  {t.status === 'active' ? <Badge tone="g">active</Badge> : <button type="button" className="btn btn-sm" disabled={busy} onClick={() => tool('theme_activate', { theme: t.name }, `Switch theme to ${t.name}`)}>Switch to</button>}
                </li>
              ))}
            </ul>
          </section>

          <section>
            <h3 className="m-0 mb-2 text-sm font-semibold">Search and replace in the database</h3>
            <div className="flex flex-col gap-2">
              <input className="input font-mono text-xs" placeholder="Search for, e.g. http://old-domain.com" value={sr.search} onChange={(e) => { setSr({ ...sr, search: e.target.value }); setReport(null) }} />
              <input className="input font-mono text-xs" placeholder="Replace with" value={sr.replace} onChange={(e) => { setSr({ ...sr, replace: e.target.value }); setReport(null) }} />
              <div className="flex gap-2">
                <button type="button" className="btn btn-sm" disabled={busy || sr.search.length < 3} onClick={() => searchReplace(true)}>Dry run</button>
                <button type="button" className="btn btn-sm btn-danger" disabled={busy || report === null} onClick={() => { if (window.confirm('Change the database now? Take a backup first if you are unsure.')) searchReplace(false) }}>Replace</button>
              </div>
              {report !== null && <pre className="m-0 p-2 rounded bg-[var(--code-bg)] text-[var(--code-fg)] text-xs whitespace-pre-wrap max-h-48 overflow-auto">{report}</pre>}
              <p className="m-0 text-xs text-muted">Run a dry run first; Replace becomes available after it. Serialized data is handled by WP-CLI.</p>
            </div>
          </section>
        </div>
      )}
      {site.error && <p className="text-sm text-danger">{errorMessage(site.error)}</p>}
    </Drawer>
  )
}
