import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { ProjectDns } from '../components/ProjectDns'
import { ToastProvider } from '../components/Toasts'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }

const domain = {
  id: 'd1', name: 'shop.example.com', app_id: 'a1', app_name: 'web', is_primary: true, dns_status: 'pending', https_status: 'pending', ownership_verified: false,
  status: 'awaiting ownership', platform_subdomain: false, dns_zone: null,
  records: [{ type: 'CNAME', name: 'shop.example.com', value: 'ingress.livezencloud.test', purpose: 'routing' }, { type: 'TXT', name: '_livezen-verify.shop.example.com', value: 'livezen-verify=abc', purpose: 'ownership' }],
}

describe('Project DNS & Domains', () => {
  const calls: { url: string; method: string; body?: string }[] = []
  beforeEach(() => {
    calls.length = 0
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET'
      calls.push({ url, method, body: init?.body as string })
      if (url.endsWith('/app/projects/p1/dns') && method === 'GET') return json(200, { data: {
        counts: { total: 1, verified: 0, pending: 1, ssl_active: 0 }, domains: [domain], apps: [{ id: 'a1', name: 'web', status: 'running' }],
        ingress: { hostname: 'ingress.livezencloud.test', ips: ['203.0.113.50'] }, platform_domain: 'livezen.app',
        permissions: ['view', 'add', 'records', 'primary', 'ssl', 'remove'], is_owner: true, permission_labels: { view: 'View', add: 'Add' },
      } })
      if (url.endsWith('/domains/d1/validate')) return json(200, { data: { ok: false, checked_at: new Date().toISOString(), checks: [
        { key: 'ownership', label: 'Domain ownership (TXT)', status: 'fail', expected: '_livezen-verify.shop.example.com TXT livezen-verify=abc', actual: 'no TXT record', message: 'Add the TXT record shown.' },
        { key: 'cname_target', label: 'CNAME target', status: 'pass', expected: 'ingress.livezencloud.test', actual: 'ingress.livezencloud.test' },
      ], records: [{ ...domain.records[1], actual: [], status: 'fail' }] } })
      if (url.endsWith('/dns/domains') && method === 'POST') return json(201, { data: { ...domain, id: 'd2', name: 'blog.example.com', published: [] } })
      return json(200, { data: [] })
    }))
  })
  const renderIt = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><MemoryRouter><ProjectDns projectId="p1" /></MemoryRouter></ToastProvider></QueryClientProvider>)

  it('lists connected domains with counts and primary flag', async () => {
    renderIt()
    expect(await screen.findByText('shop.example.com')).toBeInTheDocument()
    expect(screen.getByText('Primary')).toBeInTheDocument()
    expect(screen.getByText('awaiting ownership', { selector: 'td' })).toBeInTheDocument()
  })

  it('runs Validate DNS and shows each check with expected and found values', async () => {
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: 'Manage' }))
    await userEvent.click(screen.getByRole('button', { name: 'Validate DNS' }))
    const list = await screen.findByRole('list', { name: 'Validation results' })
    expect(list).toHaveTextContent('Domain ownership (TXT)')
    expect(list).toHaveTextContent('Found: no TXT record')
    expect(screen.getByText('Expected vs actual')).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST' && c.url.endsWith('/app/projects/p1/dns/domains/d1/validate'))).toBe(true)
  })

  it('adds a custom domain through the wizard and shows the exact records', async () => {
    renderIt()
    await userEvent.click(await screen.findByRole('tab', { name: 'Add domain' }))
    await userEvent.type(screen.getByLabelText('Domain'), 'blog.example.com')
    for (let i = 0; i < 3; i++) await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    await userEvent.click(screen.getByRole('button', { name: 'Add domain' }))
    await waitFor(() => expect(screen.getByText(/Next: set up DNS for blog.example.com/)).toBeInTheDocument())
    const post = calls.find((c) => c.method === 'POST' && c.url.endsWith('/dns/domains'))!
    expect(JSON.parse(post.body!)).toMatchObject({ kind: 'custom', name: 'blog.example.com', app_id: 'a1', method: 'manual' })
    expect(screen.getByText('ingress.livezencloud.test')).toBeInTheDocument()
  })
})
