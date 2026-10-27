import type { Job, JobStage } from '../api/types'
import { Badge, toneFor } from './Badge'

function dur(s: JobStage): string {
  if (!s.started_at) return ''
  const end = s.finished_at ? new Date(s.finished_at).getTime() : Date.now()
  const sec = Math.max(0, Math.round((end - new Date(s.started_at).getTime()) / 1000))
  return `${String(Math.floor(sec / 60)).padStart(2, '0')}:${String(sec % 60).padStart(2, '0')}`
}

/** Vertical pipeline timeline of a job's stages with per-stage status and duration. */
export function JobTimeline({ job, compact = false, stages: given }: { job?: Job; compact?: boolean; stages?: JobStage[] }) {
  const stages = given ?? job?.stages ?? []
  if (!job && !given) return <div className="text-xs text-muted">Waiting for the job to start…</div>
  return (
    <div>
      {job && (
        <div className="flex items-center gap-2 mb-2">
          <Badge tone={toneFor(job.status)}>{job.status}</Badge>
          <div className="flex-1 h-1.5 rounded bg-track overflow-hidden" role="progressbar" aria-valuenow={job.progress} aria-valuemin={0} aria-valuemax={100} aria-label="Progress">
            <div className="h-full bg-accent" style={{ width: `${job.progress ?? 0}%` }} />
          </div>
          <span className="text-xs text-muted">{job.progress ?? 0}%</span>
        </div>
      )}
      <ol className="list-none m-0 p-0">
        {stages.map((s, i) => {
          const done = s.status === 'succeeded' || s.status === 'done'
          const run = s.status === 'running'
          const fail = s.status === 'failed'
          return (
            <li key={s.name + i} className="flex gap-3">
              <div className="flex flex-col items-center">
                <span aria-hidden="true" className="w-3.5 h-3.5 rounded-full box-border flex-none mt-0.5"
                  style={done ? { background: 'var(--accent)' } : fail ? { background: '#DC2626' } : run ? { border: '3px solid var(--accent)' } : { border: '2px solid var(--line)' }} />
                {i < stages.length - 1 && <span aria-hidden="true" className="w-0.5 flex-1" style={{ minHeight: compact ? 14 : 24, background: done ? 'var(--accent)' : 'var(--line)' }} />}
              </div>
              <div className={`flex-1 min-w-0 flex justify-between gap-2 ${compact ? 'pb-1.5 text-xs' : 'pb-3 text-sm'}`}>
                <div className="min-w-0">
                  <span className={s.status === 'pending' || s.status === 'queued' ? 'text-muted' : 'font-semibold'}>{s.name}</span>
                  <span className="sr-only"> — {s.status}</span>
                  {s.message && <div className={`text-xs ${fail ? 'text-danger' : 'text-muted'} break-words`}>{s.message}</div>}
                </div>
                <span className="font-mono text-xs text-muted flex-none">{fail ? 'failed' : dur(s)}</span>
              </div>
            </li>
          )
        })}
      </ol>
    </div>
  )
}
