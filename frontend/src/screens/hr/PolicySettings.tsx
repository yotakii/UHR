import { useEffect, useState, type ReactNode } from 'react'
import { CalendarPlus, Eye, Loader2, Plus, RotateCcw, Save, Trash2 } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { hhmm, toMins, useAddHoliday, useDeleteHoliday, useDeletePolicy, useFixedHolidays, useHolidays, usePolicies, usePreviewPolicy, useSavePolicy, type PolicyKey, type PolicyValues, type PreviewSum, type Version } from '../../lib/attendance'
import { minsLabel } from '../../lib/employees'
import { Badge, Btn, Field, PageHead, Panel, toast } from '../../ui/kit'

const today = () => new Date().toLocaleDateString('en-CA')
const DAYS: [number, string, string][] = [[6, 'السبت', 'Sat'], [0, 'الأحد', 'Sun'], [1, 'الإثنين', 'Mon'], [2, 'الثلاثاء', 'Tue'], [3, 'الأربعاء', 'Wed'], [4, 'الخميس', 'Thu'], [5, 'الجمعة', 'Fri']]
const PREVIEW: PolicyKey[] = ['shift', 'work_days', 'late_deduction', 'overtime_rates']

/**
 * سياسات الدوام (M16 — SRS §10.6): كل تعديل نسخة جديدة بتاريخ نفاذ (رمضان، صيفي/شتوي…) والأيام الماضية تبقى على نسختها،
 * جدول دوام خاص لإدارة، جدول حسم التأخير، الإضافي، جهاز الحضور، والعطل الرسمية. «معاينة» تحسب أثر القيم الجديدة على آخر أسبوعين.
 */
