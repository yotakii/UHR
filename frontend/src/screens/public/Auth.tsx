import { useState, type FormEvent } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { KeyRound, Nfc, MailCheck, Eye, EyeOff, Loader2, Link2Off } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { api, ApiError } from '../../lib/api'
import { go, href, navigate, useRoute } from '../../lib/router'
import { useActivate, useLogin } from '../../lib/session'
import { Btn, Field } from '../../ui/kit'
import { PasswordFields, passwordOk } from '../../ui/password'
import { AuthCard } from './parts'

/** جهاز حضور مسجّل على هذا المتصفح (يُسجَّل من M16 — أجهزة الحضور) */
const isAttendanceDevice = () => { try { return !!localStorage.getItem('uhr-device') } catch { return false } }

/** بطاقة الدخول: للموظفين وموارد الشركات والمتقدمين ومدير المنصة — كلٌّ لواجهته */import { useCompanyPage, usePortalContext } from '../../lib/public'
import { CompanyLogo } from './parts'

export function LoginCard({ sticky = true, after, company, sub }: { sticky?: boolean; after?: string; company?: string; sub?: string }) {
  const L = useL()
  const [email, setEmail] = useState(''); const [pw, setPw] = useState(''); const [show, setShow] = useState(false)
  const login = useLogin()
  const err = login.error instanceof ApiError ? (login.error.field('email') ?? login.error.field('password') ?? login.error.message) : login.error ? L('تعذّر الاتصال بالخادم. تحقق من الشبكة وحاول مجدداً.', 'Could not reach the server. Check your connection and try again.') : ''
  const locked = login.error instanceof ApiError && login.error.code === 'locked'
  const submit = (e: FormEvent) => { e.preventDefault(); if (!login.isPending) login.mutate({ email: email.trim(), password: pw, company }, { onSuccess: () => after ? navigate(after) : go('') }) }
  const edit = (f: (v: string) => void) => (e: React.ChangeEvent<HTMLInputElement>) => { f(e.target.value); if (login.error && !locked) login.reset() }
  return <section className={`bg-surface border border-line rounded-2xl ${sticky ? 'lg:sticky lg:top-24' : ''}`}>
    <div className="p-6 border-b border-line-2">
      <h2 className="text-[20px] font-bold">{L('تسجيل الدخول', 'Sign in')}</h2>
      <p className="text-ink-2 text-[13px] mt-1">{sub ?? L('للمتقدمين لمتابعة طلباتهم، وللموظفين وفرق الموارد البشرية في الشركات.', 'For applicants following their applications, and for company staff and HR teams.')}</p>
    </div>
    <form onSubmit={submit} className="p-6 space-y-4" noValidate>
      <Field label={L('البريد الإلكتروني', 'Email')} req><input className="field ltr text-start" type="email" value={email} onChange={edit(setEmail)} autoComplete="username" required /></Field>
      <Field label={L('كلمة المرور', 'Password')} req><div className="relative">
        <input className="field ltr text-start !pr-10" type={show ? 'text' : 'password'} value={pw} onChange={edit(setPw)} autoComplete="current-password" required />
        <button type="button" onClick={() => setShow(!show)} className="absolute inset-y-0 right-0 w-10 grid place-items-center text-ink-3 hover:text-ink" aria-label={L('إظهار كلمة المرور', 'Show password')}>{show ? <EyeOff size={16} /> : <Eye size={16} />}</button>
      </div></Field>
      {err && <div role="alert" className="text-[13px] text-danger bg-danger-soft rounded px-3 py-2">{err}</div>}
      <Btn kind="primary" size="lg" className="w-full" type="submit" disabled={login.isPending || !email || !pw}>{login.isPending ? L('جارٍ الدخول…', 'Signing in…') : L('دخول', 'Sign in')}</Btn>
    </form>
    <a href={href('forgot', undefined, { email: email || undefined })} className={`w-full border-t border-line-2 flex items-center gap-2 px-6 h-11 text-[13px] text-ink-2 hover:bg-sub ${isAttendanceDevice() ? '' : 'rounded-b-2xl'}`}><KeyRound size={15} />{L('نسيت كلمة المرور؟', 'Forgot your password?')}</a>
    {isAttendanceDevice() && <a href="/kiosk" className="w-full border-t border-line-2 flex items-center gap-2 px-6 h-11 text-[13px] text-ink-2 hover:bg-sub rounded-b-2xl"><Nfc size={16} />{L('شاشة جهاز الحضور NFC', 'NFC attendance terminal')}</a>}
  </section>
}

export function Login({ after }: { after?: string }) {
  const ctx = usePortalContext().data
  if (ctx) return <CompanyLogin slug={ctx.slug} />
  return <div className="max-w-md mx-auto px-5 py-10"><LoginCard sticky={false} after={after} /></div>
}

