import { useState } from 'react'
import { ArrowLeft, ArrowRight, Plus, Printer } from 'lucide-react'
import { useL, useLang, bi, fmtDate, fmtDateTime } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { useAcknowledge, useComplain, useDefend, useMyDiscipline, useTeamCase, type CaseRow, type ComplaintRow, type MyDiscipline as Data } from '../../lib/discipline'
import { Btn, Empty, Field, PageHead, Panel, SignaturePad, toast } from '../../ui/kit'
import { CaseTable, OpenCaseModal } from '../hr/discipline/Discipline'
import { CaseStatusBadge, ComplaintBadge, ComplaintDoc, WarningDoc } from './docs'

/**
 * بوابة الموظف (M17): «إنذاراتي» — الإبلاغ بالمخالفة وكتابة الأقوال، القرار والإقرار بالاستلام بالتوقيع، الإنذار F-01؛
 * «شكاواي» F-02 للموارد البشرية مباشرة (سرية)؛ والمدير يفتح حالة لأحد فريقه ويتابع حالاتهم.
 */
export function MyDiscipline() {
  const L = useL(); const lang = useLang()
  const q = useMyDiscipline(); const d = q.data
  const [doc, setDoc] = useState<{ kind: 'warning'; c: CaseRow } | { kind: 'complaint'; c: ComplaintRow } | null>(null)
  const [open, setOpen] = useState(false)
  const team = useTeamCase()
  if (!d) return <div className="max-w-[1000px] mx-auto"><Panel><Empty title={q.isError ? L('تعذّر التحميل', 'Could not load') : L('جارٍ التحميل…', 'Loading…')} /></Panel></div>
  if (doc) return <div className="max-w-[900px] mx-auto space-y-3">
    <button className="inline-flex items-center gap-1.5 text-[13.5px] text-ink-2 hover:text-ink" onClick={() => setDoc(null)} data-noprint>{lang === 'ar' ? <ArrowRight size={15} /> : <ArrowLeft size={15} />}{L('رجوع', 'Back')}</button>
    {doc.kind === 'warning' ? <WarningDoc c={{ ...doc.c, employee: doc.c.employee ? { ...doc.c.employee, dept: d.me.dept } : null }} company={d.company} /> : <ComplaintDoc c={doc.c} who={d.me} />}
  </div>
  return <div className="max-w-[1000px] mx-auto space-y-5">
    <PageHead title={L('إنذاراتي وشكاواي', 'My warnings & complaints')} sub={L('اكتب أقوالك قبل القرار، ووقّع بالاستلام بعده. الشكاوى تصل للموارد البشرية فقط.', 'Reply before the decision and sign the acknowledgement after it. Complaints go to HR only.')} />
    <Panel title={L('المخالفات بحقي', 'Cases about me')} pad={false}>
      {d.data.length === 0 ? <Empty title={L('لا مخالفات مسجلة بحقك', 'No cases about you')} /> : <ul className="divide-y divide-line-2">{d.data.map(c => <CaseItem key={c.id} c={c} onPrint={() => setDoc({ kind: 'warning', c })} />)}</ul>}
    </Panel>
    {d.team.length > 0 && <Panel title={L('مخالفات فريقي', 'My team’s cases')} action={<Btn size="sm" kind="primary" icon={<Plus size={15} />} onClick={() => setOpen(true)}>{L('فتح حالة', 'Open a case')}</Btn>} pad={false}>
      {d.team_cases.length === 0 ? <Empty title={L('لا حالات لفريقك', 'No cases for your team')}>{L('تُبلَّغ الموارد البشرية بكل حالة تفتحها وتتولى التحقيق والقرار.', 'HR is notified of each case you open and handles the investigation and decision.')}</Empty> : <CaseTable rows={d.team_cases} />}
    </Panel>}
    <Complaints d={d} onPrint={c => setDoc({ kind: 'complaint', c })} />
    {open && <OpenCaseModal d={{ types: d.types, groups: [{ key: 'time', ar: 'مواعيد العمل', en: 'Working hours' }, { key: 'work', ar: 'نظام العمل', en: 'Work rules' }, { key: 'conduct', ar: 'سلوك العامل', en: 'Conduct' }] }}
      employees={d.team} init={{}} onClose={() => setOpen(false)} onSubmit={v => team.mutateAsync(v)} />}
  </div>
}

