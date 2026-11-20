import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, waitFor, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AuthProvider } from '../lib/auth'
import { Login } from '../pages/auth/Auth'
import { claimSilentAttempt, markSignedIn, markSignedOut, rememberNext, safeNext, takeNext } from '../lib/wscAuto'

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })

describe('WorkSuite Cloud auto sign-in', () => {
  const assign = vi.fn()
  let calls: string[] = []
  beforeEach(() => {
    sessionStorage.clear(); localStorage.clear(); calls = []; assign.mockReset()
    vi.stubGlobal('location', { ...window.location, assign })
    vi.stubGlobal('fetch', vi.fn(async (url: string) => {
      calls.push(String(url))
      if (String(url).includes('/auth/config')) return json({ data: { captcha: null, sso: [{ key: 'wsc', label: 'WorkSuite Cloud' }] } })
      if (String(url).includes('/auth/sso/wsc')) return json({ data: { url: 'https://account.worksuitecloud.test/oauth/authorize?prompt=none' } })
      return json({}, 404)
    }))
  })
  const at = (path: string, state?: unknown) => render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><AuthProvider>
      <MemoryRouter initialEntries={[state ? { pathname: path.split('?')[0], search: path.includes('?') ? '?' + path.split('?')[1] : '', state } : path]}>
        <Routes><Route path="/login" element={<Login />} /></Routes>
      </MemoryRouter></AuthProvider></QueryClientProvider>)

  it('runs the silent flow on load without a click, and keeps the deep link', async () => {
    at('/login', { from: '/admin/servers?tab=x' })
    await waitFor(() => expect(assign).toHaveBeenCalledWith('https://account.worksuitecloud.test/oauth/authorize?prompt=none'))
    expect(calls.some((u) => u.includes('/auth/sso/wsc?prompt=none'))).toBe(true)
    expect(takeNext()).toBe('/admin/servers?tab=x')
  })

  it('tries only once per tab', async () => {
    sessionStorage.setItem('wsc_silent_tried', '1')
    at('/login?sso=1')
    await screen.findByRole('button', { name: /Sign in with WorkSuite Cloud/ })
    expect(assign).not.toHaveBeenCalled()
  })

  it('skips after an explicit sign-out and with ?local=1', async () => {
    markSignedOut()
    at('/login')
    await screen.findByRole('button', { name: /Sign in with WorkSuite Cloud/ })
    expect(assign).not.toHaveBeenCalled()
    markSignedIn()
    expect(claimSilentAttempt(new URLSearchParams('local=1'))).toBe(false)
    expect(claimSilentAttempt(new URLSearchParams(''))).toBe(true)
    expect(claimSilentAttempt(new URLSearchParams(''))).toBe(false)
  })

  it('shows a short message after access_denied and does not retry', async () => {
    at('/login?wsc=denied')
    expect(await screen.findByRole('alert')).toHaveTextContent(/don't have access to CloudPanel/)
    expect(assign).not.toHaveBeenCalled()
  })

  it('only keeps same-origin next paths', () => {
    expect(safeNext('/admin/x')).toBe('/admin/x')
    for (const bad of ['//evil.test', 'https://evil.test', '/\\evil.test', '/login', 'admin']) expect(safeNext(bad)).toBeNull()
    rememberNext('//evil.test'); expect(takeNext()).toBeNull()
  })
})
