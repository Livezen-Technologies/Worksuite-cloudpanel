import { useMemo, useState } from 'react'
import { Link } from 'react-router'
import { useAction, useOne } from '../api/hooks'
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

export function ProjectCanvas({ projectId }: { projectId: string }) {
  const q = useOne<R>(`/app/projects/${projectId}/canvas`, undefined, { refetchInterval: 15000 })
  const { run, busy } = useAction()
  const [sel, setSel] = useState<string | null>(null)
  const nodes = useMemo(() => (q.item?.nodes as R[]) ?? [], [q.item])
  const links = useMemo(() => (q.item?.links as R[]) ?? [], [q.item])
  const pos = useMemo(() => layout(nodes, links), [nodes, links])
  const width = Math.max(...Object.values(pos).map((p) => p.x + W + PAD), 320)
  const height = Math.max(...Object.values(pos).map((p) => p.y + H + PAD), 160)
  const cur = nodes.find((n) => n.id === sel)

  if (q.item && nodes.length === 0) return <p className="m-0 text-sm text-muted">This project is empty. Add an app to see it on the canvas.</p>

  return (
    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">
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
              <button key={String(n.id)} type="button" role="listitem" aria-pressed={on} aria-label={`${n.name}, ${state}`} onClick={() => setSel(on ? null : String(n.id))}
                className="absolute card p-3 text-left cursor-pointer text-fg flex flex-col gap-1.5"
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
      <aside className="rounded-lg border border-line p-4 self-start flex flex-col gap-3" aria-label="Selected app">
        {!cur ? <p className="m-0 text-sm text-muted">Select an app on the canvas to see its details. Lines show which app calls which, from their variables.</p> : <>
          <div className="flex items-center gap-2"><span className="font-semibold">{String(cur.name)}</span><Badge tone={TONE[String(cur.state)] ?? 'n'}>{String(cur.state)}</Badge></div>
          <dl className="m-0 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
            <dt className="text-muted">Size</dt><dd className="m-0">{String(cur.size ?? '—')}{cur.ram_limit_mb ? ` · ${cur.ram_limit_mb} MB` : ''}</dd>
            <dt className="text-muted">Usage</dt><dd className="m-0">{cur.cpu_pct != null ? `${Math.round(Number(cur.cpu_pct))}% CPU` : '—'}{cur.ram_mb != null ? ` · ${Math.round(Number(cur.ram_mb))} MB` : ''}</dd>
            <dt className="text-muted">Region</dt><dd className="m-0">{String(cur.region ?? '—')}</dd>
            <dt className="text-muted">Cost</dt><dd className="m-0">${(Number(cur.cost_cents ?? 0) / 100).toFixed(2)}/mo</dd>
            <dt className="text-muted">Calls</dt><dd className="m-0">{links.filter((l) => l.from === cur.id).map((l) => `${nodes.find((n) => n.id === l.to)?.name} (${l.via})`).join(', ') || '—'}</dd>
          </dl>
          <div><div className="text-xs font-semibold mb-1">Recent deploys</div>
            {((cur.deployments as R[]) ?? []).length === 0 ? <p className="m-0 text-xs text-muted">None yet.</p> : (
              <ul className="list-none m-0 p-0 flex flex-col gap-1">{((cur.deployments as R[]) ?? []).map((d) => (
                <li key={String(d.id)} className="flex items-center gap-2 text-xs"><Badge tone={DEP[String(d.status)] ?? 'n'}>{String(d.status)}</Badge><span className="text-muted">{String(d.source)} · {ago(String(d.created_at))}</span></li>
              ))}</ul>
            )}
          </div>
          <div className="flex flex-wrap gap-2">
            <Link className="btn btn-sm" to={`/app/apps/${cur.id}`}>Open app</Link>
            <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/app/apps/${cur.id}/redeploy`, label: `Redeploy ${cur.name}` }).then(() => q.refetch()).catch(() => {})}>Redeploy</button>
            <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/app/apps/${cur.id}/restart`, label: `Restart ${cur.name}` }).catch(() => {})}>Restart</button>
          </div>
        </>}
      </aside>
    </div>
  )
}
