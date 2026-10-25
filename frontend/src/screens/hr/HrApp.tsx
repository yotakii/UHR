import type { ReactNode } from 'react'
import { LayoutGrid, Briefcase, Users, UserPlus, Clock, CalendarCheck, Package, Kanban, DoorOpen, Settings2, Network, Target, BarChart3, Trophy, ShieldCheck, FileText, Hammer, Stamp, FileSignature, FileSearch, Scale, FolderCog, Nfc, CalendarPlus, Fingerprint, Wallet, ClipboardList, Receipt, ShieldAlert, Gavel, BookOpen, LogOut, DoorClosed, Megaphone, Gift, ClipboardCheck, SlidersHorizontal, Sprout } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { useRoute } from '../../lib/router'
import { can, type SessionUser } from '../../lib/session'
import { useDashboard } from '../../lib/console'
import { useInbox } from '../../lib/requests'
import { hrLink } from '../../lib/hr'
import { Empty, PageHead } from '../../ui/kit'
import { Shell, type NavItem } from '../console/Shell'
import { Dashboard } from './Dashboard'
import { Jobs } from './Jobs'
import { Permissions } from './Permissions'
import { Forms } from './Forms'
import { RecruitmentSettings } from './RecruitmentSettings'
import { Board } from './recruitment/Board'
import { CandidateFile } from './recruitment/CandidateFile'
import { MyApprovals } from './recruitment/Approvals'
import { JobTest } from './recruitment/JobTest'
import { CvDatabase } from './recruitment/CvDatabase'
import { OnboardingList } from './onboarding/OnboardingList'
import { OnboardPage } from './onboarding/OnboardPage'
import { MyDocuments } from '../employee/MyDocuments'
import { Employees } from './employees/Employees'
import { EmployeeFile } from './employees/EmployeeFile'
import { OrgChart } from './employees/OrgChart'
import { DocumentSettings } from './DocumentSettings'
import { Attendance } from './attendance/Attendance'
import { PolicySettings } from './PolicySettings'
import { Devices } from './Devices'
import { Leaves } from './leaves/Leaves'
import { MyRequests } from '../requests/MyRequests'
import { MyAttendance } from '../requests/MyAttendance'
import { TeamApprovals } from '../requests/TeamApprovals'
import { Payroll } from './payroll/Payroll'
import { Custody } from './custody/Custody'
import { MyServices } from '../requests/MyServices'
import { MyPayslip } from '../requests/MyPayslip'
import { CertificatePage } from '../requests/Certificate'
import { Discipline } from './discipline/Discipline'
import { ViolationSettings } from './ViolationSettings'
import { MyDiscipline } from '../discipline/MyDiscipline'
import { Offboarding } from './offboarding/Offboarding'
import { ExitSettings } from './ExitSettings'
import { MyResignation } from '../exit/MyResignation'
import { ReferralsHR } from '../rewards/ReferralsHR'
import { Performance } from './performance/Performance'
import { Analytics } from './performance/Analytics'
import { PerformanceSettings } from './PerformanceSettings'
import { MyReviews } from '../performance/MyReviews'
import { Development } from '../development/Development'
import { MyRewards } from '../rewards/MyRewards'
import { AnnouncementsHR, MyAnnouncements } from '../rewards/Announcements'

type Page = { item: NavItem; module: string | ((u: SessionUser) => boolean); view?: () => ReactNode; task?: string }

/**
 * نظام الشركة (M15): السايدبار بمجموعات الديمو وعداداته، كل عنصر يظهر حسب صلاحية «عرض» وحدته (SRS §10.2).
 * الوحدات التي لم تُبنَ بعد تفتح صفحة «قيد البناء» برقم مهمتها.
 */
