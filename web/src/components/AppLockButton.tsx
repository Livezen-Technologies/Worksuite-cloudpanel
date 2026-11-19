import { useState } from 'react'
import { useAction } from '../api/hooks'
import { Modal } from './Overlay'
import type { R } from '../lib/util'

/**
 * Locks or unlocks public access to an app. Locked: every domain of the app answers 403, except the
 * always-allowed admin ranges and its allow list. Separate from IP blocks: unlocking never clears a block.
 */
export function AppLockButton({ app, base, onChange }: { app: R; base: '/applications' | '/app/apps'; onChange?: () => void }) {
  const { run, busy } = useAction()
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState('')
  const locked = app.public_access === false
  if (locked) {
    return <button type="button" className="btn" disabled={busy} title={app.lock_reason ? `Locked: ${app.lock_reason}` : 'Locked'}
      onClick={() => run({ path: `${base}/${app.id}/unlock`, label: `Unlock ${app.name}`, success: 'Public access restored' }).then(() => onChange?.()).catch(() => {})}>Unlock</button>
  }
  return (
    <>
      <button type="button" className="btn" onClick={() => setOpen(true)}>Lock</button>
      <Modal open={open} onClose={() => setOpen(false)} title={`Lock ${String(app.name)}?`} width={520}
        footer={<><button type="button" className="btn" onClick={() => setOpen(false)}>Cancel</button>
          <button type="button" className="btn btn-danger" disabled={busy} onClick={() => run({ path: `${base}/${app.id}/lock`, body: { reason: reason || null }, label: `Lock ${app.name}`, success: 'Public access locked' }).then(() => { setOpen(false); setReason(''); onChange?.() }).catch(() => {})}>Lock app</button></>}>
        <div className="flex flex-col gap-3 text-sm">
          <p className="m-0">Every address of this app will answer 403 to the public. Addresses on its allow list and the administrators’ ranges can still reach it. IP blocks are not affected.</p>
          <label><span className="block text-xs text-muted mb-1">Reason (optional, shown in the audit log)</span><input className="input" value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} /></label>
        </div>
      </Modal>
    </>
  )
}
