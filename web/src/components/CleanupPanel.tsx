import { useState } from 'react'
import { useAction, useOne } from '../api/hooks'
import { Card, Field, Toggle } from './Card'
import { StatusBadge } from './Badge'
import { DataTable } from './DataTable'
import { ConfirmDialog } from './Overlay'
import Select from './Select'
import { ago } from '../lib/format'
import { type R } from '../lib/util'

type Policy = { auto: boolean; level: string; disk_threshold: number; keep_releases: number; log_max_mb: number }
type Page = { policy: Policy; levels: string[]; disk_pct: number | null; protected: string[]; runs: R[] }

const LEVEL_HELP: Record<string, string> = {
  safe: 'Stopped unmanaged containers, dangling images and build cache older than a day',
  standard: 'Safe, plus unused images older than 7 days and all unused build cache',
  aggressive: 'Standard, plus every unused image. Volumes are never touched',
}

/** CLN-1..5: cleanup policy, protected releases, manual runs and history for one server. */
export function CleanupPanel({ serverId }: { serverId: string }) {
  const path = `/servers/${serverId}/cleanup`
  const page = useOne<Page>(path, undefined, { refetchInterval: 10000 })
  const { run, busy } = useAction()
  const [level, setLevel] = useState('safe')
  const [confirm, setConfirm] = useState(false)
  const p = page.item
  if (!p) return <Card>Loading cleanup…</Card>
  const save = (body: Partial<Policy>) => run({ path, method: 'PATCH', body, label: 'Save cleanup policy', success: 'Cleanup policy saved' }).catch(() => {})
  const start = (dry: boolean) => run({ path, body: { level, dry_run: dry }, label: dry ? 'Preview cleanup' : 'Run cleanup', success: dry ? 'Preview started' : 'Cleanup started' }).catch(() => {})
  return (
    <div className="flex flex-col gap-4">
      <Card title="Automatic cleanup">
        <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
          <Field label="Run when disk is full"><Toggle checked={p.policy.auto} onChange={(v) => save({ auto: v })} label="Automatic cleanup" /></Field>
          <Field label="Disk threshold" hint={`Now ${p.disk_pct ?? '—'}%`}>
            <Select className="input" value={String(p.policy.disk_threshold)} onChange={(e) => save({ disk_threshold: Number(e.target.value) })}>
              {[70, 75, 80, 85, 90, 95].map((n) => <option key={n} value={n}>{n}%</option>)}
            </Select>
          </Field>
          <Field label="Level" hint={LEVEL_HELP[p.policy.level]}>
            <Select className="input" value={p.policy.level} onChange={(e) => save({ level: e.target.value })}>{p.levels.map((l) => <option key={l} value={l}>{l}</option>)}</Select>
          </Field>
          <Field label="Releases kept per app" hint="Their images are never removed">
            <Select className="input" value={String(p.policy.keep_releases)} onChange={(e) => save({ keep_releases: Number(e.target.value) })}>
              {[1, 2, 3, 5, 10].map((n) => <option key={n} value={n}>{n}</option>)}
            </Select>
          </Field>
          <Field label="Container logs" hint="Panel-made containers rotate at 10 MB × 3; this empties any other log above the size on each cleanup">
            <Select className="input" value={String(p.policy.log_max_mb ?? 100)} onChange={(e) => save({ log_max_mb: Number(e.target.value) })}>
              <option value={0}>Leave logs alone</option>
              {[50, 100, 250, 500, 1024].map((n) => <option key={n} value={n}>Empty above {n >= 1024 ? '1 GB' : `${n} MB`}</option>)}
            </Select>
          </Field>
        </div>
      </Card>
      <Card title="Run now">
        <div className="flex flex-wrap items-end gap-3">
          <Field label="Level" hint={LEVEL_HELP[level]}><Select className="input" value={level} onChange={(e) => setLevel(e.target.value)}>{p.levels.map((l) => <option key={l} value={l}>{l}</option>)}</Select></Field>
          <button type="button" className="btn" disabled={busy} onClick={() => start(true)}>Preview</button>
          <button type="button" className="btn btn-primary" disabled={busy} onClick={() => setConfirm(true)}>Clean up</button>
        </div>
        {p.protected.length > 0 && <p className="text-xs text-muted mt-3 mb-0">Protected: <span className="font-mono">{p.protected.join(', ')}</span></p>}
      </Card>
      <DataTable label="Cleanup history" rows={p.runs} rowKey={(r) => String(r.id)} columns={[
        { key: 'created_at', header: 'When', render: (r) => ago(r.created_at) },
        { key: 'trigger', header: 'Trigger' },
        { key: 'level', header: 'Level' },
        { key: 'disk_pct', header: 'Disk', render: (r) => (r.disk_pct != null ? `${r.disk_pct}%` : '—') },
        { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
        { key: 'message', header: 'Result', render: (r) => <span className="text-xs">{r.message ?? ''}</span> },
      ]} />
      <ConfirmDialog open={confirm} onClose={() => setConfirm(false)} onConfirm={() => { setConfirm(false); start(false) }}
        title={`Run a ${level} cleanup?`} confirmLabel="Clean up">{LEVEL_HELP[level]}. Images of the last {p.policy.keep_releases} releases of each app stay.</ConfirmDialog>
    </div>
  )
}
