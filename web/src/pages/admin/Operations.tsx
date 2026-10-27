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

const REMOTE = ['s3', 'b2', 'r2', 'gdrive']
const POLICY_FIELDS: FieldDef[] = [
  { name: 'name', label: 'Name', required: true },
  { name: 'target_type', label: 'Target type', type: 'select', required: true, options: ['volume', 'database', 'compose', 'server_config'] },
  { name: 'target_id', label: 'Target ID', mono: true, required: true },
  { name: 'method', label: 'Method', type: 'select', required: true, options: [{ value: 'restic', label: 'Restic snapshot' }, { value: 'db_dump', label: 'Database dump (app-consistent)' }, { value: 'git', label: 'Git revision' }, { value: 'archive', label: 'Encrypted archive' }] },
  { name: 'schedule', label: 'Schedule', type: 'select', required: true, options: ['hourly', 'every 6 hours', 'daily', 'weekly', 'monthly'] },
  { name: 'destination', label: 'Destination', type: 'select', required: true, options: [{ value: 'local', label: 'Local' }, { value: 's3', label: 'Amazon S3' }, { value: 'b2', label: 'Backblaze B2' }, { value: 'r2', label: 'Cloudflare R2' }, { value: 'gdrive', label: 'Google Drive' }] },
  { name: 'retention_days', label: 'Retention (days)', type: 'number', required: true },
  { name: 'restore_test', label: 'Scheduled restore tests', type: 'checkbox', hint: 'monthly, in a sandbox' },
]

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

