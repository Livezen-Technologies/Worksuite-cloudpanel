import { useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router'
import { useAction, useList } from '../../api/hooks'
import { Card, Field, PageHeader } from '../../components/Card'
import Select from '../../components/Select'

type Entry = {
  slug: string
  name: string
  blurb: string
  category: 'CMS' | 'Runtime' | 'Database' | 'Tooling' | 'Custom'
  image: string
  port: number
  health: string
  env?: { key: string; value: string; hint?: string }[]
  note?: string
  accent: string
}

/** One-click installs. Each becomes an image-source Application the deploy pipeline already handles. */
const CATALOG: Entry[] = [
  {
    slug: 'wordpress', name: 'WordPress', blurb: 'The CMS behind most of the web.', category: 'CMS',
    image: 'wordpress:6.7-apache', port: 80, health: '/', accent: '#21759B',
    env: [
      { key: 'WORDPRESS_DB_HOST', value: '', hint: 'host:port of your MySQL/MariaDB' },
      { key: 'WORDPRESS_DB_NAME', value: 'wordpress' },
      { key: 'WORDPRESS_DB_USER', value: 'wordpress' },
      { key: 'WORDPRESS_DB_PASSWORD', value: '' },
    ],
    note: 'WordPress needs a MySQL or MariaDB database. Create one under Databases first, then fill these in.',
  },
  {
    slug: 'worksuite-cms', name: 'Worksuite CMS', blurb: 'Livezen’s own CMS.', category: 'CMS',
    image: '', port: 80, health: '/', accent: '#4F46E5',
    note: 'No public image yet — set the image to your registry path (and add the registry under Images & Registry if it is private).',
  },
  {
    slug: 'ghost', name: 'Ghost', blurb: 'Publishing and newsletters.', category: 'CMS',
    image: 'ghost:5-alpine', port: 2368, health: '/', accent: '#15171A',
    env: [{ key: 'url', value: 'http://localhost:2368', hint: 'public URL of the site' }],
  },
  {
    slug: 'nextcloud', name: 'Nextcloud', blurb: 'Files, calendar and contacts.', category: 'Tooling',
    image: 'nextcloud:30-apache', port: 80, health: '/status.php', accent: '#0082C9',
  },
  {
    slug: 'n8n', name: 'n8n', blurb: 'Workflow automation.', category: 'Tooling',
    image: 'n8nio/n8n:1.70.1', port: 5678, health: '/healthz', accent: '#EA4B71',
  },
  {
    slug: 'uptime-kuma', name: 'Uptime Kuma', blurb: 'Self-hosted uptime monitoring.', category: 'Tooling',
    image: 'louislam/uptime-kuma:1.23.16', port: 3001, health: '/', accent: '#5CDD8B',
  },
  {
    slug: 'mariadb', name: 'MariaDB', blurb: 'MySQL-compatible database.', category: 'Database',
    image: 'mariadb:11.8', port: 3306, health: '/', accent: '#C0765A',
    env: [
      { key: 'MARIADB_ROOT_PASSWORD', value: '' },
      { key: 'MARIADB_DATABASE', value: 'app' },
    ],
    note: 'A database has no HTTP health check — it will show as running rather than healthy.',
  },
  {
    slug: 'postgres', name: 'PostgreSQL', blurb: 'Relational database.', category: 'Database',
    image: 'postgres:18-alpine', port: 5432, health: '/', accent: '#336791',
    env: [{ key: 'POSTGRES_PASSWORD', value: '' }, { key: 'POSTGRES_DB', value: 'app' }],
  },
  {
    slug: 'redis', name: 'Redis', blurb: 'In-memory cache and queue.', category: 'Database',
    image: 'redis:8-alpine', port: 6379, health: '/', accent: '#DC382D',
  },
  {
    slug: 'custom', name: 'Custom Docker image', blurb: 'Install any image from a registry.', category: 'Custom',
    image: '', port: 80, health: '/', accent: '#64748B',
  },
]

const CATEGORIES = ['CMS', 'Runtime', 'Database', 'Tooling', 'Custom'] as const

function Logo({ e }: { e: Entry }): ReactNode {
  return (
    <span className="flex size-12 flex-none items-center justify-center rounded-xl text-base font-bold text-white"
          style={{ background: e.accent }} aria-hidden="true">
      {e.name.slice(0, 2)}
    </span>
  )
}

export default function Catalog() {
  const nav = useNavigate()
  const { run, busy } = useAction()
  const { data: servers } = useList<{ id: string; name: string }>('/servers')
  const [q, setQ] = useState('')
  const [cat, setCat] = useState<string>('')
  const [picked, setPicked] = useState<Entry | null>(null)
  const [form, setForm] = useState({ name: '', image: '', domain: '', server_id: '', environment: 'production', size: 'small' })

  const shown = CATALOG.filter((e) =>
    (!cat || e.category === cat) &&
    (!q || `${e.name} ${e.blurb} ${e.image}`.toLowerCase().includes(q.toLowerCase())))

  const choose = (e: Entry) => {
    setPicked(e)
    setForm({ name: e.slug === 'custom' ? '' : e.slug, image: e.image, domain: '', server_id: '', environment: 'production', size: 'small' })
  }

  const install = async () => {
    if (!picked) return
    const res = await run<{ data?: { id: string } }>({
      path: '/applications',
      label: `Install ${picked.name}`,
      success: `${form.name} created`,
      body: {
        name: form.name,
        kind: picked.slug,
        source_type: 'image',
        image: form.image,
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
      }).catch(() => {})   // the application exists either way; the domain can be retried on its tab
    }
    nav(`/admin/applications/${id}`)
  }

  if (picked) {
    return (
      <>
        <PageHeader title={`Install ${picked.name}`} subtitle={picked.blurb}
          actions={<button type="button" className="btn" onClick={() => setPicked(null)}>Back to catalogue</button>} />
        <Card>
          <form className="flex max-w-xl flex-col gap-4" onSubmit={(ev) => { ev.preventDefault(); install() }}>
            {picked.note && <p className="m-0 rounded-lg bg-y-bg px-3 py-2 text-sm text-y-fg">{picked.note}</p>}
            <Field label="Application name" hint="Lowercase letters, numbers and dashes.">
              <input className="input" required autoFocus value={form.name} placeholder="my-app"
                     onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </Field>
            <Field label="Docker image" hint="Pin a tag rather than using latest.">
              <input className="input font-mono text-xs" required value={form.image} placeholder="registry/image:tag"
                     onChange={(e) => setForm({ ...form, image: e.target.value })} />
            </Field>
            <Field label="Domain" hint="Optional. Point its A record at this server; the certificate is issued automatically.">
              <input className="input" value={form.domain} placeholder="example.com"
                     onChange={(e) => setForm({ ...form, domain: e.target.value })} />
            </Field>
            <Field label="Server" hint="Leave automatic to let the scheduler place it.">
              <Select className="input" value={form.server_id} onChange={(e) => setForm({ ...form, server_id: e.target.value })}>
                <option value="">Automatic</option>
                {(servers?.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </Select>
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Environment">
                <Select className="input" value={form.environment} onChange={(e) => setForm({ ...form, environment: e.target.value })}>
                  <option value="production">production</option><option value="staging">staging</option><option value="development">development</option>
                </Select>
              </Field>
              <Field label="Size">
                <Select className="input" value={form.size} onChange={(e) => setForm({ ...form, size: e.target.value })}>
                  <option value="small">Small</option><option value="standard">Standard</option><option value="pro">Pro</option>
                </Select>
              </Field>
            </div>
            {picked.env && picked.env.length > 0 && (
              <div>
                <p className="m-0 text-sm font-medium">Environment variables</p>
                <p className="m-0 mt-1 text-sm text-muted">
                  Set these on the application after it is created — {picked.env.map((v) => v.key).join(', ')}.
                </p>
              </div>
            )}
            <p className="m-0 text-sm text-muted">Container port {picked.port} · health check <code className="font-mono">{picked.health}</code></p>
            <button className="btn btn-primary min-h-11 self-start" disabled={busy}>
              {busy ? 'Installing…' : `Install ${picked.name}`}
            </button>
          </form>
        </Card>
      </>
    )
  }

  return (
    <>
      <PageHeader title="Application catalogue" subtitle="One-click installs, or bring your own Docker image" />
      <Card>
        <div className="flex flex-wrap items-center gap-3">
          <input className="input !w-auto grow" value={q} onChange={(e) => setQ(e.target.value)}
                 placeholder="Search applications…" aria-label="Search applications" />
          <Select className="input !w-auto" value={cat} onChange={(e) => setCat(e.target.value)} aria-label="Category">
            <option value="">All categories</option>
            {CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
          </Select>
        </div>
      </Card>
      <Card>
        {shown.length === 0 && <p className="m-0 text-sm text-muted">Nothing matches “{q}”.</p>}
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {shown.map((e) => (
            <div key={e.slug} className="flex flex-col gap-3 rounded-xl border border-line p-4 transition-colors hover:border-accent">
              <div className="flex items-start gap-3">
                <Logo e={e} />
                <div className="min-w-0">
                  <p className="m-0 font-semibold">{e.name}</p>
                  <p className="m-0 text-sm text-muted">{e.blurb}</p>
                </div>
              </div>
              <p className="m-0 truncate font-mono text-xs text-muted">{e.image || 'image not set'}</p>
              <button type="button" className="btn mt-auto" onClick={() => choose(e)}>Install</button>
            </div>
          ))}
        </div>
      </Card>
    </>
  )
}
