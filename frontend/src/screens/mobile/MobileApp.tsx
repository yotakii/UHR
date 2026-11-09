import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { Home, ListChecks, Plus, Bell, Menu, Plane, Hourglass, Banknote, FileText, Award, Wallet, LogIn, LogOut, Fingerprint, ChevronLeft, ChevronRight, Inbox, ExternalLink, Loader2, MapPin, Check, Eye, EyeOff, Lock } from 'lucide-react'
import { useL, useLang, bi, fmtDate, fmtDateTime, money, todayISO, setLang, setTheme, useTheme } from '../../lib/store'
import { navigate, useRoute } from '../../lib/router'
import { ApiError } from '../../lib/api'
import { can, useLogin, useLogout, useMe, type SessionUser } from '../../lib/session'
import { useCheckIn, useHome, position } from '../../lib/portal'
import { checkLeave, useCancelLeave, useInbox, useMyLeave, useSubmitLeave, type Check as LeaveCheck, type LeaveInput, type ReqRow } from '../../lib/requests'
import { useCancelService, useMyPayslips, useMyServices, useSubmitService, type ServiceInput, type ServiceKind } from '../../lib/payroll'
import { useMarkNoticesRead, useNotices } from '../../lib/console'
import { openVault } from '../../lib/vault'
import { isApp, mobileLink, startPush, stopPush } from '../../lib/mobile'
import { Badge, Btn, Empty, Field, toast } from '../../ui/kit'
import { Logo } from '../../ui/brand'
import { ConfirmSend, DecisionDialog } from '../../ui/decision'
import { VaultDialog } from '../../ui/vault'
import { DecideButtons, LeaveStatus, Period, StepsPath } from '../requests/parts'
import { ForceChangePassword } from '../Account'

/**
 * تطبيق الجوال للموظف (/m): الأساسيات فقط — الحضور، الإجازة، السلفة والشهادات، طلباتي، كشفي، الموافقات للمدير، الإشعارات.
 * صفحات خفيفة بلا جداول ولا لوحات؛ الباقي في «النسخة الكاملة». تعمل في المتصفح وداخل تطبيق Android.
 */
export function MobileApp() {
  const me = useMe()
  const user = me.data
  useEffect(() => { document.documentElement.classList.add('mobile-app'); return () => document.documentElement.classList.remove('mobile-app') }, [])
  if (me.isLoading) return <div className="min-h-dvh grid place-items-center app-glow"><Loader2 className="animate-spin text-brand" /></div>
  if (!user) return <MLogin />
  if (user.must_change_password) return <ForceChangePassword user={user} />
  if (!user.employee) return <NoEmployee user={user} />
  return <MShell user={user} />
}

/* ── الدخول ─────────────────────────────────────────── */
function MLogin() {
  const L = useL()
  const login = useLogin()
  const [email, setEmail] = useState(''); const [pw, setPw] = useState(''); const [show, setShow] = useState(false)
  const e = login.error instanceof ApiError ? login.error : null
  return <div className="min-h-dvh flex flex-col app-glow px-5" style={{ paddingTop: 'max(env(safe-area-inset-top), 40px)' }}>
    <div className="flex justify-between items-center"><Logo h={34} /><LangBtn /></div>
    <div className="mt-12"><h1 className="text-[24px] font-bold">{L('أهلاً بك', 'Welcome')}</h1><p className="text-ink-2 mt-1">{L('ادخل بحساب العمل نفسه الذي تستخدمه في النظام.', 'Sign in with your usual work account.')}</p></div>
    <form className="mt-8 space-y-4" noValidate onSubmit={ev => { ev.preventDefault(); login.mutate({ email: email.trim(), password: pw, remember: true }) }}>
      <Field label={L('البريد الإلكتروني', 'Email')} error={e?.field('email')}><input className="field ltr text-start h-12 text-[16px]" type="email" inputMode="email" autoComplete="username" value={email} onChange={x => setEmail(x.target.value)} /></Field>
      <Field label={L('كلمة المرور', 'Password')} error={e?.field('password')}>
        <div className="relative"><input className="field ltr text-start h-12 text-[16px] !pe-11" type={show ? 'text' : 'password'} autoComplete="current-password" value={pw} onChange={x => setPw(x.target.value)} />
          <button type="button" onClick={() => setShow(!show)} className="absolute end-2 top-1/2 -translate-y-1/2 size-9 grid place-items-center text-ink-3" aria-label={L('إظهار كلمة المرور', 'Show password')}>{show ? <EyeOff size={18} /> : <Eye size={18} />}</button></div></Field>
      {e && !e.field('email') && !e.field('password') && <div role="alert" className="text-[13.5px] text-danger bg-danger-soft rounded-lg px-3 py-2">{e.message}</div>}
      <button type="submit" disabled={login.isPending || !email || !pw} className="w-full h-12 rounded-xl bg-brand text-on-brand font-bold flex items-center justify-center gap-2 disabled:opacity-60">
        {login.isPending ? <Loader2 className="animate-spin" size={18} /> : <LogIn size={18} />}{L('دخول', 'Sign in')}</button>
      <p className="text-[12.5px] text-ink-3 text-center">{L('تبقى مسجّلاً على هذا الجوال حتى تضغط «خروج».', 'You stay signed in on this phone until you sign out.')}</p>
      <a href="/forgot" className="block text-center text-[13.5px] text-brand font-semibold">{L('نسيت كلمة المرور؟', 'Forgot your password?')}</a>
    </form>
  </div>
}

