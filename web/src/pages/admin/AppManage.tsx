import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router'
import { api } from '../../api/client'
import { useAction, useList, useOne } from '../../api/hooks'
import { Card, Field, PageHeader, Toggle, Empty, FormActions, Loading } from '../../components/Card'
import { Badge, StatusBadge } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { ScalingPanel } from '../../components/ScalingPanel'
import Select from '../../components/Select'

type R = Record<string, any>
const TABS = ['Settings', 'Scaling', 'Databases', 'SSL/TLS', 'Cron Jobs', 'Logs'] as const
type Tab = typeof TABS[number]

export default function AppManage() {
  const { id = '' } = useParams()
  const app = useOne<R>(id ? `/applications/${id}` : null)
  const [tab, setTab] = useState<Tab>('Settings')
  const row = app.data?.data

  return (
    <>
      <PageHeader title={row?.name ?? 'Application'}
        subtitle={row ? `${row.source_type} · ${row.image ?? row.source ?? ''}` : ''}
        actions={<Link className="btn" to="/admin/applications">All applications</Link>} />

      <div className="flex flex-wrap gap-1 border-b border-line" role="tablist">
        {TABS.map((t) => (
          <button key={t} type="button" role="tab" aria-selected={tab === t}
            onClick={() => setTab(t)}
            className={`min-h-11 border-b-2 px-4 text-sm font-medium ${tab === t ? 'border-accent text-accent' : 'border-transparent text-muted hover:text-text'}`}>
            {t}
          </button>
        ))}
      </div>

      {!row && <Loading label="Loading…" />}
      {row && tab === 'Settings' && <SettingsTab app={row} />}
      {row && tab === 'Scaling' && <ScalingPanel path={`/applications/${row.id}/scaling`} />}
      {row && tab === 'Databases' && <DatabasesTab app={row} />}
      {row && tab === 'SSL/TLS' && <DomainsTab app={row} />}
      {row && tab === 'Cron Jobs' && <CronTab app={row} />}
      {row && tab === 'Logs' && <LogsTab app={row} />}
    </>
  )
}

/* -------------------------------------------------------------------- settings */

function SettingsTab({ app }: { app: R }) {
  const { run, busy } = useAction()
  const [f, setF] = useState({
    name: app.name ?? '', port: app.port ?? 80, health_path: app.health_path ?? '/',
    health_timeout: app.health_timeout ?? 60, environment: app.environment ?? 'production',
    size: app.size ?? 'small', strategy: app.strategy ?? 'rolling',
    auto_deploy: !!app.auto_deploy, auto_restart: !!app.auto_restart, requires_approval: !!app.requires_approval,
    mesh_network: !!app.mesh_network,
  })
  useEffect(() => { setF((p) => ({ ...p, name: app.name ?? p.name })) }, [app.id])

  return (
    <Card title="Application settings">
      <form className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]"
            onSubmit={(e) => { e.preventDefault(); run({ method: 'PATCH', path: `/applications/${app.id}`, body: f, label: 'Save settings', success: 'Settings saved' }).catch(() => {}) }}>
        <Field label="Name"><input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
        <Field label="Container port"><input className="input" type="number" min={1} max={65535} value={f.port}
                 onChange={(e) => setF({ ...f, port: Number(e.target.value) })} /></Field>
        <Field label="Health check path"><input className="input font-mono text-xs" value={f.health_path}
                 onChange={(e) => setF({ ...f, health_path: e.target.value })} /></Field>
        <Field label="Health timeout (s)"><input className="input" type="number" min={5} max={600} value={f.health_timeout}
                 onChange={(e) => setF({ ...f, health_timeout: Number(e.target.value) })} /></Field>
        <Field label="Environment">
          <Select className="input" value={f.environment} onChange={(e) => setF({ ...f, environment: e.target.value })}>
            <option value="production">production</option><option value="staging">staging</option><option value="development">development</option>
          </Select>
        </Field>
        <Field label="Size">
          <Select className="input" value={f.size} onChange={(e) => setF({ ...f, size: e.target.value })}>
            <option value="small">Small</option><option value="standard">Standard</option><option value="pro">Pro</option>
          </Select>
        </Field>
        <div className="col-span-full flex flex-col gap-3">
          {([['auto_deploy', 'Deploy automatically on a new release'],
             ['auto_restart', 'Restart the container if it exits'],
             ['requires_approval', 'Require a second approver for production deploys'],
             ['mesh_network', 'Reachable from other servers on the private network (applies at the next deploy)']] as const).map(([k, label]) => (
            <label key={k} className="flex items-center gap-3 text-sm">
              <Toggle checked={(f as R)[k]} label={label} onChange={(v) => setF({ ...f, [k]: v })} />
              <span>{label}</span>
            </label>
          ))}
        </div>
        <FormActions className="col-span-full">
          <button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Save settings'}</button>
        </FormActions>
      </form>
    </Card>
  )
}