export function PolicySettings() {
  const L = useL(); const lang = useLang()
  const { data } = usePolicies()
  const [dept, setDept] = useState<number | ''>('')
  if (!data) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const ed = data.can.edit
  const deptCurrent = <K extends 'shift' | 'work_days'>(k: K): PolicyValues[K] => {
    const v = dept ? data.versions[`${k}@${dept}`]?.find(x => x.state === 'current')?.value : undefined
    return (v as PolicyValues[K]) ?? data.current[k]
  }
  return <div className="max-w-[1200px] mx-auto space-y-5">
    <PageHead title={L('سياسات الدوام والإجازات', 'Work & leave policies')} sub={L('كل تعديل يُحفظ نسخة بتاريخ نفاذ — الأيام الماضية تبقى محسوبة على نسختها. سياسات الإجازات تأتي مع وحدة الإجازات.', 'Each change is saved as a version with an effective date — past days keep their version. Leave policies arrive with the leave module.')} />
    {!ed && <Badge>{L('عرض فقط — التعديل لمسؤولي السياسات', 'Read only — policy managers can edit')}</Badge>}

    <div className="grid lg:grid-cols-2 gap-5">
      <PolicyCard k="shift" title={L('الوردية', 'Shift')} data={data} editable={ed} render={(v, set) => <ShiftEditor v={v} set={set} editable={ed} />} />
      <PolicyCard k="work_days" title={L('أيام العمل', 'Work days')} data={data} editable={ed} render={(v, set) => <DaysEditor v={v} set={set} editable={ed} />} />
    </div>

    <Panel title={L('جدول دوام خاص لإدارة', 'Department schedule')} sub={L('لإدارة بطبيعة عمل مختلفة (ورديات، دوام مسائي…). بدون نسخة خاصة تتبع الإدارة سياسة الشركة.', 'For a department with a different pattern (shifts, evening work…). Without its own version a department follows the company policy.')}>
      <select className="field !w-full sm:!w-72 mb-4" value={dept} onChange={e => setDept(e.target.value ? Number(e.target.value) : '')} aria-label={L('الإدارة', 'Department')}>
        <option value="">{L('اختر إدارة…', 'Choose a department…')}</option>{data.departments.map(d => <option key={d.id} value={d.id}>{bi(d, lang)}</option>)}</select>
      {dept && <div className="grid lg:grid-cols-2 gap-5">
        <PolicyCard key={`s${dept}`} k="shift" dept={dept} base={deptCurrent('shift')} title={L('وردية الإدارة', 'Department shift')} data={data} editable={ed} render={(v, set) => <ShiftEditor v={v} set={set} editable={ed} />} />
        <PolicyCard key={`w${dept}`} k="work_days" dept={dept} base={deptCurrent('work_days')} title={L('أيام عمل الإدارة', 'Department work days')} data={data} editable={ed} render={(v, set) => <DaysEditor v={v} set={set} editable={ed} />} />
      </div>}
    </Panel>

    <PolicyCard k="late_deduction" title={L('حسم التأخير الصباحي', 'Morning lateness deduction')} data={data} editable={ed} render={(v, set) => <LateEditor v={v} set={set} editable={ed} />}
      hint={L('الدقيقة بدقيقة × معامل شريحة التأخير. الإعفاء الشهري بمبرر يُطبَّق في كشف الشهر.', 'Minute for minute × the tier factor. The monthly justified exemption applies in the monthly sheet.')} />

    <div className="grid lg:grid-cols-2 gap-5">
      <PolicyCard k="overtime_rates" title={L('نسب العمل الإضافي', 'Overtime rates')} data={data} editable={ed} render={(v, set) => <>
        {num(v.day * 100, x => set({ ...v, day: x / 100 }), L('نهاري', 'Day'), ed, '+%')}{num(v.night * 100, x => set({ ...v, night: x / 100 }), L('ليلي', 'Night'), ed, '+%')}
        {num(v.rest * 100, x => set({ ...v, rest: x / 100 }), L('يوم الراحة الأسبوعية', 'Weekly rest day'), ed, '+%')}{num(v.holiday * 100, x => set({ ...v, holiday: x / 100 }), L('العطلة الرسمية', 'Public holiday'), ed, '+%')}</>} />
      <PolicyCard k="overtime" title={L('قواعد الإضافي', 'Overtime rules')} data={data} editable={ed} render={(v, set) => <>
        {num(v.weekly_hours, x => set({ ...v, weekly_hours: x }), L('يُستحق بعد ساعات أسبوعية', 'Due after weekly hours'), ed, L('ساعة', 'h'))}
        {num(v.daily_max, x => set({ ...v, daily_max: x }), L('الحد الأقصى اليومي', 'Daily maximum'), ed, L('دقيقة', 'min'))}
        {num(v.max_continuous_hours, x => set({ ...v, max_continuous_hours: x }), L('أقصى ساعات متتالية', 'Max continuous hours'), ed, L('ساعة', 'h'))}
        <Toggle on={v.requires_assignment} set={x => set({ ...v, requires_assignment: x })} editable={ed} label={L('بتكليف خطي مسبق (F-04)', 'Prior written assignment (F-04)')} /></>} />
    </div>

    <div className="grid lg:grid-cols-2 gap-5">
      <PolicyCard k="terminal" title={L('جهاز الحضور', 'Attendance terminal')} data={data} editable={ed} render={(v, set) => <>
        {num(v.cooldown, x => set({ ...v, cooldown: x }), L('تجاهل التمرير المكرر خلال', 'Ignore repeated taps within'), ed, L('ثانية', 'sec'))}
        {num(v.incomplete_after, x => set({ ...v, incomplete_after: x }), L('«ناقص» بلا خروج بعد نهاية الوردية بـ', '"Incomplete" without out after shift end +'), ed, L('دقيقة', 'min'))}</>} />
      <Holidays editable={ed} />
    </div>
  </div>
}

