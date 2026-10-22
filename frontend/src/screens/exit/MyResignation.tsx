import { useState } from 'react'
import { ArrowLeft, ArrowRight } from 'lucide-react'
import { useL, useLang, bi, fmtDate, todayISO, addDays } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { useRoute } from '../../lib/router'
import { useMyExit, useMyExitAction, useResign, type ExitFull, type MyExit } from '../../lib/exit'
import { Btn, Empty, Field, Modal, PageHead, Panel, toast } from '../../ui/kit'
import { Paper } from '../../ui/docs'
import { StepsPath } from '../requests/parts'
import { ClearancePanel, ExitBadge, ExitDoc, HandoverPanel, PrintLinks, SettlementTable, type PrintForm } from './parts'

/**
 * بوابة الموظف (M11، MyResign في الديمو بإجراء HR.01-03): نموذج طلب الاستقالة F-02 مع الأسباب والاستبيان والإخطار،
 * ثم متابعته (المسار، الرجوع، تسليم المهام F-05، تسليم العهدة F-07، المخالصة)، وإقرار استلام مهام زميل، وطلبات خروج الفريق للمدير.
 */
export function MyResignation({ base }: { base: string }) {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const q = useMyExit(); const d = q.data
  const parts = route.parts
  const printIdx = parts.indexOf('print')
  if (!d) return <div className="max-w-[1000px] mx-auto"><Panel><Empty title={q.isError ? L('تعذّر التحميل', 'Could not load') : L('جارٍ التحميل…', 'Loading…')} /></Panel></div>
  if (printIdx > 0) {
    const id = Number(parts[printIdx - 1]); const r = [d.data, ...d.receiving, ...d.team].find(x => x?.id === id)
    const Back = lang === 'ar' ? ArrowRight : ArrowLeft
    return <div className="max-w-[900px] mx-auto space-y-3"><a href={base} className="inline-flex items-center gap-1.5 text-[13.5px] text-ink-2" data-noprint><Back size={15} />{L('رجوع', 'Back')}</a>
      {r ? <ExitDoc r={r} form={parts[printIdx + 1] as PrintForm} company={d.company} lists={d.lists} /> : <Panel><Empty title={L('غير متاح', 'Not available')} /></Panel>}</div>
  }
  const mine = d.data && !['rejected', 'cancelled'].includes(d.data.status) ? d.data : null
  return <div className="max-w-[1000px] mx-auto space-y-5">
    {mine ? <Follow r={mine} d={d} base={base} /> : <ResignForm d={d} last={d.data} />}
    {d.receiving.length > 0 && <Panel title={L('مهام تستلمها من زملاء', 'Tasks you are receiving')}>{d.receiving.map(r => <div key={r.id} className="mb-4 last:mb-0">
      <div className="text-[14px] font-semibold mb-2">{r.employee ? bi(r.employee.name, lang) : ''} — {L('آخر يوم', 'last day')} {fmtDate(r.last_day, lang)}</div>
      <Receiver r={r} /></div>)}</Panel>}
    {d.team.length > 0 && <TeamExits d={d} base={base} />}
  </div>
}

