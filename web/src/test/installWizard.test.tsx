import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import InstallWizard, { dbFilled, generatePassword } from '../pages/admin/InstallWizard'
import { ToastProvider } from '../components/Toasts'
import { JobsProvider } from '../api/jobs'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }
const list = (data: unknown[]) => json(200, { data, meta: { page: 1, per_page: 100, total: data.length } })
const tpl = { id: 'wordpress', name: 'WordPress', category: 'CMS', type: 'image' as const, version: 2, description: 'Blog', image: 'wordpress:6', port: 80, accent: null, services: null, generated: [],
  variables: [{ key: 'WORDPRESS_DB_HOST', label: 'DB host', default: null, required: true, secret: false }, { key: 'WORDPRESS_DB_PASSWORD', label: 'DB password', default: null, required: true, secret: true }] }

describe('install wizard helpers', () => {
  it('knows which variables a linked database fills', () => {
    expect(dbFilled('WORDPRESS_DB_HOST', ['WORDPRESS_DB_HOST'])).toBe(true)
    expect(dbFilled('PMA_HOST', ['PMA_HOST'])).toBe(true)
    expect(dbFilled('SITE_TITLE', ['SITE_TITLE'])).toBe(false)
  })
  it('generates strong passwords', () => {
    const p = generatePassword()
    expect(p).toHaveLength(24)
    expect(generatePassword()).not.toBe(p)
  })
})

describe('InstallWizard', () => {
  let body: Record<string, unknown> | null
  let blocked = false
  beforeEach(() => {
    body = null
    blocked = false
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      if (url.includes('/servers')) return list([{ id: 's1', name: 'edge-1', status: 'online' }])
      if (url.includes('/databases')) return list([{ id: 'd1', name: 'shopdb', engine: 'mariadb', server_id: 's1' }])
      if (url.includes('/templates/wordpress/preflight')) return json(200, { data: { ok: !blocked, failed: blocked ? 1 : 0, warnings: 0, platform_domain: 'apps.test', checks: blocked ? [{ key: 'name', step: 'details', status: 'fail', label: 'Name', message: '“blog” is already used.' }] : [{ key: 'name', step: 'details', status: 'pass', label: 'Name', message: 'available' }] } })
      if (url.includes('/templates/wordpress/install')) { body = JSON.parse(String(init?.body)); return json(201, { data: { resource_type: 'application', resource_id: 'a1', job_id: 'j1', url: 'https://blog-abc123.apps.test' } }) }
      if (url.includes('/jobs/j1')) return json(200, { data: { id: 'j1', type: 'deploy', status: 'succeeded', progress: 100, created_at: '', stages: [{ name: 'health_check', status: 'succeeded', message: 'GET / → 200' }], result: { url: 'https://blog-abc123.apps.test' } } })
      return json(404, { error: { code: 'not_found', message: 'nope' } })
    }))
  })

  it('walks five steps and installs against a managed database', async () => {
    const u = userEvent.setup()
    render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><JobsProvider><MemoryRouter><InstallWizard t={tpl} onBack={() => {}} /></MemoryRouter></JobsProvider></ToastProvider></QueryClientProvider>)
    expect(screen.getByText('Step 1 of 5')).toBeTruthy()
    const name = screen.getByLabelText(/Application name/)
    await u.clear(name); await u.type(name, 'bad_name')
    await u.click(screen.getByRole('button', { name: 'Continue' }))
    expect(screen.getByRole('alert').textContent).toMatch(/lowercase/)
    await u.clear(name); await u.type(name, 'blog')
    await u.click(screen.getByRole('button', { name: 'Continue' }))
    expect(screen.getByText('Step 2 of 5')).toBeTruthy()
    await u.click(screen.getByRole('button', { name: /Pro/ }))
    await u.click(screen.getByRole('button', { name: 'Continue' }))
    expect(screen.getByText('Step 3 of 5')).toBeTruthy()
    await u.click(screen.getByRole('button', { name: 'Continue' }))
    expect(screen.getByRole('alert').textContent).toMatch(/Choose a database/)
    await u.click(screen.getByText('Choose a database', { selector: 'button *, button' }))
    await u.click(await screen.findByRole('option', { name: /shopdb/ }))
    expect(screen.queryByLabelText(/DB password/)).toBeNull()
    await u.click(screen.getByRole('button', { name: 'Continue' }))
    await u.click(screen.getByRole('button', { name: 'Continue' }))
    expect(screen.getByText('Step 5 of 5')).toBeTruthy()
    await screen.findByText('All checks passed')
    await u.click(screen.getByRole('button', { name: 'Install WordPress' }))
    expect(await screen.findByText('WordPress installed')).toBeTruthy()
    expect(screen.getByText('Running')).toBeTruthy()
    expect(screen.getByText('https://blog-abc123.apps.test')).toBeTruthy()
    expect(body).toMatchObject({ name: 'blog', size: 'pro', database_id: 'd1', server_id: null, variables: {}, domain_mode: 'auto' })
  })

  it('blocks install while a pre-install check fails', async () => {
    blocked = true
    const u = userEvent.setup()
    render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><JobsProvider><MemoryRouter><InstallWizard t={{ ...tpl, variables: [] }} onBack={() => {}} /></MemoryRouter></JobsProvider></ToastProvider></QueryClientProvider>)
    expect(await screen.findByText('“blog” is already used.', {}, { timeout: 2000 })).toBeTruthy()
    for (let i = 0; i < 2; i++) await u.click(screen.getByRole('button', { name: 'Continue' }))
    await u.click(screen.getByRole('button', { name: /Skip for now|No database/ }))
    for (let i = 0; i < 2; i++) await u.click(screen.getByRole('button', { name: 'Continue' }))
    expect(screen.getByText('1 must be fixed')).toBeTruthy()
    expect((screen.getByRole('button', { name: 'Install WordPress' }) as HTMLButtonElement).disabled).toBe(true)
  })
})
