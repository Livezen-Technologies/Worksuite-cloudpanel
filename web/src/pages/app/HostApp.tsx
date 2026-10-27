import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { api, isApiError, type ApiError } from '../../api/client'
import { useJob, useList, useOne } from '../../api/hooks'
import { Card, Field } from '../../components/Card'
import { StepWizard } from '../../components/StepWizard'
import { JobTimeline } from '../../components/JobTimeline'
import { logLines } from '../../lib/logs'
import { money, unwrap, validateAppName } from '../../lib/format'
import type { R } from '../../lib/util'

type SourceType = 'image' | 'git' | 'compose' | 'template'
const SOURCES: [SourceType, string, string][] = [
  ['image', 'Docker image', 'Docker Hub, GHCR or private'],
  ['git', 'Git repository', 'We build it from your Dockerfile'],
  ['compose', 'Compose file', 'Paste or upload compose.yaml'],
  ['template', 'Template', 'WordPress, n8n, Ghost and more'],
]
const TEMPLATES = ['WordPress', 'n8n', 'Ghost', 'PostgreSQL', 'Redis', 'Uptime Kuma']
const SIZES = [
  { id: 'small', name: 'Small', spec: '0.5 vCPU · 512 MB · 5 GB', price: 5 },
  { id: 'standard', name: 'Standard', spec: '1 vCPU · 1 GB · 10 GB', price: 10 },
  { id: 'pro', name: 'Pro', spec: '2 vCPU · 4 GB · 40 GB', price: 24 },
] as const
const STAGES = ['Getting your app', 'Starting your app', 'Checking it responds', 'Setting up HTTPS', 'Live']
interface Env { key: string; value: string; secret: boolean }

const pickStyle = (on: boolean) => (on ? { border: '2px solid var(--accent)', background: 'var(--sel)' } : undefined)

/** Server field names → the wizard step that owns them. */
const FIELD_STEP: Record<string, number> = { name: 1, port: 1, region: 1, env: 1, volume_path: 1, health_path: 1, size: 2, source: 0, image: 0, repo: 0 }

