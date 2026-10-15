import { useState, type FormEvent, type ReactNode } from 'react'
import { useQuery, useMutation } from '@tanstack/react-query'
import { MapPin, Briefcase, Nfc, KeyRound, ShieldCheck, Users, FileSignature, ArrowRight, ArrowLeft, Link2, CheckCircle2, MailCheck, Eye, EyeOff } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../lib/store'
import { api, ApiError } from '../lib/api'
import { useRoute, go, href } from '../lib/router'
import { useLogin } from '../lib/session'
import { Btn, Field, Badge, Empty, toast } from '../ui/kit'
import { Logo, LangToggle, ThemeToggle } from '../ui/brand'
import type { Bi } from '../lib/types'

/* ---------- البيانات العامة ---------- */
export interface PublicJob {
  code: string; title: Bi; summary: Bi; dept: Bi & { code: string }; city: Bi; type: Bi; posted_at: string
  requirements?: Bi[]; openings?: number; min_years?: number; degree?: number
}
interface Site { company: { name: Bi; short: Bi; brand_latin: string }; hero: { title: Bi; text: Bi } }

export const useSite = () => useQuery({ queryKey: ['public', 'site'], queryFn: () => api<Site>('/public/site'), staleTime: 5 * 60_000 })
export const useJobs = () => useQuery({ queryKey: ['public', 'jobs'], queryFn: () => api<{ data: PublicJob[] }>('/public/jobs').then(r => r.data) })
const useJob = (code: string) => useQuery({ queryKey: ['public', 'job', code], queryFn: () => api<{ data: PublicJob }>(`/public/jobs/${encodeURIComponent(code)}`).then(r => r.data), retry: false })

/** جهاز حضور مسجّل على هذا المتصفح (يُسجَّل من M16 — أجهزة الحضور) */
const isAttendanceDevice = () => { try { return !!localStorage.getItem('uhr-device') } catch { return false } }

/* ---------- الإطار ---------- */
export function Public() {
  const route = useRoute()
  const page = route.page
  return <div className="min-h-full flex flex-col app-glow">
    <header className="h-[68px] border-b border-line bg-canvas/70 backdrop-blur flex items-center justify-between gap-3 px-5 md:px-8 sticky top-0 z-20">
      <a href="#/" aria-label="UHR"><Logo h={30} /></a>
      <div className="flex items-center gap-2"><LangToggle /><span className="hidden sm:inline-flex"><ThemeToggle /></span></div>
    </header>
    <main className="flex-1">
      {page === 'jobs' && route.id ? <JobPage code={route.id} />
        : page === 'apply' && route.id ? <JobPage code={route.id} />
        : page === 'jobs' ? <JobsBoard full />
        : page === 'forgot' ? <Forgot />
        : page === 'reset' ? <Reset token={route.query.token ?? ''} email={route.query.email ?? ''} />
        : <Landing />}
    </main>
  </div>
}

/* ---------- الصفحة الرئيسية: الواجهة البارزة + الدخول + الوظائف ---------- */
function Landing() {
  const L = useL(); const lang = useLang()
  const site = useSite().data
  return <div className="max-w-6xl mx-auto px-5 md:px-8 py-6 md:py-8 space-y-7">
    <section className="show-root relative overflow-hidden rounded-3xl px-7 py-9 md:px-12 md:py-12">
      <div className="show-bg" aria-hidden><i /><i /></div><div className="show-grid" aria-hidden />
      <div className="relative grid md:grid-cols-[1.3fr_1fr] gap-8 items-center show-in">
        <div><div className="text-[12px] font-bold tracking-[.3em] text-brand min-h-[18px]">{site?.company.brand_latin}</div>
          <h1 className="mt-3 text-[clamp(30px,4vw,52px)] font-black leading-[1.15]">{site ? bi(site.hero.title, lang) : L('نظام الموارد البشرية المتكامل', 'The Complete People Operating System')}</h1>
          <p className="mt-3 text-show-fg/70 text-[16px] max-w-[52ch]">{site && bi(site.hero.text, lang)}</p>
          <div className="mt-6 flex flex-wrap gap-2"><button onClick={() => document.getElementById('jobs')?.scrollIntoView({ behavior: 'smooth' })} className="h-11 px-5 rounded-xl bg-brand text-on-brand font-bold hover:brightness-110">{L('ابدأ بالتقديم على وظيفة', 'Start by applying')}</button></div></div>
        <div className="grid grid-cols-3 gap-3">{[[<FileSignature key="a" />, L('11 نموذجاً رسمياً', '11 official forms')], [<Users key="b" />, L('رحلة كاملة', 'Full journey')], [<ShieldCheck key="c" />, L('اعتمادات رقمية', 'Digital approvals')]].map(([ic, t], k) =>
          <div key={k} className="rounded-2xl border border-show-fg/10 bg-show-fg/[.05] p-4 text-center"><div className="icon-orb mx-auto size-12 rounded-full border border-brand/40 grid place-items-center text-brand">{ic}</div><div className="mt-3 text-[13px] font-semibold">{t}</div></div>)}</div>
      </div>
    </section>
    <div className="grid lg:grid-cols-[380px_1fr] gap-7 items-start">
      <LoginCard />
      <JobsBoard />
    </div>
  </div>
}

