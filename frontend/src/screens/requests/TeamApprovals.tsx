import { useState } from 'react'
import { Printer } from 'lucide-react'
import { useL, useLang, bi, fmtDateTime, todayISO, addDays } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { useAssignOvertime, useInbox, useSetDelegate, type ReqRow } from '../../lib/requests'
import { Btn, Empty, Field, PageHead, Panel, toast } from '../../ui/kit'
import { DecideButtons, DocLink, LeaveStatus, Period, StepsPath, Warnings } from './parts'

/** جدول طلبات المسار الموحد (Leaves في الديمو): الموظف، النوع، الفترة، السبب، المسار، الحالة، القرار */
export function RequestTable({ rows, rejectMin, empty, certHref }: { rows: ReqRow[]; rejectMin: number; empty: string; certHref?: (id: number) => string }) {
  const L = useL(); const lang = useLang()
  if (!rows.length) return <Empty title={empty}>{L('الطلبات الجديدة تظهر هنا.', 'New requests appear here.')}</Empty>
  return <table className="tbl"><thead><tr><th className="hidden sm:table-cell">{L('الموظف', 'Employee')}</th><th>{L('الطلب', 'Request')}</th><th className="hidden lg:table-cell">{L('السبب', 'Reason')}</th>
    <th className="hidden md:table-cell">{L('مسار الاعتماد', 'Approval path')}</th><th className="hidden sm:table-cell">{L('الحالة', 'Status')}</th><th /></tr></thead>
    <tbody>{rows.map(r => <tr key={`${r.kind}${r.id}`}>
      <td className="hidden sm:table-cell"><div className="font-medium">{r.employee ? bi(r.employee.name, lang) : '—'}</div><div className="text-[12px] text-ink-3">{fmtDateTime(r.created_at, lang)}</div></td>
      <td><div className="sm:hidden font-semibold">{r.employee ? bi(r.employee.name, lang) : '—'}</div><div>{bi(r.type_name, lang)}</div><div className="text-[12.5px]"><Period r={r} /></div>
        {r.substitute && <div className="text-[12px] text-ink-3">{L('البديل', 'Substitute')}: {bi(r.substitute, lang)}</div>}
        {r.requested_by && r.kind === 'overtime' && <div className="text-[12px] text-ink-3">{L('بتكليف', 'Assigned by')}: {bi(r.requested_by, lang)}</div>}
        {r.kind === 'penalty' && r.case && r.penalty && <div className="text-[12.5px]"><b>{bi(r.penalty.label, lang)}</b> · <span className="ltr inline-block num text-ink-3">{r.case.ref_no}</span> · {L(`المرة ${r.case.occurrence}`, `occurrence ${r.case.occurrence}`)}
          {r.case.type && <div className="text-[12px] text-ink-3 max-w-[300px]">{bi(r.case.type, lang)}</div>}{r.requested_by && <div className="text-[12px] text-ink-3">{L('اقتراح', 'Proposed by')}: {bi(r.requested_by, lang)}</div>}</div>}
        <div className="lg:hidden text-[12px] text-ink-2 max-w-[260px] break-words">{r.reason}</div>
        <Warnings r={r} /><DocLink r={r} /><div className="md:hidden mt-1"><StepsPath steps={r.steps} /></div><div className="sm:hidden mt-1"><LeaveStatus s={r.status} /></div></td>
      <td className="hidden lg:table-cell text-ink-2 max-w-[220px]">{r.reason}</td>
      <td className="hidden md:table-cell"><StepsPath steps={r.steps} /></td>
      <td className="hidden sm:table-cell"><LeaveStatus s={r.status} /></td>
      <td><DecideButtons r={r} rejectMin={rejectMin} />{certHref && r.ref_no && <a href={certHref(r.id)} className="inline-flex items-center gap-1 text-brand text-[12.5px] font-semibold"><Printer size={13} />{L('الشهادة', 'Certificate')}</a>}</td>
    </tr>)}</tbody></table>
}

/**
 * «اعتماداتي» في المسار الموحد (SRS §10.1): ما ينتظر قرارك من فريقك أو بصفتك (الموارد البشرية/الإدارة العليا)،
 * ونائبك أثناء إجازتك، وتكليف فريقك بالعمل الإضافي HR.01-01-F-04.
 */
