import { useCallback, useEffect, useMemo, useRef, useState, type DragEvent } from 'react'
import { api, apiBase, errorMessage, request, tokenStore } from '../../api/client'
import { TERMINAL, useList, useServerName } from '../../api/hooks'
import type { Job } from '../../api/types'
import { Empty } from '../../components/Card'
import { ConfirmDialog, Modal } from '../../components/Overlay'
import { useToast } from '../../components/Toasts'
import type { R } from '../../lib/util'
import { useAuth } from '../../lib/auth'

/** Platform roles that may change file ownership (the API enforces the same rule). */
const OWNER_ROLES = ['super_admin', 'infra_admin', 'devops']

interface TrashItem { id: string; path: string; type: string; size: number; deleted_at: string; expires_at: string }
const ARCHIVE = /\.(zip|tar|tar\.gz|tgz)$/i
interface Entry { name: string; type: 'dir' | 'file' | 'symlink' | 'other'; size: number; mode: string; modified: string; uid?: number; gid?: number }

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
/** Outline icons (24px grid, stroke) matching the rest of the panel. */
const P = {
  folder: 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
  folderOpen: 'M3 7a2 2 0 0 1 2-2h4l2 2h7a2 2 0 0 1 2 2v1M3 7v10a2 2 0 0 0 2 2h12.5a2 2 0 0 0 1.9-1.4L21.8 12a1 1 0 0 0-1-1.3H7.4a2 2 0 0 0-1.9 1.4L3 19',
  folderPlus: 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM12 10v6M9 13h6',
  upload: 'M12 15V4m0 0-4 4m4-4 4 4M5 15v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3',
  dots: 'M6 12h.01M12 12h.01M18 12h.01',
  server: 'M4 5h16v6H4zM4 13h16v6H4zM8 8h.01M8 16h.01',
  drive: 'M3 14h18M3 14l2.5-7h13L21 14v4a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1zM17 16.5h.01',
  trash: 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6',
  code: 'm9 8-4 4 4 4m6-8 4 4-4 4m-2-10-2 12',
  doc: 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
  link: 'M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1',
  up: 'M12 19V5m0 0-6 6m6-6 6 6',
}
const CODE = /\.(php|js|mjs|ts|tsx|jsx|css|scss|html?|json|ya?ml|py|rb|go|sh|sql|xml|vue)$/i
const iconFor = (e: Entry) => (e.type === 'dir' ? P.folder : e.type === 'symlink' ? P.link : CODE.test(e.name) ? P.code : P.doc)

function IconSvg({ d, size = 20, className = '' }: { d: string; size?: number; className?: string }) {
  return <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.6} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className={className}><path d={d} /></svg>
}

function ToolButton({ label, d, onClick, disabled, expanded }: { label: string; d: string; onClick?: () => void; disabled?: boolean; expanded?: boolean }) {
  return (
    <button type="button" aria-label={label} title={label} aria-expanded={expanded} disabled={disabled} onClick={onClick}
      className="grid place-content-center w-11 h-11 rounded-xl border border-line bg-[var(--head)] text-[var(--text)] cursor-pointer hover:border-[var(--accent)] disabled:opacity-45 disabled:cursor-not-allowed">
      <IconSvg d={d} size={20} />
    </button>
  )
}

function SideLink({ d, label, active, onClick, disabled, note }: { d: string; label: string; active?: boolean; onClick?: () => void; disabled?: boolean; note?: string }) {
  return (
    <button type="button" disabled={disabled} onClick={onClick} aria-current={active ? 'location' : undefined}
      className={`flex items-center gap-3 min-h-11 px-3 rounded-lg border-0 text-left text-[15px] font-[inherit] cursor-pointer disabled:cursor-not-allowed disabled:opacity-55 ${active ? 'bg-[var(--sel)] text-[var(--text)] font-semibold' : 'bg-transparent text-[var(--text)] hover:bg-[var(--head)]'}`}>
      <IconSvg d={d} size={20} className="shrink-0" /><span className="truncate flex-1">{label}</span>{note && <span className="text-xs text-muted">{note}</span>}
    </button>
  )
}

/** Waits for a file job the panel already started (by job id) and returns its result. */
async function waitJob<T>(id: string): Promise<T> {
  return fsCall<T>(async () => ({ data: { job_id: id } }))
}

