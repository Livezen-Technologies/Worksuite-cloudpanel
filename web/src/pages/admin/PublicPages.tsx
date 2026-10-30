import { useEffect, useState } from 'react'
import { api } from '../../api/client'
import { useAction, useOne } from '../../api/hooks'
import { Card, PageHeader } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { CopyButton } from '../../components/CopyButton'
import Select from '../../components/Select'
import type { R } from '../../lib/util'

const WHEN: Record<string, string> = {
  maintenance: 'Shown while maintenance mode is on for a site or the platform',
  busy: 'When an app is over its rate or capacity limit',
  not_found: 'When an app returns 404 with no page of its own',
  suspended: 'When a hosting customer is suspended',
  expired: 'When a package has expired; data kept for the grace period',
  welcome: 'When a domain points here but no site is set up yet',
}
const tone = (c: string) => (c.startsWith('2') ? 'g' : c.startsWith('4') ? 'y' : 'r') as 'g' | 'y' | 'r'

/** Hosting → Public pages (SRS PUB-1..4): branded maintenance and error pages served from the edge. */
export default function PublicPages() {
  const page = useOne<R>('/public-pages')
  const { run, busy } = useAction()
  const w = page.item ?? {}
  const [texts, setTexts] = useState<Record<string, R>>({})
  const [preview, setPreview] = useState<{ key: string; html: string } | null>(null)
  useEffect(() => { if (w.pages) setTexts(Object.fromEntries((w.pages as R[]).map((p) => [String(p.key), { title: p.title, message: p.message }]))) }, [JSON.stringify(w.pages)]) // eslint-disable-line react-hooks/exhaustive-deps
  const save = (body: R, label: string) => run({ path: '/public-pages', method: 'PATCH', body, label, success: 'Saved and published to the edge' }).then(() => page.refetch())
  const show = async (key: string) => setPreview({ key, html: (await api.get<{ data: { html: string } }>(`/public-pages/preview/${key}`)).data.html })

  const pages = (w.pages as R[]) ?? []
  const sites = (w.sites as R[]) ?? []
  const [sel, setSel] = useState('')
  const [scope, setScope] = useState('platform')
  const cur = pages.find((p) => String(p.key) === sel) ?? pages[0]
  const key = cur ? String(cur.key) : ''
  const t = texts[key] ?? {}
  const site = sites.find((s) => String(s.id) === scope)
  useEffect(() => { if (key) show(key).catch(() => setPreview(null)) }, [key]) // eslint-disable-line react-hooks/exhaustive-deps
  const inMaint = site ? !!site.maintenance : !!w.maintenance
  const toggleMaint = () => {
    if (site) return run({ path: `/public-pages/sites/${site.id}`, method: 'PATCH', body: { maintenance: !site.maintenance }, label: `Maintenance for ${site.name}` }).then(() => page.refetch())
    if (w.maintenance || window.confirm('Put every site on the platform into maintenance? Visitors get a 503 page; signed-in admins can bypass it with the link below.')) save({ maintenance: !w.maintenance }, 'Platform maintenance')
  }

  return (
    <>
      <PageHeader title="Public pages" subtitle="What visitors see when a site is down, missing, suspended or not set up yet — served from the edge, even when the app or server is down."
        actions={<Select className="input !w-60" aria-label="Scope" value={scope} onChange={(e) => setScope(e.target.value)}>
          <option value="platform">Platform default</option>
          {sites.map((s) => <option key={String(s.id)} value={String(s.id)}>{String(s.name)}</option>)}
        </Select>} />
      {page.item && !w.installed && <Card>
        <p className="m-0 mb-2 text-sm">The pages container is not running on the ingress servers yet, so visitors still see Traefik's plain errors.</p>
        <button type="button" className="btn btn-primary" disabled={busy} onClick={() => run({ path: '/public-pages/install', label: 'Install public pages', success: 'Installing on the ingress servers' }).then(() => page.refetch())}>Install on ingress servers</button>
      </Card>}

      <div className="grid gap-4 lg:grid-cols-[300px_minmax(0,1fr)] items-start">
        <div className="flex flex-col gap-2" role="listbox" aria-label="Page kinds">
          {pages.map((p) => {
            const on = String(p.key) === key
            return <button key={String(p.key)} type="button" role="option" aria-selected={on} onClick={() => setSel(String(p.key))}
              className="card text-left p-3 cursor-pointer border-l-4"
              style={{ borderLeftColor: on ? 'var(--accent)' : 'transparent', background: on ? 'var(--sel)' : undefined }}>
              <span className="flex items-center gap-2"><span className="font-semibold text-sm flex-1 capitalize">{String(p.key).replace(/_/g, ' ')}</span><Badge tone={tone(String(p.status))}>{String(p.status)}</Badge></span>
              {WHEN[String(p.key)] && <span className="block text-xs text-muted mt-1">{WHEN[String(p.key)]}</span>}
            </button>
          })}
        </div>

        <div className="flex flex-col gap-4 min-w-0">
          {cur && <Card title={<span className="capitalize">{key.replace(/_/g, ' ')} page</span>} subtitle={site ? `Page text is shared platform-wide; maintenance below applies to ${String(site.name)}.` : WHEN[key]}>
            <div className="flex flex-col gap-3 text-sm">
              <label className="flex flex-col gap-1">Title<input className="input" value={String(t.title ?? '')} onChange={(e) => setTexts({ ...texts, [key]: { ...t, title: e.target.value } })} /></label>
              <label className="flex flex-col gap-1">Message<textarea className="input min-h-20" value={String(t.message ?? '')} onChange={(e) => setTexts({ ...texts, [key]: { ...t, message: e.target.value } })} /></label>
              {!site && <div className="flex flex-wrap gap-3">
                <label className="flex flex-col gap-1">Retry-After (seconds)<input className="input w-32" type="number" defaultValue={Number(w.retry_after ?? 3600)} onBlur={(e) => Number(e.target.value) !== w.retry_after && save({ retry_after: Number(e.target.value) }, 'Retry-After')} /></label>
                <label className="flex flex-col gap-1">Grace period before "plan expired" (days)<input className="input w-32" type="number" defaultValue={Number(w.grace_days ?? 7)} onBlur={(e) => Number(e.target.value) !== w.grace_days && save({ grace_days: Number(e.target.value) }, 'Grace period')} /></label>
              </div>}
              <div className="flex flex-wrap gap-2">
                <button type="button" className="btn btn-primary" disabled={busy} onClick={() => save({ pages: texts }, 'Save pages').then(() => show(key))}>Save</button>
                <button type="button" className={inMaint ? 'btn btn-danger' : 'btn'} disabled={busy} onClick={toggleMaint}>
                  {inMaint ? 'Turn maintenance off' : 'Turn maintenance on'}{site ? '' : ' (platform)'}
                </button>
                {inMaint && <Badge tone="y">in maintenance</Badge>}
              </div>
              {w.bypass_query ? <p className="m-0 text-xs">Admin bypass: open any site in maintenance with <span className="font-mono">?{String(w.bypass_query)}</span> once; a cookie lets you through for 12 hours. <CopyButton value={`?${w.bypass_query}`} /></p> : null}
            </div>
          </Card>}

          {cur && <div className="card overflow-hidden">
            <div className="flex items-center gap-3 px-3 py-2 border-b border-line">
              <span className="flex gap-1.5" aria-hidden="true">{[0, 1, 2].map((i) => <span key={i} className="w-2.5 h-2.5 rounded-full bg-[var(--line)]" />)}</span>
              <span className="flex-1 min-w-0 truncate font-mono text-xs text-muted rounded px-2 py-1 border border-line">https://{site ? String(site.name) : 'your-site.example'}/</span>
              <span className="text-xs font-semibold text-muted">HTTP {String(cur.status)}</span>
            </div>
            {preview && preview.key === key ? <iframe title={`Preview of ${key}`} sandbox="" srcDoc={preview.html} className="block w-full h-96 border-0 bg-white" />
              : <p className="m-0 p-5 text-sm text-muted">Loading preview…</p>}
          </div>}
        </div>
      </div>

      <Card title="Sites">
        <ul className="m-0 p-0 list-none flex flex-col gap-2 text-sm">
          {sites.map((s) => <li key={String(s.id)} className="flex flex-wrap items-center gap-2">
            <span className="flex-1 min-w-0 break-all font-mono text-xs">{String(s.name)}</span>
            {s.showing ? <Badge tone={s.showing === 'welcome' ? 'n' : 'y'}>{String(s.showing)} page</Badge> : <Badge tone="g">live</Badge>}
            <label className="flex items-center gap-1"><input type="checkbox" checked={!!s.maintenance} disabled={busy} onChange={(e) => run({ path: `/public-pages/sites/${s.id}`, method: 'PATCH', body: { maintenance: e.target.checked }, label: `Maintenance for ${s.name}` }).then(() => page.refetch())} />maintenance</label>
          </li>)}
        </ul>
      </Card>
    </>
  )
}
