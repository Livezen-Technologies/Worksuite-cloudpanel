import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { useAction, useList, useOne } from '../../api/hooks'
import { Card, Field, Meter, PageHeader, Toggle, Empty } from '../../components/Card'
import { StatusBadge } from '../../components/Badge'
import { Tabs } from '../../components/Tabs'
import { ConfirmDialog } from '../../components/Overlay'
import { LogView } from '../../components/LogView'
import { ago, mb } from '../../lib/format'
import { num, type R } from '../../lib/util'
import { ScalingPanel } from '../../components/ScalingPanel'
import Select from '../../components/Select'
import { BuildUsage } from '../../components/BuildUsage'

/** Customer-friendly state words (no Docker jargon). */
export function appState(s?: string): string {
  const m: Record<string, string> = { running: 'running', exited: 'stopped', stopped: 'stopped', restarting: 'restarting', deploying: 'deploying', failed: 'failed', unhealthy: 'having trouble', created: 'starting', pending: 'starting' }
  return m[String(s)] ?? (s || 'unknown')
}
export const appDomain = (a: R): string | undefined => (a.address ? String(a.address).replace(/^https?:\/\//, '') : undefined) ?? a.primary_domain ?? a.domains?.[0]?.name

export function MyApps() {
  const { rows, isLoading, error } = useList<R>('/app/apps', undefined, { refetchInterval: 15000 })
  const nav = useNavigate()
  return (
    <>
      <PageHeader title="My apps" subtitle="Everything you host, with its address and health"
        actions={<Link to="/app/host" className="btn btn-primary no-underline">+ Host a Docker app</Link>} />
      {rows.some((a) => a.source_type === 'git') && <BuildUsage path="/app/builds/usage" />}
      {error && <div role="alert" className="card p-4 text-danger text-sm">We could not load your apps. Refresh the page to try again.</div>}
      {isLoading ? <Card>Loading your apps…</Card> : rows.length === 0 ? (
        <Card><Empty>You are not hosting anything yet. <Link to="/app/host" className="font-semibold">Host your first app</Link> — it takes about two minutes.</Empty></Card>
      ) : (
        <ul className="list-none m-0 p-0 grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(300px,1fr))]">
          {rows.map((a) => (
            <li key={a.id} className="card p-5 flex flex-col gap-3.5">
              <div className="flex items-center gap-3">
                <span aria-hidden="true" className="w-10 h-10 rounded-[10px] bg-sel text-accent-fg font-bold flex items-center justify-center flex-none">{String(a.name)[0]?.toUpperCase()}</span>
                <div className="flex-1 min-w-0"><div className="font-bold truncate">{a.name}</div><div className="font-mono text-xs text-muted truncate">{a.image ?? a.source?.image ?? a.source?.repo ?? a.source?.type}</div></div>
                <StatusBadge status={appState(a.state ?? a.status)} />
              </div>
              {appDomain(a) && <a href={`https://${appDomain(a)}`} target="_blank" rel="noreferrer" className="font-mono text-xs truncate">{appDomain(a)}</a>}
              <div className="flex flex-wrap gap-4 text-xs text-muted"><span>CPU {a.cpu_pct != null ? `${Math.round(a.cpu_pct)}%` : '—'}</span><span>RAM {mb(a.mem_mb ?? a.ram_mb)}</span><span>Updated {ago(a.last_deploy_at ?? a.updated_at)}</span></div>
              <div className="flex gap-2 flex-wrap">
                <button type="button" className="btn btn-primary flex-1" onClick={() => nav(`/app/apps/${a.id}`)}>Manage</button>
                <button type="button" className="btn flex-1" onClick={() => nav(`/app/apps/${a.id}?tab=logs`)}>Logs</button>
                <button type="button" className="btn flex-1" onClick={() => nav(`/app/domains?app=${a.id}`)}>Domain</button>
              </div>
            </li>
          ))}
        </ul>
      )}
    </>
  )
}

type Tab = 'overview' | 'scaling' | 'logs' | 'env' | 'releases' | 'settings'
interface Env { key: string; value: string; secret: boolean; masked?: boolean }

const SIZES = [['small', 'Small — 0.5 vCPU · 512 MB · 5 GB'], ['standard', 'Standard — 1 vCPU · 1 GB · 10 GB'], ['pro', 'Pro — 2 vCPU · 4 GB · 40 GB']] as const

export function AppDetail() {
  const { id } = useParams()
  const [params, setParams] = useSearchParams()
  const tab = (params.get('tab') as Tab) || 'overview'
  const nav = useNavigate()
  const { item: a, isLoading } = useOne<R>(`/app/apps/${id}`, undefined, { refetchInterval: 15000 })
  const metrics = useOne<R>(tab === 'overview' ? `/app/apps/${id}/metrics` : null, undefined, { refetchInterval: 15000 })
  const releases = useList<R>(tab === 'releases' ? `/app/apps/${id}/releases` : null)
  const { run, busy } = useAction()
  const [env, setEnv] = useState<Env[]>([])
  const [settings, setSettings] = useState<R>({})
  const [del, setDel] = useState(false)
  const [rollback, setRollback] = useState<R | null>(null)
  useEffect(() => {
    if (!a) return
    setEnv(((a.env ?? []) as R[]).map((e) => ({ key: e.key, value: e.secret ? '' : String(e.value ?? ''), secret: !!e.secret, masked: !!e.secret })))
    setSettings({ size: a.size ?? 'standard', auto_deploy: !!a.auto_deploy, auto_restart: a.auto_restart !== false, health_path: a.health_path ?? '/' })
  }, [a?.id]) // eslint-disable-line react-hooks/exhaustive-deps
  if (isLoading || !a) return <Card>{isLoading ? 'Loading…' : 'We could not find this app.'}</Card>
  // Metrics are series ({range, cpu:[{t,v}], ram:[{t,v}] in MB}); the app itself carries the latest cpu_pct/ram_mb/limits.
  const m = (metrics.item ?? {}) as R
  const last = (k: string) => { const xs = (m[k] ?? []) as R[]; return xs.length ? num(xs[xs.length - 1]) : null }
  const cpu = last('cpu') ?? (a.cpu_pct != null ? num(a.cpu_pct) : null)
  const ram = last('ram') ?? (a.ram_mb != null ? num(a.ram_mb) : null)
  const ramLimit = a.ram_limit_mb != null ? num(a.ram_limit_mb) : null
  // A just-deployed app has no stats until the agent's next heartbeat.
  const pending = (a.state ?? a.status) === 'running' ? 'collecting…' : '—'
  const stats = [
    ['CPU', cpu != null ? `${Math.round(cpu)}%` : pending, cpu ?? 0],
    ['Memory', ram != null ? `${mb(ram)}${ramLimit ? ` of ${mb(ramLimit)}` : ''}` : pending, ram != null && ramLimit ? (ram / ramLimit) * 100 : 0],
    ['Storage', a.storage_gb != null ? `${num(a.storage_gb)} GB included` : '—', -1],
    ['Requests (24 h)', a.requests_24h != null ? Number(a.requests_24h).toLocaleString() : 'not tracked yet', -1],
  ] as const
  const addresses: string[] = [...new Set([
    ...((a.addresses ?? []) as string[]),
    ...(a.domains ?? []).filter((d: R | string) => typeof d === 'string' || d.dns_status === 'verified').map((d: R | string) => (typeof d === 'string' ? d : d.name ?? d.domain)),
  ].filter(Boolean).map((x) => String(x).replace(/^https?:\/\//, '')))]
  const saveEnv = async () => {
    // Secret values left blank keep their current value on the server.
    // PATCH with env (and restart: true) redeploys once and returns the job_id (docs/API.md appendix).
    await run({ method: 'PATCH', path: `/app/apps/${a.id}`, body: { restart: true, env: env.filter((e) => e.key).map((e) => ({ key: e.key, secret: e.secret, ...(e.masked && !e.value ? {} : { value: e.value }) })) }, label: `Save & restart ${a.name}` })
  }
  return (
    <>
      <Link to="/app" className="text-sm font-semibold inline-flex items-center min-h-[44px]">← My apps</Link>
      <div className="flex flex-wrap items-center gap-3">
        <span aria-hidden="true" className="w-12 h-12 rounded-xl bg-sel text-accent-fg font-bold text-lg flex items-center justify-center">{String(a.name)[0]?.toUpperCase()}</span>
        <div className="flex-1 min-w-[200px]">
          <h1 className="m-0 text-[26px] font-bold">{a.name}</h1>
          <div className="text-sm text-muted"><span className="font-mono">{a.image ?? a.source?.image ?? a.source?.repo}</span>{a.size ? ` · ${a.size} plan` : ''}{a.region ? ` · ${a.region}` : ''}</div>
        </div>
        <StatusBadge status={appState(a.state ?? a.status)} />
        <button type="button" className="btn" disabled={busy} onClick={() => run({ path: `/app/apps/${a.id}/restart`, label: `Restart ${a.name}` }).catch(() => {})}>Restart</button>
        <button type="button" className="btn btn-primary" disabled={busy} onClick={() => run({ path: `/app/apps/${a.id}/redeploy`, label: `Redeploy ${a.name}` }).catch(() => {})}>Redeploy</button>
      </div>
      <Tabs value={tab} onChange={(t) => setParams({ tab: t })} label="App sections" tabs={[{ id: 'overview', label: 'Overview' }, { id: 'scaling', label: 'Scaling' }, { id: 'logs', label: 'Logs' }, { id: 'env', label: 'Environment' }, { id: 'releases', label: 'Releases' }, { id: 'settings', label: 'Settings' }]} />
      {tab === 'overview' && (
        <>
          {a.last_deployment?.status === 'failed' && (
            <Card title="Last deploy failed">
              <p className="mt-0 text-sm text-danger font-semibold" role="alert">The new version did not pass its health check. Last lines it printed:</p>
              <pre className="m-0 p-3 rounded-lg bg-code text-code-fg font-mono text-xs max-h-64 overflow-auto whitespace-pre-wrap">{((a.last_deployment.logs_tail ?? []) as string[]).join('\n') || 'No output.'}</pre>
              <button type="button" className="btn btn-primary mt-3" disabled={busy} onClick={() => run({ path: `/app/apps/${a.id}/redeploy`, label: `Retry ${a.name}` }).catch(() => {})}>Retry</button>
            </Card>
          )}
          <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
            {stats.map(([k, v, p]) => (
              <div key={k} className="card p-5"><div className="text-[13px] text-muted">{k}</div><div className="text-lg font-bold mt-1.5">{v}</div>{p >= 0 && <div className="mt-3"><Meter value={p} label={k} /></div>}</div>
            ))}
          </div>
          <Card title="Addresses">
            <div className="flex flex-col gap-2">{addresses.length === 0 ? <span className="text-sm text-muted">Your address appears here once the app is live.</span> : addresses.map((x) => <a key={x} href={`https://${x}`} target="_blank" rel="noreferrer" className="font-mono text-sm min-h-[44px] inline-flex items-center">https://{x}</a>)}</div>
          </Card>
        </>
      )}
      {tab === 'scaling' && <ScalingPanel path={`/app/apps/${a.id}/scaling`} />}
      {tab === 'logs' && <Card><LogView path={`/app/apps/${a.id}/logs`} title="What your app is printing" height={420} /></Card>}
      {tab === 'env' && (
        <Card title="Environment variables">
          <p className="mt-0 text-sm text-muted">Settings your app reads at start-up. Secret values are hidden; leave a secret blank to keep its current value.</p>
          <div className="flex flex-col gap-2">
            {env.map((e, i) => (
              <div key={i} className="flex flex-wrap gap-2 items-center">
                <input className="input font-mono flex-1 min-w-[140px]" aria-label={`Variable ${i + 1} name`} value={e.key} onChange={(x) => setEnv(env.map((y, j) => (j === i ? { ...y, key: x.target.value } : y)))} />
                <input className="input font-mono flex-[2] min-w-[160px]" aria-label={`Variable ${i + 1} value`} type={e.secret ? 'password' : 'text'} placeholder={e.masked ? '•••••••• (unchanged)' : ''} value={e.value} onChange={(x) => setEnv(env.map((y, j) => (j === i ? { ...y, value: x.target.value } : y)))} />
                <label className="flex items-center gap-1.5 text-sm min-h-[44px]"><input type="checkbox" checked={e.secret} onChange={(x) => setEnv(env.map((y, j) => (j === i ? { ...y, secret: x.target.checked } : y)))} />Secret</label>
                <button type="button" className="btn" aria-label={`Remove variable ${e.key || i + 1}`} onClick={() => setEnv(env.filter((_, j) => j !== i))}>Remove</button>
              </div>
            ))}
          </div>
          <div className="flex flex-wrap justify-between gap-2 mt-4">
            <button type="button" className="btn" onClick={() => setEnv([...env, { key: '', value: '', secret: false }])}>+ Add variable</button>
            <button type="button" className="btn btn-primary" disabled={busy} onClick={() => saveEnv().catch(() => {})}>Save &amp; restart</button>
          </div>
        </Card>
      )}
      {tab === 'releases' && (
        <Card title="Releases">
          {releases.isLoading ? 'Loading…' : releases.rows.length === 0 ? <Empty>No releases yet.</Empty> : releases.rows.map((r) => (
            <div key={r.id} className="flex flex-wrap items-center gap-3 py-3 border-t border-line first:border-t-0 text-sm">
              <span className="font-mono font-semibold">{r.version ?? r.id.slice(0, 7)}</span>
              <StatusBadge status={r.current || r.status === 'active' ? 'live' : r.status ?? 'previous'} />
              <span className="font-mono text-xs text-muted flex-1 min-w-[120px] truncate">{r.note ?? r.message ?? r.image ?? ''}</span>
              <span className="text-muted">{ago(r.created_at)}</span>
              {!(r.current || r.status === 'active') && r.status !== 'failed' && <button type="button" className="btn" onClick={() => setRollback(r)}>Roll back</button>}
            </div>
          ))}
        </Card>
      )}
      {tab === 'settings' && (
        <Card>
          <div className="flex flex-col gap-4 max-w-[560px]">
            <Field label="Size"><Select className="input" value={settings.size} onChange={(e) => setSettings({ ...settings, size: e.target.value })}>{SIZES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select></Field>
            <div className="flex items-center gap-3 min-h-[44px]"><Toggle checked={!!settings.auto_deploy} onChange={(v) => setSettings({ ...settings, auto_deploy: v })} label="Auto-deploy when I push" /><span className="text-sm">Auto-deploy when I push to my main branch</span></div>
            <Field label="Health check path" hint="Requested on your app's port after each deploy; 2xx or 3xx means healthy."><input className="input font-mono" value={settings.health_path ?? '/'} onChange={(e) => setSettings({ ...settings, health_path: e.target.value })} /></Field>
            <div className="flex items-center gap-3 min-h-[44px]"><Toggle checked={!!settings.auto_restart} onChange={(v) => setSettings({ ...settings, auto_restart: v })} label="Restart automatically if it crashes" /><span className="text-sm">Restart automatically if it crashes</span></div>
            <button type="button" className="btn btn-primary self-start" disabled={busy} onClick={() => run({ method: 'PATCH', path: `/app/apps/${a.id}`, body: settings, label: 'Save settings', success: 'Settings saved' }).catch(() => {})}>Save settings</button>
            <div className="flex flex-wrap items-center gap-3 justify-between p-4 rounded-xl border border-danger-line mt-4">
              <div><div className="font-semibold text-danger">Delete this app</div><div className="text-sm text-muted">The app is removed. Your data is kept for 7 days in case you change your mind.</div></div>
              <button type="button" className="btn btn-danger" onClick={() => setDel(true)}>Delete app</button>
            </div>
          </div>
        </Card>
      )}
      <ConfirmDialog open={del} onClose={() => setDel(false)} danger confirmText={a.name} title={`Delete ${a.name}?`} confirmLabel="Delete app" busy={busy}
        onConfirm={() => run({ method: 'DELETE', path: `/app/apps/${a.id}`, label: `Delete ${a.name}`, success: `${a.name} deleted. Data is kept for 7 days.` }).then(() => nav('/app')).catch(() => {})}>
        <p className="m-0">Your app stops and its address goes offline. Your data is kept for 7 days, then deleted for good.</p>
      </ConfirmDialog>
      <ConfirmDialog open={!!rollback} onClose={() => setRollback(null)} title={`Go back to ${rollback?.version ?? 'this version'}?`} confirmLabel="Roll back" busy={busy}
        onConfirm={() => run({ path: `/app/apps/${a.id}/rollback`, body: { release_id: rollback!.id }, label: `Roll back ${a.name}` }).then(() => setRollback(null)).catch(() => {})}>
        <p className="m-0">Your app switches to this earlier version. You can move forward again at any time.</p>
      </ConfirmDialog>
    </>
  )
}
