import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router'
import { api } from '../../api/client'
import { useAction, useJob, useList } from '../../api/hooks'
import { CopyButton } from '../../components/CopyButton'
import { Icon } from '../../components/Icon'
import { Badge } from '../../components/Badge'
import { Card, Field, PageHeader, Toggle } from '../../components/Card'
import Select from '../../components/Select'
import type { CatalogTemplate } from './Catalog'
import { Logo } from './Catalog'

type Server = { id: string; name: string; status?: string; cpu_count?: number | null; memory_mb?: number | null }
type Db = { id: string; name: string; engine: string; server_id: string | null; status?: string }
type EnvRow = { key: string; value: string; secret: boolean }
type Check = { key: string; step: string; status: 'pass' | 'warn' | 'fail'; label: string; message: string }
type Preflight = { ok: boolean; failed: number; warnings: number; platform_domain: string; checks: Check[] }
type Result = { resource_type: string; resource_id: string; job_id?: string | null; url?: string | null }
type DomainMode = 'auto' | 'subdomain' | 'custom' | 'none'
const STEP_KEYS = ['details', 'server', 'database', 'advanced'] as const
const LABEL_RE = /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/

/** Pass / warning / failure list for the install checks. */
function Checks({ checks }: { checks: Check[] }) {
  if (!checks.length) return null
  const tone = { pass: 'text-g-fg', warn: 'text-y-fg', fail: 'text-r-fg' } as const
  const glyph = { pass: 'check', warn: 'warn', fail: 'close' } as const
  return (
    <ul className="list-none m-0 p-0 flex flex-col gap-1.5" aria-label="Install checks">
      {checks.map((c) => (
        <li key={c.key} className="flex items-start gap-2 text-sm">
          <span className={`mt-0.5 ${tone[c.status]}`} aria-label={c.status}><Icon name={glyph[c.status]} size={16} /></span>
          <span><b className="font-semibold">{c.label}</b> <span className="text-muted">{c.message}</span></span>
        </li>
      ))}
    </ul>
  )
}

const STEPS = ['Details', 'Server & resources', 'Database', 'Advanced', 'Review & deploy'] as const
const SIZES = [
  { id: 'small', label: 'Small', spec: '0.5 vCPU · 512 MB' },
  { id: 'standard', label: 'Standard', spec: '1 vCPU · 1 GB' },
  { id: 'pro', label: 'Pro', spec: '2 vCPU · 4 GB' },
] as const
const NAME_RE = /^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/
const DOMAIN_RE = /^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i
const PATH_RE = /^\/\S*$/
const ENV_RE = /^[A-Za-z_][A-Za-z0-9_]*$/

/** True when a template variable is filled in from a linked database (mirrors TemplateService::databaseVariables). */
export function dbFilled(key: string, keys: string[]): boolean {
  if (/(DB|DATABASE|MYSQL|MARIADB|POSTGRES|PG)/.test(key) && /(HOST|PORT|USER|USERNAME|PASSWORD|PASS)$/.test(key)) return true
  const hosts = keys.filter((k) => /HOST$/.test(k))
  return hosts.length === 1 && hosts[0] === key && !keys.some((k) => /(DB|DATABASE|MYSQL|MARIADB|POSTGRES|PG).*HOST$/.test(k))
}

/** A strong random password from the browser's crypto source. */
export function generatePassword(len = 24): string {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_'
  const buf = new Uint32Array(len)
  crypto.getRandomValues(buf)
  return Array.from(buf, (n) => chars[n % chars.length]).join('')
}

