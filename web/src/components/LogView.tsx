import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../api/client'
import { logLines } from '../lib/logs'
import { Icon } from './Icon'

const TS = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(?:[.,]\d+)?(Z|[+-]\d{2}:?\d{2})?\s+/

/** A log line split into its leading timestamp (if any) and the message. */
export function splitLogLine(line: string): { time: string | null; full: string | null; text: string } {
  const m = line.match(TS)
  return m ? { time: m[2], full: m[0].trim(), text: line.slice(m[0].length) } : { time: null, full: null, text: line }
}

export function logTone(text: string): 'error' | 'warn' | null {
  if (/\b(error|fatal|panic|exception|failed|failure)\b/i.test(text)) return 'error'
  if (/\bwarn(ing)?\b/i.test(text)) return 'warn'
  return null
}

/** Live log stream: polls a logs endpoint, with search, pause and export. */
export function LogView({ path, title, height = 320, query }: { path: string | null; title?: string; height?: number | string; query?: Record<string, string | number> }) {
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
  // Follow new lines only while the reader is at the bottom, so scrolling back to read is not undone every 3 s.
  const follow = useRef(true)
  useEffect(() => { if (!paused && follow.current && ref.current) ref.current.scrollTop = ref.current.scrollHeight }, [data, paused])
  const exportLogs = () => {
    const blob = new Blob([(data ?? []).join('\n')], { type: 'text/plain' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob); a.download = `${(title ?? 'logs').replace(/\W+/g, '-')}.log`; a.click()
    URL.revokeObjectURL(a.href)
  }
  const [atEnd, setAtEnd] = useState(true)
  const toEnd = () => { if (ref.current) { ref.current.scrollTop = ref.current.scrollHeight; follow.current = true; setAtEnd(true) } }
  return (
    <div className="flex flex-col gap-2">
      <div className="flex items-center gap-2">
        {title && <span className="text-sm font-semibold whitespace-nowrap">{title}</span>}
        <span className={`inline-flex items-center gap-1.5 text-xs ${paused ? 'text-muted' : 'text-success'}`} role="status">
          <span aria-hidden="true" className={`inline-block w-1.5 h-1.5 rounded-full ${paused ? 'bg-[var(--muted)]' : 'bg-[var(--g-fg)] animate-pulse'}`} />{paused ? 'Paused' : 'Live'}
        </span>
        <span className="relative ml-auto flex-1 max-w-56 min-w-0">
          <span className="absolute left-2 top-1/2 -translate-y-1/2 text-muted pointer-events-none"><Icon name="search" size={14} /></span>
          <input className="input !min-h-8 !py-1 !pl-7 text-xs w-full" aria-label="Search logs" placeholder="Filter" value={q} onChange={(e) => setQ(e.target.value)} />
        </span>
        <button type="button" className="btn btn-sm !px-2" aria-label={paused ? 'Resume' : 'Pause'} title={paused ? 'Resume' : 'Pause'} onClick={() => setPaused(!paused)}><Icon name={paused ? 'play' : 'pause'} size={14} /></button>
        <button type="button" className="btn btn-sm !px-2" aria-label="Export" title="Download the log" onClick={exportLogs}><Icon name="download" size={14} /></button>
      </div>
      <div className="relative">
        <pre ref={ref} tabIndex={0} aria-label={`${title ?? ''} log output`} aria-live="off"
          onScroll={(e) => { const el = e.currentTarget; const end = el.scrollHeight - el.scrollTop - el.clientHeight < 24; follow.current = end; setAtEnd(end) }}
          className="m-0 py-2 rounded-lg bg-code text-code-fg font-mono text-xs leading-[1.6] overflow-auto" style={{ height }}>
          {error ? <div className="px-3.5">Could not load logs. They will retry automatically.</div> : lines.length ? lines.map((l, i) => {
            const { time, full, text } = splitLogLine(l)
            const tone = logTone(text)
            return (
              <div key={i} className={`flex gap-3 px-3.5 hover:bg-white/5 ${tone === 'error' ? 'bg-[color-mix(in_srgb,var(--r-fg)_10%,transparent)]' : ''}`}>
                {time && <span className="shrink-0 select-none opacity-50" title={full ?? undefined}>{time}</span>}
                <span className="min-w-0 whitespace-pre-wrap [overflow-wrap:anywhere]" style={{ color: tone === 'error' ? 'var(--r-fg)' : tone === 'warn' ? 'var(--y-fg)' : undefined }}>{text}</span>
              </div>
            )
          }) : <div className="px-3.5 opacity-60">{q ? 'No lines match the filter.' : 'No log lines yet.'}</div>}
        </pre>
        {!atEnd && <button type="button" className="btn btn-sm absolute bottom-2 right-3 shadow" onClick={toEnd}>Jump to latest ↓</button>}
      </div>
    </div>
  )
}
