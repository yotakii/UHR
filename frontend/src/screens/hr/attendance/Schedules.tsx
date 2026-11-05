import { useMemo, useState } from 'react'
import { CalendarRange, Clock, Plus, Trash2, UserPlus, Users, Pencil, MapPin } from 'lucide-react'
import { useL, useLang, bi } from '../../../lib/store'
import { ApiError } from '../../../lib/api'
import { hm, toMins, useAssignSchedule, useBranches, useDeleteSchedule, useSaveBranch, useSaveSchedule, useSchedules, useUnassign, type Branch, type SchedulesData, type ShiftDef, type WorkSchedule } from '../../../lib/attendance'
import { Badge, Btn, Empty, Field, Modal, PageHead, Panel, Seg, toast } from '../../../ui/kit'
import { DecisionDialog } from '../../../ui/decision'

/** أيام الأسبوع بترتيب Carbon (0 = الأحد) معروضة من السبت */
const DAYS: [number, string, string][] = [[6, 'السبت', 'Sat'], [0, 'الأحد', 'Sun'], [1, 'الاثنين', 'Mon'], [2, 'الثلاثاء', 'Tue'], [3, 'الأربعاء', 'Wed'], [4, 'الخميس', 'Thu'], [5, 'الجمعة', 'Fri']]
const TONE: Record<string, string> = { brand: 'bg-brand', brass: 'bg-brass', info: 'bg-info', danger: 'bg-danger', 'ink-3': 'bg-ink-3' }
const blank = (): Omit<WorkSchedule, 'id'> => ({ name_ar: '', name_en: '', kind: 'fixed', shifts: [{ key: 'main', ar: 'الدوام', en: 'Shift', start: 540, end: 1020 }], work_days: [6, 0, 1, 2, 3, 4], season_from: null, season_to: null, color: 'brand', active: true })

/**
 * جداول الدوام (ملاحظات الاختبار 24 و76): أكثر من نظام دوام في الشركة — دوام ثابت (مثل دوام المدراء)، ورديات (صباحية/مسائية)،
 * وقوالب موسمية بتاريخ سريان مقترح. تُسند للموظف دائماً أو لفترة (مناوبة/موسم)، والفترة تتقدم على الدائم، والدائم على سياسة الإدارة والشركة.
 */
