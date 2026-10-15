import { useState } from 'react'
import { ArrowRight, ArrowLeft, Check, Copy, Send } from 'lucide-react'
import { useStore, useL, useLang, bi, fmtDate, fmtDateTime } from '../lib/store'
import { toggleCheck, sendMemo, assignAsset } from '../lib/actions'
import { useNav } from '../lib/nav'
import { Btn, Panel, Badge, Avatar, PageHead, Tabs, Progress, Empty, toast } from '../ui/kit'
import { Paper, SigBox, Timeline } from '../ui/docs'
import { F19 } from '../lib/rules'

export function OnboardingList() {
  const L = useL(); const lang = useLang(); const { go } = useNav()
  const pending = useStore(s => s.candidates.filter(c => c.appointment && c.stage !== 'hired'))
  const emps = useStore(s => s.employees.filter(e => e.onboarding && e.status !== 'terminated'))
  const jobs = useStore(s => s.jobs)
  return <div className="max-w-[1100px] mx-auto">
    <PageHead title={L('التعيين والمباشرة', 'Appointment & onboarding')} sub={L('قرارات التعيين قيد الاعتماد، والموظفون الجدد في مرحلة المباشرة والتجربة.', 'Appointments under approval and new joiners in onboarding or probation.')} />
    <Panel title={L('قرارات تعيين بانتظار الاعتماد', 'Appointments awaiting approval')} sub="F-31" pad={false} className="mb-5">
      {pending.length === 0 ? <Empty title={L('لا توجد قرارات معلقة', 'No pending decisions')}>{L('تظهر هنا بعد أن يوقّع المتقدم عرض العمل.', 'They appear here after an applicant signs the offer.')}</Empty> :
        <table className="tbl"><thead><tr><th>{L('المتقدم', 'Applicant')}</th><th>{L('الوظيفة', 'Position')}</th><th>{L('رقم القرار', 'Decision')}</th><th>{L('الاعتمادات', 'Approvals')}</th><th /></tr></thead><tbody>
          {pending.map(c => { const done = c.appointment!.approvals.filter(a => a.status === 'approved').length
            return <tr key={c.id} className="click" onClick={() => go('candidate', c.id + ':decision')}><td className="font-medium">{lang === 'ar' ? c.name : (c.nameEn || c.name)}</td><td>{bi(jobs.find(j => j.id === c.jobId)!.title, lang)}</td><td className="ltr num">{c.appointment!.decisionNo}</td>
              <td><div className="flex items-center gap-2 w-40"><Progress value={done / 4 * 100} tone="brass" /><span className="num text-[12px]">{done}/4</span></div></td><td><Badge tone="brass">{L('بانتظار', 'Pending')}: {c.appointment!.approvals.find(a => a.status === 'pending')?.role[lang]}</Badge></td></tr> })}
        </tbody></table>}
    </Panel>
    <Panel title={L('الموظفون الجدد', 'New joiners')} sub="F-19 · F-32 · F-22 · F-20" pad={false}>
      {emps.length === 0 ? <Empty title={L('لا يوجد موظفون جدد حالياً', 'No new joiners')} /> :
        <table className="tbl"><thead><tr><th>{L('الموظف', 'Employee')}</th><th>{L('الرقم الوظيفي', 'ID')}</th><th>{L('المباشرة', 'Start')}</th><th>{L('إجراءات F-19', 'F-19 steps')}</th><th>{L('الحالة', 'Status')}</th></tr></thead><tbody>
          {emps.map(e => { const n = e.onboarding!.checklist.filter(Boolean).length
            return <tr key={e.id} className="click" onClick={() => go('onboard', e.id)}><td><div className="flex items-center gap-2"><Avatar name={e.name} size={28} tone={e.visitor ? 'brass' : 'brand'} /><span className="font-medium">{e.name}</span></div></td><td className="ltr num">{e.code}</td><td>{fmtDate(e.hireDate, lang)}</td>
              <td><div className="flex items-center gap-2 w-44"><Progress value={n / 28 * 100} /><span className="num text-[12px]">{n}/28</span></div></td><td>{e.status === 'onboarding' ? <Badge tone="info">{L('قيد المباشرة', 'Onboarding')}</Badge> : <Badge tone="brand">{L('فترة التجربة', 'Probation')}</Badge>}</td></tr> })}
        </tbody></table>}
    </Panel>
  </div>
}

