import { useState, useEffect } from 'react'
import { MapPin, Briefcase, Nfc, KeyRound, ChevronDown, Clock, ArrowRight, ArrowLeft, Play, ShieldCheck, Users, FileSignature } from 'lucide-react'
import { useStore, useL, useLang, bi, fmtDate, todayISO, fmtTime } from '../lib/store'
import { login, nfcScan } from '../lib/actions'
import { useNav } from '../lib/nav'
import { Btn, Field, Badge, Avatar, toast } from '../ui/kit'
import { Logo, LangToggle, ThemeToggle } from '../ui/brand'
import { setUI } from '../lib/ui'
import { Apply, Applied } from './Apply'
import { Terminal } from './Terminal'
import { calcDay, minsLabel } from '../lib/rules'

export function Public() {
  const { route, go } = useNav()
  const L = useL()
  // attract mode: only on the sign-in screen, after 90s without interaction
  useEffect(() => {
    if (route.page !== 'login') return
    let t = window.setTimeout(() => setUI({ showcase: true }), 90000)
    const reset = () => { clearTimeout(t); t = window.setTimeout(() => setUI({ showcase: true }), 90000) }
    const ev = ['pointerdown', 'keydown', 'pointermove', 'wheel']
    ev.forEach(e => window.addEventListener(e, reset))
    return () => { clearTimeout(t); ev.forEach(e => window.removeEventListener(e, reset)) }
  }, [route.page])
  return <div className="min-h-full flex flex-col app-glow">
    <header className="h-[68px] border-b border-line bg-canvas/70 backdrop-blur flex items-center justify-between gap-3 px-5 md:px-8 sticky top-0 z-20">
      <Logo h={30} />
      <div className="flex items-center gap-2"><Btn size="sm" icon={<Play size={14} />} onClick={() => setUI({ showcase: true })}><span className="hidden sm:inline whitespace-nowrap">{L('العرض التعريفي', 'Product tour')}</span></Btn><LangToggle /><span className="hidden sm:inline-flex"><ThemeToggle /></span></div>
    </header>
    <main className="flex-1">
      {route.page === 'apply' ? <Apply jobId={route.id!} /> : route.page === 'applied' ? <Applied candId={route.id!} /> : route.page === 'kiosk' ? <Terminal onBack={() => go('login')} /> : route.page === 'jobs' ? <JobsBoard full /> : <Login />}
    </main>
  </div>
}

