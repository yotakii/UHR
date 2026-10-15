// @vitest-environment jsdom
import { describe, it, expect } from 'vitest'
import { setLang, setTheme, getPrefs, bi } from '../store'

describe('prefs & i18n', () => {
  it('setLang switches dir and lang on <html>', () => {
    setLang('en'); expect(document.documentElement.dir).toBe('ltr'); expect(document.documentElement.lang).toBe('en')
    setLang('ar'); expect(document.documentElement.dir).toBe('rtl')
  })
  it('themes toggle dark/slate classes', () => {
    const c = document.documentElement.classList
    setTheme('slate'); expect(c.contains('dark') && c.contains('slate')).toBe(true)
    setTheme('dark'); expect(c.contains('dark') && !c.contains('slate')).toBe(true)
    setTheme('light'); expect(c.contains('dark')).toBe(false)
  })
  it('prefs persist per browser', () => { setTheme('dark'); expect(JSON.parse(localStorage.getItem('uhr-prefs')!).theme).toBe('dark'); expect(getPrefs().theme).toBe('dark') })
  it('bi picks language', () => { expect(bi({ ar: 'دمشق', en: 'Damascus' }, 'en')).toBe('Damascus') })
})
