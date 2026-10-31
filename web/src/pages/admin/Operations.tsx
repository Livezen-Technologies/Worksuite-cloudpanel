import { useMemo, useState } from 'react'
import { useAction, useList, useOne, useServerName } from '../../api/hooks'
import type { Container, Server } from '../../api/types'
import { Card, PageHeader, StatTile, Toggle, Empty, Meter } from '../../components/Card'
import { Badge, StatusBadge, toneFor } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, Modal } from '../../components/Overlay'
import { FormModal, type FieldDef } from '../../components/FormModal'
import { DayBars, LineChart } from '../../components/Charts'
import { LogView } from '../../components/LogView'
import { TerminalDialog } from '../../components/Terminal'
import { TerminalSessions } from '../../components/TerminalSessions'
import { Tabs } from '../../components/Tabs'
import { ago, str } from '../../lib/format'
import { num, type R } from '../../lib/util'
import { useToast } from '../../components/Toasts'
import { backupDownloadUrl } from '../../lib/download'
import Select from '../../components/Select'
import { Icon } from '../../components/Icon'

const REMOTE = ['s3', 'b2', 'r2', 'gdrive', 'sftp']
const S3_LIKE = ['s3', 'b2', 'r2']
const isS3 = (v: Record<string, unknown>) => S3_LIKE.includes(String(v.destination))
const POLICY_FIELDS: FieldDef[] = [
  { name: 'name', label: 'Name', required: true },
  { name: 'target_type', label: 'Target type', type: 'select', required: true, options: [{ value: 'volume', label: 'Volume (restic snapshot)' }, { value: 'database', label: 'Database (app-consistent dump + snapshot)' }, { value: 'application', label: 'Application data' }, { value: 'server', label: 'Server config' }] },
  { name: 'target_id', label: 'Target ID', mono: true, required: true },
  { name: 'schedule', label: 'Schedule (cron)', mono: true, required: true, placeholder: '0 3 * * *', hint: 'hourly 0 * * * * · daily 0 3 * * * · monthly 0 3 1 * *' },
  { name: 'destination', label: 'Destination', type: 'select', required: true, options: [{ value: 'local', label: 'Local (on the server)' }, { value: 's3', label: 'Amazon S3 / S3-compatible (MinIO)' }, { value: 'b2', label: 'Backblaze B2 (S3 API)' }, { value: 'r2', label: 'Cloudflare R2' }, { value: 'gdrive', label: 'Google Drive (rclone)' }, { value: 'sftp', label: 'SFTP server' }] },
  { name: 'dc_path', label: 'Repository root', mono: true, placeholder: '/var/lib/dockermgr/backups', show: (v) => v.destination === 'local' || v.destination === 'sftp' },
  { name: 'dc_host', label: 'SFTP host', mono: true, placeholder: 'backup.example.com', show: (v) => v.destination === 'sftp', required: true },
  { name: 'dc_port', label: 'SFTP port', type: 'number', placeholder: '22', show: (v) => v.destination === 'sftp' },
  { name: 'dc_user', label: 'SFTP user', mono: true, show: (v) => v.destination === 'sftp', required: true },
  { name: 'dc_private_key', label: 'Private key (PEM)', type: 'textarea', mono: true, show: (v) => v.destination === 'sftp', hint: 'write-only; leave blank to keep the stored key' },
  { name: 'dc_endpoint', label: 'Endpoint URL', mono: true, placeholder: 'https://s3.eu-central-1.amazonaws.com', show: isS3 },
  { name: 'dc_bucket', label: 'Bucket', mono: true, show: isS3, required: true },
  { name: 'dc_region', label: 'Region', mono: true, placeholder: 'us-east-1 (R2: auto)', show: isS3 },
  { name: 'dc_account_id', label: 'Account ID', mono: true, show: (v) => v.destination === 'r2' },
  { name: 'dc_access_key', label: 'Access key ID', type: 'password', show: isS3, hint: 'write-only; leave blank to keep the stored key' },
  { name: 'dc_secret_key', label: 'Secret access key', type: 'password', show: isS3, hint: 'write-only; leave blank to keep the stored key' },
  { name: 'retention_days', label: 'Retention (days)', type: 'number', required: true, hint: 'used when no daily/weekly/monthly counts are set' },
  { name: 'keep_daily', label: 'Keep daily', type: 'number', placeholder: 'e.g. 7' },
  { name: 'keep_weekly', label: 'Keep weekly', type: 'number', placeholder: 'e.g. 4' },
  { name: 'keep_monthly', label: 'Keep monthly', type: 'number', placeholder: 'e.g. 6' },
  { name: 'capacity_gb', label: 'Destination capacity (GB)', type: 'number', hint: 'warns when the destination is over 85 % full' },
  { name: 'object_lock_days', label: 'Object lock (days)', type: 'number', show: isS3, hint: 'the bucket must have object lock turned on; at most the retention window' },
  { name: 'check_subset', label: 'Integrity check data subset', mono: true, placeholder: '5%', hint: 'restic check --read-data-subset after each run' },
  { name: 'restore_test_schedule', label: 'Restore test schedule (cron)', mono: true, placeholder: '0 4 * * 0', hint: 'restores the latest snapshot into a scratch directory and validates it' },
]
const DC_KEYS = ['path', 'endpoint', 'bucket', 'region', 'account_id', 'host', 'port', 'user', 'access_key', 'secret_key', 'private_key'] as const
const WRITE_ONLY = ['access_key', 'secret_key', 'private_key']

/** Flat form values -> API body; credentials are only sent when typed (write-only). */
/** Five evenly spaced clock labels across the chosen range, ending at now. */
function timeLabels(range: string) {
  const span = ({ '1h': 3600e3, '6h': 6 * 3600e3, '24h': 86400e3, '7d': 7 * 86400e3 } as Record<string, number>)[range] ?? 6 * 3600e3
  const now = Date.now()
  return [0, 1, 2, 3, 4].map((i) => {
    const d = new Date(now - span + (span * i) / 4)
    if (i === 4) return 'now'
    return range === '7d' ? d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric' }) : d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
  })
}

export function policyBody(v: Record<string, unknown>): Record<string, unknown> {
  const body: Record<string, unknown> = {}
  const dc: Record<string, string> = {}
  for (const [k, val] of Object.entries(v)) {
    if (k.startsWith('dc_')) { if (val !== '' && val != null) dc[k.slice(3)] = String(val) } else body[k] = val
  }
  for (const k of ['destination_summary', 'success_14d', 'next_run_at', 'id', 'organization_id', 'created_at', 'updated_at', 'last_run_at', 'last_status', 'last_restore_test_at', 'last_restore_test_status', 'usage', 'last_probe', 'probed_at']) delete body[k]
  // Cleared number fields clear the setting; untouched ones are not sent.
  for (const k of ['keep_daily', 'keep_weekly', 'keep_monthly', 'capacity_gb', 'object_lock_days']) if (k in body) body[k] = body[k] === '' || body[k] == null ? null : Number(body[k])
  if (body.restore_test_schedule === '') body.restore_test_schedule = null
  if (body.check_subset === '' || body.check_subset == null) delete body.check_subset
  return { ...body, destination_config: dc, encrypted: true }
}
function policyInitial(r: R): R {
  const sum = (r.destination_summary ?? {}) as R
  const out: R = { ...r }
  for (const k of DC_KEYS) if (!WRITE_ONLY.includes(k) && sum[k] != null) out[`dc_${k}`] = sum[k]
  return out
}

