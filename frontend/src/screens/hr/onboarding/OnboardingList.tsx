import { useState } from 'react'
import { Loader2, UserPlus, FileSpreadsheet } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../../lib/store'
import { navigate } from '../../../lib/router'
import { can, type SessionUser } from '../../../lib/session'
import { useOnboardingList, type EmpStatus } from '../../../lib/onboarding'
import { Avatar, Badge, Btn, Empty, PageHead, Panel, Progress } from '../../../ui/kit'
import { AddEmployee } from './AddEmployee'
import { ImportEmployees } from './ImportEmployees'

/** M06 — قائمة التعيين والمباشرة: قرارات F-31 بانتظار الاعتماد + الموظفون الجدد (F-19 · F-32 · F-22 · F-20) */
export function OnboardingList({ user }: { user: SessionUser }) {
  const L = useL(); const lang = useLang()
  const { data, isLoading } = useOnboardingList()
  const [add, setAdd] = useState(false); const [imp, setImp] = useState(false)
  const creator = can(user, 'employees', 'create')
  if (isLoading || !data) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  return <div className="max-w-[1100px] mx-auto space-y-5">
    <PageHead title={L('التعيين والمباشرة', 'Appointment & onboarding')} sub={L('قرارات التعيين قيد الاعتماد، والموظفون الجدد في مرحلة المباشرة والتجربة.', 'Appointments under approval and new joiners in onboarding or probation.')}
      actions={creator && <><Btn icon={<FileSpreadsheet size={16} />} onClick={() => setImp(true)}>{L('استيراد من Excel', 'Import from Excel')}</Btn><Btn kind="primary" icon={<UserPlus size={16} />} onClick={() => setAdd(true)}>{L('إضافة موظف', 'Add employee')}</Btn></>} />

    {data.pending && <Panel title={L('قرارات تعيين بانتظار الاعتماد', 'Appointments awaiting approval')} sub="F-31" pad={false}>
      {data.pending.length === 0 ? <Empty title={L('لا توجد قرارات معلقة', 'No pending decisions')}>{L('تظهر هنا بعد أن يوقّع المتقدم عرض العمل.', 'They appear here after an applicant signs the offer.')}</Empty> :
        <table className="tbl w-full"><thead><tr><th>{L('المتقدم', 'Applicant')}</th><th className="hidden md:table-cell">{L('رقم القرار', 'Decision')}</th><th>{L('الاعتمادات', 'Approvals')}</th><th className="hidden sm:table-cell" /></tr></thead><tbody>
          {data.pending.map(p => <tr key={p.candidate_id} className="click" onClick={() => navigate(`/hr/candidates/${p.candidate_id}?tab=decision`)}>
            <td><div className="font-medium">{bi(p.name, lang)}</div><div className="text-[12px] text-ink-3">{bi(p.job, lang)}<span className="md:hidden"> · <span className="ltr inline-block">{p.decision_no}</span></span></div></td>
            <td className="hidden md:table-cell ltr num">{p.decision_no}</td>
            <td><div className="flex items-center gap-2 w-28 sm:w-40"><Progress value={p.done / Math.max(1, p.total) * 100} tone="brass" /><span className="num text-[12px]">{p.done}/{p.total}</span></div></td>
            <td className="hidden sm:table-cell">{p.waiting && <Badge tone="brass">{L('بانتظار', 'Pending')}: {bi(p.waiting, lang)}</Badge>}</td>
          </tr>)}
        </tbody></table>}
    </Panel>}

    <Panel title={L('الموظفون الجدد', 'New joiners')} sub="F-19 · F-32 · F-22 · F-20" pad={false}>
      {data.joiners.length === 0 ? <Empty title={L('لا يوجد موظفون جدد حالياً', 'No new joiners')}>{L('يظهر هنا كل من اكتمل قرار تعيينه أو أُضيف من معالج «إضافة موظف».', 'Everyone whose appointment completes, or who is added with the wizard, appears here.')}</Empty> :
        <table className="tbl w-full"><thead><tr><th>{L('الموظف', 'Employee')}</th><th className="hidden md:table-cell">{L('الرقم الوظيفي', 'ID')}</th><th className="hidden lg:table-cell">{L('المباشرة', 'Start')}</th><th>{L('إجراءات F-19', 'F-19 steps')}</th><th className="hidden sm:table-cell">{L('الحالة', 'Status')}</th></tr></thead><tbody>
          {data.joiners.map(e => <tr key={e.id} className="click" onClick={() => navigate(`/hr/onboarding/${e.id}`)}>
            <td><div className="flex items-center gap-2"><Avatar size={28} /><div className="min-w-0"><div className="font-medium">{bi(e.name, lang)}</div>
              <div className="text-[12px] text-ink-3">{bi(e.title, lang)}<span className="md:hidden"> · <span className="ltr inline-block">{e.code}</span></span><span className="sm:hidden"> · <StatusBadge s={e.status} text /></span></div></div></div></td>
            <td className="hidden md:table-cell ltr num">{e.code}</td>
            <td className="hidden lg:table-cell num">{fmtDate(e.hire_date, lang)}</td>
            <td><div className="flex items-center gap-2 w-28 sm:w-44"><Progress value={e.done / e.total * 100} /><span className="num text-[12px]">{e.done}/{e.total}</span></div></td>
            <td className="hidden sm:table-cell"><StatusBadge s={e.status} /></td>
          </tr>)}
        </tbody></table>}
    </Panel>
    {add && <AddEmployee onClose={() => setAdd(false)} />}
    {imp && <ImportEmployees onClose={() => setImp(false)} />}
  </div>
}

export function StatusBadge({ s, text }: { s: EmpStatus; text?: boolean }) {
  const L = useL()
  const [label, tone] = s === 'onboarding' ? [L('قيد المباشرة', 'Onboarding'), 'info' as const] : s === 'probation' ? [L('فترة التجربة', 'Probation'), 'brand' as const] : [L('على رأس العمل', 'Active'), 'neutral' as const]
  return text ? <>{label}</> : <Badge tone={tone}>{label}</Badge>
}
