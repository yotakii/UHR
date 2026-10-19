import { useEffect, useState, type ReactNode } from 'react'
import { Plus, Trash2, X, AlertTriangle, Globe, Building2, Lock } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { useHrJob, useLookups, useSaveJob, type HrCompany, type JobInput, type MatchWeights, type Visibility } from '../../lib/hr'
import { useRules } from '../../lib/candidates'
import { Btn, Drawer, Field, Seg, toast } from '../../ui/kit'

const EMPTY: JobInput = {
  title_ar: '', title_en: '', department_id: '', location_id: '', employment_type: 'full_time', openings: 1, min_years: 0, degree: 2,
  salary_min: '', salary_max: '', currency: 'USD', summary_ar: '', summary_en: '', requirements: [{ ar: '', en: '' }],
  visibility: 'public', closes_at: '', hard_to_fill: false, requires_photo: false, status: 'open', match_weights: null,
}

/** إنشاء/تعديل شاغر ونشره (لوحة 620px) — الحقول باللغتين، ومكان النشر وحالة مراجعة المنصة */
export function JobForm({ open, id, company, onClose }: { open: boolean; id: number | null; company?: HrCompany; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const lk = useLookups().data
  const existing = useHrJob(open ? id : null).data
  const save = useSaveJob(id)
  const [v, setV] = useState<JobInput>(EMPTY)
  useEffect(() => {
    if (!open) return
    save.reset()
    if (!id) setV({ ...EMPTY, visibility: company?.portal_enabled ? 'public' : 'company' })
  }, [open, id])
  useEffect(() => {
    if (open && id && existing) setV({
      title_ar: existing.title_ar, title_en: existing.title_en, department_id: existing.department_id, location_id: existing.location_id, employment_type: existing.employment_type,
      openings: existing.openings, min_years: existing.min_years, degree: existing.degree, salary_min: existing.salary_min, salary_max: existing.salary_max, currency: existing.currency,
      summary_ar: existing.summary_ar, summary_en: existing.summary_en, requirements: existing.requirements.length ? existing.requirements : [{ ar: '', en: '' }],
      visibility: existing.visibility, closes_at: existing.closes_at ?? '', hard_to_fill: existing.hard_to_fill, requires_photo: existing.requires_photo, status: existing.status, match_weights: existing.match_weights,
    })
  }, [open, id, existing])
  const set = <K extends keyof JobInput>(k: K, x: JobInput[K]) => { setV(s => ({ ...s, [k]: x })); if (save.error) save.reset() }
  const e = save.error instanceof ApiError ? save.error : null
  const err = (k: string) => e?.field(k) ?? Object.entries(e?.errors ?? {}).find(([f]) => f.startsWith(k + '.'))?.[1]?.[0]
  const submit = () => save.mutate(v, { onSuccess: j => { toast(j.portal_status === 'pending' ? L('حُفظ الشاغر — بانتظار مراجعة المنصة قبل البوابة', 'Saved — awaiting platform review for the portal') : L('حُفظ الشاغر', 'Position saved')); onClose() } })
  const num = (s: string) => (s === '' ? '' : Number(s))
  const visOpts: { v: Visibility; icon: ReactNode; label: string; hint: string }[] = [
    { v: 'internal', icon: <Lock size={14} />, label: L('داخلي', 'Internal'), hint: L('للموظفين فقط — الاستقطاب الداخلي', 'Employees only — internal hiring') },
    { v: 'company', icon: <Building2 size={14} />, label: L('صفحة الشركة', 'Company page'), hint: L('يظهر في صفحة شركتكم ورابطه المباشر', 'On your company page and its direct link') },
    { v: 'public', icon: <Globe size={14} />, label: L('البوابة العامة', 'Public portal'), hint: company?.portal_enabled ? (company.portal_review ? L('صفحة الشركة + فرص البوابة بعد مراجعة المنصة', 'Company page + portal after platform review') : L('صفحة الشركة + فرص البوابة مباشرة', 'Company page + portal immediately')) : L('غير مفعّل لشركتكم — راجع مدير المنصة', 'Not enabled for your company — contact the platform admin') },
  ]
  return <Drawer open={open} onClose={onClose} width={680}>
    <div className="h-full flex flex-col">
      <header className="flex items-center justify-between px-5 h-14 border-b border-line-2 shrink-0">
        <h3 className="font-semibold text-[15px]">{id ? L('تعديل الشاغر', 'Edit position') : L('شاغر جديد', 'New position')} {existing && <span className="ltr text-ink-3 text-[13px]">{existing.code}</span>}</h3>
        <button onClick={onClose} className="p-1 rounded hover:bg-sub text-ink-3" aria-label={L('إغلاق', 'Close')}><X size={18} /></button>
      </header>
      <div className="flex-1 overflow-y-auto p-5 space-y-5">
        {existing?.portal_status === 'rejected' && <div className="flex gap-2 rounded-lg bg-danger-soft text-danger px-3 py-2 text-[13px]"><AlertTriangle size={16} className="shrink-0 mt-0.5" /><span>{L('لم يُنشر على البوابة:', 'Not published on the portal:')} {existing.portal_note} — {L('عدّل الإعلان واحفظه لإعادة إرساله للمراجعة.', 'edit and save to resubmit for review.')}</span></div>}
        {e && !Object.keys(e.errors).length && <div role="alert" className="rounded-lg bg-danger-soft text-danger px-3 py-2 text-[13px]">{e.message}</div>}

        <div className="grid sm:grid-cols-2 gap-4">
          <Field label={L('المسمى الوظيفي (عربي)', 'Title (Arabic)')} req error={err('title_ar')}><input className="field" value={v.title_ar} onChange={x => set('title_ar', x.target.value)} /></Field>
          <Field label={L('المسمى الوظيفي (إنكليزي)', 'Title (English)')} req error={err('title_en')}><input className="field ltr text-start" value={v.title_en} onChange={x => set('title_en', x.target.value)} /></Field>
          <Field label={L('الإدارة', 'Department')} req error={err('department_id')}><select className="field" value={v.department_id} onChange={x => set('department_id', num(x.target.value))}><option value="">—</option>{lk?.departments.map(d => <option key={d.id} value={d.id}>{bi(d, lang)}</option>)}</select></Field>
          <Field label={L('المدينة', 'City')} req error={err('location_id')}><select className="field" value={v.location_id} onChange={x => set('location_id', num(x.target.value))}><option value="">—</option>{lk?.locations.map(d => <option key={d.id} value={d.id}>{bi(d, lang)}</option>)}</select></Field>
          <Field label={L('نوع الدوام', 'Job type')} req error={err('employment_type')}><select className="field" value={v.employment_type} onChange={x => set('employment_type', x.target.value)}>{lk?.types.map(t => <option key={t.code} value={t.code}>{bi(t, lang)}</option>)}</select></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label={L('الشواغر', 'Openings')} req error={err('openings')}><input type="number" min={1} className="field num" value={v.openings} onChange={x => set('openings', Number(x.target.value))} /></Field>
            <Field label={L('سنوات الخبرة', 'Years of experience')} req error={err('min_years')}><input type="number" min={0} className="field num" value={v.min_years} onChange={x => set('min_years', Number(x.target.value))} /></Field>
          </div>
          <Field label={L('المؤهل المطلوب', 'Required qualification')} req><select className="field" value={v.degree} onChange={x => set('degree', Number(x.target.value))}>{lk?.degrees.map((d, i) => <option key={i} value={i}>{bi(d, lang)}</option>)}</select></Field>
          <div className="grid grid-cols-[1fr_1fr_84px] gap-2">
            <Field label={L('الراتب من', 'Pay from')} req error={err('salary_min')}><input type="number" min={0} className="field num" value={v.salary_min} onChange={x => set('salary_min', num(x.target.value))} /></Field>
            <Field label={L('إلى', 'to')} req error={err('salary_max')}><input type="number" min={0} className="field num" value={v.salary_max} onChange={x => set('salary_max', num(x.target.value))} /></Field>
            <Field label={L('العملة', 'Currency')}><select className="field" value={v.currency} onChange={x => set('currency', x.target.value as 'USD' | 'SYP')}><option value="USD">USD</option><option value="SYP">SYP</option></select></Field>
          </div>
        </div>
        <p className="text-[12px] text-ink-3 -mt-2">{L('نطاق الراتب داخلي — يُستخدم للمطابقة ولا يظهر في البوابة.', 'The pay band is internal — used for matching and never shown on the portal.')}</p>

        <div className="grid sm:grid-cols-2 gap-4">
          <Field label={L('ملخص الوظيفة (عربي)', 'Summary (Arabic)')} req error={err('summary_ar')}><textarea className="field min-h-[84px]" value={v.summary_ar} onChange={x => set('summary_ar', x.target.value)} /></Field>
          <Field label={L('ملخص الوظيفة (إنكليزي)', 'Summary (English)')} req error={err('summary_en')}><textarea className="field min-h-[84px] ltr text-start" value={v.summary_en} onChange={x => set('summary_en', x.target.value)} /></Field>
        </div>

        <div>
          <div className="flex items-center justify-between mb-1.5"><span className="text-[13px] font-medium text-ink-2">{L('المتطلبات', 'Requirements')}<span className="text-danger"> *</span></span>
            {v.requirements.length < 12 && <Btn size="sm" kind="ghost" icon={<Plus size={14} />} onClick={() => set('requirements', [...v.requirements, { ar: '', en: '' }])}>{L('إضافة', 'Add')}</Btn>}</div>
          <div className="space-y-2">{v.requirements.map((r, i) => <div key={i} className="grid grid-cols-[1fr_1fr_auto] gap-2">
            <input className="field" placeholder={L('بالعربية', 'Arabic')} value={r.ar} aria-label={L('المتطلب بالعربية', 'Requirement in Arabic')} onChange={x => set('requirements', v.requirements.map((y, j) => j === i ? { ...y, ar: x.target.value } : y))} />
            <input className="field ltr text-start" placeholder="English" value={r.en} aria-label={L('المتطلب بالإنكليزية', 'Requirement in English')} onChange={x => set('requirements', v.requirements.map((y, j) => j === i ? { ...y, en: x.target.value } : y))} />
            <button type="button" className="px-2 text-ink-3 hover:text-danger disabled:opacity-30" disabled={v.requirements.length === 1} onClick={() => set('requirements', v.requirements.filter((_, j) => j !== i))} aria-label={L('حذف', 'Remove')}><Trash2 size={16} /></button>
          </div>)}</div>
          {err('requirements') && <span className="block text-[12px] text-danger mt-1">{err('requirements')}</span>}
        </div>

        <section className="rounded-xl border border-line p-4 space-y-4 bg-canvas/40">
          <h4 className="font-semibold">{L('النشر', 'Publishing')}</h4>
          <div className="grid sm:grid-cols-3 gap-2">{visOpts.map(o => {
            const off = o.v === 'public' && !company?.portal_enabled
            return <button key={o.v} type="button" disabled={off} onClick={() => set('visibility', o.v)} className={`text-start rounded-lg border p-3 transition disabled:opacity-45 ${v.visibility === o.v ? 'border-brand bg-brand-soft' : 'border-line bg-surface hover:border-brand/40'}`}>
              <span className="flex items-center gap-1.5 font-semibold text-[13px]">{o.icon}{o.label}</span><span className="block text-[12px] text-ink-3 mt-1">{o.hint}</span></button>
          })}</div>
          {err('visibility') && <span className="block text-[12px] text-danger">{err('visibility')}</span>}
          <div className="grid sm:grid-cols-2 gap-4 items-end">
            <Field label={L('آخر موعد للتقديم', 'Application deadline')} hint={L('يُغلق الشاغر تلقائياً بعده (اختياري)', 'The position closes automatically after it (optional)')} error={err('closes_at')}><input type="date" className="field" value={v.closes_at} onChange={x => set('closes_at', x.target.value)} /></Field>
            <Field label={L('الحالة', 'Status')} div><Seg value={v.status} onChange={x => set('status', x)} options={[{ v: 'draft', label: L('مسودة', 'Draft') }, { v: 'open', label: L('مفتوح للتقديم', 'Open') }, { v: 'closed', label: L('مغلق', 'Closed') }]} /></Field>
          </div>
          <label className="flex gap-3 text-[14px]"><input type="checkbox" className="mt-1 accent-[var(--primary)]" checked={v.hard_to_fill} onChange={x => set('hard_to_fill', x.target.checked)} /><span>{L('وظيفة حرجة / نادرة — «مطلوب بشدة» في البوابة ومضاعفة نقاط الإحالة', 'Critical / hard-to-fill — "Hiring urgently" on the portal and double referral points')}</span></label>
          <label className="flex gap-3 text-[14px]"><input type="checkbox" className="mt-1 accent-[var(--primary)]" checked={v.requires_photo} onChange={x => set('requires_photo', x.target.checked)} /><span>{L('تتطلب صورة شخصية مع الطلب', 'Requires a personal photo with the application')}</span></label>
          <WeightsField value={v.match_weights} onChange={w => set('match_weights', w)} error={err('match_weights')} />
        </section>
      </div>
      <footer className="px-5 py-3 border-t border-line-2 flex justify-end gap-2 shrink-0">
        <Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
        <Btn kind="primary" onClick={submit} disabled={save.isPending}>{save.isPending ? L('جارٍ الحفظ…', 'Saving…') : L('حفظ', 'Save')}</Btn>
      </footer>
    </div>
  </Drawer>
}

/** أوزان مطابقة خاصة بهذا الشاغر (اختياري) — بدونها تُستخدم أوزان الشركة من «قواعد التوظيف والمطابقة» */
function WeightsField({ value, onChange, error }: { value: MatchWeights | null; onChange: (w: MatchWeights | null) => void; error?: string }) {
  const L = useL()
  const rules = useRules().data
  const base = rules?.match.weights ?? { exp: 30, deg: 25, sal: 20, eng: 15, file: 10 }
  const label: Record<keyof MatchWeights, string> = { exp: L('الخبرة', 'Experience'), deg: L('المؤهل', 'Qualification'), sal: L('الراتب', 'Salary'), eng: L('الإنكليزية', 'English'), file: L('اكتمال الملف', 'Completeness') }
  const sum = value ? Object.values(value).reduce((a, b) => a + (Number(b) || 0), 0) : 100
  return <div className="rounded-xl border border-line p-3 space-y-2">
    <label className="flex gap-3 text-[14px]"><input type="checkbox" className="mt-1 accent-[var(--primary)]" checked={!!value} onChange={x => onChange(x.target.checked ? { ...base } : null)} />
      <span>{L('أوزان مطابقة خاصة بهذا الشاغر', 'Custom match weights for this position')}<span className="block text-[12px] text-ink-3">{L('بدونها تُطبَّق أوزان الشركة. المجموع يجب أن يكون 100.', 'Otherwise the company weights apply. They must add up to 100.')}</span></span></label>
    {value && <div className="grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(110px,1fr))]">{(Object.keys(label) as (keyof MatchWeights)[]).map(k => <label key={k} className="text-[12px] text-ink-3">
      <span className="block mb-1 truncate">{label[k]}</span><input type="number" min={0} max={100} step={5} className="field num text-center" value={value[k]} onChange={x => onChange({ ...value, [k]: Number(x.target.value) })} /></label>)}</div>}
    {value && <div className={`text-[12px] ${sum === 100 ? 'text-ink-3' : 'text-danger'}`}>{L('المجموع', 'Total')} <span className="num">{sum}</span>/100</div>}
    {error && <div className="text-[12px] text-danger">{error}</div>}
  </div>
}
