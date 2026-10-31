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
  beforeEach(() => {
    body = null
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      if (url.includes('/servers')) return list([{ id: 's1', name: 'edge-1', status: 'online' }])
      if (url.includes('/databases')) return list([{ id: 'd1', name: 'shopdb', engine: 'mariadb', server_id: 's1' }])
      if (url.includes('/templates/wordpress/install')) { body = JSON.parse(String(init?.body)); return json(201, { data: { resource_type: 'application', resource_id: 'a1' } }) }
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
    await u.click(screen.getByRole('button', { name: 'Install WordPress' }))
    await screen.findByText(/Application created/)
    expect(body).toMatchObject({ name: 'blog', size: 'pro', database_id: 'd1', server_id: null, variables: {} })
  })
})
