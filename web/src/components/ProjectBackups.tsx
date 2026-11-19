import Select from '../components/Select'
import { useEffect, useState } from 'react'
import { useAction, useOne } from '../api/hooks'
import { Card, Empty, Toggle } from './Card'
import { Badge, StatusBadge } from './Badge'
import { Modal } from './Overlay'
import { ago } from '../lib/format'
import { num, type R } from '../lib/util'

const fmtMb = (mb: number) => mb >= 1024 ? `${(mb / 1024).toFixed(1)} GB` : `${mb.toFixed(mb < 10 ? 1 : 0)} MB`
const when = (t?: string) => t ? new Date(t).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—'
const DEST: Record<string, string> = { local: 'On the server', s3: 'Amazon S3', b2: 'Backblaze B2', r2: 'Cloudflare R2', gdrive: 'Google Drive', sftp: 'SFTP server' }

/**
 * Project → Backups: automatic schedule, history, run now, details and restore. `base` is '' for the
 * admin API and '/app' for the customer portal; both expose the same endpoints.
 */
export function ProjectBackups({ projectId, base = '' }: { projectId: string; base?: string }) {
  const list = useOne<R>(`${base}/projects/${projectId}/backups`, undefined, { refetchInterval: 15000 })
  const { run, busy } = useAction()
  const [open, setOpen] = useState<string | null>(null)
  const [restoring, setRestoring] = useState<R | null>(null)
  const data = list.item ?? {}
  const sched = (data.schedule ?? {}) as R
  const backups = (data.backups ?? []) as R[]
  const [s, setS] = useState<R>({})
  useEffect(() => { setS(sched) }, [JSON.stringify(sched)]) // eslint-disable-line react-hooks/exhaustive-deps
  const saveSchedule = (patch: R) => run({ method: 'PATCH', path: `${base}/projects/${projectId}/backup-schedule`, body: { enabled: s.enabled ?? false, frequency: s.frequency ?? 'daily', retention_days: Number(s.retention_days) || 14, destination: s.destination ?? 'local', capacity_gb: s.capacity_gb ? Number(s.capacity_gb) : null, ...patch }, label: 'Save backup schedule', success: 'Backup schedule saved' }).then(() => list.refetch()).catch(() => {})

  return (
    <div className="flex flex-col gap-3">
      <Card>
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex-1 min-w-[220px]">
            <div className="font-bold">Automatic backup</div>
            <div className="text-xs text-muted">Every application’s volumes and database dumps, plus its configuration. Encrypted. {sched.enabled && sched.next_run_at ? `Next run ${when(String(sched.next_run_at))}.` : ''}</div>
          </div>
          <Toggle checked={!!s.enabled} label="Automatic backup" onChange={(v) => { setS({ ...s, enabled: v }); saveSchedule({ enabled: v }) }} />
        </div>
        <div className="grid gap-3 mt-3" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))' }}>
          <label className="text-sm"><span className="block text-muted text-xs mb-1">Frequency</span>
            <Select className="input" value={s.frequency ?? 'daily'} onChange={(e) => setS({ ...s, frequency: e.target.value })}><option value="hourly">Hourly</option><option value="daily">Daily (02:00 Sri Lanka)</option><option value="weekly">Weekly (Sunday 02:00)</option></Select></label>
          <label className="text-sm"><span className="block text-muted text-xs mb-1">Keep for (days)</span>
            <input className="input" type="number" min={1} max={3650} value={s.retention_days ?? 14} onChange={(e) => setS({ ...s, retention_days: e.target.value })} /></label>
          <label className="text-sm"><span className="block text-muted text-xs mb-1">Destination</span>
            <Select className="input" value={s.destination ?? 'local'} onChange={(e) => setS({ ...s, destination: e.target.value })}>{Object.entries(DEST).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select></label>
          <label className="text-sm"><span className="block text-muted text-xs mb-1">Storage limit (GB)</span>
            <input className="input" type="number" min={1} placeholder="no limit" value={s.capacity_gb ?? ''} onChange={(e) => setS({ ...s, capacity_gb: e.target.value })} /></label>
          <div className="flex items-end"><button type="button" className="btn" disabled={busy} onClick={() => saveSchedule({})}>Save settings</button></div>
        </div>
      </Card>

      <Card pad={false}>
        <div className="flex flex-wrap items-center gap-3 px-4 sm:px-5 py-3">
          <div className="font-bold flex-1">Backup history</div>
          <button type="button" className="btn btn-primary" disabled={busy} onClick={() => run({ path: `${base}/projects/${projectId}/backups`, label: 'Back up project', success: 'Project backup started' }).then(() => list.refetch()).catch(() => {})}>Create backup now</button>
        </div>
        {list.isLoading ? <p className="px-5 pb-4 m-0 text-sm text-muted">Loading…</p> : !backups.length ? <div className="px-5 pb-5"><Empty>No project backups yet.</Empty></div> :
          <ul className="list-none m-0 p-0">{backups.map((b) => (
            <li key={b.id} className="flex flex-wrap items-center gap-3 px-4 sm:px-5 py-3 border-t border-line text-[13.5px]">
              <button type="button" className="flex-[1_1_220px] min-w-0 text-left bg-transparent border-0 p-0 cursor-pointer" onClick={() => setOpen(b.id)}>
                <span className="block font-semibold">{b.type === 'scheduled' ? 'Scheduled backup' : b.type === 'pre_restore' ? 'Recovery point' : 'Manual backup'}</span>
                <span className="block text-xs text-muted truncate">{when(b.created_at)} · {((b.applications ?? []) as string[]).join(', ') || '—'}{b.error ? ` · ${b.error}` : ''}</span>
              </button>
              <span className="tabular-nums min-w-[70px]">{num(b.size_mb) ? fmtMb(num(b.size_mb)) : '—'}</span>
              <StatusBadge status={b.status} />
              <button type="button" className="btn btn-sm" disabled={!['succeeded', 'partial'].includes(String(b.status))} onClick={() => setRestoring(b)}>Restore</button>
            </li>))}</ul>}
      </Card>

      {open && <BackupDetails id={open} base={base} onClose={() => setOpen(null)} onRestore={(b) => { setOpen(null); setRestoring(b) }} />}
      {restoring && <RestoreModal backup={restoring} base={base} onClose={() => { setRestoring(null); list.refetch() }} />}
    </div>
  )
}

