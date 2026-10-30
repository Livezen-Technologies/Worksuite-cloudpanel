import { useEffect, useState } from 'react'
import { api } from '../../api/client'
import { useAction, useOne } from '../../api/hooks'
import { Card, PageHeader } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { CopyButton } from '../../components/CopyButton'
import type { R } from '../../lib/util'

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

  return (
    <>
      <PageHeader title="Public pages" subtitle="Branded pages the edge shows for maintenance, errors, suspended sites and parked domains, even when the app or server is down." />
      {page.item && !w.installed && <Card>
        <p className="m-0 mb-2 text-sm">The pages container is not running on the ingress servers yet, so visitors still see Traefik's plain errors.</p>
        <button type="button" className="btn btn-primary" disabled={busy} onClick={() => run({ path: '/public-pages/install', label: 'Install public pages', success: 'Installing on the ingress servers' }).then(() => page.refetch())}>Install on ingress servers</button>
      </Card>}

      <Card title="Maintenance">
        <div className="flex flex-col gap-3 text-sm">
          <label className="flex items-center gap-2"><input type="checkbox" checked={!!w.maintenance} disabled={busy} onChange={(e) => { if (!e.target.checked || window.confirm('Put every site on the platform into maintenance? Visitors get a 503 page; signed-in admins can bypass it with the link below.')) save({ maintenance: e.target.checked }, 'Platform maintenance') }} /><span className="font-semibold">Platform-wide maintenance</span></label>
          <div className="flex flex-wrap gap-3">
            <label className="flex flex-col gap-1">Retry-After (seconds)<input className="input w-32" type="number" defaultValue={Number(w.retry_after ?? 3600)} onBlur={(e) => Number(e.target.value) !== w.retry_after && save({ retry_after: Number(e.target.value) }, 'Retry-After')} /></label>
            <label className="flex flex-col gap-1">Grace period before "plan expired" (days)<input className="input w-32" type="number" defaultValue={Number(w.grace_days ?? 7)} onBlur={(e) => Number(e.target.value) !== w.grace_days && save({ grace_days: Number(e.target.value) }, 'Grace period')} /></label>
          </div>
          {w.bypass_query ? <p className="m-0 text-xs">Admin bypass: open any site in maintenance with <span className="font-mono">?{String(w.bypass_query)}</span> once; a cookie lets you through for 12 hours. <CopyButton value={`?${w.bypass_query}`} /></p> : null}
        </div>
      </Card>

      <Card title="Pages">
        <ul className="m-0 p-0 list-none flex flex-col gap-4 text-sm">
          {((w.pages as R[]) ?? []).map((p) => {
            const t = texts[String(p.key)] ?? {}
            return <li key={String(p.key)} className="flex flex-col gap-1">
              <span className="flex items-center gap-2"><Badge>{String(p.status)}</Badge><span className="font-semibold">{String(p.key).replace('_', ' ')}</span>
                <button type="button" className="btn btn-sm ml-auto" onClick={() => show(String(p.key))}>Preview</button></span>
              <input className="input" value={String(t.title ?? '')} onChange={(e) => setTexts({ ...texts, [String(p.key)]: { ...t, title: e.target.value } })} />
              <textarea className="input min-h-16" value={String(t.message ?? '')} onChange={(e) => setTexts({ ...texts, [String(p.key)]: { ...t, message: e.target.value } })} />
            </li>
          })}
        </ul>
        <button type="button" className="btn btn-primary mt-3" disabled={busy} onClick={() => save({ pages: texts }, 'Save pages')}>Save pages</button>
        {preview && <iframe title={`Preview of ${preview.key}`} sandbox="" srcDoc={preview.html} className="mt-3 w-full h-80 border border-[var(--line)] rounded bg-white" />}
      </Card>

      <Card title="Sites">
        <ul className="m-0 p-0 list-none flex flex-col gap-2 text-sm">
          {((w.sites as R[]) ?? []).map((s) => <li key={String(s.id)} className="flex flex-wrap items-center gap-2">
            <span className="flex-1 font-mono text-xs">{String(s.name)}</span>
            {s.showing ? <Badge tone={s.showing === 'welcome' ? 'n' : 'y'}>{String(s.showing)} page</Badge> : <Badge tone="g">live</Badge>}
            <label className="flex items-center gap-1"><input type="checkbox" checked={!!s.maintenance} disabled={busy} onChange={(e) => run({ path: `/public-pages/sites/${s.id}`, method: 'PATCH', body: { maintenance: e.target.checked }, label: `Maintenance for ${s.name}` }).then(() => page.refetch())} />maintenance</label>
          </li>)}
        </ul>
      </Card>
    </>
  )
}
