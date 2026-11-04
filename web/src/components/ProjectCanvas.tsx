import { useMemo, useRef, useState, type PointerEvent } from 'react'
import { Link } from 'react-router'
import { useAction, useOne } from '../api/hooks'
import { request } from '../api/client'
import { useToast } from './Toasts'
import { Badge, type Tone } from './Badge'
import { ago } from '../lib/format'
import type { R } from '../lib/util'

const W = 220, H = 112, GX = 90, GY = 28, PAD = 16
const TONE: Record<string, Tone> = { running: 'g', deploying: 'b', failed: 'r', stopped: 'n' }
const DEP: Record<string, Tone> = { succeeded: 'g', active: 'g', running: 'b', building: 'b', queued: 'b', failed: 'r', cancelled: 'n' }

/** Columns by dependency depth: an app sits one column right of the deepest app that calls it. */
export function layout(nodes: R[], links: R[]): Record<string, { x: number; y: number }> {
  const depth: Record<string, number> = {}
  const ids = nodes.map((n) => String(n.id))
  ids.forEach((id) => (depth[id] = 0))
  for (let pass = 0; pass < ids.length; pass++) {
    let changed = false
    for (const l of links) {
      const f = String(l.from), t = String(l.to)
      if (depth[f] !== undefined && depth[t] !== undefined && depth[t] < depth[f] + 1 && depth[f] + 1 < ids.length) { depth[t] = depth[f] + 1; changed = true }
    }
    if (!changed) break
  }
  const rows: Record<number, number> = {}
  const pos: Record<string, { x: number; y: number }> = {}
  for (const id of ids) {
    const d = depth[id]
    const r = rows[d] ?? 0
    rows[d] = r + 1
    pos[id] = { x: PAD + d * (W + GX), y: PAD + r * (H + GY) }
  }
  return pos
}

type XY = { x: number; y: number }

