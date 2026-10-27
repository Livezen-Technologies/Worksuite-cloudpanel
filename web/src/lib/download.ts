import { api } from '../api/client'
import { unwrap } from './format'

export interface DownloadLink { status: 'ready' | 'exporting'; url?: string; expires_at?: string; job_id?: string; bytes?: number; sha256?: string }

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms))

/**
 * UBAK-002: asks for a backup download link. The first request starts an export on the agent
 * (202 + job_id); we poll that job and ask again for the signed, expiring URL of the real archive.
 */
export async function backupDownloadUrl(path: string, opts: { onExporting?: (jobId: string) => void; timeoutMs?: number; pollMs?: number } = {}): Promise<string> {
  const deadline = Date.now() + (opts.timeoutMs ?? 15 * 60_000)
  let link = unwrap<DownloadLink>(await api.get(path))
  if (link.url) return link.url
  if (link.job_id) opts.onExporting?.(link.job_id)
  while (Date.now() < deadline) {
    if (link.job_id) {
      const job = unwrap<{ status: string; error?: string | null }>(await api.get(`/jobs/${link.job_id}`))
      if (job.status === 'failed' || job.status === 'cancelled') throw new Error(job.error || 'Preparing the archive failed.')
      if (job.status !== 'succeeded') { await sleep(opts.pollMs ?? 2000); continue }
    }
    link = unwrap<DownloadLink>(await api.get(path))
    if (link.url) return link.url
    await sleep(opts.pollMs ?? 2000)
  }
  throw new Error('The archive is still being prepared. Try again in a few minutes.')
}
