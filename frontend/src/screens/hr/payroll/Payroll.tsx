import { useState } from 'react'
import { AlertTriangle, Calculator, CheckCircle2, Download, Lock, Loader2, RotateCcw, X } from 'lucide-react'
import { useL, useLang, bi, fmtDate, fmtDateTime, money } from '../../../lib/store'
import { ApiError } from '../../../lib/api'
import { setQuery, useRoute } from '../../../lib/router'
import { minsLabel } from '../../../lib/employees'
import { downloadSheet, useOpenSheet, usePayroll, useSheetAction, type SheetRow } from '../../../lib/payroll'
import { Badge, Btn, Drawer, Empty, PageHead, Panel, toast } from '../../../ui/kit'
import { Lifecycle } from '../../../ui/docs'
import { DecisionDialog } from '../../../ui/decision'

const STEPS = ['draft', 'calculated', 'approved', 'closed'] as const

/**
 * كشف الدوام والمستحقات الشهري (D-01، SRS §10.10): مسودة ← محتسب ← معتمد ← مُغلق.
 * الأيام الناقصة تمنع الإغلاق (التصحيح من سجل الحضور)، الإغلاق يخصم السلف ويُبلغ كل موظف — المبالغ «تقديرية» حتى التكامل مع ZERP.
 */
