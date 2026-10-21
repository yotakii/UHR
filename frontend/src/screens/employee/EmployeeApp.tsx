import type { ReactNode } from 'react'
import { Home, FileSignature, ChevronLeft, ChevronRight, CalendarPlus, Fingerprint, Stamp } from 'lucide-react'
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

/** روابط إشعارات الموظف ← مسارات بوابته */
export const employeeLink = (link: string) => ({ docs: '/me/documents', requests: '/me/requests', approvals: '/me/approvals', attendance: '/me/attendance' } as Record<string, string>)[link] ?? null

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
  const items = [{ to: '/', icon: <Home size={18} />, label: L('الرئيسية', 'Home') },
    { to: '/me/requests', icon: <CalendarPlus size={18} />, label: L('إجازاتي وطلباتي', 'My leave & requests') },
    { to: '/me/attendance', icon: <Fingerprint size={18} />, label: L('حضوري', 'My attendance'), count: issues.length || undefined },
    ...(approver ? [{ to: '/me/approvals', icon: <Stamp size={18} />, label: L('اعتماداتي', 'My approvals'), count: inbox!.data.length || undefined }] : []),
    { to: '/me/documents', icon: <FileSignature size={18} />, label: L('وثائق للتوقيع', 'Documents to sign'), count: n || undefined }]
  const sec = route.parts[0] === 'me' ? route.parts[1] : undefined
  const page = sec === 'documents' ? 'docs' : sec === 'requests' || sec === 'attendance' || (sec === 'approvals' && approver) ? sec : 'home'
  const Next = lang === 'ar' ? ChevronLeft : ChevronRight
  return <Shell items={items} user={user} org={user.company ? bi(user.company.short, lang) : ''} sub={L('بوابة الموظف', 'Employee portal')} linkOf={employeeLink}>
    {page === 'docs' ? <MyDocuments /> : page === 'requests' ? <MyRequests /> : page === 'attendance' ? <MyAttendance /> : page === 'approvals' ? <TeamApprovals /> : <div className="max-w-[1000px] mx-auto space-y-5">
      <PageHead title={<>{L('أهلاً', 'Welcome')}، {bi(user.name, lang)}</>} sub={user.employee ? <>{bi(user.employee.title, lang)} · {bi(user.employee.dept, lang)} · <span className="ltr inline-block">{user.employee.code}</span></> : undefined} />
      <Panel title={L('بانتظارك', 'Waiting for you')} pad={false}>
        {n === 0 && !issues.length && !inbox?.data.length ? <Empty title={L('لا شيء بانتظارك الآن', 'Nothing waiting for you now')} /> : <ul className="divide-y divide-line-2">
          {inbox && inbox.data.length > 0 && <Todo to="/me/approvals" text={L(`${inbox.data.length} طلب بانتظار اعتمادك`, `${inbox.data.length} request(s) awaiting your approval`)} icon={<Next size={16} />} />}
          {issues.map(i => <Todo key={i.date} to="/me/attendance" text={L(`تبرير ${i.status === 'absent' ? 'غياب' : i.status === 'incomplete' ? 'يوم ناقص' : 'تأخير'} يوم ${i.date}`, `Justify ${i.status === 'absent' ? 'absence' : i.status === 'incomplete' ? 'incomplete day' : 'lateness'} on ${i.date}`)} icon={<Next size={16} />} />)}
          {docs?.pending.map(a => <Todo key={a.id} text={L(`توقيع استلام عهدة: ${a.name.ar}`, `Sign custody receipt: ${a.name.en}`)} icon={<Next size={16} />} />)}
          {docs?.undertakings && !docs.undertakings.f22.signed_at && <Todo text={L('توقيع تعهد المحافظة على سرية العمل (F-22)', 'Sign confidentiality undertaking (F-22)')} icon={<Next size={16} />} />}
          {docs?.undertakings && !docs.undertakings.f20.signed_at && <Todo text={L('توقيع إقرار إبراء الذمة الوقائي (F-20)', 'Sign precautionary clearance (F-20)')} icon={<Next size={16} />} />}
          {docs?.undertakings && !docs.undertakings.conduct.signed_at && <Todo text={L('توقيع إقرار ميثاق السلوك المهني', 'Sign the code of conduct acknowledgment')} icon={<Next size={16} />} />}
        </ul>}
      </Panel>
      <div className="rounded-2xl border border-dashed border-line bg-surface/60"><Empty title={L('بقية أقسام بوابة الموظف', 'More of the employee portal')}>{L('كشف الراتب والشهادات والمكافآت تُبنى في المهام القادمة.', 'Payslip, certificates and rewards come in the next tasks.')}</Empty></div>
    </div>}
  </Shell>
}

function Todo({ text, icon, to = '/me/documents' }: { text: string; icon: ReactNode; to?: string }) {
  return <li><a href={to} className="flex items-center gap-3 px-4 py-3 text-[14px] hover:bg-sub"><span className="size-2 rounded-full bg-brass shrink-0" /><span className="flex-1">{text}</span><span className="text-ink-3">{icon}</span></a></li>
}
