import { useState, type ReactNode } from 'react'
import { Check, Plus, Printer, Trash2 } from 'lucide-react'
import { useL, useLang, bi, fmtDate, fmtDateTime, money } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { EXIT_TONE, type ClearanceItem, type ClearanceSection, type ExitFull, type ExitStatus, type Item, type Signed, type Task } from '../../lib/exit'
import type { Bi } from '../../lib/types'
import { Badge, Btn, Empty, Field, Modal, Panel, toast } from '../../ui/kit'
import { Paper, SigBox } from '../../ui/docs'
import { ProtectedBlock } from '../../ui/vault'

export function ExitBadge({ s, kind }: { s: ExitStatus; kind?: 'resignation' | 'termination' }) {
  const L = useL()
  const t: Record<ExitStatus, string> = { pending_manager: L('بانتظار المدير المباشر', 'Awaiting manager'), pending_hr: L('بانتظار الموارد البشرية', 'Awaiting HR'),
    pending_exec: L('بانتظار المدير العام', 'Awaiting GM'), clearance: L('إجراءات الخروج', 'Exit procedures'), closed: L('انتهت الخدمة', 'Service ended'),
    rejected: L('مرفوض', 'Declined'), cancelled: kind === 'termination' ? L('ملغى', 'Cancelled') : L('مسحوبة', 'Withdrawn') }
  return <Badge tone={EXIT_TONE[s]}>{t[s]}</Badge>
}

export const signedBy = (s: Signed | null | undefined, lang: 'ar' | 'en') => s ? `${lang === 'ar' ? s.by : s.by_en} · ${fmtDateTime(s.at, lang)}` : null
const label = (list: Item[], k: string, lang: 'ar' | 'en') => { const x = list.find(i => i.key === k); return x ? (lang === 'ar' ? x.ar : x.en) : k }

