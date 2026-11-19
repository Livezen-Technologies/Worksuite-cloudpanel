import { useMemo, useState } from 'react'
import { useAction, useList } from '../api/hooks'
import { Modal } from './Overlay'
import { Badge } from './Badge'
import Select from './Select'
import InstallWizard from '../pages/admin/InstallWizard'
import NewApplicationWizard from '../pages/admin/NewApplicationWizard'
import type { R } from '../lib/util'

type Step = 'choose' | 'catalog' | 'existing'

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
        <div className="grid gap-3 sm:grid-cols-3">
          {([
            ['catalog', 'From the App Catalogue', 'WordPress, databases, tools… installed and set up for you.'],
            ['git', 'From Git or a Docker image', 'Your own code or image, with build, domain and resources.'],
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
