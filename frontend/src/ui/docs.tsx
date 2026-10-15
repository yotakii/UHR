import type { ReactNode } from 'react'
import { Printer, CheckCircle2, Circle, AlertTriangle, XCircle, Info } from 'lucide-react'
import { useL, useLang, fmtDate, fmtDateTime, money } from '../lib/store'
import type { LogEvent } from '../lib/types'
import type { MyOffer, MyAppointment } from '../lib/recruitment'

/** ما تحتاجه الوثائق فقط (بشكل بيانات الـ API) */
type Person = { name: string; nameEn?: string }
type OfferTerms = Pick<MyOffer, 'title' | 'contract' | 'currency' | 'basic' | 'housing' | 'transport' | 'fuel' | 'leave_days' | 'start_date'>
import { Btn } from './kit'

export function Paper({ code, title, sub, children, actions }: { code: string; title: string; sub?: string; children: ReactNode; actions?: ReactNode }) {
  const L = useL(); const lang = useLang()
  return <div className="print-root">
    <div className="flex justify-end gap-2 mb-2" data-noprint>{actions}<Btn size="sm" icon={<Printer size={15} />} onClick={() => window.print()}>{L('طباعة النموذج', 'Print form')}</Btn></div>
    <article className="paper rounded-2xl">
      <header className="paper-head px-5 sm:px-7 pt-6 pb-4 flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div>
          <div className="font-bold text-[15px]">{L('شركة الفرادة للتطوير والاستثمار', 'Uniqueness Development & Investment Co.')}</div>
          <div className="text-[12px] text-ink-3">{L('إدارة الموارد البشرية', 'Human Resources Department')}</div>
          <h2 className="text-[19px] font-bold mt-3">{title}</h2>
          {sub && <p className="text-[13px] text-ink-2 mt-0.5">{sub}</p>}
        </div>
        <table className="text-[12px] border border-ink shrink-0" dir={lang === 'ar' ? 'rtl' : 'ltr'}>
          <tbody>
            {code && <tr><td className="border border-ink px-2 py-1 text-ink-3">{L('رقم النموذج', 'Form no.')}</td><td className="border border-ink px-2 py-1 font-semibold ltr">Uniqueness-HR-01-{code}</td></tr>}
            <tr><td className="border border-ink px-2 py-1 text-ink-3">{L('الإصدار', 'Version')}</td><td className="border border-ink px-2 py-1">1.0</td></tr>
            <tr><td className="border border-ink px-2 py-1 text-ink-3">{L('تاريخ الطباعة', 'Printed')}</td><td className="border border-ink px-2 py-1 num">{fmtDate(new Date().toISOString(), lang)}</td></tr>
          </tbody>
        </table>
      </header>
      <div className="px-5 sm:px-7 py-6">{children}</div>
    </article>
  </div>
}

export function SigBox({ label, name, sig, at }: { label: string; name?: string; sig?: string; at?: string }) {
  const lang = useLang(); const L = useL()
  return <div className="border border-line rounded-md p-3 min-h-[120px] flex flex-col">
    <div className="text-[12px] text-ink-3">{label}</div>
    {name && <div className="text-[14px] font-medium">{name}</div>}
    <div className="flex-1 grid place-items-center">{sig ? <img src={sig} alt="" className="max-h-16 bg-white rounded p-1" /> : <span className="text-[12px] text-ink-3">{L('لم يُوقّع بعد', 'Not signed yet')}</span>}</div>
    {at && <div className="text-[11px] text-ink-3 num">{fmtDateTime(at, lang)}</div>}
  </div>
}

export function Timeline({ items, compact }: { items: LogEvent[]; compact?: boolean }) {
  const lang = useLang()
  const sorted = [...items].sort((a, b) => b.at.localeCompare(a.at))
  const icon = (k?: string) => k === 'ok' ? <CheckCircle2 size={16} className="text-brand" /> : k === 'warn' ? <AlertTriangle size={16} className="text-warn" /> : k === 'danger' ? <XCircle size={16} className="text-danger" /> : <Info size={16} className="text-info" />
  return <ol className="relative">
    {sorted.map((e, i) => <li key={i} className="relative flex gap-3 pb-3">
      {i < sorted.length - 1 && <span className="absolute top-5 bottom-0 start-[7.5px] w-px bg-line" />}
      <span className="relative bg-surface mt-0.5">{icon(e.kind)}</span>
      <div className="min-w-0 flex-1">
        <div className={`${compact ? 'text-[13px]' : 'text-[14px]'}`}>{e[lang]}{e.form && <span className="ms-2 text-[11px] text-ink-3 border border-line rounded px-1 ltr">{e.form}</span>}</div>
        <div className="text-[12px] text-ink-3 num">{fmtDateTime(e.at, lang)}</div>
      </div>
    </li>)}
  </ol>
}

export function Lifecycle({ steps }: { steps: { label: string; code?: string; done: boolean; current?: boolean }[] }) {
  return <ol className="flex overflow-x-auto pb-1">
    {steps.map((s, i) => <li key={i} className="flex-1 min-w-[96px] relative">
      <div className="flex items-center">
        <span className={`relative z-10 size-7 rounded-full grid place-items-center border-2 shrink-0 ${s.done ? 'bg-brand border-brand text-on-brand' : s.current ? 'bg-surface border-brass text-brass' : 'bg-surface border-line text-ink-3'}`}>
          {s.done ? <CheckCircle2 size={15} /> : <Circle size={9} fill="currentColor" />}
        </span>
        {i < steps.length - 1 && <span className={`h-0.5 flex-1 ${s.done ? 'bg-brand' : 'bg-line'}`} />}
      </div>
      <div className={`mt-1.5 pe-2 text-[12px] leading-tight ${s.current ? 'font-semibold text-ink' : s.done ? 'text-ink' : 'text-ink-3'}`}>{s.label}</div>
      {s.code && <div className="text-[10px] text-ink-3"><bdi>{s.code}</bdi></div>}
    </li>)}
  </ol>
}

