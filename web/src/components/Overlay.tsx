import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { Icon } from './Icon'

function useEscapeAndFocus(open: boolean, onClose: () => void, ref: React.RefObject<HTMLElement | null>) {
  useEffect(() => {
    if (!open) return
    const prev = document.activeElement as HTMLElement | null
    const el = ref.current
    const focusables = () => Array.from(el?.querySelectorAll<HTMLElement>('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])') ?? []).filter((x) => !x.hasAttribute('disabled'))
    const first = el?.querySelector<HTMLElement>('[data-autofocus]') ?? focusables()[0]
    first?.focus()
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') { e.stopPropagation(); onClose() }
      if (e.key === 'Tab') {
        const f = focusables(); if (!f.length) return
        const a = f[0], b = f[f.length - 1]
        if (e.shiftKey && document.activeElement === a) { e.preventDefault(); b.focus() }
        else if (!e.shiftKey && document.activeElement === b) { e.preventDefault(); a.focus() }
      }
    }
    document.addEventListener('keydown', onKey)
    return () => { document.removeEventListener('keydown', onKey); prev?.focus?.() }
  }, [open, onClose, ref])
}

export function Drawer({ open, onClose, title, children, side = 'right', width = 440, footer }: { open: boolean; onClose: () => void; title: string; children: ReactNode; side?: 'right' | 'left'; width?: number; footer?: ReactNode }) {
  const ref = useRef<HTMLDivElement>(null)
  const id = useId()
  useEscapeAndFocus(open, onClose, ref)
  if (!open) return null
  return (
    <div className="fixed inset-0 z-50">
      <div className="absolute inset-0 bg-black/40" onClick={onClose} aria-hidden="true" />
      <div ref={ref} role="dialog" aria-modal="true" aria-labelledby={id}
        className={`absolute top-0 bottom-0 ${side === 'right' ? 'right-0 border-l' : 'left-0 border-r'} bg-card border-line flex flex-col max-w-full`}
        style={{ width }}>
        <div className="flex items-center justify-between gap-2 px-5 h-16 border-b border-line flex-none">
          <h2 id={id} className="m-0 text-base font-semibold truncate">{title}</h2>
          <button type="button" aria-label="Close panel" onClick={onClose} className="btn btn-sm border-0"><Icon name="close" /></button>
        </div>
        <div className="flex-1 overflow-y-auto p-5">{children}</div>
        {footer && <div className="border-t border-line p-4 flex flex-wrap gap-2 justify-end">{footer}</div>}
      </div>
    </div>
  )
}

export function Modal({ open, onClose, title, children, footer, width = 520 }: { open: boolean; onClose: () => void; title: string; children: ReactNode; footer?: ReactNode; width?: number }) {
  const ref = useRef<HTMLDivElement>(null)
  const id = useId()
  useEscapeAndFocus(open, onClose, ref)
  if (!open) return null
  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/45" onClick={onClose} aria-hidden="true" />
      <div ref={ref} role="dialog" aria-modal="true" aria-labelledby={id} className="relative card w-full max-h-[90vh] flex flex-col shadow-xl" style={{ maxWidth: width }}>
        <div className="flex items-center justify-between px-5 pt-4">
          <h2 id={id} className="m-0 text-base font-semibold">{title}</h2>
          <button type="button" aria-label="Close dialog" onClick={onClose} className="btn btn-sm border-0"><Icon name="close" /></button>
        </div>
        <div className="p-5 overflow-y-auto text-sm">{children}</div>
        {footer && <div className="px-5 pb-5 flex flex-wrap gap-2 justify-end">{footer}</div>}
      </div>
    </div>
  )
}

/**
 * Confirmation dialog. When `confirmText` is set (destructive actions) the user must type it
 * exactly before the confirm button is enabled.
 */
export function ConfirmDialog({ open, onClose, onConfirm, title, children, confirmLabel = 'Confirm', confirmText, danger = false, busy = false }: {
  open: boolean; onClose: () => void; onConfirm: () => void; title: string; children?: ReactNode
  confirmLabel?: string; confirmText?: string; danger?: boolean; busy?: boolean
}) {
  const [typed, setTyped] = useState('')
  useEffect(() => { if (!open) setTyped('') }, [open])
  const ok = !confirmText || typed === confirmText
  return (
    <Modal open={open} onClose={onClose} title={title}
      footer={<>
        <button type="button" className="btn" onClick={onClose}>Cancel</button>
        <button type="button" className={`btn ${danger ? 'btn-primary !bg-[#B91C1C] !border-[#B91C1C]' : 'btn-primary'}`} disabled={!ok || busy} onClick={onConfirm}>{busy ? 'Working…' : confirmLabel}</button>
      </>}>
      <div className="flex flex-col gap-3">
        {children}
        {confirmText && (
          <label className="label">
            <span>Type <span className="font-mono">{confirmText}</span> to confirm</span>
            <input className="input font-mono" value={typed} onChange={(e) => setTyped(e.target.value)} data-autofocus autoComplete="off" />
          </label>
        )}
      </div>
    </Modal>
  )
}
