import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import Kubernetes from '../pages/admin/Kubernetes'
import { ToastProvider } from '../components/Toasts'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }

describe('Kubernetes', () => {
  const calls: { url: string; method: string; body?: string }[] = []
  beforeEach(() => {
    calls.length = 0
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET'
      calls.push({ url, method, body: init?.body as string })
      if (url.endsWith('/kubernetes') && method === 'GET') return json(200, { data: [{ id: 'k1', name: 'prod', status: 'connected', api_url: 'https://10.0.0.5:6443', version: 'v1.31.2', default_namespace: 'shop' }] })
      if (url.includes('/deployments/web/pods')) return json(200, { data: [{ name: 'web-abc12', phase: 'Running', ready: false, restarts: 4, reason: 'CrashLoopBackOff', node: 'node-a', containers: ['web', 'sidecar'] }] })
      if (url.includes('/pods/web-abc12/logs')) return json(200, { data: { lines: [url.includes('previous=1') ? 'before restart' : 'boom: out of memory'] } })
      if (url.includes('/kubernetes/k1') && method === 'GET') return json(200, { data: {
        ingresses: [{ namespace: 'shop', name: 'web', hosts: ['shop.example.com'], service: 'web', tls: true, issuer: 'letsencrypt', address: ['203.0.113.9'], class: 'nginx' }],
        cluster_issuers: [{ name: 'letsencrypt', ready: true }],
        nodes: [{ name: 'node-a', ready: 'True', roles: ['control-plane'], cpu: '4', memory: '8Gi' }],
        namespaces: [{ name: 'shop' }],
        deployments: [{ namespace: 'shop', name: 'web', images: ['nginx:1.27'], replicas: 3, ready: 2 }],
      } })
      return json(201, { data: { ok: true } })
    }))
  })
  const renderIt = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><MemoryRouter><Kubernetes /></MemoryRouter></ToastProvider></QueryClientProvider>)

  it('shows nodes and deployments, scales and deploys into the default namespace', async () => {
    renderIt()
    expect(await screen.findByText('2/3 ready')).toBeInTheDocument()
    expect(screen.getByText('node-a')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'More replicas of web' }))
    expect(JSON.parse(calls.find((c) => c.url.endsWith('/kubernetes/k1/namespaces/shop/deployments/web/scale'))!.body!)).toEqual({ replicas: 4 })
    await userEvent.type(screen.getByRole('textbox', { name: 'Deployment name' }), 'api')
    await userEvent.type(screen.getByRole('textbox', { name: 'Image' }), 'ghcr.io/acme/api:2')
    await userEvent.click(screen.getByRole('button', { name: 'Deploy' }))
    expect(JSON.parse(calls.find((c) => c.url.endsWith('/kubernetes/k1/deployments'))!.body!)).toEqual({ namespace: 'shop', name: 'api', image: 'ghcr.io/acme/api:2', replicas: 1 })
  })

  it('connects with a kubeconfig', async () => {
    renderIt()
    await userEvent.type(await screen.findByRole('textbox', { name: 'Name' }), 'staging')
    await userEvent.type(screen.getByRole('textbox', { name: 'Kubeconfig' }), 'apiVersion: v1')
    await userEvent.click(screen.getByRole('button', { name: 'Connect' }))
    expect(JSON.parse(calls.find((c) => c.url.endsWith('/kubernetes') && c.method === 'POST')!.body!)).toEqual({ name: 'staging', kubeconfig: 'apiVersion: v1' })
  })

  it('lists a deployment\'s pods and reads their logs', async () => {
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: 'Pods' }))
    expect(await screen.findByText('CrashLoopBackOff')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Logs' }))
    expect(await screen.findByText('boom: out of memory')).toBeInTheDocument()
    expect(calls.some((c) => c.url.includes('/kubernetes/k1/namespaces/shop/pods/web-abc12/logs') && c.url.includes('container=web') && c.url.includes('tail=300'))).toBe(true)
    await userEvent.click(screen.getByRole('checkbox', { name: 'Before the last restart' }))
    expect(await screen.findByText('before restart')).toBeInTheDocument()
  })

  it('shows domains and adds one with HTTPS', async () => {
    renderIt()
    expect(await screen.findByText('HTTPS · letsencrypt')).toBeInTheDocument()
    expect(screen.getByText('shop.example.com')).toBeInTheDocument()
    await userEvent.type(screen.getByRole('textbox', { name: 'Domain' }), 'api.example.com')
    await userEvent.type(screen.getByRole('textbox', { name: 'Service' }), 'api')
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'HTTPS certificate' }), 'letsencrypt')
    await userEvent.click(screen.getByRole('button', { name: 'Add domain' }))
    expect(JSON.parse(calls.find((c) => c.url.endsWith('/kubernetes/k1/ingresses'))!.body!)).toEqual({ namespace: 'shop', host: 'api.example.com', service: 'api', port: 80, issuer: 'letsencrypt' })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    await userEvent.click(screen.getByRole('button', { name: 'Delete ingress web' }))
    expect(calls.some((c) => c.method === 'DELETE' && c.url.endsWith('/kubernetes/k1/namespaces/shop/ingresses/web'))).toBe(true)
  })
})