export default function HostApp() {
  const [step, setStep] = useState(0)
  const [max, setMax] = useState(0)
  const [src, setSrc] = useState<SourceType>('image')
  const [s, setS] = useState({ image: '', private: false, username: '', password: '', repo: '', branch: 'main', compose: '', template: '' })
  const [c, setC] = useState({ name: '', port: '80', region: '', volume: false, volume_path: '/data', health_path: '/' })
  const [env, setEnv] = useState<Env[]>([{ key: '', value: '', secret: false }])
  const [size, setSize] = useState<string>('standard')
  const [fieldErr, setFieldErr] = useState<Record<string, string>>({})
  const [err, setErr] = useState<string | null>(null)
  const [quota, setQuota] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [srcOk, setSrcOk] = useState(false)
  const [deploy, setDeploy] = useState<{ app: R; job_id: string } | null>(null)
  const templates = useList<R>('/app/templates')
  const plans = useOne<R | R[]>('/app/billing/plans')
  const { job } = useJob(deploy?.job_id)
  const failed = job?.status === 'failed'
  const done = job?.status === 'succeeded'
  const failLogs = useQuery({
    queryKey: ['host-fail-logs', deploy?.app?.id],
    queryFn: async () => logLines(await api.get(`/app/apps/${deploy!.app.id}/logs`, { tail: 50 })).slice(-50),
    enabled: failed && !!deploy?.app?.id,
  })
  useEffect(() => setMax((m) => Math.max(m, step)), [step])
  useEffect(() => setSrcOk(false), [src, s])

  const sizePrice = (id: string, fallback: number) => {
    const list = (Array.isArray(plans.item) ? plans.item : (plans.item as R | undefined)?.sizes ?? []) as R[]
    const p = list.find((x) => x.code === id || x.id === id || String(x.name ?? '').toLowerCase() === id)
    if (p?.price_cents != null) return money(Number(p.price_cents) / 100, p.currency)
    return p?.price != null ? money(p.price) : money(fallback)
  }

  const source = () => (src === 'image' ? { type: src, image: s.image.trim(), ...(s.private ? { registry_auth: { username: s.username, password: s.password } } : {}) }
    : src === 'git' ? { type: src, repo: s.repo.trim(), branch: s.branch || 'main' }
    : src === 'compose' ? { type: src, compose: s.compose }
    : { type: src, template: s.template })

  const validateSource = async () => {
    const local = src === 'image' && !s.image.trim() ? 'Enter the image name, for example nginx:1.27-alpine.'
      : src === 'git' && !/^(https:\/\/|git@)/.test(s.repo.trim()) ? 'Enter the repository address, starting with https://'
      : src === 'compose' && !s.compose.trim() ? 'Paste your compose file or upload it.'
      : src === 'template' && !s.template ? 'Pick a template.'
      : src === 'image' && s.private && (!s.username || !s.password) ? 'Enter the username and password for the private registry.' : null
    if (local) { setErr(local); return }
    setBusy(true); setErr(null)
    try {
      const r = unwrap<R>(await api.post('/app/apps/validate-source', { source: source() }))
      if (r?.ok === false || r?.valid === false) { setErr(r.message ?? 'We could not reach that image. Check the name and credentials.'); return }
      if (src === 'image' && r?.suggested_port && c.port === '80') setC((x) => ({ ...x, port: String(r.suggested_port) }))
      if (src === 'template') {
        const t = (r?.template ?? templates.rows.find((x) => x.id === s.template)) as R | undefined
        setC((x) => ({ ...x, name: x.name || s.template.toLowerCase().replace(/[^a-z0-9-]+/g, '-'), port: t?.port ? String(t.port) : x.port, ...(t?.volume_path ? { volume: true, volume_path: String(t.volume_path) } : {}) }))
      }
      setSrcOk(true); setStep(1)
    } catch (e) {
      setErr(isApiError(e) ? (e.status === 422 ? (Object.values(e.fields)[0]?.[0] ?? e.message) : e.message) + ' Check the details and try again.' : 'Something went wrong.')
    } finally { setBusy(false) }
  }

  const start = async () => {
    setBusy(true); setErr(null); setQuota(null)
    try {
      const res = unwrap<{ app: R; job_id: string }>(await api.post('/app/apps', {
        name: c.name.trim(), source: source(), port: Number(c.port), region: c.region || undefined,
        env: env.filter((e) => e.key.trim()).map((e) => ({ key: e.key.trim(), value: e.value, secret: e.secret })),
        volume_path: c.volume ? c.volume_path : undefined, size,
        health_path: c.health_path && c.health_path !== '/' ? c.health_path : undefined,
      }))
      setDeploy(res)
    } catch (e) {
      const ae = e as ApiError
      if (isApiError(e) && ae.code === 'quota_exceeded') setQuota(ae.message)
      else if (isApiError(e) && Object.keys(ae.fields).length) {
        setFieldErr(Object.fromEntries(Object.entries(ae.fields).map(([k, v]) => [k.split('.')[0], v[0]])))
        const first = Math.min(...Object.keys(ae.fields).map((k) => FIELD_STEP[k.split('.')[0]] ?? 3))
        if (first < 3) setStep(first)
        setErr(ae.message)
      } else setErr(isApiError(e) ? ae.message : 'Something went wrong. Try again.')
    } finally { setBusy(false) }
  }
  const retry = async () => {
    if (!deploy) return
    setBusy(true)
    try { const r = unwrap<R>(await api.post(`/app/apps/${deploy.app.id}/redeploy`)); setDeploy({ app: deploy.app, job_id: String(r.job_id) }) }
    catch (e) { setErr((e as Error).message) } finally { setBusy(false) }
  }
  const nameErr = c.name ? validateAppName(c.name) : null
  const rawAddress = (job?.result as R | undefined)?.address ?? (job?.result as R | undefined)?.url ?? deploy?.app?.address ?? deploy?.app?.primary_domain ?? (deploy ? `${deploy.app.name ?? c.name}.livezen.app` : '')
  const address = String(rawAddress).replace(/^https?:\/\//, '')
  const stages = job?.stages?.length ? job.stages : STAGES.map((name) => ({ name, status: 'pending' }))

  const steps = [
    {
      label: 'Source',
      validate: () => (srcOk ? null : (validateSource(), 'pending')),
      content: (
        <div className="flex flex-col gap-4">
          <h2 className="m-0 text-lg font-semibold">Where is your app?</h2>
          <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
            {SOURCES.map(([id, t, sub]) => (
              <button key={id} type="button" aria-pressed={src === id} onClick={() => setSrc(id)} className="card p-4 text-left cursor-pointer text-fg min-h-[44px]" style={pickStyle(src === id)}>
                <span className="block font-semibold">{t}</span><span className="block text-xs text-muted mt-1">{sub}</span>
              </button>
            ))}
          </div>
          {src === 'image' && (
            <>
              <Field label="Image" hint="For example nginx:1.27-alpine or ghcr.io/you/shop:1.4"><input className="input font-mono" value={s.image} onChange={(e) => setS({ ...s, image: e.target.value })} placeholder="nginx:1.27-alpine" /></Field>
              <label className="flex items-center gap-2 text-sm min-h-[44px]"><input type="checkbox" checked={s.private} onChange={(e) => setS({ ...s, private: e.target.checked })} />This image is private</label>
              {s.private && <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
                <Field label="Registry username"><input className="input" autoComplete="off" value={s.username} onChange={(e) => setS({ ...s, username: e.target.value })} /></Field>
                <Field label="Password or access token"><input className="input" type="password" autoComplete="new-password" value={s.password} onChange={(e) => setS({ ...s, password: e.target.value })} /></Field>
              </div>}
            </>
          )}
          {src === 'git' && (
            <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
              <Field label="Repository URL"><input className="input font-mono" value={s.repo} onChange={(e) => setS({ ...s, repo: e.target.value })} placeholder="https://github.com/you/my-shop" /></Field>
              <Field label="Branch"><input className="input" value={s.branch} onChange={(e) => setS({ ...s, branch: e.target.value })} /></Field>
            </div>
          )}
          {src === 'compose' && (
            <>
              <label className="label">Compose file<textarea className="input font-mono" rows={10} value={s.compose} onChange={(e) => setS({ ...s, compose: e.target.value })} placeholder="services:\n  web:\n    image: nginx" /></label>
              <label className="label">Or upload compose.yaml<input type="file" accept=".yml,.yaml" className="text-sm font-normal min-h-[44px]" onChange={async (e) => { const f = e.target.files?.[0]; if (f) setS({ ...s, compose: await f.text() }) }} /></label>
            </>
          )}
          <div className="text-sm font-semibold">{src === 'template' ? 'Pick a template:' : 'Or start from a template:'}</div>
          <div className="flex flex-wrap gap-2">
            {(templates.rows.length ? templates.rows.map((t) => ({ id: String(t.id), name: String(t.name ?? t.id) })) : TEMPLATES.map((t) => ({ id: t.toLowerCase().replace(/\s+/g, '-'), name: t }))).map((t) => (
              <button key={t.id} type="button" className="chip" aria-pressed={src === 'template' && s.template === t.id} onClick={() => { setSrc('template'); setS({ ...s, template: t.id }) }}>{t.name}</button>
            ))}
          </div>
        </div>
      ),
    },
    {
      label: 'Configure',
      validate: () => {
        const e: Record<string, string> = {}
        const n = validateAppName(c.name); if (n) e.name = n
        const p = Number(c.port); if (!Number.isInteger(p) || p < 1 || p > 65535) e.port = 'Enter the port your app listens on (1–65535).'
        if (c.volume && !/^\/[\w./-]*$/.test(c.volume_path)) e.volume_path = 'Use an absolute path such as /data.'
        if (c.health_path && !/^\/\S*$/.test(c.health_path)) e.health_path = 'Start with / , for example /healthz.'
        const keys = env.filter((x) => x.key.trim()).map((x) => x.key.trim())
        if (keys.some((k) => !/^[A-Za-z_][A-Za-z0-9_]*$/.test(k))) e.env = 'Variable names can use letters, numbers and _ and cannot start with a number.'
        else if (new Set(keys).size !== keys.length) e.env = 'Each variable name can only be used once.'
        setFieldErr(e)
        return Object.keys(e).length ? 'Fix the highlighted fields to continue.' : null
      },
      content: (
        <div className="flex flex-col gap-4">
          <h2 className="m-0 text-lg font-semibold">Configure</h2>
          <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
            <Field label="App name" error={fieldErr.name || nameErr || undefined} hint="Lowercase letters, numbers and hyphens. Used in your free address.">
              <input className="input" value={c.name} aria-invalid={!!(fieldErr.name || nameErr)} onChange={(e) => { setC({ ...c, name: e.target.value }); setFieldErr({ ...fieldErr, name: '' }) }} placeholder="my-shop" />
            </Field>
            <Field label="Port your app listens on" error={fieldErr.port}><input className="input" inputMode="numeric" value={c.port} onChange={(e) => setC({ ...c, port: e.target.value.replace(/\D/g, '') })} /></Field>
            <Field label="Region" error={fieldErr.region}><select className="input" value={c.region} onChange={(e) => setC({ ...c, region: e.target.value })}><option value="">Closest to me</option><option value="ap-southeast">Singapore</option><option value="eu-central">Frankfurt</option><option value="us-east">New York</option></select></Field>
          </div>
          <fieldset className="border-0 p-0 m-0 flex flex-col gap-2">
            <legend className="text-sm font-semibold mb-2">Environment variables</legend>
            {env.map((e, i) => (
              <div key={i} className="flex flex-wrap gap-2 items-center">
                <input className="input font-mono flex-1 min-w-[130px]" aria-label={`Variable ${i + 1} name`} placeholder="NAME" value={e.key} onChange={(x) => setEnv(env.map((y, j) => (j === i ? { ...y, key: x.target.value } : y)))} />
                <input className="input font-mono flex-[2] min-w-[150px]" aria-label={`Variable ${i + 1} value`} type={e.secret ? 'password' : 'text'} placeholder="value" value={e.value} onChange={(x) => setEnv(env.map((y, j) => (j === i ? { ...y, value: x.target.value } : y)))} />
                <label className="flex items-center gap-1.5 text-sm min-h-[44px]"><input type="checkbox" checked={e.secret} onChange={(x) => setEnv(env.map((y, j) => (j === i ? { ...y, secret: x.target.checked } : y)))} />Secret</label>
                <button type="button" className="btn" aria-label={`Remove variable ${i + 1}`} onClick={() => setEnv(env.filter((_, j) => j !== i))}>Remove</button>
              </div>
            ))}
            {fieldErr.env && <div role="alert" className="text-xs font-semibold text-danger">{fieldErr.env}</div>}
            <button type="button" className="btn self-start" onClick={() => setEnv([...env, { key: '', value: '', secret: false }])}>+ Add variable</button>
          </fieldset>
          <label className="flex items-center gap-2 text-sm min-h-[44px]"><input type="checkbox" checked={c.volume} onChange={(e) => setC({ ...c, volume: e.target.checked })} />Keep files between restarts (persistent storage, backed up daily)</label>
          {c.volume && <Field label="Folder inside your app" error={fieldErr.volume_path}><input className="input font-mono" value={c.volume_path} onChange={(e) => setC({ ...c, volume_path: e.target.value })} /></Field>}
          <details className="text-sm">
            <summary className="cursor-pointer font-semibold min-h-[44px] inline-flex items-center">Advanced</summary>
            <Field label="Health check path" hint="After start we request this path on your app's port; any 2xx or 3xx answer within 60 s means healthy." error={fieldErr.health_path}>
              <input className="input font-mono" value={c.health_path} onChange={(e) => setC({ ...c, health_path: e.target.value })} />
            </Field>
          </details>
        </div>
      ),
    },
    {
      label: 'Size',
      content: (
        <div className="flex flex-col gap-4">
          <h2 className="m-0 text-lg font-semibold">Choose a size</h2>
          <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]" role="radiogroup" aria-label="Size">
            {SIZES.map((p) => (
              <button key={p.id} type="button" role="radio" aria-checked={size === p.id} onClick={() => setSize(p.id)} className="card p-4 text-left cursor-pointer text-fg flex flex-col gap-1" style={pickStyle(size === p.id)}>
                <span className="font-bold">{p.name}</span><span className="text-xs text-muted">{p.spec}</span><span className="font-semibold mt-1">{sizePrice(p.id, p.price)} / mo</span>
              </button>
            ))}
          </div>
          {fieldErr.size && <div role="alert" className="text-xs font-semibold text-danger">{fieldErr.size}</div>}
        </div>
      ),
    },
    {
      label: 'Deploy',
      content: !deploy ? (
        <div className="flex flex-col gap-3">
          <h2 className="m-0 text-lg font-semibold">Ready to go</h2>
          <dl className="m-0 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <dt className="text-muted">App</dt><dd className="m-0 font-semibold">{c.name}</dd>
            <dt className="text-muted">From</dt><dd className="m-0 font-mono text-xs break-all">{src === 'image' ? s.image : src === 'git' ? s.repo : src === 'template' ? (templates.rows.find((t) => t.id === s.template)?.name ?? s.template) : 'compose file'}</dd>
            <dt className="text-muted">Size</dt><dd className="m-0">{SIZES.find((x) => x.id === size)?.name}</dd>
            <dt className="text-muted">Variables</dt><dd className="m-0">{env.filter((e) => e.key).length}</dd>
          </dl>
          <p className="m-0 text-sm text-muted">We pick a server for you, set up HTTPS and restart your app if it ever crashes.</p>
          {quota && (
            <div role="alert" className="p-4 rounded-xl border border-danger-line" style={{ background: 'var(--r-bg)' }}>
              <div className="font-semibold" style={{ color: 'var(--r-fg)' }}>This app does not fit in your current plan</div>
              <div className="text-sm mt-1">{quota} Upgrade your plan or choose a smaller size.</div>
              <div className="flex gap-2 mt-3 flex-wrap"><Link to="/app/billing" className="btn btn-primary no-underline">Upgrade plan</Link><button type="button" className="btn" onClick={() => setStep(2)}>Choose a smaller size</button></div>
            </div>
          )}
          <button type="button" className="btn btn-primary self-start min-h-11" disabled={busy} onClick={start}>{busy ? 'Starting…' : 'Deploy my app'}</button>
        </div>
      ) : (
        <div className="flex flex-col gap-4" aria-live="polite">
          <h2 className="m-0 text-lg font-semibold">Deploying {deploy.app.name ?? c.name}</h2>
          <JobTimeline job={job} stages={stages} />
          {done && (
            <>
              <div className="p-4 rounded-xl text-sm" style={{ background: 'var(--g-bg)', color: 'var(--g-fg)' }}>Your app is live at <a href={`https://${address}`} target="_blank" rel="noreferrer" className="font-mono font-semibold">{address}</a></div>
              <div className="flex gap-2 flex-wrap"><Link to="/app/domains" className="btn btn-primary no-underline">Connect your own domain →</Link><Link to={`/app/apps/${deploy.app.id}`} className="btn no-underline">Manage app</Link></div>
            </>
          )}
          {failed && (
            <div className="flex flex-col gap-2">
              <div role="alert" className="font-semibold text-danger">Your app did not pass its health check, so we stopped it. Here is what it printed last:</div>
              <pre className="m-0 p-3 rounded-lg bg-code text-code-fg font-mono text-xs max-h-64 overflow-auto whitespace-pre-wrap">{(((job?.result as R | undefined)?.logs_tail as string[] | undefined)?.length ? ((job?.result as R).logs_tail as string[]) : failLogs.data ?? []).join('\n') || 'No output.'}</pre>
              <p className="m-0 text-sm text-muted">Check the port number and environment variables, then try again.</p>
              <div className="flex gap-2 flex-wrap"><button type="button" className="btn btn-primary" disabled={busy} onClick={retry}>Retry</button><button type="button" className="btn" onClick={() => { setDeploy(null); setStep(1) }}>Change settings</button></div>
            </div>
          )}
        </div>
      ),
    },
  ]
  return (
    <>
      <div><h1 className="m-0 text-[28px] font-bold">Host a Docker app</h1><p className="m-0 mt-1.5 text-sm text-muted">Four steps — we handle the server, HTTPS and restarts</p></div>
      <Card>
        <StepWizard steps={steps} step={step} maxReached={deploy ? 3 : max} busy={busy || (!!deploy && !done && !failed)} error={err}
          onStep={(n) => { if (deploy && n < 3 && !failed) return; setErr(null); setStep(n) }} hideNav={step === 3} />
        {step === 3 && !deploy && <div className="mt-4"><button type="button" className="btn" onClick={() => setStep(2)}>Back</button></div>}
      </Card>
    </>
  )
}
