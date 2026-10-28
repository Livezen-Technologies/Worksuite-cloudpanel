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
      <ol className="list-none m-0 p-0 grid gap-3" style={{ gridTemplateColumns: `repeat(${steps.length}, minmax(0, 1fr))` }} aria-label="Progress">
        {steps.map((s, i) => {
          const done = i <= step
          const can = i <= reach && !busy
          return (
            <li key={s.label} className="min-w-0">
              <button type="button" disabled={!can} aria-current={i === step ? 'step' : undefined}
                onClick={() => {
                  if (i > step && check()) return
                  onStep(i)
                }}
                className="w-full flex flex-col items-start gap-0.5 pt-2.5 bg-transparent border-0 border-t-4 text-left cursor-pointer disabled:cursor-default text-fg"
                style={{ borderTopColor: done ? 'var(--accent)' : 'var(--line)' }}>
                <span className="text-xs font-semibold" style={{ color: done ? 'var(--accent-fg)' : 'var(--muted)' }}>Step {i + 1}</span>
                <span className="text-[13px] font-semibold truncate max-w-full hidden sm:block">{s.label}</span>
                <span className="sr-only sm:hidden">{s.label}</span>
              </button>
            </li>
          )
        })}
      </ol>
      <div className="sm:hidden text-sm font-semibold">{steps[step].label}</div>
      <div>{steps[step].content}</div>
      {(error || localErr) && <div role="alert" className="text-sm font-semibold text-danger">{error || localErr}</div>}
      {!hideNav && (
        <div className="flex justify-end gap-2 flex-wrap">
          <button type="button" className="btn" disabled={step === 0 || busy} onClick={() => onStep(step - 1)}>Back</button>
          <button type="button" className="btn btn-primary" disabled={busy} onClick={next}>{busy ? 'Working…' : last ? finishLabel : nextLabel}</button>
        </div>
      )}
    </div>
  )
}
