import { useEffect, useState, type ReactNode } from 'react'
import { CalendarPlus, Eye, Loader2, Plus, RotateCcw, Save, Trash2 } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { hhmm, toMins, useAddHoliday, useDeleteHoliday, useDeletePolicy, useFixedHolidays, useHolidays, usePolicies, usePreviewPolicy, useSavePolicy, type PolicyKey, type PolicyValues, type LeaveTypePolicy, type PreviewSum, type Version } from '../../lib/attendance'
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
    <PageHead title={L('سياسات الدوام والإجازات', 'Work & leave policies')} sub={L('كل تعديل يُحفظ نسخة بتاريخ نفاذ — الأيام الماضية تبقى محسوبة على نسختها. الطلبات المرسلة تبقى على قواعد يوم إرسالها.', 'Each change is saved as a version with an effective date — past days keep their version. Submitted requests keep the rules of their day.')} />
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

    <h2 className="font-display font-bold text-[17px] pt-2">{L('الإجازات', 'Leave')}</h2>
    <div className="grid lg:grid-cols-2 gap-5">
      <PolicyCard k="annual_leave" title={L('استحقاق الإجازة السنوية', 'Annual leave entitlement')} data={data} editable={ed} render={(v, set) => <>
        {num(v.first_year, x => set({ ...v, first_year: x }), L('السنة الأولى', 'First year'), ed, L('يوم', 'days'))}
        {num(v.standard, x => set({ ...v, standard: x }), L('بعد سنوات الخدمة', 'After service years'), ed, L('يوم', 'days'))}
        {num(v.standard_years, x => set({ ...v, standard_years: x }), L('— عدد سنوات الخدمة', '— service years'), ed, L('سنة', 'yrs'))}
        {num(v.senior, x => set({ ...v, senior: x }), L('الأقدمية أو السن', 'Seniority or age'), ed, L('يوم', 'days'))}
        {num(v.senior_years, x => set({ ...v, senior_years: x }), L('— بعد سنوات خدمة', '— after service years'), ed, L('سنة', 'yrs'))}
        {num(v.senior_age, x => set({ ...v, senior_age: x }), L('— أو عند بلوغ سن', '— or from age'), ed, L('سنة', 'yrs'))}
        <Toggle on={v.prorate_first_year} set={x => set({ ...v, prorate_first_year: x })} editable={ed} label={L('السنة الأولى تُحسب نسبةً لأشهر الخدمة', 'Prorate the first year by months served')} /></>} />
      <PolicyCard k="leave_rules" title={L('قواعد طلب الإجازة', 'Leave request rules')} data={data} editable={ed} render={(v, set) => <LeaveRulesEditor v={v} set={set} editable={ed} />} />
    </div>
    <PolicyCard k="leave_types" title={L('أنواع الإجازات', 'Leave types')} data={data} editable={ed} render={(v, set) => <LeaveTypesEditor v={v} set={set} editable={ed} />}
      hint={L('الأجر والرصيد والحد والمستند وشروط الخدمة لكل نوع. نوع عليه طلبات قيد الاعتماد لا يُحذف.', 'Pay, balance, limit, document and service conditions per type. A type with pending requests cannot be removed.')} />
    <h2 className="font-display font-bold text-[17px] pt-2">{L('الكشف الشهري والسلفة', 'Monthly sheet & advances')}</h2>
    <PolicyCard k="payroll" title={L('قواعد الكشف الشهري', 'Monthly sheet rules')} data={data} editable={ed}
      hint={L('المبالغ تقديرية حتى التكامل مع النظام المالي. التغيير يسري على الكشوف غير المغلقة عند إعادة احتسابها.', 'Amounts are estimates until the finance integration. Changes apply to open sheets when recalculated.')}
      render={(v, set) => <div className="grid lg:grid-cols-2 gap-x-8">
        <div>
          <label className="flex items-center justify-between gap-3 py-1.5 text-[13.5px]"><span>{L('بدء احتساب الدوام في النظام', 'Attendance counted from')}</span>
            <input type="date" disabled={!ed} value={v.start_date ?? ''} onChange={e => set({ ...v, start_date: e.target.value || null })} className="field !h-9 !w-40" /></label>
          {num(v.month_days, x => set({ ...v, month_days: x }), L('أيام الشهر للأجر اليومي', 'Month days for the daily wage'), ed, L('يوم', 'days'))}
          {num(v.day_hours, x => set({ ...v, day_hours: x }), L('ساعات اليوم لأجر الساعة', 'Day hours for the hourly wage'), ed, L('ساعة', 'h'))}
          {num(v.absent_days, x => set({ ...v, absent_days: x }), L('حسم الغياب غير المبرر', 'Unjustified absence deduction'), ed, L('يوم أجر', 'day wage'), 0.5)}
          {num(v.justified_absent_days, x => set({ ...v, justified_absent_days: x }), L('حسم الغياب المبرر', 'Justified absence deduction'), ed, L('يوم أجر', 'day wage'), 0.5)}
        </div>
        <div>
          {num(v.insurance_base * 100, x => set({ ...v, insurance_base: x / 100 }), L('أساس التأمينات من الراتب', 'Insurance base of salary'), ed, '%')}
          {num(v.insurance_rate * 100, x => set({ ...v, insurance_rate: x / 100 }), L('نسبة التأمينات (حصة العامل)', 'Insurance rate (employee)'), ed, '%', 0.5)}
          {num(v.advance_earned_ratio * 100, x => set({ ...v, advance_earned_ratio: x / 100 }), L('السلفة من المستحق حتى تاريخه', 'Advance of pay earned so far'), ed, '%')}
          {num(v.advance_max_ratio * 100, x => set({ ...v, advance_max_ratio: x / 100 }), L('سقف السلفة من الراتب', 'Advance cap of salary'), ed, '%')}
          {num(v.advance_per_month, x => set({ ...v, advance_per_month: x }), L('عدد السلف بالشهر', 'Advances per month'), ed, L('سلفة', 'adv.'))}
        </div></div>} />
    <h2 className="font-display font-bold text-[17px] pt-2">{L('الاستقالة وإنهاء الخدمة', 'Resignation & end of service')}</h2>
    <PolicyCard k="exit" title={L('قواعد الخروج', 'Exit rules')} data={data} editable={ed}
      hint={L('إجراء الشركة HR.01-03. القوائم (الأسباب، الأسئلة، جهات تسليم العهدة) في «إعدادات الخروج».', 'Company procedure HR.01-03. Lists (reasons, questions, clearance sections) live in «Exit settings».')}
      render={(v, set) => <div className="grid lg:grid-cols-2 gap-x-8">
        <div>
          {num(v.notice_days, x => set({ ...v, notice_days: x }), L('فترة الإخطار للاستقالة', 'Resignation notice period'), ed, L('يوم', 'days'))}
          {num(v.decision_days, x => set({ ...v, decision_days: x }), L('مهلة قرار المدير العام من التقديم', 'General manager decision period'), ed, L('يوم', 'days'))}
          {num(v.notice_pay_months, x => set({ ...v, notice_pay_months: x }), L('بدل الإشعار عند مخالفة الإخطار', 'Notice pay when the notice is not served'), ed, L('شهر أجر', 'months'), 0.5)}
        </div>
        <div>
          {num(v.death_grant_months, x => set({ ...v, death_grant_months: x }), L('إعانة الوفاة (فوق أجر شهر الوفاة)', 'Death grant (plus the month’s pay)'), ed, L('شهر أجر', 'months'), 0.5)}
          {num(v.insurance_days, x => set({ ...v, insurance_days: x }), L('مهلة الانفكاك من التأمينات بعد آخر يوم', 'Insurance disengagement after the last day'), ed, L('يوم', 'days'))}
        </div></div>} />
    <h2 className="font-display font-bold text-[17px] pt-2">{L('المخالفات والجزاءات', 'Violations & penalties')}</h2>
    <PolicyCard k="discipline" title={L('قواعد المخالفات', 'Discipline rules')} data={data} editable={ed}
      hint={L('لائحة الجزاءات (المادة 98): جزاء واحد للمخالفة، لا جزاء قبل سماع أقوال العامل ولا بعد مهلة القرار. الجزاءات لكل مخالفة في «دليل المخالفات».', 'Penalty rules (art. 98): one penalty per violation, none before hearing the employee or after the decision period. Penalties per violation live in the violations catalogue.')}
      render={(v, set) => <div className="grid lg:grid-cols-2 gap-x-8">
        <div>
          {num(v.cap_days, x => set({ ...v, cap_days: x }), L('سقف الحسم بالشهر', 'Monthly deduction cap'), ed, L('يوم أجر', 'day wage'), 0.25)}
          {num(v.deadline_days, x => set({ ...v, deadline_days: x }), L('مهلة القرار من اكتشاف المخالفة', 'Decision period from discovery'), ed, L('يوم', 'days'))}
          {num(v.repeat_months, x => set({ ...v, repeat_months: x }), L('مدة احتساب التكرار', 'Repeat window'), ed, L('شهر', 'months'))}
          {num(v.hr_max_days, x => set({ ...v, hr_max_days: x }), L('صلاحية الموارد البشرية حتى حسم', 'HR may deduct up to'), ed, L('يوم', 'days'), 0.25)}
        </div>
        <div>
          <p className="text-[12.5px] text-ink-3 py-1.5">{L('الاقتراح الآلي من الحضور خلال الشهر:', 'Monthly suggestions from attendance:')}</p>
          {num(v.suggest_late, x => set({ ...v, suggest_late: x }), L('تأخر غير مبرر', 'Unjustified lateness'), ed, L('مرة', 'times'))}
          {num(v.suggest_absent, x => set({ ...v, suggest_absent: x }), L('غياب غير مبرر', 'Unjustified absence'), ed, L('يوم', 'days'))}
          {num(v.suggest_early, x => set({ ...v, suggest_early: x }), L('خروج مبكر', 'Early leave'), ed, L('مرة', 'times'))}
          <p className="text-[12.5px] text-ink-3 py-1.5">{L('ما فوق صلاحية الموارد البشرية (والترفيع والفصل) يُرسل للمدير العام — سلسلة «penalty».', 'Anything above HR’s authority (and promotion/dismissal) goes to the general manager — the «penalty» chain.')}</p>
        </div></div>} />
  </div>
}

