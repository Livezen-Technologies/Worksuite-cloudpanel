import { useEffect, useRef, useState } from 'react'

/** The WorkSuite Cloud admin consoles, opened from the 9-dot launcher in the header. */
export const CONSOLES = [
  { name: 'Account', url: 'https://account.worksuitecloud.com/admin/', color: '#2563EB', text: 'Ac' },
  { name: 'Desk', url: 'https://desk.worksuitecloud.com/admin/', color: '#0EA5E9', text: 'De' },
  { name: 'IoT', url: 'https://iot.worksuitecloud.com/admin', color: '#16A34A', text: 'Io' },
  { name: 'Download Center', url: 'https://download.worksuitecloud.com/admin', color: '#9333EA', text: 'Dl' },
  { name: 'AI Studio', url: 'https://aistudio.worksuitecloud.com/admin', color: '#EA580C', text: 'AI' },
] as const

export function AppLauncher() {
  const [open, setOpen] = useState(false)
  const box = useRef<HTMLDivElement>(null)
  useEffect(() => {
    if (!open) return
    const close = (e: MouseEvent | KeyboardEvent) => {
      if (e instanceof KeyboardEvent ? e.key === 'Escape' : !box.current?.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', close)
    document.addEventListener('keydown', close)
    return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', close) }
  }, [open])
  return (
    <div className="relative" ref={box}>
      <button type="button" aria-label="WorkSuite Cloud apps" aria-expanded={open} aria-haspopup="true" onClick={() => setOpen((v) => !v)}
        className="!min-h-11 !min-w-11 grid place-content-center rounded-lg border-0 bg-transparent text-white/70 hover:text-white hover:bg-white/10 cursor-pointer">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          {[5, 12, 19].flatMap((y) => [5, 12, 19].map((x) => <circle key={`${x}-${y}`} cx={x} cy={y} r="2" />))}
        </svg>
      </button>
      {open && (
        <div role="menu" aria-label="WorkSuite Cloud apps" className="card absolute right-0 top-full mt-2 w-[300px] max-w-[calc(100vw-32px)] p-3 text-fg shadow-pop" style={{ zIndex: "var(--z-popover)" }}>
          <div className="grid grid-cols-3 gap-1">
            {CONSOLES.map((c) => (
              <a key={c.name} role="menuitem" href={c.url} target="_blank" rel="noopener noreferrer" onClick={() => setOpen(false)}
                className="flex flex-col items-center gap-1.5 rounded-lg p-2.5 no-underline text-fg hover:bg-[color-mix(in_srgb,var(--accent)_8%,transparent)]">
                <span className="grid h-10 w-10 place-content-center rounded-lg text-sm font-bold text-white" style={{ background: c.color }}>{c.text}</span>
                <span className="text-xs text-center leading-tight">{c.name}</span>
              </a>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
