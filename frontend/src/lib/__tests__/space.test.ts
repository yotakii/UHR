import { describe, expect, it } from 'vitest'
import { parseBody } from '../space'

describe('parseBody', () => {
  it('splits mentions and links', () => {
    expect(parseBody('شكراً @[سامر الحلبي](12) راجع https://x.sy/a.')).toEqual([
      { t: 'text', v: 'شكراً ' }, { t: 'mention', name: 'سامر الحلبي', id: 12 }, { t: 'text', v: ' راجع ' }, { t: 'link', v: 'https://x.sy/a.' },
    ])
  })
  it('plain text stays one chunk', () => { expect(parseBody('سطر\nثانٍ')).toEqual([{ t: 'text', v: 'سطر\nثانٍ' }]) })
})