function Login() {
  const L = useL(); const { go } = useNav()
  const [email, setEmail] = useState(''); const [pw, setPw] = useState(''); const [err, setErr] = useState('')
  const [demo, setDemo] = useState(false)
  const visitors = useStore(s => [...s.employees.filter(e => e.visitor && e.status !== 'terminated').map(e => ({ n: e.name, e: e.email, r: L('موظف', 'Employee') })), ...s.candidates.filter(c => c.visitor && c.stage !== 'hired').map(c => ({ n: c.name, e: c.email, r: L('متقدم', 'Applicant') }))].slice(0, 4))
  const submit = (e?: React.FormEvent) => { e?.preventDefault(); const r = login(email, pw); if (!r) setErr(L('البريد أو كلمة المرور غير صحيحة. تحقق منهما وحاول مجدداً.', 'Email or password is incorrect. Check them and try again.')) }
  const accounts = [
    { n: L('رشا الخطيب — الموارد البشرية', 'Rasha Al-Khatib — HR'), e: 'hr@alfarada.sy' },
    { n: L('سامر الحلبي — موظف', 'Samer Al-Halabi — employee'), e: 'samer.halabi@alfarada.sy' },
    { n: L('لين العلي — متقدمة لديها عرض عمل', 'Leen Al-Ali — applicant with offer'), e: 'leen.ali@mail.sy' },
  ]
  return <div className="max-w-6xl mx-auto px-5 md:px-8 py-6 md:py-8 space-y-7">
    <section className="show-root relative overflow-hidden rounded-3xl px-7 py-9 md:px-12 md:py-12">
      <div className="show-bg" aria-hidden><i /><i /></div><div className="show-grid" aria-hidden />
      <div className="relative grid md:grid-cols-[1.3fr_1fr] gap-8 items-center show-in">
        <div><div className="text-[12px] font-bold tracking-[.3em] text-brand">UNIQUENESS GROUP</div>
          <h1 className="mt-3 text-[clamp(30px,4vw,52px)] font-black leading-[1.15]">{L('نظام الموارد البشرية المتكامل', 'The Complete People Operating System')}</h1>
          <p className="mt-3 text-show-fg/70 text-[16px] max-w-[52ch]">{L('قدّم على وظيفة الآن، وعِش رحلة الموظف كاملة: المقابلة، عرض العمل، التعيين، المباشرة، الحضور… حتى إنهاء الخدمة.', 'Apply for a role now and live the full employee journey: interview, offer, appointment, onboarding, attendance… through to exit.')}</p>
          <div className="mt-6 flex flex-wrap gap-2"><button onClick={() => document.getElementById('jobs')?.scrollIntoView({ behavior: 'smooth' })} className="h-11 px-5 rounded-xl bg-brand text-on-brand font-bold hover:brightness-110">{L('ابدأ بالتقديم على وظيفة', 'Start by applying')}</button>
            <button onClick={() => setUI({ showcase: true })} className="h-11 px-5 rounded-xl border border-show-fg/20 font-semibold flex items-center gap-2 hover:bg-show-fg/10"><Play size={15} />{L('شاهد العرض التعريفي', 'Watch the tour')}</button></div></div>
        <div className="grid grid-cols-3 gap-3">{[[<FileSignature key="a" />, L('11 نموذجاً رسمياً', '11 official forms')], [<Users key="b" />, L('رحلة كاملة', 'Full journey')], [<ShieldCheck key="c" />, L('اعتمادات رقمية', 'Digital approvals')]].map(([ic, t], k) =>
          <div key={k} className="rounded-2xl border border-show-fg/10 bg-show-fg/[.05] p-4 text-center"><div className="icon-orb mx-auto size-12 rounded-full border border-brand/40 grid place-items-center text-brand">{ic}</div><div className="mt-3 text-[13px] font-semibold">{t}</div></div>)}</div>
      </div>
    </section>
    <div className="grid lg:grid-cols-[380px_1fr] gap-7 items-start">
    <section className="bg-surface border border-line rounded-2xl lg:sticky lg:top-24">
      <div className="p-6 border-b border-line-2">
        <h2 className="text-[20px] font-bold">{L('تسجيل الدخول', 'Sign in')}</h2>
        <p className="text-ink-2 text-[13px] mt-1">{L('للموظفين والمتقدمين الذين لديهم طلب توظيف.', 'For employees and applicants with an application.')}</p>
      </div>
      <form onSubmit={submit} className="p-6 space-y-4">
        <Field label={L('البريد الإلكتروني', 'Email')}><input className="field ltr text-start" type="email" value={email} onChange={e => { setEmail(e.target.value); setErr('') }} autoComplete="username" /></Field>
        <Field label={L('كلمة المرور', 'Password')}><input className="field ltr text-start" type="password" value={pw} onChange={e => { setPw(e.target.value); setErr('') }} autoComplete="current-password" /></Field>
        {err && <div className="text-[13px] text-danger bg-danger-soft rounded px-3 py-2">{err}</div>}
        <Btn kind="primary" size="lg" className="w-full" type="submit">{L('دخول', 'Sign in')}</Btn>
      </form>
      <div className="border-t border-line-2">
        <button onClick={() => setDemo(!demo)} className="w-full flex items-center justify-between px-6 h-11 text-[13px] text-ink-2 hover:bg-sub">
          <span className="flex items-center gap-2"><KeyRound size={15} />{L('حسابات العرض', 'Demo accounts')}</span><ChevronDown size={16} className={demo ? 'rotate-180' : ''} />
        </button>
        {demo && <div className="px-4 pb-4 space-y-1">
          {[...accounts, ...visitors.map(v => ({ n: `${v.n} — ${v.r}`, e: v.e }))].map(a => <button key={a.e} onClick={() => { setEmail(a.e); setPw('123456'); setErr('') }} className="w-full text-start rounded-md px-2 py-1.5 hover:bg-sub">
            <div className="text-[13px] font-medium">{a.n}</div><div className="text-[12px] text-ink-3 ltr text-start">{a.e}</div></button>)}
          <p className="text-[12px] text-ink-3 px-2 pt-1">{L('كلمة المرور لجميع حسابات العرض: 123456', 'Password for all demo accounts: 123456')}</p>
        </div>}
      </div>
      <button onClick={() => go('kiosk')} className="w-full border-t border-line-2 flex items-center gap-2 px-6 h-11 text-[13px] text-ink-2 hover:bg-sub rounded-b-2xl"><Nfc size={16} />{L('شاشة جهاز الحضور NFC', 'NFC attendance terminal')}</button>
    </section>
    <JobsBoard />
    </div>
  </div>
}

