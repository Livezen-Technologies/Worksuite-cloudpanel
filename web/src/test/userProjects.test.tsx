import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
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
      if (url.endsWith('/app/projects/p1/environments') && (init?.method ?? 'GET') === 'GET') return json(200, { data: [
        { id: 'e1', name: 'Production', kind: 'production', branch: 'main', duplicated_from_id: null, apps: [{ id: 'a1', name: 'web', status: 'running', url: 'https://shop.test' }] },
        { id: 'e2', name: 'Staging', kind: 'staging', branch: null, duplicated_from_id: 'e1', apps: [] },
      ] })
      if (url.endsWith('/environments/e1/promote')) return json(202, { data: { results: [{ service: 'web', deployment_id: 'd9', skipped: null }] } })
      if (url.endsWith('/app/projects/p1/canvas')) return json(200, { data: {
        nodes: [{ id: 'a1', name: 'web', state: 'running', source: 'nginx', domain: 'shop.test', cost_cents: 500, environment_id: 'e1', deployments: [] }, { id: 'a3', name: 'api', state: 'running', source: 'node', domain: 'api.test', cost_cents: 500, environment_id: 'e1', deployments: [{ id: 'd1', status: 'failed', source: 'git', created_at: new Date().toISOString() }] },
          { id: 'a4', name: 'web-staging', state: 'stopped', source: 'nginx', domain: '', cost_cents: 0, environment_id: 'e2', deployments: [] }],
        links: [{ from: 'a1', to: 'a3', via: 'API_URL' }],
        layout: {}, environments: [{ id: 'e1', name: 'Production', kind: 'production' }, { id: 'e2', name: 'Staging', kind: 'staging' }],
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
    expect(screen.getByRole('complementary', { name: 'Selected app' })).toHaveTextContent('api via API_URL')
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

  const openCanvas = async () => { renderIt(); await userEvent.click(await screen.findByRole('button', { name: 'Open Online Store' })) }

  it('filters the canvas by environment', async () => {
    await openCanvas()
    expect(await screen.findByRole('listitem', { name: 'web-staging, stopped' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Staging' }))
    expect(screen.queryByRole('listitem', { name: 'web, running' })).toBeNull()
    expect(screen.getByRole('listitem', { name: 'web-staging, stopped' })).toBeInTheDocument()
  })

  it('connects two apps and removes a connection', async () => {
    await openCanvas()
    await userEvent.click(await screen.findByRole('listitem', { name: 'api, running' }))
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Connect to' }), 'web')
    await userEvent.type(screen.getByRole('textbox', { name: 'Variable name' }), 'FRONT_URL')
    await userEvent.click(screen.getByRole('button', { name: 'Connect' }))
    expect(JSON.parse(calls.find((c) => c.url.endsWith('/canvas/links') && c.method === 'POST')!.body!)).toEqual({ from: 'a3', to: 'a1', key: 'FRONT_URL' })
    await userEvent.click(screen.getByRole('listitem', { name: 'web, running' }))
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    await userEvent.click(screen.getByRole('button', { name: 'Remove API_URL' }))
    expect(calls.some((c) => c.method === 'DELETE' && c.url.includes('/canvas/links?from=a1&to=a3&key=API_URL'))).toBe(true)
  })

  it('saves where an app is dragged', async () => {
    await openCanvas()
    const node = await screen.findByRole('listitem', { name: 'web, running' })
    fireEvent.pointerDown(node, { clientX: 10, clientY: 10, pointerId: 1 })
    fireEvent.pointerMove(node, { clientX: 110, clientY: 60, pointerId: 1 })
    fireEvent.pointerUp(node, { clientX: 110, clientY: 60, pointerId: 1 })
    await waitFor(() => expect(calls.some((c) => c.method === 'PUT' && c.url.endsWith('/canvas/layout'))).toBe(true))
    const body = JSON.parse(calls.find((c) => c.method === 'PUT')!.body!)
    expect(body.positions.a1.x).toBeGreaterThan(50)
    expect(screen.getByRole('complementary', { name: 'Selected app' })).not.toHaveTextContent('via API_URL')
  })

  it('adds a copied environment, promotes one and deletes an empty one', async () => {
    await openCanvas()
    await userEvent.click(screen.getByRole('tab', { name: 'Environments' }))
    expect(await screen.findByText('copied from Production')).toBeInTheDocument()
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Promote Production to' }), 'Staging')
    await userEvent.click(screen.getByRole('button', { name: 'Promote' }))
    expect(JSON.parse(calls.find((c) => c.url.endsWith('/environments/e1/promote'))!.body!)).toEqual({ target_environment_id: 'e2' })
    await userEvent.click(screen.getByRole('button', { name: 'Delete Staging environment' }))
    expect(calls.some((c) => c.method === 'DELETE' && c.url.endsWith('/app/environments/e2'))).toBe(true)
    await userEvent.click(screen.getByRole('button', { name: '+ Add environment' }))
    const dialog = screen.getByRole('dialog')
    await userEvent.type(within(dialog).getByRole('textbox', { name: 'Name' }), 'QA')
    await userEvent.selectOptions(within(dialog).getByRole('combobox', { name: 'Copy apps from' }), 'Production')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Add environment' }))
    const post = calls.find((c) => c.url.endsWith('/app/projects/p1/environments') && c.method === 'POST')
    expect(JSON.parse(post!.body!)).toEqual({ name: 'QA', kind: 'staging', branch: null, copy_from: 'e1' })
  })
})
