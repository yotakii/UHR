import { useEffect, useRef, useState, type ReactNode } from 'react'
import { LogOut, UserRound, MoreHorizontal, ChevronDown } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { navigate, useRoute } from '../../lib/router'
import { useLogout, type SessionUser } from '../../lib/session'
import { useNotices } from '../../lib/console'
import { Logo, LangToggle, ThemeToggle } from '../../ui/brand'
import { Avatar, toast } from '../../ui/kit'
import { BellMenu } from '../console/Shell'

export interface PortalTab { to: string; icon: ReactNode; label: string; badge?: number }

/**
 * إطار بوابة الموظف M14 (PortalShell في الديمو): هيدر بالشعار والتبويبات على الحاسب، وشريط سفلي بخمسة أزرار على الجوال،
 * وقائمة المستخدم (ملفي الشخصي، بقية الصفحات، الاستقالة، الخروج). محتوى بعرض أقصاه 1180px.
 */
export function PortalShell({ user, tabs, more, children, linkOf }: { user: SessionUser; tabs: PortalTab[]; more: PortalTab[]; children: ReactNode; linkOf: (link: string) => string | null }) {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const logout = useLogout()
  const [menu, setMenu] = useState(false); const [bell, setBell] = useState(false); const [extra, setExtra] = useState(false)
  const path = '/' + route.parts.join('/')
  const active = (to: string) => to === '/' ? path === '/' || path === '/me' : path === to || path.startsWith(to + '/')
  // عند الدخول: «لديك N إشعارات جديدة»، وعند وصول إشعار جديد أثناء الفتح: نصه
  const notices = useNotices().data
  const first = useRef(true); const newest = useRef<number | undefined>(undefined)
  useEffect(() => {
    if (!notices) return
    const top = notices.data[0]
    if (first.current) { first.current = false; newest.current = top?.id; if (notices.unread) window.setTimeout(() => toast(L(`لديك ${notices.unread} إشعارات جديدة`, `You have ${notices.unread} new notifications`)), 600); return }
    if (top && top.id !== newest.current && !top.read) toast(bi(top.text, lang))
    newest.current = top?.id
  }, [notices]) // eslint-disable-line react-hooks/exhaustive-deps
  const close = () => { setMenu(false); setBell(false); setExtra(false) }
  const go = (to: string) => { close(); navigate(to) }
  const moreActive = more.some(t => active(t.to))
  const moreBadge = more.reduce((a, t) => a + (t.badge ?? 0), 0)
  return <div className="min-h-full app-glow flex flex-col">
    <header className="sticky top-0 z-30 border-b border-line bg-canvas/80 backdrop-blur" data-noprint>
      <div className="max-w-[1180px] mx-auto px-4 md:px-6 h-16 flex items-center gap-3">
        <a href="/" className="shrink-0"><Logo h={26} /></a>
        <span className="hidden 2xl:inline whitespace-nowrap text-[12px] text-ink-3 border-s border-line ps-3">{L('بوابة الموظف', 'Employee portal')}</span>
        <nav className="hidden lg:flex items-center gap-0.5 mx-auto min-w-0">
          {tabs.map(t => <a key={t.to} href={t.to} title={t.label} className={`relative h-9 px-2.5 rounded-lg text-[13.5px] flex items-center gap-1.5 whitespace-nowrap ${active(t.to) ? 'bg-brand-soft text-brand font-semibold' : 'text-ink-2 hover:bg-sub'}`}>
            {t.icon}<span className="hidden xl:inline">{t.label}</span>{!!t.badge && <span className="min-w-4 h-4 px-1 rounded-full bg-brand text-on-brand text-[10px] grid place-items-center num">{t.badge}</span>}</a>)}
          <div className="relative">
            <button onClick={() => { setExtra(!extra); setMenu(false); setBell(false) }} className={`h-9 px-2.5 rounded-lg text-[13.5px] flex items-center gap-1.5 ${moreActive ? 'bg-brand-soft text-brand font-semibold' : 'text-ink-2 hover:bg-sub'}`} aria-expanded={extra}>
              <MoreHorizontal size={17} /><span className="hidden xl:inline">{L('المزيد', 'More')}</span>{!!moreBadge && <span className="min-w-4 h-4 px-1 rounded-full bg-brand text-on-brand text-[10px] grid place-items-center num">{moreBadge}</span>}<ChevronDown size={14} /></button>
            {extra && <div className="absolute end-0 top-11 w-60 bg-surface border border-line rounded-xl p-1 shadow-2xl z-40 fadeup">{more.map(t => <MenuItem key={t.to} t={t} on={active(t.to)} go={go} />)}</div>}
          </div>
        </nav>
        <div className="flex items-center gap-2 ms-auto lg:ms-0">
          <span className="hidden sm:flex"><LangToggle /></span><span className="hidden sm:flex"><ThemeToggle /></span>
          <BellMenu open={bell} setOpen={v => { setBell(v); setMenu(false); setExtra(false) }} linkOf={linkOf} />
          <div className="relative">
            <button onClick={() => { setMenu(!menu); setBell(false); setExtra(false) }} className="flex items-center gap-2 h-10 ps-1 pe-2 rounded-lg hover:bg-sub" aria-haspopup="menu" aria-expanded={menu}>
              <Avatar name={user.name.ar} size={32} />
              <span className="hidden xl:block text-start leading-tight whitespace-nowrap"><span className="block text-[13px] font-semibold">{bi(user.name, lang)}</span><span className="block text-[11px] text-ink-3">{user.employee?.code}</span></span></button>
            {menu && <div className="absolute end-0 top-12 w-64 max-h-[75vh] overflow-y-auto bg-surface border border-line rounded-xl p-1 shadow-2xl z-40 fadeup">
              <MenuItem t={{ to: '/me/profile', icon: <UserRound size={15} />, label: L('ملفي الشخصي', 'My profile') }} on={active('/me/profile')} go={go} />
              <div className="lg:hidden border-t border-line-2 my-1 pt-1">{more.filter(t => t.to !== '/me/profile').map(t => <MenuItem key={t.to} t={t} on={active(t.to)} go={go} />)}
                <div className="sm:hidden flex items-center gap-2 px-3 py-2"><LangToggle /><ThemeToggle /></div></div>
              <button onClick={() => logout.mutate()} className="w-full flex items-center gap-2 px-3 h-9 rounded-lg hover:bg-sub text-[13px] text-danger"><LogOut size={15} />{L('تسجيل الخروج', 'Sign out')}</button>
            </div>}
          </div>
        </div>
      </div>
    </header>
    <main className="flex-1 max-w-[1180px] w-full mx-auto px-4 md:px-6 py-6 pb-24 lg:pb-8" onClick={close}>{children}</main>
    <nav className="lg:hidden fixed bottom-0 inset-x-0 z-30 bg-surface/95 backdrop-blur border-t border-line grid grid-cols-5" data-noprint>
      {tabs.slice(0, 5).map(t => <a key={t.to} href={t.to} onClick={close} className={`relative h-16 flex flex-col items-center justify-center gap-1 text-[11px] ${active(t.to) ? 'text-brand font-semibold' : 'text-ink-3'}`}>
        {t.icon}<span className="truncate max-w-full px-1">{t.label}</span>{!!t.badge && <span className="absolute top-2 start-1/2 ms-2 min-w-4 h-4 px-1 rounded-full bg-brand text-on-brand text-[10px] grid place-items-center num">{t.badge}</span>}</a>)}
    </nav>
  </div>
}

function MenuItem({ t, on, go }: { t: PortalTab; on: boolean; go: (to: string) => void }) {
  return <button onClick={() => go(t.to)} className={`w-full flex items-center gap-2 px-3 h-9 rounded-lg text-[13px] ${on ? 'bg-brand-soft text-brand font-semibold' : 'hover:bg-sub'}`}>
    <span className="[&_svg]:size-[15px]">{t.icon}</span><span className="flex-1 text-start truncate">{t.label}</span>{!!t.badge && <span className="min-w-4 h-4 px-1 rounded-full bg-brand text-on-brand text-[10px] grid place-items-center num">{t.badge}</span>}</button>
}
