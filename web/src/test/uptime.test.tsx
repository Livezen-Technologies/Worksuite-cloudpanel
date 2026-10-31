import { afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import Uptime from '../pages/admin/Uptime'

const json = (body: unknown) => new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })

describe('Uptime page', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('lists sites worst first with their numbers, and shows a site\'s incidents on click', async () => {
    const fetch = vi.fn(async (_url: string) => json({ data: {
      range: '30d',
      summary: { measured: 2, unmeasured: 1, avg_uptime_pct: 99.5, open_incidents: 1 },
      domains: [
        { domain_id: 'd1', domain: 'shop.example.com', uptime_pct: 98.75, checks: 8000, failed: 100, avg_ms: 210, p95_ms: 480,
          incidents: [{ id: 'a1', title: 'shop.example.com is down', status: 'open', started_at: '2026-10-31T09:00:00Z', minutes: 75 }] },
        { domain_id: 'd2', domain: 'blog.example.com', uptime_pct: 100, checks: 8640, failed: 0, avg_ms: 90, p95_ms: 150, incidents: [] },
      ],
    } }))
    vi.stubGlobal('fetch', fetch)
    render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><Uptime /></MemoryRouter></QueryClientProvider>)

    expect(await screen.findByText('shop.example.com')).toBeInTheDocument()
    expect(screen.getByText('98.75%')).toBeInTheDocument()
    expect(screen.getByText('100%')).toBeInTheDocument()
    expect(screen.getByText('1 without checks yet')).toBeInTheDocument()
    expect(fetch.mock.calls.map((c) => String(c[0])).find((u) => !u.includes('table-views'))).toContain('range=30d')

    await userEvent.click(screen.getByText('shop.example.com'))
    expect(screen.getByText('Incidents · shop.example.com')).toBeInTheDocument()
    expect(screen.getByText('ongoing')).toBeInTheDocument()
    expect(screen.getByText(/1 h 15 min/)).toBeInTheDocument()
  })
})
