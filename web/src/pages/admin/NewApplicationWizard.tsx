import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router'
import { api } from '../../api/client'
import { useAction, useList } from '../../api/hooks'
import type { Server } from '../../api/types'
import { Field, Toggle } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { Icon } from '../../components/Icon'
import { Modal } from '../../components/Overlay'
import Select from '../../components/Select'
import { type R } from '../../lib/util'
import { mergeEnv, parseDotEnv, type EnvRow } from './InstallWizard'

type SourceType = 'git' | 'image' | 'dockerfile' | 'compose'
type DomainMode = 'auto' | 'subdomain' | 'custom' | 'none'
type Check = { key: string; step: 'source' | 'build' | 'deploy'; status: 'pass' | 'warn' | 'fail'; label: string; message: string }
type Preflight = { ok: boolean; failed: number; warnings: number; checks: Check[]; detected?: { commit?: string; server?: { id: string; name: string } }; platform_domain?: string }
type KV = { key: string; value: string }

const STEPS = [
  { id: 'source', label: 'Source & repository', hint: 'Where the code or image comes from' },
  { id: 'build', label: 'Build & image', hint: 'How the image is built' },
  { id: 'deploy', label: 'Deploy & runtime', hint: 'Server, address, health and variables' },
  { id: 'review', label: 'Review & create', hint: 'Check everything and deploy' },
] as const

const SOURCES: { id: SourceType; title: string; text: string; icon: string }[] = [
  { id: 'git', title: 'Git repository', text: 'GitHub · GitLab · Gitea', icon: 'share' },
  { id: 'image', title: 'Docker image', text: 'Public or private registry', icon: 'box' },
  { id: 'dockerfile', title: 'Dockerfile', text: 'Build from source, isolated runner', icon: 'file' },
  { id: 'compose', title: 'Compose stack', text: 'Multi-service application', icon: 'layers' },
]
const STRATEGIES = [['rolling', 'Rolling update', 'Replace instances one by one'], ['recreate', 'Recreate', 'Simple; brief downtime'], ['blue_green', 'Blue-green', 'Switch traffic after health checks']] as const

const NAME_RE = /^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/
const DOMAIN_RE = /^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i
const PATH_RE = /^\/\S*$/
const KEY_RE = /^[A-Za-z_][A-Za-z0-9_]*$/
/** Common images and the port they listen on. */
const KNOWN_PORTS: [RegExp, number][] = [[/(^|\/)(nginx|httpd|caddy|traefik\/whoami)(:|$)/, 80], [/(^|\/)(node|next|nuxt|remix)/, 3000], [/(^|\/)(python|django|flask|fastapi|uvicorn)/, 8000], [/(^|\/)(grafana)/, 3000], [/(^|\/)(ghost)/, 2368], [/(^|\/)(wordpress|php)/, 80], [/(^|\/)(golang|go)/, 8080], [/(^|\/)(redis)/, 6379]]

/** A DNS-safe app name from a repository URL or image reference. */
export function suggestName(source: string): string {
  const last = source.trim().replace(/\.git$/, '').replace(/[:@].*$/, (m) => (m.startsWith(':') && !m.includes('/') ? '' : m)).split('/').filter(Boolean).pop() ?? ''
  return last.toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40)
}
export function guessPort(image: string): number | null {
  for (const [re, port] of KNOWN_PORTS) if (re.test(image.toLowerCase())) return port
  return null
}

function Choice({ on, onClick, title, text, icon }: { on: boolean; onClick: () => void; title: string; text: string; icon?: string }) {
  return (
    <button type="button" aria-pressed={on} onClick={onClick}
      className={`relative card p-4 text-left cursor-pointer text-fg flex flex-col gap-1.5 transition-colors ${on ? '' : 'hover:border-[var(--muted)]'}`}
      style={on ? { borderColor: 'var(--accent)', boxShadow: '0 0 0 1px var(--accent)', background: 'var(--sel)' } : undefined}>
      {icon && <span aria-hidden="true" className="grid place-items-center w-9 h-9 rounded-lg bg-head" style={{ color: 'var(--accent)' }}><Icon name={icon} size={18} /></span>}
      <span className="font-semibold text-sm">{title}</span>
      <span className="text-xs text-muted">{text}</span>
      {on && <span aria-hidden="true" className="absolute top-2.5 right-2.5 grid place-items-center w-5 h-5 rounded-full text-white" style={{ background: 'var(--accent)' }}><Icon name="check" size={12} /></span>}
    </button>
  )
}

