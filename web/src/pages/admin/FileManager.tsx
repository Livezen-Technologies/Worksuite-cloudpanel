import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, errorMessage, request } from '../../api/client'
import { TERMINAL, useList, useServerName } from '../../api/hooks'
import type { Job } from '../../api/types'
import { Card, Empty, PageHeader } from '../../components/Card'
import { DataTable } from '../../components/DataTable'
import { Icon } from '../../components/Icon'
import { ConfirmDialog, Modal } from '../../components/Overlay'
import { useToast } from '../../components/Toasts'
import { ago } from '../../lib/format'
import type { R } from '../../lib/util'

interface Entry { name: string; type: 'dir' | 'file' | 'symlink' | 'other'; size: number; mode: string; modified: string }

/** File operations run on the agent: start one, then wait for its job to finish and return the result. */
async function fsCall<T>(start: () => Promise<unknown>): Promise<T> {
  const res = (await start()) as { data?: { job_id?: string } }
  const id = res?.data?.job_id
  if (!id) throw new Error('The panel did not start the file operation.')
  for (let i = 0; i < 120; i++) {
    const { data: job } = await api.get<{ data: Job }>(`/jobs/${id}`)
    if (TERMINAL.has(job.status)) {
      if (job.status !== 'succeeded') throw new Error(typeof job.error === 'string' ? job.error : 'The file operation failed.')
      return job.result as T
    }
    await new Promise((r) => setTimeout(r, i < 10 ? 400 : 1500))
  }
  throw new Error('The server did not answer in time. Is its agent online?')
}

const join = (dir: string, name: string) => (dir ? `${dir}/${name}` : name)
const parentOf = (p: string) => p.split('/').slice(0, -1).join('/')
const size = (n: number) => (n < 1024 ? `${n} B` : n < 1 << 20 ? `${(n / 1024).toFixed(1)} KB` : `${(n / (1 << 20)).toFixed(1)} MB`)
const validName = (n: string) => !!n && n !== '.' && n !== '..' && !n.includes('/')