/** F-05 تسليم المهام: الجدول والتواقيع بالترتيب، وأزرار التوقيع لمن يخصّه */
export function HandoverPanel({ r, canEdit, colleagues, onSave, sign, signAs }: { r: ExitFull; canEdit: boolean; colleagues: (Bi & { id: number; code: string })[]
  onSave: (v: { receiver_id: number; tasks: Task[] }) => Promise<unknown>; sign: (as: string) => Promise<unknown>; signAs: string[] }) {
  const L = useL(); const lang = useLang()
  const h = r.handover
  const [edit, setEdit] = useState(false)
  const st = (s: Task['status']) => s === 'done' ? L('منجز', 'Done') : s === 'in_progress' ? L('قيد التنفيذ', 'In progress') : L('مؤجل', 'Postponed')
  const chain: [string, string, Signed | null | undefined][] = [['employee', L('تعهد المسلِّم', 'Handing-over pledge'), h?.employee], ['receiver', L('إقرار المستلم', 'Receiver’s acknowledgement'), h?.receiver_signed],
    ['manager', L('مصادقة المدير', 'Manager approval'), h?.manager], ['hr', L('مصادقة الموارد البشرية', 'HR approval'), h?.hr]]
  const next = chain.find(c => !c[2])?.[0]
  const signed = !!h?.employee
  return <Panel title={L('تسليم المهام', 'Handover of duties')} sub="HR.01-03-F-05" action={canEdit && r.status === 'clearance' && <Btn size="sm" onClick={() => setEdit(true)}>{h?.tasks.length ? L('تعديل الجدول', 'Edit list') : L('إدخال المهام', 'Enter tasks')}</Btn>} pad={false}>
    {!h?.tasks.length ? <Empty title={L('لم يُدخل جدول المهام بعد', 'No task list yet')}>{L('يُدخله الموظف المستقيل (أو HR) ويسمّي المستلم.', 'Entered by the leaver (or HR), naming the receiver.')}</Empty> : <>
      <table className="tbl"><thead><tr><th>#</th><th>{L('المهمة', 'Task')}</th><th className="hidden sm:table-cell">{L('الحالة', 'Status')}</th><th className="hidden md:table-cell">{L('الملفات والنوع', 'Files & type')}</th><th className="hidden lg:table-cell">{L('المتبقي ومكان الحفظ', 'Remaining & location')}</th></tr></thead>
        <tbody>{h.tasks.map((t, i) => <tr key={i}><td className="num">{i + 1}</td><td><div>{t.task}</div><div className="sm:hidden text-[12px] text-ink-3">{st(t.status)}</div></td>
          <td className="hidden sm:table-cell">{st(t.status)}</td><td className="hidden md:table-cell text-[13px]">{t.files ?? '—'}{t.type ? <span className="text-ink-3"> · {t.type === 'paper' ? L('ورقي', 'paper') : L('إلكتروني', 'electronic')}</span> : null}</td>
          <td className="hidden lg:table-cell text-[13px]">{t.remaining ?? '—'}{t.location ? <div className="text-ink-3 break-all">{t.location}</div> : null}</td></tr>)}</tbody></table>
      <div className="p-4 space-y-2 border-t border-line-2">
        <div className="text-[13px]">{L('المستلم', 'Receiver')}: <b>{h.receiver ? bi(h.receiver, lang) : '—'}</b></div>
        <ol className="grid [grid-template-columns:repeat(auto-fill,minmax(200px,1fr))] gap-2">{chain.map(([k, t, s]) => <li key={k} className={`rounded-lg border px-3 py-2 text-[13px] ${s ? 'border-brand/40 bg-brand-soft/30' : 'border-line'}`}>
          <div className="flex items-center gap-1.5 font-semibold">{s ? <Check size={14} className="text-brand" /> : <span className="size-3 rounded-full border border-ink-3" />}{t}</div>
          <div className="text-[12px] text-ink-3 mt-0.5">{signedBy(s, lang) ?? L('بانتظار', 'Pending')}</div>
          {!s && k === next && signAs.includes(k) && r.status === 'clearance' && <Btn size="sm" kind="primary" className="mt-1.5" onClick={() => sign(k).then(() => toast(L('سُجّل التوقيع', 'Signed')))
            .catch(e => toast(e instanceof ApiError ? e.message : L('تعذّر التوقيع', 'Could not sign')))}>{L('توقيع', 'Sign')}</Btn>}
        </li>)}</ol>
        {signed && <p className="text-[12px] text-ink-3">{L('أتعهد بأنني سلّمت كافة الملفات والمستندات التي بحوزتي للموظف البديل.', 'I pledge that I handed over every file and document in my possession to the replacement.')}</p>}
      </div></>}
    {edit && <HandoverEditor r={r} colleagues={colleagues} onClose={() => setEdit(false)} onSave={onSave} />}
  </Panel>
}

