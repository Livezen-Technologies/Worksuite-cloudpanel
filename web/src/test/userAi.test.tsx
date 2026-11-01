import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import UserAi from '../pages/app/Ai'
import { ToastProvider } from '../components/Toasts'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }

describe('AI assistant & MCP', () => {
  const calls: { url: string; method: string; body?: string }[] = []
  beforeEach(() => {
    calls.length = 0
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET'
      calls.push({ url, method, body: init?.body as string })
      if (url.endsWith('/app/ai') && method === 'GET') return json(200, { data: {
        mcp_url: 'https://panel.test/api/v1/app/mcp', settings: { level: 'ask', confirm_destructive: true, hide_secrets: true, audit: true },
        levels: ['read_only', 'ask', 'autonomous'], tools: [{ name: 'list_apps', write: false }], tokens: [],
        pending: [{ id: 'x1', client: 'Claude', tool: 'restart_app', target: 'shop', status: 'pending', created_at: new Date().toISOString() }],
        activity: [],
      } })
      if (url.endsWith('/app/ai/tokens')) return json(201, { data: { id: 't1', name: 'Cursor', token: 'abc|secret123' } })
      return json(200, { data: {} })
    }))
  })
  const renderIt = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><MemoryRouter><UserAi /></MemoryRouter></ToastProvider></QueryClientProvider>)

  it('shows the MCP URL, creates a token once and approves a pending change', async () => {
    renderIt()
    expect(await screen.findByText('https://panel.test/api/v1/app/mcp')).toBeInTheDocument()
    await userEvent.type(screen.getByRole('textbox', { name: 'Token name' }), 'Cursor')
    await userEvent.click(screen.getByRole('button', { name: '+ Access token' }))
    expect(await screen.findByText('abc|secret123')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Approve' }))
    expect(calls.some((c) => c.url.endsWith('/app/ai/actions/x1/approve') && c.method === 'POST')).toBe(true)
  })

  it('changes the AI level', async () => {
    renderIt()
    await userEvent.click(await screen.findByRole('tab', { name: 'Permissions' }))
    await userEvent.click(screen.getByRole('radio', { name: /Read-only/ }))
    const patch = calls.find((c) => c.url.endsWith('/app/ai/settings'))
    expect(JSON.parse(patch!.body!)).toEqual({ level: 'read_only' })
  })
})
