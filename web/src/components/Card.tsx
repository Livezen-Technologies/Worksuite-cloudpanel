import type { ReactNode } from 'react'
import { Icon } from './Icon'

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

/** Icon for a stat label when the page gives none, so every KPI card matches the dashboard. */
const AUTO_ICON: [RegExp, string][] = [
  [/threat|malware|attack|blocked|firewall|security|score|protect|rule|enforc|countr/i, 'shield'],
  [/alert|incident|fail|error|warning/i, 'warn'],
  [/backup|stored|snapshot|archive|quarantin|restore/i, 'archive'],
  [/next run|last|time|uptime|schedul|expir|cron|detection/i, 'clock'],
  [/cpu|load|traffic|success|rate|health/i, 'pulse'],
  [/memory|ram|database|db/i, 'db'],
  [/disk|volume|storage|cloud/i, 'disk'],
  [/domain|dns|certificate|site|network|exposed|mesh|peer/i, 'globe'],
  [/container|image|app|stack/i, 'box'],
  [/server|host/i, 'server'],
  [/mail|alias|inbox|blocklist/i, 'inbox'],
  [/user|member|customer|team/i, 'users'],
  [/update|upgrade|php|wordpress|staging|copies/i, 'refresh'],
  [/resolved|clean|valid|enabled|connected/i, 'check'],
]
const autoIcon = (label: string) => AUTO_ICON.find(([re]) => re.test(label))?.[1] ?? 'grid'

/** A KPI card (WorkSuite family): tinted icon chip + label, the figure, a grey line under it. */
export function StatTile({ label, value, delta, deltaTone, valueTone, pct, barColor, icon, iconTone }: { label: string; value: ReactNode; delta?: string; deltaTone?: 'g' | 'y' | 'r' | 'n'; valueTone?: 'g' | 'y' | 'r' | 'n'; pct?: number; barColor?: string; icon?: ReactNode; iconTone?: keyof typeof TONE_CHIP }) {
  // A figure that is its own status needs no separate badge.
  const tone = valueTone && valueTone !== 'n' ? { g: 'text-g-fg', y: 'text-y-fg', r: 'text-r-fg' }[valueTone] : ''
  // No icon given: pick one from the label, tinted like the value (blue when neutral).
  const chipTone = iconTone ?? (valueTone && valueTone !== 'n' ? valueTone : deltaTone && deltaTone !== 'n' && deltaTone !== 'g' ? deltaTone : 'b')
  const glyph = icon ?? <Icon name={autoIcon(label)} size={16} />
  return (
    <div className="card px-4 py-4">
      <div className="flex items-center gap-2.5 text-sm text-muted font-medium">
        <span aria-hidden="true" className={`w-7 h-7 rounded-md flex items-center justify-center flex-none ${TONE_CHIP[chipTone]}`}>{glyph}</span>
        <span className="min-w-0 leading-tight">{label}</span>
      </div>
      <div className={`mt-2.5 text-[28px] leading-8 font-bold tracking-tight tabular-nums ${tone}`}>{value}</div>
      {delta && <div className="mt-1 text-[13px]" style={{ color: deltaTone && deltaTone !== 'n' ? `var(--${deltaTone}-fg)` : 'var(--muted)' }}>{delta}</div>}
      {pct !== undefined && <div className="mt-3"><Meter value={pct} label={label} color={barColor} /></div>}
    </div>
  )
}

export function Toggle({ checked, onChange, label, disabled }: { checked: boolean; onChange: (v: boolean) => void; label: string; disabled?: boolean }) {
  // Exact px sizes: the theme remaps Tailwind's spacing steps (h-6 is 32px here).
  return (
    <button type="button" role="switch" aria-checked={checked} aria-label={label} disabled={disabled}
      onClick={() => onChange(!checked)}
      className="group relative inline-flex flex-none items-center w-[44px] h-[24px] p-[2px] rounded-full border-0 cursor-pointer appearance-none
                 transition-[background-color,box-shadow] duration-200 ease-out
                 shadow-[inset_0_1px_2px_rgba(15,23,42,.15)] hover:brightness-[.97]
                 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--accent)]
                 disabled:opacity-50 disabled:cursor-not-allowed"
      style={{ background: checked ? 'var(--accent)' : 'var(--ctl-ring)', minHeight: 0, minWidth: 0 }}>
      <span aria-hidden="true" className={`grid place-items-center w-[20px] h-[20px] rounded-full bg-white shadow-[0_1px_3px_rgba(15,23,42,.25),0_1px_1px_rgba(15,23,42,.08)] transition-transform duration-200 ease-[cubic-bezier(.3,1.4,.6,1)] group-active:scale-95 ${checked ? 'translate-x-[20px]' : 'translate-x-0'}`}>
        <svg viewBox="0 0 12 12" className={`w-[10px] h-[10px] transition-opacity duration-150 ${checked ? 'opacity-100' : 'opacity-0'}`} fill="none" stroke="var(--accent)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M2.5 6.2 5 8.5l4.5-5" /></svg>
      </span>
    </button>
  )
}

/** Split "No servers yet. Add your first…" into a heading and its explanation. */
export function splitEmpty(text: string): [string, string] {
  const m = text.match(/^(.+?[.!?])\s+(.+)$/)
  return m ? [m[1], m[2]] : [text, '']
}

/** Empty state: an icon, a short heading, an explanation and an optional call to action. */
export function Empty({ children, icon = 'inbox', action, compact }: { children: ReactNode; icon?: string; action?: ReactNode; compact?: boolean }) {
  // Progress text ("Loading…", "Scanning…") is not an empty state: keep it quiet.
  if (typeof children === 'string' && children.endsWith('…')) return <div className="p-6 text-center text-sm text-muted" aria-live="polite">{children}</div>
  const [title, body] = typeof children === 'string' ? splitEmpty(children) : [null, null]
  return (
    <div className={`flex flex-col items-center text-center gap-1 ${compact ? 'p-4' : 'px-6 py-10'}`}>
      {!compact && <span className="grid place-items-center w-12 h-12 rounded-full bg-head text-muted mb-2" aria-hidden="true"><Icon name={icon} size={22} /></span>}
      {title !== null
        ? <><div className="text-sm font-semibold text-fg">{title}</div>{body && <div className="text-sm text-muted max-w-md">{body}</div>}</>
        : <div className="text-sm text-muted max-w-md">{children}</div>}
      {action && <div className="mt-3">{action}</div>}
    </div>
  )
}

export function Field({ label, error, hint, required, children }: { label: string; error?: string; hint?: ReactNode; required?: boolean; children: ReactNode }) {
  const star = required || / \*$/.test(label)
  return (
    <label className="label">
      <span className="flex items-center gap-1">{label.replace(/ \*$/, '')}{star && <span aria-hidden="true" className="text-danger font-semibold">*</span>}{star && <span className="sr-only">(required)</span>}</span>
      {children}
      {hint && !error && <span className="text-xs font-normal text-muted">{hint}</span>}
      {error && <span role="alert" className="flex items-center gap-1 text-xs font-semibold text-danger"><Icon name="warn" size={13} />{error}</span>}
    </label>
  )
}

export function Mono({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <span className={`font-mono text-xs ${className}`}>{children}</span>
}
