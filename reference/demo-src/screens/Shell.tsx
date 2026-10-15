import { useState, useEffect, type ReactNode } from 'react'
import { LayoutGrid, Briefcase, Users, UserPlus, Clock, CalendarCheck, Package, LogOut, Bell, RotateCcw, Kanban, DoorOpen, Settings2, Home, FileSignature, Menu, Building2, Maximize, Play, Search, Trophy, Network, Target, BarChart3, UserRound } from 'lucide-react'
import { useStore, useL, useLang, fmtDateTime, resetDemo } from '../lib/store'
import { logout, markNoticesRead } from '../lib/actions'
import { useNav } from '../lib/nav'
import { Logo, LangToggle, ThemeToggle } from '../ui/brand'
import { setUI, useUI, toggleFullscreen } from '../lib/ui'
import { Btn, Modal } from '../ui/kit'

export type NavItem = { id: string; icon: ReactNode; label: string; count?: number; group?: string }

export function Shell({ items, children, user, role }: { items: NavItem[]; children: ReactNode; user: { name: string; title: string }; role: 'hr' | 'employee' }) {
  const L = useL(); const lang = useLang(); const { route, go } = useNav()
  const notices = useStore(s => s.notices.filter(n => role === 'hr' ? n.to === 'hr' : n.to === s.session?.id).slice(0, 12))
  const unread = notices.filter(n => !n.read).length
  const [bell, setBell] = useState(false); const [menu, setMenu] = useState(false); const [reset, setReset] = useState(false); const [mobile, setMobile] = useState(false)
  const base = route.page.split(':')[0]
  const isActive = (id: string) => base === id || (id === 'recruitment' && base === 'candidate') || (id === 'employees' && base === 'employee') || (id === 'onboarding' && base === 'onboard') || (id === 'attendance' && base === 'kiosk-sim')
  const current = items.find(i => isActive(i.id)) ?? items[0]
  let lastGroup: string | undefined
  const open = (link?: string) => {
    if (!link) return; setBell(false)
    if (link.startsWith('cand:')) go('candidate', link.slice(5)); else if (link.startsWith('emp:')) go('employee', link.slice(4)); else go(link)
  }
  useEffect(() => { const k = (e: KeyboardEvent) => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); setUI({ palette: true }) } }; window.addEventListener('keydown', k); return () => window.removeEventListener('keydown', k) }, [])
  return <div className="h-full flex">
    <aside className={`${mobile ? 'fixed inset-y-0 start-0 z-40 flex' : 'hidden'} lg:flex w-[268px] shrink-0 bg-side text-side-fg flex-col border-e border-white/5`} data-noprint>
      <div className="px-5 pt-5 pb-4"><Logo h={34} onDark /></div>
      <div className="mx-4 flex items-center justify-between border-y border-white/10 py-2.5 text-[12px]">
        <span className="flex items-center gap-1.5 text-side-fg/75"><Building2 size={14} />{L('مجموعة الفرادة', 'Uniqueness Group')}</span>
        <span className="flex items-center gap-1.5 text-brand font-semibold"><span className="size-1.5 rounded-full bg-brand live-dot" />{L('عرض مباشر', 'Live demo')}</span>
      </div>
      <nav className="flex-1 overflow-y-auto px-3 py-3">
        {items.map(it => {
          const head = it.group !== lastGroup ? <div className="px-2 pt-3 pb-1.5 text-[11px] text-side-fg/45">{it.group ?? L('وحدات النظام', 'Platform modules')}</div> : null
          lastGroup = it.group
          const active = isActive(it.id)
          return <div key={it.id}>{head}
            <button onClick={() => { go(it.id); setMobile(false) }} className={`group w-full flex items-center gap-3 h-11 px-2 rounded-xl text-[13.5px] mb-1 border transition-colors ${active ? 'bg-side-accent border-brand/50 text-side-fg font-semibold shadow-[inset_3px_0_0_var(--primary)] rtl:shadow-[inset_-3px_0_0_var(--primary)]' : 'border-transparent text-side-fg/80 hover:bg-white/5 hover:text-side-fg'}`}>
              <span className={`size-8 rounded-lg grid place-items-center shrink-0 border ${active ? 'bg-brand/15 border-brand/40 text-brand' : 'bg-white/5 border-white/10 group-hover:text-brand'}`}>{it.icon}</span>
              <span className="flex-1 text-start truncate">{it.label}</span>
              {!!it.count && <span className="min-w-5 h-5 px-1.5 rounded-full text-[11px] font-bold grid place-items-center num bg-brand text-on-brand">{it.count}</span>}
            </button></div>
        })}
      </nav>
      <div className="p-3 space-y-2 border-t border-white/10">
        <button onClick={toggleFullscreen} className="w-full h-10 rounded-xl bg-brand text-on-brand font-semibold flex items-center justify-center gap-2 hover:brightness-110"><Maximize size={16} />{L('وضع المعرض', 'Expo mode')}</button>
        <button onClick={() => setUI({ showcase: true })} className="w-full h-10 rounded-xl border border-white/15 font-semibold flex items-center justify-center gap-2 hover:bg-white/5"><Play size={15} />{L('عرض تلقائي', 'Auto showcase')}</button>
        <div className="relative">
          {menu && <div className="absolute bottom-full mb-2 inset-x-0 bg-surface text-ink border border-line rounded-xl p-1 z-50 shadow-xl fadeup">
            {role === 'hr' && <button onClick={() => { setMenu(false); setReset(true) }} className="w-full flex items-center gap-2 px-3 h-9 rounded-lg hover:bg-sub text-[13px]"><RotateCcw size={15} />{L('إعادة ضبط بيانات العرض', 'Reset demo data')}</button>}
            <button onClick={logout} className="w-full flex items-center gap-2 px-3 h-9 rounded-lg hover:bg-sub text-[13px] text-danger"><LogOut size={15} />{L('تسجيل الخروج', 'Sign out')}</button>
          </div>}
          <button onClick={() => setMenu(!menu)} className="w-full flex items-center gap-3 rounded-xl bg-white/5 p-2.5 text-start hover:bg-white/10">
            <span className="size-9 rounded-full grid place-items-center bg-info text-on-brand"><UserRound size={18} /></span>
            <span className="flex-1 min-w-0"><span className="block text-[13px] font-semibold truncate">{user.name}</span><span className="block text-[11px] text-side-fg/55 truncate">{user.title}</span></span>
          </button>
        </div>
      </div>
    </aside>
    {mobile && <div className="fixed inset-0 z-30 bg-black/45 lg:hidden" onClick={() => setMobile(false)} />}

    <div className="flex-1 min-w-0 flex flex-col app-glow">
      <header className="relative z-40 h-[68px] shrink-0 border-b border-line bg-canvas/70 backdrop-blur flex items-center gap-4 px-4 md:px-6" data-noprint>
        <button className="lg:hidden p-2 -ms-2" onClick={() => setMobile(true)} aria-label="menu"><Menu size={20} /></button>
        <div className="min-w-0"><div className="font-display font-bold text-[17px] leading-tight truncate">{current?.label}</div><div className="text-[12px] text-ink-3 truncate">{role === 'hr' ? L('مركز عمليات الموارد البشرية', 'People Operations Center') : L('الخدمة الذاتية للموظف', 'Employee self-service')}</div></div>
        <button onClick={() => setUI({ palette: true })} className="hidden md:flex flex-1 max-w-[440px] mx-auto h-10 items-center gap-2 rounded-xl border border-line bg-surface px-3 text-ink-3 text-[13px] hover:border-brand/50">
          <Search size={16} /><span className="flex-1 text-start">{L('ابحث في النظام…', 'Search the platform…')}</span><kbd className="text-[11px] border border-line rounded px-1.5 ltr">Ctrl K</kbd></button>
        <div className="flex items-center gap-2 ms-auto md:ms-0">
          <LangToggle /><span className="hidden sm:inline-flex"><ThemeToggle /></span>
          <div className="relative">
            <button onClick={() => { setBell(!bell); setMenu(false); if (!bell) setTimeout(markNoticesRead, 1500) }} className="relative h-9 w-9 grid place-items-center rounded-lg border border-line bg-surface hover:bg-sub" aria-label="notifications">
              <Bell size={17} />{unread > 0 && <span className="absolute -top-1 -end-1 min-w-4 h-4 px-1 rounded-full bg-danger text-on-brand text-[10px] font-bold grid place-items-center num">{unread}</span>}
            </button>
            {bell && <div className="absolute end-0 top-11 w-[370px] max-w-[90vw] bg-surface border border-line rounded-xl z-40 overflow-hidden shadow-2xl fadeup">
              <div className="px-4 h-11 flex items-center border-b border-line font-semibold text-[14px]">{L('الإشعارات', 'Notifications')}</div>
              <div className="max-h-[380px] overflow-y-auto divide-y divide-line-2">
                {notices.length === 0 && <div className="p-4 text-[13px] text-ink-3">{L('لا توجد إشعارات', 'No notifications')}</div>}
                {notices.map(n => <button key={n.id} onClick={() => open(n.link)} className="w-full text-start px-4 py-2.5 hover:bg-sub flex gap-2">
                  {!n.read && <span className="size-2 rounded-full bg-brand mt-2 shrink-0" />}
                  <div><div className="text-[13px]">{n[lang]}</div><div className="text-[11px] text-ink-3 num">{fmtDateTime(n.at, lang)}</div></div></button>)}
              </div>
            </div>}
          </div>
        </div>
      </header>
      <main id="main-scroll" className="flex-1 overflow-y-auto p-4 md:p-6" onClick={() => { setBell(false); setMenu(false) }}>{children}</main>
    </div>
    <Palette items={items} />
    <Modal open={reset} onClose={() => setReset(false)} title={L('إعادة ضبط بيانات العرض', 'Reset demo data')}
      footer={<><Btn onClick={() => setReset(false)}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="danger" onClick={() => { resetDemo() }}>{L('إعادة الضبط', 'Reset')}</Btn></>}>
      <p className="text-[14px] text-ink-2">{L('سيتم حذف كل الطلبات والحسابات التي أُنشئت أثناء العرض وإرجاع البيانات التجريبية الأصلية. لا يمكن التراجع عن هذه العملية.', 'All applications and accounts created during the demo will be removed and sample data restored. This cannot be undone.')}</p>
    </Modal>
  </div>
}