function NoEmployee({ user }: { user: SessionUser }) {
  const L = useL(); const lang = useLang(); const logout = useLogout()
  return <div className="min-h-dvh grid place-items-center px-6 app-glow"><div className="text-center space-y-3">
    <Logo h={30} /><p className="font-semibold">{bi(user.name, lang)}</p>
    <p className="text-ink-2 text-[14px]">{L('هذا التطبيق مخصص للموظفين. استخدم النظام من المتصفح.', 'This app is for employees. Please use the system from a browser.')}</p>
    <div className="flex justify-center gap-2"><Btn onClick={() => { location.href = '/' }}>{L('فتح النظام', 'Open the system')}</Btn><Btn kind="danger" onClick={() => logout.mutate()}>{L('خروج', 'Sign out')}</Btn></div>
  </div></div>
}

/* ── الإطار: رأس + محتوى + شريط سفلي ─────────────────── */
function MShell({ user }: { user: SessionUser }) {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const page = route.parts[1] ?? ''
  const notices = useNotices()
  const inbox = useInbox(true)
  const pending = (inbox.data?.data ?? []).filter(r => r.can_act).length
  useEffect(() => { void startPush(text => toast(text)) }, [])
  const title: Record<string, string> = { '': '', requests: L('طلباتي', 'My requests'), new: L('طلب جديد', 'New request'), leave: L('طلب إجازة', 'Leave request'), service: L('طلب خدمة', 'Service request'),
    pay: L('كشفي الشهري', 'My payslip'), approvals: L('الموافقات', 'Approvals'), notices: L('الإشعارات', 'Notifications'), more: L('المزيد', 'More') }
  const Back = lang === 'ar' ? ChevronRight : ChevronLeft
  const sub = ['leave', 'service', 'pay', 'approvals'].includes(page)
  return <div className="min-h-dvh flex flex-col bg-canvas">
    <header className="sticky top-0 z-20 bg-canvas/90 backdrop-blur border-b border-line flex items-center gap-2 px-4 h-14" style={{ paddingTop: 'env(safe-area-inset-top)' }}>
      {sub ? <button onClick={() => history.length > 1 ? history.back() : navigate('/m')} className="size-9 -ms-2 grid place-items-center" aria-label={L('رجوع', 'Back')}><Back size={22} /></button> : null}
      {page === '' ? <div className="flex items-center gap-2 min-w-0"><Logo h={24} /><span className="text-[13px] text-ink-3 truncate">{user.company ? bi(user.company.short, lang) : ''}</span></div>
        : <h1 className="font-bold text-[17px] truncate">{title[page] ?? ''}</h1>}
      <span className="flex-1" />
      <button onClick={() => navigate('/m/notices')} className="relative size-10 grid place-items-center" aria-label={title.notices}><Bell size={20} />
        {!!notices.data?.unread && <span className="absolute top-1.5 end-1.5 min-w-4 h-4 px-1 rounded-full bg-danger text-white text-[10px] font-bold grid place-items-center">{notices.data.unread}</span>}</button>
    </header>
    <main className="flex-1 px-4 pt-4 pb-28 max-w-[640px] w-full mx-auto">
      {page === '' ? <MHome user={user} pending={pending} />
        : page === 'requests' ? <MRequests />
        : page === 'new' ? <MNew />
        : page === 'leave' ? <MLeave />
        : page === 'service' ? <MService kind={(route.parts[2] ?? 'advance') as ServiceKind} />
        : page === 'pay' ? <MPay />
        : page === 'approvals' ? <MApprovals />
        : page === 'notices' ? <MNotices />
        : page === 'more' ? <MMore user={user} pending={pending} />
        : <MHome user={user} pending={pending} />}
    </main>
    <nav className="fixed bottom-0 inset-x-0 z-30 bg-surface border-t border-line grid grid-cols-5" style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}>
      <Tab to="/m" on={page === ''} icon={<Home size={21} />} label={L('الرئيسية', 'Home')} />
      <Tab to="/m/requests" on={page === 'requests'} icon={<ListChecks size={21} />} label={L('طلباتي', 'Requests')} />
      <button onClick={() => navigate('/m/new')} className="flex flex-col items-center justify-center -mt-5" aria-label={title.new}>
        <span className={`size-14 rounded-full grid place-items-center shadow-lg ${['new', 'leave', 'service'].includes(page) ? 'bg-brand-2' : 'bg-brand'} text-on-brand`}><Plus size={26} /></span></button>
      <Tab to="/m/pay" on={page === 'pay'} icon={<Wallet size={21} />} label={L('كشفي', 'Payslip')} />
      <Tab to="/m/more" on={page === 'more' || page === 'approvals'} icon={<Menu size={21} />} label={L('المزيد', 'More')} dot={pending > 0} />
    </nav>
    <VaultDialog />
  </div>
}
function Tab({ to, on, icon, label, dot }: { to: string; on: boolean; icon: ReactNode; label: string; dot?: boolean }) {
  return <button onClick={() => navigate(to)} className={`relative flex flex-col items-center justify-center gap-0.5 h-16 text-[11px] ${on ? 'text-brand font-semibold' : 'text-ink-3'}`}>
    {icon}{label}{dot && <span className="absolute top-2.5 end-[calc(50%-16px)] size-2 rounded-full bg-danger" />}</button>
}
function LangBtn() {
  const lang = useLang()
  return <button onClick={() => setLang(lang === 'ar' ? 'en' : 'ar')} className="h-9 px-3 rounded-lg border border-line text-[13px] font-semibold">{lang === 'ar' ? 'EN' : 'ع'}</button>
}
const Card = ({ children, className = '' }: { children: ReactNode; className?: string }) => <section className={`rounded-2xl border border-line bg-surface p-4 ${className}`}>{children}</section>

