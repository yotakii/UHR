import type { ReactNode } from 'react'
import { LayoutGrid, Briefcase, Users, UserPlus, Clock, CalendarCheck, Package, Kanban, DoorOpen, Settings2, Network, Target, BarChart3, Trophy, ShieldCheck, FileText, Hammer, Stamp } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { useRoute } from '../../lib/router'
import { can, type SessionUser } from '../../lib/session'
import { useDashboard } from '../../lib/console'
import { hrLink } from '../../lib/hr'
import { Empty, PageHead } from '../../ui/kit'
import { Shell, type NavItem } from '../console/Shell'
import { Dashboard } from './Dashboard'
import { Jobs } from './Jobs'
import { Permissions } from './Permissions'
import { Forms } from './Forms'
import { Board } from './recruitment/Board'
import { CandidateFile } from './recruitment/CandidateFile'
import { MyApprovals } from './recruitment/Approvals'

type Page = { item: NavItem; module: string | ((u: SessionUser) => boolean); view?: () => ReactNode; task?: string }

/**
 * نظام الشركة (M15): السايدبار بمجموعات الديمو وعداداته، كل عنصر يظهر حسب صلاحية «عرض» وحدته (SRS §10.2).
 * الوحدات التي لم تُبنَ بعد تفتح صفحة «قيد البناء» برقم مهمتها.
 */
