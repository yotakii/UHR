/**
 * نموذج طلب التوظيف F-28: الحالة، التحقق لكل خطوة (نفس قواعد الخادم)، وتحويله لـ multipart.
 * أسماء الحقول = أسماء حقول الـ API، فتُعرض أخطاء الخادم تحت حقولها مباشرة.
 */
export type YesNo = 'no' | 'yes'
export type Experience = { company: string; position: string; from: string; to: string; reason: string }

export interface ApplyForm {
  name: string; name_en: string; gender: '' | 'M' | 'F'; marital: string; nationality: string; dob: string; pob: string; national_id: string; blood: string
  mobile: string; email: string; address: string; linkedin: string
  degree: string; institute: string; major: string; grad_year: string; grade: string; english: string; years_exp: string
  experience: Experience[]
  ref_name: string; ref_job: string; ref_phone: string
  shifts: YesNo; travel: YesNo; public_sector: YesNo; conviction: YesNo; salary: string; availability: string; source: string; ref_code: string
  cv: File | null; photo: File | null
  password: string; password_confirmation: string; agree_info: boolean; agree_medical: boolean; agree_data: boolean; keep_cv: boolean
}

export const emptyExperience = (): Experience => ({ company: '', position: '', from: '', to: '', reason: '' })

export const emptyForm = (refCode?: string): ApplyForm => ({
  name: '', name_en: '', gender: '', marital: '', nationality: 'سورية', dob: '', pob: '', national_id: '', blood: '', mobile: '+963 ', email: '', address: '', linkedin: '',
  degree: '2', institute: '', major: '', grad_year: '', grade: '', english: 'good', years_exp: '',
  experience: [emptyExperience()], ref_name: '', ref_job: '', ref_phone: '',
  shifts: 'no', travel: 'no', public_sector: 'no', conviction: 'no', salary: '', availability: '', source: refCode ? 'referral' : 'website', ref_code: refCode ?? '',
  cv: null, photo: null, password: '', password_confirmation: '', agree_info: false, agree_medical: false, agree_data: false, keep_cv: true,
})

/** تعبئة النموذج من آخر طلب للمتقدم المسجّل (GET /me/profile) — لا تُنسخ الملفات ولا الإقرارات */
export type Profile = Partial<Record<string, unknown>> & { experience?: { company: string; position: string; from: string; to: string; reason: string }[]; ref?: { name: string; job: string; phone: string } | null }
export function fromProfile(base: ApplyForm, p: Profile): ApplyForm {
  const str = (v: unknown) => (v === null || v === undefined ? '' : String(v))
  const yn = (v: unknown): YesNo => (v ? 'yes' : 'no')
  const out = { ...base }
  for (const k of ['name', 'name_en', 'marital', 'nationality', 'dob', 'pob', 'national_id', 'blood', 'mobile', 'email', 'address', 'linkedin', 'institute', 'major', 'grad_year', 'grade', 'english', 'years_exp', 'salary', 'degree'] as const) {
    if (p[k] !== undefined && p[k] !== null && p[k] !== '') (out as Record<string, unknown>)[k] = str(p[k])
  }
  if (p.gender === 'M' || p.gender === 'F') out.gender = p.gender
  if (p.experience?.length) out.experience = p.experience.slice(0, 3).map(x => ({ company: str(x.company), position: str(x.position), from: str(x.from), to: str(x.to), reason: str(x.reason) }))
  if (p.ref) { out.ref_name = str(p.ref.name); out.ref_job = str(p.ref.job); out.ref_phone = str(p.ref.phone) }
  for (const k of ['shifts', 'travel', 'public_sector', 'conviction'] as const) if (p[k] !== undefined) out[k] = yn(p[k])
  return out
}

export const CV_MAX = 10 * 1024 * 1024
export const CV_EXT = /\.(pdf|docx?)$/i
export const PHOTO_MAX = 2 * 1024 * 1024
export const PHOTO_EXT = /\.(jpe?g|png)$/i

/** في أي خطوة يقع الحقل — للانتقال لأول خطوة فيها خطأ من الخادم */
const STEP_FIELDS: string[][] = [
  ['name', 'name_en', 'gender', 'marital', 'nationality', 'dob', 'pob', 'national_id', 'blood', 'mobile', 'email', 'address', 'linkedin'],
  ['degree', 'institute', 'major', 'grad_year', 'grade', 'english', 'years_exp', 'experience'],
  ['ref_name', 'ref_job', 'ref_phone', 'shifts', 'travel', 'public_sector', 'conviction', 'salary', 'availability', 'source', 'ref_code', 'cv', 'photo'],
  ['password', 'password_confirmation', 'agree_info', 'agree_medical', 'agree_data', 'keep_cv'],
]
export function stepOf(field: string): number {
  const base = field.split('.')[0]
  const i = STEP_FIELDS.findIndex(fs => fs.includes(base))
  return i < 0 ? 0 : i
}

type T = (ar: string, en: string) => string
export type Errors = Record<string, string>

/** وضع الحساب في الخطوة 4: جديد (إنشاء كلمة مرور)، موجود (كلمة مروره)، مسجّل الدخول (بلا كلمة مرور) */
export type Account = 'new' | 'existing' | 'signed_in'
export type StepOptions = { account?: Account; requiresPhoto?: boolean }

