import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import Containers, { containerSearchText } from '../pages/admin/Containers'
import { ToastProvider } from '../components/Toasts'
import type { Container } from '../api/types'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }
const base = { server_id: 's1', state: 'running' }

describe('Containers search', () => {
  const urls: string[] = []
  beforeEach(() => {
    urls.length = 0
    vi.stubGlobal('fetch', vi.fn(async (url: string) => {
      urls.push(url)
      if (url.includes('/containers?')) return json(200, { data: [
        { ...base, id: 'c1', name: 'app-x1', image: 'nginx:1', application_name: 'tender' },
        { ...base, id: 'c2', name: 'eager_tesla', image: 'postgres:16', compose_project: 'shop', compose_service: 'db', docker_id: 'abcdef1234567890' },
        { ...base, id: 'c3', name: 'redis', image: 'redis:7', ports: ['0.0.0.0:6379->6379/tcp'] },
      ], meta: { total: 3 } })
      if (url.includes('/servers')) return json(200, { data: [{ id: 's1', name: 'edge-1' }], meta: { total: 1 } })
      return json(200, { data: [] })
    }))
  })
  const renderIt = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><MemoryRouter><Containers /></MemoryRouter></ToastProvider></QueryClientProvider>)
  const search = async (text: string) => {
    const box = screen.getByPlaceholderText(/Search name, image/)
    await userEvent.clear(box)
    await userEvent.type(box, text)
  }

  it('finds containers by app, stack, ID and port, not only name and image', async () => {
    renderIt()
    expect(await screen.findByText('eager_tesla')).toBeInTheDocument()
    expect(urls.find((u) => u.includes('/containers?'))).toContain('per_page=200')
    await search('tender')
    expect(screen.getByText('app-x1')).toBeInTheDocument()
    expect(screen.queryByText('eager_tesla')).toBeNull()
    await search('shop')
    expect(screen.getByText('eager_tesla')).toBeInTheDocument()
    await search('abcdef123456')
    expect(screen.getByText('eager_tesla')).toBeInTheDocument()
    await search('6379')
    expect(screen.getByText('redis')).toBeInTheDocument()
    expect(screen.queryByText('app-x1')).toBeNull()
  })

  it('builds search text from every field people look for', () => {
    const t = containerSearchText({ id: 'c9', name: 'n', image: 'i', server_id: 's', state: 'running', application_name: 'tender', compose_project: 'p', compose_service: 'svc' } as Container, 'edge-1')
    for (const w of ['n', 'i', 'tender', 'p', 'svc', 'edge-1', 'c9']) expect(t).toContain(w)
  })
})
