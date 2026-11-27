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
  records: [{ type: 'CNAME', name: 'shop.example.com', value: 'ingress.livezencloud.test', purpose: 'routing', status: 'verified' }, { type: 'TXT', name: '_livezen-verify.shop.example.com', value: 'livezen-verify=abc', purpose: 'ownership', status: 'missing' }],
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
        permissions: ['view', 'add', 'records', 'primary', 'ssl', 'remove', 'provider'], is_owner: true, permission_labels: { view: 'View', add: 'Add' },
      } })
      if (url.endsWith('/domains/d1/validate')) return json(200, { data: { ok: false, checked_at: new Date().toISOString(), checks: [
        { key: 'ownership', label: 'Domain ownership (TXT)', status: 'fail', expected: '_livezen-verify.shop.example.com TXT livezen-verify=abc', actual: 'no TXT record', message: 'Add the TXT record shown.' },
        { key: 'cname_target', label: 'CNAME target', status: 'pass', expected: 'ingress.livezencloud.test', actual: 'ingress.livezencloud.test' },
      ], records: [{ ...domain.records[1], actual: [], status: 'fail' }] } })
      if (url.endsWith('/dns/providers') && method === 'POST') return json(201, { data: { id: 'pa1', name: 'Cloudflare', zones: [{ id: 'z1', name: 'example.com', status: 'active' }, { id: 'z2', name: 'other.org', status: 'active' }] } })
      if (url.endsWith('/dns/providers') && method === 'GET') return json(200, { data: [{ id: 'pa1', name: 'Cloudflare', type: 'cloudflare', source: 'customer', can_disconnect: true, zones: [] }] })
      if (url.endsWith('/dns/permissions') && method === 'GET') return json(200, { data: [{ user_id: 'u2', name: 'Dev', email: 'dev@example.test', owner: false, permissions: ['view', 'add'] }] })
      if (url.endsWith('/dns/domains') && method === 'POST') return json(201, { data: { ...domain, id: 'd2', name: 'blog.example.com', published: [] } })
      return json(200, { data: [] })
    }))
  })
  const renderIt = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><MemoryRouter><ProjectDns projectId="p1" /></MemoryRouter></ToastProvider></QueryClientProvider>)

  it('lists connected domains with counts and primary flag', async () => {
    renderIt()
    expect(await screen.findByText('shop.example.com', { selector: 'td.font-semibold' })).toBeInTheDocument()
    expect(screen.getByText('Primary')).toBeInTheDocument()
    expect(screen.getByText('awaiting ownership', { selector: 'td' })).toBeInTheDocument()
  })

  it('lists every record a connected domain needs, with purpose and status', async () => {
    renderIt()
    expect(await screen.findByText('_livezen-verify.shop.example.com')).toBeInTheDocument()
    expect(screen.getByText('ingress.livezencloud.test')).toBeInTheDocument()
    expect(screen.getByText('Proves you own the domain')).toBeInTheDocument()
    expect(screen.getByText('verified')).toBeInTheDocument()
    expect(screen.getByText('missing')).toBeInTheDocument()
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

  it('connects a Cloudflare token, lists its zones and picks the one holding the domain', async () => {
    renderIt()
    await userEvent.click(await screen.findByRole('tab', { name: 'DNS provider' }))
    await userEvent.type(screen.getByLabelText('Cloudflare API token'), 'cf-token-0123456789abcdef')
    await userEvent.click(screen.getByRole('button', { name: 'Connect Cloudflare' }))
    const zones = await screen.findByRole('list', { name: 'Cloudflare zones' })
    expect(zones).toHaveTextContent('example.com')
    const buttons = screen.getAllByRole('button', { name: 'Use for this domain' })
    expect(buttons[1]).toBeDisabled()
    await userEvent.click(buttons[0])
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url.endsWith('/domains/d1/zone'))).toBe(true))
    const post = calls.find((c) => c.url.endsWith('/domains/d1/zone'))!
    expect(JSON.parse(post.body!)).toEqual({ provider_id: 'pa1', zone_id: 'z1' })
    expect(JSON.parse(calls.find((c) => c.method === 'POST' && c.url.endsWith('/dns/providers'))!.body!)).toMatchObject({ type: 'cloudflare', token: 'cf-token-0123456789abcdef' })
  })

  it('revokes every DNS permission of a member after confirmation', async () => {
    renderIt()
    await userEvent.click(await screen.findByRole('tab', { name: 'Permissions' }))
    await userEvent.click(await screen.findByRole('button', { name: 'Revoke all' }))
    expect(screen.getByText(/Revoke all DNS permissions of Dev/)).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'DELETE')).toBe(false)
    await userEvent.click(screen.getAllByRole('button', { name: 'Revoke all' }).at(-1)!)
    await waitFor(() => expect(calls.some((c) => c.method === 'DELETE' && c.url.endsWith('/dns/permissions/u2'))).toBe(true))
  })
})