/* ── الرئيسية ───────────────────────────────────────── */
function MHome({ user, pending }: { user: SessionUser; pending: number }) {
  const L = useL(); const lang = useLang()
  const q = useHome(); const d = q.data
  const check = useCheckIn()
  const [locating, setLocating] = useState(false)
  if (!d) return <Loading />
  const t = d.today
  const doCheck = async () => {
    let pos: { lat: number; lng: number } | undefined
    if (d.geo) {
      setLocating(true)
      try { pos = await position() } catch { setLocating(false); toast(L('اسمح بتحديد الموقع لتسجيل الحضور ضمن نطاق المقر.', 'Allow location access to check in within the office radius.')); return }
      setLocating(false)
    }
    check.mutate(pos ?? {}, { onSuccess: r => toast(r.result.type === 'in' ? L(`سُجّل دخولك ${r.result.time}`, `Checked in at ${r.result.time}`) : L(`سُجّل خروجك ${r.result.time}`, `Checked out at ${r.result.time}`)),
      onError: e => toast(e instanceof ApiError ? e.message : L('تعذّر التسجيل', 'Could not record')) })
  }
  const h = new Date().getHours()
  const quick: { icon: ReactNode; t: string; to: string }[] = [
    { icon: <Plane size={22} />, t: L('إجازة', 'Leave'), to: '/m/leave' },
    { icon: <Hourglass size={22} />, t: L('مغادرة ساعية', 'Hourly'), to: '/m/leave?hourly=1' },
    { icon: <Banknote size={22} />, t: L('سلفة', 'Advance'), to: '/m/service/advance' },
    { icon: <FileText size={22} />, t: L('شهادة راتب', 'Salary cert.'), to: '/m/service/salary_cert' },
    { icon: <Award size={22} />, t: L('شهادة خبرة', 'Experience cert.'), to: '/m/service/experience_cert' },
    { icon: <Wallet size={22} />, t: L('كشفي', 'Payslip'), to: '/m/pay' },
  ]
  return <div className="space-y-4">
    <div><div className="text-ink-3 text-[13px]">{h < 12 ? L('صباح الخير', 'Good morning') : L('مساء الخير', 'Good evening')}</div>
      <div className="text-[21px] font-bold leading-tight">{bi(user.name, lang)}</div><div className="text-[13px] text-ink-2">{bi(d.me.title, lang)}</div></div>
    <Card className="!bg-show text-show-fg !border-transparent">
      <div className="flex items-center justify-between text-[13px] opacity-80"><span>{L('اليوم', 'Today')} · {fmtDate(t.date, lang)}</span>{t.late > 0 && <Badge tone="brass">{L(`تأخير ${t.late} د`, `${t.late} min late`)}</Badge>}</div>
      <div className="grid grid-cols-2 gap-3 mt-3">
        <div><div className="text-[12px] opacity-70">{L('الدخول', 'In')}</div><div className="text-[22px] font-bold num ltr text-start">{t.in ?? '—'}</div></div>
        <div><div className="text-[12px] opacity-70">{L('الخروج', 'Out')}</div><div className="text-[22px] font-bold num ltr text-start">{t.out ?? '—'}</div></div>
      </div>
      {t.checkin && <button disabled={check.isPending || locating} onClick={doCheck} className="mt-4 w-full h-12 rounded-xl bg-brand text-on-brand font-bold flex items-center justify-center gap-2 disabled:opacity-60">
        {check.isPending || locating ? <Loader2 className="animate-spin" size={18} /> : <Fingerprint size={20} />}{t.in ? L('تسجيل الخروج', 'Check out') : L('تسجيل الحضور', 'Check in')}</button>}
      {t.checkin && d.geo && <div className="mt-2 text-[11.5px] opacity-60 flex items-center gap-1"><MapPin size={12} />{L(`ضمن ${d.geo.radius} متر من المقر`, `Within ${d.geo.radius} m of the office`)}</div>}
    </Card>
    {pending > 0 && <button onClick={() => navigate('/m/approvals')} className="w-full rounded-2xl border border-brass/40 bg-brass-soft text-start p-4 flex items-center gap-3">
      <Inbox className="text-brass shrink-0" /><span className="flex-1 font-semibold">{L(`${pending} طلبات بانتظار موافقتك`, `${pending} requests awaiting your approval`)}</span><Chevron /></button>}
    <div className="grid grid-cols-2 gap-3">
      <Card><div className="text-[12px] text-ink-3">{L('رصيد الإجازة السنوية', 'Annual leave left')}</div><div className="text-[24px] font-bold num">{d.balance.annual.left}<span className="text-[13px] text-ink-3 font-normal"> / {d.balance.annual.of}</span></div></Card>
      <Card><div className="text-[12px] text-ink-3">{L('الإجازة المرضية', 'Sick leave used')}</div><div className="text-[24px] font-bold num">{d.balance.sick.used}{d.balance.sick.of !== null && <span className="text-[13px] text-ink-3 font-normal"> / {d.balance.sick.of}</span>}</div></Card>
    </div>
    <div className="grid grid-cols-3 gap-2.5">{quick.map(x => <button key={x.to} onClick={() => navigate(x.to)} className="rounded-2xl border border-line bg-surface h-[84px] flex flex-col items-center justify-center gap-1.5 text-[12.5px] font-semibold active:scale-[.98]">
      <span className="text-brand">{x.icon}</span>{x.t}</button>)}</div>
    {d.recent.length > 0 && <Card><div className="flex justify-between items-center mb-2"><h2 className="font-bold">{L('آخر طلباتي', 'Recent requests')}</h2><button onClick={() => navigate('/m/requests')} className="text-[13px] text-brand font-semibold">{L('الكل', 'All')}</button></div>
      <ul className="divide-y divide-line-2">{d.recent.slice(0, 4).map(r => <li key={r.kind + r.id} className="py-2.5 flex items-center gap-2">
        <span className="flex-1 min-w-0"><span className="block text-[14px] font-medium truncate">{r.title ? bi(r.title, lang) : r.type}</span><span className="block text-[12px] text-ink-3">{fmtDate(r.date ?? r.at, lang)}</span></span><LeaveStatus s={r.status} /></li>)}</ul></Card>}
  </div>
}
function Chevron() { const lang = useLang(); return lang === 'ar' ? <ChevronLeft size={18} className="text-ink-3" /> : <ChevronRight size={18} className="text-ink-3" /> }
function Loading() { return <div className="py-16 grid place-items-center"><Loader2 className="animate-spin text-brand" /></div> }