function last14(jobs: R[]) {
  const days: { label: string; ok: number; failed: number }[] = []
  for (let i = 13; i >= 0; i--) {
    const d = new Date(); d.setHours(0, 0, 0, 0); d.setDate(d.getDate() - i)
    const key = d.toISOString().slice(0, 10)
    const day = jobs.filter((j) => String(j.created_at ?? j.finished_at ?? '').slice(0, 10) === key)
    days.push({ label: d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }), ok: day.filter((j) => j.status === 'succeeded' || j.status === 'success').length, failed: day.filter((j) => j.status === 'failed').length })
  }
  return days
}

const TARGET_KIND: Record<string, string> = { volume: 'Volume', database: 'Database', application: 'Application', server: 'Server config' }
const METHOD: Record<string, string> = { volume: 'Restic snapshot', database: 'Dump + snapshot', application: 'Restic (app data)', server: 'Encrypted archive' }
const DEST_LABEL: Record<string, string> = { local: 'Local disk', s3: 'Amazon S3', b2: 'Backblaze B2', r2: 'Cloudflare R2', gdrive: 'Google Drive', sftp: 'SFTP' }
const fmtMb = (mb: number) => mb >= 1024 ? `${(mb / 1024).toFixed(1)} GB` : `${mb} MB`

const RESTORE_BLANK: R = { scope: 'everything', mode: 'replace', fresh_backup: true, stop_app: false, health_check: true }