/* ------------------------------------------------------------------- databases */

function DatabasesTab({ app }: { app: R }) {
  const dbs = useList<R>('/databases')
  const { run, busy } = useAction()
  const [f, setF] = useState({ name: '', engine: 'mariadb', version: '', memory_mb: 512 })
  const mine = dbs.rows.filter((d) => !app.server_id || d.server_id === app.server_id)

  return (
    <>
      <Card title="Create a database">
        {!app.server_id && <p className="m-0 mb-3 text-sm text-muted">This application has no server yet; the database needs one.</p>}
        <form className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]"
              onSubmit={(e) => {
                e.preventDefault()
                run({ path: '/databases', label: `Create ${f.name}`, success: 'Database queued',
                      body: { ...f, server_id: app.server_id, environment: app.environment } }).catch(() => {})
              }}>
          <Field label="Database name"><input className="input" required value={f.name} placeholder="app_db"
                   onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
          <Field label="Engine">
            <Select className="input" value={f.engine} onChange={(e) => setF({ ...f, engine: e.target.value })}>
              {['mariadb', 'mysql', 'postgres', 'redis', 'mongodb'].map((x) => <option key={x}>{x}</option>)}
            </Select>
          </Field>
          <Field label="Version" hint="Optional, e.g. 11.8"><input className="input" value={f.version}
                   onChange={(e) => setF({ ...f, version: e.target.value })} /></Field>
          <Field label="Memory (MB)"><input className="input" type="number" min={64} value={f.memory_mb}
                   onChange={(e) => setF({ ...f, memory_mb: Number(e.target.value) })} /></Field>
          <FormActions className="col-span-full">
            <button className="btn btn-primary" disabled={busy || !app.server_id}>Create database</button>
          </FormActions>
        </form>
      </Card>
      {!dbs.isLoading && mine.length === 0 && <Empty>No databases on this server yet.</Empty>}
      <DataTable label="Databases" rows={mine} loading={dbs.isLoading} rowKey={(r) => r.id}
        actions={(r) => [{ label: `Back up ${r.name}`, icon: 'archive', disabled: busy, onClick: () => run({ path: `/databases/${r.id}/backup`, label: `Back up ${r.name}` }).catch(() => {}) }]}
        columns={[
          { key: 'name', header: 'Database', render: (r) => <span className="font-semibold">{r.name}</span> },
          { key: 'engine', header: 'Engine' },
          { key: 'version', header: 'Version', value: (r) => r.version ?? '—' },
          { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status ?? 'unknown'} /> },
        ]} />
    </>
  )
}

/* --------------------------------------------------------------------- domains */

function DomainsTab({ app }: { app: R }) {
  const domains = useList<R>('/domains', { application_id: app.id })
  const { run, busy } = useAction()
  const [name, setName] = useState('')

  return (
    <>
      <Card title="Add a domain">
        <p className="m-0 mb-3 text-sm text-muted">Point the DNS A record at this server first; the certificate is issued automatically once it verifies.</p>
        <form className="flex flex-wrap items-end gap-3"
              onSubmit={(e) => {
                e.preventDefault()
                run({ path: '/domains', label: `Add ${name}`, success: 'Domain added',
                      body: { name, application_id: app.id, target_port: app.port } })
                  .then(() => setName('')).catch(() => {})
              }}>
          <Field label="Domain"><input className="input" required value={name} placeholder="example.com"
                   onChange={(e) => setName(e.target.value)} /></Field>
          <button className="btn btn-primary min-h-11" disabled={busy}>Add domain</button>
        </form>
      </Card>
      {!domains.isLoading && domains.rows.length === 0 && <Empty>No domains attached to this application.</Empty>}
      <DataTable label="Domains" rows={domains.rows} loading={domains.isLoading} rowKey={(r) => r.id}
        actions={(r) => [
          { label: `Verify DNS for ${r.name}`, icon: 'globe', disabled: busy, onClick: () => run({ path: `/domains/${r.id}/verify`, label: `Verify ${r.name}` }).catch(() => {}) },
          { label: `Issue certificate for ${r.name}`, icon: 'shield', disabled: busy, onClick: () => run({ path: `/domains/${r.id}/certificate`, label: `Issue certificate for ${r.name}` }).catch(() => {}) },
        ]}
        columns={[
          { key: 'name', header: 'Domain', render: (r) => <span className="font-semibold">{r.name}</span> },
          { key: 'dns_status', header: 'DNS', render: (r) => <StatusBadge status={r.dns_status ?? 'pending'} /> },
          { key: 'https_status', header: 'HTTPS', render: (r) => <StatusBadge status={r.https_status ?? 'pending'} /> },
          { key: 'certificate_expires_at', header: 'Certificate expires', value: (r) => r.certificate_expires_at ?? '—' },
        ]} />
    </>
  )
}

/* ------------------------------------------------------------------------ cron */

