import { useState } from 'react'
import { useAction, useList, useOne } from '../../api/hooks'
import { Card, PageHeader, StatTile } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, Modal } from '../../components/Overlay'
import { ago } from '../../lib/format'
import type { R } from '../../lib/util'

const STATUS_TONE: Record<string, 'r' | 'y' | 'g' | 'n'> = { infected: 'r', quarantined: 'y', restored: 'n', deleted: 'g', cleaned: 'g', ignored: 'n' }
const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
// A WordPress core file the panel can replace with the official copy (AV-6); mirrors AntivirusService::CORE_FILE.
const CORE_FILE = /^(?:.*\/)?(?:wp-(?:admin|includes)\/.+|wp-[a-z-]+\.php|index\.php|xmlrpc\.php)$/
const scoreTone = (n: number) => (n >= 80 ? 'g' : n >= 50 ? 'y' : 'r') as 'g' | 'y' | 'r'

/** Antivirus (SRS 4.25): threats found by ClamAV and the panel's YARA rules, with quarantine. */
export default function Antivirus() {
  const summary = useOne<R>('/antivirus')
  const score = useOne<R>('/antivirus/score')
  const [scheduleOpen, setScheduleOpen] = useState(false)
  const [schedule, setSchedule] = useState<R | null>(null)
  const [status, setStatus] = useState('')
  const threats = useList<R>('/antivirus/threats', status ? { status } : undefined)
  const volumes = useList<R>('/volumes')
  const { run, busy } = useAction()
  const [volumeId, setVolumeId] = useState('')
  const [confirm, setConfirm] = useState<{ threat: R; action: string } | null>(null)
  const s = summary.item ?? {}

  const act = (threat: R, action: string) => run({
    path: `/antivirus/threats/${threat.id}/${action}`,
    label: `${{ quarantine: 'Quarantine', restore: 'Restore', delete: 'Delete', ignore: 'Not a threat', clean: 'Clean' }[action]} ${String(threat.path).split('/').pop()}`,
  }).then(() => { setConfirm(null); threats.refetch(); summary.refetch(); score.refetch() })
  const sc = score.item ?? {}
  const sched = (s.schedule as R | undefined) ?? {}
  const scheduleText = sched.schedule === 'off' ? 'Scheduled scans are off' : sched.schedule === 'daily' ? `Full scan daily at ${sched.time}` : sched.schedule ? `Full scan every ${DAYS[Number(sched.day)]} at ${sched.time}` : ''

  return (
    <>
      <PageHeader title="Antivirus" subtitle="Malware found by ClamAV and the panel's web-shell rules. New detections are quarantined automatically."
        actions={<>
          <select className="input h-10" aria-label="Volume to scan" value={volumeId} onChange={(e) => setVolumeId(e.target.value)}>
            <option value="">Choose a volume…</option>
            {volumes.rows.filter((v) => v.type !== 'nfs').map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
          </select>
          <button type="button" className="btn btn-primary" disabled={!volumeId || busy}
            onClick={() => run({ path: '/antivirus/scan', body: { volume_id: volumeId }, label: 'Antivirus scan' }).then(() => threats.refetch())}>Scan now</button>
        </>} />

      <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
        <StatTile label="Open threats" value={String(s.open ?? 0)} valueTone={Number(s.open) ? 'r' : 'g'} />
        <StatTile label="Quarantined" value={String(s.quarantined ?? 0)} valueTone={Number(s.quarantined) ? 'y' : 'n'} />
        <StatTile label="Resolved" value={String(s.resolved ?? 0)} />
        <StatTile label="Last detection" value={s.last_detected_at ? ago(String(s.last_detected_at)) : 'never'} />
        <StatTile label="Security score" value={sc.score !== undefined ? `${sc.score}/100` : '…'} valueTone={sc.score !== undefined ? scoreTone(Number(sc.score)) : 'n'} />
      </div>

      <Card title="Security score" actions={<span className="flex items-center gap-2 text-sm text-muted">{scheduleText}
        <button type="button" className="btn btn-sm" onClick={() => { setSchedule({ ...sched }); setScheduleOpen(true) }}>Scan schedule</button></span>}>
        {!((sc.volumes as R[] | undefined)?.length) ? <p className="m-0 text-sm text-muted">No volumes to score yet.</p> : (
          <ul className="m-0 p-0 list-none flex flex-col gap-3">
            {(sc.volumes as R[]).map((v) => (
              <li key={String(v.volume_id)} className="flex flex-wrap items-start gap-3">
                <Badge tone={scoreTone(Number(v.score))}>{String(v.score)}</Badge>
                <span className="min-w-0 flex-1">
                  <span className="font-semibold">{String(v.volume)}</span>
                  <span className="text-xs text-muted"> · {v.last_scan_at ? `scanned ${ago(String(v.last_scan_at))}` : 'never scanned'}</span>
                  {(v.fixes as R[]).length > 0 && <ul className="m-0 mt-1 pl-4 text-sm text-muted">{(v.fixes as R[]).map((f) => <li key={String(f.fix)}>{String(f.fix)} <span className="text-xs">(+{String(f.points)})</span></li>)}</ul>}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card title="Threats" pad={false} actions={
        <select className="input h-9" aria-label="Filter by status" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All</option>
          {Object.keys(STATUS_TONE).map((k) => <option key={k} value={k}>{k}</option>)}
        </select>
      }>
        <DataTable label="Threats" rows={threats.rows} loading={threats.isLoading} rowKey={(r) => r.id}
          columns={[
            { key: 'status', header: 'Status', render: (r) => <Badge tone={STATUS_TONE[String(r.status)] ?? 'n'}>{String(r.status)}</Badge> },
            { key: 'path', header: 'File', render: (r) => (
              <span className="flex flex-col">
                <span className="font-mono text-xs break-all">{String(r.path)}</span>
                <span className="text-xs text-muted">{String(r.volume)} · {String(r.server ?? '')}</span>
              </span>
            ) },
            { key: 'signature', header: 'Detected as', render: (r) => <span className="font-mono text-xs">{String(r.signature)}</span> },
            { key: 'detected_at', header: 'Found', value: (r) => String(r.detected_at), render: (r) => ago(String(r.detected_at)) },
            { key: 'actions', header: '', sortable: false, render: (r) => (
              <span className="flex flex-wrap gap-1 justify-end">
                {['infected', 'restored'].includes(String(r.status)) && <button type="button" className="btn btn-sm" disabled={busy} onClick={() => act(r, 'quarantine')}>Quarantine</button>}
                {['infected', 'quarantined', 'restored'].includes(String(r.status)) && CORE_FILE.test(String(r.path)) && <button type="button" className="btn btn-sm" disabled={busy} onClick={() => setConfirm({ threat: r, action: 'clean' })}>Clean</button>}
                {r.status === 'quarantined' && <button type="button" className="btn btn-sm" disabled={busy} onClick={() => setConfirm({ threat: r, action: 'restore' })}>Restore</button>}
                {r.status === 'quarantined' && <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => setConfirm({ threat: r, action: 'delete' })}>Delete</button>}
                {['infected', 'quarantined', 'restored'].includes(String(r.status)) && <button type="button" className="btn btn-sm" disabled={busy} onClick={() => setConfirm({ threat: r, action: 'ignore' })}>Not a threat</button>}
              </span>
            ) },
          ]} />
      </Card>

      <ConfirmDialog open={!!confirm} onClose={() => setConfirm(null)} busy={busy} danger={confirm?.action === 'delete'}
        title={{ restore: 'Restore this file?', delete: 'Delete this file for good?', ignore: 'Mark as not a threat?', clean: 'Replace with the official WordPress file?' }[confirm?.action ?? 'restore'] ?? ''}
        confirmLabel={{ restore: 'Restore', delete: 'Delete', ignore: 'Not a threat', clean: 'Clean' }[confirm?.action ?? 'restore']}
        onConfirm={() => confirm && act(confirm.threat, confirm.action)}>
        <p className="m-0 text-sm">
          {confirm?.action === 'restore' && 'The file goes back where it was and can run again. Only restore it if you are sure it is safe.'}
          {confirm?.action === 'delete' && 'The quarantined file is removed permanently. This cannot be undone.'}
          {confirm?.action === 'clean' && 'The panel reads the site\'s WordPress version, downloads this file from WordPress.org, checks it against the published checksum and writes it in place. A quarantined copy stays in quarantine.'}
          {confirm?.action === 'ignore' && 'Files with this exact content will not be flagged again in this organisation, and a quarantined copy is restored. The decision is recorded in the audit log.'}
        </p>
        <p className="mt-2 mb-0 font-mono text-xs break-all">{String(confirm?.threat.path ?? '')}</p>
      </ConfirmDialog>

      <Modal open={scheduleOpen} onClose={() => setScheduleOpen(false)} title="Scan schedule"
        footer={<button type="button" className="btn btn-primary" disabled={busy || !schedule}
          onClick={() => schedule && run({ path: '/settings', method: 'PATCH', body: { antivirus: { schedule: schedule.schedule, day: Number(schedule.day), time: schedule.time, notify: !!schedule.notify } }, label: 'Scan schedule', success: 'Scan schedule saved' })
            .then(() => { setScheduleOpen(false); summary.refetch(); score.refetch() })}>Save</button>}>
        {schedule && <div className="flex flex-col gap-3">
          <label className="label">Full scans
            <select className="input" value={String(schedule.schedule)} onChange={(e) => setSchedule({ ...schedule, schedule: e.target.value })}>
              <option value="weekly">Weekly</option><option value="daily">Daily</option><option value="off">Off</option>
            </select>
          </label>
          {schedule.schedule === 'weekly' && <label className="label">Day
            <select className="input" value={String(schedule.day)} onChange={(e) => setSchedule({ ...schedule, day: Number(e.target.value) })}>
              {DAYS.map((d, i) => <option key={d} value={i}>{d}</option>)}
            </select>
          </label>}
          {schedule.schedule !== 'off' && <label className="label">Time (server time)<input className="input" type="time" value={String(schedule.time)} onChange={(e) => setSchedule({ ...schedule, time: e.target.value })} /></label>}
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!schedule.notify} onChange={(e) => setSchedule({ ...schedule, notify: e.target.checked })} />Send new detections to the notification channels</label>
        </div>}
      </Modal>
    </>
  )
}