/** BKM-001..009: the Backup manager — Policies, Snapshots, Restore, Destinations, Restore tests. */
export function Backups() {
  const [tab, setTab] = useState<'policies' | 'snapshots' | 'restore' | 'destinations' | 'tests'>('policies')
  const [target, setTarget] = useState('')
  const policies = useList<R>('/backup-policies')
  const summary = useOne<R>('/backups/summary', undefined, { refetchInterval: 30000 })
  const jobs = useList<R>('/backup-jobs', { per_page: 500 }, { refetchInterval: 20000 })
  const artifacts = useList<R>('/backup-artifacts', { per_page: 50, sort: '-created_at', ...(target ? { target_id: target } : {}) }, { refetchInterval: 20000 })
  const toast = useToast()
  const [preparing, setPreparing] = useState<string | null>(null)
  const download = async (a: R) => {
    setPreparing(a.id)
    try {
      window.location.assign(await backupDownloadUrl(`/backup-artifacts/${a.id}/download`, { onExporting: () => toast.success('Exporting the snapshot as tar.gz…') }))
    } catch (e) { toast.error(e, 'Download failed') } finally { setPreparing(null) }
  }
  const policyName = (id?: string) => policies.rows.find((p) => p.id === id)?.name ?? (id ? id.slice(0, 8) : 'app backup')
  const { run, busy } = useAction()
  const [edit, setEdit] = useState<R | null>(null)
  const [artifact, setArtifact] = useState<R | null>(null)
  const [opts, setOpts] = useState<R>(RESTORE_BLANK)
  const [del, setDel] = useState<R | null>(null)
  const days = useMemo(() => last14(jobs.rows), [jobs.rows])
  const s = summary.item ?? {}
  const restorable = artifacts.rows.filter((a) => a.snapshot_id)
  const targets = useMemo(() => {
    const seen = new Map<string, string>()
    for (const p of policies.rows) seen.set(String(p.target_id), `${p.name} (${p.target_type})`)
    return [...seen.entries()]
  }, [policies.rows])
  const openRestore = (a: R) => { setArtifact(a); setOpts({ ...RESTORE_BLANK, scope: a.dump_engine ? 'database' : 'everything' }) }
  const isDump = !!artifact?.dump_engine
  const wizardOk = !(opts.mode === 'side_by_side' && isDump)
  const newPolicy = () => setEdit({ schedule: '0 3 * * *', destination: 'local', retention_days: 14, target_type: 'volume', check_subset: '5%', restore_test_schedule: '0 4 * * 0' })
  const destOf = (a: R) => String(policies.rows.find((p) => p.id === a.backup_policy_id)?.destination ?? 'local')
  const snapFilter = (
    <Select className="input !w-auto" aria-label="Filter snapshots by target" value={target} onChange={(e) => setTarget(e.target.value)}>
      <option value="">All targets</option>
      {targets.map(([id, label]) => <option key={id} value={id}>{label}</option>)}
    </Select>
  )
  const snapRow = (r: R) => (
    <li key={r.id} className="flex flex-wrap items-center gap-3 px-4 sm:px-5 py-3 border-t border-line text-[13.5px]">
      <span className="flex-[1_1_220px] min-w-0">
        <span className="block font-semibold truncate">{policyName(r.backup_policy_id)}</span>
        <span className="block text-xs text-muted font-mono truncate">{String(r.snapshot_id ?? '—').slice(0, 10)} · {destOf(r)}{r.target_type ? ` · ${r.target_type}` : ''}</span>
      </span>
      <span className="text-muted min-w-[120px]">{ago(r.created_at)}</span>
      <span className="min-w-[70px] tabular-nums">{fmtMb(num(r.size_mb))}</span>
      <span title={r.verify_message ?? undefined}><Badge tone={r.verified ? 'g' : 'y'}>{r.verified ? 'verified' : 'unverified'}</Badge></span>
      <span className="flex gap-2 ml-auto">
        <button type="button" className="btn btn-sm" disabled={preparing === r.id} onClick={() => download(r)}>{preparing === r.id ? 'Preparing…' : 'Download'}</button>
        {r.snapshot_id ? <button type="button" className="btn btn-sm btn-primary" onClick={() => openRestore(r)}>Restore</button> : null}
      </span>
    </li>
  )
  const restoreTested = artifacts.rows.filter((a) => a.restore_test_status || a.snapshot_id)

  return (
    <>
      <PageHeader title="Backup manager" subtitle="Policies, snapshots, restores and storage destinations — encrypted and verified"
        actions={<>
          <button type="button" className="btn" onClick={() => setTab('restore')}>Restore…</button>
          <button type="button" className="btn" disabled={busy} onClick={() => run({ path: '/backups/run-all', body: { which: 'failed' }, label: 'Rerun failed', success: 'Started the failed backups again' }).catch(() => {})}>Rerun failed</button>
          <button type="button" className="btn btn-primary" disabled={busy} onClick={() => run({ path: '/backups/run-all', body: { which: 'due' }, label: 'Run all due', success: 'Started the due backups' }).catch(() => {})}>Run all due now</button>
        </>} />
      <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(170px,1fr))]">
        <StatTile label="Protected targets" value={num(s.protected_targets)} delta={s.paused ? `${s.paused} paused` : undefined} />
        <StatTile label="Success (30 days)" value={s.success_rate_30d == null ? '—' : `${s.success_rate_30d}%`} delta={`${num(s.runs_30d)} runs`} deltaTone={num(s.success_rate_30d) < 95 && s.success_rate_30d != null ? 'r' : undefined} />
        <StatTile label="Stored" value={fmtMb(num(s.stored_mb))} />
        <StatTile label="Last restore test" value={s.last_restore_test ? String((s.last_restore_test as R).status) : 'never'} delta={s.last_restore_test ? ago((s.last_restore_test as R).at) : undefined} deltaTone={(s.last_restore_test as R)?.status === 'failed' ? 'r' : undefined} />
        <StatTile label="Next run" value={s.next_run_at ? ago(String(s.next_run_at)) : '—'} />
      </div>
      <Tabs value={tab} onChange={setTab} label="Backup manager sections" tabs={[
        { id: 'policies', label: 'Policies' }, { id: 'snapshots', label: 'Snapshots' }, { id: 'restore', label: 'Restore' },
        { id: 'destinations', label: 'Destinations' }, { id: 'tests', label: 'Restore tests' },
      ]} />

      {tab === 'policies' && <div className="flex flex-col gap-4">
        <Card title="Last 14 days" actions={<span className="flex flex-wrap gap-3 text-xs text-muted"><span>runs per day</span><span className="flex items-center gap-1"><span className="w-2.5 h-2.5 bg-accent rounded-sm" />succeeded</span><span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-sm bg-[var(--r-fg)]" />failed</span></span>}>
          <DayBars days={days} label="Backup runs per day over the last 14 days, succeeded and failed" />
        </Card>
        <Card title="Backup policies" pad={false} actions={<button type="button" className="btn btn-primary btn-sm" onClick={newPolicy}>+ New policy</button>}>
          <DataTable label="Backup policies" rows={policies.rows} loading={policies.isLoading} rowKey={(r) => r.id} minWidth={900}
            empty={<Empty>No backup policies yet.</Empty>}
            columns={[
              { key: 'name', header: 'Target', render: (r) => <span><span className="block font-semibold">{r.name}</span><span className="block text-xs text-muted">{TARGET_KIND[String(r.target_type)] ?? r.target_type}<span className="font-mono"> · {String(r.target_id ?? '').slice(0, 12)}</span></span></span> },
              { key: 'target_type', header: 'Method', render: (r) => <span className="text-muted">{METHOD[String(r.target_type)] ?? 'Restic snapshot'}</span> },
              { key: 'schedule', header: 'Schedule', render: (r) => <span className="font-mono text-xs">{r.schedule}</span> },
              { key: 'destination', header: 'Destination', render: (r) => <span className="text-muted">{DEST_LABEL[String(r.destination)] ?? r.destination}{r.object_lock_days ? <> <Badge tone="b">locked {r.object_lock_days} d</Badge></> : null}</span> },
              { key: 'retention', header: 'Retention', value: (r) => (r.keep_daily || r.keep_weekly || r.keep_monthly) ? [r.keep_daily && `${r.keep_daily}d`, r.keep_weekly && `${r.keep_weekly}w`, r.keep_monthly && `${r.keep_monthly}m`].filter(Boolean).join(' · ') : `${r.retention_days} days` },
              { key: 'last_status', header: 'Last run', render: (r) => <span><StatusBadge status={r.enabled === false ? 'paused' : (r.last_status ?? 'never run')} /><span className="block text-xs text-muted mt-1">{r.last_run_at ? ago(r.last_run_at) : '—'}</span></span> },
              { key: 'enabled', header: 'On', sortable: false, render: (r) => <Toggle checked={r.enabled !== false} label={`${r.enabled !== false ? 'Pause' : 'Resume'} ${r.name}`} onChange={(v) => run({ method: 'PATCH', path: `/backup-policies/${r.id}`, body: { enabled: v }, label: v ? 'Resume policy' : 'Pause policy', success: v ? 'Policy resumed' : 'Policy paused' }).catch(() => {})} /> },
              { key: 'act', header: '', sortable: false, render: (r) => (
                <span className="flex gap-2 whitespace-nowrap">
                  <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: '/backup-jobs', body: { policy_id: r.id }, label: `Back up ${r.name}` }).catch(() => {})}>Run now</button>
                  <button type="button" className="btn btn-sm btn-ghost" onClick={() => setEdit(r)}>Edit</button>
                  <button type="button" className="btn btn-sm btn-ghost btn-danger" onClick={() => setDel(r)}>Delete</button>
                </span>) },
            ]} />
        </Card>
      </div>}

      {tab === 'snapshots' && <Card pad={false} title="Snapshots" actions={snapFilter}>
        {artifacts.isLoading ? <p className="px-5 pb-4 m-0 text-sm text-muted">Loading…</p>
          : artifacts.rows.length ? <ul className="list-none m-0 p-0">{artifacts.rows.map(snapRow)}</ul>
            : <div className="px-5 pb-5"><Empty>No snapshots yet.</Empty></div>}
      </Card>}

      {tab === 'restore' && <Card pad={false} title="Restore" subtitle="Pick a snapshot to start the restore wizard: choose the snapshot, where to restore it, what to include, then confirm." actions={snapFilter}>
        <ol className="list-none m-0 px-4 sm:px-5 pb-4 grid gap-2.5 grid-cols-2 sm:grid-cols-4">
          {['Snapshot', 'Restore to', 'Scope', 'Confirm'].map((l, i) => (
            <li key={l} className={`flex flex-col gap-0.5 rounded-lg border p-3 ${i === 0 ? 'border-accent bg-sel' : 'border-line'}`}>
              <span className={`text-xs font-semibold ${i === 0 ? 'text-accent' : 'text-muted'}`}>Step {i + 1}</span>
              <span className="text-sm font-semibold">{l}</span>
            </li>))}
        </ol>
        {artifacts.isLoading ? <p className="px-5 pb-4 m-0 text-sm text-muted">Loading…</p>
          : restorable.length ? <ul className="list-none m-0 p-0">{restorable.map(snapRow)}</ul>
            : <div className="px-5 pb-5"><Empty>No restorable snapshots.</Empty></div>}
      </Card>}

      {tab === 'destinations' && (policies.isLoading ? <p className="m-0 text-sm text-muted">Loading…</p> : !policies.rows.length ? <Empty>No policies yet.</Empty> :
        <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(min(100%,270px),1fr))]">
          {policies.rows.map((r) => {
            const u = (r.usage ?? {}) as R
            const gb = (num(u.stored_bytes) / 1073741824).toFixed(2)
            const where = r.destination_summary?.bucket ?? r.destination_summary?.host ?? r.destination_summary?.path ?? ''
            const p = r.last_probe as R | undefined
            return (
              <section key={r.id} className="card p-4 flex flex-col gap-2.5 min-w-0">
                <div className="flex items-center gap-2.5">
                  <span className="w-9 h-9 shrink-0 rounded-lg bg-sel text-accent grid place-items-center text-xs font-bold uppercase">{String(r.destination).slice(0, 2)}</span>
                  <div className="flex-1 min-w-0">
                    <div className="font-bold truncate">{DEST_LABEL[String(r.destination)] ?? r.destination}</div>
                    <div className="text-xs text-muted font-mono truncate" title={where}>{where || r.name}</div>
                  </div>
                  {p ? <span title={p.message ?? undefined}><Badge tone={p.reachable ? (u.warning ? 'y' : 'g') : 'r'}>{p.reachable ? (u.warning ? 'nearly full' : 'connected') : 'unreachable'}</Badge></span> : <Badge>not tested</Badge>}
                </div>
                <div>
                  <div className="flex justify-between text-[12.5px]"><span className="text-muted">Used</span><span className="font-semibold">{gb} GB{u.capacity_gb ? ` of ${u.capacity_gb} GB` : ''}{u.percent != null ? ` · ${u.percent}%` : ''}</span></div>
                  {u.percent != null && <div className="mt-1.5"><Meter value={num(u.percent)} label={`${r.name} usage`} color={u.warning ? 'var(--r-fg)' : undefined} /></div>}
                </div>
                <div className="text-xs text-muted">
                  Policy {r.name}{p?.reachable ? ` · ${p.snapshots} snapshots` : ''}{r.probed_at ? ` · tested ${ago(r.probed_at)}` : ''}
                  {r.object_lock_days ? ` · object lock ${r.object_lock_days} d` : ''}
                  {S3_LIKE.includes(r.destination) && !r.destination_summary?.has_secret_key ? ' · no keys stored' : ''}
                  {u.warning ? <span className="block text-[var(--r-fg)]">Over 85 % full</span> : null}
                </div>
                <button type="button" className="btn btn-sm self-start" disabled={busy} onClick={() => run({ path: `/backup-policies/${r.id}/probe`, label: `Test ${r.name}` }).catch(() => {})}>Test connection</button>
              </section>)
          })}
        </div>)}

      {tab === 'tests' && <Card pad={false}>
        {artifacts.isLoading ? <p className="px-5 py-4 m-0 text-sm text-muted">Loading…</p>
          : restoreTested.length ? <ul className="list-none m-0 p-0">{restoreTested.map((r) => (
            <li key={r.id} className="flex flex-wrap items-center gap-3 px-4 sm:px-5 py-3.5 border-b border-line text-[13.5px]">
              <span className="flex-[1_1_220px] min-w-0">
                <span className="block font-semibold">{policyName(r.backup_policy_id)}</span>
                <span className="block text-xs text-muted">{String(r.restore_test_message ?? '') || `Snapshot taken ${ago(r.created_at)}`}</span>
              </span>
              <span className="text-muted">{r.restore_tested_at ? ago(r.restore_tested_at) : '—'}</span>
              {r.restore_test_status ? <StatusBadge status={r.restore_test_status} /> : <Badge>not tested</Badge>}
              <button type="button" className="btn btn-sm btn-ghost" disabled={busy} onClick={() => run({ path: `/backup-artifacts/${r.id}/restore-test`, label: 'Restore test' }).catch(() => {})}>Test now</button>
            </li>))}</ul>
            : <div className="px-5 py-4"><Empty>No snapshots to test yet.</Empty></div>}
        <p className="m-0 px-4 sm:px-5 py-3.5 text-[12.5px] text-muted">Each test restores the snapshot into an isolated scratch location and validates it.</p>
      </Card>}

      <FormModal open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? `Edit ${edit.name}` : 'New backup policy'} fields={POLICY_FIELDS} initial={edit ? policyInitial(edit) : {}} submitLabel="Save policy"
        extra={(v) => <p className="m-0 text-xs text-muted">{REMOTE.includes(String(v.destination)) ? 'Remote backups are always encrypted. The repository password is generated for you; a recovery copy is kept separately (php artisan backups:recovery-key).' : 'Local backups are encrypted with a generated repository password.'}{edit?.destination_summary?.has_secret_key || edit?.destination_summary?.has_private_key ? ' Stored keys stay in place unless you type new ones.' : ''}</p>}
        onSubmit={(v) => run({ method: edit?.id ? 'PATCH' : 'POST', path: edit?.id ? `/backup-policies/${edit.id}` : '/backup-policies', body: policyBody(v), label: 'Save policy', success: 'Policy saved' })} />

      <Modal open={!!artifact} onClose={() => setArtifact(null)} title="Restore wizard" width={640}>
        <div className="flex flex-col gap-4 text-sm">
          <p className="m-0">Snapshot <span className="font-mono">{String(artifact?.snapshot_id ?? '').slice(0, 10)}</span> of {policyName(artifact?.backup_policy_id)}, taken {ago(artifact?.created_at)}.</p>
          <fieldset className="flex flex-col gap-1 border-0 p-0 m-0"><legend className="font-semibold mb-1">What to restore</legend>
            {(isDump ? [['database', 'The database'], ['everything', 'Everything in the snapshot']] : [['everything', 'Everything in the snapshot'], ['files', 'Files only']]).map(([v, l]) =>
              <label key={v} className="flex gap-2 items-center"><input type="radio" name="scope" checked={opts.scope === v} onChange={() => setOpts({ ...opts, scope: v })} />{l}</label>)}
          </fieldset>
          <fieldset className="flex flex-col gap-1 border-0 p-0 m-0"><legend className="font-semibold mb-1">Where</legend>
            <label className="flex gap-2 items-center"><input type="radio" name="mode" checked={opts.mode === 'replace'} onChange={() => setOpts({ ...opts, mode: 'replace' })} />Replace the current data</label>
            <label className="flex gap-2 items-center"><input type="radio" name="mode" disabled={isDump} checked={opts.mode === 'side_by_side'} onChange={() => setOpts({ ...opts, mode: 'side_by_side', stop_app: false })} />Side by side, in a separate directory{isDump ? ' (files only)' : ''}</label>
            <label className="flex gap-2 items-center"><input type="radio" name="mode" checked={opts.mode === 'download'} onChange={() => setOpts({ ...opts, mode: 'download' })} />Download it instead</label>
          </fieldset>
          {opts.mode !== 'download' && <div className="flex flex-col gap-2">
            <label className="flex gap-2 items-center"><input type="checkbox" checked={!!opts.fresh_backup} onChange={(e) => setOpts({ ...opts, fresh_backup: e.target.checked })} />Take a safety backup first</label>
            {artifact?.application_id && <>
              <label className="flex gap-2 items-center"><input type="checkbox" disabled={opts.mode !== 'replace'} checked={!!opts.stop_app} onChange={(e) => setOpts({ ...opts, stop_app: e.target.checked })} />Stop the app while restoring</label>
              <label className="flex gap-2 items-center"><input type="checkbox" checked={!!opts.health_check} onChange={(e) => setOpts({ ...opts, health_check: e.target.checked })} />Health-check the app afterwards</label>
            </>}
          </div>}
          <p className="m-0 text-xs text-muted">Progress shows in the Activity dock.</p>
          <div className="flex gap-2 justify-end">
            <button type="button" className="btn" onClick={() => setArtifact(null)}>Cancel</button>
            {opts.mode === 'download'
              ? <button type="button" className="btn btn-primary" onClick={() => { const a = artifact!; setArtifact(null); download(a) }}>Download</button>
              : <button type="button" className={`btn ${opts.mode === 'replace' ? 'btn-danger' : 'btn-primary'}`} disabled={busy || !wizardOk}
                onClick={() => run({ path: '/restore-jobs', body: { artifact_id: artifact!.id, scope: opts.scope, mode: opts.mode, fresh_backup: !!opts.fresh_backup, stop_app: !!opts.stop_app, health_check: !!opts.health_check }, label: 'Restore backup' }).then(() => setArtifact(null)).catch(() => {})}>
                {opts.mode === 'replace' ? 'Replace and restore' : 'Restore side by side'}</button>}
          </div>
        </div>
      </Modal>
      <ConfirmDialog open={!!del} onClose={() => setDel(null)} danger confirmText={del?.name} title={`Delete policy ${del?.name}?`} confirmLabel="Delete policy" busy={busy}
        onConfirm={() => run({ method: 'DELETE', path: `/backup-policies/${del!.id}`, label: 'Delete policy', success: 'Policy deleted' }).then(() => setDel(null)).catch(() => {})}>
        <p className="m-0">Existing backups are kept until their retention ends.</p>
      </ConfirmDialog>
    </>
  )
}

