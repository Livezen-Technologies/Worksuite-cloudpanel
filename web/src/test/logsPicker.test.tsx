import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { Logs } from '../pages/admin/Operations'
import { ToastProvider } from '../components/Toasts'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }

const all = [
  { id: 'c1', name: 'shop-web-1', server_id: 's1', server_name: 'node-05', state: 'running', application_id: 'a1', application_name: 'shop-web', project_id: 'p1' },
  { id: 'c2', name: 'shop-db', server_id: 's1', server_name: 'node-05', state: 'running', application_id: null, project_id: 'p1' },
  { id: 'c3', name: 'other-db', server_id: 's2', server_name: 'tenderhub', state: 'running', application_id: null, project_id: 'p2' },
]

describe('Logs picker', () => {
  const urls: string[] = []
  beforeEach(() => {
    urls.length = 0
    vi.stubGlobal('fetch', vi.fn(async (url: string) => {
      urls.push(url)
      if (url.includes('/projects')) return json(200, { data: [{ id: 'p1', name: 'Shop' }, { id: 'p2', name: 'Other' }], meta: { page: 1, per_page: 200, total: 2 } })
      if (url.includes('/containers?') || url.endsWith('/containers')) {
        const rows = url.includes('project_id=p1') ? all.filter((c) => c.project_id === 'p1') : all
        return json(200, { data: rows, meta: { page: 1, per_page: 200, total: rows.length } })
      }
      if (url.includes('/logs')) return json(200, { data: { lines: [] } })
      return json(200, { data: [] , meta: { page: 1, per_page: 100, total: 0 } })
    }))
  })

  it('narrows containers by project on the server, then by application', async () => {
    render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><MemoryRouter><Logs /></MemoryRouter></ToastProvider></QueryClientProvider>)
    const project = await screen.findByRole('button', { name: /Project/ })
    await userEvent.click(project)
    await userEvent.click(await screen.findByRole('option', { name: 'Shop' }))
    await vi.waitFor(() => expect(urls.some((u) => u.includes('/containers') && u.includes('project_id=p1'))).toBe(true))

    await userEvent.click(screen.getByRole('button', { name: /Application/ }))
    const appOpts = await screen.findAllByRole('option')
    expect(appOpts.map((o) => o.textContent)).toEqual(['All applications', 'shop-web', 'No application'])
    await userEvent.click(appOpts[2])

    await userEvent.click(screen.getByRole('button', { name: /Container/ }))
    const list = await screen.findByRole('listbox')
    expect(within(list).getAllByRole('option').map((o) => o.textContent)).toEqual(['shop-db · node-05'])
  })
})
