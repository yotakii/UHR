// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ask, type AskEvent } from '../assistant'

/** «المساعد»: قراءة الرد بالتدفق سطراً سطراً حتى لو انقسم السطر بين دفعتين */
describe('assistant stream', () => {
  afterEach(() => vi.unstubAllGlobals())
  it('parses NDJSON events split across chunks', async () => {
    const enc = new TextEncoder()
    const parts = ['{"t":"reply","message":{"id":7}}\n{"t":"del', 'ta","s":"مرحباً "}\n{"t":"delta","s":"بك"}\n', '{"t":"done","message":{"id":7}}']
    const body = new ReadableStream({ start(c) { parts.forEach(p => c.enqueue(enc.encode(p))); c.close() } })
    document.cookie = 'XSRF-TOKEN=x'
    vi.stubGlobal('fetch', vi.fn(async () => new Response(body, { status: 200 })))
    const got: AskEvent[] = []
    await ask('سؤال', e => got.push(e))
    expect(got.map(e => e.t)).toEqual(['reply', 'delta', 'delta', 'done'])
    expect(got.filter(e => e.t === 'delta').map(e => (e as { s: string }).s).join('')).toBe('مرحباً بك')
  })
  it('throws the server message before streaming starts', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'بلغت الحد اليومي', code: 'assistant_limit' }), { status: 429 })))
    await expect(ask('x', () => {})).rejects.toMatchObject({ status: 429, code: 'assistant_limit', message: 'بلغت الحد اليومي' })
  })
})
