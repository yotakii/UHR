import { CheckCircle2 } from 'lucide-react'
import { useL } from '../lib/store'
import { Field } from './kit'

/** سياسة كلمة المرور (ملاحظة الاختبار 81): من إعداد الشركة — الحرف والرقم مطلوبان دائماً */
export type PwPolicy = { min: number; upper: boolean; symbol: boolean }
export const DEFAULT_POLICY: PwPolicy = { min: 8, upper: false, symbol: false }

const checks = (p: string, pol: PwPolicy) => ({
  min: p.length >= pol.min, letter: /[A-Za-z\u0600-\u06FF]/.test(p), digit: /\d/.test(p),
  upper: !pol.upper || (/[A-Z]/.test(p) && /[a-z]/.test(p)), symbol: !pol.symbol || /[^A-Za-z0-9\u0600-\u06FF\s]/.test(p),
})

/** كلمة مرور جديدة + تأكيدها، مع قائمة الشروط الحيّة (نفس قاعدة الخادم) */
export function PasswordFields({ v, set, errs, label, policy = DEFAULT_POLICY }: { label?: string; v: { password: string; password_confirmation: string }; set: (k: 'password' | 'password_confirmation', x: string) => void; errs: Record<string, string | undefined>; policy?: PwPolicy }) {
  const L = useL()
  const c = checks(v.password, policy)
  const rules: [boolean, string][] = [[c.min, L(`${policy.min} أحرف على الأقل`, `At least ${policy.min} characters`)], [c.letter, L('حرف واحد على الأقل', 'At least one letter')], [c.digit, L('رقم واحد على الأقل', 'At least one number')]]
  if (policy.upper) rules.push([c.upper, L('حرف لاتيني كبير وآخر صغير', 'An uppercase and a lowercase letter')])
  if (policy.symbol) rules.push([c.symbol, L('رمز واحد على الأقل (مثل ! أو @)', 'At least one symbol (such as ! or @)')])
  return <>
    <Field label={label ?? L('كلمة المرور الجديدة', 'New password')} req error={errs.password}><input className="field ltr text-start" type="password" value={v.password} onChange={e => set('password', e.target.value)} autoComplete="new-password" /></Field>
    <ul className="text-[12px] space-y-0.5 -mt-2">{rules.map(([ok, t]) => <li key={t} className={`flex items-center gap-1.5 ${ok ? 'text-brand' : 'text-ink-3'}`}><CheckCircle2 size={13} />{t}</li>)}</ul>
    <Field label={L('تأكيد كلمة المرور', 'Confirm password')} req error={errs.password_confirmation ?? (v.password_confirmation && v.password_confirmation !== v.password ? L('كلمتا المرور غير متطابقتين', 'Passwords do not match') : undefined)}>
      <input className="field ltr text-start" type="password" value={v.password_confirmation} onChange={e => set('password_confirmation', e.target.value)} autoComplete="new-password" /></Field>
  </>
}
export const passwordOk = (v: { password: string; password_confirmation: string }, policy: PwPolicy = DEFAULT_POLICY) =>
  Object.values(checks(v.password, policy)).every(Boolean) && v.password === v.password_confirmation
