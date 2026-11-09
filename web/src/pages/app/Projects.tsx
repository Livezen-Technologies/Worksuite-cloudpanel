import { useState } from 'react'
import { Link } from 'react-router'
import { useAction, useOne } from '../../api/hooks'
import { Card, PageHeader } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { Modal } from '../../components/Overlay'
import type { R } from '../../lib/util'
import { ProjectCanvas } from '../../components/ProjectCanvas'
import { ProjectEnvironments } from './ProjectEnvironments'

const GRADIENT: Record<string, string> = {
  blue: 'linear-gradient(135deg,#3b82f6,#1d4ed8)', violet: 'linear-gradient(135deg,#8b5cf6,#6d28d9)', green: 'linear-gradient(135deg,#22c55e,#15803d)',
  amber: 'linear-gradient(135deg,#f59e0b,#b45309)', rose: 'linear-gradient(135deg,#f43f5e,#be123c)', teal: 'linear-gradient(135deg,#14b8a6,#0f766e)', slate: 'linear-gradient(135deg,#64748b,#334155)',
}

export const initials = (name: string) => {
  const w = name.trim().split(/\s+/).filter(Boolean)
  return (w.length > 1 ? w[0][0] + w[1][0] : name.trim().slice(0, 2)).toUpperCase()
}