function Section({ title, hint, children }: { title: string; hint?: string; children: ReactNode }) {
  return (
    <section className="flex flex-col gap-3">
      <div><h3 className="m-0 text-[15px] font-semibold">{title}</h3>{hint && <p className="m-0 text-xs text-muted mt-0.5">{hint}</p>}</div>
      {children}
    </section>
  )
}

function Rows({ rows, onChange, keyLabel, secret, addLabel }: { rows: (KV & { secret?: boolean })[]; onChange: (r: (KV & { secret?: boolean })[]) => void; keyLabel: string; secret?: boolean; addLabel: string }) {
  return (
    <div className="flex flex-col gap-2">
      {rows.map((e, i) => (
        <div key={i} className="flex flex-wrap gap-2 items-center">
          <input className="input font-mono text-xs w-44" aria-label={keyLabel} placeholder="KEY" value={e.key} onChange={(ev) => onChange(rows.map((x, j) => (j === i ? { ...x, key: ev.target.value } : x)))} />
          <input className="input font-mono text-xs grow min-w-[140px]" aria-label="Value" type={e.secret ? 'password' : 'text'} placeholder="value" value={e.value} onChange={(ev) => onChange(rows.map((x, j) => (j === i ? { ...x, value: ev.target.value } : x)))} />
          {secret && <label className="text-xs flex items-center gap-1"><input type="checkbox" checked={!!e.secret} onChange={(ev) => onChange(rows.map((x, j) => (j === i ? { ...x, secret: ev.target.checked } : x)))} /> Secret</label>}
          <button type="button" className="btn btn-sm" aria-label={`Remove ${e.key || 'row'}`} onClick={() => onChange(rows.filter((_, j) => j !== i))}>Remove</button>
        </div>
      ))}
      <div><button type="button" className="btn btn-sm" onClick={() => onChange([...rows, { key: '', value: '', ...(secret ? { secret: false } : {}) }])}>{addLabel}</button></div>
    </div>
  )
}

function CheckList({ checks }: { checks: Check[] }) {
  return (
    <ul className="list-none m-0 p-0 flex flex-col gap-2" aria-label="Pre-deploy checks">
      {checks.map((c) => (
        <li key={c.key} className="flex items-start gap-2 text-sm">
          <span aria-hidden="true" className={`mt-0.5 grid place-items-center w-5 h-5 rounded-full flex-none ${{ pass: 'bg-g-bg text-g-fg', warn: 'bg-y-bg text-y-fg', fail: 'bg-r-bg text-r-fg' }[c.status]}`}><Icon name={c.status === 'pass' ? 'check' : c.status === 'warn' ? 'warn' : 'close'} size={12} /></span>
          <span><span className="font-semibold">{c.label}</span><span className="sr-only"> ({c.status})</span> <span className="text-muted">{c.message}</span></span>
        </li>
      ))}
    </ul>
  )
}

/**
 * New application: four steps (source, build, deploy, review). Values survive moving back and forth,
 * the server re-checks access, capacity and the address before anything is created, and the first
 * deployment runs as a background job whose progress page opens on confirm.
 */
