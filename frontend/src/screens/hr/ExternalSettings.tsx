import { useEffect, useState } from 'react'
import { Loader2, Plus, RotateCcw, X } from 'lucide-react'
import { useL } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { PLEDGE, useExtSettings, useSaveExtSettings } from '../../lib/externals'
import { Btn, Field, PageHead, Panel, Seg, toast } from '../../ui/kit'

type S = Record<string, unknown> & { pledges: string[]; fee_roles: string[]; onboarding: { ar: string; en: string }[] }

/** إعدادات غير الموظفين والخبراء (الدفعة 22): كل قرار مفتوح في الجزء E إعداد بقيمة افتراضية */
export function ExternalSettings() {
  const L = useL()
  const q = useExtSettings(); const save = useSaveExtSettings()
  const [v, setV] = useState<S | null>(null)
  useEffect(() => { if (q.data && !v) setV(q.data.data as S) }, [q.data, v])
  if (!q.data || !v) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const edit = q.data.can.edit
  const e = save.error instanceof ApiError ? save.error : null
  const num = (k: string, label: string, unit?: string, hint?: string) => <Field label={label} hint={hint} error={e?.field(k)}><div className="flex items-center gap-2">
    <input className="field ltr num !w-28" inputMode="decimal" disabled={!edit} value={String(v[k] ?? '')} onChange={x => setV({ ...v, [k]: Number(x.target.value.replace(/[^\d.]/g, '')) || 0 })} />{unit && <span className="text-[13px] text-ink-3">{unit}</span>}</div></Field>
  const roles: [string, string, string][] = [['hr_director', 'مدير الموارد البشرية', 'HR director'], ['hr_officer', 'موظف الموارد البشرية', 'HR officer'], ['finance', 'المالية', 'Finance'], ['ceo', 'الرئيس التنفيذي', 'CEO'], ['executive', 'المدير التنفيذي', 'Executive'], ['manager', 'المدير المباشر', 'Line manager']]
  const toggle = (k: 'pledges' | 'fee_roles', item: string) => setV({ ...v, [k]: v[k].includes(item) ? v[k].filter(x => x !== item) : [...v[k], item] })
  return <div className="max-w-[900px] mx-auto space-y-5">
    <PageHead title={L('إعدادات غير الموظفين والخبراء', 'Non-employee & expert settings')} sub={L('حدود التطوع، فحص طبيعة العلاقة، الاعتماد، الاتفاقية، قائمة الخبراء وسرية الأتعاب.', 'Volunteering limits, relationship check, approvals, the agreement, the expert roster and fee confidentiality.')}
      actions={edit && <><Btn icon={<RotateCcw size={15} />} onClick={() => setV(q.data!.defaults as S)}>{L('القيم الافتراضية', 'Defaults')}</Btn>
        <Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate(v, { onSuccess: () => toast(L('حُفظت الإعدادات', 'Settings saved')) })}>{L('حفظ', 'Save')}</Btn></>} />
    <Panel title={L('التطوع', 'Volunteering')}>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(240px,1fr))]">
        {num('max_months', L('المدة القصوى للاتفاقية', 'Maximum agreement length'), L('شهر', 'months'))}
        {num('max_weekly_hours', L('الحد الأعلى للساعات الأسبوعية', 'Max weekly hours'), L('ساعة', 'hours'))}
        {num('warn_pct', L('تنبيه عند بلوغ', 'Warn at'), '%')}
        {num('end_warn_days', L('تنبيه قبل انتهاء الاتفاقية', 'Warn before the agreement ends'), L('يوم', 'days'))}
        {num('expense_cap', L('حد تعويض المصاريف لكل طلب', 'Expense cap per claim'), String(v.expense_currency ?? ''))}
        {num('hour_value', L('القيمة التقديرية لساعة التطوع', 'Estimated value of a volunteer hour'), String(v.hour_currency ?? ''), L('للتقارير فقط. 0 = لا تُعرض.', 'Reports only. 0 = hidden.'))}
      </div>
      <Field label={L('وثائق إلزامية قبل التفعيل', 'Required documents before activation')} div className="mt-4"><div className="flex flex-wrap gap-3">{Object.entries(PLEDGE).map(([k, l]) => <label key={k} className="flex items-center gap-2 text-[13.5px]"><input type="checkbox" disabled={!edit} checked={v.pledges.includes(k)} onChange={() => toggle('pledges', k)} />{L(l[0], l[1])}</label>)}</div></Field>
      <Field label={L('قائمة التأهيل الأولي', 'Initial onboarding checklist')} div className="mt-4"><div className="space-y-1.5">{v.onboarding.map((i, n) => <div key={n} className="flex gap-2">
        <input className="field" disabled={!edit} value={i.ar} onChange={x => setV({ ...v, onboarding: v.onboarding.map((y, j) => j === n ? { ...y, ar: x.target.value } : y) })} />
        <input className="field ltr" disabled={!edit} value={i.en} onChange={x => setV({ ...v, onboarding: v.onboarding.map((y, j) => j === n ? { ...y, en: x.target.value } : y) })} />
        {edit && <button className="text-ink-3 hover:text-danger" onClick={() => setV({ ...v, onboarding: v.onboarding.filter((_, j) => j !== n) })} aria-label={L('حذف', 'Remove')}><X size={15} /></button>}</div>)}
        {edit && <Btn size="sm" kind="ghost" icon={<Plus size={13} />} onClick={() => setV({ ...v, onboarding: [...v.onboarding, { ar: '', en: '' }] })}>{L('بند', 'Item')}</Btn>}</div></Field>
    </Panel>
    <Panel title={L('طبيعة العلاقة والاعتماد', 'Relationship & approval')}>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(240px,1fr))]">
        {num('relation_threshold', L('عتبة فحص طبيعة العلاقة', 'Relationship check threshold'), L('من 6', 'of 6'), L('عند بلوغها يحتاج التكليف اعتماداً أعلى.', 'At this score the engagement needs higher approval.'))}
        {num('replaces_max_months', L('المدة القصوى لحالة «يحل محل موظف»', 'Max length for “replaces an employee”'), L('شهر', 'months'))}
        <Field label={L('العلاقة الافتراضية', 'Default relation')} div><Seg value={String(v.default_relation)} onChange={x => edit && setV({ ...v, default_relation: x })} options={[{ v: 'alongside', label: L('إلى جانب موظف', 'Alongside') }, { v: 'independent', label: L('مستقلة', 'Independent') }]} /></Field>
        <Field label={L('معتمد التكليف', 'Engagement approver')}><select className="field" disabled={!edit} value={String(v.approver)} onChange={x => setV({ ...v, approver: x.target.value })}>{roles.filter(r => ['hr_director', 'hr_officer', 'ceo', 'executive'].includes(r[0])).map(r => <option key={r[0]} value={r[0]}>{L(r[1], r[2])}</option>)}</select></Field>
        <Field label={L('الاعتماد الأعلى', 'Higher approval')}><select className="field" disabled={!edit} value={String(v.high_approver)} onChange={x => setV({ ...v, high_approver: x.target.value })}>{roles.filter(r => ['hr_director', 'ceo', 'executive'].includes(r[0])).map(r => <option key={r[0]} value={r[0]}>{L(r[1], r[2])}</option>)}</select></Field>
      </div>
    </Panel>
    <Panel title={L('قائمة الخبراء', 'Expert roster')}>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(240px,1fr))]">
        {num('shortlist_min', L('الحد الأدنى للقائمة المختصرة', 'Minimum shortlist'), L('خبير', 'experts'))}
        {num('roster_review_months', L('طلب تحديث البيانات كل', 'Ask for an update every'), L('شهر', 'months'))}
        {num('roster_inactive_days', L('تعطيل من لا يستجيب بعد', 'Deactivate if no reply after'), L('يوم', 'days'))}
        {num('doc_alert_days', L('تنبيه قبل انتهاء صلاحية الشهادات', 'Warn before certificates expire'), L('يوم', 'days'))}
      </div>
      <Field label={L('من يرى الأتعاب والتقييمات والملاحظات السرية', 'Who sees fees, ratings and confidential notes')} div className="mt-4"><div className="flex flex-wrap gap-3">{roles.map(r => <label key={r[0]} className="flex items-center gap-2 text-[13.5px]"><input type="checkbox" disabled={!edit} checked={v.fee_roles.includes(r[0])} onChange={() => toggle('fee_roles', r[0])} />{L(r[1], r[2])}</label>)}</div></Field>
    </Panel>
    <Panel title={L('قالب اتفاقية التطوع', 'Volunteer agreement template')} sub={L('حقول الدمج: :company :name :national_id :branch :department :supervisor :from :to :hours :relation :tasks :cap', 'Merge fields: :company :name :national_id :branch :department :supervisor :from :to :hours :relation :tasks :cap')}>
      <div className="grid gap-3 md:grid-cols-2">
        <textarea className="field font-sans text-[13px]" rows={14} disabled={!edit} value={String(v.agreement_ar ?? '')} onChange={x => setV({ ...v, agreement_ar: x.target.value })} />
        <textarea className="field ltr font-sans text-[13px]" rows={14} disabled={!edit} value={String(v.agreement_en ?? '')} onChange={x => setV({ ...v, agreement_en: x.target.value })} /></div>
      <p className="mt-2 text-[12px] text-ink-3">{L('اعرض القالب على مستشار قانوني قبل اعتماده، خصوصاً حالة «يحل محل موظف».', 'Have a legal adviser review the template, especially the “replaces an employee” case.')}</p>
    </Panel>
  </div>
}
