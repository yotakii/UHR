import { useState, useEffect, useRef, type ReactNode } from 'react'
import { Home, Clock, CalendarCheck, Wallet, Users, FileSignature, Trophy, UserRound, LogOut, Bell, Smartphone, Plane, Hourglass, FileText, Banknote, Link2, ChevronLeft, ChevronRight, Megaphone, Printer, Check, DoorOpen } from 'lucide-react'
import { useStore, useL, useLang, todayISO, addDays, isWeekend, fmtDate, fmtTime, fmtDateTime, money, daysBetween } from '../lib/store'
import { logout, nfcScan, requestService, SERVICE_LABEL, updateContact, markNoticesRead } from '../lib/actions'
import { useNav } from '../lib/nav'
import { Logo, LangToggle, ThemeToggle } from '../ui/brand'
import { Btn, Badge, Avatar, Field, Modal, Panel, toast } from '../ui/kit'
import { CountUp } from '../ui/viz'
import { calcDay, minsLabel, annualEntitlement, hourlyRate, LEAVE_TYPES } from '../lib/rules'
import { MyAttendance, MyRequests, MyDocs, MyResign } from './Self'
import { MyRewards } from './Rewards'
import { Timeline, Lifecycle } from '../ui/docs'
import { lifecycleOf, LeaveStatus } from './Employees'
import type { ServiceKind } from '../lib/types'

const useMe = () => { const id = useStore(s => s.session!.id); return useStore(s => s.employees.find(e => e.id === id))! }

