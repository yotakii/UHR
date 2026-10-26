import { useState } from 'react'
import { Check, Clock, FileText, X } from 'lucide-react'
import { useL, useLang, bi, fmtDate, money } from '../../lib/store'
import { fileUrl } from '../../lib/console'
import { minsLabel } from '../../lib/employees'
import { useDecide, type ReqRow, type Step } from '../../lib/requests'
import { Btn, toast } from '../../ui/kit'
import { DecisionDialog } from '../../ui/decision'
export { LeaveStatus } from '../hr/employees/EmployeeFile'

/** مسار الاعتماد (chain في الديمو): كل مستوى بدوره ومن اعتمد، والنائب، والمتأخر */
export function StepsPath({ steps }: { steps: Step[] }) {
  const L = useL(); const lang = useLang()
  return <ol className="text-[12px] space-y-0.5">{steps.filter(s => s.status !== 'skipped').map(s => {
    const cls = s.status === 'approved' ? 'text-brand' : s.status === 'rejected' ? 'text-danger' : s.status === 'pending' ? 'text-ink font-semibold' : 'text-ink-3'
    return <li key={s.id} className={`flex items-start gap-1 ${cls}`}>
      <span className="mt-0.5 shrink-0">{s.status === 'approved' ? <Check size={12} /> : s.status === 'rejected' ? <X size={12} /> : s.status === 'pending' ? <Clock size={12} /> : <span className="inline-block size-3" />}</span>
      <span>{bi(s.role, lang)}{s.by ? ` — ${bi(s.by, lang)}` : s.owner && s.status === 'pending' ? ` — ${bi(s.owner, lang)}` : ''}
        {s.delegate_for && <span className="text-ink-3"> ({L('نائباً عن', 'deputy for')} {bi(s.delegate_for, lang)})</span>}
        {s.overdue && <span className="ms-1 text-danger">· {L('متأخر', 'overdue')}</span>}
        {s.note && <span className="block text-ink-3 font-normal">«{s.note}»</span>}</span></li>
  })}</ol>
}

/** الفترة/المدة كما في الديمو: ساعات، يوم التبرير ودقائقه، أو من — إلى */
export function Period({ r }: { r: ReqRow }) {
  const L = useL(); const lang = useLang()
  if (r.kind === 'service') return <span className="num">{fmtDate(r.created_at, lang)}{r.amount ? <> — {money(r.amount, r.currency ?? 'USD', lang)}</> : null}
    {r.addressee?.name && <span className="block text-[12px] text-ink-3">{L('إلى', 'To')}: {r.addressee.name}{r.addressee.cr_no ? ` · ${L('سجل', 'CR')} ${r.addressee.cr_no}` : ''}</span>}
    {r.data?.phone && <span className="block text-[12px] text-ink-3">{L('الجوال الجديد', 'New mobile')}: <span className="ltr inline-block">{r.data.phone}</span></span>}
    {r.ref_no && <span className="block text-[12px] text-brand ltr text-start">{r.ref_no}</span>}</span>
  if (r.kind === 'exit') return <span className="num">{L('آخر يوم', 'Last day')}: {fmtDate(r.last_day ?? r.to, lang)}{r.ref_no && <span className="block text-[12px] text-ink-3 ltr text-start">{r.ref_no}</span>}</span>
  if (r.kind === 'internal') return <span>{r.job ? <b>{bi(r.job.title, lang)}</b> : null} <span className="ltr inline-block num text-[12px] text-ink-3">{r.job?.code}</span><span className="block text-[12px] text-ink-3 num">{fmtDate(r.created_at, lang)}</span></span>
  if (r.kind === 'salary') return <span>{r.scope === 'all' ? L('رواتب كل الشركة', 'Whole company salaries') : <>{L('رواتب إدارة', 'Department salaries')}: <b>{r.department ? bi(r.department, lang) : ''}</b></>}
    <span className="block text-[12px] text-ink-3 num">{L(`لمدة ${r.hours} ساعات بعد الموافقة`, `for ${r.hours} hours after approval`)}</span></span>
  if (r.kind === 'penalty') return <span className="num">{L('تاريخ المخالفة', 'Violation date')}: {fmtDate(r.from, lang)}</span>
  if (r.kind === 'overtime') return <span className="num">{fmtDate(r.from, lang)} — {minsLabel(r.minutes ?? 0, lang)}</span>
  if (r.kind === 'justify') return <span className="num">{fmtDate(r.ref_date ?? r.from, lang)}{r.minutes ? <> — {minsLabel(r.minutes, lang)}</> : <> — {r.warnings.issue === 'incomplete' ? L('بلا خروج', 'no out') : L('غياب', 'absent')}</>}</span>
  if (r.hours) return <span className="num">{fmtDate(r.from, lang)} — {r.hours} {L('ساعة', 'h')}</span>
  return <span className="num">{fmtDate(r.from, lang)}{r.to !== r.from && <> — {fmtDate(r.to, lang)}</>}{r.days ? <span className="text-ink-3"> · {r.days} {L('يوم', 'd')}</span> : null}</span>
}