function HandoverEditor({ r, colleagues, onClose, onSave }: { r: ExitFull; colleagues: (Bi & { id: number; code: string })[]; onClose: () => void; onSave: (v: { receiver_id: number; tasks: Task[] }) => Promise<unknown> }) {
  const L = useL(); const lang = useLang()
  const [tasks, setTasks] = useState<Task[]>(r.handover?.tasks.length ? r.handover.tasks : [{ task: '', status: 'in_progress', type: 'electronic' }])
  const [rec, setRec] = useState(r.handover?.receiver?.id ?? 0)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const set = (i: number, p: Partial<Task>) => setTasks(tasks.map((t, j) => j === i ? { ...t, ...p } : t))
  const save = () => { setBusy(true); setErr(''); onSave({ receiver_id: rec, tasks: tasks.filter(t => t.task.trim()) }).then(() => { toast(L('حُفظ الجدول — التواقيع تبدأ من جديد', 'Saved — signatures restart')); onClose() })
    .catch(e => setErr(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : L('تعذّر الحفظ', 'Could not save'))).finally(() => setBusy(false)) }
  return <Modal open onClose={onClose} title={L('جدول تسليم المهام', 'Handover task list')} width={860} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={busy || !rec || !tasks.some(t => t.task.trim())} onClick={save}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="space-y-3">
      <Field label={L('الموظف المستلم', 'Receiving employee')} req><select className="field" value={rec || ''} onChange={e => setRec(Number(e.target.value))}><option value="">—</option>
        {colleagues.map(c => <option key={c.id} value={c.id}>{bi(c, lang)} — {c.code}</option>)}</select></Field>
      {tasks.map((t, i) => <div key={i} className="rounded-lg border border-line p-3 grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
        <Field label={`${i + 1}. ${L('المهمة', 'Task')}`} className="sm:col-span-2 lg:col-span-3"><input className="field" maxLength={300} value={t.task} onChange={e => set(i, { task: e.target.value })} /></Field>
        <Field label={L('الحالة', 'Status')}><select className="field" value={t.status} onChange={e => set(i, { status: e.target.value as Task['status'] })}>
          <option value="done">{L('منجز', 'Done')}</option><option value="in_progress">{L('قيد التنفيذ', 'In progress')}</option><option value="postponed">{L('مؤجل', 'Postponed')}</option></select></Field>
        <Field label={L('النوع', 'Type')}><select className="field" value={t.type ?? ''} onChange={e => set(i, { type: (e.target.value || null) as Task['type'] })}><option value="">—</option>
          <option value="paper">{L('ورقي', 'Paper')}</option><option value="electronic">{L('إلكتروني', 'Electronic')}</option></select></Field>
        <Field label={L('الملفات', 'Files')}><input className="field" maxLength={300} value={t.files ?? ''} onChange={e => set(i, { files: e.target.value })} /></Field>
        <Field label={L('الأعمال المتبقية', 'Remaining work')} className="lg:col-span-2"><input className="field" maxLength={500} value={t.remaining ?? ''} onChange={e => set(i, { remaining: e.target.value })} /></Field>
        <Field label={L('مكان الحفظ / الرابط', 'Location / link')}><input className="field" maxLength={300} value={t.location ?? ''} onChange={e => set(i, { location: e.target.value })} /></Field>
        {tasks.length > 1 && <button className="justify-self-start text-danger text-[13px] inline-flex items-center gap-1" onClick={() => setTasks(tasks.filter((_, j) => j !== i))}><Trash2 size={14} />{L('حذف', 'Remove')}</button>}
      </div>)}
      {tasks.length < 20 && <Btn size="sm" icon={<Plus size={14} />} onClick={() => setTasks([...tasks, { task: '', status: 'in_progress', type: 'electronic' }])}>{L('مهمة أخرى', 'Another task')}</Btn>}
      {err && <p role="alert" className="text-[13px] text-danger">{err}</p>}
    </div>
  </Modal>
}

/** F-07 تسليم العهدة: كل جهة تؤشّر بنودها ثم توقّع؛ اكتمالها ← براءة الذمة F-04 */
export function ClearancePanel({ r, mark, sign, custodyHref }: { r: ExitFull; mark: (section: string, item: string, body: Record<string, unknown>) => Promise<unknown>
  sign: (section: string) => Promise<unknown>; custodyHref?: (id: number) => string }) {
  const L = useL()
  if (!r.clearance) return null
  const done = r.clearance.filter(s => s.signed).length
  return <Panel title={L('تسليم العهدة وبراءة الذمة', 'Clearance & custody handover')} sub={`HR.01-03-F-07 · ${L(`${done} من ${r.clearance.length} جهات`, `${done} of ${r.clearance.length} sections`)}${r.clearance_no ? ` · ${L('براءة ذمة', 'Certificate')} ${r.clearance_no}` : ''}`}>
    <div className="grid [grid-template-columns:repeat(auto-fill,minmax(300px,1fr))] gap-3">{r.clearance.map(s => <SectionCard key={s.key} s={s} editable={s.can_sign && !s.signed && r.status === 'clearance'} mark={mark} sign={sign} custodyHref={custodyHref} />)}</div>
  </Panel>
}

