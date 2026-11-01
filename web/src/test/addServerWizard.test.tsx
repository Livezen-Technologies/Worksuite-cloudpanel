import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { AddServerWizard } from '../pages/admin/Servers'
import { ToastProvider } from '../components/Toasts'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }

describe('AddServerWizard', () => {
  let posted: Record<string, unknown> | null
  let patched: Record<string, unknown> | null
  beforeEach(() => {
    posted = null
    patched = null
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      if (url.endsWith('/servers') && init?.method === 'POST') { posted = JSON.parse(String(init.body)); return json(201, { data: { id: 's1', name: 'vps-eu-03', status: 'pending' } }) }
      if (url.endsWith('/servers/enrollment')) return json(201, { data: { token: 'abcdef123456', expires_at: new Date(Date.now() + 900000).toISOString(), install_command: 'curl -fsSL https://panel.test/install.sh | sh -s abcdef123456' } })
      if (url.endsWith('/servers/s1/verification')) return json(200, { data: { checks: [{ name: 'Docker', ok: true, detail: '27.1' }, { name: 'Disk', ok: true, detail: '20% used' }] } })
      if (url.endsWith('/servers/s1') && init?.method === 'PATCH') { patched = JSON.parse(String(init.body)); return json(200, { data: { id: 's1' } }) }
      if (url.endsWith('/servers/s1')) return json(200, { data: { id: 's1', name: 'vps-eu-03', status: 'online' } })
      return json(404, { error: { code: 'not_found', message: 'nope' } })
    }))
  })

  it('creates the server, shows the install command, waits for the agent, verifies and configures', async () => {
    const u = userEvent.setup()
    render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><MemoryRouter><AddServerWizard open onClose={() => {}} /></MemoryRouter></ToastProvider></QueryClientProvider>)
    expect(screen.getByText('Step 1 of 6')).toBeTruthy()
    await u.click(screen.getByRole('button', { name: /Next: Connection/ }))
    expect(screen.getByText('Fix the highlighted fields.')).toBeTruthy()
    await u.type(screen.getByLabelText(/^Server name/), 'vps-eu-03')
    await u.type(screen.getByLabelText(/^IP address/), '203.0.113.10')
    await u.click(screen.getByRole('button', { name: /DigitalOcean/ }))
    await u.click(screen.getByRole('button', { name: /Next: Connection/ }))
    expect(screen.getByText('Step 2 of 6')).toBeTruthy()
    await u.click(screen.getByRole('button', { name: 'Create server & get command' }))
    expect(await screen.findByText(/curl -fsSL/)).toBeTruthy()
    expect(posted).toMatchObject({ name: 'vps-eu-03', ip: '203.0.113.10', provider: 'DigitalOcean', connection_method: 'agent' })
    expect(await screen.findByText('Agent heartbeat received', {}, { timeout: 4000 })).toBeTruthy()
    await u.click(screen.getByRole('button', { name: 'Next: Verification' }))
    await u.click(await screen.findByRole('button', { name: 'Next: Configuration' }, { timeout: 3000 }))
    await u.clear(screen.getByLabelText(/Scheduling weight/)); await u.type(screen.getByLabelText(/Scheduling weight/), '150')
    await u.click(screen.getByRole('button', { name: 'Save and finish' }))
    expect(await screen.findByText('vps-eu-03 is ready')).toBeTruthy()
    expect(patched).toMatchObject({ scheduling_weight: 150 })
  })
})
