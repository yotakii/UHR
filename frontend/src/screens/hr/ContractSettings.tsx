import { useEffect, useState } from 'react'
import { Loader2, Plus, RotateCcw, Trash2 } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { DECISION, useContractSettings, useSaveContractSettings, type ContractsCfg } from '../../lib/contracts'
import { Btn, Field, PageHead, Panel, Seg, toast } from '../../ui/kit'

type Row = Record<string, unknown>

/** إعدادات العقود (الدفعة 19): كل مهلة وعتبة ونص قابل للتعديل مع القيم الافتراضية */
export function ContractSettings() {
  const L = useL(); const lang = useLang()
  const { data } = useContractSettings()
  const save = useSaveContractSettings()
  const [v, setV] = useState<ContractsCfg | null>(null)
  useEffect(() => { if (data && !v) setV(structuredClone(data.settings)) }, [data, v])
  if (!data || !v) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const err = save.error instanceof ApiError ? save.error : null
  const set = (k: string, x: unknown) => setV({ ...v, [k]: x })
  const num = (k: string, ar: string, en: string) => <Field label={L(ar, en)} error={err?.field(k)}><input type="number" className="field num" value={String(v[k] ?? '')} onChange={e => set(k, Number(e.target.value))} /></Field>
  const scale = v.scale
  const list = (k: string) => (v[k] as Row[]) ?? []
  const editList = (k: string, i: number, f: string, x: unknown) => set(k, list(k).map((r, j) => j === i ? { ...r, [f]: x } : r))
  const chips = (k: string, opts: { key: string; ar: string; en: string }[]) => <div className="flex flex-wrap gap-2">{opts.map(o => {
    const on = (v[k] as string[]).includes(o.key)
    return <button key={o.key} type="button" onClick={() => set(k, on ? (v[k] as string[]).filter(x => x !== o.key) : [...(v[k] as string[]), o.key])}
      className={`h-8 px-3 rounded-full border text-[13px] ${on ? 'bg-brand text-on-brand border-brand' : 'border-line hover:bg-sub'}`}>{bi(o, lang)}</button>
  })}</div>
  return <div className="max-w-[1100px] mx-auto space-y-5">
    <PageHead title={L('إعدادات العقود والتجديد', 'Contracts & renewal settings')} sub={L('المهل، قواعد اقتراح القرار، نموذج تقييم التجربة، قالب العقد والخطابات.', 'Deadlines, decision rules, the probation form, the contract template and letters.')}
      actions={<div className="flex gap-2"><Btn icon={<RotateCcw size={15} />} onClick={() => setV(structuredClone(data.defaults))}>{L('القيم الافتراضية', 'Defaults')}</Btn>
        <Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate(v, { onSuccess: () => toast(L('حُفظت الإعدادات', 'Settings saved')), onError: e => toast((e as Error).message) })}>{L('حفظ', 'Save')}</Btn></div>} />
    <Panel title={L('المهل', 'Deadlines')}><div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
      {num('renewal_open_days', 'فتح حالة التجديد قبل الانتهاء (يوم)', 'Open renewal before the end (days)')}{num('probation_open_days', 'فتح تقييم التجربة قبل نهايتها (يوم)', 'Open probation evaluation before its end (days)')}
      {num('manager_days', 'مهلة المدير (يوم)', 'Manager deadline (days)')}{num('hr_days', 'مهلة مراجعة HR (يوم)', 'HR review deadline (days)')}
      <Field label={L('نوافذ الرادار (يوم، مفصولة بفاصلة)', 'Radar windows (days, comma separated)')} error={err?.field('radar_days')} className="sm:col-span-2">
        <input className="field num ltr text-start" value={(v.radar_days ?? []).join(', ')} onChange={e => set('radar_days', e.target.value.split(/[,،\s]+/).filter(Boolean).map(Number))} /></Field>
    </div></Panel>
    <Panel title={L('قواعد العقود والقرار', 'Contract & decision rules')}><div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
      {num('default_months', 'مدة العقد المحدد الافتراضية (شهر)', 'Default fixed term (months)')}{num('probation_months', 'فترة التجربة الافتراضية (شهر)', 'Default probation (months)')}
      {num('open_after_years', 'التحويل لغير محدد بعد سنوات متواصلة', 'Open-ended after continuous years')}
      <Field label={L('نوع العقد المحدد', 'Fixed-term type')} error={err?.field('fixed_type')}><select className="field" value={String(v.fixed_type)} onChange={e => set('fixed_type', e.target.value)}>{data.types.map(t => <option key={t.id} value={t.code}>{t.code} · {bi(t, lang)}</option>)}</select></Field>
      <Field label={L('نوع العقد غير المحدد', 'Open-ended type')} error={err?.field('open_type')}><select className="field" value={String(v.open_type)} onChange={e => set('open_type', e.target.value)}>{data.types.map(t => <option key={t.id} value={t.code}>{t.code} · {bi(t, lang)}</option>)}</select></Field>
      <Field label={L('أدنى تقدير لاقتراح التجديد', 'Minimum rating to suggest renewal')}><select className="field" value={String(v.min_rating_renew)} onChange={e => set('min_rating_renew', e.target.value)}>{scale.map(s => <option key={s.key} value={s.key}>{L(s.ar, s.en)}</option>)}</select></Field>
      <Field label={L('سبب الإنهاء عند عدم التجديد', 'Exit cause when not renewed')}><select className="field" value={String(v.exit_cause_not_renewed)} onChange={e => set('exit_cause_not_renewed', e.target.value)}>{data.causes.map(c => <option key={c.key} value={c.key}>{bi(c, lang)}</option>)}</select></Field>
      <Field label={L('سبب الإنهاء عند فشل التجربة', 'Exit cause when probation fails')}><select className="field" value={String(v.exit_cause_probation)} onChange={e => set('exit_cause_probation', e.target.value)}>{data.causes.map(c => <option key={c.key} value={c.key}>{bi(c, lang)}</option>)}</select></Field>
      <Field label={L('آخر يوم عند فشل التجربة', 'Last day when probation fails')} div><Seg value={String(v.fail_last_day)} onChange={x => set('fail_last_day', x)} options={[{ v: 'decision', label: L('يوم القرار', 'Decision day') }, { v: 'probation_end', label: L('نهاية التجربة', 'Probation end') }]} /></Field>
      <Field label={L('تمديد التجربة مرة واحدة', 'Allow one probation extension')} div><Seg value={v.allow_extension ? 'y' : 'n'} onChange={x => set('allow_extension', x === 'y')} options={[{ v: 'y', label: L('نعم', 'Yes') }, { v: 'n', label: L('لا', 'No') }]} /></Field>
      {num('extension_max_months', 'أقصى مدة للتمديد (شهر)', 'Maximum extension (months)')}
    </div></Panel>
    <Panel title={L('التواقيع والنسخ', 'Signatures & copies')}><div className="space-y-3">
      <Field label={L('من يوقّع العقد', 'Who signs the contract')} div error={err?.field('signers')}>{chips('signers', data.signers)}</Field>
      <Field label={L('النسخ المتابعة', 'Tracked copies')} div>{chips('copies', data.copies)}</Field>
      <Field label={L('طريقة التوقيع', 'Signing method')} div><Seg value={String(v.sign_mode)} onChange={x => set('sign_mode', x)} options={[{ v: 'electronic', label: L('إلكتروني', 'Electronic') }, { v: 'upload', label: L('رفع نسخة موقعة', 'Upload a signed copy') }, { v: 'both', label: L('الطريقتان', 'Both') }]} /></Field>
    </div></Panel>
    <Panel title={L('سلّم التقدير وشرائح نتيجة التجربة', 'Rating scale & probation bands')}><div className="grid lg:grid-cols-2 gap-5">
      <div className="space-y-2">{scale.map((s, i) => <div key={i} className="grid grid-cols-[90px_1fr_1fr_64px] gap-2">
        <input className="field ltr text-start" value={s.key} onChange={e => editList('scale', i, 'key', e.target.value)} aria-label="key" /><input className="field" value={s.ar} onChange={e => editList('scale', i, 'ar', e.target.value)} aria-label="ar" />
        <input className="field ltr text-start" value={s.en} onChange={e => editList('scale', i, 'en', e.target.value)} aria-label="en" /><input type="number" className="field num" value={s.score} onChange={e => editList('scale', i, 'score', Number(e.target.value))} aria-label="score" /></div>)}
        <Field label={L('يلزم التبرير لكل تقدير غير', 'Justification required for any rating other than')}><select className="field" value={String(v.justify_other_than)} onChange={e => set('justify_other_than', e.target.value)}>{scale.map(s => <option key={s.key} value={s.key}>{L(s.ar, s.en)}</option>)}</select></Field></div>
      <div className="space-y-2">{list('bands').map((b, i) => <div key={i} className="grid grid-cols-[80px_1fr_1fr] gap-2 items-center">
        <input type="number" className="field num" value={Number(b.from)} onChange={e => editList('bands', i, 'from', Number(e.target.value))} aria-label={L('من', 'From')} />
        <select className="field" value={String(b.grade)} onChange={e => editList('bands', i, 'grade', e.target.value)}>{scale.map(s => <option key={s.key} value={s.key}>{L(s.ar, s.en)}</option>)}</select>
        <select className="field" value={String(b.decision)} onChange={e => editList('bands', i, 'decision', e.target.value)}>{(['confirm', 'decide', 'terminate'] as const).map(d => <option key={d} value={d}>{L(...DECISION[d])}</option>)}</select></div>)}
        {err?.field('bands') && <div className="text-[12px] text-danger">{err.field('bands')}</div>}
        <p className="text-[12.5px] text-ink-3">{L('«من» = أدنى مجموع للشريحة من 100. «يحتاج قراراً» يترك القرار لصاحب الصلاحية دون اقتراح.', '«From» is the minimum total out of 100. «Needs a decision» leaves it to the decision maker without a suggestion.')}</p></div>
    </div></Panel>
    <ListEditor title={L('عناصر نموذج تقييم التجربة', 'Probation form items')} rows={list('items')} onChange={r => set('items', r)} prefix="i" err={err?.field('items')} />
    <ListEditor title={L('قائمة تحقق مراجعة الموارد البشرية', 'HR review checklist')} rows={list('review_checks')} onChange={r => set('review_checks', r)} prefix="c" />
    <Panel title={L('قالب العقد', 'Contract template')} sub={L('حقول الدمج: :name :code :national_id :title :department :branch :type :company :start :end_text :duration :probation', 'Merge fields: :name :code :national_id :title :department :branch :type :company :start :end_text :duration :probation')}>
      <div className="grid lg:grid-cols-2 gap-3"><textarea className="field min-h-56" value={String(v.template_ar)} onChange={e => set('template_ar', e.target.value)} aria-label="ar" /><textarea className="field min-h-56 ltr text-start" value={String(v.template_en)} onChange={e => set('template_en', e.target.value)} aria-label="en" /></div></Panel>
    <Panel title={L('الخطابات الآلية', 'Automatic letters')} sub={L('حقول الدمج: :name :title :type :start :end_text :end :date :last_day', 'Merge fields: :name :title :type :start :end_text :end :date :last_day')}>
      <div className="space-y-3">{Object.entries(v.letters as Record<string, { ar: string; en: string }>).map(([k, x]) => <div key={k}><div className="text-[13px] font-semibold mb-1">{({ renewed: L('إخطار التجديد', 'Renewal notice'), not_renewed: L('إخطار عدم التجديد', 'Non-renewal notice'), confirmed: L('التثبيت بعد التجربة', 'Confirmation after probation'), terminated: L('الإنهاء خلال التجربة', 'Ending during probation') } as Record<string, string>)[k] ?? k}</div>
        <div className="grid lg:grid-cols-2 gap-2"><textarea className="field min-h-20" value={x.ar} onChange={e => set('letters', { ...(v.letters as object), [k]: { ...x, ar: e.target.value } })} /><textarea className="field min-h-20 ltr text-start" value={x.en} onChange={e => set('letters', { ...(v.letters as object), [k]: { ...x, en: e.target.value } })} /></div></div>)}</div></Panel>
    {err && !Object.keys(err.errors).length && <div role="alert" className="rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2">{err.message}</div>}
  </div>
}

