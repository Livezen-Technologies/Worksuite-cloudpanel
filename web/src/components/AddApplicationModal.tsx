import { useMemo, useState } from 'react'
import { useAction, useList } from '../api/hooks'
import { Modal } from './Overlay'
import { Badge } from './Badge'
import Select from './Select'
import InstallWizard from '../pages/admin/InstallWizard'
import NewApplicationWizard from '../pages/admin/NewApplicationWizard'
import type { R } from '../lib/util'

type Step = 'choose' | 'catalog' | 'existing' | 'database' | 'service'

/**
 * Project → Add application, without leaving the project: install from the App Catalogue, deploy
 * from Git or a Docker image, or move an existing application in. Whatever is created lands in this
 * project's environment.
 */
export function AddApplicationModal({ projectId, projectName, open, onClose, onAdded }: { projectId: string; projectName: string; open: boolean; onClose: () => void; onAdded: () => void }) {
  const [step, setStep] = useState<Step>('choose')
  const [picked, setPicked] = useState<R | null>(null)
  const [q, setQ] = useState('')
  const [wizard, setWizard] = useState(false)
  const [existing, setExisting] = useState('')
  const templates = useList<R>(open && step === 'catalog' ? '/templates' : null, { per_page: 500 })
  const apps = useList<R>(open && step === 'existing' ? '/applications' : null, { per_page: 500 })
  const { run, busy } = useAction()
  const shown = useMemo(() => templates.rows.filter((t) => !q || `${t.name} ${t.category ?? ''} ${t.description ?? ''}`.toLowerCase().includes(q.toLowerCase())), [templates.rows, q])
  const close = () => { setStep('choose'); setPicked(null); setQ(''); setExisting(''); onClose() }

  if (wizard) {
    return <NewApplicationWizard open projectId={projectId} onClose={() => { setWizard(false); close(); onAdded() }} />
  }

  return (
    <Modal open={open} onClose={close} title={picked ? `Install ${String(picked.name)} into ${projectName}` : `Add an application to ${projectName}`} width={picked ? 960 : 760}>
      {picked ? (
        <InstallWizard t={picked as never} projectId={projectId} onBack={() => { setPicked(null); onAdded() }} />
      ) : step === 'choose' ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {([
            ['catalog', 'App or full stack from the catalogue', 'Apps installed with their own new database, or complete stacks (app + database + cache) in one go.'],
            ['git', 'From Git or a Docker image', 'Your own code or image, with build, domain and resources.'],
            ['database', 'Database', 'MySQL, MariaDB, PostgreSQL, MongoDB or Redis for this project’s apps to share.'],
            ['service', 'Service container', 'A worker, queue, cron runner or any other image next to the apps.'],
            ['existing', 'Move an existing app', 'An application that already runs, moved into this project.'],
          ] as const).map(([id, title, text]) => (
            <button key={id} type="button" className="card text-left p-4 flex flex-col gap-2 cursor-pointer hover:border-accent"
              onClick={() => (id === 'git' ? setWizard(true) : setStep(id))}>
              <span className="font-bold">{title}</span><span className="text-sm text-muted">{text}</span>
            </button>
          ))}
        </div>
      ) : step === 'catalog' ? (
        <div className="flex flex-col gap-3">
          <div className="flex gap-2"><button type="button" className="btn" onClick={() => setStep('choose')}>← Back</button>
            <input className="input flex-1" placeholder="Search the catalogue" value={q} onChange={(e) => setQ(e.target.value)} autoFocus /></div>
          {templates.isLoading ? <p className="m-0 text-sm text-muted">Loading the catalogue…</p> :
            <div className="grid gap-2 max-h-[60vh] overflow-auto [grid-template-columns:repeat(auto-fill,minmax(min(100%,220px),1fr))]">
              {shown.map((t) => (
                <button key={t.id} type="button" className="card text-left p-3 flex flex-col gap-1 cursor-pointer hover:border-accent" onClick={() => setPicked(t)}>
                  <span className="font-semibold truncate">{t.name}</span>
                  <span className="text-xs text-muted line-clamp-2">{t.description}</span>
                  <span className="mt-1">{t.type === 'compose' ? <Badge tone="b">Stack</Badge> : <Badge tone="n">Single image</Badge>}</span>
                </button>))}
            </div>}
        </div>
      ) : step === 'database' || step === 'service' ? (
        <NewResourceForm kind={step} projectId={projectId} onBack={() => setStep('choose')} onDone={() => { onAdded(); close() }} />
      ) : (
        <div className="flex flex-col gap-3 text-sm">
          <button type="button" className="btn self-start" onClick={() => setStep('choose')}>← Back</button>
          <label><span className="block text-xs text-muted mb-1">Application</span>
            <Select className="input" value={existing} onChange={(e) => setExisting(e.target.value)}>
              <option value="">Choose an application…</option>
              {apps.rows.filter((a) => a.project_id !== projectId).map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
            </Select></label>
          <p className="m-0 text-xs text-muted">It keeps running; its environment moves with it into this project.</p>
          <div className="flex justify-end"><button type="button" className="btn btn-primary" disabled={busy || !existing}
            onClick={() => run({ path: `/projects/${projectId}/apps`, body: { app_id: existing }, label: 'Move application', success: 'Application added to the project' }).then(() => { onAdded(); close() }).catch(() => {})}>Add to project</button></div>
        </div>
      )}
    </Modal>
  )
}

