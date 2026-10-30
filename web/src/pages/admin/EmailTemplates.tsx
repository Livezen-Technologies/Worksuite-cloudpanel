import { useEffect, useState } from 'react'
import { api } from '../../api/client'
import { useAction, useOne } from '../../api/hooks'
import { Card } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { useToast } from '../../components/Toasts'
import type { R } from '../../lib/util'

const FIELDS: [string, string, boolean][] = [
  ['subject', 'Subject', false], ['preview', 'Preview text', false], ['heading', 'Heading', false], ['body', 'Body', true],
  ['button_label', 'Button label', false], ['button_url', 'Button link', false], ['secondary_label', 'Secondary link label', false], ['secondary_url', 'Secondary link', false],
]

/** Settings → Email templates (SRS EML): brand, the 20 transactional emails, preview and test sends. */
export default function EmailTemplates() {
  const list = useOne<R>('/email-templates')
  const { run, busy } = useAction()
  const [key, setKey] = useState<string | null>(null)
  const [brand, setBrand] = useState<R>({})
  useEffect(() => { if (list.item?.brand) setBrand(list.item.brand as R) }, [list.item])
  const groups = (list.item?.groups as R[]) ?? []

  return (
    <div className="flex flex-col gap-4">
      <Card title="Brand">
        <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))] text-sm">
          <label className="flex flex-col gap-1">Sender name<input className="input" placeholder="WorkSuite Cloud" value={String(brand.sender_name ?? '')} onChange={(e) => setBrand({ ...brand, sender_name: e.target.value })} /></label>
          <label className="flex flex-col gap-1">Logo URL (https)<input className="input" value={String(brand.logo_url ?? '')} onChange={(e) => setBrand({ ...brand, logo_url: e.target.value })} /></label>
          <label className="flex flex-col gap-1">Colour<span className="flex gap-2"><input type="color" aria-label="Colour picker" value={String(brand.color ?? '#4F46E5')} onChange={(e) => setBrand({ ...brand, color: e.target.value.toUpperCase() })} /><input className="input flex-1" value={String(brand.color ?? '')} onChange={(e) => setBrand({ ...brand, color: e.target.value })} /></span></label>
          <label className="flex flex-col gap-1 col-span-full">Footer<input className="input" value={String(brand.footer ?? '')} onChange={(e) => setBrand({ ...brand, footer: e.target.value })} /></label>
        </div>
        <button type="button" className="btn btn-primary mt-3" disabled={busy} onClick={() => run({ path: '/email-templates/brand', method: 'PATCH', body: { sender_name: brand.sender_name || null, logo_url: brand.logo_url || null, color: brand.color, footer: brand.footer || null }, label: 'Save brand', success: 'Brand saved' }).then(() => list.refetch())}>Save brand</button>
      </Card>

      <div className="grid gap-4 lg:[grid-template-columns:300px_1fr]">
        <Card title="Templates" pad={false}>
          <ul className="m-0 p-0 list-none text-sm">
            {groups.map((g) => (
              <li key={String(g.id)}>
                <div className="px-4 pt-3 pb-1 text-xs font-semibold text-muted uppercase tracking-wide">{String(g.name)}{g.mandatory ? ' · always sent' : ''}</div>
                <ul className="m-0 p-0 list-none">
                  {(g.templates as R[]).map((t) => (
                    <li key={String(t.key)}>
                      <button type="button" onClick={() => setKey(String(t.key))} className={`w-full text-left px-4 py-2 flex items-center gap-2 ${key === t.key ? 'bg-[var(--sel)]' : ''}`}>
                        <span className="flex-1">{String(t.name)}</span>
                        {t.customised ? <Badge tone="b">edited</Badge> : null}
                        {!t.enabled ? <Badge>off</Badge> : null}
                      </button>
                    </li>
                  ))}
                </ul>
              </li>
            ))}
          </ul>
        </Card>
        {key ? <Editor key={key} id={key} onSaved={() => list.refetch()} /> : <Card><p className="m-0 text-sm text-muted">Choose a template to edit its copy, preview it and send a test.</p></Card>}
      </div>
    </div>
  )
}

