import { describe, expect, it } from 'vitest'
import { useState } from 'react'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Modal } from '../components/Overlay'

function Host() {
  const [name, setName] = useState('')
  // A new onClose on every render, as callers write it.
  return <Modal open title="New project" onClose={() => setName('')}><label>Name<input value={name} onChange={(e) => setName(e.target.value)} /></label></Modal>
}

describe('Modal focus', () => {
  it('starts in the first field and keeps focus there while typing', async () => {
    render(<Host />)
    const input = screen.getByLabelText('Name')
    expect(document.activeElement).toBe(input)
    await userEvent.keyboard('the-shop')
    expect(input).toHaveValue('the-shop')
    expect(document.activeElement).toBe(input)
  })
})
