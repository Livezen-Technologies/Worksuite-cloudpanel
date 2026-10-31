import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { NotFound, PageErrorBoundary } from '../components/ErrorStates'

describe('error states', () => {
  it('shows a styled 404 with a way home', () => {
    render(<MemoryRouter><NotFound /></MemoryRouter>)
    expect(screen.getByRole('heading', { name: 'Page not found' })).toBeTruthy()
    expect(screen.getByRole('link', { name: 'Go to the dashboard' }).getAttribute('href')).toBe('/')
    expect(document.title).toBe('Page not found · WorkSuite Cloud')
  })

  it('catches a page that throws instead of blanking the panel, and can retry', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    let broken = true
    function Page() { if (broken) throw new Error('boom'); return <p>page body</p> }
    render(<PageErrorBoundary><Page /></PageErrorBoundary>)
    expect(screen.getByRole('heading', { name: 'This page could not be shown' })).toBeTruthy()
    expect(screen.getByText('boom')).toBeTruthy()
    broken = false
    await userEvent.click(screen.getByRole('button', { name: 'Try again' }))
    expect(screen.getByText('page body')).toBeTruthy()
  })
})
