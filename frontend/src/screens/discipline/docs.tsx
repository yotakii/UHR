import { useL, useLang, bi, fmtDate } from '../../lib/store'
import type { Bi } from '../../lib/types'
import type { CaseRow, CaseStatus, ComplaintRow } from '../../lib/discipline'
import { Badge } from '../../ui/kit'
import { Paper, SigBox } from '../../ui/docs'

export function CaseStatusBadge({ s, daysLeft }: { s: CaseStatus; daysLeft?: number | null }) {
  const L = useL()
  if (s === 'open' || s === 'investigated') {
    const late = daysLeft !== null && daysLeft !== undefined && daysLeft <= 3
    return <span className="inline-flex flex-wrap items-center gap-1"><Badge tone={s === 'open' ? 'brass' : 'info'}>{s === 'open' ? L('بانتظار التحقيق', 'Awaiting investigation') : L('بانتظار القرار', 'Awaiting decision')}</Badge>
      {daysLeft !== null && daysLeft !== undefined && <span className={`text-[12px] num ${late ? 'text-danger font-semibold' : 'text-ink-3'}`}>{L(`${daysLeft} يوم للمهلة`, `${daysLeft}d left`)}</span>}</span>
  }
  return s === 'pending_gm' ? <Badge tone="warn">{L('بانتظار المدير العام', 'Awaiting the GM')}</Badge> : s === 'decided' ? <Badge tone="danger">{L('صدر القرار', 'Decided')}</Badge>
    : s === 'dismissed' ? <Badge tone="brand">{L('حُفظت بلا جزاء', 'Closed, no penalty')}</Badge> : <Badge>{L('انقضت المهلة', 'Expired')}</Badge>
}

export function ComplaintBadge({ s }: { s: ComplaintRow['status'] }) {
  const L = useL()
  return s === 'received' ? <Badge tone="brass">{L('مستلمة', 'Received')}</Badge> : s === 'reviewing' ? <Badge tone="info">{L('قيد الدراسة', 'Under review')}</Badge> : <Badge tone="brand">{L('مغلقة', 'Closed')}</Badge>
}

