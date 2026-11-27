import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { VpnNetworks } from '../components/VpnNetworks'
import { ToastProvider } from '../components/Toasts'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }
const page = (data: unknown[]) => ({ data, meta: { page: 1, per_page: 200, total: data.length } })

describe('VPN networks', () => {
  const calls: { url: string; method: string; body?: string }[] = []
  beforeEach(() => {
    calls.length = 0
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET'
      calls.push({ url, method, body: init?.body as string })
      if (url.includes('/vpn/networks/suggest')) return json(200, { data: { cidr: '10.90.0.0/24', mesh: '10.88.0.0/16' } })
      if (url.includes('/vpn/networks') && method === 'GET') return json(200, page([]))
      if (url.includes('/servers')) return json(200, page([{ id: 's1', name: 'livezen-panel-01', ip: '51.89.7.73', status: 'online', category: 'hosting' }, { id: 'f1', name: 'frontdoor-01', ip: '51.254.220.203', status: 'online', category: 'dns' }]))
      if (url.endsWith('/vpn/networks') && method === 'POST') return json(201, { data: { id: 'v1' } })
      return json(200, page([]))
    }))
  })

  it('creates a WireGuard network on a DNS gateway with the suggested range', async () => {
    render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><MemoryRouter><VpnNetworks /></MemoryRouter></ToastProvider></QueryClientProvider>)
    await userEvent.click((await screen.findAllByRole('button', { name: 'Create VPN network' }))[0])
    await userEvent.type(await screen.findByRole('textbox', { name: 'VPN network name' }), 'Livezen Private')
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    const gws = await screen.findAllByRole('button', { name: /online/ })
    expect(gws[0]).toHaveTextContent('frontdoor-01')
    await userEvent.click(gws[0])
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    expect(screen.getByRole('textbox', { name: 'Private range' })).toHaveValue('10.90.0.0/24')
    expect(screen.getByText('253 device addresses')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /Full tunnel/ }))
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    await userEvent.click(await screen.findByRole('button', { name: 'Save network' }))
    const post = calls.find((c) => c.method === 'POST' && c.url.endsWith('/vpn/networks'))!
    expect(JSON.parse(post.body!)).toMatchObject({ name: 'Livezen Private', protocol: 'wireguard', gateway_server_id: 'f1', cidr: '10.90.0.0/24', port: 51821, transport: 'udp', routing: 'full', private_dns: true })
  })
})