const ACTIONS = ['container_restart', 'backup_policy', 'image_prune', 'container_prune', 'webhook']

function CronTab({ app }: { app: R }) {
  const jobs = useList<R>('/scheduled-jobs')
  const { run, busy } = useAction()
  const [f, setF] = useState({ name: '', cron: '0 3 * * *', action: 'container_restart', enabled: true })

  return (
    <>
      <Card title="Add a cron job">
        <p className="m-0 mb-3 text-sm text-muted">Standard five-field cron expression, evaluated in UTC.</p>
        <form className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]"
              onSubmit={(e) => {
                e.preventDefault()
                run({ path: '/scheduled-jobs', label: `Create ${f.name}`, success: 'Cron job created',
                      body: { ...f, trigger: 'cron', params: { application_id: app.id } } })
                  .then(() => setF({ ...f, name: '' })).catch(() => {})
              }}>
          <Field label="Name"><input className="input" required value={f.name} placeholder="Nightly restart"
                   onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
          <Field label="Schedule" hint="min hour day month weekday">
            <input className="input font-mono text-xs" required value={f.cron}
                   onChange={(e) => setF({ ...f, cron: e.target.value })} /></Field>
          <Field label="Action">
            <Select className="input" value={f.action} onChange={(e) => setF({ ...f, action: e.target.value })}>
              {ACTIONS.map((a) => <option key={a} value={a}>{a.replace(/_/g, ' ')}</option>)}
            </Select>
          </Field>
          <FormActions className="col-span-full">
            <button className="btn btn-primary" disabled={busy}>Add cron job</button>
          </FormActions>
        </form>
      </Card>
      {!jobs.isLoading && jobs.rows.length === 0 && <Empty>No cron jobs found.</Empty>}
      <DataTable label="Cron jobs" rows={jobs.rows} loading={jobs.isLoading} rowKey={(r) => r.id}
        actions={(r) => [
          { label: `Run ${r.name} now`, icon: 'play', disabled: busy, onClick: () => run({ path: `/scheduled-jobs/${r.id}/run`, label: `Run ${r.name}` }).catch(() => {}) },
          { label: 'Delete', icon: 'trash', danger: true, confirm: `The cron job ${r.name} is deleted.`, disabled: busy, onClick: () => run({ method: 'DELETE', path: `/scheduled-jobs/${r.id}`, label: `Delete ${r.name}`, success: 'Cron job deleted' }).catch(() => {}) },
        ]}
        columns={[
          { key: 'name', header: 'Job', render: (r) => <span className="font-semibold">{r.name}</span> },
          { key: 'cron', header: 'Schedule', render: (r) => <span className="font-mono text-xs">{r.cron ?? r.trigger}</span> },
          { key: 'action', header: 'Action', value: (r) => String(r.action ?? '').replace(/_/g, ' ') },
          { key: 'enabled', header: 'Enabled', render: (r) => <Badge tone={r.enabled ? 'g' : 'n'}>{r.enabled ? 'enabled' : 'paused'}</Badge> },
          { key: 'next_run_at', header: 'Next run', value: (r) => r.next_run_at ?? '—' },
        ]} />
    </>
  )
}

/* ------------------------------------------------------------------------ logs */

function LogsTab({ app }: { app: R }) {
  const containers = useList<R>('/containers')
  const mine = containers.rows.filter((c) => c.application_id === app.id || c.name?.includes(app.name))
  const [cid, setCid] = useState('')
  const [text, setText] = useState('')
  const [loading, setLoading] = useState(false)
  const target = cid || mine[0]?.id

  const load = async () => {
    if (!target) return
    setLoading(true)
    try {
      const res = await api.get<R>(`/containers/${target}/logs`, { tail: 200 })
      const body = res?.data ?? res
      setText(typeof body === 'string' ? body : (body?.logs ?? JSON.stringify(body, null, 2)))
    } catch (e) {
      setText(String(e))
    } finally { setLoading(false) }
  }
  useEffect(() => { if (target) load() }, [target])

  return (
    <Card title="Container logs">
      {mine.length === 0 && <p className="m-0 mb-3 text-sm text-muted">No container is running for this application yet.</p>}
      {mine.length > 0 && (
        <div className="mb-3 flex flex-wrap items-end gap-3">
          <Field label="Container">
            <Select className="input !w-auto" value={target ?? ''} onChange={(e) => setCid(e.target.value)}>
              {mine.map((c) => <option key={c.id} value={c.id}>{c.name ?? c.id.slice(0, 12)}</option>)}
            </Select>
          </Field>
          <button type="button" className="btn min-h-11" disabled={loading} onClick={load}>
            {loading ? 'Loading…' : 'Refresh'}
          </button>
        </div>
      )}
      <pre className="m-0 max-h-[28rem] overflow-auto rounded-lg bg-code-bg p-4 font-mono text-xs leading-relaxed text-code-fg">
        {text || 'No output.'}
      </pre>
    </Card>
  )
}