/** الإنذار الكتابي HR.01-01-F-01 — نص نموذج الشركة، مع إقرار استلام الموظف بتوقيعه */
export function WarningDoc({ c, company }: { c: CaseRow; company: Bi }) {
  const L = useL(); const lang = useLang()
  const e = c.employee
  return <Paper code="HR.01-01-F-01" title={L('إنذار كتابي', 'Written warning')} subject={c.ref_no}>
    <div className="flex flex-wrap justify-between gap-2 text-[13px] text-ink-2 mb-5"><span>{L('الرقم', 'No.')}: <b className="ltr inline-block text-ink">{c.ref_no}</b></span>
      <span>{L('التاريخ', 'Date')}: <b className="num text-ink">{fmtDate(c.decided_at ?? c.created_at, lang)}</b></span></div>
    <ul className="list-disc ps-5 space-y-1.5 text-[15px] leading-8">
      <li>{L('السيد/ة', 'Mr/Ms')} <b>{e ? bi(e.name, lang) : ''}</b> {L('الموظف في قسم', 'of the department of')} <b>{e?.dept ? bi(e.dept, lang) : '—'}</b> {e?.title ? <span className="text-ink-2">({bi(e.title, lang)})</span> : null}</li>
      <li>{L('الإنذار بتاريخ', 'Warning date')} <b className="num">{fmtDate(c.decided_at ?? c.created_at, lang)}</b></li>
    </ul>
    <p className="mt-4 text-[15px] font-semibold">{L('وذلك للأسباب التالية:', 'For the following reasons:')}</p>
    <div className="mt-2 rounded-lg bg-sub px-4 py-3 text-[14.5px] leading-8">
      {c.type ? bi(c.type.title, lang) : ''}{c.type?.article ? <span className="text-ink-3"> — {L('المادة', 'Art.')} <bdi>{c.type.article}</bdi></span> : null}
      <div className="text-[13.5px] text-ink-2">{L('بتاريخ', 'On')} <span className="num">{fmtDate(c.occurred_on, lang)}</span> · {L(`المرة ${c.occurrence}`, `occurrence ${c.occurrence}`)} — {c.description}</div>
    </div>
    <p className="mt-4 text-[15px] leading-9">{L('نظراً لارتكابكم المخالفات السابقة، وحيث أن النظام الأساسي يحدد بشكل واضح العقوبة المرتبطة بهذه المخالفات فإن إدارة الشركة توجه لكم هذا الإنذار الكتابي والذي سوف يُحتفظ بنسخة منه في ملفكم الخاص، علماً أن لإدارة الشركة الحق في توجيه عقوبات أشد في حال حدوث أو تكرار مخالفات أخرى.',
      'Given the violations above, and since the company regulations clearly set the penalty for them, the management issues you this written warning, a copy of which will be kept in your file. The management may impose heavier penalties if further violations occur or are repeated.')}</p>
    <p className="mt-2 text-[15px] leading-9">{L('نأمل عدم تكرار مثل هذه المخالفات مستقبلاً لما له من أثر سلبي على مسيرة العمل في الشركة وعلى مسيرة التطور الشخصي للموظف.', 'We hope such violations will not recur, given their negative effect on the company’s work and on the employee’s own development.')}</p>
    <p className="mt-3 text-[13px] text-ink-3">{L('ملاحظة: نسخة إلى ملف الموظف.', 'Note: copy to the employee file.')} {bi(company, lang)}</p>
    <div className="mt-8 grid grid-cols-3 gap-3 text-center text-[13px]">
      <div className="border-t border-line pt-2">{L('مدير القسم', 'Department manager')}</div>
      <div className="border-t border-line pt-2">{L('الموارد البشرية', 'Human Resources')}{c.decided_by ? <div className="text-ink-2">{bi(c.decided_by, lang)}</div> : null}</div>
      <div className="border-t border-line pt-2">{L('المدير العام', 'General manager')}</div>
    </div>
    <div className="mt-6 max-w-sm"><SigBox label={L('إقرار استلام الموظف — التوقيع', 'Employee acknowledgement — signature')} name={e ? bi(e.name, lang) : undefined} sig={c.signature ?? undefined} at={c.acknowledged_at ?? undefined} /></div>
  </Paper>
}

/** نموذج شكوى موظف HR.01-01-F-02 */
export function ComplaintDoc({ c, who }: { c: ComplaintRow; who: { name: Bi; title?: Bi; dept?: Bi | null } }) {
  const L = useL(); const lang = useLang()
  const row = (k: string, v: React.ReactNode) => <div className="grid grid-cols-[130px_1fr] gap-3 py-2 border-b border-line-2 text-[14.5px]"><span className="text-ink-3">{k}</span><span>{v}</span></div>
  return <Paper code="HR.01-01-F-02" title={L('نموذج شكوى موظف', 'Employee complaint form')} subject={c.ref_no}>
    {row(L('الرقم', 'No.'), <span className="ltr inline-block">{c.ref_no}</span>)}
    {row(L('اسم الموظف الثلاثي', 'Employee full name'), bi(who.name, lang))}
    {row(L('المنصب الوظيفي', 'Position'), who.title ? bi(who.title, lang) : '—')}
    {row(L('مكان العمل', 'Workplace'), who.dept ? bi(who.dept, lang) : '—')}
    {row(L('الموضوع', 'Subject'), c.subject)}
    {c.against && row(L('بحق', 'Against'), c.against)}
    <div className="py-3 text-[14.5px]"><div className="text-ink-3 mb-1">{L('وصف الشكوى', 'Description')}</div><p className="leading-8 whitespace-pre-wrap">{c.body}</p></div>
    {row(L('تاريخ الشكوى', 'Date'), <span className="num">{fmtDate(c.created_at, lang)}</span>)}
    {c.response && <div className="mt-4 rounded-lg bg-sub px-4 py-3 text-[14px]"><div className="font-semibold mb-1">{L('رد الموارد البشرية', 'HR reply')}{c.responded_by ? ` — ${bi(c.responded_by, lang)}` : ''}</div><p className="leading-7 whitespace-pre-wrap">{c.response}</p></div>}
  </Paper>
}
