import { afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import Status from '../pages/public/Status'

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const day = (i: number, p: number | null) => ({ date: `2026-10-${String(i + 1).padStart(2, '0')}`, uptime_pct: p })

describe('public status page', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('shows the overall state, components, daily bars and incidents', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => json(200, { data: {
      title: 'Acme status', notice: 'Planned upgrade on Sunday.', overall: 'down', updated_at: '2026-10-31T10:00:00Z',
      components: [
        { name: 'API', state: 'down', uptime_pct: 99.5, days: [day(0, 100), day(1, 97.1)] },
        { name: 'Website', state: 'operational', uptime_pct: null, days: [day(0, null)] },
      ],
      incidents: [{ component: 'API', state: 'ongoing', started_at: '2026-10-31T09:40:00Z', resolved_at: null }],
    } })))
    render(<Status />)
    expect(await screen.findByRole('status')).toHaveTextContent('Some systems are disrupted')
    expect(screen.getByText('Planned upgrade on Sunday.')).toBeInTheDocument()
    expect(screen.getByText('Disruption')).toBeInTheDocument()
    expect(screen.getByText('No data yet')).toBeInTheDocument()
    expect(screen.getByRole('img', { name: 'API: daily uptime over 2 days' }).children).toHaveLength(2)
    expect(screen.getByText(/Disrupted since/)).toBeInTheDocument()
  })

  it('says so when no status page is published', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => json(404, { error: { code: 'not_found', message: 'There is no status page.' } })))
    render(<Status />)
    expect(await screen.findByText('There is no status page here.')).toBeInTheDocument()
  })
})