function LoginCard() {
  const L = useL()
  const [email, setEmail] = useState(''); const [pw, setPw] = useState(''); const [show, setShow] = useState(false)
  const login = useLogin()
  const err = login.error instanceof ApiError ? (login.error.field('email') ?? login.error.field('password') ?? login.error.message) : login.error ? L('تعذّر الاتصال بالخادم. تحقق من الشبكة وحاول مجدداً.', 'Could not reach the server. Check your connection and try again.') : ''
  const locked = login.error instanceof ApiError && login.error.code === 'locked'
  const submit = (e: FormEvent) => { e.preventDefault(); if (!login.isPending) login.mutate({ email: email.trim(), password: pw }) }
  const edit = (f: (v: string) => void) => (e: React.ChangeEvent<HTMLInputElement>) => { f(e.target.value); if (login.error && !locked) login.reset() }
  return <section className="bg-surface border border-line rounded-2xl lg:sticky lg:top-24">
    <div className="p-6 border-b border-line-2">
      <h2 className="text-[20px] font-bold">{L('تسجيل الدخول', 'Sign in')}</h2>
      <p className="text-ink-2 text-[13px] mt-1">{L('للموظفين والمتقدمين الذين لديهم طلب توظيف.', 'For employees and applicants with an application.')}</p>
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
    <a href={href('forgot', undefined, email ? { email } : undefined)} className={`w-full border-t border-line-2 flex items-center gap-2 px-6 h-11 text-[13px] text-ink-2 hover:bg-sub ${isAttendanceDevice() ? '' : 'rounded-b-2xl'}`}><KeyRound size={15} />{L('نسيت كلمة المرور؟', 'Forgot your password?')}</a>
    {isAttendanceDevice() && <a href={href('kiosk')} className="w-full border-t border-line-2 flex items-center gap-2 px-6 h-11 text-[13px] text-ink-2 hover:bg-sub rounded-b-2xl"><Nfc size={16} />{L('شاشة جهاز الحضور NFC', 'NFC attendance terminal')}</a>}
  </section>
}

