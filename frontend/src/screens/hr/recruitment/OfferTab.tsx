import { useState } from 'react'
import { Loader2, Send } from 'lucide-react'
import { useL, useLang, money, todayISO } from '../../../lib/store'
import { useMe } from '../../../lib/session'
import { useIssueOffer, useRules, type CandFile, type OfferInput } from '../../../lib/candidates'
import type { ApiError } from '../../../lib/api'
import { Badge, Btn, Field, Panel, toast } from '../../../ui/kit'
import { OfferDoc, Paper, SigBox } from '../../../ui/docs'
import { ProtectedBlock } from '../../../ui/vault'

/** عرض العمل F-29: مبالغه لا تظهر ولا تُطبع ولا تُكتب قبل التحقق (ملاحظة الاختبار 82) */
export function OfferTab({ c, readOnly }: { c: CandFile; readOnly: boolean }) {
  const L = useL()
  return <ProtectedBlock masked={!!c.offer?.masked} title={L('مبالغ عرض العمل محمية', 'Offer amounts are protected')}><OfferInner c={c} readOnly={readOnly} /></ProtectedBlock>
}

/** عرض العمل F-29 (OfferTab في الديمو): إعداد العرض وإصداره، أو عرضه بحالته وتوقيع المتقدم */
function OfferInner({ c, readOnly }: { c: CandFile; readOnly: boolean }) {
  const L = useL(); const lang = useLang()
  const me = useMe().data
  const issue = useIssueOffer(c.id)
  const company = me?.company?.name
  const base = Math.max(c.job.salary_min, Math.round(c.salary * 0.8 / 10) * 10)
  const [o, setO] = useState<OfferInput>(() => c.offer
    ? { title: c.offer.title, contract: c.offer.contract, currency: c.offer.currency, basic: c.offer.basic, housing: c.offer.housing, transport: c.offer.transport, fuel: c.offer.fuel, leave_days: c.offer.leave_days, start_date: c.offer.start_date }
    : { title: c.job.title.ar, contract: 'fixed', currency: c.job.currency, basic: base, housing: Math.round(base * 0.12), transport: 40, fuel: 30, leave_days: 14, start_date: todayISO() })
  const locked = !!c.appointment || c.stage === 'hired' || c.stage === 'rejected'
  const [editing, setEditing] = useState(!locked && !readOnly && (!c.offer || c.offer.status === 'negotiation' || c.offer.status === 'returned'))
  const [why, setWhy] = useState('')
  const rules = useRules().data?.rules
  const err = issue.error as ApiError | null
  const total = o.basic + o.housing + o.transport + o.fuel
  const person = { name: c.name_ar, nameEn: c.name_en ?? undefined }

  if (c.offer && !editing) return <Paper code="F-29" company={undefined} subject={c.ref} title={L('عرض العمل الرسمي', 'Official job offer')}
    actions={!readOnly && !locked && c.offer.status !== 'accepted' ? <Btn size="sm" onClick={() => setEditing(true)}>{L('تعديل العرض', 'Edit offer')}</Btn> : undefined}>
    <div className="mb-4">{c.offer.status === 'pending_approval' ? <Badge tone="brass">{L('بانتظار موافقة تجاوز الميزانية — لم يُرسل للمتقدم بعد', 'Awaiting over-budget approval — not sent to the applicant yet')}</Badge> : c.offer.status === 'sent' ? <Badge tone="info">{L('أُرسل — بانتظار رد المتقدم', 'Sent — awaiting response')}</Badge> : c.offer.status === 'accepted' ? <Badge tone="brand">{L('مقبول وموقّع', 'Accepted & signed')}</Badge> : c.offer.status === 'declined' ? <Badge tone="danger">{L('اعتذر المتقدم', 'Declined')}</Badge> : <Badge tone="warn">{L('طلب تعديل', 'Change requested')}</Badge>}
      {c.offer.note && <div className="mt-2 text-[13px] text-ink-2">{L('ملاحظة المتقدم:', 'Applicant note:')} {c.offer.note}</div>}</div>
    <OfferDoc c={person} offer={c.offer} jobTitle={c.job.title.ar} company={company} />
    <div className="grid sm:grid-cols-2 gap-4 mt-6"><SigBox label={L('عن الشركة — الموارد البشرية', 'For the company — HR')} name={L('الموارد البشرية', 'Human Resources')} at={c.offer.issued_at} />
      <SigBox label={L('توقيع المتقدم', 'Applicant signature')} name={c.name_ar} sig={c.offer.signature ?? undefined} at={c.offer.responded_at ?? undefined} /></div>
  </Paper>

  if (!c.offer && (readOnly || locked)) return <Panel><div className="text-ink-2 text-[14px]">{L('لم يصدر عرض عمل لهذا المتقدم.', 'No offer has been issued for this applicant.')}</div></Panel>

  const num = (k: 'basic' | 'housing' | 'transport' | 'fuel' | 'leave_days') => <input type="number" min={0} className="field num" value={o[k]} onChange={e => setO({ ...o, [k]: Number(e.target.value) })} />
  // ملاحظتا الاختبار 56 و70: الحد الأعلى لراتب الشاغر = ميزانية العرض
  const mode = rules?.offer_budget ?? 'approval'
  const over = mode !== 'off' && (o.currency !== c.job.currency || total > c.job.salary_max)
  const noInterview = (rules?.offer_needs_interview ?? true) && (!c.interview || c.interview.recommendation === 'rejected')
  const submit = () => issue.mutate({ ...o, budget_reason: over ? why : undefined }, { onSuccess: d => { setEditing(false); toast(d.offer?.status === 'pending_approval' ? L('العرض يتجاوز الميزانية — أُرسل للموافقة قبل وصوله للمتقدم', 'Over budget — sent for approval before reaching the applicant') : L('أُرسل عرض العمل للمتقدم', 'Offer sent to applicant')) }, onError: e => { if (!(e as ApiError).errors || !Object.keys((e as ApiError).errors).length) toast((e as ApiError).message) } })
  return <Panel title={L('إعداد عرض العمل', 'Prepare job offer')} sub={<span className="ltr inline-block">F-29</span>}>
    {c.offer?.status === 'negotiation' && <div className="mb-4 text-[13px] bg-warn-soft text-warn rounded px-3 py-2">{L('طلب المتقدم', 'Applicant asked')}: {c.offer.note}</div>}
    {c.offer?.status === 'returned' && <div className="mb-4 text-[13px] bg-danger-soft text-danger rounded px-3 py-2">{L('لم تتم الموافقة على تجاوز الميزانية — عدّل المبالغ أو المبرر وأعد الإرسال. السبب في سجل المتقدم.', 'The over-budget offer was not approved — adjust the amounts or justification and resend. The reason is in the applicant log.')}</div>}
    {noInterview && <div className="mb-4 text-[13px] bg-warn-soft text-warn rounded px-3 py-2">{c.interview ? L('تقييم المقابلة F-25 «غير موصى به» — سياسة الشركة تمنع إصدار عرض.', 'The F-25 interview is “not recommended” — company policy blocks the offer.') : L('اعتمد تقييم المقابلة F-25 أولاً — سياسة الشركة لا تسمح بعرض دون مقابلة.', 'Save the F-25 interview first — company policy needs an interview before an offer.')}</div>}
    <div className="grid md:grid-cols-3 gap-4">
      <Field label={L('المسمى الوظيفي المعتمد', 'Approved title')} req error={err?.field('title')}><input className="field" value={o.title} onChange={e => setO({ ...o, title: e.target.value })} /></Field>
      <Field label={L('طبيعة العقد', 'Contract')}><select className="field" value={o.contract} onChange={e => setO({ ...o, contract: e.target.value as OfferInput['contract'] })}><option value="fixed">{L('محدد المدة — سنة قابلة للتجديد', 'Fixed term 1yr')}</option><option value="open">{L('غير محدد المدة', 'Open-ended')}</option><option value="project">{L('عقد مشروع', 'Project')}</option></select></Field>
      <Field label={L('العملة', 'Currency')}><select className="field" value={o.currency} onChange={e => setO({ ...o, currency: e.target.value as 'USD' | 'SYP' })}><option value="USD">{L('دولار أمريكي', 'US dollar')}</option><option value="SYP">{L('ليرة سورية', 'Syrian pound')}</option></select></Field>
      <Field label={L('الراتب الأساسي', 'Basic salary')} req error={err?.field('basic')}>{num('basic')}</Field><Field label={L('بدل السكن / غلاء المعيشة', 'Housing / COL')}>{num('housing')}</Field><Field label={L('بدل المواصلات', 'Transport')}>{num('transport')}</Field>
      <Field label={L('بدل المحروقات', 'Fuel')}>{num('fuel')}</Field><Field label={L('أيام الإجازة السنوية', 'Annual leave days')} error={err?.field('leave_days')}>{num('leave_days')}</Field>
      <Field label={L('تاريخ المباشرة', 'Start date')} req error={err?.field('start_date')}><input type="date" min={todayISO()} className="field" value={o.start_date} onChange={e => setO({ ...o, start_date: e.target.value })} /></Field>
    </div>
    {over && <div className="mt-4 rounded-md border border-danger/40 bg-danger-soft/50 p-3">
      <div className="text-[13px] font-semibold text-danger">{o.currency !== c.job.currency ? L(`عملة العرض تختلف عن عملة الشاغر (${c.job.currency})`, `The offer currency differs from the job’s (${c.job.currency})`) : L('العرض يتجاوز الحد الأعلى لراتب الشاغر', 'The offer exceeds the job’s maximum pay')}</div>
      {mode === 'block' ? <p className="text-[12.5px] text-ink-2 mt-1">{L('سياسة الشركة تمنع إرسال عرض فوق الميزانية — خفّض المبالغ أو عدّل نطاق راتب الشاغر.', 'Company policy blocks offers above budget — lower the amounts or change the job’s pay range.')}</p>
        : <Field label={L('مبرر تجاوز الميزانية (يُرسل للموافقة)', 'Justification for exceeding the budget (sent for approval)')} req error={err?.field('reason')}>
          <textarea className="field" value={why} maxLength={1000} onChange={e => setWhy(e.target.value)} placeholder={L('مثلاً: خبرة نادرة في المجال وعرض منافس من جهة أخرى', 'e.g. rare expertise and a competing offer')} /></Field>}
    </div>}
    <div className="flex flex-wrap items-center justify-between gap-3 mt-5 pt-4 border-t border-line-2">
      <div className="text-[14px]">{L('الإجمالي الشهري', 'Monthly total')}: <span className={`font-bold num ${over ? 'text-danger' : ''}`}>{money(total, o.currency, lang)}</span>
        <span className="text-ink-3 text-[13px]"> — {L('ميزانية الشاغر حتى', 'job budget up to')} <span className="num">{money(c.job.salary_max, c.job.currency, lang)}</span> · {L('توقّع المتقدم', 'expected')} <span className="num">{money(c.salary, c.job.currency, lang)}</span></span></div>
      <div className="flex gap-2">{c.offer && <Btn onClick={() => setEditing(false)}>{L('إلغاء', 'Cancel')}</Btn>}
        <Btn kind="primary" icon={issue.isPending ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />} disabled={issue.isPending || noInterview || (over && mode === 'block')} onClick={submit}>{over && mode === 'approval' ? L('إرسال للموافقة', 'Send for approval') : L('إصدار العرض وإرساله', 'Issue and send offer')}</Btn></div>
    </div>
  </Panel>
}