/** دخول بوابة الشركة (ملاحظة 86): بهوية الشركة، ولا يدخل منها إلا موظفوها (والمتقدمون بحسابهم) */
export function CompanyLogin({ slug }: { slug: string }) {
  const L = useL(); const lang = useLang()
  const { data: c, error } = useCompanyPage(slug)
  if (error) return <div className="max-w-md mx-auto px-5 py-10"><LoginCard sticky={false} /></div>
  const color = c?.brand_color || undefined
  return <div className="max-w-md mx-auto px-5 py-10 space-y-4">
    <div className="rounded-2xl border border-line bg-surface overflow-hidden">
      <div className="h-1.5" style={{ background: color ?? 'var(--primary)' }} />
      <div className="p-5 flex items-center gap-4">{c ? <><CompanyLogo c={c} size={56} /><div className="min-w-0">
        {c.brand_latin && <div className="text-[11px] font-bold tracking-[.25em] ltr text-start" style={{ color: color ?? 'var(--primary)' }}>{c.brand_latin}</div>}
        <div className="font-bold text-[18px] leading-tight">{bi(c.name, lang)}</div><div className="text-[12.5px] text-ink-3">{L('بوابة الموظفين وفرص العمل', 'Staff and careers portal')}</div></div></> : <div className="h-14 w-full animate-pulse rounded-xl bg-sub" />}</div>
    </div>
    <LoginCard sticky={false} company={slug} sub={c ? L(`لموظفي ${bi(c.name, 'ar')} ولمتابعة طلبات التوظيف.`, `For ${bi(c.name, 'en')} staff and for following job applications.`) : undefined} />
    <a href={`/c/${slug}`} className="block text-center text-[13px] text-brand font-semibold">{L('فرص العمل في الشركة', 'Jobs at this company')}</a>
  </div>
}

export function Forgot() {
  const L = useL(); const route = useRoute()
  const [email, setEmail] = useState(route.query.email ?? '')
  const m = useMutation({ mutationFn: () => api<{ message: string; hint?: string }>('/auth/forgot', { json: { email: email.trim() } }) })
  const err = m.error instanceof ApiError ? (m.error.field('email') ?? m.error.message) : m.error ? L('تعذّر الاتصال بالخادم.', 'Could not reach the server.') : ''
  if (m.isSuccess) return <AuthCard title={L('تحقق من بريدك', 'Check your email')}>
    <div className="text-center"><div className="size-12 rounded-full bg-brand-soft text-brand grid place-items-center mx-auto"><MailCheck /></div>
      <p className="text-ink-2 mt-4">{m.data.message}</p>
      {m.data.hint && <p className="text-[13px] text-ink-2 mt-2">{m.data.hint}</p>}
      <p className="text-[13px] text-ink-3 mt-2">{L('لم تصلك الرسالة؟ تحقق من مجلد الرسائل غير المرغوبة، أو أعد الطلب بعد دقيقة.', "Didn't get it? Check your spam folder, or request again in a minute.")}</p>
      <div className="flex justify-center gap-2 mt-5"><Btn kind="primary" onClick={() => go('login')}>{L('العودة لتسجيل الدخول', 'Back to sign in')}</Btn><Btn onClick={() => m.reset()}>{L('إعادة الإرسال', 'Send again')}</Btn></div></div>
  </AuthCard>
  return <AuthCard title={L('نسيت كلمة المرور؟', 'Forgot your password?')} sub={L('أدخل البريد المسجّل في حسابك؛ يُرسل الرابط إليه فقط. موظفو الشركات: بريد العمل الرسمي.', 'Enter the email registered on your account; the link is sent only there. Company staff: your official work email.')}>
    <form onSubmit={e => { e.preventDefault(); m.mutate() }} className="space-y-4" noValidate>
      <Field label={L('البريد الإلكتروني', 'Email')} req error={err}><input className="field ltr text-start" type="email" value={email} onChange={e => { setEmail(e.target.value); m.reset() }} autoComplete="username" autoFocus /></Field>
      <Btn kind="primary" size="lg" className="w-full" type="submit" disabled={m.isPending || !email}>{m.isPending ? L('جارٍ الإرسال…', 'Sending…') : L('أرسل رابط التعيين', 'Send reset link')}</Btn>
    </form>
  </AuthCard>
}