export function PortalShell() {
  const L = useL(); const lang = useLang(); const { route, go } = useNav(); const me = useMe()
  const toSign = useStore(s => s.assets.filter(a => a.empId === me?.id && a.status === 'pending_ack').length + (me?.onboarding ? Number(!me.onboarding.f22) + Number(!me.onboarding.f20) : 0))
  const notices = useStore(s => s.notices.filter(n => n.to === me?.id).slice(0, 10))
  const [bell, setBell] = useState(false); const [menu, setMenu] = useState(false)
  const newest = notices[0]?.id; const seen = useRef<string | undefined>(newest)
  useEffect(() => { if (newest && newest !== seen.current && notices[0] && !notices[0].read) toast(notices[0][lang]); seen.current = newest }, [newest]) // eslint-disable-line
  useEffect(() => { const u = notices.filter(n => !n.read).length; if (u) setTimeout(() => toast(lang === 'ar' ? `لديك ${u} إشعارات جديدة` : `You have ${u} new notifications`), 600) }, []) // eslint-disable-line
  if (!me) return null
  const tabs: { id: string; icon: ReactNode; label: string; badge?: number }[] = [
    { id: 'home', icon: <Home size={17} />, label: L('الرئيسية', 'Home') },
    { id: 'my-attendance', icon: <Clock size={17} />, label: L('حضوري', 'Attendance') },
    { id: 'my-requests', icon: <CalendarCheck size={17} />, label: L('طلباتي', 'Requests') },
    { id: 'payslip', icon: <Wallet size={17} />, label: L('راتبي', 'Pay') },
    { id: 'team', icon: <Users size={17} />, label: L('فريقي', 'Team') },
    { id: 'my-docs', icon: <FileSignature size={17} />, label: L('وثائقي', 'Documents'), badge: toSign },
    { id: 'my-rewards', icon: <Trophy size={17} />, label: L('المكافآت', 'Rewards') },
  ]
  const p = route.page
  const page = p === 'my-attendance' ? <MyAttendance /> : p === 'my-requests' ? <><Services /><div className="mt-6"><MyRequests /></div></> : p === 'payslip' ? <Payslip /> : p === 'team' ? <Team /> : p === 'my-docs' ? <MyDocs /> : p === 'my-rewards' ? <MyRewards /> : p === 'profile' ? <Profile /> : p === 'my-resign' ? <MyResign /> : <PortalHome />
  const unread = notices.filter(n => !n.read).length
  return <div className="min-h-full app-glow flex flex-col">
    <header className="sticky top-0 z-30 border-b border-line bg-canvas/80 backdrop-blur" data-noprint>
      <div className="max-w-[1180px] mx-auto px-4 md:px-6 h-16 flex items-center gap-4">
        <button onClick={() => go('home')}><Logo h={26} /></button>
        <span className="hidden xl:inline whitespace-nowrap text-[12px] text-ink-3 border-s border-line ps-3">{L('بوابة الموظف', 'Employee portal')}</span>
        <nav className="hidden lg:flex items-center gap-1 mx-auto">{tabs.map(t => <button key={t.id} onClick={() => go(t.id)} className={`relative h-9 px-3 rounded-lg text-[13.5px] flex items-center gap-1.5 ${p === t.id || (t.id === 'home' && !tabs.some(x => x.id === p) && p !== 'profile' && p !== 'my-resign') ? 'bg-brand-soft text-brand font-semibold' : 'text-ink-2 hover:bg-sub'}`}>{t.icon}{t.label}{!!t.badge && <span className="min-w-4 h-4 px-1 rounded-full bg-brand text-on-brand text-[10px] grid place-items-center num">{t.badge}</span>}</button>)}</nav>
        <div className="flex items-center gap-2 ms-auto lg:ms-0">
          <span className="hidden sm:flex"><LangToggle /></span><span className="hidden sm:flex"><ThemeToggle /></span>
          <div className="relative"><button onClick={() => { setBell(!bell); setMenu(false); if (!bell) setTimeout(markNoticesRead, 1500) }} className="relative h-9 w-9 grid place-items-center rounded-lg border border-line bg-surface"><Bell size={17} />{unread > 0 && <span className="absolute -top-1 -end-1 min-w-4 h-4 px-1 rounded-full bg-danger text-on-brand text-[10px] grid place-items-center num">{unread}</span>}</button>
            {bell && <div className="absolute end-0 top-11 w-[320px] bg-surface border border-line rounded-xl shadow-2xl z-40 fadeup max-h-[360px] overflow-y-auto">{notices.length === 0 ? <div className="p-4 text-[13px] text-ink-3">{L('لا توجد إشعارات', 'No notifications')}</div> : notices.map(n => <button key={n.id} onClick={() => { if (n.link) go(n.link); setBell(false) }} className="w-full text-start flex gap-2 px-4 py-2.5 border-b border-line-2 hover:bg-sub">{!n.read && <span className="size-2 rounded-full bg-brand mt-2 shrink-0" />}<span><span className="block text-[13px]">{n[lang]}</span><span className="block text-[11px] text-ink-3 num">{fmtDateTime(n.at, lang)}</span></span></button>)}</div>}</div>
          <div className="relative"><button onClick={() => { setMenu(!menu); setBell(false) }} className="flex items-center gap-2 h-10 ps-1 pe-2 rounded-lg hover:bg-sub"><Avatar name={me.name} size={32} tone={me.visitor ? 'brass' : 'brand'} /><span className="hidden xl:block text-start leading-tight whitespace-nowrap"><span className="block text-[13px] font-semibold">{lang === 'ar' ? me.name : me.nameEn}</span><span className="block text-[11px] text-ink-3">{me.code}</span></span></button>
            {menu && <div className="absolute end-0 top-12 w-52 bg-surface border border-line rounded-xl p-1 shadow-2xl z-40 fadeup">
              <button onClick={() => { go('profile'); setMenu(false) }} className="w-full flex items-center gap-2 px-3 h-9 rounded-lg hover:bg-sub text-[13px]"><UserRound size={15} />{L('ملفي الشخصي', 'My profile')}</button>
              <button onClick={() => { go('my-resign'); setMenu(false) }} className="w-full flex items-center gap-2 px-3 h-9 rounded-lg hover:bg-sub text-[13px]"><DoorOpen size={15} />{L('الاستقالة', 'Resignation')}</button>
              <button onClick={logout} className="w-full flex items-center gap-2 px-3 h-9 rounded-lg hover:bg-sub text-[13px] text-danger"><LogOut size={15} />{L('تسجيل الخروج', 'Sign out')}</button></div>}</div>
        </div>
      </div>
    </header>
    <main className="flex-1 max-w-[1180px] w-full mx-auto px-4 md:px-6 py-6 pb-24 lg:pb-8" onClick={() => { setBell(false); setMenu(false) }}>{page}</main>
    <nav className="lg:hidden fixed bottom-0 inset-x-0 z-30 bg-surface/95 backdrop-blur border-t border-line grid grid-cols-5" data-noprint>{tabs.slice(0, 5).map(t => <button key={t.id} onClick={() => go(t.id)} className={`h-16 flex flex-col items-center justify-center gap-1 text-[11px] ${p === t.id ? 'text-brand font-semibold' : 'text-ink-3'}`}>{t.icon}{t.label}</button>)}</nav>
  </div>
}