const RULE_FIELDS = (channels: R[]): FieldDef[] => [
  { name: 'name', label: 'Name', required: true },
  { name: 'metric', label: 'Metric', type: 'select', required: true, options: ['cpu_pct', 'ram_pct', 'disk_pct', 'heartbeat', 'container_unhealthy', 'restarts', 'response_time_ms', 'backup_failed', 'ssl_days_left'] },
  { name: 'operator', label: 'Condition', type: 'select', required: true, options: ['>', '>=', '<', '<=', '='] },
  { name: 'threshold', label: 'Threshold', type: 'number', required: true },
  { name: 'duration_minutes', label: 'For (minutes)', type: 'number' },
  { name: 'severity', label: 'Severity', type: 'select', required: true, options: ['info', 'warning', 'critical', 'emergency'] },
  { name: 'scope', label: 'Scope', type: 'select', options: ['organization', 'server_group', 'application'], required: true },
  { name: 'scope_id', label: 'Scope ID (group or app)', mono: true, show: (v) => v.scope !== 'organization' },
  ...channels.map((c) => ({ name: `ch_${c.id}`, label: `Notify ${c.name ?? c.type}`, type: 'checkbox' as const })),
]

/** The public status page: which domains it shows, under which names. */
function StatusPageCard() {
  const page = useOne<R>('/status-page')
  const { run, busy } = useAction()
  const [f, setF] = useState<R | null>(null)
  const w = f ?? page.item
  if (!w) return null
  const comps = ((w.components as R[]) ?? [])
  const domains = ((page.item?.domains as R[]) ?? [])
  const set = (patch: R) => setF({ ...w, ...patch })
  const save = () => run({ path: '/status-page', method: 'PATCH', label: 'Save status page', success: 'Status page saved',
    body: { enabled: !!w.enabled, title: w.title, notice: w.notice || null, components: comps.filter((c) => c.domain_id && c.name) } }).then(() => { setF(null); page.refetch() }).catch(() => {})
  return (
    <Card title="Public status page" subtitle={w.enabled ? <a className="link" href={String(page.item?.url ?? '/status')} target="_blank" rel="noreferrer">{String(page.item?.url ?? '/status')}</a> : 'Off'}
      actions={<Toggle checked={!!w.enabled} label="Publish the status page" onChange={(v) => set({ enabled: v })} />}>
      <p className="m-0 mb-3 text-xs text-muted">Visitors see each component's state, 90 days of daily uptime from the 5-minute checks, and when incidents started and ended. Domain names and alert details are never shown.</p>
      <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
        <label className="flex flex-col gap-1 text-sm">Title<input className="input" value={String(w.title ?? '')} onChange={(e) => set({ title: e.target.value })} /></label>
        <label className="flex flex-col gap-1 text-sm">Notice (optional)<input className="input" value={String(w.notice ?? '')} placeholder="Planned maintenance on Sunday 02:00 UTC" onChange={(e) => set({ notice: e.target.value })} /></label>
      </div>
      <ul className="m-0 mt-3 p-0 list-none flex flex-col gap-2">
        {comps.map((c, i) => <li key={i} className="flex flex-wrap items-center gap-2">
          <input className="input flex-[1_1_160px]" aria-label="Component name" value={String(c.name ?? '')} placeholder="Website" onChange={(e) => set({ components: comps.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)) })} />
          <Select className="input flex-[1_1_200px]" aria-label="Domain checked" value={String(c.domain_id ?? '')} onChange={(e) => set({ components: comps.map((x, j) => (j === i ? { ...x, domain_id: e.target.value } : x)) })}>
            <option value="">Choose a domain…</option>{domains.map((d) => <option key={String(d.id)} value={String(d.id)}>{String(d.name)}</option>)}
          </Select>
          <button type="button" className="btn btn-sm btn-ghost" onClick={() => set({ components: comps.filter((_, j) => j !== i) })}>Remove</button>
        </li>)}
      </ul>
      <div className="mt-3 flex flex-wrap gap-2">
        <button type="button" className="btn btn-sm" disabled={comps.length >= 20} onClick={() => set({ components: [...comps, { name: '', domain_id: '' }] })}>Add a component</button>
        <button type="button" className="btn btn-sm btn-primary" disabled={busy || !f} onClick={save}>Save</button>
      </div>
    </Card>
  )
}

