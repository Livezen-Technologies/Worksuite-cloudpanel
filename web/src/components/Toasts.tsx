import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react'
import { errorMessage } from '../api/client'
import { Icon } from './Icon'

export type ToastKind = 'success' | 'error' | 'info'
interface Toast { id: number; kind: ToastKind; title: string; detail?: string }
interface Ctx { push: (t: Omit<Toast, 'id'>) => void; success: (title: string, detail?: string) => void; error: (e: unknown, title?: string) => void; info: (title: string, detail?: string) => void }
const ToastCtx = createContext<Ctx | null>(null)
let seq = 0

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<Toast[]>([])
  const dismiss = useCallback((id: number) => setItems((x) => x.filter((t) => t.id !== id)), [])
  const push = useCallback((t: Omit<Toast, 'id'>) => {
    const id = ++seq
    setItems((x) => [...x.slice(-4), { ...t, id }])
    setTimeout(() => dismiss(id), t.kind === 'error' ? 8000 : 4500)
  }, [dismiss])
  const value = useMemo<Ctx>(() => ({
    push,
    success: (title, detail) => push({ kind: 'success', title, detail }),
    info: (title, detail) => push({ kind: 'info', title, detail }),
    error: (e, title) => push({ kind: 'error', title: title ?? 'That did not work', detail: errorMessage(e) }),
  }), [push])
  return (
    <ToastCtx.Provider value={value}>
      {children}
      <div aria-live="polite" role="status" className="fixed z-[70] bottom-4 right-4 left-4 sm:left-auto flex flex-col gap-2 sm:w-[360px]">
        {items.map((t) => (
          <div key={t.id} className="card p-3.5 shadow-lg flex gap-3 items-start"
            style={{ borderLeft: `4px solid var(--${t.kind === 'success' ? 'g' : t.kind === 'error' ? 'r' : 'b'}-fg)` }}>
            <div className="flex-1 min-w-0">
              <div className="text-sm font-semibold"><span className="sr-only">{t.kind}: </span>{t.title}</div>
              {t.detail && <div className="text-xs text-muted mt-0.5 break-words">{t.detail}</div>}
            </div>
            <button type="button" aria-label="Dismiss notification" className="text-muted bg-transparent border-0 cursor-pointer p-1" onClick={() => dismiss(t.id)}><Icon name="close" size={16} /></button>
          </div>
        ))}
      </div>
    </ToastCtx.Provider>
  )
}

export function useToast(): Ctx {
  const c = useContext(ToastCtx)
  if (!c) return { push: () => {}, success: () => {}, error: () => {}, info: () => {} }
  return c
}
