import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useJob } from './hooks'
import { JobTimeline } from '../components/JobTimeline'
import { useToast } from '../components/Toasts'
import { Icon } from '../components/Icon'

interface Tracked { id: string; label: string }
interface Ctx { track: (id: string, label: string) => void }
const JobsCtx = createContext<Ctx>({ track: () => {} })
export const useJobs = () => useContext(JobsCtx)

function TrackedJob({ t, onDone, onDismiss }: { t: Tracked; onDone: (t: Tracked, ok: boolean) => void; onDismiss: () => void }) {
  const { job, done, error } = useJob(t.id)
  useEffect(() => { if (done && job) onDone(t, job.status === 'succeeded') }, [done]) // eslint-disable-line react-hooks/exhaustive-deps
  return (
    <div className="border-t border-line first:border-t-0 p-3">
      <div className="flex justify-between items-center gap-2 mb-2">
        <span className="text-sm font-semibold truncate">{t.label}</span>
        <button type="button" className="btn btn-sm border-0" aria-label={`Dismiss ${t.label}`} onClick={onDismiss}><Icon name="close" size={14} /></button>
      </div>
      {error ? <div className="text-xs text-danger">Could not load progress.</div> : <JobTimeline job={job} compact />}
    </div>
  )
}

/** Tracks 202 job_id responses and shows a live stages timeline in a floating "Activity" dock. */
export function JobsProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<Tracked[]>([])
  const [open, setOpen] = useState(true)
  const toast = useToast()
  const qc = useQueryClient()
  const track = useCallback((id: string, label: string) => { setItems((x) => [...x.filter((y) => y.id !== id), { id, label }]); setOpen(true) }, [])
  const onDone = useCallback((t: Tracked, ok: boolean) => {
    if (ok) toast.success(`${t.label} finished`)
    else toast.push({ kind: 'error', title: `${t.label} failed`, detail: 'Open the activity panel for the failed step.' })
    qc.invalidateQueries()
  }, [toast, qc])
  const value = useMemo(() => ({ track }), [track])
  return (
    <JobsCtx.Provider value={value}>
      {children}
      {items.length > 0 && (
        <div className="fixed z-[55] bottom-4 right-4 w-[min(360px,calc(100vw-32px))] card shadow-lg">
          <button type="button" className="w-full flex justify-between items-center px-3 h-11 bg-transparent border-0 text-fg font-semibold text-sm cursor-pointer" aria-expanded={open} onClick={() => setOpen(!open)}>
            Activity ({items.length})<span aria-hidden="true">{open ? '▾' : '▴'}</span>
          </button>
          {open && <div className="max-h-[50vh] overflow-y-auto border-t border-line">{items.map((t) => <TrackedJob key={t.id} t={t} onDone={onDone} onDismiss={() => setItems((x) => x.filter((y) => y.id !== t.id))} />)}</div>}
        </div>
      )}
    </JobsCtx.Provider>
  )
}
