import { useEffect, useState, type ReactNode } from 'react'

export interface WizardStep { label: string; content: ReactNode; /** Return an error message to block moving forward. */ validate?: () => string | null | undefined }

/**
 * Step wizard. Steps up to the furthest reached can be revisited by clicking them; moving forward
 * runs the current step's validate().
 */
export function StepWizard({ steps, step, onStep, onFinish, finishLabel = 'Finish', nextLabel = 'Continue', busy, error, hideNav, maxReached }: {
  steps: WizardStep[]; step: number; onStep: (n: number) => void; onFinish?: () => void
  finishLabel?: string; nextLabel?: string; busy?: boolean; error?: string | null; hideNav?: boolean; maxReached?: number
}) {
  const last = step === steps.length - 1
  const reach = Math.max(maxReached ?? step, step)
  const [localErr, setLocalErr] = useState<string | null>(null)
  useEffect(() => setLocalErr(null), [step])
  /** validate() may return 'pending' when it starts async work that will move the wizard itself. */
  const check = () => {
    const err = steps[step].validate?.()
    setLocalErr(err && err !== 'pending' ? err : null)
    return err
  }
  const next = () => {
    if (check()) return
    if (last) onFinish?.()
    else onStep(step + 1)
  }
  return (
    <div className="flex flex-col gap-5">
      <div className="flex items-center justify-between text-sm">
        <span className="font-semibold">Step {step + 1} of {steps.length}</span>
        <span className="text-muted truncate ml-3">{steps[step].label}</span>
      </div>
      <ol className="list-none m-0 p-0 flex items-center" aria-label="Progress">
        {steps.map((s, i) => {
          const done = i < step
          const cur = i === step
          const can = i <= reach && !busy
          return (
            <li key={s.label} className="flex items-center flex-1 last:flex-none min-w-0">
              <button type="button" disabled={!can} aria-current={cur ? 'step' : undefined} aria-label={`Step ${i + 1}: ${s.label}`}
                onClick={() => {
                  if (i > step && check()) return
                  onStep(i)
                }}
                className="flex items-center gap-2 bg-transparent border-0 p-0 cursor-pointer disabled:cursor-default min-w-0 text-fg">
                <span aria-hidden="true" className="flex flex-none items-center justify-center rounded-full text-xs font-bold w-[28px] h-[28px] transition-colors"
                  style={{ background: done || cur ? 'var(--accent)' : 'var(--head)', color: done || cur ? '#fff' : 'var(--muted)', boxShadow: cur ? '0 0 0 4px color-mix(in srgb, var(--accent) 22%, transparent)' : 'inset 0 0 0 1px var(--line)' }}>
                  {done ? <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><path d="M5 12l5 5L20 7" /></svg> : i + 1}
                </span>
                <span aria-hidden="true" className={`hidden md:block text-[13px] truncate ${cur ? 'font-semibold' : 'text-muted'}`}>{s.label}</span>
              </button>
              {i < steps.length - 1 && <span aria-hidden="true" className="flex-1 mx-2 h-[2px] rounded min-w-3" style={{ background: i < step ? 'var(--accent)' : 'var(--line)' }} />}
            </li>
          )
        })}
      </ol>
      <div>{steps[step].content}</div>
      {(error || localErr) && <div role="alert" className="text-sm font-semibold text-danger">{error || localErr}</div>}
      {!hideNav && (
        <div className="flex justify-between gap-2 flex-wrap pt-4 border-t border-line">
          <button type="button" className="btn" disabled={step === 0 || busy} onClick={() => onStep(step - 1)}>Back</button>
          <button type="button" className="btn btn-primary" disabled={busy} onClick={next}>{busy ? 'Working…' : last ? finishLabel : nextLabel}</button>
        </div>
      )}
    </div>
  )
}
