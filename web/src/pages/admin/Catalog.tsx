import appIcons from '../../lib/appIcons.json'
import { useMemo, useState, type ReactNode } from 'react'
import { useAction, useList } from '../../api/hooks'
import { Badge } from '../../components/Badge'
import { Card, PageHeader } from '../../components/Card'
import InstallWizard from './InstallWizard'

type Variable = { key: string; label: string; default: string | null; required: boolean; secret: boolean }
export type CatalogTemplate = Template
type Template = {
  id: string; name: string; category: string; type: 'image' | 'compose'; version: number; description: string
  image: string | null; port: number | null; accent: string | null; services: string[] | null; variables: Variable[]; generated: string[]
}
type Install = { id: string; template_id: string; name: string; version: number; latest_version: number | null; update_available: boolean; resource_type: string; resource_id: string }

const ICONS = appIcons as Record<string, string>

/** The app's own logo on a white tile (public/app-icons, see NOTICE.md); its initials when there is none. */
export function Logo({ t, size = 48 }: { t: Pick<Template, 'id' | 'name' | 'accent'>; size?: number }): ReactNode {
  const [broken, setBroken] = useState(false)
  const file = ICONS[t.id]
  if (file && !broken) {
    return (
      <span className="flex flex-none items-center justify-center rounded-xl bg-white ring-1 ring-inset ring-[var(--line)]" style={{ width: size, height: size }} aria-hidden="true">
        <img src={`/app-icons/${file}`} alt="" loading="lazy" decoding="async" className="object-contain" style={{ width: size * 0.66, height: size * 0.66 }} onError={() => setBroken(true)} />
      </span>
    )
  }
  return (
    <span className="flex flex-none items-center justify-center rounded-xl font-bold text-white" style={{ width: size, height: size, fontSize: size * 0.33, background: t.accent ?? 'var(--accent)' }} aria-hidden="true">
      {t.name.replace(/[^A-Za-z0-9]/g, '').slice(0, 2)}
    </span>
  )
}

/** TPL-1..6: the template catalogue from the API; each install becomes an app or a Compose stack. */
export default function Catalog() {
  const { run, busy } = useAction()
  const { rows: templates, isLoading } = useList<Template>('/templates')
  const installs = useList<Install>('/template-installs')
  const [q, setQ] = useState('')
  const [cat, setCat] = useState('')
  const [type, setType] = useState('')
  const [picked, setPicked] = useState<Template | null>(null)

  const categories = useMemo(() => [...new Set(templates.map((t) => t.category))].sort(), [templates])
  const shown = templates.filter((t) => (!cat || t.category === cat) && (!type || t.type === type)
    && (!q || `${t.name} ${t.description} ${t.image ?? ''} ${t.id}`.toLowerCase().includes(q.toLowerCase())))
  const updates = installs.rows.filter((i) => i.update_available)

  const choose = (t: Template) => {
    setPicked(t)
  }

  if (picked) return <InstallWizard t={picked} onBack={() => setPicked(null)} />

  return (
    <>
      <PageHeader title="Application catalogue" subtitle={`${templates.length} one-click templates: single images and multi-service stacks`}
        actions={<button type="button" className="btn" disabled={busy} onClick={() => run({ path: '/templates-sync', label: 'Sync templates', success: 'Templates synced' }).catch(() => {})}>Sync templates</button>} />
      {updates.length > 0 && (
        <Card title="Updates available">
          <ul className="list-none m-0 p-0">
            {updates.map((i) => (
              <li key={i.id} className="flex flex-wrap items-center gap-3 py-2 border-t border-line text-sm">
                <span className="font-semibold">{i.name}</span><span className="text-muted">{i.template_id} v{i.version} → v{i.latest_version}</span>
                <button type="button" className="btn btn-sm btn-primary ml-auto" disabled={busy} onClick={() => run({ path: `/template-installs/${i.id}/update`, label: `Update ${i.name}`, success: 'Update started' }).catch(() => {})}>Update</button>
              </li>
            ))}
          </ul>
        </Card>
      )}
      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center gap-3">
          <label className="relative grow min-w-[240px]">
            <span className="sr-only">Search templates</span>
            <svg aria-hidden="true" className="absolute left-3 top-1/2 -translate-y-1/2 text-muted" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
            <input className="input pl-9" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder={`Search ${templates.length} apps by name or description…`} />
          </label>
          <span className="btn-group" role="group" aria-label="Type">
            {([['', 'All'], ['image', 'Single image'], ['compose', 'Stack']] as const).map(([v, l]) => <button key={v} type="button" className={`btn ${type === v ? 'btn-primary' : ''}`} aria-pressed={type === v} onClick={() => setType(v)}>{l}</button>)}
          </span>
        </div>
        <div className="flex gap-2 overflow-x-auto pb-1 -mx-1 px-1" role="group" aria-label="Category">
          {['', ...categories].map((c) => {
            const n = c ? templates.filter((t) => t.category === c).length : templates.length
            return <button key={c || 'all'} type="button" aria-pressed={cat === c} onClick={() => setCat(c)} className="chip !min-h-8 !text-xs flex-none whitespace-nowrap">{c || 'All'} <span className="opacity-70 tabular-nums">{n}</span></button>
          })}
        </div>
        <p className="m-0 text-sm text-muted" aria-live="polite">{isLoading ? 'Loading templates…' : `Showing ${shown.length} of ${templates.length}`}</p>
      </div>
      {!isLoading && shown.length === 0 && <Card><p className="m-0 text-sm text-muted">Nothing matches “{q}”. Try another word or category.</p></Card>}
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr))]">
        {shown.map((t) => (
          <button key={t.id} type="button" onClick={() => choose(t)} className="card group text-left flex flex-col gap-3 p-4 cursor-pointer transition-[border-color,box-shadow] hover:border-accent hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
            <span className="flex items-start gap-3">
              <Logo t={t} />
              <span className="min-w-0 flex-1">
                <span className="block font-semibold truncate">{t.name}</span>
                <span className="block text-xs text-muted">{t.category ?? 'Other'}</span>
              </span>
            </span>
            <span className="text-sm text-muted line-clamp-2 min-h-10">{t.description}</span>
            <span className="mt-auto flex items-center gap-2 pt-1">
              {t.type === 'compose' ? <Badge tone="b">Stack · {t.services?.length ?? 1} services</Badge> : <Badge tone="n">Single image</Badge>}
              <span className="ml-auto text-sm font-semibold text-accent-fg opacity-0 group-hover:opacity-100 group-focus-visible:opacity-100 transition-opacity">Install →</span>
            </span>
          </button>
        ))}
      </div>
    </>
  )
}
