import type { ReactNode } from 'react'
import { Icon } from './Icon'
import { Modal } from './Overlay'

export interface ShellStep { id: string; label: string; hint: string }

/** A selectable card (source type, method, provider…) with an icon and a check when chosen. */
export function WizardChoice({ on, onClick, title, text, icon, disabled }: { on: boolean; onClick: () => void; title: string; text: string; icon?: string; disabled?: boolean }) {
  return (
    <button type="button" aria-pressed={on} onClick={onClick} disabled={disabled}
      className={`relative card p-4 text-left cursor-pointer text-fg flex flex-col gap-1.5 transition-colors disabled:opacity-60 disabled:cursor-not-allowed ${on ? '' : 'hover:border-[var(--muted)]'}`}
      style={on ? { borderColor: 'var(--accent)', boxShadow: '0 0 0 1px var(--accent)', background: 'var(--sel)' } : undefined}>
      {icon && <span aria-hidden="true" className="grid place-items-center w-9 h-9 rounded-lg bg-head" style={{ color: 'var(--accent)' }}><Icon name={icon} size={18} /></span>}
      <span className="font-semibold text-sm">{title}</span>
      <span className="text-xs text-muted">{text}</span>
      {on && <span aria-hidden="true" className="absolute top-2.5 right-2.5 grid place-items-center w-5 h-5 rounded-full text-white" style={{ background: 'var(--accent)' }}><Icon name="check" size={12} /></span>}
    </button>
  )
}

export function WizardSection({ title, hint, children }: { title: string; hint?: string; children: ReactNode }) {
  return (
    <section className="flex flex-col gap-3">
      <div><h3 className="m-0 text-[15px] font-semibold">{title}</h3>{hint && <p className="m-0 text-xs text-muted mt-0.5">{hint}</p>}</div>
      {children}
    </section>
  )
}

/** Pass / warn / fail list used by pre-flight and verification steps. */
export function WizardChecks({ checks, label = 'Checks' }: { checks: { key: string; status: 'pass' | 'warn' | 'fail' | 'pending'; label: string; message?: string }[]; label?: string }) {
  return (
    <ul className="list-none m-0 p-0 flex flex-col gap-2" aria-label={label}>
      {checks.map((c) => (
        <li key={c.key} className="flex items-start gap-2 text-sm">
          <span aria-hidden="true" className={`mt-0.5 grid place-items-center w-5 h-5 rounded-full flex-none ${{ pass: 'bg-g-bg text-g-fg', warn: 'bg-y-bg text-y-fg', fail: 'bg-r-bg text-r-fg', pending: 'bg-head text-muted' }[c.status]}`}>
            <Icon name={c.status === 'pass' ? 'check' : c.status === 'warn' ? 'warn' : c.status === 'pending' ? 'clock' : 'close'} size={12} />
          </span>
          <span><span className="font-semibold">{c.label}</span><span className="sr-only"> ({c.status})</span>{c.message ? <> <span className="text-muted">{c.message}</span></> : null}</span>
        </li>
      ))}
    </ul>
  )
}

/**
 * The shared wizard frame: large modal on desktop (full screen on phones), a step bar showing done,
 * current and upcoming steps, a step heading, a configuration summary sidebar and a sticky footer.
 * Steps up to `reach` can be revisited; the caller decides what Next does.
 */