export function Payroll() {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const [now] = useState(() => new Date().toISOString().slice(0, 7))
  const month = /^\d{4}-\d{2}$/.test(route.query.month ?? '') ? route.query.month! : now
  const q = usePayroll(month); const d = q.data
  const openS = useOpenSheet(); const act = useSheetAction()
  const [dlg, setDlg] = useState<'approve' | 'reopen' | 'close' | null>(null)
  const [row, setRow] = useState<SheetRow | null>(null)
  const s = d?.sheet
  const fail = (e: unknown) => toast(e instanceof ApiError ? e.message : L('تعذّر التنفيذ', 'Could not complete'))
  const months = [...new Set([now, ...(d?.months.map(m => m.month) ?? []), ...[1, 2, 3].map(i => { const x = new Date(now + '-01T12:00'); x.setMonth(x.getMonth() - i); return x.toISOString().slice(0, 7) })])].sort().reverse()
  const st = (m: string) => d?.months.find(x => x.month === m)?.status
  const label = (m: string) => new Date(m + '-01T12:00').toLocaleDateString(lang === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB', { month: 'long', year: 'numeric' })
  const stLabel = { draft: L('مسودة', 'Draft'), calculated: L('محتسب', 'Calculated'), approved: L('معتمد', 'Approved'), closed: L('مُغلق', 'Closed') }
  const idx = s ? STEPS.indexOf(s.status) : -1
  const cur = d?.rows[0]?.money.currency ?? 'USD'
  const T = s?.totals

  return <div className="max-w-[1200px] mx-auto space-y-4">
    <PageHead title={L('الكشف الشهري للدوام والمستحقات', 'Monthly attendance & pay sheet')}
      sub={L('يُحتسب من الحضور والإجازات والتأخير والإضافي المعتمد، ويُعتمد ثم يُغلق. المبالغ تقديرية حتى التكامل مع النظام المالي.', 'Calculated from attendance, leave, lateness and approved overtime, then approved and closed. Amounts are estimates until the finance integration.')}
      actions={<select className="field !h-9 !w-auto" value={month} onChange={e => setQuery({ month: e.target.value })} aria-label={L('الشهر', 'Month')}>
        {months.map(m => <option key={m} value={m}>{label(m)}{st(m) ? ` — ${stLabel[st(m)!]}` : ''}</option>)}</select>} />
    {!d ? <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div> : !s ? <Panel>
      <Empty title={L(`لا يوجد كشف لشهر ${label(month)}`, `No sheet for ${label(month)}`)} action={d.can.edit && <Btn kind="primary" icon={<Calculator size={15} />} disabled={openS.isPending} onClick={() => openS.mutateAsync(month).catch(fail)}>{L('فتح الكشف واحتسابه', 'Open & calculate')}</Btn>}>
        {L('يُحتسب لكل الموظفين الحاليين من سجلات الدوام المسجلة.', 'It is calculated for every current employee from the recorded attendance.')}</Empty></Panel> : <>
      <Panel title={<span className="flex flex-wrap items-center gap-2">{label(s.month)}<Badge tone={s.status === 'closed' ? 'brand' : s.status === 'approved' ? 'info' : 'brass'}>{stLabel[s.status]}</Badge><Badge>{L('تقديري', 'Estimate')}</Badge></span>}
        action={<div className="flex flex-wrap gap-2 justify-end">
          {d.can.export && <Btn size="sm" icon={<Download size={14} />} onClick={() => downloadSheet(s.id, s.month).catch(fail)}>Excel</Btn>}
          {d.can.edit && ['draft', 'calculated'].includes(s.status) && <Btn size="sm" icon={act.isPending ? <Loader2 size={14} className="animate-spin" /> : <Calculator size={14} />} disabled={act.isPending}
            onClick={() => act.mutateAsync({ id: s.id, action: 'calculate' }).then(() => toast(L('أُعيد الاحتساب', 'Recalculated'))).catch(fail)}>{L('إعادة الاحتساب', 'Recalculate')}</Btn>}
          {d.can.decide && s.status === 'calculated' && <Btn size="sm" kind="primary" icon={<CheckCircle2 size={14} />} onClick={() => setDlg('approve')}>{L('اعتماد', 'Approve')}</Btn>}
          {d.can.decide && s.status === 'approved' && <Btn size="sm" icon={<RotateCcw size={14} />} onClick={() => setDlg('reopen')}>{L('إرجاع للاحتساب', 'Send back')}</Btn>}
          {d.can.decide && s.status === 'approved' && <Btn size="sm" kind="primary" icon={<Lock size={14} />} onClick={() => setDlg('close')}>{L('إغلاق الكشف', 'Close sheet')}</Btn>}
        </div>}>
        <Lifecycle steps={STEPS.map((k, i) => ({ label: stLabel[k], done: i <= idx && (k !== 'draft' || idx > 0), current: i === idx + 1 && s.status !== 'closed',
          code: k === 'calculated' ? fmtDateTime(s.calculated.at ?? undefined, lang) : k === 'approved' && s.approved.at ? fmtDateTime(s.approved.at, lang) : k === 'closed' && s.closed.at ? fmtDateTime(s.closed.at, lang) : undefined }))} />
        {s.note && <p className="mt-3 text-[13px] text-ink-2">{L('ملاحظة', 'Note')}: {s.note}</p>}
        {T && <div className="mt-4 grid [grid-template-columns:repeat(auto-fill,minmax(150px,1fr))] gap-3">
          {([[L('الموظفون', 'Employees'), T.employees], [L('الرواتب', 'Salaries'), money(T.base, cur, lang)], [L('أجر الإضافي', 'Overtime pay'), money(T.ot_pay, cur, lang)],
            [L('الاقتطاعات', 'Deductions'), money(T.deductions, cur, lang)], [L('السلف', 'Advances'), money(T.advance, cur, lang)], [L('الصافي', 'Net'), money(T.net, cur, lang)]] as const).map(([k, v]) =>
            <div key={k} className="rounded-xl border border-line px-3 py-2"><div className="text-[12px] text-ink-3">{k}</div><div className="font-bold num text-[16px]">{v}</div></div>)}
        </div>}
      </Panel>
      {d.incomplete.length > 0 && <Panel title={<span className="flex items-center gap-2 text-danger"><AlertTriangle size={16} />{L(`أيام ناقصة بلا خروج (${d.incomplete.length}) — تمنع الإغلاق`, `Incomplete days (${d.incomplete.length}) — they block closing`)}</span>} pad={false}>
        <ul className="divide-y divide-line-2">{d.incomplete.slice(0, 30).map(x => <li key={x.employee_id + x.date} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-2.5 text-[13.5px]">
          <span className="flex-1 min-w-[180px]">{bi(x.name, lang)} <span className="text-ink-3 ltr inline-block">{x.code}</span></span><span className="num">{fmtDate(x.date, lang)}</span>
          {d.can.attendance && <a className="text-brand font-semibold text-[13px]" href={`/hr/attendance?date=${x.date}`}>{L('تصحيح', 'Correct')}</a>}</li>)}</ul>
      </Panel>}
      <Panel pad={false}>{d.rows.length === 0 ? <Empty title={L('لا توجد صفوف', 'No rows')} /> :
        <table className="tbl"><thead><tr><th>{L('الموظف', 'Employee')}</th><th className="hidden sm:table-cell">{L('الحضور', 'Present')}</th><th className="hidden md:table-cell">{L('غياب / إجازات', 'Absent / leave')}</th>
          <th className="hidden lg:table-cell">{L('التأخير والحسم', 'Late & deduction')}</th><th className="hidden lg:table-cell">{L('الإضافي', 'Overtime')}</th><th>{L('الصافي', 'Net')}</th></tr></thead>
          <tbody>{d.rows.map(r => <tr key={r.id} className="cursor-pointer hover:bg-sub" onClick={() => setRow(r)}>
            <td><div className="font-medium">{bi(r.employee.name, lang)}</div><div className="text-[12px] text-ink-3"><span className="ltr inline-block">{r.employee.code}</span>{r.employee.dept ? ` · ${bi(r.employee.dept, lang)}` : ''}</div>
              {r.data.incomplete > 0 && <Badge tone="danger">{L(`${r.data.incomplete} ناقص`, `${r.data.incomplete} incomplete`)}</Badge>}</td>
            <td className="num hidden sm:table-cell">{r.data.present} / {r.data.work_days}</td>
            <td className="num hidden md:table-cell">{r.data.absent ? <span className="text-danger">{r.data.absent}</span> : '—'}{r.data.justified_absent ? <span className="text-ink-3"> +{r.data.justified_absent} {L('مبرر', 'just.')}</span> : ''} / {r.data.leave || '—'}</td>
            <td className="num hidden lg:table-cell">{r.data.late ? <>{minsLabel(r.data.late, lang)} <span className="text-ink-3">→ {minsLabel(Math.round(r.data.deduct), lang)}</span></> : '—'}</td>
            <td className="num hidden lg:table-cell">{r.data.ot_paid ? <span className="text-brand">{minsLabel(r.data.ot_paid, lang)}</span> : '—'}{r.data.ot_raw > r.data.ot_paid ? <span className="text-ink-3 text-[11.5px]"> / {minsLabel(r.data.ot_raw, lang)}</span> : ''}</td>
            <td className="num font-semibold">{money(r.money.net, r.money.currency, lang)}</td></tr>)}</tbody></table>}</Panel>
    </>}
    {row && <Drawer open onClose={() => setRow(null)} width={520}>
      <header className="flex items-center justify-between gap-3 px-5 h-14 border-b border-line-2"><h3 className="font-display font-bold text-[15px] truncate">{bi(row.employee.name, lang)}</h3>
        <button className="p-1.5 rounded-lg hover:bg-sub" onClick={() => setRow(null)} aria-label={L('إغلاق', 'Close')}><X size={18} /></button></header>
      <div className="flex-1 overflow-y-auto p-5"><RowDetail r={row} /></div></Drawer>}
    {s && <DecisionDialog open={!!dlg} onClose={() => setDlg(null)} tone={dlg === 'reopen' ? 'danger' : 'primary'}
      title={dlg === 'approve' ? L('اعتماد الكشف', 'Approve the sheet') : dlg === 'reopen' ? L('إرجاع الكشف للاحتساب', 'Send the sheet back') : L('إغلاق الكشف', 'Close the sheet')}
      subject={<>{label(s.month)} — {T ? money(T.net, cur, lang) : ''}{dlg === 'close' && <div className="text-[12.5px] text-ink-3 mt-1">{L('بعد الإغلاق لا تعديل، وتُخصم السلف ويُبلغ كل موظف بكشفه.', 'After closing nothing changes; advances are deducted and every employee is notified.')}</div>}</>}
      confirmLabel={dlg === 'approve' ? L('تأكيد الاعتماد', 'Confirm approval') : dlg === 'reopen' ? L('تأكيد الإرجاع', 'Confirm') : L('تأكيد الإغلاق', 'Confirm closing')}
      reasonRequired={dlg === 'reopen'}
      onConfirm={note => act.mutateAsync({ id: s.id, action: dlg!, note }).then(() => toast(L('تم', 'Done')))} />}
  </div>
}

function RowDetail({ r }: { r: SheetRow }) {
  const L = useL(); const lang = useLang()
  const t = r.data, m = r.money, c = m.currency
  const line = (k: string, v: string | number, tone = '') => <div className="flex justify-between gap-3 py-1.5 border-b border-line-2 text-[13.5px]"><span className="text-ink-2">{k}</span><b className={`num ${tone}`}>{v}</b></div>
  return <div className="space-y-4">
    <div>
      <div className="text-[12px] font-semibold text-ink-3 mb-1">{L('الدوام', 'Attendance')} <span className="num">({fmtDate(t.from, lang)} — {fmtDate(t.to, lang)})</span></div>
      {line(L('أيام العمل / الحضور', 'Work days / present'), `${t.work_days} / ${t.present}`)}{line(L('غياب غير مبرر', 'Unjustified absence'), t.absent, t.absent ? 'text-danger' : '')}
      {line(L('غياب مبرر', 'Justified absence'), t.justified_absent)}{line(L('أيام ناقصة', 'Incomplete days'), t.incomplete, t.incomplete ? 'text-danger' : '')}
      {line(L('إجازات (منها بلا أجر)', 'Leave (unpaid)'), `${t.leave} (${t.leave_unpaid})`)}{line(L('التأخير', 'Lateness'), `${minsLabel(t.late, lang)} · ${t.late_days} ${L('يوم', 'd')}`)}
      {line(L('معفى بمبرر', 'Exempted (justified)'), `${t.exempted} × ${minsLabel(t.exempt_min, lang)}`)}{line(L('دقائق الحسم', 'Deduction minutes'), minsLabel(Math.round(t.deduct), lang))}
      {line(L('الإضافي المسجل / المكلّف / المعتمد', 'OT recorded / assigned / paid'), `${t.ot_raw} / ${t.ot_assigned} / ${t.ot_paid}`)}
    </div>
    <div>
      <div className="text-[12px] font-semibold text-ink-3 mb-1">{L('المبالغ (تقديرية)', 'Amounts (estimate)')} <span className="num">· {L('الأجر اليومي', 'day')} {money(m.day, c, lang)} · {L('الساعة', 'hour')} {money(m.hour, c, lang)}</span></div>
      {line(L('الراتب', 'Salary'), money(m.base, c, lang))}{line(L('أجر الإضافي', 'Overtime pay'), money(m.ot_pay, c, lang), 'text-brand')}{!!m.bonus && line(L('مكافأة التقييم', 'Performance reward'), money(m.bonus, c, lang), 'text-brand')}
      {line(L('حسم التأخير', 'Lateness'), money(m.late, c, lang), 'text-danger')}{line(L('حسم الغياب', 'Absence'), money(m.absent, c, lang), 'text-danger')}
      {line(L('إجازات بلا أجر', 'Unpaid leave'), money(m.leave, c, lang), 'text-danger')}{line(L('التأمينات', 'Insurance'), money(m.insurance, c, lang), 'text-danger')}
      {!!m.discipline && line(L(`جزاءات المخالفات (${t.discipline_days ?? 0} يوم)`, `Disciplinary deductions (${t.discipline_days ?? 0} d)`), money(m.discipline, c, lang), 'text-danger')}
      {line(L('السلفة', 'Advance'), money(m.advance, c, lang), 'text-danger')}
      <div className="flex justify-between pt-3 text-[16px] font-bold"><span>{L('الصافي', 'Net')}</span><span className="num text-brand">{money(m.net, c, lang)}</span></div>
    </div>
    {t.incomplete_dates.length > 0 && <div className="text-[12.5px] text-danger">{L('أيام ناقصة: ', 'Incomplete: ')}<span className="num">{t.incomplete_dates.join('، ')}</span></div>}
  </div>
}