function Ring({ v, max, label, sub, tone = 'var(--primary)' }: { v: number; max: number; label: string; sub: string; tone?: string }) {
  const pct = Math.max(0, Math.min(1, v / Math.max(1, max)))
  return <div className="flex items-center gap-3"><svg viewBox="0 0 36 36" className="size-16 -rotate-90"><circle r="15.9" cx="18" cy="18" fill="none" stroke="var(--sub)" strokeWidth="3.4" /><circle r="15.9" cx="18" cy="18" fill="none" stroke={tone} strokeWidth="3.4" strokeLinecap="round" strokeDasharray={`${pct * 100} 100`} style={{ transition: 'stroke-dasharray .8s' }} /></svg>
    <div><div className="text-[22px] font-bold font-display num leading-none"><CountUp to={v} /><span className="text-[13px] text-ink-3"> / {max}</span></div><div className="text-[13px] font-semibold mt-1">{label}</div><div className="text-[11.5px] text-ink-3">{sub}</div></div></div>
}

function PortalHome() {
  const L = useL(); const lang = useLang(); const { go } = useNav(); const me = useMe()
  const s = useStore(x => x)
  const today = s.days.find(d => d.empId === me.id && d.date === todayISO())
  const c = calcDay(todayISO(), today?.inAt, today?.outAt)
  const ent = annualEntitlement(me)
  const mine = s.leaves.filter(l => l.empId === me.id).slice(0, 4)
  const svc = (s.services ?? []).filter(x => x.empId === me.id).slice(0, 3)
  const toSign = s.assets.filter(a => a.empId === me.id && a.status === 'pending_ack').length + (me.onboarding ? Number(!me.onboarding.f22) + Number(!me.onboarding.f20) : 0)
  const mgr = s.employees.find(e => e.id === me.managerId)
  const team = s.employees.filter(e => e.dept === me.dept && e.id !== me.id && e.status !== 'terminated')
  const away = team.filter(e => s.days.find(d => d.empId === e.id && d.date === todayISO())?.status === 'leave' || s.leaves.some(l => l.empId === e.id && l.status === 'approved' && l.from <= todayISO() && l.to >= todayISO()))
  const h = new Date().getHours()
  const tenure = daysBetween(me.hireDate, todayISO())
  const probEnd = addDays(me.hireDate, 90)
  const [svcOpen, setSvcOpen] = useState<ServiceKind | null>(null)
  const quick: { icon: ReactNode; t: string; f: () => void }[] = [
    { icon: <Plane size={20} />, t: L('طلب إجازة', 'Request leave'), f: () => go('my-requests') },
    { icon: <Hourglass size={20} />, t: L('مغادرة ساعية', 'Hourly permission'), f: () => go('my-requests') },
    { icon: <FileText size={20} />, t: L('شهادة راتب', 'Salary certificate'), f: () => setSvcOpen('salary_cert') },
    { icon: <Banknote size={20} />, t: L('سلفة', 'Advance'), f: () => setSvcOpen('advance') },
    { icon: <Link2 size={20} />, t: L('رشّح صديقاً', 'Refer a friend'), f: () => go('my-rewards') },
    { icon: <Wallet size={20} />, t: L('كشف الراتب', 'Payslip'), f: () => go('payslip') },
  ]
  return <div className="space-y-5">
    <section className="show-root relative overflow-hidden rounded-3xl p-6 md:p-8"><div className="show-bg" aria-hidden><i /><i /></div>
      <div className="relative grid md:grid-cols-[1.3fr_1fr] gap-6 items-center">
        <div><div className="text-[13px] text-show-fg/60">{fmtDate(todayISO(), lang)}</div>
          <h1 className="text-[clamp(26px,3.2vw,38px)] font-bold mt-1">{h < 12 ? L('صباح الخير', 'Good morning') : L('مساء الخير', 'Good evening')}، {(lang === 'ar' ? me.name : me.nameEn).split(' ')[0]}</h1>
          <p className="text-show-fg/65 mt-1">{me.title[lang]} · {s.depts.find(d => d.id === me.dept)?.[lang]} · {L('مديرك', 'Manager')}: {mgr ? (lang === 'ar' ? mgr.name : mgr.nameEn) : '—'}</p></div>
        <div className="rounded-2xl border border-show-fg/10 bg-show-fg/[.05] p-5">
          <div className="flex items-center justify-between"><span className="text-[13px] text-show-fg/60">{L('دوام اليوم', 'Today')}</span>{today?.inAt ? (c.late ? <Badge tone="warn">{L('متأخر', 'Late')} {minsLabel(c.late, lang)}</Badge> : <Badge tone="brand">{L('في الموعد', 'On time')}</Badge>) : <Badge>{L('لم تسجّل بعد', 'Not in yet')}</Badge>}</div>
          <div className="mt-2 flex items-baseline gap-5 font-display"><span><span className="text-[12px] text-show-fg/50 me-1">{L('دخول', 'In')}</span><span className="text-[26px] font-bold ltr num">{today?.inAt ? fmtTime(today.inAt) : '--:--'}</span></span><span><span className="text-[12px] text-show-fg/50 me-1">{L('خروج', 'Out')}</span><span className="text-[26px] font-bold ltr num">{today?.outAt ? fmtTime(today.outAt) : '--:--'}</span></span></div>
          {!today?.outAt && !isWeekend(todayISO()) && <button onClick={() => { const r = nfcScan(me.id, new Date(), { ar: 'بوابة الموظف — الجوال', en: 'Portal — mobile' }); toast(r.type === 'in' ? L('سُجّل دخولك', 'Checked in') : L('سُجّل خروجك', 'Checked out')) }} className="mt-4 w-full h-11 rounded-xl bg-brand text-on-brand font-bold flex items-center justify-center gap-2 hover:brightness-110"><Smartphone size={17} />{today?.inAt ? L('تسجيل الخروج', 'Check out') : L('تسجيل الحضور', 'Check in')}</button>}
        </div>
      </div>
    </section>

    {toSign > 0 && <button onClick={() => go('my-docs')} className="w-full flex items-center gap-3 rounded-2xl border border-brass/40 bg-brass-soft px-5 py-3 text-start fadeup"><FileSignature size={18} className="text-brass" /><span className="flex-1 font-semibold">{L(`لديك ${toSign} وثائق بانتظار توقيعك`, `${toSign} documents are waiting for your signature`)}</span>{lang === 'ar' ? <ChevronLeft size={18} /> : <ChevronRight size={18} />}</button>}

    <div className="grid grid-cols-3 sm:grid-cols-6 gap-3">{quick.map((q, i) => <button key={i} onClick={q.f} className="hoverlift rounded-2xl border border-line bg-surface p-4 flex flex-col items-center gap-2 text-center fadeup" style={{ animationDelay: `${i * 50}ms` }}><span className="size-11 rounded-xl grid place-items-center bg-brand-soft text-brand">{q.icon}</span><span className="text-[12.5px] font-semibold leading-tight">{q.t}</span></button>)}</div>

    <div className="grid lg:grid-cols-3 gap-5">
      <Panel title={L('أرصدتي', 'My balances')}><div className="space-y-5">
        <Ring v={ent - me.leaveUsed} max={ent} label={L('إجازة سنوية متبقية', 'Annual leave left')} sub={L(`استخدمت ${me.leaveUsed} يوماً`, `${me.leaveUsed} days used`)} />
        <Ring v={Math.max(0, 30 - me.sickUsed)} max={30} label={L('إجازة مرضية بأجر كامل', 'Full-pay sick leave')} sub={L('حسب السياسة', 'Per policy')} tone="var(--user)" />
        <Ring v={me.points ?? 0} max={Math.max(2000, me.points ?? 0)} label={L('نقاط المكافآت', 'Reward points')} sub={L('استبدلها من المتجر', 'Redeem in the store')} tone="var(--warn)" /></div></Panel>
      <Panel title={L('طلباتي الأخيرة', 'Recent requests')} action={<button onClick={() => go('my-requests')} className="text-[12.5px] text-brand font-semibold">{L('الكل', 'All')}</button>} pad={false}>
        <ul className="divide-y divide-line-2">{[...mine.map(l => ({ k: l.id, t: LEAVE_TYPES[l.type][lang], d: fmtDate(l.from, lang), st: <LeaveStatus s={l.status} /> })), ...svc.map(x => ({ k: x.id, t: SERVICE_LABEL[x.kind][lang], d: fmtDate(x.at, lang), st: x.status === 'pending' ? <Badge tone="brass">{L('قيد التنفيذ', 'In progress')}</Badge> : x.status === 'approved' ? <Badge tone="brand">{L('جاهز', 'Ready')}</Badge> : <Badge tone="danger">{L('مرفوض', 'Declined')}</Badge> }))].slice(0, 6).map(r => <li key={r.k} className="flex items-center justify-between gap-3 px-5 py-3"><span><span className="block text-[13.5px] font-semibold">{r.t}</span><span className="text-[12px] text-ink-3">{r.d}</span></span>{r.st}</li>)}
          {mine.length + svc.length === 0 && <li className="px-5 py-8 text-center text-[13px] text-ink-3">{L('لا توجد طلبات بعد', 'No requests yet')}</li>}</ul></Panel>
      <div className="space-y-5">
        <Panel title={<span className="flex items-center gap-2"><Megaphone size={16} className="text-brand" />{L('إعلانات الشركة', 'Company news')}</span>} pad={false}><ul className="divide-y divide-line-2">{(s.announcements ?? []).map(a => <li key={a.id} className="px-5 py-3"><div className="text-[13px]">{a[lang]}</div><div className="text-[11.5px] text-ink-3 mt-0.5">{fmtDate(a.at, lang)}</div></li>)}</ul></Panel>
        <Panel title={L('فريقي اليوم', 'My team today')} action={<button onClick={() => go('team')} className="text-[12.5px] text-brand font-semibold">{L('الكل', 'All')}</button>}><div className="flex flex-wrap gap-1.5">{team.slice(0, 8).map(e => { const inNow = !!s.days.find(d => d.empId === e.id && d.date === todayISO() && d.inAt && !d.outAt)
          return <span key={e.id} className="inline-flex items-center gap-1.5 rounded-full border border-line bg-sub/60 px-2.5 py-1 text-[12.5px]"><span className={`size-2 rounded-full ${inNow ? 'bg-brand' : 'bg-ink-3'}`} />{lang === 'ar' ? e.name : e.nameEn}</span> })}</div>
          <div className="text-[12.5px] text-ink-3 mt-3">{away.length ? L(`${away.length} في إجازة اليوم`, `${away.length} on leave today`) : L('الفريق كامل اليوم', 'Everyone is in today')}</div></Panel>
      </div>
    </div>

    <Panel title={L('رحلتي في الشركة', 'My journey')} sub={tenure >= 0 ? (me.status === 'probation' ? L(`فترة التجربة تنتهي ${fmtDate(probEnd, 'ar')}`, `Probation ends ${fmtDate(probEnd, 'en')}`) : L(`${(tenure / 365).toFixed(1)} سنة خدمة`, `${(tenure / 365).toFixed(1)} years of service`)) : undefined}>
      <Lifecycle steps={lifecycleOf(me, s.resignations.some(r => r.empId === me.id && r.status !== 'rejected'), L)} /></Panel>
    <ServiceModal kind={svcOpen} onClose={() => setSvcOpen(null)} />
  </div>
}