function CaseItem({ c, onPrint }: { c: CaseRow; onPrint: () => void }) {
  const L = useL(); const lang = useLang()
  const defend = useDefend(); const ack = useAcknowledge()
  const [text, setText] = useState(c.defense ?? '')
  const [sig, setSig] = useState<string>()
  const live = c.status === 'open' || c.status === 'investigated'
  const err = (defend.error ?? ack.error) instanceof ApiError ? (defend.error ?? ack.error) as ApiError : null
  return <li className="p-4 space-y-2">
    <div className="flex flex-wrap items-center gap-2"><span className="ltr inline-block text-[12.5px] text-ink-3">{c.ref_no}</span><CaseStatusBadge s={c.status} daysLeft={c.days_left} /></div>
    <div className="text-[14px] font-medium">{c.type ? bi(c.type.title, lang) : ''}</div>
    <div className="text-[13px] text-ink-2">{L('بتاريخ', 'On')} <span className="num">{fmtDate(c.occurred_on, lang)}</span> · {L(`المرة ${c.occurrence}`, `occurrence ${c.occurrence}`)} — {c.description}</div>
    {live && <div className="space-y-2">
      <Field label={L('أقوالك ودفاعك', 'Your statement')} hint={L(`قبل ${fmtDate(c.deadline, lang)}`, `Before ${fmtDate(c.deadline, lang)}`)} error={err?.field('defense')}>
        <textarea className="field" rows={3} maxLength={4000} value={text} onChange={e => setText(e.target.value)} /></Field>
      <Btn size="sm" kind="primary" disabled={text.trim().length < 5 || defend.isPending} onClick={() => defend.mutate({ id: c.id, defense: text.trim() }, { onSuccess: () => toast(L('أُرسلت أقوالك للموارد البشرية', 'Your statement was sent to HR')) })}>
        {c.defense ? L('تحديث الأقوال', 'Update statement') : L('إرسال الأقوال', 'Send statement')}</Btn>
      {c.defended_at && <span className="ms-2 text-[12px] text-ink-3 num">{L('أُرسلت', 'Sent')} {fmtDateTime(c.defended_at, lang)}</span>}
    </div>}
    {c.status === 'decided' && <div className="rounded-lg border border-line p-3 space-y-2">
      <div className="text-[14px]">{L('القرار', 'Decision')}: <b>{c.penalty ? bi(c.penalty.label, lang) : ''}</b>
        {c.penalty?.kind === 'deduct' && <span className="text-[13px] text-ink-2"> — {L(`يُحسم ${c.deduct_days} يوم في كشف ${c.month}`, `${c.deduct_days} day(s) deducted in ${c.month}`)}</span>}</div>
      {c.reduce_reason && <div className="text-[13px] text-ink-2">{c.reduce_reason}</div>}
      {c.acknowledged_at ? <div className="text-[13px] text-brand">{L('أقررت بالاستلام', 'Acknowledged')} · <span className="num">{fmtDateTime(c.acknowledged_at, lang)}</span></div> : <div className="grid sm:grid-cols-[1fr_auto] gap-3 items-end">
        <SignaturePad value={sig} onChange={setSig} />
        <Btn kind="primary" disabled={!sig || ack.isPending} onClick={() => ack.mutate({ id: c.id, signature: sig! }, { onSuccess: () => toast(L('سُجّل إقرارك بالاستلام', 'Acknowledgement recorded')) })}>{L('أقرّ بالاستلام', 'I acknowledge receipt')}</Btn></div>}
      {c.penalty?.kind === 'warning' && <Btn size="sm" icon={<Printer size={14} />} onClick={onPrint}>{L('الإنذار الكتابي F-01', 'Written warning F-01')}</Btn>}
    </div>}
    {c.status === 'dismissed' && <p className="text-[13px] text-brand">{L('حُفظت الحالة بلا جزاء.', 'Closed without a penalty.')}</p>}
    {err && !Object.keys(err.errors).length && <p role="alert" className="text-[13px] text-danger">{err.message}</p>}
  </li>
}

