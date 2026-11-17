import { ArrowRight, Loader2 } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { fmtDate, money } from '../../lib/dates'
import { ELEMENT, useMissionPrint } from '../../lib/missions'
import { Paper } from '../../ui/docs'
import { Btn } from '../../ui/kit'

/** نموذج مهمة سفر (HR-11-F-05 — الرمز MSN): البيانات والوجهة والتكلفة التقديرية ومسار الاعتماد وتواقيعه */
export function MissionPrint({ id }: { id: number }) {
  const L = useL(); const lang = useLang()
  const { data } = useMissionPrint(id)
  if (!data) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const m = data.data; const t = m.travel
  const cell = (k: string, v: React.ReactNode) => <tr><td className="border border-line px-2 py-1 bg-sub w-1/3">{k}</td><td className="border border-line px-2 py-1">{v}</td></tr>
  return <div className="max-w-[860px] mx-auto"><div className="mb-2" data-noprint><Btn icon={<ArrowRight size={16} className="ltr:rotate-180" />} onClick={() => history.back()}>{L('رجوع', 'Back')}</Btn></div>
    <Paper code="MSN" title={L('نموذج مهمة سفر', 'Travel mission form')} sub={m.ref_no} subject={data.employee?.code}>
      <table className="w-full text-[13px] border-collapse"><tbody>
        {cell(L('الموظف', 'Employee'), <>{m.employee ? bi(m.employee, lang) : '—'} · {data.employee?.code} · {data.employee ? bi(data.employee.title, lang) : ''}{data.employee?.grade ? ` · ${data.employee.grade}` : ''}</>)}
        {cell(L('عنوان المهمة', 'Mission'), m.title)}
        {t && cell(L('الوجهة', 'Destination'), <>{t.city}{t.place && bi(t.place, lang) !== t.city ? ` (${bi(t.place, lang)})` : ''}{t.legs.length ? ` · ${t.legs.join(' ← ')}` : ''}</>)}
        {t && cell(L('الغرض', 'Purpose'), t.purpose)}
        {t && cell(L('الانطلاق والعودة', 'Departure & return'), <span className="num">{fmtDate(t.depart_on, lang)} — {fmtDate(t.return_on, lang)} ({m.estimate?.days ?? '—'} {L('يوم', 'days')})</span>)}
        {t && cell(L('طريقة السفر', 'Transport'), L({ road: 'طرقي', air: 'جوي', car: 'سيارة شخصية' }[t.transport] ?? '', { road: 'Road', air: 'Air', car: 'Personal car' }[t.transport] ?? '') + (t.km ? ` · ${t.km} km` : ''))}
        {m.estimate?.lines?.map((l, i) => <tr key={i}><td className="border border-line px-2 py-1 bg-sub">{l.code} · {ELEMENT[l.code] ? L(...ELEMENT[l.code]) : ''}</td><td className="border border-line px-2 py-1 num">{money(l.amount, l.currency, lang)}</td></tr>)}
        {m.advance && !m.advance.masked && cell(L('السلفة', 'Advance'), <span className="num">{money(m.advance.amount ?? 0, m.advance.currency ?? 'SYP', lang)}</span>)}
      </tbody></table>
      <div className="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-2">{[{ role: { ar: 'الموظف', en: 'Employee' }, by: null as null | { ar: string; en: string }, at: null as string | null }, ...m.steps].map((s, i) =>
        <div key={i} className="border border-line rounded-lg p-2 min-h-[80px] text-[12px]"><div className="font-semibold">{bi(s.role, lang)}</div>{s.by && <div>{bi(s.by, lang)}</div>}{s.at && (s as { status?: string }).status === 'approved' && <div className="num text-ink-3">{fmtDate(s.at, lang)}</div>}</div>)}</div>
    </Paper></div>
}
