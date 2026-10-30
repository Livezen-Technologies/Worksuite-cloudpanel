import { useState } from 'react'
import { Link } from 'react-router'
import { useAction, useList } from '../../api/hooks'
import { Card, Empty, Field, PageHeader, Toggle } from '../../components/Card'
import { Badge, StatusBadge } from '../../components/Badge'
import { Modal } from '../../components/Overlay'
import Select from '../../components/Select'
import { ago } from '../../lib/format'
import { type R } from '../../lib/util'

type App = { id: string; name: string; service: string; status: string; asleep: boolean; image: string | null; last_deployed_at: string | null; url: string | null }
type Env = { id: string; name: string; slug: string; kind: string; branch: string | null; requires_approval: boolean; sleep_after_minutes: number | null; variables: string[]; apps: App[] }
type Project = { id: string; name: string; description: string | null; webhook_url: string; environments: Env[] }

const KIND_TONE: Record<string, 'g' | 'b' | 'y' | 'n'> = { production: 'g', staging: 'b', development: 'n', preview: 'y' }

function EnvEditor({ env, onClose }: { env: Env; onClose: () => void }) {
  const { run, busy } = useAction()
  const [f, setF] = useState({ branch: env.branch ?? '', requires_approval: env.requires_approval, sleep: env.sleep_after_minutes ? String(env.sleep_after_minutes) : '', vars: '' })
  const save = () => {
    const vars = f.vars.trim() ? Object.fromEntries(f.vars.split('\n').map((l) => l.trim()).filter((l) => l.includes('=')).map((l) => [l.slice(0, l.indexOf('=')).trim(), l.slice(l.indexOf('=') + 1)])) : undefined
    run({ path: `/environments/${env.id}`, method: 'PATCH', label: `Save ${env.name}`, success: 'Environment saved',
      body: { branch: f.branch || null, requires_approval: f.requires_approval, sleep_after_minutes: f.sleep ? Number(f.sleep) : null, ...(vars ? { variables: vars } : {}) } }).then(onClose).catch(() => {})
  }
  return (
    <Modal open onClose={onClose} title={`Environment: ${env.name}`} width={560}
      footer={<><button type="button" className="btn" onClick={onClose}>Cancel</button><button type="button" className="btn btn-primary" disabled={busy} onClick={save}>Save</button></>}>
      <div className="flex flex-col gap-4">
        <Field label="Git branch" hint="Pushes to this branch deploy the environment's Git apps"><input className="input font-mono" value={f.branch} onChange={(e) => setF({ ...f, branch: e.target.value })} placeholder="main" /></Field>
        <Field label="Approval"><Toggle checked={f.requires_approval} onChange={(v) => setF({ ...f, requires_approval: v })} label="Require approval to deploy" /></Field>
        {env.kind !== 'production' && (
          <Field label="Sleep when idle" hint="Stops the apps after this long without a deployment; wake them from here">
            <Select className="input" value={f.sleep} onChange={(e) => setF({ ...f, sleep: e.target.value })}>
              <option value="">Never</option>{[30, 60, 240, 720, 1440].map((m) => <option key={m} value={m}>{m < 60 ? `${m} minutes` : `${m / 60} hours`}</option>)}
            </Select>
          </Field>
        )}
        <Field label="Shared variables" hint={`One KEY=value per line; replaces ${env.variables.length ? env.variables.join(', ') : 'none'}. Reference them as \${{shared.KEY}}, and other services as \${{api.URL}}`}>
          <textarea className="input font-mono" rows={5} value={f.vars} onChange={(e) => setF({ ...f, vars: e.target.value })} placeholder="REGION=eu" />
        </Field>
      </div>
    </Modal>
  )
}

