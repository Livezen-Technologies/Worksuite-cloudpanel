import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AuthProvider } from '../lib/auth'
import { SiteLayout } from '../site/SiteLayout'
import { Contact, Pricing, SiteHome, Templates } from '../site/pages'

function json(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }

describe('Marketing site', () => {
  const calls: { url: string; method: string; body?: string }[] = []
  let contactStatus = 201
  beforeEach(() => {
    calls.length = 0; contactStatus = 201
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET'
      calls.push({ url, method, body: init?.body as string })
      if (url.endsWith('/site/contact')) return contactStatus === 201 ? json(201, { data: { received: true } }) : json(422, { error: { code: 'validation_failed', message: 'Invalid', fields: { email: ['The email must be a valid email address.'] } } })
      if (url.endsWith('/auth/config')) return json(200, { data: { captcha: null, sso: [] } })
      return json(401, { error: { code: 'unauthenticated' } })
    }))
  })
  const at = (path: string) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><AuthProvider><MemoryRouter initialEntries={[path]}><Routes><Route element={<SiteLayout />}>
    <Route path="/" element={<SiteHome />} /><Route path="/pricing" element={<Pricing />} /><Route path="/templates" element={<Templates />} /><Route path="/contact" element={<Contact />} />
  </Route></Routes></MemoryRouter></AuthProvider></QueryClientProvider>)

  it('home shows the hero, sign-in links and toggles the theme', async () => {
    const { container } = at('/')
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Deploy anything.')
    expect(screen.getByRole('link', { name: 'Sign in' })).toHaveAttribute('href', '/login')
    const site = container.querySelector('.lz-site')!
    const before = site.getAttribute('data-theme')
    await userEvent.click(screen.getByRole('button', { name: /Use (dark|light) theme/ }))
    expect(site.getAttribute('data-theme')).not.toBe(before)
  })

  it('pricing switches to yearly prices and estimates usage', async () => {
    at('/pricing')
    expect(screen.getByTestId('price-Pro')).toHaveTextContent('$20')
    await userEvent.click(screen.getByRole('button', { name: /Yearly/ }))
    expect(screen.getByTestId('price-Pro')).toHaveTextContent('$16')
    expect(screen.getByTestId('estimate')).toHaveTextContent('$22.50')
  })

  it('templates filter by category and search', async () => {
    at('/templates')
    await userEvent.click(screen.getByRole('button', { name: 'Monitoring' }))
    expect(screen.getByText('Grafana')).toBeInTheDocument()
    expect(screen.queryByText('WordPress')).toBeNull()
    await userEvent.type(screen.getByLabelText('Search templates'), 'kuma')
    expect(screen.queryByText('Grafana')).toBeNull()
  })

  it('contact form posts the lead and shows field errors', async () => {
    contactStatus = 422
    at('/contact')
    await userEvent.type(screen.getByLabelText('Name'), 'Ann')
    await userEvent.type(screen.getByLabelText('Work email'), 'ann@example.com')
    await userEvent.click(screen.getByRole('button', { name: 'Send message' }))
    expect(await screen.findByText('The email must be a valid email address.')).toBeInTheDocument()
    contactStatus = 201
    await userEvent.click(screen.getByRole('button', { name: 'Send message' }))
    expect(await screen.findByText(/we got your message/)).toBeInTheDocument()
    const body = JSON.parse(calls.filter((c) => c.url.endsWith('/site/contact')).pop()!.body!)
    expect(body).toMatchObject({ name: 'Ann', email: 'ann@example.com', website: '', budget: null })
  })
})

describe('Website changelog and policies', () => {
  it('parses Keep a Changelog releases and skips Unreleased', async () => {
    const { parseChangelog } = await import('../site/changelog')
    const r = parseChangelog('# Changelog\n\n## [Unreleased]\n\n### Added\n\n- Not yet\n\n## [1.2.0] — 2026-11-01\n\n### Added\n\n- A `thing` that\n  spans lines (#9)\n- **Bold** [link](https://x)\n\n### Fixed\n\n## [1.1.0] — 2026-10-01\n\n### Fixed\n\n- Bug\n')
    expect(r).toEqual([
      { version: '1.2.0', date: '2026-11-01', groups: [{ k: 'Added', items: ['A thing that spans lines (#9)', 'Bold link'] }] },
      { version: '1.1.0', date: '2026-10-01', groups: [{ k: 'Fixed', items: ['Bug'] }] },
    ])
  })
  it('the real CHANGELOG.md has the current release first', async () => {
    const { parseChangelog } = await import('../site/changelog')
    const md = (await import('../../../CHANGELOG.md?raw')).default
    const version = (await import('../../../VERSION?raw')).default.trim()
    expect(parseChangelog(md)[0].version).toBe(version)
  })
  it('every legal section has text', async () => {
    const { POLICIES } = await import('../site/legal')
    const { LEGAL_NAV } = await import('../site/content')
    for (const [, id] of LEGAL_NAV.slice(1)) expect(POLICIES[id as keyof typeof POLICIES].length).toBeGreaterThan(2)
  })
})