function ResignForm({ d, last }: { d: MyExit; last: ExitFull | null }) {
  const L = useL(); const lang = useLang()
  const send = useResign()
  const [day, setDay] = useState(addDays(todayISO(), d.policy.notice_days))
  const [reasons, setReasons] = useState<string[]>([])
  const [survey, setSurvey] = useState<Record<string, string>>({})
  const [text, setText] = useState(L(`أتقدم إلى إدارة ${d.company.ar} بطلب قبول استقالتي من منصبي، مع التزامي بكامل بنود السرية المستمرة وميثاق السلوك المهني، وإكمال فترة الإخطار وتسليم المهام والعهدة.`,
    `I hereby submit my resignation to ${d.company.en}, committing to ongoing confidentiality and the code of conduct, completing the notice period and handing over my tasks and custody.`))
  const [confirm, setConfirm] = useState(false)
  const err = send.error instanceof ApiError ? send.error : null
  const short = day < addDays(todayISO(), d.policy.notice_days)
  const tog = (k: string) => setReasons(reasons.includes(k) ? reasons.filter(x => x !== k) : [...reasons, k])
  return <>
    <PageHead title={L('الاستقالة', 'Resignation')} sub={L('يمر الطلب على مديرك المباشر، ثم مقابلة الموارد البشرية، ثم قرار المدير العام.', 'Goes to your manager, then an HR interview, then the general manager’s decision.')} />
    {last && <p className="text-[13px] text-ink-3">{L('طلبك السابق', 'Your previous request')} {last.ref_no}: <ExitBadge s={last.status} /></p>}
    <Paper code="HR.01-03-F-02" title={L('طلب استقالة', 'Resignation request')}>
      <div className="space-y-4" data-noprint>
        <Field div label={L('أسباب الاستقالة', 'Reasons')} req error={err?.field('reasons')}><div className="flex flex-wrap gap-2">{d.lists.reasons.map(x => <button key={x.key} onClick={() => tog(x.key)}
          className={`h-9 px-3 rounded-lg border text-[13.5px] ${reasons.includes(x.key) ? 'bg-brand text-on-brand border-brand' : 'border-line'}`}>{lang === 'ar' ? x.ar : x.en}</button>)}</div></Field>
        <Field label={L('آخر يوم عمل (تاريخ النفاذ)', 'Last working day (effective date)')} req error={err?.field('last_day')}
          hint={L(`فترة الإخطار ${d.policy.notice_days} يوماً من تاريخ التقديم.`, `The notice period is ${d.policy.notice_days} days from submission.`)}>
          <input type="date" className="field !w-auto" min={todayISO()} value={day} onChange={e => setDay(e.target.value)} /></Field>
        {short && <p className="text-[13px] text-warn">{L(`التاريخ قبل نهاية فترة الإخطار — ما لم يعفك مديرك يُقتطع بدل إشعار يعادل أجر ${d.policy.notice_pay_months} شهر في المخالصة.`, `This is before the notice ends — unless your manager waives it, ${d.policy.notice_pay_months} months’ pay is deducted in the settlement.`)}</p>}
        <Field label={L('نص الطلب', 'Request text')} req error={err?.field('text')}><textarea className="field" rows={4} maxLength={4000} value={text} onChange={e => setText(e.target.value)} /></Field>
        <details className="rounded-lg border border-line p-3"><summary className="cursor-pointer text-[14px] font-semibold">{L('استبيان الاستقالة (اختياري — يساعدنا على التحسين)', 'Resignation survey (optional)')}</summary>
          <div className="mt-3 space-y-3">{d.lists.questions.map(x => <Field key={x.key} label={lang === 'ar' ? x.ar : x.en}><textarea className="field" rows={2} maxLength={1000} value={survey[x.key] ?? ''} onChange={e => setSurvey({ ...survey, [x.key]: e.target.value })} /></Field>)}</div></details>
        <Btn kind="danger" disabled={!reasons.length || text.trim().length < 10} onClick={() => setConfirm(true)}>{L('تقديم الاستقالة', 'Submit resignation')}</Btn>
      </div>
    </Paper>
    <Modal open={confirm} onClose={() => setConfirm(false)} title={L('تأكيد تقديم الاستقالة', 'Confirm resignation')} footer={<><Btn onClick={() => setConfirm(false)}>{L('تراجع', 'Go back')}</Btn>
      <Btn kind="danger" disabled={send.isPending} onClick={() => send.mutate({ last_day: day, reasons, text, survey }, { onSuccess: () => { toast(L('قُدّم طلب الاستقالة', 'Resignation submitted')); setConfirm(false) }, onError: () => setConfirm(false) })}>{L('تقديم', 'Submit')}</Btn></>}>
      <p className="text-[14px]">{L(`سيُرفع طلبك إلى مديرك المباشر، وآخر يوم عمل ${fmtDate(day, 'ar')}. يمكنك الرجوع عنه فوراً قبل أي قرار، وبعده بموافقة الموارد البشرية.`, `Your request goes to your manager; last day ${fmtDate(day, 'en')}. You can withdraw immediately before any decision, and afterwards with HR’s approval.`)}</p>
    </Modal>
  </>
}

