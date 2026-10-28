import { useId, useRef, type ReactNode } from 'react'

export function Tabs<T extends string>({ tabs, value, onChange, label = 'Sections' }: { tabs: { id: T; label: ReactNode }[]; value: T; onChange: (v: T) => void; label?: string }) {
  const id = useId()
  const refs = useRef<(HTMLButtonElement | null)[]>([])
  const onKey = (e: React.KeyboardEvent, i: number) => {
    let n = -1
    if (e.key === 'ArrowRight') n = (i + 1) % tabs.length
    if (e.key === 'ArrowLeft') n = (i - 1 + tabs.length) % tabs.length
    if (e.key === 'Home') n = 0
    if (e.key === 'End') n = tabs.length - 1
    if (n >= 0) { e.preventDefault(); onChange(tabs[n].id); refs.current[n]?.focus() }
  }
  return (
    <div role="tablist" aria-label={label} className="flex gap-6 border-b border-line overflow-x-auto">
      {tabs.map((t, i) => {
        const on = t.id === value
        return (
          <button key={t.id} ref={(el) => { refs.current[i] = el }} type="button" role="tab" id={`${id}-${t.id}`}
            aria-selected={on} tabIndex={on ? 0 : -1} onClick={() => onChange(t.id)} onKeyDown={(e) => onKey(e, i)}
            className="min-h-[44px] px-0.5 -mb-px bg-transparent border-0 border-b-2 cursor-pointer text-sm font-semibold whitespace-nowrap"
            style={{ borderBottomColor: on ? 'var(--accent)' : 'transparent', color: on ? 'var(--accent-fg)' : 'var(--muted)' }}>
            {t.label}
          </button>
        )
      })}
    </div>
  )
}
