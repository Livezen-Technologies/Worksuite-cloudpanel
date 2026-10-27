import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router'
import { api } from '../../api/client'
import { useAction, useJob, useList } from '../../api/hooks'
import type { JobStage, Server } from '../../api/types'
import { Card, Field, PageHeader, Toggle, Empty } from '../../components/Card'
import { Badge, StatusBadge } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, Modal } from '../../components/Overlay'
import { JobTimeline } from '../../components/JobTimeline'
import { CodeEditor } from '../../components/CodeEditor'
import { useToast } from '../../components/Toasts'
import { ago, unwrap } from '../../lib/format'
import { serverName, type R } from '../../lib/util'

const SOURCES = [['git', 'Git repository', 'GitHub · GitLab · Gitea'], ['image', 'Docker image', 'Public or private registry'], ['dockerfile', 'Dockerfile', 'Build from source, isolated runner'], ['compose', 'Compose stack', 'Multi-service application']] as const
const STRATEGIES = [['recreate', 'Recreate', 'Simple; brief downtime'], ['rolling', 'Rolling update', 'Replace instances one by one'], ['blue_green', 'Blue-green', 'Switch traffic after health checks']] as const
const PIPELINE = ['Webhook validated & authorised', 'Source checkout', 'Build & image scan', 'Push image by digest', 'Server selection', 'Deploy', 'Health check', 'Complete']

function choice(on: boolean) { return on ? { border: '2px solid var(--accent)', background: 'var(--sel)' } : undefined }

function NewApplication({ open, onClose }: { open: boolean; onClose: () => void }) {
  const servers = useList<Server>('/servers')
  const { run, busy } = useAction()
  const [f, setF] = useState({ name: '', source_type: 'git' as string, repo: '', branch: 'main', image: '', environment: 'production', strategy: 'rolling' as string, server_id: '', require_approval: true })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const submit = async () => {
    try {
      await run({ path: '/applications', body: f, label: `Create ${f.name}`, success: 'Application created' })
      onClose()
    } catch (e) { setErrors(Object.fromEntries(Object.entries((e as { fields?: Record<string, string[]> }).fields ?? {}).map(([k, v]) => [k, v[0]]))) }
  }
  return (
    <Modal open={open} onClose={onClose} title="New application" width={760}
      footer={<><button type="button" className="btn" onClick={onClose}>Cancel</button><button type="button" className="btn btn-primary" disabled={busy || !f.name} onClick={submit}>Create application</button></>}>
      <div className="flex flex-col gap-4">
        <fieldset className="border-0 p-0 m-0"><legend className="text-[13px] font-semibold mb-2">Source</legend>
          <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
            {SOURCES.map(([id, t, s]) => (
              <button key={id} type="button" aria-pressed={f.source_type === id} onClick={() => setF({ ...f, source_type: id })} className="card p-4 text-left cursor-pointer text-fg" style={choice(f.source_type === id)}>
                <span className="block font-semibold text-sm">{t}</span><span className="block text-xs text-muted mt-1">{s}</span>
              </button>
            ))}
          </div>
        </fieldset>
        <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
          <Field label="Name" error={errors.name}><input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
          {f.source_type === 'image' ? <Field label="Image" error={errors.image}><input className="input font-mono" value={f.image} onChange={(e) => setF({ ...f, image: e.target.value })} /></Field>
            : <><Field label="Repository URL" error={errors.repo}><input className="input font-mono" value={f.repo} onChange={(e) => setF({ ...f, repo: e.target.value })} /></Field>
              <Field label="Branch"><input className="input" value={f.branch} onChange={(e) => setF({ ...f, branch: e.target.value })} /></Field></>}
          <Field label="Environment"><select className="input" value={f.environment} onChange={(e) => setF({ ...f, environment: e.target.value })}><option>production</option><option>staging</option><option>preview</option></select></Field>
          <Field label="Target server" hint="Leave empty to let the scheduler place it"><select className="input" value={f.server_id} onChange={(e) => setF({ ...f, server_id: e.target.value })}><option value="">Automatic placement</option>{servers.rows.map((s) => <option key={s.id} value={s.id} disabled={s.status === 'maintenance'}>{s.name}{s.status === 'maintenance' ? ' (maintenance)' : ''}</option>)}</select></Field>
        </div>
        <fieldset className="border-0 p-0 m-0"><legend className="text-[13px] font-semibold mb-2">Release strategy</legend>
          <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(170px,1fr))]">
            {STRATEGIES.map(([id, t, s]) => (
              <button key={id} type="button" aria-pressed={f.strategy === id} onClick={() => setF({ ...f, strategy: id })} className="card p-4 text-left cursor-pointer text-fg" style={choice(f.strategy === id)}>
                <span className="block font-semibold text-sm">{t}</span><span className="block text-xs text-muted mt-1">{s}</span>
              </button>
            ))}
          </div>
        </fieldset>
        <div className="flex items-center gap-3"><Toggle checked={f.require_approval} onChange={(v) => setF({ ...f, require_approval: v })} label="Require approval for production" /><span className="text-sm font-semibold">Require approval for production</span></div>
      </div>
    </Modal>
  )
}

