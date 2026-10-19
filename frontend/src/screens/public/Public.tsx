import { LayoutDashboard, LogIn, FileSearch } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { useRoute } from '../../lib/router'
import { useMe } from '../../lib/session'
import { Logo, LangToggle, ThemeToggle } from '../../ui/brand'
import { Apply } from '../apply/Apply'
import { TakeTest } from './TakeTest'
import { Applied } from '../apply/Applied'
import { PortalHome } from './Portal'
import { JobsBoard } from './JobsBoard'
import { CompanyPage } from './CompanyPage'
import { JobPage } from './JobPage'
import { Forgot, Login, Reset } from './Auth'

/** صفحات الزائر: البوابة، فرص العمل، صفحة الشركة، صفحة الشاغر، التقديم، الدخول */
export const PUBLIC_PAGES = ['', 'jobs', 'c', 'apply', 'applied', 'login', 'forgot', 'reset', 'test']

export function Public() {
  const route = useRoute(); const { page, parts, query } = route
  const [slug, code] = [parts[1], parts.slice(2).join('/')]
  return <div className="min-h-full flex flex-col app-glow">
    <PublicHeader />
    <main className="flex-1">
      {page === 'jobs' && slug && code ? <JobPage slug={slug} code={code} refCode={query.ref} />
        : page === 'jobs' ? <JobsBoard full />
        : page === 'c' && slug ? <CompanyPage slug={slug} />
        : page === 'apply' && slug && code ? <Apply slug={slug} code={code} refCode={query.ref} />
        : page === 'applied' ? <Applied />
        : page === 'login' ? <Login />
        : page === 'forgot' ? <Forgot />
        : page === 'test' && parts[1] ? <TakeTest token={parts[1]} />
        : page === 'reset' ? <Reset token={query.token ?? ''} email={query.email ?? ''} />
        : <PortalHome />}
    </main>
    <footer className="border-t border-line text-[12px] text-ink-3 px-5 md:px-8 py-4 flex flex-wrap justify-between gap-2">
      <span>UHR · Atmata Group</span><span className="ltr">© {new Date().getFullYear()}</span>
    </footer>
  </div>
}

function PublicHeader() {
  const L = useL(); const lang = useLang()
  const user = useMe().data
  const link = 'inline-flex items-center gap-1.5 h-9 px-3 rounded-lg text-[13px] font-semibold'
  return <header className="h-[68px] border-b border-line bg-canvas/70 backdrop-blur flex items-center justify-between gap-3 px-5 md:px-8 sticky top-0 z-20">
    <div className="flex items-center gap-4 min-w-0">
      <a href="/" aria-label="UHR"><Logo h={30} /></a>
      <a href="/jobs" className={`${link} hidden sm:inline-flex text-ink-2 hover:text-ink hover:bg-sub`}>{L('فرص العمل', 'Jobs')}</a>
    </div>
    <div className="flex items-center gap-2">
      {user
        ? <a href="/" className={`${link} bg-brand-soft text-brand-2 hover:brightness-110`}>{user.portal === 'candidate' ? <FileSearch size={15} /> : <LayoutDashboard size={15} />}
            <span className="hidden sm:inline">{user.portal === 'candidate' ? L('طلباتي', 'My applications') : bi(user.name, lang)}</span></a>
        : <a href="/login" className={`${link} border border-line bg-surface hover:bg-sub`}><LogIn size={15} />{L('دخول', 'Sign in')}</a>}
      <LangToggle /><span className="hidden sm:inline-flex"><ThemeToggle /></span>
    </div>
  </header>
}
