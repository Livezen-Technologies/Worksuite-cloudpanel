export interface Series { label: string; color: string; data: number[]; dashed?: boolean }

const W = 640, H = 220, L = 36, R = 630, T = 12, B = 196

function pathFor(data: number[], max: number) {
  if (!data.length) return ''
  const step = data.length > 1 ? (R - L) / (data.length - 1) : 0
  return data.map((v, i) => `${i ? 'L' : 'M'}${(L + i * step).toFixed(1)} ${(B - (Math.max(0, Math.min(max, v)) / max) * (B - T)).toFixed(1)}`).join(' ')
}

/** Plain-SVG line chart (0–100 % by default) with grid, y labels, optional x labels and area under the first series. */
export function LineChart({ series, xLabels = [], label, max = 100, unit = '%' }: { series: Series[]; xLabels?: string[]; label: string; max?: number; unit?: string }) {
  const ticks = [1, 0.75, 0.5, 0.25, 0]
  const first = series[0]
  return (
    <figure className="m-0">
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={label} className="w-full h-auto">
        <g stroke="var(--line)" strokeWidth="1">{ticks.map((t) => <line key={t} x1={L} x2={R} y1={B - t * (B - T)} y2={B - t * (B - T)} />)}</g>
        <g fill="var(--muted)" fontSize="10" textAnchor="end">{ticks.map((t) => <text key={t} x={30} y={B - t * (B - T) + 4}>{Math.round(t * max)}{unit}</text>)}</g>
        {xLabels.length > 1 && (
          <g fill="var(--muted)" fontSize="10" textAnchor="middle">
            {xLabels.map((x, i) => <text key={i} x={L + (i * (R - L)) / (xLabels.length - 1)} y={214}>{x}</text>)}
          </g>
        )}
        {first && first.data.length > 1 && <path d={`${pathFor(first.data, max)} L${R} ${B} L${L} ${B} Z`} fill={first.color} opacity={0.1} />}
        {series.map((s) => <path key={s.label} d={pathFor(s.data, max)} fill="none" stroke={s.color} strokeWidth={2.2} strokeLinejoin="round" strokeDasharray={s.dashed ? '5 4' : undefined} />)}
      </svg>
      <figcaption className="flex flex-wrap gap-4 text-xs text-muted mt-1.5">
        {series.map((s) => (
          <span key={s.label} className="flex items-center gap-1.5"><span className="w-2.5 h-[3px]" style={{ background: s.color }} />{s.label} {s.data.length ? `${Math.round(s.data[s.data.length - 1])}${unit}` : '—'}</span>
        ))}
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
              {d.failed > 0 && <div style={{ height: `${(d.failed / max) * 100}%`, background: '#DC2626' }} className="rounded-t" />}
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
