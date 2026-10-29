import { useEffect, useRef, useState } from 'react'
import type { Terminal as XTerm } from '@xterm/xterm'
import '@xterm/xterm/css/xterm.css'
import { api } from '../api/client'
import { unwrap } from '../lib/format'
import { Modal } from './Overlay'

interface Session { session_id: string; ws_url: string; expires_at: string; shell_user?: string; shell?: string; recorded?: boolean }
interface Control { type: string; message?: string; reason?: string; code?: number }

// xterm is loaded on demand so it stays out of the main bundle.
const loadXterm = () => Promise.all([import('@xterm/xterm'), import('@xterm/addon-fit')]).then(([x, f]) => ({ Terminal: x.Terminal, FitAddon: f.FitAddon }))

/** Resolves the API's ws_url (absolute, or relative to the panel origin) to a ws(s):// URL with the terminal size. */
export function terminalSocketUrl(wsUrl: string, cols: number, rows: number, base = window.location.href): string {
  const u = new URL(wsUrl, base)
  if (u.protocol === 'http:') u.protocol = 'ws:'
  if (u.protocol === 'https:') u.protocol = 'wss:'
  u.searchParams.set('cols', String(cols))
  u.searchParams.set('rows', String(rows))
  return u.toString()
}

/**
 * CTR-008 / LOG-002: exec terminal over the relay WebSocket. Binary frames carry the TTY stream,
 * text frames are JSON control messages (resize, status, ready, exit, closed). The session is
 * time-limited, non-root, recorded, and "End session" closes it for good.
 */
