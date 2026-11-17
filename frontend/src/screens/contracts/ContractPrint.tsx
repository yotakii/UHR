import { ArrowRight, Loader2 } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { fmtDate } from '../../lib/dates'
import { useRoute } from '../../lib/router'
import { DECISION, RECOMMEND, usePrintData } from '../../lib/contracts'
import { Paper } from '../../ui/docs'
import { Btn } from '../../ui/kit'

/** طباعة: العقد (CTR)، تقييم التجربة (PRB = HR-05-F-03) أو التجديد (RNE)، خطاب الإخطار (CRL)، ملحق العقد (ANX) */
export function ContractPrint({ me, kind, id }: { me: boolean; kind: string; id: number }) {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const { data } = usePrintData(me, kind, id, route.query.which)
  const back = <Btn icon={<ArrowRight size={16} className="ltr:rotate-180" />} onClick={() => history.back()}>{L('رجوع', 'Back')}</Btn>
  if (!data) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const sigBox = (title: string, name?: string | null, at?: string | null, img?: string | null) => <div className="border border-line rounded-lg p-2 min-h-[90px] text-[12px]">
    <div className="font-semibold">{title}</div>{img && <img src={img} alt="" className="h-12 object-contain" />}{name && <div>{name}</div>}{at && <div className="num text-ink-3">{fmtDate(at, lang)}</div>}</div>
  const roleName: Record<string, [string, string]> = { employee: ['الموظف (الطرف الثاني)', 'Employee (second party)'], hr_director: ['الموارد البشرية', 'Human Resources'], executive: ['المدير التنفيذي', 'Executive director'], ceo: ['الرئيس التنفيذي', 'CEO'] }

  if (kind === 'contract' && data.contract) {
    const c = data.contract
    return <div className="max-w-[860px] mx-auto"><div className="mb-2" data-noprint>{back}</div>
      <Paper code="CTR" title={L('عقد عمل', 'Employment contract')} sub={c.ref_no} subject={c.employee.code}>
        <pre className="whitespace-pre-wrap font-[inherit] text-[13.5px] leading-7">{data.body}</pre>
        <div className="mt-6 grid grid-cols-3 gap-3">{(data.signatures ?? []).map((s, i) => <div key={i}>{sigBox(L(...(roleName[s.role] ?? [s.role, s.role])), s.name, s.at, s.image)}</div>)}</div>
      </Paper></div>
  }
  if (kind === 'annex' && data.annex && data.contract) {
    return <div className="max-w-[860px] mx-auto"><div className="mb-2" data-noprint>{back}</div>
      <Paper code="ANX" title={L('ملحق عقد', 'Contract annex')} sub={`${data.annex.ref_no} · ${data.contract.ref_no}`}>
        <p className="text-[14px]">{bi(data.contract.employee, lang)} — {data.contract.employee.code}</p>
        <p className="text-[14px] mt-2">{L('تاريخ السريان', 'Effective date')}: <span className="num">{fmtDate(data.annex.effective_on, lang)}</span></p>
        <p className="text-[14px] mt-4 whitespace-pre-wrap leading-7">{data.annex.text}</p>
        <div className="mt-8 grid grid-cols-2 gap-3">{sigBox(L('الموظف', 'Employee'))}{sigBox(L('الموارد البشرية', 'Human Resources'))}</div>
      </Paper></div>
  }
  const c = data.case; if (!c) return null
  if (kind === 'letter') return <div className="max-w-[860px] mx-auto"><div className="mb-2" data-noprint>{back}</div>
    <Paper code="CRL" title={L('خطاب إخطار', 'Notification letter')} sub={c.ref_no} subject={c.employee.code}>
      <p className="text-[14px]">{L('السيد/ة', 'Dear')} {bi(c.employee, lang)} — {c.employee.code}</p>
      <p className="text-[14px] mt-4 leading-8">{data.text}</p>
      <div className="mt-10 grid grid-cols-2 gap-3">{sigBox(L('الموارد البشرية', 'Human Resources'))}{sigBox(L('استلام الموظف', 'Received by the employee'))}</div>
    </Paper></div>
  const ev = c.evaluation
  const scale = Object.fromEntries(c.form.scale.map(s => [s.key, s]))
  return <div className="max-w-[900px] mx-auto"><div className="mb-2" data-noprint>{back}</div>
    <Paper code={c.kind === 'probation' ? 'PRB' : 'RNE'} title={c.kind === 'probation' ? L('تقييم موظف / فترة تجريبية', 'Employee evaluation / probation') : L('تقييم تجديد العقد', 'Contract renewal evaluation')} sub={c.ref_no} subject={c.employee.code}>
      <table className="w-full text-[12.5px] border-collapse mb-3"><tbody>
        <tr><td className="border border-line px-2 py-1 w-1/4 bg-sub">{L('اسم الموظف', 'Employee')}</td><td className="border border-line px-2 py-1">{bi(c.employee, lang)}</td><td className="border border-line px-2 py-1 w-1/4 bg-sub">{L('الرقم الوظيفي', 'Employee no.')}</td><td className="border border-line px-2 py-1 ltr text-start">{c.employee.code}</td></tr>
        <tr><td className="border border-line px-2 py-1 bg-sub">{L('المنصب والقسم', 'Position & department')}</td><td className="border border-line px-2 py-1">{bi(c.employee.title, lang)}{c.employee.dept ? ` — ${bi(c.employee.dept, lang)}` : ''}</td>
          <td className="border border-line px-2 py-1 bg-sub">{c.kind === 'probation' ? L('نهاية فترة التجربة', 'Probation end') : L('نهاية العقد', 'Contract end')}</td><td className="border border-line px-2 py-1 num">{fmtDate(c.due_on, lang)}</td></tr>
        {ev && <tr><td className="border border-line px-2 py-1 bg-sub">{L('تاريخ التقييم', 'Evaluation date')}</td><td className="border border-line px-2 py-1 num">{fmtDate(ev.at, lang)}</td>
          <td className="border border-line px-2 py-1 bg-sub">{L('التقييم العام', 'Overall')}</td><td className="border border-line px-2 py-1">{c.kind === 'probation' ? <><span className="num">{ev.total}/100</span> — {ev.grade && scale[ev.grade] ? L(scale[ev.grade].ar, scale[ev.grade].en) : ''}</> : ev.rating && scale[ev.rating] ? L(scale[ev.rating].ar, scale[ev.rating].en) : ''}</td></tr>}
      </tbody></table>
      {c.kind === 'probation' && ev && <table className="w-full text-[12px] border-collapse"><thead><tr className="bg-sub"><th className="border border-line px-1 py-1 w-6">#</th><th className="border border-line px-2 py-1 text-start">{L('العنصر', 'Item')}</th>
        {c.form.scale.map(s => <th key={s.key} className="border border-line px-1 py-1">{L(s.ar, s.en)}<div className="num font-normal">{s.score}</div></th>)}<th className="border border-line px-2 py-1 text-start">{L('المبرر', 'Reason')}</th></tr></thead>
        <tbody>{(c.form.items ?? []).map((it, i) => <tr key={it.key}><td className="border border-line px-1 text-center num">{i + 1}</td><td className="border border-line px-2">{bi(it, lang)}</td>
          {c.form.scale.map(s => <td key={s.key} className="border border-line text-center">{ev.items?.[it.key] === s.key ? '✓' : ''}</td>)}<td className="border border-line px-2">{ev.justify?.[it.key] ?? ''}</td></tr>)}</tbody></table>}
      {ev && <div className="mt-3 text-[13px] space-y-1"><div>{L('التوصية', 'Recommendation')}: <b>{L(...(RECOMMEND[ev.recommendation] ?? [ev.recommendation, ev.recommendation]))}</b></div>{ev.notes && <div>{ev.notes}</div>}
        {c.decision && <div>{L('القرار', 'Decision')}: <b>{L(...DECISION[c.decision.decision])}</b>{c.decision.reason ? ` — ${c.decision.reason}` : ''}</div>}</div>}
      <div className="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-2">{sigBox(L('المدير المباشر', 'Direct manager'), c.evaluator ? bi(c.evaluator, lang) : null, ev?.at)}
        {c.signatures.map((s, i) => <div key={i}>{sigBox(s.role === 'employee' ? L('الموظف', 'Employee') : L('مدير القسم', 'Department head'), s.user, s.at)}</div>)}
        {sigBox(L('الموارد البشرية', 'Human Resources'), null, c.review?.at ?? null)}</div>
    </Paper></div>
}
