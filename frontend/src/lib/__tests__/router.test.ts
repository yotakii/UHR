// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach } from 'vitest'
import { parse, href } from '../router'
import { api, ApiError } from '../api'

describe('path router', () => {
  it('parses page, id, parts and query', () => {
    expect(parse('/jobs/alfarada/REQ-2026-031', '?ref=UHR1007-J1')).toEqual({ page: 'jobs', id: 'alfarada/REQ-2026-031', parts: ['jobs', 'alfarada', 'REQ-2026-031'], query: { ref: 'UHR1007-J1' } })
    expect(parse('/')).toEqual({ page: '', id: undefined, parts: [], query: {} })
    expect(parse('/reset?token=abc&email=a%40b.sy').query).toEqual({ token: 'abc', email: 'a@b.sy' })
  })
  it('builds links that round-trip, encoding each segment and dropping empty filters', () => {
    const h = href('jobs', 'alfarada/REQ 1', { ref: 'X 1', q: '' })
    expect(h).toBe('/jobs/alfarada/REQ%201?ref=X+1')
    expect(parse(h)).toEqual({ page: 'jobs', id: 'alfarada/REQ 1', parts: ['jobs', 'alfarada', 'REQ 1'], query: { ref: 'X 1' } })
    expect(href('')).toBe('/')
  })
})

describe('api client', () => {
  afterEach(() => { vi.unstubAllGlobals(); document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT' })
  it('sends CSRF + language headers with the session cookie', async () => {
    document.cookie = 'XSRF-TOKEN=tok%3D1'
    const f = vi.fn(async () => new Response(JSON.stringify({ user: null }), { status: 200 }))
    vi.stubGlobal('fetch', f)
    await api('/auth/logout', { json: {} })
    const [url, init] = f.mock.calls[0] as unknown as [string, RequestInit & { headers: Record<string, string> }]
    expect(url).toBe('/api/auth/logout')
    expect(init.method).toBe('POST')
    expect(init.credentials).toBe('same-origin')
    expect(init.headers['X-XSRF-TOKEN']).toBe('tok=1')
    expect(init.headers['Accept-Language']).toMatch(/ar|en/)
  })
  it('turns error bodies into ApiError with code and field errors', async () => {
    document.cookie = 'XSRF-TOKEN=t'
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'x', code: 'locked', errors: { email: ['bad'] } }), { status: 423 })))
    const e = await api('/auth/login', { json: {} }).catch(x => x) as ApiError
    expect(e).toBeInstanceOf(ApiError)
    expect([e.status, e.code, e.field('email')]).toEqual([423, 'locked', 'bad'])
  })
})