export function Monitoring() {
  const servers = useList<Server>('/servers')
  const [server, setServer] = useState('')
  const [range, setRange] = useState('6h')
  const [on, setOn] = useState<Record<string, boolean>>({ CPU: true, RAM: true, Disk: true })
  const metrics = useOne<R>('/metrics', { server_id: server || undefined, range }, { refetchInterval: 15000 })
  const rules = useList<R>('/alert-rules')
  const alerts = useList<R>('/alerts', undefined, { refetchInterval: 15000 })
  const channels = useList<R>('/notification-channels')
  const { run } = useAction()
  const [edit, setEdit] = useState<R | null>(null)
  const s = (metrics.item?.series ?? metrics.item ?? {}) as R
  // Three series, three distinct hues (blue, violet, amber) that hold in both themes.
  const series = [{ label: 'CPU', color: 'var(--accent)', data: ((s.cpu ?? []) as unknown[]).map(num) }, { label: 'RAM', color: 'var(--tok-kw)', data: ((s.ram ?? []) as unknown[]).map(num) }, { label: 'Disk', color: 'var(--y-fg)', data: ((s.disk ?? []) as unknown[]).map(num) }]
  const containers = useList<Container>('/containers', server ? { server_id: server } : undefined, { refetchInterval: 30000 })
  const last = (d: number[]) => (d.length ? d[d.length - 1] : null)
  const peak = (d: number[]) => (d.length ? Math.max(...d) : null)
  const pctTone = (v: number | null) => (v == null ? 'n' : v >= 85 ? 'r' : v >= 70 ? 'y' : 'g') as 'g' | 'y' | 'r' | 'n'
  const openAlerts = alerts.rows.filter((a) => a.status === 'open' || a.status === 'firing' || !a.resolved_at)
  const shownServers = servers.rows.filter((x) => !server || x.id === server)
  const busy = [...containers.rows].filter((c) => c.state === 'running').sort((a, b) => num(b.cpu_pct) - num(a.cpu_pct)).slice(0, 6)
  const unhealthy = containers.rows.filter((c) => c.health === 'unhealthy' || c.state === 'restarting').length
  const toForm = (r: R) => ({ ...r, ...Object.fromEntries(((r.channels ?? r.channel_ids ?? []) as (string | R)[]).map((c) => [`ch_${typeof c === 'string' ? c : c.id}`, true])) })
  const fromForm = (v: Record<string, unknown>) => {
    const ids = Object.keys(v).filter((k) => k.startsWith('ch_') && v[k]).map((k) => k.slice(3))
    const body = Object.fromEntries(Object.entries(v).filter(([k]) => !k.startsWith('ch_')))
    return { ...body, channel_ids: ids }
  }
  return (
    <>
      <PageHeader title="Monitoring" subtitle="Metrics, alerts and alert rules"
        actions={<Select className="input !w-auto" aria-label="Server filter" value={server} onChange={(e) => setServer(e.target.value)}><option value="">All servers</option>{servers.rows.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</Select>} />
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(170px,1fr))]">
        {series.map((x) => <StatTile key={x.label} label={`${x.label} now`} value={last(x.data) == null ? '—' : `${Math.round(last(x.data)!)}%`} delta={peak(x.data) == null ? 'no data yet' : `peak ${Math.round(peak(x.data)!)}% in ${range}`}
          deltaTone={pctTone(peak(x.data)) === 'g' ? 'n' : pctTone(peak(x.data))} pct={last(x.data) ?? 0} barColor={x.color}
          icon={<Icon name={x.label === 'CPU' ? 'pulse' : x.label === 'RAM' ? 'db' : 'disk'} size={16} />} iconTone={pctTone(last(x.data)) === 'g' ? 'b' : pctTone(last(x.data))} />)}
        <StatTile label="Open alerts" value={String(openAlerts.length)} delta={openAlerts.length ? `${openAlerts.filter((a) => a.severity === 'critical' || a.severity === 'emergency').length} critical` : 'all clear'} deltaTone={openAlerts.length ? 'r' : 'g'}
          icon={<Icon name="shield" size={16} />} iconTone={openAlerts.length ? 'r' : 'g'} />
        <StatTile label="Containers" value={`${containers.rows.filter((c) => c.state === 'running').length} / ${containers.rows.length}`} delta={unhealthy ? `${unhealthy} unhealthy` : 'all healthy'} deltaTone={unhealthy ? 'r' : 'g'}
          icon={<Icon name="box" size={16} />} iconTone={unhealthy ? 'r' : 'b'} />
      </div>
      <Card title="Fleet resources" subtitle={server ? servers.rows.find((x) => x.id === server)?.name : `${servers.rows.length} servers`} actions={<>
        {series.map((x) => <button key={x.label} type="button" aria-pressed={on[x.label]} onClick={() => setOn({ ...on, [x.label]: !on[x.label] })}
          className={`btn btn-sm gap-1.5 ${on[x.label] ? '' : 'opacity-50'}`}><span aria-hidden="true" className="w-2.5 h-2.5 rounded-full" style={{ background: x.color }} />{x.label}</button>)}
        <span className="btn-group" role="group" aria-label="Range">{['1h', '6h', '24h', '7d'].map((r) => <button key={r} type="button" aria-pressed={range === r} className={`btn btn-sm ${range === r ? 'btn-primary' : ''}`} onClick={() => setRange(r)}>{r}</button>)}</span>
      </>}>
        <LineChart label={`Fleet ${series.filter((x) => on[x.label]).map((x) => x.label).join(', ')} over ${range}`} series={series.filter((x) => on[x.label])} xLabels={timeLabels(range)} height={260} />
      </Card>
      <div className="grid gap-4 items-start [grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr))]">
        <Card title="Servers" subtitle="Click one to chart it alone" pad={false}>
          <ul className="m-0 p-0 list-none">
            {shownServers.map((x) => (
              <li key={x.id}>
                <button type="button" onClick={() => setServer(server === x.id ? '' : x.id)} className={`w-full text-left grid gap-x-4 gap-y-1.5 items-center px-5 py-3 border-0 border-t border-line first:border-t-0 bg-transparent cursor-pointer hover:bg-head [grid-template-columns:minmax(120px,1.2fr)_repeat(3,minmax(70px,1fr))] ${server === x.id ? 'bg-sel' : ''}`}>
                  <span className="min-w-0"><span className="block font-semibold text-sm truncate">{x.name}</span><span className="flex items-center gap-1.5 text-xs text-muted"><StatusBadge status={x.status} />{x.last_heartbeat_at ? ago(x.last_heartbeat_at) : ''}</span></span>
                  {([['CPU', x.cpu_pct, 'var(--accent)'], ['RAM', x.ram_pct, 'var(--tok-kw)'], ['Disk', x.disk_pct, 'var(--y-fg)']] as const).map(([k, v, c]) => (
                    <span key={k} className="min-w-0"><span className="flex justify-between text-xs text-muted"><span>{k}</span><span className="tabular-nums font-medium text-fg">{v == null ? '—' : `${Math.round(num(v))}%`}</span></span><Meter value={num(v)} label={`${x.name} ${k}`} color={num(v) >= 85 ? 'var(--r-fg)' : c} /></span>
                  ))}
                </button>
              </li>
            ))}
            {shownServers.length === 0 && <li className="px-5 py-4 text-sm text-muted">No servers yet.</li>}
          </ul>
        </Card>
        <Card title="Busiest containers" subtitle="Running, by CPU" pad={false}>
          <ul className="m-0 p-0 list-none">
            {busy.map((c) => (
              <li key={c.id} className="flex items-center gap-3 px-5 py-2.5 border-t border-line first:border-t-0 text-sm">
                <span className="flex-1 min-w-0"><span className="block font-mono text-xs font-semibold truncate">{c.name}</span><span className="text-xs text-muted">{c.server_name ?? c.server?.name ?? ''}{c.mem_mb != null ? ` · ${Math.round(num(c.mem_mb))} MB` : ''}</span></span>
                <span className="w-28"><Meter value={num(c.cpu_pct)} label={`${c.name} CPU`} /></span>
                <span className="w-12 text-right tabular-nums text-xs font-semibold">{c.cpu_pct == null ? '—' : `${Math.round(num(c.cpu_pct))}%`}</span>
              </li>
            ))}
            {busy.length === 0 && <li className="px-5 py-4 text-sm text-muted">No running containers.</li>}
          </ul>
        </Card>
      </div>
      <StatusPageCard />
      <Card title="Active alerts" pad={false}>
        <DataTable label="Alerts" rows={alerts.rows} loading={alerts.isLoading} rowKey={(r) => r.id}
          filters={[{ id: 'emergency', label: 'Emergency', test: (r) => r.severity === 'emergency' }, { id: 'critical', label: 'Critical', test: (r) => r.severity === 'critical' }, { id: 'unack', label: 'Not acknowledged', test: (r) => !r.acknowledged_at }]}
          columns={[
            { key: 'severity', header: 'Severity', render: (r) => <Badge tone={toneFor(r.severity)}>{r.severity}</Badge> },
            { key: 'title', header: 'Alert', value: (r) => r.title ?? r.rule_name ?? r.name, render: (r) => <span className="font-semibold">{r.title ?? r.rule_name ?? r.name}</span> },
            { key: 'detail', header: 'Detail', value: (r) => r.detail ?? r.message ?? [r.resource_type, r.value != null ? `value ${r.value}` : null, r.status].filter(Boolean).join(' · ') },
            { key: 'created_at', header: 'Since', render: (r) => ago(r.created_at ?? r.started_at) },
            { key: 'ack', header: '', sortable: false, render: (r) => r.acknowledged_at ? <Badge tone="n">acknowledged</Badge> : <button type="button" className="btn btn-sm" onClick={() => run({ path: `/alerts/${r.id}/ack`, label: 'Acknowledge alert', success: 'Alert acknowledged' }).catch(() => {})}>Acknowledge</button> },
          ]} />
      </Card>
      <Card title="Alert rules" pad={false} actions={<button type="button" className="btn btn-sm btn-primary" onClick={() => setEdit({ operator: '>', severity: 'warning', scope: 'organization', duration_minutes: 5 })}>Add rule</button>}>
        <DataTable label="Alert rules" rows={rules.rows} loading={rules.isLoading} rowKey={(r) => r.id}
          columns={[
            { key: 'name', header: 'Rule', render: (r) => <span className="font-semibold">{r.name}</span> },
            { key: 'condition', header: 'Condition', value: (r) => `${r.metric} ${r.operator ?? '>'} ${r.threshold}${r.duration_minutes ? ` for ${r.duration_minutes} min` : ''}` },
            { key: 'severity', header: 'Severity', render: (r) => <Badge tone={toneFor(r.severity)}>{r.severity}</Badge> },
            { key: 'scope', header: 'Scope & channels', value: (r) => `${r.scope ?? r.scope_type ?? 'organization'} · ${(r.channels ?? r.channel_ids ?? []).length ? str(((r.channels ?? []) as R[]).map((c) => c.name ?? c)) : 'no channels'}` },
            { key: 'enabled', header: 'Enabled', sortable: false, render: (r) => <Toggle checked={r.enabled !== false} label={`Enable ${r.name}`} onChange={(v) => run({ method: 'PATCH', path: `/alert-rules/${r.id}`, body: { enabled: v }, label: 'Update rule', success: v ? 'Rule enabled' : 'Rule disabled' }).catch(() => {})} /> },
            { key: 'edit', header: '', sortable: false, render: (r) => <span className="flex gap-2"><button type="button" className="btn btn-sm" onClick={() => setEdit(toForm(r))}>Edit</button><button type="button" className="btn btn-sm btn-danger" onClick={() => run({ method: 'DELETE', path: `/alert-rules/${r.id}`, label: 'Delete rule', success: 'Rule deleted' }).catch(() => {})}>Delete</button></span> },
          ]} />
      </Card>
      <Card title="Notification channels">
        <div className="flex flex-wrap gap-2">{channels.rows.map((c) => <span key={c.id} className="flex items-center gap-2 px-3 py-1.5 rounded-lg border border-line text-sm"><span className="font-semibold">{c.name ?? c.type}</span><StatusBadge status={c.enabled === false ? 'off' : 'connected'} /></span>)}
          {channels.rows.length === 0 && <span className="text-sm text-muted">No channels yet — add them in Settings → Notifications.</span>}</div>
      </Card>
      <FormModal open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? `Edit ${edit.name}` : 'Add alert rule'} fields={RULE_FIELDS(channels.rows)} initial={edit ?? {}} submitLabel="Save rule"
        onSubmit={(v) => run({ method: edit?.id ? 'PATCH' : 'POST', path: edit?.id ? `/alert-rules/${edit.id}` : '/alert-rules', body: fromForm(v), label: 'Save rule', success: 'Rule saved' })} />
    </>
  )
}

