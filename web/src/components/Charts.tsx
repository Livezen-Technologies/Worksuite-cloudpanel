import { useEffect, useId, useRef, useState } from 'react'
export interface Series { label: string; color: string; data: number[]; dashed?: boolean }


/** Smooth path through the points (monotone cubic, so it never overshoots the data). */
function smooth(pts: [number, number][]) {
  if (pts.length < 2) return ''
  const n = pts.length, d: number[] = [], m: number[] = []
  for (let i = 0; i < n - 1; i++) d.push((pts[i + 1][1] - pts[i][1]) / (pts[i + 1][0] - pts[i][0] || 1))
  m.push(d[0]); for (let i = 1; i < n - 1; i++) m.push(d[i - 1] * d[i] <= 0 ? 0 : (d[i - 1] + d[i]) / 2); m.push(d[n - 2])
  let out = `M${pts[0][0].toFixed(1)} ${pts[0][1].toFixed(1)}`
  for (let i = 0; i < n - 1; i++) {
    const [x0, y0] = pts[i], [x1, y1] = pts[i + 1], h = (x1 - x0) / 3
    out += ` C${(x0 + h).toFixed(1)} ${(y0 + m[i] * h).toFixed(1)} ${(x1 - h).toFixed(1)} ${(y1 - m[i + 1] * h).toFixed(1)} ${x1.toFixed(1)} ${y1.toFixed(1)}`
  }
  return out
}

function niceMax(v: number, cap: number) {
  if (v <= 0) return cap
  const steps = [5, 10, 20, 25, 40, 50, 60, 80, 100, 150, 200, 250, 500, 1000]
  const want = v * 1.15
  return Math.min(cap, steps.find((s) => s >= want) ?? Math.ceil(want / 100) * 100)
}

/**
 * Line chart drawn at the real pixel width (text stays 11px at any size): smooth lines, a soft
 * gradient under each series, a y-axis that fits the data, and a hover guide with every value.
 */
