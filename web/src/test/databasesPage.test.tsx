import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { Databases } from '../pages/admin/Resources'
import { ToastProvider } from '../components/Toasts'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }
const page = (data: unknown[]) => ({ data, meta: { page: 1, per_page: 200, total: data.length } })

describe('Databases', () => {
  const calls: { url: string; method: string; body?: string }[] = []
  let networks: unknown[] = []
  beforeEach(() => {
    calls.length = 0
    networks = [{ id: 'n1', name: 'internal', driver: 'bridge' }, { id: 'n2', name: 'bridge', driver: 'bridge' }]
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET'
      calls.push({ url, method, body: init?.body as string })
      if (url.includes('/databases') && method === 'GET') return json(200, page([{ id: 'd1', name: 'shop-db', engine: 'mariadb', version: '11', status: 'running', server_id: 's1', server_name: 'node-05', last_backup_at: '2026-11-27T05:00:00Z', secret_ref: 'secret://abc' }]))
      if (url.includes('/servers')) return json(200, page([{ id: 's1', name: 'node-05', status: 'online', ram_pct: 20, disk_pct: 30, category: 'hosting' }, { id: 's2', name: 'db-1', status: 'online', ram_pct: 10, disk_pct: 10, category: 'database' }]))
      if (url.includes('/networks')) return json(200, page(networks))
      if (url.includes('/projects')) return json(200, page([]))
      if (url.endsWith('/databases') && method === 'POST') return json(202, { data: { job_id: 'j1', database: { id: 'd2' } } })
      return json(200, page([]))
    }))
  })
  const renderIt = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><MemoryRouter><Databases /></MemoryRouter></ToastProvider></QueryClientProvider>)

  it('creates a PostgreSQL database through the wizard on the internal network', async () => {
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: 'New database' }))
    await userEvent.click(await screen.findByRole('button', { name: /PostgreSQL/ }))
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    await userEvent.type(screen.getByRole('textbox', { name: 'Database name' }), 'Orders DB')
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    const servers = await screen.findAllByRole('button', { name: /online · RAM/ })
    expect(servers[0]).toHaveTextContent('db-1')
    await userEvent.click(servers[0])
    await screen.findByRole('button', { name: /Network/ })
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    await userEvent.click(await screen.findByRole('button', { name: 'Create database' }))
    const post = calls.find((c) => c.method === 'POST' && c.url.endsWith('/databases'))!
    expect(JSON.parse(post.body!)).toMatchObject({ engine: 'postgres', name: 'orders-db', version: '17', server_id: 's2', network: 'internal', environment: 'production' })
  })

  it('stops at placement when the server has no network a database can join', async () => {
    networks = []
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: 'New database' }))
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    await userEvent.type(screen.getByRole('textbox', { name: 'Database name' }), 'x')
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    await userEvent.click((await screen.findAllByRole('button', { name: /online · RAM/ }))[0])
    expect(await screen.findByRole('alert')).toHaveTextContent('has no network a database can join')
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    expect(screen.queryByText('Pre-flight checks')).toBeNull()
  })

  it('offers backup, restore, copy and delete for each database', async () => {
    renderIt()
    await screen.findByText('shop-db')
    await userEvent.click(screen.getByRole('button', { name: 'Back up now' }))
    expect(calls.some((c) => c.method === 'POST' && c.url.endsWith('/databases/d1/backup'))).toBe(true)
    await userEvent.click(screen.getByRole('button', { name: 'More actions' }))
    expect(screen.getByRole('menuitem', { name: /Copy credentials reference/ })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('menuitem', { name: /Delete/ }))
    expect(await screen.findByText('Delete shop-db?')).toBeInTheDocument()
  })
})