/* ---------- الوظائف الشاغرة ---------- */
export function JobsBoard({ full }: { full?: boolean }) {
  const L = useL(); const lang = useLang()
  const { data: jobs, isLoading, isError, refetch } = useJobs()
  return <section id="jobs" className={full ? 'max-w-4xl mx-auto px-5 py-8' : 'scroll-mt-24'}>
    <div className="flex items-end justify-between mb-3">
      <div><h2 className="text-[20px] font-bold">{L('الوظائف الشاغرة', 'Open positions')}</h2>
        <p className="text-ink-2 text-[13px]">{L('قدّم طلبك مباشرة، وتابع حالته من حسابك بعد الإرسال.', 'Apply directly and follow your application from your account.')}</p></div>
      {jobs && <span className="text-[13px] text-ink-3 num">{jobs.length} {L('وظائف', 'roles')}</span>}
    </div>
    <div className="grid gap-3">
      {isLoading && [0, 1, 2].map(k => <div key={k} className="rounded-2xl border border-line bg-surface p-5 h-[118px] animate-pulse" />)}
      {isError && <div className="rounded-2xl border border-line bg-surface"><Empty title={L('تعذّر تحميل الوظائف', 'Could not load positions')} action={<Btn size="sm" onClick={() => refetch()}>{L('إعادة المحاولة', 'Retry')}</Btn>} /></div>}
      {jobs?.length === 0 && <div className="rounded-2xl border border-line bg-surface"><Empty title={L('لا توجد وظائف شاغرة حالياً', 'No open positions right now')}>{L('تابعنا لاحقاً — تُنشر الوظائف الجديدة هنا فور اعتمادها.', 'Check back later — new roles are posted here as soon as they are approved.')}</Empty></div>}
      {jobs?.map((j, k) => <article key={j.code} className="hoverlift fadeup rounded-2xl border border-line bg-surface p-4 md:p-5 flex flex-col md:flex-row md:items-center gap-3" style={{ animationDelay: `${k * 60}ms` }}>
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap"><a href={href('jobs', j.code)} className="font-semibold text-[16px] hover:text-brand">{bi(j.title, lang)}</a><span className="text-[12px] text-ink-3 ltr">{j.code}</span></div>
          <p className="text-[13px] text-ink-2 mt-1 max-w-[62ch]">{bi(j.summary, lang)}</p>
          <JobMeta j={j} />
        </div>
        <Btn kind="primary" onClick={() => go('apply', j.code)}>{L('قدّم على الوظيفة', 'Apply for this role')}</Btn>
      </article>)}
    </div>
  </section>
}

function JobMeta({ j }: { j: PublicJob }) {
  const L = useL(); const lang = useLang()
  return <div className="flex flex-wrap gap-x-4 gap-y-1 mt-2 text-[12px] text-ink-3">
    <span className="flex items-center gap-1"><Briefcase size={13} />{bi(j.dept, lang)}</span>
    <span className="flex items-center gap-1"><MapPin size={13} />{bi(j.city, lang)}</span>
    <span>{bi(j.type, lang)}</span>
    <span>{L('نُشرت', 'Posted')} {fmtDate(j.posted_at, lang)}</span>
  </div>
}

function BackHome() {
  const L = useL(); const lang = useLang()
  return <a href="#/" className="text-[13px] text-ink-3 hover:text-ink inline-flex items-center gap-1">{lang === 'ar' ? <ArrowRight size={14} /> : <ArrowLeft size={14} />}{L('الصفحة الرئيسية', 'Home')}</a>
}

/* ---------- صفحة الوظيفة (رابط مباشر) — معالج التقديم F-28 يُبنى عليها في المهمة 04 ---------- */
function JobPage({ code }: { code: string }) {
  const L = useL(); const lang = useLang()
  const { data: j, isLoading, error } = useJob(code)
  const copy = () => { const url = location.origin + location.pathname + href('jobs', code); navigator.clipboard?.writeText(url).then(() => toast(L('تم نسخ رابط الوظيفة', 'Job link copied')), () => {}) }
  return <div className="max-w-4xl mx-auto px-5 py-8 space-y-4">
    <BackHome />
    {isLoading && <div className="rounded-2xl border border-line bg-surface h-56 animate-pulse" />}
    {error && <div className="rounded-2xl border border-line bg-surface"><Empty title={L('هذه الوظيفة غير متاحة', 'This position is not available')} action={<Btn onClick={() => go('jobs')}>{L('عرض الوظائف الشاغرة', 'See open positions')}</Btn>}>{error.message}</Empty></div>}
    {j && <>
      <article className="rounded-2xl border border-line bg-surface p-5 md:p-6">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0"><div className="flex items-center gap-2 flex-wrap"><h1 className="text-[22px] font-bold">{bi(j.title, lang)}</h1><span className="text-[12px] text-ink-3 ltr">{j.code}</span></div><JobMeta j={j} /></div>
          <Btn size="sm" icon={<Link2 size={14} />} onClick={copy}><span className="hidden sm:inline">{L('نسخ الرابط', 'Copy link')}</span></Btn>
        </div>
        <p className="text-ink-2 mt-4 max-w-[70ch]">{bi(j.summary, lang)}</p>
        {!!j.requirements?.length && <><h2 className="font-semibold mt-5 mb-2">{L('المتطلبات', 'Requirements')}</h2>
          <ul className="space-y-1.5">{j.requirements.map((r, i) => <li key={i} className="flex gap-2 text-[14px]"><CheckCircle2 size={16} className="text-brand shrink-0 mt-0.5" />{bi(r, lang)}</li>)}</ul></>}
        <div className="flex flex-wrap gap-2 mt-5">
          {!!j.openings && <Badge tone="brand">{L(`${j.openings} شواغر`, `${j.openings} openings`)}</Badge>}
          {j.min_years !== undefined && <Badge>{j.min_years ? L(`خبرة ${j.min_years}+ سنوات`, `${j.min_years}+ years experience`) : L('بدون خبرة سابقة', 'No prior experience')}</Badge>}
        </div>
      </article>
      <section className="rounded-2xl border border-dashed border-line bg-surface/60"><Empty title={L('نموذج طلب التوظيف F-28', 'Application form F-28')}>{L('معالج التقديم بأربع خطوات — المهمة 04.', 'Four-step application wizard — task 04.')}</Empty></section>
    </>}
  </div>
}

