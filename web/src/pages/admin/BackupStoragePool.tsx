import { useState } from 'react'
import { useAction, useList } from '../../api/hooks'
import { Card, Empty, Meter, Toggle } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { ConfirmDialog, Modal } from '../../components/Overlay'
import { FormModal, type FieldDef } from '../../components/FormModal'
import { ago } from '../../lib/format'
import { num, type R } from '../../lib/util'

const FIELDS: FieldDef[] = [
  { name: 'name', label: 'Name', required: true, placeholder: 'Backup server 1' },
  { name: 'host', label: 'Host', mono: true, required: true, placeholder: '40.160.59.90' },
  { name: 'port', label: 'Port', mono: true, placeholder: '22' },
  { name: 'username', label: 'SFTP user', mono: true, required: true, placeholder: 'panelbackup' },
  { name: 'path', label: 'Folder', mono: true, placeholder: 'backups', hint: 'Relative to the user’s SFTP root' },
  { name: 'private_key', label: 'Private key (optional)', type: 'textarea', mono: true, hint: 'Leave empty and the panel creates a key; you then add its public key to the server’s authorized_keys.' },
]

const gb = (b: unknown) => `${(num(b) / 1073741824).toFixed(num(b) > 1e12 ? 0 : 1)} GB`

/** Backup manager → Storage pool: SFTP servers that hold backups. Jobs write to the member with the most free space. */
export function BackupStoragePool() {
  const servers = useList<R>('/backup-storage-servers', { per_page: 100 }, { refetchInterval: 60000 })
  const { run, busy } = useAction()
  const [edit, setEdit] = useState<R | null>(null)
  const [del, setDel] = useState<R | null>(null)
  const [created, setCreated] = useState<R | null>(null)
  const totals = servers.rows.filter((s) => s.enabled && s.status === 'ok').reduce((t, s) => ({ total: t.total + num(s.total_bytes), free: t.free + num(s.free_bytes) }), { total: 0, free: 0 })

  const save = async (v: R) => {
    const body: R = { name: v.name, host: v.host, username: v.username, ...(v.port ? { port: Number(v.port) } : {}), ...(v.path ? { path: v.path } : {}), ...(v.private_key ? { private_key: v.private_key } : {}) }
    const res = await run({ method: edit?.id ? 'PATCH' : 'POST', path: edit?.id ? `/backup-storage-servers/${edit.id}` : '/backup-storage-servers', body, label: 'Save storage server', success: 'Storage server saved' }) as R | undefined
    if (!edit?.id && !v.private_key && res) setCreated((res.data ?? res) as R)
  }

  return (
    <>
      <div className="flex flex-wrap items-center gap-3 mb-3">
        <p className="m-0 text-sm text-muted flex-1 min-w-[240px]">
          SFTP servers that hold backups. Each night’s backups go to the healthy server with the most free space.
          {totals.total ? ` Pool: ${gb(totals.free)} free of ${gb(totals.total)}.` : ''}
        </p>
        <button type="button" className="btn btn-primary" onClick={() => setEdit({ port: 22, path: 'backups' })}>+ Add server</button>
      </div>
      {servers.isLoading ? <p className="m-0 text-sm text-muted">Loading…</p> : !servers.rows.length ? <Card><Empty>No storage servers yet. Add one to start sending backups to it.</Empty></Card> :
        <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(min(100%,290px),1fr))]">
          {servers.rows.map((s) => {
            const pct = s.usage_percent == null ? null : num(s.usage_percent)
            return (
              <section key={s.id} className="card p-4 flex flex-col gap-2.5 min-w-0">
                <div className="flex items-center gap-2.5">
                  <span className="w-9 h-9 shrink-0 rounded-lg bg-sel text-accent grid place-items-center text-xs font-bold">SF</span>
                  <div className="flex-1 min-w-0">
                    <div className="font-bold truncate">{s.name}</div>
                    <div className="text-xs text-muted font-mono truncate">{s.username}@{s.host}{num(s.port) !== 22 ? `:${s.port}` : ''}/{s.path}</div>
                  </div>
                  <span title={s.message ?? undefined}><Badge tone={s.status === 'ok' ? (pct != null && pct > 85 ? 'y' : 'g') : s.status === 'error' ? 'r' : undefined}>{s.status === 'ok' ? (pct != null && pct > 85 ? 'nearly full' : 'connected') : s.status === 'error' ? 'unreachable' : 'not tested'}</Badge></span>
                </div>
                {s.total_bytes ? <div>
                  <div className="flex justify-between text-[12.5px]"><span className="text-muted">Free</span><span className="font-semibold">{gb(s.free_bytes)} of {gb(s.total_bytes)}{pct != null ? ` · ${pct}% used` : ''}</span></div>
                  {pct != null && <div className="mt-1.5"><Meter value={pct} label={`${s.name} usage`} color={pct > 85 ? 'var(--r-fg)' : undefined} /></div>}
                </div> : null}
                {s.status === 'error' && s.message ? <p className="m-0 text-xs text-[var(--r-fg)] break-words">{s.message}</p> : null}
                <div className="text-xs text-muted">{s.checked_at ? `Checked ${ago(s.checked_at)}` : 'Never checked'}</div>
                <div className="flex flex-wrap items-center gap-2">
                  <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/backup-storage-servers/${s.id}/check`, label: `Test ${s.name}`, success: 'Checked' }).catch(() => {})}>Test connection</button>
                  <button type="button" className="btn btn-sm btn-ghost" onClick={() => setEdit(s)}>Edit</button>
                  <button type="button" className="btn btn-sm btn-ghost" onClick={() => setCreated(s)}>Public key</button>
                  <button type="button" className="btn btn-sm btn-ghost" onClick={() => setDel(s)}>Remove</button>
                  <span className="ml-auto"><Toggle checked={s.enabled !== false} label={`${s.enabled !== false ? 'Disable' : 'Enable'} ${s.name}`} onChange={(v) => run({ method: 'PATCH', path: `/backup-storage-servers/${s.id}`, body: { enabled: v }, label: 'Update storage server', success: v ? 'Server enabled' : 'Server disabled' }).catch(() => {})} /></span>
                </div>
              </section>)
          })}
        </div>}

      <FormModal open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? `Edit ${edit.name}` : 'Add storage server'} fields={FIELDS} initial={edit ?? {}} submitLabel="Save server"
        extra={() => <p className="m-0 text-xs text-muted">{edit?.id ? 'The stored key stays in place unless you paste a new one. Changing the host re-pins its host key on the next test.' : 'After saving, press Test connection. The server’s host key is pinned on the first successful test.'}</p>}
        onSubmit={save} />

      <Modal open={!!created} onClose={() => setCreated(null)} title={`Public key for ${created?.name ?? ''}`} width={640}>
        <div className="flex flex-col gap-3 text-sm">
          <p className="m-0">Add this line to <span className="font-mono">~/.ssh/authorized_keys</span> of <span className="font-mono">{created?.username}</span> on {created?.host}, then press Test connection.</p>
          <textarea readOnly className="input font-mono text-xs min-h-[90px]" value={String(created?.public_key ?? '')} onFocus={(e) => e.currentTarget.select()} aria-label="Public key" />
          <div className="flex justify-end"><button type="button" className="btn btn-primary" onClick={() => setCreated(null)}>Done</button></div>
        </div>
      </Modal>

      <ConfirmDialog open={!!del} onClose={() => setDel(null)} title={`Remove ${del?.name ?? ''}?`} confirmLabel="Remove" danger
        onConfirm={() => run({ method: 'DELETE', path: `/backup-storage-servers/${del!.id}`, label: 'Remove storage server', success: 'Storage server removed' }).then(() => setDel(null)).catch(() => {})}>
        New backups stop going to this server. Backups already on it are not deleted.
      </ConfirmDialog>
    </>
  )
}
