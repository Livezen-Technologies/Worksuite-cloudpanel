import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { Account } from '../pages/app/Panel'
import { ToastProvider } from '../components/Toasts'

vi.mock('../lib/auth', () => ({ useAuth: () => ({ me: { user: { mfa_enabled: true } }, refresh: vi.fn() }) }))

function json(status: number, body: unknown) { return new Response(status === 204 ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }

describe('Account → Team', () => {
  const calls: { url: string; method: string; body?: string }[] = []
  let role = 'owner'
  beforeEach(() => {
    calls.length = 0
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET'
      calls.push({ url, method, body: init?.body as string })
      if (url.endsWith('/app/account') && method === 'GET') return json(200, { data: {
        user: { id: 'u1', name: 'Ada', email: 'ada@x.test' }, organization: { id: 'o1', name: 'Acme' }, role,
        team: [{ id: 'u1', name: 'Ada', role: 'owner', status: 'active' }, { id: 'u2', name: 'Ravi', role: 'developer', status: 'active' }],
      } })
      if (method === 'DELETE') return json(204, null)
      return json(200, { data: [] })
    }))
  })
  const renderIt = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><MemoryRouter><Account /></MemoryRouter></ToastProvider></QueryClientProvider>)

  it('lets an owner change a role and remove someone else, never themselves', async () => {
    role = 'owner'
    const user = userEvent.setup()
    renderIt()
    const select = await screen.findByRole('button', { name: /Role for Ravi/ })
    expect(screen.queryByRole('button', { name: /Role for Ada/ })).toBeNull()
    await user.click(select)
    await user.click(await screen.findByRole('option', { name: 'billing' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'PATCH' && c.url.endsWith('/app/account/team/u2') && c.body?.includes('billing'))).toBe(true))
    await user.click(screen.getByRole('button', { name: 'Remove' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Remove' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'DELETE' && c.url.endsWith('/app/account/team/u2'))).toBe(true))
  })

  it('shows roles read-only to non-owners', async () => {
    role = 'developer'
    renderIt()
    await screen.findByText('Ravi')
    expect(screen.queryByRole('button', { name: /Role for/ })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Remove' })).toBeNull()
  })
})
