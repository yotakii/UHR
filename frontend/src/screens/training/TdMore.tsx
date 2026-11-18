import { useRef, useState, type DragEvent } from 'react'
import { AlertTriangle, ArrowRight, CalendarClock, CalendarDays, Copy, Download, FileSpreadsheet, LayoutGrid, List, Plus, Printer, RotateCcw, Share2, Sparkles, Upload, X } from 'lucide-react'
import { api } from '../../lib/api'
import { useL, useLang, bi, fmtDate, money } from '../../lib/store'
import { navigate, setQuery, useRoute } from '../../lib/router'
import { download } from '../../lib/admin'
import { queryClient } from '../../lib/session'
import { AUDIENCE, DELIVERY, PATH, S_STATUS, useCalendar, useCalendarLink, useHistory, useRooms, useTdAct, useTdPrint, useTdReport,
  type CostRow, type ImpactCard, type Lookups, type Path, type PrintBlock, type ScoreRow, type SessionFull } from '../../lib/training'
import { Badge, Btn, Empty, Field, Modal, Panel, Seg, toast } from '../../ui/kit'
import { DecisionDialog } from '../../ui/decision'
import { Paper } from '../../ui/docs'
import { QR } from '../../ui/vault'
import { StepsPath } from '../requests/parts'
import { Spin, Stat, StatusBadge, TdFilePick, errOf, fail } from './parts'

// الدفعة 27 — إكمال واجهات التدريب: الطباعة، العروض واعتمادها، التأجيل وإعادة الفتح، القاعات، السجل السابق، الاستيراد، التقارير، التقويم

// ———————————————— الطباعة ————————————————

export const FORM_TITLES: Record<string, [string, string]> = {
  TD1: ['برنامج تدريب موظف جديد', 'New employee training programme'], TD2: ['تحديد الاحتياجات التدريبية', 'Training needs identification'], TD3: ['تعهد الالتزام بالتدريب والعمل', 'Training & service commitment'],
  TD4: ['الخطة السنوية للتدريب والتطوير', 'Annual training & development plan'], TD5: ['قياس أثر التدريب', 'Training impact assessment'], TD6: ['سجل تدريب موظف', 'Employee training record'],
  TD7: ['استبيان تقييم دورة تدريبية', 'Course evaluation survey'], TD8: ['حضور دورة تدريبية', 'Course attendance sheet'], TD9: ['اتفاقية تدريب عملي', 'Internship agreement'],
  TD10: ['ساعات التدريب العملي وتقييمه', 'Internship hours & evaluation'], TD11: ['خطة التطوير الفردية', 'Individual development plan'], TD12: ['خطة تطبيق التعلّم 30-60-90', 'Learning application plan 30-60-90'],
  TD13: ['تقييم مدرب / مركز تدريب', 'Trainer / centre rating'], TD14: ['مقارنة عروض التدريب الخارجي', 'External training quote comparison'], TDC: ['شهادة تدريب', 'Training certificate'], CBP: ['الإطار المنطقي لخطة بناء القدرات', 'Capacity-building logical framework'],
}

export function PrintBtn({ code, id, me = false, label, size = 'sm' }: { code: string; id: number; me?: boolean; label?: string; size?: 'sm' }) {
  const L = useL()
  return <Btn size={size} kind="ghost" icon={<Printer size={14} />} onClick={() => navigate(`${me ? (location.pathname.startsWith('/hr') ? '/hr/my-training' : '/me/training') : '/hr/training'}/print/${code}/${id}`)}>{label ?? L('طباعة', 'Print')}</Btn>
}

export function TdPrintPage({ code, id, me = false }: { code: string; id: number; me?: boolean }) {
  const L = useL(); const lang = useLang()
  const { data: d, error } = useTdPrint(code, id, me)
  const t = (p?: [string, string]) => p ? (lang === 'ar' ? p[0] : p[1]) : ''
  if (error) return <Panel><Empty title={L('لا يمكن عرض هذا النموذج', 'This form cannot be shown')}>{(error as Error).message}</Empty></Panel>
  if (!d) return <Spin />
  const title = d.certificate ? (lang === 'ar' ? d.title_ar : d.title_en) ?? '' : t(FORM_TITLES[code])
  const block = (b: PrintBlock, i: number) => {
    if (b.type === 'kv') return <section key={i} className="mb-4">{b.title && <h3 className="font-semibold text-[14px] mb-1.5">{t(b.title)}</h3>}
      <div className="grid gap-x-6 gap-y-1 sm:grid-cols-2 text-[13px]">{b.rows.map(([k, v], j) => <div key={j} className="flex gap-2 border-b border-line-2 py-1"><span className="text-ink-3 shrink-0">{t(k)}:</span><span className="font-medium">{v}</span></div>)}</div></section>
    if (b.type === 'table') return <section key={i} className="mb-4">{b.title && <h3 className="font-semibold text-[14px] mb-1.5">{t(b.title)}</h3>}
      {b.rows.length === 0 ? <div className="text-[12.5px] text-ink-3">—</div> : <table className="w-full text-[12px] border-collapse"><thead><tr>{b.head.map((h, j) => <th key={j} className="border border-line px-1.5 py-1 text-start bg-sub">{t(h)}</th>)}</tr></thead>
        <tbody>{b.rows.map((r, j) => <tr key={j}>{r.map((c, k) => <td key={k} className="border border-line px-1.5 py-1 align-top whitespace-pre-line">{c}</td>)}</tr>)}</tbody></table>}</section>
    if (b.type === 'text') return <section key={i} className="mb-4">{b.title && <h3 className="font-semibold text-[14px] mb-1.5">{t(b.title)}</h3>}
      <p className={`text-[13.5px] leading-7 whitespace-pre-line ${d.certificate ? 'text-center text-[15px]' : ''} ${b.ltr ? 'ltr' : ''}`} dir={b.ltr ? 'ltr' : undefined}>{b.text}</p></section>
    if (b.type === 'qr') return <section key={i} className="mb-4 flex items-center gap-3 break-inside-avoid"><QR text={b.text} size={96} /><div className="text-[12px]"><div className="font-semibold">{t(b.title)}</div><div className="ltr text-ink-3 break-all">{b.text}</div></div></section>
    return <section key={i} className="mt-6 grid gap-4 sm:grid-cols-3 break-inside-avoid">{b.items.map(([k, name, img], j) => <div key={j} className="text-center text-[12.5px]">
      <div className="h-16 border-b border-ink-3 flex items-end justify-center">{img ? <img src={img} alt="" className="max-h-14" /> : name ?? ''}</div><div className="mt-1 text-ink-3">{t(k)}</div></div>)}</section>
  }
  return <div className="max-w-[980px] mx-auto">
    <div className="mb-3 flex flex-wrap gap-2" data-noprint><Btn kind="ghost" icon={<ArrowRight size={15} className="rtl:rotate-0 ltr:rotate-180" />} onClick={() => history.back()}>{L('رجوع', 'Back')}</Btn>
      {d.linkedin && <a href={d.linkedin} target="_blank" rel="noreferrer"><Btn icon={<Share2 size={15} />}>{L('إضافة إلى LinkedIn', 'Add to LinkedIn')}</Btn></a>}</div>
    {d.landscape && <style>{'@media print { @page { size: A4 landscape } }'}</style>}
    <Paper code={code === 'CBP' ? 'TD4' : code} title={title}>{d.blocks.map(block)}</Paper>
  </div>
}

