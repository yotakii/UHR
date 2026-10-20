import { useState } from 'react'
import { Download, Loader2, Pencil, Nfc, Settings2 } from 'lucide-react'
import { useL, useLang, bi } from '../../../lib/store'
import { navigate, setQuery, useRoute } from '../../../lib/router'
import { minsLabel } from '../../../lib/employees'
import { periodExportUrl, useCorrect, useDaily, usePeriod, type AttRow, type AttStatus, type Person } from '../../../lib/attendance'
import { ApiError } from '../../../lib/api'
import { Badge, Btn, Empty, Field, Modal, PageHead, Panel, Tabs, toast } from '../../../ui/kit'

const today = () => new Date().toLocaleDateString('en-CA')
const minus = (d: string, n: number) => { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() - n); return x.toLocaleDateString('en-CA') }

export function AttBadge({ s }: { s: AttStatus }) {
  const L = useL()
  return {
    before_start: <span className="text-ink-3 text-[12px]">{L('قبل المباشرة', 'Before start')}</span>, leave: <Badge>{L('إجازة', 'Leave')}</Badge>, holiday: <Badge>{L('عطلة رسمية', 'Holiday')}</Badge>,
    weekend: <Badge>{L('عطلة', 'Weekend')}</Badge>, not_in: <Badge tone="warn">{L('لم يسجل', 'Not in')}</Badge>, absent: <Badge tone="danger">{L('غياب', 'Absent')}</Badge>,
    incomplete: <Badge tone="danger">{L('ناقص — بلا خروج', 'Incomplete — no out')}</Badge>, justified: <Badge tone="brand">{L('تأخير مبرر', 'Justified')}</Badge>,
    late: <Badge tone="warn">{L('متأخر', 'Late')}</Badge>, present: <Badge tone="brand">{L('حاضر', 'Present')}</Badge>,
  }[s]
}