function LeaveRulesEditor({ v, set, editable }: { v: PolicyValues['leave_rules']; set: (v: PolicyValues['leave_rules']) => void; editable: boolean }) {
  const L = useL()
  const n = (k: keyof PolicyValues['leave_rules'], label: string, suffix: string) => num(v[k] as number, x => set({ ...v, [k]: x }), label, editable, suffix)
  const t = (k: keyof PolicyValues['leave_rules'], label: string) => <Toggle on={v[k] as boolean} set={x => set({ ...v, [k]: x })} editable={editable} label={label} />
  const notice = (i: number, p: Partial<PolicyValues['leave_rules']['notice'][number]>) => set({ ...v, notice: v.notice.map((x, j) => j === i ? { ...x, ...p } : x) })
  return <>
    <div className="text-[13px] font-semibold text-ink-2 pt-1">{L('مهلة التقديم حسب مدة الإجازة', 'Notice by leave length')}</div>
    {v.notice.map((x, i) => <div key={i} className="flex flex-wrap items-center gap-2 py-1 text-[13px]">
      <span>{L('حتى', 'Up to')}</span><input type="number" min={1} disabled={!editable} className="field !h-9 !w-16 num text-center" value={x.max_days} onChange={e => notice(i, { max_days: Number(e.target.value) })} />
      <span>{L('يوم ← قبل', 'days → ')}</span><input type="number" min={0} disabled={!editable} className="field !h-9 !w-16 num text-center" value={x.days_before} onChange={e => notice(i, { days_before: Number(e.target.value) })} />
      <span>{L('يوم', 'days before')}</span>
      {editable && v.notice.length > 1 && <button className="p-1 rounded hover:bg-sub text-ink-3" onClick={() => set({ ...v, notice: v.notice.filter((_, j) => j !== i) })} aria-label={L('حذف', 'Delete')}><Trash2 size={14} /></button>}
    </div>)}
    {editable && v.notice.length < 10 && <button className="text-brand text-[12.5px] font-semibold" onClick={() => set({ ...v, notice: [...v.notice, { max_days: (v.notice.at(-1)?.max_days ?? 0) + 7, days_before: 14 }] })}>+ {L('شريحة', 'Tier')}</button>}
    {n('hourly_notice_days', L('مهلة الإجازة الساعية', 'Hourly leave notice'), L('يوم', 'days'))}
    {n('hourly_max_per_day', L('أقصى ساعات في اليوم', 'Max hours a day'), L('ساعة', 'h'))}
    {n('hourly_day_hours', L('ساعات تعادل يوماً من الرصيد', 'Hours that equal one day'), L('ساعة', 'h'))}
    {n('emergency_per_year', L('الطارئة في السنة', 'Emergency per year'), L('يوم', 'days'))}
    {n('emergency_report_hours', L('الإبلاغ عن الطارئة خلال', 'Report emergency within'), L('ساعة', 'h'))}
    {n('unpaid_max_per_year', L('غير المدفوعة في السنة', 'Unpaid per year'), L('يوم', 'days'))}
    {n('exec_after_days', L('مستوى الإدارة العليا لما يزيد على', 'Executive level above'), L('يوم', 'days'))}
    {n('reject_min', L('أقل طول لسبب الرفض', 'Min rejection reason'), L('حرف', 'chars'))}
    {n('reminder_hours', L('تذكير المعتمِد بعد', 'Remind approver after'), L('ساعة', 'h'))}
    {n('justify_hours', L('مهلة تبرير التأخير/الغياب', 'Justification window'), L('ساعة', 'h'))}
    {t('substitute_required', L('الموظف البديل إلزامي', 'Substitute is required'))}
    {t('conflict_note_required', L('ملاحظة إلزامية عند تعارض الإجازات في الإدارة', 'Note required on a department clash'))}
    {t('probation_paid', L('إجازة مأجورة أثناء فترة التجربة', 'Paid leave during probation'))}
  </>
}

