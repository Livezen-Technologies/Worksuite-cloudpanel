import { describe, it, expect, vi } from 'vitest'
import { useState } from 'react'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { StepWizard, type WizardStep } from '../components/StepWizard'

function Harness({ onFinish, blockFirst }: { onFinish: () => void; blockFirst?: () => string | null }) {
  const [step, setStep] = useState(0)
  const [max, setMax] = useState(0)
  const steps: WizardStep[] = [
    { label: 'Source', content: <p>source body</p>, validate: blockFirst },
    { label: 'Configure', content: <p>configure body</p> },
    { label: 'Deploy', content: <p>deploy body</p> },
  ]
  return <StepWizard steps={steps} step={step} maxReached={max} onStep={(n) => { setStep(n); setMax((m) => Math.max(m, n)) }} onFinish={onFinish} finishLabel="Deploy" />
}

describe('StepWizard', () => {
  it('moves forward and back, and finishes on the last step', async () => {
    const finish = vi.fn()
    render(<Harness onFinish={finish} />)
    expect(screen.getByText('source body')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Back' })).toBeDisabled()
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    expect(screen.getByText('configure body')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Back' }))
    expect(screen.getByText('source body')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    expect(screen.getByText('deploy body')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Deploy' }))
    expect(finish).toHaveBeenCalledOnce()
  })

  it('blocks moving on when validate returns an error and shows it', async () => {
    let msg: string | null = 'Pick a source.'
    render(<Harness onFinish={() => {}} blockFirst={() => msg} />)
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    expect(screen.getByRole('alert')).toHaveTextContent('Pick a source.')
    expect(screen.getByText('source body')).toBeInTheDocument()
    msg = null
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    expect(screen.getByText('configure body')).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('lets you revisit reached steps but not jump ahead', async () => {
    render(<Harness onFinish={() => {}} />)
    const step3 = screen.getByRole('button', { name: /Step 3/ })
    expect(step3).toBeDisabled()
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))
    expect(screen.getByRole('button', { name: /Step 3/ })).toHaveAttribute('aria-current', 'step')
    await userEvent.click(screen.getByRole('button', { name: /Step 1/ }))
    expect(screen.getByText('source body')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /Step 3/ }))
    expect(screen.getByText('deploy body')).toBeInTheDocument()
  })
})
