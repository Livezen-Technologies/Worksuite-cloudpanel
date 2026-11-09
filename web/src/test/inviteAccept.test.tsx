import { afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router'
import { InviteAccept } from '../pages/auth/Auth'

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const page = () => render(<MemoryRouter initialEntries={['/invite/abc']}><Routes><Route path="/invite/:token" element={<InviteAccept />} /><Route path="/login" element={<p>signin page</p>} /></Routes></MemoryRouter>)

describe('accepting an invitation', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('a new person sets a name and a matching password of 10+ characters', async () => {
    const fetch = vi.fn(async (_u: string, init?: RequestInit) => init?.method === 'POST' ? json(200, { data: { accepted: true } })
      : json(200, { data: { email: 'new@acme.lk', name: 'new', organization: 'Acme', role: 'developer', has_account: false } }))
    vi.stubGlobal('fetch', fetch)
    page()
    expect(await screen.findByRole('heading', { name: 'Join Acme' })).toBeInTheDocument()
    const accept = screen.getByRole('button', { name: 'Accept invitation' })
    await userEvent.type(screen.getByLabelText(/Choose a password/), 'short')
    expect(accept).toBeDisabled()
    await userEvent.clear(screen.getByLabelText(/Choose a password/))
    await userEvent.type(screen.getByLabelText(/Choose a password/), 'a-long-password')
    await userEvent.type(screen.getByLabelText(/Repeat it/), 'a-long-passwor')
    expect(screen.getByText('The passwords do not match.')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText(/Repeat it/), 'd')
    await userEvent.click(accept)
    expect(await screen.findByText('signin page')).toBeInTheDocument()
    const body = JSON.parse(String((fetch.mock.calls.find((c) => c[1]?.method === 'POST')![1] as RequestInit).body))
    expect(body).toEqual({ name: 'new', password: 'a-long-password', password_confirmation: 'a-long-password' })
  })

  it('an existing account only accepts, and a dead link says so', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => json(200, { data: { email: 'me@acme.lk', name: 'Me', organization: 'Acme', role: 'viewer', has_account: true } })))
    page()
    expect(await screen.findByText(/already have an account/)).toBeInTheDocument()
    expect(screen.queryByLabelText(/Choose a password/)).not.toBeInTheDocument()
    vi.stubGlobal('fetch', vi.fn(async () => json(422, { error: { code: 'validation_failed', message: 'x', fields: { token: ['This invitation is not valid or has expired. Ask for a new one.'] } } })))
    page()
    expect(await screen.findByText(/not valid or has expired/)).toBeInTheDocument()
  })
})