export function HrApp({ user }: { user: SessionUser }) {
  const L = useL(); const lang = useLang()
  const route = useRoute()
  const dash = useDashboard().data
  const inbox = useInbox().data
  const count = (...kinds: string[]) => dash?.tasks.filter(t => kinds.includes(t.kind)).length
  const g1 = L('التوظيف والتعيين', 'Hiring'), g2 = L('شؤون الموظفين', 'People'), g3 = L('الرؤى', 'Insights'), g4 = L('الإعدادات', 'Settings')

  const pages: Page[] = [
    { item: { to: '/hr', icon: <LayoutGrid size={18} />, label: L('لوحة المتابعة', 'Overview') }, module: () => true, view: () => <Dashboard user={user} /> },
    { item: { to: '/hr/jobs', icon: <Briefcase size={18} />, label: L('الوظائف الشاغرة', 'Job openings'), group: g1 }, module: 'jobs',
      view: () => route.parts[2] && /^\d+$/.test(route.parts[2]) && route.parts[3] === 'test' ? <JobTest id={Number(route.parts[2])} /> : <Jobs /> },
    { item: { to: '/hr/candidates', icon: <Kanban size={18} />, label: L('المتقدمون', 'Applicants'), count: dash?.funnel?.find(f => f.stage === 'new')?.count, group: g1 }, module: 'candidates',
      view: () => route.parts[2] && /^\d+$/.test(route.parts[2]) ? <CandidateFile id={Number(route.parts[2])} /> : <Board /> },
    { item: { to: '/hr/cv', icon: <FileSearch size={18} />, label: L('قاعدة السير الذاتية', 'CV database'), group: g1 }, module: 'candidates', view: () => <CvDatabase /> },
    // «اعتماداتي» لكل حساب في الشركة: طلبات المسار الموحد (إجازات، تبريرات، إضافي) وقرارات التعيين F-31 حين تصل لمستواه
    { item: { to: '/hr/approvals', icon: <Stamp size={18} />, label: L('اعتماداتي', 'My approvals'), count: (inbox?.data.length ?? 0) + (dash?.tasks.filter(t => t.link === 'approvals' && t.kind === 'appointment').length ?? 0) || undefined, group: g1 },
      module: () => true, view: () => <div className="space-y-6"><TeamApprovals /><MyApprovals embedded /></div> },
    { item: { to: '/hr/onboarding', icon: <UserPlus size={18} />, label: L('التعيين والمباشرة', 'Appointment & onboarding'), count: count('appointment', 'onboarding'), group: g1 }, module: 'employees',
      view: () => route.parts[2] && /^\d+$/.test(route.parts[2]) ? <OnboardPage id={Number(route.parts[2])} /> : <OnboardingList user={user} /> },
    { item: { to: '/hr/referrals', icon: <Trophy size={17} />, label: L('الإحالات والمكافآت', 'Referrals & rewards'), count: count('redemption'), group: g1 }, module: 'referrals', view: () => <ReferralsHR /> },
    { item: { to: '/hr/employees', icon: <Users size={18} />, label: L('الموظفون', 'Employees'), group: g2 }, module: 'employees',
      view: () => route.parts[2] && /^\d+$/.test(route.parts[2]) ? <EmployeeFile id={Number(route.parts[2])} /> : <Employees /> },
    { item: { to: '/hr/org', icon: <Network size={17} />, label: L('الهيكل التنظيمي', 'Org chart'), group: g2 }, module: 'org', view: () => <OrgChart /> },
    { item: { to: '/hr/attendance', icon: <Clock size={18} />, label: L('الحضور والدوام', 'Attendance'), group: g2 }, module: 'attendance', view: () => <Attendance /> },
    { item: { to: '/hr/leaves', icon: <CalendarCheck size={18} />, label: L('الإجازات والتبريرات', 'Leave & justifications'), count: count('leave', 'service'), group: g2 }, module: 'leaves',
      view: () => route.parts[2] === 'cert' && /^\d+$/.test(route.parts[3] ?? '') ? <CertificatePage id={Number(route.parts[3])} hr back="/hr/leaves?tab=services" /> : <Leaves /> },
    { item: { to: '/hr/payroll', icon: <Wallet size={18} />, label: L('الكشف الشهري', 'Monthly sheet'), group: g2 }, module: 'payroll', view: () => <Payroll /> },
    { item: { to: '/hr/custody', icon: <Package size={18} />, label: L('العهد', 'Custody'), group: g2 }, module: 'assets', view: () => <Custody /> },
    { item: { to: '/hr/discipline', icon: <Gavel size={18} />, label: L('المخالفات والجزاءات', 'Violations & penalties'), count: count('discipline'), group: g2 }, module: 'discipline', view: () => <Discipline /> },
    { item: { to: '/hr/offboarding', icon: <DoorOpen size={18} />, label: L('الاستقالات وإنهاء الخدمة', 'Resignations & exits'), count: count('resignation'), group: g2 }, module: 'employees', view: () => <Offboarding /> },
    { item: { to: '/hr/announcements', icon: <Megaphone size={17} />, label: L('الإعلانات', 'Announcements'), group: g2 }, module: 'announcements', view: () => <AnnouncementsHR /> },
    // موظفو الشركة أصحاب الحسابات الإدارية: إجازاتهم وحضورهم كأي موظف (M14)
    { item: { to: '/hr/my-requests', icon: <CalendarPlus size={17} />, label: L('إجازاتي وطلباتي', 'My leave & requests'), group: g2 }, module: u => !!u.employee, view: () => <MyRequests /> },
    { item: { to: '/hr/my-attendance', icon: <Fingerprint size={17} />, label: L('حضوري', 'My attendance'), group: g2 }, module: u => !!u.employee, view: () => <MyAttendance /> },
    { item: { to: '/hr/my-services', icon: <ClipboardList size={17} />, label: L('خدماتي', 'My services'), group: g2 }, module: u => !!u.employee,
      view: () => route.parts[2] && /^\d+$/.test(route.parts[2]) ? <CertificatePage id={Number(route.parts[2])} hr={false} back="/hr/my-services" /> : <MyServices base="/hr/my-services" /> },
    { item: { to: '/hr/my-payslip', icon: <Receipt size={17} />, label: L('كشفي الشهري', 'My monthly sheet'), group: g2 }, module: u => !!u.employee, view: () => <MyPayslip /> },
    { item: { to: '/hr/my-discipline', icon: <ShieldAlert size={17} />, label: L('إنذاراتي وشكاواي', 'Warnings & complaints'), group: g2 }, module: u => !!u.employee, view: () => <MyDiscipline /> },
    { item: { to: '/hr/my-resignation', icon: <LogOut size={17} />, label: L('الاستقالة', 'Resignation'), group: g2 }, module: u => !!u.employee, view: () => <MyResignation base="/hr/my-resignation" /> },
    { item: { to: '/hr/my-reviews', icon: <ClipboardCheck size={17} />, label: L('تقييماتي', 'My reviews'), group: g2 }, module: u => !!u.employee, view: () => <MyReviews /> },
    { item: { to: '/hr/my-rewards', icon: <Gift size={17} />, label: L('مكافآتي', 'My rewards'), group: g2 }, module: u => !!u.employee, view: () => <MyRewards /> },
    { item: { to: '/hr/my-news', icon: <Megaphone size={17} />, label: L('إعلانات الشركة', 'Company news'), group: g2 }, module: u => !!u.employee && !can(u, 'announcements'), view: () => <MyAnnouncements /> },
    { item: { to: '/hr/my-documents', icon: <FileSignature size={17} />, label: L('وثائقي للتوقيع', 'My documents'), group: g2 }, module: u => !!u.employee, view: () => <MyDocuments /> },
    { item: { to: '/hr/performance', icon: <Target size={17} />, label: L('الأداء وتقييم 360', 'Performance & 360'), group: g3 }, module: 'performance', view: () => <Performance /> },
    { item: { to: '/hr/development', icon: <Sprout size={17} />, label: L('التعاقب وخطط التطوير', 'Succession & development'), group: g3 }, module: 'succession', view: () => <Development /> },
    { item: { to: '/hr/analytics', icon: <BarChart3 size={17} />, label: L('التحليلات', 'Analytics'), group: g3 }, module: u => can(u, 'employees', 'export'), view: () => <Analytics /> },
    { item: { to: '/hr/settings/policies', icon: <Settings2 size={18} />, label: L('سياسات الدوام والإجازات', 'Work & leave policies'), group: g4 }, module: 'policies', view: () => <PolicySettings /> },
    { item: { to: '/hr/settings/performance', icon: <SlidersHorizontal size={17} />, label: L('إعدادات الأداء والتقييم', 'Performance settings'), group: g4 }, module: u => can(u, 'settings', 'edit') && can(u, 'performance'), view: () => <PerformanceSettings /> },
    { item: { to: '/hr/settings/devices', icon: <Nfc size={18} />, label: L('أجهزة الحضور', 'Attendance devices'), group: g4 }, module: u => can(u, 'attendance', 'edit'), view: () => <Devices /> },
    { item: { to: '/hr/settings/recruitment', icon: <Scale size={18} />, label: L('قواعد التوظيف والمطابقة', 'Recruitment & matching rules'), group: g4 }, module: u => can(u, 'settings') || can(u, 'candidates'), view: () => <RecruitmentSettings /> },
    { item: { to: '/hr/settings/documents', icon: <FolderCog size={18} />, label: L('الملفات الإضافية للموظفين', 'Employee documents'), group: g4 }, module: u => can(u, 'settings') && can(u, 'employees'), view: () => <DocumentSettings /> },
    { item: { to: '/hr/settings/violations', icon: <BookOpen size={18} />, label: L('دليل المخالفات', 'Violations catalogue'), group: g4 }, module: 'discipline', view: () => <ViolationSettings /> },
    { item: { to: '/hr/settings/exit', icon: <DoorClosed size={18} />, label: L('إعدادات الخروج', 'Exit settings'), group: g4 }, module: u => can(u, 'settings', 'edit'), view: () => <ExitSettings /> },
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
