import { useCallback, useEffect, useMemo, useRef, useState, type DragEvent } from 'react'
import { api, apiBase, errorMessage, request, tokenStore } from '../../api/client'
import { TERMINAL, useList, useServerName } from '../../api/hooks'
import type { Job } from '../../api/types'
import { Badge } from '../../components/Badge'
import { Empty, Meter, PageHeader, Field } from '../../components/Card'
import { FileEditor } from '../../components/FileEditor'
import { ConfirmDialog, Modal } from '../../components/Overlay'
import { useToast } from '../../components/Toasts'
import type { R } from '../../lib/util'
import { useAuth } from '../../lib/auth'
import Select from '../../components/Select'

/** Platform roles that may change file ownership (the API enforces the same rule). */
const OWNER_ROLES = ['super_admin', 'infra_admin', 'devops']

interface TrashItem { id: string; path: string; type: string; size: number; deleted_at: string; expires_at: string }
const ARCHIVE = /\.(zip|tar|tar\.gz|tgz)$/i
/** Shown inline from a signed link (the API serves only these inline). */
const MEDIA = /\.(png|jpe?g|gif|webp|avif|bmp|ico|pdf|mp4|webm|ogv|mov|mp3|ogg|oga|wav|m4a)$/i
/** Shown as text: code, config, logs, and HTML/SVG, which never render on the panel's origin. */
const TEXT = /\.(txt|md|log|csv|json|ya?ml|xml|ini|conf|env|htaccess|php|js|mjs|ts|tsx|jsx|css|scss|html?|svg|sql|sh|py|rb|go|vue|twig|blade\.php)$|^\.[a-z]+$/i
const previewKind = (name: string) => (MEDIA.test(name) ? 'media' : TEXT.test(name) ? 'text' : null)
interface Hit extends Entry { path: string }
interface Version { id: string; saved_at: string; reason: string; size: number }
interface Change { action: string; actor: string | null; at: string }
const REASON: Record<string, string> = { edit: 'Before an edit', upload: 'Before an upload replaced it', delete: 'Before it was deleted', restore: 'Before a restore' }
interface Finding { path: string; rule: string; severity: 'critical' | 'high' | 'medium' | 'info'; detail: string; type: string; size: number; mode: string; modified: string }
interface ScanResult { findings: Finding[]; counts: Record<string, number>; files: number; folders: number; truncated: boolean; scanned_at?: string; recent_days?: number }
const SEVERITY: Record<string, { label: string; tone: string }> = {
  critical: { label: 'Critical', tone: 'bg-[var(--r-bg)] text-[var(--r-fg)]' },
  high: { label: 'High', tone: 'bg-[var(--y-bg)] text-[var(--y-fg)]' },
  medium: { label: 'Medium', tone: 'bg-[var(--b-bg)] text-[var(--b-fg)]' },
  info: { label: 'Info', tone: 'bg-[var(--n-bg)] text-[var(--n-fg)]' },
}
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

type ShareRow = { id: string; paths: string[]; status: string; download_count: number; max_downloads: number | null; expires_at: string; password: boolean }
type TransferRow = { id: string; operation: string; status: string; progress: number; paths: string[]; error: string | null; retry_of: string | null; can_retry: boolean; can_cancel: boolean; created_at: string | null }
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
  shield: 'M12 3 4 6v6c0 4.5 3.4 8 8 9 4.6-1 8-4.5 8-9V6zM9 12l2 2 4-4',
  newFolder: 'M3 6a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1zM12 11v5M9.5 13.5h5',
  newFile: 'M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8zM14 3v5h5M12 12v6M9 15h6',
  pencil: 'M4 20h4L19 9l-4-4L4 16zM13.5 6.5l4 4',
  cursor: 'M4 7V4h3M17 4h3v3M20 17v3h-3M7 20H4v-3M9 12h6M12 9v6',
  copy: 'M8 8h11v12H8zM5 16V4h11',
  move: 'M5 12h14M13 6l6 6-6 6',
  zip: 'M4 4h16v16H4zM10 4v4h2v2h-2v2h2v2h-2',
  unzip: 'M4 4h16v16H4zM9 13l3 3 3-3M12 8v8',
  lock: 'M6 11h12v9H6zM8 11V8a4 4 0 0 1 8 0v3',
  download: 'M12 4v11M7 10l5 5 5-5M5 20h14',
}
const CODE = /\.(php|js|mjs|ts|tsx|jsx|css|scss|html?|json|ya?ml|py|rb|go|sh|sql|xml|vue)$/i
const iconFor = (e: Entry) => (e.type === 'dir' ? P.folder : e.type === 'symlink' ? P.link : CODE.test(e.name) ? P.code : P.doc)

