import { useMemo, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router'
import { useAction, useList } from '../../api/hooks'
import { Badge } from '../../components/Badge'
import { Card, Field, PageHeader } from '../../components/Card'
import Select from '../../components/Select'

type Variable = { key: string; label: string; default: string | null; required: boolean; secret: boolean }
type Template = {
  id: string; name: string; category: string; type: 'image' | 'compose'; version: number; description: string
  image: string | null; port: number | null; accent: string | null; services: string[] | null; variables: Variable[]; generated: string[]
}
type Install = { id: string; template_id: string; name: string; version: number; latest_version: number | null; update_available: boolean; resource_type: string; resource_id: string }

function Logo({ t }: { t: Template }): ReactNode {
  return (
    <span className="flex size-12 flex-none items-center justify-center rounded-xl text-base font-bold text-white"
          style={{ background: t.accent ?? 'var(--accent)' }} aria-hidden="true">
      {t.name.slice(0, 2)}
    </span>
  )
}

/** TPL-1..6: the template catalogue from the API; each install becomes an app or a Compose stack. */
export default function Catalog() {
  const nav = useNavigate()
  const { run, busy } = useAction()
  const { rows: templates, isLoading } = useList<Template>('/templates')
  const { rows: servers } = useList<{ id: string; name: string }>('/servers')
  const installs = useList<Install>('/template-installs')
  const [q, setQ] = useState('')
  const [cat, setCat] = useState('')
  const [type, setType] = useState('')
  const [picked, setPicked] = useState<Template | null>(null)
  const [form, setForm] = useState({ name: '', domain: '', server_id: '', environment: 'production' })
  const [vars, setVars] = useState<Record<string, string>>({})

  const categories = useMemo(() => [...new Set(templates.map((t) => t.category))].sort(), [templates])
  const shown = templates.filter((t) => (!cat || t.category === cat) && (!type || t.type === type)
    && (!q || `${t.name} ${t.description} ${t.image ?? ''} ${t.id}`.toLowerCase().includes(q.toLowerCase())))
  const updates = installs.rows.filter((i) => i.update_available)

  const choose = (t: Template) => {
    setPicked(t)
    setForm({ name: t.id.slice(0, 40), domain: '', server_id: '', environment: 'production' })
    setVars(Object.fromEntries(t.variables.map((v) => [v.key, v.default ?? ''])))
  }

  const install = async () => {
    if (!picked) return
    const res = await run<{ data?: { resource_type: string; resource_id: string } }>({
      path: `/templates/${picked.id}/install`, label: `Install ${picked.name}`, success: `${form.name} installed`,
      body: { name: form.name, domain: form.domain.trim() || null, server_id: form.server_id || null, environment: form.environment, variables: vars },
    }).catch(() => null)
    const r = res?.data
    if (!r) return
    if (r.resource_type === 'application') {
      if (form.domain.trim() && picked.port) {
        await run({ path: '/domains', label: `Attach ${form.domain}`, success: `${form.domain} attached`, body: { name: form.domain.trim(), application_id: r.resource_id, target_port: picked.port } }).catch(() => {})
      }
      nav(`/admin/applications/${r.resource_id}`)
    } else {
      nav("/admin/compose")
    }
  }

  if (picked) {
    return (
      <>
        <PageHeader title={`Install ${picked.name}`} subtitle={picked.description}
          actions={<button type="button" className="btn" onClick={() => setPicked(null)}>Back to catalogue</button>} />
        <Card>
          <form className="flex max-w-xl flex-col gap-4" onSubmit={(ev) => { ev.preventDefault(); install() }}>
            <p className="m-0 text-sm text-muted">
              {picked.type === 'compose' ? <>Compose stack: {picked.services?.join(', ')}</> : <>Image <code className="font-mono">{picked.image}</code> · port {picked.port ?? '—'}</>}
              {picked.generated.length > 0 && <> · generates {picked.generated.length} secret{picked.generated.length > 1 ? 's' : ''} ({picked.generated.map((g) => g.replace(/^SERVICE_/, '').toLowerCase()).join(', ')})</>}
            </p>
            <Field label={picked.type === 'compose' ? 'Stack name' : 'Application name'} hint="Lowercase letters, numbers and dashes.">
              <input className="input" required autoFocus value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </Field>
            <Field label="Domain" hint="Optional. Used for the app's public URL; point its A record at the server.">
              <input className="input" value={form.domain} placeholder="app.example.com" onChange={(e) => setForm({ ...form, domain: e.target.value })} />
            </Field>
            <Field label="Server" hint={picked.type === 'compose' ? 'Required for a stack.' : 'Leave automatic to let the scheduler place it.'}>
              <Select className="input" value={form.server_id} onChange={(e) => setForm({ ...form, server_id: e.target.value })}>
                <option value="">{picked.type === 'compose' ? 'Choose a server' : 'Automatic'}</option>
                {servers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </Select>
            </Field>
            <Field label="Environment">
              <Select className="input" value={form.environment} onChange={(e) => setForm({ ...form, environment: e.target.value })}>
                <option value="production">production</option><option value="staging">staging</option><option value="development">development</option>
              </Select>
            </Field>
            {picked.variables.map((v) => (
              <Field key={v.key} label={`${v.label}${v.required ? ' *' : ''}`}>
                <input className="input font-mono text-xs" type={v.secret ? 'password' : 'text'} autoComplete="off" required={v.required}
                       value={vars[v.key] ?? ''} onChange={(e) => setVars({ ...vars, [v.key]: e.target.value })} />
              </Field>
            ))}
            <button className="btn btn-primary min-h-11 self-start" disabled={busy}>{busy ? 'Installing…' : `Install ${picked.name}`}</button>
          </form>
        </Card>
      </>
    )
  }

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
      <Card>
        <div className="flex flex-wrap items-center gap-3">
          <input className="input !w-auto grow" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search templates…" aria-label="Search templates" />
          <Select className="input !w-auto" value={cat} onChange={(e) => setCat(e.target.value)} aria-label="Category">
            <option value="">All categories</option>{categories.map((c) => <option key={c} value={c}>{c}</option>)}
          </Select>
          <Select className="input !w-auto" value={type} onChange={(e) => setType(e.target.value)} aria-label="Type">
            <option value="">Images and stacks</option><option value="image">Single image</option><option value="compose">Stack</option>
          </Select>
        </div>
      </Card>
      <Card>
        {isLoading && <p className="m-0 text-sm text-muted">Loading templates…</p>}
        {!isLoading && shown.length === 0 && <p className="m-0 text-sm text-muted">Nothing matches “{q}”.</p>}
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {shown.map((t) => (
            <div key={t.id} className="flex flex-col gap-3 rounded-xl border border-line p-4 transition-colors hover:border-accent">
              <div className="flex items-start gap-3">
                <Logo t={t} />
                <div className="min-w-0">
                  <p className="m-0 font-semibold flex items-center gap-2">{t.name}{t.type === 'compose' && <Badge tone="b">stack</Badge>}</p>
                  <p className="m-0 text-sm text-muted">{t.description}</p>
                </div>
              </div>
              <p className="m-0 truncate font-mono text-xs text-muted">{t.type === 'compose' ? t.services?.join(' + ') : t.image}</p>
              <button type="button" className="btn mt-auto" onClick={() => choose(t)}>Install</button>
            </div>
          ))}
        </div>
      </Card>
    </>
  )
}
