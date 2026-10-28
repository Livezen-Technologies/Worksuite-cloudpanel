import { useState } from 'react'
import { api } from '../api/client'
import { useList } from '../api/hooks'
import { unwrap } from '../lib/format'
import { Badge } from './Badge'
import { Card } from './Card'
import { DataTable } from './DataTable'
import { Modal } from './Overlay'
import { RecordingPlayer } from './Terminal'
import { useToast } from './Toasts'

export interface TerminalSessionRow {
  id: string; container_name?: string | null; user_email?: string | null; shell_user: string; shell: string
  status: 'pending' | 'active' | 'ended' | 'expired'; close_reason?: string | null; created_at: string; ended_at?: string | null
  expires_at: string; recording?: { bytes: number; format: string } | null; ip?: string | null
}

const tone = { active: 'g', pending: 'y', ended: 'n', expired: 'n' } as const

/** LOG-002: terminal session history with recordings (Logs & Terminal). */
export function TerminalSessions({ containerId }: { containerId?: string }) {
  const list = useList<TerminalSessionRow>('/terminal-sessions', containerId ? { container_id: containerId } : undefined, { refetchInterval: 10000 })
  const toast = useToast()
  const [view, setView] = useState<{ row: TerminalSessionRow; cast: string } | null>(null)
  const open = async (row: TerminalSessionRow) => {
    try { setView({ row, cast: unwrap<{ cast: string }>(await api.get(`/terminal-sessions/${row.id}/recording`)).cast }) }
    catch (e) { toast.error(e, 'Could not load the recording') }
  }
  return (
    <Card title="Session recordings" pad={false}>
      <DataTable label="Terminal sessions" rows={list.rows} loading={list.isLoading} rowKey={(r) => r.id} pageSize={10}
        columns={[
          { key: 'created_at', header: 'Started', render: (r) => <span className="font-mono text-xs">{r.created_at.replace('T', ' ').slice(0, 19)}</span> },
          { key: 'container_name', header: 'Container', value: (r) => r.container_name ?? '—' },
          { key: 'user_email', header: 'User', value: (r) => r.user_email ?? '—' },
          { key: 'shell_user', header: 'As', render: (r) => <span className="font-mono text-xs">{r.shell_user} · {r.shell}</span> },
          { key: 'status', header: 'Status', render: (r) => <span title={r.close_reason ?? ''}><Badge tone={tone[r.status] ?? 'n'}>{r.status}</Badge>{r.close_reason && <span className="ml-2 text-xs text-muted">{r.close_reason}</span>}</span> },
          { key: 'recording', header: 'Recording', render: (r) => r.recording ? <button type="button" className="btn btn-sm" onClick={() => open(r)}>Play ({Math.max(1, Math.round(r.recording.bytes / 1024))} KB)</button> : <span className="text-xs text-muted">{r.status === 'active' ? 'recording…' : 'none'}</span> },
        ]} />
      <Modal open={!!view} onClose={() => setView(null)} title={`Recording · ${view?.row.container_name ?? ''} · ${view?.row.user_email ?? ''}`} width={900}>
        {view && <RecordingPlayer cast={view.cast} />}
      </Modal>
    </Card>
  )
}