function PolicyCard<K extends PolicyKey>({ k, title, data, editable, render, dept, base, hint }: {
  k: K; title: string; data: NonNullable<ReturnType<typeof usePolicies>['data']>; editable: boolean; render: (v: PolicyValues[K], set: (v: PolicyValues[K]) => void) => ReactNode
  dept?: number; base?: PolicyValues[K]; hint?: string
}) {
  const L = useL(); const lang = useLang()
  const cur = (base ?? data.current[k]) as PolicyValues[K]
  const [v, setV] = useState<PolicyValues[K]>(cur)
  const [from, setFrom] = useState(today())
  const [pv, setPv] = useState<{ current: PreviewSum; proposed: PreviewSum } | null>(null)
  const save = useSavePolicy(); const del = useDeletePolicy(); const preview = usePreviewPolicy()
  useEffect(() => { setV(cur); setPv(null) }, [JSON.stringify(cur)])   // eslint-disable-line react-hooks/exhaustive-deps
  const versions: Version[] = data.versions[dept ? `${k}@${dept}` : k] ?? []
  const dirty = JSON.stringify(v) !== JSON.stringify(cur)
  const err = save.error instanceof ApiError ? save.error : preview.error instanceof ApiError ? preview.error : null
  const errText = err && (Object.values(err.errors)[0]?.[0] ?? err.message)
  const stateBadge = (s: Version['state']) => s === 'current' ? <Badge tone="brand">{L('نافذة', 'In force')}</Badge> : s === 'future' ? <Badge tone="info">{L('قادمة', 'Upcoming')}</Badge> : <Badge>{L('سابقة', 'Past')}</Badge>
  return <Panel title={title} sub={hint}>
    <div className="space-y-1">{render(v, x => { setV(x); setPv(null); save.reset() })}</div>
    {pv && <div className="mt-3 rounded-lg bg-sub p-3 text-[12.5px] grid grid-cols-3 gap-2">
      {([[L('أيام تأخير', 'Late days'), 'late_days'], [L('دقائق الحسم', 'Deduction'), 'deduct'], [L('الإضافي', 'Overtime'), 'ot']] as const).map(([lbl, f]) =>
        <div key={f}><div className="text-ink-3">{lbl}</div><div className="num font-semibold">{f === 'late_days' ? pv.current[f] : minsLabel(Math.round(pv.current[f]), lang)} → <span className="text-brand">{f === 'late_days' ? pv.proposed[f] : minsLabel(Math.round(pv.proposed[f]), lang)}</span></div></div>)}
      <div className="col-span-3 text-ink-3">{L('آخر 14 يوماً لكل الموظفين', 'Last 14 days, all employees')}{dept ? L(' في الإدارة', ' in the department') : ''}</div>
    </div>}
    {errText && <div role="alert" className="mt-3 text-[13px] text-danger bg-danger-soft rounded px-3 py-2">{errText}</div>}
    {editable && <div className="mt-4 flex flex-wrap items-end gap-2 border-t border-line-2 pt-3">
      <Field label={L('تاريخ النفاذ', 'Effective from')} className="!mb-0"><input type="date" className="field !h-9 !w-40" min={today()} value={from} onChange={e => setFrom(e.target.value)} /></Field>
      <span className="flex-1" />
      <Btn size="sm" icon={<RotateCcw size={14} />} onClick={() => setV(data.defaults[k])}>{L('الافتراضي', 'Defaults')}</Btn>
      {PREVIEW.includes(k) && <Btn size="sm" icon={preview.isPending ? <Loader2 size={14} className="animate-spin" /> : <Eye size={14} />} disabled={preview.isPending}
        onClick={() => preview.mutate({ key: k, department_id: dept ?? null, value: v }, { onSuccess: setPv })}>{L('معاينة', 'Preview')}</Btn>}
      <Btn size="sm" kind="primary" icon={save.isPending ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />} disabled={!dirty || !from || save.isPending}
        onClick={() => save.mutate({ key: k, department_id: dept ?? null, effective_from: from, value: v }, { onSuccess: () => toast(L(`حُفظت نسخة نافذة من ${fmtDate(from, lang)}`, `Version saved, effective ${fmtDate(from, lang)}`)) })}>{L('حفظ كنسخة', 'Save version')}</Btn>
    </div>}
    {versions.length > 0 && <ul className="mt-3 divide-y divide-line-2 text-[13px]">{versions.slice(0, 6).map(x => <li key={x.id} className="flex items-center justify-between gap-2 py-1.5">
      <span className="flex items-center gap-2">{stateBadge(x.state)}<span className="num">{L('من', 'From')} {fmtDate(x.effective_from, lang)}</span></span>
      <span className="flex items-center gap-1">
        {x.state === 'future' && editable && <button className="text-brand text-[12.5px] font-semibold px-1" onClick={() => { setV(x.value as PolicyValues[K]); setFrom(x.effective_from) }}>{L('تعديل', 'Edit')}</button>}
        {x.state === 'future' && editable && <button className="p-1 rounded hover:bg-sub text-ink-3" disabled={del.isPending} onClick={() => del.mutate(x.id, { onError: e => toast((e as Error).message) })} aria-label={L('حذف', 'Delete')}><Trash2 size={14} /></button>}
      </span></li>)}</ul>}
  </Panel>
}

