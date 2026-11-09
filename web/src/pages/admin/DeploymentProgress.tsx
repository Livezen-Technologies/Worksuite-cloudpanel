import { useEffect, useRef, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router'
import { useAction, useJob, useOne } from '../../api/hooks'
import type { JobStage } from '../../api/types'
import { Card, PageHeader, Loading, Empty } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { CopyButton } from '../../components/CopyButton'
import { Icon } from '../../components/Icon'
import { JobTimeline } from '../../components/JobTimeline'
import { type R } from '../../lib/util'

/**
 * Follows one deployment as it runs: stages, build log, health check, then the address. Failed or
 * cancelled deployments offer a retry, which starts a new deployment and follows that one.
 */
export default function DeploymentProgress() {
  const { id = '' } = useParams()
  const [params] = useSearchParams()
  const nav = useNavigate()
  const { item: d, isLoading } = useOne<R>(`/deployments/${id}`)
  const { job } = useJob(d?.job_id)
  const { run, busy } = useAction()
  const status = String(job?.status ?? d?.status ?? 'queued')
  const state: 'running' | 'succeeded' | 'failed' | 'waiting' = status === 'succeeded' ? 'succeeded' : ['failed', 'cancelled'].includes(status) ? 'failed' : status === 'awaiting_approval' ? 'waiting' : 'running'
  const url = (job?.result as { url?: string } | undefined)?.url ?? params.get('url')
  const log = (job?.build_log ?? []) as string[]
  const pre = useRef<HTMLPreElement>(null)
  const [follow, setFollow] = useState(true)
  useEffect(() => { if (follow && pre.current) pre.current.scrollTop = pre.current.scrollHeight }, [log.length, follow])
  if (isLoading) return <Loading label="Loading deployment…" />
  if (!d) return <Card><Empty>Deployment not found.</Empty></Card>
  const name = String(d.application ?? 'application')
  const retry = async () => {
    const r = await run<{ data?: { id?: string } }>({ path: '/deployments', body: { application_id: d.application_id }, label: `Redeploy ${name}`, success: 'Deployment restarted', silent: true }).catch(() => null)
    if (r?.data?.id) nav(`/admin/deployments/${r.data.id}${url ? `?url=${encodeURIComponent(url)}` : ''}`, { replace: true })
  }
  const head = {
    running: { tone: 'b' as const, badge: 'Deploying', title: `Deploying ${name}…`, text: 'Building or pulling the image, starting the container and waiting for its health check. You can leave this page; it keeps running.' },
    waiting: { tone: 'y' as const, badge: 'Awaiting approval', title: `${name} is waiting for approval`, text: 'A production deployment needs approval before it starts. An approver can approve it on the Applications page.' },
    succeeded: { tone: 'g' as const, badge: 'Running', title: `${name} is live`, text: 'The deployment passed its health check and is serving traffic.' },
    failed: { tone: 'r' as const, badge: status === 'cancelled' ? 'Cancelled' : 'Failed', title: `${name} did not deploy`, text: 'The steps and build log below show where it stopped. Nothing else was changed; fix the cause and retry.' },
  }[state]
  return (
    <>
      <PageHeader title={`Deployment · ${name}`} subtitle={`Started ${d.created_at ? new Date(String(d.created_at)).toLocaleString() : ''}`}
        actions={<><button type="button" className="btn" onClick={() => nav(`/admin/applications/${d.application_id}`)}>Open application</button>
          {state === 'running' && <button type="button" className="btn" disabled={busy} onClick={() => { if (window.confirm('Cancel this deployment?')) run({ path: `/deployments/${d.id}/cancel`, label: 'Cancel deployment', success: 'Deployment cancelled' }).catch(() => {}) }}>Cancel</button>}
          {state === 'failed' && <button type="button" className="btn btn-primary" disabled={busy} onClick={retry}>Retry deployment</button>}</>} />
      <Card>
        <div className="flex flex-col gap-5" aria-live="polite">
          <div className="flex items-center gap-4">
            <span className={`grid place-items-center w-14 h-14 rounded-xl ${{ g: 'bg-g-bg text-g-fg', b: 'bg-b-bg text-b-fg', r: 'bg-r-bg text-r-fg', y: 'bg-y-bg text-y-fg' }[head.tone]}`} aria-hidden="true">
              <Icon name={state === 'succeeded' ? 'check' : state === 'failed' ? 'warn' : state === 'waiting' ? 'clock' : 'refresh'} size={26} className={state === 'running' ? 'animate-spin' : ''} />
            </span>
            <div className="flex-1 min-w-0"><div className="text-lg font-semibold">{head.title}</div><div className="text-sm text-muted">{head.text}</div></div>
            <Badge tone={head.tone}>{head.badge}</Badge>
          </div>
          <div className="flex items-center gap-2 rounded-lg border border-line p-3">
            <span className="flex-1 min-w-0 truncate text-sm font-medium">{url ?? 'Internal only (no public address)'}</span>
            {url && <CopyButton value={url} />}
            {url && state === 'succeeded' && <a className="btn btn-sm no-underline" href={url} target="_blank" rel="noreferrer">Open</a>}
          </div>
          {url && state === 'running' && <p className="m-0 text-xs text-muted">The address answers once DNS, HTTPS and the health check are ready.</p>}
        </div>
      </Card>
      <Card title="Progress"><JobTimeline job={job} stages={job?.stages?.length ? job.stages : (d.stages as JobStage[] | undefined)} /></Card>
      {log.length > 0 && (
        <Card title="Build log" actions={<label className="text-xs flex items-center gap-1"><input type="checkbox" checked={follow} onChange={(e) => setFollow(e.target.checked)} /> Follow</label>}>
          <pre ref={pre} aria-label="Build log" className="m-0 p-3 rounded-lg bg-head font-mono text-xs overflow-auto max-h-96 whitespace-pre-wrap">{log.map((l, i) => <div key={i} style={/^ERROR|error/.test(l) ? { color: 'var(--r-fg)' } : undefined}>{l}</div>)}</pre>
        </Card>
      )}
    </>
  )
}
