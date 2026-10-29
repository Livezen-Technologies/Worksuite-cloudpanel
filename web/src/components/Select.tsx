import type { ChangeEvent, MouseEvent as ReactMouseEvent } from 'react'
import { Children, isValidElement, useCallback, useEffect, useId, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react'

type Opt = { value: string; label: string; disabled?: boolean }

/** Flatten an option's children to text: they may be a string, a number, or an
 *  array of both when the label interpolates values ({name} — {price}). */
function textOf(node: ReactNode): string {
  if (node === null || node === undefined || typeof node === 'boolean') return ''
  if (typeof node === 'string' || typeof node === 'number') return String(node)
  if (Array.isArray(node)) return node.map(textOf).join('')
  if (isValidElement(node)) return textOf((node.props as { children?: ReactNode }).children)
  return ''
}

/**
 * Drop-in replacement for a native select element: same props, same e.target.value
 * onChange contract, <option> children. Renders a styled listbox instead of the
 * OS menu so it matches the app in both themes.
 */
export default function Select({
  value, onChange, children, className = '', disabled, id, 'aria-label': ariaLabel, name, onClick, title,
}: {
  value?: string | number
  onChange?: (e: ChangeEvent<HTMLSelectElement>) => void
  children?: ReactNode
  className?: string
  disabled?: boolean
  id?: string
  'aria-label'?: string
  name?: string
  onClick?: (e: ReactMouseEvent<HTMLButtonElement>) => void
  title?: string
}) {
  const opts = useMemo<Opt[]>(() => {
    const out: Opt[] = []
    const walk = (nodes: ReactNode) => Children.forEach(nodes, (c) => {
      if (!isValidElement(c)) return
      const p = c.props as { value?: string | number; children?: ReactNode; disabled?: boolean }
      if (c.type === 'option') {
        const label = textOf(p.children).trim()
        out.push({ value: p.value !== undefined ? String(p.value) : label, label: label || String(p.value ?? ''), disabled: p.disabled })
      } else if (p.children) walk(p.children)
    })
    walk(children)
    return out
  }, [children])

  const current = String(value ?? '')
  const selected = opts.find((o) => o.value === current)
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)
  const [rect, setRect] = useState<{ top: number; left: number; width: number; drop: 'down' | 'up' } | null>(null)
  const btn = useRef<HTMLButtonElement>(null)
  const list = useRef<HTMLDivElement>(null)
  const typed = useRef({ s: '', t: 0 })
  const listId = useId()

  const place = useCallback(() => {
    const el = btn.current
    if (!el) return
    const r = el.getBoundingClientRect()
    const need = Math.min(opts.length * 36 + 8, 288)
    const drop = r.bottom + need > window.innerHeight && r.top > need ? 'up' : 'down'
    setRect({ top: drop === 'down' ? r.bottom + 4 : r.top - need - 4, left: r.left, width: r.width, drop })
  }, [opts.length])

  useLayoutEffect(() => { if (open) place() }, [open, place])

  useEffect(() => {
    if (!open) return
    const close = () => setOpen(false)
    const onDoc = (e: MouseEvent) => {
      if (!btn.current?.contains(e.target as Node) && !list.current?.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    window.addEventListener('resize', close)
    window.addEventListener('scroll', close, true)
    return () => {
      document.removeEventListener('mousedown', onDoc)
      window.removeEventListener('resize', close)
      window.removeEventListener('scroll', close, true)
    }
  }, [open])

  useEffect(() => {
    if (open) {
      const i = opts.findIndex((o) => o.value === current)
      setActive(i < 0 ? 0 : i)
      requestAnimationFrame(() => list.current?.querySelector<HTMLElement>('[data-active="true"]')?.scrollIntoView({ block: 'nearest' }))
    }
  }, [open]) // eslint-disable-line react-hooks/exhaustive-deps

  const pick = (o: Opt) => {
    if (o.disabled) return
    onChange?.({ target: { value: o.value, name } } as unknown as ChangeEvent<HTMLSelectElement>)
    setOpen(false)
    btn.current?.focus()
  }

  const move = (d: number) => {
    setActive((a) => {
      let n = a
      for (let i = 0; i < opts.length; i++) {
        n = (n + d + opts.length) % opts.length
        if (!opts[n]?.disabled) break
      }
      requestAnimationFrame(() => list.current?.querySelector<HTMLElement>('[data-active="true"]')?.scrollIntoView({ block: 'nearest' }))
      return n
    })
  }

  const onKey = (e: React.KeyboardEvent) => {
    if (disabled) return
    if (!open && (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
      e.preventDefault(); setOpen(true); return
    }
    if (!open) return
    if (e.key === 'Escape') { e.preventDefault(); setOpen(false); btn.current?.focus() }
    else if (e.key === 'ArrowDown') { e.preventDefault(); move(1) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1) }
    else if (e.key === 'Home') { e.preventDefault(); setActive(0) }
    else if (e.key === 'End') { e.preventDefault(); setActive(opts.length - 1) }
    else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); const o = opts[active]; if (o) pick(o) }
    else if (e.key === 'Tab') setOpen(false)
    else if (e.key.length === 1) {
      const now = Date.now()
      typed.current.s = now - typed.current.t > 800 ? e.key : typed.current.s + e.key
      typed.current.t = now
      const i = opts.findIndex((o) => o.label.toLowerCase().startsWith(typed.current.s.toLowerCase()))
      if (i >= 0) setActive(i)
    }
  }

  return (
    <>
      <button
        ref={btn} type="button" id={id} disabled={disabled} aria-label={ariaLabel}
        aria-haspopup="listbox" aria-expanded={open} aria-controls={open ? listId : undefined}
        title={title}
        onClick={(e) => { onClick?.(e); if (!disabled) setOpen((o) => !o) }} onKeyDown={onKey}
        className={`${className} flex items-center justify-between gap-2 text-left cursor-pointer ${disabled ? 'opacity-50 cursor-not-allowed' : ''}`}
      >
        <span className={`truncate ${selected ? '' : 'text-muted'}`}>{selected?.label ?? '—'}</span>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"
             className={`flex-none transition-transform duration-150 ${open ? 'rotate-180' : ''}`}>
          <path d="M6 9l6 6 6-6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </button>

      {open && rect && (
        <div
          ref={list} id={listId} role="listbox" tabIndex={-1} aria-activedescendant={`${listId}-${active}`}
          onKeyDown={onKey}
          style={{ position: 'fixed', top: rect.top, left: rect.left, minWidth: rect.width, zIndex: 80 }}
          className="max-h-72 overflow-auto rounded-xl border border-line bg-card p-1 shadow-2xl animate-[fadeIn_120ms_ease-out]"
        >
          {opts.length === 0 && <div className="px-3 py-2 text-sm text-muted">No options</div>}
          {opts.map((o, i) => {
            const isSel = o.value === current
            return (
              <div
                key={`${o.value}-${i}`} id={`${listId}-${i}`} role="option" aria-selected={isSel}
                data-active={i === active} onMouseEnter={() => setActive(i)} onClick={() => pick(o)}
                className={`flex items-center gap-2 rounded-lg px-3 py-2 text-sm min-h-[36px]
                  ${o.disabled ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer'}
                  ${i === active && !o.disabled ? 'bg-accent text-white' : ''}
                  ${isSel && i !== active ? 'text-accent font-medium' : ''}`}
              >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"
                     className={`flex-none ${isSel ? '' : 'invisible'}`}>
                  <path d="M20 6L9 17l-5-5" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
                <span className="truncate">{o.label}</span>
              </div>
            )
          })}
        </div>
      )}
    </>
  )
}
