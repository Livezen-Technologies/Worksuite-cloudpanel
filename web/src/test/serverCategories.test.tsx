import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import Servers from '../pages/admin/Servers'
import { ToastProvider } from '../components/Toasts'

function json(body: unknown) { return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } }) }
const srv = (id: string, name: string, category?: string) => ({ id, name, hostname: name, ip: '198.51.100.1', environment: 'production', region: 'eu', tags: [], status: 'online', category })

describe('Servers grouped by category', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn(async (url: string) => url.includes('/servers')
      ? json({ data: [srv('1', 'frontdoor-01', 'dns'), srv('2', 'worksuite_LZ', 'hosting'), srv('3', 'old-box'), srv('4', 'vault', 'backup')], meta: { page: 1, per_page: 100, total: 4 } })
      : json({ data: [], meta: { page: 1, per_page: 100, total: 0 } })))
  })

  it('shows sections in the fixed order, counting a server with no category as Hosting', async () => {
    render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><MemoryRouter><Servers /></MemoryRouter></ToastProvider></QueryClientProvider>)
    await screen.findByText('frontdoor-01')
    await userEvent.click(screen.getByRole('button', { name: /Group by/ }))
    await userEvent.click(await screen.findByRole('option', { name: 'Category' }))
    expect(screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent)).toEqual(['DNS', 'Hosting', 'Backup'])
    expect(screen.getAllByText('DNS').length).toBeGreaterThan(1)
  })
})
