import type { ReactNode } from 'react'

export function Card({ title, subtitle, actions, children, className = '', pad = true, id }: { title?: ReactNode; subtitle?: ReactNode; actions?: ReactNode; children?: ReactNode; className?: string; pad?: boolean; id?: string }) {
  return (
    <section className={`card min-w-0 ${pad ? 'p-5' : 'overflow-hidden'} ${className}`} aria-labelledby={title && id ? id : undefined}>
      {(title || actions) && (
        <div className={`flex flex-wrap items-start justify-between gap-2 ${pad ? 'mb-3' : 'px-5 pt-4 pb-3'}`}>
          {title && (
            <div className="min-w-0">
              <h2 id={id} className="m-0 text-[15px] font-semibold">{title}</h2>
              {subtitle && <div className="mt-1 text-[13px] text-muted">{subtitle}</div>}
            </div>
          )}
          {actions && <div className="flex flex-wrap gap-2 items-center">{actions}</div>}
        </div>
      )}
      {children}
    </section>
  )
}

export function PageHeader({ title, subtitle, actions }: { title: string; subtitle?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="flex flex-wrap items-end gap-4">
      <div className="flex-1 min-w-[220px]">
        <h1 className="m-0 text-[28px] font-bold tracking-tight">{title}</h1>
        {subtitle && <p className="mt-1.5 mb-0 text-sm text-muted">{subtitle}</p>}
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  )
}

export function Meter({ value, label, color, thick = false }: { value: number; label?: string; color?: string; thick?: boolean }) {
  const v = Math.max(0, Math.min(100, value || 0))
  const c = color ?? (v >= 90 ? 'var(--r-fg)' : 'var(--accent)')
  return (
    <div role="meter" aria-valuenow={Math.round(v)} aria-valuemin={0} aria-valuemax={100} aria-label={label}
      className={`${thick ? 'h-2' : 'h-1.5'} rounded bg-track overflow-hidden`}>
      <div className="h-full rounded" style={{ width: `${v}%`, background: c }} />
    </div>
  )
}

export function StatTile({ label, value, delta, deltaTone, valueTone, pct, barColor }: { label: string; value: ReactNode; delta?: string; deltaTone?: 'g' | 'y' | 'r' | 'n'; valueTone?: 'g' | 'y' | 'r' | 'n'; pct?: number; barColor?: string }) {
  // A figure that is its own status needs no separate badge.
  const tone = valueTone && valueTone !== 'n' ? { g: 'text-g-fg', y: 'text-y-fg', r: 'text-r-fg' }[valueTone] : ''
  return (
    <div className="card p-5">
      <div className="text-[13px] text-muted font-medium">{label}</div>
      <div className="flex items-baseline gap-2 mt-2 flex-wrap">
        <span className={`text-[30px] font-bold tracking-tight ${tone}`}>{value}</span>
        {delta && <span className="text-[13px] font-semibold" style={{ color: deltaTone && deltaTone !== 'n' ? `var(--${deltaTone}-fg)` : 'var(--muted)' }}>{delta}</span>}
      </div>
      {pct !== undefined && <div className="mt-3.5"><Meter value={pct} label={label} color={barColor} /></div>}
    </div>
  )
}

export function Toggle({ checked, onChange, label, disabled }: { checked: boolean; onChange: (v: boolean) => void; label: string; disabled?: boolean }) {
  return (
    <button type="button" role="switch" aria-checked={checked} aria-label={label} disabled={disabled}
      onClick={() => onChange(!checked)}
      className="relative w-11 h-[26px] rounded-full border-0 cursor-pointer flex-none disabled:opacity-50"
      style={{ background: checked ? 'var(--accent)' : 'var(--track)' }}>
      <span className="absolute top-[3px] w-5 h-5 rounded-full bg-white transition-all" style={{ left: checked ? 21 : 3 }} />
    </button>
  )
}

export function Empty({ children }: { children: ReactNode }) {
  return <div className="p-6 text-center text-sm text-muted">{children}</div>
}

export function Field({ label, error, hint, children }: { label: string; error?: string; hint?: ReactNode; children: ReactNode }) {
  return (
    <label className="label">
      <span>{label}</span>
      {children}
      {hint && !error && <span className="text-xs font-normal text-muted">{hint}</span>}
      {error && <span role="alert" className="text-xs font-semibold text-danger">{error}</span>}
    </label>
  )
}

export function Mono({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <span className={`font-mono text-xs ${className}`}>{children}</span>
}
