import { useState } from 'react'
import { Link } from 'react-router'
import { useAction, useOne } from '../../api/hooks'
import { Card, PageHeader } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { Modal } from '../../components/Overlay'
import type { R } from '../../lib/util'

const GRADIENT: Record<string, string> = {
  blue: 'linear-gradient(135deg,#3b82f6,#1d4ed8)', violet: 'linear-gradient(135deg,#8b5cf6,#6d28d9)', green: 'linear-gradient(135deg,#22c55e,#15803d)',
  amber: 'linear-gradient(135deg,#f59e0b,#b45309)', rose: 'linear-gradient(135deg,#f43f5e,#be123c)', teal: 'linear-gradient(135deg,#14b8a6,#0f766e)', slate: 'linear-gradient(135deg,#64748b,#334155)',
}

export const initials = (name: string) => {
  const w = name.trim().split(/\s+/).filter(Boolean)
  return (w.length > 1 ? w[0][0] + w[1][0] : name.trim().slice(0, 2)).toUpperCase()
}

function ProjectForm({ open, onClose, colors, initial, onSave, busy }: { open: boolean; onClose: () => void; colors: string[]; initial?: R; onSave: (b: R) => void; busy: boolean }) {
  const [name, setName] = useState(String(initial?.name ?? ''))
  const [color, setColor] = useState(String(initial?.color ?? 'blue'))
  return (
    <Modal open={open} onClose={onClose} title={initial ? 'Edit project' : 'New project'} subtitle="Group related apps, domains and databases together."
      footer={<><button type="button" className="btn" onClick={onClose}>Cancel</button><button type="button" className="btn btn-primary" disabled={busy || name.trim().length < 2} onClick={() => onSave({ name: name.trim(), color })}>{initial ? 'Save' : 'Create project'}</button></>}>
      <div className="flex flex-col gap-4">
        <label className="flex flex-col gap-1 text-sm">Project name<input className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Storefront" autoFocus /></label>
        <fieldset className="m-0 p-0 border-0"><legend className="text-sm mb-1">Colour</legend>
          <div className="flex gap-2">{colors.map((c) => (
            <button key={c} type="button" aria-label={c} aria-pressed={color === c} onClick={() => setColor(c)} className="w-8 h-8 rounded-full border-0 cursor-pointer"
              style={{ background: GRADIENT[c], boxShadow: color === c ? '0 0 0 2px var(--bg), 0 0 0 4px var(--accent)' : undefined }} />
          ))}</div>
        </fieldset>
      </div>
    </Modal>
  )
}

