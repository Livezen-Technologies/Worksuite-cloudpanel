import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { SignInApps } from '../components/SignInApps'
import { ToastProvider } from '../components/Toasts'

const json = (body: unknown) => new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })

describe('SignInApps', () => {
  let patched: unknown = null
  beforeEach(() => {
    patched = null
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      if (init?.method === 'PATCH') { patched = JSON.parse(String(init.body)); return json({ data: [{ key: 'google_login', label: 'Google', email: 'a@x.lk', last_used_at: '2026-10-30T10:00:00Z', blocked: true }] }) }
      if (url.includes('/me/sign-in-apps')) return json({ data: [{ key: 'google_login', label: 'Google', email: 'a@x.lk', last_used_at: '2026-10-30T10:00:00Z', blocked: false }] })
      return json({ data: [] })
    }))
  })

  it('lists the apps with last use and turns one off', async () => {
    render(<ToastProvider><SignInApps /></ToastProvider>)
    expect(await screen.findByText(/a@x.lk · last used/)).toBeTruthy()
    await userEvent.click(screen.getByRole('switch', { name: 'Allow signing in with Google' }))
    expect(patched).toEqual({ blocked: true })
    expect(await screen.findByText(/turned off for your account/)).toBeTruthy()
  })
})
