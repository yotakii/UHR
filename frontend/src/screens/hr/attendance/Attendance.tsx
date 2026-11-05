import { useState } from 'react'
import { Download, Loader2, Pencil, Nfc, Settings2, LogIn, LogOut, CalendarClock, Wallet, ChevronLeft, ChevronRight } from 'lucide-react'
import { useL, useLang, bi } from '../../../lib/store'
import { navigate, setQuery, useRoute } from '../../../lib/router'
import { minsLabel } from '../../../lib/employees'
import { hm, periodExportUrl, useCorrect, useDaily, usePeriod, useRoster, useTrend, type AttRow, type AttStatus, type Person } from '../../../lib/attendance'
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
  const view = (['period', 'trend', 'roster'] as const).find(v => v === route.query.view) ?? 'day'
  const date = route.query.date ?? today(); const dept = route.query.dept ?? ''; const branch = route.query.branch ?? ''
  const to = route.query.to ?? today(); const from = route.query.from ?? minus(to, 13)
  const day = useDaily(date, dept, view === 'day' && date === today(), branch)
  const period = usePeriod(from, to, dept, view === 'period', branch)
  const meta = (view === 'day' ? day.data : period.data) ?? day.data
  const [fix, setFix] = useState<{ p: Person; r: AttRow } | null>(null)
  const set = (q: Record<string, string | undefined>) => setQuery({ ...route.query, ...q })

  return <div className="max-w-[1280px] mx-auto">
    <PageHead title={L('الحضور والدوام', 'Attendance')} sub={L('حركات بطاقات NFC محسوبة وفق سياسة الدوام النافذة: الوردية، جدول حسم التأخير، والإضافي.', 'NFC taps calculated with the policy in force: shift, late-deduction table and overtime.')}
      actions={<>
        {meta?.can.policies && <Btn icon={<Settings2 size={15} />} onClick={() => navigate('/hr/settings/policies')}>{L('سياسة الدوام', 'Work policy')}</Btn>}
        <Btn icon={<CalendarClock size={15} />} onClick={() => navigate('/hr/settings/schedules')}>{L('جداول الدوام', 'Work schedules')}</Btn>
        {meta?.can.devices && <Btn icon={<Nfc size={15} />} onClick={() => navigate('/hr/settings/devices')}>{L('أجهزة الحضور', 'Attendance devices')}</Btn>}
      </>} />
    <Tabs value={view} onChange={v => set({ view: v === 'day' ? undefined : v })} tabs={[{ v: 'day', label: L('السجل اليومي', 'Daily log') }, { v: 'period', label: L('ملخص الفترة', 'Period summary') }, { v: 'trend', label: L('الاتجاه الأسبوعي', 'Weekly trend') }, { v: 'roster', label: L('المناوبات', 'Rota') }]} />
    <div className="flex flex-wrap gap-2 my-4">
      <select className="field !w-full sm:!w-48" value={dept} onChange={e => set({ dept: e.target.value || undefined })} aria-label={L('الإدارة', 'Department')}>
        <option value="">{L('كل الإدارات', 'All departments')}</option>{meta?.depts.map(d => <option key={d.id} value={d.id}>{bi(d, lang)}</option>)}</select>
      {view !== 'roster' && (meta?.branches?.length ?? 0) > 1 && <select className="field !w-full sm:!w-44" value={branch} onChange={e => set({ branch: e.target.value || undefined })} aria-label={L('الفرع', 'Branch')}>
        <option value="">{L('كل الفروع', 'All branches')}</option>{meta?.branches?.map(b => <option key={b.id} value={b.id}>{bi(b, lang)}</option>)}</select>}
      {view === 'trend' || view === 'roster' ? null : view === 'day' ? <input type="date" className="field !w-full sm:!w-44" value={date} max={today()} onChange={e => set({ date: e.target.value || undefined })} aria-label={L('التاريخ', 'Date')} />
        : <><input type="date" className="field !w-[calc(50%-4px)] sm:!w-44" value={from} max={to} onChange={e => set({ from: e.target.value || undefined })} aria-label={L('من', 'From')} />
          <input type="date" className="field !w-[calc(50%-4px)] sm:!w-44" value={to} max={today()} onChange={e => set({ to: e.target.value || undefined })} aria-label={L('إلى', 'To')} />
          {meta?.can.export && <a href={periodExportUrl(from, to, dept, branch)} className="inline-flex items-center gap-2 h-10 px-4 rounded-lg border border-line bg-surface font-semibold text-[14px] hover:bg-sub"><Download size={15} />{L('تصدير Excel', 'Export Excel')}</a>}</>}
    </div>

    {view === 'day' ? (!day.data ? <Spin /> : <>
      <div className="grid grid-cols-2 md:grid-cols-5 gap-px bg-line border border-line rounded-lg overflow-hidden mb-4">
        {([[L('حاضرون', 'Present'), day.data.counts.present, 'text-brand'], [L('متأخرون', 'Late'), day.data.counts.late, 'text-warn'], [L('غائبون', 'Absent'), day.data.counts.absent, 'text-danger'],
          [L('ناقص', 'Incomplete'), day.data.counts.incomplete, 'text-danger'], [L('إجازات', 'On leave'), day.data.counts.leave, 'text-ink-2']] as const).map(([k, v, c]) =>
          <div key={k} className="bg-surface px-4 py-3"><div className="text-[12px] text-ink-3">{k}</div><div className={`text-[22px] font-bold num ${c}`}>{v}</div></div>)}
      </div>
      {day.data.latest.length > 0 && <Panel title={<span className="flex items-center gap-2">{date === today() && <span className="size-2 rounded-full bg-brand live-dot" />}{L('آخر الحركات', 'Latest taps')}</span>} className="mb-4"
        sub={date === today() ? L('تتحدث تلقائياً من أجهزة الحضور', 'Updates automatically from the attendance devices') : undefined}>
        <ul className="grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(220px,1fr))]">{day.data.latest.map((t, i) => <li key={t.id} className={`flex items-center gap-3 rounded-xl border border-line px-3 py-2 ${i === 0 && date === today() ? 'bg-brand-soft fadeup' : ''}`}>
          <span className={`size-8 shrink-0 rounded-full grid place-items-center ${t.type === 'in' ? 'bg-brand/15 text-brand' : 'bg-brass/15 text-brass'}`}>{t.type === 'in' ? <LogIn size={15} /> : <LogOut size={15} />}</span>
          <button className="min-w-0 flex-1 text-start" onClick={() => navigate(`/hr/employees/${t.employee.id}?tab=attendance`)}><span className="block text-[13.5px] font-semibold truncate">{bi(t.employee.name, lang)}</span>
            <span className="block text-[11.5px] text-ink-3 truncate">{t.type === 'in' ? L('دخول', 'In') : L('خروج', 'Out')} · {bi(t.device, lang)}</span></button>
          <span className="num ltr text-[13px] font-semibold">{t.time}</span></li>)}</ul>
      </Panel>}
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
    </>) : view === 'trend' ? <TrendView dept={dept} branch={branch} /> : view === 'roster' ? <RosterView dept={dept} /> : (!period.data ? <Spin /> : <>
      {period.data.totals && <div className="grid gap-px bg-line border border-line rounded-lg overflow-hidden mb-4 [grid-template-columns:repeat(auto-fill,minmax(140px,1fr))]">
        {([[L('نسبة الحضور', 'Attendance rate'), period.data.totals.rate === null ? '—' : `${period.data.totals.rate}%`, 'text-brand'], [L('أيام الحضور', 'Days present'), period.data.totals.present, ''], [L('الغياب', 'Absences'), period.data.totals.absent, 'text-danger'],
          [L('أيام التأخير', 'Late days'), period.data.totals.late_days, 'text-warn'], [L('دقائق الحسم', 'Deduction'), minsLabel(Math.round(period.data.totals.deduct), lang), 'text-warn'], [L('الإضافي', 'Overtime'), minsLabel(period.data.totals.ot, lang), 'text-brand'],
          [L('ناقص', 'Incomplete'), period.data.totals.incomplete, 'text-danger'], [L('إجازات', 'Leave days'), period.data.totals.leave, '']] as const).map(([k, v, c]) =>
          <div key={k} className="bg-surface px-4 py-3"><div className="text-[12px] text-ink-3">{k}</div><div className={`text-[20px] font-bold num ${c}`}>{v}</div></div>)}
      </div>}
      {period.data.payroll?.month && period.data.payroll.can && <div className="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-line bg-surface px-4 py-2.5 text-[13px]">
        <Wallet size={16} className="text-brand" />{L(`هذه الأرقام تدخل كشف شهر ${period.data.payroll.month}`, `These figures feed the ${period.data.payroll.month} sheet`)}
        <span className="text-ink-3">· {period.data.payroll.sheet ? L(`حالة الكشف: ${({ draft: 'مسودة', calculated: 'محسوب', approved: 'معتمد', closed: 'مغلق' } as Record<string, string>)[period.data.payroll.sheet.status] ?? period.data.payroll.sheet.status}`, `Sheet status: ${period.data.payroll.sheet.status}`) : L('لم يُفتح كشف الشهر بعد', 'The month sheet is not open yet')}</span>
        <Btn size="sm" className="ms-auto" onClick={() => navigate(`/hr/payroll?month=${period.data!.payroll!.month}`)}>{L('فتح الكشف الشهري', 'Open the monthly sheet')}</Btn></div>}
      <Panel pad={false}>{period.data.rows.length === 0 ? <Empty title={L('لا توجد بيانات', 'No data')} /> : <table className="tbl">
      <thead><tr><th>{L('الموظف', 'Employee')}</th><th>{L('أيام الحضور', 'Days present')}</th><th className="hidden sm:table-cell">{L('الغياب', 'Absences')}</th><th className="hidden md:table-cell">{L('ناقص', 'Incomplete')}</th>
        <th>{L('مجموع التأخير', 'Total late')}</th><th className="hidden lg:table-cell">{L('دقائق الحسم', 'Deduction')}</th><th className="hidden md:table-cell">{L('ساعات إضافية', 'Overtime')}</th></tr></thead>
      <tbody>{[...period.data.rows].sort((a, b) => b.late - a.late).map(x => <tr key={x.employee.id} className="click" onClick={() => navigate(`/hr/employees/${x.employee.id}?tab=attendance`)}>
        <td><div className="font-medium">{bi(x.employee.name, lang)}</div><div className="text-[12px] text-ink-3 sm:hidden">{x.absent ? L(`غياب ${x.absent}`, `${x.absent} absent`) : ''}</div></td>
        <td className="num">{x.present}</td><td className="num hidden sm:table-cell">{x.absent || '—'}</td><td className="num hidden md:table-cell">{x.incomplete ? <span className="text-danger">{x.incomplete}</span> : '—'}</td>
        <td className="num">{x.late ? <span className={x.late > 90 ? 'text-danger font-medium' : 'text-warn'}>{minsLabel(x.late, lang)}</span> : '—'}</td>
        <td className="num hidden lg:table-cell">{x.deduct ? minsLabel(Math.round(x.deduct), lang) : '—'}</td><td className="num hidden md:table-cell">{x.ot ? minsLabel(x.ot, lang) : '—'}</td></tr>)}</tbody></table>}</Panel></>)}
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

