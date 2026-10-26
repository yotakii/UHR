import { ArrowRight, ArrowLeft, Loader2 } from 'lucide-react'
import { useL, useLang, bi, fmtDate, money } from '../../lib/store'
import { useCertificate } from '../../lib/payroll'
import { Empty, Panel } from '../../ui/kit'
import { Paper } from '../../ui/docs'
import { ProtectedBlock } from '../../ui/vault'

/** شهادة راتب / عمل صادرة (D-16) — نموذج مطبوع بشعار الشركة ورقم تسلسلي وبيانات الجهة (الديمو: Services ← عرض الشهادة) */
export function CertificatePage({ id, hr, back }: { id: number; hr: boolean; back: string }) {
  const L = useL(); const lang = useLang()
  const q = useCertificate(id, hr); const c = q.data
  const Back = lang === 'ar' ? ArrowRight : ArrowLeft
  if (!c) return <div className="max-w-[900px] mx-auto">{q.isError ? <Panel><Empty title={L('الشهادة غير متاحة', 'Certificate not available')} /></Panel> : <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>}</div>
  const e = c.employee
  const he = e.gender === 'F'
  const name = bi(e.name, lang), title = bi(e.title, lang), since = e.hire_date ? fmtDate(e.hire_date, lang) : '—'
  const to = c.addressee?.name
  const text = c.kind === 'salary_cert'
    ? L(`تشهد ${c.company.ar} بأن ${he ? 'السيدة' : 'السيد'} ${e.name.ar}${e.national_id ? ` (الرقم الوطني ${e.national_id})` : ''} ${he ? 'تعمل' : 'يعمل'} لديها بوظيفة ${e.title.ar} منذ ${since}، ${he ? 'وتتقاضى' : 'ويتقاضى'} راتباً شهرياً إجمالياً قدره ${money(c.salary!.amount ?? 0, c.salary!.currency, 'ar')}. أُعطيت هذه الشهادة بناءً على ${he ? 'طلبها' : 'طلبه'} لتقديمها إلى: ${to}.`,
      `${c.company.en} certifies that ${name}${e.national_id ? ` (national ID ${e.national_id})` : ''} has been employed as ${title} since ${since}, with a gross monthly salary of ${money(c.salary!.amount ?? 0, c.salary!.currency, 'en')}. Issued at the employee’s request for: ${to}.`)
    : L(`تشهد ${c.company.ar} بأن ${he ? 'السيدة' : 'السيد'} ${e.name.ar} ${he ? 'تعمل' : 'يعمل'} لديها بوظيفة ${e.title.ar} منذ ${since} وما ${he ? 'تزال على رأس عملها' : 'يزال على رأس عمله'}. أُعطيت هذه الشهادة بناءً على ${he ? 'طلبها' : 'طلبه'}${to ? ` لتقديمها إلى: ${to}` : ''}.`,
      `${c.company.en} certifies that ${name} has been employed as ${title} since ${since} and remains in service.${to ? ` Issued at the employee’s request for: ${to}.` : ''}`)
  return <div className="max-w-[900px] mx-auto space-y-3">
    <a href={back} className="inline-flex items-center gap-1.5 text-[13.5px] text-ink-2 hover:text-ink" data-noprint><Back size={15} />{L('رجوع', 'Back')}</a>
    {c.masked ? <ProtectedBlock masked title={L('شهادة الراتب محمية', 'The salary certificate is protected')}>{null}</ProtectedBlock> :
    <Paper code={c.form} title={c.kind === 'salary_cert' ? L('شهادة راتب', 'Salary certificate') : L('شهادة عمل', 'Employment certificate')} sub={L('إلى من يهمه الأمر', 'To whom it may concern')} subject={c.ref_no}>
      <div className="flex flex-wrap justify-between gap-2 text-[13px] text-ink-2 mb-5"><span>{L('الرقم', 'No.')}: <b className="ltr inline-block text-ink">{c.ref_no}</b></span><span>{L('التاريخ', 'Date')}: <b className="num text-ink">{fmtDate(c.issued_at, lang)}</b></span></div>
      <p className="text-[15px] leading-9">{text}</p>
      {c.addressee?.cr_no && <div className="mt-5 rounded-lg bg-sub px-4 py-3 text-[13px] grid sm:grid-cols-2 gap-x-6 gap-y-1">
        <span>{L('الجهة', 'Organisation')}: <b>{c.addressee.name}</b></span><span>{L('السجل التجاري', 'Commercial reg.')}: <b className="ltr inline-block">{c.addressee.cr_no}</b></span>
        <span>{L('البريد', 'Email')}: <b className="ltr inline-block">{c.addressee.email}</b></span><span>{L('الهاتف', 'Phone')}: <b className="ltr inline-block">{c.addressee.phone}</b></span></div>}
      <div className="mt-10 flex justify-between items-end gap-4">
        <span className="text-[13px] text-ink-3">{L('الموارد البشرية', 'Human Resources')}<br /><b className="text-ink">{c.issuer ? bi(c.issuer, lang) : ''}</b></span>
        <span className="stamp text-brand text-[12px]">{bi(c.company, lang)} — {L('الموارد البشرية', 'HR')}</span>
      </div>
    </Paper>}
  </div>
}