function Palette({ items }: { items: NavItem[] }) {
  const L = useL(); const lang = useLang(); const { go } = useNav(); const { palette } = useUI()
  const role = useStore(s => s.session?.role)
  const emps = useStore(s => s.employees); const cands = useStore(s => s.candidates)
  const [q, setQ] = useState(''); const [i, setI] = useState(0)
  useEffect(() => { if (palette) { setQ(''); setI(0) } }, [palette])
  if (!palette) return null
  const t = q.trim().toLowerCase()
  type R = { k: string; label: string; sub: string; icon: ReactNode; run: () => void }
  const res: R[] = [
    ...items.filter(it => !t || it.label.toLowerCase().includes(t)).map(it => ({ k: 'p' + it.id, label: it.label, sub: L('صفحة', 'Page'), icon: it.icon, run: () => go(it.id) })),
    ...(role === 'hr' && t ? emps.filter(e => e.name.includes(q) || e.nameEn.toLowerCase().includes(t) || e.code.toLowerCase().includes(t)).slice(0, 6).map(e => ({ k: 'e' + e.id, label: lang === 'ar' ? e.name : e.nameEn, sub: `${e.code} · ${e.title[lang]}`, icon: <Users size={16} />, run: () => go('employee', e.id) })) : []),
    ...(role === 'hr' && t ? cands.filter(c => c.name.includes(q) || c.ref.toLowerCase().includes(t)).slice(0, 5).map(c => ({ k: 'c' + c.id, label: c.name, sub: `${L('متقدم', 'Applicant')} · ${c.ref}`, icon: <Kanban size={16} />, run: () => go('candidate', c.id) })) : []),
  ]
  const pick = (r?: R) => { if (!r) return; r.run(); setUI({ palette: false }) }
  return <div className="fixed inset-0 z-[70] bg-black/55 grid place-items-start justify-center pt-[12vh] px-4" onMouseDown={() => setUI({ palette: false })} data-noprint>
    <div className="w-full max-w-[560px] bg-surface border border-line rounded-2xl shadow-2xl overflow-hidden fadeup" onMouseDown={e => e.stopPropagation()}>
      <div className="flex items-center gap-3 px-4 h-14 border-b border-line"><Search size={18} className="text-ink-3" />
        <input autoFocus value={q} onChange={e => { setQ(e.target.value); setI(0) }} placeholder={L('اسم موظف، رقم وظيفي، متقدم، أو صفحة…', 'Employee, ID, applicant or page…')} className="flex-1 bg-transparent outline-none text-[15px]"
          onKeyDown={e => { if (e.key === 'ArrowDown') { e.preventDefault(); setI(Math.min(res.length - 1, i + 1)) } if (e.key === 'ArrowUp') { e.preventDefault(); setI(Math.max(0, i - 1)) } if (e.key === 'Enter') pick(res[i]); if (e.key === 'Escape') setUI({ palette: false }) }} /></div>
      <div className="max-h-[50vh] overflow-y-auto p-2">
        {res.length === 0 && <div className="p-6 text-center text-ink-3 text-[13px]">{L('لا نتائج مطابقة', 'No matches')}</div>}
        {res.map((r, k) => <button key={r.k} onMouseEnter={() => setI(k)} onClick={() => pick(r)} className={`w-full flex items-center gap-3 px-3 h-12 rounded-lg text-start ${k === i ? 'bg-brand-soft' : ''}`}>
          <span className="size-8 rounded-lg grid place-items-center bg-sub text-brand">{r.icon}</span><span className="flex-1 min-w-0"><span className="block text-[14px] font-medium truncate">{r.label}</span><span className="block text-[12px] text-ink-3 truncate">{r.sub}</span></span></button>)}
      </div>
    </div>
  </div>
}

