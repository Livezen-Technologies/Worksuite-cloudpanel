import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { AppGitTab } from '../pages/admin/AppGitTab'
import { ToastProvider } from '../components/Toasts'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }
const KEY = { id: 'k1', name: 'web-deploy', public_key: 'ssh-ed25519 AAAAC3NzaPUBLIC web-deploy', fingerprint: 'SHA256:abc' }

describe('Git & auto-deploy tab', () => {
  const calls: { url: string; method: string; body?: string }[] = []
  let state: Record<string, unknown>
  beforeEach(() => {
    calls.length = 0
    state = { source_type: 'image', repo: null, branch: null, deploy_on_push: true, deploy_key: null, keys: [], connections: [],
      project: { id: 'p1', name: 'Shop' }, environment: { name: 'Production', branch: null }, webhook: { url: 'https://panel.test/api/v1/hooks/git/p1', has_secret: true }, deliveries: [] }
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET'
      calls.push({ url, method, body: init?.body as string })
      if (url.endsWith('/git/key')) { state = { ...state, deploy_key: KEY, keys: [KEY] }; return json(201, { data: state }) }
      if (url.endsWith('/git/test')) return json(200, { data: { ok: true, message: 'Repository reachable.', commit: 'abcdef1234' } })
      if (url.endsWith('/git/webhook-secret')) return json(200, { data: { webhook_secret: 'whsec-new-123' } })
      if (url.endsWith('/applications/a1/git')) return json(200, { data: state })
      return json(200, { data: {} })
    }))
  })
  const renderIt = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><MemoryRouter><AppGitTab app={{ id: 'a1', source_type: 'image' }} /></MemoryRouter></ToastProvider></QueryClientProvider>)

  it('generates a key, shows the public key, tests, shows the webhook and saves', async () => {
    renderIt()
    const repo = await screen.findByPlaceholderText('git@github.com:acme/shop.git')
    await userEvent.type(repo, 'git@github.com:acme/shop.git')
    expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled()
    await userEvent.click(screen.getByRole('button', { name: 'Generate new key' }))
    expect(await screen.findByLabelText('Public key')).toHaveTextContent('ssh-ed25519 AAAAC3NzaPUBLIC')
    expect(screen.getByText(/Settings → Deploy keys/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Test connection' }))
    expect(await screen.findByText(/Latest commit abcdef1/)).toBeInTheDocument()
    expect(JSON.parse(calls.find((c) => c.url.endsWith('/git/test'))!.body!)).toEqual({ repo: 'git@github.com:acme/shop.git', branch: 'main', git_ssh_key_id: 'k1' })

    expect(screen.getByText('https://panel.test/api/v1/hooks/git/p1')).toBeInTheDocument()
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    await userEvent.click(screen.getByRole('button', { name: 'Show a new secret' }))
    expect(await screen.findByText('whsec-new-123')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('switch', { name: 'Deploy on push' }))
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))
    const save = calls.find((c) => c.method === 'PATCH' && c.url.endsWith('/applications/a1/git'))!
    expect(JSON.parse(save.body!)).toEqual({ repo: 'git@github.com:acme/shop.git', branch: 'main', git_ssh_key_id: 'k1', deploy_on_push: false })
    expect(screen.getByText(/Connection tested/)).toBeInTheDocument()
  })

  it('an https repository needs no key, and an app outside a project is told how to get a webhook', async () => {
    state = { ...state, webhook: null, project: null }
    renderIt()
    await userEvent.type(await screen.findByPlaceholderText('git@github.com:acme/shop.git'), 'https://github.com/acme/site')
    expect(screen.getByText(/Not needed for an https:\/\/ address/)).toBeInTheDocument()
    expect(screen.getByText(/not in a project yet/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled()
  })
})
