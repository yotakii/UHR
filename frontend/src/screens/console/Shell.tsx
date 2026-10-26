import { useEffect, useRef, useState, type ReactNode } from 'react'
import { LogOut, Menu, Building2, UserRound, ExternalLink, Bell, Search, Users, Kanban, Briefcase } from 'lucide-react'
import { useL, useLang, bi, fmtDateTime } from '../../lib/store'
import { navigate, useRoute } from '../../lib/router'
import { useLogout, type SessionUser } from '../../lib/session'
import { useMarkNoticesRead, useNotices, useSearch } from '../../lib/console'
import { Logo, LangToggle, ThemeToggle } from '../../ui/brand'

/**
 * إطار لوحات الإدارة (M15 — منقول من Shell.tsx في الديمو بدون أدوات المعرض):
 * سايدبار 268px بمجموعاته وعداداته وبطاقة المستخدم، وهيدر 68px بعنوان الصفحة. يستخدمه نظام الشركة ولوحة المنصة.
 */
export type NavItem = { to: string; icon: ReactNode; label: string; count?: number; group?: string }

/** linkOf: يحوّل رابط الإشعار (cand:12، leaves…) لمسار — ويفعّل الجرس. search: البحث الشامل في بيانات الشركة (HR) */
export function Shell({ items, user, org, sub, children, linkOf, search }: { items: NavItem[]; user: SessionUser; org: ReactNode; sub: string; children: ReactNode; linkOf?: (link: string) => string | null; search?: SearchTargets }) {
  const L = useL(); const lang = useLang()
  const logout = useLogout()
  const route = useRoute()
  const path = '/' + route.parts.join('/')
  const [menu, setMenu] = useState(false); const [mobile, setMobile] = useState(false); const [bell, setBell] = useState(false); const [palette, setPalette] = useState(false)
  useEffect(() => {
    const k = (e: KeyboardEvent) => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); setPalette(true) } }
    window.addEventListener('keydown', k); return () => window.removeEventListener('keydown', k)
  }, [])
  const isActive = (to: string) => path === to || path.startsWith(to + '/')
  const current = [...items].sort((a, b) => b.to.length - a.to.length).find(i => isActive(i.to)) ?? items[0]
  let lastGroup: string | undefined
  return <div className="h-full flex">
    <aside className={`${mobile ? 'fixed inset-y-0 start-0 z-40 flex' : 'hidden'} lg:flex w-[268px] shrink-0 bg-side text-side-fg flex-col border-e border-white/5`} data-noprint>
      <div className="px-5 pt-5 pb-4"><Logo h={34} onDark /></div>
      <div className="mx-4 flex items-center gap-1.5 border-y border-white/10 py-2.5 text-[12px] text-side-fg/75"><Building2 size={14} className="shrink-0" /><span className="truncate">{org}</span></div>
      <nav className="flex-1 overflow-y-auto px-3 py-3">
        {items.map(it => {
          const head = it.group !== lastGroup ? <div className="px-2 pt-3 pb-1.5 text-[11px] text-side-fg/45">{it.group ?? L('وحدات النظام', 'Platform modules')}</div> : null
          lastGroup = it.group
          const active = it === current
          return <div key={it.to}>{head}
            <a href={it.to} onClick={() => setMobile(false)} className={`group w-full flex items-center gap-3 h-11 px-2 rounded-xl text-[13.5px] mb-1 border transition-colors ${active ? 'bg-side-accent border-brand/50 text-side-fg font-semibold shadow-[inset_3px_0_0_var(--primary)] rtl:shadow-[inset_-3px_0_0_var(--primary)]' : 'border-transparent text-side-fg/80 hover:bg-white/5 hover:text-side-fg'}`}>
              <span className={`size-8 rounded-lg grid place-items-center shrink-0 border ${active ? 'bg-brand/15 border-brand/40 text-brand' : 'bg-white/5 border-white/10 group-hover:text-brand'}`}>{it.icon}</span>
              <span className="flex-1 text-start truncate">{it.label}</span>
              {!!it.count && <span className="min-w-5 h-5 px-1.5 rounded-full text-[11px] font-bold grid place-items-center num bg-brand text-on-brand">{it.count}</span>}
            </a></div>
        })}
      </nav>
      <div className="p-3 space-y-2 border-t border-white/10">
        <a href="/jobs" className="w-full h-10 rounded-xl border border-white/15 font-semibold flex items-center justify-center gap-2 hover:bg-white/5 text-[13px]"><ExternalLink size={15} />{L('بوابة فرص العمل', 'Jobs portal')}</a>
        <div className="relative">
          {menu && <div className="absolute bottom-full mb-2 inset-x-0 bg-surface text-ink border border-line rounded-xl p-1 z-50 shadow-xl fadeup">
            <button onClick={() => logout.mutate()} className="w-full flex items-center gap-2 px-3 h-9 rounded-lg hover:bg-sub text-[13px] text-danger"><LogOut size={15} />{L('تسجيل الخروج', 'Sign out')}</button>
          </div>}
          <button onClick={() => setMenu(!menu)} className="w-full flex items-center gap-3 rounded-xl bg-white/5 p-2.5 text-start hover:bg-white/10" aria-haspopup="menu">
            <span className="size-9 rounded-full grid place-items-center bg-info text-on-brand"><UserRound size={18} /></span>
            <span className="flex-1 min-w-0"><span className="block text-[13px] font-semibold truncate">{bi(user.name, lang)}</span><span className="block text-[11px] text-side-fg/55 truncate ltr text-start">{user.email}</span></span>
          </button>
        </div>
      </div>
    </aside>
    {mobile && <div className="fixed inset-0 z-30 bg-black/45 lg:hidden" onClick={() => setMobile(false)} />}

    <div className="flex-1 min-w-0 flex flex-col app-glow">
      <header className="relative z-20 h-[68px] shrink-0 border-b border-line bg-canvas/70 backdrop-blur flex items-center gap-4 px-4 md:px-6" data-noprint>
        <button className="lg:hidden p-2 -ms-2" onClick={() => setMobile(true)} aria-label={L('القائمة', 'Menu')}><Menu size={20} /></button>
        <div className="min-w-0"><div className="font-display font-bold text-[17px] leading-tight truncate">{current?.label}</div><div className="text-[12px] text-ink-3 truncate">{sub}</div></div>
        <button onClick={() => setPalette(true)} className="hidden md:flex flex-1 max-w-[440px] mx-auto h-10 items-center gap-2 rounded-xl border border-line bg-surface px-3 text-ink-3 text-[13px] hover:border-brand/50">
          <Search size={16} /><span className="flex-1 text-start">{L('ابحث في النظام…', 'Search the platform…')}</span><kbd className="text-[11px] border border-line rounded px-1.5 ltr">Ctrl K</kbd></button>
        <div className="flex items-center gap-2 ms-auto md:ms-0">
          <button onClick={() => setPalette(true)} className="md:hidden h-9 w-9 grid place-items-center rounded-lg border border-line bg-surface hover:bg-sub" aria-label={L('بحث', 'Search')}><Search size={17} /></button>
          <LangToggle /><span className="hidden sm:inline-flex"><ThemeToggle /></span>
          {linkOf && <BellMenu open={bell} setOpen={v => { setBell(v); setMenu(false) }} linkOf={linkOf} />}
        </div>
      </header>
      <main id="main-scroll" className="flex-1 overflow-y-auto p-4 md:p-6" onClick={() => { setMenu(false); setBell(false) }}>{children}</main>
    </div>
    {palette && <Palette items={items} search={search} onClose={() => setPalette(false)} />}
  </div>
}