function Editor({ id, onSaved }: { id: string; onSaved: () => void }) {
  const t = useOne<R>(`/email-templates/${id}`)
  const { run, busy } = useAction()
  const toast = useToast()
  const [f, setF] = useState<R>({})
  const [preview, setPreview] = useState<R | null>(null)
  const [mode, setMode] = useState<'desktop' | 'mobile' | 'text'>('desktop')
  const w = t.item ?? {}
  useEffect(() => { if (t.item) setF(Object.fromEntries(FIELDS.map(([k]) => [k, t.item?.[k] ?? '']))) }, [t.item])
  const refresh = () => api.post<{ data: R }>(`/email-templates/${id}/preview`).then((r) => setPreview(r.data)).catch((e) => toast.error(e, 'Preview failed'))
  useEffect(() => { refresh() }, [id]) // eslint-disable-line react-hooks/exhaustive-deps
  const save = () => run({ path: `/email-templates/${id}`, method: 'PATCH', body: f, label: 'Save template', success: 'Template saved' }).then(() => { t.refetch(); onSaved(); refresh() })

  return (
    <Card title={String(w.name ?? 'Template')}>
      <div className="flex flex-col gap-3 text-sm">
        <p className="m-0 text-xs text-muted">Variables: {((w.variables as string[]) ?? []).map((v) => `{{${v}}}`).join(' ')}</p>
        {FIELDS.map(([k, label, multi]) => (
          <label key={k} className="flex flex-col gap-1">{label}
            {multi ? <textarea className="input min-h-28 font-mono text-xs" value={String(f[k] ?? '')} onChange={(e) => setF({ ...f, [k]: e.target.value })} />
              : <input className="input" value={String(f[k] ?? '')} onChange={(e) => setF({ ...f, [k]: e.target.value })} />}
          </label>
        ))}
        <div className="flex flex-wrap items-center gap-2">
          <button type="button" className="btn btn-primary" disabled={busy} onClick={save}>Save</button>
          <button type="button" className="btn" disabled={busy || !w.customised} onClick={() => run({ path: `/email-templates/${id}/reset`, label: 'Reset to default' }).then(() => { t.refetch(); onSaved(); refresh() })}>Reset to default</button>
          <button type="button" className="btn" disabled={busy} onClick={() => run({ path: `/email-templates/${id}/test`, label: 'Send test', success: 'Test email sent to you' })}>Send test to me</button>
          <label className="flex items-center gap-2 ml-auto">
            <input type="checkbox" disabled={busy || !!w.mandatory} checked={w.enabled !== false} onChange={(e) => run({ path: `/email-templates/${id}`, method: 'PATCH', body: { enabled: e.target.checked }, label: e.target.checked ? 'Turn on' : 'Turn off' }).then(() => { t.refetch(); onSaved() })} />
            {w.mandatory ? 'Always sent (security and billing)' : 'Sent'}
          </label>
        </div>

        <div className="flex gap-2 mt-2">
          {(['desktop', 'mobile', 'text'] as const).map((m) => <button key={m} type="button" className={`btn btn-sm ${mode === m ? 'btn-primary' : ''}`} onClick={() => setMode(m)}>{m === 'desktop' ? '600 px' : m === 'mobile' ? '360 px' : 'Plain text'}</button>)}
        </div>
        {preview && <div>
          <p className="m-0 mb-1 text-xs"><span className="font-semibold">{String(preview.subject)}</span> <span className="text-muted">— {String(preview.preview)}</span></p>
          {mode === 'text'
            ? <pre className="m-0 p-3 rounded bg-[var(--code-bg)] text-[var(--code-fg)] text-xs whitespace-pre-wrap">{String(preview.text)}</pre>
            : <iframe title="Email preview" sandbox="" srcDoc={String(preview.html)} className="border border-[var(--line)] rounded bg-white" style={{ width: mode === 'mobile' ? 360 : '100%', maxWidth: 680, height: 560 }} />}
        </div>}
      </div>
    </Card>
  )
}
