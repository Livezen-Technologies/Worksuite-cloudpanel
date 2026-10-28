/** Pages render the field names the API really returns (docs/API.md "Implemented response shapes"). */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import type { ReactNode } from 'react'
import { ToastProvider } from '../components/Toasts'
import { JobsProvider } from '../api/jobs'
import Overview from '../pages/admin/Overview'
import { Billing } from '../pages/app/Panel'
import { Domains } from '../pages/admin/Resources'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }
const page = (rows: unknown[]) => ({ data: rows, meta: { page: 1, per_page: 100, total: rows.length } })

const ROUTES: [RegExp, unknown][] = [
  [/\/dashboard/, { data: {
    kpis: { servers_online: 3, servers_total: 4, containers_running: 7, containers_total: 9, cpu_pct: 41.6, ram_pct: 55.2, alerts_open: 1 },
    series: { range: '6h', cpu: [{ t: '2026-10-27T10:00:00Z', v: 40 }], ram: [{ t: '2026-10-27T10:00:00Z', v: 50 }] },
    alerts: [], top: [{ id: 'c1', name: 'db-main', server_id: 's1', cpu_pct: 22, mem_mb: 4096 }],
    deployments: [{ id: 'd1', application_id: 'a1', application: 'shop', status: 'succeeded', source: 'git', trigger: 'manual', author: 'Alex Admin', job_id: 'j1', created_at: '2026-10-27T10:00:00Z', finished_at: null }],
    status: { servers: { online: 3, offline: 1 }, backups_24h: { succeeded: 5, failed: 1, running: 0 }, certificates_expiring: 2, image_scans: { scanned: 4, critical: 0, high: 3, not_scanned: 1 } },
    generated_at: '2026-10-27T10:00:00Z' } }],
  [/\/app\/billing\/plans/, { data: [{ code: 'small', name: 'Small', price_cents: 500, currency: 'EUR', current: true, limits: { apps: 3, ram_mb: 1536, storage_gb: 15, bandwidth_gb: 100 } }] }],
  [/\/app\/billing\/invoices/, page([{ id: 'i1', number: 'INV-202609-0001', period: 'Sep 2026', amount_cents: 1700, currency: 'EUR', status: 'paid', pdf_url: null, created_at: '2026-10-01T00:00:00Z' }])],
  [/\/app\/billing/, { data: {
    plan: { code: 'small', name: 'Small', vcpu: 0.5, ram_mb: 512, storage_gb: 5, price_cents: 500, currency: 'EUR', limits: {} },
    limits: { apps: 3, ram_mb: 1536, storage_gb: 15, bandwidth_gb: 100 }, usage: { apps: 1, ram_mb: 512, storage_gb: 5, bandwidth_gb: 0 },
    usage_pct: { apps: 33.3 }, month_to_date: { amount_cents: 870, currency: 'EUR' },
    payment_method: { brand: 'visa', last4: '4242', exp_month: 12, exp_year: 2029 }, billing_email: 'b@example.com', payment_portal_url: 'https://pay.example/portal' } }],
  [/\/domains/, page([{ id: 'dm1', name: 'shop.example.com', domain: 'shop.example.com', application_id: 'a1', app_id: 'a1', app_name: 'shop', target_port: 8080,
    challenge: 'http-01', dns_status: 'verified', https_status: 'active', records: [], certificate: { issuer: 'letsencrypt', status: 'active', expires_at: '2027-01-01T00:00:00Z', days_left: 30 }, wildcard: false }])],
  [/\/applications/, page([])],
]

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn(async (url: string) => {
    for (const [re, body] of ROUTES) if (re.test(url)) return json(200, body)
    return json(404, { error: { code: 'not_found', message: 'nope' } })
  }))
})
const wrap = (ui: ReactNode) => render(
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    <ToastProvider><JobsProvider><MemoryRouter>{ui}</MemoryRouter></JobsProvider></ToastProvider>
  </QueryClientProvider>)

describe('pages use the implemented API field names', () => {
  it('Overview: KPIs, status cards, deployments and top consumers', async () => {
    wrap(<Overview />)
    expect(await screen.findByText('3 / 4')).toBeInTheDocument()
    expect(screen.getByText('5 succeeded · 1 failed')).toBeInTheDocument()
    expect(screen.getByText('2 expiring soon')).toBeInTheDocument()
    expect(screen.getByText('manual')).toBeInTheDocument()
    expect(screen.getByText(/· 4096 MB/)).toBeInTheDocument()
  })

  it('Billing: cents are formatted with the currency', async () => {
    wrap(<Billing />)
    expect(await screen.findByText(/Small · €5\.00 \/ month/)).toBeInTheDocument()
    expect(screen.getByText('€8.70')).toBeInTheDocument()
    expect(await screen.findByText('€17.00')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Update' })).toHaveAttribute('href', 'https://pay.example/portal')
  })

  it('Domains: app name, port and certificate days come from the real fields', async () => {
    wrap(<Domains />)
    expect(await screen.findByText('shop.example.com')).toBeInTheDocument()
    expect(screen.getByText('shop')).toBeInTheDocument()
    expect(screen.getByText('8080')).toBeInTheDocument()
    expect(screen.getByText('30 days')).toBeInTheDocument()
  })
})