function LeaveTypesEditor({ v, set, editable }: { v: LeaveTypePolicy[]; set: (v: LeaveTypePolicy[]) => void; editable: boolean }) {
  const L = useL(); const lang = useLang()
  const [open, setOpen] = useState<number | null>(null)
  const up = (i: number, p: Partial<LeaveTypePolicy>) => set(v.map((x, j) => j === i ? { ...x, ...p } : x))
  const add = () => { set([...v, { key: `type_${v.length + 1}`, ar: 'نوع جديد', en: 'New type', paid: 100, balance: null, unit: 'day', max_days: null, notice: true, doc: 'none', after_months: 0, confirmed: false, once: false, calendar: false, exec: false }]); setOpen(v.length) }
  const bal = (b: LeaveTypePolicy['balance']) => b === 'annual' ? L('من السنوية', 'annual') : b === 'sick' ? L('رصيد مرضي', 'sick') : b === 'unpaid' ? L('بلا راتب', 'unpaid') : L('بلا رصيد', 'no balance')
  return <div className="grid [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))] gap-3">
    {v.map((x, i) => <div key={i} className="rounded-xl border border-line p-3 text-[13px]">
      <div className="flex items-start justify-between gap-2"><div className="min-w-0"><div className="font-semibold truncate">{bi(x, lang)}</div><div className="text-[12px] text-ink-3 ltr text-start">{x.key}</div></div>
        <span className="flex gap-1 shrink-0">{editable && <button className="text-brand text-[12.5px] font-semibold px-1" onClick={() => setOpen(open === i ? null : i)}>{open === i ? L('إغلاق', 'Close') : L('تعديل', 'Edit')}</button>}
          {editable && <button className="p-1 rounded hover:bg-sub text-ink-3" onClick={() => set(v.filter((_, j) => j !== i))} aria-label={L('حذف', 'Delete')}><Trash2 size={14} /></button>}</span></div>
      <div className="mt-1 flex flex-wrap gap-1"><Badge>{x.paid}{lang === 'ar' ? '٪' : '%'}</Badge><Badge>{bal(x.balance)}</Badge>{x.max_days && <Badge>≤ {x.max_days} {x.unit === 'hour' ? L('س', 'h') : L('يوم', 'd')}</Badge>}
        {x.doc !== 'none' && <Badge tone={x.doc === 'required' ? 'warn' : 'neutral'}>{x.doc === 'required' ? L('مستند إلزامي', 'doc required') : L('مستند اختياري', 'doc optional')}</Badge>}
        {x.after_months > 0 && <Badge>{L(`بعد ${x.after_months} شهر`, `after ${x.after_months} mo`)}</Badge>}{x.exec && <Badge tone="info">{L('إدارة عليا', 'executive')}</Badge>}</div>
      {open === i && <div className="mt-3 space-y-2 border-t border-line-2 pt-3">
        <div className="grid grid-cols-2 gap-2"><input className="field !h-9" value={x.ar} onChange={e => up(i, { ar: e.target.value })} aria-label="ar" /><input className="field !h-9 ltr" value={x.en} onChange={e => up(i, { en: e.target.value })} aria-label="en" /></div>
        <input className="field !h-9 ltr" value={x.key} onChange={e => up(i, { key: e.target.value.toLowerCase() })} aria-label={L('المفتاح', 'Key')} />
        {num(x.paid, p => up(i, { paid: p }), L('نسبة الأجر', 'Paid'), true, '%')}
        {num(x.max_days ?? 0, p => up(i, { max_days: p || null }), L('الحد للطلب (0 = بلا حد)', 'Max per request (0 = none)'), true, x.unit === 'hour' ? L('ساعة', 'h') : L('يوم', 'days'))}
        {num(x.after_months, p => up(i, { after_months: p }), L('بعد خدمة', 'After service of'), true, L('شهر', 'mo'))}
        <div className="grid grid-cols-3 gap-2">
          <select className="field !h-9" value={x.balance ?? ''} onChange={e => up(i, { balance: (e.target.value || null) as LeaveTypePolicy['balance'] })} aria-label={L('الرصيد', 'Balance')}>
            {(['annual', 'sick', 'unpaid', ''] as const).map(b => <option key={b} value={b}>{bal(b || null)}</option>)}</select>
          <select className="field !h-9" value={x.unit} onChange={e => up(i, { unit: e.target.value as 'day' | 'hour' })} aria-label={L('الوحدة', 'Unit')}><option value="day">{L('أيام', 'Days')}</option><option value="hour">{L('ساعات', 'Hours')}</option></select>
          <select className="field !h-9" value={x.doc} onChange={e => up(i, { doc: e.target.value as LeaveTypePolicy['doc'] })} aria-label={L('المستند', 'Document')}>
            <option value="none">{L('بلا مستند', 'No doc')}</option><option value="optional">{L('اختياري', 'Optional')}</option><option value="required">{L('إلزامي', 'Required')}</option></select>
        </div>
        <Toggle on={x.notice} set={b => up(i, { notice: b })} editable label={L('تطبيق مهلة التقديم', 'Apply the notice period')} />
        <Toggle on={x.calendar} set={b => up(i, { calendar: b })} editable label={L('أيام تقويمية (تشمل العطل)', 'Calendar days (incl. rest days)')} />
        <Toggle on={x.confirmed} set={b => up(i, { confirmed: b })} editable label={L('بعد التثبيت فقط', 'Only after confirmation')} />
        <Toggle on={x.once} set={b => up(i, { once: b })} editable label={L('مرة واحدة طوال الخدمة', 'Once during service')} />
        <Toggle on={x.exec} set={b => up(i, { exec: b })} editable label={L('تحتاج الإدارة العليا دائماً', 'Always needs the executive')} />
      </div>}
    </div>)}
    {editable && <button className="rounded-xl border border-dashed border-line p-3 text-brand text-[13px] font-semibold hover:border-brand/50" onClick={add}>+ {L('نوع إجازة', 'Leave type')}</button>}
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
