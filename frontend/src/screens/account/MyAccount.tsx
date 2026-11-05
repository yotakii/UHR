import { useRef, useState } from 'react'
import { Camera, KeyRound, Loader2, Save, ShieldCheck, Trash2 } from 'lucide-react'
import { useL, useLang, bi, fmtDate, fmtDateTime, setLang, setTheme, useTheme, type Theme } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { useChangePassword } from '../../lib/session'
import { openVault } from '../../lib/vault'
import { useAccount, useAvatar, useRemoveAvatar, useSaveAccount } from '../../lib/account'
import { Avatar, Badge, Btn, Field, KV, PageHead, Panel, Seg, toast } from '../../ui/kit'
import { PasswordFields, passwordOk } from '../../ui/password'

/**
 * «حسابي» (ملاحظة الاختبار 67): الصورة الشخصية، اللغة والمظهر (تُحفظ في الحساب)، كلمة المرور، طرق التحقق،
 * وعرض المسمى والإدارة والمدير — تُعدَّل من الموارد البشرية أو بطلب «تحديث بيانات».
 */
export function MyAccount({ servicesBase }: { servicesBase: string }) {
  const L = useL(); const lang = useLang(); const theme = useTheme()
  const { data: a } = useAccount()
  const avatar = useAvatar(); const remove = useRemoveAvatar(); const save = useSaveAccount()
  const input = useRef<HTMLInputElement>(null)
  const [name, setName] = useState<{ name: string; name_en: string } | null>(null)
  if (!a) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const n = name ?? { name: a.own_name ?? '', name_en: a.name_en ?? '' }
  const fail = (x: unknown) => toast(x instanceof ApiError ? (Object.values(x.errors)[0]?.[0] ?? x.message) : L('تعذّر الحفظ', 'Could not save'))
  const pref = (p: { locale?: 'ar' | 'en'; theme?: Theme }) => { if (p.locale) setLang(p.locale); if (p.theme) setTheme(p.theme); save.mutate(p) }
  const e = a.employee
  return <div className="max-w-[980px] mx-auto space-y-4">
    <PageHead title={L('حسابي', 'My account')} sub={L('بيانات حسابك وتفضيلاتك وأمانه.', 'Your account details, preferences and security.')} />
    <Panel>
      <div className="flex flex-wrap items-center gap-4">
        <div className="relative">
          <Avatar size={84} src={a.avatar ?? undefined} />
          <button onClick={() => input.current?.click()} className="absolute -bottom-1 -end-1 size-8 rounded-full bg-brand text-on-brand grid place-items-center shadow" aria-label={L('تغيير الصورة', 'Change photo')}>
            {avatar.isPending ? <Loader2 size={15} className="animate-spin" /> : <Camera size={15} />}</button>
          <input ref={input} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={x => { const f = x.target.files?.[0]; if (f) avatar.mutate(f, { onSuccess: () => toast(L('حُدّثت الصورة', 'Photo updated')), onError: fail }); x.target.value = '' }} />
        </div>
        <div className="flex-1 min-w-[200px]">
          <div className="text-[20px] font-bold">{bi(a.name, lang)}</div>
          <div className="text-[13px] text-ink-3 ltr text-start">{a.email}</div>
          <div className="flex flex-wrap gap-1.5 mt-2">{a.roles.map(r => <Badge key={r.key}>{bi(r, lang)}</Badge>)}</div>
        </div>
        {a.avatar && <Btn size="sm" icon={<Trash2 size={14} />} onClick={() => remove.mutate(undefined, { onError: fail })}>{L('إزالة الصورة', 'Remove photo')}</Btn>}
      </div>
      {a.last_login_at && <p className="text-[12px] text-ink-3 mt-3">{L('آخر دخول', 'Last sign-in')}: <span className="num">{fmtDateTime(a.last_login_at, lang)}</span></p>}
    </Panel>

    <div className="grid md:grid-cols-2 gap-4">
      {e ? <Panel title={L('البيانات الوظيفية', 'Employment')} sub={L('تُعدَّل من الموارد البشرية.', 'Managed by HR.')}
        action={<a className="text-brand font-semibold text-[13px]" href={`${servicesBase}?new=data_update`}>{L('طلب تحديث بيانات', 'Request an update')}</a>}>
        <KV k={L('الرقم الوظيفي', 'Employee ID')} v={<span className="ltr num">{e.code}</span>} />
        <KV k={L('المسمى الوظيفي', 'Job title')} v={bi(e.title, lang)} /><KV k={L('الإدارة', 'Department')} v={bi(e.dept, lang)} />
        <KV k={L('المدير المباشر', 'Manager')} v={e.manager ? bi(e.manager, lang) : '—'} /><KV k={L('مقر العمل', 'Location')} v={e.location ? bi(e.location, lang) : '—'} />
        <KV k={L('تاريخ المباشرة', 'Start date')} v={fmtDate(e.hire_date ?? undefined, lang)} /><KV k={L('الجوال', 'Mobile')} v={<span className="ltr">{e.phone || '—'}</span>} />
      </Panel> : <Panel title={L('الاسم', 'Name')} action={<Btn size="sm" kind="primary" icon={<Save size={14} />} disabled={!name || save.isPending} onClick={() => save.mutate(n, { onSuccess: () => { setName(null); toast(L('حُفظ', 'Saved')) }, onError: fail })}>{L('حفظ', 'Save')}</Btn>}>
        <div className="space-y-3">
          <Field label={L('الاسم', 'Name')} req><input className="field" value={n.name} maxLength={120} onChange={x => setName({ ...n, name: x.target.value })} /></Field>
          <Field label={L('الاسم بالإنكليزية', 'English name')}><input className="field ltr" value={n.name_en} maxLength={120} onChange={x => setName({ ...n, name_en: x.target.value })} /></Field>
        </div></Panel>}

      <Panel title={L('اللغة والمظهر', 'Language & appearance')} sub={L('يُحفظان في حسابك ويتبعانك على كل جهاز.', 'Saved to your account on every device.')}>
        <div className="space-y-4">
          <Field label={L('اللغة', 'Language')} div><Seg value={lang} onChange={v => pref({ locale: v })} options={[{ v: 'ar', label: 'العربية' }, { v: 'en', label: 'English' }]} /></Field>
          <Field label={L('المظهر', 'Theme')} div><Seg value={theme} onChange={v => pref({ theme: v })} options={[{ v: 'slate', label: L('داكن أزرق', 'Slate') }, { v: 'dark', label: L('داكن', 'Dark') }, { v: 'light', label: L('فاتح', 'Light') }]} /></Field>
          {e?.has_dob && <Field label={L('عيد ميلادي في الساحة', 'My birthday in the Space')} hint={L('يُعرض اليوم والشهر فقط، دون السنة.', 'Only the day and month are shown, never the year.')} div>
            <Seg value={e.hide_birthday ? 'hide' : 'show'} onChange={v => save.mutate({ hide_birthday: v === 'hide' }, { onSuccess: () => toast(L('حُفظ', 'Saved')), onError: fail })}
              options={[{ v: 'show', label: L('يظهر للزملاء', 'Shown to colleagues') }, { v: 'hide', label: L('مخفي', 'Hidden') }]} /></Field>}
        </div>
      </Panel>

      <PasswordPanel policy={a.password_policy} />

      <Panel title={<span className="flex items-center gap-2"><ShieldCheck size={17} className="text-brand" />{L('التحقق من الهوية', 'Identity verification')}</span>}
        sub={L('الطرق التي تُفتح بها الرواتب والبيانات الحساسة: الموافقة من الجوال، مفتاح المرور، تطبيق المصادقة، أو رمز بالبريد.', 'How you unlock salaries and sensitive data: phone approval, passkey, authenticator app or an email code.')}>
        <Btn icon={<KeyRound size={15} />} onClick={() => openVault()}>{L('إدارة طرق التحقق', 'Manage verification methods')}</Btn>
      </Panel>
    </div>
  </div>
}

