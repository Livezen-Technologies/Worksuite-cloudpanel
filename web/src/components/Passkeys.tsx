import { useState } from 'react'
import { api } from '../api/client'
import { useList } from '../api/hooks'
import { type Passkey, passkeyError, passkeysSupported, registerPasskey } from '../lib/passkeys'
import { ago } from '../lib/format'
import { Card, Field } from './Card'
import { ConfirmDialog, Modal } from './Overlay'
import { useToast } from './Toasts'
import { useAuth } from '../lib/auth'

/** Account page: register, list, rename and delete passkeys (SEC-002, UACC-002). Used by both panels. */
export function PasskeyManager() {
  const list = useList<Passkey>('/me/passkeys')
  const toast = useToast()
  // MFA-POL: a passkey can satisfy the organisation's two-step rule, so the signed-in user is refreshed.
  const { refresh } = useAuth()
  const [adding, setAdding] = useState(false)
  const [name, setName] = useState('')
  const [rename, setRename] = useState<Passkey | null>(null)
  const [del, setDel] = useState<Passkey | null>(null)
  const [busy, setBusy] = useState(false)
  const supported = passkeysSupported()

  const add = async () => {
    setBusy(true)
    try { await registerPasskey(name.trim() || 'Passkey'); toast.success('Passkey added'); setAdding(false); setName(''); list.refetch(); refresh() }
    catch (e) { toast.error(new Error(passkeyError(e)), 'Could not add the passkey') } finally { setBusy(false) }
  }
  const saveName = async () => {
    if (!rename) return
    try { await api.patch(`/me/passkeys/${rename.id}`, { name: rename.name }); toast.success('Passkey renamed'); setRename(null); list.refetch() }
    catch (e) { toast.error(e, 'Could not rename the passkey') }
  }
  const remove = async () => {
    if (!del) return
    try { await api.del(`/me/passkeys/${del.id}`); toast.success('Passkey removed'); setDel(null); list.refetch(); refresh() }
    catch (e) { toast.error(e, 'Could not remove the passkey') }
  }
  return (
    <Card title="Passkeys" actions={<button type="button" className="btn" disabled={!supported} onClick={() => setAdding(true)}>Add a passkey</button>}>
      <p className="mt-0 text-xs text-muted">Sign in with your device (fingerprint, face or security key) instead of a password, or use a passkey as your second factor.{!supported && ' This browser does not support passkeys.'}</p>
      {list.isLoading ? <div className="text-sm text-muted">Loading…</div> : list.rows.length === 0 ? <div className="text-sm text-muted">No passkeys yet.</div> : (
        <ul className="list-none m-0 p-0" aria-label="Passkeys">
          {list.rows.map((p) => (
            <li key={p.id} className="flex items-center gap-3 py-2 border-t border-line first:border-t-0">
              <div className="flex-1 min-w-0"><div className="font-semibold text-sm truncate">{p.name}</div>
                <div className="text-xs text-muted">Added {ago(p.created_at)} · {p.last_used_at ? `last used ${ago(p.last_used_at)}` : 'never used'}{p.backup_eligible ? ' · synced' : ''}</div></div>
              <button type="button" className="btn btn-sm" onClick={() => setRename({ ...p })}>Rename</button>
              <button type="button" className="btn btn-sm btn-danger" onClick={() => setDel(p)}>Delete</button>
            </li>
          ))}
        </ul>
      )}
      <Modal open={adding} onClose={() => setAdding(false)} title="Add a passkey"
        footer={<><button type="button" className="btn" onClick={() => setAdding(false)}>Cancel</button><button type="button" className="btn btn-primary" disabled={busy} onClick={add}>{busy ? 'Waiting for your device…' : 'Continue'}</button></>}>
        <Field label="Name" hint="So you can recognise it later, e.g. “Work laptop”"><input className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="Work laptop" /></Field>
      </Modal>
      <Modal open={!!rename} onClose={() => setRename(null)} title="Rename passkey"
        footer={<><button type="button" className="btn" onClick={() => setRename(null)}>Cancel</button><button type="button" className="btn btn-primary" disabled={!rename?.name.trim()} onClick={saveName}>Save</button></>}>
        <Field label="Name"><input className="input" value={rename?.name ?? ''} onChange={(e) => rename && setRename({ ...rename, name: e.target.value })} /></Field>
      </Modal>
      <ConfirmDialog open={!!del} onClose={() => setDel(null)} onConfirm={remove} title="Delete passkey?" confirmLabel="Delete" danger>
        {`“${del?.name}” will no longer sign you in.`}
      </ConfirmDialog>
    </Card>
  )
}