function ServiceModal({ kind, onClose }: { kind: ServiceKind | null; onClose: () => void }) {
  const L = useL(); const lang = useLang(); const me = useMe()
  const [note, setNote] = useState(''); const [amount, setAmount] = useState(200)
  if (!kind) return null
  return <Modal open onClose={onClose} title={SERVICE_LABEL[kind][lang]} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" onClick={() => { requestService(me.id, kind, note || SERVICE_LABEL[kind][lang], kind === 'advance' ? amount : undefined); onClose(); setNote(''); toast(L('أُرسل الطلب للموارد البشرية', 'Sent to HR')) }}>{L('إرسال الطلب', 'Submit')}</Btn></>}>
    <div className="space-y-3">{kind === 'advance' && <Field label={L('المبلغ (دولار)', 'Amount (USD)')} hint={L(`الحد الأعلى 50٪ من الراتب: $${Math.round(me.salary / 2)}`, `Max 50% of salary: $${Math.round(me.salary / 2)}`)}><input type="number" className="field num" value={amount} onChange={e => setAmount(Math.min(Math.round(me.salary / 2), Number(e.target.value)))} /></Field>}
      <Field label={kind === 'salary_cert' || kind === 'experience_cert' ? L('الجهة الموجّه إليها', 'Addressed to') : L('ملاحظات', 'Notes')}><textarea className="field" value={note} onChange={e => setNote(e.target.value)} placeholder={kind === 'salary_cert' ? L('مثلاً: المصرف التجاري السوري', 'e.g. Commercial Bank of Syria') : ''} /></Field></div>
  </Modal>
}

