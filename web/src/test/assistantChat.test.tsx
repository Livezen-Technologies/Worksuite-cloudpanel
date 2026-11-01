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
      if (url.endsWith('/app/ai/assistant') && method === 'POST') {
        posts.push(String(init?.body))
        return json(200, { data: { reply: 'Waiting for your approval.', messages: [
          { role: 'user', text: 'Restart shop' },
          { role: 'assistant', text: 'I asked to restart shop. It is waiting for your approval.', actions: [{ tool: 'restart_app', target: 'shop', status: 'pending' }] },
        ] } })
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

  it('says when the assistant is not set up', async () => {
    available = false
    renderIt()
    expect(await screen.findByText(/not set up on this platform/)).toBeInTheDocument()
  })
})