export function Logs() {
  const serverName = useServerName()
  const [tab, setTab] = useState<'logs' | 'term' | 'audit'>('logs')
  const containers = useList<Container>('/containers')
  const [cid, setCid] = useState('')
  const [term, setTerm] = useState(false)
  const audit = useList<R>(tab === 'audit' ? '/audit-logs' : null, { per_page: 200 })
  const c = containers.rows.find((x) => x.id === cid) ?? containers.rows.find((x) => x.state === 'running')
  const exportCsv = () => {
    const head = ['time', 'user', 'action', 'resource', 'source_ip']
    const lines = audit.rows.map((r) => [r.created_at, r.user_name ?? r.user?.name ?? r.user, r.action, r.resource ?? r.resource_type, r.ip ?? r.source_ip].map((x) => `"${String(x ?? '').replace(/"/g, '""')}"`).join(','))
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([[head.join(','), ...lines].join('\n')], { type: 'text/csv' })); a.download = 'audit-log.csv'; a.click()
  }
  const picker = <Select className="input !w-auto" aria-label="Container" value={c?.id ?? ''} onChange={(e) => setCid(e.target.value)}>{containers.rows.map((x) => <option key={x.id} value={x.id}>{x.name} · {serverName(x as unknown as R)}</option>)}</Select>
  return (
    <>
      <PageHeader title="Logs & Terminal" subtitle="Live log streams, audited exec sessions and the audit trail" />
      <Tabs value={tab} onChange={setTab} tabs={[{ id: 'logs', label: 'Live logs' }, { id: 'term', label: 'Terminal' }, { id: 'audit', label: 'Audit log' }]} />
      {tab === 'logs' && <Card actions={picker}>{c ? <LogView path={`/containers/${c.id}/logs`} title={`${c.name} · ${serverName(c as unknown as R)}`} height={440} /> : <Empty>No containers.</Empty>}</Card>}
      {tab === 'term' && (
        <Card title="Exec session" actions={picker}>
          <p className="mt-0 text-sm text-muted">Sessions run a fixed shell as a non-root user through the relay, expire automatically, can be ended at any time and are recorded (see below) and audited.</p>
          <button type="button" className="btn btn-primary" disabled={!c || c.state !== 'running'} onClick={() => setTerm(true)}>Open terminal{c ? ` in ${c.name}` : ''}</button>
          {c && <TerminalDialog open={term} onClose={() => setTerm(false)} containerId={c.id} name={c.name} />}
        </Card>
      )}
      {tab === 'term' && <div className="mt-4"><TerminalSessions /></div>}
      {tab === 'audit' && (
        <DataTable label="Audit log" rows={audit.rows} loading={audit.isLoading} rowKey={(r) => String(r.id)} pageSize={25}
          toolbar={<button type="button" className="btn btn-sm" onClick={exportCsv}>Export CSV</button>}
          columns={[
            { key: 'created_at', header: 'Time', render: (r) => <span className="font-mono text-xs">{String(r.created_at ?? '').replace('T', ' ').slice(0, 19)}</span> },
            { key: 'user', header: 'User', value: (r) => r.actor ?? r.user_name ?? r.user?.name ?? 'system' },
            { key: 'action', header: 'Action', render: (r) => <span className="font-mono text-xs">{r.action}</span> },
            { key: 'resource', header: 'Resource', value: (r) => r.resource ?? `${r.resource_type ?? ''} ${r.resource_id ?? ''}` },
            { key: 'ip', header: 'Source IP', render: (r) => <span className="font-mono text-xs">{r.ip ?? r.source_ip ?? '—'}</span> },
          ]} />
      )}
    </>
  )
}

