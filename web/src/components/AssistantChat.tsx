import { useEffect, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api, buildUrl, tokenStore } from '../api/client'
import { useOne } from '../api/hooks'
import { Badge, type Tone } from './Badge'
import { useToast } from './Toasts'
import type { R } from '../lib/util'

const TONE: Record<string, Tone> = { done: 'g', pending: 'y', failed: 'r', refused: 'r', rejected: 'n' }
/** Reads the assistant's server-sent events: text pieces, tool actions, then done or error. */
export async function streamAssistant(message: string, on: { text: (t: string) => void; action: (a: R) => void }): Promise<R> {
  const token = tokenStore.get()
  const res = await fetch(buildUrl('/app/ai/assistant/stream'), {
    method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    body: JSON.stringify({ message }),
  })
  if (!res.ok || !res.body) {
    const j = await res.json().catch(() => null) as R | null
    throw new Error(String(((j?.error as R) ?? {}).message ?? 'The assistant could not answer.'))
  }
  const reader = res.body.getReader()
  const dec = new TextDecoder()
  let buf = ''
  for (;;) {
    const { done, value } = await reader.read()
    if (value) buf += dec.decode(value, { stream: true })
    let i
    while ((i = buf.indexOf('\n\n')) >= 0) {
      const chunk = buf.slice(0, i)
      buf = buf.slice(i + 2)
      const ev = /^event: (.+)$/m.exec(chunk)?.[1]
      const data = /^data: (.*)$/m.exec(chunk)?.[1]
      if (!ev || data === undefined) continue
      const d = JSON.parse(data) as R
      if (ev === 'text') on.text(String(d.text ?? ''))
      else if (ev === 'action') on.action(d)
      else if (ev === 'done') return d
      else if (ev === 'error') throw new Error(String(d.message ?? 'The assistant could not answer.'))
    }
    if (done) throw new Error('The answer was cut off. Try again.')
  }
}

const LABEL: Record<string, string> = { done: 'done', pending: 'waiting for approval', failed: 'failed', refused: 'not allowed' }

/** UAI: chat with the built-in assistant. Changes it asks for follow the account's AI level. */
export function AssistantChat() {
  const conv = useOne<R>('/app/ai/assistant')
  const qc = useQueryClient()
  const toast = useToast()
  const [text, setText] = useState('')
  const [sending, setSending] = useState(false)
  const [local, setLocal] = useState<R[] | null>(null)
  const end = useRef<HTMLDivElement>(null)
  const messages = local ?? ((conv.item?.messages as R[]) ?? [])
  useEffect(() => { end.current?.scrollIntoView?.({ block: 'end' }) }, [messages.length])

  if (conv.item && conv.item.available === false) {
    return <p className="m-0 text-sm text-muted">The assistant is not set up on this platform yet. You can still connect your own AI tool under Connect AI (MCP).</p>
  }

  const send = async () => {
    const msg = text.trim()
    if (!msg || sending) return
    setSending(true)
    setLocal([...messages, { role: 'user', text: msg }])
    setText('')
    const base = [...messages, { role: 'user', text: msg }]
    let live = ''
    const acts: R[] = []
    const paint = () => setLocal([...base, { role: 'assistant', text: live, actions: [...acts] }])
    try {
      const d = await streamAssistant(msg, { text: (t) => { live += t; paint() }, action: (a) => { acts.push(a); paint() } })
      setLocal(d.messages as R[])
      qc.invalidateQueries({ queryKey: ['/app/ai'] })
    } catch (e) {
      setLocal(null)
      setText(msg)
      toast.error(e, 'The assistant could not answer')
    } finally { setSending(false) }
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-3 max-h-[480px] overflow-y-auto" role="log" aria-live="polite" aria-label="Conversation">
        {messages.length === 0 && <p className="m-0 text-sm text-muted">Ask about your apps, their logs, or to restart or redeploy one. Changes wait for your approval unless AI is set to autonomous.</p>}
        {messages.map((m, i) => (
          <div key={i} className={`flex ${m.role === 'user' ? 'justify-end' : 'justify-start'}`}>
            <div className={`max-w-[85%] rounded-lg px-3 py-2 text-sm whitespace-pre-wrap ${m.role === 'user' ? 'text-white' : 'bg-head'}`} style={m.role === 'user' ? { background: 'var(--accent)' } : undefined}>
              {String(m.text ?? '')}
              {((m.actions as R[]) ?? []).length > 0 && <div className="mt-2 flex flex-wrap gap-1">{((m.actions as R[]) ?? []).map((a, j) => (
                <Badge key={j} tone={TONE[String(a.status)] ?? 'n'}>{String(a.tool)}{a.target ? ` · ${a.target}` : ''}: {LABEL[String(a.status)] ?? String(a.status)}</Badge>
              ))}</div>}
            </div>
          </div>
        ))}
        {sending && !(messages[messages.length - 1]?.role === 'assistant' && String(messages[messages.length - 1]?.text ?? '')) && <div className="text-xs text-muted" role="status">Thinking…</div>}
        <div ref={end} />
      </div>
      <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); void send() }}>
        <input className="input flex-1" aria-label="Message the assistant" placeholder="e.g. Why is shop slow? Show me its last logs." value={text} onChange={(e) => setText(e.target.value)} maxLength={4000} disabled={sending} />
        <button type="submit" className="btn btn-primary" disabled={sending || !text.trim()}>Send</button>
        {messages.length > 0 && <button type="button" className="btn" disabled={sending} onClick={() => api.del('/app/ai/assistant').then(() => { setLocal([]); conv.refetch() }).catch((e) => toast.error(e, 'Could not start over'))}>New chat</button>}
      </form>
    </div>
  )
}
