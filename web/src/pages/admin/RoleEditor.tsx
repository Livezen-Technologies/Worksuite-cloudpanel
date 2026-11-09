import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useAction, useOne } from '../../api/hooks'
import { Field } from '../../components/Card'
import { Modal } from '../../components/Overlay'
import Select from '../../components/Select'
import type { R } from '../../lib/util'

type Perm = { name: string; label: string; group: string; granted: boolean; constraint: string | null; requires_approval: boolean }
const SCOPES = [['platform', 'Staff', 'For your own team running the platform'], ['customer', 'Customers', 'For people in a hosting customer’s organisation'], ['both', 'Both', 'Can be given to staff and customers']] as const
const CONSTRAINT: Record<string, string> = { all: 'Everything', own_org: 'Own organisation', assigned: 'Assigned only', read: 'Read only' }

/** Create, edit or copy a role: name, who it is for, and a grouped permission checklist. */
export function RoleEditor({ open, onClose, from, mode, onSaved }: { open: boolean; onClose: () => void; from: string | null; mode: 'new' | 'edit' | 'copy'; onSaved: (name: string) => void }) {
  const src = useOne<R>(open && from ? `/roles/${encodeURIComponent(from)}` : null)
  const blank = useOne<R>(open && !from ? '/roles/viewer' : null)
  const { run, busy } = useAction()
  const [label, setLabel] = useState('')
  const [description, setDescription] = useState('')
  const [scope, setScope] = useState('platform')
  const [perms, setPerms] = useState<Perm[]>([])
  useEffect(() => {
    const d = (from ? src.item : blank.item) as R | undefined
    if (!open || !d) return
    setLabel(mode === 'copy' ? `${d.label} (copy)` : mode === 'edit' ? String(d.label) : '')
    setDescription(mode === 'new' ? '' : String(d.description ?? ''))
    setScope(mode === 'new' ? 'platform' : String(d.scope === 'both' || d.scope === 'customer' ? d.scope : 'platform'))
    setPerms(((d.permissions ?? []) as Perm[]).map((p) => mode === 'new' ? { ...p, granted: false, constraint: null, requires_approval: false } : p))
  }, [open, src.item, blank.item, mode, from])
  const groups = useMemo(() => perms.reduce<Record<string, Perm[]>>((a, p) => ((a[p.group] ??= []).push(p), a), {}), [perms])
  const set = (name: string, patch: Partial<Perm>) => setPerms((ps) => ps.map((p) => p.name === name ? { ...p, ...patch } : p))
  const count = perms.filter((p) => p.granted).length
  const submit = (e: FormEvent) => {
    e.preventDefault()
    const body = { label, description: description || null, scope, permissions: perms.filter((p) => p.granted).map((p) => ({ name: p.name, constraint: p.constraint ?? 'all', requires_approval: p.requires_approval })) }
    const req = mode === 'edit' && from
      ? run<{ data: R }>({ method: 'PATCH', path: `/roles/${from}`, body, label: 'Save role', success: `${label} saved` })
      : run<{ data: R }>({ path: '/roles', body, label: 'Create role', success: `${label} created` })
    req.then((r) => { onSaved(String(r?.data?.name ?? from)); onClose() }).catch(() => {})
  }
  return (
    <Modal open={open} onClose={onClose} width={760} title={mode === 'edit' ? `Edit ${label || 'role'}` : mode === 'copy' ? 'Copy role' : 'New role'}
      footer={<><span className="mr-auto self-center text-sm text-muted">{count} permission{count === 1 ? '' : 's'}</span>
        <button type="button" className="btn" onClick={onClose}>Cancel</button>
        <button type="submit" form="role-editor" className="btn btn-primary" disabled={busy || label.trim().length < 2}>{mode === 'edit' ? 'Save role' : 'Create role'}</button></>}>
      <form id="role-editor" className="flex flex-col gap-5" onSubmit={submit}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Name"><input className="input" required minLength={2} maxLength={60} value={label} onChange={(e) => setLabel(e.target.value)} placeholder="Release manager" /></Field>
          <Field label="Description"><input className="input" maxLength={300} value={description} onChange={(e) => setDescription(e.target.value)} placeholder="What this role is for" /></Field>
        </div>
        <fieldset className="border-0 p-0 m-0"><legend className="text-[15px] font-semibold mb-2">Who it’s for</legend>
          <div className="grid gap-2 sm:grid-cols-3">
            {SCOPES.map(([v, t, h]) => (
              <label key={v} className="choice"><input type="radio" name="scope" checked={scope === v} onChange={() => setScope(v)} /><span><span className="choice-title">{t}</span><span className="choice-hint">{h}</span></span></label>
            ))}
          </div>
        </fieldset>
        <fieldset className="border-0 p-0 m-0"><legend className="text-[15px] font-semibold mb-1">Permissions</legend>
          <p className="m-0 mb-3 text-sm text-muted">You can only grant permissions you have yourself.</p>
          <div className="flex flex-col gap-4">
            {Object.entries(groups).map(([g, list]) => (
              <section key={g} className="rounded-xl border border-line">
                <header className="flex items-center justify-between px-4 py-2.5 bg-head rounded-t-xl">
                  <span className="text-sm font-semibold capitalize">{g.replace(/_/g, ' ')}</span>
                  <button type="button" className="btn btn-sm btn-ghost" onClick={() => { const all = list.every((p) => p.granted); list.forEach((p) => set(p.name, { granted: !all, constraint: p.constraint ?? 'all' })) }}>{list.every((p) => p.granted) ? 'Clear' : 'Select all'}</button>
                </header>
                <ul className="m-0 p-0 list-none">
                  {list.map((p) => (
                    <li key={p.name} className="flex flex-wrap items-center gap-3 px-4 py-2.5 border-t border-line">
                      <label className="flex items-center gap-3 flex-1 min-w-48 cursor-pointer text-sm">
                        <input type="checkbox" checked={p.granted} onChange={(e) => set(p.name, { granted: e.target.checked, constraint: p.constraint ?? 'all' })} />
                        <span><span className="font-medium">{p.label}</span> <span className="font-mono text-xs text-muted">{p.name}</span></span>
                      </label>
                      {p.granted && <>
                        <Select className="input !w-auto !min-h-8 text-xs" aria-label={`Reach of ${p.label}`} value={p.constraint ?? 'all'} onChange={(e) => set(p.name, { constraint: e.target.value })}>
                          {Object.entries(CONSTRAINT).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                        </Select>
                        <label className="flex items-center gap-2 text-xs text-muted cursor-pointer"><input type="checkbox" checked={p.requires_approval} onChange={(e) => set(p.name, { requires_approval: e.target.checked })} />Needs approval</label>
                      </>}
                    </li>
                  ))}
                </ul>
              </section>
            ))}
          </div>
        </fieldset>
      </form>
    </Modal>
  )
}