function BackupDetails({ id, base, onClose, onRestore }: { id: string; base: string; onClose: () => void; onRestore: (b: R) => void }) {
  const d = useOne<R>(`${base}/project-backups/${id}`, undefined, { refetchInterval: 10000 })
  const b = d.item ?? {}
  const apps = (b.applications ?? []) as R[]
  const restores = (b.restores ?? []) as R[]
  return (
    <Modal open onClose={onClose} title="Backup details" width={760}>
      {d.isLoading ? <p className="m-0 text-sm text-muted">Loading…</p> : (
        <div className="flex flex-col gap-4 text-sm">
          <dl className="m-0 grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
            {[['Backup ID', <span key="i" className="font-mono text-xs">{String(b.id)}</span>], ['Created', when(b.created_at)], ['Type', String(b.type ?? '—')], ['Size', fmtMb(num(b.size_mb))],
              ['Status', <StatusBadge key="s" status={b.status} />], ['Destination', DEST[String(b.destination)] ?? String(b.destination ?? '—')],
              ['Verification', b.verified ? <Badge key="v" tone="g">verified</Badge> : <Badge key="v" tone="y">not verified</Badge>], ['Project', String(b.project ?? '—')]].map(([k, v]) => (
              <div key={String(k)} className="flex justify-between gap-3 border-b border-line py-1.5"><dt className="text-muted">{k}</dt><dd className="m-0 text-right">{v}</dd></div>))}
          </dl>
          {b.error ? <p className="m-0 text-[var(--r-fg)]">{String(b.error)}</p> : null}
          <div>
            <div className="font-semibold mb-1.5">Included applications</div>
            <ul className="list-none m-0 p-0 flex flex-col gap-1">{apps.map((a) => (
              <li key={a.id} className="flex flex-wrap items-center gap-2"><span className="font-medium flex-1">{a.name}</span>
                {((a.artifacts ?? []) as R[]).map((x) => <Badge key={x.id} tone={x.verified ? 'g' : 'y'}>{x.kind} · {fmtMb(num(x.size_mb))}</Badge>)}
                <StatusBadge status={a.status} /></li>))}</ul>
          </div>
          <div>
            <div className="font-semibold mb-1.5">Restore history</div>
            {restores.length ? <ul className="list-none m-0 p-0 flex flex-col gap-1">{restores.map((r) => (
              <li key={r.id} className="flex flex-wrap gap-2"><span className="flex-1">{r.application ?? '—'} · {r.mode === 'side_by_side' ? 'separate location' : 'replaced'} · {ago(r.created_at)}</span><StatusBadge status={r.status} /></li>))}</ul>
              : <p className="m-0 text-muted">Never restored.</p>}
          </div>
          <details><summary className="cursor-pointer text-muted">Configuration snapshot</summary>
            <pre className="m-0 mt-2 p-3 rounded-lg bg-sel text-xs font-mono overflow-auto max-h-[260px]">{JSON.stringify(b.configuration ?? {}, null, 2)}</pre></details>
          <div className="flex justify-end gap-2"><button type="button" className="btn" onClick={onClose}>Close</button>
            <button type="button" className="btn btn-primary" disabled={!['succeeded', 'partial'].includes(String(b.status))} onClick={() => onRestore(b)}>Restore…</button></div>
        </div>)}
    </Modal>
  )
}