function DeployDialog({ open, onClose, apps }: { open: boolean; onClose: () => void; apps: R[] }) {
  const [app, setApp] = useState('')
  const { run, busy } = useAction()
  return (
    <Modal open={open} onClose={onClose} title="New deployment"
      footer={<><button type="button" className="btn" onClick={onClose}>Cancel</button><button type="button" className="btn btn-primary" disabled={!app || busy} onClick={() => run({ path: '/deployments', body: { application_id: app }, label: `Deploy ${apps.find((a) => a.id === app)?.name ?? ''}` }).then(onClose).catch(() => {})}>Deploy</button></>}>
      <Field label="Application"><select className="input" value={app} onChange={(e) => setApp(e.target.value)}><option value="">Choose…</option>{apps.map((a) => <option key={a.id} value={a.id}>{a.name} · {a.environment}</option>)}</select></Field>
    </Modal>
  )
}

function Pipeline({ d }: { d: R }) {
  const { job } = useJob(d.job_id)
  const stages: JobStage[] = job?.stages?.length ? job.stages : (d.stages as JobStage[] | undefined) ?? PIPELINE.map((name) => ({ name, status: 'pending' }))
  return (
    <Card title={`Pipeline · ${d.application_name ?? d.application?.name ?? 'deployment'} ${d.version ?? ''} → ${d.environment ?? ''}`} actions={<StatusBadge status={job?.status ?? d.status} />}>
      <JobTimeline job={job} stages={stages} />
    </Card>
  )
}

function Releases({ app }: { app: R }) {
  const { rows, isLoading } = useList<R>(`/applications/${app.id}/releases`)
  const { run, busy } = useAction()
  const [target, setTarget] = useState<R | null>(null)
  return (
    <Card title={`Releases · ${app.name}`}>
      {isLoading ? 'Loading…' : rows.length === 0 ? <Empty>No releases yet.</Empty> : (
        <ul className="list-none m-0 p-0">
          {rows.map((r, i) => (
            <li key={r.id} className="flex flex-wrap items-center gap-3 py-3 border-t border-line text-sm">
              <span className="font-mono font-semibold">{r.version ?? r.tag ?? r.id.slice(0, 8)}</span>
              <StatusBadge status={i === 0 && !r.status ? 'live' : r.status ?? 'previous'} />
              <span className="font-mono text-xs text-muted truncate max-w-[220px]">{r.image_digest ?? r.digest ?? r.commit}</span>
              <span className="text-muted ml-auto">{ago(r.created_at)}</span>
              {i > 0 && <button type="button" className="btn btn-sm" onClick={() => setTarget(r)}>Roll back</button>}
            </li>
          ))}
        </ul>
      )}
      <ConfirmDialog open={!!target} onClose={() => setTarget(null)} title={`Roll back ${app.name}?`} confirmLabel="Roll back" busy={busy}
        onConfirm={() => run({ path: `/applications/${app.id}/rollback`, body: { release_id: target!.id }, label: `Roll back ${app.name}` }).then(() => setTarget(null)).catch(() => {})}>
        <p className="m-0">Traffic moves to release <span className="font-mono">{target?.version ?? target?.id}</span>. Releases are immutable, so you can roll forward again.</p>
      </ConfirmDialog>
    </Card>
  )
}

