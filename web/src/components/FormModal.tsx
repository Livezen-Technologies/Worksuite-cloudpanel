import { useEffect, useState, type ReactNode } from 'react'
import { isApiError } from '../api/client'
import { Modal } from './Overlay'
import { Field } from './Card'

export interface FieldDef {
  name: string
  label: string
  type?: 'text' | 'number' | 'password' | 'select' | 'checkbox' | 'textarea' | 'email'
  options?: (string | { value: string; label: string })[]
  required?: boolean
  hint?: string
  mono?: boolean
  placeholder?: string
  show?: (v: Record<string, unknown>) => boolean
}

/** Generic create/edit form in a modal. Server-side `fields` errors are shown inline. */
export function FormModal({ open, onClose, title, fields, initial = {}, submitLabel = 'Save', onSubmit, children, extra }: {
  open: boolean; onClose: () => void; title: string; fields: FieldDef[]; initial?: Record<string, unknown>; submitLabel?: string
  onSubmit: (v: Record<string, unknown>) => Promise<unknown>; children?: ReactNode; extra?: (v: Record<string, unknown>) => ReactNode
}) {
  const [v, setV] = useState<Record<string, unknown>>(initial)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)
  useEffect(() => { if (open) { setV(initial); setErrors({}) } }, [open]) // eslint-disable-line react-hooks/exhaustive-deps
  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    const missing = Object.fromEntries(fields.filter((f) => f.required && (!f.show || f.show(v)) && !v[f.name]).map((f) => [f.name, `${f.label} is required.`]))
    setErrors(missing)
    if (Object.keys(missing).length) return
    setBusy(true)
    try { await onSubmit(v); onClose() } catch (ex) {
      if (isApiError(ex)) setErrors(Object.fromEntries(Object.entries(ex.fields).map(([k, m]) => [k, m[0]])))
    } finally { setBusy(false) }
  }
  return (
    <Modal open={open} onClose={onClose} title={title} width={620}>
      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {children}
        <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
          {fields.filter((f) => !f.show || f.show(v)).map((f) => {
            const val = v[f.name]
            const set = (x: unknown) => setV((o) => ({ ...o, [f.name]: x }))
            if (f.type === 'checkbox') return (
              <label key={f.name} className="flex items-center gap-2 text-sm font-semibold col-span-full"><input type="checkbox" checked={!!val} onChange={(e) => set(e.target.checked)} />{f.label}{f.hint && <span className="font-normal text-muted text-xs">— {f.hint}</span>}</label>
            )
            return (
              <div key={f.name} className={f.type === 'textarea' ? 'col-span-full' : ''}>
                <Field label={f.label} error={errors[f.name]} hint={f.hint}>
                  {f.type === 'select' ? (
                    <select className="input" value={String(val ?? '')} onChange={(e) => set(e.target.value)}>
                      {!f.required && <option value="">—</option>}
                      {f.required && !val && <option value="">Choose…</option>}
                      {(f.options ?? []).map((o) => typeof o === 'string' ? <option key={o} value={o}>{o}</option> : <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>
                  ) : f.type === 'textarea' ? (
                    <textarea className={`input ${f.mono ? 'font-mono' : ''}`} rows={5} value={String(val ?? '')} placeholder={f.placeholder} onChange={(e) => set(e.target.value)} />
                  ) : (
                    <input className={`input ${f.mono ? 'font-mono' : ''}`} type={f.type ?? 'text'} value={String(val ?? '')} placeholder={f.placeholder} autoComplete={f.type === 'password' ? 'new-password' : 'off'}
                      onChange={(e) => set(f.type === 'number' ? (e.target.value === '' ? '' : Number(e.target.value)) : e.target.value)} />
                  )}
                </Field>
              </div>
            )
          })}
        </div>
        {extra?.(v)}
        <div className="flex justify-end gap-2">
          <button type="button" className="btn" onClick={onClose}>Cancel</button>
          <button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : submitLabel}</button>
        </div>
      </form>
    </Modal>
  )
}
