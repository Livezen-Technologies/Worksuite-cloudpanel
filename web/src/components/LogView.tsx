import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../api/client'
import { logLines } from '../lib/logs'
import { Badge } from './Badge'

/** Live log stream: polls a logs endpoint, with search, pause and export. */
export function LogView({ path, title, height = 320, query }: { path: string | null; title?: string; height?: number; query?: Record<string, string | number> }) {
  const [paused, setPaused] = useState(false)
  const [q, setQ] = useState('')
  const ref = useRef<HTMLPreElement>(null)
  const { data, error } = useQuery({
    queryKey: [path, 'logs', query],
    queryFn: async ({ signal }) => logLines(await api.get(path!, { tail: 200, ...query }, signal)),
    enabled: !!path,
    refetchInterval: paused ? false : 3000,
  })
  const lines = (data ?? []).filter((l) => !q || l.toLowerCase().includes(q.toLowerCase()))
  useEffect(() => { if (!paused && ref.current) ref.current.scrollTop = ref.current.scrollHeight }, [data, paused])
  const exportLogs = () => {
    const blob = new Blob([(data ?? []).join('\n')], { type: 'text/plain' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob); a.download = `${(title ?? 'logs').replace(/\W+/g, '-')}.log`; a.click()
    URL.revokeObjectURL(a.href)
  }
  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-2">
        {title && <span className="text-sm font-semibold">{title}</span>}
        <Badge tone={paused ? 'n' : 'g'}>{paused ? 'Paused' : 'Streaming'}</Badge>
        <input className="input !w-auto flex-1 min-w-[140px] !min-h-8 text-xs" aria-label="Search logs" placeholder="Search logs…" value={q} onChange={(e) => setQ(e.target.value)} />
        <button type="button" className="btn btn-sm" onClick={() => setPaused(!paused)}>{paused ? 'Resume' : 'Pause'}</button>
        <button type="button" className="btn btn-sm" onClick={exportLogs}>Export</button>
      </div>
      <pre ref={ref} tabIndex={0} aria-label={`${title ?? ''} log output`} aria-live="off"
        className="m-0 p-3.5 rounded-lg bg-code text-code-fg font-mono text-xs leading-relaxed overflow-auto whitespace-pre-wrap break-all" style={{ height }}>
        {error ? 'Could not load logs. They will retry automatically.' : lines.length ? lines.map((l, i) => {
          const tone = /\berror\b/i.test(l) ? '#F87171' : /\bwarn/i.test(l) ? '#FACC15' : undefined
          return <div key={i} style={{ color: tone }}>{l}</div>
        }) : 'No log lines yet.'}
      </pre>
    </div>
  )
}
