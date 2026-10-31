import { useEffect, useState, type ReactNode } from 'react'
import { isApiError } from '../api/client'
import { Modal } from './Overlay'
import { Field } from './Card'
import Select from './Select'

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
  const [formError, setFormError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => { if (open) { setV(initial); setErrors({}) } }, [open]) // eslint-disable-line react-hooks/exhaustive-deps
  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    const missing = Object.fromEntries(fields.filter((f) => f.required && (!f.show || f.show(v)) && !v[f.name]).map((f) => [f.name, `${f.label} is required.`]))
    setErrors(missing)
    setFormError(null)
    const form = e.currentTarget as HTMLFormElement
    const focusFirst = () => requestAnimationFrame(() => (form.querySelector('[aria-invalid="true"]') as HTMLElement | null)?.focus())
    if (Object.keys(missing).length) { focusFirst(); return }
    setBusy(true)
    try { await onSubmit(v); onClose() } catch (ex) {
      if (isApiError(ex)) {
        const fe = Object.fromEntries(Object.entries(ex.fields).map(([k, m]) => [k, m[0]]))
        setErrors(fe)
        // An error on a field this form does not show, or none at all, is shown above the form.
        const shown = new Set(fields.map((f) => f.name))
        const stray = Object.entries(fe).filter(([k]) => !shown.has(k)).map(([, m]) => m)
        if (stray.length || !Object.keys(fe).length) setFormError(stray[0] ?? ex.message)
        focusFirst()
      } else setFormError(ex instanceof Error ? ex.message : 'Something went wrong. Try again.')
    } finally { setBusy(false) }
  }
  return (
    <Modal open={open} onClose={onClose} title={title} width={620}>
      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {formError && <div role="alert" className="rounded-lg border border-[var(--danger-line)] bg-r-bg text-r-fg text-sm px-3 py-2">{formError}</div>}
        {children}
        <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
          {fields.filter((f) => !f.show || f.show(v)).map((f) => {
            const val = v[f.name]
            const set = (x: unknown) => setV((o) => ({ ...o, [f.name]: x }))
            if (f.type === 'checkbox') return (
              <label key={f.name} className="col-span-full flex items-start gap-3 rounded-lg border border-line p-3 cursor-pointer hover:border-accent"><input type="checkbox" className="mt-0.5" checked={!!val} onChange={(e) => set(e.target.checked)} /><span className="flex flex-col"><span className="text-sm font-semibold">{f.label}</span>{f.hint && <span className="text-xs text-muted">{f.hint}</span>}</span></label>
            )
            return (
              <div key={f.name} className={f.type === 'textarea' ? 'col-span-full' : ''}>
                <Field label={f.label} error={errors[f.name]} hint={f.hint} required={f.required}>
                  {f.type === 'select' ? (
                    <Select className="input" aria-invalid={!!errors[f.name]} value={String(val ?? '')} onChange={(e) => set(e.target.value)}>
                      {!f.required && <option value="">—</option>}
                      {f.required && !val && <option value="">Choose…</option>}
                      {(f.options ?? []).map((o) => typeof o === 'string' ? <option key={o} value={o}>{o}</option> : <option key={o.value} value={o.value}>{o.label}</option>)}
                    </Select>
                  ) : f.type === 'textarea' ? (
                    <textarea aria-invalid={!!errors[f.name]} className={`input ${f.mono ? 'font-mono' : ''}`} rows={5} value={String(val ?? '')} placeholder={f.placeholder} onChange={(e) => set(e.target.value)} />
                  ) : (
                    <input aria-invalid={!!errors[f.name]} required={f.required} className={`input ${f.mono ? 'font-mono' : ''}`} type={f.type ?? 'text'} value={String(val ?? '')} placeholder={f.placeholder} autoComplete={f.type === 'password' ? 'new-password' : 'off'}
                      onChange={(e) => set(f.type === 'number' ? (e.target.value === '' ? '' : Number(e.target.value)) : e.target.value)} />
                  )}
                </Field>
              </div>
            )
          })}
        </div>
        {extra?.(v)}
        <div className="flex justify-end gap-2 -mx-5 -mb-5 mt-1 px-5 py-3.5 border-t border-line bg-head/40">
          <button type="button" className="btn" onClick={onClose}>Cancel</button>
          <button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : submitLabel}</button>
        </div>
      </form>
    </Modal>
  )
}