function PasswordPanel({ policy }: { policy: { min: number; upper: boolean; symbol: boolean } }) {
  const L = useL()
  const ch = useChangePassword()
  const [v, setV] = useState({ current_password: '', password: '', password_confirmation: '' })
  const err = ch.error instanceof ApiError ? ch.error : null
  const errs = { password: err?.field('password'), password_confirmation: err?.field('password_confirmation') }
  const ok = !!v.current_password && passwordOk(v, policy)
  return <Panel title={L('كلمة المرور', 'Password')}>
    <form className="space-y-3" onSubmit={x => { x.preventDefault(); if (ok) ch.mutate(v, { onSuccess: () => { setV({ current_password: '', password: '', password_confirmation: '' }); toast(L('غُيّرت كلمة المرور', 'Password changed')) } }) }}>
      <Field label={L('كلمة المرور الحالية', 'Current password')} req error={err?.field('current_password')}><input type="password" autoComplete="current-password" className="field ltr" value={v.current_password} onChange={x => setV({ ...v, current_password: x.target.value })} /></Field>
      <PasswordFields v={v} set={(k, x) => setV(p => ({ ...p, [k]: x }))} errs={errs} policy={policy} label={L('كلمة المرور الجديدة', 'New password')} />
      <Btn kind="primary" type="submit" disabled={!ok || ch.isPending} icon={ch.isPending ? <Loader2 size={15} className="animate-spin" /> : undefined}>{L('تغيير كلمة المرور', 'Change password')}</Btn>
    </form>
  </Panel>
}