export function JobsBoard({ full }: { full?: boolean }) {
  const L = useL(); const lang = useLang(); const { go } = useNav()
  const jobs = useStore(s => s.jobs.filter(j => j.status === 'open'))
  const depts = useStore(s => s.depts)
  return <section id="jobs" className={full ? 'max-w-4xl mx-auto px-5 py-8' : 'scroll-mt-24'}>
    <div className="flex items-end justify-between mb-3">
      <div><h2 className="text-[20px] font-bold">{L('الوظائف الشاغرة', 'Open positions')}</h2>
        <p className="text-ink-2 text-[13px]">{L('قدّم طلبك مباشرة، وتابع حالته من حسابك بعد الإرسال.', 'Apply directly and follow your application from your account.')}</p></div>
      <span className="text-[13px] text-ink-3 num">{jobs.length} {L('وظائف', 'roles')}</span>
    </div>
    <div className="grid gap-3">
      {jobs.map((j, k) => <article key={j.id} className="hoverlift fadeup rounded-2xl border border-line bg-surface p-4 md:p-5 flex flex-col md:flex-row md:items-center gap-3" style={{ animationDelay: `${k * 60}ms` }}>
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap"><h3 className="font-semibold text-[16px]">{bi(j.title, lang)}</h3><span className="text-[12px] text-ink-3 ltr">{j.code}</span></div>
          <p className="text-[13px] text-ink-2 mt-1 max-w-[62ch]">{bi(j.summary, lang)}</p>
          <div className="flex flex-wrap gap-x-4 gap-y-1 mt-2 text-[12px] text-ink-3">
            <span className="flex items-center gap-1"><Briefcase size={13} />{depts.find(d => d.id === j.dept)?.[lang]}</span>
            <span className="flex items-center gap-1"><MapPin size={13} />{bi(j.city, lang)}</span>
            <span>{bi(j.type, lang)}</span>
            <span>{L('نُشرت', 'Posted')} {fmtDate(j.postedAt, lang)}</span>
          </div>
        </div>
        <Btn kind="primary" onClick={() => go('apply', j.id)}>{L('قدّم على الوظيفة', 'Apply for this role')}</Btn>
      </article>)}
    </div>
  </section>
}

