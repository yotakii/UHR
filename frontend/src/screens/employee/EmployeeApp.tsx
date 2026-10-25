import type { ReactNode } from 'react'
import { Home, FileSignature, ChevronLeft, ChevronRight, CalendarPlus, Fingerprint, Stamp, ClipboardList, Receipt, ShieldAlert, LogOut, Gift, Megaphone } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { useRoute } from '../../lib/router'
import type { SessionUser } from '../../lib/session'
import { pendingDocs, useMyDocs } from '../../lib/onboarding'
import { Empty, PageHead, Panel } from '../../ui/kit'
import { Shell } from '../console/Shell'
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
import { MyAnnouncements } from '../rewards/Announcements'

/** روابط إشعارات الموظف ← مسارات بوابته */
export const employeeLink = (link: string) => ({ docs: '/me/documents', requests: '/me/requests', approvals: '/me/approvals', attendance: '/me/attendance', payslip: '/me/payslip', services: '/me/services', discipline: '/me/discipline', complaints: '/me/discipline', resignation: '/me/resignation', handover: '/me/resignation', rewards: '/me/rewards', internal: '/me/rewards', announcements: '/me/news' } as Record<string, string>)[link] ?? null

/**
 * بوابة الموظف (M14): «الرئيسية» بما ينتظره، «إجازاتي وطلباتي»، «حضوري» مع التبرير، «اعتماداتي» للمدير (فريقه، نائبه، التكليف بالإضافي)
 * و«وثائق للتوقيع» — بقية أقسامها (كشف الراتب، المكافآت…) مع المهمة 09.
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
  const items = [{ to: '/', icon: <Home size={18} />, label: L('الرئيسية', 'Home') },
    { to: '/me/requests', icon: <CalendarPlus size={18} />, label: L('إجازاتي وطلباتي', 'My leave & requests') },
    { to: '/me/attendance', icon: <Fingerprint size={18} />, label: L('حضوري', 'My attendance'), count: issues.length || undefined },
    { to: '/me/services', icon: <ClipboardList size={18} />, label: L('خدماتي', 'My services') },
    { to: '/me/payslip', icon: <Receipt size={18} />, label: L('كشفي الشهري', 'My monthly sheet') },
    { to: '/me/rewards', icon: <Gift size={18} />, label: L('المكافآت', 'Rewards') },
    { to: '/me/news', icon: <Megaphone size={18} />, label: L('إعلانات الشركة', 'Company news') },
    { to: '/me/discipline', icon: <ShieldAlert size={18} />, label: L('إنذاراتي وشكاواي', 'Warnings & complaints'), count: toSign.length + toAnswer.length || undefined },
    { to: '/me/resignation', icon: <LogOut size={18} />, label: L('الاستقالة', 'Resignation'), count: toReceive.length || undefined },
    ...(approver ? [{ to: '/me/approvals', icon: <Stamp size={18} />, label: L('اعتماداتي', 'My approvals'), count: inbox!.data.length || undefined }] : []),
    { to: '/me/documents', icon: <FileSignature size={18} />, label: L('وثائق للتوقيع', 'Documents to sign'), count: n || undefined }]
  const sec = route.parts[0] === 'me' ? route.parts[1] : undefined
  const page = sec === 'documents' ? 'docs' : sec === 'requests' || sec === 'attendance' || sec === 'services' || sec === 'payslip' || sec === 'discipline' || sec === 'resignation' || sec === 'rewards' || sec === 'news' || (sec === 'approvals' && approver) ? sec : 'home'
  const Next = lang === 'ar' ? ChevronLeft : ChevronRight
  return <Shell items={items} user={user} org={user.company ? bi(user.company.short, lang) : ''} sub={L('بوابة الموظف', 'Employee portal')} linkOf={employeeLink}>
    {page === 'docs' ? <MyDocuments /> : page === 'requests' ? <MyRequests /> : page === 'attendance' ? <MyAttendance /> : page === 'approvals' ? <TeamApprovals /> : page === 'payslip' ? <MyPayslip /> : page === 'discipline' ? <MyDiscipline /> : page === 'rewards' ? <MyRewards /> : page === 'news' ? <MyAnnouncements /> : page === 'resignation' ? <MyResignation base="/me/resignation" />
      : page === 'services' ? (route.parts[2] && /^\d+$/.test(route.parts[2]) ? <CertificatePage id={Number(route.parts[2])} hr={false} back="/me/services" /> : <MyServices base="/me/services" />) : <div className="max-w-[1000px] mx-auto space-y-5">
      <PageHead title={<>{L('أهلاً', 'Welcome')}، {bi(user.name, lang)}</>} sub={user.employee ? <>{bi(user.employee.title, lang)} · {bi(user.employee.dept, lang)} · <span className="ltr inline-block">{user.employee.code}</span></> : undefined} />
      <Panel title={L('بانتظارك', 'Waiting for you')} pad={false}>
        {n === 0 && !issues.length && !inbox?.data.length && !toSign.length && !toAnswer.length && !toReceive.length ? <Empty title={L('لا شيء بانتظارك الآن', 'Nothing waiting for you now')} /> : <ul className="divide-y divide-line-2">
          {inbox && inbox.data.length > 0 && <Todo to="/me/approvals" text={L(`${inbox.data.length} طلب بانتظار اعتمادك`, `${inbox.data.length} request(s) awaiting your approval`)} icon={<Next size={16} />} />}
          {toAnswer.map(c => <Todo key={'a' + c.id} to="/me/discipline" text={L(`إبلاغ بمخالفة ${c.ref_no} — اكتب أقوالك قبل ${c.deadline}`, `Violation notice ${c.ref_no} — reply before ${c.deadline}`)} icon={<Next size={16} />} />)}
          {toReceive.map(r => <Todo key={'h' + r.id} to="/me/resignation" text={L(`استلام مهام ${r.employee?.name.ar ?? ''} — راجع الجدول ووقّع الإقرار`, `Receive ${r.employee?.name.en ?? ''}'s tasks — review and acknowledge`)} icon={<Next size={16} />} />)}
          {toSign.map(c => <Todo key={'s' + c.id} to="/me/discipline" text={L(`قرار الحالة ${c.ref_no} — وقّع بالاستلام`, `Decision on ${c.ref_no} — sign the acknowledgement`)} icon={<Next size={16} />} />)}
          {issues.map(i => <Todo key={i.date} to="/me/attendance" text={L(`تبرير ${i.status === 'absent' ? 'غياب' : i.status === 'incomplete' ? 'يوم ناقص' : 'تأخير'} يوم ${i.date}`, `Justify ${i.status === 'absent' ? 'absence' : i.status === 'incomplete' ? 'incomplete day' : 'lateness'} on ${i.date}`)} icon={<Next size={16} />} />)}
          {docs?.pending.map(a => <Todo key={a.id} text={L(`توقيع استلام عهدة: ${a.name.ar}`, `Sign custody receipt: ${a.name.en}`)} icon={<Next size={16} />} />)}
          {docs?.undertakings && !docs.undertakings.f22.signed_at && <Todo text={L('توقيع تعهد المحافظة على سرية العمل (F-22)', 'Sign confidentiality undertaking (F-22)')} icon={<Next size={16} />} />}
          {docs?.undertakings && !docs.undertakings.f20.signed_at && <Todo text={L('توقيع إقرار إبراء الذمة الوقائي (F-20)', 'Sign precautionary clearance (F-20)')} icon={<Next size={16} />} />}
          {docs?.undertakings && !docs.undertakings.conduct.signed_at && <Todo text={L('توقيع إقرار ميثاق السلوك المهني', 'Sign the code of conduct acknowledgment')} icon={<Next size={16} />} />}
        </ul>}
      </Panel>
      <MyAnnouncements panel limit={3} />
    </div>}
  </Shell>
}

function Todo({ text, icon, to = '/me/documents' }: { text: string; icon: ReactNode; to?: string }) {
  return <li><a href={to} className="flex items-center gap-3 px-4 py-3 text-[14px] hover:bg-sub"><span className="size-2 rounded-full bg-brass shrink-0" /><span className="flex-1">{text}</span><span className="text-ink-3">{icon}</span></a></li>
}
