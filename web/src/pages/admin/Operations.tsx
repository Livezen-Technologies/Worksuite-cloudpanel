import { useMemo, useState } from 'react'
import { useAction, useList, useOne, useServerName } from '../../api/hooks'
import type { Container, Server } from '../../api/types'
import { Card, PageHeader, StatTile, Toggle, Empty } from '../../components/Card'
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

  return (
    <>
      <PageHeader title="Backup manager" subtitle="Encrypted, integrity-checked and restore-tested"
        actions={<>
          <button type="button" className="btn" disabled={busy} onClick={() => run({ path: '/backups/run-all', body: { which: 'due' }, label: 'Run all due', success: 'Started the due backups' }).catch(() => {})}>Run all due</button>
          <button type="button" className="btn" disabled={busy} onClick={() => run({ path: '/backups/run-all', body: { which: 'failed' }, label: 'Rerun failed', success: 'Started the failed backups again' }).catch(() => {})}>Rerun failed</button>
          <button type="button" className="btn btn-primary" onClick={() => setEdit({ schedule: '0 3 * * *', destination: 'local', retention_days: 14, target_type: 'volume', check_subset: '5%', restore_test_schedule: '0 4 * * 0' })}>New backup policy</button>
        </>} />
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
        <StatTile label="Protected targets" value={num(s.protected_targets)} delta={s.paused ? `${s.paused} paused` : undefined} />
        <StatTile label="Success (30 days)" value={s.success_rate_30d == null ? '—' : `${s.success_rate_30d}%`} delta={`${num(s.runs_30d)} runs`} deltaTone={num(s.success_rate_30d) < 95 && s.success_rate_30d != null ? 'r' : undefined} />
        <StatTile label="Stored" value={`${num(s.stored_mb) >= 1024 ? (num(s.stored_mb) / 1024).toFixed(1) + ' GB' : num(s.stored_mb) + ' MB'}`} />
        <StatTile label="Last restore test" value={s.last_restore_test ? String((s.last_restore_test as R).status) : 'never'} delta={s.last_restore_test ? ago((s.last_restore_test as R).at) : undefined} deltaTone={(s.last_restore_test as R)?.status === 'failed' ? 'r' : undefined} />
        <StatTile label="Next run" value={s.next_run_at ? ago(String(s.next_run_at)) : '—'} />
      </div>
      <Card title="Last 14 days" actions={<span className="flex gap-3 text-xs text-muted"><span className="flex items-center gap-1"><span className="w-2.5 h-2.5 bg-accent rounded-sm" />succeeded</span><span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-sm bg-[var(--r-fg)]" />failed</span></span>}>
        <DayBars days={days} label="Backup runs per day over the last 14 days, succeeded and failed" />
      </Card>
      <Tabs value={tab} onChange={setTab} label="Backup manager sections" tabs={[
        { id: 'policies', label: 'Policies' }, { id: 'snapshots', label: 'Snapshots' }, { id: 'restore', label: 'Restore' },
        { id: 'destinations', label: 'Destinations' }, { id: 'tests', label: 'Restore tests' },
      ]} />

      {tab === 'policies' && <DataTable label="Backup policies" rows={policies.rows} loading={policies.isLoading} rowKey={(r) => r.id} minWidth={900}
        columns={[
          { key: 'name', header: 'Policy', render: (r) => <span className="font-semibold">{r.name}</span> },
          { key: 'target_type', header: 'Target' },
          { key: 'schedule', header: 'Schedule', render: (r) => <span className="font-mono text-xs">{r.schedule}</span> },
          { key: 'destination', header: 'Destination', render: (r) => <span>{r.destination}{r.object_lock_days ? <Badge tone="b">locked {r.object_lock_days} d</Badge> : null}</span> },
          { key: 'retention', header: 'Retention', value: (r) => (r.keep_daily || r.keep_weekly || r.keep_monthly) ? [r.keep_daily && `${r.keep_daily} daily`, r.keep_weekly && `${r.keep_weekly} weekly`, r.keep_monthly && `${r.keep_monthly} monthly`].filter(Boolean).join(', ') : `${r.retention_days} days` },
          { key: 'last_status', header: 'Last run', render: (r) => <StatusBadge status={r.last_status ?? 'never run'} /> },
          { key: 'last_run_at', header: 'When', render: (r) => ago(r.last_run_at) },
          { key: 'enabled', header: 'Active', sortable: false, render: (r) => <Toggle checked={r.enabled !== false} label={`${r.enabled !== false ? 'Pause' : 'Resume'} ${r.name}`} onChange={(v) => run({ method: 'PATCH', path: `/backup-policies/${r.id}`, body: { enabled: v }, label: v ? 'Resume policy' : 'Pause policy', success: v ? 'Policy resumed' : 'Policy paused' }).catch(() => {})} /> },
          { key: 'act', header: '', sortable: false, render: (r) => (
            <span className="flex gap-2">
              <button type="button" className="btn btn-sm btn-ghost" disabled={busy} onClick={() => run({ path: '/backup-jobs', body: { policy_id: r.id }, label: `Back up ${r.name}` }).catch(() => {})}>Run now</button>
              <button type="button" className="btn btn-sm btn-ghost" onClick={() => setEdit(r)}>Edit</button>
              <button type="button" className="btn btn-sm btn-ghost btn-danger" onClick={() => setDel(r)}>Delete</button>
            </span>) },
        ]} />}

      {(tab === 'snapshots' || tab === 'restore') && <>
        <div className="flex flex-wrap gap-2 items-center">
          <Select className="input !w-auto" aria-label="Filter snapshots by target" value={target} onChange={(e) => setTarget(e.target.value)}>
            <option value="">All targets</option>
            {targets.map(([id, label]) => <option key={id} value={id}>{label}</option>)}
          </Select>
          {tab === 'restore' && <span className="text-sm text-muted">Pick a snapshot to start the restore wizard.</span>}
        </div>
        <DataTable label="Snapshots" rows={tab === 'restore' ? restorable : artifacts.rows} loading={artifacts.isLoading} rowKey={(r) => r.id} minWidth={900}
          columns={[
            { key: 'created_at', header: 'Taken', render: (r) => ago(r.created_at) },
            { key: 'policy', header: 'Policy', value: (r) => policyName(r.backup_policy_id) },
            { key: 'target_type', header: 'Target', value: (r) => String(r.target_type ?? '—') },
            { key: 'snapshot_id', header: 'Snapshot', render: (r) => <span className="font-mono text-xs">{String(r.snapshot_id ?? '—').slice(0, 10)}</span> },
            { key: 'destination', header: 'Destination', value: (r) => String(policies.rows.find((p) => p.id === r.backup_policy_id)?.destination ?? 'local') },
            { key: 'size_mb', header: 'Size', value: (r) => `${r.size_mb ?? 0} MB` },
            { key: 'verified', header: 'Integrity', render: (r) => <span title={r.verify_message ?? undefined}><Badge tone={r.verified ? 'g' : 'y'}>{r.verified ? 'verified' : 'unverified'}</Badge></span> },
            { key: 'act', header: '', sortable: false, render: (r) => (
              <span className="flex gap-2">
                <button type="button" className="btn btn-sm btn-ghost" disabled={preparing === r.id} onClick={() => download(r)}>{preparing === r.id ? 'Preparing…' : 'Download'}</button>
                {r.snapshot_id ? <button type="button" className="btn btn-sm" onClick={() => openRestore(r)}>Restore…</button> : null}
              </span>) },
          ]} />
      </>}

      {tab === 'destinations' && <DataTable label="Destinations" rows={policies.rows} loading={policies.isLoading} rowKey={(r) => r.id} minWidth={800}
        empty={<Empty>No policies yet.</Empty>}
        columns={[
          { key: 'name', header: 'Policy', render: (r) => <span className="font-semibold">{r.name}</span> },
          { key: 'destination', header: 'Destination', render: (r) => <span>{r.destination}<span className="block text-xs text-muted font-mono">{r.destination_summary?.bucket ?? r.destination_summary?.host ?? r.destination_summary?.path ?? ''}</span>{S3_LIKE.includes(r.destination) && !r.destination_summary?.has_secret_key ? <Badge tone="y">no keys</Badge> : null}</span> },
          { key: 'probe', header: 'Connection', render: (r) => r.last_probe ? <span title={r.last_probe.message ?? undefined}><Badge tone={r.last_probe.reachable ? 'g' : 'r'}>{r.last_probe.reachable ? `ok · ${r.last_probe.snapshots} snapshots` : 'unreachable'}</Badge> <span className="text-xs text-muted">{ago(r.probed_at)}</span></span> : <span className="text-xs text-muted">not tested</span> },
          { key: 'usage', header: 'Usage', render: (r) => {
            const u = (r.usage ?? {}) as R
            const gb = (num(u.stored_bytes) / 1073741824).toFixed(2)
            return <span>{gb} GB{u.capacity_gb ? ` of ${u.capacity_gb} GB` : ''}{u.percent != null ? <> · <Badge tone={u.warning ? 'r' : 'g'}>{String(u.percent)}%</Badge></> : null}{u.warning ? <span className="block text-xs text-[var(--r-fg)]">Over 85 % full</span> : null}</span>
          } },
          { key: 'lock', header: 'Immutability', render: (r) => r.object_lock_days ? <Badge tone="b">object lock {r.object_lock_days} d</Badge> : <span className="text-xs text-muted">off</span> },
          { key: 'act', header: '', sortable: false, render: (r) => <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/backup-policies/${r.id}/probe`, label: `Test ${r.name}` }).catch(() => {})}>Test connection</button> },
        ]} />}

      {tab === 'tests' && <DataTable label="Restore tests" rows={artifacts.rows.filter((a) => a.restore_test_status || a.snapshot_id)} loading={artifacts.isLoading} rowKey={(r) => r.id} minWidth={800}
        columns={[
          { key: 'policy', header: 'Policy', value: (r) => policyName(r.backup_policy_id) },
          { key: 'created_at', header: 'Snapshot taken', render: (r) => ago(r.created_at) },
          { key: 'restore_test_status', header: 'Result', render: (r) => r.restore_test_status ? <StatusBadge status={r.restore_test_status} /> : <span className="text-muted text-xs">not tested</span> },
          { key: 'restore_tested_at', header: 'Tested', render: (r) => r.restore_tested_at ? ago(r.restore_tested_at) : '—' },
          { key: 'restore_test_message', header: 'Detail', render: (r) => <span className="text-xs">{String(r.restore_test_message ?? '')}</span> },
          { key: 'act', header: '', sortable: false, render: (r) => <button type="button" className="btn btn-sm btn-ghost" disabled={busy} onClick={() => run({ path: `/backup-artifacts/${r.id}/restore-test`, label: 'Restore test' }).catch(() => {})}>Test now</button> },
        ]} />}

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
  const series = [{ label: 'CPU', color: 'var(--accent)', data: ((s.cpu ?? []) as unknown[]).map(num) }, { label: 'RAM', color: 'var(--b-fg)', data: ((s.ram ?? []) as unknown[]).map(num) }, { label: 'Disk', color: 'var(--y-fg)', data: ((s.disk ?? []) as unknown[]).map(num) }]
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
      <Card title="Fleet resources" actions={<>
        {series.map((x) => <button key={x.label} type="button" className="chip !min-h-8 !text-xs" aria-pressed={on[x.label]} onClick={() => setOn({ ...on, [x.label]: !on[x.label] })}>{x.label}</button>)}
        <Select className="input !w-auto !min-h-8 text-xs" aria-label="Range" value={range} onChange={(e) => setRange(e.target.value)}>{['1h', '6h', '24h', '7d'].map((r) => <option key={r}>{r}</option>)}</Select>
      </>}>
        <LineChart label={`Fleet ${series.filter((x) => on[x.label]).map((x) => x.label).join(', ')} over ${range}`} series={series.filter((x) => on[x.label])} />
      </Card>
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
