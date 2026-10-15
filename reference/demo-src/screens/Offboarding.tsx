import { useState } from 'react'
import { Check } from 'lucide-react'
import { useStore, useL, useLang, fmtDate, fmtDateTime, money, getState, useDisplayName } from '../lib/store'
import { advanceResignation, rejectResignation, toggleClearance, saveExit, closeResignation } from '../lib/actions'
import { useNav } from '../lib/nav'
import { Btn, Panel, Badge, Avatar, PageHead, Empty, KV, Field, toast } from '../ui/kit'
import { Paper, SigBox } from '../ui/docs'
import { settlement } from '../lib/rules'
import type { Resignation } from '../lib/types'

export const REASONS: Record<string, [string, string]> = {
  offer: ['فرصة عمل أخرى بامتيازات أفضل', 'Another job offer'], travel: ['السفر أو ظروف عائلية', 'Travel or family reasons'],
  study: ['أسباب صحية أو دراسية', 'Health or study reasons'], other: ['أسباب أخرى', 'Other reasons'],
}
const stLabel = (s: Resignation['status'], L: (a: string, b: string) => string) => ({ pending_manager: <Badge tone="info">{L('بانتظار المدير المباشر', 'Awaiting manager')}</Badge>, pending_hr: <Badge tone="brass">{L('بانتظار الموارد البشرية', 'Awaiting HR')}</Badge>, clearance: <Badge tone="warn">{L('إبراء ذمة', 'Clearance')}</Badge>, closed: <Badge tone="danger">{L('انتهت الخدمة', 'Closed')}</Badge>, rejected: <Badge>{L('مسحوبة', 'Withdrawn')}</Badge> }[s])

export function Offboarding() {
  const L = useL(); const lang = useLang()
  const res = useStore(s => s.resignations); const emps = useStore(s => s.employees)
  const [sel, setSel] = useState<string | null>(res.find(r => r.status !== 'closed' && r.status !== 'rejected')?.id ?? res[0]?.id ?? null)
  const r = res.find(x => x.id === sel)
  return <div className="max-w-[1280px] mx-auto">
    <PageHead title={L('الاستقالات وإنهاء الخدمة', 'Resignations & exits')} sub={L('من طلب الاستقالة حتى إبراء الذمة والمخالصة النهائية.', 'From resignation request to clearance and final settlement.')} />
    <div className="grid lg:grid-cols-[320px_1fr] gap-5 items-start">
      <Panel pad={false}>{res.length === 0 ? <Empty title={L('لا توجد طلبات استقالة', 'No resignations')} /> : <ul className="divide-y divide-line-2">{res.map(x => { const e = emps.find(y => y.id === x.empId)!
        return <li key={x.id}><button onClick={() => setSel(x.id)} className={`w-full text-start px-4 py-3 flex gap-3 items-center ${sel === x.id ? 'bg-brand-soft/60' : 'hover:bg-sub'}`}><Avatar name={e.name} size={32} tone={e.visitor ? 'brass' : 'brand'} /><div className="flex-1 min-w-0"><div className="font-medium truncate">{lang === 'ar' ? e.name : e.nameEn}</div><div className="text-[12px] text-ink-3">{fmtDate(x.createdAt, lang)}</div></div>{stLabel(x.status, L)}</button></li> })}</ul>}</Panel>
      {r ? <ResignationDetail key={r.id} r={r} /> : <Panel><Empty title={L('اختر طلباً لعرض تفاصيله', 'Select a request')} /></Panel>}
    </div>
  </div>
}