/* ---------- NFC kiosk ---------- */
export function Kiosk({ onBack }: { onBack?: () => void }) {
  const L = useL(); const lang = useLang(); const { go } = useNav()
  const emps = useStore(s => s.employees.filter(e => e.status !== 'terminated'))
  const days = useStore(s => s.days)
  const [q, setQ] = useState('')
  const [clock, setClock] = useState(new Date())
  const [sim, setSim] = useState<number | null>(null) // minutes from midnight
  const [last, setLast] = useState<{ name: string; type: 'in' | 'out'; time: string; late: number; ot: number } | null>(null)
  useEffect(() => { const t = setInterval(() => setClock(new Date()), 1000); return () => clearInterval(t) }, [])
  const at = () => { const d = new Date(); if (sim !== null) { d.setHours(Math.floor(sim / 60), sim % 60, 0, 0) } return d }
  const shown = at()
  const list = emps.filter(e => !q || e.name.includes(q) || e.nameEn.toLowerCase().includes(q.toLowerCase()) || e.code.includes(q.toUpperCase())).sort((a, b) => Number(!!b.visitor) - Number(!!a.visitor))
  const tap = (id: string) => {
    const e = emps.find(x => x.id === id)!
    const r = nfcScan(id, at(), { ar: 'بوابة المقر — دمشق', en: 'HQ gate — Damascus' })
    const d = days.find(x => x.empId === id && x.date === todayISO())
    const c = r.type === 'in' ? calcDay(todayISO(), r.time) : calcDay(todayISO(), d?.inAt, r.time)
    setLast({ name: e.name, type: r.type, time: fmtTime(r.time), late: c.late, ot: c.ot })
    toast(r.type === 'in' ? L(`تسجيل دخول: ${e.name}`, `Checked in: ${e.nameEn}`) : L(`تسجيل خروج: ${e.name}`, `Checked out: ${e.nameEn}`))
  }
  void clock
  return <div className="max-w-6xl mx-auto px-5 md:px-8 py-6 grid lg:grid-cols-[1fr_380px] gap-6">
    <section className="show-root relative overflow-hidden rounded-2xl p-8 flex flex-col items-center justify-center text-center min-h-[460px]">
      <div className="text-show-fg/60 text-[13px]">{L('بوابة المقر الرئيسي — دمشق', 'Head office gate — Damascus')}</div>
      <div className="text-[64px] font-bold num leading-none mt-3 ltr">{fmtTime(shown.toISOString())}</div>
      <div className="text-show-fg/60 mt-1">{fmtDate(todayISO(), lang)}</div>
      <div key={last?.time + (last?.name ?? '')} className={`mt-8 size-36 rounded-full border-2 border-show-fg/30 grid place-items-center ${last ? 'scan-pulse' : ''}`}><Nfc size={56} className="text-show-fg/80" /></div>
      {last ? <div className="mt-6">
        <div className="text-[22px] font-semibold">{last.name}</div>
        <div className="mt-1 text-show-fg/80">{last.type === 'in' ? L('تم تسجيل الدخول', 'Checked in') : L('تم تسجيل الخروج', 'Checked out')} — <span className="num ltr">{last.time}</span></div>
        {last.type === 'in' && last.late > 0 && <div className="mt-2 inline-block bg-brass text-on-brand rounded px-3 py-1 text-[14px] font-medium">{L('تأخير', 'Late')} {minsLabel(last.late, lang)} — {L('يمكن تقديم تبرير من حسابك', 'you can justify it from your account')}</div>}
        {last.type === 'out' && last.ot > 0 && <div className="mt-2 inline-block bg-brand text-on-brand rounded px-3 py-1 text-[14px]">{L('عمل إضافي', 'Overtime')} {minsLabel(last.ot, lang)}</div>}
      </div> : <p className="mt-6 text-show-fg/70">{L('قرّب بطاقة الموظف من القارئ', 'Hold the employee card near the reader')}</p>}
    </section>
    <aside className="bg-surface border border-line rounded-2xl flex flex-col max-h-[640px]">
      <div className="p-4 border-b border-line-2 space-y-3">
        <div className="flex items-center justify-between"><h2 className="font-semibold">{L('محاكاة مرور البطاقة', 'Simulate card tap')}</h2><button onClick={() => (onBack ? onBack() : go('login'))} className="text-[13px] text-ink-3 hover:text-ink flex items-center gap-1">{lang === 'ar' ? <ArrowRight size={14} /> : <ArrowLeft size={14} />}{L('رجوع', 'Back')}</button></div>
        <input className="field" placeholder={L('ابحث بالاسم أو الرقم الوظيفي', 'Search name or employee ID')} value={q} onChange={e => setQ(e.target.value)} />
        <div className="flex items-center gap-2 text-[13px]">
          <Clock size={15} className="text-ink-3" />
          <span className="text-ink-2">{L('وقت المرور', 'Tap time')}</span>
          <select className="field !h-8 !w-auto" value={sim ?? ''} onChange={e => setSim(e.target.value === '' ? null : Number(e.target.value))}>
            <option value="">{L('الوقت الحالي', 'Current time')}</option>
            {[455, 478, 495, 520, 545, 960, 975, 1035, 1110].map(m => <option key={m} value={m}>{String(Math.floor(m / 60)).padStart(2, '0')}:{String(m % 60).padStart(2, '0')}</option>)}
          </select>
        </div>
      </div>
      <div className="overflow-y-auto divide-y divide-line-2">
        {list.map(e => { const d = days.find(x => x.empId === e.id && x.date === todayISO()); const st = d?.outAt ? L('غادر', 'Out') : d?.inAt ? L('داخل', 'In') : L('لم يسجّل', 'Not in')
          return <button key={e.id} onClick={() => tap(e.id)} className="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-brand-soft/50 text-start">
            <Avatar name={e.name} size={30} tone={e.visitor ? 'brass' : 'brand'} />
            <div className="flex-1 min-w-0"><div className="text-[14px] font-medium truncate">{lang === 'ar' ? e.name : e.nameEn}</div><div className="text-[12px] text-ink-3 ltr text-start">{e.nfcUid}</div></div>
            <Badge tone={d?.outAt ? 'neutral' : d?.inAt ? 'brand' : 'warn'}>{st}</Badge>
          </button> })}
      </div>
    </aside>
  </div>
}