// ———————————————— عروض الأسعار (F-14 / BR-05) ————————————————

/** حمولة حفظ التنفيذ الكاملة (التعديل يتطلب كل الحقول) */
export const sessionPayload = (s: SessionFull, o: Partial<Record<string, unknown>> = {}) => ({ program_id: s.program?.id, title: s.title, meetings: s.meetings, trainer_id: s.trainer?.id ?? null, center_id: s.center_id, room_id: s.room_id,
  location_id: s.location_id, place: s.place, link: s.link, capacity: s.capacity, currency: s.currency, costs: s.costs, quotes: s.quotes, quote_choice: s.quote_choice, quote_reason: s.quote_reason, ...o })

export function QuotesPanel({ s }: { s: SessionFull }) {
  const L = useL(); const lang = useLang()
  const act = useTdAct()
  const [scores, setScores] = useState(() => s.quotes.map(q => ({ ...(q.scores ?? {}) })))
  const [choice, setChoice] = useState<number | null>(s.quote_choice)
  const [reason, setReason] = useState(s.quote_reason ?? '')
  const crit = s.quote_criteria.filter(c => c.key !== 'price')
  const rank = Object.fromEntries(s.quote_rank.map(r => [r.index, r]))
  const best = s.quote_rank[0]?.index
  if (!s.quotes.length) return <Empty title={L('لا توجد عروض بعد', 'No quotes yet')}>{L('أضف عروض الجهات من «تعديل»، ثم قيّمها هنا بالمعايير الموزونة واختر واحداً للاعتماد.', 'Add provider quotes from “Edit”, then score them here with the weighted criteria and choose one for approval.')}</Empty>
  const save = () => act.mutate({ path: `sessions/${s.id}`, method: 'PUT', v: sessionPayload(s, { quotes: s.quotes.map((q, i) => ({ ...q, scores: scores[i] })) }) }, { onSuccess: () => toast(L('حُفظ التقييم وأعيد الترتيب', 'Scores saved and ranked')), onError: fail })
  const ST: Record<string, [string, string, 'brass' | 'brand' | 'danger']> = { pending: ['بانتظار الاعتماد', 'Awaiting approval', 'brass'], approved: ['معتمد', 'Approved', 'brand'], rejected: ['مرفوض — اختر مجدداً', 'Rejected — choose again', 'danger'] }
  return <div className="space-y-3">
    <div className="flex flex-wrap items-center gap-2 text-[12.5px] text-ink-3">{L('الأوزان', 'Weights')}: {s.quote_criteria.map(c => <span key={c.key} className="rounded-md bg-sub px-1.5 py-0.5">{bi(c, lang)} {c.weight}%</span>)}
      {s.quote_status && <Badge tone={ST[s.quote_status][2]}>{L(ST[s.quote_status][0], ST[s.quote_status][1])}</Badge>}<span className="flex-1" /><PrintBtn code="TD14" id={s.id} label={L('طباعة F-14', 'Print F-14')} /></div>
    <div className="space-y-2">{s.quotes.map((q, i) => <div key={i} className={`rounded-xl border p-3 ${choice === i ? 'border-brand' : 'border-line'}`}>
      <div className="flex flex-wrap items-center gap-2"><input type="radio" name="qpick" checked={choice === i} onChange={() => setChoice(i)} aria-label={L('اختيار', 'Choose')} />
        <b>{q.center || '—'}</b><span className="num text-ink-3">{money(q.price, q.currency ?? s.currency, lang)}</span>{best === i && <Badge tone="brand">{L('الأعلى تقييماً', 'Top rated')}</Badge>}
        <span className="flex-1" /><span className="text-[13px]">{L('الدرجة', 'Score')} <b className="num">{rank[i]?.score ?? '—'}</b>/100</span></div>
      <div className="grid gap-2 sm:grid-cols-3 mt-2">{crit.map(c => <label key={c.key} className="text-[12px] text-ink-3">{bi(c, lang)}
        <select className="field mt-0.5" value={scores[i]?.[c.key] ?? ''} onChange={e => setScores(scores.map((x, k) => k === i ? { ...x, [c.key]: Number(e.target.value) } : x))}>
          <option value="">—</option>{[1, 2, 3, 4, 5].map(n => <option key={n} value={n}>{n}</option>)}</select></label>)}</div>
    </div>)}</div>
    <div className="flex flex-wrap gap-2"><Btn size="sm" disabled={act.isPending} onClick={save}>{L('حفظ التقييم', 'Save scores')}</Btn></div>
    <Field label={L('مبرر الاختيار (إلزامي إن لم يكن الأعلى تقييماً أو قلّت العروض عن الحد)', 'Justification (required when not the top-rated or too few quotes)')}><textarea className="field" rows={2} value={reason} onChange={e => setReason(e.target.value)} /></Field>
    <Btn kind="primary" disabled={choice == null || act.isPending || s.quote_status === 'pending'} onClick={() => act.mutate({ path: `sessions/${s.id}/quote`, v: { choice, reason: reason || null } }, { onSuccess: () => toast(L('أُرسل الاختيار للاعتماد — لا يُؤكَّد التسجيل قبله', 'Sent for approval — places cannot be confirmed before it')), onError: fail })}>{L('إرسال الاختيار للاعتماد', 'Send the choice for approval')}</Btn>
  </div>
}

// ———————————————— التأجيل وإعادة الفتح والخلاصة ————————————————