function ResignationDetail({ r }: { r: Resignation }) {
  const L = useL(); const lang = useLang(); const { go } = useNav(); const dn = useDisplayName()
  const e = useStore(s => s.employees.find(x => x.id === r.empId))!
  const mgr = useStore(s => s.employees.find(x => x.id === e.managerId))
  const me = useStore(s => s.employees.find(x => x.id === s.session?.id))
  const st = settlement(e, r.lastDay, getState())
  useStore(s => s.assets)
  const [exit, setExit] = useState(r.exit ?? { rating: 4, wouldReturn: true, feedback: '' })
  const clr: [keyof Resignation['clearance'], string, string][] = [['custody', L('استلام العهد كاملة', 'All custody returned'), st.unreturned.length ? L(`${st.unreturned.length} عهد لم تُسترجع`, `${st.unreturned.length} assets outstanding`) : L('لا توجد عهد معلّقة', 'None outstanding')], ['it', L('إغلاق الحسابات والبريد', 'Accounts & email closed'), L('تقنية المعلومات', 'IT')], ['finance', L('تسوية السلف والذمم المالية', 'Advances settled'), L('المالية', 'Finance')], ['hr', L('مقابلة الخروج وتسليم المهام', 'Exit interview & handover'), L('الموارد البشرية', 'HR')]]
  const allClear = Object.values(r.clearance).every(Boolean)
  return <div className="space-y-4">
    <Panel title={<span className="flex items-center gap-2">{lang === 'ar' ? e.name : e.nameEn} {stLabel(r.status, L)}</span>} sub={`${e.code} — ${e.title[lang]}`} action={<Btn size="sm" onClick={() => go('employee', e.id)}>{L('الملف الوظيفي', 'Employee file')}</Btn>}>
      <div className="grid md:grid-cols-[1fr_260px] gap-5">
        <div><div className="text-[12px] text-ink-3 mb-1">{L('نص الاستقالة الخطية', 'Resignation letter')} — F-26</div><p className="text-[15px] leading-7 bg-canvas rounded-md p-3">{r.text}</p>
          <div className="text-[13px] text-ink-2 mt-2">{L('السبب الرئيسي', 'Main reason')}: {REASONS[r.reasonKey]?.[lang === 'ar' ? 0 : 1]}</div>{r.managerNote && <div className="text-[13px] text-ink-2">{L('توصية المدير', 'Manager note')}: {r.managerNote}</div>}</div>
        <div><KV k={L('تاريخ التقديم', 'Submitted')} v={fmtDate(r.createdAt, lang)} /><KV k={L('آخر يوم عمل', 'Last day')} v={fmtDate(r.lastDay, lang)} />
          {r.chain.map((c, i) => <KV key={i} k={`${i + 1}. ${c.role[lang]}`} v={c.at ? <span className="text-brand">{dn(c.by)} <span className="text-[11px] text-ink-3 num">{fmtDateTime(c.at, lang)}</span></span> : <span className="text-ink-3">{L('بانتظار', 'Pending')}</span>} />)}</div>
      </div>
      {(r.status === 'pending_manager' || r.status === 'pending_hr') && <div className="flex gap-2 mt-4 pt-4 border-t border-line-2">
        <Btn kind="primary" onClick={() => { advanceResignation(r.id, r.status === 'pending_manager' ? (mgr?.name ?? '') : (me?.name ?? 'HR')); toast(L('اعتُمدت', 'Approved')) }}>{r.status === 'pending_manager' ? L(`موافقة المدير المباشر (${mgr?.name ?? ''})`, 'Manager approval') : L('موافقة الموارد البشرية وبدء إبراء الذمة', 'HR approve & start clearance')}</Btn>
        <Btn kind="ghost" onClick={() => rejectResignation(r.id, me?.name ?? 'HR')}>{L('سحب الطلب بعد التفاهم مع الموظف', 'Withdraw after discussion')}</Btn></div>}
    </Panel>

    {(r.status === 'clearance' || r.status === 'closed') && <>
      <div className="grid md:grid-cols-2 gap-4">
        <Panel title={L('إبراء الذمة', 'Clearance')} sub="F-33">
          {clr.map(([k, a, b]) => <button key={k} disabled={r.status === 'closed'} onClick={() => toggleClearance(r.id, k)} className="w-full flex items-center gap-3 py-2.5 border-b border-line-2 last:border-0 text-start">
            <span className={`size-5 rounded border grid place-items-center ${r.clearance[k] ? 'bg-brand border-brand text-on-brand' : 'border-ink-3'}`}>{r.clearance[k] && <Check size={13} />}</span>
            <span className="flex-1"><span className="block text-[14px]">{a}</span><span className="block text-[12px] text-ink-3">{b}</span></span></button>)}
        </Panel>
        <Panel title={L('مقابلة الخروج', 'Exit interview')}>
          <div className="space-y-3">
            <Field div label={L('تقييم الموظف لتجربة العمل (1–5)', 'Rating of experience (1–5)')}><div className="flex gap-1">{[1, 2, 3, 4, 5].map(v => <button key={v} onClick={() => setExit({ ...exit, rating: v })} className={`size-8 rounded border num ${exit.rating === v ? 'bg-ink text-canvas border-ink' : 'border-line'}`}>{v}</button>)}</div></Field>
            <label className="flex items-center gap-2 text-[14px]"><input type="checkbox" className="accent-[var(--primary)]" checked={exit.wouldReturn} onChange={x => setExit({ ...exit, wouldReturn: x.target.checked })} />{L('يرغب بالعودة مستقبلاً', 'Would consider returning')}</label>
            <Field label={L('ملاحظات الموظف', 'Feedback')}><textarea className="field" value={exit.feedback} onChange={x => setExit({ ...exit, feedback: x.target.value })} /></Field>
            <Btn size="sm" onClick={() => { saveExit(r.id, exit); toast(L('حُفظت المقابلة', 'Saved')) }}>{L('حفظ', 'Save')}</Btn>
          </div>
        </Panel>
      </div>
      <Paper code="F-33" title={L('المخالصة النهائية وإنهاء الخدمة', 'Final settlement & end of service')} sub={L('حساب تقديري وفق بيانات النظام — يُراجع من الإدارة المالية', 'Estimate from system data — reviewed by Finance')}
        actions={r.status === 'clearance' ? <Btn size="sm" kind="danger" disabled={!allClear} onClick={() => { closeResignation(r.id, st.total); toast(L('أُنهيت الخدمة', 'Service ended')) }}>{allClear ? L('اعتماد إنهاء الخدمة', 'Close employment') : L('أكمل إبراء الذمة أولاً', 'Complete clearance first')}</Btn> : undefined}>
        <table className="w-full border border-line text-[14px]"><tbody>
          {[[L('الأجر اليومي', 'Daily wage'), money(st.daily, e.currency, lang)], [L(`راتب الشهر الأخير (${Number(r.lastDay.slice(8))} يوماً)`, `Last month pro-rata (${Number(r.lastDay.slice(8))} days)`), money(st.proRata, e.currency, lang)], [L(`بدل رصيد الإجازات (${st.remaining} يوماً)`, `Unused leave (${st.remaining} days)`), money(st.leavePay, e.currency, lang)], [L('اقتطاع عهد غير مسترجعة', 'Unreturned assets deduction'), st.deduction ? `− ${money(st.deduction, 'USD', lang)}` : '—']].map(([k, v]) => <tr key={k}><td className="border border-line px-3 py-2 bg-sub">{k}</td><td className="border border-line px-3 py-2 num">{v}</td></tr>)}
          <tr><td className="border border-ink px-3 py-2 font-bold">{L('صافي المستحقات', 'Net amount due')}</td><td className="border border-ink px-3 py-2 font-bold num">{money(st.total, e.currency, lang)}</td></tr>
        </tbody></table>
        <div className="grid sm:grid-cols-3 gap-3 mt-5"><SigBox label={L('الموظف', 'Employee')} name={e.name} /><SigBox label={L('الموارد البشرية', 'HR')} name="رشا الخطيب" at={r.closedAt} /><SigBox label={L('الإدارة المالية', 'Finance')} name="باسل الأتاسي" at={r.closedAt} /></div>
        {r.status === 'closed' && <div className="mt-4"><span className="stamp text-danger">{L('انتهت الخدمة', 'SERVICE ENDED')} — {fmtDate(r.lastDay, lang)}</span></div>}
      </Paper>
    </>}
  </div>
}
