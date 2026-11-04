import { afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Routes, Route } from 'react-router'
import AppManage from '../pages/admin/AppManage'

const err = (status: number, code: string, message: string) =>
  new Response(JSON.stringify({ error: { code, message, fields: {}, request_id: 'r1' } }),
    { status, headers: { 'Content-Type': 'application/json' } })

function renderAt(id = 'app-1') {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[`/admin/applications/${id}`]}>
        <Routes><Route path="/admin/applications/:id" element={<AppManage />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

/**
 * The page used to render its loading skeleton whenever there was no row,
 * which included "the request finished and failed". A 404 therefore looked
 * like a page that never loaded, and the real cause - the application living
 * in an organisation other than the selected one - was never shown.
 */
describe('AppManage error states', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('explains a 404 as a wrong-organisation problem instead of loading for ever', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => err(404, 'not_found', 'The requested resource was not found.')))
    renderAt()
    await waitFor(() => expect(screen.getByText(/not in the current organisation/i)).toBeTruthy())
    expect(screen.getByText(/switch organisation/i)).toBeTruthy()
    // The skeleton must be gone, and tabs for a thing we could not load are noise.
    expect(screen.queryByText('Loading…')).toBeNull()
    expect(screen.queryByRole('tab', { name: 'Settings' })).toBeNull()
    // The header keeps the only way back; the empty state does not repeat it.
    expect(screen.getAllByRole('link', { name: 'All applications' })).toHaveLength(1)
  })

  it('surfaces any other failure with the server message', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => err(500, 'server_error', 'Database connection refused.')))
    renderAt()
    await waitFor(() => expect(screen.getByText(/could not be loaded/i)).toBeTruthy())
    expect(screen.getByText(/Database connection refused/)).toBeTruthy()
    expect(screen.queryByText('Loading…')).toBeNull()
  })

  it('still renders the application normally when the request succeeds', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(
      JSON.stringify({ data: { id: 'app-1', name: 'tenderhub.lk', source_type: 'image', image: 'tenderhub-web', port: 3000 } }),
      { status: 200, headers: { 'Content-Type': 'application/json' } })))
    renderAt()
    await waitFor(() => expect(screen.getByRole('heading', { name: 'tenderhub.lk' })).toBeTruthy())
    expect(screen.getByRole('tab', { name: 'Settings' })).toBeTruthy()
    expect(screen.queryByText(/not in the current organisation/i)).toBeNull()
  })
})
