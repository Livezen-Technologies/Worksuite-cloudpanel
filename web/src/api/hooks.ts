import { useQuery, useQueryClient, type UseQueryOptions } from '@tanstack/react-query'
import { useCallback, useState } from 'react'
import { api, newIdempotencyKey, type Query } from './client'
import type { Job, Paged } from './types'
import { useToast } from '../components/Toasts'
import { useJobs } from './jobs'

export function useList<T>(path: string | null, query?: Query, opts?: Partial<UseQueryOptions<Paged<T>>>) {
  const q = useQuery<Paged<T>>({
    queryKey: [path, query ?? {}],
    queryFn: ({ signal }) => api.get<Paged<T>>(path!, { per_page: 100, ...query }, signal),
    enabled: !!path,
    ...opts,
  })
  return { ...q, rows: q.data?.data ?? [], total: q.data?.meta?.total ?? 0 }
}

export function useOne<T>(path: string | null, query?: Query, opts?: Partial<UseQueryOptions<{ data: T }>>) {
  const q = useQuery<{ data: T }>({
    queryKey: [path, query ?? {}],
    queryFn: ({ signal }) => api.get<{ data: T }>(path!, query, signal),
    enabled: !!path,
    ...opts,
  })
  return { ...q, item: q.data?.data }
}

export const TERMINAL = new Set(['succeeded', 'failed', 'cancelled'])

/** Polls GET /jobs/{id} every `interval` ms until the job reaches a terminal status. */
export function useJob(jobId: string | null | undefined, interval = 1500) {
  const q = useQuery<{ data: Job } | Job>({
    queryKey: ['/jobs', jobId],
    queryFn: ({ signal }) => api.get(`/jobs/${jobId}`, undefined, signal),
    enabled: !!jobId,
    refetchInterval: (query) => {
      const d = query.state.data as { data?: Job } | Job | undefined
      const job = d && 'data' in d && d.data ? d.data : (d as Job | undefined)
      return job && TERMINAL.has(job.status) ? false : interval
    },
  })
  const raw = q.data as { data?: Job } | Job | undefined
  const job = raw && 'data' in raw && raw.data ? raw.data : (raw as Job | undefined)
  return { ...q, job, done: !!job && TERMINAL.has(job.status) }
}

export function extractJobId(res: unknown): string | null {
  const d = (res as { data?: { job_id?: string } } | undefined)?.data
  return d?.job_id ?? (res as { job_id?: string } | undefined)?.job_id ?? null
}

interface ActionArgs { path: string; body?: unknown; method?: 'POST' | 'PATCH' | 'DELETE'; label: string; success?: string; silent?: boolean }

/**
 * Runs a mutation: sends an Idempotency-Key on POST (stable for the life of one call, reused on retry),
 * shows a toast, tracks a returned job_id in the Activity dock, and invalidates cached queries.
 */
export function useAction() {
  const qc = useQueryClient()
  const toast = useToast()
  const jobs = useJobs()
  const [busy, setBusy] = useState(false)
  const run = useCallback(async <R = unknown>(a: ActionArgs): Promise<R> => {
    setBusy(true)
    try {
      const m = a.method ?? 'POST'
      const res = m === 'POST' ? await api.post<R>(a.path, a.body, { idempotencyKey: newIdempotencyKey() })
        : m === 'PATCH' ? await api.patch<R>(a.path, a.body) : await api.del<R>(a.path)
      const jobId = extractJobId(res)
      if (jobId) jobs.track(jobId, a.label)
      else if (!a.silent) toast.success(a.success ?? `${a.label} done`)
      qc.invalidateQueries()
      return res
    } catch (e) {
      if (!a.silent) toast.error(e, `${a.label} failed`)
      throw e
    } finally { setBusy(false) }
  }, [qc, toast, jobs])
  return { run, busy }
}
