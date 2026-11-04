import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { MfaRequired } from '../components/ErrorStates'

describe('MfaRequired', () => {
  it('explains the rule and links to the account security settings', () => {
    render(<MemoryRouter><MfaRequired accountPath="/admin/account#security" /></MemoryRouter>)
    expect(screen.getByRole('heading', { name: 'Set up two-step verification' })).toBeTruthy()
    expect(screen.getByRole('link', { name: 'Set it up now' }).getAttribute('href')).toBe('/admin/account#security')
  })
})