/* ---------- نسيت كلمة المرور ---------- */
function AuthCard({ title, sub, children }: { title: ReactNode; sub?: ReactNode; children: ReactNode }) {
  return <div className="max-w-md mx-auto px-5 py-10 space-y-4">
    <BackHome />
    <section className="bg-surface border border-line rounded-2xl">
      <div className="p-6 border-b border-line-2"><h1 className="text-[20px] font-bold">{title}</h1>{sub && <p className="text-ink-2 text-[13px] mt-1">{sub}</p>}</div>
      <div className="p-6">{children}</div>
    </section>
  </div>
}

function Forgot() {
  const L = useL(); const route = useRoute()
  const [email, setEmail] = useState(route.query.email ?? '')
  const m = useMutation({ mutationFn: () => api<{ message: string }>('/auth/forgot', { json: { email: email.trim() } }) })
  const err = m.error instanceof ApiError ? (m.error.field('email') ?? m.error.message) : m.error ? L('تعذّر الاتصال بالخادم.', 'Could not reach the server.') : ''
  if (m.isSuccess) return <AuthCard title={L('تحقق من بريدك', 'Check your email')}>
    <div className="text-center"><div className="size-12 rounded-full bg-brand-soft text-brand grid place-items-center mx-auto"><MailCheck /></div>
      <p className="text-ink-2 mt-4">{m.data.message}</p>
      <p className="text-[13px] text-ink-3 mt-2">{L('لم تصلك الرسالة؟ تحقق من مجلد الرسائل غير المرغوبة، أو أعد الطلب بعد دقيقة.', "Didn't get it? Check your spam folder, or request again in a minute.")}</p>
      <div className="flex justify-center gap-2 mt-5"><Btn kind="primary" onClick={() => go('')}>{L('العودة لتسجيل الدخول', 'Back to sign in')}</Btn><Btn onClick={() => m.reset()}>{L('إعادة الإرسال', 'Send again')}</Btn></div></div>
  </AuthCard>
  return <AuthCard title={L('نسيت كلمة المرور؟', 'Forgot your password?')} sub={L('أدخل بريدك المسجّل وسنرسل لك رابطاً لتعيين كلمة مرور جديدة.', "Enter your registered email and we'll send you a link to set a new password.")}>
    <form onSubmit={e => { e.preventDefault(); m.mutate() }} className="space-y-4" noValidate>
      <Field label={L('البريد الإلكتروني', 'Email')} req error={err}><input className="field ltr text-start" type="email" value={email} onChange={e => { setEmail(e.target.value); m.reset() }} autoComplete="username" autoFocus /></Field>
      <Btn kind="primary" size="lg" className="w-full" type="submit" disabled={m.isPending || !email}>{m.isPending ? L('جارٍ الإرسال…', 'Sending…') : L('أرسل رابط التعيين', 'Send reset link')}</Btn>
    </form>
  </AuthCard>
}