function Services() {
  const L = useL(); const lang = useLang(); const me = useMe()
  const list = useStore(s => (s.services ?? []).filter(x => x.empId === me.id))
  const [open, setOpen] = useState<ServiceKind | null>(null); const [print, setPrint] = useState<string | null>(null)
  const kinds: [ServiceKind, ReactNode][] = [['salary_cert', <FileText size={20} />], ['experience_cert', <FileSignature size={20} />], ['advance', <Banknote size={20} />], ['data_update', <UserRound size={20} />]]
  const pr = list.find(x => x.id === print)
  return <div>
    <h2 className="text-[20px] font-bold mb-3">{L('طلبات الخدمات', 'Service requests')}</h2>
    <div className="grid grid-cols-2 md:grid-cols-4 gap-3">{kinds.map(([k, ic]) => <button key={k} onClick={() => setOpen(k)} className="hoverlift rounded-2xl border border-line bg-surface p-4 flex items-center gap-3 text-start"><span className="size-11 rounded-xl grid place-items-center bg-brand-soft text-brand">{ic}</span><span className="font-semibold text-[13.5px]">{SERVICE_LABEL[k][lang]}</span></button>)}</div>
    {list.length > 0 && <div className="mt-3 rounded-2xl border border-line bg-surface divide-y divide-line-2">{list.map(x => <div key={x.id} className="flex items-center gap-3 px-5 py-3"><span className="flex-1"><span className="block font-semibold text-[13.5px]">{SERVICE_LABEL[x.kind][lang]}{x.amount ? ` — $${x.amount}` : ''}</span><span className="text-[12px] text-ink-3">{fmtDateTime(x.at, lang)}</span></span>
      {x.status === 'pending' ? <Badge tone="brass">{L('قيد التنفيذ', 'In progress')}</Badge> : x.status === 'approved' ? (x.kind.endsWith('cert') ? <Btn size="sm" icon={<Printer size={14} />} onClick={() => setPrint(x.id)}>{L('عرض الشهادة', 'View certificate')}</Btn> : <Badge tone="brand">{L('معتمد', 'Approved')}</Badge>) : <Badge tone="danger">{L('مرفوض', 'Declined')}</Badge>}</div>)}</div>}
    <ServiceModal kind={open} onClose={() => setOpen(null)} />
    <Modal open={!!pr} onClose={() => setPrint(null)} title={pr ? SERVICE_LABEL[pr.kind][lang] : ''} width={640} footer={<Btn icon={<Printer size={15} />} onClick={() => window.print()}>{L('طباعة', 'Print')}</Btn>}>
      {pr && <div className="sheet rounded-xl p-6 text-[14px] leading-8"><div className="font-bold">{L('شركة الفرادة للتطوير والاستثمار', 'Uniqueness Development & Investment')}</div><div className="text-[12px] text-[#6b7680]">{L('إدارة الموارد البشرية', 'Human Resources')} · {fmtDate(pr.doneAt, lang)}</div>
        <div className="mt-4 font-bold text-[17px] text-center">{L('إلى من يهمه الأمر', 'To whom it may concern')}</div>
        <p className="mt-3">{pr.kind === 'salary_cert' ? L(`تشهد شركة الفرادة بأن السيد/ة ${me.name} يعمل لديها بوظيفة ${me.title.ar} منذ ${fmtDate(me.hireDate, 'ar')}، ويتقاضى راتباً شهرياً إجمالياً قدره ${money(me.salary, me.currency, 'ar')}. أُعطيت هذه الشهادة بناءً على طلبه لتقديمها إلى: ${pr.note}.`, `Uniqueness certifies that ${me.nameEn} has been employed as ${me.title.en} since ${fmtDate(me.hireDate, 'en')}, with a gross monthly salary of ${money(me.salary, me.currency, 'en')}. Issued at the employee’s request for: ${pr.note}.`)
          : L(`تشهد شركة الفرادة بأن السيد/ة ${me.name} يعمل لديها بوظيفة ${me.title.ar} منذ ${fmtDate(me.hireDate, 'ar')} وما يزال على رأس عمله. أُعطيت هذه الشهادة بناءً على طلبه.`, `Uniqueness certifies that ${me.nameEn} has been employed as ${me.title.en} since ${fmtDate(me.hireDate, 'en')} and remains in service.`)}</p>
        <div className="mt-6 flex justify-between items-end"><span className="text-[12px] text-[#6b7680]">{L('مديرة الموارد البشرية', 'HR Director')}<br /><b className="text-[#17242f]">رشا الخطيب</b></span><span className="stamp text-[#0e6b5c] text-[12px]">{L('الفرادة — الموارد البشرية', 'UNIQUENESS — HR')}</span></div></div>}
    </Modal>
  </div>
}

