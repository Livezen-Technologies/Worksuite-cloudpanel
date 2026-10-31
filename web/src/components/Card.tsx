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
        <h1 className="m-0 text-[26px] leading-8 font-semibold tracking-tight">{title}</h1>
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

const TONE_CHIP = { g: 'bg-g-bg text-g-fg', y: 'bg-y-bg text-y-fg', r: 'bg-r-bg text-r-fg', b: 'bg-b-bg text-b-fg', n: 'bg-n-bg text-n-fg' } as const

/** A KPI card (WorkSuite family): optional tinted icon chip + label, the figure, a grey line under it. */
export function StatTile({ label, value, delta, deltaTone, valueTone, pct, barColor, icon, iconTone = 'b' }: { label: string; value: ReactNode; delta?: string; deltaTone?: 'g' | 'y' | 'r' | 'n'; valueTone?: 'g' | 'y' | 'r' | 'n'; pct?: number; barColor?: string; icon?: ReactNode; iconTone?: keyof typeof TONE_CHIP }) {
  // A figure that is its own status needs no separate badge.
  const tone = valueTone && valueTone !== 'n' ? { g: 'text-g-fg', y: 'text-y-fg', r: 'text-r-fg' }[valueTone] : ''
  return (
    <div className="card px-4 py-4">
      <div className="flex items-center gap-2.5 text-sm text-muted font-medium">
        {icon && <span aria-hidden="true" className={`w-7 h-7 rounded-md flex items-center justify-center flex-none ${TONE_CHIP[iconTone]}`}>{icon}</span>}
        <span className="min-w-0 truncate">{label}</span>
      </div>
      <div className={`mt-2.5 text-[28px] leading-8 font-bold tracking-tight tabular-nums ${tone}`}>{value}</div>
      {delta && <div className="mt-1 text-[13px]" style={{ color: deltaTone && deltaTone !== 'n' ? `var(--${deltaTone}-fg)` : 'var(--muted)' }}>{delta}</div>}
      {pct !== undefined && <div className="mt-3"><Meter value={pct} label={label} color={barColor} /></div>}
    </div>
  )
}

export function Toggle({ checked, onChange, label, disabled }: { checked: boolean; onChange: (v: boolean) => void; label: string; disabled?: boolean }) {
  return (
    <button type="button" role="switch" aria-checked={checked} aria-label={label} disabled={disabled}
      onClick={() => onChange(!checked)}
      className="inline-flex flex-none items-center w-[44px] h-[24px] p-[3px] rounded-full border-0 cursor-pointer appearance-none transition-colors duration-150 disabled:opacity-50 disabled:cursor-not-allowed"
      style={{ background: checked ? 'var(--accent)' : 'var(--ctl-ring)', minHeight: 0, minWidth: 0 }}>
      <span aria-hidden="true" className={`block size-[18px] rounded-full bg-white shadow-[0_1px_2px_rgba(15,23,42,.25)] transition-transform duration-150 ${checked ? 'translate-x-[20px]' : 'translate-x-0'}`} />
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
