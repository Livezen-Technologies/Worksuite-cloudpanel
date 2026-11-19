import Select from '../../components/Select'
import { useState } from 'react'
import { useAction, useList, useOne } from '../../api/hooks'
import { Card, Empty, Toggle } from '../../components/Card'
import { Badge, StatusBadge } from '../../components/Badge'
import { Modal } from '../../components/Overlay'
import { ago } from '../../lib/format'
import { num, type R } from '../../lib/util'

const fmtMb = (mb: number) => mb >= 1024 ? `${(mb / 1024).toFixed(1)} GB` : `${mb.toFixed(mb < 10 ? 1 : 0)} MB`
const BACKUP_METRICS = ['backup_failed', 'backup_overdue']
const local = (t?: string) => t ? new Date(t).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—'
const duration = (a?: string, b?: string) => {
  if (!a || !b) return '—'
  const s = Math.max(0, Math.round((new Date(b).getTime() - new Date(a).getTime()) / 1000))
  return s >= 60 ? `${Math.floor(s / 60)} min ${s % 60} s` : `${s} s`
}

/** Backup manager → Logs: every backup run with its status, size, error and log, plus the backup alert rules. */
export function BackupLogs({ policyName }: { policyName: (id?: string) => string }) {
  const [status, setStatus] = useState('')
  const jobs = useList<R>('/backup-jobs', { per_page: 200, sort: '-created_at', ...(status ? { status } : {}) }, { refetchInterval: 30000 })
  const rules = useList<R>('/alert-rules', { per_page: 100 })
  const alerts = useList<R>('/alerts', { per_page: 50 }, { refetchInterval: 60000 })
  const [open, setOpen] = useState<string | null>(null)
  const detail = useOne<R>(open ? `/backup-jobs/${open}/log` : null)
  const { run } = useAction()
  const backupRules = rules.rows.filter((r) => BACKUP_METRICS.includes(String(r.metric)))
  const firing = alerts.rows.filter((a) => backupRules.some((r) => r.id === a.alert_rule_id) && !a.resolved_at)
  const d = detail.item ?? {}

  return (
    <div className="flex flex-col gap-3">
      <Card>
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex-1 min-w-[220px]">
            <div className="font-bold">Backup alerts</div>
            <div className="text-xs text-muted">Sent to the organisation’s notification channels and email. A failed run also emails right away.</div>
          </div>
          {firing.length ? <Badge tone="r">{firing.length} open</Badge> : <Badge tone="g">all clear</Badge>}
        </div>
        {rules.isLoading ? <p className="m-0 mt-3 text-sm text-muted">Loading…</p> : !backupRules.length ? <p className="m-0 mt-3 text-sm text-muted">No backup alert rules. Add them under Monitoring → Alerts.</p> :
          <ul className="list-none m-0 mt-3 p-0 flex flex-col gap-2">{backupRules.map((r) => (
            <li key={r.id} className="flex items-center gap-3 text-[13.5px]">
              <span className="flex-1 min-w-0 truncate">{r.name}</span>
              <Toggle checked={r.enabled !== false} label={`${r.enabled !== false ? 'Disable' : 'Enable'} ${r.name}`}
                onChange={(v) => run({ method: 'PATCH', path: `/alert-rules/${r.id}`, body: { enabled: v }, label: 'Update alert', success: v ? 'Alert on' : 'Alert off' }).catch(() => {})} />
            </li>))}</ul>}
        {firing.length ? <ul className="list-none m-0 mt-3 p-0 flex flex-col gap-1.5">{firing.map((a) => (
          <li key={a.id} className="text-[13px] text-[var(--r-fg)]">{a.title} · {ago(a.created_at)}</li>))}</ul> : null}
      </Card>

      <Card pad={false}>
        <div className="flex flex-wrap items-center gap-3 px-4 sm:px-5 py-3">
          <div className="font-bold flex-1">Backup runs</div>
          <Select className="input !w-auto" aria-label="Filter runs by status" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All runs</option><option value="succeeded">Succeeded</option><option value="failed">Failed</option><option value="running">Running</option>
          </Select>
        </div>
        {jobs.isLoading ? <p className="px-5 pb-4 m-0 text-sm text-muted">Loading…</p> : !jobs.rows.length ? <div className="px-5 pb-5"><Empty>No backup runs yet.</Empty></div> :
          <ul className="list-none m-0 p-0">{jobs.rows.map((j) => {
            const arts = (j.artifacts ?? []) as R[]
            const size = arts.reduce((t, a) => t + num(a.size_mb), 0)
            return (
              <li key={j.id}>
                <button type="button" className="w-full text-left flex flex-wrap items-center gap-3 px-4 sm:px-5 py-3 border-t border-line text-[13.5px] hover:bg-sel" onClick={() => setOpen(j.id)}>
                  <span className="flex-[1_1_220px] min-w-0">
                    <span className="block font-semibold truncate">{policyName(j.backup_policy_id)}</span>
                    <span className="block text-xs text-muted truncate">{local(j.started_at ?? j.created_at)} · {j.trigger}{j.error ? ` · ${j.error}` : ''}</span>
                  </span>
                  <span className="text-muted min-w-[80px]">{duration(j.started_at, j.finished_at)}</span>
                  <span className="min-w-[90px] tabular-nums">{arts.length ? `${arts.length} file${arts.length > 1 ? 's' : ''} · ${fmtMb(size)}` : '—'}</span>
                  <StatusBadge status={j.status} />
                </button>
              </li>)
          })}</ul>}
      </Card>

      <Modal open={!!open} onClose={() => setOpen(null)} title="Backup run" width={760}>
        {detail.isLoading ? <p className="m-0 text-sm text-muted">Loading…</p> :
          <div className="flex flex-col gap-3 text-sm">
            <div className="flex flex-wrap gap-x-6 gap-y-1">
              <span><span className="text-muted">Status</span> <StatusBadge status={d.status} /></span>
              <span><span className="text-muted">Started</span> {local(d.started_at)}</span>
              <span><span className="text-muted">Took</span> {duration(d.started_at, d.finished_at)}</span>
            </div>
            {d.error ? <p className="m-0 text-[var(--r-fg)] break-words">{d.error}</p> : null}
            {((d.artifacts ?? []) as R[]).length ? <ul className="list-none m-0 p-0 flex flex-col gap-1">{((d.artifacts ?? []) as R[]).map((a) => (
              <li key={a.id} className="flex flex-wrap gap-2 text-[13px]"><span className="font-mono truncate flex-1 min-w-0" title={a.repository}>{a.snapshot_id}</span><span className="tabular-nums">{fmtMb(num(a.size_mb))}</span></li>))}</ul> : null}
            {d.log ? <pre className="m-0 p-3 rounded-lg bg-sel text-xs font-mono overflow-auto max-h-[360px] whitespace-pre-wrap">{String(d.log)}</pre> : <p className="m-0 text-xs text-muted">No log was kept for this run.</p>}
          </div>}
      </Modal>
    </div>
  )
}
