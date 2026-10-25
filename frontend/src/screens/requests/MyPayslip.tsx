import { useState } from 'react'
import { Loader2, Printer } from 'lucide-react'
import { useL, useLang, money } from '../../lib/store'
import { minsLabel } from '../../lib/employees'
import { useMyPayslips, type Payslip } from '../../lib/payroll'
import { Badge, Btn, Empty, Panel } from '../../ui/kit'

const monthName = (m: string, lang: 'ar' | 'en') => new Date(m + '-01T12:00').toLocaleDateString(lang === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB', { month: 'long', year: 'numeric' })

/** «كشفي الشهري» (Payslip في الديمو): الكشوف المغلقة + تقدير الشهر الجاري حتى أمس — تقديري حتى التكامل مع ZERP */
export function MyPayslip() {
  const L = useL(); const lang = useLang()
  const q = useMyPayslips(); const d = q.data
  const [sel, setSel] = useState<string | null>(null)
  if (!d) return <div className="grid place-items-center py-24">{q.isError ? <Empty title={L('تعذّر التحميل', 'Could not load')} /> : <Loader2 className="animate-spin text-brand" />}</div>
  const all: Payslip[] = [d.current, ...d.data]
  const p = all.find(x => x.month === sel) ?? all[0]
  const m = p.money, t = p.data, cur = m.currency
  const row = (k: string, v: number, neg?: boolean) => <div className="flex justify-between gap-3 py-2 border-b border-line-2 text-[14px]"><span className={neg ? 'text-ink-2' : ''}>{k}</span><span className={`num font-semibold shrink-0 ${neg ? 'text-danger' : ''}`}>{neg ? '− ' : ''}{money(v, cur, lang)}</span></div>
  return <div className="max-w-[880px] mx-auto">
    <div className="flex flex-wrap items-center justify-between gap-3 mb-4" data-noprint><h1 className="text-[22px] font-bold">{L('كشفي الشهري', 'My monthly sheet')}</h1>
      <div className="flex flex-wrap items-center gap-1 rounded-lg border border-line bg-surface p-0.5">{all.slice(0, 6).map(x => <button key={x.month} onClick={() => setSel(x.month)}
        className={`h-8 px-3 rounded-md text-[13px] ${p.month === x.month ? 'bg-brand text-on-brand font-semibold' : 'text-ink-2'}`}>{new Date(x.month + '-01T12:00').toLocaleDateString(lang === 'ar' ? 'ar-SY' : 'en-GB', { month: 'long' })}</button>)}</div></div>
    <div className="grid md:grid-cols-[1fr_280px] gap-5">
      <Panel title={<span className="flex flex-wrap items-center gap-2">{monthName(p.month, lang)}{p.status === 'estimate' ? <Badge tone="brass">{L('تقدير حتى أمس', 'Estimate to yesterday')}</Badge> : <Badge tone="brand">{L('مغلق', 'Closed')}</Badge>}</span>}
        action={<Btn size="sm" icon={<Printer size={14} />} onClick={() => window.print()}>{L('طباعة', 'Print')}</Btn>}>
        <div className="text-[12px] font-semibold text-ink-3 mb-1">{L('المستحقات', 'Earnings')}</div>
        {row(L('الراتب', 'Salary'), m.base)}{m.ot_pay > 0 && row(L(`أجر العمل الإضافي (${minsLabel(t.ot_paid, lang)})`, `Overtime pay (${minsLabel(t.ot_paid, lang)})`), m.ot_pay)}{!!m.bonus && row(L('مكافأة التقييم', 'Performance reward'), m.bonus)}
        <div className="text-[12px] font-semibold text-ink-3 mt-4 mb-1">{L('الاقتطاعات', 'Deductions')}</div>
        {row(L('التأمينات الاجتماعية (حصة العامل)', 'Social insurance (employee)'), m.insurance, true)}
        {m.late > 0 && row(L(`تأخير (${minsLabel(Math.round(t.deduct), 'ar')} حسم)`, `Lateness (${minsLabel(Math.round(t.deduct), 'en')} deducted)`), m.late, true)}
        {m.absent > 0 && row(L(`غياب (${t.absent} يوم)`, `Absence (${t.absent} d)`), m.absent, true)}
        {m.leave > 0 && row(L(`إجازات بلا أجر (${t.leave_unpaid} يوم)`, `Unpaid leave (${t.leave_unpaid} d)`), m.leave, true)}
        {!!m.discipline && row(L(`جزاءات المخالفات (${t.discipline_days ?? 0} يوم)`, `Disciplinary deductions (${t.discipline_days ?? 0} d)`), m.discipline, true)}
        {m.advance > 0 && row(L('سلفة', 'Advance'), m.advance, true)}
        <div className="flex justify-between pt-3 mt-1 text-[17px] font-bold"><span>{L('الصافي', 'Net')}</span><span className="num text-brand">{money(m.net, cur, lang)}</span></div>
        <p className="text-[11.5px] text-ink-3 mt-3">{L('كشف تقديري محسوب من بيانات الحضور؛ الكشف الرسمي يصدر عن الإدارة المالية.', 'Estimate from attendance data; the official payslip is issued by Finance.')}</p>
      </Panel>
      <div className="space-y-4">
        <div className="rounded-2xl border border-line bg-surface p-5"><div className="text-[12.5px] text-ink-3">{L('الصافي', 'Net')}</div><div className="text-[30px] font-bold font-display num text-brand">{money(m.net, cur, lang)}</div></div>
        <div className="rounded-2xl border border-line bg-surface p-5 text-[13px] space-y-2">
          {([[L('أيام العمل', 'Work days'), t.work_days], [L('أيام الحضور', 'Days present'), t.present], [L('غياب', 'Absent'), t.absent], [L('إجازات', 'Leave'), t.leave],
            [L('تأخير', 'Late'), minsLabel(t.late, lang)], [L('معفى بمبرر', 'Exempted'), `${t.exempted} × ${minsLabel(t.exempt_min, lang)}`], [L('موعد الصرف', 'Pay date'), d.pay_day]] as const).map(([k, v]) =>
            <div key={k} className="flex justify-between gap-3"><span className="text-ink-3">{k}</span><b className="num">{v}</b></div>)}
          {t.incomplete > 0 && <div className="text-danger text-[12px]">{L(`${t.incomplete} يوم ناقص بلا خروج — راجع الموارد البشرية`, `${t.incomplete} incomplete day(s) — contact HR`)}</div>}
        </div>
      </div>
    </div>
  </div>
}