/** تحقق الخطوة i — نفس قواعد الخادم */
export function validateStep(i: number, f: ApplyForm, L: T, opts: StepOptions = {}): Errors {
  const account = opts.account ?? 'new'
  const e: Errors = {}
  const req = L('هذا الحقل مطلوب', 'This field is required')
  const need = (...keys: (keyof ApplyForm)[]) => keys.forEach(k => { if (!String(f[k] ?? '').trim()) e[k] = req })
  if (i === 0) {
    need('name', 'gender', 'marital', 'nationality', 'dob', 'national_id', 'email', 'address')
    if (f.mobile.replace(/\D/g, '').length < 10) e.mobile = L('أدخل رقم الجوال كاملاً مع رمز الدولة', 'Enter the full mobile number with country code')
    if (f.email.trim() && !/^\S+@\S+\.\S+$/.test(f.email.trim())) e.email = L('صيغة البريد غير صحيحة', 'Invalid email format')
  }
  if (i === 1) {
    need('institute', 'major', 'years_exp')
    if (f.years_exp !== '' && !(Number.isInteger(Number(f.years_exp)) && Number(f.years_exp) >= 0 && Number(f.years_exp) <= 60)) e.years_exp = L('أدخل رقماً صحيحاً', 'Enter a whole number')
  }
  if (i === 2) {
    if (!f.salary || !(Number(f.salary) > 0)) e.salary = L('حدد الراتب المتوقع بالدولار', 'Enter expected salary in USD')
    if (f.cv && f.cv.size > CV_MAX) e.cv = L('الحد الأقصى لحجم الملف 10MB', 'Maximum file size is 10MB')
    else if (f.cv && !CV_EXT.test(f.cv.name)) e.cv = L('الصيغ المقبولة: PDF أو DOC أو DOCX', 'Accepted formats: PDF, DOC or DOCX')
    if (opts.requiresPhoto && !f.photo) e.photo = L('هذه الوظيفة تتطلب صورة شخصية', 'This position requires a personal photo')
    else if (f.photo && f.photo.size > PHOTO_MAX) e.photo = L('الحد الأقصى لحجم الصورة 2MB', 'Maximum photo size is 2MB')
    else if (f.photo && !PHOTO_EXT.test(f.photo.name)) e.photo = L('الصورة بصيغة JPG أو PNG', 'Photo must be JPG or PNG')
  }
  if (i === 3) {
    if (account === 'existing') { if (!f.password) e.password = L('أدخل كلمة مرور حسابك الحالية', 'Enter your current account password') }
    else if (account === 'new') {
      if (f.password.length < 8) e.password = L('كلمة المرور 8 أحرف على الأقل.', 'Password must be at least 8 characters.')
      else if (!/[A-Za-z؀-ۿ]/.test(f.password) || !/\d/.test(f.password)) e.password = L('كلمة المرور تحتوي حرفاً ورقماً على الأقل.', 'Password needs at least one letter and one number.')
      if (f.password !== f.password_confirmation) e.password_confirmation = L('كلمتا المرور غير متطابقتين', 'Passwords do not match')
    }
    if (!f.agree_info || !f.agree_medical) e.agree_medical = L('يجب الموافقة على الإقرارين لإرسال الطلب', 'You must accept both declarations to submit')
    if (!f.agree_data) e.agree_data = L('يجب الموافقة على معالجة بياناتك لإرسال الطلب', 'You must accept the processing of your data to submit')
  }
  return e
}

/** multipart للإرسال: نعم/لا ← 1/0، الخبرات الفارغة تُحذف، رمز الإحالة فقط عند «ترشيح» */
export function toFormData(f: ApplyForm): FormData {
  const d = new FormData()
  const put = (k: string, v: string) => d.append(k, v)
  const plain: (keyof ApplyForm)[] = ['name', 'name_en', 'gender', 'marital', 'nationality', 'dob', 'pob', 'national_id', 'blood', 'mobile', 'email', 'address', 'linkedin',
    'degree', 'institute', 'major', 'grad_year', 'grade', 'english', 'years_exp', 'ref_name', 'ref_job', 'ref_phone', 'salary', 'availability', 'source', 'password', 'password_confirmation']
  plain.forEach(k => { const v = String(f[k] ?? '').trim(); if (v !== '' || k === 'password') put(k, k.startsWith('password') ? String(f[k]) : v) })
  ;(['shifts', 'travel', 'public_sector', 'conviction'] as const).forEach(k => put(k, f[k] === 'yes' ? '1' : '0'))
  f.experience.filter(x => x.company.trim()).forEach((x, i) => (Object.keys(x) as (keyof Experience)[]).forEach(k => { if (x[k].trim()) put(`experience[${i}][${k}]`, x[k].trim()) }))
  if (f.source === 'referral' && f.ref_code.trim()) put('ref_code', f.ref_code.trim().toUpperCase())
  if (f.cv) d.append('cv', f.cv)
  if (f.photo) d.append('photo', f.photo)
  for (const k of ['agree_info', 'agree_medical', 'agree_data', 'keep_cv'] as const) put(k, f[k] ? '1' : '0')
  return d
}