export function HrApp({ user }: { user: SessionUser }) {
  const L = useL(); const lang = useLang()
  const route = useRoute()
  const dash = useDashboard().data
  const count = (...kinds: string[]) => dash?.tasks.filter(t => kinds.includes(t.kind)).length
  const g1 = L('التوظيف والتعيين', 'Hiring'), g2 = L('شؤون الموظفين', 'People'), g3 = L('الرؤى', 'Insights'), g4 = L('الإعدادات', 'Settings')

  const pages: Page[] = [
    { item: { to: '/hr', icon: <LayoutGrid size={18} />, label: L('لوحة المتابعة', 'Overview') }, module: () => true, view: () => <Dashboard user={user} /> },
    { item: { to: '/hr/jobs', icon: <Briefcase size={18} />, label: L('الوظائف الشاغرة', 'Job openings'), group: g1 }, module: 'jobs', view: () => <Jobs /> },
    { item: { to: '/hr/candidates', icon: <Kanban size={18} />, label: L('المتقدمون', 'Applicants'), count: dash?.funnel?.find(f => f.stage === 'new')?.count, group: g1 }, module: 'candidates',
      view: () => route.parts[2] && /^\d+$/.test(route.parts[2]) ? <CandidateFile id={Number(route.parts[2])} /> : <Board /> },
    // «اعتماداتي» لكل حساب في الشركة: يظهر عندما يصل لمستواه قرار تعيين (أو لمن يتابع التوظيف)
    { item: { to: '/hr/approvals', icon: <Stamp size={18} />, label: L('اعتماداتي', 'My approvals'), count: dash?.tasks.filter(t => t.link === 'approvals').length, group: g1 },
      module: () => true, view: () => <MyApprovals /> },
    { item: { to: '/hr/onboarding', icon: <UserPlus size={18} />, label: L('التعيين والمباشرة', 'Appointment & onboarding'), count: count('appointment', 'onboarding'), group: g1 }, module: 'candidates', task: '06' },
    { item: { to: '/hr/referrals', icon: <Trophy size={17} />, label: L('الإحالات والمكافآت', 'Referrals & rewards'), group: g1 }, module: 'referrals', task: '06' },
    { item: { to: '/hr/employees', icon: <Users size={18} />, label: L('الموظفون', 'Employees'), group: g2 }, module: 'employees', task: '07' },
    { item: { to: '/hr/org', icon: <Network size={17} />, label: L('الهيكل التنظيمي', 'Org chart'), group: g2 }, module: 'org', task: '07' },
    { item: { to: '/hr/attendance', icon: <Clock size={18} />, label: L('الحضور والدوام', 'Attendance'), group: g2 }, module: 'attendance', task: '07' },
    { item: { to: '/hr/leaves', icon: <CalendarCheck size={18} />, label: L('الإجازات والتبريرات', 'Leave & justifications'), count: count('leave', 'service'), group: g2 }, module: 'leaves', task: '07' },
    { item: { to: '/hr/custody', icon: <Package size={18} />, label: L('العهد', 'Custody'), group: g2 }, module: 'assets', task: '08' },
    { item: { to: '/hr/offboarding', icon: <DoorOpen size={18} />, label: L('الاستقالات وإنهاء الخدمة', 'Resignations & exits'), count: count('resignation'), group: g2 }, module: 'employees', task: '08' },
    { item: { to: '/hr/performance', icon: <Target size={17} />, label: L('الأداء وتقييم 360', 'Performance & 360'), group: g3 }, module: 'performance', task: '09' },
    { item: { to: '/hr/analytics', icon: <BarChart3 size={17} />, label: L('التحليلات', 'Analytics'), group: g3 }, module: u => can(u, 'employees', 'export'), task: '09' },
    { item: { to: '/hr/settings/policies', icon: <Settings2 size={18} />, label: L('سياسات الدوام والإجازات', 'Work & leave policies'), group: g4 }, module: 'policies', task: '07' },
    { item: { to: '/hr/settings/permissions', icon: <ShieldCheck size={18} />, label: L('الصلاحيات', 'Permissions'), group: g4 }, module: 'users', view: () => <Permissions user={user} /> },
    { item: { to: '/hr/settings/forms', icon: <FileText size={18} />, label: L('النماذج وقالب الطباعة', 'Forms & print template'), group: g4 }, module: 'settings', view: () => <Forms /> },
  ]
  const allowed = pages.filter(p => typeof p.module === 'function' ? p.module(user) : can(user, p.module))
  // الصفحة الرئيسية لحساب الشركة = لوحة المتابعة
  const path = route.parts[0] === 'hr' ? '/' + route.parts.join('/') : '/hr'
  const page = [...allowed].sort((a, b) => b.item.to.length - a.item.to.length).find(p => path === p.item.to || path.startsWith(p.item.to + '/'))

  return <Shell items={allowed.map(p => p.item)} user={user} org={user.company ? bi(user.company.short, lang) : ''} sub={L('مركز عمليات الموارد البشرية', 'People Operations Center')}
    linkOf={hrLink} search={{ employee: id => `/hr/employees/${id}`, candidate: id => `/hr/candidates/${id}`, job: () => '/hr/jobs' }}>
    {page?.view ? page.view() : page ? <Soon title={page.item.label} task={page.task!} /> : <NotAllowed />}
  </Shell>
}

function Soon({ title, task }: { title: string; task: string }) {
  const L = useL()
  return <div className="max-w-[1200px] mx-auto space-y-5">
    <PageHead title={title} />
    <div className="bg-surface border border-line rounded-2xl"><Empty title={<span className="flex items-center gap-2 justify-center"><Hammer size={18} className="text-brass" />{L('هذه الوحدة قيد البناء', 'This module is being built')}</span>}>
      {L(`تُسلَّم ضمن المهمة ${task} من خطة العمل. البيانات موجودة وتظهر في لوحة المتابعة.`, `It ships with task ${task} of the plan. Its data already feeds the overview.`)}</Empty></div>
  </div>
}

function NotAllowed() {
  const L = useL()
  return <div className="max-w-[1200px] mx-auto"><div className="bg-surface border border-line rounded-2xl">
    <Empty title={L('هذه الصفحة غير متاحة لك', 'This page is not available to you')} action={<a className="text-brand font-semibold text-[14px]" href="/hr">{L('العودة للوحة المتابعة', 'Back to the overview')}</a>}>
      {L('صلاحيات دورك لا تشمل هذه الوحدة. راجع مدير النظام إذا احتجت إليها.', 'Your role does not include this module. Ask your system administrator if you need it.')}</Empty>
  </div></div>
}
