import { useEffect, useState } from 'react'
import { useAction, useOne } from '../api/hooks'
import { Card, Field, StatTile, Toggle } from './Card'
import { Badge } from './Badge'
import Select from './Select'
import { ago } from '../lib/format'
import { num, type R } from '../lib/util'

const MODE: Record<string, [string, string]> = {
  fixed: ['Fixed', 'Always this many copies'],
  autoscale: ['Autoscale', 'Add a copy when CPU stays high, remove one when it stays low'],
  scheduled: ['Scheduled', 'More copies during set hours'],
}

/**
 * SCL-1..5, 9 / USCL-1..3: copies of an app, how they scale, health checks and sticky sessions.
 * `path` is the API path: /applications/{id}/scaling for admins, /app/apps/{id}/scaling for customers.
 */
export function ScalingPanel({ path }: { path: string }) {
  const page = useOne<R>(path, undefined, { refetchInterval: 15000 })
  const { run, busy } = useAction()
  const w = page.item ?? {}
  const s = (w.settings ?? {}) as R
  const lim = (w.limits ?? {}) as R
  const [f, setF] = useState<R | null>(null)
  useEffect(() => { if (page.item && !f) setF({ ...(page.item.settings as R) }) }, [page.item, f])
  const save = (body: R, label: string, success: string) => run({ path, method: 'PATCH', body, label, success }).then(() => { setF(null); page.refetch() }).catch(() => {})
  const replicas = num(w.replicas) || 1
  const max = num(lim.max_replicas) || 1
  const modes = Object.keys(MODE).filter((m) => m === 'fixed' || (m === 'autoscale' ? lim.autoscale : lim.schedule))
  const health = ((f?.health ?? s.health) ?? {}) as R
  if (!page.item || !f) return <Card>Loading…</Card>

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
        <StatTile label="Copies" value={replicas} delta={`up to ${max} on this plan`} />
        <StatTile label="Healthy" value={`${num(w.healthy)} / ${((w.copies as R[]) ?? []).length}`} deltaTone={num(w.healthy) < ((w.copies as R[]) ?? []).length ? 'r' : undefined} />
        <StatTile label="Scaling" value={MODE[String(s.mode)]?.[0] ?? String(s.mode)} delta={s.mode === 'autoscale' ? `${s.min}–${s.max} copies at ${s.metric === 'rps' ? `${s.rps_target} req/s per copy` : `${s.cpu_target}% CPU`}` : undefined} />
      </div>

      {((w.warnings as string[]) ?? []).length > 0 && replicas > 1 && <Card>
        <ul className="m-0 pl-5 text-sm flex flex-col gap-1">{(w.warnings as string[]).map((x) => <li key={x}>{x}</li>)}</ul>
      </Card>}

      <Card title="Copies" actions={<span className="flex items-center gap-2">
        <button type="button" className="btn btn-sm" aria-label="Remove a copy" disabled={busy || replicas <= 1 || s.mode !== 'fixed'} onClick={() => save({ replicas: replicas - 1 }, 'Remove a copy', `${replicas - 1} copies`)}>−</button>
        <span className="font-semibold w-6 text-center">{replicas}</span>
        <button type="button" className="btn btn-sm" aria-label="Add a copy" disabled={busy || replicas >= max || s.mode !== 'fixed'} onClick={() => save({ replicas: replicas + 1 }, 'Add a copy', `${replicas + 1} copies`)}>+</button>
      </span>}>
        <p className="m-0 mb-3 text-xs text-muted">Visitors are spread across every healthy copy. A copy that fails its health check stops getting traffic until it recovers. Removing a copy lets it finish its current requests for up to 30 seconds.{s.mode !== 'fixed' ? ' Switch to Fixed to change the count by hand.' : ''}</p>
        <ul className="m-0 p-0 list-none flex flex-col text-sm">
          {((w.copies as R[]) ?? []).map((c) => <li key={String(c.name)} className="flex flex-wrap items-center gap-3 py-2 border-t border-line first:border-t-0">
            <span className="font-mono text-xs flex-1 min-w-0">{String(c.name)}{c.server ? <span className="text-muted"> · {String(c.server)}</span> : null}</span>
            {c.cpu_pct != null ? <span className="text-xs text-muted">CPU {Math.round(num(c.cpu_pct))}%{c.mem_mb != null ? ` · ${Math.round(num(c.mem_mb))} MB` : ''}</span> : null}
            <Badge tone={c.state === 'running' && (!c.health || c.health === 'healthy') ? 'g' : c.state === 'running' ? 'y' : 'n'}>{String(c.health && c.state === 'running' ? c.health : c.state)}</Badge>
          </li>)}
        </ul>
      </Card>

      {lim.placement ? <PlacementCard path={path} settings={s} placement={(w.placement as R[]) ?? []} busy={busy} save={save} /> : null}
      {lim.canary ? <CanaryCard path={path.replace(/\/scaling$/, '/canary')} canary={(s.canary as R | null) ?? null} onDone={() => page.refetch()} /> : null}

      <Card title="How it scales">
        <div className="flex flex-wrap gap-2 mb-4" role="radiogroup" aria-label="Scaling mode">
          {modes.map((m) => <button key={m} type="button" role="radio" aria-checked={f.mode === m} onClick={() => setF({ ...f, mode: m })}
            className={`flex-[1_1_200px] text-left p-3 rounded-lg border ${f.mode === m ? 'border-accent bg-[var(--sel)]' : 'border-line bg-card'}`}>
            <span className="block font-semibold text-sm">{MODE[m][0]}</span><span className="block text-xs text-muted">{MODE[m][1]}</span>
          </button>)}
        </div>
        {f.mode !== 'fixed' && <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(160px,1fr))]">
          <Field label="Fewest copies"><input className="input" type="number" min={1} max={max} value={String(f.min)} onChange={(e) => setF({ ...f, min: Number(e.target.value) })} /></Field>
          <Field label="Most copies"><input className="input" type="number" min={1} max={max} value={String(f.max)} onChange={(e) => setF({ ...f, max: Number(e.target.value) })} /></Field>
          {f.mode === 'autoscale' && <Field label="Scale on">
            <Select className="input" value={String(f.metric ?? 'cpu')} onChange={(e) => setF({ ...f, metric: e.target.value })}><option value="cpu">CPU</option><option value="rps">Requests per second</option></Select>
          </Field>}
          {f.mode === 'autoscale' && f.metric === 'rps' && <Field label="Requests/s per copy" hint="Needs request metrics (Prometheus). Same 3-minute / 15-minute rule as CPU.">
            <input className="input" type="number" min={1} value={String(f.rps_target ?? 50)} onChange={(e) => setF({ ...f, rps_target: Number(e.target.value) })} />
          </Field>}
          {f.mode === 'autoscale' && f.metric !== 'rps' && <Field label="Target CPU" hint="Add a copy after 3 minutes above it; remove one after 15 minutes under half of it.">
            <Select className="input" value={String(f.cpu_target)} onChange={(e) => setF({ ...f, cpu_target: Number(e.target.value) })}>{[50, 60, 70, 80, 90].map((v) => <option key={v} value={v}>{v}%</option>)}</Select>
          </Field>}
        </div>}
        {f.mode === 'scheduled' && <div className="mt-3 flex flex-col gap-2 text-sm">
          {((f.schedule as R[]) ?? []).map((win, i) => <div key={i} className="flex flex-wrap items-center gap-2">
            <span>Weekdays</span>
            <input className="input w-24" aria-label="From" value={String(win.from)} onChange={(e) => setF({ ...f, schedule: (f.schedule as R[]).map((x, j) => (j === i ? { ...x, from: e.target.value } : x)) })} />
            <span>to</span>
            <input className="input w-24" aria-label="To" value={String(win.to)} onChange={(e) => setF({ ...f, schedule: (f.schedule as R[]).map((x, j) => (j === i ? { ...x, to: e.target.value } : x)) })} />
            <input className="input w-20" type="number" aria-label="Copies" min={1} max={max} value={String(win.replicas)} onChange={(e) => setF({ ...f, schedule: (f.schedule as R[]).map((x, j) => (j === i ? { ...x, replicas: Number(e.target.value) } : x)) })} />
            <span>copies (UTC)</span>
            <button type="button" className="btn btn-sm btn-ghost" onClick={() => setF({ ...f, schedule: (f.schedule as R[]).filter((_, j) => j !== i) })}>Remove</button>
          </div>)}
          <button type="button" className="btn btn-sm self-start" onClick={() => setF({ ...f, schedule: [...((f.schedule as R[]) ?? []), { days: [1, 2, 3, 4, 5], from: '09:00', to: '18:00', replicas: 2 }] })}>Add a window</button>
        </div>}
        <div className="mt-4 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(160px,1fr))]">
          <Field label="Health check path"><input className="input font-mono" value={String(health.path ?? '/')} onChange={(e) => setF({ ...f, health: { ...health, path: e.target.value } })} /></Field>
          <Field label="Check every (s)"><input className="input" type="number" min={2} value={String(health.interval ?? 10)} onChange={(e) => setF({ ...f, health: { ...health, interval: Number(e.target.value) } })} /></Field>
          <Field label="Timeout (s)"><input className="input" type="number" min={1} value={String(health.timeout ?? 5)} onChange={(e) => setF({ ...f, health: { ...health, timeout: Number(e.target.value) } })} /></Field>
        </div>
        <div className="mt-4 flex items-center gap-3 text-sm">
          <Toggle checked={!!f.sticky} label="Sticky sessions" onChange={(v) => setF({ ...f, sticky: v })} />
          <span><span className="font-semibold">Sticky sessions</span> <span className="text-muted">— keep each visitor on one copy. Better: store sessions in Redis.</span></span>
        </div>
        <div className="mt-4 max-w-xs">
          <Field label="Spread requests" hint="Least busy picks the less loaded of two copies for each request">
            <Select className="input" value={String(f.strategy ?? 'round_robin')} onChange={(e) => setF({ ...f, strategy: e.target.value })}>
              <option value="round_robin">Evenly, in turn</option><option value="least_connections">To the least busy copy</option>
            </Select>
          </Field>
        </div>
        <div className="mt-4 flex flex-wrap items-center gap-3">
          <button type="button" className="btn btn-primary" disabled={busy} onClick={() => save({ mode: f.mode, min: f.min, max: f.max, cpu_target: f.cpu_target, ...(f.mode === 'autoscale' ? { metric: f.metric ?? 'cpu', rps_target: f.rps_target ?? 50 } : {}), schedule: f.schedule ?? [], sticky: !!f.sticky, strategy: f.strategy ?? 'round_robin', health: { path: health.path, interval: health.interval, timeout: health.timeout } }, 'Save scaling', 'Scaling saved')}>Save</button>
          <span className="text-xs text-muted">{((w.notes as string[]) ?? []).join(' ')}</span>
        </div>
      </Card>

      <Card title="Scaling history">
        {((w.events as R[]) ?? []).length === 0 ? <p className="m-0 text-sm text-muted">No changes yet.</p> : <ul className="m-0 p-0 list-none flex flex-col text-sm">
          {(w.events as R[]).map((e, i) => <li key={i} className="flex flex-wrap items-center gap-2 py-1.5 border-t border-line first:border-t-0">
            <span className="font-semibold">{String(e.from)} → {String(e.to)}</span>
            <span className="flex-1 min-w-0 text-muted">{String(e.reason)}</span>
            {e.result === 'at_max' ? <Badge tone="y">at maximum</Badge> : null}
            <span className="text-xs text-muted">{ago(String(e.at))}</span>
          </li>)}
        </ul>}
      </Card>
    </div>
  )
}