/* ── طلب جديد ───────────────────────────────────────── */
function MNew() {
  const L = useL()
  const items: { icon: ReactNode; t: string; s: string; to: string }[] = [
    { icon: <Plane />, t: L('إجازة', 'Leave'), s: L('سنوية، مرضية، طارئة وغيرها', 'Annual, sick, emergency and more'), to: '/m/leave' },
    { icon: <Hourglass />, t: L('مغادرة ساعية', 'Hourly permission'), s: L('بالساعات خلال يوم العمل', 'Hours within a work day'), to: '/m/leave?hourly=1' },
    { icon: <Banknote />, t: L('سلفة على الراتب', 'Salary advance'), s: L('تُخصم من كشف الشهر', 'Deducted from the monthly sheet'), to: '/m/service/advance' },
    { icon: <FileText />, t: L('شهادة راتب', 'Salary certificate'), s: L('لجهة رسمية', 'For an organisation'), to: '/m/service/salary_cert' },
    { icon: <Award />, t: L('شهادة عمل / خبرة', 'Employment certificate'), s: L('تعريف بالعمل', 'Proof of employment'), to: '/m/service/experience_cert' },
  ]
  return <div className="space-y-2.5">{items.map(x => <button key={x.to} onClick={() => navigate(x.to)} className="w-full rounded-2xl border border-line bg-surface p-4 flex items-center gap-3 text-start active:scale-[.99]">
    <span className="size-11 rounded-xl grid place-items-center bg-brand-soft text-brand shrink-0">{x.icon}</span>
    <span className="flex-1 min-w-0"><span className="block font-semibold">{x.t}</span><span className="block text-[12.5px] text-ink-3">{x.s}</span></span><Chevron /></button>)}</div>
}

