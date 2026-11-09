import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import UserVps, { money } from '../pages/app/Vps'
import { ToastProvider } from '../components/Toasts'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }
const KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKhkp4Qd0lVbq3KXc8fWl5WQh2v0b3d0aTVpY2hrZXk me@laptop'

describe('My servers (VPS)', () => {
  const calls: { url: string; method: string; body?: string }[] = []
  beforeEach(() => {
    calls.length = 0
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET'
      calls.push({ url, method, body: init?.body as string })
      if (url.endsWith('/app/vps') && method === 'GET') return json(200, { data: {
        limit: 5, regions: ['sg'], images: [{ id: 'ubuntu-2404', label: 'Ubuntu 24.04 LTS' }],
        plans: [{ id: 'ct-2', label: 'CT-2', type: 'container', cpus: 2, memory_mb: 2048, price_cents: 400, available: true }, { id: 'vps-4', label: 'VPS-4 (KVM)', type: 'kvm', cpus: 2, memory_mb: 4096, price_cents: 1200, available: false }],
        servers: [{ id: 'v1', name: 'web-1', status: 'running', plan: { label: 'CT-2', cpus: 2, memory_mb: 2048 }, os: { label: 'Ubuntu 24.04 LTS' }, region: 'sg', ssh: 'ssh -p 22000 root@203.0.113.7' }],
      } })
      return json(202, { data: { job_id: 'j1', status: 'queued' } })
    }))
  })
  const renderIt = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><MemoryRouter><UserVps /></MemoryRouter></ToastProvider></QueryClientProvider>)

  it('formats prices', () => { expect(money(400)).toBe('$4/mo'); expect(money(1250)).toBe('$12.50/mo') })

  it('lists servers with their SSH command and creates one', async () => {
    renderIt()
    expect(await screen.findByText('ssh -p 22000 root@203.0.113.7')).toBeInTheDocument()
    expect(screen.getByText(/1 of 5 on your plan/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Create server' }))
    expect(screen.getByRole('button', { name: /VPS-4 \(KVM\)/ })).toBeDisabled()
    await userEvent.type(screen.getByRole('textbox', { name: 'Server name' }), 'db-1')
    await userEvent.type(screen.getByRole('textbox', { name: /SSH public key/ }), KEY)
    const buttons = screen.getAllByRole('button', { name: 'Create server' })
    await userEvent.click(buttons[buttons.length - 1])
    const post = calls.find((c) => c.url.endsWith('/app/vps') && c.method === 'POST')
    expect(JSON.parse(post!.body!)).toEqual({ name: 'db-1', plan: 'ct-2', os: 'ubuntu-2404', region: null, ssh_keys: [KEY] })
  })

  it('stops a running server', async () => {
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: 'Stop' }))
    expect(calls.some((c) => c.url.endsWith('/app/vps/v1/stop') && c.method === 'POST')).toBe(true)
  })
})