/** Editing an existing project. New ones go through NewProjectWizard. */
function ProjectForm({ open, onClose, colors, initial, onSave, busy }: { open: boolean; onClose: () => void; colors: string[]; initial: R; onSave: (b: R) => void; busy: boolean }) {
  const [name, setName] = useState(String(initial.name ?? ''))
  const [color, setColor] = useState(String(initial.color ?? 'blue'))
  const [description, setDescription] = useState(String(initial.description ?? ''))
  return (
    <Modal open={open} onClose={onClose} title="Edit project" subtitle="Group related apps, domains and databases together."
      footer={<><button type="button" className="btn" onClick={onClose}>Cancel</button><button type="button" className="btn btn-primary" disabled={busy || name.trim().length < 2} onClick={() => onSave({ name: name.trim(), color, description: description.trim() })}>Save</button></>}>
      <div className="flex flex-col gap-4">
        <label className="flex flex-col gap-1 text-sm">Project name<input className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Storefront" autoFocus /></label>
        <label className="flex flex-col gap-1 text-sm">Description<textarea className="input" rows={2} maxLength={255} value={description} onChange={(e) => setDescription(e.target.value)} /></label>
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


/** Human wording for the purposes the API accepts; the API stores the key. */
const PURPOSE_LABELS: Record<string, string> = {
  trying_out: 'Just trying things out', class_project: 'Class project or learning', website_blog: 'Website or blog',
  web_application: 'Web application', service_api: 'Service or API', mobile_application: 'Mobile application', other: 'Something else',
}

/**
 * Creating a project in two steps: what it is, then what goes in it. The second
 * step only has anything to offer when the customer has apps sitting outside a
 * project, so it is skipped entirely when they do not.
 */
function NewProjectWizard({ open, onClose, colors, purposes, envTypes, unassigned, onDone }:
  { open: boolean; onClose: () => void; colors: string[]; purposes: string[]; envTypes: Record<string, string>; unassigned: R[]; onDone: () => void }) {
  const { run, busy } = useAction()
  const [step, setStep] = useState<1 | 2>(1)
  const [created, setCreated] = useState<R | null>(null)
  const [name, setName] = useState('')
  const [color, setColor] = useState('blue')
  const [environment, setEnvironment] = useState('development')
  const [purpose, setPurpose] = useState('')
  const [description, setDescription] = useState('')
  const [chosen, setChosen] = useState<string[]>([])

  const create = () => {
    run<{ data: R }>({ path: '/app/projects', method: 'POST', label: 'New project', success: 'Project created',
      body: { name: name.trim(), color, environment, ...(purpose ? { purpose } : {}), ...(description.trim() ? { description: description.trim() } : {}) } })
      .then((res) => {
        const row = res?.data
        setCreated(row)
        // Nothing to assign means the second step would be an empty page.
        if (unassigned.length === 0) { onDone(); onClose() } else setStep(2)
      }).catch(() => {})
  }
  const assign = async () => {
    for (const id of chosen) {
      // One at a time: the endpoint takes a single app and returns the project.
      await run({ path: `/app/projects/${created?.id}/apps`, method: 'POST', body: { app_id: id }, label: 'Add app', silent: true }).catch(() => {})
    }
    onDone(); onClose()
  }

  const footer = step === 1
    ? (<><button type="button" className="btn" onClick={onClose}>Cancel</button>
        <button type="button" className="btn btn-primary" disabled={busy || name.trim().length < 2} onClick={create}>Create project</button></>)
    : (<><button type="button" className="btn" onClick={() => { onDone(); onClose() }} disabled={busy}>Skip for now</button>
        <button type="button" className="btn btn-primary" disabled={busy} onClick={assign}>{chosen.length ? `Add ${chosen.length} app${chosen.length > 1 ? 's' : ''}` : 'Finish'}</button></>)

  return (
    <Modal open={open} onClose={onClose} title="Create new project"
      subtitle={step === 1 ? 'Group related apps, domains and databases together.' : `Choose what to move into ${String(created?.name ?? 'the project')}.`}
      footer={footer}>
      <ol className="mb-5 mt-0 flex list-none gap-5 p-0 text-sm">
        {(['Create project', 'Assign resources'] as const).map((t, i) => (
          <li key={t} className={`flex items-center gap-2 ${step === i + 1 ? 'font-semibold text-fg' : 'text-muted'}`} aria-current={step === i + 1 ? 'step' : undefined}>
            <span className={`grid h-6 w-6 flex-none place-content-center rounded-full text-xs ${step === i + 1 ? 'bg-accent text-white' : 'bg-head text-muted'}`}>{i + 1}</span>
            {t}
          </li>
        ))}
      </ol>

      {step === 1 ? (
        <div className="flex flex-col gap-4">
          <label className="flex flex-col gap-1 text-sm">Project name<input className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Storefront" autoFocus /></label>
          <label className="flex flex-col gap-1 text-sm">Environment type
            <select className="input" value={environment} onChange={(e) => setEnvironment(e.target.value)}>
              {Object.entries(envTypes).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </label>
          <label className="flex flex-col gap-1 text-sm">What is it for?
            <select className="input" value={purpose} onChange={(e) => setPurpose(e.target.value)}>
              <option value="">Prefer not to say</option>
              {purposes.map((p) => <option key={p} value={p}>{PURPOSE_LABELS[p] ?? p}</option>)}
            </select>
            <span className="text-[13px] text-muted">Optional. It helps us suggest the right things later.</span>
          </label>
          <label className="flex flex-col gap-1 text-sm">Description
            <textarea className="input" rows={2} maxLength={255} value={description} onChange={(e) => setDescription(e.target.value)}
              placeholder="Helpful when several projects have similar names." />
          </label>
          <fieldset className="m-0 border-0 p-0"><legend className="mb-1 text-sm">Colour</legend>
            <div className="flex gap-2">{colors.map((c) => (
              <button key={c} type="button" aria-label={c} aria-pressed={color === c} onClick={() => setColor(c)} className="h-8 w-8 cursor-pointer rounded-full border-0"
                style={{ background: GRADIENT[c], boxShadow: color === c ? '0 0 0 2px var(--bg), 0 0 0 4px var(--accent)' : undefined }} />
            ))}</div>
          </fieldset>
        </div>
      ) : (
        <ul className="m-0 flex list-none flex-col gap-1 p-0">
          {unassigned.map((a) => (
            <li key={String(a.id)}>
              <label className="flex min-h-11 items-center gap-2.5 rounded-lg px-2 text-sm hover:bg-head">
                <input type="checkbox" checked={chosen.includes(String(a.id))}
                  onChange={(e) => setChosen((v) => (e.target.checked ? [...v, String(a.id)] : v.filter((x) => x !== String(a.id))))} />
                <span className="flex-1 truncate">{String(a.name)}</span>
                <Badge tone="n">{String(a.status)}</Badge>
              </label>
            </li>
          ))}
        </ul>
      )}
    </Modal>
  )
}

/** UPRJ: the customer's projects, each a card that opens to its apps. */
export default function UserProjects() {
  const page = useOne<R>('/app/projects')
  const { run, busy } = useAction()
  const [form, setForm] = useState<R | null>(null)
  const [creating, setCreating] = useState(false)
  const [openId, setOpenId] = useState<string | null>(null)
  const [pick, setPick] = useState('')
  const [view, setView] = useState<'list' | 'canvas' | 'environments'>('canvas')
  const w = page.item ?? {}
  const projects = (w.projects as R[]) ?? []
  const unassigned = (w.unassigned as R[]) ?? []
  const colors = (w.colors as string[]) ?? Object.keys(GRADIENT)
  const purposes = (w.purposes as string[]) ?? []
  // Falls back so the wizard still works against an API that predates it.
  const envTypes = (w.environment_types as Record<string, string>) ?? { development: 'Development', staging: 'Staging', production: 'Production' }
  const refetch = () => page.refetch()
  const cur = projects.find((p) => p.id === openId)

  const save = (b: R) => {
    if (!form) return
    run({ path: `/app/projects/${form.id}`, method: 'PATCH', body: b, label: 'Save project', success: 'Project saved' })
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
        <div role="tablist" aria-label="Project view" className="flex gap-1 border-b border-line">
          {(['canvas', 'list', 'environments'] as const).map((v) => <button key={v} role="tab" type="button" aria-selected={view === v} onClick={() => setView(v)}
            className={`h-10 px-3.5 border-0 bg-transparent cursor-pointer text-sm ${view === v ? 'font-semibold' : 'text-muted'}`}
            style={view === v ? { boxShadow: 'inset 0 -2px 0 var(--accent)' } : undefined}>{v === 'canvas' ? 'Canvas' : v === 'list' ? 'Apps' : 'Environments'}</button>)}
        </div>
        {view === 'canvas' && <Card><ProjectCanvas projectId={String(cur.id)} /></Card>}
        {view === 'environments' && <ProjectEnvironments projectId={String(cur.id)} />}
        {view === 'list' && <>
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
        </>}
        {form && <ProjectForm key={String(form.id)} open onClose={() => setForm(null)} colors={colors} initial={form} onSave={save} busy={busy} />}
      </>
    )
  }

  return (
    <>
      <PageHeader title="Projects" subtitle="Group your apps and their domains by product or client."
        actions={<button type="button" className="btn btn-primary" onClick={() => setCreating(true)}>New project</button>} />
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
              <span className="flex flex-wrap gap-2 text-xs text-muted items-center"><Badge tone="n">{Number(c.apps ?? 0)} apps</Badge><Badge tone="n">{Number(c.domains ?? 0)} domains</Badge>
                {((p.environments as string[]) ?? []).map((e) => <Badge key={e} tone={e === 'production' ? 'g' : 'b'}>{e[0].toUpperCase() + e.slice(1)}</Badge>)}
                <span className="ml-auto font-semibold text-fg">${(Number(p.cost_cents ?? 0) / 100).toFixed(2)}/mo</span></span>
            </button>
          )
        })}
      </div>
      {creating && <NewProjectWizard open onClose={() => setCreating(false)} colors={colors} purposes={purposes} envTypes={envTypes} unassigned={unassigned} onDone={refetch} />}
    </>
  )
}