/* ---------- تعيين كلمة مرور جديدة (من رابط البريد) ---------- */
export function PasswordFields({ v, set, errs }: { v: { password: string; password_confirmation: string }; set: (k: 'password' | 'password_confirmation', x: string) => void; errs: Record<string, string | undefined> }) {
  const L = useL()
  const rules = [[v.password.length >= 8, L('8 أحرف على الأقل', 'At least 8 characters')], [/[A-Za-z؀-ۿ]/.test(v.password), L('حرف واحد على الأقل', 'At least one letter')], [/\d/.test(v.password), L('رقم واحد على الأقل', 'At least one number')]] as const
  return <>
    <Field label={L('كلمة المرور الجديدة', 'New password')} req error={errs.password}><input className="field ltr text-start" type="password" value={v.password} onChange={e => set('password', e.target.value)} autoComplete="new-password" /></Field>
    <ul className="text-[12px] space-y-0.5 -mt-2">{rules.map(([ok, t]) => <li key={t} className={`flex items-center gap-1.5 ${ok ? 'text-brand' : 'text-ink-3'}`}><CheckCircle2 size={13} />{t}</li>)}</ul>
    <Field label={L('تأكيد كلمة المرور', 'Confirm password')} req error={errs.password_confirmation ?? (v.password_confirmation && v.password_confirmation !== v.password ? L('كلمتا المرور غير متطابقتين', 'Passwords do not match') : undefined)}>
      <input className="field ltr text-start" type="password" value={v.password_confirmation} onChange={e => set('password_confirmation', e.target.value)} autoComplete="new-password" /></Field>
  </>
}
export const passwordOk = (v: { password: string; password_confirmation: string }) => v.password.length >= 8 && /[A-Za-z؀-ۿ]/.test(v.password) && /\d/.test(v.password) && v.password === v.password_confirmation

function Reset({ token, email }: { token: string; email: string }) {
  const L = useL()
  const [v, setV] = useState({ password: '', password_confirmation: '' })
  const m = useMutation({ mutationFn: () => api<{ message: string }>('/auth/reset', { json: { token, email, ...v } }) })
  const e = m.error instanceof ApiError ? m.error : null
  if (!token || !email) return <AuthCard title={L('رابط غير مكتمل', 'Incomplete link')}><p className="text-ink-2">{L('افتح الرابط كما وصلك في البريد، أو اطلب رابطاً جديداً.', 'Open the link exactly as it arrived in your email, or request a new one.')}</p><Btn className="mt-4" onClick={() => go('forgot')}>{L('طلب رابط جديد', 'Request a new link')}</Btn></AuthCard>
  if (m.isSuccess) return <AuthCard title={L('تم تعيين كلمة المرور', 'Password updated')}><p className="text-ink-2">{m.data.message}</p><Btn kind="primary" className="mt-4" onClick={() => go('')}>{L('تسجيل الدخول', 'Sign in')}</Btn></AuthCard>
  return <AuthCard title={L('تعيين كلمة مرور جديدة', 'Set a new password')} sub={<span className="ltr">{email}</span>}>
    <form onSubmit={x => { x.preventDefault(); m.mutate() }} className="space-y-4" noValidate>
      <PasswordFields v={v} set={(k, x) => { setV(s => ({ ...s, [k]: x })); m.reset() }} errs={{ password: e?.field('password') }} />
      {e && !e.field('password') && <div role="alert" className="text-[13px] text-danger bg-danger-soft rounded px-3 py-2">{e.message}{e.code === 'invalid_token' && <> — <a className="underline" href={href('forgot', undefined, { email })}>{L('طلب رابط جديد', 'request a new link')}</a></>}</div>}
      <Btn kind="primary" size="lg" className="w-full" type="submit" disabled={m.isPending || !passwordOk(v)}>{L('حفظ كلمة المرور', 'Save password')}</Btn>
    </form>
  </AuthCard>
}