/** تحذيرات يراها المعتمِد: تجاوز الرصيد، تعارض في الإدارة، المستند */
export function Warnings({ r }: { r: Pick<ReqRow, 'warnings'> & Partial<Pick<ReqRow, 'doc_required' | 'doc' | 'status'>> }) {
  const L = useL(); const lang = useLang()
  const w = r.warnings
  const noDoc = r.doc_required && !r.doc && (!r.status || r.status.startsWith('pending'))
  if (!w.over_balance && !w.conflict?.length && !noDoc) return null
  return <div className="mt-1 space-y-0.5 text-[11.5px]">
    {w.over_balance && <div className="text-danger">{L(`يتجاوز الرصيد: ${w.over_balance.days} من ${w.over_balance.remaining} متبقٍ`, `Over balance: ${w.over_balance.days} of ${w.over_balance.remaining} left`)}</div>}
    {w.conflict?.length ? <div className="text-warn">{L('تعارض في الإدارة: ', 'Clash in department: ')}{w.conflict.map(c => bi(c, lang)).join('، ')}</div> : null}
    {noDoc && <div className="text-warn">{L('المستند المطلوب لم يُرفق بعد', 'Required document not attached yet')}</div>}
  </div>
}

export function DocLink({ r }: { r: ReqRow }) {
  return r.doc ? <a href={fileUrl(r.doc.id, true)} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-brand text-[12px] mt-1"><FileText size={12} />{r.doc.name}</a> : null
}

/** اعتماد/رفض بنافذة القرار (تبرير + تأكيد ثانٍ): الرفض بسبب إلزامي، والاعتماد بملاحظة إلزامية عند تجاوز الرصيد أو التعارض */
export function DecideButtons({ r, rejectMin }: { r: ReqRow; rejectMin: number }) {
  const L = useL(); const lang = useLang()
  const decide = useDecide()
  const [d, setD] = useState<'approve' | 'reject' | null>(null)
  if (!r.can_act || !r.step_id) return null
  const pending = r.steps.find(s => s.status === 'pending')
  const last = r.steps.filter(s => s.status === 'waiting').length === 0
  const needsNote = !!r.warnings.over_balance || !!r.warnings.conflict?.length
  return <>
    <div className="flex gap-1.5 justify-end flex-wrap max-sm:flex-col max-sm:items-stretch">
      <Btn size="sm" kind="primary" onClick={() => setD('approve')}>{last ? L('اعتماد نهائي', 'Final approve') : pending ? L(`اعتماد ${pending.role.ar}`, `Approve as ${pending.role.en}`) : L('اعتماد', 'Approve')}</Btn>
      <Btn size="sm" kind="danger" onClick={() => setD('reject')}>{L('رفض', 'Reject')}</Btn>
    </div>
    <DecisionDialog open={!!d} onClose={() => setD(null)} tone={d === 'reject' ? 'danger' : 'primary'}
      title={d === 'reject' ? L('رفض الطلب', 'Reject the request') : L('اعتماد الطلب', 'Approve the request')}
      subject={<>{r.employee ? bi(r.employee.name, lang) : ''} — {bi(r.type_name, lang)} · <Period r={r} /><Warnings r={r} /></>}
      confirmLabel={d === 'reject' ? L('تأكيد الرفض', 'Confirm rejection') : L('تأكيد الاعتماد', 'Confirm approval')}
      reasonRequired={d === 'reject' || needsNote} minLength={d === 'reject' ? rejectMin : 3}
      reasonLabel={d === 'approve' && needsNote ? L('ملاحظة إلزامية — تتحمل بها مسؤولية تجاوز الرصيد أو التعارض', 'Required note — you take responsibility for the balance or the clash') : undefined}
      presets={d === 'reject' ? [L('ضغط عمل في هذه الفترة', 'Workload in this period'), L('لا يوجد بديل مناسب', 'No suitable substitute'), L('المستندات غير كافية', 'Documents are not sufficient')] : undefined}
      onConfirm={note => decide.mutateAsync({ step: r.step_id!, decision: d!, note }).then(() => toast(d === 'reject' ? L('رُفض الطلب', 'Request rejected') : L('اعتُمد الطلب', 'Request approved')))} />
  </>
}