export function Automation() {
  const serverName = useServerName()
  const jobs = useList<R>('/scheduled-jobs', undefined, { refetchInterval: 20000 })
  const windows = useList<R>('/maintenance-windows')
  const servers = useList<Server>('/servers')
  const { run, busy } = useAction()
  const [edit, setEdit] = useState<R | null>(null)
  const [mw, setMw] = useState(false)
  return (
    <>
      <PageHeader title="Automation" subtitle="Scheduled jobs, webhooks and maintenance windows"
        actions={<><button type="button" className="btn" onClick={() => setMw(true)}>New maintenance window</button><button type="button" className="btn btn-primary" onClick={() => setEdit({ trigger: 'cron', enabled: true })}>New job</button></>} />
      {jobs.isLoading ? <Card>Loading…</Card> : jobs.rows.length === 0 ? <Card><Empty>No scheduled jobs yet.</Empty></Card> : (
        <ul className="list-none m-0 p-0 flex flex-col gap-3">
          {jobs.rows.map((j) => (
            <li key={j.id} className="card p-4 flex flex-wrap items-center gap-4">
              <Toggle checked={j.enabled !== false} label={`Enable ${j.name}`} onChange={(v) => run({ method: 'PATCH', path: `/scheduled-jobs/${j.id}`, body: { enabled: v }, label: 'Update job', success: v ? 'Job enabled' : 'Job disabled' }).catch(() => {})} />
              <div className="flex-1 min-w-[200px]">
                <div className="font-semibold">{j.name}</div>
                <div className="text-xs text-muted"><span className="font-mono">{j.trigger === 'cron' ? `cron ${j.cron ?? j.schedule ?? ''}` : j.trigger}</span> · {j.target ?? j.action ?? ''}</div>
              </div>
              <div className="text-xs text-muted">Next: <span className="font-semibold text-fg">{j.next_run_at ? new Date(j.next_run_at).toLocaleString() : j.trigger === 'cron' ? '—' : 'on event'}</span></div>
              <StatusBadge status={j.last_status ?? j.last_result ?? 'never run'} />
              <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/scheduled-jobs/${j.id}/run`, label: `Run ${j.name}` }).catch(() => {})}>Run now</button>
              <button type="button" className="btn btn-sm" onClick={() => setEdit(j)}>Edit</button>
            </li>
          ))}
        </ul>
      )}
      <Card title="Maintenance windows" pad={false}>
        <DataTable label="Maintenance windows" rows={windows.rows} loading={windows.isLoading} rowKey={(r) => r.id}
          columns={[
            { key: 'name', header: 'Name', render: (r) => <span className="font-semibold">{r.name ?? 'Window'}</span> },
            { key: 'scope', header: 'Server / group', value: (r) => r.server_name ?? r.group ?? serverName(r) },
            { key: 'starts_at', header: 'Starts', render: (r) => r.starts_at ? new Date(r.starts_at).toLocaleString() : (r.schedule ?? '—') },
            { key: 'ends_at', header: 'Ends', render: (r) => r.ends_at ? new Date(r.ends_at).toLocaleString() : '—' },
            { key: 'del', header: '', sortable: false, render: (r) => <button type="button" className="btn btn-sm btn-danger" onClick={() => run({ method: 'DELETE', path: `/maintenance-windows/${r.id}`, label: 'Delete window', success: 'Window deleted' }).catch(() => {})}>Delete</button> },
          ]} />
      </Card>
      <FormModal open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? `Edit ${edit.name}` : 'New job'} initial={edit ?? {}} submitLabel="Save job"
        fields={[{ name: 'name', label: 'Name', required: true }, { name: 'trigger', label: 'Trigger', type: 'select', required: true, options: ['cron', 'webhook', 'manual'] }, { name: 'cron', label: 'Cron expression', mono: true, placeholder: '0 4 * * SUN', show: (v) => v.trigger === 'cron', required: true }, { name: 'action', label: 'Action', type: 'select', required: true, options: ['image.prune', 'container.restart', 'backup.run', 'restore.test', 'agent.update', 'deploy'] }, { name: 'target', label: 'Target', placeholder: 'All servers, a group or an app' }, { name: 'requires_approval', label: 'Requires approval', type: 'checkbox' }, { name: 'enabled', label: 'Enabled', type: 'checkbox' }]}
        onSubmit={(v) => run({ method: edit?.id ? 'PATCH' : 'POST', path: edit?.id ? `/scheduled-jobs/${edit.id}` : '/scheduled-jobs', body: v, label: 'Save job', success: 'Job saved' })} />
      <FormModal open={mw} onClose={() => setMw(false)} title="New maintenance window" submitLabel="Create window"
        fields={[{ name: 'name', label: 'Name', required: true }, { name: 'server_id', label: 'Server', type: 'select', options: servers.rows.map((s) => ({ value: s.id, label: s.name })) }, { name: 'group', label: 'or server group tag' }, { name: 'starts_at', label: 'Starts (ISO time)', placeholder: '2026-11-01T01:00:00Z', required: true, mono: true }, { name: 'ends_at', label: 'Ends (ISO time)', placeholder: '2026-11-01T03:00:00Z', required: true, mono: true }, { name: 'recurrence', label: 'Repeats', type: 'select', options: ['none', 'weekly', 'monthly'] }]}
        onSubmit={(v) => run({ path: '/maintenance-windows', body: v, label: 'Create window', success: 'Window created' })} />
    </>
  )
}
