import { describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import UserProjects from '../pages/app/Projects'
import { ToastProvider } from '../components/Toasts'
import { JobsProvider } from '../api/jobs'

const listing = {
  projects: [], unassigned: [{ id: 'app-1', name: 'storefront', status: 'running' }],
  colors: ['blue', 'green'], purposes: ['web_application', 'service_api'],
  environment_types: { development: 'Development', staging: 'Staging', production: 'Production' },
}
const ok = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })

function setup() {
  const calls: { url: string; body: unknown }[] = []
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const u = String(url)
    if (init?.method && init.method !== 'GET') {
      calls.push({ url: u, body: init.body ? JSON.parse(String(init.body)) : null })
      return ok({ data: { id: 'proj-1', name: 'Storefront' } }, 201)
    }
    return ok({ data: listing })
  }))
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <ToastProvider><JobsProvider>
        <MemoryRouter><UserProjects /></MemoryRouter>
      </JobsProvider></ToastProvider>
    </QueryClientProvider>,
  )
  return calls
}

/** "Create new project" is two steps: what it is, then what goes into it. */
describe('the customer new-project wizard', () => {
  it('sends the environment, purpose and description, then assigns the chosen app', async () => {
    const calls = setup()
    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'New project' }))
    const dialog = screen.getByRole('dialog')

    await user.type(within(dialog).getByLabelText(/Project name/), 'Storefront')
    await user.selectOptions(within(dialog).getByLabelText(/Environment type/), 'production')
    await user.selectOptions(within(dialog).getByLabelText(/What is it for/), 'web_application')
    await user.type(within(dialog).getByLabelText(/Description/), 'The shop')
    await user.click(within(dialog).getByRole('button', { name: 'Create project' }))

    await waitFor(() => expect(calls).toHaveLength(1))
    expect(calls[0].body).toMatchObject({ name: 'Storefront', environment: 'production', purpose: 'web_application', description: 'The shop' })

    // Step two offers the apps that are in no project yet.
    await screen.findByText(/Choose what to move into Storefront/)
    await user.click(screen.getByRole('checkbox', { name: /storefront/ }))
    await user.click(screen.getByRole('button', { name: 'Add 1 app' }))
    await waitFor(() => expect(calls).toHaveLength(2))
    expect(calls[1].url).toContain('/projects/proj-1/apps')
    expect(calls[1].body).toEqual({ app_id: 'app-1' })
  })
})
