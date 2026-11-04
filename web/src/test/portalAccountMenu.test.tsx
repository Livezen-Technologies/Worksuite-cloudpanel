import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { Shell } from '../components/Shell'

const switchOrg = vi.fn()
const createOrg = vi.fn()
const me = {
  user: { name: 'Ada Perera', email: 'ada@example.com' },
  organization: { id: 'org-1', name: 'Flxware' },
  organizations: [{ id: 'org-1', name: 'Flxware', role: 'owner' }, { id: 'org-2', name: 'Side Project', role: 'owner' }],
  role: 'owner', permissions: [],
}
vi.mock('../lib/auth', () => ({
  useAuth: () => ({ me, logout: vi.fn(), switchOrg, createOrg }),
  CUSTOMER_ROLES: ['owner'],
  homeFor: () => '/app',
}))

const shell = (area: 'admin' | 'portal') => render(
  <MemoryRouter>
    <Shell area={area} label="Overview" nav={[]} brand={{ title: 'My Cloud' }} />
  </MemoryRouter>,
)
const openMenu = async () => {
  const user = userEvent.setup()
  await user.click(screen.getByRole('button', { expanded: false, name: /Ada Perera/ }))
  return user
}

beforeEach(() => { switchOrg.mockReset(); createOrg.mockReset() })

/**
 * The account menu is shared with the admin panel, so each change here has to
 * be shown not to reach /admin - a customer cannot open those pages at all.
 */
describe('the customer portal account menu', () => {
  it('points "My Account" at the portal, not at /admin', async () => {
    shell('portal')
    await openMenu()
    const item = screen.getByRole('menuitem', { name: 'My Account' })
    expect(item.getAttribute('href')).toBe('/app/account')
    // The admin-only organisation settings link has no place in the portal.
    expect(screen.queryByRole('menuitem', { name: 'Settings' })).toBeNull()
  })

  it('offers the three theme choices with the one in force ticked', async () => {
    shell('portal')
    const user = await openMenu()
    await user.click(screen.getByRole('menuitem', { name: 'Theme' }))
    const system = screen.getByRole('menuitemradio', { name: 'System' })
    // Nothing is stored in a fresh jsdom, which is what 'system' means.
    expect(system.getAttribute('aria-checked')).toBe('true')
    await user.click(screen.getByRole('menuitemradio', { name: 'Dark' }))
    expect(document.documentElement.classList.contains('dark')).toBe(true)
  })

  it('switches to another organisation and does nothing for the current one', async () => {
    shell('portal')
    const user = await openMenu()
    await user.click(screen.getByRole('menuitem', { name: 'Organization' }))
    expect(screen.getByRole('menuitemradio', { name: 'Flxware' }).getAttribute('aria-checked')).toBe('true')
    await user.click(screen.getByRole('menuitemradio', { name: 'Flxware' }))
    expect(switchOrg).not.toHaveBeenCalled()

    await openMenu()
    await user.click(screen.getByRole('menuitem', { name: 'Organization' }))
    await user.click(screen.getByRole('menuitemradio', { name: 'Side Project' }))
    expect(switchOrg).toHaveBeenCalledWith('org-2')
  })

  it('creates an organisation from the dialog', async () => {
    shell('portal')
    const user = await openMenu()
    await user.click(screen.getByRole('menuitem', { name: 'Organization' }))
    await user.click(screen.getByRole('menuitem', { name: 'Create an Organization' }))
    const dialog = screen.getByRole('dialog')
    await user.type(within(dialog).getByLabelText('Organisation name'), 'Harbour Ltd')
    await user.click(within(dialog).getByRole('button', { name: 'Create organisation' }))
    expect(createOrg).toHaveBeenCalledWith('Harbour Ltd')
  })

  it('leaves the admin panel menu exactly as it was', async () => {
    shell('admin')
    await openMenu()
    expect(screen.getByRole('menuitem', { name: 'Account' }).getAttribute('href')).toBe('/admin/account')
    expect(screen.getByRole('menuitem', { name: 'Settings' })).toBeTruthy()
    expect(screen.queryByRole('menuitem', { name: 'Theme' })).toBeNull()
    expect(screen.queryByRole('menuitem', { name: 'Organization' })).toBeNull()
  })
})
