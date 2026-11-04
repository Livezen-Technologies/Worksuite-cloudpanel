import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SshKeyManager } from '../components/SshKeys'
import { ToastProvider } from '../components/Toasts'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }
const PEM = ['-----BEGIN OPENSSH', 'PRIVATE KEY-----\nAAAA\n-----END OPENSSH', 'PRIVATE KEY-----\n'].join(' ')

describe('SSH keys card', () => {
  const calls: { url: string; method: string; body?: string }[] = []
  let keys: Record<string, unknown>[]
  beforeEach(() => {
    calls.length = 0
    keys = [{ id: 'k1', name: 'desktop', public_key: 'ssh-ed25519 AAAA desktop', fingerprint: 'SHA256:desk', last_used_at: null }]
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET'
      calls.push({ url, method, body: init?.body as string })
      if (url.endsWith('/me/ssh-keys/generate')) return json(201, { data: { id: 'k2', name: 'laptop', public_key: 'ssh-ed25519 BBBB laptop', fingerprint: 'SHA256:lap', private_key: PEM } })
      if (url.endsWith('/me/ssh-keys') && method === 'GET') return json(200, { data: { gateway: { enabled: true, host: 'panel.test', port: 2222 }, keys } })
      if (method === 'DELETE') return new Response(null, { status: 204 })
      return json(201, { data: {} })
    }))
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:x'), revokeObjectURL: vi.fn() }))
  })
  const renderIt = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><SshKeyManager /></ToastProvider></QueryClientProvider>)

  it('lists keys, shows how to connect, generates a key and offers the private key once', async () => {
    renderIt()
    expect(await screen.findByText('SHA256:desk')).toBeInTheDocument()
    expect(screen.getByText('ssh -p 2222 list@panel.test')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Generate a key' }))
    const dlg = screen.getByRole('dialog')
    await userEvent.type(within(dlg).getByRole('textbox'), 'laptop')
    await userEvent.click(within(dlg).getByRole('button', { name: 'Generate' }))
    expect(JSON.parse(calls.find((c) => c.url.endsWith('/generate'))!.body!)).toEqual({ name: 'laptop' })
    const dl = await screen.findByRole('button', { name: 'Download private key' })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    await userEvent.click(dl)
    expect(click).toHaveBeenCalled()
    expect(screen.queryByText(/BEGIN OPENSSH/)).toBeNull()
  })

  it('pastes a public key and deletes one', async () => {
    renderIt()
    await screen.findByText('SHA256:desk')
    await userEvent.click(screen.getByRole('button', { name: 'Add a key' }))
    const dlg = screen.getByRole('dialog')
    const [name, pub] = within(dlg).getAllByRole('textbox')
    await userEvent.type(name, 'work')
    await userEvent.type(pub, 'ssh-ed25519 CCCC work')
    await userEvent.click(within(dlg).getByRole('button', { name: 'Add key' }))
    expect(JSON.parse(calls.find((c) => c.method === 'POST' && c.url.endsWith('/me/ssh-keys'))!.body!)).toEqual({ name: 'work', public_key: 'ssh-ed25519 CCCC work' })
    await userEvent.click(screen.getByRole('button', { name: 'Delete key desktop' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete' }))
    expect(calls.some((c) => c.method === 'DELETE' && c.url.endsWith('/me/ssh-keys/k1'))).toBe(true)
  })
})