export function Schedules() {
  const L = useL(); const lang = useLang()
  const { data: d } = useSchedules()
  const [edit, setEdit] = useState<(Omit<WorkSchedule, 'id'> & { id?: number }) | null>(null)
  const [assign, setAssign] = useState<WorkSchedule | 'policy' | null>(null)
  const [del, setDel] = useState<WorkSchedule | null>(null)
  const unassign = useUnassign(); const remove = useDeleteSchedule()
  if (!d) return <div className="max-w-[1200px] mx-auto"><PageHead title={L('جداول الدوام والورديات', 'Work schedules & shifts')} /><Panel><Empty title={L('جارٍ التحميل…', 'Loading…')} /></Panel></div>
  const span = (s: ShiftDef) => <span className="ltr inline-block num">{hm(s.start)}–{hm(s.end)}</span>
  return <div className="max-w-[1200px] mx-auto space-y-5">
    <PageHead title={L('جداول الدوام والورديات', 'Work schedules & shifts')}
      sub={L('أنشئ أكثر من نظام دوام (ثابت، ورديات، موسمي) وأسنده للموظفين دائماً أو لفترة. من لا جدول له يتبع سياسة الدوام لإدارته أو للشركة.', 'Create several work systems (fixed, shifts, seasonal) and assign them permanently or for a period. Employees without one follow their department or company policy.')}
      actions={d.can.edit && <Btn kind="primary" icon={<Plus size={16} />} onClick={() => setEdit(blank())}>{L('جدول جديد', 'New schedule')}</Btn>} />
    {d.data.length === 0 ? <Panel><Empty title={L('لا توجد جداول بعد', 'No schedules yet')}>{L('كل الموظفين على سياسة الدوام العامة. أنشئ جدولاً لدوام المدراء أو للورديات.', 'Everyone follows the general work policy. Create a schedule for managers or shifts.')}</Empty></Panel>
      : <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(320px,1fr))]">{d.data.map(s => <article key={s.id} className={`rounded-2xl border border-line bg-surface p-4 ${s.active ? '' : 'opacity-60'}`}>
        <div className="flex items-start gap-3"><span className={`size-3 rounded-full mt-1.5 shrink-0 ${TONE[s.color] ?? 'bg-brand'}`} />
          <div className="flex-1 min-w-0"><div className="font-semibold">{lang === 'en' && s.name_en ? s.name_en : s.name_ar}</div>
            <div className="flex flex-wrap gap-1.5 mt-1">{s.kind === 'shifts' ? <Badge tone="info">{L('ورديات', 'Shifts')}</Badge> : <Badge>{L('دوام ثابت', 'Fixed')}</Badge>}
              {(s.season_from || s.season_to) && <Badge tone="brass"><CalendarRange size={12} />{L('موسمي', 'Seasonal')}</Badge>}{!s.active && <Badge tone="danger">{L('موقوف', 'Inactive')}</Badge>}</div></div>
          {d.can.edit && <button className="p-1.5 rounded hover:bg-sub text-ink-3" onClick={() => setEdit({ ...s })} aria-label={L('تعديل', 'Edit')}><Pencil size={15} /></button>}</div>
        <ul className="mt-3 space-y-1 text-[13px]">{s.shifts.map(sh => <li key={sh.key} className="flex items-center gap-2"><Clock size={13} className="text-ink-3" /><span className="flex-1">{bi(sh, lang)}</span>{span(sh)}{sh.grace ? <span className="text-ink-3 text-[12px]">{L(`سماح ${sh.grace} د`, `${sh.grace}m grace`)}</span> : null}</li>)}</ul>
        <div className="flex flex-wrap gap-1 mt-2">{DAYS.map(([n, ar, en]) => <span key={n} className={`text-[11px] rounded px-1.5 py-0.5 border ${s.work_days.includes(n) ? 'border-brand/40 bg-brand-soft text-brand' : 'border-line text-ink-3'}`}>{L(ar, en)}</span>)}</div>
        {(s.season_from || s.season_to) && <div className="text-[12px] text-ink-3 mt-2">{L('الموسم المقترح', 'Suggested season')}: <span className="ltr inline-block">{s.season_from ?? '…'} → {s.season_to ?? '…'}</span></div>}
        <div className="flex items-center justify-between gap-2 mt-3 pt-3 border-t border-line-2">
          <span className="text-[12.5px] text-ink-2 flex items-center gap-1"><Users size={14} />{L(`${s.employees ?? 0} موظف دائم`, `${s.employees ?? 0} permanent`)}</span>
          {d.can.edit && <div className="flex gap-1.5"><Btn size="sm" icon={<UserPlus size={14} />} onClick={() => setAssign(s)}>{L('إسناد', 'Assign')}</Btn>
            {!s.employees && <Btn size="sm" kind="ghost" onClick={() => setDel(s)} aria-label={L('حذف', 'Delete')}><Trash2 size={14} /></Btn>}</div>}
        </div>
      </article>)}</div>}

    <Panel title={L('الإسنادات بفترة (المناوبات والمواسم)', 'Period assignments (rota & seasons)')} sub={L('الإسناد بفترة يتقدم على جدول الموظف الدائم خلال تواريخه.', 'A period assignment overrides the permanent schedule during its dates.')} pad={false}
      action={d.can.edit && <Btn size="sm" onClick={() => setAssign('policy')}>{L('إعادة موظفين إلى السياسة العامة', 'Back to the general policy')}</Btn>}>
      {d.assignments.length === 0 ? <Empty title={L('لا توجد إسنادات حالية أو قادمة', 'No current or upcoming assignments')} /> : <table className="tbl">
        <thead><tr><th>{L('الموظف', 'Employee')}</th><th>{L('الجدول / الوردية', 'Schedule / shift')}</th><th className="hidden sm:table-cell">{L('الفترة', 'Period')}</th>{d.can.edit && <th />}</tr></thead>
        <tbody>{d.assignments.map(a => <tr key={a.id}>
          <td><div className="font-medium">{lang === 'en' ? a.employee.en : a.employee.ar}</div><div className="text-[12px] text-ink-3 ltr text-start">{a.employee.code}</div></td>
          <td>{bi(a.schedule, lang)}{a.shift && <div className="text-[12px] text-ink-3">{bi(a.shift, lang)} · {span(a.shift)}</div>}<div className="sm:hidden text-[12px] ltr text-start">{a.from} → {a.to ?? '…'}</div></td>
          <td className="hidden sm:table-cell ltr num">{a.from} → {a.to ?? L('مفتوحة', 'open')}</td>
          {d.can.edit && <td><button className="p-1.5 rounded hover:bg-sub text-ink-3 hover:text-danger" aria-label={L('إلغاء الإسناد', 'Remove')} onClick={() => unassign.mutateAsync(a.id).then(() => toast(L('أُلغي الإسناد', 'Removed')))}><Trash2 size={15} /></button></td>}
        </tr>)}</tbody></table>}
    </Panel>
    {edit && <ScheduleForm v={edit} onClose={() => setEdit(null)} />}
    {assign && <AssignModal d={d} s={assign === 'policy' ? null : assign} onClose={() => setAssign(null)} />}
    <DecisionDialog open={!!del} onClose={() => setDel(null)} tone="danger" title={L('حذف الجدول', 'Delete schedule')} subject={del?.name_ar} confirmLabel={L('حذف', 'Delete')}
      onConfirm={() => remove.mutateAsync(del!.id).then(() => toast(L('حُذف الجدول', 'Deleted')))} />
  </div>
}

