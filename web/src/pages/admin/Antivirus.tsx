import { useState } from 'react'
import { useAction, useList, useOne } from '../../api/hooks'
import { Card, PageHeader, StatTile } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog } from '../../components/Overlay'
import { ago } from '../../lib/format'
import type { R } from '../../lib/util'

const STATUS_TONE: Record<string, 'r' | 'y' | 'g' | 'n'> = { infected: 'r', quarantined: 'y', restored: 'n', deleted: 'g', ignored: 'n' }

/** Antivirus (SRS 4.25): threats found by ClamAV and the panel's YARA rules, with quarantine. */
export default function Antivirus() {
  const summary = useOne<R>('/antivirus')
  const [status, setStatus] = useState('')
  const threats = useList<R>('/antivirus/threats', status ? { status } : undefined)
  const volumes = useList<R>('/volumes')
  const { run, busy } = useAction()
  const [volumeId, setVolumeId] = useState('')
  const [confirm, setConfirm] = useState<{ threat: R; action: string } | null>(null)
  const s = summary.item ?? {}

  const act = (threat: R, action: string) => run({
    path: `/antivirus/threats/${threat.id}/${action}`,
    label: `${{ quarantine: 'Quarantine', restore: 'Restore', delete: 'Delete', ignore: 'Not a threat' }[action]} ${String(threat.path).split('/').pop()}`,
  }).then(() => { setConfirm(null); threats.refetch(); summary.refetch() })

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
      </div>

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
                {r.status === 'quarantined' && <button type="button" className="btn btn-sm" disabled={busy} onClick={() => setConfirm({ threat: r, action: 'restore' })}>Restore</button>}
                {r.status === 'quarantined' && <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => setConfirm({ threat: r, action: 'delete' })}>Delete</button>}
                {['infected', 'quarantined', 'restored'].includes(String(r.status)) && <button type="button" className="btn btn-sm" disabled={busy} onClick={() => setConfirm({ threat: r, action: 'ignore' })}>Not a threat</button>}
              </span>
            ) },
          ]} />
      </Card>

      <ConfirmDialog open={!!confirm} onClose={() => setConfirm(null)} busy={busy} danger={confirm?.action === 'delete'}
        title={{ restore: 'Restore this file?', delete: 'Delete this file for good?', ignore: 'Mark as not a threat?' }[confirm?.action ?? 'restore'] ?? ''}
        confirmLabel={{ restore: 'Restore', delete: 'Delete', ignore: 'Not a threat' }[confirm?.action ?? 'restore']}
        onConfirm={() => confirm && act(confirm.threat, confirm.action)}>
        <p className="m-0 text-sm">
          {confirm?.action === 'restore' && 'The file goes back where it was and can run again. Only restore it if you are sure it is safe.'}
          {confirm?.action === 'delete' && 'The quarantined file is removed permanently. This cannot be undone.'}
          {confirm?.action === 'ignore' && 'Files with this exact content will not be flagged again in this organisation, and a quarantined copy is restored. The decision is recorded in the audit log.'}
        </p>
        <p className="mt-2 mb-0 font-mono text-xs break-all">{String(confirm?.threat.path ?? '')}</p>
      </ConfirmDialog>
    </>
  )
}
