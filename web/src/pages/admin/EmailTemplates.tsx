import { useEffect, useState } from 'react'
import { api } from '../../api/client'
import { useAction, useOne } from '../../api/hooks'
import { Card, PageHeader, Toggle, Empty } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { useToast } from '../../components/Toasts'
import type { R } from '../../lib/util'

const FIELDS: [string, string, boolean][] = [
  ['subject', 'Subject', false], ['preview', 'Preview text', false], ['eyebrow', 'Eyebrow', false], ['heading', 'Heading', false], ['body', 'Body', true], ['code', 'Code block', true],
  ['button_label', 'Button label', false], ['button_url', 'Button link', false], ['secondary_label', 'Secondary link label', false], ['secondary_url', 'Secondary link', false],
]

/** Settings → Email templates (SRS EML): brand, the transactional emails, preview and test sends. */
export default function EmailTemplates() {
  const list = useOne<R>('/email-templates')
  const { run, busy } = useAction()
  const [key, setKey] = useState<string | null>(null)
  const [brand, setBrand] = useState<R>({})
  const [brandOpen, setBrandOpen] = useState(false)
  const [q, setQ] = useState('')
  useEffect(() => { if (list.item?.brand) setBrand(list.item.brand as R) }, [list.item])
  const groups = (list.item?.groups as R[]) ?? []
  const needle = q.trim().toLowerCase()
  const shown = groups
    .map((g) => ({ g, items: ((g.templates as R[]) ?? []).filter((t) => !needle || String(t.name).toLowerCase().includes(needle)) }))
    .filter((x) => x.items.length)
  // Open the first template once the list arrives, as the design shows an editor straight away.
  useEffect(() => { if (!key && groups.length) { const first = (groups[0].templates as R[])?.[0]; if (first) setKey(String(first.key)) } }, [groups, key])

  return (
    <div className="flex flex-col gap-4">
      <PageHeader title="Email templates" subtitle="Every email the platform sends to admins and customers. Edits apply to new emails only."
        actions={<button type="button" className={`btn ${brandOpen ? 'btn-primary' : ''}`} aria-expanded={brandOpen} onClick={() => setBrandOpen(!brandOpen)}>Branding &amp; sender</button>} />

      {brandOpen && (
        <Card>
          <div className="grid gap-3.5 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))] text-sm">
            <label className="flex flex-col gap-1 font-semibold text-[13px]">From name<input className="input font-normal" placeholder="WorkSuite Cloud" value={String(brand.sender_name ?? '')} onChange={(e) => setBrand({ ...brand, sender_name: e.target.value })} /></label>
            <label className="flex flex-col gap-1 font-semibold text-[13px]">Logo URL (https)<input className="input font-normal" value={String(brand.logo_url ?? '')} onChange={(e) => setBrand({ ...brand, logo_url: e.target.value })} /></label>
            <label className="flex flex-col gap-1 font-semibold text-[13px]">Brand colour<span className="flex gap-2"><input type="color" aria-label="Colour picker" className="h-10 w-12 rounded border border-line bg-card" value={String(brand.color ?? '#4F46E5')} onChange={(e) => setBrand({ ...brand, color: e.target.value.toUpperCase() })} /><input className="input flex-1 font-normal font-mono" value={String(brand.color ?? '')} onChange={(e) => setBrand({ ...brand, color: e.target.value })} /></span></label>
            <label className="flex flex-col gap-1 font-semibold text-[13px]">Footer text<input className="input font-normal" value={String(brand.footer ?? '')} onChange={(e) => setBrand({ ...brand, footer: e.target.value })} /></label>
          </div>
          <div className="flex flex-wrap items-center gap-3 mt-3.5">
            <button type="button" className="btn btn-primary" disabled={busy} onClick={() => run({ path: '/email-templates/brand', method: 'PATCH', body: { sender_name: brand.sender_name || null, logo_url: brand.logo_url || null, color: brand.color, footer: brand.footer || null }, label: 'Save brand', success: 'Brand saved' }).then(() => list.refetch())}>Save branding</button>
          </div>
        </Card>
      )}

      <div className="flex flex-wrap gap-3.5 items-start">
        <section aria-label="Templates" className="flex-[0_1_280px] min-w-[250px] max-w-full rounded-lg border border-line bg-card overflow-hidden">
          <div className="p-2.5 border-b border-line bg-head"><input className="input" aria-label="Search templates" placeholder="Search templates…" value={q} onChange={(e) => setQ(e.target.value)} /></div>
          {list.isLoading ? <p className="m-0 p-4 text-sm text-muted">Loading…</p>
            : shown.length === 0 ? <div className="p-4"><Empty>No templates match.</Empty></div>
            : <ul className="m-0 p-0 pb-2 list-none text-sm">
              {shown.map(({ g, items }) => (
                <li key={String(g.id)}>
                  <div className="px-3.5 pt-2.5 pb-1 text-[11px] font-bold tracking-wider uppercase text-muted">{String(g.name)}{g.mandatory ? ' · always sent' : ''}</div>
                  <ul className="m-0 p-0 list-none">
                    {items.map((t) => (
                      <li key={String(t.key)}>
                        <button type="button" onClick={() => setKey(String(t.key))} aria-current={key === t.key ? 'true' : undefined}
                          className={`w-full text-left px-3.5 py-2 min-h-[40px] flex items-center gap-2 cursor-pointer hover:bg-head ${key === t.key ? 'bg-[var(--sel)] font-semibold' : ''}`}>
                          <span className="flex-1 min-w-0 truncate">{String(t.name)}</span>
                          {!t.enabled ? <Badge>off</Badge> : t.customised ? <Badge tone="b">edited</Badge> : null}
                        </button>
                      </li>
                    ))}
                  </ul>
                </li>
              ))}
            </ul>}
        </section>
        {key ? <Editor key={key} id={key} onSaved={() => list.refetch()} />
          : <section className="flex-[1_1_380px] min-w-0"><Card><p className="m-0 text-sm text-muted">Choose a template to edit its copy, preview it and send a test.</p></Card></section>}
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
  const vars = (w.variables as string[]) ?? []
  const field = (k: string, label: string, opts: { mono?: boolean; multi?: boolean } = {}) => (
    <label key={k} className="flex flex-col gap-1 text-[13px] font-semibold">{label}
      {opts.multi ? <textarea rows={9} className="input min-h-40 font-normal font-mono text-xs" value={String(f[k] ?? '')} onChange={(e) => setF({ ...f, [k]: e.target.value })} />
        : <input className={`input font-normal ${opts.mono ? 'font-mono text-xs' : ''}`} value={String(f[k] ?? '')} onChange={(e) => setF({ ...f, [k]: e.target.value })} />}
    </label>
  )
  const enabled = w.enabled !== false

  return (
    <>
      <section className="flex-[1_1_380px] min-w-0 max-w-full rounded-lg border border-line bg-card flex flex-col">
        <div className="flex flex-wrap items-center gap-2 px-4 py-3 border-b border-line">
          <div className="flex-1 min-w-0">
            <div className="font-bold text-[15px] truncate">{String(w.name ?? 'Template')}</div>
            <div className="text-xs text-muted">{w.mandatory ? 'Always sent: security and billing emails cannot be turned off' : enabled ? 'Sent' : 'Not sent'}</div>
          </div>
          <Toggle checked={enabled} disabled={busy || !!w.mandatory} label="Enabled"
            onChange={(v) => run({ path: `/email-templates/${id}`, method: 'PATCH', body: { enabled: v }, label: v ? 'Turn on' : 'Turn off' }).then(() => { t.refetch(); onSaved() })} />
        </div>
        <div className="p-4 flex flex-col gap-3 text-sm">
          {field('subject', 'Subject')}
          {field('preview', 'Preheader')}
          {vars.length > 0 && <>
            <div className="text-[13px] font-semibold">Variables <span className="font-normal text-muted">· click to insert at the end of the body</span></div>
            <div className="flex flex-wrap gap-1.5">
              {vars.map((v) => (
                <button key={v} type="button" className="px-2 py-1 rounded-md border border-line bg-head font-mono text-xs cursor-pointer hover:bg-[var(--sel)]"
                  onClick={() => setF({ ...f, body: `${String(f.body ?? '')}{{${v}}}` })}>{`{{${v}}}`}</button>
              ))}
            </div>
          </>}
          {field('eyebrow', 'Eyebrow (small line above the heading)')}
          {field('heading', 'Heading')}
          {field('body', 'Body', { multi: true })}
          {field('code', 'Code block (log lines; leave empty for none)', { multi: true, mono: true })}
          <div className="grid gap-2.5 sm:grid-cols-2">
            {field('button_label', 'Button label')}
            {field('button_url', 'Button link', { mono: true })}
            {field('secondary_label', 'Secondary link label')}
            {field('secondary_url', 'Secondary link', { mono: true })}
          </div>
          <div className="flex flex-wrap gap-2 pt-1">
            <button type="button" className="btn btn-primary" disabled={busy} onClick={save}>Save template</button>
            <button type="button" className="btn" disabled={busy} onClick={() => run({ path: `/email-templates/${id}/test`, label: 'Send test', success: 'Test email sent to you' })}>Send test to me</button>
            <button type="button" className="btn btn-ghost" disabled={busy || !w.customised} onClick={() => run({ path: `/email-templates/${id}/reset`, label: 'Reset to default' }).then(() => { t.refetch(); onSaved(); refresh() })}>Reset to default</button>
          </div>
        </div>
      </section>

      <section className="flex-[1_1_380px] min-w-0 max-w-full rounded-lg border border-line bg-card overflow-hidden">
        <div className="flex items-center gap-2 px-3 py-2.5 border-b border-line bg-head">
          <span className="flex-1 text-[13px] font-semibold">Preview</span>
          <div role="group" aria-label="Preview mode" className="flex gap-1 p-[3px] rounded-lg bg-card border border-line">
            {(['desktop', 'mobile', 'text'] as const).map((m) => <button key={m} type="button" aria-pressed={mode === m} className={`btn btn-sm ${mode === m ? 'btn-primary' : 'btn-ghost'}`} onClick={() => setMode(m)}>{m === 'desktop' ? 'Desktop' : m === 'mobile' ? 'Mobile' : 'Plain text'}</button>)}
          </div>
        </div>
        {preview ? <>
          <div className="px-3.5 py-3 border-b border-line text-[12.5px] flex flex-col gap-0.5">
            <div><span className="text-muted">Subject</span> <b>{String(preview.subject)}</b></div>
            <div className="text-muted">{String(preview.preview)}</div>
          </div>
          <div className="p-3.5 bg-head flex justify-center overflow-x-auto">
            {mode === 'text'
              ? <pre className="m-0 w-full max-w-[560px] p-4 rounded-lg bg-[var(--code-bg)] text-[var(--code-fg)] text-xs whitespace-pre-wrap">{String(preview.text)}</pre>
              : <iframe title="Email preview" sandbox="" srcDoc={String(preview.html)} className="border border-line rounded bg-white max-w-full" style={{ width: mode === 'mobile' ? 360 : 600, height: 560 }} />}
          </div>
        </> : <p className="m-0 p-4 text-sm text-muted">Loading preview…</p>}
      </section>
    </>
  )
}