export function SessionMore({ s }: { s: SessionFull }) {
  const L = useL()
  const act = useTdAct()
  const [pp, setPp] = useState(false)
  const [ro, setRo] = useState(false)
  const live = !['completed', 'closed', 'cancelled', 'evaluated'].includes(s.status)
  return <>
    {live && <Btn size="sm" icon={<CalendarClock size={14} />} onClick={() => setPp(true)}>{L('تأجيل', 'Postpone')}</Btn>}
    {['closed', 'evaluated'].includes(s.status) && <Btn size="sm" icon={<RotateCcw size={14} />} onClick={() => setRo(true)}>{L('إعادة فتح', 'Reopen')}</Btn>}
    <PrintBtn code="TD8" id={s.id} label={L('كشف الحضور', 'Attendance sheet')} />
    {pp && <Postpone s={s} onClose={() => setPp(false)} />}
    <DecisionDialog open={ro} onClose={() => setRo(false)} reasonRequired minLength={5} title={L('إعادة فتح التنفيذ', 'Reopen the run')} subject={s.title} confirmLabel={L('إعادة الفتح', 'Reopen')}
      onConfirm={r => act.mutateAsync({ path: `sessions/${s.id}/reopen`, v: { reason: r } })} />
  </>
}

function Postpone({ s, onClose }: { s: SessionFull; onClose: () => void }) {
  const L = useL()
  const act = useTdAct()
  const [reason, setReason] = useState('')
  const [withDates, setWD] = useState(true)
  const [meetings, setM] = useState(s.meetings.map(m => ({ ...m, date: '' })))
  const [confirm, setConfirm] = useState(false)
  const ok = reason.trim().length >= 5 && (!withDates || meetings.every(m => m.date))
  return <Modal open onClose={onClose} title={L('تأجيل التنفيذ', 'Postpone the run')} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    {!confirm ? <Btn kind="primary" disabled={!ok} onClick={() => setConfirm(true)}>{L('متابعة', 'Continue')}</Btn>
      : <Btn kind="danger" disabled={act.isPending} onClick={() => act.mutate({ path: `sessions/${s.id}/postpone`, v: { reason, meetings: withDates ? meetings : null } }, { onSuccess: () => { toast(L('أُجّل التنفيذ وأُبلغ المتدربون', 'Postponed; trainees were notified')); onClose() }, onError: fail })}>{L('تأكيد التأجيل', 'Confirm')}</Btn>}</>}>
    <Field label={L('السبب', 'Reason')} req><textarea className="field" rows={2} value={reason} onChange={e => { setReason(e.target.value); setConfirm(false) }} /></Field>
    <label className="flex items-center gap-2 text-[13px] mt-3"><input type="checkbox" checked={withDates} onChange={e => { setWD(e.target.checked); setConfirm(false) }} />{L('تحديد المواعيد الجديدة الآن', 'Set the new dates now')}</label>
    {withDates && <div className="space-y-2 mt-2">{meetings.map((m, i) => <div key={i} className="grid grid-cols-[1fr_90px_90px] gap-2">
      <input type="date" className="field" value={m.date} aria-label={L('التاريخ', 'Date')} onChange={x => setM(meetings.map((y, k) => k === i ? { ...y, date: x.target.value } : y))} />
      <input type="time" className="field" value={m.from ?? ''} aria-label={L('من', 'From')} onChange={x => setM(meetings.map((y, k) => k === i ? { ...y, from: x.target.value } : y))} />
      <input type="time" className="field" value={m.to ?? ''} aria-label={L('إلى', 'To')} onChange={x => setM(meetings.map((y, k) => k === i ? { ...y, to: x.target.value } : y))} /></div>)}</div>}
    {!withDates && <div className="text-[12.5px] text-ink-3 mt-2">{L('يصبح التنفيذ «مؤجلاً» وتُزال أيام التدريب من الحضور حتى تحديد موعد جديد من «تعديل».', 'The run becomes “postponed” and training days are removed from attendance until new dates are set from “Edit”.')}</div>}
    {confirm && <div className="mt-3 rounded-xl bg-sub p-2.5 text-[12.5px]"><AlertTriangle size={14} className="inline me-1" />{L('سيُبلَّغ كل المرشحين والمؤكدين بالتأجيل. اضغط «تأكيد التأجيل».', 'Every nominated and confirmed trainee will be notified. Press “Confirm”.')}</div>}
  </Modal>
}

export function SurveySummary({ s }: { s: SessionFull }) {
  const L = useL()
  const act = useTdAct()
  const x = s.ai_summary
  return <Panel title={L('خلاصة آراء المتدربين', 'Summary of trainee comments')} action={<Btn size="sm" kind="ghost" icon={<Sparkles size={14} />} disabled={act.isPending} onClick={() => act.mutate({ path: `sessions/${s.id}/summary` }, { onError: fail })}>{x ? L('تحديث', 'Refresh') : L('تلخيص', 'Summarise')}</Btn>}>
    {!x ? <div className="text-[12.5px] text-ink-3">{L('تُلخَّص التعليقات المكتوبة في الاستبيان إلى نقاط قوة ونقاط تحسين واقتراحات، دون أسماء.', 'Written survey comments are summarised into strengths, improvements and suggestions, without names.')}</div>
      : <div className="grid gap-3 sm:grid-cols-3 text-[13px]">{([['strengths', 'نقاط القوة', 'Strengths'], ['improve', 'نقاط التحسين', 'To improve'], ['suggestions', 'اقتراحات', 'Suggestions']] as const).map(([k, a, e]) =>
        <div key={k}><div className="font-semibold mb-1">{L(a, e)}</div><ul className="list-disc ps-5 space-y-0.5">{x[k].map((t, i) => <li key={i}>{t}</li>)}</ul></div>)}
        <div className="sm:col-span-3 text-[11.5px] text-ink-3">{L(`من ${x.n} تعليقاً`, `From ${x.n} comments`)} · {fmtDate(x.at, 'ar')}</div></div>}
  </Panel>
}

// ———————————————— القاعات ————————————————