/** SCL-6: servers that may run copies; they must be on the private network. */
function PlacementCard({ settings, placement, busy, save }: { path: string; settings: R; placement: R[]; busy: boolean; save: (b: R, l: string, s: string) => void }) {
  const mesh = useOne<R>('/mesh')
  const chosen = ((settings.servers as string[]) ?? [])
  const members = ((mesh.item?.members as R[]) ?? [])
  const primary = placement[0]
  const toggle = (id: string, on: boolean) => save({ servers: on ? [...chosen, id] : chosen.filter((x) => x !== id) }, 'Change placement', 'Placement saved')
  return (
    <Card title="Placement" subtitle="Spread copies over more servers; they talk over the private network">
      {!primary?.mesh_ip ? <p className="m-0 text-sm text-muted">Add this app's server to the private network (Resources → Private network) to place copies on other servers.</p> : (
        <ul className="m-0 p-0 list-none flex flex-col text-sm">
          {members.filter((m) => m.id !== primary.id).map((m) => (
            <li key={String(m.id)} className="flex items-center gap-3 py-2 border-t border-line first:border-t-0">
              <span className="flex-1">{String(m.name)} <span className="font-mono text-xs text-muted">{String(m.mesh_ip)}</span></span>
              <Toggle checked={chosen.includes(String(m.id))} disabled={busy} onChange={(v) => toggle(String(m.id), v)} label={`Run copies on ${String(m.name)}`} />
            </li>
          ))}
          {members.length < 2 && <li className="text-muted">No other servers are on the private network.</li>}
        </ul>
      )}
      <p className="m-0 mt-3 text-xs text-muted">Copies are dealt in turn over {placement.map((x) => String(x.name)).join(', ') || 'the app\'s server'}. Change the count above to move them.</p>
    </Card>
  )
}

