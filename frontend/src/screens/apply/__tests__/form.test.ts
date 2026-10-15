import { describe, it, expect } from 'vitest'
import { emptyForm, validateStep, toFormData, stepOf, fromProfile, type ApplyForm } from '../form'

const L = (_ar: string, en: string) => en
const filled = (): ApplyForm => ({
  ...emptyForm(), name: 'سارة الأحمد', gender: 'F', marital: 'single', dob: '1997-03-14', national_id: '0102', mobile: '+963 944 555 123', email: 'sara@mail.sy', address: 'دمشق',
  institute: 'جامعة دمشق', major: 'محاسبة', years_exp: '4', salary: '750', password: 'Sara2026pw', password_confirmation: 'Sara2026pw', agree_info: true, agree_medical: true, agree_data: true,
})

describe('F-28 wizard validation', () => {
  it('requires the starred fields of each step', () => {
    const e = validateStep(0, emptyForm(), L)
    expect(Object.keys(e).sort()).toEqual(['address', 'dob', 'email', 'gender', 'marital', 'mobile', 'name', 'national_id'])
    expect(e.mobile).toBe('Enter the full mobile number with country code')
    expect(Object.keys(validateStep(1, emptyForm(), L)).sort()).toEqual(['institute', 'major', 'years_exp'])
    expect(validateStep(2, emptyForm(), L).salary).toBe('Enter expected salary in USD')
  })
  it('passes a complete form on every step', () => {
    for (const i of [0, 1, 2, 3]) expect(validateStep(i, filled(), L)).toEqual({})
  })
  it('checks email format, password strength and both declarations', () => {
    expect(validateStep(0, { ...filled(), email: 'bad' }, L).email).toBe('Invalid email format')
    const e = validateStep(3, { ...filled(), password: 'abcdefgh', password_confirmation: 'x', agree_info: false }, L)
    expect(e.password).toMatch(/letter and one number/)
    expect(e.password_confirmation).toBe('Passwords do not match')
    expect(e.agree_medical).toBeDefined()
  })
  it('asks only for the current password when the applicant already has an account', () => {
    expect(validateStep(3, { ...filled(), password: '123456', password_confirmation: '' }, L, { account: 'existing' })).toEqual({})
    expect(validateStep(3, { ...filled(), password: '' }, L, { account: 'existing' }).password).toBe('Enter your current account password')
    expect(validateStep(3, { ...filled(), password: '', password_confirmation: '' }, L, { account: 'signed_in' })).toEqual({})
  })
  it('rejects oversized or wrong-type CVs before upload', () => {
    const big = new File(['x'], 'cv.pdf'); Object.defineProperty(big, 'size', { value: 11 * 1024 * 1024 })
    expect(validateStep(2, { ...filled(), cv: big }, L).cv).toBe('Maximum file size is 10MB')
    expect(validateStep(2, { ...filled(), cv: new File(['x'], 'cv.exe') }, L).cv).toMatch(/PDF/)
  })
})

describe('v3 rules — consent, photo, prefill', () => {
  it('requires data consent to submit', () => {
    expect(validateStep(3, { ...filled(), agree_data: false }, L).agree_data).toBe('You must accept the processing of your data to submit')
  })
  it('requires a photo only when the job asks for it', () => {
    expect(validateStep(2, filled(), L, { requiresPhoto: true }).photo).toBe('This position requires a personal photo')
    expect(validateStep(2, filled(), L).photo).toBeUndefined()
    expect(validateStep(2, { ...filled(), photo: new File(['x'], 'me.gif') }, L, { requiresPhoto: true }).photo).toBe('Photo must be JPG or PNG')
    expect(validateStep(2, { ...filled(), photo: new File(['x'], 'me.jpg') }, L, { requiresPhoto: true })).toEqual({})
  })
  it('prefills from the last application without files or declarations', () => {
    const f = fromProfile(emptyForm(), { name: 'لين العلي', gender: 'F', degree: 3, years_exp: 4, salary: 800, shifts: true, experience: [{ company: 'بنك', position: 'محاسبة', from: '2020', to: '2026', reason: '' }], ref: { name: 'سليم', job: 'مدير', phone: '0944' } })
    expect(f).toMatchObject({ name: 'لين العلي', gender: 'F', degree: '3', years_exp: '4', salary: '800', shifts: 'yes', ref_name: 'سليم', agree_data: false, cv: null })
    expect(f.experience[0].company).toBe('بنك')
  })
})

describe('F-28 submission payload', () => {
  it('maps the form to the API fields', () => {
    const f = { ...filled(), shifts: 'yes' as const, source: 'referral', ref_code: 'uhr1007-j1', experience: [{ company: 'بنك', position: 'محاسب', from: '2020', to: '', reason: '' }, { company: ' ', position: 'x', from: '', to: '', reason: '' }] }
    const d = toFormData(f)
    expect(d.get('job')).toBeNull()
    expect(d.get('shifts')).toBe('1')
    expect(d.get('travel')).toBe('0')
    expect(d.get('ref_code')).toBe('UHR1007-J1')
    expect(d.get('experience[0][company]')).toBe('بنك')
    expect(d.get('experience[1][company]')).toBeNull()
    expect(d.get('agree_medical')).toBe('1')
    expect(d.get('agree_data')).toBe('1')
    expect(d.get('keep_cv')).toBe('1')
  })
  it('drops the referral code unless the source is a referral', () => {
    expect(toFormData({ ...filled(), ref_code: 'X' }).get('ref_code')).toBeNull()
  })
  it('finds the step of a server error', () => {
    expect([stepOf('email'), stepOf('experience.0.from'), stepOf('cv'), stepOf('password'), stepOf('unknown')]).toEqual([0, 1, 2, 3, 0])
  })
  it('pre-fills a referral from the link', () => {
    expect(emptyForm('UHR1007-J1')).toMatchObject({ source: 'referral', ref_code: 'UHR1007-J1' })
  })
})