export function RoomsPanel({ lk }: { lk: Lookups }) {
  const L = useL(); const lang = useLang()
  const { data } = useRooms()
  const act = useTdAct()
  const [ed, setEd] = useState<{ id?: number; name: string; name_en: string; location_id: number | null; capacity: number; equipment: string; active: boolean } | null>(null)
  return <Panel title={L('قاعات التدريب', 'Training rooms')} action={<Btn size="sm" icon={<Plus size={14} />} onClick={() => setEd({ name: '', name_en: '', location_id: null, capacity: 20, equipment: '', active: true })}>{L('قاعة', 'Room')}</Btn>}>
    <div className="text-[12.5px] text-ink-3 mb-2">{L('يمنع النظام حجز القاعة نفسها لتنفيذين في الوقت ذاته، وينبّه عند تعارض المدرب أو صغر القاعة.', 'The system blocks double-booking a room and warns on trainer clashes or a too-small room.')}</div>
    {!data ? <Spin /> : data.length === 0 ? <div className="text-[13px] text-ink-3">{L('لا قاعات بعد.', 'No rooms yet.')}</div>
      : <div className="grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(220px,1fr))]">{data.map(r => <button key={r.id} onClick={() => setEd({ id: r.id, name: r.name, name_en: r.name_en ?? '', location_id: r.location_id, capacity: r.capacity, equipment: r.equipment.join('، '), active: r.active })}
        className={`text-start rounded-xl border border-line p-3 hover:border-brand/50 ${r.active ? '' : 'opacity-60'}`}><div className="font-medium">{r.name}</div>
        <div className="text-[12px] text-ink-3">{L('السعة', 'Capacity')} <span className="num">{r.capacity}</span>{r.location_id ? ` · ${bi(lk.branches.find(b => b.id === r.location_id) ?? { ar: '', en: '' }, lang)}` : ''}</div>
        {r.equipment.length > 0 && <div className="text-[11.5px] text-ink-3 mt-0.5">{r.equipment.join('، ')}</div>}</button>)}</div>}
    {ed && <Modal open onClose={() => setEd(null)} title={ed.id ? ed.name : L('قاعة جديدة', 'New room')} footer={<><Btn onClick={() => setEd(null)}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" disabled={!ed.name.trim() || act.isPending} onClick={() => act.mutate({ path: ed.id ? `rooms/${ed.id}` : 'rooms', method: ed.id ? 'PUT' : 'POST', v: { ...ed, name_en: ed.name_en || null, equipment: ed.equipment.split(/[،,]/).map(x => x.trim()).filter(Boolean) } }, { onSuccess: () => setEd(null), onError: fail })}>{L('حفظ', 'Save')}</Btn></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={L('الاسم', 'Name')} req><input className="field" value={ed.name} onChange={e => setEd({ ...ed, name: e.target.value })} /></Field>
        <Field label={L('الاسم بالإنكليزية', 'English name')}><input className="field" value={ed.name_en} onChange={e => setEd({ ...ed, name_en: e.target.value })} /></Field>
        <Field label={L('الفرع', 'Branch')}><select className="field" value={ed.location_id ?? ''} onChange={e => setEd({ ...ed, location_id: e.target.value ? Number(e.target.value) : null })}><option value="">—</option>{lk.branches.map(b => <option key={b.id} value={b.id}>{bi(b, lang)}</option>)}</select></Field>
        <Field label={L('السعة', 'Capacity')}><input type="number" min={1} className="field num" value={ed.capacity} onChange={e => setEd({ ...ed, capacity: Number(e.target.value) })} /></Field>
        <Field label={L('التجهيزات (مفصولة بفواصل)', 'Equipment (comma separated)')} className="sm:col-span-2"><input className="field" value={ed.equipment} onChange={e => setEd({ ...ed, equipment: e.target.value })} /></Field>
        <label className="flex items-center gap-2 text-[13px]"><input type="checkbox" checked={ed.active} onChange={e => setEd({ ...ed, active: e.target.checked })} />{L('فعّالة', 'Active')}</label>
      </div>
    </Modal>}
  </Panel>
}

// ———————————————— السجل السابق للنظام ————————————————

export function HistoryPanel({ employeeId, edit }: { employeeId: number; edit: boolean }) {
  const L = useL(); const lang = useLang()
  const { data } = useHistory(employeeId)
  const act = useTdAct()
  const blank = { title: '', provider: '', place: '', kind: 'external', from_on: '', to_on: '', hours: 0, cost: '', currency: 'USD', file_id: null as number | null, note: '' }
  const [ed, setEd] = useState<(typeof blank & { id?: number }) | null>(null)
  const [del, setDel] = useState<number | null>(null)
  return <Panel title={L('تدريب سابق لاستخدام النظام', 'Training before the system')} action={edit && <Btn size="sm" icon={<Plus size={14} />} onClick={() => setEd(blank)}>{L('إضافة', 'Add')}</Btn>}>
    {!data ? <Spin /> : data.length === 0 ? <div className="text-[13px] text-ink-3">{L('لا سجلات سابقة.', 'No earlier records.')}</div>
      : <div className="space-y-1.5">{data.map(h => <div key={h.id} className="flex flex-wrap items-center gap-2 rounded-lg border border-line px-3 py-2 text-[13px]">
        <span className="font-medium flex-1 min-w-[160px]">{h.title}</span><span className="text-ink-3">{h.provider ?? ''}</span>
        <span className="num text-ink-3">{h.from ? fmtDate(h.from, lang) : ''}{h.to && h.to !== h.from ? ` — ${fmtDate(h.to, lang)}` : ''}</span><span className="num">{h.hours} {L('ساعة', 'h')}</span>
        {h.file_id && <a className="underline text-[12px]" href={`/api/files/${h.file_id}`} target="_blank" rel="noreferrer">{L('المرفق', 'File')}</a>}
        {edit && <><Btn size="sm" kind="ghost" onClick={() => setEd({ ...blank, ...h, from_on: h.from ?? '', to_on: h.to ?? '', cost: h.cost?.toString() ?? '', currency: h.currency ?? 'USD', provider: h.provider ?? '', place: h.place ?? '', note: h.note ?? '' })}>{L('تعديل', 'Edit')}</Btn>
          <Btn size="sm" kind="ghost" icon={<X size={14} />} aria-label={L('حذف', 'Delete')} onClick={() => setDel(h.id)} /></>}</div>)}</div>}
    {ed && <Modal open onClose={() => setEd(null)} title={ed.id ? ed.title : L('تدريب سابق', 'Earlier training')} footer={<><Btn onClick={() => setEd(null)}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" disabled={ed.title.trim().length < 2 || act.isPending} onClick={() => act.mutate({ path: ed.id ? `history/${ed.id}` : 'history', method: ed.id ? 'PUT' : 'POST', v: { ...ed, employee_id: employeeId, cost: ed.cost === '' ? null : Number(ed.cost), from_on: ed.from_on || null, to_on: ed.to_on || null, provider: ed.provider || null, place: ed.place || null, note: ed.note || null } },
        { onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['td'] }); setEd(null) }, onError: fail })}>{L('حفظ', 'Save')}</Btn></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={L('الدورة', 'Course')} req className="sm:col-span-2"><input className="field" value={ed.title} onChange={e => setEd({ ...ed, title: e.target.value })} /></Field>
        <Field label={L('الجهة', 'Provider')}><input className="field" value={ed.provider} onChange={e => setEd({ ...ed, provider: e.target.value })} /></Field>
        <Field label={L('النوع', 'Kind')}><Seg value={ed.kind} onChange={v => setEd({ ...ed, kind: v })} options={[{ v: 'external', label: L('خارجي', 'External') }, { v: 'internal', label: L('داخلي', 'Internal') }]} /></Field>
        <Field label={L('من', 'From')}><input type="date" className="field" value={ed.from_on} onChange={e => setEd({ ...ed, from_on: e.target.value })} /></Field>
        <Field label={L('إلى', 'To')}><input type="date" className="field" value={ed.to_on} onChange={e => setEd({ ...ed, to_on: e.target.value })} /></Field>
        <Field label={L('الساعات', 'Hours')}><input type="number" min={0} className="field num" value={ed.hours} onChange={e => setEd({ ...ed, hours: Number(e.target.value) })} /></Field>
        <Field label={L('التكلفة', 'Cost')}><div className="flex gap-2"><input type="number" min={0} className="field num" value={ed.cost} onChange={e => setEd({ ...ed, cost: e.target.value })} /><input className="field !w-20 ltr" maxLength={3} value={ed.currency} onChange={e => setEd({ ...ed, currency: e.target.value.toUpperCase() })} aria-label={L('العملة', 'Currency')} /></div></Field>
        <Field label={L('ملاحظة', 'Note')} className="sm:col-span-2"><input className="field" value={ed.note} onChange={e => setEd({ ...ed, note: e.target.value })} /></Field>
        <div className="sm:col-span-2"><TdFilePick label={L('إرفاق الشهادة', 'Attach the certificate')} onDone={f => setEd({ ...ed, file_id: f.id })} /></div>
      </div>
    </Modal>}
    <DecisionDialog open={!!del} onClose={() => setDel(null)} tone="danger" title={L('حذف السجل', 'Delete the record')} confirmLabel={L('حذف', 'Delete')} onConfirm={() => act.mutateAsync({ path: `history/${del}`, method: 'DELETE' })} />
  </Panel>
}