/** SCL-7: try another image on a share of the traffic, then promote or abort it. */
function CanaryCard({ path, canary, onDone }: { path: string; canary: R | null; onDone: () => void }) {
  const { run, busy } = useAction()
  const [image, setImage] = useState('')
  const [weight, setWeight] = useState('10')
  const act = (a: Parameters<typeof run>[0]) => run(a).then(onDone).catch(() => {})
  if (!canary) {
    return (
      <Card title="Canary release" subtitle="Send a share of visitors to another image before rolling it out">
        <div className="flex flex-wrap items-end gap-3">
          <Field label="Image"><input className="input font-mono text-xs" value={image} onChange={(e) => setImage(e.target.value)} placeholder="registry/app:2.0" /></Field>
          <Field label="Traffic"><Select className="input" value={weight} onChange={(e) => setWeight(e.target.value)}>{[1, 5, 10, 25, 50].map((n) => <option key={n} value={n}>{n}%</option>)}</Select></Field>
          <button type="button" className="btn btn-primary" disabled={busy || !image} onClick={() => act({ path, body: { image, weight: Number(weight) }, label: 'Start canary', success: 'Canary starting' })}>Start canary</button>
        </div>
      </Card>
    )
  }
  return (
    <Card title={<span className="flex items-center gap-2">Canary release <Badge tone="y">{num(canary.weight)}% of traffic</Badge></span>}
      subtitle={<span className="font-mono text-xs">{String(canary.image)}</span>}
      actions={<>
        <button type="button" className="btn btn-sm btn-primary" disabled={busy} onClick={() => act({ path: `${path}/promote`, label: 'Promote canary', success: 'Rolling out the canary' })}>Promote</button>
        <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => act({ path, method: 'DELETE', label: 'Abort canary', success: 'Canary removed' })}>Abort</button>
      </>}>
      <div className="flex flex-wrap gap-2">
        {[5, 10, 25, 50, 75].map((n) => <button key={n} type="button" className="btn btn-sm" aria-pressed={num(canary.weight) === n} disabled={busy} onClick={() => act({ path, method: 'PATCH', body: { weight: n }, label: `Canary at ${n}%`, success: `Canary at ${n}%` })}>{n}%</button>)}
      </div>
      <p className="m-0 mt-3 text-xs text-muted">Started {canary.started_at ? ago(String(canary.started_at)) : ''}. Promote deploys this image as the next release; abort removes it and sends all traffic back.</p>
    </Card>
  )
}
