import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import UserProjects, { initials } from '../pages/app/Projects'
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
        projects: [{ id: 'p1', name: 'Online Store', color: 'violet', apps: [{ id: 'a1', name: 'shop', status: 'running' }], counts: { apps: 1, domains: 2 }, regions: ['eu-west'] }],
      } })
      return json(200, { data: {} })
    }))
  })
  const renderIt = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><MemoryRouter><UserProjects /></MemoryRouter></ToastProvider></QueryClientProvider>)

  it('abbreviates names', () => { expect(initials('Online Store')).toBe('OS'); expect(initials('blog')).toBe('BL') })

  it('shows project cards and opens one to add an app', async () => {
    renderIt()
    const card = await screen.findByRole('button', { name: 'Open Online Store' })
    expect(card).toHaveTextContent('OS')
    expect(card).toHaveTextContent('eu-west · 3 resources')
    await userEvent.click(card)
    expect(screen.getByText('shop')).toBeInTheDocument()
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'App to add' }), 'a2')
    await userEvent.click(screen.getByRole('button', { name: '+ Add app' }))
    expect(calls.some((c) => c.url.endsWith('/app/projects/p1/apps') && c.method === 'POST' && c.body?.includes('a2'))).toBe(true)
  })

  it('creates a project with a colour', async () => {
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: 'New project' }))
    const dialog = screen.getByRole('dialog')
    await userEvent.type(within(dialog).getByRole('textbox', { name: 'Project name' }), 'Agency')
    await userEvent.click(within(dialog).getByRole('button', { name: 'violet' }))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Create project' }))
    const post = calls.find((c) => c.url.endsWith('/app/projects') && c.method === 'POST')
    expect(JSON.parse(post!.body!)).toEqual({ name: 'Agency', color: 'violet' })
  })
})