// ———————————————— استيراد الاحتياجات ————————————————

export function NeedsImport({ onClose }: { onClose: () => void }) {
  const L = useL()
  const ref = useRef<HTMLInputElement>(null)
  const [file, setFile] = useState<File | null>(null)
  const [pv, setPv] = useState<{ rows: { row: number; data: { title: string; employee: string | null }; errors: string[] }[]; ok: number; bad: number } | null>(null)
  const [busy, setBusy] = useState(false)
  const send = async (path: string) => { if (!file) return; const f = new FormData(); f.append('file', file); setBusy(true)
    try { return await api<Record<string, unknown>>(`/td/needs/import${path}`, { method: 'POST', body: f }) } catch (e) { fail(e) } finally { setBusy(false) } }
  return <Modal open onClose={onClose} width={720} title={L('استيراد الاحتياجات من Excel', 'Import needs from Excel')} footer={<><Btn onClick={onClose}>{L('إغلاق', 'Close')}</Btn>
    <Btn kind="primary" disabled={!pv || pv.bad > 0 || pv.ok === 0 || busy} onClick={async () => { const r = await send(''); if (r) { toast(L(`أُدخل ${r.imported} احتياجاً`, `${r.imported} needs imported`)); queryClient.invalidateQueries({ queryKey: ['td'] }); onClose() } }}>{L('استيراد', 'Import')}</Btn></>}>
    <div className="flex flex-wrap gap-2"><Btn icon={<Download size={15} />} onClick={() => download('/td/needs/import/template', 'training-needs.xlsx')}>{L('تنزيل القالب', 'Download the template')}</Btn>
      <input ref={ref} type="file" accept=".xlsx" className="hidden" onChange={async e => { const f = e.target.files?.[0]; if (!f) return; setFile(f); setPv(null); e.target.value = '' }} />
      <Btn icon={<Upload size={15} />} onClick={() => ref.current?.click()}>{file ? file.name : L('اختيار الملف', 'Choose the file')}</Btn>
      <Btn disabled={!file || busy} onClick={async () => { const r = await send('/preview'); if (r) setPv(r as never) }}>{L('معاينة', 'Preview')}</Btn></div>
    {pv && <div className="mt-3"><div className="text-[13px]">{L(`صحيحة ${pv.ok} · خاطئة ${pv.bad}`, `${pv.ok} valid · ${pv.bad} with errors`)}{pv.bad > 0 && <span className="text-danger"> — {L('صحّح الأسطر الخاطئة ثم أعد المعاينة.', 'Fix the rows with errors and preview again.')}</span>}</div>
      <div className="mt-2 max-h-72 overflow-y-auto space-y-1">{pv.rows.map(r => <div key={r.row} className={`rounded-lg border px-3 py-1.5 text-[12.5px] ${r.errors.length ? 'border-danger/50' : 'border-line'}`}>
        <span className="num text-ink-3">#{r.row}</span> {r.data.title} {r.data.employee ? `· ${r.data.employee}` : ''}{r.errors.length > 0 && <div className="text-danger">{r.errors.join(' · ')}</div>}</div>)}</div></div>}
  </Modal>
}

// ———————————————— تقارير إضافية ————————————————

export function MoreReports({ year }: { year: number }) {
  const L = useL(); const lang = useLang()
  const costs = useTdReport<{ currency: string; rows: CostRow[]; totals: { planned: number; quote: number; actual: number; budget: number | null; over: number } }>('costs', { year })
  const score = useTdReport<ScoreRow[]>('scorecard')
  const impacts = useTdReport<ImpactCard[]>('impacts')
  const [view, setView] = useState('costs')
  const group = useTdReport<{ currency: string; companies: { company: { id: number; ar: string; en: string }; plan: { id: number; status: string } | null; budget: number; planned: number; actual: number; items: number; done: number; trained: number; hours: number; headcount: number }[]; totals: Record<string, number> }>('group', { year })
  const pct = (v: number | null) => v == null ? '—' : `${v}%`
  return <Panel title={L('تقارير المطابقة والأثر', 'Reconciliation & impact reports')} action={<div className="flex flex-wrap gap-1.5">
    <Btn size="sm" kind="ghost" icon={<FileSpreadsheet size={14} />} onClick={() => download(`/td/export/${view === 'impact' ? 'impacts' : view}?year=${year}`, `training-${view}-${year}.xlsx`)}>Excel</Btn>
    <Btn size="sm" kind="ghost" icon={<FileSpreadsheet size={14} />} onClick={() => download(`/td/export/summary?year=${year}`, `training-summary-${year}.xlsx`)}>{L('ملخص المؤشرات', 'KPI summary')}</Btn></div>}>
    <Seg value={view} onChange={setView} options={[{ v: 'costs', label: L('مطابقة التكلفة', 'Cost reconciliation') }, { v: 'scorecard', label: L('بطاقة أداء المدربين', 'Trainer scorecard') }, { v: 'impact', label: L('أثر البرامج', 'Programme impact') }, ...((group.data?.companies.length ?? 0) > 1 ? [{ v: 'group', label: L('خطة المجموعة', 'Group plan') }] : [])]} />
    <div className="mt-3">
      {view === 'group' && group.data ? <table className="tbl"><thead><tr><th>{L('الشركة', 'Company')}</th><th>{L('الموازنة', 'Budget')}</th><th className="hidden md:table-cell">{L('المخطط', 'Planned')}</th><th>{L('الفعلي', 'Actual')}</th><th className="hidden sm:table-cell">{L('المتدربون', 'Trained')}</th></tr></thead>
        <tbody>{group.data.companies.map(c => <tr key={c.company.id}><td>{bi(c.company, lang)}{!c.plan && <div className="text-[11.5px] text-ink-3">{L('لا خطة', 'No plan')}</div>}</td><td className="num">{money(c.budget, group.data!.currency, lang)}</td><td className="hidden md:table-cell num">{money(c.planned, group.data!.currency, lang)}</td><td className="num">{money(c.actual, group.data!.currency, lang)}</td><td className="hidden sm:table-cell num">{c.trained}</td></tr>)}
          <tr className="font-semibold"><td>{L('المجموع', 'Total')}</td><td className="num">{money(group.data.totals.budget, group.data.currency, lang)}</td><td className="hidden md:table-cell num">{money(group.data.totals.planned, group.data.currency, lang)}</td><td className="num">{money(group.data.totals.actual, group.data.currency, lang)}</td><td className="hidden sm:table-cell num">{group.data.totals.trained}</td></tr></tbody></table>
      : view === 'costs' ? (!costs.data ? <Spin /> : <>
        <div className="grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(150px,1fr))] mb-3">
          <Stat label={L('المخطط', 'Planned')} value={money(costs.data.totals.planned, costs.data.currency, lang)} /><Stat label={L('العروض المعتمدة', 'Approved quotes')} value={money(costs.data.totals.quote, costs.data.currency, lang)} />
          <Stat label={L('الفعلي', 'Actual')} value={money(costs.data.totals.actual, costs.data.currency, lang)} /><Stat label={L('تنفيذات تجاوزت', 'Runs over')} value={costs.data.totals.over} tone={costs.data.totals.over ? 'var(--danger)' : undefined} /></div>
        {costs.data.rows.length === 0 ? <div className="text-[13px] text-ink-3">{L('لا تنفيذات في هذه السنة.', 'No runs this year.')}</div> : <table className="tbl"><thead><tr><th>{L('التنفيذ', 'Run')}</th><th className="hidden md:table-cell">{L('المخطط', 'Planned')}</th><th className="hidden md:table-cell">{L('العرض', 'Quote')}</th><th>{L('الفعلي', 'Actual')}</th><th>{L('الفرق', 'Variance')}</th></tr></thead>
          <tbody>{costs.data.rows.map(r => <tr key={r.id}><td><div>{r.title}</div><div className="text-[11.5px] text-ink-3 ltr text-start">{r.ref}</div></td><td className="hidden md:table-cell num">{r.planned != null ? money(r.planned, costs.data!.currency, lang) : '—'}</td>
            <td className="hidden md:table-cell num">{r.quote != null ? money(r.quote, costs.data!.currency, lang) : '—'}</td><td className="num">{money(r.actual, costs.data!.currency, lang)}</td>
            <td className={`num ${(r.variance ?? 0) > 0 ? 'text-danger' : ''}`}>{r.variance != null ? `${r.variance > 0 ? '+' : ''}${money(r.variance, costs.data!.currency, lang)} (${pct(r.variance_pct)})` : '—'}</td></tr>)}</tbody></table>}
      </>) : view === 'scorecard' ? (!score.data ? <Spin /> : score.data.length === 0 ? <div className="text-[13px] text-ink-3">{L('لا تقييمات بعد.', 'No ratings yet.')}</div>
        : <table className="tbl"><thead><tr><th>{L('الجهة', 'Provider')}</th><th>{L('الدرجة الموزونة', 'Weighted')}</th><th className="hidden md:table-cell">{L('رضا المتدربين', 'Reaction')}</th><th className="hidden md:table-cell">{L('النتائج', 'Results')}</th><th className="hidden sm:table-cell">{L('النجاح', 'Pass rate')}</th></tr></thead>
          <tbody>{score.data.map(r => <tr key={`${r.kind}-${r.id}`}><td><div>{r.name}</div><div className="text-[11.5px] text-ink-3">{r.kind === 'trainer' ? L('مدرب', 'Trainer') : L('مركز', 'Centre')} · {L(`${r.sessions} تنفيذ`, `${r.sessions} runs`)}{r.status !== 'approved' ? ` · ${r.status}` : ''}</div></td>
            <td className="num font-semibold">{r.weighted ?? '—'}</td><td className="hidden md:table-cell num">{r.l1 ?? '—'}</td><td className="hidden md:table-cell num">{r.results ?? '—'}</td><td className="hidden sm:table-cell num">{pct(r.pass_rate)}</td></tr>)}</tbody></table>)
      : (!impacts.data ? <Spin /> : impacts.data.length === 0 ? <div className="text-[13px] text-ink-3">{L('لا برامج منفذة بعد.', 'No delivered programmes yet.')}</div>
        : <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(280px,1fr))]">{impacts.data.map(c => <div key={c.program.id} className="rounded-xl border border-line p-3">
          <div className="font-semibold">{c.program.title}</div><div className="text-[11.5px] text-ink-3"><span className="ltr">{c.program.code}</span> · {L(`${c.sessions} تنفيذ · ${c.trainees} متدرب · ${c.passed} ناجح`, `${c.sessions} runs · ${c.trainees} trainees · ${c.passed} passed`)}</div>
          <div className="grid grid-cols-2 gap-2 mt-2 text-[12.5px]">
            <div><div className="text-ink-3">{L('1. الرضا', '1. Reaction')}</div><b className="num">{c.l1 ?? '—'}</b>/5</div>
            <div><div className="text-ink-3">{L('2. التعلم', '2. Learning')}</div><b className="num">{c.l2.gain != null ? `+${c.l2.gain}` : '—'}</b> <span className="text-ink-3 num">{c.l2.pre ?? ''}{c.l2.post != null ? `→${c.l2.post}` : ''}</span></div>
            <div><div className="text-ink-3">{L('3. التطبيق', '3. Behaviour')}</div><b className="num">{c.l3.avg != null ? `${c.l3.avg}%` : '—'}</b> <span className="text-ink-3">{L(`(${c.l3.pass}/${c.l3.n})`, `(${c.l3.pass}/${c.l3.n})`)}</span></div>
            <div><div className="text-ink-3">{L('4. النتائج', '4. Results')}</div><b className="num">{c.l4.kpi_change != null ? `${c.l4.kpi_change}%` : '—'}</b>{c.l4.roi != null && <span className="text-ink-3"> · ROI {c.l4.roi}%</span>}</div>
          </div>
          <div className="text-[12px] text-ink-3 mt-2">{L('التكلفة للمتدرب', 'Cost per trainee')}: <span className="num">{c.cost_per_trainee ?? '—'}</span></div></div>)}</div>)}
    </div>
  </Panel>
}

