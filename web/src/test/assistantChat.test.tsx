import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AssistantChat } from '../components/AssistantChat'
import { ToastProvider } from '../components/Toasts'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }
const renderIt = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><AssistantChat /></ToastProvider></QueryClientProvider>)

describe('Assistant chat', () => {
  let available = true
  const posts: string[] = []
  beforeEach(() => {
    available = true
    posts.length = 0
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET'
      if (url.endsWith('/app/ai/assistant') && method === 'GET') return json(200, { data: { messages: [], available } })
      if (url.endsWith('/app/ai/assistant/stream') && method === 'POST') {
        posts.push(String(init?.body))
        const done = { messages: [
          { role: 'user', text: 'Restart shop' },
          { role: 'assistant', text: 'I asked to restart shop. It is waiting for your approval.', actions: [{ tool: 'restart_app', target: 'shop', status: 'pending' }] },
        ] }
        const sse = 'event: text\ndata: {"type":"text","text":"I asked "}\n\n'
          + 'event: action\ndata: {"type":"action","tool":"restart_app","target":"shop","status":"pending"}\n\n'
          + `event: done\ndata: ${JSON.stringify(done)}\n\n`
        return new Response(sse, { status: 200, headers: { 'Content-Type': 'text/event-stream' } })
      }
      return json(204, {})
    }))
  })

  it('sends a message and shows the reply with the change it is waiting on', async () => {
    renderIt()
    await userEvent.type(await screen.findByRole('textbox', { name: 'Message the assistant' }), 'Restart shop')
    await userEvent.click(screen.getByRole('button', { name: 'Send' }))
    expect(await screen.findByText('I asked to restart shop. It is waiting for your approval.')).toBeInTheDocument()
    expect(screen.getByText('restart_app · shop: waiting for approval')).toBeInTheDocument()
    expect(JSON.parse(posts[0])).toEqual({ message: 'Restart shop' })
  })

  it('parses the event stream, including events split across chunks', async () => {
    const { streamAssistant } = await import('../components/AssistantChat')
    const enc = new TextEncoder()
    const parts = ['event: te', 'xt\ndata: {"text":"Hel"}\n', '\nevent: text\ndata: {"text":"lo"}\n\nevent: done\ndata: {"messages":[]}\n\n']
    vi.stubGlobal('fetch', vi.fn(async () => new Response(new ReadableStream({ start(c) { parts.forEach((p) => c.enqueue(enc.encode(p))); c.close() } }), { status: 200 })))
    let text = ''
    const d = await streamAssistant('hi', { text: (t) => { text += t }, action: () => {} })
    expect(text).toBe('Hello')
    expect(d).toEqual({ messages: [] })
  })

  it('surfaces a stream error', async () => {
    const { streamAssistant } = await import('../components/AssistantChat')
    vi.stubGlobal('fetch', vi.fn(async () => new Response('event: error\ndata: {"message":"Your account has used today\'s 200 assistant messages."}\n\n', { status: 200 })))
    await expect(streamAssistant('hi', { text: () => {}, action: () => {} })).rejects.toThrow(/200 assistant messages/)
  })

  it('says when the assistant is not set up', async () => {
    available = false
    renderIt()
    expect(await screen.findByText(/not set up on this platform/)).toBeInTheDocument()
  })
})
