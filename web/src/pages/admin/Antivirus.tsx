import { useState } from 'react'
import { useAction, useList, useOne } from '../../api/hooks'
import { Card, PageHeader, StatTile, Field } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, Modal } from '../../components/Overlay'
import { ago } from '../../lib/format'
import type { R } from '../../lib/util'
import Select from '../../components/Select'

const STATUS_TONE: Record<string, 'r' | 'y' | 'g' | 'n'> = { infected: 'r', quarantined: 'y', restored: 'n', deleted: 'g', cleaned: 'g', ignored: 'n' }
const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
// A WordPress core file the panel can replace with the official copy (AV-6); mirrors AntivirusService::CORE_FILE.
const CORE_FILE = /^(?:.*\/)?(?:wp-(?:admin|includes)\/.+|wp-[a-z-]+\.php|index\.php|xmlrpc\.php)$/
const scoreTone = (n: number) => (n >= 80 ? 'g' : n >= 50 ? 'y' : 'r') as 'g' | 'y' | 'r'

/** Antivirus (SRS 4.25): threats found by ClamAV and the panel's YARA rules, with quarantine. */
export default function Antivirus() {
  const summary = useOne<R>('/antivirus')
  const score = useOne<R>('/antivirus/score')
  const integrity = useOne<R>('/antivirus/integrity')
  const servers = useList<R>('/servers')
  const [serverId, setServerId] = useState('')
  const security = useOne<R>(serverId ? `/antivirus/servers/${serverId}` : null, undefined, { retry: false })
  const sec = security.item
  const setSecurity = (body: R, label: string) => run({ path: `/antivirus/servers/${serverId}`, method: 'PATCH', body, label }).then(() => security.refetch())
  const fim = (volumeId: string, action: 'baseline' | 'check' | 'accept', name: string) => run({
    path: `/antivirus/integrity/${volumeId}/${action}`, label: `${{ baseline: 'Baseline', check: 'Integrity check', accept: 'Accept changes' }[action]} for ${name}`,
  }).then(() => { integrity.refetch(); score.refetch() })
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
          <Select className="input h-10" aria-label="Volume to scan" value={volumeId} onChange={(e) => setVolumeId(e.target.value)}>
            <option value="">Choose a volume…</option>
            {volumes.rows.filter((v) => v.type !== 'nfs').map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
          </Select>
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

      <Card title="Server protection" actions={
        <Select className="input h-9" aria-label="Server" value={serverId} onChange={(e) => setServerId(e.target.value)}>
          <option value="">Choose a server…</option>
          {servers.rows.map((sv) => <option key={sv.id} value={sv.id}>{sv.name}</option>)}
        </Select>}>
        {!serverId ? <p className="m-0 text-sm text-muted">Real-time scanning and outbound abuse limits are set per server by platform administrators.</p>
        : security.error ? <p className="m-0 text-sm text-danger">{(security.error as Error).message}</p>
        : !sec ? <p className="m-0 text-sm text-muted">Loading…</p>
        : (
          <div className="flex flex-col gap-3 text-sm">
            <label className="flex items-start gap-2"><input type="checkbox" className="mt-1" checked={!!sec.realtime} disabled={busy} onChange={(e) => setSecurity({ realtime: e.target.checked }, 'Real-time scanning')} />
              <span><span className="font-semibold">Real-time scanning</span><br /><span className="text-muted">Files written to this server's volumes are scanned as they are written. Detections appear as threats within 5 minutes.</span></span></label>
            <label className="flex items-start gap-2"><input type="checkbox" className="mt-1" checked={!!(sec.egress as R)?.enabled} disabled={busy} onChange={(e) => setSecurity({ egress: { enabled: e.target.checked } }, 'Outbound limits')} />
              <span><span className="font-semibold">Outbound abuse limits</span><br /><span className="text-muted">Stops sites on this server from reaching crypto-mining pools and from flooding mail servers.</span></span></label>
            {!!(sec.egress as R)?.enabled && <div className="flex flex-wrap gap-4 pl-6">
              <label className="flex items-center gap-2"><input type="checkbox" checked={!!(sec.egress as R).block_mining} disabled={busy} onChange={(e) => setSecurity({ egress: { block_mining: e.target.checked } }, 'Mining block')} />Block mining pools</label>
              <label className="flex items-center gap-2">New SMTP connections per minute, per site
                <input className="input h-9 w-24" type="number" min={0} max={10000} defaultValue={Number((sec.egress as R).smtp_per_minute)} key={String((sec.egress as R).smtp_per_minute)}
                  onBlur={(e) => { const n = Number(e.target.value); if (n !== Number((sec.egress as R).smtp_per_minute)) setSecurity({ egress: { smtp_per_minute: n } }, 'SMTP limit') }} />
                <span className="text-xs text-muted">0 = no limit</span></label>
            </div>}
          </div>
        )}
      </Card>

      <Card title="File integrity" pad={false} actions={<span className="text-sm text-muted">Code files changed outside a deployment. A baseline is taken after every deployment.</span>}>
        <DataTable label="File integrity" rows={((integrity.item?.volumes as R[]) ?? [])} loading={integrity.isLoading} rowKey={(r) => r.id}
          actions={(r) => [
            ...(!r.baseline_at ? [{ label: `Take baseline of ${r.name}`, icon: 'bookmark', disabled: busy, onClick: () => fim(String(r.id), 'baseline', String(r.name)) }] : [{ label: `Check ${r.name} now`, icon: 'refresh', disabled: busy, onClick: () => fim(String(r.id), 'check', String(r.name)) }]),
            ...(Number(r.open) > 0 ? [{ label: 'Accept changes', icon: 'check', menuOnly: true, disabled: busy, confirm: `Accept the ${r.open} changes on ${r.name} as the new baseline? Only do this if you made them.`, onClick: () => fim(String(r.id), 'accept', String(r.name)) }] : []),
          ]}
          columns={[
            { key: 'name', header: 'Volume' },
            { key: 'open', header: 'Changes', render: (r) => Number(r.open) ? <Badge tone="r">{String(r.open)} to review</Badge> : r.baseline_at ? <Badge tone="g">none</Badge> : <Badge tone="n">no baseline</Badge> },
            { key: 'checked_at', header: 'Last check', render: (r) => r.checked_at ? ago(String(r.checked_at)) : '—' },
          ]} />
        {((integrity.item?.changes as R[]) ?? []).length > 0 && (
          <ul className="m-0 px-4 py-3 border-t border-line list-none flex flex-col gap-1 text-sm">
            {(integrity.item!.changes as R[]).slice(0, 50).map((c) => (
              <li key={String(c.id)} className="flex flex-wrap gap-2 items-baseline">
                <Badge tone={c.change === 'removed' ? 'n' : 'y'}>{String(c.change)}</Badge>
                <span className="font-mono text-xs break-all">{String(c.path)}</span>
                <span className="text-xs text-muted">{String(c.volume)} · {ago(String(c.detected_at))}</span>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card title="Threats" pad={false} actions={
        <Select className="input h-9" aria-label="Filter by status" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All</option>
          {Object.keys(STATUS_TONE).map((k) => <option key={k} value={k}>{k}</option>)}
        </Select>
      }>
        <DataTable label="Threats" rows={threats.rows} loading={threats.isLoading} rowKey={(r) => r.id}
          actions={(r) => {
            const st = String(r.status), live = ['infected', 'quarantined', 'restored'].includes(st)
            return [
              ...(['infected', 'restored'].includes(st) ? [{ label: 'Quarantine', icon: 'archive', disabled: busy, onClick: () => act(r, 'quarantine') }] : []),
              ...(live && CORE_FILE.test(String(r.path)) ? [{ label: 'Clean', icon: 'shield', disabled: busy, onClick: () => setConfirm({ threat: r, action: 'clean' }) }] : []),
              ...(st === 'quarantined' ? [{ label: 'Restore', icon: 'refresh', disabled: busy, onClick: () => setConfirm({ threat: r, action: 'restore' }) }] : []),
              ...(live ? [{ label: 'Not a threat', icon: 'check', menuOnly: true, disabled: busy, onClick: () => setConfirm({ threat: r, action: 'ignore' }) }] : []),
              ...(st === 'quarantined' ? [{ label: 'Delete', icon: 'trash', danger: true, confirm: false, disabled: busy, onClick: () => setConfirm({ threat: r, action: 'delete' }) }] : []),
            ]
          }}
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
          <Field label="Full scans">
            <Select className="input" value={String(schedule.schedule)} onChange={(e) => setSchedule({ ...schedule, schedule: e.target.value })}>
              <option value="weekly">Weekly</option><option value="daily">Daily</option><option value="off">Off</option>
            </Select>
          </Field>
          {schedule.schedule === 'weekly' && <Field label="Day">
            <Select className="input" value={String(schedule.day)} onChange={(e) => setSchedule({ ...schedule, day: Number(e.target.value) })}>
              {DAYS.map((d, i) => <option key={d} value={i}>{d}</option>)}
            </Select>
          </Field>}
          {schedule.schedule !== 'off' && <Field label="Time (server time)"><input className="input" type="time" value={String(schedule.time)} onChange={(e) => setSchedule({ ...schedule, time: e.target.value })} /></Field>}
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!schedule.notify} onChange={(e) => setSchedule({ ...schedule, notify: e.target.checked })} />Send new detections to the notification channels</label>
        </div>}
      </Modal>
    </>
  )
}