export function OnboardPage({ id }: { id: string }) {
  const L = useL(); const lang = useLang(); const { go } = useNav()
  const e = useStore(s => s.employees.find(x => x.id === id))
  const dept = useStore(s => s.depts.find(d => d.id === e?.dept))
  const mgr = useStore(s => s.employees.find(x => x.id === e?.managerId))
  const cand = useStore(s => s.candidates.find(c => c.id === e?.candidateId))
  const assets = useStore(s => s.assets)
  const [tab, setTab] = useState('f19')
  const [pick, setPick] = useState('')
  if (!e || !e.onboarding) return null
  const ob = e.onboarding; const n = ob.checklist.filter(Boolean).length
  const mine = assets.filter(a => a.empId === e.id); const free = assets.filter(a => a.status === 'available')
  const Back = lang === 'ar' ? ArrowRight : ArrowLeft
  const memo = L(`الزملاء الكرام،
يسرّ إدارة الموارد البشرية في شركة الفرادة أن ترحّب بانضمام ${e.name} إلى فريق ${dept?.ar} بوظيفة ${e.title.ar}، اعتباراً من ${fmtDate(e.hireDate, 'ar')}.
${cand?.education[0] ? `${e.gender === 'F' ? 'تحمل' : 'يحمل'} ${cand.education[0].phase} في ${cand.education[0].major} من ${cand.education[0].institute}` : ''}${cand?.experience[0] ? `، مع خبرة سابقة لدى ${cand.experience[0].company}` : ''}.
مقر العمل: ${e.city.ar}. المدير المباشر: ${mgr?.name ?? '—'}.
نتمنى ${e.gender === 'F' ? 'لها' : 'له'} بداية موفقة، ونرجو من الجميع تسهيل اندماج${e.gender === 'F' ? 'ها' : 'ه'} في العمل.

إدارة الموارد البشرية`, `Dear colleagues,
HR is pleased to welcome ${e.nameEn} to the ${dept?.en} team as ${e.title.en}, starting ${fmtDate(e.hireDate, 'en')}.
Work location: ${e.city.en}. Direct manager: ${mgr?.nameEn ?? '—'}.
Please help make their first weeks smooth.

Human Resources`)
  return <div className="max-w-[1100px] mx-auto">
    <button onClick={() => go('onboarding')} className="text-[13px] text-ink-3 hover:text-ink flex items-center gap-1 mb-2" data-noprint><Back size={14} />{L('التعيين والمباشرة', 'Onboarding')}</button>
    <div className="bg-surface border border-line rounded-2xl p-5 mb-4 flex flex-wrap items-center gap-4" data-noprint>
      <Avatar name={e.name} size={48} tone={e.visitor ? 'brass' : 'brand'} />
      <div className="flex-1"><h1 className="text-[20px] font-bold">{e.name}</h1><div className="text-ink-2 text-[14px]">{bi(e.title, lang)} — {dept?.[lang]} — <span className="ltr">{e.code}</span></div></div>
      <div className="w-56"><div className="flex justify-between text-[12px] mb-1"><span className="text-ink-3">{L('إجراءات المباشرة', 'Onboarding')}</span><span className="num font-semibold">{n}/28</span></div><Progress value={n / 28 * 100} /></div>
      <Btn onClick={() => go('employee', e.id)}>{L('الملف الوظيفي', 'Employee file')}</Btn>
    </div>
    <Tabs value={tab} onChange={setTab} tabs={[{ v: 'f19', label: L('قائمة إجراءات المباشرة', 'Onboarding checklist') }, { v: 'f32', label: L('مذكرة الترحيب', 'Welcome memo') }, { v: 'custody', label: L('تسليم العهدة', 'Custody'), count: mine.length }, { v: 'pledges', label: L('التعهدات', 'Undertakings') }, { v: 'log', label: L('السجل', 'History') }]} />
    <div className="mt-4">
      {tab === 'f19' && <Paper code="F-19" title={L('قائمة تحقق إجراءات الموظف الجديد', 'New employee onboarding checklist')} sub={L('بعد إنجاز 14 خطوة ينتقل الموظف تلقائياً إلى فترة التجربة.', 'After 14 steps the employee moves to probation automatically.')}>
        <ol className="grid md:grid-cols-2 gap-x-6">{F19.map((s, i) => <li key={i}><button onClick={() => toggleCheck(e.id, i)} className="w-full flex items-start gap-3 py-2 border-b border-line-2 text-start hover:bg-sub">
          <span className={`mt-0.5 size-5 rounded border grid place-items-center shrink-0 ${ob.checklist[i] ? 'bg-brand border-brand text-on-brand' : 'border-ink-3'}`}>{ob.checklist[i] && <Check size={13} />}</span>
          <span className="text-[12px] text-ink-3 num w-5 shrink-0 mt-0.5">{i + 1}</span><span className={`text-[14px] ${ob.checklist[i] ? 'text-ink-3 line-through' : ''}`}>{s[lang]}</span></button></li>)}</ol>
      </Paper>}
      {tab === 'f32' && <Paper code="F-32" title={L('مذكرة الترحيب بالموظف الجديد', 'Welcome memo')} actions={<><Btn size="sm" icon={<Copy size={15} />} onClick={() => { navigator.clipboard?.writeText(memo); toast(L('نُسخ النص', 'Copied')) }}>{L('نسخ النص', 'Copy text')}</Btn><Btn size="sm" kind="primary" icon={<Send size={15} />} onClick={() => { sendMemo(e.id); toast(L('عُمّمت المذكرة على الأقسام', 'Memo circulated')) }}>{ob.memoSent ? L('إعادة الإرسال', 'Resend') : L('تعميم على الأقسام', 'Circulate')}</Btn></>}>
        {ob.memoSent && <div className="mb-3"><Badge tone="brand">{L('عُمّمت', 'Circulated')} {fmtDateTime(ob.memoSent, lang)}</Badge></div>}
        <div className="text-[13px] text-ink-3 mb-1">{L('إلى: جميع الأقسام — نسخة إلى', 'To: all departments — cc')} {mgr?.name}</div>
        <pre className="whitespace-pre-wrap font-sans text-[15px] leading-8 border-t border-line-2 pt-3">{memo}</pre>
      </Paper>}
      {tab === 'custody' && <Panel title={L('العهد المسلّمة للموظف', 'Assets issued')} action={<div className="flex gap-2"><select className="field !h-8 !w-64" value={pick} onChange={x => setPick(x.target.value)}><option value="">{L('اختر من المستودع…', 'Choose from stock…')}</option>{free.map(a => <option key={a.id} value={a.id}>{a.name[lang]} — {a.tag}</option>)}</select><Btn size="sm" kind="primary" disabled={!pick} onClick={() => { assignAsset(pick, e.id); setPick(''); toast(L('سُلّمت العهدة — بانتظار توقيع الموظف', 'Issued — awaiting employee signature')) }}>{L('تسليم', 'Issue')}</Btn></div>} pad={false}>
        {mine.length === 0 ? <Empty title={L('لم تُسلّم عهد بعد', 'No assets issued yet')} /> : <table className="tbl"><thead><tr><th>{L('الأصل', 'Asset')}</th><th>{L('الرمز', 'Tag')}</th><th>{L('الرقم التسلسلي', 'Serial')}</th><th>{L('الحالة', 'Status')}</th></tr></thead><tbody>
          {mine.map(a => <tr key={a.id}><td>{a.name[lang]}</td><td className="ltr num">{a.tag}</td><td className="ltr num text-ink-3">{a.serial}</td><td>{a.status === 'assigned' ? <Badge tone="brand">{L('موقّع الاستلام', 'Signed')}</Badge> : <Badge tone="brass">{L('بانتظار توقيع الموظف', 'Awaiting signature')}</Badge>}</td></tr>)}
        </tbody></table>}
      </Panel>}
      {tab === 'pledges' && <div className="grid md:grid-cols-2 gap-4">
        <Panel title={L('تعهد المحافظة على سرية العمل', 'Confidentiality undertaking')} sub="F-22"><SigBox label={L('توقيع الموظف', 'Employee signature')} name={e.name} sig={ob.f22?.sig} at={ob.f22?.at} /></Panel>
        <Panel title={L('إقرار إبراء الذمة الوقائي', 'Precautionary clearance')} sub="F-20"><SigBox label={L('توقيع الموظف', 'Employee signature')} name={e.name} sig={ob.f20?.sig} at={ob.f20?.at} /></Panel>
        <p className="text-[13px] text-ink-3 md:col-span-2">{L('يوقّع الموظف التعهدات من حسابه في قسم «وثائق للتوقيع».', 'The employee signs these from "Documents to sign" in their account.')}</p>
      </div>}
      {tab === 'log' && <Panel><Timeline items={e.log} /></Panel>}
    </div>
  </div>
}
