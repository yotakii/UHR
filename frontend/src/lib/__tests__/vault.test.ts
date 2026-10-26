import { describe, expect, it } from 'vitest'
import { b64u, mmss, unb64u } from '../vault'

describe('salary vault helpers', () => {
  it('round-trips base64url without padding', () => {
    const bytes = new Uint8Array([0, 1, 250, 251, 252, 253, 254, 255])
    const s = b64u(bytes)
    expect(s).not.toMatch(/[+/=]/)
    expect(Array.from(unb64u(s))).toEqual(Array.from(bytes))
  })
  it('formats the countdown', () => {
    expect(mmss(300)).toBe('5:00')
    expect(mmss(61)).toBe('1:01')
    expect(mmss(9)).toBe('0:09')
  })
})
