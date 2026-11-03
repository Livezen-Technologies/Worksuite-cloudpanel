import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import UserProjects, { initials } from '../pages/app/Projects'
import { layout } from '../components/ProjectCanvas'
import { ToastProvider } from '../components/Toasts'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }

describe('Customer projects', () => {
  const calls: { url: string; method: string; body?: string }[] = []
  beforeEach(() => {
    calls.length = 0
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      calls.push({ url, method: init?.method ?? 'GET', body: init?.body as string })
      if (url.endsWith('/app/projects') && (init?.method ?? 'GET') === 'GET') return json(200, { data: {
        colors: ['blue', 'violet'],
        unassigned: [{ id: 'a2', name: 'blog', status: 'running' }],
        projects: [{ id: 'p1', name: 'Online Store', color: 'violet', cost_cents: 1250, environments: ['production'], apps: [{ id: 'a1', name: 'shop', status: 'running' }], counts: { apps: 1, domains: 2 }, regions: ['eu-west'] }],
      } })
      if (url.endsWith('/app/projects/p1/canvas')) return json(200, { data: {
        nodes: [{ id: 'a1', name: 'web', state: 'running', source: 'nginx', domain: 'shop.test', cost_cents: 500, deployments: [] }, { id: 'a3', name: 'api', state: 'running', source: 'node', domain: 'api.test', cost_cents: 500, deployments: [{ id: 'd1', status: 'failed', source: 'git', created_at: new Date().toISOString() }] }],
        links: [{ from: 'a1', to: 'a3', via: 'API_URL' }],
      } })
      return json(200, { data: {} })
    }))
  })
  const renderIt = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><MemoryRouter><UserProjects /></MemoryRouter></ToastProvider></QueryClientProvider>)

  it('lays callers left of the apps they call', () => {
    const p = layout([{ id: 'a' }, { id: 'b' }, { id: 'c' }], [{ from: 'a', to: 'b' }, { from: 'b', to: 'c' }])
    expect(p.a.x).toBeLessThan(p.b.x)
    expect(p.b.x).toBeLessThan(p.c.x)
    const loop = layout([{ id: 'a' }, { id: 'b' }], [{ from: 'a', to: 'b' }, { from: 'b', to: 'a' }])
    expect(Number.isFinite(loop.a.x) && Number.isFinite(loop.b.x)).toBe(true)
  })

  it('abbreviates names', () => { expect(initials('Online Store')).toBe('OS'); expect(initials('blog')).toBe('BL') })

  it('shows project cards and opens one to add an app', async () => {
    renderIt()
    const card = await screen.findByRole('button', { name: 'Open Online Store' })
    expect(card).toHaveTextContent('OS')
    expect(card).toHaveTextContent('eu-west · 3 resources')
    expect(card).toHaveTextContent('$12.50/mo')
    expect(card).toHaveTextContent('Production')
    await userEvent.click(card)
    await userEvent.click(screen.getByRole('tab', { name: 'Apps' }))
    expect(screen.getByText('shop')).toBeInTheDocument()
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'App to add' }), 'a2')
    await userEvent.click(screen.getByRole('button', { name: '+ Add app' }))
    expect(calls.some((c) => c.url.endsWith('/app/projects/p1/apps') && c.method === 'POST' && c.body?.includes('a2'))).toBe(true)
  })

  it('opens on the canvas and shows the selected app', async () => {
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: 'Open Online Store' }))
    await userEvent.click(await screen.findByRole('listitem', { name: 'web, running' }))
    expect(screen.getByRole('complementary', { name: 'Selected app' })).toHaveTextContent('api (API_URL)')
    await userEvent.click(screen.getByRole('listitem', { name: 'api, running' }))
    expect(screen.getByRole('complementary', { name: 'Selected app' })).toHaveTextContent('failed')
  })

  it('creates a project with a colour and its first environment', async () => {
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: 'New project' }))
    const dialog = screen.getByRole('dialog')
    await userEvent.type(within(dialog).getByRole('textbox', { name: 'Project name' }), 'Agency')
    await userEvent.click(within(dialog).getByRole('button', { name: 'violet' }))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Create project' }))
    const post = calls.find((c) => c.url.endsWith('/app/projects') && c.method === 'POST')
    // Every project now starts with an environment; nothing can be deployed into one without.
    expect(JSON.parse(post!.body!)).toEqual({ name: 'Agency', color: 'violet', environment: 'development' })
  })
})