function SectionCard({ s, editable, mark, sign, custodyHref }: { s: ClearanceSection; editable: boolean; mark: (section: string, item: string, body: Record<string, unknown>) => Promise<unknown>; sign: (section: string) => Promise<unknown>; custodyHref?: (id: number) => string }) {
  const L = useL(); const lang = useLang()
  const [pick, setPick] = useState<ClearanceItem | null>(null)
  const stateText = (i: ClearanceItem) => i.state === 'done' ? (i.amount && i.value ? money(i.value, 'USD', lang) : L('تم', 'Done')) : i.state === 'none' ? L('لا يوجد', 'None') : i.state === 'missing' ? L('لم يُسلَّم', 'Missing') : L('بانتظار', 'Pending')
  return <div className={`rounded-xl border p-3 ${s.signed ? 'border-brand/40 bg-brand-soft/20' : 'border-line'}`}>
    <div className="flex items-start justify-between gap-2 mb-2"><b className="text-[14px]">{lang === 'ar' ? s.ar : s.en}</b>{s.signed ? <Badge tone="brand">{L('وُقّعت', 'Signed')}</Badge> : null}</div>
    <ul className="space-y-1.5">{s.items.map(i => <li key={i.key} className="text-[13px]">
      <div className="flex items-start justify-between gap-2">
        <span className="flex-1">{lang === 'ar' ? i.ar : i.en}{i.auto && <span className="text-[11px] text-info"> · {L('من النظام', 'auto')}</span>}</span>
        {editable && !(i.auto === 'handover' || i.auto === 'assets') ? <button className={`shrink-0 rounded-md px-2 py-0.5 text-[12px] font-semibold ${i.state === 'missing' ? 'bg-danger/15 text-danger' : i.state ? 'bg-brand-soft text-brand' : 'bg-sub text-ink-2'}`} onClick={() => setPick(i)}>{stateText(i)}</button>
          : <span className={`shrink-0 text-[12px] ${i.state === 'missing' ? 'text-danger' : i.state ? 'text-brand' : 'text-ink-3'}`}>{stateText(i)}</span>}
      </div>
      {i.note && <div className="text-[12px] text-ink-3">«{i.note}»</div>}
      {i.pending && i.pending.length > 0 && <ul className="mt-1 space-y-0.5">{i.pending.map(a => <li key={a.id} className="text-[12px] text-warn flex justify-between gap-2">
        <span>{bi(a.name, lang)} <span className="ltr inline-block text-ink-3">{a.tag}</span></span>{custodyHref && <a className="text-brand font-semibold shrink-0" href={custodyHref(a.id)}>{L('استرجاع', 'Return')}</a>}</li>)}</ul>}
      {i.balance && <div className="text-[12px] text-ink-3 num">{L(`المستحق ${i.balance.entitlement} · المستخدم ${i.balance.used} · المتبقي ${i.balance.remaining}`, `Earned ${i.balance.entitlement} · used ${i.balance.used} · left ${i.balance.remaining}`)}</div>}
    </li>)}</ul>
    <div className="mt-2 pt-2 border-t border-line-2 text-[12px] text-ink-3">{s.signed ? signedBy(s.signed, lang) : editable ? <Btn size="sm" kind="primary" disabled={!s.ready}
      onClick={() => sign(s.key).then(() => toast(L('وُقّعت الجهة', 'Section signed'))).catch(e => toast(e instanceof ApiError ? e.message : L('تعذّر التوقيع', 'Could not sign')))}>{s.ready ? L('توقيع الجهة', 'Sign section') : L('أشّر كل البنود', 'Mark every item')}</Btn> : L('بانتظار الجهة', 'Awaiting this section')}</div>
    {pick && <ItemModal i={pick} onClose={() => setPick(null)} onSave={b => mark(s.key, pick.key, b)} />}
  </div>
}

