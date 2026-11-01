import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import GitSources from '../pages/admin/GitSources'
import { ToastProvider } from '../components/Toasts'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }

describe('Git sources', () => {
  const calls: { url: string; method: string; body?: string }[] = []
  beforeEach(() => {
    calls.length = 0
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET'
      calls.push({ url, method, body: init?.body as string })
      if (url.endsWith('/git') && method === 'GET') return json(200, { data: {
        connections: [{ id: 'c1', provider: 'github', account: 'acme-bot', status: 'connected', used_by: [] }],
        keys: [{ id: 'k1', name: 'deploy-shop', public_key: 'ssh-ed25519 AAAAC3Nz deploy-shop', fingerprint: 'SHA256:abc', used_by: ['shop'] }],
        deliveries: [{ id: 'd1', event: 'push', repo: 'acme/shop', ref: 'main', outcome: 'rejected', detail: 'Signature invalid.', created_at: new Date().toISOString() }],
      } })
      if (url.endsWith('/git/connections/c1/repositories')) return json(200, { data: [{ name: 'acme/shop', clone_url: 'https://github.com/acme/shop.git', private: true }] })
      return json(201, { data: {} })
    }))
  })
  const renderIt = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><MemoryRouter><GitSources /></MemoryRouter></ToastProvider></QueryClientProvider>)

  it('shows connections, keys and deliveries, and lists repositories', async () => {
    renderIt()
    expect(await screen.findByText('acme-bot')).toBeInTheDocument()
    expect(screen.getByText('SHA256:abc')).toBeInTheDocument()
    expect(screen.getByText('Used by shop')).toBeInTheDocument()
    expect(screen.getByText('Signature invalid.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Repositories' }))
    expect(await screen.findByText('https://github.com/acme/shop.git')).toBeInTheDocument()
  })

  it('connects Bitbucket only with a username and generates a key', async () => {
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: 'Connect Bitbucket' }))
    await userEvent.type(screen.getByLabelText('App password'), 'app-pass-123')
    const connect = screen.getByRole('button', { name: 'Connect' })
    expect(connect).toBeDisabled()
    await userEvent.type(screen.getByLabelText('Username'), 'acme')
    await userEvent.click(connect)
    expect(JSON.parse(calls.find((c) => c.url.endsWith('/git/connections') && c.method === 'POST')!.body!)).toEqual({ provider: 'bitbucket', token: 'app-pass-123', username: 'acme' })

    await userEvent.type(screen.getByRole('textbox', { name: 'Key name' }), 'deploy-api')
    await userEvent.click(screen.getByRole('button', { name: 'Generate key' }))
    expect(JSON.parse(calls.find((c) => c.url.endsWith('/git/keys'))!.body!)).toEqual({ name: 'deploy-api' })
  })
})
