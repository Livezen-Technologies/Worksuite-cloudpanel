import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { validateAppName } from '../lib/format'
import HostApp from '../pages/app/HostApp'

describe('validateAppName', () => {
  it.each(['my-shop', 'abc', 'shop2', 'a-b-c', 'n8n-prod'])('accepts %s', (n) => expect(validateAppName(n)).toBeNull())
  it.each([
    ['', /name/],
    ['ab', /at least 3/],
    ['My-Shop', /lowercase/],
    ['1shop', /Start with a letter/],
    ['my_shop', /only lowercase letters, numbers and hyphens/],
    ['my shop', /only lowercase letters, numbers and hyphens/],
    ['shop-', /end with a hyphen/],
    ['my--shop', /two hyphens/],
    ['a'.repeat(41), /40 characters/],
    ['shop.example', /only lowercase/],
  ])('rejects %j', (n, re) => expect(validateAppName(n as string)).toMatch(re as RegExp))
})

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }

describe('Host an app wizard', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      if (url.includes('/app/apps/validate-source')) return json(200, { data: { ok: true } })
      if (url.includes('/app/templates')) return json(200, { data: [], meta: { page: 1, per_page: 100, total: 0 } })
      if (url.includes('/app/billing/plans')) return json(200, { data: [] })
      if (url.endsWith('/app/apps') && init?.method === 'POST') return json(402, { error: { code: 'quota_exceeded', message: 'You have used all 5 apps on Starter.' } })
      return json(404, { error: { code: 'not_found', message: 'nope' } })
    }))
  })
  const renderIt = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><HostApp /></MemoryRouter></QueryClientProvider>)

  it('validates the source, then shows DNS-safe name errors live and blocks Continue', async () => {
    renderIt()
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    expect(screen.getByRole('alert')).toHaveTextContent(/image name/)
    await userEvent.type(screen.getByRole('textbox', { name: /^Image/ }), 'nginx:1.27-alpine')
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    const name = await screen.findByRole('textbox', { name: /App name/ })
    await userEvent.type(name, 'My_Shop')
    expect(screen.getByText('Use lowercase letters only.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    expect(screen.getByText('Fix the highlighted fields to continue.')).toBeInTheDocument()
    await userEvent.clear(name)
    await userEvent.type(name, 'my-shop')
    expect(screen.queryByText(/Use lowercase/)).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    expect(screen.getByRole('heading', { name: 'Choose a size' })).toBeInTheDocument()
  })

  it('shows an upgrade prompt when the plan quota is exceeded (402)', async () => {
    renderIt()
    await userEvent.type(screen.getByRole('textbox', { name: /^Image/ }), 'nginx')
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    await userEvent.type(await screen.findByRole('textbox', { name: /App name/ }), 'my-shop')
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    await userEvent.click(screen.getByRole('button', { name: 'Deploy my app' }))
    expect(await screen.findByText(/does not fit in your current plan/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Upgrade plan' })).toHaveAttribute('href', '/app/billing')
  })
})