/** اتجاه الحضور الأسبوعي (ملاحظة الاختبار 31): نسبة الحضور لكل أسبوع مع التأخير والغياب */
function TrendView({ dept, branch }: { dept: string; branch: string }) {
  const L = useL(); const lang = useLang()
  const { data } = useTrend(dept, branch)
  if (!data) return <Spin />
  const max = 100
  return <Panel title={L('نسبة الحضور الأسبوعية — آخر 8 أسابيع', 'Weekly attendance rate — last 8 weeks')} sub={L('الحضور ÷ أيام العمل المتوقعة (دون الإجازات والعطل)، لكل أسبوع يبدأ السبت.', 'Present ÷ expected working days (excluding leave and days off), weeks starting Saturday.')}>
    <div className="grid gap-2" style={{ gridTemplateColumns: `repeat(${data.length}, minmax(0, 1fr))` }}>
      {data.map(w => <div key={w.from} className="flex flex-col items-center gap-1 min-w-0" title={L(`${w.present} من ${w.expected} · تأخير ${w.late} · غياب ${w.absent}`, `${w.present} of ${w.expected} · late ${w.late} · absent ${w.absent}`)}>
        <span className="text-[12px] font-semibold num">{w.rate === null ? '—' : `${w.rate}%`}</span>
        <div className="w-full max-w-[44px] h-40 rounded-md bg-sub flex flex-col justify-end overflow-hidden" role="img" aria-label={`${w.from}: ${w.rate ?? 0}%`}>
          <div className="w-full rounded-md" style={{ height: `${Math.max(2, ((w.rate ?? 0) / max) * 100)}%`, background: 'var(--primary)' }} /></div>
        <span className="text-[11px] text-ink-3 num ltr">{new Date(w.from + 'T12:00:00').toLocaleDateString(lang === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB', { day: 'numeric', month: 'short' })}</span>
        <span className="text-[11px] text-warn num">{w.late ? L(`${w.late} تأخير`, `${w.late} late`) : ''}</span>
        <span className="text-[11px] text-danger num">{w.absent ? L(`${w.absent} غياب`, `${w.absent} abs.`) : ''}</span>
      </div>)}
    </div>
  </Panel>
}

/** المناوبات: الموظفون × أيام الأسبوع — الوردية السارية لكل يوم من جدولهم (ملاحظتا 24 و76) */
function RosterView({ dept }: { dept: string }) {
  const L = useL(); const lang = useLang()
  const [from, setFrom] = useState(today())
  const { data } = useRoster(from, dept)
  const shift = (d: number) => setFrom(minus(from, -d))
  const Prev = lang === 'ar' ? ChevronRight : ChevronLeft; const Next = lang === 'ar' ? ChevronLeft : ChevronRight
  const tone: Record<string, string> = { brand: 'border-brand/40 bg-brand-soft', brass: 'border-brass/40 bg-brass/10', info: 'border-info/40 bg-info/10', danger: 'border-danger/40 bg-danger-soft', 'ink-3': 'border-line bg-sub' }
  return <Panel pad={false} title={L('جدول المناوبات الأسبوعي', 'Weekly rota')} action={<div className="flex items-center gap-1">
    <Btn size="sm" kind="ghost" onClick={() => shift(-7)} aria-label={L('الأسبوع السابق', 'Previous week')}><Prev size={15} /></Btn>
    <input type="date" className="field !h-8 !w-40 text-[13px]" value={from} onChange={e => e.target.value && setFrom(e.target.value)} aria-label={L('من', 'From')} />
    <Btn size="sm" kind="ghost" onClick={() => shift(7)} aria-label={L('الأسبوع التالي', 'Next week')}><Next size={15} /></Btn></div>}>
    {!data ? <Spin /> : data.data.length === 0 ? <Empty title={L('لا يوجد موظفون', 'No employees')} /> : <div className="p-3 space-y-2">
      <div className="hidden md:grid gap-1.5 text-[11.5px] text-ink-3 font-semibold" style={{ gridTemplateColumns: `minmax(140px,1.4fr) repeat(${data.days.length}, minmax(0,1fr))` }}>
        <span />{data.days.map(d => <span key={d} className="text-center">{new Date(d + 'T12:00:00').toLocaleDateString(lang === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB', { weekday: 'short', day: 'numeric' })}</span>)}</div>
      {data.data.map(r => <div key={r.id} className="grid gap-1.5 items-center md:[grid-template-columns:var(--cols)] grid-cols-4 sm:grid-cols-7" style={{ ['--cols' as string]: `minmax(140px,1.4fr) repeat(${data.days.length}, minmax(0,1fr))` }}>
        <div className="col-span-4 sm:col-span-7 md:col-span-1 min-w-0"><div className="text-[13px] font-medium truncate">{lang === 'en' ? r.en : r.ar}</div><div className="text-[11px] text-ink-3 ltr text-start">{r.code}</div></div>
        {r.days.map(d => <div key={d.date} title={d.schedule ? `${d.schedule.ar}${d.shift ? ' · ' + d.shift : ''}` : L('سياسة الدوام العامة', 'General work policy')}
          className={`rounded-md border px-1 py-1.5 text-center text-[11px] num ${d.off ? 'border-line bg-sub text-ink-3' : d.schedule ? tone[d.schedule.color] ?? tone.brand : 'border-line'}`}>
          <span className="md:hidden block text-ink-3">{new Date(d.date + 'T12:00:00').toLocaleDateString(lang === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB', { weekday: 'short' })}</span>
          {d.off === 'holiday' ? L('عطلة', 'Holiday') : d.off ? L('راحة', 'Off') : <span className="ltr inline-block">{hm(d.start)}–{hm(d.end)}</span>}</div>)}
      </div>)}
      <p className="text-[12px] text-ink-3 px-1">{L('الخانات الملونة من جدول أو إسناد؛ البيضاء من سياسة الدوام العامة. لتغيير وردية: «جداول الدوام» ← «إسناد».', 'Coloured cells come from a schedule or assignment; plain ones from the general policy. To change a shift: Work schedules → Assign.')}</p>
    </div>}
  </Panel>
}
