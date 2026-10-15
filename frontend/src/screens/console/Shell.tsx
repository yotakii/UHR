import { useState, type ReactNode } from 'react'
import { LogOut, Menu, Building2, UserRound, ExternalLink } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { useRoute } from '../../lib/router'
import { useLogout, type SessionUser } from '../../lib/session'
import { Logo, LangToggle, ThemeToggle } from '../../ui/brand'

/**
 * إطار لوحات الإدارة (M15 — منقول من Shell.tsx في الديمو بدون أدوات المعرض):
 * سايدبار 268px بمجموعاته وعداداته وبطاقة المستخدم، وهيدر 68px بعنوان الصفحة. يستخدمه نظام الشركة ولوحة المنصة.
 */
export type NavItem = { to: string; icon: ReactNode; label: string; count?: number; group?: string }

export function Shell({ items, user, org, sub, children }: { items: NavItem[]; user: SessionUser; org: ReactNode; sub: string; children: ReactNode }) {
  const L = useL(); const lang = useLang()
  const logout = useLogout()
  const route = useRoute()
  const path = '/' + route.parts.join('/')
  const [menu, setMenu] = useState(false); const [mobile, setMobile] = useState(false)
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
        <div className="flex items-center gap-2 ms-auto"><LangToggle /><span className="hidden sm:inline-flex"><ThemeToggle /></span></div>
      </header>
      <main id="main-scroll" className="flex-1 overflow-y-auto p-4 md:p-6" onClick={() => setMenu(false)}>{children}</main>
    </div>
  </div>
}
