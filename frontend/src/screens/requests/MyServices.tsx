import { useState, type ReactNode } from 'react'
import { Banknote, FileSignature, FileText, Printer, UserRound } from 'lucide-react'
import { useL, useLang, bi, fmtDateTime, money } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { navigate, useRoute } from '../../lib/router'
import { useCancelService, useMyServices, useSubmitService, type ServiceInput, type ServiceKind } from '../../lib/payroll'
import { Btn, Empty, Field, Modal, PageHead, Panel, toast } from '../../ui/kit'
import { Masked } from '../../ui/vault'
import { DecisionDialog } from '../../ui/decision'
import { LeaveStatus, Period, StepsPath } from './parts'

const ICONS: Record<ServiceKind, ReactNode> = { salary_cert: <FileText size={20} />, experience_cert: <FileSignature size={20} />, advance: <Banknote size={20} />, data_update: <UserRound size={20} /> }

/**
 * «خدماتي» (Services في الديمو): شهادة راتب ببيانات الجهة الإلزامية (D-16)، شهادة عمل، سلفة ضمن الرصيد المتاح من الدوام (D-01)،
 * وتحديث البيانات — كلها بالمسار الموحد للموارد البشرية، والشهادة المعتمدة تُطبع برقمها التسلسلي.
 */