export function useHrNav(): NavItem[] {
  const L = useL()
  const c = useStore(s => ({
    newApps: s.candidates.filter(x => x.stage === 'new').length,
    onboard: s.employees.filter(e => e.status === 'onboarding').length + s.candidates.filter(x => x.appointment && x.stage !== 'hired').length,
    leaves: s.leaves.filter(l => l.status === 'pending_hr' || l.status === 'pending_manager').length + (s.services ?? []).filter(x => x.status === 'pending').length,
    off: s.resignations.filter(r => r.status !== 'closed' && r.status !== 'rejected').length,
    red: s.redemptions.filter(r => r.status === 'pending').length,
  }))
  const g1 = L('التوظيف والتعيين', 'Hiring'), g2 = L('شؤون الموظفين', 'People'), g3 = L('الإعدادات', 'Settings')
  return [
    { id: 'dashboard', icon: <LayoutGrid size={18} />, label: L('لوحة المتابعة', 'Overview') },
    { id: 'jobs', icon: <Briefcase size={18} />, label: L('الوظائف الشاغرة', 'Job openings'), group: g1 },
    { id: 'recruitment', icon: <Kanban size={18} />, label: L('المتقدمون', 'Applicants'), count: c.newApps, group: g1 },
    { id: 'onboarding', icon: <UserPlus size={18} />, label: L('التعيين والمباشرة', 'Appointment & onboarding'), count: c.onboard, group: g1 },
    { id: 'rewards', icon: <Trophy size={17} />, label: L('الإحالات والمكافآت', 'Referrals & rewards'), count: c.red, group: g1 },
    { id: 'employees', icon: <Users size={18} />, label: L('الموظفون', 'Employees'), group: g2 },
    { id: 'org', icon: <Network size={17} />, label: L('الهيكل التنظيمي', 'Org chart'), group: g2 },
    { id: 'attendance', icon: <Clock size={18} />, label: L('الحضور والدوام', 'Attendance'), group: g2 },
    { id: 'leaves', icon: <CalendarCheck size={18} />, label: L('الإجازات والتبريرات', 'Leave & justifications'), count: c.leaves, group: g2 },
    { id: 'custody', icon: <Package size={18} />, label: L('العهد', 'Custody'), group: g2 },
    { id: 'offboarding', icon: <DoorOpen size={18} />, label: L('الاستقالات وإنهاء الخدمة', 'Resignations & exits'), count: c.off, group: g2 },
    { id: 'performance', icon: <Target size={17} />, label: L('الأداء وتقييم 360', 'Performance & 360'), group: L('الرؤى', 'Insights') },
    { id: 'analytics', icon: <BarChart3 size={17} />, label: L('التحليلات', 'Analytics'), group: L('الرؤى', 'Insights') },
    { id: 'policies', icon: <Settings2 size={18} />, label: L('سياسات الدوام والإجازات', 'Work & leave policies'), group: g3 },
  ]
}

export function useEmpNav(empId: string): NavItem[] {
  const L = useL()
  const c = useStore(s => ({
    sign: s.assets.filter(a => a.empId === empId && a.status === 'pending_ack').length + (() => { const e = s.employees.find(x => x.id === empId); return e?.onboarding ? Number(!e.onboarding.f22) + Number(!e.onboarding.f20) : 0 })(),
  }))
  return [
    { id: 'home', icon: <Home size={18} />, label: L('ملفي الوظيفي', 'My file') },
    { id: 'my-attendance', icon: <Clock size={18} />, label: L('حضوري', 'My attendance') },
    { id: 'my-requests', icon: <CalendarCheck size={18} />, label: L('طلباتي', 'My requests') },
    { id: 'my-rewards', icon: <Trophy size={17} />, label: L('الإحالات والمكافآت', 'Referrals & rewards') },
    { id: 'my-docs', icon: <FileSignature size={18} />, label: L('وثائق للتوقيع', 'Documents to sign'), count: c.sign },
    { id: 'my-resign', icon: <DoorOpen size={18} />, label: L('الاستقالة', 'Resignation') },
  ]
}