function ItemModal({ i, onClose, onSave }: { i: ClearanceItem; onClose: () => void; onSave: (b: Record<string, unknown>) => Promise<unknown> }) {
  const L = useL(); const lang = useLang()
  const [st, setSt] = useState<'done' | 'none' | 'missing'>(i.state ?? 'done')
  const [note, setNote] = useState(i.note ?? ''); const [value, setValue] = useState<number | ''>(i.value ?? '')
  const [err, setErr] = useState('')
  return <Modal open onClose={onClose} title={lang === 'ar' ? i.ar : i.en} width={480} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" onClick={() => onSave({ state: st, note: note || null, value: i.amount ? (value === '' ? 0 : value) : null }).then(onClose).catch(e => setErr(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : L('تعذّر الحفظ', 'Could not save')))}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="space-y-3">
      <div className="flex flex-wrap gap-2">{([['done', i.amount ? L('يوجد — بالمبلغ', 'Yes — amount') : L('تم التسليم', 'Handed over')], ['none', L('لا يوجد', 'None')], ['missing', L('لم يُسلَّم', 'Missing')]] as const).map(([k, t]) =>
        <button key={k} onClick={() => setSt(k)} className={`h-9 px-3 rounded-lg border text-[13px] font-semibold ${st === k ? 'bg-brand text-on-brand border-brand' : 'border-line'}`}>{t}</button>)}</div>
      {i.amount && st === 'done' && <Field label={L('المبلغ ($) — يُقتطع في المخالصة', 'Amount ($) — deducted in the settlement')}><input type="number" min={0} className="field num" value={value} onChange={e => setValue(e.target.value === '' ? '' : Number(e.target.value))} /></Field>}
      <Field label={L('الحالة / ملاحظة', 'Condition / note')} req={st === 'missing'}><input className="field" maxLength={500} value={note} onChange={e => setNote(e.target.value)} /></Field>
      {err && <p role="alert" className="text-[13px] text-danger">{err}</p>}
    </div>
  </Modal>
}

export function SettlementTable({ s }: { s: NonNullable<ExitFull['settlement']> }) {
  const L = useL(); const lang = useLang()
  const m = (n: number) => money(n, s.currency, lang)
  const rows: [string, ReactNode][] = [[L('الأجر اليومي', 'Daily wage'), m(s.daily)], [L(`أجر الشهر الأخير (${s.days} يوماً)`, `Last month (${s.days} days)`), m(s.pro_rata)],
    [L(`بدل رصيد الإجازات (${s.leave_days} يوماً)`, `Unused leave (${s.leave_days} days)`), m(s.leave_pay)]]
  if (s.death_grant) rows.push([L('إعانة الوفاة', 'Death grant'), m(s.death_grant)])
  if (s.assets) rows.push([L('اقتطاع عهد غير مسترجعة', 'Unreturned assets'), `− ${money(s.assets, 'USD', lang)}`])
  if (s.advances) rows.push([L('سلف مفتوحة', 'Open advances'), `− ${m(s.advances)}`])
  if (s.finance) rows.push([L('ذمم المالية (F-07)', 'Finance dues (F-07)'), `− ${m(s.finance)}`])
  if (s.notice_pay) rows.push([L('بدل الإشعار (مخالفة فترة الإخطار)', 'Notice pay (notice not served)'), `− ${m(s.notice_pay)}`])
  return <table className="w-full border border-line text-[14px]"><tbody>
    {rows.map(([k, v]) => <tr key={k}><td className="border border-line px-3 py-2 bg-sub">{k}</td><td className="border border-line px-3 py-2 num">{v}</td></tr>)}
    <tr><td className="border border-ink px-3 py-2 font-bold">{L('صافي المستحقات', 'Net amount due')}</td><td className="border border-ink px-3 py-2 font-bold num">{m(s.total)}</td></tr>
  </tbody></table>
}

