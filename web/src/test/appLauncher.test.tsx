import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AppLauncher, FALLBACK_APPS, LAUNCHER_FEED, isStaff, resetLauncherCache } from '../components/AppLauncher'
import type { Me } from '../api/types'

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })

describe('AppLauncher (staff SSO)', () => {
  beforeEach(() => { resetLauncherCache() })

  it('shows the feed, highlights CloudPanel, opens in the same tab, and Esc returns focus', async () => {
    const fetchMock = vi.fn(async () => json({ apps: [{ id: 'admin', name: 'Cloud Admin', url: 'https://account.worksuitecloud.com/admin/' }, { id: 'panel', name: 'CloudPanel', url: 'https://panel.livezentech.com/admin' }, { id: 'x', name: 'Bad', url: 'javascript:alert(1)' }] }))
    vi.stubGlobal('fetch', fetchMock)
    render(<AppLauncher />)
    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith(LAUNCHER_FEED, expect.anything()))
    const btn = screen.getByRole('button', { name: 'Apps' })
    await userEvent.click(btn)
    const items = await screen.findAllByRole('menuitem')
    expect(items.map((i) => i.textContent)).toEqual(['ClCloud Admin', 'ClCloudPanel'])
    const panel = screen.getByRole('menuitem', { name: /CloudPanel/ })
    expect(panel.getAttribute('aria-current')).toBe('page')
    expect(panel.getAttribute('target')).toBeNull()
    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('menu')).toBeNull()
    expect(document.activeElement).toBe(btn)
  })

  it('falls back to the built-in list when the feed cannot be reached', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('offline') }))
    render(<AppLauncher />)
    await userEvent.click(screen.getByRole('button', { name: 'Apps' }))
    expect((await screen.findAllByRole('menuitem')).length).toBe(FALLBACK_APPS.length)
  })

  it('is for platform staff only', () => {
    const me = (role: string, orgs: string[] = []) => ({ role, organizations: orgs.map((r, i) => ({ id: String(i), name: null, role: r })) }) as unknown as Me
    expect(isStaff(me('super_admin'))).toBe(true)
    expect(isStaff(me('owner', ['devops']))).toBe(true)
    expect(isStaff(me('owner', ['owner']))).toBe(false)
    expect(isStaff(null)).toBe(false)
  })
})