function EnvCard({ project, env }: { project: Project; env: Env }) {
  const { run, busy } = useAction()
  const [edit, setEdit] = useState(false)
  const others = project.environments.filter((e) => e.id !== env.id)
  const [target, setTarget] = useState(others[0]?.id ?? '')
  const duplicate = () => {
    const name = window.prompt('Name of the new environment', `${env.name} copy`)
    if (name) run({ path: `/environments/${env.id}/duplicate`, body: { name }, label: `Duplicate ${env.name}`, success: 'Environment duplicated' }).catch(() => {})
  }
  return (
    <Card title={<span className="flex items-center gap-2">{env.name} <Badge tone={KIND_TONE[env.kind]}>{env.kind}</Badge></span>}
      subtitle={[env.branch ? `branch ${env.branch}` : 'no branch', env.requires_approval ? 'approval required' : null, env.sleep_after_minutes ? `sleeps after ${env.sleep_after_minutes} min` : null].filter(Boolean).join(' · ')}
      actions={<><button type="button" className="btn btn-sm" onClick={() => setEdit(true)}>Edit</button><button type="button" className="btn btn-sm" disabled={busy} onClick={duplicate}>Duplicate</button></>}>
      {env.apps.length === 0 ? <Empty>No apps yet. Pick this environment when creating an application.</Empty> : (
        <ul className="list-none m-0 p-0">
          {env.apps.map((a) => (
            <li key={a.id} className="flex flex-wrap items-center gap-2 py-2 border-t border-line text-sm">
              <Link to={`/admin/applications/${a.id}`} className="font-semibold">{a.service}</Link>
              <StatusBadge status={a.asleep ? 'sleeping' : a.status} />
              <span className="text-xs text-muted font-mono truncate max-w-[220px]">{a.image ?? ''}</span>
              <span className="text-xs text-muted ml-auto">{a.last_deployed_at ? ago(a.last_deployed_at) : 'never deployed'}</span>
            </li>
          ))}
        </ul>
      )}
      <div className="flex flex-wrap items-center gap-2 mt-3">
        {others.length > 0 && <>
          <Select className="input w-auto" value={target} onChange={(e) => setTarget(e.target.value)} aria-label="Promote to">{others.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}</Select>
          <button type="button" className="btn btn-sm" disabled={busy || !target} onClick={() => run({ path: `/environments/${env.id}/promote`, body: { target_environment_id: target }, label: 'Promote', success: 'Promotion started' }).catch(() => {})}>Promote live images</button>
        </>}
        {env.apps.some((a) => a.asleep) && <button type="button" className="btn btn-sm btn-primary" disabled={busy} onClick={() => run({ path: `/environments/${env.id}/wake`, label: 'Wake', success: 'Waking up' }).catch(() => {})}>Wake</button>}
      </div>
      {edit && <EnvEditor env={env} onClose={() => setEdit(false)} />}
    </Card>
  )
}

export default function Projects() {
  const { rows, isLoading } = useList<Project>('/projects')
  const { run, busy } = useAction()
  const [open, setOpen] = useState(false)
  const [name, setName] = useState('')
  const [secret, setSecret] = useState<string | null>(null)
  const create = () => run<R>({ path: '/projects', body: { name }, label: `Create ${name}`, success: 'Project created' })
    .then((r) => { setOpen(false); setName(''); setSecret((r as R)?.data?.webhook_secret ?? null) }).catch(() => {})
  return (
    <>
      <PageHeader title="Projects" subtitle="Environments per project, shared variables, branch deploys, promotion and sleep"
        actions={<button type="button" className="btn btn-primary" onClick={() => setOpen(true)}>New project</button>} />
      {secret && <div role="status" className="card p-4 mb-4 text-sm">Webhook secret (shown once): <code className="font-mono break-all">{secret}</code></div>}
      {isLoading ? <Card>Loading projects…</Card> : rows.length === 0 ? <Card><Empty>No projects yet. A project groups production, staging and preview environments of one product.</Empty></Card> : rows.map((p) => (
        <section key={p.id} className="mb-6" aria-label={p.name}>
          <div className="flex flex-wrap items-baseline gap-3 mb-2">
            <h2 className="text-base font-semibold m-0">{p.name}</h2>
            <span className="text-xs text-muted">Push webhook: <code className="font-mono">{p.webhook_url}</code></span>
          </div>
          <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(320px,1fr))]">{p.environments.map((e) => <EnvCard key={e.id} project={p} env={e} />)}</div>
        </section>
      ))}
      <Modal open={open} onClose={() => setOpen(false)} title="New project"
        footer={<><button type="button" className="btn" onClick={() => setOpen(false)}>Cancel</button><button type="button" className="btn btn-primary" disabled={busy || !name} onClick={create}>Create</button></>}>
        <Field label="Name" hint="Starts with production (branch main) and staging environments"><input className="input" value={name} onChange={(e) => setName(e.target.value)} /></Field>
      </Modal>
    </>
  )
}
