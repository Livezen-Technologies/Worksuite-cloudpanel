/** UBAK-002 download flow, UBIL-003 Stripe portal button, invoice PDF links, backup policy body. */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import type { ReactNode } from 'react'
import { ToastProvider } from '../components/Toasts'
import { JobsProvider } from '../api/jobs'
import { Billing, UserBackups } from '../pages/app/Panel'
import { policyBody } from '../pages/admin/Operations'
import { backupDownloadUrl } from '../lib/download'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }
const page = (rows: unknown[]) => ({ data: rows, meta: { page: 1, per_page: 100, total: rows.length } })

function wrap(ui: ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={qc}><MemoryRouter><ToastProvider><JobsProvider>{ui}</JobsProvider></ToastProvider></MemoryRouter></QueryClientProvider>)
}

let calls: { url: string; method: string }[] = []
function routes(table: [RegExp, (n: number) => [number, unknown]][]) {
  const counts = new Map<RegExp, number>()
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    calls.push({ url, method: init?.method ?? 'GET' })
    for (const [re, fn] of table) if (re.test(url)) { const n = (counts.get(re) ?? 0) + 1; counts.set(re, n); const [s, b] = fn(n); return json(s, b) }
    return json(404, { error: { code: 'not_found', message: 'nope' } })
  }))
}

beforeEach(() => { calls = [] })

describe('backup download', () => {
  it('exports first, waits for the job, then returns the signed URL', async () => {
    routes([
      [/\/app\/backups\/b1\/download/, (n) => (n === 1 ? [202, { data: { status: 'exporting', job_id: 'j1' } }] : [200, { data: { status: 'ready', url: 'https://panel/api/v1/backup-download/b1?signature=x', expires_at: '2026-10-27T12:15:00Z' } }])],
      [/\/jobs\/j1/, (n) => [200, { data: { id: 'j1', status: n < 2 ? 'running' : 'succeeded' } }]],
    ])
    const exporting = vi.fn()
    const url = await backupDownloadUrl('/app/backups/b1/download', { onExporting: exporting, pollMs: 1 })
    expect(url).toContain('signature=')
    expect(exporting).toHaveBeenCalledWith('j1')
    expect(calls.filter((c) => c.url.includes('/jobs/j1')).length).toBe(2)
  })

  it('surfaces a failed export', async () => {
    routes([
      [/\/download/, () => [202, { data: { status: 'exporting', job_id: 'j2' } }]],
      [/\/jobs\/j2/, () => [200, { data: { id: 'j2', status: 'failed', error: 'restic dump: repository locked' } }]],
    ])
    await expect(backupDownloadUrl('/app/backups/b2/download', { pollMs: 1 })).rejects.toThrow('repository locked')
  })

  it('shows restore-test status on customer backups', async () => {
    routes([
      [/\/app\/apps/, () => [200, page([{ id: 'a1', name: 'shop' }])]],
      [/\/app\/backups/, () => [200, page([{ id: 'b1', app_id: 'a1', app_name: 'shop', size_mb: 2, verified: true, encrypted: true, trigger: 'schedule', created_at: '2026-10-27T03:00:00Z', expires_at: null,
        restore_test: { status: 'passed', tested_at: '2026-10-27T04:00:00Z', message: 'Restored 12 file(s)' } }])]],
    ])
    wrap(<UserBackups />)
    expect(await screen.findByText(/restore test passed/)).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Download' })).toBeTruthy()
  })
})

describe('billing', () => {
  it('opens a fresh Stripe portal session and links real invoice PDFs', async () => {
    const assign = vi.fn()
    vi.stubGlobal('location', { ...window.location, assign })
    routes([
      [/\/app\/billing\/plans/, () => [200, { data: [] }]],
      [/\/app\/billing\/invoices/, () => [200, page([{ id: 'i1', number: 'INV-202609-ABCD', period: 'Sep 2026', amount_cents: 1309, tax_cents: 209, currency: 'EUR', status: 'open', pdf_url: 'https://panel/api/v1/invoices/i1/pdf?expires=1&signature=s' }])]],
      [/\/app\/billing\/portal/, () => [200, { data: { url: 'https://billing.stripe.com/p/session/x' } }]],
      [/\/app\/billing/, () => [200, { data: { plan: { code: 'small', name: 'Small', price_cents: 500, currency: 'EUR' }, limits: {}, usage: {}, month_to_date: { amount_cents: 0, currency: 'EUR' },
        payment_method: { brand: 'visa', last4: '4242' }, billing_email: 'b@x', payment_provider: 'stripe', payment_portal_url: 'https://billing.stripe.com/p/session/old' } }]],
    ])
    wrap(<Billing />)
    const pdf = await screen.findByRole('link', { name: /Download invoice INV-202609-ABCD as PDF/ })
    expect(pdf.getAttribute('href')).toContain('signature=')
    fireEvent.click(screen.getByRole('button', { name: 'Manage in Stripe' }))
    await waitFor(() => expect(assign).toHaveBeenCalledWith('https://billing.stripe.com/p/session/x'))
    expect(calls.some((c) => c.url.includes('/app/billing/portal') && c.method === 'POST')).toBe(true)
  })
})

describe('backup policy form', () => {
  it('nests destination config and never sends blank keys', () => {
    const body = policyBody({ name: 'p', destination: 's3', dc_endpoint: 'http://minio:9000', dc_bucket: 'bk', dc_access_key: '', dc_secret_key: '', destination_summary: { has_secret_key: true }, restore_test_schedule: '' })
    expect(body).toEqual({ name: 'p', destination: 's3', destination_config: { endpoint: 'http://minio:9000', bucket: 'bk' }, restore_test_schedule: null, encrypted: true })
  })

  it('sends retention counts as numbers and clears emptied ones', () => {
    const body = policyBody({ name: 'p', destination: 'sftp', dc_host: 'h', dc_user: 'u', dc_private_key: '', keep_daily: '7', keep_weekly: '', usage: { percent: 1 } })
    expect(body).toMatchObject({ keep_daily: 7, keep_weekly: null, destination_config: { host: 'h', user: 'u' } })
    expect(body).not.toHaveProperty('usage')
    expect(body).not.toHaveProperty('keep_monthly')
  })
})