/** الجرس: عداد غير المقروء، آخر 12، النقر يفتح الرابط، وتُعلَّم مقروءة بعد 1.5 ثانية من الفتح */
export function BellMenu({ open, setOpen, linkOf }: { open: boolean; setOpen: (v: boolean) => void; linkOf: (link: string) => string | null }) {
  const L = useL(); const lang = useLang()
  const { data } = useNotices()
  const mark = useMarkNoticesRead()
  const timer = useRef<number | undefined>(undefined)
  useEffect(() => () => window.clearTimeout(timer.current), [])
  const toggle = () => {
    setOpen(!open)
    window.clearTimeout(timer.current)
    if (!open && data?.unread) timer.current = window.setTimeout(() => mark.mutate(), 1500)
  }
  const pick = (link: string | null) => { const to = link ? linkOf(link) : null; setOpen(false); if (to) navigate(to) }
  return <div className="relative">
    <button onClick={toggle} className="relative h-9 w-9 grid place-items-center rounded-lg border border-line bg-surface hover:bg-sub" aria-label={L('الإشعارات', 'Notifications')} aria-expanded={open}>
      <Bell size={17} />{!!data?.unread && <span className="absolute -top-1 -end-1 min-w-4 h-4 px-1 rounded-full bg-danger text-on-brand text-[10px] font-bold grid place-items-center num">{data.unread}</span>}
    </button>
    {open && <div className="absolute end-0 top-11 w-[370px] max-w-[calc(100vw-2rem)] bg-surface border border-line rounded-xl z-40 overflow-hidden shadow-2xl fadeup">
      <div className="px-4 h-11 flex items-center border-b border-line font-semibold text-[14px]">{L('الإشعارات', 'Notifications')}</div>
      <div className="max-h-[380px] overflow-y-auto divide-y divide-line-2">
        {!data?.data.length && <div className="p-4 text-[13px] text-ink-3">{L('لا توجد إشعارات', 'No notifications')}</div>}
        {data?.data.map(n => <button key={n.id} onClick={() => pick(n.link)} className="w-full text-start px-4 py-2.5 hover:bg-sub flex gap-2">
          {!n.read && <span className="size-2 rounded-full bg-brand mt-2 shrink-0" />}
          <div><div className="text-[13px]">{bi(n.text, lang)}</div><div className="text-[11px] text-ink-3 num">{fmtDateTime(n.at, lang)}</div></div></button>)}
      </div>
    </div>}
  </div>
}