export function TeamApprovals({ head = true }: { head?: boolean }) {
  const L = useL(); const lang = useLang()
  const q = useInbox(); const d = q.data
  const setDelegate = useSetDelegate(); const assign = useAssignOvertime()
  const [f, setF] = useState({ employee_id: 0, date: addDays(todayISO(), 1), minutes: 60, reason: '' })
  const [err, setErr] = useState<Record<string, string>>({})
  const submit = () => {
    setErr({})
    assign.mutateAsync(f).then(() => { toast(L('أُرسل التكليف للموارد البشرية', 'Assignment sent to HR')); setF(x => ({ ...x, reason: '' })) })
      .catch(e => e instanceof ApiError && setErr(Object.keys(e.errors).length ? Object.fromEntries(Object.entries(e.errors).map(([k, m]) => [k, m[0]])) : { reason: e.message }))
  }
  return <div className="max-w-[1200px] mx-auto space-y-4">
    {head && <PageHead title={L('اعتماداتي', 'My approvals')} sub={L('الطلبات التي تنتظر قرارك. الرفض يحتاج سبباً، وكل قرار بتأكيد ثانٍ.', 'Requests waiting for your decision. Rejection needs a reason; every decision is confirmed twice.')} />}
    <Panel title={L('بانتظار قرارك', 'Waiting for you')} pad={false}>
      {d ? <RequestTable rows={d.data} rejectMin={d.reject_min} empty={L('لا شيء بانتظار قرارك', 'Nothing waiting for your decision')} /> : <Empty title={L('جارٍ التحميل…', 'Loading…')} />}
    </Panel>
    {d && d.team.length > 0 && <div className="grid lg:grid-cols-[380px_1fr] gap-4 items-start">
      <div className="space-y-4">
        <Panel title={L('نائبي أثناء إجازتي', 'My deputy while on leave')} sub={L('يستلم طلبات فريقك حين تكون في إجازة', 'Receives your team’s requests while you are on leave')}>
          <select className="field" value={d.delegate?.id ?? ''} onChange={x => setDelegate.mutateAsync(x.target.value ? Number(x.target.value) : null).then(() => toast(L('حُفظ النائب', 'Deputy saved')))}>
            <option value="">{L('— بلا نائب (تتخطاك الطلبات للموارد البشرية) —', '— none (requests skip to HR) —')}</option>
            {d.delegates.map(x => <option key={x.id} value={x.id}>{bi(x, lang)}</option>)}</select>
        </Panel>
        <Panel title={L('تكليف بعمل إضافي', 'Overtime assignment')} sub="HR.01-01-F-04">
          <div className="space-y-3">
            <Field label={L('الموظف', 'Employee')} req error={err.employee_id}><select className="field" value={f.employee_id || ''} onChange={x => setF({ ...f, employee_id: Number(x.target.value) })}>
              <option value="">{L('— اختر —', '— choose —')}</option>{d.team.map(x => <option key={x.id} value={x.id}>{bi(x, lang)}</option>)}</select></Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label={L('التاريخ', 'Date')} req error={err.date}><input type="date" className="field" min={todayISO()} value={f.date} onChange={x => setF({ ...f, date: x.target.value })} /></Field>
              <Field label={L('المدة (دقيقة)', 'Minutes')} req error={err.minutes} hint={L(`حتى ${d.overtime_max} د يومياً`, `up to ${d.overtime_max} min a day`)}>
                <input type="number" min={15} step={15} max={d.overtime_max} className="field num" value={f.minutes} onChange={x => setF({ ...f, minutes: Number(x.target.value) })} /></Field>
            </div>
            <Field label={L('المهمة / السبب', 'Task / reason')} req error={err.reason}><textarea className="field" value={f.reason} maxLength={500} onChange={x => setF({ ...f, reason: x.target.value })} /></Field>
            <Btn kind="primary" className="w-full" disabled={assign.isPending || !f.employee_id} onClick={submit}>{L('إرسال التكليف', 'Send assignment')}</Btn>
          </div>
        </Panel>
      </div>
      <Panel title={L('تكليفاتي بالإضافي', 'My overtime assignments')} pad={false}>
        <RequestTable rows={d.assigned} rejectMin={d.reject_min} empty={L('لا توجد تكليفات', 'No assignments')} /></Panel>
    </div>}
  </div>
}
