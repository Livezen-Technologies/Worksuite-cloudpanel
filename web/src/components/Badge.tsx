export type Tone = 'g' | 'y' | 'r' | 'b' | 'n'

const MAP: Record<string, Tone> = {
  online: 'g', running: 'g', healthy: 'g', success: 'g', succeeded: 'g', verified: 'g', valid: 'g', active: 'g',
  connected: 'g', completed: 'g', paid: 'g', ok: 'g', live: 'g', enabled: 'g', approved: 'g', clean: 'g', yes: 'g',
  warning: 'y', paused: 'y', pending: 'y', waiting: 'y', expiring: 'y', degraded: 'y', restarting: 'y', 'rolled back': 'y',
  queued: 'y', due: 'y', open: 'y', medium: 'y', awaiting_approval: 'y',
  offline: 'r', failed: 'r', unhealthy: 'r', critical: 'r', emergency: 'r', error: 'r', expired: 'r', high: 'r', cancelled: 'r', overdue: 'r',
  maintenance: 'b', deploying: 'b', info: 'b', scheduled: 'b', managed: 'b', staging: 'b', preview: 'b', building: 'b',
}
export function toneFor(status?: string | null): Tone {
  if (!status) return 'n'
  const s = status.toLowerCase()
  return MAP[s] ?? (s.startsWith('pending') ? 'y' : s.includes('fail') ? 'r' : 'n')
}

/** Status badge — always renders a word, never colour alone. */
export function Badge({ children, tone, className = '' }: { children: React.ReactNode; tone?: Tone; className?: string }) {
  const t = tone ?? toneFor(typeof children === 'string' ? children : undefined)
  return (
    <span className={`inline-flex items-center h-[22px] px-2 rounded-md text-xs font-semibold whitespace-nowrap ${className}`}
      style={{ background: `var(--${t}-bg)`, color: `var(--${t}-fg)` }}>
      {children}
    </span>
  )
}

export function StatusBadge({ status }: { status?: string | null }) {
  return <Badge tone={toneFor(status)}>{status || 'unknown'}</Badge>
}

export function Dot({ tone }: { tone: Tone }) {
  const c = { g: 'var(--g-fg)', y: 'var(--y-fg)', r: 'var(--r-fg)', b: 'var(--b-fg)', n: 'var(--n-fg)' }[tone]
  return <span aria-hidden="true" className="inline-block w-2 h-2 rounded-full flex-none" style={{ background: c }} />
}