/* ── طلب إجازة (نفس فحوص صفحة الطلبات) ──────────────── */
function MLeave() {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const q = useMyLeave(); const d = q.data
  const submit = useSubmitLeave()
  const hourly = route.query.hourly === '1'
  const types = useMemo(() => (d?.types ?? []).filter(t => hourly ? t.unit === 'hour' : t.unit !== 'hour'), [d, hourly])
  const [v, setV] = useState<LeaveInput>({ type: '', from: todayISO(), to: todayISO(), hours: 1, reason: '', substitute_id: '' })
  const [chk, setChk] = useState<LeaveCheck | null>(null)
  const [err, setErr] = useState<Record<string, string>>({})
  useEffect(() => { if (types.length && !types.some(t => t.key === v.type)) setV(x => ({ ...x, type: types[0].key })) }, [types, v.type])
  const type = types.find(t => t.key === v.type)
  const input: LeaveInput = { ...v, to: type?.unit === 'hour' ? v.from : v.to, hours: type?.unit === 'hour' ? v.hours : undefined }
  const key = JSON.stringify(input)
  useEffect(() => {
    if (!v.type) return
    const t = setTimeout(() => { checkLeave(input).then(setChk).catch(() => setChk(null)) }, 400)
    return () => clearTimeout(t)
  }, [key])   // eslint-disable-line react-hooks/exhaustive-deps
  if (!d) return <Loading />
  const errs = { ...(chk?.errors ?? {}), ...err }
  const send = () => { setErr({}); submit.mutateAsync(input).then(() => { toast(L('أُرسل الطلب', 'Request sent')); navigate('/m/requests', { replace: true }) })
    .catch(e => e instanceof ApiError && setErr(Object.keys(e.errors).length ? Object.fromEntries(Object.entries(e.errors).map(([k, m]) => [k, m[0]])) : { reason: e.message })) }
  const colleagues = [...d.colleagues].sort((a, b) => Number(b.same_dept) - Number(a.same_dept))
  return <div className="space-y-3.5">
    {!hourly && <Card className="!p-3 grid grid-cols-3 text-center">
      <div><div className="text-[11.5px] text-ink-3">{L('المتبقي', 'Left')}</div><div className="font-bold text-[18px] num">{d.balance.remaining}</div></div>
      <div><div className="text-[11.5px] text-ink-3">{L('المستخدم', 'Used')}</div><div className="font-bold text-[18px] num">{d.balance.used}</div></div>
      <div><div className="text-[11.5px] text-ink-3">{L('المحجوز', 'Held')}</div><div className="font-bold text-[18px] num">{d.balance.held}</div></div></Card>}
    <Field label={L('نوع الإجازة', 'Leave type')} error={errs.type}><select className="field h-12" value={v.type} onChange={e => setV({ ...v, type: e.target.value })}>{types.map(t => <option key={t.key} value={t.key}>{bi(t, lang)}</option>)}</select></Field>
    {type?.unit === 'hour' ? <div className="grid grid-cols-2 gap-3">
      <Field label={L('التاريخ', 'Date')} error={errs.from}><input type="date" className="field h-12" value={v.from} onChange={e => setV({ ...v, from: e.target.value, to: e.target.value })} /></Field>
      <Field label={L('عدد الساعات', 'Hours')} error={errs.hours}><input type="number" min={1} max={d.rules.hourly_max_per_day} className="field h-12 num" value={v.hours ?? 1} onChange={e => setV({ ...v, hours: Number(e.target.value) })} /></Field></div>
      : <div className="grid grid-cols-2 gap-3">
        <Field label={L('من', 'From')} error={errs.from}><input type="date" className="field h-12" value={v.from} onChange={e => setV({ ...v, from: e.target.value, to: e.target.value > v.to ? e.target.value : v.to })} /></Field>
        <Field label={L('إلى', 'To')} error={errs.to}><input type="date" className="field h-12" min={v.from} value={v.to} onChange={e => setV({ ...v, to: e.target.value })} /></Field></div>}
    <Field label={L('الموظف البديل', 'Substitute')} req={d.rules.substitute_required} error={errs.substitute_id}>
      <select className="field h-12" value={v.substitute_id} onChange={e => setV({ ...v, substitute_id: e.target.value ? Number(e.target.value) : '' })}>
        <option value="">{L('— اختر —', '— choose —')}</option>{colleagues.map(c => <option key={c.id} value={c.id}>{bi(c, lang)}</option>)}</select></Field>
    <Field label={L('السبب', 'Reason')} error={errs.reason}><textarea className="field min-h-[84px]" value={v.reason} maxLength={500} onChange={e => setV({ ...v, reason: e.target.value })} /></Field>
    {chk && <Card className="!p-3 text-[13px] space-y-1.5">
      {type?.unit !== 'hour' && <div>{L('عدد الأيام', 'Days')}: <b className="num">{chk.days}</b></div>}
      {chk.warnings.over_balance && <div className="text-brass">{L(`يتجاوز رصيدك المتبقي (${chk.warnings.over_balance.remaining} يوم)`, `Exceeds your remaining balance (${chk.warnings.over_balance.remaining} days)`)}</div>}
      {!!chk.warnings.conflict?.length && <div className="text-brass">{L('تتعارض مع إجازات زملاء في الإدارة', 'Overlaps colleagues’ leave in your department')}</div>}
      {chk.levels.length > 0 && <div className="text-ink-3">{L('مسار الاعتماد', 'Approval path')}: {chk.levels.map(l => bi(l, lang)).join(' ← ')}</div>}
      {type?.doc === 'required' && <div className="text-ink-3">{L('يلزم إرفاق مستند لاحقاً من النسخة الكاملة («إجازاتي وطلباتي»).', 'A document must be attached later from the full version.')}</div>}
    </Card>}
    <ConfirmSend block label={L('إرسال الطلب', 'Send request')} busy={submit.isPending} disabled={!v.type || !!Object.keys(chk?.errors ?? {}).length}
      summary={type ? `${bi(type, lang)} · ${fmtDate(v.from, lang)}${type.unit !== 'hour' && v.to !== v.from ? ' ← ' + fmtDate(v.to, lang) : ''}` : undefined} onConfirm={send} />
  </div>
}