const num = (value: number, on: (v: number) => void, label: string, editable: boolean, suffix: string, step = 1) =>
  <label className="flex items-center justify-between gap-3 py-1.5 text-[13.5px]"><span className="min-w-0">{label}</span>
    <span className="flex items-center gap-1.5 shrink-0"><input type="number" min={0} step={step} disabled={!editable} value={Math.round(value * 100) / 100} onChange={e => on(Number(e.target.value))} className="field !h-9 !w-20 num text-center" />
      <span className="text-[12px] text-ink-3 w-12">{suffix}</span></span></label>

const time = (value: number, on: (v: number) => void, label: string, editable: boolean) =>
  <label className="flex items-center justify-between gap-3 py-1.5 text-[13.5px]"><span>{label}</span>
    <input type="time" disabled={!editable} value={hhmm(value)} onChange={e => e.target.value && on(toMins(e.target.value))} className="field !h-9 !w-32 ltr num" /></label>

function Toggle({ on, set, editable, label }: { on: boolean; set: (v: boolean) => void; editable: boolean; label: string }) {
  return <button type="button" disabled={!editable} onClick={() => set(!on)} aria-pressed={on} className="w-full flex items-center justify-between gap-4 py-2 text-start text-[13.5px] disabled:cursor-default">
    <span>{label}</span><span className={`w-11 h-6 shrink-0 rounded-full p-0.5 transition-colors ${on ? 'bg-brand' : 'bg-sub border border-line'}`}><span className={`block size-5 rounded-full bg-white transition-transform ${on ? 'translate-x-5 rtl:-translate-x-5' : ''}`} /></span></button>
}

function ShiftEditor({ v, set, editable }: { v: PolicyValues['shift']; set: (v: PolicyValues['shift']) => void; editable: boolean }) {
  const L = useL()
  return <>
    {time(v.start, x => set({ ...v, start: x }), L('بداية الدوام', 'Start'), editable)}{time(v.end, x => set({ ...v, end: x }), L('نهاية الدوام', 'End'), editable)}
    {num(v.grace, x => set({ ...v, grace: x }), L('سماح قبل احتساب التأخير', 'Grace before lateness'), editable, L('دقيقة', 'min'))}
    {num(v.break, x => set({ ...v, break: x }), L('الاستراحة المطروحة', 'Break deducted'), editable, L('دقيقة', 'min'))}
    {num(v.break_after, x => set({ ...v, break_after: x }), L('تُطرح الاستراحة بعد عمل', 'Break deducted after working'), editable, L('دقيقة', 'min'))}
    {num(v.ot_threshold, x => set({ ...v, ot_threshold: x }), L('يُحتسب الإضافي بعد نهاية الدوام بـ', 'Overtime counts after shift end +'), editable, L('دقيقة', 'min'))}
    {time(v.night_from, x => set({ ...v, night_from: x }), L('بداية العمل الليلي', 'Night work starts'), editable)}
  </>
}

