import { useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router'
import { useAction, useList } from '../../api/hooks'
import { Card, Field } from '../../components/Card'
import Select from '../../components/Select'

type Kind = 'wordpress' | 'php' | 'node' | 'static' | 'python' | 'proxy'

type Template = {
  kind: Kind
  title: string
  blurb: string
  image: string
  port: number
  health: string
  accent: string
  art: ReactNode
  available: boolean
}

/** Stack presets. Each maps onto an image-source Application the deploy pipeline already understands. */
const TEMPLATES: Template[] = [
  {
    kind: 'wordpress', title: 'WordPress Site', blurb: 'WordPress with Apache and PHP 8.',
    image: 'wordpress:6.7-apache', port: 80, health: '/', accent: '#21759B', available: true,
    art: <><rect x="6" y="10" width="36" height="26" rx="3" /><circle cx="24" cy="23" r="7" /><path d="M20 20l2.5 7 1.8-4.4L26 27l2.2-7" /></>,
  },
  {
    kind: 'php', title: 'PHP Site', blurb: 'PHP 8.3 with Apache, ready for any framework.',
    image: 'php:8.3-apache', port: 80, health: '/', accent: '#777BB4', available: true,
    art: <><rect x="4" y="14" width="40" height="18" rx="9" /><path d="M14 20v8M14 20h3a2 2 0 010 4h-3M22 18v12M22 24h4M26 18v12M32 20v8M32 20h3a2 2 0 010 4h-3" /></>,
  },
  {
    kind: 'node', title: 'Node.js Site', blurb: 'Node 22 on Alpine for JS and TypeScript apps.',
    image: 'node:22-alpine', port: 3000, health: '/', accent: '#5FA04E', available: true,
    art: <><path d="M24 6l16 9v18l-16 9-16-9V15z" /><path d="M24 18v12M19 21v6M29 21v6" /></>,
  },
  {
    kind: 'static', title: 'Static HTML Site', blurb: 'nginx serving plain HTML, CSS and JS.',
    image: 'nginx:1.27-alpine', port: 80, health: '/', accent: '#E34F26', available: true,
    art: <><rect x="6" y="9" width="36" height="30" rx="3" /><path d="M6 17h36" /><path d="M17 25l-4 4 4 4M31 25l4 4-4 4" /></>,
  },
  {
    kind: 'python', title: 'Python Site', blurb: 'Python 3.13 for Django, Flask or FastAPI.',
    image: 'python:3.13-slim', port: 8000, health: '/', accent: '#3776AB', available: true,
    art: <><path d="M24 6c-6 0-6 4-6 4v4h12v2H14s-4 0-4 8 4 8 4 8h4v-6s0-4 4-4h8s4 0 4-4V10s0-4-10-4z" /><circle cx="20" cy="11" r="1.4" /></>,
  },
  {
    kind: 'proxy', title: 'Reverse Proxy', blurb: 'Forward a domain to an upstream service.',
    image: '', port: 80, health: '/', accent: 'var(--muted)', available: false,
    art: <><rect x="5" y="12" width="18" height="24" rx="3" /><rect x="29" y="12" width="14" height="24" rx="3" /><path d="M23 20h6M23 28h6M27 18l2 2-2 2M25 26l-2 2 2 2" /></>,
  },
]

export default function NewSite() {
  const nav = useNavigate()
  const { run, busy } = useAction()
  const { data: servers } = useList<{ id: string; name: string }>('/servers')
  const [picked, setPicked] = useState<Template | null>(null)
  const [form, setForm] = useState({ name: '', domain: '', server_id: '', environment: 'production', size: 'small' })

  const create = async () => {
    if (!picked) return
    const res = await run<{ data?: { id: string } }>({
      path: '/applications',
      label: `Create ${picked.title}`,
      success: `${form.name} created`,
      body: {
        name: form.name,
        kind: picked.kind,
        source_type: 'image',
        image: picked.image,
        port: picked.port,
        health_path: picked.health,
        environment: form.environment,
        size: form.size,
        ...(form.server_id ? { server_id: form.server_id } : {}),
      },
    }).catch(() => null)
    const id = res?.data?.id
    if (!id) return
    if (form.domain.trim()) {
      await run({
        path: '/domains', label: `Attach ${form.domain}`, success: `${form.domain} attached`,
        body: { name: form.domain.trim(), application_id: id, target_port: picked.port },
      }).catch(() => {})
    }
    nav(`/app/apps/${id}`)
  }

  if (!picked) {
    return (
      <>
        <h1 className="m-0 text-center text-2xl font-bold tracking-tight sm:text-3xl">
          What kind of site would you like to create?
        </h1>
        <Card>
          <div className="grid gap-4 sm:grid-cols-2">
            {TEMPLATES.map((t) => (
              <div key={t.kind}
                   className={`flex flex-col items-center gap-5 rounded-xl border border-dashed border-line p-6 text-center transition-colors ${t.available ? 'hover:border-accent hover:bg-sel' : 'opacity-60'}`}>
                <svg viewBox="0 0 48 48" width="76" height="76" fill="none" stroke={t.accent}
                     strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                  {t.art}
                </svg>
                <div>
                  <p className="m-0 font-semibold">{t.title}</p>
                  <p className="m-0 mt-1 text-sm text-muted">{t.blurb}</p>
                </div>
                <button type="button" className="btn !rounded-full min-h-11" disabled={!t.available}
                        onClick={() => { setPicked(t); setForm((f) => ({ ...f, name: '', domain: '' })) }}>
                  {t.available ? `Create a ${t.title}` : 'Not available yet'}
                </button>
              </div>
            ))}
          </div>
        </Card>
      </>
    )
  }

  return (
    <>
      <div className="flex items-center gap-3">
        <button type="button" className="btn !rounded-full" onClick={() => setPicked(null)}>← Back</button>
        <h1 className="m-0 text-xl font-semibold tracking-tight">New {picked.title}</h1>
      </div>
      <Card>
        <form className="flex max-w-xl flex-col gap-4" onSubmit={(e) => { e.preventDefault(); create() }}>
          <Field label="Site name" hint="Lowercase letters, numbers and dashes.">
            <input className="input" required autoFocus value={form.name}
                   onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="my-site" />
          </Field>
          <Field label="Domain" hint="Optional. Point its A record at this server; the certificate is issued automatically.">
            <input className="input" value={form.domain} placeholder="example.com"
                   onChange={(e) => setForm({ ...form, domain: e.target.value })} />
          </Field>
          <Field label="Server" hint="Leave unset to let the scheduler place it.">
            <Select className="input" value={form.server_id} onChange={(e) => setForm({ ...form, server_id: e.target.value })}>
              <option value="">Automatic</option>
              {(servers?.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </Select>
          </Field>
          <Field label="Environment">
            <Select className="input" value={form.environment} onChange={(e) => setForm({ ...form, environment: e.target.value })}>
              <option value="production">production</option>
              <option value="staging">staging</option>
              <option value="development">development</option>
            </Select>
          </Field>
          <Field label="Size">
            <Select className="input" value={form.size} onChange={(e) => setForm({ ...form, size: e.target.value })}>
              <option value="small">Small</option>
              <option value="standard">Standard</option>
              <option value="pro">Pro</option>
            </Select>
          </Field>
          <p className="m-0 text-sm text-muted">
            Image <code className="font-mono">{picked.image}</code> · port {picked.port}
          </p>
          <button className="btn btn-primary !rounded-full min-h-11 self-start shadow-md" disabled={busy}>
            {busy ? 'Creating…' : `Create ${picked.title}`}
          </button>
        </form>
      </Card>
    </>
  )
}