function ListEditor({ title, rows, onChange, prefix, err }: { title: string; rows: Row[]; onChange: (r: Row[]) => void; prefix: string; err?: string }) {
  const L = useL()
  return <Panel title={title} action={<Btn size="sm" icon={<Plus size={14} />} onClick={() => onChange([...rows, { key: `${prefix}${Date.now().toString(36).slice(-5)}`, ar: '', en: '' }])}>{L('إضافة', 'Add')}</Btn>}>
    <ol className="space-y-2">{rows.map((r, i) => <li key={i} className="grid grid-cols-[1fr_1fr_36px] gap-2 items-center">
      <input className="field" value={String(r.ar)} onChange={e => onChange(rows.map((x, j) => j === i ? { ...x, ar: e.target.value } : x))} aria-label="ar" />
      <input className="field ltr text-start" value={String(r.en)} onChange={e => onChange(rows.map((x, j) => j === i ? { ...x, en: e.target.value } : x))} aria-label="en" />
      <button className="p-1.5 rounded-lg hover:bg-sub text-ink-3" onClick={() => onChange(rows.filter((_, j) => j !== i))} aria-label={L('حذف', 'Remove')}><Trash2 size={15} /></button></li>)}</ol>
    {err && <div className="text-[12px] text-danger mt-1">{err}</div>}
  </Panel>
}