function DaysEditor({ v, set, editable }: { v: number[]; set: (v: number[]) => void; editable: boolean }) {
  const lang = useLang()
  return <div className="flex flex-wrap gap-1.5">{DAYS.map(([d, ar, en]) => { const on = v.includes(d)
    return <button key={d} type="button" disabled={!editable} aria-pressed={on} onClick={() => set(on ? v.filter(x => x !== d) : [...v, d].sort())}
      className={`rounded-full border px-3 h-9 text-[13px] font-semibold ${on ? 'border-brand bg-brand-soft text-brand' : 'border-line text-ink-3'} disabled:cursor-default`}>{lang === 'ar' ? ar : en}</button> })}</div>
}

function LateEditor({ v, set, editable }: { v: PolicyValues['late_deduction']; set: (v: PolicyValues['late_deduction']) => void; editable: boolean }) {
  const L = useL()
  const tier = (i: number, f: 'from' | 'to' | 'factor', x: number) => set({ ...v, tiers: v.tiers.map((t, j) => j === i ? { ...t, [f]: x } : t) })
  return <div className="grid md:grid-cols-2 gap-x-8">
    <div>
      <div className="grid grid-cols-[1fr_1fr_1fr_auto] gap-2 text-[12px] text-ink-3 mb-1"><span>{L('من (دقيقة)', 'From (min)')}</span><span>{L('إلى', 'To')}</span><span>{L('المعامل', 'Factor')}</span><span className="w-7" /></div>
      {v.tiers.map((t, i) => <div key={i} className="grid grid-cols-[1fr_1fr_1fr_auto] gap-2 mb-1.5 items-center">
        <input type="number" min={1} disabled={!editable} value={t.from} onChange={e => tier(i, 'from', Number(e.target.value))} className="field !h-9 num text-center min-w-0" aria-label="from" />
        <input type="number" min={1} disabled={!editable} value={t.to} onChange={e => tier(i, 'to', Number(e.target.value))} className="field !h-9 num text-center min-w-0" aria-label="to" />
        <input type="number" min={0} step={0.25} disabled={!editable} value={t.factor} onChange={e => tier(i, 'factor', Number(e.target.value))} className="field !h-9 num text-center min-w-0" aria-label="factor" />
        {editable ? <button className="p-1.5 rounded hover:bg-sub text-ink-3 disabled:opacity-30" disabled={v.tiers.length < 2} onClick={() => set({ ...v, tiers: v.tiers.filter((_, j) => j !== i) })} aria-label={L('حذف', 'Remove')}><Trash2 size={14} /></button> : <span className="w-7" />}
      </div>)}
      {editable && <Btn size="sm" icon={<Plus size={14} />} onClick={() => { const last = v.tiers[v.tiers.length - 1]; set({ ...v, tiers: [...v.tiers, { from: (last?.to ?? 0) + 1, to: (last?.to ?? 0) + 30, factor: last?.factor ?? 1 }] }) }}>{L('شريحة', 'Tier')}</Btn>}
    </div>
    <div>
      {num(v.exempt_upto, x => set({ ...v, exempt_upto: x }), L('يُعفى التأخير حتى', 'Exempt lateness up to'), editable, L('دقيقة', 'min'))}
      {num(v.exempt_per_month, x => set({ ...v, exempt_per_month: x }), L('عدد مرات الإعفاء بمبرر شهرياً', 'Justified exemptions per month'), editable, L('مرة', 'times'))}
      {num(v.absent_after, x => set({ ...v, absent_after: x }), L('تأخير أكثر من هذا = غياب', 'Lateness beyond this = absence'), editable, L('دقيقة', 'min'))}
    </div>
  </div>
}