// ———————————————— طلبات تعديل الخطة ————————————————

export function ChangeRequests({ rows, currency }: { rows: { id: number; title: string | null; reason: string; cost_delta: number; status: string; at: string; steps: import('../../lib/requests').Step[] }[]; currency: string }) {
  const L = useL(); const lang = useLang()
  if (!rows.length) return null
  const ST: Record<string, [string, string, 'brass' | 'brand' | 'danger' | 'neutral']> = { approved: ['معتمد', 'Approved', 'brand'], rejected: ['مرفوض', 'Rejected', 'danger'], cancelled: ['ملغى', 'Cancelled', 'neutral'] }
  return <Panel title={L('طلبات تعديل الخطة المعتمدة', 'Change requests on the approved plan')}>
    <div className="text-[12.5px] text-ink-3 mb-2">{L('لا يُطبَّق أي تعديل على الخطة المعتمدة قبل اعتماده؛ يظهر الطلب في صندوق الاعتمادات لصاحب الصلاحية.', 'No change applies to the approved plan before it is approved; the request appears in the approver’s inbox.')}</div>
    <div className="space-y-2">{rows.map(r => <div key={r.id} className="rounded-xl border border-line p-3 text-[13px]">
      <div className="flex flex-wrap items-center gap-2"><b>{r.title ?? '—'}</b><span className={`num ${r.cost_delta > 0 ? 'text-danger' : ''}`}>{r.cost_delta > 0 ? '+' : ''}{money(r.cost_delta, currency, lang)}</span>
        <Badge tone={ST[r.status]?.[2] ?? 'brass'}>{ST[r.status] ? L(ST[r.status][0], ST[r.status][1]) : L('بانتظار الاعتماد', 'Awaiting approval')}</Badge><span className="flex-1" /><span className="text-[11.5px] text-ink-3">{fmtDate(r.at, lang)}</span></div>
      <div className="text-ink-3 mt-1">{r.reason}</div>{r.steps.length > 0 && <div className="mt-2"><StepsPath steps={r.steps} /></div>}</div>)}</div>
  </Panel>
}

