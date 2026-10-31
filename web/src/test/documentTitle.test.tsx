import { describe, it, expect } from 'vitest'
import { render } from '@testing-library/react'
import { PageHeader } from '../components/Card'

describe('page titles', () => {
  it('names the browser tab after the page and restores it on leave', () => {
    document.title = 'WorkSuite Cloud'
    const { unmount } = render(<PageHeader title="Domains & SSL" />)
    expect(document.title).toBe('Domains & SSL · WorkSuite Cloud')
    unmount()
    expect(document.title).toBe('WorkSuite Cloud')
  })
  it('does not repeat the product name', () => {
    render(<PageHeader title="Sign in to WorkSuite Cloud" />)
    expect(document.title).toBe('Sign in to WorkSuite Cloud')
  })
})
