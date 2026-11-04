import { useState } from 'react'
import { useAction, useOne } from '../api/hooks'
import { ago } from '../lib/format'
import { Card, Field } from './Card'
import { CopyButton } from './CopyButton'
import { ConfirmDialog, Modal } from './Overlay'

type Key = { id: string; name: string; public_key: string; fingerprint: string; last_used_at?: string | null; created_at?: string }
type Data = { gateway: { enabled: boolean; host: string; port: number }; keys: Key[] }

/** Saves the generated private key as a file; it is never shown again. */
function download(name: string, text: string) {
  const a = document.createElement('a')
  a.href = URL.createObjectURL(new Blob([text], { type: 'application/x-pem-file' }))
  a.download = name
  a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 1000)
}

/** SSH-GW: personal SSH keys for opening app and container shells with PuTTY or any SSH client. */
export function SshKeyManager() {
  const q = useOne<Data>('/me/ssh-keys')
  const { run, busy } = useAction()
  const [mode, setMode] = useState<null | 'paste' | 'generate'>(null)
  const [f, setF] = useState({ name: '', public_key: '' })
  const [made, setMade] = useState<(Key & { private_key: string }) | null>(null)
  const [del, setDel] = useState<Key | null>(null)
  const d = q.item
  const gw = d?.gateway
  const host = gw?.host ?? 'panel.example.com'
  const port = gw?.port ?? 2222
  const close = () => { setMode(null); setF({ name: '', public_key: '' }) }
  const save = () => {
    const name = f.name.trim()
    if (mode === 'generate') {
      run<{ data: Key & { private_key: string } }>({ path: '/me/ssh-keys/generate', body: { name }, label: 'Generate SSH key', success: 'Key created. Download the private key now.' })
        .then((r) => { setMade(r.data); close(); q.refetch() }).catch(() => {})
    } else {
      run({ path: '/me/ssh-keys', body: { ...f, name }, label: 'Add SSH key', success: 'SSH key added' }).then(() => { close(); q.refetch() }).catch(() => {})
    }
  }

  return (
    <Card title="SSH keys" subtitle="Open a shell in your apps with PuTTY, Terminal or any SSH client."
      actions={<span className="flex gap-2"><button type="button" className="btn" onClick={() => setMode('paste')}>Add a key</button><button type="button" className="btn" onClick={() => setMode('generate')}>Generate a key</button></span>}>
      {gw && !gw.enabled && <p role="note" className="m-0 mb-3 text-sm text-muted">SSH access is not switched on for this panel yet. Keys you add now will work once it is.</p>}
      {!d ? <p className="m-0 text-sm text-muted">Loading…</p> : d.keys.length === 0 ? <p className="m-0 text-sm text-muted">No SSH keys yet.</p> : (
        <ul className="list-none m-0 p-0 flex flex-col">{d.keys.map((k) => (
          <li key={k.id} className="flex flex-wrap items-center gap-3 py-2 border-t border-line first:border-t-0 text-sm">
            <span className="flex-1 min-w-0"><span className="font-semibold">{k.name}</span> <span className="block text-xs text-muted font-mono truncate">{k.fingerprint}</span></span>
            <span className="text-xs text-muted">{k.last_used_at ? `used ${ago(k.last_used_at)}` : 'never used'}</span>
            <button type="button" className="btn btn-sm" aria-label={`Delete key ${k.name}`} onClick={() => setDel(k)}>Delete</button>
          </li>
        ))}</ul>
      )}
      <details className="mt-3 text-sm">
        <summary className="cursor-pointer font-semibold">How to connect</summary>
        <div className="mt-2 flex flex-col gap-2 text-muted">
          <p className="m-0">The SSH user name is the container or app you want a shell in. Use <code>list</code> to see yours.</p>
          <div className="flex items-center gap-2"><code className="font-mono text-xs">ssh -p {port} list@{host}</code><CopyButton value={`ssh -p ${port} list@${host}`} label="Copy" /></div>
          <div className="flex items-center gap-2"><code className="font-mono text-xs">ssh -p {port} shop-web@{host}</code></div>
          <p className="m-0"><b>PuTTY:</b> Host Name <code>shop-web@{host}</code>, Port <code>{port}</code>. Under Connection → SSH → Auth → Credentials, choose your private key (.ppk). A key generated here downloads in OpenSSH format: open it in PuTTYgen (Load) and “Save private key” to get a .ppk.</p>
          <p className="m-0">Shells run as a non-root user, end after the session time limit, are recorded, and follow the same permissions and approvals as the web terminal. File copy (scp/sftp) is not available.</p>
        </div>
      </details>

      <Modal open={mode !== null} onClose={close} title={mode === 'generate' ? 'Generate an SSH key' : 'Add an SSH key'}
        subtitle={mode === 'generate' ? 'A new Ed25519 key. You download the private key once; the panel keeps only the public key.' : 'Paste your public key (one line). In PuTTYgen, copy the box “Public key for pasting into OpenSSH authorized_keys file”.'}
        footer={<><button type="button" className="btn" onClick={close}>Cancel</button><button type="button" className="btn btn-primary" disabled={busy || f.name.trim().length < 2 || (mode === 'paste' && !f.public_key.trim())} onClick={save}>{mode === 'generate' ? 'Generate' : 'Add key'}</button></>}>
        <div className="flex flex-col gap-3">
          <Field label="Name"><input className="input" value={f.name} placeholder="Work laptop" onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
          {mode === 'paste' && <Field label="Public key"><textarea className="input font-mono text-xs min-h-[90px]" value={f.public_key} placeholder="ssh-ed25519 AAAA… you@laptop" onChange={(e) => setF({ ...f, public_key: e.target.value })} /></Field>}
        </div>
      </Modal>

      <Modal open={!!made} onClose={() => setMade(null)} title="Download your private key" subtitle="This is the only time it is shown. Keep it private; anyone with it can open your shells."
        footer={<button type="button" className="btn btn-primary" onClick={() => setMade(null)}>Done</button>}>
        {made && <div className="flex flex-col gap-3 text-sm">
          <button type="button" className="btn btn-primary self-start" onClick={() => download(`${made.name.replace(/[^A-Za-z0-9._-]/g, '-')}_ed25519`, made.private_key)}>Download private key</button>
          <p className="m-0 text-muted">OpenSSH / macOS / Linux: save it as <code>~/.ssh/{made.name.replace(/[^A-Za-z0-9._-]/g, '-')}_ed25519</code>, then <code>chmod 600</code> it. PuTTY: load it in PuTTYgen and save it as .ppk.</p>
          <p className="m-0 text-xs text-muted font-mono">{made.fingerprint}</p>
        </div>}
      </Modal>
      <ConfirmDialog open={!!del} title={`Delete SSH key ${del?.name ?? ''}?`} confirmLabel="Delete" danger busy={busy}
        onClose={() => setDel(null)} onConfirm={() => { if (del) run({ path: `/me/ssh-keys/${del.id}`, method: 'DELETE', label: 'Delete SSH key', success: 'SSH key deleted' }).then(() => { setDel(null); q.refetch() }).catch(() => {}) }}>
        Shells can no longer be opened with this key.
      </ConfirmDialog>
    </Card>
  )
}
