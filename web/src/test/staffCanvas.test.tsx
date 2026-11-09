import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { ProjectCanvas } from '../components/ProjectCanvas'
import { ToastProvider } from '../components/Toasts'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }

describe('Project canvas for staff', () => {
  const calls: { url: string; method: string; body?: string }[] = []
  beforeEach(() => {
    calls.length = 0
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET'
      calls.push({ url, method, body: init?.body as string })
      if (url.endsWith('/projects/p1/canvas')) return json(200, { data: {
        nodes: [{ id: 'a1', name: 'web', state: 'running', source: 'acme/web:1', environment_id: 'e1', deployments: [] }, { id: 'a2', name: 'api', state: 'running', source: 'acme/api:1', environment_id: 'e1', deployments: [] }],
        links: [{ from: 'a1', to: 'a2', via: 'API_URL' }], layout: {}, environments: [{ id: 'e1', name: 'Production', kind: 'production' }],
      } })
      return new Response(null, { status: 204 })
    }))
  })
  const renderIt = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><MemoryRouter><ProjectCanvas projectId="p1" staff /></MemoryRouter></ToastProvider></QueryClientProvider>)

  it('reads the staff endpoint, links to the admin app page and has no customer actions', async () => {
    renderIt()
    await userEvent.click(await screen.findByRole('listitem', { name: 'web, running' }))
    expect(calls[0].url).toMatch(/\/api\/v1\/projects\/p1\/canvas$/)
    expect(screen.getByRole('link', { name: 'Open app' })).toHaveAttribute('href', '/admin/applications/a1')
    expect(screen.getByRole('complementary', { name: 'Selected app' })).toHaveTextContent('api via API_URL')
    for (const name of ['Redeploy', 'Restart', 'Connect', 'Remove API_URL']) expect(screen.queryByRole('button', { name })).toBeNull()
  })

  it('saves a dragged layout to the staff endpoint', async () => {
    renderIt()
    const node = await screen.findByRole('listitem', { name: 'api, running' })
    fireEvent.pointerDown(node, { clientX: 0, clientY: 0, pointerId: 1 })
    fireEvent.pointerMove(node, { clientX: 80, clientY: 80, pointerId: 1 })
    fireEvent.pointerUp(node, { clientX: 80, clientY: 80, pointerId: 1 })
    await waitFor(() => expect(calls.some((c) => c.method === 'PUT' && /\/api\/v1\/projects\/p1\/canvas\/layout$/.test(c.url))).toBe(true))
  })
})