export function WizardShell({ open, onClose, title, subtitle, steps, step, reach, onStep, summary, summaryTitle = 'Configuration summary', error, children, next, back = true, busy, cancelLabel = 'Cancel' }: {
  open: boolean; onClose: () => void; title: string; subtitle?: string; steps: readonly ShellStep[]; step: number; reach: number; onStep: (i: number) => void
  summary?: [string, string][]; summaryTitle?: string; error?: string | null; children: ReactNode
  next: { label: string; onClick: () => void; disabled?: boolean } | null; back?: boolean; busy?: boolean; cancelLabel?: string
}) {
  return (
    <Modal open={open} onClose={onClose} title={title} subtitle={subtitle} width={1120} fullOnMobile
      footer={<div className="w-full flex items-center justify-between gap-2">
        <button type="button" className="btn" onClick={onClose}>{cancelLabel}</button>
        <span className="flex gap-2">
          {back && <button type="button" className="btn" disabled={step === 0 || busy} onClick={() => onStep(step - 1)}>Previous</button>}
          {next && <button type="button" className="btn btn-primary" disabled={busy || next.disabled} onClick={next.onClick}>{next.label}</button>}
        </span>
      </div>}>
      <div className="flex flex-col gap-5">
        <ol className="list-none m-0 p-0 flex items-center" aria-label="Progress">
          {steps.map((s, i) => {
            const done = i < step
            const cur = i === step
            return (
              <li key={s.id} className="flex items-center flex-1 last:flex-none min-w-0">
                <button type="button" disabled={i > reach || busy} aria-current={cur ? 'step' : undefined} aria-label={`Step ${i + 1}: ${s.label}`} onClick={() => onStep(i)}
                  className="flex items-center gap-2 bg-transparent border-0 p-0 cursor-pointer disabled:cursor-default min-w-0 text-fg">
                  <span aria-hidden="true" className="flex flex-none items-center justify-center rounded-full text-xs font-bold w-7 h-7"
                    style={{ background: done || cur ? 'var(--accent)' : 'var(--head)', color: done || cur ? '#fff' : 'var(--muted)', boxShadow: cur ? '0 0 0 4px color-mix(in srgb, var(--accent) 22%, transparent)' : 'inset 0 0 0 1px var(--line)' }}>
                    {done ? <Icon name="check" size={13} /> : i + 1}
                  </span>
                  <span aria-hidden="true" className={`hidden md:block text-[13px] truncate ${cur ? 'font-semibold' : 'text-muted'}`}>{s.label}</span>
                </button>
                {i < steps.length - 1 && <span aria-hidden="true" className="flex-1 mx-2 h-[2px] rounded min-w-3" style={{ background: done ? 'var(--accent)' : 'var(--line)' }} />}
              </li>
            )
          })}
        </ol>
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_280px]">
          <div className="min-w-0 flex flex-col gap-5">
            <div><div className="text-xs font-semibold" style={{ color: 'var(--accent)' }}>Step {step + 1} of {steps.length}</div><h3 className="m-0 text-lg font-semibold">{steps[step].label}</h3><p className="m-0 text-sm text-muted">{steps[step].hint}</p></div>
            {children}
            {error && <div role="alert" className="text-sm font-semibold text-danger">{error}</div>}
          </div>
          <aside className="hidden lg:flex flex-col gap-4 rounded-lg border border-line p-4 self-start sticky top-0" aria-label={summaryTitle}>
            <div className="font-semibold">{summaryTitle}</div>
            <ol className="list-none m-0 p-0 flex flex-col gap-1">
              {steps.map((s, i) => (
                <li key={s.id} className="flex gap-3 rounded-md p-2" style={i === step ? { background: 'var(--sel)' } : undefined}>
                  <span aria-hidden="true" className="flex-none grid place-items-center w-6 h-6 rounded-full text-xs font-bold" style={{ background: i <= step ? 'var(--accent)' : 'var(--head)', color: i <= step ? '#fff' : 'var(--muted)' }}>{i < step ? <Icon name="check" size={11} /> : i + 1}</span>
                  <span className="min-w-0"><span className="block text-[13px] font-semibold">{s.label}</span><span className="block text-xs text-muted">{s.hint}</span></span>
                </li>
              ))}
            </ol>
            {summary && summary.length > 0 && (
              <dl className="m-0 flex flex-col gap-1.5 border-t border-line pt-3 text-xs">
                {summary.map(([k, v]) => <div key={k} className="flex justify-between gap-2"><dt className="text-muted">{k}</dt><dd className="m-0 font-medium text-right truncate max-w-[160px]" title={v}>{v}</dd></div>)}
              </dl>
            )}
          </aside>
        </div>
      </div>
    </Modal>
  )
}
