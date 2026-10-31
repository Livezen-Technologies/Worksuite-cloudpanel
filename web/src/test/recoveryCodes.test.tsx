import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { RecoveryCodesDialog, SecondFactorInput, secondFactorReady } from '../components/TwoFactorSetup'

describe('recovery codes', () => {
  it('accepts a 6-digit code or a recovery code', () => {
    expect(secondFactorReady('123456')).toBe(true)
    expect(secondFactorReady('abcde-fgh23')).toBe(true)
    expect(secondFactorReady('ABCDE FGH23')).toBe(true)
    expect(secondFactorReady('12345')).toBe(false)
    expect(secondFactorReady('abcde-fg')).toBe(false)
  })

  it('switches the sign-in step to a recovery code field', async () => {
    function H() { const [v, setV] = useState(''); return <><SecondFactorInput value={v} onChange={setV} /><output>{v}</output></> }
    render(<H />)
    await userEvent.click(screen.getByRole('button', { name: /Use a recovery code/ }))
    await userEvent.type(screen.getByLabelText('Recovery code'), 'abcde-fgh23')
    expect(screen.getByRole('status').textContent).toBe('abcde-fgh23')
    await userEvent.click(screen.getByRole('button', { name: /Use the code from my app/ }))
    expect(screen.queryByLabelText('Recovery code')).toBeNull()
  })

  it('shows the codes once, with copy and download', () => {
    render(<RecoveryCodesDialog codes={['aaaaa-bbbbb', 'ccccc-ddddd']} onClose={() => {}} />)
    expect(screen.getByRole('list', { name: 'Recovery codes' }).textContent).toContain('aaaaa-bbbbb')
    expect(screen.getByRole('button', { name: 'Download .txt' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'I’ve saved them' })).toBeTruthy()
  })
})