function Holidays({ editable }: { editable: boolean }) {
  const L = useL(); const lang = useLang()
  const [year, setYear] = useState(new Date().getFullYear())
  const { data } = useHolidays(year)
  const add = useAddHoliday(); const fixed = useFixedHolidays(); const del = useDeleteHoliday()
  const [h, setH] = useState({ date: '', days: 1, name_ar: '', name_en: '' })
  const err = add.error instanceof ApiError ? add.error : null
  return <Panel title={L('العطل الرسمية', 'Public holidays')} sub={L('تُحسب كيوم راحة، والعمل فيها إضافي بنسبة العطلة الرسمية.', 'Counted as rest days; work on them is overtime at the holiday rate.')}
    action={<select className="field !h-9 !w-28" value={year} onChange={e => setYear(Number(e.target.value))} aria-label={L('السنة', 'Year')}>{[year - 1, year, year + 1].map(y => <option key={y}>{y}</option>)}</select>}>
    {!data ? <Loader2 className="animate-spin text-brand mx-auto" /> : <ul className="divide-y divide-line-2 text-[13.5px] max-h-64 overflow-y-auto">
      {data.data.length === 0 && <li className="py-3 text-ink-3 text-center">{L('لا توجد عطل لهذه السنة', 'No holidays for this year')}</li>}
      {data.data.map(x => <li key={x.id} className="flex items-center justify-between gap-2 py-1.5"><span><span className="num text-ink-3 me-2">{fmtDate(x.date, lang)}</span>{bi(x, lang)}</span>
        {editable && <button className="p-1 rounded hover:bg-sub text-ink-3" onClick={() => del.mutate(x.id)} aria-label={L('حذف', 'Delete')}><Trash2 size={14} /></button>}</li>)}</ul>}
    {editable && <div className="mt-3 border-t border-line-2 pt-3 space-y-2">
      <div className="grid grid-cols-[1fr_80px] gap-2"><input type="date" className="field !h-9" value={h.date} onChange={e => setH({ ...h, date: e.target.value })} aria-label={L('التاريخ', 'Date')} />
        <input type="number" min={1} max={10} className="field !h-9 num text-center" value={h.days} onChange={e => setH({ ...h, days: Number(e.target.value) })} aria-label={L('عدد الأيام', 'Days')} title={L('عدد الأيام', 'Days')} /></div>
      <div className="grid grid-cols-2 gap-2"><input className="field !h-9" placeholder={L('الاسم بالعربية', 'Arabic name')} value={h.name_ar} onChange={e => setH({ ...h, name_ar: e.target.value })} />
        <input className="field !h-9 ltr text-start" placeholder="English name" value={h.name_en} onChange={e => setH({ ...h, name_en: e.target.value })} /></div>
      {err && <div role="alert" className="text-[13px] text-danger">{Object.values(err.errors)[0]?.[0] ?? err.message}</div>}
      <div className="flex flex-wrap gap-2 justify-end">
        <Btn size="sm" disabled={fixed.isPending} onClick={() => fixed.mutate(year, { onSuccess: () => toast(L('أُضيفت العطل ثابتة التاريخ', 'Fixed-date holidays added')) })}>{L(`العطل الثابتة لسنة ${year}`, `Fixed holidays for ${year}`)}</Btn>
        <Btn size="sm" kind="primary" icon={<CalendarPlus size={14} />} disabled={!h.date || !h.name_ar.trim() || !h.name_en.trim() || add.isPending}
          onClick={() => add.mutate(h, { onSuccess: d => { setYear(d.year); setH({ date: '', days: 1, name_ar: '', name_en: '' }) } })}>{L('إضافة عطلة', 'Add holiday')}</Btn>
      </div>
    </div>}
  </Panel>
}
