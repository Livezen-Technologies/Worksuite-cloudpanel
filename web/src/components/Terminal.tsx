import { useEffect, useRef, useState } from 'react'
import { api } from '../api/client'
import { unwrap } from '../lib/format'
import { Modal } from './Overlay'

interface Session { session_id: string; ws_url: string; expires_at: string }

/** Time-limited, recorded exec terminal session over the ws_url returned by the API. */
export function TerminalDialog({ containerId, name, open, onClose }: { containerId: string; name: string; open: boolean; onClose: () => void }) {
  const [sess, setSess] = useState<Session | null>(null)
  const [out, setOut] = useState<string[]>([])
  const [cmd, setCmd] = useState('')
  const [left, setLeft] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const ws = useRef<WebSocket | null>(null)
  const pre = useRef<HTMLPreElement>(null)

  useEffect(() => {
    if (!open) return
    let closed = false
    setOut([]); setErr(null); setSess(null)
    api.post(`/containers/${containerId}/terminal-sessions`).then((r) => {
      if (closed) return
      const s = unwrap<Session>(r)
      setSess(s)
      try {
        const sock = new WebSocket(s.ws_url)
        ws.current = sock
        sock.onmessage = (m) => setOut((o) => [...o.slice(-500), String(m.data)])
        sock.onopen = () => setOut((o) => [...o, `Connected · session ${s.session_id.slice(0, 8)} · recorded`])
        sock.onclose = () => setOut((o) => [...o, 'Session ended.'])
        sock.onerror = () => setErr('The terminal connection failed. Check that the agent is online and try again.')
      } catch { setErr('Could not open the terminal connection.') }
    }).catch((e) => setErr((e as Error).message))
    return () => { closed = true; ws.current?.close(); ws.current = null }
  }, [open, containerId])

  useEffect(() => {
    if (!sess) return
    const t = setInterval(() => {
      const s = Math.max(0, Math.round((new Date(sess.expires_at).getTime() - Date.now()) / 1000))
      setLeft(`${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`)
      if (!s) ws.current?.close()
    }, 1000)
    return () => clearInterval(t)
  }, [sess])
  useEffect(() => { if (pre.current) pre.current.scrollTop = pre.current.scrollHeight }, [out])

  const end = () => { ws.current?.close(); onClose() }
  return (
    <Modal open={open} onClose={end} title={`Terminal · ${name}`} width={820}
      footer={<button type="button" className="btn btn-danger" onClick={end}>End session</button>}>
      <div className="text-xs text-muted mb-2">sh as a non-root user · {left ? `session expires in ${left}` : 'starting…'} · recorded</div>
      {err && <div role="alert" className="text-sm text-danger mb-2">{err}</div>}
      <pre ref={pre} className="m-0 p-3.5 rounded-lg bg-code text-code-fg font-mono text-xs h-[320px] overflow-auto whitespace-pre-wrap">{out.join('\n')}</pre>
      <form className="flex gap-2 mt-2" onSubmit={(e) => { e.preventDefault(); if (ws.current?.readyState === 1) { ws.current.send(cmd + '\n'); setOut((o) => [...o, `$ ${cmd}`]); setCmd('') } }}>
        <input className="input font-mono" aria-label="Command" value={cmd} onChange={(e) => setCmd(e.target.value)} placeholder="Type a command and press Enter" />
        <button className="btn">Send</button>
      </form>
    </Modal>
  )
}
