import { useEffect, useState } from 'react'
import { Plus, RotateCcw, X } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { useTd, useTdAct, useTdSettings } from '../../lib/training'
import { Btn, Field, PageHead, Panel, Seg, toast } from '../../ui/kit'
import { Spin } from '../training/parts'

type Tier = { from: number; to: number; months: number }
type Lbl = { key: string; ar: string; en: string }
type Station = { department_id: number | null; days: number; topics: string }
type Tpl = { key: string; ar: string; en: string; job_ids: number[]; department_ids: number[]; stations: Station[] }
type S = Record<string, unknown> & { capacity: Record<string, [number, number]>; commitment_tiers: Tier[]; weights: Record<string, number>; impact_points: number[]; cert_alerts: number[]
  survey_items: string[]; impact_items: string[]; cost_types: string[]; logistics: string[]; peak_seasons: { from: string; to: string; name: string }[]; qualification_templates: Tpl[] }

/** إعدادات التأهيل والتدريب والتطوير (الدفعة 23): كل قاعدة BR وكل قرار مفتوح في SRS التدريب إعداد بقيمة افتراضية */
export function TrainingSettings() {
  const L = useL(); const lang = useLang()
  const q = useTdSettings(); const lk = useTd().data?.lookups
  const save = useTdAct()
  const [v, setV] = useState<S | null>(null)
  useEffect(() => { if (q.data && !v) setV(q.data.data as S) }, [q.data, v])
  if (!q.data || !v) return <Spin />
  const edit = q.data.can.edit
  const e = save.error instanceof ApiError ? save.error : null
  const set = (o: Partial<S>) => setV({ ...v, ...o })
  const num = (k: string, label: string, unit?: string, hint?: string) => <Field label={label} hint={hint} error={e?.field(k)}><div className="flex items-center gap-2">
    <input className="field ltr num !w-28" inputMode="decimal" disabled={!edit} value={String(v[k] ?? '')} onChange={x => setV({ ...v, [k]: Number(x.target.value.replace(/[^\d.]/g, '')) || 0 })} />{unit && <span className="text-[13px] text-ink-3">{unit}</span>}</div></Field>
  const list = (k: 'survey_items' | 'impact_items' | 'cost_types' | 'logistics', ltr = false) => <div className="space-y-1.5">{v[k].map((x, i) => <div key={i} className="flex gap-2">
    <input className={`field ${ltr ? 'ltr' : ''}`} disabled={!edit} value={x} onChange={y => set({ [k]: v[k].map((z, j) => j === i ? y.target.value : z) } as Partial<S>)} />
    {edit && <button className="text-ink-3 hover:text-danger" onClick={() => set({ [k]: v[k].filter((_, j) => j !== i) } as Partial<S>)} aria-label={L('حذف', 'Remove')}><X size={15} /></button>}</div>)}
    {edit && <Btn size="sm" icon={<Plus size={14} />} onClick={() => set({ [k]: [...v[k], ''] } as Partial<S>)}>{L('إضافة', 'Add')}</Btn>}
    {e?.field(k) && <div className="text-[12px] text-danger">{e.field(k)}</div>}</div>
  const days = (k: 'impact_points' | 'cert_alerts') => <input className="field ltr num" disabled={!edit} value={v[k].join(', ')} onChange={x => set({ [k]: x.target.value.split(/[,،\s]+/).map(Number).filter(n => n > 0) } as Partial<S>)} />
  const METHOD: [string, string, string][] = [['general', 'عام', 'General'], ['workshop', 'ورشة عمل', 'Workshop'], ['lecture', 'محاضرة', 'Lecture'], ['group', 'جماعي', 'Group']]
  const tpls = v.qualification_templates
  const setT = (i: number, o: Partial<Tpl>) => set({ qualification_templates: tpls.map((t, j) => j === i ? { ...t, ...o } : t) })
  return <div className="max-w-[960px] mx-auto space-y-5">
    <PageHead title={L('إعدادات التأهيل والتدريب والتطوير', 'Training & development settings')} sub={L('قواعد الترشيح والنجاح والتعهد والقياس والتأهيل — كل قيمة قابلة للتعديل ولها قيمة افتراضية.', 'Rules for nomination, success, commitments, measurement and qualification — each value editable with a default.')}
      actions={edit && <><Btn icon={<RotateCcw size={15} />} onClick={() => setV(q.data!.defaults as S)}>{L('القيم الافتراضية', 'Defaults')}</Btn>
        <Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate({ path: 'settings', method: 'PUT', v }, { onSuccess: () => toast(L('حُفظت الإعدادات', 'Settings saved')) })}>{L('حفظ', 'Save')}</Btn></>} />
    {save.error && !e?.errors && <div className="text-[13px] text-danger">{(save.error as Error).message}</div>}
    <Panel title={L('طريقة العمل', 'Way of working')}>
      <Field label={L('من يقترح الاحتياجات', 'Who proposes needs')} div><Seg value={v.mode as string} onChange={x => edit && set({ mode: x })} options={[{ v: 'hr_only', label: L('الموارد البشرية فقط', 'HR only') }, { v: 'self_service', label: L('المدراء والموظفون أيضاً', 'Managers and staff too') }]} /></Field>
      <label className="flex items-center gap-2 text-[13.5px] mt-3"><input type="checkbox" disabled={!edit} checked={!!v.probation_block} onChange={x => set({ probation_block: x.target.checked })} />{L('لا يُرشَّح من في فترة التجربة إلا بمبرر استثناء', 'No nomination during probation without an exception reason')}</label>
    </Panel>
    <Panel title={L('النجاح والسعة', 'Success & capacity')}>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(210px,1fr))]">
        {num('pass_mark', L('علامة النجاح', 'Pass mark'), '%')}{num('attendance_min', L('الحد الأدنى للحضور', 'Minimum attendance'), '%')}
        {num('excellence', L('شهادة التفوق من', 'Excellence certificate from'), '%')}{num('ban_threshold', L('توصية حظر الجهة إن قلّ متوسط النتائج عن', 'Recommend banning the provider below'), '%')}
        {num('min_quotes', L('عروض أسعار التدريب الخارجي', 'Quotes for external training'), L('عروض', 'quotes'))}
      </div>
      <div className="mt-4 text-[13px] font-semibold">{L('السعة المنصوح بها حسب الأسلوب (أدنى – أعلى)', 'Recommended capacity by method (min – max)')}</div>
      <div className="grid gap-2 mt-2 [grid-template-columns:repeat(auto-fill,minmax(200px,1fr))]">{METHOD.map(m => <div key={m[0]} className="flex items-center gap-2"><span className="text-[13px] w-20">{L(m[1], m[2])}</span>
        {[0, 1].map(i => <input key={i} className="field num ltr !w-16" disabled={!edit} value={v.capacity[m[0]]?.[i] ?? 0} aria-label={`${m[2]} ${i ? 'max' : 'min'}`} onChange={x => set({ capacity: { ...v.capacity, [m[0]]: (i ? [v.capacity[m[0]]?.[0] ?? 0, Number(x.target.value) || 0] : [Number(x.target.value) || 0, v.capacity[m[0]]?.[1] ?? 0]) as [number, number] } })} />)}</div>)}</div>
    </Panel>
    <Panel title={L('تعهد الالتزام بالعمل', 'Service commitment')}>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(240px,1fr))]">
        {num('commitment_pct', L('يُطلب التعهد إذا بلغت تكلفة الفرد', 'Required when the cost per person reaches'), L('% من الراتب الشهري', '% of monthly salary'))}
        <Field label={L('عملة الشرائح', 'Tier currency')}><input className="field ltr !w-24" disabled={!edit} value={v.commitment_currency as string} onChange={x => set({ commitment_currency: x.target.value.toUpperCase().slice(0, 3) })} /></Field>
        <Field label={L('الاسترداد عند ترك العمل', 'Recovery on leaving')} div><Seg value={v.recovery as string} onChange={x => edit && set({ recovery: x })} options={[{ v: 'prorata', label: L('نسبة المدة المتبقية', 'Pro rata') }, { v: 'full', label: L('كامل المبلغ', 'Full amount') }]} /></Field>
      </div>
      <label className="flex items-center gap-2 text-[13.5px] mt-3"><input type="checkbox" disabled={!edit} checked={!!v.commitment_on_request} onChange={x => set({ commitment_on_request: x.target.checked })} />{L('يُطلب التعهد أيضاً لكل دورة بطلب الموظف', 'Also required for every course the employee requested')}</label>
      <div className="mt-3 text-[13px] font-semibold">{L('مدة التعهد حسب تكلفة الدورة', 'Commitment length by course cost')}</div>
      <div className="space-y-1.5 mt-2">{v.commitment_tiers.map((t, i) => <div key={i} className="flex flex-wrap items-center gap-2 text-[13px]">
        <span>{L('من', 'From')}</span><input className="field num ltr !w-24" disabled={!edit} value={t.from} onChange={x => set({ commitment_tiers: v.commitment_tiers.map((y, j) => j === i ? { ...y, from: Number(x.target.value) || 0 } : y) })} aria-label="from" />
        <span>{L('إلى', 'to')}</span><input className="field num ltr !w-24" disabled={!edit} value={t.to} placeholder="∞" onChange={x => set({ commitment_tiers: v.commitment_tiers.map((y, j) => j === i ? { ...y, to: Number(x.target.value) || 0 } : y) })} aria-label="to" />
        <span>←</span><input className="field num ltr !w-16" disabled={!edit} value={t.months} onChange={x => set({ commitment_tiers: v.commitment_tiers.map((y, j) => j === i ? { ...y, months: Number(x.target.value) || 1 } : y) })} aria-label="months" /><span>{L('شهراً', 'months')}</span>
        {edit && <button className="text-ink-3 hover:text-danger" onClick={() => set({ commitment_tiers: v.commitment_tiers.filter((_, j) => j !== i) })} aria-label={L('حذف', 'Remove')}><X size={15} /></button>}</div>)}
        {edit && <Btn size="sm" icon={<Plus size={14} />} onClick={() => set({ commitment_tiers: [...v.commitment_tiers, { from: 0, to: 0, months: 6 }] })}>{L('شريحة', 'Tier')}</Btn>}
        <div className="text-[12px] text-ink-3">{L('«إلى» = 0 تعني بلا حد أعلى.', '«to» = 0 means no upper limit.')}</div></div>
      <div className="grid gap-3 sm:grid-cols-2 mt-3">
        <Field label={L('نص التعهد (عربي)', 'Commitment text (Arabic)')} hint=":name :course :from :to :company :months :cost :mode"><textarea className="field" rows={7} disabled={!edit} value={v.commitment_ar as string} onChange={x => set({ commitment_ar: x.target.value })} /></Field>
        <Field label={L('نص التعهد (إنجليزي)', 'Commitment text (English)')}><textarea className="field ltr" rows={7} disabled={!edit} value={v.commitment_en as string} onChange={x => set({ commitment_en: x.target.value })} /></Field>
      </div>
    </Panel>
    <Panel title={L('تحليل الاحتياجات', 'Needs analysis')}>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(210px,1fr))]">
        {num('min_evidence', L('الحد الأدنى للأدلة', 'Minimum evidence'), L('أدلة', 'pieces'))}{num('gap_threshold', L('اقتراح احتياج عند فجوة', 'Suggest a need at a gap of'), L('مستوى', 'level(s)'))}
        {num('tna_from_month', L('موسم جمع الاحتياجات من شهر', 'Collection season from month'))}{num('tna_to_month', L('إلى شهر', 'to month'))}
      </div>
      <div className="mt-3 text-[13px] font-semibold">{L('أوزان الأولوية', 'Priority weights')}</div>
      <div className="flex flex-wrap gap-3 mt-2">{([['impact', 'الأثر', 'Impact'], ['gap', 'الفجوة', 'Gap'], ['affected', 'عدد المتأثرين', 'Affected'], ['urgency', 'الإلحاح', 'Urgency']] as const).map(([k, a, b]) => <label key={k} className="flex items-center gap-2 text-[13px]">{L(a, b)}
        <input className="field num ltr !w-16" disabled={!edit} value={v.weights[k] ?? 1} onChange={x => set({ weights: { ...v.weights, [k]: Number(x.target.value) || 0 } })} /></label>)}</div>
    </Panel>
    <Panel title={L('التقييم وقياس الأثر', 'Evaluation & impact')}>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(210px,1fr))]">
        <Field label={L('نقاط متابعة الأثر بعد الدورة (أيام)', 'Impact follow-up points (days)')}>{days('impact_points')}</Field>
        {num('impact_form_point', L('نقطة قياس السلوك F-05', 'Behaviour check (F-05) at'), L('يوم', 'days'))}{num('impact_pass', L('متوسط F-05 الذي يرفع مستوى الكفاءة', 'F-05 average that raises the level'), '%')}
        {num('survey_min', L('نسبة استجابة الاستبيان قبل الإغلاق', 'Survey response before closing'), '%')}{num('remind_days', L('تذكير بعد', 'Remind after'), L('يوم', 'days'))}{num('escalate_days', L('تصعيد بعد', 'Escalate after'), L('يوم', 'days'))}
      </div>
      <div className="grid gap-4 md:grid-cols-2 mt-4"><Field label={L('بنود استبيان الرضا', 'Satisfaction survey items')} div>{list('survey_items')}</Field><Field label={L('بنود قياس السلوك F-05', 'Behaviour check (F-05) items')} div>{list('impact_items')}</Field></div>
    </Panel>
    <Panel title={L('الشهادات والستاج', 'Certificates & internships')}>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(210px,1fr))]">
        <Field label={L('تنبيه قبل انتهاء الشهادة (أيام)', 'Alert before a certificate expires (days)')}>{days('cert_alerts')}</Field>
        {num('stage_hours_pct', L('شهادة الستاج: إتمام الساعات', 'Internship certificate: hours done'), '%')}{num('stage_min_rating', L('وأقل تقييم للمشرف', 'and minimum supervisor rating'), '/5')}
        {num('stage_free_max_months', L('أقصى مدة للستاج غير المأجور', 'Maximum unpaid internship'), L('شهر', 'months'))}
      </div>
    </Panel>
    <Panel title={L('التكاليف والتجهيزات ومواسم الذروة', 'Costs, logistics & peak seasons')}>
      <div className="grid gap-4 md:grid-cols-2"><Field label={L('بنود التكلفة', 'Cost items')} div>{list('cost_types', true)}</Field><Field label={L('قائمة التجهيزات', 'Logistics checklist')} div>{list('logistics')}</Field></div>
      <Field label={L('مواسم الذروة (لا تُجدول فيها دورات)', 'Peak seasons (avoid scheduling)')} div className="mt-4"><div className="space-y-1.5">{v.peak_seasons.map((p, i) => <div key={i} className="flex flex-wrap gap-2 items-center">
        <input className="field !w-40" disabled={!edit} placeholder={L('الاسم', 'Name')} value={p.name} onChange={x => set({ peak_seasons: v.peak_seasons.map((y, j) => j === i ? { ...y, name: x.target.value } : y) })} />
        <input className="field ltr num !w-24" disabled={!edit} placeholder="MM-DD" value={p.from} onChange={x => set({ peak_seasons: v.peak_seasons.map((y, j) => j === i ? { ...y, from: x.target.value } : y) })} aria-label="from" />
        <input className="field ltr num !w-24" disabled={!edit} placeholder="MM-DD" value={p.to} onChange={x => set({ peak_seasons: v.peak_seasons.map((y, j) => j === i ? { ...y, to: x.target.value } : y) })} aria-label="to" />
        {edit && <button className="text-ink-3 hover:text-danger" onClick={() => set({ peak_seasons: v.peak_seasons.filter((_, j) => j !== i) })} aria-label={L('حذف', 'Remove')}><X size={15} /></button>}</div>)}
        {edit && <Btn size="sm" icon={<Plus size={14} />} onClick={() => set({ peak_seasons: [...v.peak_seasons, { name: '', from: '', to: '' }] })}>{L('موسم', 'Season')}</Btn>}</div></Field>
    </Panel>
    <Panel title={L('التصنيفات والاعتمادات', 'Categories & approvals')}>
      <div className="grid gap-4 md:grid-cols-2">
        {(['deliveries', 'audiences'] as const).map(k => <Field key={k} label={k === 'deliveries' ? L('أساليب التنفيذ', 'Delivery methods') : L('الفئات المستهدفة', 'Target audiences')} div error={e?.field(k)}>
          <div className="space-y-1.5">{((v[k] as Lbl[]) ?? []).map((x, i) => <div key={i} className="grid grid-cols-[90px_1fr_1fr_auto] gap-1.5">
            <input className="field ltr !text-[12px]" disabled={!edit} value={x.key} aria-label="key" onChange={y => set({ [k]: (v[k] as Lbl[]).map((z, j) => j === i ? { ...z, key: y.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '') } : z) })} />
            <input className="field" disabled={!edit} value={x.ar} aria-label={L('عربي', 'Arabic')} onChange={y => set({ [k]: (v[k] as Lbl[]).map((z, j) => j === i ? { ...z, ar: y.target.value } : z) })} />
            <input className="field ltr" disabled={!edit} value={x.en} aria-label="English" onChange={y => set({ [k]: (v[k] as Lbl[]).map((z, j) => j === i ? { ...z, en: y.target.value } : z) })} />
            {edit && <button className="text-ink-3 hover:text-danger" onClick={() => set({ [k]: (v[k] as Lbl[]).filter((_, j) => j !== i) })} aria-label={L('حذف', 'Remove')}><X size={15} /></button>}</div>)}
            {edit && <Btn size="sm" icon={<Plus size={14} />} onClick={() => set({ [k]: [...((v[k] as Lbl[]) ?? []), { key: '', ar: '', en: '' }] })}>{L('إضافة', 'Add')}</Btn>}</div></Field>)}
      </div>
      <div className="mt-4 text-[13px] font-semibold">{L('معايير مقارنة العروض وأوزانها (المجموع 100)', 'Quote comparison criteria and weights (total 100)')}</div>
      <div className="space-y-1.5 mt-2">{((v.quote_criteria as (Lbl & { weight: number })[]) ?? []).map((x, i) => <div key={i} className="grid grid-cols-[90px_1fr_1fr_70px_auto] gap-1.5">
        <input className="field ltr !text-[12px]" disabled={!edit || x.key === 'price'} value={x.key} aria-label="key" onChange={y => set({ quote_criteria: (v.quote_criteria as Lbl[]).map((z, j) => j === i ? { ...z, key: y.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '') } : z) })} />
        <input className="field" disabled={!edit} value={x.ar} aria-label={L('عربي', 'Arabic')} onChange={y => set({ quote_criteria: (v.quote_criteria as Lbl[]).map((z, j) => j === i ? { ...z, ar: y.target.value } : z) })} />
        <input className="field ltr" disabled={!edit} value={x.en} aria-label="English" onChange={y => set({ quote_criteria: (v.quote_criteria as Lbl[]).map((z, j) => j === i ? { ...z, en: y.target.value } : z) })} />
        <input className="field num ltr" disabled={!edit} value={x.weight} aria-label={L('الوزن', 'Weight')} onChange={y => set({ quote_criteria: (v.quote_criteria as (Lbl & { weight: number })[]).map((z, j) => j === i ? { ...z, weight: Number(y.target.value) || 0 } : z) })} />
        {edit && x.key !== 'price' && <button className="text-ink-3 hover:text-danger" onClick={() => set({ quote_criteria: (v.quote_criteria as Lbl[]).filter((_, j) => j !== i) })} aria-label={L('حذف', 'Remove')}><X size={15} /></button>}</div>)}
        {edit && <Btn size="sm" icon={<Plus size={14} />} onClick={() => set({ quote_criteria: [...((v.quote_criteria as Lbl[]) ?? []), { key: '', ar: '', en: '', weight: 0 }] })}>{L('معيار', 'Criterion')}</Btn>}
        {e?.field('quote_criteria') && <div className="text-[12px] text-danger">{e.field('quote_criteria')}</div>}</div>
      <div className="mt-4 text-[13px] font-semibold">{L('أوزان بطاقة أداء المدرب (المجموع 100)', 'Trainer scorecard weights (total 100)')}</div>
      <div className="flex flex-wrap gap-3 mt-2">{([['content', 'المحتوى', 'Content'], ['trainer', 'أداء المدرب', 'Trainer'], ['punctuality', 'الالتزام', 'Punctuality'], ['facilities', 'التجهيزات', 'Facilities'], ['results', 'النتائج', 'Results'], ['value', 'القيمة', 'Value']] as const).map(([k, a, b]) => <label key={k} className="flex items-center gap-1.5 text-[13px]">{L(a, b)}
        <input className="field num ltr !w-16" disabled={!edit} value={(v.trainer_weights as Record<string, number>)?.[k] ?? 0} onChange={x => set({ trainer_weights: { ...(v.trainer_weights as Record<string, number>), [k]: Number(x.target.value) || 0 } })} /></label>)}</div>
      {e?.field('trainer_weights') && <div className="text-[12px] text-danger">{e.field('trainer_weights')}</div>}
      <div className="grid gap-2 mt-4 sm:grid-cols-2 text-[13.5px]">{([['quote_gate', 'لا يُؤكَّد التسجيل قبل اعتماد اختيار العرض الخارجي', 'Places are not confirmed before the external quote choice is approved'],
        ['change_approval', 'تعديل الخطة المعتمدة يحتاج اعتماداً قبل تطبيقه', 'Changes to the approved plan need approval first'], ['tna_enforce', 'الحملات خارج نافذة تحديد الاحتياجات تحتاج مبرراً', 'Campaigns outside the needs window need a justification'],
        ['smart_required', 'لا يُعتمد احتياج دون هدف مكتمل (السلوك والشرط والمعيار)', 'No need is approved without a complete objective'], ['campaign_auto_close', 'إغلاق الحملة تلقائياً بعد موعدها', 'Close campaigns automatically after the deadline'],
        ['email', 'نسخة بالبريد من إشعارات الترشيح والتعهد والمتابعة', 'Email copies of nomination, commitment and follow-up notices'], ['idp_from_appraisal', 'مسودة خطة تطوير فردية لمن يقل تقييمه عن المتوسط', 'Draft an IDP for below-average appraisals'],
        ['linkedin_share', 'زر إضافة الشهادة إلى LinkedIn', 'LinkedIn “add certificate” button']] as const).map(([k, a, b]) =>
        <label key={k} className="flex items-start gap-2"><input type="checkbox" className="mt-1" disabled={!edit} checked={!!v[k]} onChange={x => set({ [k]: x.target.checked })} />{L(a, b)}</label>)}</div>
      <div className="grid gap-4 mt-4 [grid-template-columns:repeat(auto-fill,minmax(210px,1fr))]">
        {num('checkin_before', L('الحضور بالرمز يُفتح قبل الجلسة', 'QR check-in opens before'), L('دقيقة', 'min'))}{num('checkin_after', L('ويُغلق بعد نهايتها', 'and closes after the end'), L('دقيقة', 'min'))}
        {num('late_after', L('يُعدّ متأخراً بعد', 'Late after'), L('دقيقة', 'min'))}{num('campaign_remind_days', L('تذكير الحملة قبل الإغلاق', 'Campaign reminder before closing'), L('يوم', 'days'))}
        {num('link_days', L('صلاحية روابط البريد', 'Email links valid for'), L('يوم', 'days'))}
        <Field label={L('موقّع الشهادات', 'Certificate signer')}><input className="field" disabled={!edit} value={(v.cert_signer as string) ?? ''} onChange={x => set({ cert_signer: x.target.value })} /></Field>
      </div>
      <div className="mt-4 text-[13px] font-semibold">{L('قوالب نصوص الشهادات', 'Certificate text templates')} <span className="text-ink-3 font-normal ltr">:company :name :course :date</span></div>
      <div className="space-y-2 mt-2">{Object.entries((v.cert_templates as Record<string, { ar: string; en: string; body_ar: string; body_en: string }>) ?? {}).map(([k, t]) => <div key={k} className="rounded-xl border border-line p-2.5 grid gap-1.5 sm:grid-cols-2">
        <input className="field" disabled={!edit} value={t.ar} aria-label={L('العنوان', 'Title')} onChange={x => set({ cert_templates: { ...(v.cert_templates as object), [k]: { ...t, ar: x.target.value } } })} />
        <input className="field ltr" disabled={!edit} value={t.en} aria-label="Title (English)" onChange={x => set({ cert_templates: { ...(v.cert_templates as object), [k]: { ...t, en: x.target.value } } })} />
        <textarea className="field" rows={2} disabled={!edit} value={t.body_ar} aria-label={L('النص', 'Text')} onChange={x => set({ cert_templates: { ...(v.cert_templates as object), [k]: { ...t, body_ar: x.target.value } } })} />
        <textarea className="field ltr" rows={2} disabled={!edit} value={t.body_en} aria-label="Text (English)" onChange={x => set({ cert_templates: { ...(v.cert_templates as object), [k]: { ...t, body_en: x.target.value } } })} /></div>)}</div>
    </Panel>
    <Panel title={L('قوالب برامج التأهيل', 'Qualification templates')} sub={L('يُولَّد برنامج التأهيل تلقائياً عند التعيين للموظف الجديد في الإدارات المحددة.', 'A programme is generated at hire for new staff in the selected departments.')}>
      <div className="space-y-3">{tpls.map((t, i) => <div key={i} className="rounded-xl border border-line p-3">
        <div className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]"><input className="field" disabled={!edit} placeholder={L('اسم القالب', 'Template name')} value={t.ar} onChange={x => setT(i, { ar: x.target.value, key: t.key || `t${Date.now()}` })} />
          <input className="field ltr" disabled={!edit} placeholder="English" value={t.en} onChange={x => setT(i, { en: x.target.value })} />
          {edit && <Btn size="sm" kind="ghost" icon={<X size={14} />} aria-label={L('حذف', 'Remove')} onClick={() => set({ qualification_templates: tpls.filter((_, j) => j !== i) })} />}</div>
        {lk && <div className="flex flex-wrap gap-2 mt-2 text-[12.5px]"><span className="text-ink-3">{L('للإدارات:', 'For departments:')}</span>{lk.departments.map(d => <label key={d.id} className="flex items-center gap-1"><input type="checkbox" disabled={!edit} checked={t.department_ids.includes(d.id)}
          onChange={x => setT(i, { department_ids: x.target.checked ? [...t.department_ids, d.id] : t.department_ids.filter(y => y !== d.id) })} />{bi(d, lang)}</label>)}</div>}
        <div className="mt-2 space-y-1.5">{t.stations.map((s, k) => <div key={k} className="grid gap-2 grid-cols-[1fr_70px_1.4fr_auto]">
          <select className="field" disabled={!edit} value={s.department_id ?? ''} aria-label={L('الإدارة', 'Department')} onChange={x => setT(i, { stations: t.stations.map((y, j) => j === k ? { ...y, department_id: x.target.value ? Number(x.target.value) : null } : y) })}><option value="">{L('إدارة الموظف', "Employee's department")}</option>{lk?.departments.map(d => <option key={d.id} value={d.id}>{bi(d, lang)}</option>)}</select>
          <input className="field num ltr" disabled={!edit} value={s.days} aria-label={L('أيام', 'Days')} onChange={x => setT(i, { stations: t.stations.map((y, j) => j === k ? { ...y, days: Number(x.target.value) || 1 } : y) })} />
          <input className="field" disabled={!edit} placeholder={L('المواضيع', 'Topics')} value={s.topics} onChange={x => setT(i, { stations: t.stations.map((y, j) => j === k ? { ...y, topics: x.target.value } : y) })} />
          {edit && <Btn size="sm" kind="ghost" icon={<X size={14} />} aria-label={L('حذف', 'Remove')} onClick={() => setT(i, { stations: t.stations.filter((_, j) => j !== k) })} />}</div>)}
          {edit && <Btn size="sm" icon={<Plus size={14} />} onClick={() => setT(i, { stations: [...t.stations, { department_id: null, days: 3, topics: '' }] })}>{L('محطة', 'Station')}</Btn>}</div>
      </div>)}
        {edit && <Btn size="sm" icon={<Plus size={14} />} onClick={() => set({ qualification_templates: [...tpls, { key: `t${Date.now()}`, ar: '', en: '', job_ids: [], department_ids: [], stations: [{ department_id: null, days: 5, topics: '' }] }] })}>{L('قالب', 'Template')}</Btn>}</div>
    </Panel>
  </div>
}