/** Sends files to the panel with upload progress (fetch cannot report it). */
function postFiles(url: string, form: FormData, onProgress: (pct: number) => void): Promise<string> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    xhr.open('POST', url)
    const token = tokenStore.get()
    if (token) xhr.setRequestHeader('Authorization', `Bearer ${token}`)
    xhr.setRequestHeader('Accept', 'application/json')
    xhr.upload.onprogress = (e) => { if (e.lengthComputable) onProgress(Math.round((e.loaded / e.total) * 100)) }
    xhr.onerror = () => reject(new Error('The upload could not reach the panel.'))
    xhr.onload = () => {
      let body: { data?: { job_id?: string }; message?: string } = {}
      try { body = JSON.parse(xhr.responseText) } catch { /* not JSON */ }
      if (xhr.status === 202 && body.data?.job_id) resolve(body.data.job_id)
      else reject(new Error(body.message || (xhr.status === 413 ? 'Those files are larger than the panel accepts.' : `Upload failed (HTTP ${xhr.status}).`)))
    }
    xhr.send(form)
  })
}

interface Picked { file: File; rel: string }

/** Files dropped from the desktop, walking into dropped folders. */
async function droppedFiles(dt: DataTransfer): Promise<Picked[]> {
  const out: Picked[] = []
  type FSEntry = { isFile: boolean; isDirectory: boolean; name: string; fullPath: string; file?: (cb: (f: File) => void, err: (e: unknown) => void) => void; createReader?: () => { readEntries: (cb: (es: FSEntry[]) => void, err: (e: unknown) => void) => void } }
  const walk = async (e: FSEntry): Promise<void> => {
    if (e.isFile && e.file) {
      const f = await new Promise<File>((res, rej) => e.file!(res, rej))
      out.push({ file: f, rel: e.fullPath.replace(/^\//, '') })
    } else if (e.isDirectory && e.createReader) {
      const reader = e.createReader()
      for (;;) {
        const batch = await new Promise<FSEntry[]>((res, rej) => reader.readEntries(res, rej))
        if (!batch.length) break
        for (const c of batch) await walk(c)
      }
    }
  }
  const entries = Array.from(dt.items).map((i) => (i as DataTransferItem & { webkitGetAsEntry?: () => FSEntry | null }).webkitGetAsEntry?.()).filter(Boolean) as FSEntry[]
  if (!entries.length) return Array.from(dt.files).map((f) => ({ file: f, rel: f.name }))
  for (const e of entries) await walk(e)
  return out
}

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
  const [deleting, setDeleting] = useState<Entry[] | null>(null)
  const [permanent, setPermanent] = useState(false)
  const [view, setView] = useState<'files' | 'trash'>('files')
  const [trash, setTrash] = useState<TrashItem[] | null>(null)
  const [trashSel, setTrashSel] = useState<Set<string>>(new Set())
  const { me } = useAuth()
  const canChown = OWNER_ROLES.includes(String(me?.role ?? '').toLowerCase())
  const [perm, setPerm] = useState<{ items: Entry[]; mode: number; recursive: boolean; uid: string; gid: string } | null>(null)
  const [op, setOp] = useState<{ kind: 'copy' | 'move' | 'compress' | 'extract'; names: string[]; dest: string; name: string; format: string; overwrite: boolean } | null>(null)
  const [filter, setFilter] = useState('')
  const [menu, setMenu] = useState(false)
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [transfer, setTransfer] = useState<{ label: string; pct: number | null } | null>(null)
  const [dragging, setDragging] = useState(false)
  const [overwrite, setOverwrite] = useState<Picked[] | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)
  const folderInput = useRef<HTMLInputElement>(null)

  const usable = useMemo(() => volumes.rows.filter((v) => v.type !== 'nfs'), [volumes.rows])
  useEffect(() => { if (!volumeId && usable[0]) setVolumeId(usable[0].id) }, [usable, volumeId])
  const volume = usable.find((v) => v.id === volumeId)
  const base = `/volumes/${volumeId}/files`

  const load = useCallback(async (path: string) => {
    if (!volumeId) return
    setLoading(true); setError(null)
    try {
      const r = await fsCall<{ entries: Entry[]; truncated: boolean }>(() => api.get(base, { path }))
      setEntries(r.entries); setTruncated(r.truncated); setDir(path); setFilter(''); setSelected(new Set()); setView('files')
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
      const r = (await start()) as { data?: { job_id?: string } }
      if (r?.data?.job_id) await waitJob(r.data.job_id)
      toast.success(label)
      after?.()
      await load(dir)
    } catch (e) {
      toast.push({ kind: 'error', title: `${label} failed`, detail: errorMessage(e) })
    } finally {
      setBusy(false)
    }
  }

  async function upload(picked: Picked[], replace = false) {
    if (!picked.length || !volumeId) return
    const names = new Set((entries ?? []).map((e) => e.name))
    if (!replace && picked.some((p) => names.has(p.rel.split('/')[0]))) { setOverwrite(picked); return }
    const form = new FormData()
    form.append('path', dir)
    if (replace) form.append('overwrite', '1')
    picked.forEach((p) => { form.append('files[]', p.file); form.append('relative_paths[]', p.rel) })
    const label = picked.length === 1 ? picked[0].rel : `${picked.length} files`
    setBusy(true); setTransfer({ label: `Uploading ${label}`, pct: 0 })
    try {
      const job = await postFiles(`${apiBase()}${base}/upload`, form, (pct) => setTransfer({ label: `Uploading ${label}`, pct }))
      setTransfer({ label: `Writing ${label} to the server`, pct: null })
      await waitJob(job)
      toast.success(`Uploaded ${label}`)
      await load(dir)
    } catch (e) {
      toast.push({ kind: 'error', title: 'Upload failed', detail: errorMessage(e) })
    } finally {
      setBusy(false); setTransfer(null)
    }
  }

  async function download(names: string[]) {
    if (!names.length) return
    const label = names.length === 1 ? names[0] : `${names.length} items`
    setBusy(true); setTransfer({ label: `Preparing ${label}`, pct: null })
    try {
      const r = await fsCall<{ download_url: string }>(() => api.post(`${base}/download`, { paths: names.map((n) => join(dir, n)) }))
      const a = document.createElement('a')
      a.href = r.download_url; a.rel = 'noopener'; document.body.appendChild(a); a.click(); a.remove()
    } catch (e) {
      toast.push({ kind: 'error', title: `Could not download ${label}`, detail: errorMessage(e) })
    } finally {
      setBusy(false); setTransfer(null)
    }
  }

  function onDrop(e: DragEvent) {
    e.preventDefault(); setDragging(false)
    if (!entries || busy) return
    droppedFiles(e.dataTransfer).then((p) => upload(p))
  }

  async function loadTrash() {
    setView('trash'); setLoading(true); setError(null); setTrashSel(new Set())
    try {
      const r = await fsCall<{ items: TrashItem[] }>(() => api.get(`${base}/trash`))
      setTrash(r.items)
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }

  async function trashAct(label: string, start: () => Promise<unknown>) {
    setBusy(true)
    try {
      await fsCall(start)
      toast.success(label)
      await loadTrash()
    } catch (e) {
      toast.push({ kind: 'error', title: `${label} failed`, detail: errorMessage(e) })
    } finally {
      setBusy(false)
    }
  }

  function remove(items: Entry[]) {
    const label = items.length === 1 ? items[0].name : `${items.length} items`
    const paths = items.map((i) => join(dir, i.name))
    if (!permanent) return act(`Moved ${label} to Trash`, () => api.post(`${base}/trash`, { paths }), () => { setDeleting(null); setSelected(new Set()) })
    return act(`Deleted ${label}`, async () => {
      let last: unknown
      for (const i of items) last = await fsCall(() => api.del(base, { path: join(dir, i.name), recursive: i.type === 'dir' }))
      return { data: { job_id: '' }, last }
    }, () => { setDeleting(null); setSelected(new Set()) })
  }

  function runOp() {
    if (!op) return
    const paths = op.names.map((n) => join(dir, n))
    const dest = op.dest.replace(/^\/+|\/+$/g, '')
    const label = op.names.length === 1 ? op.names[0] : `${op.names.length} items`
    const done = () => { setOp(null); setSelected(new Set()) }
    if (op.kind === 'copy') return act(`Copied ${label}`, () => api.post(`${base}/copy`, { paths, dest }), done)
    if (op.kind === 'move') return act(`Moved ${label}`, () => api.post(`${base}/move`, { paths, dest }), done)
    if (op.kind === 'compress') return act(`Created ${op.name || 'archive'}`, () => api.post(`${base}/compress`, { paths, dest: dir, format: op.format, name: op.name || null }), done)
    return act(`Extracted ${label}`, () => api.post(`${base}/extract`, { path: paths[0], dest, overwrite: op.overwrite }), done)
  }

  const chosen = () => (entries ?? []).filter((e) => selected.has(e.name))
  const opFor = (kind: 'copy' | 'move' | 'compress' | 'extract', names: string[]) =>
    setOp({ kind, names, dest: dir, name: names.length === 1 ? names[0].replace(ARCHIVE, '') : 'archive', format: 'zip', overwrite: false })

  function openPerms(items: Entry[]) {
    const first = items[0]
    setPerm({ items, mode: parseInt(first?.mode || '644', 8) & 0o777, recursive: false, uid: first?.uid != null && first.uid >= 0 ? String(first.uid) : '', gid: first?.gid != null && first.gid >= 0 ? String(first.gid) : '' })
  }

  function savePerms() {
    if (!perm) return
    const paths = perm.items.map((i) => join(dir, i.name))
    const label = perm.items.length === 1 ? perm.items[0].name : `${perm.items.length} items`
    const mode = perm.mode.toString(8).padStart(3, '0')
    const first = perm.items[0]
    const owner = canChown && (perm.uid !== String(first?.uid ?? '') || perm.gid !== String(first?.gid ?? ''))
    return act(`Updated permissions of ${label}`, async () => {
      const r = (await api.post(`${base}/chmod`, { paths, mode, recursive: perm.recursive })) as { data?: { job_id?: string } }
      if (owner) {
        if (r?.data?.job_id) await waitJob(r.data.job_id)
        return api.post(`${base}/chown`, { paths, recursive: perm.recursive, uid: perm.uid === '' ? null : Number(perm.uid), gid: perm.gid === '' ? null : Number(perm.gid) })
      }
      return r
    }, () => { setPerm(null); setSelected(new Set()) })
  }

  function toggle(name: string) {
    const next = new Set(selected)
    if (next.has(name)) next.delete(name); else next.add(name)
    setSelected(next)
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
  const shown = (entries ?? []).filter((e) => !filter || e.name.toLowerCase().includes(filter.toLowerCase()))
  const folders = shown.filter((e) => e.type === 'dir').length
  const used = volume?.size_gb != null ? `${Number(volume.size_gb).toFixed(1)}` : null

  return (
    <>
      <section className="card overflow-hidden">
        <div className="flex flex-wrap items-center gap-3 px-6 pt-5 pb-4">
          <IconSvg d={P.folderOpen} size={26} className="text-muted" />
          <h1 className="m-0 text-xl font-semibold flex-1 min-w-[160px]">File Manager</h1>
          <label className="sr-only" htmlFor="fm-volume">Volume</label>
          <select id="fm-volume" className="input h-11 max-w-[260px]" value={volumeId} onChange={(e) => setVolumeId(e.target.value)}>
            {usable.map((v) => <option key={v.id} value={v.id}>{v.name} · {serverName(v)}</option>)}
          </select>
          <ToolButton label="Upload files" d={P.upload} disabled={!entries || busy} onClick={() => fileInput.current?.click()} />
          <input ref={fileInput} type="file" multiple hidden onChange={(e) => { const f = Array.from(e.target.files ?? []); e.target.value = ''; upload(f.map((file) => ({ file, rel: file.name }))) }} />
          <input ref={folderInput} type="file" multiple hidden {...{ webkitdirectory: '' }} onChange={(e) => { const f = Array.from(e.target.files ?? []); e.target.value = ''; upload(f.map((file) => ({ file, rel: file.webkitRelativePath || file.name }))) }} />
          <ToolButton label="New folder" d={P.folderPlus} disabled={!entries || busy} onClick={() => setNaming({ kind: 'folder', value: '' })} />
          <div className="relative">
            <ToolButton label="More actions" d={P.dots} expanded={menu} disabled={!entries} onClick={() => setMenu(!menu)} />
            {menu && (
              <div role="menu" className="absolute right-0 top-12 z-20 card shadow-lg py-1 w-44">
                <button type="button" role="menuitem" className="w-full text-left px-3 h-10 bg-transparent border-0 text-sm cursor-pointer hover:bg-[var(--head)]" onClick={() => { setMenu(false); setNaming({ kind: 'file', value: '' }) }}>New file</button>
                <button type="button" role="menuitem" className="w-full text-left px-3 h-10 bg-transparent border-0 text-sm cursor-pointer hover:bg-[var(--head)]" onClick={() => { setMenu(false); load(dir) }}>Refresh</button>
                <button type="button" role="menuitem" className="w-full text-left px-3 h-10 bg-transparent border-0 text-sm cursor-pointer hover:bg-[var(--head)]" onClick={() => { setMenu(false); folderInput.current?.click() }}>Upload a folder</button>
              </div>
            )}
          </div>
        </div>

        <nav aria-label="Path" className="mx-6 flex flex-wrap items-center gap-1 rounded-xl bg-[var(--head)] px-4 py-3 text-[15px] text-muted">
          <IconSvg d={P.server} size={20} className="mr-2" />
          <span>{volume ? serverName(volume) : '—'}</span>
          <span aria-hidden="true">/</span>
          <button type="button" className="bg-transparent border-0 p-0 cursor-pointer text-muted hover:text-[var(--text)] font-[inherit]" onClick={() => load('')}>{volume?.name ?? ''}</button>
          {crumbs.map((c, i) => (
            <span key={i} className="flex items-center gap-1"><span aria-hidden="true">/</span>
              <button type="button" className="bg-transparent border-0 p-0 cursor-pointer text-muted hover:text-[var(--text)] font-[inherit]" onClick={() => load(crumbs.slice(0, i + 1).join('/'))}>{c}</button>
            </span>
          ))}
        </nav>

        <div className="flex flex-wrap gap-4 p-6">
          <nav aria-label="Folders" className="flex-[0_1_200px] min-w-[160px] flex flex-col gap-1">
            <SideLink d={P.drive} label="Root" active={view === 'files' && !dir} onClick={() => load('')} />
            {crumbs.map((c, i) => <SideLink key={i} d={i === crumbs.length - 1 ? P.folderOpen : P.folder} label={c} active={view === 'files' && i === crumbs.length - 1} onClick={() => load(crumbs.slice(0, i + 1).join('/'))} />)}
            <SideLink d={P.trash} label="Trash" active={view === 'trash'} disabled={!volumeId} onClick={loadTrash} />
          </nav>

          <div className={`relative flex-[999_1_480px] min-w-0 rounded-xl border overflow-hidden ${dragging ? 'border-[var(--accent)] border-dashed' : 'border-line'}`}
            onDragOver={(e) => { if (entries && !busy) { e.preventDefault(); setDragging(true) } }} onDragLeave={() => setDragging(false)} onDrop={onDrop}>
            {dragging && <div className="absolute inset-0 z-10 grid place-content-center bg-[var(--sel)]/80 text-[15px] font-semibold pointer-events-none">Drop files or folders to upload to {dir || volume?.name}</div>}
            {transfer && (
              <div role="status" className="px-4 py-3 border-b border-line text-sm">
                <div className="flex justify-between mb-2"><span>{transfer.label}</span><span className="text-muted">{transfer.pct !== null ? `${transfer.pct}%` : 'Working…'}</span></div>
                <div className="h-1.5 rounded bg-[var(--track)] overflow-hidden"><div className={`h-full bg-[var(--accent)] ${transfer.pct === null ? 'w-1/3 animate-pulse' : ''}`} style={transfer.pct !== null ? { width: `${transfer.pct}%` } : undefined} /></div>
              </div>
            )}
            {view === 'files' && <div className="flex items-center gap-3 px-4 py-2 border-b border-line">
              <label className="sr-only" htmlFor="fm-filter">Filter this folder</label>
              <input id="fm-filter" className="input h-9 flex-1 min-w-0" placeholder="Filter by name…" value={filter} onChange={(e) => setFilter(e.target.value)} />
            </div>}
            {view === 'trash' ? (
              error ? <div role="alert" className="p-6 text-sm text-danger">{error}</div>
              : loading && !trash ? <Empty>Loading…</Empty>
              : !trash?.length ? <Empty>Trash is empty. Deleted items stay here for 7 days.</Empty>
              : (
                <table className="w-full border-collapse text-[15px]">
                  <thead><tr className="bg-[var(--head)] text-left">
                    <th className="w-10 pl-4"><input type="checkbox" aria-label="Select all in Trash" checked={trash.every((t) => trashSel.has(t.id))} onChange={(e) => setTrashSel(e.target.checked ? new Set(trash.map((t) => t.id)) : new Set())} /></th>
                    <th className="px-4 py-3 font-semibold">Original location</th>
                    <th className="px-4 py-3 font-medium text-muted">Deleted</th>
                    <th className="px-4 py-3 font-medium text-muted text-right">Size</th>
                  </tr></thead>
                  <tbody>{trash.map((t) => (
                    <tr key={t.id} className={`group border-t border-line hover:bg-[var(--head)] ${trashSel.has(t.id) ? 'bg-[var(--sel)]' : ''}`}>
                      <td className="w-10 pl-4"><input type="checkbox" aria-label={`Select ${t.path}`} checked={trashSel.has(t.id)} onChange={() => { const n = new Set(trashSel); if (n.has(t.id)) n.delete(t.id); else n.add(t.id); setTrashSel(n) }} /></td>
                      <td className="px-4 py-2"><div className="flex items-center gap-3 min-h-9">
                        <IconSvg d={t.type === 'dir' ? P.folder : P.doc} size={20} className="text-muted shrink-0" /><span className="truncate font-mono text-sm">{t.path}</span>
                        <span className="ml-auto flex gap-1 opacity-0 group-hover:opacity-100 focus-within:opacity-100">
                          <button type="button" className="btn btn-sm" disabled={busy} onClick={() => trashAct(`Restored ${t.path}`, () => api.post(`${base}/trash/restore`, { ids: [t.id] }))}>Restore</button>
                        </span>
                      </div></td>
                      <td className="px-4 py-2 text-muted whitespace-nowrap text-sm">{new Date(t.deleted_at).toLocaleString()}</td>
                      <td className="px-4 py-2 text-right text-muted whitespace-nowrap">{size(t.size)}</td>
                    </tr>
                  ))}</tbody>
                </table>
              )
            ) : !volumeId && !volumes.isLoading ? <Empty>No volumes yet. Create one under Volumes &amp; Storage.</Empty>
              : error ? <div role="alert" className="p-6 text-sm text-danger">{error}</div>
              : loading && !entries ? <Empty>Loading…</Empty>
              : (
                <table className="w-full border-collapse text-[15px]">
                  <thead><tr className="bg-[var(--head)] text-left">
                    <th className="w-10 pl-4"><input type="checkbox" aria-label="Select all" checked={shown.length > 0 && shown.every((r) => selected.has(r.name))} onChange={(e) => setSelected(e.target.checked ? new Set(shown.map((r) => r.name)) : new Set())} /></th>
                    <th className="px-4 py-3 font-semibold">Name</th>
                    <th className="px-4 py-3 font-medium text-muted text-right w-32">Size</th>
                  </tr></thead>
                  <tbody>
                    {dir && (
                      <tr className="border-t border-line">
                        <td className="px-4 py-3" colSpan={3}><button type="button" className="flex items-center gap-3 bg-transparent border-0 p-0 cursor-pointer text-[var(--text)] font-[inherit]" onClick={() => load(parentOf(dir))}><IconSvg d={P.up} size={20} className="text-muted" />Up one folder</button></td>
                      </tr>
                    )}
                    {shown.map((r) => (
                      <tr key={r.name} className={`group border-t border-line hover:bg-[var(--head)] ${selected.has(r.name) ? 'bg-[var(--sel)]' : ''}`}>
                        <td className="w-10 pl-4"><input type="checkbox" aria-label={`Select ${r.name}`} checked={selected.has(r.name)} onChange={() => toggle(r.name)} /></td>
                        <td className="px-4 py-2 min-w-0"><div className="flex items-center gap-3 min-h-9">
                          <button type="button" className="flex items-center gap-3 bg-transparent border-0 p-0 cursor-pointer text-[var(--text)] font-[inherit] text-left disabled:cursor-default" disabled={busy || r.type === 'other' || r.type === 'symlink'} onClick={() => open(r)}>
                            <IconSvg d={iconFor(r)} size={20} className="text-muted shrink-0" />
                            <span className="truncate">{r.name}{r.type === 'symlink' ? ' (link)' : ''}</span>
                          </button>
                          <span className="ml-auto flex gap-1 opacity-0 group-hover:opacity-100 focus-within:opacity-100">
                            {r.type === 'file' && ARCHIVE.test(r.name) && <button type="button" className="btn btn-sm" disabled={busy} aria-label={`Extract ${r.name}`} onClick={() => opFor('extract', [r.name])}>Extract</button>}
                            {(r.type === 'file' || r.type === 'dir') && <button type="button" className="btn btn-sm" disabled={busy} aria-label={`Download ${r.name}`} onClick={() => download([r.name])}>Download</button>}
                            {(r.type === 'file' || r.type === 'dir') && <button type="button" className="btn btn-sm" disabled={busy} aria-label={`Permissions of ${r.name}`} onClick={() => openPerms([r])}>Permissions</button>}
                            <button type="button" className="btn btn-sm" disabled={busy} aria-label={`Rename ${r.name}`} onClick={() => setNaming({ kind: 'rename', target: r, value: r.name })}>Rename</button>
                            <button type="button" className="btn btn-sm btn-danger" disabled={busy} aria-label={`Delete ${r.name}`} onClick={() => { setPermanent(false); setDeleting([r]) }}>Delete</button>
                          </span>
                        </div></td>
                        <td className="px-4 py-2 text-right text-muted whitespace-nowrap">{r.type === 'dir' ? 'Folder' : size(r.size)}</td>
                      </tr>
                    ))}
                    {entries && shown.length === 0 && <tr><td colSpan={3}><Empty>{filter ? 'Nothing here matches that name.' : 'This folder is empty.'}</Empty></td></tr>}
                  </tbody>
                </table>
              )}
          </div>
        </div>

        <div className="flex flex-wrap justify-between gap-2 px-6 pb-5 text-[15px] text-muted">
          {view === 'trash' ? (
            <span className="flex flex-wrap items-center gap-3">{trash?.length ?? 0} items in Trash · kept 7 days
              {trashSel.size > 0 && <><span className="font-semibold text-[var(--text)]">{trashSel.size} selected</span>
                <button type="button" className="btn btn-sm" disabled={busy} onClick={() => trashAct(`Restored ${trashSel.size} items`, () => api.post(`${base}/trash/restore`, { ids: [...trashSel] }))}>Restore</button>
                <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => { if (window.confirm('Delete the selected items forever? This cannot be undone.')) trashAct(`Deleted ${trashSel.size} items forever`, () => request(`${base}/trash`, { method: 'DELETE', body: { ids: [...trashSel] } })) }}>Delete forever</button></>}
            </span>
          ) : (
          <span className="flex flex-wrap items-center gap-3">{shown.length} items · {folders} folders · {shown.length - folders} files{truncated ? ' (first 5,000 shown)' : ''}
            {selected.size > 0 && <><span className="font-semibold text-[var(--text)]">{selected.size} selected</span>
              <button type="button" className="btn btn-sm" disabled={busy} onClick={() => download([...selected])}>Download{selected.size > 1 ? ' as ZIP' : ''}</button>
              <button type="button" className="btn btn-sm" disabled={busy} onClick={() => opFor('copy', [...selected])}>Copy to…</button>
              <button type="button" className="btn btn-sm" disabled={busy} onClick={() => opFor('move', [...selected])}>Move to…</button>
              <button type="button" className="btn btn-sm" disabled={busy} onClick={() => opFor('compress', [...selected])}>Compress</button>
              <button type="button" className="btn btn-sm" disabled={busy} onClick={() => openPerms(chosen().filter((e) => e.type === 'file' || e.type === 'dir'))}>Permissions</button>
              <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => { setPermanent(false); setDeleting(chosen()) }}>Delete</button></>}
          </span>
          )}
          {used && <span>Storage: {used}{volume?.quota_gb ? ` / ${volume.quota_gb}` : ''} GB</span>}
        </div>
      </section>

      <ConfirmDialog open={!!overwrite} onClose={() => setOverwrite(null)} title="Replace existing items?" confirmLabel="Replace"
        onConfirm={() => { const p = overwrite!; setOverwrite(null); upload(p, true) }}>
        <p className="m-0 text-sm">Some of these names already exist in this folder. Replacing overwrites those files; nothing else is removed.</p>
      </ConfirmDialog>

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

      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} danger={permanent} busy={busy}
        title={deleting?.length === 1 ? `Delete ${deleting[0].name}?` : `Delete ${deleting?.length} items?`}
        confirmLabel={permanent ? 'Delete permanently' : 'Move to Trash'}
        confirmText={permanent && deleting?.some((d) => d.type === 'dir') ? 'DELETE' : undefined}
        onConfirm={() => deleting && remove(deleting)}>
        <p className="m-0 text-sm">{permanent ? 'This cannot be undone.' : 'Items stay in Trash for 7 days and can be restored to where they were.'}</p>
        <label className="flex items-center gap-2 text-sm mt-3"><input type="checkbox" checked={permanent} onChange={(e) => setPermanent(e.target.checked)} />Delete permanently instead</label>
        {permanent && deleting?.some((d) => d.type === 'dir') && <p className="text-sm mt-2 mb-0">This includes folders and everything in them. Type DELETE to confirm.</p>}
      </ConfirmDialog>

      <Modal open={!!perm} onClose={() => setPerm(null)} title={perm ? `Permissions · ${perm.items.length === 1 ? perm.items[0].name : `${perm.items.length} items`}` : ''}
        footer={<>
          <button type="button" className="btn" onClick={() => setPerm(null)}>Cancel</button>
          <button type="button" className="btn btn-primary" disabled={busy || !perm?.items.length || (canChown && [perm.uid, perm.gid].some((v) => v !== '' && !/^\d{1,10}$/.test(v)))} onClick={savePerms}>{busy ? 'Saving…' : 'Save'}</button>
        </>}>
        {perm && (
          <div className="flex flex-col gap-4">
            <table className="w-full border-collapse text-sm">
              <thead><tr className="text-left text-muted"><th className="py-2 font-medium"></th>{['Read', 'Write', 'Execute'].map((h) => <th key={h} className="py-2 font-medium text-center">{h}</th>)}</tr></thead>
              <tbody>{(['Owner', 'Group', 'Others'] as const).map((who, row) => (
                <tr key={who} className="border-t border-line">
                  <th scope="row" className="py-2 text-left font-semibold">{who}</th>
                  {[4, 2, 1].map((bit, col) => {
                    const mask = bit << ((2 - row) * 3)
                    return <td key={col} className="py-2 text-center"><input type="checkbox" aria-label={`${who} ${['read', 'write', 'execute'][col]}`} checked={(perm.mode & mask) !== 0}
                      onChange={(e) => setPerm({ ...perm, mode: e.target.checked ? perm.mode | mask : perm.mode & ~mask })} /></td>
                  })}
                </tr>
              ))}</tbody>
            </table>
            <label className="label">Numeric value
              <input className="input font-mono w-28" inputMode="numeric" value={perm.mode.toString(8).padStart(3, '0')}
                onChange={(e) => { const v = e.target.value.replace(/[^0-7]/g, '').slice(-3); if (v) setPerm({ ...perm, mode: parseInt(v, 8) }) }} />
            </label>
            {perm.items.some((i) => i.type === 'dir') && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={perm.recursive} onChange={(e) => setPerm({ ...perm, recursive: e.target.checked })} />Apply to everything inside the selected folders</label>}
            {canChown ? (
              <fieldset className="border border-line rounded-lg p-3 m-0">
                <legend className="px-1 text-sm font-semibold">Ownership (administrators only)</legend>
                <div className="flex flex-wrap gap-3">
                  <label className="label flex-1 min-w-[120px]">Owner user id<input className="input font-mono" inputMode="numeric" value={perm.uid} placeholder="unchanged" onChange={(e) => setPerm({ ...perm, uid: e.target.value.trim() })} /></label>
                  <label className="label flex-1 min-w-[120px]">Group id<input className="input font-mono" inputMode="numeric" value={perm.gid} placeholder="unchanged" onChange={(e) => setPerm({ ...perm, gid: e.target.value.trim() })} /></label>
                </div>
                <p className="mt-2 mb-0 text-xs text-muted">Most PHP and WordPress images run as user 33 (www-data). Leave a field empty to keep it as it is.</p>
              </fieldset>
            ) : perm.items.length === 1 && perm.items[0].uid != null && perm.items[0].uid >= 0 && (
              <p className="m-0 text-xs text-muted">Owned by user {perm.items[0].uid}, group {perm.items[0].gid}. Only platform administrators can change ownership.</p>
            )}
          </div>
        )}
      </Modal>

      <Modal open={!!op} onClose={() => setOp(null)}
        title={op ? ({ copy: 'Copy', move: 'Move', compress: 'Compress', extract: 'Extract' }[op.kind] + ' ' + (op.names.length === 1 ? op.names[0] : `${op.names.length} items`)) : ''}
        footer={<>
          <button type="button" className="btn" onClick={() => setOp(null)}>Cancel</button>
          <button type="button" className="btn btn-primary" disabled={busy} onClick={runOp}>{busy ? 'Working…' : op ? { copy: 'Copy', move: 'Move', compress: 'Create archive', extract: 'Extract' }[op.kind] : ''}</button>
        </>}>
        {op && op.kind === 'compress' ? (
          <div className="flex flex-col gap-3">
            <label className="label">Archive name<input className="input font-mono" value={op.name} onChange={(e) => setOp({ ...op, name: e.target.value })} /></label>
            <label className="label">Format
              <select className="input" value={op.format} onChange={(e) => setOp({ ...op, format: e.target.value })}>
                <option value="zip">ZIP</option><option value="tar.gz">TAR.GZ</option><option value="tar">TAR</option>
              </select>
            </label>
            <p className="m-0 text-xs text-muted">The archive is created in {dir || 'the top folder'}. Links are left out.</p>
          </div>
        ) : op && (
          <div className="flex flex-col gap-3">
            <label className="label">{op.kind === 'extract' ? 'Extract into folder' : 'Destination folder'}
              <input className="input font-mono" value={op.dest} placeholder="(top folder)" onChange={(e) => setOp({ ...op, dest: e.target.value })} />
            </label>
            <p className="m-0 text-xs text-muted">A path inside {volume?.name}, for example <span className="font-mono">wp-content/uploads</span>. Leave empty for the top folder.</p>
            {op.kind === 'extract' && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={op.overwrite} onChange={(e) => setOp({ ...op, overwrite: e.target.checked })} />Replace files that already exist</label>}
          </div>
        )}
      </Modal>

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