export function LineChart({ series, xLabels = [], label, max = 100, unit = '%', height = 240, autoScale = true }: { series: Series[]; xLabels?: string[]; label: string; max?: number; unit?: string; height?: number; autoScale?: boolean }) {
  const box = useRef<HTMLDivElement>(null)
  const [w, setW] = useState(640)
  const [hover, setHover] = useState<number | null>(null)
  const gid = useId().replace(/:/g, '')
  useEffect(() => {
    const el = box.current
    if (!el || typeof ResizeObserver === 'undefined') return
    const ro = new ResizeObserver(([e]) => setW(Math.max(240, Math.round(e.contentRect.width))))
    ro.observe(el)
    return () => ro.disconnect()
  }, [])
  const top = autoScale ? niceMax(Math.max(0, ...series.flatMap((s) => s.data)), max) : max
  const L = 40, R = w - 8, T = 10, B = height - (xLabels.length > 1 ? 26 : 10)
  const n = Math.max(0, ...series.map((s) => s.data.length))
  const x = (i: number, len: number) => L + (len > 1 ? (i * (R - L)) / (len - 1) : 0)
  const y = (v: number) => B - (Math.max(0, Math.min(top, v)) / top) * (B - T)
  const ticks = [0, 0.25, 0.5, 0.75, 1]
  const fmt = (v: number) => `${v >= 10 || v === 0 ? Math.round(v) : v.toFixed(1)}${unit}`
  const onMove = (e: React.PointerEvent<SVGSVGElement>) => {
    if (n < 2) return
    const px = e.clientX - e.currentTarget.getBoundingClientRect().left
    setHover(Math.max(0, Math.min(n - 1, Math.round(((px - L) / (R - L)) * (n - 1)))))
  }
  const hx = hover == null ? 0 : x(hover, n)
  const stat = (d: number[]) => d.length ? { now: d[d.length - 1], avg: d.reduce((a, b) => a + b, 0) / d.length, peak: Math.max(...d) } : null
  return (
    <figure className="m-0">
      <div ref={box} className="relative">
        <svg width="100%" height={height} viewBox={`0 0 ${w} ${height}`} role="img" aria-label={label} className="block select-none" onPointerMove={onMove} onPointerLeave={() => setHover(null)}>
          <defs>
            {series.map((s, i) => (
              <linearGradient key={s.label} id={`${gid}-${i}`} x1="0" x2="0" y1="0" y2="1">
                <stop offset="0%" stopColor={s.color} stopOpacity={0.22} /><stop offset="100%" stopColor={s.color} stopOpacity={0} />
              </linearGradient>
            ))}
          </defs>
          <g stroke="var(--line)" strokeWidth="1">{ticks.map((t) => <line key={t} x1={L} x2={R} y1={y(t * top)} y2={y(t * top)} strokeDasharray={t ? '3 4' : undefined} />)}</g>
          <g fill="var(--muted)" fontSize="11" textAnchor="end" className="tabular-nums">{ticks.map((t) => <text key={t} x={L - 8} y={y(t * top) + 4}>{fmt(t * top)}</text>)}</g>
          {xLabels.length > 1 && (
            <g fill="var(--muted)" fontSize="11">
              {xLabels.map((lb, i) => <text key={i} x={x(i, xLabels.length)} y={height - 6} textAnchor={i === 0 ? 'start' : i === xLabels.length - 1 ? 'end' : 'middle'}>{lb}</text>)}
            </g>
          )}
          {series.map((s, i) => {
            if (s.data.length < 2) return null
            const pts = s.data.map((v, j) => [x(j, s.data.length), y(v)] as [number, number])
            const line = smooth(pts)
            return (
              <g key={s.label}>
                {!s.dashed && <path d={`${line} L${pts[pts.length - 1][0]} ${B} L${pts[0][0]} ${B} Z`} fill={`url(#${gid}-${i})`} />}
                <path d={line} fill="none" stroke={s.color} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" strokeDasharray={s.dashed ? '5 4' : undefined} />
                {hover == null && <circle cx={pts[pts.length - 1][0]} cy={pts[pts.length - 1][1]} r={3.5} fill="var(--card)" stroke={s.color} strokeWidth={2} />}
              </g>
            )
          })}
          {hover != null && (
            <g>
              <line x1={hx} x2={hx} y1={T} y2={B} stroke="var(--muted)" strokeOpacity={0.4} strokeDasharray="3 3" />
              {series.map((s) => s.data[hover] != null && <circle key={s.label} cx={hx} cy={y(s.data[hover])} r={4} fill={s.color} stroke="var(--card)" strokeWidth={2} />)}
            </g>
          )}
        </svg>
        {hover != null && (
          <div role="status" className="pointer-events-none absolute top-1 rounded-lg border border-line bg-card shadow-over px-3 py-2 text-xs min-w-[120px]"
            style={{ left: Math.min(Math.max(hx + 12, 0), w - 140) }}>
            {xLabels.length > 1 && <div className="text-muted mb-1">{xLabels[Math.round((hover / Math.max(1, n - 1)) * (xLabels.length - 1))]}</div>}
            {series.map((s) => s.data[hover] != null && (
              <div key={s.label} className="flex items-center gap-2"><span className="w-2 h-2 rounded-full" style={{ background: s.color }} /><span className="flex-1">{s.label}</span><span className="font-semibold tabular-nums">{fmt(s.data[hover])}</span></div>
            ))}
          </div>
        )}
      </div>
      <figcaption className="grid gap-2 mt-3 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
        {series.map((s) => {
          const st = stat(s.data)
          return (
            <span key={s.label} className="flex items-center gap-2.5 rounded-lg bg-head px-3 py-2 text-xs">
              <span className="w-1 self-stretch rounded-full" style={{ background: s.color }} />
              <span className="flex flex-col"><span className="text-muted">{s.label}</span><span className="text-base font-semibold tabular-nums text-fg leading-tight">{st ? fmt(st.now) : '—'}</span></span>
              {st && <span className="ml-auto text-right text-muted tabular-nums leading-snug">avg {fmt(st.avg)}<br />peak {fmt(st.peak)}</span>}
            </span>
          )
        })}
      </figcaption>
    </figure>
  )
}

export function Sparkline({ data, color = 'var(--accent)', width = 96, height = 28, label }: { data: number[]; color?: string; width?: number; height?: number; label: string }) {
  const max = Math.max(1, ...data), min = Math.min(0, ...data)
  const step = data.length > 1 ? width / (data.length - 1) : 0
  const d = data.map((v, i) => `${i ? 'L' : 'M'}${(i * step).toFixed(1)} ${(height - 2 - ((v - min) / (max - min || 1)) * (height - 4)).toFixed(1)}`).join(' ')
  return <svg width={width} height={height} role="img" aria-label={label}><path d={d} fill="none" stroke={color} strokeWidth={1.8} /></svg>
}

/** Stacked success/failure bars, one per day. */
export function DayBars({ days, label }: { days: { label: string; ok: number; failed: number }[]; label: string }) {
  const max = Math.max(1, ...days.map((d) => d.ok + d.failed))
  return (
    <div>
      <div role="img" aria-label={label} className="flex items-end gap-1.5 h-[120px]">
        {days.map((d) => {
          const tot = d.ok + d.failed
          return (
            <div key={d.label} className="flex-1 flex flex-col justify-end h-full" title={`${d.label}: ${d.ok} succeeded, ${d.failed} failed`}>
              {d.failed > 0 && <div style={{ height: `${(d.failed / max) * 100}%`, background: 'var(--r-fg)' }} className="rounded-t" />}
              <div style={{ height: `${(d.ok / max) * 100}%`, background: 'var(--accent)' }} className={d.failed ? '' : 'rounded-t'} />
              {tot === 0 && <div className="h-0.5 bg-line" />}
            </div>
          )
        })}
      </div>
      <div className="flex gap-1.5 mt-1">{days.map((d) => <div key={d.label} className="flex-1 text-center text-[10px] text-muted truncate">{d.label}</div>)}</div>
    </div>
  )
}
