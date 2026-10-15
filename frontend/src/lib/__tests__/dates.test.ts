import { describe, it, expect } from 'vitest'
import { addDays, isWeekend, workdaysBetween, daysBetween, fmtTime, money, ymd } from '../dates'

// نفس سلوك الديمو: العطلة الجمعة والسبت، الأرقام لاتينية، الدولار بفاصلة آلاف
describe('dates', () => {
  it('weekend is Friday & Saturday', () => {
    expect(isWeekend('2026-10-09')).toBe(true)   // جمعة
    expect(isWeekend('2026-10-10')).toBe(true)   // سبت
    expect(isWeekend('2026-10-11')).toBe(false)  // أحد
  })
  it('addDays crosses months', () => { expect(addDays('2026-09-29', 3)).toBe('2026-10-02') })
  it('workdays skip Fri/Sat', () => { expect(workdaysBetween('2026-10-08', '2026-10-12')).toBe(3) }) // خميس، أحد، اثنين
  it('daysBetween ignores time', () => { expect(daysBetween('2026-10-01T23:00:00', '2026-10-12')).toBe(11) })
  it('fmtTime is 24h zero-padded', () => { expect(fmtTime('2026-10-12T08:05:00')).toBe('08:05') })
  it('money formats USD & SYP', () => {
    expect(money(1450.4, 'USD', 'ar')).toBe('$1,450')
    expect(money(250000, 'SYP', 'ar')).toBe('250,000 ل.س')
    expect(money(250000, 'SYP', 'en')).toBe('SYP 250,000')
  })
  it('ymd pads', () => { expect(ymd(new Date(2026, 0, 5))).toBe('2026-01-05') })
})
