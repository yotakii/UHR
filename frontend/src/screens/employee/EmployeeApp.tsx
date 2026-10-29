import type { ReactNode } from 'react'
import { Home, FileSignature, ChevronLeft, ChevronRight, CalendarCheck, Clock, Stamp, ClipboardList, Wallet, ShieldAlert, DoorOpen, Trophy, Megaphone, ClipboardCheck, Sprout, Users, UserRound, Library } from 'lucide-react'
import { useL, useLang } from '../../lib/store'
import { useRoute } from '../../lib/router'
import type { SessionUser } from '../../lib/session'
import { pendingDocs, useMyDocs } from '../../lib/onboarding'
import { Panel } from '../../ui/kit'
import { PortalShell, type PortalTab } from './PortalShell'
import { PortalHome, PortalProfile, PortalTeam } from './PortalPages'
import { MyDocuments } from './MyDocuments'
import { useInbox, useMyAttendance } from '../../lib/requests'
import { MyRequests } from '../requests/MyRequests'
import { MyAttendance } from '../requests/MyAttendance'
import { TeamApprovals } from '../requests/TeamApprovals'
import { MyServices } from '../requests/MyServices'
import { MyPayslip } from '../requests/MyPayslip'
import { CertificatePage } from '../requests/Certificate'
import { MyDiscipline } from '../discipline/MyDiscipline'
import { useMyDiscipline } from '../../lib/discipline'
import { MyResignation } from '../exit/MyResignation'
import { useMyExit } from '../../lib/exit'
import { MyRewards } from '../rewards/MyRewards'
import { MyReviews } from '../performance/MyReviews'
import { Development } from '../development/Development'
import { useMyReviews } from '../../lib/performance'
import { MyAnnouncements } from '../rewards/Announcements'
import { PolicyLibrary } from '../policies/PolicyLibrary'

/** روابط إشعارات الموظف ← مسارات بوابته */
export const employeeLink = (link: string) => link.startsWith('policy:') ? `/me/policies?doc=${link.slice(7)}` : ({ docs: '/me/documents', requests: '/me/requests', approvals: '/me/approvals', attendance: '/me/attendance', payslip: '/me/payslip', services: '/me/services', discipline: '/me/discipline', complaints: '/me/discipline', resignation: '/me/resignation', handover: '/me/resignation', profile: '/me/profile', rewards: '/me/rewards', internal: '/me/rewards', announcements: '/me/news', reviews: '/me/reviews', development: '/me/development' } as Record<string, string>)[link] ?? null

/**
 * بوابة الموظف M14 (PortalShell في الديمو): تبويبات علوية على الحاسب وشريط سفلي بخمسة أزرار على الجوال،
 * وبقية الصفحات (الخدمات، التقييم، التطوير، الإعلانات، الإنذارات، الاعتمادات، الاستقالة، ملفي) في «المزيد» وقائمة المستخدم.
 */