function Complaints({ d, onPrint }: { d: Data; onPrint: (c: ComplaintRow) => void }) {
  const L = useL(); const lang = useLang()
  const send = useComplain()
  const [v, setV] = useState({ subject: '', against: '', body: '' })
  const [show, setShow] = useState(false)
  const err = send.error instanceof ApiError ? send.error : null
  return <Panel title={L('شكاواي (F-02)', 'My complaints (F-02)')} sub={L('سرية — لا يراها مديرك', 'Confidential — not visible to your manager')}
    action={!show && <Btn size="sm" className="whitespace-nowrap" icon={<Plus size={15} />} onClick={() => setShow(true)}>{L('شكوى جديدة', 'New complaint')}</Btn>} pad={false}>
    {show && <div className="p-4 border-b border-line-2 grid sm:grid-cols-2 gap-3">
      <Field label={L('الموضوع', 'Subject')} req error={err?.field('subject')}><input className="field" maxLength={190} value={v.subject} onChange={e => setV({ ...v, subject: e.target.value })} /></Field>
      <Field label={L('بحق (اختياري)', 'Against (optional)')} error={err?.field('against')}><input className="field" maxLength={190} value={v.against} onChange={e => setV({ ...v, against: e.target.value })} /></Field>
      <Field label={L('وصف الشكوى', 'Description')} req error={err?.field('body')} className="sm:col-span-2"><textarea className="field" rows={4} maxLength={4000} value={v.body} onChange={e => setV({ ...v, body: e.target.value })} /></Field>
      <div className="sm:col-span-2 flex gap-2"><Btn kind="primary" disabled={send.isPending || v.subject.trim().length < 3 || v.body.trim().length < 10}
        onClick={() => send.mutate(v, { onSuccess: () => { toast(L('وصلت شكواك للموارد البشرية', 'Your complaint reached HR')); setV({ subject: '', against: '', body: '' }); setShow(false) } })}>{L('إرسال', 'Send')}</Btn>
        <Btn onClick={() => setShow(false)}>{L('إلغاء', 'Cancel')}</Btn></div>
      {err && !Object.keys(err.errors).length && <p role="alert" className="sm:col-span-2 text-[13px] text-danger">{err.message}</p>}
    </div>}
    {d.complaints.length === 0 ? <Empty title={L('لا شكاوى', 'No complaints')} /> : <ul className="divide-y divide-line-2">{d.complaints.map(c => <li key={c.id} className="p-4 space-y-1">
      <div className="flex flex-wrap items-center gap-2"><b className="text-[14px]">{c.subject}</b><ComplaintBadge s={c.status} /><span className="text-[12px] text-ink-3 ltr inline-block">{c.ref_no}</span>
        <span className="text-[12px] text-ink-3 num">{fmtDate(c.created_at, lang)}</span></div>
      <p className="text-[13.5px] text-ink-2 whitespace-pre-wrap">{c.body}</p>
      {c.response && <div className="rounded-lg bg-sub px-3 py-2 text-[13px]"><b>{L('رد الموارد البشرية', 'HR reply')}:</b> {c.response}</div>}
      <Btn size="sm" kind="ghost" icon={<Printer size={14} />} onClick={() => onPrint(c)}>F-02</Btn>
    </li>)}</ul>}
  </Panel>
}
