import type { Job, JobStage } from '../api/types'
import { Badge, toneFor } from './Badge'

/** "16 s", "1 min 05 s", "<1 s"; empty before a stage starts. */
export function dur(s: JobStage): string {
  if (!s.started_at) return ''
  const end = s.finished_at ? new Date(s.finished_at).getTime() : Date.now()
  return seconds(Math.max(0, Math.round((end - new Date(s.started_at).getTime()) / 1000)))
}

export function seconds(sec: number): string {
  if (sec < 1) return '<1 s'
  if (sec < 60) return `${sec} s`
  return `${Math.floor(sec / 60)} min ${String(sec % 60).padStart(2, '0')} s`
}

/** build_scan → Build scan */
export const stageLabel = (name: string) => name.replace(/[_-]+/g, ' ').replace(/^./, (c) => c.toUpperCase())

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
          const skip = s.status === 'skipped'
          return (
            <li key={s.name + i} className="flex gap-3">
              <div className="flex flex-col items-center">
                <span aria-hidden="true" className="w-3.5 h-3.5 rounded-full box-border flex-none mt-0.5"
                  style={done ? { background: 'var(--accent)' } : fail ? { background: 'var(--r-fg)' } : run ? { border: '3px solid var(--accent)' } : skip ? { border: '2px dashed var(--muted)' } : { border: '2px solid var(--line)' }} />
                {i < stages.length - 1 && <span aria-hidden="true" className="w-0.5 flex-1" style={{ minHeight: compact ? 14 : 24, background: done || skip ? 'var(--accent)' : 'var(--line)', opacity: skip ? 0.35 : 1 }} />}
              </div>
              <div className={`flex-1 min-w-0 flex justify-between gap-2 ${compact ? 'pb-1.5 text-xs' : 'pb-3 text-sm'}`}>
                <div className="min-w-0">
                  <span className={s.status === 'pending' || s.status === 'queued' || skip ? 'text-muted' : 'font-semibold'}>{stageLabel(s.name)}</span>
                  <span className="sr-only"> — {s.status}</span>
                  {s.message && <div className={`text-xs ${fail ? 'text-danger' : 'text-muted'} break-words`}>{s.message}</div>}
                </div>
                <span className="text-xs text-muted flex-none tabular-nums">{fail ? 'failed' : skip ? 'skipped' : dur(s)}</span>
              </div>
            </li>
          )
        })}
      </ol>
    </div>
  )
}
