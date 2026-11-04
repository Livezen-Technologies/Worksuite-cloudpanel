import { describe, it, expect } from 'vitest'
import { logTone, splitLogLine } from '../components/LogView'

describe('log lines', () => {
  it('splits a Docker timestamp from the message', () => {
    expect(splitLogLine('2026-11-04T08:06:33.734025839Z Failed to set cache')).toEqual({ time: '08:06:33', full: '2026-11-04T08:06:33.734025839Z', text: 'Failed to set cache' })
    expect(splitLogLine('2026-11-04 08:06:33,123+05:30 started')).toMatchObject({ time: '08:06:33', text: 'started' })
    expect(splitLogLine('no timestamp here')).toEqual({ time: null, full: null, text: 'no timestamp here' })
  })
  it('marks errors and warnings', () => {
    expect(logTone('Failed to set Next.js data cache')).toBe('error')
    expect(logTone('[Warning] Aborted connection')).toBe('warn')
    expect(logTone('GET /api 200')).toBeNull()
    expect(logTone('errors_total=0')).toBeNull()
  })
})