/** UPRJ: the customer's projects, each a card that opens to its apps. */
export default function UserProjects() {
  const page = useOne<R>('/app/projects')
  const { run, busy } = useAction()
  const [form, setForm] = useState<R | 'new' | null>(null)
  const [openId, setOpenId] = useState<string | null>(null)
  const [pick, setPick] = useState('')
  const w = page.item ?? {}
  const projects = (w.projects as R[]) ?? []
  const unassigned = (w.unassigned as R[]) ?? []
  const colors = (w.colors as string[]) ?? Object.keys(GRADIENT)
  const refetch = () => page.refetch()
  const cur = projects.find((p) => p.id === openId)

  const save = (b: R) => {
    const edit = form && form !== 'new' ? form : null
    run({ path: edit ? `/app/projects/${edit.id}` : '/app/projects', method: edit ? 'PATCH' : 'POST', body: b, label: edit ? 'Save project' : 'New project', success: edit ? 'Project saved' : 'Project created' })
      .then(() => { setForm(null); refetch() }).catch(() => {})
  }

  if (cur) {
    const apps = (cur.apps as R[]) ?? []
    return (
      <>
        <PageHeader title={String(cur.name)} subtitle={`${apps.length} app${apps.length === 1 ? '' : 's'}`}
          actions={<span className="flex gap-2">
            <button type="button" className="btn" onClick={() => setOpenId(null)}>All projects</button>
            <button type="button" className="btn" onClick={() => setForm(cur)}>Edit</button>
            <button type="button" className="btn btn-danger" disabled={busy} onClick={() => { if (window.confirm(`Delete ${cur.name}? Its apps stay, without a project.`)) run({ path: `/app/projects/${cur.id}`, method: 'DELETE', label: 'Delete project', success: 'Project deleted' }).then(() => { setOpenId(null); refetch() }).catch(() => {}) }}>Delete</button>
          </span>} />
        <Card title="Add an app">
          {unassigned.length === 0 ? <p className="m-0 text-sm text-muted">All your apps are already in a project. <Link to="/app/host">+ Host a new app</Link></p> : (
            <div className="flex flex-wrap gap-2">
              <select className="input flex-1 min-w-[180px]" aria-label="App to add" value={pick} onChange={(e) => setPick(e.target.value)}>
                <option value="">Choose an app…</option>
                {unassigned.map((a) => <option key={String(a.id)} value={String(a.id)}>{String(a.name)}</option>)}
              </select>
              <button type="button" className="btn btn-primary" disabled={busy || !pick} onClick={() => run({ path: `/app/projects/${cur.id}/apps`, body: { app_id: pick }, label: 'Add app', success: 'App added' }).then(() => { setPick(''); refetch() }).catch(() => {})}>+ Add app</button>
            </div>
          )}
        </Card>
        <Card title="Apps">
          {apps.length === 0 ? <p className="m-0 text-sm text-muted">This project is empty. Add your first app above.</p> : (
            <ul className="list-none m-0 p-0 flex flex-col">{apps.map((a) => (
              <li key={String(a.id)} className="flex items-center gap-3 py-2 border-t border-line first:border-t-0 text-sm">
                <Link className="flex-1 font-semibold" to={`/app/apps/${a.id}`}>{String(a.name)}</Link>
                <Badge tone={a.status === 'running' ? 'g' : 'n'}>{String(a.status ?? '—')}</Badge>
                <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/app/projects/${cur.id}/apps/${a.id}`, method: 'DELETE', label: 'Remove from project', success: 'Removed from project' }).then(refetch).catch(() => {})}>Remove</button>
              </li>
            ))}</ul>
          )}
        </Card>
        {form && <ProjectForm key={String((form as R).id ?? 'new')} open onClose={() => setForm(null)} colors={colors} initial={form === 'new' ? undefined : form} onSave={save} busy={busy} />}
      </>
    )
  }

  return (
    <>
      <PageHeader title="Projects" subtitle="Group your apps and their domains by product or client."
        actions={<button type="button" className="btn btn-primary" onClick={() => setForm('new')}>New project</button>} />
      {page.item && projects.length === 0 && <Card><p className="m-0 text-sm text-muted">No projects yet. Create one to group your apps.</p></Card>}
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]">
        {projects.map((p) => {
          const c = (p.counts ?? {}) as R
          const regions = (p.regions as string[]) ?? []
          return (
            <button key={String(p.id)} type="button" className="card p-4 text-left cursor-pointer text-fg flex flex-col gap-3 hover:border-[var(--muted)]" onClick={() => setOpenId(String(p.id))} aria-label={`Open ${p.name}`}>
              <span className="flex items-center gap-3">
                <span aria-hidden="true" className="grid place-items-center w-10 h-10 rounded-lg text-white font-bold text-sm" style={{ background: GRADIENT[String(p.color)] ?? GRADIENT.blue }}>{initials(String(p.name))}</span>
                <span className="min-w-0"><span className="block font-semibold truncate">{String(p.name)}</span><span className="block text-xs text-muted">{regions.length ? regions.join(', ') + ' · ' : ''}{Number(c.apps ?? 0) + Number(c.domains ?? 0)} resources</span></span>
              </span>
              <span className="flex gap-2 text-xs text-muted"><Badge tone="n">{Number(c.apps ?? 0)} apps</Badge><Badge tone="n">{Number(c.domains ?? 0)} domains</Badge></span>
            </button>
          )
        })}
      </div>
      {form && <ProjectForm key="new" open onClose={() => setForm(null)} colors={colors} onSave={save} busy={busy} />}
    </>
  )
}
