import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router'
import Projects from '../pages/admin/Projects'
import ProjectDetail from '../pages/admin/ProjectDetail'
import { ToastProvider } from '../components/Toasts'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }
const project = {
  id: 'p1', name: 'Cloudbox', description: null, webhook_url: 'https://panel.test/api/v1/hooks/git/p1', preview_environment_id: null, preview_comments: false,
  organization: { id: 'o2', name: 'Acme Ltd' },
  environments: [
    { id: 'e1', name: 'Production', slug: 'production', kind: 'production', branch: null, requires_approval: true, sleep_after_minutes: null, variables: [],
      apps: [{ id: 'a1', name: 'web', service: 'web', status: 'running', asleep: false, image: 'nginx:1', last_deployed_at: null, url: null }] },
    { id: 'e2', name: 'Staging', slug: 'staging', kind: 'staging', branch: 'dev', requires_approval: false, sleep_after_minutes: null, variables: [], apps: [] },
  ],
}

describe('admin projects list and details', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn(async (url: string) => {
      if (/\/projects\/p1$/.test(url)) return json(200, { data: project })
      if (/\/projects\/p1\/workspace/.test(url)) return json(200, { data: { role: 'viewer', abilities: ['view'], domains: [{ id: 'd1', name: 'shop.example.com', status: 'active', application: 'web' }], deployments: [], networks: [], variables: [], activity: [], members: [], delete_blockers: ['1 application'] } })
      if (/\/projects\/p1\/dashboard/.test(url)) return json(200, { data: { health: 'healthy', counts: { applications: 1, containers: 2, running: 2, nodes: 1 }, applications: [], containers: [], nodes: [{ id: 'n1', name: 'node-a', status: 'online', applications: 1, containers: 2 }] } })
      if (/\/projects(\?|$)/.test(url)) return json(200, { data: [project], meta: { total: 1 } })
      return json(200, { data: [] })
    }))
  })
  const renderAt = (path: string) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider>
    <MemoryRouter initialEntries={[path]}><Routes>
      <Route path="/admin/projects" element={<Projects />} />
      <Route path="/admin/projects/:id" element={<ProjectDetail />} />
    </Routes></MemoryRouter></ToastProvider></QueryClientProvider>)

  it('lists one compact row per project and opens its details', async () => {
    renderAt('/admin/projects')
    expect((await screen.findAllByText('Cloudbox')).length).toBeGreaterThan(0)
    expect(screen.getAllByText('Acme Ltd').length).toBeGreaterThan(0)
    expect(screen.getAllByText(/1\/1 running/).length).toBeGreaterThan(0)
    expect(screen.queryByText('approval required')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'New project' })).toBeInTheDocument()
    await userEvent.click(screen.getAllByText('Cloudbox')[0])
    expect(await screen.findByRole('tab', { name: 'Dashboard' })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByRole('link', { name: 'Projects' })).toHaveAttribute('href', '/admin/projects')
    expect(await screen.findByText('Active nodes')).toBeInTheDocument()
  })

  it('keeps environments and settings on the details tabs', async () => {
    renderAt('/admin/projects/p1?tab=environments')
    expect(await screen.findByText(/no branch · approval required/)).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: 'Duplicate' }).length).toBe(2)
    await userEvent.click(screen.getByRole('tab', { name: 'Settings' }))
    expect(screen.getByText(project.webhook_url)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Set token' })).toBeInTheDocument()
  })

  it('scopes sections to the project and hides actions a viewer may not take', async () => {
    renderAt('/admin/projects/p1?s=docker&sub=nodes')
    expect(await screen.findByText('node-a')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('tab', { name: 'Databases' }))
    expect(screen.getByText(/Not yet available per project/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('tab', { name: 'Settings' }))
    expect(screen.queryByRole('button', { name: /Hand over to customer/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Delete project' })).not.toBeInTheDocument()
  })
})