export function OfferDoc({ c, offer, jobTitle }: { c: Person; offer: OfferTerms; jobTitle: string }) {
  const L = useL(); const lang = useLang()
  const total = offer.basic + offer.housing + offer.transport + offer.fuel
  const contract = { fixed: L('محدد المدة — سنة قابلة للتجديد', 'Fixed term — 1 year renewable'), open: L('غير محدد المدة', 'Open-ended'), project: L('عقد مشروع', 'Project contract') }[offer.contract]
  return <div className="space-y-5 text-[14px]">
    <p>{L(`السيد/ة ${c.name} المحترم/ة،`, `Dear ${c.nameEn || c.name},`)}</p>
    <p className="max-w-[70ch]">{L(`يسرّنا أن نعرض عليك العمل لدى شركة الفرادة بوظيفة «${offer.title || jobTitle}» وفق الشروط التالية، على أن يكون العرض نافذاً لمدة خمسة أيام من تاريخ إصداره.`, `We are pleased to offer you the position of "${offer.title || jobTitle}" at Uniqueness on the terms below. This offer is valid for five days from issue.`)}</p>
    <table className="w-full border border-line text-[14px]">
      <tbody>
        {[[L('طبيعة العقد', 'Contract'), contract], [L('الراتب الأساسي', 'Basic salary'), money(offer.basic, offer.currency, lang)], [L('بدل السكن / غلاء المعيشة', 'Housing / cost of living'), money(offer.housing, offer.currency, lang)], [L('بدل المواصلات', 'Transport'), money(offer.transport, offer.currency, lang)], [L('بدل المحروقات', 'Fuel'), money(offer.fuel, offer.currency, lang)], [L('الإجازة السنوية المدفوعة', 'Paid annual leave'), `${offer.leave_days} ${L('يوماً', 'days')}`], [L('تاريخ المباشرة المتوقع', 'Expected start date'), fmtDate(offer.start_date, lang)], [L('فترة التجربة', 'Probation'), L('90 يوماً', '90 days')]].map(([k, v]) =>
          <tr key={k}><td className="border border-line px-3 py-2 bg-sub w-1/2">{k}</td><td className="border border-line px-3 py-2 num font-medium">{v}</td></tr>)}
        <tr><td className="border border-ink px-3 py-2 font-bold">{L('إجمالي الراتب الشهري الشامل', 'Total monthly package')}</td><td className="border border-ink px-3 py-2 font-bold num">{money(total, offer.currency, lang)}</td></tr>
      </tbody>
    </table>
  </div>
}

export function DecisionDoc({ c, ap, jobTitle, deptName }: { c: Person; ap: Pick<MyAppointment, 'decision_no' | 'approvals'>; jobTitle: string; deptName: string }) {
  const L = useL(); const lang = useLang()
  return <div className="space-y-5 text-[14px]">
    <div className="grid sm:grid-cols-4 gap-3">
      {[[L('رقم القرار', 'Decision no.'), ap.decision_no], [L('اسم الموظف المعيّن', 'Appointee'), c.name], [L('المنصب', 'Position'), jobTitle], [L('الإدارة', 'Department'), deptName]].map(([k, v]) => <div key={k} className="border border-line rounded-md p-2.5"><div className="text-[12px] text-ink-3">{k}</div><div className="font-semibold">{v}</div></div>)}
    </div>
    <div>
      <h4 className="font-semibold mb-1">{L('شروط القرار', 'Terms')}</h4>
      <ul className="list-disc ps-5 space-y-1 text-ink-2">
        <li>{L('يخضع الموظف لفترة تجربة مدتها 90 يوماً قابلة للتمديد وفق نظام العمل.', 'A 90-day probation period applies, extendable per labour regulations.')}</li>
        <li>{L('يُصرف الراتب وفق عرض العمل الموقّع، ويُضم الموظف للتأمينات الاجتماعية.', 'Salary per the signed offer; employee is registered with social insurance.')}</li>
        <li>{L('يستحق الموظف الإجازة السنوية وفق سياسة الشركة بعد التثبيت.', 'Annual leave per company policy after confirmation.')}</li>
      </ul>
    </div>
    <div>
      <h4 className="font-semibold mb-2">{L('سلسلة الاعتماد', 'Approval chain')}</h4>
      <div className="grid sm:grid-cols-4 gap-3">
        {ap.approvals.map(a => <div key={a.level} className={`border rounded-md p-3 ${a.status === 'approved' ? 'border-brand' : 'border-line'}`}>
          <div className="text-[12px] text-ink-3">{a.level}. {a.role[lang]}</div>
          <div className="font-medium">{a.name}</div>
          <div className="mt-2 h-8">{a.status === 'approved' ? <span className="stamp text-brand text-[12px]">{L('معتمد', 'APPROVED')}</span> : <span className="text-[12px] text-brass">{L('بانتظار الاعتماد', 'Pending')}</span>}</div>
          {a.at && <div className="text-[11px] text-ink-3 num mt-1">{fmtDateTime(a.at, lang)}</div>}
        </div>)}
      </div>
    </div>
  </div>
}
