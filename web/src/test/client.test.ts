import { describe, it, expect, vi, beforeEach } from 'vitest'
import { api, ApiError, onUnauthenticated, request, tokenStore } from '../api/client'

function jsonResponse(status: number, body: unknown) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('api client', () => {
  let fetchMock: ReturnType<typeof vi.fn>
  beforeEach(() => {
    tokenStore.clear()
    fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
  })

  it('sends the bearer token and base path', async () => {
    tokenStore.set('tok-123')
    fetchMock.mockResolvedValue(jsonResponse(200, { data: [] }))
    await api.get('/servers', { search: 'eu', page: 2, empty: '' })
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('/api/v1/servers?search=eu&page=2')
    expect(init.headers.Authorization).toBe('Bearer tok-123')
    expect(init.headers['Idempotency-Key']).toBeUndefined()
  })

  it('adds an Idempotency-Key to every POST, unique per call', async () => {
    fetchMock.mockImplementation(() => Promise.resolve(jsonResponse(202, { data: { job_id: 'j1' } })))
    await api.post('/containers/bulk-actions', { ids: ['a'], action: 'restart' })
    await api.post('/containers/bulk-actions', { ids: ['a'], action: 'restart' })
    const k1 = fetchMock.mock.calls[0][1].headers['Idempotency-Key']
    const k2 = fetchMock.mock.calls[1][1].headers['Idempotency-Key']
    expect(k1).toBeTruthy()
    expect(k2).toBeTruthy()
    expect(k1).not.toBe(k2)
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ ids: ['a'], action: 'restart' })
  })

  it('reuses the same Idempotency-Key when retrying a POST after a network failure', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch')).mockResolvedValueOnce(jsonResponse(202, { data: { job_id: 'j1' } }))
    const res = await api.post<{ data: { job_id: string } }>('/deployments', { application_id: 'x' }, { idempotencyKey: 'fixed-key' })
    expect(res.data.job_id).toBe('j1')
    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(fetchMock.mock.calls[0][1].headers['Idempotency-Key']).toBe('fixed-key')
    expect(fetchMock.mock.calls[1][1].headers['Idempotency-Key']).toBe('fixed-key')
  })

  it('does not retry GET on network failure and raises network_error', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
    await expect(api.get('/servers')).rejects.toMatchObject({ code: 'network_error', status: 0 })
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('parses the structured error envelope', async () => {
    fetchMock.mockResolvedValue(jsonResponse(422, { error: { code: 'validation_failed', message: 'The name is taken.', fields: { name: ['The name is taken.'] }, request_id: 'req-9' } }))
    const err = await api.post('/app/apps', { name: 'x' }).catch((e) => e)
    expect(err).toBeInstanceOf(ApiError)
    expect(err.code).toBe('validation_failed')
    expect(err.status).toBe(422)
    expect(err.message).toBe('The name is taken.')
    expect(err.field('name')).toBe('The name is taken.')
    expect(err.requestId).toBe('req-9')
  })

  it('maps quota_exceeded (402) and falls back to status codes for non-JSON bodies', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(402, { error: { code: 'quota_exceeded', message: 'App limit reached.' } }))
    await expect(api.post('/app/apps', {})).rejects.toMatchObject({ code: 'quota_exceeded', status: 402 })
    fetchMock.mockResolvedValueOnce(new Response('<html>Bad gateway</html>', { status: 409 }))
    await expect(api.get('/x')).rejects.toMatchObject({ code: 'conflict', status: 409 })
    fetchMock.mockResolvedValueOnce(new Response('oops', { status: 503 }))
    const e = await api.get('/x').catch((x) => x)
    expect(e.code).toBe('server_error')
    expect(e.message).toMatch(/Try again/)
  })

  it('clears the token and notifies listeners on 401', async () => {
    tokenStore.set('old')
    const spy = vi.fn()
    const off = onUnauthenticated(spy)
    fetchMock.mockResolvedValue(jsonResponse(401, { error: { code: 'unauthenticated', message: 'Unauthenticated.' } }))
    await expect(request('/me')).rejects.toMatchObject({ code: 'unauthenticated' })
    expect(tokenStore.get()).toBeNull()
    expect(spy).toHaveBeenCalledOnce()
    off()
  })

  it('returns undefined for 204 responses', async () => {
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }))
    await expect(api.del('/servers/1')).resolves.toBeUndefined()
  })
})
