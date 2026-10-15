import { useState } from 'react'
import { Check, X, Loader2, ClipboardCheck } from 'lucide-react'
import { useL, useLang, bi, money, fmtDate } from '../../../lib/store'
import { useDecideApproval, useMyApprovals, type HrAppointment } from '../../../lib/candidates'
import { Badge, Btn, Empty, KV, PageHead, Panel, toast } from '../../../ui/kit'
import { DecisionDoc } from '../../../ui/docs'
import { DecisionDialog } from '../../../ui/decision'

/** أزرار قرار المستوى الحالي — تظهر لصاحبه فقط (الخادم يتحقق أيضاً) */
export function ApprovalActions({ ap, who }: { ap: HrAppointment; who: string }) {
  const L = useL(); const lang = useLang()
  const decide = useDecideApproval()
  const [d, setD] = useState<'approved' | 'rejected' | null>(null)
  if (ap.complete) return <Badge tone="brand">{L('اكتمل الاعتماد', 'Fully approved')}</Badge>
  if (ap.rejected) return <Badge tone="danger">{L('رُفض القرار', 'Decision rejected')}</Badge>
  if (!ap.current) return null
  if (!ap.current.mine) return <Badge tone="brass">{L('بانتظار', 'Awaiting')} {bi(ap.current.role, lang)}</Badge>
  return <>
    <Btn size="sm" kind="danger" icon={<X size={14} />} onClick={() => setD('rejected')}>{L('رفض', 'Reject')}</Btn>
    <Btn size="sm" kind="primary" icon={<Check size={14} />} onClick={() => setD('approved')}>{L('اعتماد', 'Approve')} ({bi(ap.current.role, lang)})</Btn>
    <DecisionDialog open={!!d} onClose={() => setD(null)} tone={d === 'rejected' ? 'danger' : 'primary'} reasonRequired={d === 'rejected'}
      title={d === 'rejected' ? L('رفض قرار التعيين', 'Reject the appointment') : L('اعتماد قرار التعيين', 'Approve the appointment')}
      subject={<><span className="font-semibold">{who}</span> · <span className="ltr inline-block">{ap.decision_no}</span><div className="text-[13px] text-ink-2 mt-1">{bi(ap.current.role, lang)}</div></>}
      confirmLabel={d === 'rejected' ? L('تأكيد الرفض', 'Confirm rejection') : L('تأكيد الاعتماد', 'Confirm approval')}
      onConfirm={reason => decide.mutateAsync({ id: ap.current!.id, decision: d!, reason: reason || undefined }).then(r =>
        toast(r.complete ? L('اكتمل الاعتماد وأُنشئ ملف الموظف', 'Approved — employee file created') : d === 'rejected' ? L('رُفض القرار وأُغلق الطلب', 'Rejected — application closed') : L('اعتُمد — انتقل للمستوى التالي', 'Approved — moved to the next level')))} />
  </>
}

/** «اعتماداتي»: قرارات التعيين F-31 التي دورها عندك الآن — لكل أصحاب المستويات (المالية، التنفيذي…) */
export function MyApprovals() {
  const L = useL(); const lang = useLang()
  const { data, isLoading } = useMyApprovals()
  return <div className="max-w-[1100px] mx-auto space-y-5">
    <PageHead title={L('اعتماداتي', 'My approvals')} sub={L('قرارات التعيين التي تنتظر توقيعك. كل مستوى يُعتمد من حساب صاحبه، بالترتيب.', 'Appointment decisions waiting for your signature. Each level is approved from its owner’s account, in order.')} />
    {isLoading ? <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
      : !data?.length ? <div className="bg-surface border border-line rounded-2xl"><Empty title={<span className="inline-flex items-center gap-2"><ClipboardCheck size={18} className="text-brand" />{L('لا شيء بانتظار اعتمادك', 'Nothing awaits your approval')}</span>}>{L('ستصلك إشعار عندما يصل قرار جديد لمستواك.', 'You will be notified when a new decision reaches your level.')}</Empty></div>
      : data.map(it => <Panel key={it.appointment.id} title={<span className="flex flex-wrap items-center gap-2">{bi(it.candidate.name, lang)}<span className="text-ink-3 text-[13px] font-normal">{bi(it.job.title, lang)} · {bi(it.job.dept, lang)}</span></span>}
          sub={<span className="ltr inline-block">{it.appointment.decision_no}</span>} action={<div className="flex flex-wrap gap-2"><ApprovalActions ap={it.appointment} who={bi(it.candidate.name, lang)} /></div>}>
          <DecisionDoc c={{ name: it.candidate.name_ar }} ap={it.appointment} jobTitle={bi(it.job.title, lang)} deptName={bi(it.job.dept, lang)} />
          <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-x-6 mt-4">
            {it.offer && <KV k={L('إجمالي الراتب الشهري', 'Monthly package')} v={<span className="num">{money(it.offer.total, it.offer.currency, lang)}</span>} />}
            {it.offer && <KV k={L('تاريخ المباشرة', 'Start date')} v={fmtDate(it.offer.start_date, lang)} />}
            <KV k={L('الملاك العددي / الموجود', 'Headcount / current')} v={<span className="num">{it.appointment.headcount.approved || '—'} / {it.appointment.headcount.current}</span>} />
            <KV k={L('مطابقة الشهادة', 'Degree check')} v={it.appointment.degree_ok ? L('مطابق', 'Matches') : <span className="text-danger">{L('أدنى من المطلوب', 'Below requirement')}</span>} />
          </div>
        </Panel>)}
  </div>
}
