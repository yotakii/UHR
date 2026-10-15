import { CheckCircle2 } from 'lucide-react'
import { useL } from '../lib/store'
import { Field } from './kit'

/** كلمة مرور جديدة + تأكيدها، مع قائمة الشروط الحيّة (نفس قاعدة الخادم: 8 أحرف، حرف ورقم) */
export function PasswordFields({ v, set, errs, label }: { label?: string; v: { password: string; password_confirmation: string }; set: (k: 'password' | 'password_confirmation', x: string) => void; errs: Record<string, string | undefined> }) {
  const L = useL()
  const rules = [[v.password.length >= 8, L('8 أحرف على الأقل', 'At least 8 characters')], [/[A-Za-z؀-ۿ]/.test(v.password), L('حرف واحد على الأقل', 'At least one letter')], [/\d/.test(v.password), L('رقم واحد على الأقل', 'At least one number')]] as const
  return <>
    <Field label={label ?? L('كلمة المرور الجديدة', 'New password')} req error={errs.password}><input className="field ltr text-start" type="password" value={v.password} onChange={e => set('password', e.target.value)} autoComplete="new-password" /></Field>
    <ul className="text-[12px] space-y-0.5 -mt-2">{rules.map(([ok, t]) => <li key={t} className={`flex items-center gap-1.5 ${ok ? 'text-brand' : 'text-ink-3'}`}><CheckCircle2 size={13} />{t}</li>)}</ul>
    <Field label={L('تأكيد كلمة المرور', 'Confirm password')} req error={errs.password_confirmation ?? (v.password_confirmation && v.password_confirmation !== v.password ? L('كلمتا المرور غير متطابقتين', 'Passwords do not match') : undefined)}>
      <input className="field ltr text-start" type="password" value={v.password_confirmation} onChange={e => set('password_confirmation', e.target.value)} autoComplete="new-password" /></Field>
  </>
}
export const passwordOk = (v: { password: string; password_confirmation: string }) => v.password.length >= 8 && /[A-Za-z؀-ۿ]/.test(v.password) && /\d/.test(v.password) && v.password === v.password_confirmation