function Payslip() {
  const L = useL(); const lang = useLang(); const me = useMe()
  const days = useStore(s => s.days.filter(d => d.empId === me.id))
  const [m, setM] = useState(0)
  const ref = new Date(); ref.setDate(1); ref.setMonth(ref.getMonth() - m)
  const ym = `${ref.getFullYear()}-${String(ref.getMonth() + 1).padStart(2, '0')}`
  const md = days.filter(d => d.date.startsWith(ym))
  let late = 0, otPay = 0; md.forEach(d => { const c = calcDay(d.date, d.inAt, d.outAt); if (!d.justified) late += Math.max(0, c.late - 0); otPay += c.ot / 60 * hourlyRate(me) * (1 + c.otRate) })
  const basic = Math.round(me.salary * .72), housing = Math.round(me.salary * .14), transport = Math.round(me.salary * .08), other = me.salary - basic - housing - transport
  const ins = Math.round(basic * .07), lateDed = Math.round(late / 60 * hourlyRate(me))
  const net = me.salary + otPay - ins - lateDed
  const row = (k: string, v: number, neg?: boolean) => <div className="flex justify-between py-2 border-b border-line-2 text-[14px]"><span className={neg ? 'text-ink-2' : ''}>{k}</span><span className={`num font-semibold ${neg ? 'text-danger' : ''}`}>{neg ? '− ' : ''}{money(v, me.currency, lang)}</span></div>
  return <div className="max-w-[880px] mx-auto">
    <div className="flex items-center justify-between mb-4"><h1 className="text-[22px] font-bold">{L('كشف الراتب', 'Payslip')}</h1>
      <div className="flex items-center gap-1 rounded-lg border border-line bg-surface p-0.5">{[0, 1, 2].map(k => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - k); return <button key={k} onClick={() => setM(k)} className={`h-8 px-3 rounded-md text-[13px] ${m === k ? 'bg-brand text-on-brand font-semibold' : 'text-ink-2'}`}>{d.toLocaleDateString(lang === 'ar' ? 'ar-SY' : 'en-GB', { month: 'long' })}</button> })}</div></div>
    <div className="grid md:grid-cols-[1fr_280px] gap-5">
      <Panel title={ref.toLocaleDateString(lang === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB', { month: 'long', year: 'numeric' })} action={<Btn size="sm" icon={<Printer size={14} />} onClick={() => window.print()}>{L('طباعة', 'Print')}</Btn>}>
        <div className="text-[12px] font-semibold text-ink-3 mb-1">{L('المستحقات', 'Earnings')}</div>
        {row(L('الراتب الأساسي', 'Basic salary'), basic)}{row(L('بدل السكن / غلاء المعيشة', 'Housing / COL'), housing)}{row(L('بدل المواصلات', 'Transport'), transport)}{row(L('بدلات أخرى', 'Other allowances'), other)}{otPay > 0 && row(L('أجر العمل الإضافي', 'Overtime pay'), Math.round(otPay))}
        <div className="text-[12px] font-semibold text-ink-3 mt-4 mb-1">{L('الاقتطاعات', 'Deductions')}</div>
        {row(L('التأمينات الاجتماعية (حصة العامل)', 'Social insurance (employee)'), ins, true)}{lateDed > 0 && row(L(`تأخير غير مبرر (${minsLabel(late, 'ar')})`, `Unjustified lateness (${minsLabel(late, 'en')})`), lateDed, true)}
        <div className="flex justify-between pt-3 mt-1 text-[17px] font-bold"><span>{L('صافي الراتب', 'Net pay')}</span><span className="num text-brand">{money(net, me.currency, lang)}</span></div>
        <p className="text-[11.5px] text-ink-3 mt-3">{L('كشف تقديري محسوب من بيانات الحضور؛ الكشف الرسمي يصدر عن الإدارة المالية.', 'Estimate from attendance data; the official payslip is issued by Finance.')}</p>
      </Panel>
      <div className="space-y-4"><div className="rounded-2xl border border-line bg-surface p-5"><div className="text-[12.5px] text-ink-3">{L('صافي هذا الشهر', 'Net this month')}</div><div className="text-[34px] font-bold font-display num text-brand"><CountUp to={Math.round(net)} /> <span className="text-[14px] text-ink-3">{me.currency === 'USD' ? '$' : L('ل.س', 'SYP')}</span></div></div>
        <div className="rounded-2xl border border-line bg-surface p-5 text-[13px] space-y-2"><div className="flex justify-between"><span className="text-ink-3">{L('أيام الحضور', 'Days present')}</span><b className="num">{md.filter(d => d.inAt).length}</b></div><div className="flex justify-between"><span className="text-ink-3">{L('موعد الصرف', 'Pay date')}</span><b>28</b></div><div className="flex justify-between"><span className="text-ink-3">{L('طريقة الدفع', 'Method')}</span><b>{L('تحويل مصرفي', 'Bank transfer')}</b></div></div></div>
    </div>
  </div>
}

function Team() {
  const L = useL(); const lang = useLang(); const me = useMe()
  const s = useStore(x => x)
  const team = s.employees.filter(e => e.dept === me.dept && e.status !== 'terminated')
  return <div className="max-w-[1000px] mx-auto"><h1 className="text-[22px] font-bold mb-1">{L('فريقي', 'My team')}</h1><p className="text-ink-3 mb-4">{s.depts.find(d => d.id === me.dept)?.[lang]}</p>
    <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">{team.map(e => { const d = s.days.find(x => x.empId === e.id && x.date === todayISO()); const st = d?.status === 'leave' ? 'leave' : d?.outAt ? 'out' : d?.inAt ? 'in' : 'none'
      return <div key={e.id} className={`rounded-2xl border bg-surface p-4 flex items-center gap-3 ${e.id === me.id ? 'border-brand/50' : 'border-line'}`}><div className="relative"><Avatar name={e.name} size={44} /><span className={`absolute -bottom-0.5 -end-0.5 size-3.5 rounded-full ring-2 ring-surface ${st === 'in' ? 'bg-brand' : st === 'leave' ? 'bg-brass' : 'bg-ink-3'}`} /></div>
        <div className="flex-1 min-w-0"><div className="font-semibold truncate">{lang === 'ar' ? e.name : e.nameEn}{e.id === me.id && <span className="text-ink-3 text-[12px]"> ({L('أنت', 'you')})</span>}</div><div className="text-[12px] text-ink-3 truncate">{e.title[lang]}</div>
          <div className="text-[11.5px] mt-0.5">{st === 'in' ? <span className="text-brand">{L('موجود منذ', 'In since')} <span className="ltr num">{fmtTime(d!.inAt)}</span></span> : st === 'leave' ? <span className="text-brass">{L('في إجازة', 'On leave')}</span> : st === 'out' ? <span className="text-ink-3">{L('غادر', 'Left')}</span> : <span className="text-ink-3">{L('لم يصل بعد', 'Not in yet')}</span>}</div></div>
        <a href={`tel:${e.phone}`} className="text-[11.5px] text-ink-3 ltr hidden sm:block">{e.phone.slice(-7)}</a></div> })}</div></div>
}