function ScheduleForm({ v: init, onClose }: { v: Omit<WorkSchedule, 'id'> & { id?: number }; onClose: () => void }) {
  const L = useL()
  const save = useSaveSchedule()
  const [v, setV] = useState(init)
  const err = save.error instanceof ApiError ? save.error : null
  const setShift = (i: number, p: Partial<ShiftDef>) => setV({ ...v, shifts: v.shifts.map((s, j) => j === i ? { ...s, ...p } : s) })
  const shifts = v.kind === 'fixed' ? v.shifts.slice(0, 1) : v.shifts
  return <Modal open onClose={onClose} width={720} title={v.id ? L('تعديل جدول الدوام', 'Edit schedule') : L('جدول دوام جديد', 'New work schedule')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate({ ...v, shifts }, { onSuccess: () => { toast(L('حُفظ الجدول', 'Saved')); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('اسم الجدول', 'Name')} req error={err?.field('name_ar')}><input className="field" value={v.name_ar} onChange={e => setV({ ...v, name_ar: e.target.value })} placeholder={L('مثلاً: دوام المدراء، ورديات الإنتاج، دوام رمضان', 'e.g. Managers, Production shifts, Ramadan hours')} /></Field>
      <Field label={L('الاسم بالإنكليزية', 'English name')}><input className="field ltr" value={v.name_en ?? ''} onChange={e => setV({ ...v, name_en: e.target.value })} /></Field>
      <Field div label={L('النوع', 'Type')}><Seg value={v.kind} onChange={k => setV({ ...v, kind: k, shifts: k === 'shifts' && v.shifts.length < 2 ? [...v.shifts, { key: 'pm', ar: 'مسائية', en: 'Evening', start: 900, end: 1380 }] : v.shifts })}
        options={[{ v: 'fixed', label: L('دوام ثابت', 'Fixed hours') }, { v: 'shifts', label: L('ورديات', 'Shifts') }]} /></Field>
      <Field div label={L('اللون', 'Colour')}><div className="flex gap-2 pt-1">{Object.entries(TONE).map(([k, c]) => <button key={k} type="button" onClick={() => setV({ ...v, color: k })} aria-label={k} className={`size-7 rounded-full ${c} ${v.color === k ? 'ring-2 ring-offset-2 ring-brand ring-offset-surface' : ''}`} />)}</div></Field>
    </div>
    <div className="mt-4"><div className="text-[13px] font-medium text-ink-2 mb-1.5">{v.kind === 'shifts' ? L('الورديات', 'Shifts') : L('ساعات الدوام', 'Working hours')}</div>
      <div className="space-y-2">{shifts.map((s, i) => <div key={i} className="grid grid-cols-2 sm:grid-cols-[1fr_110px_110px_90px_auto] gap-2 items-end">
        <Field label={L('الاسم', 'Name')} error={err?.field(`shifts.${i}.ar`)}><input className="field" value={s.ar} onChange={e => setShift(i, { ar: e.target.value, key: s.key || `s${i + 1}` })} /></Field>
        <Field label={L('من', 'From')}><input type="time" className="field" value={hm(s.start)} onChange={e => setShift(i, { start: toMins(e.target.value) })} /></Field>
        <Field label={L('إلى', 'To')} error={err?.field(`shifts.${i}.end`)}><input type="time" className="field" value={hm(s.end)} onChange={e => setShift(i, { end: toMins(e.target.value) })} /></Field>
        <Field label={L('سماح (د)', 'Grace')}><input type="number" min={0} max={120} className="field num" value={s.grace ?? ''} onChange={e => setShift(i, { grace: e.target.value === '' ? undefined : Number(e.target.value) })} /></Field>
        {v.kind === 'shifts' && shifts.length > 1 ? <button type="button" className="h-10 px-2 text-ink-3 hover:text-danger" onClick={() => setV({ ...v, shifts: v.shifts.filter((_, j) => j !== i) })} aria-label={L('حذف', 'Remove')}><Trash2 size={16} /></button> : <span />}
      </div>)}</div>
      {v.kind === 'shifts' && v.shifts.length < 6 && <button type="button" className="mt-2 text-brand text-[13px] font-semibold inline-flex items-center gap-1" onClick={() => setV({ ...v, shifts: [...v.shifts, { key: `s${v.shifts.length + 1}`, ar: '', en: '', start: 480, end: 960 }] })}><Plus size={14} />{L('وردية', 'Shift')}</button>}
      <p className="text-[12px] text-ink-3 mt-1.5">{L('الوردية ضمن اليوم نفسه (البداية قبل النهاية). الاستراحة وحد الإضافي وبداية الليل من سياسة الدوام العامة.', 'A shift starts and ends on the same day. Break, overtime threshold and night start come from the general work policy.')}</p></div>
    <Field div label={L('أيام العمل', 'Working days')} className="mt-4" error={err?.field('work_days')}><div className="flex flex-wrap gap-1.5">{DAYS.map(([n, ar, en]) => { const on = v.work_days.includes(n)
      return <button key={n} type="button" aria-pressed={on} onClick={() => setV({ ...v, work_days: on ? v.work_days.filter(x => x !== n) : [...v.work_days, n] })} className={`h-9 px-3 rounded-lg border text-[13px] ${on ? 'bg-brand-soft border-brand/50 text-brand' : 'border-line'}`}>{L(ar, en)}</button> })}</div></Field>
    <div className="grid sm:grid-cols-2 gap-3 mt-4">
      <Field label={L('قالب موسمي — من', 'Seasonal template — from')} hint={L('اختياري: لعقود أو دوام موسمي (رمضان، الصيف…). يُقترح عند الإسناد.', 'Optional: for seasonal contracts or hours (Ramadan, summer…). Suggested when assigning.')}><input type="date" className="field" value={v.season_from ?? ''} onChange={e => setV({ ...v, season_from: e.target.value || null })} /></Field>
      <Field label={L('إلى', 'To')} error={err?.field('season_to')}><input type="date" className="field" value={v.season_to ?? ''} onChange={e => setV({ ...v, season_to: e.target.value || null })} /></Field>
    </div>
    {v.id && <label className="flex items-center gap-2 mt-3 text-[13.5px]"><input type="checkbox" className="accent-[var(--primary)] size-4" checked={v.active} onChange={e => setV({ ...v, active: e.target.checked })} />{L('فعّال (الموقوف لا يُسند لموظفين جدد)', 'Active (an inactive schedule cannot be assigned)')}</label>}
    {err && !Object.keys(err.errors).length && <p role="alert" className="mt-3 text-[13px] text-danger">{err.message}</p>}
  </Modal>
}

function AssignModal({ d, s, onClose }: { d: SchedulesData; s: WorkSchedule | null; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const assign = useAssignSchedule()
  const [mode, setMode] = useState<'permanent' | 'period'>(s?.season_from ? 'period' : 'permanent')
  const [shift, setShift] = useState(s?.shifts[0]?.key ?? '')
  const [from, setFrom] = useState(s?.season_from ?? new Date().toLocaleDateString('en-CA'))
  const [to, setTo] = useState(s?.season_to ?? '')
  const [dept, setDept] = useState<number | ''>('')
  const [q, setQ] = useState('')
  const [ids, setIds] = useState<number[]>([])
  const err = assign.error instanceof ApiError ? assign.error : null
  const list = useMemo(() => d.employees.filter(e => (!dept || e.department_id === dept) && (!q || (e.ar + e.en + e.code).toLowerCase().includes(q.toLowerCase()))), [d.employees, dept, q])
  const all = list.length > 0 && list.every(e => ids.includes(e.id))
  const submit = () => assign.mutate({ employee_ids: ids, work_schedule_id: s?.id ?? null, mode: s ? mode : 'permanent', shift_key: mode === 'period' ? shift : undefined, from: mode === 'period' ? from : undefined, to: mode === 'period' && to ? to : undefined },
    { onSuccess: r => { toast(L(`أُسند لـ ${r.count} موظف`, `Assigned to ${r.count}`)); onClose() } })
  return <Modal open onClose={onClose} width={680} title={s ? L(`إسناد «${s.name_ar}»`, `Assign “${s.name_en || s.name_ar}”`) : L('إعادة إلى سياسة الدوام العامة', 'Back to the general work policy')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={!ids.length || assign.isPending} onClick={submit}>{L(`إسناد (${ids.length})`, `Assign (${ids.length})`)}</Btn></>}>
    {s && <><Field div label={L('نوع الإسناد', 'Assignment')}><Seg value={mode} onChange={setMode} options={[{ v: 'permanent', label: L('دائم (جدول الموظف)', 'Permanent') }, { v: 'period', label: L('لفترة (مناوبة / موسم)', 'For a period (rota / season)') }]} /></Field>
      {mode === 'period' && <div className="grid sm:grid-cols-3 gap-3 mt-3">
        {s.kind === 'shifts' && <Field label={L('الوردية', 'Shift')}><select className="field" value={shift} onChange={e => setShift(e.target.value)}>{s.shifts.map(x => <option key={x.key} value={x.key}>{bi(x, lang)} {hm(x.start)}–{hm(x.end)}</option>)}</select></Field>}
        <Field label={L('من', 'From')} req error={err?.field('from')}><input type="date" className="field" value={from} onChange={e => setFrom(e.target.value)} /></Field>
        <Field label={L('إلى', 'To')} hint={L('فارغ = مفتوحة', 'Empty = open')} error={err?.field('to')}><input type="date" className="field" value={to} onChange={e => setTo(e.target.value)} /></Field>
      </div>}</>}
    {!s && <p className="text-[13px] text-ink-2">{L('يُزال الجدول الدائم عن الموظفين المختارين فيتبعون سياسة الدوام لإدارتهم أو للشركة.', 'The permanent schedule is removed; they follow their department or company policy.')}</p>}
    <div className="flex flex-wrap gap-2 mt-4">
      <input className="field flex-1 min-w-[160px]" placeholder={L('بحث بالاسم أو الرقم', 'Search name or ID')} value={q} onChange={e => setQ(e.target.value)} />
      <select className="field !w-auto" value={dept} onChange={e => setDept(e.target.value ? Number(e.target.value) : '')} aria-label={L('الإدارة', 'Department')}>
        <option value="">{L('كل الإدارات', 'All departments')}</option>{d.departments.map(x => <option key={x.id} value={x.id}>{bi(x, lang)}</option>)}</select>
      <Btn onClick={() => setIds(all ? ids.filter(i => !list.some(e => e.id === i)) : [...new Set([...ids, ...list.map(e => e.id)])])}>{all ? L('إلغاء التحديد', 'Clear') : L('تحديد الظاهرين', 'Select shown')}</Btn>
    </div>
    <ul className="mt-2 max-h-[280px] overflow-y-auto border border-line rounded-lg divide-y divide-line-2">{list.map(e => { const on = ids.includes(e.id)
      return <li key={e.id}><label className="flex items-center gap-3 px-3 py-2 text-[13.5px] cursor-pointer hover:bg-sub/50"><input type="checkbox" className="accent-[var(--primary)] size-4" checked={on} onChange={() => setIds(on ? ids.filter(x => x !== e.id) : [...ids, e.id])} />
        <span className="flex-1 min-w-0 truncate">{lang === 'en' ? e.en : e.ar}</span><span className="text-[12px] text-ink-3 ltr">{e.code}</span>
        {e.work_schedule_id && <Badge>{d.data.find(x => x.id === e.work_schedule_id)?.name_ar}</Badge>}</label></li> })}</ul>
    {err && <p role="alert" className="mt-3 text-[13px] text-danger">{err.field('from') ?? err.message}</p>}
  </Modal>
}

/** الفروع ومناطقها الزمنية (ملاحظة الاختبار 80): المدن المشتركة للقراءة، وفروع الشركة تُضاف وتُعدّل */
export function Branches() {
  const L = useL(); const lang = useLang()
  const { data: d } = useBranches()
  const save = useSaveBranch()
  const [edit, setEdit] = useState<(Partial<Branch> & { name_ar: string; name_en: string; timezone: string }) | null>(null)
  const err = save.error instanceof ApiError ? save.error : null
  if (!d) return <div className="max-w-[1000px] mx-auto"><PageHead title={L('الفروع والمناطق الزمنية', 'Branches & time zones')} /><Panel><Empty title={L('جارٍ التحميل…', 'Loading…')} /></Panel></div>
  return <div className="max-w-[1000px] mx-auto">
    <PageHead title={L('الفروع والمناطق الزمنية', 'Branches & time zones')} sub={L('أوقات حضور كل موظف تُحسب بتوقيت فرعه (مقر العمل في ملفه). أضف فروع شركتك خارج المدن المشتركة.', 'Each employee’s attendance is calculated in their branch’s time (work location). Add your own branches beyond the shared cities.')}
      actions={d.can.edit && <Btn kind="primary" icon={<Plus size={16} />} onClick={() => { save.reset(); setEdit({ code: '', name_ar: '', name_en: '', timezone: 'Asia/Damascus' }) }}>{L('فرع جديد', 'New branch')}</Btn>} />
    <Panel pad={false}><table className="tbl"><thead><tr><th>{L('الفرع', 'Branch')}</th><th>{L('المنطقة الزمنية', 'Time zone')}</th><th className="hidden sm:table-cell">{L('الموظفون', 'Employees')}</th><th /></tr></thead>
      <tbody>{d.data.map(b => <tr key={b.id} className={b.active ? '' : 'opacity-60'}>
        <td><div className="font-medium flex items-center gap-1.5"><MapPin size={14} className="text-ink-3" />{lang === 'en' ? b.en : b.ar}</div><div className="text-[12px] text-ink-3">{b.own ? L('فرع الشركة', 'Company branch') : L('مدينة مشتركة', 'Shared city')}</div></td>
        <td className="ltr text-start num">{b.timezone}</td><td className="hidden sm:table-cell num">{b.employees}</td>
        <td>{b.own && d.can.edit && <button className="p-1.5 rounded hover:bg-sub text-ink-3" onClick={() => { save.reset(); setEdit({ ...b, name_ar: b.ar, name_en: b.en }) }} aria-label={L('تعديل', 'Edit')}><Pencil size={15} /></button>}</td></tr>)}</tbody></table></Panel>
    {edit && <Modal open onClose={() => setEdit(null)} title={edit.id ? L('تعديل فرع', 'Edit branch') : L('فرع جديد', 'New branch')}
      footer={<><Btn onClick={() => setEdit(null)}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate({ id: edit.id, code: edit.id ? undefined : edit.code, name_ar: edit.name_ar, name_en: edit.name_en, timezone: edit.timezone, active: edit.active ?? true }, { onSuccess: () => { toast(L('حُفظ الفرع', 'Saved')); setEdit(null) } })}>{L('حفظ', 'Save')}</Btn></>}>
      <div className="grid sm:grid-cols-2 gap-3">
        <Field label={L('الاسم', 'Name')} req error={err?.field('name_ar')}><input className="field" value={edit.name_ar} onChange={e => setEdit({ ...edit, name_ar: e.target.value })} /></Field>
        <Field label={L('الاسم بالإنكليزية', 'English name')} req error={err?.field('name_en')}><input className="field ltr" value={edit.name_en} onChange={e => setEdit({ ...edit, name_en: e.target.value })} /></Field>
        <Field label={L('الرمز', 'Code')} req={!edit.id} error={err?.field('code')} hint={L('أحرف إنكليزية صغيرة، لا يتغير بعد الإنشاء', 'Lowercase letters; fixed after creation')}><input className="field ltr" disabled={!!edit.id} value={edit.code ?? ''} onChange={e => setEdit({ ...edit, code: e.target.value.toLowerCase().replace(/[^a-z0-9_-]/g, '') })} /></Field>
        <Field label={L('المنطقة الزمنية', 'Time zone')} req><select className="field ltr" value={edit.timezone} onChange={e => setEdit({ ...edit, timezone: e.target.value })}>{d.timezones.map(z => <option key={z}>{z}</option>)}</select></Field>
      </div>
      {edit.id && <label className="flex items-center gap-2 mt-3 text-[13.5px]"><input type="checkbox" className="accent-[var(--primary)] size-4" checked={edit.active ?? true} onChange={e => setEdit({ ...edit, active: e.target.checked })} />{L('فعّال', 'Active')}</label>}
      {err && !Object.keys(err.errors).length && <p role="alert" className="mt-3 text-[13px] text-danger">{err.message}</p>}
    </Modal>}
  </div>
}