/** M08 — الحضور والدوام (Attendance في الديمو): السجل اليومي وملخص الفترة، حسب سياسة الدوام النافذة لكل يوم */
export function Attendance() {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const view = route.query.view === 'period' ? 'period' : 'day'
  const date = route.query.date ?? today(); const dept = route.query.dept ?? ''
  const to = route.query.to ?? today(); const from = route.query.from ?? minus(to, 13)
  const day = useDaily(date, dept)
  const period = usePeriod(from, to, dept, view === 'period')
  const meta = (view === 'day' ? day.data : period.data) ?? day.data
  const [fix, setFix] = useState<{ p: Person; r: AttRow } | null>(null)
  const set = (q: Record<string, string | undefined>) => setQuery({ ...route.query, ...q })

  return <div className="max-w-[1280px] mx-auto">
    <PageHead title={L('الحضور والدوام', 'Attendance')} sub={L('حركات بطاقات NFC محسوبة وفق سياسة الدوام النافذة: الوردية، جدول حسم التأخير، والإضافي.', 'NFC taps calculated with the policy in force: shift, late-deduction table and overtime.')}
      actions={<>
        {meta?.can.policies && <Btn icon={<Settings2 size={15} />} onClick={() => navigate('/hr/settings/policies')}>{L('سياسة الدوام', 'Work policy')}</Btn>}
        {meta?.can.devices && <Btn icon={<Nfc size={15} />} onClick={() => navigate('/hr/settings/devices')}>{L('أجهزة الحضور', 'Attendance devices')}</Btn>}
      </>} />
    <Tabs value={view} onChange={v => set({ view: v === 'day' ? undefined : v })} tabs={[{ v: 'day', label: L('السجل اليومي', 'Daily log') }, { v: 'period', label: L('ملخص الفترة', 'Period summary') }]} />
    <div className="flex flex-wrap gap-2 my-4">
      <select className="field !w-full sm:!w-48" value={dept} onChange={e => set({ dept: e.target.value || undefined })} aria-label={L('الإدارة', 'Department')}>
        <option value="">{L('كل الإدارات', 'All departments')}</option>{meta?.depts.map(d => <option key={d.id} value={d.id}>{bi(d, lang)}</option>)}</select>
      {view === 'day' ? <input type="date" className="field !w-full sm:!w-44" value={date} max={today()} onChange={e => set({ date: e.target.value || undefined })} aria-label={L('التاريخ', 'Date')} />
        : <><input type="date" className="field !w-[calc(50%-4px)] sm:!w-44" value={from} max={to} onChange={e => set({ from: e.target.value || undefined })} aria-label={L('من', 'From')} />
          <input type="date" className="field !w-[calc(50%-4px)] sm:!w-44" value={to} max={today()} onChange={e => set({ to: e.target.value || undefined })} aria-label={L('إلى', 'To')} />
          {meta?.can.export && <a href={periodExportUrl(from, to, dept)} className="inline-flex items-center gap-2 h-10 px-4 rounded-lg border border-line bg-surface font-semibold text-[14px] hover:bg-sub"><Download size={15} />{L('تصدير Excel', 'Export Excel')}</a>}</>}
    </div>

    {view === 'day' ? (!day.data ? <Spin /> : <>
      <div className="grid grid-cols-2 md:grid-cols-5 gap-px bg-line border border-line rounded-lg overflow-hidden mb-4">
        {([[L('حاضرون', 'Present'), day.data.counts.present, 'text-brand'], [L('متأخرون', 'Late'), day.data.counts.late, 'text-warn'], [L('غائبون', 'Absent'), day.data.counts.absent, 'text-danger'],
          [L('ناقص', 'Incomplete'), day.data.counts.incomplete, 'text-danger'], [L('إجازات', 'On leave'), day.data.counts.leave, 'text-ink-2']] as const).map(([k, v, c]) =>
          <div key={k} className="bg-surface px-4 py-3"><div className="text-[12px] text-ink-3">{k}</div><div className={`text-[22px] font-bold num ${c}`}>{v}</div></div>)}
      </div>
      <Panel pad={false}>{day.data.rows.length === 0 ? <Empty title={L('لا يوجد موظفون في هذا اليوم', 'No employees for this day')} /> : <table className="tbl">
        <thead><tr><th>{L('الموظف', 'Employee')}</th><th className="hidden lg:table-cell">{L('الإدارة', 'Dept')}</th><th>{L('الدخول', 'In')}<span className="sm:hidden"> / {L('الخروج', 'Out')}</span></th><th className="hidden sm:table-cell">{L('الخروج', 'Out')}</th>
          <th className="hidden md:table-cell">{L('العمل الفعلي', 'Worked')}</th><th className="hidden md:table-cell">{L('تأخير', 'Late')}</th><th className="hidden xl:table-cell">{L('مغادرة مبكرة', 'Early')}</th><th className="hidden lg:table-cell">{L('إضافي', 'OT')}</th><th>{L('الحالة', 'Status')}</th>{day.data.can.edit && <th />}</tr></thead>
        <tbody>{day.data.rows.map(r => <tr key={r.employee.id} className="click" onClick={() => navigate(`/hr/employees/${r.employee.id}?tab=attendance`)}>
          <td><div className="font-medium">{bi(r.employee.name, lang)}</div><div className="text-[12px] text-ink-3 lg:hidden">{r.employee.dept ? bi(r.employee.dept, lang) : ''}</div></td>
          <td className="text-ink-2 text-[13px] hidden lg:table-cell">{r.employee.dept ? bi(r.employee.dept, lang) : ''}</td>
          <td className="num ltr">{r.in ?? '—'}<div className="sm:hidden text-ink-3">{r.out ?? (r.on_shift ? L('بالدوام', 'on shift') : '—')}</div></td>
          <td className="num ltr hidden sm:table-cell">{r.out ?? (r.on_shift ? <span className="text-ink-3">{L('بالدوام', 'on shift')}</span> : '—')}</td>
          <td className="num hidden md:table-cell">{r.worked ? minsLabel(r.worked, lang) : '—'}</td>
          <td className="num hidden md:table-cell">{r.late ? <span className="text-warn" title={r.deduct ? L(`حسم ${r.deduct} د`, `Deduct ${r.deduct} min`) : undefined}>{minsLabel(r.late, lang)}</span> : '—'}</td>
          <td className="num hidden xl:table-cell">{r.early ? minsLabel(r.early, lang) : '—'}</td>
          <td className="num hidden lg:table-cell">{r.ot ? <span className="text-brand">{minsLabel(r.ot, lang)}</span> : '—'}</td>
          <td><AttBadge s={r.status} />{r.corrected && <div className="text-[11px] text-ink-3 mt-0.5">{L('مصحّح يدوياً', 'Corrected')}</div>}</td>
          {day.data.can.edit && <td onClick={e => e.stopPropagation()}>{r.status !== 'before_start' && <button onClick={() => setFix({ p: r.employee, r })} className="p-1.5 rounded hover:bg-sub text-ink-3" aria-label={L('تصحيح', 'Correct')} title={L('تصحيح يدوي', 'Manual correction')}><Pencil size={15} /></button>}</td>}
        </tr>)}</tbody></table>}</Panel>
    </>) : (!period.data ? <Spin /> : <Panel pad={false}>{period.data.rows.length === 0 ? <Empty title={L('لا توجد بيانات', 'No data')} /> : <table className="tbl">
      <thead><tr><th>{L('الموظف', 'Employee')}</th><th>{L('أيام الحضور', 'Days present')}</th><th className="hidden sm:table-cell">{L('الغياب', 'Absences')}</th><th className="hidden md:table-cell">{L('ناقص', 'Incomplete')}</th>
        <th>{L('مجموع التأخير', 'Total late')}</th><th className="hidden lg:table-cell">{L('دقائق الحسم', 'Deduction')}</th><th className="hidden md:table-cell">{L('ساعات إضافية', 'Overtime')}</th></tr></thead>
      <tbody>{[...period.data.rows].sort((a, b) => b.late - a.late).map(x => <tr key={x.employee.id} className="click" onClick={() => navigate(`/hr/employees/${x.employee.id}?tab=attendance`)}>
        <td><div className="font-medium">{bi(x.employee.name, lang)}</div><div className="text-[12px] text-ink-3 sm:hidden">{x.absent ? L(`غياب ${x.absent}`, `${x.absent} absent`) : ''}</div></td>
        <td className="num">{x.present}</td><td className="num hidden sm:table-cell">{x.absent || '—'}</td><td className="num hidden md:table-cell">{x.incomplete ? <span className="text-danger">{x.incomplete}</span> : '—'}</td>
        <td className="num">{x.late ? <span className={x.late > 90 ? 'text-danger font-medium' : 'text-warn'}>{minsLabel(x.late, lang)}</span> : '—'}</td>
        <td className="num hidden lg:table-cell">{x.deduct ? minsLabel(Math.round(x.deduct), lang) : '—'}</td><td className="num hidden md:table-cell">{x.ot ? minsLabel(x.ot, lang) : '—'}</td></tr>)}</tbody></table>}</Panel>)}
    {fix && <CorrectModal p={fix.p} r={fix.r} onClose={() => setFix(null)} />}
  </div>
}