function Profile() {
  const L = useL(); const lang = useLang(); const me = useMe()
  const [phone, setPhone] = useState(me.phone)
  return <div className="max-w-[1000px] mx-auto grid lg:grid-cols-[1fr_1.2fr] gap-5">
    <Panel title={L('بياناتي', 'My details')}><div className="flex items-center gap-3 mb-4"><Avatar name={me.name} size={56} /><div><div className="font-display font-bold text-[18px]">{lang === 'ar' ? me.name : me.nameEn}</div><div className="text-[12.5px] text-ink-3">{me.code} · {me.email}</div></div></div>
      <Field label={L('رقم الجوال', 'Mobile')}><div className="flex gap-2"><input className="field ltr text-start" value={phone} onChange={e => setPhone(e.target.value)} /><Btn icon={<Check size={14} />} onClick={() => { updateContact(me.id, phone); toast(L('حُفظ الرقم', 'Saved')) }}>{L('حفظ', 'Save')}</Btn></div></Field>
      <div className="mt-4 text-[13px] space-y-2">{[[L('الرقم الوطني', 'National ID'), me.nationalId], [L('تاريخ المباشرة', 'Start date'), fmtDate(me.hireDate, lang)], [L('مقر العمل', 'Location'), me.city[lang]], [L('بطاقة الحضور', 'Attendance card'), me.nfcUid || '—']].map(([k, v]) => <div key={k} className="flex justify-between border-b border-line-2 pb-1.5"><span className="text-ink-3">{k}</span><b className="ltr">{v}</b></div>)}</div></Panel>
    <Panel title={L('سجلي الوظيفي', 'My career history')}><div className="max-h-[480px] overflow-y-auto"><Timeline items={me.log} compact /></div></Panel>
  </div>
}