/** تعيين كلمة مرور جديدة (من رابط البريد) */
export function Reset({ token, email }: { token: string; email: string }) {
  const L = useL()
  const [v, setV] = useState({ password: '', password_confirmation: '' })
  const m = useMutation({ mutationFn: () => api<{ message: string }>('/auth/reset', { json: { token, email, ...v } }) })
  const e = m.error instanceof ApiError ? m.error : null
  if (!token || !email) return <AuthCard title={L('رابط غير مكتمل', 'Incomplete link')}><p className="text-ink-2">{L('افتح الرابط كما وصلك في البريد، أو اطلب رابطاً جديداً.', 'Open the link exactly as it arrived in your email, or request a new one.')}</p><Btn className="mt-4" onClick={() => go('forgot')}>{L('طلب رابط جديد', 'Request a new link')}</Btn></AuthCard>
  if (m.isSuccess) return <AuthCard title={L('تم تعيين كلمة المرور', 'Password updated')}><p className="text-ink-2">{m.data.message}</p><Btn kind="primary" className="mt-4" onClick={() => go('login')}>{L('تسجيل الدخول', 'Sign in')}</Btn></AuthCard>
  return <AuthCard title={L('تعيين كلمة مرور جديدة', 'Set a new password')} sub={<span className="ltr">{email}</span>}>
    <form onSubmit={x => { x.preventDefault(); m.mutate() }} className="space-y-4" noValidate>
      <PasswordFields v={v} set={(k, x) => { setV(s => ({ ...s, [k]: x })); m.reset() }} errs={{ password: e?.field('password') }} />
      {e && !e.field('password') && <div role="alert" className="text-[13px] text-danger bg-danger-soft rounded px-3 py-2">{e.message}{e.code === 'invalid_token' && <> — <a className="underline" href={href('forgot', undefined, { email })}>{L('طلب رابط جديد', 'request a new link')}</a></>}</div>}
      <Btn kind="primary" size="lg" className="w-full" type="submit" disabled={m.isPending || !passwordOk(v)}>{L('حفظ كلمة المرور', 'Save password')}</Btn>
    </form>
  </AuthCard>
}

type InviteInfo = { email: string; name: { ar: string; en: string }; kind: 'invite' | 'reset'; company: { ar: string; en: string; logo: string | null } | null; expires_at: string | null; password?: { min: number; upper: boolean; symbol: boolean } }

/** رابط تفعيل الحساب أو تعيين كلمة المرور (يصل بالبريد): يختار كلمة المرور ويدخل مباشرة */
export function Activate({ token }: { token: string }) {
  const L = useL(); const lang = useLang()
  const info = useQuery({ queryKey: ['public', 'activate', token], queryFn: () => api<{ data: InviteInfo }>(`/auth/activate/${encodeURIComponent(token)}`), retry: false, enabled: !!token })
  const [v, setV] = useState({ password: '', password_confirmation: '' })
  const m = useActivate(token)
  const e = m.error instanceof ApiError ? m.error : null
  if (info.isLoading) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const bad = !token || info.isError || (e && e.code === 'invite_invalid')
  if (bad) return <AuthCard title={L('الرابط غير صالح', 'This link is not valid')}>
    <div className="text-center"><div className="size-12 rounded-full bg-danger-soft text-danger grid place-items-center mx-auto"><Link2Off /></div>
      <p className="text-ink-2 mt-4">{info.error instanceof ApiError && info.error.data.expired
        ? L('انتهت صلاحية هذا الرابط. اطلب رابطاً جديداً بإدخال بريدك، أو راجع مدير النظام.', 'This link has expired. Request a new one with your email, or contact your administrator.')
        : L('استُخدم هذا الرابط من قبل أو استُبدل برابط أحدث. إن كنت قد اخترت كلمة المرور فادخل بها مباشرة.', 'This link was already used or replaced by a newer one. If you already chose your password, just sign in.')}</p>
      <div className="flex flex-wrap justify-center gap-2 mt-5"><Btn kind="primary" onClick={() => go('login')}>{L('تسجيل الدخول', 'Sign in')}</Btn><Btn onClick={() => go('forgot')}>{L('طلب رابط جديد', 'Request a new link')}</Btn></div></div>
  </AuthCard>
  const d = info.data!.data
  const reset = d.kind === 'reset'
  return <AuthCard title={reset ? L('تعيين كلمة مرور جديدة', 'Set a new password') : L('تفعيل حسابك', 'Activate your account')}
    sub={<>{d.company && <span className="block font-semibold text-ink">{bi(d.company, lang)}</span>}{bi(d.name, lang)} · <span className="ltr inline-block">{d.email}</span></>}>
    {d.company?.logo && <img src={d.company.logo} alt="" className="h-10 mb-4 object-contain" />}
    <p className="text-[13.5px] text-ink-2 mb-4">{reset ? L('اختر كلمة مرور جديدة لحسابك. بعد الحفظ تدخل مباشرة.', 'Choose a new password for your account. You will be signed in right after.')
      : L('اختر كلمة مرور لحسابك. ستستخدمها مع بريدك في كل دخول، ثم تدخل الآن مباشرة.', 'Choose a password for your account. You will use it with your email from now on, and you will be signed in right away.')}</p>
    <form onSubmit={x => { x.preventDefault(); m.mutate(v, { onSuccess: () => navigate('/') }) }} className="space-y-4" noValidate>
      <PasswordFields policy={d.password} v={v} set={(k, x) => { setV(s => ({ ...s, [k]: x })); m.reset() }} errs={{ password: e?.field('password') }} label={reset ? undefined : L('كلمة المرور', 'Password')} />
      {e && !e.field('password') && <div role="alert" className="text-[13px] text-danger bg-danger-soft rounded px-3 py-2">{e.message}</div>}
      <Btn kind="primary" size="lg" className="w-full" type="submit" disabled={m.isPending || !passwordOk(v, d.password)}>{reset ? L('حفظ والدخول', 'Save and sign in') : L('تفعيل الحساب والدخول', 'Activate and sign in')}</Btn>
    </form>
  </AuthCard>
}
