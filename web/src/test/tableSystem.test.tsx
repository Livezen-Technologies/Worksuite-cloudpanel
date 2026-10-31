import { beforeEach, describe, it, expect, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { DataTable, type Column } from '../components/DataTable'
import { applyConditions, matches, multiSort, nextSort } from '../components/table/filtering'
import { toCsv, toExcelXml } from '../components/table/exporting'

interface Row { id: string; name: string; state: string; cpu: number; created: string }
const rows: Row[] = Array.from({ length: 30 }, (_, i) => ({ id: `r${i}`, name: `app-${String(i).padStart(2, '0')}`, state: ['running', 'exited', 'paused'][i % 3], cpu: (i * 7) % 50, created: `2026-10-${String((i % 28) + 1).padStart(2, '0')}` }))
const columns: Column<Row>[] = [
  { key: 'name', header: 'Name' },
  { key: 'state', header: 'State', options: ['running', 'exited', 'paused'] },
  { key: 'cpu', header: 'CPU', value: (r) => r.cpu, kind: 'number', align: 'right' },
  { key: 'created', header: 'Created', kind: 'date' },
]
const body = () => within(screen.getByRole('table')).getAllByRole('row').slice(1)

describe('filter engine', () => {
  it('matches every operator', () => {
    expect(matches('Running', { col: 'x', op: 'equals', value: 'running' })).toBe(true)
    expect(matches('web-01', { col: 'x', op: 'starts', value: 'web' })).toBe(true)
    expect(matches('exited', { col: 'x', op: 'in', value: ['running', 'exited'] })).toBe(true)
    expect(matches(42, { col: 'x', op: 'gt', value: '40' })).toBe(true)
    expect(matches('12 MB', { col: 'x', op: 'lt', value: '20' })).toBe(true)
    expect(matches('2026-10-05', { col: 'x', op: 'after', value: '2026-10-01' })).toBe(true)
    expect(matches('', { col: 'x', op: 'empty' })).toBe(true)
    expect(matches('—', { col: 'x', op: 'not_empty' })).toBe(false)
  })
  it('ignores half-filled conditions and ANDs the rest', () => {
    const get = (r: Row, k: string) => (r as unknown as Record<string, unknown>)[k]
    expect(applyConditions(rows, [{ col: 'state', op: 'equals', value: '' }], get)).toHaveLength(30)
    expect(applyConditions(rows, [{ col: 'state', op: 'equals', value: 'running' }, { col: 'cpu', op: 'gt', value: '20' }], get).every((r) => r.state === 'running' && r.cpu > 20)).toBe(true)
  })
  it('sorts by several columns and cycles sort keys', () => {
    const get = (r: Row, k: string) => (r as unknown as Record<string, unknown>)[k]
    const out = multiSort(rows, [{ key: 'state', dir: 1 }, { key: 'cpu', dir: -1 }], get)
    expect(out[0].state).toBe('exited')
    expect(out[0].cpu).toBeGreaterThanOrEqual(out[1].state === 'exited' ? out[1].cpu : 0)
    expect(nextSort([], 'a', false)).toEqual([{ key: 'a', dir: 1 }])
    expect(nextSort([{ key: 'a', dir: 1 }], 'a', false)).toEqual([{ key: 'a', dir: -1 }])
    expect(nextSort([{ key: 'a', dir: -1 }], 'a', false)).toEqual([])
    expect(nextSort([{ key: 'a', dir: 1 }], 'b', true)).toEqual([{ key: 'a', dir: 1 }, { key: 'b', dir: 1 }])
  })
})

describe('export', () => {
  it('quotes CSV and neutralises formulas', () => {
    expect(toCsv(['A', 'B'], [['x,y', '=1+1'], [3, 'say "hi"']])).toBe('A,B\r\n"x,y",\'=1+1\r\n3,"say ""hi"""')
  })
  it('writes typed Excel cells', () => {
    const x = toExcelXml('Apps', ['Name', 'CPU'], [['a<b', 5]])
    expect(x).toContain('<Data ss:Type="String">a&lt;b</Data>')
    expect(x).toContain('<Data ss:Type="Number">5</Data>')
  })
})

describe('DataTable', () => {
  beforeEach(() => localStorage.clear())

  it('changes page size and density, hides columns and remembers it', async () => {
    const { unmount } = render(<DataTable label="Apps" rows={rows} columns={columns} rowKey={(r) => r.id} savedViews={false} />)
    expect(body()).toHaveLength(10)
    await userEvent.click(screen.getByRole('button', { name: 'Rows per page' }))
    await userEvent.click(await screen.findByRole('option', { name: '25' }))
    expect(body()).toHaveLength(25)
    await userEvent.click(screen.getByRole('button', { name: 'Columns and density' }))
    await userEvent.click(screen.getByRole('checkbox', { name: 'CPU' }))
    await userEvent.click(screen.getByRole('radio', { name: 'Compact' }))
    await userEvent.click(screen.getByRole('button', { name: 'Done' }))
    expect(screen.queryByRole('columnheader', { name: /CPU/ })).toBeNull()
    unmount()
    render(<DataTable label="Apps" rows={rows} columns={columns} rowKey={(r) => r.id} savedViews={false} />)
    expect(body()).toHaveLength(25)
    expect(screen.queryByRole('columnheader', { name: /CPU/ })).toBeNull()
  })

  it('filters with the builder and shows removable chips', async () => {
    render(<DataTable label="Apps" rows={rows} columns={columns} rowKey={(r) => r.id} savedViews={false} />)
    await userEvent.click(screen.getByRole('button', { name: 'Filters' }))
    await userEvent.click(screen.getByRole('button', { name: 'Column' }))
    await userEvent.click(await screen.findByRole('option', { name: 'State' }))
    await userEvent.click(screen.getByRole('button', { name: 'Condition' }))
    await userEvent.click(await screen.findByRole('option', { name: 'is any of' }))
    await userEvent.click(screen.getByRole('button', { name: 'paused' }))
    await userEvent.click(screen.getByRole('button', { name: 'Apply filters' }))
    expect(screen.getByText(/of 10 \(filtered from 30\)/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Remove filter State' }))
    expect(screen.getByText('1–10 of 30')).toBeInTheDocument()
  })

  it('confirms destructive row actions and runs inline ones', async () => {
    const view = vi.fn(), del = vi.fn()
    render(<DataTable label="Apps" rows={rows.slice(0, 2)} columns={columns} rowKey={(r) => r.id} savedViews={false}
      actions={(r) => [{ label: `View ${r.name}`, icon: 'eye', onClick: view }, { label: 'Delete', icon: 'trash', danger: true, onClick: del }]} />)
    await userEvent.click(screen.getByRole('button', { name: 'View app-00' }))
    expect(view).toHaveBeenCalled()
    await userEvent.click(screen.getAllByRole('button', { name: 'More actions' })[0])
    await userEvent.click(await screen.findByRole('menuitem', { name: 'Delete' }))
    expect(del).not.toHaveBeenCalled()
    await userEvent.click(screen.getByRole('button', { name: 'Confirm' }))
    expect(del).toHaveBeenCalledTimes(1)
  })

  it('shows the bulk bar for selections and switches to grid', async () => {
    const stop = vi.fn()
    render(<DataTable label="Apps" rows={rows} columns={columns} rowKey={(r) => r.id} selectable savedViews={false} bulkActions={[{ label: 'Stop', onClick: stop }]} />)
    await userEvent.click(screen.getByRole('checkbox', { name: 'Select all on this page' }))
    expect(screen.getByText('10 selected')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Select all 30' }))
    await userEvent.click(screen.getByRole('button', { name: 'Stop' }))
    expect(stop).toHaveBeenCalledWith(expect.arrayContaining(['r0', 'r29']))
    await userEvent.click(screen.getByRole('button', { name: 'Grid view' }))
    expect(screen.queryByRole('table')).toBeNull()
    expect(screen.getByText('app-00')).toBeInTheDocument()
  })

  it('shows an error state with retry', async () => {
    const retry = vi.fn()
    render(<DataTable label="Apps" rows={[]} columns={columns} rowKey={(r) => r.id} error="Server said no" onRetry={retry} savedViews={false} />)
    expect(screen.getByRole('alert')).toHaveTextContent('Server said no')
    await userEvent.click(screen.getByRole('button', { name: 'Try again' }))
    expect(retry).toHaveBeenCalled()
  })
})