export function EmployeeApp({ user }: { user: SessionUser }) {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const docs = useMyDocs().data
  const n = pendingDocs(docs)
  const inbox = useInbox().data
  const issues = useMyAttendance().data?.issues.filter(i => i.open && !i.justification) ?? []
  const approver = !!inbox && (inbox.team.length > 0 || inbox.data.length > 0)
  const disc = useMyDiscipline().data
  const toSign = disc?.data.filter(c => c.status === 'decided' && !c.acknowledged_at) ?? []
  const toAnswer = disc?.data.filter(c => (c.status === 'open' || c.status === 'investigated') && !c.has_defense) ?? []
  const ex = useMyExit().data
  const toReceive = ex?.receiving.filter(r => !r.handover?.receiver_signed) ?? []
  const reviews = useMyReviews().data?.forms.filter(f => f.status === 'pending') ?? []
  const tabs: PortalTab[] = [{ to: '/', icon: <Home size={17} />, label: L('الرئيسية', 'Home') },
    { to: '/me/attendance', icon: <Clock size={17} />, label: L('حضوري', 'Attendance'), badge: issues.length || undefined },
    { to: '/me/requests', icon: <CalendarCheck size={17} />, label: L('طلباتي', 'Requests') },
    { to: '/me/payslip', icon: <Wallet size={17} />, label: L('راتبي', 'Pay') },
    { to: '/me/team', icon: <Users size={17} />, label: L('فريقي', 'Team') },
    ...(approver ? [{ to: '/me/approvals', icon: <Stamp size={17} />, label: L('اعتماداتي', 'Approvals'), badge: inbox!.data.length || undefined }] : []),
    { to: '/me/documents', icon: <FileSignature size={17} />, label: L('وثائقي', 'Documents'), badge: n || undefined },
    { to: '/me/rewards', icon: <Trophy size={17} />, label: L('المكافآت', 'Rewards') }]
  const more: PortalTab[] = [{ to: '/me/services', icon: <ClipboardList size={17} />, label: L('خدماتي', 'My services') },
    { to: '/me/reviews', icon: <ClipboardCheck size={17} />, label: L('تقييماتي', 'My reviews'), badge: reviews.length || undefined },
    { to: '/me/development', icon: <Sprout size={17} />, label: L('خطة تطويري', 'My development') },
    { to: '/me/news', icon: <Megaphone size={17} />, label: L('إعلانات الشركة', 'Company news') },
    { to: '/me/policies', icon: <Library size={17} />, label: L('سياسات الشركة', 'Company policies') },
    { to: '/me/discipline', icon: <ShieldAlert size={17} />, label: L('إنذاراتي وشكاواي', 'Warnings & complaints'), badge: toSign.length + toAnswer.length || undefined },
    { to: '/me/resignation', icon: <DoorOpen size={17} />, label: L('الاستقالة', 'Resignation'), badge: toReceive.length || undefined },
    { to: '/me/profile', icon: <UserRound size={17} />, label: L('ملفي الشخصي', 'My profile') }]
  const sec = route.parts[0] === 'me' ? route.parts[1] : undefined
  const Next = lang === 'ar' ? ChevronLeft : ChevronRight
  const todoList = <>
    {reviews.length > 0 && <Todo to="/me/reviews" text={L(`${reviews.length} نموذج تقييم بانتظارك`, `${reviews.length} review form(s) waiting for you`)} icon={<Next size={16} />} />}
    {inbox && inbox.data.length > 0 && <Todo to="/me/approvals" text={L(`${inbox.data.length} طلب بانتظار اعتمادك`, `${inbox.data.length} request(s) awaiting your approval`)} icon={<Next size={16} />} />}
    {toAnswer.map(c => <Todo key={'a' + c.id} to="/me/discipline" text={L(`إبلاغ بمخالفة ${c.ref_no} — اكتب أقوالك قبل ${c.deadline}`, `Violation notice ${c.ref_no} — reply before ${c.deadline}`)} icon={<Next size={16} />} />)}
    {toReceive.map(r => <Todo key={'h' + r.id} to="/me/resignation" text={L(`استلام مهام ${r.employee?.name.ar ?? ''} — راجع الجدول ووقّع الإقرار`, `Receive ${r.employee?.name.en ?? ''}'s tasks — review and acknowledge`)} icon={<Next size={16} />} />)}
    {toSign.map(c => <Todo key={'s' + c.id} to="/me/discipline" text={L(`قرار الحالة ${c.ref_no} — وقّع بالاستلام`, `Decision on ${c.ref_no} — sign the acknowledgement`)} icon={<Next size={16} />} />)}
    {issues.map(i => <Todo key={i.date} to="/me/attendance" text={L(`تبرير ${i.status === 'absent' ? 'غياب' : i.status === 'incomplete' ? 'يوم ناقص' : 'تأخير'} يوم ${i.date}`, `Justify ${i.status === 'absent' ? 'absence' : i.status === 'incomplete' ? 'incomplete day' : 'lateness'} on ${i.date}`)} icon={<Next size={16} />} />)}
  </>
  const hasTodos = reviews.length + (inbox?.data.length ?? 0) + toAnswer.length + toReceive.length + toSign.length + issues.length > 0
  const todos = hasTodos ? <Panel title={L('بانتظارك', 'Waiting for you')} pad={false}><ul className="divide-y divide-line-2">{todoList}</ul></Panel> : null
  const page = (): ReactNode => {
    switch (sec) {
      case 'documents': return <MyDocuments />
      case 'requests': return <MyRequests />
      case 'attendance': return <MyAttendance />
      case 'approvals': return approver ? <TeamApprovals /> : <PortalHome toSign={n} todos={todos} />
      case 'payslip': return <MyPayslip />
      case 'discipline': return <MyDiscipline />
      case 'rewards': return <MyRewards />
      case 'reviews': return <MyReviews />
      case 'development': return <Development title={L('خطة تطويري', 'My development')} />
      case 'news': return <MyAnnouncements />
      case 'policies': return <PolicyLibrary settingsLinks={false} />
      case 'resignation': return <MyResignation base="/me/resignation" />
      case 'team': return <PortalTeam />
      case 'profile': return <PortalProfile />
      case 'services': return route.parts[2] && /^\d+$/.test(route.parts[2]) ? <CertificatePage id={Number(route.parts[2])} hr={false} back="/me/services" /> : <MyServices base="/me/services" />
      default: return <PortalHome toSign={n} todos={todos} />
    }
  }
  return <PortalShell user={user} tabs={tabs} more={more} linkOf={employeeLink}>{page()}</PortalShell>
}

function Todo({ text, icon, to = '/me/documents' }: { text: string; icon: ReactNode; to?: string }) {
  return <li><a href={to} className="flex items-center gap-3 px-4 py-3 text-[14px] hover:bg-sub"><span className="size-2 rounded-full bg-brass shrink-0" /><span className="flex-1">{text}</span><span className="text-ink-3">{icon}</span></a></li>
}