export function Applications() {
  const [params, setParams] = useSearchParams()
  const apps = useList<R>('/applications')
  const deployments = useList<R>('/deployments', undefined, { refetchInterval: 10000 })
  const approvals = useList<R>('/approvals', { status: 'pending' })
  const { run, busy } = useAction()
  const [newApp, setNewApp] = useState(false)
  const selected = apps.rows.find((a) => a.id === params.get('id'))
  const active = deployments.rows.find((d) => ['running', 'queued', 'pending', 'awaiting_approval'].includes(d.status)) ?? deployments.rows[0]
  return (
    <>
      <PageHeader title="Applications" subtitle="Deploy from Git, a Docker image, a Dockerfile or a Compose stack"
        actions={<><button type="button" className="btn" onClick={() => setNewApp(true)}>New application</button><button type="button" className="btn btn-primary" onClick={() => setParams({ deploy: '1' })}>New deployment</button></>} />
      {approvals.rows.length > 0 && (
        <Card title="Waiting for approval">
          <ul className="list-none m-0 p-0">
            {approvals.rows.map((a) => (
              <li key={a.id} className="flex flex-wrap items-center gap-3 py-2.5 border-t border-line text-sm">
                <Badge tone="y">awaiting approval</Badge>
                <span className="font-semibold">{a.title ?? a.subject ?? a.action ?? 'Deployment'}</span>
                <span className="text-muted">{a.requested_by_name ?? a.requested_by ?? ''} · {ago(a.created_at)}</span>
                <button type="button" className="btn btn-sm btn-primary ml-auto" disabled={busy} onClick={() => run({ path: `/approvals/${a.id}/approve`, label: 'Approve', success: 'Approved' })}>Approve</button>
              </li>
            ))}
          </ul>
        </Card>
      )}
      {active && <Pipeline d={active} />}
      <DataTable label="Applications" rows={apps.rows} loading={apps.isLoading} rowKey={(r) => r.id} onRowClick={(r) => setParams({ id: r.id })} selectedRowId={selected?.id}
        filters={[{ id: 'production', label: 'Production', test: (r) => r.environment === 'production' }, { id: 'staging', label: 'Staging', test: (r) => r.environment === 'staging' }]}
        columns={[
          { key: 'name', header: 'Application', render: (r) => <span className="font-semibold">{r.name}</span> },
          { key: 'source_type', header: 'Source' },
          { key: 'environment', header: 'Environment', render: (r) => <StatusBadge status={r.environment} /> },
          { key: 'strategy', header: 'Strategy' },
          { key: 'server', header: 'Server', value: (r) => serverName(r) },
          { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
          { key: 'deploy', header: '', sortable: false, render: (r) => <button type="button" className="btn btn-sm" onClick={(e) => { e.stopPropagation(); run({ path: '/deployments', body: { application_id: r.id }, label: `Deploy ${r.name}` }).catch(() => {}) }}>Deploy</button> },
        ]} />
      {selected && <Releases app={selected} />}
      <Card title="Release history" pad={false}>
        <DataTable label="Deployments" rows={deployments.rows} loading={deployments.isLoading} rowKey={(r) => r.id}
          columns={[
            { key: 'application', header: 'Application', value: (r) => r.application_name ?? r.application?.name ?? r.application_id, render: (r) => <span className="font-semibold">{r.application_name ?? r.application?.name ?? r.application_id}</span> },
            { key: 'version', header: 'Version', render: (r) => <span className="font-mono text-xs">{r.version ?? '—'}</span> },
            { key: 'environment', header: 'Environment', render: (r) => <StatusBadge status={r.environment} /> },
            { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            { key: 'source', header: 'Source', render: (r) => <span className="font-mono text-xs text-muted">{r.source ?? r.commit ?? '—'}</span> },
            { key: 'author', header: 'Author', value: (r) => r.author ?? r.user_name },
            { key: 'created_at', header: 'When', render: (r) => ago(r.created_at) },
            { key: 'approve', header: '', sortable: false, render: (r) => r.status === 'awaiting_approval' ? <button type="button" className="btn btn-sm btn-primary" onClick={() => run({ path: `/deployments/${r.id}/approve`, label: 'Approve deployment', success: 'Deployment approved' })}>Approve</button> : null },
          ]} />
      </Card>
      <DeployDialog open={params.get('deploy') === '1'} onClose={() => setParams({})} apps={apps.rows} />
      <NewApplication open={newApp} onClose={() => setNewApp(false)} />
    </>
  )
}

const SAMPLE = `services:\n  web:\n    image: nginx:1.27-alpine\n    ports:\n      - "8080:80"\n    restart: unless-stopped\n`

function StackEditor({ stack }: { stack: R }) {
  const [yaml, setYaml] = useState<string>(stack.yaml ?? stack.compose ?? stack.content ?? '')
  const [result, setResult] = useState<{ kind: 'valid' | 'invalid' | 'diff'; text: string[]; line?: number } | null>(null)
  const revisions = useList<R>(`/compose-projects/${stack.id}/revisions`)
  const { run, busy } = useAction()
  const toast = useToast()
  const [confirm, setConfirm] = useState<null | 'remove' | R>(null)
  useEffect(() => { setYaml(stack.yaml ?? stack.compose ?? stack.content ?? ''); setResult(null) }, [stack.id]) // eslint-disable-line react-hooks/exhaustive-deps
  const validate = async () => {
    try {
      const r = unwrap<R>(await api.post(`/compose-projects/${stack.id}/validate`, { yaml }))
      const errs = (r.errors ?? []) as (string | R)[]
      if (r.valid === false || errs.length) setResult({ kind: 'invalid', text: errs.map((e) => (typeof e === 'string' ? e : `line ${e.line ?? '?'}: ${e.message}`)), line: typeof errs[0] === 'object' ? (errs[0] as R).line : undefined })
      else setResult({ kind: 'valid', text: ['Schema valid'] })
    } catch (e) { toast.error(e, 'Validation failed') }
  }
  const diff = async () => {
    try {
      const r = unwrap<R>(await api.post(`/compose-projects/${stack.id}/diff`, { yaml }))
      const d = r.diff ?? r.lines ?? r
      setResult({ kind: 'diff', text: typeof d === 'string' ? d.split('\n') : Array.isArray(d) ? d.map(String) : [JSON.stringify(d, null, 2)] })
    } catch (e) { toast.error(e, 'Diff failed') }
  }
  const services = (stack.services ?? []) as (string | R)[]
  return (
    <div className="flex flex-wrap gap-4">
      <Card className="flex-[2_1_520px]" title={<>{stack.name} <span className="font-mono text-xs text-muted font-normal">rev {stack.revision ?? stack.current_revision ?? '—'}</span></>}
        actions={<>
          <button type="button" className="btn btn-sm" onClick={validate}>Validate</button>
          <button type="button" className="btn btn-sm" onClick={diff}>Diff</button>
          <button type="button" className="btn btn-sm btn-primary" disabled={busy} onClick={() => run({ path: `/compose-projects/${stack.id}/deploy`, body: { yaml }, label: `Deploy ${stack.name}` })}>Deploy</button>
        </>}>
        <CodeEditor label={`${stack.name} compose.yaml`} value={yaml} onChange={(v) => { setYaml(v); setResult(null) }} errorLine={result?.line} />
        {result && (
          <div className="mt-3" role="status">
            {result.kind === 'diff' ? (
              <pre className="m-0 p-3 rounded-lg bg-head font-mono text-xs overflow-auto max-h-64">{result.text.map((l, i) => <div key={i} style={{ color: l.startsWith('+') ? 'var(--g-fg)' : l.startsWith('-') ? 'var(--r-fg)' : undefined }}>{l}</div>)}</pre>
            ) : <div className="flex gap-2 items-start"><Badge tone={result.kind === 'valid' ? 'g' : 'r'}>{result.kind}</Badge><div className="text-sm">{result.text.map((t, i) => <div key={i}>{t}</div>)}</div></div>}
          </div>
        )}
        <div className="flex flex-wrap gap-2 mt-4 text-sm text-muted items-center">
          <span>{serverName(stack)} · {stack.environment}</span>
          <span className="ml-auto flex gap-2">
            <button type="button" className="btn btn-sm" onClick={() => run({ path: `/compose-projects/${stack.id}/actions`, body: { action: 'restart' }, label: `Restart ${stack.name}` })}>Restart</button>
            <button type="button" className="btn btn-sm" onClick={() => run({ path: `/compose-projects/${stack.id}/actions`, body: { action: 'stop' }, label: `Stop ${stack.name}` })}>Stop</button>
            <button type="button" className="btn btn-sm btn-danger" onClick={() => setConfirm('remove')}>Remove</button>
          </span>
        </div>
      </Card>
      <div className="flex-[1_1_280px] flex flex-col gap-4 min-w-0">
        <Card title="Services">
          {services.length === 0 ? <Empty>No services reported.</Empty> : services.map((s, i) => {
            const o = typeof s === 'string' ? { name: s } : s
            return <div key={i} className="flex items-center gap-2 py-2 border-t border-line text-sm first:border-t-0"><span className="font-semibold flex-1">{o.name}</span>{o.depends_on?.length ? <span className="text-xs text-muted">needs {[].concat(o.depends_on).join(', ')}</span> : null}<StatusBadge status={o.health ?? o.state ?? 'unknown'} /></div>
          })}
        </Card>
        <Card title="Revisions">
          {revisions.rows.length === 0 ? <Empty>No revisions yet.</Empty> : revisions.rows.map((r) => (
            <div key={r.id} className="flex items-center gap-2 py-2 border-t border-line text-sm first:border-t-0">
              <span className="font-mono font-semibold">rev {r.number ?? r.revision ?? r.id.slice(0, 6)}</span>
              <span className="text-xs text-muted flex-1">{r.author ?? ''} {ago(r.created_at)}</span>
              <button type="button" className="btn btn-sm" onClick={() => setConfirm(r)}>Roll back</button>
            </div>
          ))}
        </Card>
      </div>
      <ConfirmDialog open={!!confirm} onClose={() => setConfirm(null)} busy={busy}
        danger={confirm === 'remove'} confirmText={confirm === 'remove' ? stack.name : undefined}
        title={confirm === 'remove' ? `Remove ${stack.name}?` : `Roll back ${stack.name}?`} confirmLabel={confirm === 'remove' ? 'Remove stack' : 'Roll back'}
        onConfirm={() => (confirm === 'remove'
          ? run({ path: `/compose-projects/${stack.id}/actions`, body: { action: 'remove' }, label: `Remove ${stack.name}` })
          : run({ path: `/compose-projects/${stack.id}/rollback`, body: { revision_id: (confirm as R).id }, label: `Roll back ${stack.name}` })).then(() => setConfirm(null)).catch(() => {})}>
        <p className="m-0">{confirm === 'remove' ? 'All services in this stack are stopped and removed. Named volumes are kept.' : 'The selected revision is deployed as a new revision.'}</p>
      </ConfirmDialog>
    </div>
  )
}

export function Compose() {
  const { rows, isLoading } = useList<R>('/compose-projects', undefined, { refetchInterval: 15000 })
  const servers = useList<Server>('/servers')
  const [sel, setSel] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)
  const [f, setF] = useState({ name: '', server_id: '', environment: 'production', yaml: SAMPLE, git_url: '' })
  const { run, busy } = useAction()
  const stack = rows.find((r) => r.id === sel) ?? rows[0]
  return (
    <>
      <PageHeader title="Compose Stacks" subtitle="One Compose project per target server · versioned revisions with rollback"
        actions={<button type="button" className="btn btn-primary" onClick={() => setCreating(true)}>New stack</button>} />
      {isLoading ? <Card>Loading stacks…</Card> : rows.length === 0 ? <Card><Empty>No stacks yet.</Empty></Card> : (
        <ul className="list-none m-0 p-0 grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(240px,1fr))]">
          {rows.map((s) => (
            <li key={s.id}>
              <button type="button" aria-pressed={stack?.id === s.id} onClick={() => setSel(s.id)} className="card p-4 w-full text-left cursor-pointer text-fg flex flex-col gap-1.5" style={choice(stack?.id === s.id)}>
                <span className="flex justify-between items-center gap-2"><span className="font-semibold">{s.name}</span><StatusBadge status={s.status ?? s.state} /></span>
                <span className="text-xs text-muted">{serverName(s)} · {s.environment} · rev {s.revision ?? s.current_revision ?? '—'}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
      {stack && <StackEditor stack={stack} />}
      <Modal open={creating} onClose={() => setCreating(false)} title="New stack" width={760}
        footer={<><button type="button" className="btn" onClick={() => setCreating(false)}>Cancel</button><button type="button" className="btn btn-primary" disabled={busy || !f.name || !f.server_id} onClick={() => run({ path: '/compose-projects', body: f, label: `Create ${f.name}`, success: 'Stack created' }).then(() => setCreating(false)).catch(() => {})}>Create stack</button></>}>
        <div className="flex flex-col gap-4">
          <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
            <Field label="Project name"><input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
            <Field label="Target server"><select className="input" value={f.server_id} onChange={(e) => setF({ ...f, server_id: e.target.value })}><option value="">Choose…</option>{servers.rows.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
            <Field label="Environment"><select className="input" value={f.environment} onChange={(e) => setF({ ...f, environment: e.target.value })}><option>production</option><option>staging</option></select></Field>
          </div>
          <Field label="Import from Git (optional)" hint="Repository URL containing compose.yaml"><input className="input font-mono" value={f.git_url} onChange={(e) => setF({ ...f, git_url: e.target.value })} /></Field>
          <label className="label">Upload compose.yaml<input type="file" accept=".yml,.yaml" className="text-sm font-normal" onChange={async (e) => { const file = e.target.files?.[0]; if (file) setF({ ...f, yaml: await file.text() }) }} /></label>
          <CodeEditor label="compose.yaml" value={f.yaml} onChange={(v) => setF({ ...f, yaml: v })} rows={12} />
        </div>
      </Modal>
    </>
  )
}