function Spin() { return <div className="grid place-items-center py-16"><Loader2 className="animate-spin text-brand" /></div> }

/** تصحيح يدوي ليوم (اليوم الناقص، نسيان البطاقة): سبب إلزامي، ويُسجَّل في التدقيق والسجل الوظيفي ويصل إشعار للموظف */
export function CorrectModal({ p, r, onClose }: { p: Person; r: AttRow; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const save = useCorrect()
  const [v, setV] = useState({ in: r.in ?? '', out: r.out ?? '', note: '' })
  const err = save.error instanceof ApiError ? save.error : null
  return <Modal open onClose={() => !save.isPending && onClose()} title={<>{L('تصحيح الدوام', 'Correct attendance')} — {bi(p.name, lang)}</>} width={480}
    footer={<><Btn onClick={onClose} disabled={save.isPending}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" disabled={v.note.trim().length < 5 || save.isPending} onClick={() => save.mutate({ employee: p.id, date: r.date, ...v }, { onSuccess: () => { toast(L('حُفظ التصحيح', 'Correction saved')); onClose() } })}>{L('حفظ التصحيح', 'Save correction')}</Btn></>}>
    <div className="grid grid-cols-2 gap-3">
      <p className="col-span-2 text-[13px] text-ink-3">{L('اليوم', 'Day')}: <span className="num text-ink">{new Date(r.date + 'T12:00').toLocaleDateString(lang === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB', { weekday: 'long', day: 'numeric', month: 'long' })}</span></p>
      <Field label={L('الدخول', 'In')} error={err?.field('in')}><input type="time" className="field ltr" value={v.in} onChange={e => setV({ ...v, in: e.target.value })} /></Field>
      <Field label={L('الخروج', 'Out')} error={err?.field('out')}><input type="time" className="field ltr" value={v.out} onChange={e => setV({ ...v, out: e.target.value })} /></Field>
      <Field label={L('سبب التصحيح', 'Reason')} req className="col-span-2" error={err?.field('note') ?? err?.field('date')} hint={L('يُحفظ مع اسمك ويصل للموظف.', 'Saved with your name and sent to the employee.')}>
        <textarea className="field min-h-20" value={v.note} onChange={e => setV({ ...v, note: e.target.value })} maxLength={500} placeholder={L('مثال: نسي تسجيل الخروج — أكّده المدير المباشر', 'e.g. forgot to tap out — confirmed by the manager')} /></Field>
      {err && !Object.keys(err.errors).length && <div role="alert" className="col-span-2 text-[13px] text-danger bg-danger-soft rounded px-3 py-2">{err.message}</div>}
    </div>
  </Modal>
}
