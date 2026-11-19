import { useState } from 'react'
import { useAction, useOne } from '../api/hooks'
import { Card, Field } from './Card'
import { StatusBadge } from './Badge'
import { DataTable } from './DataTable'
import { Modal } from './Overlay'
import Select from './Select'
import { ago } from '../lib/format'
import { type R } from '../lib/util'

/**
 * PWS-G: the project's deploy jobs — progress, the failed stage and its error, retry of the newest
 * failure and rollback to an earlier release. `base` is '' (admin) or '/app'; `deploymentLink`
 * opens the full deployment page when the portal has one.
 */
export function ProjectDeployJobs({ projectId, base = '', deploymentLink }: { projectId: string; base?: string; deploymentLink?: (id: string) => string }) {
  const path = `${base}/projects/${projectId}/deploy-jobs`
  const q = useOne<R>(path, undefined, { refetchInterval: 5000 })
  const { run, busy } = useAction()
  const [open, setOpen] = useState<R | null>(null)
  const [rb, setRb] = useState<{ application_id: string; release_id: string } | null>(null)
  const d = q.item ?? {}
  const can = Boolean(d.can_deploy)
  const releases = (d.releases ?? []) as R[]
  const failedStage = (x: R) => ((x.stages ?? []) as R[]).find((s) => s.status === 'failed')
  return (
    <div className="flex flex-col gap-4">
      <Card pad={false}>
        <DataTable label="Deployments" tableId="project-deploy-jobs" layouts={false} rows={(d.deployments ?? []) as R[]} loading={q.isLoading} rowKey={(r) => String(r.id)}
          toolbar={can && releases.length > 0 ? <button type="button" className="btn" onClick={() => setRb({ application_id: String(releases[0].application_id), release_id: '' })}>Roll back…</button> : undefined}
          columns={[
            { key: 'app', header: 'Application', value: (x) => String(x.application ?? '') },
            { key: 'status', header: 'Status', value: (x) => String(x.status), render: (x) => <StatusBadge status={String(x.status)} /> },
            { key: 'progress', header: 'Progress', value: (x) => Number(x.progress ?? 0), render: (x) => <span className="flex items-center gap-2"><span className="h-1.5 w-20 rounded bg-track overflow-hidden" aria-hidden="true"><span className={`block h-full ${x.status === 'failed' ? 'bg-danger' : 'bg-accent'}`} style={{ width: `${Number(x.progress ?? 0)}%` }} /></span>{String(x.progress ?? 0)}%</span> },
            { key: 'source', header: 'Trigger', value: (x) => String(x.source) },
            { key: 'error', header: 'Failure', value: (x) => String(x.error ?? ''), render: (x) => x.error ? <span className="text-sm">{failedStage(x) ? <b>{String(failedStage(x)?.name)}: </b> : null}{String(x.error)}</span> : '—' },
            { key: 'when', header: 'Started', value: (x) => String(x.created_at ?? ''), render: (x) => ago(String(x.created_at)) },
          ]}
          actions={(x) => [
            { label: 'Details', icon: 'eye', onClick: () => setOpen(x) },
            ...(can && x.can_retry ? [{ label: 'Retry', icon: 'refresh', onClick: () => { run({ path: `${path}/${String(x.id)}/retry`, label: `Retry ${String(x.application)}`, success: 'Deployment restarted' }).then(() => q.refetch()).catch(() => {}) } }] : []),
          ]}
          empty="No deployments yet. Deployments of this project's apps appear here with their progress and failures." />
      </Card>
      <Modal open={!!open} onClose={() => setOpen(null)} title={`Deployment of ${String(open?.application ?? '')}`} subtitle={open ? `${String(open.status)} · ${String(open.progress ?? 0)}%` : undefined}
        footer={open && deploymentLink ? <a className="btn" href={deploymentLink(String(open.id))}>Open full page</a> : undefined}>
        {open && <div className="flex flex-col gap-3 text-sm">
          <ol className="m-0 pl-5">{((open.stages ?? []) as R[]).map((s, i) => <li key={i}><b>{String(s.name)}</b> <StatusBadge status={String(s.status)} /> {s.message ? <span className="text-muted">— {String(s.message)}</span> : null}</li>)}</ol>
          {open.error ? <p className="m-0 text-danger">{String(open.error)}</p> : null}
          {((open.logs_tail ?? []) as string[]).length > 0 && <pre className="m-0 max-h-64 overflow-auto rounded bg-code p-2 text-xs">{((open.logs_tail ?? []) as string[]).join('\n')}</pre>}
        </div>}
      </Modal>
      <Modal open={!!rb} onClose={() => setRb(null)} title="Roll back"
        footer={<button type="button" className="btn btn-primary" disabled={busy || !rb?.release_id} onClick={() => rb && run({ path: `${path}/rollback`, body: rb, label: 'Roll back', success: 'Rollback started' }).then(() => { setRb(null); q.refetch() }).catch(() => {})}>Roll back</button>}>
        {rb && <div className="flex flex-col gap-3">
          <Field label="Application"><Select className="input" value={rb.application_id} onChange={(e) => setRb({ application_id: e.target.value, release_id: '' })}>
            {[...new Map(((d.deployments ?? []) as R[]).map((x) => [String(x.application_id), String(x.application)])).entries()].map(([id, name]) => <option key={id} value={id}>{name}</option>)}
          </Select></Field>
          <Field label="Release"><Select className="input" value={rb.release_id} onChange={(e) => setRb({ ...rb, release_id: e.target.value })}>
            <option value="">Choose…</option>
            {releases.filter((r) => r.application_id === rb.application_id && r.available && !r.current).map((r) => <option key={String(r.id)} value={String(r.id)}>v{String(r.version)} · {ago(String(r.created_at))}</option>)}
          </Select></Field>
        </div>}
      </Modal>
    </div>
  )
}
