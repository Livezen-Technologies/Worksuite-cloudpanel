import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router'
import NewApplicationWizard, { guessPort, suggestName } from '../pages/admin/NewApplicationWizard'
import { ToastProvider } from '../components/Toasts'
import { JobsProvider } from '../api/jobs'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }
const list = (data: unknown[]) => json(200, { data, meta: { page: 1, per_page: 100, total: data.length } })

describe('new application helpers', () => {
  it('suggests a name and a port', () => {
    expect(suggestName('https://github.com/acme/My_Web.git')).toBe('my-web')
    expect(suggestName('ghcr.io/acme/shop-api:1.4')).toBe('shop-api')
    expect(guessPort('nginx:1.27')).toBe(80)
    expect(guessPort('node:22-alpine')).toBe(3000)
    expect(guessPort('acme/custom')).toBeNull()
  })
})

describe('NewApplicationWizard', () => {
  let created: Record<string, unknown> | null
  let failing = false
  beforeEach(() => {
    created = null
    failing = false
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      if (url.includes('/servers')) return list([{ id: 's1', name: 'edge-1', status: 'online', ram_pct: 40, disk_pct: 30 }])
      if (url.includes('/registries') || url.includes('/projects')) return list([])
      if (url.includes('/applications/preflight')) return json(200, { data: { ok: !failing, failed: failing ? 1 : 0, warnings: 0, platform_domain: 'apps.test', detected: { server: { id: 's1', name: 'edge-1' } },
        checks: [{ key: 'image', step: 'source', status: failing ? 'fail' : 'pass', label: 'Docker image', message: failing ? 'We couldn’t find the image.' : 'nginx:1.27 is available.' }, { key: 'server', step: 'deploy', status: 'pass', label: 'Server', message: 'Placed automatically on edge-1.' }] } })
      if (url.endsWith('/applications')) { created = JSON.parse(String(init?.body)); return json(201, { data: { id: 'a1', deployment_id: 'd1', job_id: 'j1', url: 'https://web-abc123.apps.test' } }) }
      return json(404, { error: { code: 'not_found', message: 'nope' } })
    }))
  })

  const view = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><JobsProvider>
    <MemoryRouter initialEntries={['/admin/applications']}><Routes>
      <Route path="/admin/applications" element={<NewApplicationWizard open onClose={() => {}} />} />
      <Route path="/admin/deployments/:id" element={<div>progress page</div>} />
    </Routes></MemoryRouter></JobsProvider></ToastProvider></QueryClientProvider>)

  it('walks four steps with smart defaults, keeps values going back, and opens the progress page', async () => {
    const u = userEvent.setup()
    view()
    expect(screen.getByText('Step 1 of 4')).toBeTruthy()
    await u.click(screen.getByRole('button', { name: /Next: Build/ }))
    expect(screen.getByRole('alert').textContent).toMatch(/Name/)

    await u.click(screen.getByRole('button', { name: /Docker image/ }))
    await u.type(screen.getByLabelText('Docker image'), 'nginx:1.27')
    expect((screen.getByLabelText('Application name') as HTMLInputElement).value).toBe('nginx')
    const name = screen.getByLabelText('Application name')
    await u.clear(name); await u.type(name, 'web')
    await u.click(screen.getByRole('button', { name: /Next: Build/ }))
    expect(screen.getByText(/Nothing to build/)).toBeTruthy()
    expect(screen.queryByLabelText('Dockerfile path')).toBeNull()
    await u.click(screen.getByRole('button', { name: /Next: Deploy/ }))
    expect((screen.getByLabelText(/Container port/) as HTMLInputElement).value).toBe('80')

    await u.click(screen.getByRole('button', { name: 'Previous' }))
    await u.click(screen.getByRole('button', { name: 'Previous' }))
    expect((screen.getByLabelText('Application name') as HTMLInputElement).value).toBe('web')
    await u.click(screen.getByRole('button', { name: /Step 3/ }))
    await u.click(screen.getByRole('button', { name: /Next: Review/ }))
    expect(await screen.findByText('All checks passed', {}, { timeout: 2000 })).toBeTruthy()
    await u.click(screen.getByRole('button', { name: 'Create and deploy' }))
    expect(await screen.findByText('progress page')).toBeTruthy()
    expect(created).toMatchObject({ name: 'web', source_type: 'image', image: 'nginx:1.27', port: 80, domain_mode: 'auto', deploy: true, server_id: null })
  })

  it('blocks create while a check fails', async () => {
    failing = true
    const u = userEvent.setup()
    view()
    await u.click(screen.getByRole('button', { name: /Docker image/ }))
    await u.type(screen.getByLabelText('Docker image'), 'acme/missing:1')
    await u.click(screen.getByRole('button', { name: /Next: Build/ }))
    await u.click(screen.getByRole('button', { name: /Next: Deploy/ }))
    await u.type(screen.getByLabelText(/Container port/), '8080')
    await u.click(screen.getByRole('button', { name: /Next: Review/ }))
    expect(await screen.findByText('1 must be fixed', {}, { timeout: 2000 })).toBeTruthy()
    expect((screen.getByRole('button', { name: 'Create and deploy' }) as HTMLButtonElement).disabled).toBe(true)
  })
})
