import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { ProjectDomains } from '../pages/admin/ProjectDomains'
import { ToastProvider } from '../components/Toasts'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }

describe('Admin: project domains', () => {
  const calls: { url: string; method: string }[] = []
  beforeEach(() => {
    calls.length = 0
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET'
      calls.push({ url, method })
      if (url.includes('/project-domains/d1/validate')) return json(200, { data: { ok: false, checks: [{ key: 'ownership', label: 'Domain ownership (TXT)', status: 'fail', expected: 'TXT livezen-verify=abc', actual: 'no TXT record' }] } })
      if (url.includes('/project-domains')) return json(200, { data: [
        { id: 'd1', name: 'shop.example.com', status: 'awaiting ownership', dns_status: 'pending', https_status: 'pending', organization_id: 'o1', organization_name: 'Acme', project_name: 'Shop', app_name: 'web', subdomain: true },
        { id: 'd2', name: 'example.co.uk', status: 'active', dns_status: 'verified', https_status: 'active', organization_id: 'o2', organization_name: 'Beta', project_name: 'Site', app_name: 'www', subdomain: false },
      ], meta: { total: 2, page: 1, per_page: 100 } })
      return json(200, { data: [] })
    }))
  })

  it('lists domains across customers with a link to the customer and runs validation', async () => {
    render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><MemoryRouter><ProjectDomains /></MemoryRouter></ToastProvider></QueryClientProvider>)
    expect(await screen.findByText('example.co.uk')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Acme' })).toHaveAttribute('href', '/admin/customers/o1')
    expect(screen.getAllByText('Apex').length).toBeGreaterThan(0)
    const row = screen.getByText('shop.example.com').closest('tr')!
    const menu = within(row).queryByRole('button', { name: 'Validate shop.example.com' }) ?? within(row).getByRole('button', { name: /actions/i })
    await userEvent.click(menu)
    const item = screen.queryByRole('menuitem', { name: 'Validate shop.example.com' }) ?? screen.getByRole('button', { name: 'Validate shop.example.com' })
    await userEvent.click(item)
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url.endsWith('/project-domains/d1/validate'))).toBe(true))
    expect(await screen.findByRole('list', { name: 'Validation results' })).toHaveTextContent('Found: no TXT record')
  })
})