export function MyServices({ base }: { base: string }) {
  const L = useL(); const lang = useLang()
  const q = useMyServices(); const d = q.data
  const cancel = useCancelService()
  const route = useRoute()
  const [open, setOpen] = useState<ServiceKind | null>(['salary_cert', 'experience_cert', 'advance', 'data_update'].includes(route.query.new ?? '') ? route.query.new as ServiceKind : null)
  const [cancelId, setCancelId] = useState<number | null>(null)
  const av = d?.advance
  return <div className="max-w-[1100px] mx-auto space-y-4">
    <PageHead title={L('طلبات الخدمات', 'Service requests')} sub={L('الشهادات والسلفة وتحديث البيانات — تصل للموارد البشرية مباشرة، والشهادة المعتمدة تُطبع برقم تسلسلي.', 'Certificates, advances and data updates go straight to HR; an approved certificate prints with a serial number.')} />
    <div className="grid [grid-template-columns:repeat(auto-fill,minmax(210px,1fr))] gap-3">
      {(d?.kinds ?? []).map(k => <button key={k.key} onClick={() => setOpen(k.key)} className="hoverlift rounded-2xl border border-line bg-surface p-4 flex items-center gap-3 text-start">
        <span className="size-11 rounded-xl grid place-items-center bg-brand-soft text-brand shrink-0">{ICONS[k.key]}</span>
        <span className="min-w-0"><span className="block font-semibold text-[13.5px]">{bi(k, lang)}</span>
          {k.key === 'advance' && av && <span className="block text-[12px] text-ink-3">{L('المتاح الآن', 'Available now')}: <Masked plain value={av.available}><span className="num">{money(av.available ?? 0, av.currency, lang)}</span></Masked></span>}</span></button>)}
    </div>
    <Panel title={L('طلباتي', 'My requests')} pad={false}>
      {!d ? <Empty title={L('جارٍ التحميل…', 'Loading…')} /> : d.data.length === 0 ? <Empty title={L('لا توجد طلبات بعد', 'No requests yet')} /> :
        <ul className="divide-y divide-line-2">{d.data.map(x => <li key={x.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3">
          <span className="flex-1 min-w-[220px]"><span className="block font-semibold text-[13.5px]">{bi(x.type_name, lang)}</span>
            <span className="block text-[12.5px]"><Period r={x} /></span><span className="block text-[12px] text-ink-3">{fmtDateTime(x.created_at, lang)}</span></span>
          <span className="hidden md:block"><StepsPath steps={x.steps} /></span>
          <LeaveStatus s={x.status} />
          {x.ref_no ? <Btn size="sm" icon={<Printer size={14} />} onClick={() => navigate(`${base}/${x.id}`)}>{L('عرض الشهادة', 'View certificate')}</Btn>
            : x.can_cancel && <Btn size="sm" kind="danger" onClick={() => setCancelId(x.id)}>{L('إلغاء', 'Cancel')}</Btn>}
        </li>)}</ul>}
    </Panel>
    {d && <ServiceModal kind={open} onClose={() => setOpen(null)} available={av!.available} currency={av!.currency} />}
    <DecisionDialog open={cancelId !== null} onClose={() => setCancelId(null)} tone="danger" title={L('إلغاء الطلب', 'Cancel the request')} confirmLabel={L('تأكيد الإلغاء', 'Confirm cancel')}
      onConfirm={() => cancel.mutateAsync(cancelId!).then(() => toast(L('أُلغي الطلب', 'Request cancelled')))} />
  </div>
}

function ServiceModal({ kind, onClose, available, currency }: { kind: ServiceKind | null; onClose: () => void; available: number | null; currency: 'USD' | 'SYP' }) {
  const L = useL()
  const submit = useSubmitService()
  const [v, setV] = useState<ServiceInput>({ kind: 'salary_cert' })
  const [err, setErr] = useState<Record<string, string>>({})
  const [shown, setShown] = useState<ServiceKind | null>(null)
  if (kind !== shown) { setShown(kind); if (kind) { setV({ kind, addressee: {}, data: {}, notify_consent: false, amount: kind === 'advance' ? Math.min(available ?? 100, 100) : undefined }); setErr({}) } }
  if (!kind) return null
  const to = (k: 'name' | 'cr_no' | 'email' | 'phone', x: string) => setV({ ...v, addressee: { ...v.addressee, [k]: x } })
  const send = () => {
    setErr({})
    submit.mutateAsync(v).then(() => { toast(L('أُرسل الطلب للموارد البشرية', 'Sent to HR')); onClose() })
      .catch(e => e instanceof ApiError && setErr(Object.keys(e.errors).length ? Object.fromEntries(Object.entries(e.errors).map(([k, m]) => [k, m[0]])) : { note: e.message }))
  }
  const titles: Record<ServiceKind, string> = { salary_cert: L('شهادة راتب', 'Salary certificate'), experience_cert: L('شهادة عمل / تعريف', 'Employment certificate'), advance: L('سلفة على الراتب', 'Salary advance'), data_update: L('تحديث بيانات شخصية', 'Personal data update') }
  return <Modal open onClose={onClose} title={titles[kind]} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={submit.isPending} onClick={send}>{L('إرسال الطلب', 'Submit')}</Btn></>}>
    <div className="space-y-3">
      {kind === 'salary_cert' && <>
        <p className="text-[13px] text-ink-3">{L('بيانات الجهة الطالبة إلزامية — الطلب الناقص لا يُرسل.', 'The requesting organisation’s details are required.')}</p>
        <Field label={L('اسم الجهة', 'Organisation name')} req error={err['addressee.name']}><input className="field" value={v.addressee?.name ?? ''} onChange={e => to('name', e.target.value)} placeholder={L('مثلاً: المصرف التجاري السوري', 'e.g. Commercial Bank of Syria')} /></Field>
        <div className="grid sm:grid-cols-2 gap-3">
          <Field label={L('رقم السجل التجاري', 'Commercial reg. no.')} req error={err['addressee.cr_no']}><input className="field ltr" value={v.addressee?.cr_no ?? ''} onChange={e => to('cr_no', e.target.value)} /></Field>
          <Field label={L('هاتف الجهة', 'Phone')} req error={err['addressee.phone']}><input className="field ltr" value={v.addressee?.phone ?? ''} onChange={e => to('phone', e.target.value)} /></Field>
        </div>
        <Field label={L('البريد الرسمي للجهة', 'Official email')} req error={err['addressee.email']}><input type="email" className="field ltr" value={v.addressee?.email ?? ''} onChange={e => to('email', e.target.value)} /></Field>
        <label className="flex items-start gap-2 text-[13.5px]"><input type="checkbox" className="mt-1" checked={!!v.notify_consent} onChange={e => setV({ ...v, notify_consent: e.target.checked })} />
          {L('أوافق على إبلاغ هذه الجهة عند انتهاء خدمتي', 'I agree that this organisation is notified when my service ends')}</label>
      </>}
      {kind === 'experience_cert' && <Field label={L('الجهة الموجّه إليها (اختياري)', 'Addressed to (optional)')} error={err['addressee.name']}><input className="field" value={v.addressee?.name ?? ''} onChange={e => to('name', e.target.value)} /></Field>}
      {kind === 'advance' && <Field label={L(`المبلغ (${currency === 'USD' ? 'دولار' : 'ل.س'})`, `Amount (${currency})`)} req error={err.amount}
        hint={available === null ? <span className="inline-flex flex-wrap items-center gap-1.5">{L('الحد المتاح محمي', 'The available limit is protected')} <Masked value={null}>{null}</Masked></span>
          : L(`المتاح الآن من دوامك هذا الشهر: ${money(available, currency, 'ar')} — تُخصم من كشف الشهر`, `Available now from this month’s attendance: ${money(available, currency, 'en')} — deducted from this month’s sheet`)}>
        <input type="number" min={1} max={available ?? undefined} className="field num" value={v.amount ?? ''} onChange={e => setV({ ...v, amount: Number(e.target.value) })} disabled={available !== null && available <= 0} /></Field>}
      {kind === 'data_update' && <Field label={L('الجوال الجديد (اختياري)', 'New mobile (optional)')} error={err['data.phone']}><input className="field ltr" value={v.data?.phone ?? ''} onChange={e => setV({ ...v, data: { phone: e.target.value } })} /></Field>}
      <Field label={kind === 'data_update' ? L('ما المطلوب تحديثه', 'What should be updated') : L('ملاحظات', 'Notes')} req={kind === 'data_update'} error={err.note ?? err.kind}>
        <textarea className="field" value={v.note ?? ''} maxLength={1000} onChange={e => setV({ ...v, note: e.target.value })} /></Field>
    </div>
  </Modal>
}