function Stepper({ step, reach, onStep }: { step: number; reach: number; onStep: (i: number) => void }) {
  return (
    <nav aria-label="Install progress" className="flex flex-col gap-3">
      <div className="flex items-center justify-between text-sm">
        <span className="font-semibold">Step {step + 1} of {STEPS.length}</span>
        <span className="text-muted">{STEPS[step]}</span>
      </div>
      <ol className="list-none m-0 p-0 flex items-center">
        {STEPS.map((s, i) => {
          const done = i < step
          const cur = i === step
          return (
            <li key={s} className="flex items-center flex-1 last:flex-none min-w-0">
              <button type="button" disabled={i > reach} onClick={() => onStep(i)} aria-current={cur ? 'step' : undefined}
                className="flex items-center gap-2 bg-transparent border-0 p-0 cursor-pointer disabled:cursor-default min-w-0">
                <span className="flex flex-none items-center justify-center rounded-full text-xs font-bold w-[30px] h-[30px] transition-colors"
                  style={{ background: done || cur ? 'var(--accent)' : 'var(--surface-2, var(--line))', color: done || cur ? 'var(--accent-contrast, #fff)' : 'var(--muted)', boxShadow: cur ? '0 0 0 4px color-mix(in srgb, var(--accent) 22%, transparent)' : undefined }}>
                  {done ? <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" aria-hidden="true"><path d="M5 12l5 5L20 7" /></svg> : i + 1}
                </span>
                <span className={`hidden lg:block text-[13px] truncate ${cur ? 'font-semibold text-fg' : 'text-muted'}`}>{s}</span>
              </button>
              {i < STEPS.length - 1 && <span aria-hidden="true" className="flex-1 mx-2 h-[2px] rounded" style={{ background: i < step ? 'var(--accent)' : 'var(--line)' }} />}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}

function Choice({ on, onClick, title, children }: { on: boolean; onClick: () => void; title: string; children?: ReactNode }) {
  return (
    <button type="button" aria-pressed={on} onClick={onClick}
      className={`card text-left p-3 flex flex-col gap-1 cursor-pointer transition-[border-color,box-shadow] ${on ? 'border-accent ring-2 ring-[color-mix(in_srgb,var(--accent)_25%,transparent)]' : 'hover:border-accent'}`}>
      <span className="font-semibold text-sm">{title}</span>
      {children && <span className="text-xs text-muted">{children}</span>}
    </button>
  )
}

function Row({ k, v }: { k: string; v: ReactNode }) {
  return <div className="flex gap-3 py-2 border-t border-line text-sm first:border-t-0"><dt className="w-40 flex-none text-muted">{k}</dt><dd className="m-0 min-w-0 break-words">{v}</dd></div>
}

/** INST-1..5: five-step install wizard for every catalogue template (single image or Compose stack). */
export default function InstallWizard({ t, onBack }: { t: CatalogTemplate; onBack: () => void }) {
  const nav = useNavigate()
  const { run, busy } = useAction()
  const { rows: servers } = useList<Server>('/servers')
  const { rows: databases } = useList<Db>('/databases')
  const compose = t.type === 'compose'
  const keys = t.variables.map((v) => v.key)
  const wantsDb = !compose && keys.some((k) => dbFilled(k, keys))

  const [step, setStep] = useState(0)
  const [reach, setReach] = useState(0)
  const [err, setErr] = useState<string | null>(null)
  const [d, setD] = useState({ name: t.id.slice(0, 40), domain: '', environment: 'production', placement: compose ? 'manual' : 'auto', server_id: '', size: 'standard', port: t.port ? String(t.port) : '', https: true,
    domain_mode: (compose || t.port ? 'auto' : 'none') as DomainMode, subdomain: t.id.slice(0, 40) })
  const [pre, setPre] = useState<Preflight | null>(null)
  const [result, setResult] = useState<Result | null>(null)
  const [dbMode, setDbMode] = useState<'existing' | 'manual' | 'none'>(wantsDb ? 'existing' : 'none')
  const [dbId, setDbId] = useState('')
  // Secrets with no default get a strong generated value; the person can still change it.
  const [vars, setVars] = useState<Record<string, string>>(() => Object.fromEntries(t.variables.map((v) => [v.key, v.default ?? (v.secret ? generatePassword() : '')])))
  const [shown, setShown] = useState<Record<string, boolean>>({})
  const [adv, setAdv] = useState({ volume_path: '', health_path: '', auto_restart: true })
  const [env, setEnv] = useState<EnvRow[]>([])
  const [phase, setPhase] = useState<'idle' | 'installing' | 'done' | 'failed'>('idle')
  const [log, setLog] = useState<string[]>([])

  const set = <K extends keyof typeof d>(k: K, v: (typeof d)[K]) => setD((p) => ({ ...p, [k]: v }))
  const db = databases.find((x) => x.id === dbId)
  const linked = dbMode === 'existing' && !!db
  const server = servers.find((s) => s.id === (linked ? db!.server_id : d.server_id))
  const visibleVars = t.variables.filter((v) => !(linked && dbFilled(v.key, keys)))

  const validate = (i: number): string | null => {
    if (i === 0) {
      if (!NAME_RE.test(d.name) || d.name.length > 63) return 'Name: lowercase letters, numbers and dashes, up to 63 characters.'
      if (d.domain_mode === 'custom' && !DOMAIN_RE.test(d.domain.trim())) return 'Enter a valid domain such as app.example.com.'
      if (d.domain_mode === 'subdomain' && !LABEL_RE.test(d.subdomain)) return 'Subdomain: lowercase letters, numbers and dashes.'
    }
    if (i === 1) {
      if ((compose || d.placement === 'manual') && !d.server_id && !linked) return 'Choose a server.'
      if (d.port && !(Number(d.port) >= 1 && Number(d.port) <= 65535)) return 'Port must be between 1 and 65535.'
    }
    if (i === 2) {
      if (dbMode === 'existing' && !dbId) return 'Choose a database, or pick another option.'
      const missing = visibleVars.filter((v) => v.required && !vars[v.key]?.trim()).map((v) => v.label)
      if (missing.length) return `Fill in: ${missing.join(', ')}`
    }
    if (i === 3) {
      for (const p of [adv.volume_path, adv.health_path]) if (p && !PATH_RE.test(p)) return 'Paths must start with / and contain no spaces.'
      const bad = env.find((e) => e.key && !ENV_RE.test(e.key))
      if (bad) return `“${bad.key}” is not a valid variable name.`
      const ks = env.map((e) => e.key).filter(Boolean)
      if (new Set(ks).size !== ks.length) return 'Each environment variable can appear only once.'
    }
    return null
  }

  const go = (i: number) => {
    if (i > step) {
      for (let s = step; s < i; s++) { const e = validate(s); if (e) { setStep(s); setErr(e); return } }
    }
    setErr(null)
    setStep(i)
    setReach((r) => Math.max(r, i))
  }

  const issues = useMemo(() => [0, 1, 2, 3].map((i) => validate(i)).filter(Boolean) as string[],
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [d, dbMode, dbId, vars, adv, env, databases])

  const body = () => ({
      name: d.name, environment: d.environment, domain_mode: d.domain_mode,
      domain: d.domain_mode === 'custom' ? d.domain.trim() : null, subdomain: d.domain_mode === 'subdomain' ? d.subdomain : null,
      server_id: linked ? null : (d.placement === 'manual' || compose ? d.server_id || null : null),
      variables: Object.fromEntries(Object.entries(vars).filter(([k]) => !(linked && dbFilled(k, keys)))),
      ...(compose ? {} : {
        size: d.size, port: d.port ? Number(d.port) : null, auto_restart: adv.auto_restart,
        volume_path: adv.volume_path || null, health_path: adv.health_path || null,
        env: env.filter((e) => e.key), database_id: linked ? dbId : null,
      }),
  })

  // INST-7: the server re-checks everything (names, server health, Docker, domain, database) as the person moves through the steps.
  const bodyKey = JSON.stringify(body())
  useEffect(() => {
    if (phase !== 'idle' && phase !== 'failed') return
    const ctl = new AbortController()
    const timer = setTimeout(() => {
      api.post<{ data: Preflight }>(`/templates/${t.id}/preflight`, JSON.parse(bodyKey), { signal: ctl.signal }).then((r) => setPre(r.data)).catch(() => {})
    }, 400)
    return () => { clearTimeout(timer); ctl.abort() }
  }, [bodyKey, t.id, phase])
  const stepChecks = (i: number) => (pre?.checks ?? []).filter((c) => c.step === STEP_KEYS[i] && c.status !== 'pass')
  const platform = pre?.platform_domain ?? 'your platform domain'

  const install = async () => {
    if (issues.length) { setErr(issues[0]); return }
    if (pre && !pre.ok) { setErr('Fix the failed checks before installing.'); return }
    setPhase('installing')
    setLog([`Creating ${compose ? 'stack' : 'application'} ${d.name} from ${t.name}…`])
    const res = await run<{ data?: Result }>({
      path: `/templates/${t.id}/install`, label: `Install ${t.name}`, success: `${d.name} created`, body: body(),
    }).catch((e: Error) => { setLog((l) => [...l, `✗ ${e.message}`]); return null })
    const r = res?.data
    if (!r) { setPhase('failed'); return }
    setResult(r)
    setPhase('done')
  }

  const content: ReactNode[] = [
    // 1 · Details
    <div key="details" className="grid gap-4 sm:grid-cols-2">
      <Field label={compose ? 'Stack name' : 'Application name'} hint="Lowercase letters, numbers and dashes.">
        <input className="input" autoFocus value={d.name} onChange={(e) => set('name', e.target.value.toLowerCase())} />
      </Field>
      <Field label="Environment">
        <Select className="input" value={d.environment} onChange={(e) => set('environment', e.target.value)}>
          <option value="production">Production</option><option value="staging">Staging</option><option value="development">Development</option>
        </Select>
      </Field>
      <Field label="Version / image">
        <input className="input font-mono text-xs" readOnly value={compose ? `${t.services?.length ?? 1} services · template v${t.version}` : `${t.image ?? ''} · template v${t.version}`} />
      </Field>
      <div className="sm:col-span-2 flex flex-col gap-3">
        <div className="text-sm font-semibold">Domain configuration</div>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Choice on={d.domain_mode === 'auto'} onClick={() => set('domain_mode', 'auto')} title="Auto-generated subdomain">A unique address under {platform}. DNS and HTTPS are set up for you.</Choice>
          <Choice on={d.domain_mode === 'subdomain'} onClick={() => set('domain_mode', 'subdomain')} title="Custom subdomain">Pick the name before .{platform}.</Choice>
          <Choice on={d.domain_mode === 'custom'} onClick={() => set('domain_mode', 'custom')} title="Your own domain">Connect a domain you own; DNS is checked before HTTPS.</Choice>
          <Choice on={d.domain_mode === 'none'} onClick={() => set('domain_mode', 'none')} title="No public domain">Internal only, reachable inside its network.</Choice>
        </div>
        {d.domain_mode === 'auto' && <p className="m-0 text-sm text-muted">Your address will look like <code className="font-mono text-xs">{d.name || 'app'}-x7k2q9.{platform}</code>.</p>}
        {d.domain_mode === 'subdomain' && (
          <Field label="Subdomain">
            <span className="flex items-center gap-2">
              <input className="input grow" value={d.subdomain} onChange={(e) => set('subdomain', e.target.value.toLowerCase())} />
              <span className="text-sm text-muted whitespace-nowrap">.{platform}</span>
            </span>
          </Field>
        )}
        {d.domain_mode === 'custom' && (
          <Field label="Domain" hint="Point its A record at the server. Domains in a connected DNS zone get their record automatically.">
            <input className="input" value={d.domain} placeholder="app.example.com" onChange={(e) => set('domain', e.target.value)} />
          </Field>
        )}
      </div>
    </div>,

    // 2 · Server & resources
    <div key="server" className="flex flex-col gap-4">
      {linked && <p className="m-0 text-sm rounded-lg p-3 bg-[color-mix(in_srgb,var(--accent)_8%,transparent)]">Runs on <b>{server?.name ?? 'the database server'}</b>, next to the database you picked.</p>}
      {!compose && !linked && (
        <div className="grid gap-3 sm:grid-cols-2">
          <Choice on={d.placement === 'auto'} onClick={() => set('placement', 'auto')} title="Automatic">The scheduler picks the healthiest server with room.</Choice>
          <Choice on={d.placement === 'manual'} onClick={() => set('placement', 'manual')} title="Choose a server">Pin the app to a specific server.</Choice>
        </div>
      )}
      {!linked && (compose || d.placement === 'manual') && (
        <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(200px,1fr))]">
          {servers.length === 0 && <p className="m-0 text-sm text-muted">No servers yet. Add one under Servers first.</p>}
          {servers.map((s) => (
            <Choice key={s.id} on={d.server_id === s.id} onClick={() => set('server_id', s.id)} title={s.name}>
              <Badge tone={s.status === 'online' || s.status === 'active' ? 'g' : 'n'}>{s.status ?? 'unknown'}</Badge>
              {s.cpu_count ? ` ${s.cpu_count} vCPU` : ''}{s.memory_mb ? ` · ${Math.round(s.memory_mb / 1024)} GB` : ''}
            </Choice>
          ))}
        </div>
      )}
      {!compose && (
        <>
          <div className="text-sm font-semibold">Resources</div>
          <div className="grid gap-3 sm:grid-cols-3">
            {SIZES.map((s) => <Choice key={s.id} on={d.size === s.id} onClick={() => set('size', s.id)} title={s.label}>{s.spec}</Choice>)}
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Container port" hint="The port the app listens on inside the container.">
              <input className="input" inputMode="numeric" value={d.port} onChange={(e) => set('port', e.target.value.replace(/\D/g, ''))} />
            </Field>
            <Field label="Network"><input className="input" readOnly value={linked ? 'proxy + internal (database)' : 'proxy'} /></Field>
          </div>
        </>
      )}
    </div>,

    // 3 · Database
    <div key="db" className="flex flex-col gap-4">
      {compose ? (
        <p className="m-0 text-sm text-muted">This stack ships its own services{t.services?.length ? ` (${t.services.join(', ')})` : ''}; any database inside it is created and wired up automatically.</p>
      ) : (
        <>
          <div className="grid gap-3 sm:grid-cols-3">
            <Choice on={dbMode === 'existing'} onClick={() => setDbMode('existing')} title="Use a managed database">Credentials are filled in for you and kept secret.</Choice>
            <Choice on={dbMode === 'manual'} onClick={() => setDbMode('manual')} title="Enter connection details">An external or self-managed server.</Choice>
            <Choice on={dbMode === 'none'} onClick={() => setDbMode('none')} title={wantsDb ? 'Skip for now' : 'No database'}>{wantsDb ? 'Fill the variables yourself.' : 'This app does not need one.'}</Choice>
          </div>
          {wantsDb && t.id === 'wordpress' && <p className="m-0 text-xs text-muted">Recommended: WordPress with MariaDB. Create one under Databases, or install the “WordPress + MariaDB” stack.</p>}
          {dbMode === 'existing' && (
            <Field label="Database" hint={databases.length ? 'The app is placed on the same server and joins its private network.' : 'No managed databases yet. Create one under Databases.'}>
              <Select className="input" value={dbId} onChange={(e) => setDbId(e.target.value)}>
                <option value="">Choose a database</option>
                {databases.map((x) => <option key={x.id} value={x.id}>{x.name} · {x.engine}</option>)}
              </Select>
            </Field>
          )}
          {linked && (
            <dl className="m-0 card p-3">
              <Row k="Engine" v={db!.engine} />
              <Row k="Host" v={<code className="font-mono text-xs">db-{db!.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}</code>} />
              <Row k="User" v={db!.engine === 'postgres' ? 'postgres' : 'root'} />
              <Row k="Password" v="Taken from the database's stored secret" />
            </dl>
          )}
        </>
      )}
      {visibleVars.length > 0 && <div className="text-sm font-semibold">Configuration</div>}
      <div className="grid gap-4 sm:grid-cols-2">
        {visibleVars.map((v) => {
          const secret = v.secret || /PASS/.test(v.key)
          return (
            <Field key={v.key} label={`${v.label}${v.required ? ' *' : ''}`}>
              <span className="flex gap-2">
                <input className="input font-mono text-xs grow" type={secret && !shown[v.key] ? 'password' : 'text'} autoComplete="off"
                  value={vars[v.key] ?? ''} onChange={(e) => setVars({ ...vars, [v.key]: e.target.value })} />
                {secret && <button type="button" className="btn btn-sm" aria-label={shown[v.key] ? `Hide ${v.label}` : `Show ${v.label}`} onClick={() => setShown({ ...shown, [v.key]: !shown[v.key] })}>{shown[v.key] ? 'Hide' : 'Show'}</button>}
                {secret && <button type="button" className="btn btn-sm" onClick={() => setVars({ ...vars, [v.key]: generatePassword() })}>Generate</button>}
              </span>
            </Field>
          )
        })}
      </div>
      {t.generated.length > 0 && <p className="m-0 text-xs text-muted">Generated securely at install: {t.generated.map((g) => g.replace(/^SERVICE_/, '').toLowerCase()).join(', ')}.</p>}
    </div>,

    // 4 · Advanced
    <div key="adv" className="flex flex-col gap-4">
      {compose ? <p className="m-0 text-sm text-muted">Stacks use the volumes, health checks and restart policy defined in their Compose file. You can edit it after install.</p> : (
        <>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Persistent volume path" hint="Mounted inside the container; kept across deploys and backed up.">
              <input className="input font-mono text-xs" value={adv.volume_path} placeholder="/var/www/html" onChange={(e) => setAdv({ ...adv, volume_path: e.target.value })} />
            </Field>
            <Field label="Health check path" hint="Traffic switches over only once this returns 2xx.">
              <input className="input font-mono text-xs" value={adv.health_path} placeholder="/" onChange={(e) => setAdv({ ...adv, health_path: e.target.value })} />
            </Field>
          </div>
          <Toggle checked={adv.auto_restart} onChange={(v: boolean) => setAdv({ ...adv, auto_restart: v })} label="Restart automatically if the container stops" />
          {d.domain_mode === 'custom' && <Toggle checked={d.https} onChange={(v: boolean) => set('https', v)} label="Serve over HTTPS with a free certificate" />}
          <div className="flex items-center justify-between"><span className="text-sm font-semibold">Environment variables</span>
            <button type="button" className="btn btn-sm" onClick={() => setEnv([...env, { key: '', value: '', secret: false }])}>Add variable</button></div>
          {env.length === 0 && <p className="m-0 text-xs text-muted">None added. Template variables are set for you.</p>}
          {env.map((e, i) => (
            <div key={i} className="flex flex-wrap gap-2 items-center">
              <input className="input font-mono text-xs w-48" aria-label="Key" placeholder="KEY" value={e.key} onChange={(ev) => setEnv(env.map((x, j) => j === i ? { ...x, key: ev.target.value } : x))} />
              <input className="input font-mono text-xs grow" aria-label="Value" type={e.secret ? 'password' : 'text'} placeholder="value" value={e.value} onChange={(ev) => setEnv(env.map((x, j) => j === i ? { ...x, value: ev.target.value } : x))} />
              <label className="text-xs flex items-center gap-1"><input type="checkbox" checked={e.secret} onChange={(ev) => setEnv(env.map((x, j) => j === i ? { ...x, secret: ev.target.checked } : x))} /> Secret</label>
              <button type="button" className="btn btn-sm" aria-label={`Remove ${e.key || 'variable'}`} onClick={() => setEnv(env.filter((_, j) => j !== i))}>Remove</button>
            </div>
          ))}
          <p className="m-0 text-xs text-muted">Backups follow the server's backup schedule. Docker options beyond these can be changed on the app's Settings tab after install.</p>
        </>
      )}
    </div>,

    // 5 · Review
    <div key="review" className="flex flex-col gap-4">
      <dl className="m-0">
        <Row k="Template" v={`${t.name} (v${t.version})`} />
        <Row k="Name" v={d.name} />
        <Row k="Environment" v={d.environment} />
        <Row k="Address" v={{ auto: `Auto-generated under ${platform} · HTTPS`, subdomain: `${d.subdomain}.${platform} · HTTPS`, custom: `${d.domain.trim()}${d.https ? ' · HTTPS' : ''}`, none: 'Internal only' }[d.domain_mode]} />
        <Row k="Server" v={server?.name ?? (compose ? '—' : 'Automatic')} />
        {!compose && <Row k="Resources" v={`${SIZES.find((s) => s.id === d.size)?.label} · ${SIZES.find((s) => s.id === d.size)?.spec} · port ${d.port || '—'}`} />}
        {!compose && <Row k="Database" v={linked ? `${db!.name} (${db!.engine})` : dbMode === 'manual' ? 'Manual connection details' : 'None'} />}
        {!compose && <Row k="Advanced" v={[adv.volume_path && `volume ${adv.volume_path}`, adv.health_path && `health ${adv.health_path}`, adv.auto_restart ? 'auto-restart' : 'no auto-restart', env.length ? `${env.length} extra variable${env.length > 1 ? 's' : ''}` : ''].filter(Boolean).join(' · ')} />}
      </dl>
      {issues.length > 0 && <div role="alert" className="text-sm text-danger">{issues.map((x) => <div key={x}>• {x}</div>)}</div>}
      <div className="card p-4 flex flex-col gap-3">
        <div className="flex items-center gap-2">
          <span className="text-sm font-semibold">Pre-install checks</span>
          {pre ? <Badge tone={pre.ok ? (pre.warnings ? 'y' : 'g') : 'r'}>{pre.ok ? (pre.warnings ? `Ready · ${pre.warnings} warning${pre.warnings > 1 ? 's' : ''}` : 'All checks passed') : `${pre.failed} must be fixed`}</Badge> : <span className="text-xs text-muted">Checking…</span>}
        </div>
        {pre && <Checks checks={pre.checks} />}
      </div>
      {phase === 'installing' && (
        <div className="card p-3 font-mono text-xs flex flex-col gap-1" aria-live="polite">
          {log.map((l, i) => <div key={i}>{l}</div>)}
          <div className="text-muted">Working…</div>
        </div>
      )}
      {phase === 'failed' && <div className="card p-3 font-mono text-xs">{log.map((l, i) => <div key={i}>{l}</div>)}</div>}
      {phase === 'failed' && <p className="m-0 text-sm">Nothing was left half-created. Fix the problem above and try again, or go back to change a step.</p>}
    </div>,
  ]

  const last = step === STEPS.length - 1
  if (phase === 'done' && result) {
    return <Completion t={t} result={result} name={d.name} environment={d.environment} serverName={server?.name} size={compose ? null : d.size} onBack={onBack} onOpen={(to) => nav(to)} />
  }
  return (
    <>
      <PageHeader title={`Install ${t.name}`} subtitle={t.description}
        actions={<button type="button" className="btn" onClick={onBack} disabled={phase === 'installing'}>Back to catalogue</button>} />
      <Card>
        <div className="flex items-start gap-4 pb-4 mb-4 border-b border-line">
          <Logo t={t} size={56} />
          <div className="min-w-0">
            <div className="font-semibold text-lg">{t.name}</div>
            <div className="flex flex-wrap gap-2 mt-1 text-xs">
              <Badge tone="n">{t.category}</Badge>
              {compose ? <Badge tone="b">Stack · {t.services?.length ?? 1} services</Badge> : <Badge tone="n"><code className="font-mono">{t.image}</code></Badge>}
            </div>
          </div>
        </div>
        <Stepper step={step} reach={reach} onStep={go} />
        <div className="mt-5">{content[step]}</div>
        {step < 4 && stepChecks(step).length > 0 && <div className="mt-4 rounded-lg border border-line p-3"><Checks checks={stepChecks(step)} /></div>}
        {err && phase === 'idle' && <div role="alert" className="mt-4 text-sm font-semibold text-danger">{err}</div>}
        <div className="flex justify-between gap-2 mt-6 pt-4 border-t border-line">
          <button type="button" className="btn" disabled={step === 0 || phase === 'installing' || phase === 'done'} onClick={() => go(step - 1)}>Back</button>
          {last
            ? <button type="button" className="btn btn-primary min-h-11" disabled={busy || phase === 'installing' || (!!pre && !pre.ok)} onClick={install}>{phase === 'installing' ? 'Installing…' : phase === 'failed' ? 'Retry install' : `Install ${t.name}`}</button>
            : <button type="button" className="btn btn-primary" onClick={() => go(step + 1)}>Continue</button>}
        </div>
      </Card>
    </>
  )
}

const SIZE_LABEL: Record<string, string> = Object.fromEntries(SIZES.map((s) => [s.id, `${s.label} · ${s.spec}`]))

/** INST-9: the deployment summary after install. "Running" only once the first deployment's health check passed. */
function Completion({ t, result, name, environment, serverName, size, onBack, onOpen }: {
  t: CatalogTemplate; result: Result; name: string; environment: string; serverName?: string; size: string | null; onBack: () => void; onOpen: (to: string) => void
}) {
  const { run, busy } = useAction()
  const [jobId, setJobId] = useState(result.job_id ?? null)
  const { job } = useJob(jobId)
  const app = result.resource_type === 'application'
  const state: 'deploying' | 'running' | 'failed' = !app ? 'running' : job?.status === 'succeeded' ? 'running' : job?.status === 'failed' || job?.status === 'cancelled' ? 'failed' : 'deploying'
  const url = (job?.result as { url?: string } | undefined)?.url ?? result.url ?? null
  const manage = app ? `/admin/applications/${result.resource_id}` : '/admin/compose'
  const retry = async () => {
    const r = await run<{ data?: { job_id?: string } }>({ path: '/deployments', label: `Redeploy ${name}`, success: 'Deployment restarted', body: { application_id: result.resource_id } }).catch(() => null)
    if (r?.data?.job_id) setJobId(r.data.job_id)
  }
  const head = {
    deploying: { tone: 'b' as const, badge: 'Deploying', title: `Installing ${t.name}…`, text: 'Pulling the image, starting the container and waiting for its health check.' },
    running: { tone: 'g' as const, badge: 'Running', title: `${t.name} installed`, text: app ? 'Your application is running and ready to use.' : 'The stack was created and is starting.' },
    failed: { tone: 'r' as const, badge: 'Failed', title: `${t.name} did not start`, text: 'The deployment failed before the app became healthy. The details are below; nothing else was changed.' },
  }[state]
  return (
    <>
      <PageHeader title={`Install ${t.name}`} subtitle="Deployment summary" actions={<button type="button" className="btn" onClick={onBack}>Back to catalogue</button>} />
      <Card>
        <div className="flex flex-col gap-5" aria-live="polite">
          <div className="flex items-center gap-4">
            <span className={`grid place-items-center w-14 h-14 rounded-xl ${{ g: 'bg-g-bg text-g-fg', b: 'bg-b-bg text-b-fg', r: 'bg-r-bg text-r-fg' }[head.tone]}`} aria-hidden="true">
              <Icon name={state === 'running' ? 'check' : state === 'failed' ? 'warn' : 'refresh'} size={26} className={state === 'deploying' ? 'animate-spin' : ''} />
            </span>
            <div className="flex-1 min-w-0"><div className="text-lg font-semibold">{head.title}</div><div className="text-sm text-muted">{head.text}</div></div>
            <Badge tone={head.tone}>{head.badge}</Badge>
          </div>
          <div className="flex flex-col gap-1.5">
            <span className="text-sm font-semibold">Application URL</span>
            <div className="flex items-center gap-2 rounded-lg border border-line p-3">
              <span className="flex-1 min-w-0 truncate text-sm font-medium">{url ?? 'Internal only (no public address)'}</span>
              {url && <CopyButton value={url} />}
              {url && state === 'running' && <a className="btn btn-sm no-underline" href={url} target="_blank" rel="noreferrer">Open</a>}
            </div>
            {url && state === 'deploying' && <span className="text-xs text-muted">The address answers once DNS, HTTPS and the health check are ready.</span>}
          </div>
          <dl className="m-0 grid gap-x-6 sm:grid-cols-2">
            <Row k="Application" v={name} />
            <Row k="Template" v={`${t.name} · v${t.version}`} />
            <Row k="Environment" v={environment} />
            <Row k="Server" v={serverName ?? 'Placed automatically'} />
            {size && <Row k="Resources" v={SIZE_LABEL[size] ?? size} />}
            <Row k="Installed" v={new Date().toLocaleString()} />
          </dl>
          {app && job && (
            <div className="flex flex-col gap-1.5">
              <span className="text-sm font-semibold">Deployment progress</span>
              <ol className="list-none m-0 p-0 flex flex-col gap-1">
                {job.stages.map((s) => (
                  <li key={s.name} className="flex items-start gap-2 text-sm">
                    <span className={s.status === 'succeeded' ? 'text-g-fg' : s.status === 'failed' ? 'text-r-fg' : 'text-muted'} aria-label={s.status}>
                      <Icon name={s.status === 'succeeded' ? 'check' : s.status === 'failed' ? 'close' : s.status === 'running' ? 'refresh' : 'clock'} size={16} />
                    </span>
                    <span><b className="font-semibold">{s.name.charAt(0).toUpperCase() + s.name.slice(1).replace(/_/g, ' ')}</b>{s.message && <span className="text-muted"> · {s.message}</span>}</span>
                  </li>
                ))}
              </ol>
              {state === 'failed' && !!job.error && <pre className="m-0 mt-2 p-3 rounded-lg bg-head text-xs whitespace-pre-wrap">{typeof job.error === 'string' ? job.error : JSON.stringify(job.error, null, 2)}</pre>}
            </div>
          )}
          {state === 'running' && app && <p className="m-0 text-xs text-muted">We emailed the account owner and the platform team. Generated passwords are never emailed; find them on the app's Environment tab.</p>}
          <div className="flex flex-wrap gap-2 justify-end pt-4 border-t border-line">
            {state === 'failed' && <button type="button" className="btn" disabled={busy} onClick={retry}>Retry deployment</button>}
            <button type="button" className="btn btn-primary" onClick={() => onOpen(manage)}>{app ? 'Manage application' : 'Open stacks'}</button>
          </div>
        </div>
      </Card>
    </>
  )
}