/** نماذج الطباعة: F-02 / F-03 / F-04 / F-05 / F-06 / F-07 / F-33 */
export type PrintForm = 'f02' | 'f03' | 'f04' | 'f05' | 'f06' | 'f07' | 'f33'
export function ExitDoc({ r, form, company, lists }: { r: ExitFull; form: PrintForm; company: Bi; lists?: { reasons?: Item[]; causes?: Item[]; dismissal_reasons?: Item[] } }) {
  const L = useL(); const lang = useLang()
  const e = r.employee!
  const name = bi(e.name, lang)
  const he = e.gender === 'F'
  const head = <div className="grid sm:grid-cols-2 gap-x-6 gap-y-1 text-[14px] mb-4">
    <span>{L('اسم الموظف', 'Employee')}: <b>{name}</b></span><span>{L('الرقم الوظيفي', 'Employee ID')}: <b className="ltr inline-block">{e.code}</b></span>
    <span>{L('المسمى', 'Title')}: <b>{bi(e.title, lang)}</b></span><span>{L('القسم', 'Department')}: <b>{e.dept ? bi(e.dept, lang) : '—'}</b></span>
    <span>{L('تاريخ التعيين', 'Hire date')}: <b className="num">{e.hire_date ? fmtDate(e.hire_date, lang) : '—'}</b></span><span>{L('آخر يوم عمل', 'Last day')}: <b className="num">{fmtDate(r.last_day, lang)}</b></span>
  </div>
  const stepSig = (i: number) => r.steps[i] ? <SigBox label={bi(r.steps[i].role, lang)} name={r.steps[i].by ? bi(r.steps[i].by!, lang) : undefined} at={r.steps[i].status === 'approved' ? r.steps[i].at ?? undefined : undefined} /> : null
  if (form === 'f02') return <Paper code="HR.01-03-F-02" title={L('طلب استقالة', 'Resignation request')} subject={r.ref_no ?? undefined}>{head}
    <p className="text-[15px] leading-8 bg-canvas rounded-md p-3 whitespace-pre-wrap">{r.text}</p>
    <div className="text-[13.5px] mt-3">{L('الأسباب', 'Reasons')}: {r.reasons.map(x => bi(x, lang)).join('، ')}</div>
    <p className="text-[13px] text-ink-2 mt-3">{L('أقرّ بالتزامي بسرية المعلومات وميثاق السلوك المهني خلال فترة الإخطار وبعدها.', 'I commit to confidentiality and the code of conduct during and after the notice period.')}</p>
    <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-5"><SigBox label={L('الموظف', 'Employee')} name={name} at={r.created_at} />{stepSig(0)}{stepSig(1)}{stepSig(2)}</div>
  </Paper>
  if (form === 'f03') { const t = r.termination
    return <Paper code="HR.01-03-F-03" title={L('إنهاء عقد', 'Contract termination')} subject={r.ref_no ?? undefined}>{head}
      <div className="space-y-2 text-[14px]">
        <div>{L('نوع التغيير', 'Type')}: <b>{t ? label(lists?.causes ?? [], t.cause, lang) : ''}</b></div>
        {!!t?.reasons.length && <div>{L('أسباب الفصل', 'Dismissal reasons')}: <b>{t.reasons.map(k => label(lists?.dismissal_reasons ?? [], k, lang)).join('، ')}</b>{t.other ? ` — ${t.other}` : ''}</div>}
        <div>{L('مؤهل للتعيين مرة أخرى', 'Eligible for rehire')}: <b>{r.rehire === null ? '—' : r.rehire ? L('نعم', 'Yes') : L('لا', 'No')}</b>{r.rehire_reason ? ` — ${r.rehire_reason}` : ''}</div>
        <p className="leading-8 bg-canvas rounded-md p-3 whitespace-pre-wrap">{r.text}</p>
      </div>
      <div className="grid sm:grid-cols-3 gap-3 mt-5">{stepSig(0)}{stepSig(1)}{stepSig(2)}</div>
    </Paper> }
  if (form === 'f04') return <Paper code="HR.01-03-F-04" title={L('براءة ذمة', 'Clearance certificate')} sub={L('إلى من يهمه الأمر', 'To whom it may concern')} subject={r.clearance_no ?? undefined}>
    <div className="flex justify-between text-[13px] text-ink-2 mb-5"><span>{L('الرقم', 'No.')}: <b className="ltr inline-block text-ink">{r.clearance_no}</b></span><span>{L('التاريخ', 'Date')}: <b className="num text-ink">{fmtDate(r.clearance_issued_at ?? undefined, lang)}</b></span></div>
    <p className="text-[15px] leading-9">{L(`تشهد ${company.ar} بأن ${he ? 'السيدة' : 'السيد'} ${e.name.ar} (${e.code})، ${he ? 'التي كانت تعمل' : 'الذي كان يعمل'} لديها بوظيفة ${e.title.ar} حتى ${fmtDate(r.last_day, 'ar')}، قد ${he ? 'أبرأت' : 'أبرأ'} ذمته تجاه الشركة بتسليم كافة العهد والمهام والمستندات، ولا يترتب ${he ? 'عليها' : 'عليه'} أي التزام مالي أو عيني حسب سجلات الجهات المعنية. ولا تُعفي هذه الشهادة من المسؤولية عن أي التزام يظهر لاحقاً.`,
      `${company.en} certifies that ${name} (${e.code}), employed as ${bi(e.title, 'en')} until ${fmtDate(r.last_day, 'en')}, has returned all custody, tasks and documents and owes the company nothing according to the records of the departments concerned. This certificate does not waive liability for any obligation discovered later.`)}</p>
    <div className="mt-10 flex justify-between items-end gap-4"><span className="text-[13px] text-ink-3">{L('الموارد البشرية', 'Human Resources')}</span><span className="stamp text-brand text-[12px]">{bi(company, lang)}</span></div>
  </Paper>
  if (form === 'f06') return <Paper code="HR.01-03-F-06" title={L('شهادة خبرة / خدمة', 'Experience / service certificate')} subject={r.service_cert_no ?? undefined}>
    <div className="flex justify-between text-[13px] text-ink-2 mb-5"><span>{L('الرقم المرجعي', 'Ref.')}: <b className="ltr inline-block text-ink">{r.service_cert_no}</b></span><span className="num">{fmtDate(r.service_cert_at ?? undefined, lang)}</span></div>
    <div className="grid md:grid-cols-2 gap-6 text-[14.5px] leading-8">
      <p dir="rtl">تشهد {company.ar} بأن {he ? 'السيدة' : 'السيد'} <b>{e.name.ar}</b> {he ? 'عملت' : 'عمل'} لديها بوظيفة <b>{e.title.ar}</b>{e.dept ? ` في ${e.dept.ar}` : ''} من <b className="num">{e.hire_date ? fmtDate(e.hire_date, 'ar') : '—'}</b> حتى <b className="num">{fmtDate(r.last_day, 'ar')}</b>، وقد أُعطيت هذه الشهادة بناءً على طلب{he ? 'ها' : 'ه'} دون أدنى مسؤولية على الشركة.</p>
      <p dir="ltr" className="text-start">{company.en} certifies that <b>{e.name.en}</b> worked as <b>{e.title.en}</b>{e.dept ? ` in ${e.dept.en}` : ''} from <b className="num">{e.hire_date ? fmtDate(e.hire_date, 'en') : '—'}</b> to <b className="num">{fmtDate(r.last_day, 'en')}</b>. This certificate is issued at the employee’s request without any liability on the company.</p>
    </div>
    <div className="mt-10 flex justify-between items-end gap-4"><span className="text-[13px] text-ink-3">{L('الموارد البشرية', 'Human Resources')}</span><span className="stamp text-brand text-[12px]">{bi(company, lang)}</span></div>
  </Paper>
  if (form === 'f05') { const h = r.handover
    return <Paper code="HR.01-03-F-05" title={L('تسليم مهام', 'Handover of duties')} subject={r.ref_no ?? undefined}>{head}
      <table className="w-full border border-line text-[13px]"><thead><tr className="bg-sub">{[L('المهمة', 'Task'), L('الحالة', 'Status'), L('الملفات', 'Files'), L('النوع', 'Type'), L('المتبقي', 'Remaining'), L('مكان الحفظ', 'Location')].map(x => <th key={x} className="border border-line px-2 py-1.5 text-start">{x}</th>)}</tr></thead>
        <tbody>{(h?.tasks ?? []).map((t, i) => <tr key={i}>{[t.task, t.status === 'done' ? L('منجز', 'Done') : t.status === 'in_progress' ? L('قيد التنفيذ', 'In progress') : L('مؤجل', 'Postponed'), t.files, t.type === 'paper' ? L('ورقي', 'Paper') : t.type ? L('إلكتروني', 'Electronic') : '', t.remaining, t.location]
          .map((x, j) => <td key={j} className="border border-line px-2 py-1.5 break-words">{x ?? ''}</td>)}</tr>)}</tbody></table>
      <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-5"><SigBox label={L('تعهد المسلِّم', 'Handing over')} name={name} at={h?.employee?.at} /><SigBox label={L('إقرار المستلم', 'Receiver')} name={h?.receiver ? bi(h.receiver, lang) : undefined} at={h?.receiver_signed?.at} />
        <SigBox label={L('المدير', 'Manager')} name={h?.manager ? (lang === 'ar' ? h.manager.by : h.manager.by_en) : undefined} at={h?.manager?.at} /><SigBox label={L('الموارد البشرية', 'HR')} name={h?.hr ? (lang === 'ar' ? h.hr.by : h.hr.by_en) : undefined} at={h?.hr?.at} /></div>
    </Paper> }
  if (form === 'f07') return <Paper code="HR.01-03-F-07" title={L('تسليم عهدة موظف', 'Employee custody handover')} subject={r.ref_no ?? undefined}>{head}
    {(r.clearance ?? []).map(s => <div key={s.key} className="mb-3"><div className="font-semibold text-[14px] mb-1">{lang === 'ar' ? s.ar : s.en}</div>
      <table className="w-full border border-line text-[13px]"><tbody>{s.items.map(i => <tr key={i.key}><td className="border border-line px-2 py-1">{lang === 'ar' ? i.ar : i.en}</td>
        <td className="border border-line px-2 py-1 w-40">{i.state === 'done' ? (i.amount && i.value ? money(i.value, 'USD', lang) : L('يوجد / تم', 'Yes / done')) : i.state === 'none' ? L('لا يوجد', 'None') : i.state === 'missing' ? L('لم يُسلَّم', 'Missing') : ''}{i.note ? ` — ${i.note}` : ''}</td></tr>)}
        <tr><td colSpan={2} className="border border-line px-2 py-1 text-[12px] text-ink-3">{L('التوقيع والتاريخ', 'Signature & date')}: {signedBy(s.signed, lang) ?? ''}</td></tr></tbody></table></div>)}
  </Paper>
  return <Paper code="F-33" title={L('المخالصة النهائية وإنهاء الخدمة', 'Final settlement & end of service')} sub={L('حساب تقديري وفق بيانات النظام — يُراجع من الإدارة المالية', 'Estimate from system data — reviewed by Finance')} subject={r.ref_no ?? undefined}>{head}
    {r.settlement ? <SettlementTable s={r.settlement} /> : r.settlement_masked ? <div data-noprint><ProtectedBlock masked>{null}</ProtectedBlock></div> : null}
    <div className="grid sm:grid-cols-3 gap-3 mt-5"><SigBox label={L('الموظف', 'Employee')} name={name} /><SigBox label={L('الموارد البشرية', 'HR')} at={r.closed_at ?? undefined} /><SigBox label={L('الإدارة المالية', 'Finance')} /></div>
    {r.status === 'closed' && <div className="mt-4"><span className="stamp text-danger">{L('انتهت الخدمة', 'SERVICE ENDED')} — {fmtDate(r.last_day, lang)}</span></div>}
  </Paper>
}

export function PrintLinks({ r, href }: { r: ExitFull; href: (f: PrintForm) => string }) {
  const L = useL()
  const forms: [PrintForm, string, boolean][] = [['f02', L('طلب الاستقالة F-02', 'Resignation F-02'), r.kind === 'resignation'], ['f03', L('إنهاء العقد F-03', 'Termination F-03'), r.kind === 'termination'],
    ['f05', L('تسليم المهام F-05', 'Handover F-05'), !!r.handover?.tasks.length], ['f07', L('تسليم العهدة F-07', 'Custody F-07'), !!r.clearance], ['f04', L('براءة الذمة F-04', 'Clearance F-04'), !!r.clearance_no],
    ['f06', L('شهادة الخدمة F-06', 'Service certificate F-06'), !!r.service_cert_no], ['f33', L('المخالصة F-33', 'Settlement F-33'), !!r.settlement || !!r.settlement_masked]]
  return <div className="flex flex-wrap gap-x-4 gap-y-1">{forms.filter(f => f[2]).map(([k, t]) => <a key={k} href={href(k)} className="inline-flex items-center gap-1 text-brand text-[13px] font-semibold"><Printer size={13} />{t}</a>)}</div>
}