// ———————————————— التقويم: شهري/قائمة، فلاتر، سحب البنود، رابط الاشتراك ————————————————

export function CalendarPro({ year, lk }: { year: number; lk: Lookups }) {
  const L = useL(); const lang = useLang()
  const q = useRoute().query
  const { data } = useCalendar(year)
  const link = useCalendarLink()
  const act = useTdAct()
  const view = q.cv === 'list' ? 'list' : 'grid'
  const f = { path: q.cpath ?? '', delivery: q.cdel ?? '', branch: q.cbr ?? '' }
  const [drag, setDrag] = useState<number | null>(null)
  const [move, setMove] = useState<{ id: number; month: number | null } | null>(null)
  const [reason, setReason] = useState('')
  if (!data) return <Spin />
  const pass = (x: { path: Path | null; delivery: string | null; location_id: number | null }) => (!f.path || x.path === f.path) && (!f.delivery || x.delivery === f.delivery) && (!f.branch || String(x.location_id ?? '') === f.branch)
  const sessions = data.sessions.filter(pass); const items = data.items.filter(i => pass({ ...i, location_id: i.location_id ?? null }))
  const name = (m: number) => new Date(year, m - 1, 1).toLocaleDateString(lang === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB', { month: 'long' })
  const mv = items.find(i => i.id === move?.id)
  const doMove = () => { if (!mv || !move) return
    act.mutate({ path: `plan-items/${mv.id}`, method: 'PUT', v: { title: mv.title, path: mv.path, delivery: mv.delivery, audience: mv.audience, month: move.month, estimated_cost: mv.estimated_cost, trainees: mv.trainees, priority: mv.priority, objective: mv.objective,
      program_id: mv.program_id, department_id: mv.department_id, location_id: mv.location_id, change_reason: mv.plan_status === 'approved' ? reason : null } },
      { onSuccess: () => { toast(mv.plan_status === 'approved' ? L('أُرسل طلب تعديل الشهر للاعتماد', 'The month change was sent for approval') : L('نُقل البند', 'Item moved')); setMove(null); setReason('') }, onError: fail }) }
  const onDrop = (m: number | null) => (e: DragEvent) => { e.preventDefault(); if (drag != null) { setMove({ id: drag, month: m }); setDrag(null) } }
  const sesBtn = (s: (typeof sessions)[number]) => <button key={s.id} onClick={() => setQuery({ tab: 'sessions', s: String(s.id) })} className="w-full text-start flex items-start gap-1.5 text-[12.5px] hover:text-brand">
    <span className="size-2 rounded-full mt-1.5 shrink-0" style={{ background: s.path ? PATH[s.path][2] : 'var(--line)' }} /><span className="flex-1">{s.title} <span className="text-ink-3 num">{s.from.slice(8)}{s.to !== s.from ? `–${s.to.slice(8)}` : ''}</span></span>
    {s.status !== 'planned' && <StatusBadge map={S_STATUS} s={s.status} />}</button>
  const itemEl = (i: (typeof items)[number]) => <div key={i.id} draggable onDragStart={() => setDrag(i.id)} className="flex items-start gap-1.5 text-[12.5px] text-ink-3 cursor-grab rounded-md px-1 hover:bg-sub" title={L('اسحب إلى شهر آخر', 'Drag to another month')}>
    <span className="size-2 rounded-full mt-1.5 shrink-0 border" style={{ borderColor: PATH[i.path][2] }} /><span>{i.title} · {L('غير مجدول', 'unscheduled')}{i.status === 'postponed' ? ` · ${L('مؤجل', 'postponed')}` : ''}</span></div>
  return <>
    <div className="flex flex-wrap items-center gap-2 mb-3">
      <select className="field !w-28" value={year} onChange={e => setQuery({ year: e.target.value })} aria-label={L('السنة', 'Year')}>{[year - 1, year, year + 1].map(y => <option key={y} value={y}>{y}</option>)}</select>
      <Seg value={view} onChange={v => setQuery({ cv: v === 'grid' ? undefined : v })} options={[{ v: 'grid', label: <LayoutGrid size={15} aria-label={L('شهري', 'Months')} /> }, { v: 'list', label: <List size={15} aria-label={L('قائمة', 'List')} /> }]} />
      <select className="field !w-full sm:!w-36" value={f.path} onChange={e => setQuery({ cpath: e.target.value || undefined })} aria-label={L('المسار', 'Path')}><option value="">{L('كل المسارات', 'All paths')}</option>{Object.entries(PATH).map(([k, v]) => <option key={k} value={k}>{L(v[0], v[1])}</option>)}</select>
      <select className="field !w-full sm:!w-44" value={f.delivery} onChange={e => setQuery({ cdel: e.target.value || undefined })} aria-label={L('الأسلوب', 'Delivery')}><option value="">{L('كل الأساليب', 'All deliveries')}</option>{Object.entries(DELIVERY).map(([k, v]) => <option key={k} value={k}>{L(v[0], v[1])}</option>)}</select>
      <select className="field !w-full sm:!w-40" value={f.branch} onChange={e => setQuery({ cbr: e.target.value || undefined })} aria-label={L('الفرع', 'Branch')}><option value="">{L('كل الفروع', 'All branches')}</option>{lk.branches.map(b => <option key={b.id} value={b.id}>{bi(b, lang)}</option>)}</select>
      <span className="flex-1" />
      {link.data && <Btn size="sm" kind="ghost" icon={<Copy size={14} />} onClick={() => navigator.clipboard?.writeText(link.data.url).then(() => toast(L('نُسخ رابط الاشتراك — أضفه في Outlook أو Google Calendar', 'Subscription link copied — add it in Outlook or Google Calendar')))}>{L('رابط اشتراك التقويم', 'Calendar subscription link')}</Btn>}
    </div>
    {view === 'list' ? <Panel pad={false}>{sessions.length + items.length === 0 ? <Empty title={L('لا شيء ضمن الفلاتر', 'Nothing matches the filters')} /> : <table className="tbl"><thead><tr><th>{L('التاريخ', 'Date')}</th><th>{L('العنوان', 'Title')}</th><th className="hidden sm:table-cell">{L('الأسلوب', 'Delivery')}</th><th className="hidden md:table-cell">{L('الفئة', 'Audience')}</th><th>{L('الحالة', 'Status')}</th></tr></thead>
      <tbody>{[...sessions].sort((a, b) => a.from.localeCompare(b.from)).map(s => <tr key={`s${s.id}`} className="cursor-pointer" onClick={() => setQuery({ tab: 'sessions', s: String(s.id) })}><td className="num">{fmtDate(s.from, lang)}</td><td>{s.title}</td>
        <td className="hidden sm:table-cell">{DELIVERY[s.delivery] ? L(DELIVERY[s.delivery][0], DELIVERY[s.delivery][1]) : s.delivery}</td><td className="hidden md:table-cell">{AUDIENCE[s.audience] ? L(AUDIENCE[s.audience][0], AUDIENCE[s.audience][1]) : '—'}</td><td><StatusBadge map={S_STATUS} s={s.status} /></td></tr>)}
        {items.map(i => <tr key={`i${i.id}`}><td>{i.month ? name(i.month) : '—'}</td><td>{i.title}</td><td className="hidden sm:table-cell">{DELIVERY[i.delivery] ? L(DELIVERY[i.delivery][0], DELIVERY[i.delivery][1]) : i.delivery}</td>
          <td className="hidden md:table-cell">{AUDIENCE[i.audience] ? L(AUDIENCE[i.audience][0], AUDIENCE[i.audience][1]) : '—'}</td><td><Badge tone="neutral">{L('غير مجدول', 'Unscheduled')}</Badge></td></tr>)}</tbody></table>}</Panel>
      : <>
        {items.some(i => !i.month) && <div onDragOver={e => e.preventDefault()} onDrop={onDrop(null)} className="mb-3 rounded-2xl border border-dashed border-line p-3"><div className="text-[12.5px] font-semibold mb-1">{L('بنود دون شهر', 'Items without a month')}</div>
          <div className="flex flex-wrap gap-2">{items.filter(i => !i.month).map(itemEl)}</div></div>}
        <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(250px,1fr))]">{Array.from({ length: 12 }, (_, k) => k + 1).map(m => {
          const mm = String(m).padStart(2, '0')
          const ss = sessions.filter(s => s.from.slice(5, 7) === mm); const it = items.filter(i => i.month === m); const hs = data.holidays.filter(h => h.date.slice(5, 7) === mm)
          return <div key={m} onDragOver={e => e.preventDefault()} onDrop={onDrop(m)} className={`bg-surface border rounded-2xl p-3 min-h-[120px] ${drag != null ? 'border-brand/50' : 'border-line'}`}>
            <div className="font-semibold mb-1.5 flex items-center gap-1.5"><CalendarDays size={14} className="text-ink-3" />{name(m)}</div>
            <div className="space-y-1">{ss.map(sesBtn)}{it.map(itemEl)}{hs.map(h => <div key={h.date} className="text-[11.5px] text-danger">{h.date.slice(8)} · {bi(h, lang)}</div>)}
              {!ss.length && !it.length && !hs.length && <div className="text-[12px] text-ink-3">—</div>}</div></div>
        })}</div></>}
    {move && mv && <Modal open onClose={() => setMove(null)} title={L('نقل بند الخطة', 'Move the plan item')} footer={<><Btn onClick={() => setMove(null)}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" disabled={act.isPending || (mv.plan_status === 'approved' && reason.trim().length < 5)} onClick={doMove}>{mv.plan_status === 'approved' ? L('إرسال للاعتماد', 'Send for approval') : L('نقل', 'Move')}</Btn></>}>
      <div className="text-[13.5px]">«{mv.title}» ← <b>{move.month ? name(move.month) : L('دون شهر', 'No month')}</b></div>
      {mv.plan_status === 'approved' && <Field label={L('الخطة معتمدة — سبب التعديل', 'The plan is approved — reason for the change')} req className="mt-3"><input className="field" value={reason} onChange={e => setReason(e.target.value)} /></Field>}
    </Modal>}
  </>
}

/** يحمل إشارة «يُستعمل أكثر من اللازم» لمزيج 70-20-10 */
export function FormalHeavy({ on }: { on: boolean }) {
  const L = useL()
  if (!on) return null
  return <div className="flex items-center gap-1.5 text-[12px] text-[var(--warn)]"><AlertTriangle size={13} />{L('تعتمد الخطة على الدورات أكثر من المنصوح (10%) — أضف تعلماً بالممارسة والإرشاد.', 'The plan relies on courses more than advised (10%) — add learning by doing and mentoring.')}</div>
}

export const errField = (e: unknown, f: string) => errOf(e)?.field(f)