export function FileManager() {
  const toast = useToast()
  const serverName = useServerName()
  const volumes = useList<R>('/volumes')
  const [volumeId, setVolumeId] = useState('')
  const [dir, setDir] = useState('')
  const [entries, setEntries] = useState<Entry[] | null>(null)
  const [truncated, setTruncated] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [editing, setEditing] = useState<{ path: string; content: string; original: string } | null>(null)
  const [naming, setNaming] = useState<{ kind: 'folder' | 'file' | 'rename'; target?: Entry; value: string } | null>(null)
  const [deleting, setDeleting] = useState<Entry | null>(null)

  const usable = useMemo(() => volumes.rows.filter((v) => v.type !== 'nfs'), [volumes.rows])
  useEffect(() => { if (!volumeId && usable[0]) setVolumeId(usable[0].id) }, [usable, volumeId])
  const volume = usable.find((v) => v.id === volumeId)
  const base = `/volumes/${volumeId}/files`

  const load = useCallback(async (path: string) => {
    if (!volumeId) return
    setLoading(true); setError(null)
    try {
      const r = await fsCall<{ entries: Entry[]; truncated: boolean }>(() => api.get(base, { path }))
      setEntries(r.entries); setTruncated(r.truncated); setDir(path)
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }, [volumeId, base])
  useEffect(() => { setEntries(null); load('') }, [volumeId]) // eslint-disable-line react-hooks/exhaustive-deps

  async function act(label: string, start: () => Promise<unknown>, after?: () => void) {
    setBusy(true)
    try {
      await fsCall(start)
      toast.success(label)
      after?.()
      await load(dir)
    } catch (e) {
      toast.push({ kind: 'error', title: `${label} failed`, detail: errorMessage(e) })
    } finally {
      setBusy(false)
    }
  }

  async function open(e: Entry) {
    if (e.type === 'dir') return load(join(dir, e.name))
    if (e.type !== 'file') return
    setBusy(true)
    try {
      const r = await fsCall<{ content: string }>(() => api.get(`${base}/content`, { path: join(dir, e.name) }))
      setEditing({ path: join(dir, e.name), content: r.content, original: r.content })
    } catch (err) {
      toast.push({ kind: 'error', title: `Could not open ${e.name}`, detail: errorMessage(err) })
    } finally {
      setBusy(false)
    }
  }

  function submitName() {
    if (!naming || !validName(naming.value)) return
    const n = naming.value
    if (naming.kind === 'rename' && naming.target) {
      return act(`Renamed to ${n}`, () => api.post(`${base}/rename`, { path: join(dir, naming.target!.name), new_name: n }), () => setNaming(null))
    }
    if (naming.kind === 'folder') return act(`Created ${n}`, () => api.post(`${base}/folders`, { path: join(dir, n) }), () => setNaming(null))
    return act(`Created ${n}`, () => request(`${base}/content`, { method: 'PUT', body: { path: join(dir, n), content: '', create: true } }), () => setNaming(null))
  }

  const crumbs = dir ? dir.split('/') : []
  const dirty = !!editing && editing.content !== editing.original

  return (
    <>
      <PageHeader title="File manager" subtitle="Browse and edit the files in a volume. Every change is recorded in the audit log."
        actions={<>
          <button type="button" className="btn" disabled={!entries || busy} onClick={() => setNaming({ kind: 'folder', value: '' })}>New folder</button>
          <button type="button" className="btn btn-primary" disabled={!entries || busy} onClick={() => setNaming({ kind: 'file', value: '' })}>New file</button>
        </>} />
      <Card pad={false}>
        <div className="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-line">
          <label className="flex items-center gap-2 text-sm">Volume
            <select className="input h-9" value={volumeId} onChange={(e) => setVolumeId(e.target.value)} aria-label="Volume">
              {usable.map((v) => <option key={v.id} value={v.id}>{v.name} · {serverName(v)}</option>)}
            </select>
          </label>
          <nav aria-label="Folder" className="flex flex-wrap items-center gap-1 text-sm font-mono">
            <button type="button" className="btn btn-sm border-0" onClick={() => load('')}>{volume?.name ?? '/'}</button>
            {crumbs.map((c, i) => (
              <span key={i} className="flex items-center gap-1"><span aria-hidden="true" className="text-muted">/</span>
                <button type="button" className="btn btn-sm border-0" onClick={() => load(crumbs.slice(0, i + 1).join('/'))}>{c}</button>
              </span>
            ))}
          </nav>
          <button type="button" className="btn btn-sm ml-auto" disabled={loading} onClick={() => load(dir)}>Refresh</button>
        </div>
        {!volumeId && !volumes.isLoading ? <Empty>No volumes yet. Create one under Volumes &amp; Storage.</Empty>
          : error ? <div role="alert" className="p-6 text-sm text-danger">{error}</div>
          : (
            <DataTable label="Files" rows={[...(dir ? [{ name: '..', type: 'dir', size: 0, mode: '', modified: '' } as Entry] : []), ...(entries ?? [])]}
              loading={loading && !entries} rowKey={(r) => r.name}
              columns={[
                { key: 'name', header: 'Name', render: (r) => (
                  r.name === '..'
                    ? <button type="button" className="btn btn-sm border-0" onClick={() => load(parentOf(dir))}>.. (up one folder)</button>
                    : <button type="button" className="btn btn-sm border-0 font-semibold" disabled={busy || r.type === 'other' || r.type === 'symlink'} onClick={() => open(r)}>
                        <Icon name={r.type === 'dir' ? 'archive' : 'file'} size={16} /> {r.name}{r.type === 'symlink' ? ' (link)' : ''}
                      </button>
                ) },
                { key: 'size', header: 'Size', value: (r) => r.size, render: (r) => (r.type === 'file' ? size(r.size) : '') },
                { key: 'mode', header: 'Permissions', render: (r) => <span className="font-mono text-xs">{r.mode}</span> },
                { key: 'modified', header: 'Modified', value: (r) => r.modified, render: (r) => (r.modified ? ago(r.modified) : '') },
                { key: 'actions', header: '', sortable: false, render: (r) => r.name === '..' ? null : (
                  <span className="flex gap-2 justify-end">
                    <button type="button" className="btn btn-sm" disabled={busy} onClick={() => setNaming({ kind: 'rename', target: r, value: r.name })}>Rename</button>
                    <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => setDeleting(r)}>Delete</button>
                  </span>
                ) },
              ]} />
          )}
        {truncated && <p className="m-0 px-4 py-3 text-xs text-muted border-t border-line">This folder has more than 5,000 items; only the first 5,000 are shown.</p>}
      </Card>

      <Modal open={!!naming} onClose={() => setNaming(null)} title={naming?.kind === 'rename' ? `Rename ${naming.target?.name}` : naming?.kind === 'folder' ? 'New folder' : 'New file'}
        footer={<>
          <button type="button" className="btn" onClick={() => setNaming(null)}>Cancel</button>
          <button type="button" className="btn btn-primary" disabled={busy || !validName(naming?.value ?? '')} onClick={submitName}>{busy ? 'Working…' : naming?.kind === 'rename' ? 'Rename' : 'Create'}</button>
        </>}>
        <label className="label">Name
          <input className="input font-mono" autoFocus value={naming?.value ?? ''} onChange={(e) => naming && setNaming({ ...naming, value: e.target.value })}
            onKeyDown={(e) => { if (e.key === 'Enter') submitName() }} aria-invalid={!!naming?.value && !validName(naming.value)} />
        </label>
        {!!naming?.value && !validName(naming.value) && <p className="text-xs text-danger mt-2">A name cannot contain “/” or be “.” or “..”.</p>}
      </Modal>

      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} danger busy={busy}
        title={`Delete ${deleting?.name}?`} confirmLabel="Delete" confirmText={deleting?.type === 'dir' ? deleting.name : undefined}
        onConfirm={() => deleting && act(`Deleted ${deleting.name}`, () => api.del(base, { path: join(dir, deleting.name), recursive: deleting.type === 'dir' }), () => setDeleting(null))}>
        {deleting?.type === 'dir'
          ? <p className="m-0 text-sm">This deletes the folder and everything in it. It cannot be undone. Type the folder name to confirm.</p>
          : <p className="m-0 text-sm">This cannot be undone.</p>}
      </ConfirmDialog>

      <Modal open={!!editing} width={960} onClose={() => { if (!dirty || window.confirm('Discard unsaved changes?')) setEditing(null) }}
        title={`${editing?.path ?? ''}${dirty ? ' • unsaved' : ''}`}
        footer={<>
          <span className="mr-auto text-xs text-muted">{editing ? `${editing.content.split('\n').length} lines` : ''}</span>
          <button type="button" className="btn" onClick={() => setEditing(null)}>Close</button>
          <button type="button" className="btn btn-primary" disabled={busy || !dirty}
            onClick={() => editing && act(`Saved ${editing.path}`, () => request(`${base}/content`, { method: 'PUT', body: { path: editing.path, content: editing.content } }), () => setEditing({ ...editing, original: editing.content }))}>
            {busy ? 'Saving…' : 'Save'}
          </button>
        </>}>
        <textarea aria-label={`Contents of ${editing?.path}`} spellCheck={false} className="input font-mono text-xs w-full min-h-[60vh] leading-relaxed"
          value={editing?.content ?? ''} onChange={(e) => editing && setEditing({ ...editing, content: e.target.value })} />
      </Modal>
    </>
  )
}
