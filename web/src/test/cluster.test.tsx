import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import Cluster from '../pages/admin/Cluster'
import { ToastProvider } from '../components/Toasts'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }

describe('Cluster (Docker Swarm)', () => {
  const calls: { url: string; method: string; body?: string }[] = []
  beforeEach(() => {
    calls.length = 0
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET'
      calls.push({ url, method, body: init?.body as string })
      if (url.endsWith('/swarm') && method === 'GET') return json(200, { data: {
        available_servers: [{ id: 's3', name: 'w2', ip: '10.0.0.3' }],
        clusters: [{ id: 'c1', name: 'prod', status: 'active', advertise_addr: '10.0.0.1',
          nodes: [{ id: 'node1abcdef1234', hostname: 'w1', role: 'worker', availability: 'active', tasks: 2, labels: { zone: 'a' } }],
          services: [{ id: 'svc1abcdef1234', name: 'web', image: 'nginx:1', replicas: 3, running: 3 }] }],
      } })
      return json(202, { data: { job_id: 'j1', status: 'queued' } })
    }))
  })
  const renderIt = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><MemoryRouter><Cluster /></MemoryRouter></ToastProvider></QueryClientProvider>)

  it('shows nodes and services, drains a node and scales a service', async () => {
    renderIt()
    expect(await screen.findByText('3/3 running')).toBeInTheDocument()
    expect(screen.getByText('zone=a')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Drain' }))
    expect(JSON.parse(calls.find((c) => c.url.endsWith('/swarm/c1/nodes/node1abcdef1234'))!.body!)).toEqual({ availability: 'drain' })
    await userEvent.click(screen.getByRole('button', { name: 'More replicas of web' }))
    expect(JSON.parse(calls.find((c) => c.url.endsWith('/swarm/c1/services/svc1abcdef1234/scale'))!.body!)).toEqual({ replicas: 4 })
  })
})