/** مسارات نتائج البحث الشامل — تُمرَّر من التطبيق (null = بلا بحث في البيانات) */
export interface SearchTargets { employee: (id: number) => string; candidate: (id: number) => string; job: (id: number) => string }

/** البحث الشامل (Ctrl K): الصفحات ثم الموظفون (6) ثم المتقدمون (5) ثم الشواغر — بالأسهم وEnter وEsc */
function Palette({ items, search, onClose }: { items: NavItem[]; search?: SearchTargets; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const [q, setQ] = useState(''); const [i, setI] = useState(0)
  const [debounced, setDebounced] = useState('')
  useEffect(() => { const t = window.setTimeout(() => setDebounced(q.trim()), 200); return () => window.clearTimeout(t) }, [q])
  const { data, isFetching } = useSearch(debounced, !!search)
  const t = q.trim().toLowerCase()
  type R = { k: string; label: string; sub: string; icon: ReactNode; to: string }
  const res: R[] = [
    ...items.filter(it => !t || it.label.toLowerCase().includes(t)).map(it => ({ k: 'p' + it.to, label: it.label, sub: L('صفحة', 'Page'), icon: it.icon, to: it.to })),
    ...(search && data && debounced === q.trim() ? [
      ...data.employees.map(e => ({ k: 'e' + e.id, label: bi(e.name, lang), sub: `${e.code} · ${bi(e.title, lang)}`, icon: <Users size={16} />, to: search.employee(e.id) })),
      ...data.candidates.map(c => ({ k: 'c' + c.id, label: bi(c.name, lang), sub: `${L('متقدم', 'Applicant')} · ${c.ref} · ${bi(c.job, lang)}`, icon: <Kanban size={16} />, to: search.candidate(c.id) })),
      ...data.jobs.map(j => ({ k: 'j' + j.id, label: bi(j.title, lang), sub: `${L('شاغر', 'Opening')} · ${j.code}`, icon: <Briefcase size={16} />, to: search.job(j.id) })),
    ] : []),
  ]
  const pick = (r?: R) => { if (!r) return; onClose(); navigate(r.to) }
  return <div className="fixed inset-0 z-[70] bg-black/55 grid place-items-start justify-center pt-[12vh] px-4" onMouseDown={onClose} data-noprint>
    <div className="w-full max-w-[560px] bg-surface border border-line rounded-2xl shadow-2xl overflow-hidden fadeup" onMouseDown={e => e.stopPropagation()} role="dialog" aria-label={L('البحث في النظام', 'Search the platform')}>
      <div className="flex items-center gap-3 px-4 h-14 border-b border-line"><Search size={18} className="text-ink-3" />
        <input autoFocus value={q} onChange={e => { setQ(e.target.value); setI(0) }} placeholder={search ? L('اسم موظف، رقم وظيفي، متقدم، أو صفحة…', 'Employee, ID, applicant or page…') : L('اسم صفحة…', 'Page name…')} className="flex-1 bg-transparent outline-none text-[15px]"
          onKeyDown={e => { if (e.key === 'ArrowDown') { e.preventDefault(); setI(Math.min(res.length - 1, i + 1)) } if (e.key === 'ArrowUp') { e.preventDefault(); setI(Math.max(0, i - 1)) } if (e.key === 'Enter') pick(res[i]); if (e.key === 'Escape') onClose() }} /></div>
      <div className="max-h-[50vh] overflow-y-auto p-2">
        {res.length === 0 && <div className="p-6 text-center text-ink-3 text-[13px]">{isFetching ? L('جارٍ البحث…', 'Searching…') : L('لا نتائج مطابقة', 'No matches')}</div>}
        {res.map((r, k) => <button key={r.k} onMouseEnter={() => setI(k)} onClick={() => pick(r)} className={`w-full flex items-center gap-3 px-3 h-12 rounded-lg text-start ${k === i ? 'bg-brand-soft' : ''}`}>
          <span className="size-8 rounded-lg grid place-items-center bg-sub text-brand shrink-0">{r.icon}</span><span className="flex-1 min-w-0"><span className="block text-[14px] font-medium truncate">{r.label}</span><span className="block text-[12px] text-ink-3 truncate">{r.sub}</span></span></button>)}
      </div>
    </div>
  </div>
}
