import { useEffect, useState } from 'react'
import { Loader2, Plus, RotateCcw, Trash2 } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { useMissionSettings, useSaveMissionSettings } from '../../lib/missions'
import { Btn, Field, PageHead, Panel, Seg, toast } from '../../ui/kit'

type Rate = { grade: string; local: number; local_currency: string; foreign: number; foreign_currency: string; hotel_local: number; hotel_local_currency: string; hotel_foreign: number; hotel_foreign_currency: string; ticket: 'economy' | 'business'; valid_from: string }
type S = Record<string, unknown> & { rates: Rate[]; stages: { name: string; en: string; weight: number }[]; completion_bonus: Record<string, number>; trainer_rates: Record<string, number>; proof_types: string[] }

/** إعدادات المهمات والسفر والمكافآت (الدفعة 20): جدول المعدلات بالدرجة والعملة وتاريخ السريان، وكل القواعد */
export function MissionSettings() {
  const L = useL(); const lang = useLang()
  const { data } = useMissionSettings(); const save = useSaveMissionSettings()
  const [v, setV] = useState<S | null>(null)
  useEffect(() => { if (data && !v) setV(structuredClone(data.settings) as S) }, [data, v])
  if (!data || !v) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const err = save.error instanceof ApiError ? save.error : null
  const set = (k: string, x: unknown) => setV({ ...v, [k]: x })
  const num = (k: string, ar: string, en: string, hint?: string) => <Field label={L(ar, en)} hint={hint} error={err?.field(k)}><input type="number" className="field num" value={String(v[k] ?? '')} onChange={e => set(k, Number(e.target.value))} /></Field>
  const cur = (k: string, ar: string, en: string) => <Field label={L(ar, en)}><select className="field" value={String(v[k])} onChange={e => set(k, e.target.value)}>{data.currencies.map(c => <option key={c.code}>{c.code}</option>)}</select></Field>
  const editRate = (i: number, k: keyof Rate, x: unknown) => set('rates', v.rates.map((r, j) => j === i ? { ...r, [k]: x } : r))
  const curSel = (i: number, k: keyof Rate) => <select className="field !px-1 ltr" value={String(v.rates[i][k])} onChange={e => editRate(i, k, e.target.value)} aria-label="currency">{data.currencies.map(c => <option key={c.code}>{c.code}</option>)}</select>
  const kmPrice = Number(v.fuel_price) / Math.max(1, Number(v.km_per_tank)) * (1 + Number(v.depreciation_pct) / 100)
  return <div className="max-w-[1200px] mx-auto space-y-5">
    <PageHead title={L('إعدادات المهمات والسفر والمكافآت', 'Missions, travel & bonus settings')} sub={L('من «سياسات مصاريف ونفقات العمل» — كل القيم افتراضية قابلة للتعديل.', "From the company's business expenses policy — every value is an editable default.")}
      actions={<div className="flex gap-2"><Btn icon={<RotateCcw size={15} />} onClick={() => setV(structuredClone(data.defaults) as S)}>{L('القيم الافتراضية', 'Defaults')}</Btn>
        <Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate(v, { onSuccess: () => toast(L('حُفظت الإعدادات', 'Settings saved')), onError: e => toast((e as Error).message) })}>{L('حفظ', 'Save')}</Btn></div>} />
    <Panel pad={false} title={L('جدول المعدلات حسب الدرجة', 'Rates by grade')} sub={L('بدل السفر اليومي، حد الإقامة لليلة، ودرجة التذكرة — لكل عمود عملته، ولكل صف تاريخ سريان', 'Daily allowance, nightly accommodation limit and ticket class — each column has its currency and each row an effective date')}
      action={<Btn size="sm" icon={<Plus size={14} />} onClick={() => set('rates', [...v.rates, { ...v.rates[v.rates.length - 1], valid_from: new Date().toISOString().slice(0, 10) }])}>{L('صف', 'Row')}</Btn>}>
      <div className="p-3 space-y-3">{v.rates.map((r, i) => <div key={i} className="rounded-xl border border-line p-3 grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(150px,1fr))] items-end">
        <Field label={L('الدرجة', 'Grade')}><select className="field" value={r.grade} onChange={e => editRate(i, 'grade', e.target.value)}>{data.grades.map(g => <option key={g.code} value={g.code}>{g.code} · {bi(g, lang)}</option>)}</select></Field>
        <Field label={L('بدل محلي يومي', 'Local daily')}><div className="flex gap-1"><input type="number" className="field num" value={r.local} onChange={e => editRate(i, 'local', Number(e.target.value))} />{curSel(i, 'local_currency')}</div></Field>
        <Field label={L('بدل خارجي يومي', 'Foreign daily')}><div className="flex gap-1"><input type="number" className="field num" value={r.foreign} onChange={e => editRate(i, 'foreign', Number(e.target.value))} />{curSel(i, 'foreign_currency')}</div></Field>
        <Field label={L('حد الفندق محلياً', 'Hotel limit local')}><div className="flex gap-1"><input type="number" className="field num" value={r.hotel_local} onChange={e => editRate(i, 'hotel_local', Number(e.target.value))} />{curSel(i, 'hotel_local_currency')}</div></Field>
        <Field label={L('حد الفندق خارجياً', 'Hotel limit abroad')}><div className="flex gap-1"><input type="number" className="field num" value={r.hotel_foreign} onChange={e => editRate(i, 'hotel_foreign', Number(e.target.value))} />{curSel(i, 'hotel_foreign_currency')}</div></Field>
        <Field label={L('التذكرة', 'Ticket')}><select className="field" value={r.ticket} onChange={e => editRate(i, 'ticket', e.target.value)}><option value="economy">{L('عادية', 'Economy')}</option><option value="business">{L('أعمال', 'Business')}</option></select></Field>
        <Field label={L('يسري من', 'Valid from')}><input type="date" className="field" value={r.valid_from} onChange={e => editRate(i, 'valid_from', e.target.value)} /></Field>
        <button className="p-2 text-ink-3 hover:text-danger justify-self-start" onClick={() => set('rates', v.rates.filter((_, j) => j !== i))} aria-label={L('حذف', 'Remove')}><Trash2 size={16} /></button></div>)}
        {err?.field('rates') && <div className="text-[12px] text-danger">{err.field('rates')}</div>}</div></Panel>
    <Panel title={L('السيارة الشخصية والقواعد', 'Personal car & rules')}><div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      {num('fuel_price', 'سعر صفيحة الوقود', 'Fuel price per tank')}{cur('fuel_currency', 'عملة الوقود', 'Fuel currency')}{num('km_per_tank', 'كم لكل صفيحة', 'Km per tank')}{num('depreciation_pct', 'نسبة الاهتلاك %', 'Depreciation %', L(`سعر الكيلومتر الآن ${kmPrice.toFixed(2)}`, `km price now ${kmPrice.toFixed(2)}`))}
      <Field label={L('درجة الموظف بلا درجة', 'Grade for employees without one')}><select className="field" value={String(v.default_grade)} onChange={e => set('default_grade', e.target.value)}>{data.grades.map(g => <option key={g.code} value={g.code}>{g.code}</option>)}</select></Field>
      {num('close_days', 'مهلة إغلاق مهمة السفر (أيام عمل)', 'Closing deadline (working days)')}{num('cutoff_day', 'يوم إقفال فترة الراتب', 'Payroll cut-off day', L('ما يُعتمد بعده يُرحَّل للشهر التالي (0 = بلا إقفال)', 'Approved after it goes to next month (0 = none)'))}
      {num('installments', 'أقساط استقطاع فرق السلفة', 'Instalments for the advance difference')}{num('expense_cap', 'حد المصروف المسترد للفاتورة (0 = بلا حد)', 'Cap per receipted expense (0 = none)')}{cur('expense_currency', 'عملة المصاريف', 'Expense currency')}
      <Field label={L('يوما السفر نصف يوم', 'Travel days count as half')} div><Seg value={v.half_travel_days ? 'y' : 'n'} onChange={x => set('half_travel_days', x === 'y')} options={[{ v: 'y', label: L('نعم', 'Yes') }, { v: 'n', label: L('لا', 'No') }]} /></Field>
      <Field label={L('منع مهمة سفر مع أخرى غير مغلقة', 'Block a new trip while one is unclosed')} div><Seg value={v.block_unclosed ? 'y' : 'n'} onChange={x => set('block_unclosed', x === 'y')} options={[{ v: 'y', label: L('نعم', 'Yes') }, { v: 'n', label: L('لا', 'No') }]} /></Field>
    </div></Panel>
    <Panel title={L('مراحل التنفيذ وإثبات الإنجاز', 'Stages & proof of completion')}><div className="space-y-2">{v.stages.map((s, i) => <div key={i} className="grid grid-cols-[1fr_1fr_90px_36px] gap-2">
      <input className="field" value={s.name} onChange={e => set('stages', v.stages.map((x, j) => j === i ? { ...x, name: e.target.value } : x))} aria-label="ar" /><input className="field ltr" value={s.en} onChange={e => set('stages', v.stages.map((x, j) => j === i ? { ...x, en: e.target.value } : x))} aria-label="en" />
      <input type="number" className="field num" value={s.weight} onChange={e => set('stages', v.stages.map((x, j) => j === i ? { ...x, weight: Number(e.target.value) } : x))} aria-label={L('الوزن', 'Weight')} />
      <button onClick={() => set('stages', v.stages.filter((_, j) => j !== i))} aria-label={L('حذف', 'Remove')}><Trash2 size={15} /></button></div>)}
      <div className="flex gap-2 items-center"><Btn size="sm" icon={<Plus size={14} />} onClick={() => set('stages', [...v.stages, { name: '', en: '', weight: 0 }])}>{L('مرحلة', 'Stage')}</Btn><span className="text-[12px] text-ink-3">{L('المجموع', 'Total')} <span className="num">{v.stages.reduce((a, s) => a + s.weight, 0)}</span>/100</span></div>
      {err?.field('stages') && <div className="text-[12px] text-danger">{err.field('stages')}</div>}
      <Field label={L('أنواع تتطلب إثبات إنجاز', 'Types that need proof')} div><div className="flex flex-wrap gap-2">{data.types.map(t => { const on = v.proof_types.includes(t.code)
        return <button key={t.code} type="button" onClick={() => set('proof_types', on ? v.proof_types.filter(x => x !== t.code) : [...v.proof_types, t.code])} className={`h-8 px-3 rounded-full border text-[13px] ${on ? 'bg-brand text-on-brand border-brand' : 'border-line'}`}>{t.code}</button> })}</div></Field></div></Panel>
    <Panel title={L('المكافآت', 'Bonuses')}><div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      {data.types.map(t => <Field key={t.code} label={L(`مكافأة إنجاز ${t.code} (0 = بلا)`, `Completion bonus ${t.code} (0 = none)`)}><input type="number" className="field num" value={v.completion_bonus[t.code] ?? 0} onChange={e => set('completion_bonus', { ...v.completion_bonus, [t.code]: Number(e.target.value) })} /></Field>)}
      {Object.keys(v.trainer_rates).map(k => <Field key={k} label={L(`أجر ساعة المدرب: ${({ beginner: 'مبتدئ', intermediate: 'متوسط', advanced: 'متقدم' } as Record<string, string>)[k] ?? k}`, `Trainer hourly rate: ${k}`)}><input type="number" className="field num" value={v.trainer_rates[k]} onChange={e => set('trainer_rates', { ...v.trainer_rates, [k]: Number(e.target.value) })} /></Field>)}
      {cur('trainer_currency', 'عملة أجر المدرب', 'Trainer rate currency')}{num('attendance_bonus', 'مكافأة الالتزام بالدوام الشهرية (0 = بلا)', 'Monthly attendance bonus (0 = none)')}{cur('attendance_bonus_currency', 'عملتها', 'Its currency')}
      {num('bonus_monthly_cap', 'السقف الشهري لكل نوع للموظف (0 = بلا)', 'Monthly cap per type per employee (0 = none)')}
      <Field label={L('من يعتمد ما فوق السقف', 'Who approves above the cap')}><select className="field" value={String(v.bonus_over_cap_role)} onChange={e => set('bonus_over_cap_role', e.target.value)}>{['ceo', 'executive', 'finance', 'hr_director'].map(r => <option key={r} value={r}>{({ ceo: L('الرئيس التنفيذي', 'CEO'), executive: L('المدير التنفيذي', 'Executive'), finance: L('المالية', 'Finance'), hr_director: L('مدير الموارد البشرية', 'HR director') } as Record<string, string>)[r]}</option>)}</select></Field>
    </div></Panel>
    {err && !Object.keys(err.errors).length && <div role="alert" className="rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2">{err.message}</div>}
  </div>
}