export function Backups() {
  const policies = useList<R>('/backup-policies')
  const jobs = useList<R>('/backup-jobs', { per_page: 500 }, { refetchInterval: 20000 })
  const { run, busy } = useAction()
  const [edit, setEdit] = useState<R | null>(null)
  const [restoreOpen, setRestoreOpen] = useState(false)
  const [artifact, setArtifact] = useState<R | null>(null)
  const [del, setDel] = useState<R | null>(null)
  const days = useMemo(() => last14(jobs.rows), [jobs.rows])
  const day = jobs.rows.filter((j) => Date.now() - new Date(j.created_at).getTime() < 86400e3)
  const restorable = jobs.rows.filter((j) => (j.status === 'succeeded' || j.status === 'success') && (j.artifact_id || j.artifact))
  return (
    <>
      <PageHeader title="Backups" subtitle="Encrypted, integrity-checked and restore-tested"
        actions={<><button type="button" className="btn" onClick={() => setRestoreOpen(true)}>Restore…</button><button type="button" className="btn btn-primary" onClick={() => setEdit({ schedule: 'daily', destination: 'r2', retention_days: 14, method: 'restic', target_type: 'volume' })}>New backup policy</button></>} />
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
        <StatTile label="Policies" value={policies.rows.length} />
        <StatTile label="Succeeded (24 h)" value={day.filter((j) => j.status === 'succeeded' || j.status === 'success').length} />
        <StatTile label="Failed (24 h)" value={day.filter((j) => j.status === 'failed').length} deltaTone="r" delta={day.some((j) => j.status === 'failed') ? 'needs attention' : undefined} />
        <StatTile label="Verified" value={jobs.rows.filter((j) => j.verified || j.integrity === 'verified').length} delta="integrity checked" />
      </div>
      <Card title="Last 14 days" actions={<span className="flex gap-3 text-xs text-muted"><span className="flex items-center gap-1"><span className="w-2.5 h-2.5 bg-accent rounded-sm" />succeeded</span><span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-sm bg-[#DC2626]" />failed</span></span>}>
        <DayBars days={days} label="Backup runs per day over the last 14 days, succeeded and failed" />
      </Card>
      <DataTable label="Backup policies" rows={policies.rows} loading={policies.isLoading} rowKey={(r) => r.id} minWidth={900}
        columns={[
          { key: 'name', header: 'Policy', render: (r) => <span className="font-semibold">{r.name}</span> },
          { key: 'method', header: 'Method' },
          { key: 'schedule', header: 'Schedule' },
          { key: 'destination', header: 'Destination' },
          { key: 'last_status', header: 'Last run', render: (r) => <StatusBadge status={r.last_status ?? r.last_run?.status ?? 'never run'} /> },
          { key: 'retention_days', header: 'Retention', value: (r) => (r.retention_days != null ? `${r.retention_days} days` : '—') },
          { key: 'encrypted', header: 'Encryption', render: (r) => <Badge tone={r.encrypted !== false ? 'g' : 'y'}>{r.encrypted !== false ? 'encrypted' : 'not encrypted'}</Badge> },
          { key: 'last_run_at', header: 'When', render: (r) => ago(r.last_run_at ?? r.last_run?.created_at) },
          { key: 'act', header: '', sortable: false, render: (r) => (
            <span className="flex gap-2">
              <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: '/backup-jobs', body: { policy_id: r.id }, label: `Back up ${r.name}` }).catch(() => {})}>Run now</button>
              <button type="button" className="btn btn-sm" onClick={() => setEdit(r)}>Edit</button>
              <button type="button" className="btn btn-sm btn-danger" onClick={() => setDel(r)}>Delete</button>
            </span>) },
        ]} />
      <FormModal open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? `Edit ${edit.name}` : 'New backup policy'} fields={POLICY_FIELDS} initial={edit ?? {}} submitLabel="Save policy"
        extra={(v) => <p className="m-0 text-xs text-muted">{REMOTE.includes(String(v.destination)) ? 'Remote backups are always encrypted. Recovery keys are stored separately from the backup storage.' : 'Local backups are encrypted by default.'}</p>}
        onSubmit={(v) => run({ method: edit?.id ? 'PATCH' : 'POST', path: edit?.id ? `/backup-policies/${edit.id}` : '/backup-policies', body: { ...v, encrypted: true }, label: 'Save policy', success: 'Policy saved' })} />
      <Modal open={restoreOpen} onClose={() => setRestoreOpen(false)} title="Restore from backup" width={640}>
        {restorable.length === 0 ? <Empty>No restorable backups yet.</Empty> : (
          <ul className="list-none m-0 p-0">{restorable.slice(0, 30).map((j) => (
            <li key={j.id} className="flex items-center gap-3 py-2.5 border-t border-line">
              <span className="font-semibold flex-1">{j.policy_name ?? j.policy?.name ?? j.policy_id}</span><span className="text-xs text-muted">{ago(j.created_at)}</span>
              <button type="button" className="btn btn-sm" onClick={() => setArtifact(j)}>Restore</button>
            </li>))}
          </ul>
        )}
      </Modal>
      <ConfirmDialog open={!!artifact} onClose={() => setArtifact(null)} danger confirmText="RESTORE" title="Restore this backup?" confirmLabel="Restore" busy={busy}
        onConfirm={() => run({ path: '/restore-jobs', body: { artifact_id: artifact!.artifact_id ?? artifact!.artifact?.id }, label: 'Restore backup' }).then(() => { setArtifact(null); setRestoreOpen(false) }).catch(() => {})}>
        <p className="m-0">Current data for the target is replaced with the backup from {ago(artifact?.created_at)}.</p>
      </ConfirmDialog>
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
  { name: 'severity', label: 'Severity', type: 'select', required: true, options: ['info', 'warning', 'critical'] },
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
  const series = [{ label: 'CPU', color: 'var(--accent)', data: ((s.cpu ?? []) as unknown[]).map(num) }, { label: 'RAM', color: '#0D9488', data: ((s.ram ?? []) as unknown[]).map(num) }, { label: 'Disk', color: '#D97706', data: ((s.disk ?? []) as unknown[]).map(num) }]
  const toForm = (r: R) => ({ ...r, ...Object.fromEntries(((r.channels ?? r.channel_ids ?? []) as (string | R)[]).map((c) => [`ch_${typeof c === 'string' ? c : c.id}`, true])) })
  const fromForm = (v: Record<string, unknown>) => {
    const ids = Object.keys(v).filter((k) => k.startsWith('ch_') && v[k]).map((k) => k.slice(3))
    const body = Object.fromEntries(Object.entries(v).filter(([k]) => !k.startsWith('ch_')))
    return { ...body, channel_ids: ids }
  }
  return (
    <>
      <PageHeader title="Monitoring" subtitle="Metrics, alerts and alert rules"
        actions={<select className="input !w-auto" aria-label="Server filter" value={server} onChange={(e) => setServer(e.target.value)}><option value="">All servers</option>{servers.rows.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select>} />
      <Card title="Fleet resources" actions={<>
        {series.map((x) => <button key={x.label} type="button" className="chip !min-h-8 !text-xs" aria-pressed={on[x.label]} onClick={() => setOn({ ...on, [x.label]: !on[x.label] })}>{x.label}</button>)}
        <select className="input !w-auto !min-h-8 text-xs" aria-label="Range" value={range} onChange={(e) => setRange(e.target.value)}>{['1h', '6h', '24h', '7d'].map((r) => <option key={r}>{r}</option>)}</select>
      </>}>
        <LineChart label={`Fleet ${series.filter((x) => on[x.label]).map((x) => x.label).join(', ')} over ${range}`} series={series.filter((x) => on[x.label])} />
      </Card>
      <Card title="Active alerts" pad={false}>
        <DataTable label="Alerts" rows={alerts.rows} loading={alerts.isLoading} rowKey={(r) => r.id}
          filters={[{ id: 'critical', label: 'Critical', test: (r) => r.severity === 'critical' }, { id: 'unack', label: 'Not acknowledged', test: (r) => !r.acknowledged_at }]}
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
  const picker = <select className="input !w-auto" aria-label="Container" value={c?.id ?? ''} onChange={(e) => setCid(e.target.value)}>{containers.rows.map((x) => <option key={x.id} value={x.id}>{x.name} · {serverName(x as unknown as R)}</option>)}</select>
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