/* ── السلفة والشهادات ───────────────────────────────── */
function MService({ kind }: { kind: ServiceKind }) {
  const L = useL(); const lang = useLang()
  const q = useMyServices(); const d = q.data
  const submit = useSubmitService()
  const [v, setV] = useState<ServiceInput>({ kind, addressee: {}, notify_consent: false })
  const [err, setErr] = useState<Record<string, string>>({})
  if (!d) return <Loading />
  const av = d.advance
  const to = (k: 'name' | 'cr_no' | 'email' | 'phone', x: string) => setV({ ...v, addressee: { ...v.addressee, [k]: x } })
  const titles: Record<string, string> = { salary_cert: L('شهادة راتب', 'Salary certificate'), experience_cert: L('شهادة عمل / خبرة', 'Employment certificate'), advance: L('سلفة على الراتب', 'Salary advance') }
  const send = () => { setErr({}); submit.mutateAsync({ ...v, kind }).then(() => { toast(L('أُرسل الطلب للموارد البشرية', 'Sent to HR')); navigate('/m/requests', { replace: true }) })
    .catch(e => e instanceof ApiError && setErr(Object.keys(e.errors).length ? Object.fromEntries(Object.entries(e.errors).map(([k, m]) => [k, m[0]])) : { note: e.message })) }
  return <div className="space-y-3.5">
    <h2 className="font-bold text-[18px]">{titles[kind] ?? ''}</h2>
    {kind === 'advance' && <Card className="!p-3">
      <div className="text-[12px] text-ink-3">{L('المتاح الآن', 'Available now')}</div>
      {av.available === null ? <button onClick={() => openVault()} className="mt-1 inline-flex items-center gap-1.5 text-brand font-semibold text-[14px]"><Lock size={15} />{L('تحقق من هويتك لعرض المبلغ', 'Verify your identity to see the amount')}</button>
        : <div className="text-[22px] font-bold num">{money(av.available, av.currency, lang)}</div>}
      <div className="text-[12px] text-ink-3 mt-1">{L('تُخصم من كشف هذا الشهر.', 'Deducted from this month’s sheet.')}</div></Card>}
    {kind === 'advance' && <Field label={L(`المبلغ (${av.currency === 'USD' ? 'دولار' : 'ل.س'})`, `Amount (${av.currency})`)} error={err.amount}>
      <input type="number" inputMode="numeric" min={1} max={av.available ?? undefined} className="field h-12 num" value={v.amount ?? ''} onChange={e => setV({ ...v, amount: Number(e.target.value) })} disabled={av.available !== null && av.available <= 0} /></Field>}
    {kind === 'salary_cert' && <>
      <p className="text-[13px] text-ink-3">{L('بيانات الجهة الطالبة إلزامية.', 'The requesting organisation’s details are required.')}</p>
      <Field label={L('اسم الجهة', 'Organisation name')} error={err['addressee.name']}><input className="field h-12" value={v.addressee?.name ?? ''} onChange={e => to('name', e.target.value)} /></Field>
      <Field label={L('رقم السجل التجاري', 'Commercial reg. no.')} error={err['addressee.cr_no']}><input className="field h-12 ltr" value={v.addressee?.cr_no ?? ''} onChange={e => to('cr_no', e.target.value)} /></Field>
      <Field label={L('هاتف الجهة', 'Phone')} error={err['addressee.phone']}><input className="field h-12 ltr" inputMode="tel" value={v.addressee?.phone ?? ''} onChange={e => to('phone', e.target.value)} /></Field>
      <Field label={L('البريد الرسمي للجهة', 'Official email')} error={err['addressee.email']}><input type="email" className="field h-12 ltr" value={v.addressee?.email ?? ''} onChange={e => to('email', e.target.value)} /></Field>
      <label className="flex items-start gap-2 text-[13.5px]"><input type="checkbox" className="mt-1 size-4" checked={!!v.notify_consent} onChange={e => setV({ ...v, notify_consent: e.target.checked })} />{L('أوافق على إبلاغ هذه الجهة عند انتهاء خدمتي', 'I agree that this organisation is notified when my service ends')}</label>
    </>}
    {kind === 'experience_cert' && <Field label={L('الجهة الموجّه إليها (اختياري)', 'Addressed to (optional)')} error={err['addressee.name']}><input className="field h-12" value={v.addressee?.name ?? ''} onChange={e => to('name', e.target.value)} /></Field>}
    <Field label={L('ملاحظات', 'Notes')} error={err.note ?? err.kind}><textarea className="field min-h-[72px]" value={v.note ?? ''} maxLength={1000} onChange={e => setV({ ...v, note: e.target.value })} /></Field>
    <ConfirmSend block label={L('إرسال الطلب', 'Send request')} busy={submit.isPending} summary={titles[kind]} onConfirm={send} disabled={kind === 'advance' && (!v.amount || (av.available !== null && av.available <= 0))} />
  </div>
}

/* ── طلباتي ─────────────────────────────────────────── */
function MRequests() {
  const L = useL(); const lang = useLang()
  const leave = useMyLeave(); const svc = useMyServices()
  const cancelL = useCancelLeave(); const cancelS = useCancelService()
  const [tab, setTab] = useState<'open' | 'all'>('open')
  const [ask, setAsk] = useState<ReqRow | null>(null)
  if (!leave.data || !svc.data) return <Loading />
  const rows = [...leave.data.requests, ...svc.data.data].sort((a, b) => b.created_at.localeCompare(a.created_at))
  const shown = tab === 'open' ? rows.filter(r => r.status.startsWith('pending')) : rows
  return <div className="space-y-3">
    <div className="grid grid-cols-2 gap-1 p-1 rounded-xl bg-sub">{(['open', 'all'] as const).map(k => <button key={k} onClick={() => setTab(k)} className={`h-9 rounded-lg text-[13.5px] font-semibold ${tab === k ? 'bg-surface shadow-sm' : 'text-ink-3'}`}>
      {k === 'open' ? L('قيد الاعتماد', 'In progress') : L('الكل', 'All')}</button>)}</div>
    {shown.length === 0 ? <Empty title={tab === 'open' ? L('لا طلبات قيد الاعتماد', 'Nothing in progress') : L('لا توجد طلبات بعد', 'No requests yet')} action={<Btn kind="primary" onClick={() => navigate('/m/new')}>{L('طلب جديد', 'New request')}</Btn>} />
      : shown.map(r => <Card key={r.kind + r.id} className="!p-3.5 space-y-2">
        <div className="flex items-start gap-2"><div className="flex-1 min-w-0"><div className="font-semibold">{bi(r.type_name, lang)}</div><div className="text-[13px] text-ink-2"><Period r={r} /></div></div><LeaveStatus s={r.status} /></div>
        <div className="overflow-hidden"><StepsPath steps={r.steps} /></div>
        <div className="flex items-center justify-between text-[12px] text-ink-3"><span>{fmtDateTime(r.created_at, lang)}</span>
          {r.can_cancel && <button onClick={() => setAsk(r)} className="text-danger font-semibold text-[13px]">{L('إلغاء الطلب', 'Cancel')}</button>}</div>
        {r.doc_required && !r.doc && r.status.startsWith('pending') && <div className="text-[12.5px] text-brass">{L('مطلوب مستند: أرفقه من النسخة الكاملة.', 'A document is required: attach it from the full version.')}</div>}
      </Card>)}
    <DecisionDialog open={!!ask} onClose={() => setAsk(null)} tone="danger" title={L('إلغاء الطلب', 'Cancel the request')} confirmLabel={L('تأكيد الإلغاء', 'Confirm cancel')}
      onConfirm={() => (ask!.kind === 'service' ? cancelS : cancelL).mutateAsync(ask!.id).then(() => toast(L('أُلغي الطلب', 'Request cancelled')))} />
  </div>
}

