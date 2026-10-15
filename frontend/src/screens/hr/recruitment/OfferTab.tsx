import { useState } from 'react'
import { Loader2, Send } from 'lucide-react'
import { useL, useLang, money, todayISO } from '../../../lib/store'
import { useMe } from '../../../lib/session'
import { useIssueOffer, type CandFile, type OfferInput } from '../../../lib/candidates'
import type { ApiError } from '../../../lib/api'
import { Badge, Btn, Field, Panel, toast } from '../../../ui/kit'
import { OfferDoc, Paper, SigBox } from '../../../ui/docs'

/** عرض العمل F-29 (OfferTab في الديمو): إعداد العرض وإصداره، أو عرضه بحالته وتوقيع المتقدم */
export function OfferTab({ c, readOnly }: { c: CandFile; readOnly: boolean }) {
  const L = useL(); const lang = useLang()
  const me = useMe().data
  const issue = useIssueOffer(c.id)
  const company = me?.company?.name
  const base = Math.max(c.job.salary_min, Math.round(c.salary * 0.8 / 10) * 10)
  const [o, setO] = useState<OfferInput>(() => c.offer
    ? { title: c.offer.title, contract: c.offer.contract, currency: c.offer.currency, basic: c.offer.basic, housing: c.offer.housing, transport: c.offer.transport, fuel: c.offer.fuel, leave_days: c.offer.leave_days, start_date: c.offer.start_date }
    : { title: c.job.title.ar, contract: 'fixed', currency: c.job.currency, basic: base, housing: Math.round(base * 0.12), transport: 40, fuel: 30, leave_days: 14, start_date: todayISO() })
  const locked = !!c.appointment || c.stage === 'hired' || c.stage === 'rejected'
  const [editing, setEditing] = useState(!locked && !readOnly && (!c.offer || c.offer.status === 'negotiation'))
  const err = issue.error as ApiError | null
  const total = o.basic + o.housing + o.transport + o.fuel
  const person = { name: c.name_ar, nameEn: c.name_en ?? undefined }

  if (c.offer && !editing) return <Paper code="F-29" company={undefined} subject={c.ref} title={L('عرض العمل الرسمي', 'Official job offer')}
    actions={!readOnly && !locked && c.offer.status !== 'accepted' ? <Btn size="sm" onClick={() => setEditing(true)}>{L('تعديل العرض', 'Edit offer')}</Btn> : undefined}>
    <div className="mb-4">{c.offer.status === 'sent' ? <Badge tone="info">{L('أُرسل — بانتظار رد المتقدم', 'Sent — awaiting response')}</Badge> : c.offer.status === 'accepted' ? <Badge tone="brand">{L('مقبول وموقّع', 'Accepted & signed')}</Badge> : c.offer.status === 'declined' ? <Badge tone="danger">{L('اعتذر المتقدم', 'Declined')}</Badge> : <Badge tone="warn">{L('طلب تعديل', 'Change requested')}</Badge>}
      {c.offer.note && <div className="mt-2 text-[13px] text-ink-2">{L('ملاحظة المتقدم:', 'Applicant note:')} {c.offer.note}</div>}</div>
    <OfferDoc c={person} offer={c.offer} jobTitle={c.job.title.ar} company={company} />
    <div className="grid sm:grid-cols-2 gap-4 mt-6"><SigBox label={L('عن الشركة — الموارد البشرية', 'For the company — HR')} name={L('الموارد البشرية', 'Human Resources')} at={c.offer.issued_at} />
      <SigBox label={L('توقيع المتقدم', 'Applicant signature')} name={c.name_ar} sig={c.offer.signature ?? undefined} at={c.offer.responded_at ?? undefined} /></div>
  </Paper>

  if (!c.offer && (readOnly || locked)) return <Panel><div className="text-ink-2 text-[14px]">{L('لم يصدر عرض عمل لهذا المتقدم.', 'No offer has been issued for this applicant.')}</div></Panel>

  const num = (k: 'basic' | 'housing' | 'transport' | 'fuel' | 'leave_days') => <input type="number" min={0} className="field num" value={o[k]} onChange={e => setO({ ...o, [k]: Number(e.target.value) })} />
  const submit = () => issue.mutate(o, { onSuccess: () => { setEditing(false); toast(L('أُرسل عرض العمل للمتقدم', 'Offer sent to applicant')) }, onError: e => { if (!(e as ApiError).errors || !Object.keys((e as ApiError).errors).length) toast((e as ApiError).message) } })
  return <Panel title={L('إعداد عرض العمل', 'Prepare job offer')} sub={<span className="ltr inline-block">F-29</span>}>
    {c.offer?.status === 'negotiation' && <div className="mb-4 text-[13px] bg-warn-soft text-warn rounded px-3 py-2">{L('طلب المتقدم', 'Applicant asked')}: {c.offer.note}</div>}
    <div className="grid md:grid-cols-3 gap-4">
      <Field label={L('المسمى الوظيفي المعتمد', 'Approved title')} req error={err?.field('title')}><input className="field" value={o.title} onChange={e => setO({ ...o, title: e.target.value })} /></Field>
      <Field label={L('طبيعة العقد', 'Contract')}><select className="field" value={o.contract} onChange={e => setO({ ...o, contract: e.target.value as OfferInput['contract'] })}><option value="fixed">{L('محدد المدة — سنة قابلة للتجديد', 'Fixed term 1yr')}</option><option value="open">{L('غير محدد المدة', 'Open-ended')}</option><option value="project">{L('عقد مشروع', 'Project')}</option></select></Field>
      <Field label={L('العملة', 'Currency')}><select className="field" value={o.currency} onChange={e => setO({ ...o, currency: e.target.value as 'USD' | 'SYP' })}><option value="USD">{L('دولار أمريكي', 'US dollar')}</option><option value="SYP">{L('ليرة سورية', 'Syrian pound')}</option></select></Field>
      <Field label={L('الراتب الأساسي', 'Basic salary')} req error={err?.field('basic')}>{num('basic')}</Field><Field label={L('بدل السكن / غلاء المعيشة', 'Housing / COL')}>{num('housing')}</Field><Field label={L('بدل المواصلات', 'Transport')}>{num('transport')}</Field>
      <Field label={L('بدل المحروقات', 'Fuel')}>{num('fuel')}</Field><Field label={L('أيام الإجازة السنوية', 'Annual leave days')} error={err?.field('leave_days')}>{num('leave_days')}</Field>
      <Field label={L('تاريخ المباشرة', 'Start date')} req error={err?.field('start_date')}><input type="date" min={todayISO()} className="field" value={o.start_date} onChange={e => setO({ ...o, start_date: e.target.value })} /></Field>
    </div>
    <div className="flex flex-wrap items-center justify-between gap-3 mt-5 pt-4 border-t border-line-2">
      <div className="text-[14px]">{L('الإجمالي الشهري', 'Monthly total')}: <span className="font-bold num">{money(total, o.currency, lang)}</span> <span className="text-ink-3 text-[13px]">— {L('توقّع المتقدم', 'expected')} <span className="num">${c.salary}</span></span></div>
      <div className="flex gap-2">{c.offer && <Btn onClick={() => setEditing(false)}>{L('إلغاء', 'Cancel')}</Btn>}
        <Btn kind="primary" disabled={issue.isPending} icon={issue.isPending ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />} onClick={submit}>{L('إصدار العرض وإرساله', 'Issue and send offer')}</Btn></div>
    </div>
  </Panel>
}
