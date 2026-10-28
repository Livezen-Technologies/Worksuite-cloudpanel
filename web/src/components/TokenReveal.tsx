import { Modal } from './Overlay'
import { CopyButton } from './CopyButton'

/** Shows a newly created secret token exactly once. */
export function TokenReveal({ token, onClose }: { token: string | null; onClose: () => void }) {
  return (
    <Modal open={!!token} onClose={onClose} title="Copy your new token" footer={<button type="button" className="btn btn-primary" onClick={onClose}>I have copied it</button>}>
      <p className="mt-0">This is the only time the token is shown. Store it somewhere safe, such as your CI secrets.</p>
      <div className="flex gap-2 items-start">
        <code className="flex-1 p-3 rounded-lg bg-code text-code-fg font-mono text-xs break-all">{token}</code>
        <CopyButton value={token ?? ''} label="Copy token" />
      </div>
    </Modal>
  )
}