/* ── كشفي الشهري (بعد التحقق من الهوية) ─────────────── */
function MPay() {
  const L = useL(); const lang = useLang()
  const q = useMyPayslips(); const d = q.data
  const [open, setOpen] = useState<string | null>(null)
  if (!d) return <Loading />
  if (d.masked) return <Card className="text-center space-y-3 py-8"><Lock className="mx-auto text-brand" size={30} />
    <p className="font-semibold">{L('الرواتب محمية', 'Salaries are protected')}</p><p className="text-[13.5px] text-ink-2">{L('تحقق من هويتك لعرض كشفك الشهري.', 'Verify your identity to view your payslip.')}</p>
    <Btn kind="primary" onClick={() => openVault()}>{L('تحقق الآن', 'Verify now')}</Btn></Card>
  const slips = [d.current, ...d.data].filter(Boolean)
  const lines = (m: NonNullable<typeof d.current.money>) => [
    [L('الراتب الأساسي', 'Base'), m.base, 1], [L('العمل الإضافي', 'Overtime'), m.ot_pay, 1], [L('مكافآت', 'Bonus'), m.bonus ?? 0, 1],
    [L('حسم التأخير', 'Lateness'), m.late, -1], [L('حسم الغياب', 'Absence'), m.absent, -1], [L('إجازة غير مدفوعة', 'Unpaid leave'), m.leave, -1],
    [L('التأمينات', 'Insurance'), m.insurance, -1], [L('السلف', 'Advances'), m.advance, -1], [L('جزاءات', 'Penalties'), m.discipline ?? 0, -1],
  ].filter(x => Number(x[1]) > 0) as [string, number, number][]
  return <div className="space-y-3">{slips.map(s => {
    const m = s.pay ?? s.money
    const on = open === s.month
    return <Card key={s.month} className="!p-0 overflow-hidden">
      <button onClick={() => setOpen(on ? null : s.month)} className="w-full p-4 flex items-center gap-3 text-start">
        <span className="flex-1"><span className="block font-semibold">{new Date(s.month + '-15').toLocaleDateString(lang === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB', { month: 'long', year: 'numeric' })}</span>
          <span className="block text-[12px] text-ink-3">{s.status === 'estimate' ? L('تقدير حتى أمس', 'Estimate up to yesterday') : L('نهائي', 'Final')}</span></span>
        <span className="font-bold num text-[17px]">{m ? money(m.net, m.currency, lang) : '—'}</span></button>
      {on && m && <div className="border-t border-line-2 px-4 py-3 space-y-1.5 text-[13.5px]">
        {lines(m).map(([t, n, sign]) => <div key={t} className="flex justify-between"><span className="text-ink-2">{t}</span><span className={`num ${sign < 0 ? 'text-danger' : ''}`}>{sign < 0 ? '−' : ''}{money(n, m.currency, lang)}</span></div>)}
        <div className="flex justify-between font-bold pt-1.5 border-t border-line-2"><span>{L('الصافي', 'Net')}</span><span className="num">{money(m.net, m.currency, lang)}</span></div>
        <div className="text-[12px] text-ink-3 pt-1">{L(`حضور ${s.data.present} من ${s.data.work_days} يوم عمل`, `${s.data.present} of ${s.data.work_days} work days present`)}{s.data.late_days > 0 ? L(` · تأخير ${s.data.late_days} أيام`, ` · late on ${s.data.late_days} days`) : ''}</div>
      </div>}
    </Card>
  })}</div>
}

/* ── الموافقات (للمديرين) ───────────────────────────── */
function MApprovals() {
  const L = useL(); const lang = useLang()
  const q = useInbox(); const d = q.data
  if (!d) return <Loading />
  const rows = d.data.filter(r => r.can_act)
  if (!rows.length) return <Empty title={L('لا طلبات بانتظار موافقتك', 'Nothing awaiting your approval')} />
  return <div className="space-y-3">{rows.map(r => <Card key={r.kind + r.id} className="!p-3.5 space-y-2">
    <div><div className="font-semibold">{r.employee ? bi(r.employee.name, lang) : ''}</div><div className="text-[13px] text-ink-2">{bi(r.type_name, lang)} · <Period r={r} /></div></div>
    {r.reason && <p className="text-[13px] text-ink-2 bg-sub rounded-lg px-3 py-2">{r.reason}</p>}
    {(r.warnings.over_balance || !!r.warnings.conflict?.length) && <div className="text-[12.5px] text-brass">{r.warnings.over_balance ? L('يتجاوز الرصيد', 'Over balance') : L('يتعارض مع زملاء', 'Clashes with colleagues')}</div>}
    <DecideButtons r={r} rejectMin={d.reject_min} />
  </Card>)}</div>
}

/* ── الإشعارات ──────────────────────────────────────── */
function MNotices() {
  const L = useL(); const lang = useLang()
  const q = useNotices(); const read = useMarkNoticesRead()
  const unread = q.data?.unread ?? 0
  useEffect(() => { if (unread) { const t = setTimeout(() => read.mutate(), 1500); return () => clearTimeout(t) } }, [unread])   // eslint-disable-line react-hooks/exhaustive-deps
  if (!q.data) return <Loading />
  if (!q.data.data.length) return <Empty title={L('لا إشعارات', 'No notifications')} />
  return <ul className="space-y-2">{q.data.data.map(n => { const to = mobileLink(n.link)
    return <li key={n.id}><button disabled={!to} onClick={() => to && navigate(to)} className={`w-full text-start rounded-2xl border p-3.5 flex gap-3 ${n.read ? 'border-line bg-surface' : 'border-brand/40 bg-brand-soft/50'}`}>
      <span className={`mt-1.5 size-2 rounded-full shrink-0 ${n.read ? 'bg-line' : 'bg-brand'}`} />
      <span className="flex-1 min-w-0"><span className="block text-[14px] leading-snug">{bi(n.text, lang)}</span><span className="block text-[12px] text-ink-3 mt-1">{fmtDateTime(n.at, lang)}</span></span>
      {to && <Chevron />}</button></li> })}</ul>
}

/* ── المزيد ─────────────────────────────────────────── */
function MMore({ user, pending }: { user: SessionUser; pending: number }) {
  const L = useL(); const lang = useLang(); const theme = useTheme()
  const logout = useLogout()
  const approver = pending > 0 || can(user, 'leaves', 'decide') || user.roles.includes('manager')
  const out = async () => { await stopPush(); logout.mutate(undefined, { onSettled: () => navigate('/m', { replace: true }) }) }
  const row = (icon: ReactNode, t: string, onClick: () => void, extra?: ReactNode) => <button onClick={onClick} className="w-full flex items-center gap-3 px-4 h-14 text-start border-b border-line-2 last:border-0">
    <span className="text-ink-2">{icon}</span><span className="flex-1 font-medium">{t}</span>{extra}<Chevron /></button>
  return <div className="space-y-4">
    <Card className="flex items-center gap-3"><span className="size-12 rounded-full bg-brand-soft text-brand grid place-items-center font-bold text-[18px]">{bi(user.name, lang).slice(0, 1)}</span>
      <span className="min-w-0"><span className="block font-bold">{bi(user.name, lang)}</span><span className="block text-[13px] text-ink-2">{user.employee ? bi(user.employee.title, lang) : ''}</span>
        <span className="block text-[12px] text-ink-3 ltr text-start">{user.employee?.code} · {user.email}</span></span></Card>
    <section className="rounded-2xl border border-line bg-surface overflow-hidden">
      {approver && row(<Inbox size={20} />, L('الموافقات', 'Approvals'), () => navigate('/m/approvals'), pending > 0 ? <Badge tone="danger">{pending}</Badge> : null)}
      {row(<Wallet size={20} />, L('كشفي الشهري', 'My payslip'), () => navigate('/m/pay'))}
      {row(<Bell size={20} />, L('الإشعارات', 'Notifications'), () => navigate('/m/notices'))}
    </section>
    <section className="rounded-2xl border border-line bg-surface p-4 space-y-3">
      <div className="flex items-center justify-between"><span className="font-medium">{L('اللغة', 'Language')}</span>
        <div className="flex gap-1 p-1 rounded-lg bg-sub">{(['ar', 'en'] as const).map(l => <button key={l} onClick={() => setLang(l)} className={`h-8 px-3 rounded-md text-[13px] font-semibold ${lang === l ? 'bg-surface shadow-sm' : 'text-ink-3'}`}>{l === 'ar' ? 'العربية' : 'English'}</button>)}</div></div>
      <div className="flex items-center justify-between"><span className="font-medium">{L('المظهر', 'Theme')}</span>
        <div className="flex gap-1 p-1 rounded-lg bg-sub">{(['light', 'slate'] as const).map(t => <button key={t} onClick={() => setTheme(t)} className={`h-8 px-3 rounded-md text-[13px] font-semibold ${theme === t ? 'bg-surface shadow-sm' : 'text-ink-3'}`}>{t === 'light' ? L('فاتح', 'Light') : L('داكن', 'Dark')}</button>)}</div></div>
    </section>
    <section className="rounded-2xl border border-line bg-surface overflow-hidden">
      {row(<ExternalLink size={20} />, L('النسخة الكاملة', 'Full version'), () => { location.href = user.portal === 'hr' ? '/' : '/me' })}
      <button onClick={out} disabled={logout.isPending} className="w-full flex items-center gap-3 px-4 h-14 text-danger font-semibold"><LogOut size={20} />{L('خروج', 'Sign out')}</button>
    </section>
    <p className="text-center text-[12px] text-ink-3">{isApp() ? L('تطبيق UHR للموظفين', 'UHR employee app') : L('UHR — نسخة الجوال', 'UHR — mobile')} <Check size={12} className="inline" /></p>
  </div>
}

