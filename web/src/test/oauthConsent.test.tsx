import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import OAuthConsent from '../pages/auth/OAuthConsent'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }

describe('MCP client consent', () => {
  const posts: string[] = []
  const assign = vi.fn()
  beforeEach(() => {
    posts.length = 0
    assign.mockReset()
    vi.stubGlobal('location', { ...window.location, assign })
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      if (url.includes('/app/oauth/consent') && (init?.method ?? 'GET') === 'GET') return json(200, { data: { name: 'Claude', redirect_host: 'claude.ai' } })
      posts.push(String(init?.body))
      return json(200, { data: { redirect: 'https://claude.ai/api/mcp/auth_callback?code=abc&state=s1' } })
    }))
  })
  const q = '?client_id=mcp_1&redirect_uri=https%3A%2F%2Fclaude.ai%2Fapi%2Fmcp%2Fauth_callback&code_challenge=' + 'a'.repeat(43) + '&state=s1'

  it('shows who is asking (from the server) and approves with changes allowed', async () => {
    render(<MemoryRouter initialEntries={['/oauth/consent' + q]}><OAuthConsent /></MemoryRouter>)
    expect(await screen.findByRole('heading', { name: 'Connect Claude?' })).toBeInTheDocument()
    expect(screen.getByText('claude.ai')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('checkbox', { name: 'Allow changes' }))
    await userEvent.click(screen.getByRole('button', { name: 'Connect' }))
    expect(JSON.parse(posts[0])).toMatchObject({ client_id: 'mcp_1', approve: true, write: true, state: 's1', code_challenge: 'a'.repeat(43) })
    await vi.waitFor(() => expect(assign).toHaveBeenCalledWith('https://claude.ai/api/mcp/auth_callback?code=abc&state=s1'))
  })
})