function RestoreModal({ backup, base, onClose }: { backup: R; base: string; onClose: () => void }) {
  const d = useOne<R>(`${base}/project-backups/${backup.id}`)
  const apps = ((d.item?.applications ?? []) as R[]).filter((a) => a.status === 'succeeded')
  const { run, busy } = useAction()
  const [scope, setScope] = useState('project')
  const [mode, setMode] = useState('replace')
  const [picked, setPicked] = useState<string[]>([])
  const [ack, setAck] = useState(false)
  const [result, setResult] = useState<R | null>(null)
  const go = () => run<{ data: R }>({ path: `${base}/project-backups/${backup.id}/restore`, label: 'Restore project backup', success: 'Restore started',
    body: { confirm: true, scope, mode, ...(scope === 'applications' ? { application_ids: picked } : {}) } }).then((r) => setResult((r as R)?.data ?? r)).catch(() => {})

  return (
    <Modal open onClose={onClose} title="Restore project backup" width={620}>
      {result ? (
        <div className="flex flex-col gap-3 text-sm">
          <p className="m-0 font-semibold">Restore started for {((result.restored ?? []) as R[]).length} application(s).</p>
          <ul className="m-0 pl-5">{((result.restored ?? []) as R[]).map((r) => <li key={r.restore_job_id}>{r.application}: a recovery point is taken first, then the restore runs. Progress shows in the Activity dock.</li>)}</ul>
          {((result.skipped ?? []) as string[]).length ? <><p className="m-0 font-semibold">Left out</p><ul className="m-0 pl-5">{((result.skipped ?? []) as string[]).map((s) => <li key={s}>{s}</li>)}</ul></> : null}
          <div className="flex justify-end"><button type="button" className="btn btn-primary" onClick={onClose}>Done</button></div>
        </div>
      ) : (
        <div className="flex flex-col gap-4 text-sm">
          <p className="m-0">Backup from {when(backup.created_at)}.</p>
          <fieldset className="flex flex-col gap-2 border-0 p-0 m-0"><legend className="font-semibold mb-2">What to restore</legend>
            {[['project', 'The complete project'], ['applications', 'Selected applications'], ['database', 'Databases only'], ['volumes', 'Volumes (files) only']].map(([v, l]) =>
              <label key={v} className="choice !items-center"><input type="radio" name="scope" checked={scope === v} onChange={() => setScope(v)} />{l}</label>)}
          </fieldset>
          {scope === 'applications' && <div className="flex flex-col gap-1.5 pl-6">{apps.map((a) => (
            <label key={a.id} className="flex gap-2 items-center"><input type="checkbox" checked={picked.includes(a.id)} onChange={(e) => setPicked(e.target.checked ? [...picked, a.id] : picked.filter((x) => x !== a.id))} />{a.name}</label>))}</div>}
          <fieldset className="flex flex-col gap-2 border-0 p-0 m-0"><legend className="font-semibold mb-2">Where</legend>
            <label className="choice !items-center"><input type="radio" name="mode" checked={mode === 'replace'} onChange={() => setMode('replace')} />Replace the live data</label>
            <label className="choice !items-center"><input type="radio" name="mode" checked={mode === 'separate'} onChange={() => setMode('separate')} />Into a separate location beside it (files only; live data untouched)</label>
          </fieldset>
          <p className="m-0 text-xs text-muted">A recovery point of each application is always taken first, so this restore can be undone.</p>
          <label className="flex gap-2 items-center"><input type="checkbox" checked={ack} onChange={(e) => setAck(e.target.checked)} />I understand {mode === 'replace' ? 'the live data will be replaced' : 'files are restored beside the live data'}</label>
          <div className="flex justify-end gap-2"><button type="button" className="btn" onClick={onClose}>Cancel</button>
            <button type="button" className="btn btn-danger" disabled={busy || !ack || (scope === 'applications' && !picked.length)} onClick={go}>Restore</button></div>
        </div>)}
    </Modal>
  )
}