const TONE: Record<string, string> = { y: 'bg-[var(--y-bg)] text-[var(--y-fg)]', g: 'bg-[var(--g-bg)] text-[var(--g-fg)]', b: 'bg-[var(--b-bg)] text-[var(--b-fg)]', n: 'bg-[var(--n-bg)] text-[var(--n-fg)]' }
const IMG = /\.(png|jpe?g|gif|webp|avif|svg|bmp|ico)$/i
/** Small extension tile, tinted by kind (folder, archive, image, code, other). */
function FileIcon({ e, big = false }: { e: Entry; big?: boolean }) {
  const ext = e.type === 'dir' ? 'dir' : e.type === 'symlink' ? 'link' : (e.name.includes('.') ? e.name.split('.').pop()! : 'file').slice(0, 4)
  const tone = e.type === 'dir' ? 'y' : ARCHIVE.test(e.name) ? 'n' : IMG.test(e.name) ? 'g' : CODE.test(e.name) ? 'b' : 'n'
  return <span aria-hidden="true" className={`inline-grid place-items-center shrink-0 uppercase font-bold tracking-wide ${TONE[tone]} ${big ? 'w-12 h-12 rounded-xl text-xs' : 'w-7 h-7 rounded-md text-[9.5px]'}`}>{ext}</span>
}
const permText = (e: Entry) => {
  const m = parseInt(e.mode || '0', 8) & 0o777
  return (e.type === 'dir' ? 'd' : '-') + [6, 3, 0].map((sh) => ((m >> sh) & 4 ? 'r' : '-') + ((m >> sh) & 2 ? 'w' : '-') + ((m >> sh) & 1 ? 'x' : '-')).join('')
}
const fmtDate = (d?: string) => { if (!d) return '—'; const t = new Date(d); return isNaN(t.getTime()) ? d : t.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: t.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' }) }

function IconSvg({ d, size = 20, className = '' }: { d: string; size?: number; className?: string }) {
  return <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.6} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className={className}><path d={d} /></svg>
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

/**
 * The admin page browses any volume; the customer page (customer) browses the
 * storage of the customer's own apps, without ownership changes or the security scan.
 */
export function FileManager({ customer = false }: { customer?: boolean }) {
  const toast = useToast()
  const serverName = useServerName()
  const volumes = useList<R>(customer ? '/app/files' : '/volumes')
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
  const [view, setView] = useState<'files' | 'trash' | 'search' | 'scan' | 'shares' | 'transfers'>('files')
  const [shares, setShares] = useState<ShareRow[] | null>(null)
  const [transfers, setTransfers] = useState<TransferRow[] | null>(null)
  const [sharing, setSharing] = useState<{ paths: string[]; hours: string; max: string; password: string; url?: string } | null>(null)
  const [scan, setScan] = useState<ScanResult | null>(null)
  const [hist, setHist] = useState<{ path: string; versions: Version[] | null; changes: Change[]; viewing?: { id: string; text?: string; binary?: boolean } } | null>(null)
  const [advanced, setAdvanced] = useState(false)
  const [query, setQuery] = useState({ name: '', extensions: '', min: '', max: '', after: '', before: '' })
  const [hits, setHits] = useState<{ items: Hit[]; truncated: boolean } | null>(null)
  const [preview, setPreview] = useState<{ name: string; path: string; url?: string; text?: string } | null>(null)
  const [trash, setTrash] = useState<TrashItem[] | null>(null)
  const [trashSel, setTrashSel] = useState<Set<string>>(new Set())
  const { me } = useAuth()
  const canChown = !customer && OWNER_ROLES.includes(String(me?.role ?? '').toLowerCase())
  const [perm, setPerm] = useState<{ items: Entry[]; mode: number; recursive: boolean; uid: string; gid: string } | null>(null)
  const [op, setOp] = useState<{ kind: 'copy' | 'move' | 'compress' | 'extract'; names: string[]; dest: string; name: string; format: string; overwrite: boolean; destId: string; separate: boolean } | null>(null)
  const [filter, setFilter] = useState('')
  const [menu, setMenu] = useState(false)
  const [layout, setLayout] = useState<'list' | 'grid'>('list')
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [transfer, setTransfer] = useState<{ label: string; pct: number | null } | null>(null)
  const [dragging, setDragging] = useState(false)
  const [overwrite, setOverwrite] = useState<Picked[] | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)
  const folderInput = useRef<HTMLInputElement>(null)

  const usable = useMemo(() => volumes.rows.filter((v) => (customer ? v.ready : v.type !== 'nfs')), [volumes.rows, customer])
  useEffect(() => { if (!volumeId && usable[0]) setVolumeId(usable[0].id) }, [usable, volumeId])
  const volume = usable.find((v) => v.id === volumeId)
  // Copy and move can go into another volume, as long as it is on the same server.
  const sameServer = usable.filter((v) => !volume?.server_id || v.server_id === volume.server_id)
  const base = customer ? `/app/apps/${volumeId}/files` : `/volumes/${volumeId}/files`

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

  async function loadShares() {
    setView('shares'); setLoading(true); setError(null)
    try { setShares((await api.get<{ data: ShareRow[] }>(`${base}/shares`)).data) } catch (e) { setError(errorMessage(e)) } finally { setLoading(false) }
  }

  async function loadTransfers() {
    setView('transfers'); setLoading(true); setError(null)
    try { setTransfers((await api.get<{ data: TransferRow[] }>(`${base}/transfers`)).data) } catch (e) { setError(errorMessage(e)) } finally { setLoading(false) }
  }

  async function createShare() {
    if (!sharing) return
    setBusy(true)
    try {
      const r = await api.post<{ data: { url: string } }>(`${base}/shares`, {
        paths: sharing.paths, expires_in_hours: Number(sharing.hours),
        max_downloads: sharing.max ? Number(sharing.max) : null, password: sharing.password || null,
      })
      setSharing({ ...sharing, url: r.data.url })
    } catch (e) { toast.error(e, 'Could not create the link') } finally { setBusy(false) }
  }

  async function transferAct(row: TransferRow, action: 'retry' | 'cancel') {
    setBusy(true)
    try {
      await api.post(`${base}/transfers/${row.id}/${action}`)
      toast.success(action === 'retry' ? `Retrying ${row.operation}` : `Cancelled ${row.operation}`)
      await loadTransfers()
    } catch (e) { toast.error(e, `Could not ${action}`) } finally { setBusy(false) }
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
    const other = op.destId && op.destId !== volumeId ? { dest_id: op.destId } : {}
    const into = op.destId && op.destId !== volumeId ? ` into ${usable.find((v) => v.id === op.destId)?.name ?? 'the other volume'}` : ''
    if (op.kind === 'copy') return act(`Copied ${label}${into}`, () => api.post(`${base}/copy`, { paths, dest, ...other }), done)
    if (op.kind === 'move') return act(`Moved ${label}${into}`, () => api.post(`${base}/move`, { paths, dest, ...other }), done)
    if (op.kind === 'compress') return act(`Created ${op.name || 'archive'}`, () => api.post(`${base}/compress`, { paths, dest: dir, format: op.format, name: op.name || null }), done)
    if (paths.length > 1) return act(`Extracted ${label}`, () => api.post(`${base}/extract`, { paths, dest, overwrite: op.overwrite, separate: op.separate }), done)
    return act(`Extracted ${label}`, () => api.post(`${base}/extract`, { path: paths[0], dest, overwrite: op.overwrite }), done)
  }

  const chosen = () => (entries ?? []).filter((e) => selected.has(e.name))
  const opFor = (kind: 'copy' | 'move' | 'compress' | 'extract', names: string[]) =>
    setOp({ kind, names, dest: dir, name: names.length === 1 ? names[0].replace(ARCHIVE, '') : 'archive', format: 'zip', overwrite: false, destId: volumeId, separate: names.length > 1 })

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

  async function runSearch() {
    const q: Record<string, string | number | undefined> = { path: dir }
    if (query.name.trim()) q.name = query.name.trim()
    const exts = query.extensions.split(/[\s,]+/).map((x) => x.replace(/^\./, '')).filter(Boolean)
    if (query.min) q.min_size = Math.round(Number(query.min) * 1048576)
    if (query.max) q.max_size = Math.round(Number(query.max) * 1048576)
    if (query.after) q.modified_after = query.after
    if (query.before) q.modified_before = query.before
    exts.forEach((x, i) => { q[`extensions[${i}]`] = x })
    setView('search'); setLoading(true); setError(null); setHits(null)
    try {
      const r = await fsCall<{ hits: Hit[]; truncated: boolean }>(() => api.get(`${base}/search`, q))
      setHits({ items: r.hits, truncated: r.truncated })
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }

  async function openHistory(path: string) {
    setHist({ path, versions: null, changes: [] })
    try {
      const res = (await api.get(`${base}/history`, { path })) as { data: { job_id: string; changes: Change[] } }
      setHist({ path, versions: null, changes: res.data.changes })
      const r = await waitJob<{ versions: Version[] }>(res.data.job_id)
      setHist((h) => h && h.path === path ? { ...h, versions: r.versions } : h)
    } catch (e) {
      toast.push({ kind: 'error', title: 'Could not load history', detail: errorMessage(e) })
      setHist(null)
    }
  }

  async function viewVersion(id: string) {
    if (!hist) return
    try {
      const r = await fsCall<{ content?: string; binary?: boolean }>(() => api.get(`${base}/history/version`, { path: hist.path, id }))
      setHist((h) => h && { ...h, viewing: { id, text: r.content, binary: r.binary } })
    } catch (e) {
      toast.push({ kind: 'error', title: 'Could not open that version', detail: errorMessage(e) })
    }
  }

  function restoreVersion(id: string) {
    if (!hist || !window.confirm(`Restore this version of ${hist.path}? The current file is saved in history first, so this can be undone.`)) return
    const path = hist.path
    return act(`Restored ${path}`, () => api.post(`${base}/history/restore`, { path, id }), () => openHistory(path))
  }

  async function runScan() {
    setView('scan'); setLoading(true); setError(null); setScan(null)
    try {
      setScan(await fsCall<ScanResult>(() => api.post(`${base}/scan`, { path: dir })))
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }

  async function fixFinding(f: Finding, how: 'trash' | 'perms') {
    setBusy(true)
    try {
      if (how === 'trash') await fsCall(() => api.post(`${base}/trash`, { paths: [f.path] }))
      else await fsCall(() => api.post(`${base}/chmod`, { paths: [f.path], mode: f.type === 'dir' ? '755' : f.rule === 'sensitive_readable' ? '640' : '644' }))
      toast.success(how === 'trash' ? `Moved ${f.path} to Trash` : `Fixed permissions of ${f.path}`)
      setScan((s) => s && { ...s, findings: s.findings.filter((x) => x.path !== f.path || (how === 'perms' && !['world_writable', 'sensitive_readable'].includes(x.rule))) })
    } catch (e) {
      toast.push({ kind: 'error', title: 'Fix failed', detail: errorMessage(e) })
    } finally {
      setBusy(false)
    }
  }

  async function openPreview(name: string, path: string) {
    const kind = previewKind(name)
    if (!kind) return
    setBusy(true)
    try {
      if (kind === 'media') {
        const r = await fsCall<{ download_url: string }>(() => api.post(`${base}/preview`, { path }))
        setPreview({ name, path, url: r.download_url })
      } else {
        const r = await fsCall<{ content: string }>(() => api.get(`${base}/content`, { path }))
        setPreview({ name, path, text: r.content })
      }
    } catch (e) {
      toast.push({ kind: 'error', title: `Could not preview ${name}`, detail: errorMessage(e) })
    } finally {
      setBusy(false)
    }
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

  const sel = chosen()
  const one = sel.length === 1 ? sel[0] : null
  const allArchives = sel.length > 0 && sel.every((e) => e.type === 'file' && ARCHIVE.test(e.name))
  const none = !sel.length || busy
  const sortedShown = [...shown].sort((a, b) => (a.type === 'dir') === (b.type === 'dir') ? 0 : a.type === 'dir' ? -1 : 1)
  const quotaPct = used && volume?.quota_gb ? Math.min(100, (Number(volume.size_gb) / Number(volume.quota_gb)) * 100) : null

  return (
    <>
      <PageHeader title="File manager" subtitle={customer ? 'Browse and edit the files your apps keep. Every change is audited.' : 'Browse and edit files in app containers and volumes. Every change is audited.'} />
      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center gap-2.5">
          <label className="sr-only" htmlFor="fm-volume">Location</label>
          <Select id="fm-volume" className="input !w-auto max-w-full sm:max-w-[280px] font-semibold" value={volumeId} onChange={(e) => setVolumeId(e.target.value)}>
            {usable.map((v) => <option key={v.id} value={v.id}>{customer ? v.name : `${serverName(v)} · ${v.name}`}</option>)}
          </Select>
          <nav aria-label="Breadcrumb" className="flex-[1_1_300px] min-w-0 flex flex-wrap items-center gap-1 min-h-[38px] px-2.5 rounded-lg border border-line bg-head font-mono text-[13px]">
            <button type="button" className="bg-transparent border-0 px-1 min-h-7 cursor-pointer font-[inherit] text-[var(--text)] hover:text-accent" onClick={() => load('')}>{volume?.name ?? '—'}{customer && volume?.volume_path ? ` (${volume.volume_path})` : ''}</button>
            {crumbs.map((c, i) => (
              <span key={i} className="flex items-center gap-1"><span aria-hidden="true" className="text-muted">/</span>
                <button type="button" aria-current={i === crumbs.length - 1 ? 'location' : undefined} className={`bg-transparent border-0 px-1 min-h-7 cursor-pointer font-[inherit] hover:text-accent ${i === crumbs.length - 1 ? 'text-[var(--text)] font-semibold' : 'text-muted'}`} onClick={() => load(crumbs.slice(0, i + 1).join('/'))}>{c}</button>
              </span>
            ))}
          </nav>
          {(view === 'files' || view === 'search') && <label className="flex-[1_1_200px] sm:flex-[0_1_240px] flex items-center gap-2 h-[38px] px-2.5 rounded-lg border border-line bg-card text-muted">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
            <span className="sr-only">Filter this folder</span>
            <input id="fm-filter" className="flex-1 min-w-0 bg-transparent border-0 outline-none text-[13px] text-[var(--text)] font-[inherit]" placeholder="Filter by name…" value={filter} onChange={(e) => setFilter(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter' && filter.trim()) { setQuery({ ...query, name: filter.trim() }); setAdvanced(true) } }} />
          </label>}
        </div>

        <div role="toolbar" aria-label="File actions" className="card flex flex-wrap items-center gap-0.5 p-1.5">
          <button type="button" className="btn btn-primary btn-sm mr-1" disabled={!entries || busy} onClick={() => fileInput.current?.click()}><IconSvg d={P.upload} size={15} />Upload</button>
          <input ref={fileInput} type="file" multiple hidden onChange={(e) => { const f = Array.from(e.target.files ?? []); e.target.value = ''; upload(f.map((file) => ({ file, rel: file.name }))) }} />
          <input ref={folderInput} type="file" multiple hidden {...{ webkitdirectory: '' }} onChange={(e) => { const f = Array.from(e.target.files ?? []); e.target.value = ''; upload(f.map((file) => ({ file, rel: file.webkitRelativePath || file.name }))) }} />
          <ToolBtn d={P.newFolder} label="New folder" disabled={!entries || busy} onClick={() => setNaming({ kind: 'folder', value: '' })} />
          <ToolBtn d={P.newFile} label="New file" disabled={!entries || busy} onClick={() => setNaming({ kind: 'file', value: '' })} />
          <Sep />
          <ToolBtn d={P.pencil} label="Edit" disabled={busy || one?.type !== 'file'} onClick={() => one && open(one)} />
          <ToolBtn d={P.cursor} label="Rename" disabled={busy || !one} onClick={() => one && setNaming({ kind: 'rename', target: one, value: one.name })} />
          <Sep />
          <ToolBtn d={P.copy} label="Copy to…" disabled={none} onClick={() => opFor('copy', [...selected])} />
          <ToolBtn d={P.move} label="Move to…" disabled={none} onClick={() => opFor('move', [...selected])} />
          <Sep />
          <ToolBtn d={P.zip} label="Compress" disabled={none} onClick={() => opFor('compress', [...selected])} />
          <ToolBtn d={P.unzip} label="Extract" disabled={busy || !allArchives} onClick={() => opFor('extract', [...selected])} />
          <ToolBtn d={P.lock} label="Permissions" disabled={none || !sel.some((e) => e.type === 'file' || e.type === 'dir')} onClick={() => openPerms(sel.filter((e) => e.type === 'file' || e.type === 'dir'))} />
          <Sep />
          <ToolBtn d={P.download} label={selected.size > 1 ? 'Download ZIP' : 'Download'} disabled={none} onClick={() => download([...selected])} />
          <ToolBtn d={P.link} label="Share" disabled={none} onClick={() => setSharing({ paths: [...selected].map((n) => join(dir, n)), hours: '72', max: '', password: '' })} />
          <ToolBtn d={P.trash} label="Delete" danger disabled={none} onClick={() => { setPermanent(false); setDeleting(sel) }} />
          {selected.size > 0 && <span className="text-xs text-muted px-2 tabular-nums" aria-live="polite">{selected.size} selected · <button type="button" className="bg-transparent border-0 p-0 text-xs font-semibold text-accent-fg cursor-pointer" onClick={() => setSelected(new Set())}>Clear</button></span>}
          <span className="ml-auto flex items-center gap-1.5">
            {(view === 'files' || view === 'search') && <button type="button" className="btn btn-sm btn-ghost" aria-expanded={advanced} onClick={() => { setAdvanced(!advanced); if (!query.name && filter) setQuery({ ...query, name: filter }) }}>Search all folders</button>}
            <span className="relative">
              <button type="button" className="btn btn-sm btn-ghost" aria-label="More actions" aria-expanded={menu} disabled={!entries} onClick={() => setMenu(!menu)}><IconSvg d={P.dots} size={18} /></button>
              {menu && (
                <div role="menu" className="absolute right-0 top-10 z-20 card shadow-lg py-1 w-44">
                  <button type="button" role="menuitem" className="w-full text-left px-3 h-10 bg-transparent border-0 text-sm cursor-pointer hover:bg-[var(--head)]" onClick={() => { setMenu(false); load(dir) }}>Refresh</button>
                  <button type="button" role="menuitem" className="w-full text-left px-3 h-10 bg-transparent border-0 text-sm cursor-pointer hover:bg-[var(--head)]" onClick={() => { setMenu(false); folderInput.current?.click() }}>Upload a folder</button>
                </div>
              )}
            </span>
            {view === 'files' && <span className="flex gap-1 p-[3px] rounded-lg bg-head" role="group" aria-label="View">
              {(['list', 'grid'] as const).map((v) => <button key={v} type="button" aria-pressed={layout === v} aria-label={`${v} view`} onClick={() => setLayout(v)}
                className={`min-h-8 px-2.5 rounded-md border-0 text-xs font-semibold capitalize cursor-pointer ${layout === v ? 'bg-card text-[var(--text)] shadow-sm' : 'bg-transparent text-muted'}`}>{v}</button>)}
            </span>}
          </span>
        </div>

        <div className="flex flex-wrap gap-3 items-stretch">
          <nav aria-label="Folders" className="card flex-[1_1_100%] md:flex-[0_1_230px] md:min-w-[200px] p-2 flex flex-col gap-0.5">
            <SideLink d={P.drive} label={volume?.name ?? 'Root'} active={view === 'files' && !dir} onClick={() => load('')} />
            {crumbs.map((c, i) => <SideLink key={i} d={i === crumbs.length - 1 ? P.folderOpen : P.folder} label={c} active={view === 'files' && i === crumbs.length - 1} onClick={() => load(crumbs.slice(0, i + 1).join('/'))} />)}
            {view === 'files' && sortedShown.filter((e) => e.type === 'dir').slice(0, 12).map((e) => <span key={e.name} className="pl-4"><SideLink d={P.folder} label={e.name} onClick={() => load(join(dir, e.name))} /></span>)}
            <div className="my-1 border-t border-line" />
            {!customer && <SideLink d={P.shield} label="Security scan" active={view === 'scan'} disabled={!volumeId} onClick={runScan} />}
            <SideLink d={P.up} label="Transfers" active={view === 'transfers'} disabled={!volumeId} onClick={loadTransfers} />
            <SideLink d={P.link} label="Shared links" active={view === 'shares'} disabled={!volumeId} onClick={loadShares} />
            <SideLink d={P.trash} label="Trash" active={view === 'trash'} disabled={!volumeId} onClick={loadTrash} />
            {used && <div className="mt-auto pt-3 px-2 pb-1 border-t border-line text-xs text-muted">
              <div>Disk {used} GB{volume?.quota_gb ? ` of ${volume.quota_gb} GB` : ''}</div>
              {quotaPct != null && <div className="mt-1.5"><Meter value={quotaPct} label="Volume usage" /></div>}
            </div>}
          </nav>

          <section aria-label="Files" className={`card relative flex-[999_1_420px] min-w-0 overflow-hidden flex flex-col ${dragging ? '!border-[var(--accent)] border-dashed' : ''}`}
            onDragOver={(e) => { if (entries && !busy) { e.preventDefault(); setDragging(true) } }} onDragLeave={() => setDragging(false)} onDrop={onDrop}>
            {dragging && <div className="absolute inset-0 z-10 grid place-content-center bg-[var(--sel)]/80 text-[15px] font-semibold pointer-events-none">Drop files or folders to upload to {dir || volume?.name}</div>}
            {transfer && (
              <div role="status" className="px-4 py-3 border-b border-line bg-head text-[13px]">
                <div className="flex justify-between mb-2"><span className="truncate">{transfer.label}</span><span className="text-muted">{transfer.pct !== null ? `${transfer.pct}%` : 'Working…'}</span></div>
                <div className="h-1.5 rounded bg-[var(--track)] overflow-hidden"><div className={`h-full bg-[var(--accent)] ${transfer.pct === null ? 'w-1/3 animate-pulse' : ''}`} style={transfer.pct !== null ? { width: `${transfer.pct}%` } : undefined} /></div>
                <div className="mt-2 text-xs text-muted">Drop files anywhere in this panel to upload</div>
              </div>
            )}
            {(view === 'files' || view === 'search') && advanced && (
              <form className="grid gap-3 p-4 border-b border-line bg-head [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]" onSubmit={(e) => { e.preventDefault(); runSearch() }}>
                <Field label="Name contains"><input className="input h-9" value={query.name} onChange={(e) => setQuery({ ...query, name: e.target.value })} /></Field>
                <Field label="Extensions"><input className="input h-9 font-mono" placeholder="php, js" value={query.extensions} onChange={(e) => setQuery({ ...query, extensions: e.target.value })} /></Field>
                <Field label="Min size (MB)"><input className="input h-9" inputMode="decimal" value={query.min} onChange={(e) => setQuery({ ...query, min: e.target.value })} /></Field>
                <Field label="Max size (MB)"><input className="input h-9" inputMode="decimal" value={query.max} onChange={(e) => setQuery({ ...query, max: e.target.value })} /></Field>
                <Field label="Modified after"><input type="date" className="input h-9" value={query.after} onChange={(e) => setQuery({ ...query, after: e.target.value })} /></Field>
                <Field label="Modified before"><input type="date" className="input h-9" value={query.before} onChange={(e) => setQuery({ ...query, before: e.target.value })} /></Field>
                <div className="flex items-end gap-2">
                  <button type="submit" className="btn btn-primary btn-sm" disabled={loading || !Object.values(query).some((v) => v.trim())}>Search</button>
                  {view === 'search' && <button type="button" className="btn btn-sm" onClick={() => load(dir)}>Clear</button>}
                </div>
              </form>
            )}
            <div className="flex-1 min-w-0 overflow-x-auto">
            {view === 'scan' ? (
              error ? <div role="alert" className="p-6 text-sm text-danger">{error}</div>
              : loading || !scan ? <Empty>Scanning {dir || 'the whole site'}. Large sites can take a minute…</Empty>
              : (
                <div>
                  <div className="grid gap-3 p-4 border-b border-line [grid-template-columns:repeat(auto-fit,minmax(130px,1fr))]">
                    {(['critical', 'high', 'medium', 'info'] as const).map((k) => (
                      <div key={k} className="rounded-lg border border-line p-3">
                        <div className="text-sm text-muted">{SEVERITY[k].label}</div>
                        <div className={`text-2xl font-bold ${scan.counts[k] && k !== 'info' ? (k === 'critical' ? 'text-[var(--r-fg)]' : k === 'high' ? 'text-[var(--y-fg)]' : '') : ''}`}>{scan.findings.filter((f) => f.severity === k).length}</div>
                      </div>
                    ))}
                  </div>
                  {!scan.findings.length ? <Empty>No problems found in {scan.files.toLocaleString()} files.</Empty> : (
                    <table className="tbl text-[15px]">
                      <thead><tr className="bg-[var(--head)] text-left"><th className="px-4 py-3 font-medium text-muted w-28">Severity</th><th className="px-4 py-3 font-semibold">Finding</th></tr></thead>
                      <tbody>{scan.findings.map((f, i) => (
                        <tr key={f.path + f.rule + i} className="group border-t border-line hover:bg-[var(--head)] align-top">
                          <td className="px-4 py-3"><span className={`inline-flex items-center h-6 px-2 rounded-full text-xs font-bold ${SEVERITY[f.severity].tone}`}>{SEVERITY[f.severity].label}</span></td>
                          <td className="px-4 py-2"><div className="flex flex-wrap items-start gap-3">
                            <div className="flex-1 min-w-[240px]">
                              <div className="font-mono text-sm break-all">{f.path}</div>
                              <div className="text-sm text-muted">{f.detail} · {f.mode} · changed {new Date(f.modified).toLocaleDateString()}</div>
                            </div>
                            <span className="flex flex-wrap gap-1 opacity-0 group-hover:opacity-100 focus-within:opacity-100">
                              {f.type === 'file' && previewKind(f.path) && <button type="button" className="btn btn-sm" disabled={busy} onClick={() => openPreview(f.path.split('/').pop()!, f.path)}>Inspect</button>}
                              <button type="button" className="btn btn-sm" onClick={() => load(f.type === 'dir' ? f.path : parentOf(f.path))}>Open folder</button>
                              {['world_writable', 'sensitive_readable'].includes(f.rule) && <button type="button" className="btn btn-sm" disabled={busy} onClick={() => fixFinding(f, 'perms')}>Fix permissions</button>}
                              {['malware_pattern', 'script_in_uploads', 'double_extension', 'hidden_script'].includes(f.rule) && <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => { if (window.confirm(`Move ${f.path} to Trash? You can restore it for 7 days.`)) fixFinding(f, 'trash') }}>Move to Trash</button>}
                            </span>
                          </div></td>
                        </tr>
                      ))}</tbody>
                    </table>
                  )}
                </div>
              )
            ) : view === 'search' ? (
              error ? <div role="alert" className="p-6 text-sm text-danger">{error}</div>
              : loading || !hits ? <Empty>Searching {dir || 'the whole site'}…</Empty>
              : !hits.items.length ? <Empty>Nothing matches. Searched {dir || 'the whole site'} and everything below it.</Empty>
              : (
                <table className="tbl text-[15px]">
                  <thead><tr className="bg-[var(--head)] text-left">
                    <th className="px-4 py-3 font-semibold">Found in {dir || volume?.name}</th>
                    <th className="px-4 py-3 font-medium text-muted">Modified</th>
                    <th className="px-4 py-3 font-medium text-muted text-right">Size</th>
                  </tr></thead>
                  <tbody>{hits.items.map((h) => (
                    <tr key={h.path} className="group border-t border-line hover:bg-[var(--head)]">
                      <td className="px-4 py-2"><div className="flex items-center gap-3 min-h-9">
                        <IconSvg d={iconFor(h)} size={20} className="text-muted shrink-0" />
                        <button type="button" className="bg-transparent border-0 p-0 cursor-pointer text-[var(--text)] font-mono text-sm text-left truncate" onClick={() => load(h.type === 'dir' ? h.path : parentOf(h.path))}>{h.path}</button>
                        <span className="ml-auto flex gap-1 opacity-0 group-hover:opacity-100 focus-within:opacity-100">
                          {h.type === 'file' && previewKind(h.name) && <button type="button" className="btn btn-sm" disabled={busy} onClick={() => openPreview(h.name, h.path)}>Preview</button>}
                          <button type="button" className="btn btn-sm" onClick={() => load(h.type === 'dir' ? h.path : parentOf(h.path))}>Open folder</button>
                        </span>
                      </div></td>
                      <td className="px-4 py-2 text-muted whitespace-nowrap text-sm">{new Date(h.modified).toLocaleDateString()}</td>
                      <td className="px-4 py-2 text-right text-muted whitespace-nowrap">{h.type === 'dir' ? 'Folder' : size(h.size)}</td>
                    </tr>
                  ))}</tbody>
                </table>
              )
            ) : view === 'shares' ? (
              error ? <div role="alert" className="p-6 text-sm text-danger">{error}</div>
              : loading && !shares ? <Empty>Loading…</Empty>
              : !shares?.length ? <Empty>No shared links yet. Select files and choose Share.</Empty>
              : (
                <table className="tbl text-[15px]">
                  <thead><tr className="text-left text-muted text-sm"><th className="px-4 py-2">Shared</th><th className="px-4 py-2">Status</th><th className="px-4 py-2">Downloads</th><th className="px-4 py-2">Expires</th><th className="px-4 py-2" /></tr></thead>
                  <tbody>{shares.map((sh) => (
                    <tr key={sh.id} className="border-t border-line">
                      <td className="px-4 py-2"><span className="font-mono text-xs break-all">{sh.paths.join(', ')}</span>{sh.password && <span className="ml-2 text-xs text-muted">password</span>}</td>
                      <td className="px-4 py-2"><Badge tone={sh.status === 'active' ? 'g' : sh.status === 'preparing' ? 'y' : 'n'}>{sh.status}</Badge></td>
                      <td className="px-4 py-2 text-sm">{sh.download_count}{sh.max_downloads ? ` / ${sh.max_downloads}` : ''}</td>
                      <td className="px-4 py-2 text-sm text-muted whitespace-nowrap">{new Date(sh.expires_at).toLocaleString()}</td>
                      <td className="px-4 py-2 text-right">{['active', 'preparing'].includes(sh.status) && <button type="button" className="btn btn-sm btn-danger" disabled={busy}
                        onClick={() => { if (window.confirm('Revoke this link? Anyone who has it will no longer be able to download.')) act('Link revoked', () => request(`${base}/shares/${sh.id}`, { method: 'DELETE' }), loadShares) }}>Revoke</button>}</td>
                    </tr>
                  ))}</tbody>
                </table>
              )
            ) : view === 'transfers' ? (
              error ? <div role="alert" className="p-6 text-sm text-danger">{error}</div>
              : loading && !transfers ? <Empty>Loading…</Empty>
              : !transfers?.length ? <Empty>No transfers yet. Uploads, downloads, copies, moves and archives appear here.</Empty>
              : (
                <table className="tbl text-[15px]">
                  <thead><tr className="text-left text-muted text-sm"><th className="px-4 py-2">Transfer</th><th className="px-4 py-2">Status</th><th className="px-4 py-2">Started</th><th className="px-4 py-2" /></tr></thead>
                  <tbody>{transfers.map((t) => (
                    <tr key={t.id} className="border-t border-line">
                      <td className="px-4 py-2"><span className="capitalize">{t.operation}</span> <span className="font-mono text-xs break-all text-muted">{t.paths.join(', ')}</span>
                        {t.error && <div className="text-xs text-danger">{t.error}</div>}{t.retry_of && <div className="text-xs text-muted">Retry of an earlier attempt</div>}</td>
                      <td className="px-4 py-2"><Badge tone={t.status === 'succeeded' ? 'g' : t.status === 'failed' ? 'r' : 'y'}>{t.status === 'running' && t.progress ? `running ${t.progress}%` : t.status}</Badge></td>
                      <td className="px-4 py-2 text-sm text-muted whitespace-nowrap">{t.created_at ? new Date(t.created_at).toLocaleString() : ''}</td>
                      <td className="px-4 py-2 text-right whitespace-nowrap">
                        {t.can_retry && <button type="button" className="btn btn-sm" disabled={busy} onClick={() => transferAct(t, 'retry')}>Retry</button>}
                        {t.can_cancel && <button type="button" className="btn btn-sm" disabled={busy} onClick={() => transferAct(t, 'cancel')}>Cancel</button>}
                      </td>
                    </tr>
                  ))}</tbody>
                </table>
              )
            ) : view === 'trash' ? (
              error ? <div role="alert" className="p-6 text-sm text-danger">{error}</div>
              : loading && !trash ? <Empty>Loading…</Empty>
              : !trash?.length ? <Empty>Trash is empty. Deleted items stay here for 7 days.</Empty>
              : (
                <table className="tbl text-[15px]">
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
            ) : !volumeId && !volumes.isLoading ? <Empty>{customer ? 'None of your apps keeps files yet. Turn on persistent storage when you host an app.' : <>No volumes yet. Create one under Volumes &amp; Storage.</>}</Empty>
              : error ? <div role="alert" className="p-6 text-sm text-danger">{error}</div>
              : loading && !entries ? <Empty>Loading…</Empty>
              : layout === 'grid' ? (
                <div className="p-3.5 grid gap-2.5 [grid-template-columns:repeat(auto-fill,minmax(120px,1fr))]">
                  {dir && <button type="button" className="flex flex-col items-center gap-1.5 p-3 rounded-lg border border-line bg-transparent cursor-pointer text-accent font-semibold text-[13px] font-[inherit]" onClick={() => load(parentOf(dir))}><IconSvg d={P.up} size={28} />Parent folder</button>}
                  {sortedShown.map((r) => (
                    <button key={r.name} type="button" aria-pressed={selected.has(r.name)} onClick={() => toggle(r.name)} onDoubleClick={() => { if (!busy && (r.type === 'dir' || r.type === 'file')) open(r) }}
                      className={`flex flex-col items-center gap-1.5 p-3 rounded-lg border cursor-pointer font-[inherit] text-[var(--text)] min-w-0 ${selected.has(r.name) ? 'border-accent bg-[var(--sel)]' : 'border-line bg-transparent hover:bg-[var(--head)]'}`}>
                      <FileIcon e={r} big />
                      <span className="text-[12.5px] font-medium max-w-full truncate">{r.name}</span>
                      <span className="text-[11.5px] text-muted">{r.type === 'dir' ? 'Folder' : size(r.size)}</span>
                    </button>
                  ))}
                  {entries && shown.length === 0 && <div className="col-span-full"><Empty>{filter ? 'Nothing here matches that name.' : 'This folder is empty.'}</Empty></div>}
                </div>
              ) : (
                <table className="tbl text-[13px] min-w-[640px]">
                  <thead><tr className="bg-head text-left text-xs text-muted">
                    <th className="w-10 pl-3 py-2.5"><input type="checkbox" aria-label="Select all" checked={shown.length > 0 && shown.every((r) => selected.has(r.name))} onChange={(e) => setSelected(e.target.checked ? new Set(shown.map((r) => r.name)) : new Set())} /></th>
                    <th className="px-3 py-2.5 font-semibold">Name</th>
                    <th className="px-3 py-2.5 font-semibold">Size</th>
                    <th className="px-3 py-2.5 font-semibold">Modified</th>
                    <th className="px-3 py-2.5 font-semibold">Permissions</th>
                    <th className="px-3 py-2.5 font-semibold">Owner</th>
                  </tr></thead>
                  <tbody>
                    {dir && (
                      <tr className="border-t border-line">
                        <td />
                        <td className="px-3 py-2.5" colSpan={5}><button type="button" className="bg-transparent border-0 p-0 min-h-7 cursor-pointer text-accent font-semibold text-[13px] font-[inherit]" onClick={() => load(parentOf(dir))}>↑ Parent folder</button></td>
                      </tr>
                    )}
                    {sortedShown.map((r) => (
                      <tr key={r.name} className={`group border-t border-line hover:bg-[var(--head)] ${selected.has(r.name) ? 'bg-[var(--sel)]' : ''}`}>
                        <td className="w-10 pl-3 py-2"><input type="checkbox" aria-label={`Select ${r.name}`} checked={selected.has(r.name)} onChange={() => toggle(r.name)} /></td>
                        <td className="px-3 py-2 min-w-0"><div className="flex items-center gap-2.5 min-h-[30px]">
                          <button type="button" className="flex items-center gap-2.5 bg-transparent border-0 p-0 cursor-pointer text-[var(--text)] font-medium text-[13.5px] font-[inherit] text-left min-w-0 disabled:cursor-default" disabled={busy || r.type === 'other' || r.type === 'symlink'} onClick={() => open(r)}>
                            <FileIcon e={r} />
                            <span className="truncate">{r.name}{r.type === 'symlink' ? ' (link)' : ''}</span>
                          </button>
                          <span className="ml-auto flex gap-1 opacity-0 group-hover:opacity-100 focus-within:opacity-100">
                            {r.type === 'file' && previewKind(r.name) && <button type="button" className="btn btn-sm" disabled={busy} aria-label={`Preview ${r.name}`} onClick={() => openPreview(r.name, join(dir, r.name))}>Preview</button>}
                            {r.type === 'file' && <button type="button" className="btn btn-sm" disabled={busy} aria-label={`History of ${r.name}`} onClick={() => openHistory(join(dir, r.name))}>History</button>}
                          </span>
                        </div></td>
                        <td className="px-3 py-2 text-muted tabular-nums whitespace-nowrap">{r.type === 'dir' ? '—' : size(r.size)}</td>
                        <td className="px-3 py-2 text-muted whitespace-nowrap">{fmtDate(r.modified)}</td>
                        <td className="px-3 py-2 text-muted font-mono text-xs whitespace-nowrap">{permText(r)}</td>
                        <td className="px-3 py-2 text-muted whitespace-nowrap">{r.uid != null && r.uid >= 0 ? `${r.uid}:${r.gid ?? ''}` : '—'}</td>
                      </tr>
                    ))}
                    {entries && shown.length === 0 && <tr><td colSpan={6}><Empty>{filter ? 'Nothing here matches that name.' : 'This folder is empty.'}</Empty></td></tr>}
                  </tbody>
                </table>
              )}
            </div>

            <div className="mt-auto flex flex-wrap items-center gap-x-4 gap-y-2 px-3.5 py-2.5 border-t border-line bg-head text-[12.5px] text-muted">
              {view === 'scan' ? (
                <span className="flex flex-wrap items-center gap-3">{scan ? `Scanned ${scan.files.toLocaleString()} files in ${scan.folders.toLocaleString()} folders${scan.truncated ? ' (stopped early on a very large site)' : ''}` : ''}
                  {scan && <button type="button" className="btn btn-sm" disabled={loading} onClick={runScan}>Scan again</button>}</span>
              ) : view === 'search' ? (
                <span>{hits ? `${hits.items.length} found${hits.truncated ? ' (showing the first 500; narrow the search for more)' : ''}` : ''}</span>
              ) : view === 'shares' ? (
                <span>Links share a copy taken when they are made; later edits do not change what they serve.</span>
              ) : view === 'transfers' ? (
                <span className="flex flex-wrap items-center gap-3">Failed transfers can be retried; queued ones can be cancelled until the server starts them.
                  <button type="button" className="btn btn-sm" disabled={loading} onClick={loadTransfers}>Refresh</button></span>
              ) : view === 'trash' ? (
                <span className="flex flex-wrap items-center gap-3">{trash?.length ?? 0} items in Trash · kept 7 days
                  {trashSel.size > 0 && <><span className="font-semibold text-[var(--text)]">{trashSel.size} selected</span>
                    <button type="button" className="btn btn-sm" disabled={busy} onClick={() => trashAct(`Restored ${trashSel.size} items`, () => api.post(`${base}/trash/restore`, { ids: [...trashSel] }))}>Restore</button>
                    <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => { if (window.confirm('Delete the selected items forever? This cannot be undone.')) trashAct(`Deleted ${trashSel.size} items forever`, () => request(`${base}/trash`, { method: 'DELETE', body: { ids: [...trashSel] } })) }}>Delete forever</button></>}
                </span>
              ) : <>
                <span>{shown.length} items · {folders} folders · {shown.length - folders} files{truncated ? ' (first 5,000 shown)' : ''}</span>
                {selected.size > 0 && <span className="font-semibold text-[var(--text)]">{selected.size} selected</span>}
              </>}
            </div>
          </section>

          {view === 'files' && one && (
            <aside aria-label="Details" className="card flex-[1_1_100%] lg:flex-[0_1_260px] lg:min-w-[220px] p-4 flex flex-col gap-3.5">
              <div className="flex items-center gap-3 min-w-0"><FileIcon e={one} big /><div className="min-w-0"><div className="font-semibold text-sm truncate">{one.name}</div><div className="text-xs text-muted">{one.type === 'dir' ? 'Folder' : one.type === 'symlink' ? 'Link' : ARCHIVE.test(one.name) ? 'Archive' : 'File'}</div></div></div>
              <dl className="m-0 text-[13px]">
                {([
                  ['Size', one.type === 'dir' ? '—' : size(one.size), false],
                  ['Modified', one.modified ? new Date(one.modified).toLocaleString() : '—', false],
                  ['Permissions', permText(one), true],
                  ['Owner', one.uid != null && one.uid >= 0 ? `uid ${one.uid} · gid ${one.gid ?? '—'}` : '—', false],
                  ['Path', `/${join(dir, one.name)}`, true],
                ] as const).map(([k, v, mono]) => (
                  <div key={k} className="flex justify-between gap-2.5 py-2 border-t border-line"><dt className="text-muted">{k}</dt><dd className={`m-0 font-medium text-right break-all ${mono ? 'font-mono text-xs' : ''}`}>{v}</dd></div>
                ))}
              </dl>
              <div className="flex flex-wrap gap-1.5">
                {one.type === 'file' && previewKind(one.name) && <button type="button" className="btn btn-sm" disabled={busy} onClick={() => openPreview(one.name, join(dir, one.name))}>Preview</button>}
                {one.type === 'file' && <button type="button" className="btn btn-sm" disabled={busy} onClick={() => openHistory(join(dir, one.name))}>History</button>}
              </div>
              <p className="m-0 text-xs text-muted">Click a file name to edit it. Archives (.zip, .tar.gz) can be extracted in place.</p>
            </aside>
          )}
        </div>
      </div>

      <Modal open={!!sharing} onClose={() => setSharing(null)} title={sharing?.url ? 'Link ready' : `Share ${sharing?.paths.length === 1 ? sharing.paths[0] : `${sharing?.paths.length} items`}`}
        footer={sharing?.url
          ? <button type="button" className="btn btn-primary" onClick={() => { navigator.clipboard?.writeText(sharing.url!).catch(() => {}); toast.success('Link copied') }}>Copy link</button>
          : <button type="button" className="btn btn-primary" disabled={busy} onClick={createShare}>Create link</button>}>
        {sharing?.url ? <>
          <p className="m-0 mb-2 text-sm">Anyone with this link can download {sharing.paths.length > 1 ? 'a ZIP of the selection' : 'the file'}{sharing.password ? ' with the password' : ''}. It is shown only now.</p>
          <input className="input w-full font-mono text-xs" readOnly value={sharing.url} onFocus={(e) => e.target.select()} aria-label="Share link" />
          <p className="m-0 mt-2 text-xs text-muted">It works once the copy is ready, usually within a minute. See Shared links.</p>
        </> : sharing && <>
          <label className="label mb-3">Expires after
            <Select className="input" value={sharing.hours} onChange={(e) => setSharing({ ...sharing, hours: e.target.value })}>
              {[['1', '1 hour'], ['24', '1 day'], ['72', '3 days'], ['168', '7 days'], ['720', '30 days']].map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </Select>
          </label>
          <label className="label mb-3">Download limit (optional)<input className="input" inputMode="numeric" value={sharing.max} onChange={(e) => setSharing({ ...sharing, max: e.target.value.replace(/\D/g, '') })} placeholder="No limit" /></label>
          <label className="label mb-3">Password (optional, 6+ characters)<input className="input" type="password" autoComplete="new-password" value={sharing.password} onChange={(e) => setSharing({ ...sharing, password: e.target.value })} /></label>
          <p className="m-0 text-xs text-muted">The link shares a copy taken now. Later changes to the files are not included.</p>
        </>}
      </Modal>

      <ConfirmDialog open={!!overwrite} onClose={() => setOverwrite(null)} title="Replace existing items?" confirmLabel="Replace"
        onConfirm={() => { const p = overwrite!; setOverwrite(null); upload(p, true) }}>
        <p className="m-0 text-sm">Some of these names already exist in this folder. Replacing overwrites those files; nothing else is removed.</p>
      </ConfirmDialog>

      <Modal open={!!naming} onClose={() => setNaming(null)} title={naming?.kind === 'rename' ? `Rename ${naming.target?.name}` : naming?.kind === 'folder' ? 'New folder' : 'New file'}
        footer={<>
          <button type="button" className="btn" onClick={() => setNaming(null)}>Cancel</button>
          <button type="button" className="btn btn-primary" disabled={busy || !validName(naming?.value ?? '')} onClick={submitName}>{busy ? 'Working…' : naming?.kind === 'rename' ? 'Rename' : 'Create'}</button>
        </>}>
        <Field label="Name">
          <input className="input font-mono" autoFocus value={naming?.value ?? ''} onChange={(e) => naming && setNaming({ ...naming, value: e.target.value })}
            onKeyDown={(e) => { if (e.key === 'Enter') submitName() }} aria-invalid={!!naming?.value && !validName(naming.value)} />
        </Field>
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

      <Modal open={!!hist} width={860} onClose={() => setHist(null)} title={hist ? `History · ${hist.path}` : ''}
        footer={<button type="button" className="btn btn-primary" onClick={() => setHist(null)}>Close</button>}>
        {hist && (
          <div className="flex flex-col gap-4">
            <section>
              <h3 className="m-0 mb-2 text-sm font-semibold">Saved versions</h3>
              {hist.versions === null ? <p className="m-0 text-sm text-muted">Loading…</p>
                : !hist.versions.length ? <p className="m-0 text-sm text-muted">No earlier versions. A version is kept each time the file manager changes or deletes this file (last 20, for 30 days).</p>
                : (
                  <ul className="m-0 p-0 list-none flex flex-col">
                    {hist.versions.map((v) => (
                      <li key={v.id} className={`flex flex-wrap items-center gap-3 py-2 border-t border-line text-sm ${hist.viewing?.id === v.id ? 'bg-[var(--sel)]' : ''}`}>
                        <span className="flex-1 min-w-[200px]"><span className="font-semibold">{new Date(v.saved_at).toLocaleString()}</span> <span className="text-muted">· {REASON[v.reason] ?? v.reason} · {size(v.size)}</span></span>
                        <button type="button" className="btn btn-sm" onClick={() => viewVersion(v.id)}>View</button>
                        <button type="button" className="btn btn-sm" disabled={busy} onClick={() => restoreVersion(v.id)}>Restore</button>
                      </li>
                    ))}
                  </ul>
                )}
              {hist.viewing && (hist.viewing.binary
                ? <p className="mt-2 mb-0 text-sm text-muted">This version is not text; restore it to see it.</p>
                : <pre className="mt-2 mb-0 max-h-[40vh] overflow-auto rounded-lg bg-[var(--code-bg)] text-[var(--code-fg)] p-4 text-xs leading-relaxed whitespace-pre">{hist.viewing.text}</pre>)}
            </section>
            <section>
              <h3 className="m-0 mb-2 text-sm font-semibold">Changes made in the panel</h3>
              {!hist.changes.length ? <p className="m-0 text-sm text-muted">No changes recorded through the panel.</p> : (
                <ul className="m-0 p-0 list-none">
                  {hist.changes.map((c, i) => <li key={i} className="py-1.5 border-t border-line text-sm"><span className="font-semibold">{c.actor ?? 'Someone'}</span> <span className="text-muted">{c.action.replace(/_/g, ' ')} · {new Date(c.at).toLocaleString()}</span></li>)}
                </ul>
              )}
            </section>
          </div>
        )}
      </Modal>

      <Modal open={!!preview} width={960} onClose={() => setPreview(null)} title={preview ? `Preview · ${preview.path}` : ''}
        footer={<>
          {preview?.text !== undefined && <button type="button" className="btn mr-auto" onClick={() => { const p = preview; setPreview(null); setEditing({ path: p.path, content: p.text!, original: p.text! }) }}>Edit</button>}
          {preview?.url && <a className="btn mr-auto" href={preview.url} target="_blank" rel="noopener noreferrer">Open in new tab</a>}
          <button type="button" className="btn btn-primary" onClick={() => setPreview(null)}>Close</button>
        </>}>
        {preview?.text !== undefined ? (
          <pre className="m-0 max-h-[65vh] overflow-auto rounded-lg bg-[var(--code-bg)] text-[var(--code-fg)] p-4 text-xs leading-relaxed whitespace-pre">{preview.text}</pre>
        ) : preview?.url && (
          /\.pdf$/i.test(preview.name) ? <iframe title={preview.name} src={preview.url} className="w-full h-[65vh] rounded-lg border border-line bg-white" />
          : /\.(mp4|webm|ogv|mov)$/i.test(preview.name) ? <video src={preview.url} controls className="w-full max-h-[65vh] rounded-lg bg-black" />
          : /\.(mp3|ogg|oga|wav|m4a)$/i.test(preview.name) ? <audio src={preview.url} controls className="w-full" />
          : <div className="grid place-items-center max-h-[65vh] overflow-auto rounded-lg bg-[var(--head)] p-4"><img src={preview.url} alt={preview.name} className="max-w-full max-h-[60vh] object-contain" /></div>
        )}
      </Modal>

      <Modal open={!!perm} onClose={() => setPerm(null)} title={perm ? `Permissions · ${perm.items.length === 1 ? perm.items[0].name : `${perm.items.length} items`}` : ''}
        footer={<>
          <button type="button" className="btn" onClick={() => setPerm(null)}>Cancel</button>
          <button type="button" className="btn btn-primary" disabled={busy || !perm?.items.length || (canChown && [perm.uid, perm.gid].some((v) => v !== '' && !/^\d{1,10}$/.test(v)))} onClick={savePerms}>{busy ? 'Saving…' : 'Save'}</button>
        </>}>
        {perm && (
          <div className="flex flex-col gap-4">
            <table className="tbl">
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
            <Field label="Numeric value">
              <input className="input font-mono w-28" inputMode="numeric" value={perm.mode.toString(8).padStart(3, '0')}
                onChange={(e) => { const v = e.target.value.replace(/[^0-7]/g, '').slice(-3); if (v) setPerm({ ...perm, mode: parseInt(v, 8) }) }} />
            </Field>
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
            <Field label="Archive name"><input className="input font-mono" value={op.name} onChange={(e) => setOp({ ...op, name: e.target.value })} /></Field>
            <Field label="Format">
              <Select className="input" value={op.format} onChange={(e) => setOp({ ...op, format: e.target.value })}>
                <option value="zip">ZIP</option><option value="tar.gz">TAR.GZ</option><option value="tar">TAR</option>
              </Select>
            </Field>
            <p className="m-0 text-xs text-muted">The archive is created in {dir || 'the top folder'}. Links are left out.</p>
          </div>
        ) : op && (
          <div className="flex flex-col gap-3">
            {op.kind !== 'extract' && sameServer.length > 1 && (
              <Field label="Into">
                <Select className="input" value={op.destId} onChange={(e) => setOp({ ...op, destId: e.target.value, dest: e.target.value === volumeId ? dir : '' })}>
                  {sameServer.map((v) => <option key={v.id} value={v.id}>{v.name}{v.id === volumeId ? ' (this one)' : ''}</option>)}
                </Select>
              </Field>
            )}
            <label className="label">{op.kind === 'extract' ? 'Extract into folder' : 'Destination folder'}
              <input className="input font-mono" value={op.dest} placeholder="(top folder)" onChange={(e) => setOp({ ...op, dest: e.target.value })} />
            </label>
            <p className="m-0 text-xs text-muted">A path inside {usable.find((v) => v.id === op.destId)?.name ?? volume?.name}, for example <span className="font-mono">wp-content/uploads</span>. Leave empty for the top folder.</p>
            {op.kind === 'extract' && op.names.length > 1 && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={op.separate} onChange={(e) => setOp({ ...op, separate: e.target.checked })} />Put each archive in its own folder</label>}
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
        <FileEditor label={`Contents of ${editing?.path}`} path={editing?.path ?? ''} value={editing?.content ?? ''} onChange={(v) => editing && setEditing({ ...editing, content: v })} />
      </Modal>
    </>
  )
}


/** A toolbar action: icon + label, quiet until hovered; disabled ones fade. */
function ToolBtn({ d, label, onClick, disabled, danger }: { d: string; label: string; onClick: () => void; disabled?: boolean; danger?: boolean }) {
  return (
    <button type="button" disabled={disabled} onClick={onClick}
      className={`inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md border-0 bg-transparent text-[13px] font-medium cursor-pointer transition-colors disabled:opacity-40 disabled:cursor-not-allowed ${danger ? 'text-danger enabled:hover:bg-r-bg' : 'text-fg enabled:hover:bg-head'}`}>
      <IconSvg d={d} size={16} />{label}
    </button>
  )
}

function Sep() {
  return <span aria-hidden="true" className="w-px h-5 bg-line mx-1" />
}
