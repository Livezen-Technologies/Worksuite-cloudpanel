import { describe, it, expect, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { DataTable, type Column } from '../components/DataTable'

interface Row { id: string; name: string; state: string; cpu: number }
const rows: Row[] = Array.from({ length: 23 }, (_, i) => ({ id: `c${i}`, name: `ctr-${String(i).padStart(2, '0')}`, state: i % 3 === 0 ? 'exited' : 'running', cpu: (i * 7) % 50 }))
const columns: Column<Row>[] = [
  { key: 'name', header: 'Name' },
  { key: 'state', header: 'State' },
  { key: 'cpu', header: 'CPU', value: (r) => r.cpu },
]
const bodyRows = () => within(screen.getByRole('table')).getAllByRole('row').slice(1)

describe('DataTable', () => {
  it('paginates', async () => {
    render(<DataTable label="Containers" rows={rows} columns={columns} rowKey={(r) => r.id} pageSize={10} />)
    expect(bodyRows()).toHaveLength(10)
    expect(screen.getByText('1–10 of 23')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Next' }))
    await userEvent.click(screen.getByRole('button', { name: 'Next' }))
    expect(bodyRows()).toHaveLength(3)
    expect(screen.getByText('Page 3 of 3')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled()
  })

  it('searches across columns', async () => {
    render(<DataTable label="Containers" rows={rows} columns={columns} rowKey={(r) => r.id} />)
    await userEvent.type(screen.getByRole('textbox', { name: 'Search Containers' }), 'ctr-07')
    expect(bodyRows()).toHaveLength(1)
    expect(screen.getByText('ctr-07')).toBeInTheDocument()
  })

  it('filters with chips that show counts', async () => {
    render(<DataTable label="Containers" rows={rows} columns={columns} rowKey={(r) => r.id} pageSize={50}
      filters={[{ id: 'exited', label: 'Exited', test: (r) => r.state === 'exited' }]} />)
    const chip = screen.getByRole('button', { name: /Exited/ })
    expect(chip).toHaveTextContent('8')
    await userEvent.click(chip)
    expect(chip).toHaveAttribute('aria-pressed', 'true')
    expect(bodyRows()).toHaveLength(8)
  })

  it('sorts ascending, descending and back to original', async () => {
    render(<DataTable label="Containers" rows={rows} columns={columns} rowKey={(r) => r.id} pageSize={50} />)
    const sortBtn = screen.getByRole('button', { name: /CPU/ })
    await userEvent.click(sortBtn)
    const asc = bodyRows().map((r) => Number(within(r).getAllByRole('cell')[2].textContent))
    expect(asc).toEqual([...asc].sort((a, b) => a - b))
    expect(screen.getByRole('columnheader', { name: /CPU/ })).toHaveAttribute('aria-sort', 'ascending')
    await userEvent.click(sortBtn)
    const desc = bodyRows().map((r) => Number(within(r).getAllByRole('cell')[2].textContent))
    expect(desc).toEqual([...desc].sort((a, b) => b - a))
    await userEvent.click(sortBtn)
    expect(within(bodyRows()[0]).getAllByRole('cell')[0]).toHaveTextContent('ctr-00')
  })

  it('multi-selects rows and runs bulk actions with the selected ids', async () => {
    const restart = vi.fn()
    render(<DataTable label="Containers" rows={rows} columns={columns} rowKey={(r) => r.id} rowLabel={(r) => r.name} selectable pageSize={10}
      bulkActions={[{ label: 'Restart', onClick: restart }]} />)
    expect(screen.queryByRole('button', { name: 'Restart' })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('checkbox', { name: 'Select ctr-01' }))
    await userEvent.click(screen.getByRole('checkbox', { name: 'Select ctr-02' }))
    expect(screen.getByText('2 selected')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Restart' }))
    expect(restart).toHaveBeenCalledWith(['c1', 'c2'])
    expect(screen.queryByText('2 selected')).not.toBeInTheDocument()
  })

  it('select-all toggles only the current page', async () => {
    const stop = vi.fn()
    render(<DataTable label="Containers" rows={rows} columns={columns} rowKey={(r) => r.id} selectable pageSize={10} bulkActions={[{ label: 'Stop', onClick: stop }]} />)
    await userEvent.click(screen.getByRole('checkbox', { name: 'Select all on this page' }))
    expect(screen.getByText('10 selected')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Stop' }))
    expect(stop.mock.calls[0][0]).toHaveLength(10)
  })

  it('shows an empty message', () => {
    render(<DataTable label="Containers" rows={[]} columns={columns} rowKey={(r) => r.id} empty="No containers yet." />)
    expect(screen.getByText('No containers yet.')).toBeInTheDocument()
  })
})
