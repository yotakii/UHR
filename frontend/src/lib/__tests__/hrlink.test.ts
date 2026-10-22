import { describe, expect, it } from 'vitest'
import { hrLink } from '../hr'

describe('hrLink — روابط الإشعارات والمهام', () => {
  it('maps record links to pages', () => {
    expect(hrLink('cand:12')).toBe('/hr/candidates/12')
    expect(hrLink('emp:7')).toBe('/hr/employees/7')
    expect(hrLink('job:5')).toBe('/hr/jobs')
    expect(hrLink('onboard:3')).toBe('/hr/onboarding/3')
  })
  it('maps module names', () => {
    expect(hrLink('recruitment')).toBe('/hr/candidates')
    expect(hrLink('leaves')).toBe('/hr/leaves')
    expect(hrLink('custody')).toBe('/hr/custody')
  })
  it('maps discipline links: a case for HR, the own page for the employee', () => {
    expect(hrLink('discipline:7')).toBe('/hr/discipline?case=7')
    expect(hrLink('discipline')).toBe('/hr/my-discipline')
    expect(hrLink('complaint:3')).toBe('/hr/discipline?tab=complaints')
    expect(hrLink('complaints')).toBe('/hr/my-discipline')
  })
})