export default function NewApplicationWizard({ open, onClose }: { open: boolean; onClose: () => void }) {
  const nav = useNavigate()
  const servers = useList<Server>('/servers')
  const registries = useList<R>('/registries')
  const projects = useList<R>('/projects')
  const { run, busy } = useAction()
  const [step, setStep] = useState(0)
  const [reach, setReach] = useState(0)
  const [err, setErr] = useState<string | null>(null)
  const [fieldErr, setFieldErr] = useState<Record<string, string>>({})
  const [nameTouched, setNameTouched] = useState(false)
  const [portTouched, setPortTouched] = useState(false)
  const [subTouched, setSubTouched] = useState(false)
  const [advanced, setAdvanced] = useState(false)
  const [f, setF] = useState({
    source_type: 'git' as SourceType, name: '', repo: '', branch: 'main', image: '', git_token: '', environment_id: '',
    builder: 'auto', dockerfile_path: 'Dockerfile', context: '.', no_cache: false, push_registry_id: '',
    environment: 'production', server_id: '', port: '', health_path: '/', strategy: 'rolling', require_approval: true,
    domain_mode: 'auto' as DomainMode, subdomain: '', domain: '', deploy: true,
  })
  const [buildArgs, setBuildArgs] = useState<KV[]>([])
  const [env, setEnv] = useState<EnvRow[]>([])
  const [paste, setPaste] = useState<string | null>(null)
  const [pre, setPre] = useState<Preflight | null>(null)
  const [checking, setChecking] = useState(false)
  const set = <K extends keyof typeof f>(k: K, v: (typeof f)[K]) => setF((p) => ({ ...p, [k]: v }))

  const builds = f.source_type === 'git' || f.source_type === 'dockerfile'
  const isImage = f.source_type === 'image'
  const platform = pre?.platform_domain ?? 'your platform domain'

  // Smart defaults: the name follows the repository or image until the person edits it; known images bring their port.
  useEffect(() => { if (!nameTouched) set('name', suggestName(isImage ? f.image : f.repo)) }, [f.repo, f.image, isImage]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (!portTouched && isImage) { const p = guessPort(f.image); set('port', p ? String(p) : '') } }, [f.image]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (!subTouched) set('subdomain', f.name) }, [f.name]) // eslint-disable-line react-hooks/exhaustive-deps

  const body = (deploy = f.deploy) => ({
    name: f.name, source_type: f.source_type, environment: f.environment, environment_id: f.environment_id || null, strategy: f.strategy,
    server_id: f.server_id || null, requires_approval: f.require_approval && f.environment === 'production',
    port: f.port ? Number(f.port) : null, health_path: f.health_path || null,
    domain_mode: f.domain_mode, domain: f.domain_mode === 'custom' ? f.domain.trim() : null, subdomain: f.domain_mode === 'subdomain' ? f.subdomain : null,
    env: env.filter((e) => e.key), deploy,
    ...(isImage ? { image: f.image.trim() } : {
      source: {
        repo: f.repo.trim(), branch: f.branch.trim() || 'main', ...(f.git_token ? { git_token: f.git_token } : {}),
        ...(builds ? {
          builder: f.builder, no_cache: f.no_cache, dockerfile_path: f.dockerfile_path || 'Dockerfile', context: f.context || '.', push_registry_id: f.push_registry_id || null,
          build_args: Object.fromEntries(buildArgs.filter((a) => a.key).map((a) => [a.key, a.value])),
        } : {}),
      },
    }),
  })

  const validate = (i: number): string | null => {
    if (i === 0) {
      if (!NAME_RE.test(f.name) || f.name.length > 63) return 'Name: lowercase letters, numbers and dashes, up to 63 characters.'
      if (isImage && !f.image.trim()) return 'Enter the Docker image, for example nginx:1.27.'
      if (!isImage && !/^(https?:\/\/|git@)\S+$/.test(f.repo.trim())) return 'Enter the repository URL (https:// or git@).'
    }
    if (i === 1 && builds) {
      for (const p of [f.dockerfile_path, f.context]) if (p.startsWith('/') || p.includes('..')) return 'Paths are relative to the repository and cannot contain “..”.'
      if (buildArgs.some((a) => a.key && !KEY_RE.test(a.key))) return 'Build argument names use letters, numbers and _.'
    }
    if (i === 2) {
      if (f.port && !(Number(f.port) >= 1 && Number(f.port) <= 65535)) return 'Port must be between 1 and 65535.'
      if (f.domain_mode !== 'none' && !f.port && f.source_type !== 'compose') return 'A public address needs the port the app listens on.'
      if (f.domain_mode === 'custom' && !DOMAIN_RE.test(f.domain.trim())) return 'Enter a valid domain such as app.example.com.'
      if (f.domain_mode === 'subdomain' && !NAME_RE.test(f.subdomain)) return 'Subdomain: lowercase letters, numbers and dashes.'
      if (f.health_path && !PATH_RE.test(f.health_path)) return 'The health check path starts with / and has no spaces.'
      const bad = env.find((e) => e.key && !KEY_RE.test(e.key))
      if (bad) return `“${bad.key}” is not a valid variable name.`
      const ks = env.map((e) => e.key).filter(Boolean)
      if (new Set(ks).size !== ks.length) return 'Each environment variable can appear only once.'
    }
    return null
  }

  const go = (i: number) => {
    if (i > step) for (let s = step; s < i; s++) { const e = validate(s); if (e) { setStep(s); setErr(e); return } }
    setErr(null)
    setStep(i)
    setReach((r) => Math.max(r, i))
  }

  // The server re-checks repository/image access, capacity and the address as the person goes.
  const bodyKey = JSON.stringify(body(false))
  useEffect(() => {
    if (!open || step < 1) return
    const ctl = new AbortController()
    setChecking(true)
    const timer = setTimeout(() => {
      api.post<{ data: Preflight }>('/applications/preflight', JSON.parse(bodyKey), { signal: ctl.signal })
        .then((r) => setPre(r.data)).catch(() => {}).finally(() => setChecking(false))
    }, 500)
    return () => { clearTimeout(timer); ctl.abort() }
  }, [bodyKey, step, open])

  const stepChecks = (id: string) => (pre?.checks ?? []).filter((c) => c.step === id && c.status !== 'pass')
  const reset = () => { setStep(0); setReach(0); setErr(null); setFieldErr({}); setPre(null) }
  const close = () => { reset(); onClose() }

  const create = async () => {
    for (let s = 0; s < 3; s++) { const e = validate(s); if (e) { setStep(s); setErr(e); return } }
    if (pre && !pre.ok) { setErr('Fix the failed checks before creating the application.'); return }
    try {
      const r = await run<{ data?: { id: string; deployment_id?: string; job_id?: string; url?: string | null } }>({ path: '/applications', body: body(), label: `Create ${f.name}`, success: f.deploy ? `${f.name} created; deployment started` : `${f.name} created`, silent: !!f.deploy })
      const d = r?.data
      close()
      if (d?.deployment_id) nav(`/admin/deployments/${d.deployment_id}${d.url ? `?url=${encodeURIComponent(d.url)}` : ''}`)
      else if (d?.id) nav(`/admin/applications/${d.id}`)
    } catch (e) {
      const fields = Object.fromEntries(Object.entries((e as { fields?: Record<string, string[]> }).fields ?? {}).map(([k, v]) => [k, v[0]]))
      setFieldErr(fields)
      const where = Object.keys(fields)[0] ?? ''
      const s = /^(name|image|source\.(repo|branch|git))/.test(where) ? 0 : /^source\./.test(where) ? 1 : where ? 2 : step
      setStep(s)
      setErr(Object.values(fields)[0] ?? (e as Error).message)
    }
  }

  const server = servers.rows.find((s) => s.id === f.server_id)
  const address = { auto: `${f.name || 'app'}-xxxxxx.${platform}`, subdomain: `${f.subdomain || '…'}.${platform}`, custom: f.domain.trim() || '…', none: 'Internal only' }[f.domain_mode]
  const summary: [string, string][] = useMemo(() => [
    ['Source', SOURCES.find((s) => s.id === f.source_type)!.title],
    [isImage ? 'Image' : 'Repository', isImage ? f.image || '—' : `${f.repo || '—'}${f.repo ? ` @ ${f.branch || 'main'}` : ''}`],
    ...(builds ? [['Builder', f.builder === 'auto' ? 'Auto detect' : `Dockerfile (${f.dockerfile_path})`] as [string, string]] : []),
    ['Server', server?.name ?? (pre?.detected?.server ? `Automatic → ${pre.detected.server.name}` : 'Automatic placement')],
    ['Address', address],
    ['Port', f.port || '—'],
  ], [f, server, pre, address, builds, isImage])

  const content: ReactNode[] = [
    // 1 · Source & repository
    <div key="source" className="flex flex-col gap-6">
      <Section title="Application source" hint="Where your application comes from.">
        <div className="grid gap-3 grid-cols-2 lg:grid-cols-4">
          {SOURCES.map((s) => <Choice key={s.id} on={f.source_type === s.id} onClick={() => set('source_type', s.id)} title={s.title} text={s.text} icon={s.icon} />)}
        </div>
      </Section>
      <Section title={isImage ? 'Image details' : 'Repository details'} hint={isImage ? 'Registry credentials saved under Registries are used automatically.' : 'Public repositories need no token.'}>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Field label="Application name" error={fieldErr.name}><input className="input" value={f.name} placeholder="my-web-app" onChange={(e) => { setNameTouched(true); set('name', e.target.value.toLowerCase()) }} /></Field>
          {isImage
            ? <div className="lg:col-span-2"><Field label="Docker image" error={fieldErr.image}><input className="input font-mono" value={f.image} placeholder="ghcr.io/acme/web:1.4" onChange={(e) => set('image', e.target.value)} /></Field></div>
            : <>
              <Field label="Repository URL" error={fieldErr['source.repo']}><input className="input font-mono" value={f.repo} placeholder="https://github.com/acme/web" onChange={(e) => set('repo', e.target.value)} /></Field>
              <Field label="Branch" hint={pre?.detected?.commit ? `Latest commit ${pre.detected.commit.slice(0, 7)}` : undefined}><input className="input font-mono" value={f.branch} onChange={(e) => set('branch', e.target.value)} /></Field>
            </>}
        </div>
      </Section>
      {!isImage && (
        <div className="rounded-lg border border-line">
          <button type="button" className="w-full flex items-center justify-between gap-3 p-4 bg-transparent border-0 cursor-pointer text-fg text-left" aria-expanded={advanced} onClick={() => setAdvanced(!advanced)}>
            <span><span className="block font-semibold text-sm">Advanced options</span><span className="block text-xs text-muted">Access token and project environment</span></span>
            <Icon name={advanced ? 'up' : 'down'} />
          </button>
          {advanced && (
            <div className="grid gap-4 sm:grid-cols-2 p-4 pt-0">
              <Field label="Git access token" hint="Optional; stored as an encrypted secret and never shown again"><input className="input font-mono" type="password" autoComplete="off" value={f.git_token} onChange={(e) => set('git_token', e.target.value)} /></Field>
              {projects.rows.length > 0 && <Field label="Project environment" hint="Shares its variables, branch and approval rules"><Select className="input" value={f.environment_id} onChange={(e) => set('environment_id', e.target.value)}><option value="">None</option>{projects.rows.flatMap((p) => ((p.environments ?? []) as R[]).map((e) => <option key={e.id} value={e.id}>{p.name} / {e.name}</option>))}</Select></Field>}
            </div>
          )}
        </div>
      )}
    </div>,

    // 2 · Build & image
    <div key="build" className="flex flex-col gap-6">
      {!builds ? (
        <Section title={isImage ? 'Prebuilt image' : 'Compose stack'} hint={isImage ? 'Nothing to build: the image is pulled on the server.' : 'Each service builds or pulls as its compose file says.'}>
          <div className="rounded-lg border border-line p-4 text-sm">{isImage ? <>The server pulls <code className="font-mono">{f.image || 'the image'}</code>. Private registries use the credentials saved under <a href="/admin/registries">Registries</a>.</> : 'Build settings come from the compose file in the repository.'}</div>
        </Section>
      ) : <>
        <Section title="Build method" hint="Auto detect builds Node.js, Python, Go, PHP or static sites when the repository has no Dockerfile.">
          <div className="grid gap-3 sm:grid-cols-2">
            <Choice on={f.builder === 'auto'} onClick={() => set('builder', 'auto')} title="Auto detect" text="Buildpack-style detection; uses a Dockerfile when one exists" icon="adjust" />
            <Choice on={f.builder === 'dockerfile'} onClick={() => set('builder', 'dockerfile')} title="Dockerfile" text="Build exactly the Dockerfile you point at" icon="file" />
          </div>
        </Section>
        <div className="grid gap-4 sm:grid-cols-2">
          {f.builder === 'dockerfile' && <Field label="Dockerfile path" error={fieldErr['source.dockerfile_path']}><input className="input font-mono" value={f.dockerfile_path} onChange={(e) => set('dockerfile_path', e.target.value)} /></Field>}
          <Field label="Build context" hint="Folder sent to the build, relative to the repository" error={fieldErr['source.context']}><input className="input font-mono" value={f.context} onChange={(e) => set('context', e.target.value)} /></Field>
          <Field label="Push to registry" hint="Without one the image stays on the server that builds it, which then also runs the app"><Select className="input" value={f.push_registry_id} onChange={(e) => set('push_registry_id', e.target.value)}><option value="">Do not push</option>{registries.rows.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}</Select></Field>
          <Field label="Layer cache"><div className="flex items-center gap-3 min-h-10"><Toggle checked={!f.no_cache} onChange={(v: boolean) => set('no_cache', !v)} label="Reuse cached layers" /><span className="text-sm">{f.no_cache ? 'Build from scratch' : 'Reuse cached layers'}</span></div></Field>
        </div>
        <Section title="Build arguments" hint="Passed as --build-arg; not available to the running app.">
          <Rows rows={buildArgs} onChange={(r) => setBuildArgs(r)} keyLabel="Build argument" addLabel="Add build argument" />
        </Section>
      </>}
      {stepChecks('build').length > 0 && <div className="rounded-lg border border-line p-3"><CheckList checks={stepChecks('build')} /></div>}
    </div>,

    // 3 · Deploy & runtime
    <div key="deploy" className="flex flex-col gap-6">
      <Section title="Placement" hint="Automatic placement picks the online server with the most free memory and CPU.">
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Target server"><Select className="input" value={f.server_id} onChange={(e) => set('server_id', e.target.value)}>
            <option value="">Automatic placement</option>
            {servers.rows.map((s) => <option key={s.id} value={s.id} disabled={s.status !== 'online'}>{s.name} · {s.status}{s.ram_pct != null ? ` · RAM ${Math.round(s.ram_pct)}%` : ''}{s.disk_pct != null ? ` · disk ${Math.round(s.disk_pct)}%` : ''}</option>)}
          </Select></Field>
          <Field label="Environment"><Select className="input" value={f.environment} onChange={(e) => set('environment', e.target.value)}><option>production</option><option>staging</option><option>preview</option></Select></Field>
          <Field label="Container port" hint={isImage && guessPort(f.image) ? `Detected from the image: ${guessPort(f.image)}` : 'The port the app listens on'} error={fieldErr.port}><input className="input" inputMode="numeric" value={f.port} placeholder="3000" onChange={(e) => { setPortTouched(true); set('port', e.target.value.replace(/\D/g, '')) }} /></Field>
          <Field label="Health check path" hint="GET on the port after start; 2xx/3xx passes" error={fieldErr.health_path}><input className="input font-mono" value={f.health_path} onChange={(e) => set('health_path', e.target.value)} /></Field>
        </div>
      </Section>
      <Section title="Public address" hint="DNS, routing through the proxy and the HTTPS certificate are set up automatically.">
        <div className="grid gap-3 grid-cols-2 lg:grid-cols-4">
          <Choice on={f.domain_mode === 'auto'} onClick={() => set('domain_mode', 'auto')} title="Generated" text={`Free name under ${platform}`} />
          <Choice on={f.domain_mode === 'subdomain'} onClick={() => set('domain_mode', 'subdomain')} title="Subdomain" text={`Choose the name under ${platform}`} />
          <Choice on={f.domain_mode === 'custom'} onClick={() => set('domain_mode', 'custom')} title="Your domain" text="Point DNS at the server" />
          <Choice on={f.domain_mode === 'none'} onClick={() => set('domain_mode', 'none')} title="Internal only" text="No public address" />
        </div>
        {f.domain_mode === 'subdomain' && <Field label="Subdomain" error={fieldErr.subdomain}><div className="flex items-center gap-2"><input className="input font-mono" value={f.subdomain} onChange={(e) => { setSubTouched(true); set('subdomain', e.target.value.toLowerCase()) }} /><span className="text-sm text-muted whitespace-nowrap">.{platform}</span></div></Field>}
        {f.domain_mode === 'custom' && <Field label="Domain" error={fieldErr.domain}><input className="input font-mono" value={f.domain} placeholder="app.example.com" onChange={(e) => set('domain', e.target.value.toLowerCase())} /></Field>}
      </Section>
      <Section title="Environment variables" hint="Mark passwords and keys as secret; they are encrypted and never shown in summaries or emails.">
        <div className="flex gap-2"><button type="button" className="btn btn-sm" onClick={() => setPaste(paste === null ? '' : null)}>Paste .env</button></div>
        {paste !== null && (
          <div className="flex flex-col gap-2">
            <textarea className="input font-mono text-xs min-h-[100px]" aria-label="Paste .env contents" value={paste} onChange={(ev) => setPaste(ev.target.value)} placeholder={'APP_URL=https://example.com\nAPI_TOKEN=…'} />
            <div><button type="button" className="btn btn-sm btn-primary" disabled={!parseDotEnv(paste).length} onClick={() => { setEnv(mergeEnv(env, parseDotEnv(paste))); setPaste(null) }}>Add {parseDotEnv(paste).length || ''} variables</button></div>
          </div>
        )}
        <Rows rows={env} onChange={(r) => setEnv(r as EnvRow[])} keyLabel="Variable" secret addLabel="Add variable" />
      </Section>
      <Section title="Release strategy">
        <div className="grid gap-3 sm:grid-cols-3">{STRATEGIES.map(([id, t, s]) => <Choice key={id} on={f.strategy === id} onClick={() => set('strategy', id)} title={t} text={s} />)}</div>
        {f.environment === 'production' && <div className="flex items-center gap-3"><Toggle checked={f.require_approval} onChange={(v: boolean) => set('require_approval', v)} label="Require approval for production" /><span className="text-sm">Require approval before production deploys</span></div>}
      </Section>
      {stepChecks('deploy').length > 0 && <div className="rounded-lg border border-line p-3"><CheckList checks={stepChecks('deploy')} /></div>}
    </div>,

    // 4 · Review & create
    <div key="review" className="flex flex-col gap-6">
      <Section title="Summary">
        <dl className="m-0 grid gap-x-6 gap-y-2 sm:grid-cols-2">
          {[...summary, ['Environment', f.environment], ['Health check', f.health_path || '—'], ['Strategy', STRATEGIES.find((s) => s[0] === f.strategy)![1]], ['Variables', `${env.filter((e) => e.key).length} (${env.filter((e) => e.key && e.secret).length} secret)`]].map(([k, v]) => (
            <div key={k} className="flex justify-between gap-3 border-b border-line py-2"><dt className="text-muted">{k}</dt><dd className="m-0 font-medium text-right break-all">{v}</dd></div>
          ))}
        </dl>
      </Section>
      <Section title="Pre-deploy checks" hint="Repository or image access, server capacity and the address, checked on the server just now.">
        {!pre ? <p className="m-0 text-sm text-muted">{checking ? 'Checking…' : 'Checks run when you reach this step.'}</p> : (
          <>
            <div className="flex items-center gap-2 text-sm font-semibold">{pre.ok ? <Badge tone="g">All checks passed</Badge> : <Badge tone="r">{pre.failed} must be fixed</Badge>}{pre.warnings > 0 && <Badge tone="y">{pre.warnings} warning{pre.warnings > 1 ? 's' : ''}</Badge>}{checking && <span className="text-muted font-normal">Re-checking…</span>}</div>
            <CheckList checks={pre.checks} />
          </>
        )}
      </Section>
      <div className="flex items-center gap-3"><Toggle checked={f.deploy} onChange={(v: boolean) => set('deploy', v)} label="Deploy now" /><span className="text-sm">{f.deploy ? 'Deploy right away and follow the progress' : 'Only create it; deploy later'}</span></div>
      <p className="m-0 text-xs text-muted">You and the customer's owners get an email when the deployment finishes, with the address and status. Secrets are never included.</p>
    </div>,
  ]

  const last = step === STEPS.length - 1
  return (
    <Modal open={open} onClose={close} title="Create new application" subtitle="Deploy from a repository, a Docker image or a compose stack." width={1120} fullOnMobile
      footer={<div className="w-full flex items-center justify-between gap-2">
        <button type="button" className="btn" onClick={close}>Cancel</button>
        <span className="flex gap-2">
          <button type="button" className="btn" disabled={step === 0 || busy} onClick={() => go(step - 1)}>Previous</button>
          {last
            ? <button type="button" className="btn btn-primary" disabled={busy || (pre ? !pre.ok : false)} onClick={create}>{busy ? 'Creating…' : f.deploy ? 'Create and deploy' : 'Create application'}</button>
            : <button type="button" className="btn btn-primary" onClick={() => go(step + 1)}>Next: {STEPS[step + 1].label}</button>}
        </span>
      </div>}>
      <div className="flex flex-col gap-5">
        <ol className="list-none m-0 p-0 flex items-center" aria-label="Progress">
          {STEPS.map((s, i) => {
            const done = i < step
            const cur = i === step
            return (
              <li key={s.id} className="flex items-center flex-1 last:flex-none min-w-0">
                <button type="button" disabled={i > reach || busy} aria-current={cur ? 'step' : undefined} aria-label={`Step ${i + 1}: ${s.label}`} onClick={() => go(i)}
                  className="flex items-center gap-2 bg-transparent border-0 p-0 cursor-pointer disabled:cursor-default min-w-0 text-fg">
                  <span aria-hidden="true" className="flex flex-none items-center justify-center rounded-full text-xs font-bold w-7 h-7"
                    style={{ background: done || cur ? 'var(--accent)' : 'var(--head)', color: done || cur ? '#fff' : 'var(--muted)', boxShadow: cur ? '0 0 0 4px color-mix(in srgb, var(--accent) 22%, transparent)' : 'inset 0 0 0 1px var(--line)' }}>
                    {done ? <Icon name="check" size={13} /> : i + 1}
                  </span>
                  <span aria-hidden="true" className={`hidden md:block text-[13px] truncate ${cur ? 'font-semibold' : 'text-muted'}`}>{s.label}</span>
                </button>
                {i < STEPS.length - 1 && <span aria-hidden="true" className="flex-1 mx-2 h-[2px] rounded min-w-3" style={{ background: done ? 'var(--accent)' : 'var(--line)' }} />}
              </li>
            )
          })}
        </ol>
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_280px]">
          <div className="min-w-0 flex flex-col gap-5">
            <div><div className="text-xs font-semibold" style={{ color: 'var(--accent)' }}>Step {step + 1} of {STEPS.length}</div><h3 className="m-0 text-lg font-semibold">{STEPS[step].label}</h3><p className="m-0 text-sm text-muted">{STEPS[step].hint}</p></div>
            {content[step]}
            {err && <div role="alert" className="text-sm font-semibold text-danger">{err}</div>}
          </div>
          <aside className="hidden lg:flex flex-col gap-4 rounded-lg border border-line p-4 self-start sticky top-0" aria-label="Configuration summary">
            <div className="font-semibold">Configuration summary</div>
            <ol className="list-none m-0 p-0 flex flex-col gap-1">
              {STEPS.map((s, i) => (
                <li key={s.id} className="flex gap-3 rounded-md p-2" style={i === step ? { background: 'var(--sel)' } : undefined}>
                  <span aria-hidden="true" className="flex-none grid place-items-center w-6 h-6 rounded-full text-xs font-bold" style={{ background: i <= step ? 'var(--accent)' : 'var(--head)', color: i <= step ? '#fff' : 'var(--muted)' }}>{i < step ? <Icon name="check" size={11} /> : i + 1}</span>
                  <span className="min-w-0"><span className="block text-[13px] font-semibold">{s.label}</span><span className="block text-xs text-muted">{s.hint}</span></span>
                </li>
              ))}
            </ol>
            <dl className="m-0 flex flex-col gap-1.5 border-t border-line pt-3 text-xs">
              {summary.map(([k, v]) => <div key={k} className="flex justify-between gap-2"><dt className="text-muted">{k}</dt><dd className="m-0 font-medium text-right truncate max-w-[160px]" title={v}>{v}</dd></div>)}
            </dl>
          </aside>
        </div>
      </div>
    </Modal>
  )
}