/** `staff`: the admin view of any project. Arranging works the same; connecting and app actions are the customer's. */
export function ProjectCanvas({ projectId, staff = false }: { projectId: string; staff?: boolean }) {
  const base = staff ? `/projects/${projectId}/canvas` : `/app/projects/${projectId}/canvas`
  const appHref = (id: unknown) => (staff ? `/admin/applications/${id}` : `/app/apps/${id}`)
  const q = useOne<R>(base, undefined, { refetchInterval: 15000 })
  const { run, busy } = useAction()
  const toast = useToast()
  const [sel, setSel] = useState<string | null>(null)
  const [env, setEnv] = useState<string>('')
  const [moved, setMoved] = useState<Record<string, XY>>({})
  const [target, setTarget] = useState('')
  const [key, setKey] = useState('')
  const drag = useRef<{ id: string; sx: number; sy: number; ox: number; oy: number; on: boolean } | null>(null)
  const envs = useMemo(() => (q.item?.environments as R[]) ?? [], [q.item])
  const all = useMemo(() => (q.item?.nodes as R[]) ?? [], [q.item])
  const nodes = useMemo(() => all.filter((n) => !env || n.environment_id === env), [all, env])
  const links = useMemo(() => ((q.item?.links as R[]) ?? []).filter((l) => nodes.some((n) => n.id === l.from) && nodes.some((n) => n.id === l.to)), [q.item, nodes])
  const saved = useMemo(() => (q.item?.layout as Record<string, XY>) ?? {}, [q.item])
  const pos = useMemo(() => ({ ...layout(nodes, links), ...saved, ...moved }), [nodes, links, saved, moved])
  const width = Math.max(...Object.values(pos).map((p) => p.x + W + PAD), 320)
  const height = Math.max(...Object.values(pos).map((p) => p.y + H + PAD), 160)
  const cur = nodes.find((n) => n.id === sel)
  const hasLayout = Object.keys(saved).length > 0 || Object.keys(moved).length > 0

  const saveLayout = (positions: Record<string, XY>) =>
    request(`${base}/layout`, { method: 'PUT', body: { positions } }).then(() => q.refetch()).catch(() => toast.error(null, 'Could not save the layout'))
  const down = (e: PointerEvent, id: string) => {
    const p = pos[id]
    drag.current = { id, sx: e.clientX, sy: e.clientY, ox: p.x, oy: p.y, on: false }
    ;(e.currentTarget as Element).setPointerCapture?.(e.pointerId)
  }
  const move = (e: PointerEvent) => {
    const d = drag.current
    if (!d) return
    const dx = e.clientX - d.sx, dy = e.clientY - d.sy
    if (!d.on && Math.abs(dx) + Math.abs(dy) < 5) return
    d.on = true
    setMoved((m) => ({ ...m, [d.id]: { x: Math.max(0, Math.round(d.ox + dx)), y: Math.max(0, Math.round(d.oy + dy)) } }))
  }
  const up = (id: string) => {
    const d = drag.current
    drag.current = null
    if (d?.on) {
      const ids = new Set(all.map((n) => String(n.id)))
      const keep = Object.fromEntries(Object.entries({ ...saved, ...moved }).filter(([k]) => ids.has(k)))
      saveLayout(keep)
    } else setSel(sel === id ? null : id)
  }
  const reset = () => { setMoved({}); saveLayout({}) }
  const connect = () => cur && target && run({ path: `${base}/links`, body: { from: cur.id, to: target, key: key.trim() || null }, label: `Connect ${cur.name}`, success: `Connected. Redeploy ${cur.name} to use the new variable.` })
    .then(() => { setTarget(''); setKey(''); q.refetch() }).catch(() => {})
  const unlink = (l: R) => {
    if (!window.confirm(`Delete the variable ${l.via} from ${cur?.name}? The app loses that setting on its next deploy.`)) return
    run({ path: `${base}/links?from=${l.from}&to=${l.to}&key=${encodeURIComponent(String(l.via))}`, method: 'DELETE', label: `Remove ${l.via}` }).then(() => q.refetch()).catch(() => {})
  }

  if (q.item && all.length === 0) return <p className="m-0 text-sm text-muted">This project is empty. Add an app to see it on the canvas.</p>

  return (
    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">
      <div className="min-w-0 flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-2">
        {envs.length > 1 && <div role="group" aria-label="Environment" className="flex flex-wrap gap-1">
          {[{ id: '', name: 'All' }, ...envs].map((e) => <button key={String(e.id)} type="button" className={`btn btn-sm ${env === e.id ? 'btn-primary' : ''}`} aria-pressed={env === e.id} onClick={() => { setEnv(String(e.id)); setSel(null) }}>{String(e.name)}</button>)}
        </div>}
        <span className="text-xs text-muted ml-auto">Drag apps to arrange them.</span>
        {hasLayout && <button type="button" className="btn btn-sm" onClick={reset}>Reset layout</button>}
      </div>
      <div className="overflow-auto rounded-lg border border-line" style={{ background: 'radial-gradient(var(--line) 1px, transparent 1px) 0 0 / 18px 18px' }}>
        <div className="relative" style={{ width, height }} role="list" aria-label="Project canvas">
          <svg className="absolute inset-0 pointer-events-none" width={width} height={height} aria-hidden="true">
            {links.map((l) => {
              const a = pos[String(l.from)], b = pos[String(l.to)]
              if (!a || !b) return null
              const x1 = a.x + W, y1 = a.y + H / 2, x2 = b.x, y2 = b.y + H / 2, mx = (x1 + x2) / 2
              return <path key={`${l.from}>${l.to}`} d={`M${x1} ${y1} C ${mx} ${y1}, ${mx} ${y2}, ${x2} ${y2}`} fill="none" stroke="var(--muted)" strokeWidth={1.5} strokeDasharray="5 4" />
            })}
          </svg>
          {nodes.map((n) => {
            const p = pos[String(n.id)]
            const on = sel === n.id
            const state = String(n.state ?? '')
            return (
              <button key={String(n.id)} type="button" role="listitem" aria-pressed={on} aria-label={`${n.name}, ${state}`}
                onPointerDown={(e) => down(e, String(n.id))} onPointerMove={move} onPointerUp={() => up(String(n.id))}
                onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setSel(on ? null : String(n.id)) } }}
                className="absolute card p-3 text-left cursor-grab text-fg flex flex-col gap-1.5 touch-none select-none"
                style={{ left: p.x, top: p.y, width: W, height: H, ...(on ? { borderColor: 'var(--accent)', boxShadow: '0 0 0 3px color-mix(in srgb, var(--accent) 22%, transparent)' } : {}) }}>
                <span className="flex items-center gap-2 min-w-0"><span className="font-semibold truncate">{String(n.name)}</span><Badge tone={TONE[state] ?? 'n'}>{state}</Badge></span>
                <span className="text-xs text-muted truncate">{String(n.source ?? '')}</span>
                <span className="text-xs truncate">{String(n.domain ?? '')}</span>
                {n.volume_path ? <span className="text-xs text-muted truncate">Volume {String(n.volume_path)}</span> : null}
              </button>
            )
          })}
        </div>
      </div>
      </div>
      <aside className="rounded-lg border border-line p-4 self-start flex flex-col gap-3" aria-label="Selected app">
        {!cur ? <p className="m-0 text-sm text-muted">Select an app on the canvas to see its details. Lines show which app calls which, from their variables.</p> : <>
          <div className="flex items-center gap-2"><span className="font-semibold">{String(cur.name)}</span><Badge tone={TONE[String(cur.state)] ?? 'n'}>{String(cur.state)}</Badge></div>
          <dl className="m-0 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
            <dt className="text-muted">Size</dt><dd className="m-0">{String(cur.size ?? '—')}{cur.ram_limit_mb ? ` · ${cur.ram_limit_mb} MB` : ''}</dd>
            <dt className="text-muted">Usage</dt><dd className="m-0">{cur.cpu_pct != null ? `${Math.round(Number(cur.cpu_pct))}% CPU` : '—'}{cur.ram_mb != null ? ` · ${Math.round(Number(cur.ram_mb))} MB` : ''}</dd>
            <dt className="text-muted">Region</dt><dd className="m-0">{String(cur.region ?? '—')}</dd>
            <dt className="text-muted">Cost</dt><dd className="m-0">${(Number(cur.cost_cents ?? 0) / 100).toFixed(2)}/mo</dd>
          </dl>
          <div><div className="text-xs font-semibold mb-1">Connects to</div>
            {links.filter((l) => l.from === cur.id).length === 0 ? <p className="m-0 text-xs text-muted">Nothing yet.</p> : (
              <ul className="list-none m-0 p-0 flex flex-col gap-1">{links.filter((l) => l.from === cur.id).map((l) => (
                <li key={`${l.to}-${l.via}`} className="flex items-center gap-2 text-xs"><span className="flex-1 min-w-0 truncate">{String(nodes.find((n) => n.id === l.to)?.name)} <span className="text-muted">via {String(l.via)}</span></span>
                  {!staff && <button type="button" className="btn btn-sm" disabled={busy} aria-label={`Remove ${l.via}`} onClick={() => unlink(l)}>Remove</button>}</li>
              ))}</ul>
            )}
            {!staff && nodes.length > 1 && <div className="flex flex-col gap-1.5 mt-2">
              <select className="input" aria-label="Connect to" value={target} onChange={(e) => setTarget(e.target.value)}>
                <option value="">Connect to…</option>
                {nodes.filter((n) => n.id !== cur.id).map((n) => <option key={String(n.id)} value={String(n.id)}>{String(n.name)}</option>)}
              </select>
              {target && <input className="input font-mono" aria-label="Variable name" placeholder={`${String(nodes.find((n) => n.id === target)?.name ?? '').toUpperCase().replace(/[^A-Z0-9]+/g, '_')}_URL`} value={key} onChange={(e) => setKey(e.target.value)} />}
              <button type="button" className="btn btn-sm" disabled={busy || !target} onClick={connect}>Connect</button>
              <p className="m-0 text-xs text-muted">Adds a variable to {String(cur.name)} with the other app’s private address.</p>
            </div>}
          </div>
          <div><div className="text-xs font-semibold mb-1">Recent deploys</div>
            {((cur.deployments as R[]) ?? []).length === 0 ? <p className="m-0 text-xs text-muted">None yet.</p> : (
              <ul className="list-none m-0 p-0 flex flex-col gap-1">{((cur.deployments as R[]) ?? []).map((d) => (
                <li key={String(d.id)} className="flex items-center gap-2 text-xs"><Badge tone={DEP[String(d.status)] ?? 'n'}>{String(d.status)}</Badge><span className="text-muted">{String(d.source)} · {ago(String(d.created_at))}</span></li>
              ))}</ul>
            )}
          </div>
          <div className="flex flex-wrap gap-2">
            <Link className="btn btn-sm" to={appHref(cur.id)}>Open app</Link>
            {!staff && <><button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/app/apps/${cur.id}/redeploy`, label: `Redeploy ${cur.name}` }).then(() => q.refetch()).catch(() => {})}>Redeploy</button>
            <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/app/apps/${cur.id}/restart`, label: `Restart ${cur.name}` }).catch(() => {})}>Restart</button></>}
          </div>
        </>}
      </aside>
    </div>
  )
}