/** Database or service container that belongs to the project, on a server the person picks. */
function NewResourceForm({ kind, projectId, onBack, onDone }: { kind: 'database' | 'service'; projectId: string; onBack: () => void; onDone: () => void }) {
  const servers = useList<R>('/servers', { per_page: 100 })
  const { run, busy } = useAction()
  const [f, setF] = useState({ name: '', engine: 'mysql', version: '', server_id: '', image: '', env: '', memory: '512' })
  const online = servers.rows.filter((s) => s.status === 'online' || s.status === 'warning')
  const submit = () => {
    if (kind === 'database') {
      return run({ path: '/databases', label: 'Create database', success: `${f.name} is being created`,
        body: { name: f.name, engine: f.engine, version: f.version || null, server_id: f.server_id, memory_mb: Number(f.memory) || 512, project_id: projectId } }).then(onDone).catch(() => {})
    }
    const env = Object.fromEntries(f.env.split('\n').map((l) => l.trim()).filter((l) => l.includes('=')).map((l) => [l.slice(0, l.indexOf('=')), l.slice(l.indexOf('=') + 1)]))
    return run({ path: '/containers', label: 'Create container', success: `${f.name} is being created`,
      body: { name: f.name, image: f.image, server_id: f.server_id, env, network: 'internal', restart_policy: 'unless-stopped', limits: { memory_mb: Number(f.memory) || 512 }, project_id: projectId } }).then(onDone).catch(() => {})
  }
  return (
    <div className="flex flex-col gap-3 text-sm">
      <button type="button" className="btn self-start" onClick={onBack}>← Back</button>
      <div className="grid gap-3 sm:grid-cols-2">
        <label><span className="block text-xs text-muted mb-1">Name</span><input className="input" value={f.name} placeholder={kind === 'database' ? 'shop-db' : 'shop-worker'} onChange={(e) => setF({ ...f, name: e.target.value.toLowerCase() })} /></label>
        <label><span className="block text-xs text-muted mb-1">Server</span><Select className="input" value={f.server_id} onChange={(e) => setF({ ...f, server_id: e.target.value })}>
          <option value="">Choose a server…</option>{online.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</Select></label>
        {kind === 'database' ? <>
          <label><span className="block text-xs text-muted mb-1">Engine</span><Select className="input" value={f.engine} onChange={(e) => setF({ ...f, engine: e.target.value, version: '' })}>
            <option value="mysql">MySQL</option><option value="mariadb">MariaDB</option><option value="postgres">PostgreSQL</option><option value="mongodb">MongoDB</option><option value="redis">Redis</option></Select></label>
          <label><span className="block text-xs text-muted mb-1">Version</span><Select className="input" value={f.version} onChange={(e) => setF({ ...f, version: e.target.value })}>
            <option value="">Latest</option>{({ mysql: ['8.4', '8.0'], mariadb: ['11', '10.11'], postgres: ['16', '15'], mongodb: ['7'], redis: ['7'] } as Record<string, string[]>)[f.engine].map((v) => <option key={v} value={v}>{v}</option>)}</Select></label>
        </> : <>
          <label className="sm:col-span-2"><span className="block text-xs text-muted mb-1">Image</span><input className="input font-mono" placeholder="ghcr.io/acme/worker:1.2" value={f.image} onChange={(e) => setF({ ...f, image: e.target.value })} /></label>
          <label className="sm:col-span-2"><span className="block text-xs text-muted mb-1">Environment (KEY=value per line)</span><textarea className="input font-mono text-xs min-h-[80px]" value={f.env} onChange={(e) => setF({ ...f, env: e.target.value })} /></label>
        </>}
        <label><span className="block text-xs text-muted mb-1">Memory (MB)</span><input className="input" inputMode="numeric" value={f.memory} onChange={(e) => setF({ ...f, memory: e.target.value.replace(/\D/g, '') })} /></label>
      </div>
      <p className="m-0 text-xs text-muted">{kind === 'database' ? 'A password is generated and kept secret. Apps on the same server reach it on the private network by its host name db-<name>.' : 'It joins the private network so it can reach the project’s databases, and restarts automatically.'}</p>
      <div className="flex justify-end"><button type="button" className="btn btn-primary" disabled={busy || !f.name || !f.server_id || (kind === 'service' && !f.image)} onClick={submit}>{kind === 'database' ? 'Create database' : 'Create container'}</button></div>
    </div>
  )
}