export function TerminalDialog({ containerId, name, open, onClose }: { containerId: string; name: string; open: boolean; onClose: () => void }) {
  const [sess, setSess] = useState<Session | null>(null)
  const [status, setStatus] = useState('Starting…')
  const [ended, setEnded] = useState<string | null>(null)
  const [left, setLeft] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const [pending, setPending] = useState<string | null>(null)
  const [approvalId, setApprovalId] = useState<string | null>(null)
  const host = useRef<HTMLDivElement>(null)
  const ws = useRef<WebSocket | null>(null)
  const sessRef = useRef<Session | null>(null)

  useEffect(() => {
    if (!open) return
    let disposed = false
    setErr(null); setSess(null); setEnded(null); setPending(null); setStatus('Starting…')
    let term: XTerm | null = null
    let ro: ResizeObserver | null = null
    const sendCtl = (v: Control | { type: 'resize'; cols: number; rows: number }) => { if (ws.current?.readyState === WebSocket.OPEN) ws.current.send(JSON.stringify(v)) }
    const enc = new TextEncoder()

    const start = async () => {
      const { Terminal, FitAddon } = await loadXterm()
      if (disposed || !host.current) return
      const t = new Terminal({ convertEol: false, cursorBlink: true, fontSize: 13, fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace', scrollback: 2000, theme: { background: 'var(--text)' } })
      term = t
      const fit = new FitAddon()
      t.loadAddon(fit)
      t.open(host.current)
      try { fit.fit() } catch { /* not laid out yet */ }
      let s: Session
      try {
        const r = unwrap<Session & { approval_id?: string; status?: string; message?: string }>(await api.post(`/containers/${containerId}/terminal-sessions`, approvalId ? { approval_id: approvalId } : {}))
        if (r.approval_id && !r.session_id) {
          // Approval gate (security.require_approval_destructive): a second person approves, then reopen with the id.
          if (!disposed) { setPending(r.approval_id); setStatus(r.message ?? 'Waiting for approval') }
          return
        }
        s = r
      } catch (e) { if (!disposed) setErr((e as Error).message); return }
      if (disposed) return
      sessRef.current = s; setSess(s)
      const sock = new WebSocket(terminalSocketUrl(s.ws_url, t.cols, t.rows))
      sock.binaryType = 'arraybuffer'
      ws.current = sock
      sock.onopen = () => setStatus('Connected · waiting for the agent…')
      sock.onmessage = (m) => {
        if (m.data instanceof ArrayBuffer) { t.write(new Uint8Array(m.data)); return }
        let c: Control
        try { c = JSON.parse(String(m.data)) } catch { return }
        if (c.type === 'status' && c.message) setStatus(c.message)
        else if (c.type === 'ready') { setStatus(`Attached · ${c.message ?? s.shell_user ?? 'app'}`); t.focus() }
        else if (c.type === 'error' && c.message) setErr(c.message)
        else if (c.type === 'exit') setStatus(`Shell exited (${c.code ?? 0})`)
        else if (c.type === 'closed') setEnded(c.reason ?? 'closed')
      }
      sock.onclose = () => { setEnded((r) => r ?? 'disconnected'); ws.current = null }
      sock.onerror = () => setErr('The terminal connection failed. Check that the relay and the agent are online.')
      t.onData((d) => { if (sock.readyState === WebSocket.OPEN) sock.send(enc.encode(d)) })
      t.onResize(({ cols, rows }) => sendCtl({ type: 'resize', cols, rows }))
      ro = new ResizeObserver(() => { try { fit.fit() } catch { /* hidden */ } })
      ro.observe(host.current)
    }
    // Wait one frame so the modal has laid out the terminal host.
    const raf = requestAnimationFrame(() => { start() })
    return () => {
      disposed = true
      cancelAnimationFrame(raf)
      ro?.disconnect()
      ws.current?.close(); ws.current = null
      term?.dispose()
    }
  }, [open, containerId, approvalId])

  useEffect(() => {
    if (!sess || ended) return
    const tick = () => {
      const s = Math.max(0, Math.round((new Date(sess.expires_at).getTime() - Date.now()) / 1000))
      setLeft(`${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`)
    }
    tick()
    const t = setInterval(tick, 1000)
    return () => clearInterval(t)
  }, [sess, ended])

  const end = async () => {
    const s = sessRef.current
    // End it in the API first (audited as ended by this user), then close the socket.
    if (s && !ended) { try { await api.del(`/terminal-sessions/${s.session_id}`) } catch { /* already ended */ } }
    try { ws.current?.send(JSON.stringify({ type: 'close' })) } catch { /* closed */ }
    ws.current?.close()
    sessRef.current = null
    setApprovalId(null)
    onClose()
  }
  const user = sess?.shell_user ?? 'app'
  return (
    <Modal open={open} onClose={end} title={`Terminal · ${name}`} width={900}
      footer={<button type="button" className="btn btn-danger" onClick={end}>End session</button>}>
      <div className="text-xs text-muted mb-2" role="status" data-testid="terminal-status">
        {sess?.shell ?? 'sh'} as {user} (non-root) · {ended ? `Session ended: ${ended}` : left ? `expires in ${left}` : 'starting…'} · {sess?.recorded === false ? 'not recorded' : 'recorded'} · {status}
      </div>
      {err && <div role="alert" className="text-sm text-danger mb-2">{err}</div>}
      {pending && (
        <div className="flex flex-wrap items-center gap-2 mb-2 text-sm" role="status">
          <span className="flex-1">Opening a terminal needs a second person’s approval (request {pending.slice(0, 8)}). Once it is approved:</span>
          <button type="button" className="btn btn-sm btn-primary" onClick={() => setApprovalId(pending)}>Open with approval</button>
        </div>
      )}
      <div ref={host} data-testid="terminal" className="rounded-lg overflow-hidden bg-[var(--text)] p-2 h-[380px]" />
    </Modal>
  )
}

type CastEvent = [number, string, string]

/** Parses an asciicast v2 recording (header line + [time, type, data] events). */
export function parseCast(cast: string): { header: { width?: number; height?: number; timestamp?: number; title?: string }; events: CastEvent[] } {
  const lines = cast.split('\n').filter((l) => l.trim())
  let header = {}
  const events: CastEvent[] = []
  lines.forEach((l, i) => {
    try {
      const v = JSON.parse(l)
      if (i === 0 && !Array.isArray(v)) header = v
      else if (Array.isArray(v) && v.length >= 3) events.push([Number(v[0]), String(v[1]), String(v[2])])
    } catch { /* skip malformed line */ }
  })
  return { header, events }
}

/** Replays a recorded session in a read-only terminal (instantly, or with the original timing). */
export function RecordingPlayer({ cast }: { cast: string }) {
  const host = useRef<HTMLDivElement>(null)
  const term = useRef<XTerm | null>(null)
  const timers = useRef<number[]>([])
  const { header, events } = parseCast(cast)
  const writeAll = () => {
    timers.current.forEach(clearTimeout); timers.current = []
    const t = term.current
    if (!t) return
    t.reset()
    for (const [, kind, data] of events) {
      if (kind === 'o') t.write(data)
      else if (kind === 'r') { const [c, r] = data.split('x').map(Number); if (c && r) t.resize(c, r) }
    }
  }
  const replay = () => {
    timers.current.forEach(clearTimeout); timers.current = []
    const t = term.current
    if (!t) return
    t.reset()
    let at = 0; let prev = 0
    for (const [time, kind, data] of events) {
      at += Math.min(1, Math.max(0, time - prev)) * 1000; prev = time
      if (kind === 'o') timers.current.push(window.setTimeout(() => t.write(data), at))
    }
  }
  useEffect(() => {
    let t: XTerm | null = null
    let gone = false
    loadXterm().then(({ Terminal }) => {
      if (gone || !host.current) return
      t = new Terminal({ cols: header.width ?? 80, rows: Math.min(header.height ?? 24, 30), disableStdin: true, fontSize: 12, scrollback: 5000, theme: { background: 'var(--text)' } })
      t.open(host.current)
      term.current = t
      writeAll()
    })
    return () => { gone = true; timers.current.forEach(clearTimeout); t?.dispose(); term.current = null }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cast])
  const duration = events.length ? events[events.length - 1][0] : 0
  return (
    <div>
      <div className="flex gap-2 items-center mb-2 text-xs text-muted">
        <span className="flex-1">{events.filter((e) => e[1] === 'o').length} output events · {duration.toFixed(1)} s{header.timestamp ? ` · ${new Date(header.timestamp * 1000).toISOString().replace('T', ' ').slice(0, 19)} UTC` : ''}</span>
        <button type="button" className="btn btn-sm" onClick={replay}>Replay</button>
        <button type="button" className="btn btn-sm" onClick={writeAll}>Show all</button>
      </div>
      <div ref={host} data-testid="recording" className="rounded-lg overflow-auto bg-[var(--text)] p-2 max-h-[460px]" />
    </div>
  )
}
