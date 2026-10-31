import { describe, it, expect, vi, beforeEach } from 'vitest'
import { tokenStore } from '../api/client'
import { endImpersonation, startImpersonation } from '../lib/impersonation'

describe('support sessions', () => {
  const assign = vi.fn()
  beforeEach(() => {
    assign.mockReset()
    sessionStorage.clear()
    vi.stubGlobal('location', { ...window.location, assign })
    vi.stubGlobal('fetch', vi.fn(async (url: string) => new Response(
      url.includes('/impersonate') ? JSON.stringify({ data: { token: 'customer-token' } }) : '',
      { status: url.includes('/logout') ? 204 : 200, headers: { 'Content-Type': 'application/json' } })))
  })

  it('keeps the admin token aside and puts it back when the session ends', async () => {
    tokenStore.set('staff-token')
    await startImpersonation('c1', 'Ticket 4411')
    expect(tokenStore.get()).toBe('customer-token')
    expect(assign).toHaveBeenLastCalledWith('/app')

    await endImpersonation()
    expect(tokenStore.get()).toBe('staff-token')
    expect(assign).toHaveBeenLastCalledWith('/admin/customers/c1')
  })
})