function Follow({ r, d, base }: { r: ExitFull; d: MyExit; base: string }) {
  const L = useL(); const lang = useLang()
  const act = useMyExitAction()
  const [wd, setWd] = useState(false); const [wtext, setWtext] = useState('')
  const run = (path: string, body?: Record<string, unknown>, method?: 'POST' | 'PUT') => act.mutateAsync({ id: r.id, path, body, method })
  return <>
    <PageHead title={r.kind === 'termination' ? L('إنهاء الخدمة', 'End of service') : L('طلب الاستقالة', 'Resignation')} sub={<>{L('آخر يوم عمل', 'Last working day')}: {fmtDate(r.last_day, lang)} · <ExitBadge s={r.status} kind={r.kind} /></>} />
    <Panel title={r.ref_no ?? ''} sub={r.reasons.map(x => bi(x, lang)).join('، ')}>
      <p className="text-[14.5px] leading-7 bg-canvas rounded-md p-3 whitespace-pre-wrap">{r.text}</p>
      <div className="mt-3"><StepsPath steps={r.steps} /></div>
      {r.notice_waived && <p className="text-[13px] text-brand mt-2">{L('أُعفيت من فترة الإخطار', 'Your notice was waived')}{r.waive_note ? `: ${r.waive_note}` : ''}</p>}
      {r.status === 'clearance' && <p className="text-[13.5px] text-ink-2 mt-3">{L('يرجى إدخال جدول تسليم المهام وتوقيعه، وتسليم العهد إلى الشؤون الإدارية قبل آخر يوم عمل.', 'Please fill in and sign the handover list and return your custody before the last working day.')}</p>}
      {r.withdrawal?.status === 'pending' ? <p className="text-[13px] text-warn mt-2">{L('طلب الرجوع عن الاستقالة قيد الدراسة لدى الموارد البشرية.', 'Your withdrawal is under review by HR.')}</p>
        : r.withdrawal?.status === 'rejected' ? <p className="text-[13px] text-ink-3 mt-2">{L('لم يُقبل الرجوع', 'Withdrawal declined')}: {r.withdrawal.note}</p> : null}
      <div className="flex flex-wrap items-center gap-3 mt-3">
        {r.kind === 'resignation' && r.status !== 'closed' && r.withdrawal?.status !== 'pending' && <Btn onClick={() => setWd(true)}>{L('الرجوع عن الاستقالة', 'Withdraw resignation')}</Btn>}
        <PrintLinks r={r} href={f => `${base}/${r.id}/print/${f}`} />
      </div>
    </Panel>
    {(r.status === 'clearance' || r.status === 'closed') && <>
      <HandoverPanel r={r} canEdit={r.can.handover} colleagues={d.colleagues} onSave={v => run('handover', v as unknown as Record<string, unknown>, 'PUT')} sign={as => run('handover/sign', { as })} signAs={['employee']} />
      <ClearancePanel r={r} mark={() => Promise.resolve()} sign={() => Promise.resolve()} />
      {r.settlement && <Panel title={L('المخالصة النهائية (تقديرية)', 'Final settlement (estimate)')} sub="F-33"><SettlementTable s={r.settlement} /></Panel>}
    </>}
    <Modal open={wd} onClose={() => setWd(false)} title={L('الرجوع عن الاستقالة', 'Withdraw the resignation')} footer={<><Btn onClick={() => setWd(false)}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" disabled={wtext.trim().length < 10 || act.isPending} onClick={() => run('withdraw', { text: wtext }).then(() => { toast(L('أُرسل طلب الرجوع', 'Withdrawal sent')); setWd(false) }).catch(e => toast(e instanceof ApiError ? e.message : L('تعذّر', 'Failed')))}>{L('إرسال', 'Send')}</Btn></>}>
      <p className="text-[13px] text-ink-3 mb-2">{L('ناقش مديرك المباشر أولاً. قبل أي قرار يُلغى الطلب فوراً، وبعده يحتاج موافقة الموارد البشرية وتحديث العقد إن تغيّرت الشروط.', 'Talk to your manager first. Before any decision the request is cancelled at once; afterwards HR approves it and updates the contract if terms change.')}</p>
      <Field label={L('طلب خطي بالرجوع', 'Written withdrawal request')} req><textarea className="field" rows={3} maxLength={2000} value={wtext} onChange={e => setWtext(e.target.value)} /></Field>
    </Modal>
  </>
}

function Receiver({ r }: { r: ExitFull }) {
  const L = useL()
  const act = useMyExitAction()
  return <HandoverPanel r={r} canEdit={false} colleagues={[]} onSave={() => Promise.resolve()} sign={as => act.mutateAsync({ id: r.id, path: 'handover/sign', body: { as } }).then(() => toast(L('سُجّل إقرارك', 'Acknowledged')))} signAs={['receiver']} />
}

/** المدير: طلبات خروج فريقه — مصادقة F-05 وجهة «إدارة الموظف» في F-07 */
function TeamExits({ d, base }: { d: MyExit; base: string }) {
  const L = useL(); const lang = useLang()
  const act = useMyExitAction()
  return <Panel title={L('خروج من فريقي', 'Team exits')} sub={L('توصيتك من «اعتماداتي»، والمصادقة على تسليم المهام وجهتك في تسليم العهدة من هنا.', 'Your recommendation is in «My approvals»; approve the handover and your clearance section here.')}>
    {d.team.map(r => <div key={r.id} className="mb-5 last:mb-0 space-y-3">
      <div className="flex flex-wrap items-center gap-2 text-[14px]"><b>{r.employee ? bi(r.employee.name, lang) : ''}</b><ExitBadge s={r.status} kind={r.kind} /><span className="text-ink-3">{L('آخر يوم', 'last day')} {fmtDate(r.last_day, lang)}</span>
        <PrintLinks r={r} href={f => `${base}/${r.id}/print/${f}`} /></div>
      {r.status === 'clearance' && <>
        <HandoverPanel r={r} canEdit={false} colleagues={[]} onSave={() => Promise.resolve()} sign={as => act.mutateAsync({ id: r.id, path: 'handover/sign', body: { as } })} signAs={['manager']} />
        <ClearancePanel r={r} mark={(s, i, b) => act.mutateAsync({ id: r.id, path: `clearance/${s}/${i}`, body: b })} sign={s => act.mutateAsync({ id: r.id, path: `clearance/${s}/sign` })} />
      </>}
    </div>)}
  </Panel>
}
