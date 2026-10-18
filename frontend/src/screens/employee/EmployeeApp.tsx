import type { ReactNode } from 'react'
import { Home, FileSignature, ChevronLeft, ChevronRight } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { useRoute } from '../../lib/router'
import type { SessionUser } from '../../lib/session'
import { pendingDocs, useMyDocs } from '../../lib/onboarding'
import { Empty, PageHead, Panel } from '../../ui/kit'
import { Shell } from '../console/Shell'
import { MyDocuments } from './MyDocuments'

/** روابط إشعارات الموظف ← مسارات بوابته */
export const employeeLink = (link: string) => link === 'docs' ? '/me/documents' : null

/**
 * بوابة الموظف (M14): الآن «الرئيسية» بما ينتظره و«وثائق للتوقيع» — بقية أقسامها (الحضور، الإجازات، المكافآت…) مع المهمة 09.
 */
export function EmployeeApp({ user }: { user: SessionUser }) {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const docs = useMyDocs().data
  const n = pendingDocs(docs)
  const items = [{ to: '/', icon: <Home size={18} />, label: L('الرئيسية', 'Home') }, { to: '/me/documents', icon: <FileSignature size={18} />, label: L('وثائق للتوقيع', 'Documents to sign'), count: n || undefined }]
  const page = route.parts[0] === 'me' && route.parts[1] === 'documents' ? 'docs' : 'home'
  const Next = lang === 'ar' ? ChevronLeft : ChevronRight
  return <Shell items={items} user={user} org={user.company ? bi(user.company.short, lang) : ''} sub={L('بوابة الموظف', 'Employee portal')} linkOf={employeeLink}>
    {page === 'docs' ? <MyDocuments /> : <div className="max-w-[1000px] mx-auto space-y-5">
      <PageHead title={<>{L('أهلاً', 'Welcome')}، {bi(user.name, lang)}</>} sub={user.employee ? <>{bi(user.employee.title, lang)} · {bi(user.employee.dept, lang)} · <span className="ltr inline-block">{user.employee.code}</span></> : undefined} />
      <Panel title={L('بانتظارك', 'Waiting for you')} pad={false}>
        {n === 0 ? <Empty title={L('لا شيء بانتظارك الآن', 'Nothing waiting for you now')} /> : <ul className="divide-y divide-line-2">
          {docs!.pending.map(a => <Todo key={a.id} text={L(`توقيع استلام عهدة: ${a.name.ar}`, `Sign custody receipt: ${a.name.en}`)} icon={<Next size={16} />} />)}
          {docs!.undertakings && !docs!.undertakings.f22.signed_at && <Todo text={L('توقيع تعهد المحافظة على سرية العمل (F-22)', 'Sign confidentiality undertaking (F-22)')} icon={<Next size={16} />} />}
          {docs!.undertakings && !docs!.undertakings.f20.signed_at && <Todo text={L('توقيع إقرار إبراء الذمة الوقائي (F-20)', 'Sign precautionary clearance (F-20)')} icon={<Next size={16} />} />}
          {docs!.undertakings && !docs!.undertakings.conduct.signed_at && <Todo text={L('توقيع إقرار ميثاق السلوك المهني', 'Sign the code of conduct acknowledgment')} icon={<Next size={16} />} />}
        </ul>}
      </Panel>
      <div className="rounded-2xl border border-dashed border-line bg-surface/60"><Empty title={L('بقية أقسام بوابة الموظف', 'More of the employee portal')}>{L('الحضور والإجازات وكشف الراتب والمكافآت تُبنى في المهام 07 و09.', 'Attendance, leave, payslip and rewards are built in tasks 07 and 09.')}</Empty></div>
    </div>}
  </Shell>
}

function Todo({ text, icon }: { text: string; icon: ReactNode }) {
  return <li><a href="/me/documents" className="flex items-center gap-3 px-4 py-3 text-[14px] hover:bg-sub"><span className="size-2 rounded-full bg-brass shrink-0" /><span className="flex-1">{text}</span><span className="text-ink-3">{icon}</span></a></li>
}
