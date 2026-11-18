import { useState } from 'react'
import { Check, Loader2, Plus, Star, X } from 'lucide-react'
import { useL, useLang, bi, fmtDate, money } from '../../lib/store'
import { setQuery, useRoute } from '../../lib/router'
import { fileUrl } from '../../lib/console'
import { ASG_STATUS, DLV, PAY, TEST_Q, useAssignment, useAssignments, useExternals, useExtAct, useExtReports, usePayments, type AssignmentRow, type Lookups } from '../../lib/externals'
import { Badge, Btn, Drawer, Empty, Field, KV, Modal, Panel, Seg, toast } from '../../ui/kit'
import { ConfirmSend, DecisionDialog } from '../../ui/decision'
import { FilePick, errOf } from './parts'

const today = () => new Date().toISOString().slice(0, 10)

export function AssignmentsTab() {
  const L = useL(); const lang = useLang()
  const route = useRoute(); const q = route.query
  const [status, setStatus] = useState('open')
  const { data } = useAssignments(status)
  const [add, setAdd] = useState(false)
  return <>
    <div className="flex flex-wrap gap-2 mb-3 items-center"><Seg value={status} onChange={setStatus} options={[{ v: 'open', label: L('الجارية', 'Open') }, { v: 'closed', label: L('المغلقة', 'Closed') }, { v: '', label: L('الكل', 'All') }]} />
      <div className="flex-1" />{data?.can.create && <Btn kind="primary" icon={<Plus size={15} />} onClick={() => setAdd(true)}>{L('طلب خبير', 'Request an expert')}</Btn>}</div>
    {!data ? <Loader2 className="animate-spin text-brand mx-auto" /> : data.data.length === 0 ? <Panel><Empty title={L('لا توجد تكليفات', 'No assignments')}>{L('ابدأ بطلب خبير وشروطه المرجعية، ثم اختر من القائمة.', 'Start with an expert request and its terms of reference, then choose from the roster.')}</Empty></Panel> :
      <Panel pad={false}><table className="tbl"><thead><tr><th>{L('التكليف', 'Assignment')}</th><th className="hidden sm:table-cell">{L('الخبير', 'Expert')}</th><th className="hidden md:table-cell">{L('المخرجات', 'Deliverables')}</th><th>{L('الحالة', 'Status')}</th></tr></thead>
        <tbody>{data.data.map(a => <tr key={a.id} className="click" onClick={() => setQuery({ a: String(a.id) })}>
          <td><div className="font-medium">{a.title}</div><div className="text-[12px] text-ink-3"><span className="ltr inline-block">{a.ref}</span>{a.starts_on ? ` · ${fmtDate(a.starts_on, lang)}` : ''}</div></td>
          <td className="hidden sm:table-cell">{a.expert ? bi(a.expert, lang) : <span className="text-ink-3">{L(`${a.shortlist.length} في القائمة المختصرة`, `${a.shortlist.length} shortlisted`)}</span>}</td>
          <td className="hidden md:table-cell num">{a.deliverables.filter(d => d.status === 'approved').length}/{a.deliverables.length}</td>
          <td><Badge tone={ASG_STATUS[a.status]?.[2] ?? 'neutral'}>{ASG_STATUS[a.status] ? L(ASG_STATUS[a.status][0], ASG_STATUS[a.status][1]) : a.status}</Badge></td></tr>)}</tbody></table></Panel>}
    {add && data && <TorForm lk={data.lookups} onClose={() => setAdd(false)} />}
    {q.a && data && <AssignmentDrawer id={Number(q.a)} lk={data.lookups} fees={data.can.fees} onClose={() => setQuery({ a: undefined })} />}
  </>
}

function TorForm({ lk, a, onClose }: { lk: Lookups; a?: AssignmentRow; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const act = useExtAct()
  const [v, setV] = useState({ title: a?.title ?? '', tor: a?.tor ?? { objective: '', outputs: '', place: '', specialties: [] as number[] }, department_id: a?.department_id ?? null, starts_on: a?.starts_on ?? '', ends_on: a?.ends_on ?? '', budget: a?.budget ?? null, currency: a?.currency ?? 'USD' })
  const e = errOf(act.error)
  return <Modal open onClose={onClose} width={680} title={L('طلب خبير — الشروط المرجعية', 'Expert request — terms of reference')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={act.isPending || !v.title || v.tor.objective.length < 10} onClick={() => act.mutate({ path: a ? `assignments/${a.id}` : 'assignments', method: a ? 'PUT' : 'POST', v: { ...v, starts_on: v.starts_on || null, ends_on: v.ends_on || null } }, { onSuccess: r => { toast(L('حُفظ الطلب', 'Request saved')); onClose(); if (!a && (r as { id?: number }).id) setQuery({ a: String((r as { id: number }).id) }) } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('العنوان', 'Title')} req className="sm:col-span-2" error={e?.field('title')}><input className="field" value={v.title} onChange={x => setV({ ...v, title: x.target.value })} /></Field>
      <Field label={L('الهدف', 'Objective')} req className="sm:col-span-2" error={e?.field('tor.objective')}><textarea className="field" rows={3} value={v.tor.objective} onChange={x => setV({ ...v, tor: { ...v.tor, objective: x.target.value } })} /></Field>
      <Field label={L('المخرجات المتوقعة', 'Expected outputs')} className="sm:col-span-2"><textarea className="field" rows={2} value={v.tor.outputs} onChange={x => setV({ ...v, tor: { ...v.tor, outputs: x.target.value } })} /></Field>
      <Field label={L('التخصصات المطلوبة', 'Required specialties')} className="sm:col-span-2"><select multiple className="field !h-24" value={v.tor.specialties.map(String)} onChange={x => setV({ ...v, tor: { ...v.tor, specialties: [...x.target.selectedOptions].map(o => Number(o.value)) } })}>
        {lk.specialties.map(s => <option key={s.id} value={s.id}>{s.parent_id ? '— ' : ''}{bi(s, lang)}</option>)}</select></Field>
      <Field label={L('القسم الطالب', 'Requesting department')}><select className="field" value={v.department_id ?? ''} onChange={x => setV({ ...v, department_id: x.target.value ? Number(x.target.value) : null })}><option value="">—</option>{lk.departments.map(d => <option key={d.id} value={d.id}>{bi(d, lang)}</option>)}</select></Field>
      <Field label={L('مكان التنفيذ', 'Place')}><input className="field" value={v.tor.place} onChange={x => setV({ ...v, tor: { ...v.tor, place: x.target.value } })} /></Field>
      <Field label={L('من', 'From')}><input type="date" className="field" value={v.starts_on} onChange={x => setV({ ...v, starts_on: x.target.value })} /></Field>
      <Field label={L('إلى', 'To')}><input type="date" className="field" value={v.ends_on} min={v.starts_on} onChange={x => setV({ ...v, ends_on: x.target.value })} /></Field>
      <Field label={L('الموازنة', 'Budget')}><div className="flex gap-2"><input className="field num" value={v.budget ?? ''} onChange={x => setV({ ...v, budget: x.target.value === '' ? null : Number(x.target.value) })} />
        <select className="field !w-24 ltr" value={v.currency} onChange={x => setV({ ...v, currency: x.target.value })}>{lk.currencies.map(c => <option key={c.code}>{c.code}</option>)}</select></div></Field>
    </div>
  </Modal>
}

function AssignmentDrawer({ id, lk, fees, onClose }: { id: number; lk: Lookups; fees: boolean; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const { data: a } = useAssignment(id)
  const act = useExtAct()
  const roster = useExternals({ tab: 'experts' })
  const [pick, setPick] = useState(''); const [sel, setSel] = useState<number | null>(null); const [reason, setReason] = useState(''); const [confirm, setConfirm] = useState(false)
  const [contract, setContract] = useState(false); const [rate, setRate] = useState(false); const [cancel, setCancel] = useState(false); const [rev, setRev] = useState<{ id: number; ok: boolean } | null>(null); const [edit, setEdit] = useState(false)
  if (!a) return <Drawer open onClose={onClose}><div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div></Drawer>
  const spec = new Map(lk.specialties.map(s => [s.id, s]))
  const shortIds = a.shortlist.map(s => s.external_id)
  const setShort = (ids: number[]) => act.mutate({ path: `assignments/${a.id}/shortlist`, v: { items: ids.map(i => ({ external_id: i, note: a.shortlist.find(s => s.external_id === i)?.note ?? null })) } })
  const e = errOf(act.error)
  return <Drawer open onClose={onClose} width={760}>
    <div className="p-5 space-y-4">
      <div className="flex items-start justify-between gap-3"><div><h2 className="text-[20px] font-bold">{a.title}</h2><div className="text-[13px] text-ink-3"><span className="ltr inline-block">{a.ref}</span>{a.starts_on ? ` · ${fmtDate(a.starts_on, lang)} — ${a.ends_on ? fmtDate(a.ends_on, lang) : ''}` : ''}</div></div>
        <Badge tone={ASG_STATUS[a.status]?.[2] ?? 'neutral'}>{ASG_STATUS[a.status] ? L(ASG_STATUS[a.status][0], ASG_STATUS[a.status][1]) : a.status}</Badge></div>
      <Panel title={L('الشروط المرجعية', 'Terms of reference')} action={['request', 'shortlist'].includes(a.status) && <Btn size="sm" kind="ghost" onClick={() => setEdit(true)}>{L('تعديل', 'Edit')}</Btn>}>
        <p className="text-[13.5px] whitespace-pre-line">{a.tor.objective}</p>
        {a.tor.outputs && <p className="text-[13px] text-ink-2 mt-2 whitespace-pre-line">{L('المخرجات', 'Outputs')}: {a.tor.outputs}</p>}
        <div className="flex flex-wrap gap-1 mt-2">{a.tor.specialties.map(s => spec.get(s) && <span key={s} className="text-[11.5px] rounded-md bg-sub px-1.5 py-0.5">{bi(spec.get(s)!, lang)}</span>)}</div>
        {fees && (a.budget != null || a.total_fee != null) && <div className="mt-2 text-[13px]">{a.budget != null && <>{L('الموازنة', 'Budget')}: <b className="num">{money(a.budget, a.currency, lang)}</b></>}{a.total_fee != null && <> · {L('الأتعاب', 'Fee')}: <b className="num">{money(a.total_fee, a.currency, lang)}</b></>}</div>}
      </Panel>
      {['request', 'shortlist'].includes(a.status) && <Panel title={L('القائمة المختصرة والاختيار', 'Shortlist & selection')} sub={L(`الاختيار من المعتمدين في القائمة فقط، ويُرشَّح أكثر من خبير (${lk.cfg.shortlist_min} على الأقل) مع توثيق سبب الاختيار.`, `Only approved roster experts; shortlist more than one (at least ${lk.cfg.shortlist_min}) and document the reason.`)}>
        {a.suggested && a.suggested.length > 0 && <div className="mb-3"><div className="text-[12.5px] text-ink-3 mb-1">{L('مقترحون من الشروط المرجعية', 'Suggested from the terms')}</div>
          <div className="flex flex-wrap gap-1.5">{a.suggested.filter(s => !shortIds.includes(s.id)).map(s => <button key={s.id} onClick={() => setShort([...shortIds, s.id])} className="text-[12.5px] rounded-lg border border-line px-2 py-1 hover:bg-sub">+ {bi(s, lang)}{s.avg_rating != null ? ` ★${s.avg_rating}` : ''}</button>)}</div></div>}
        <div className="flex gap-2 mb-3"><select className="field" value={pick} onChange={x => setPick(x.target.value)}><option value="">{L('إضافة من القائمة…', 'Add from the roster…')}</option>
          {roster.data?.data.filter(r => !shortIds.includes(r.id)).map(r => <option key={r.id} value={r.id}>{bi(r, lang)}{r.avg_rating != null ? ` ★${r.avg_rating}` : ''}</option>)}</select>
          <Btn disabled={!pick} onClick={() => { setShort([...shortIds, Number(pick)]); setPick('') }}>{L('إضافة', 'Add')}</Btn></div>
        {a.shortlist.length === 0 ? <p className="text-[13px] text-ink-3">{L('القائمة المختصرة فارغة.', 'The shortlist is empty.')}</p> : <div className="space-y-1.5">{a.shortlist.map(s => <label key={s.external_id} className={`flex items-center justify-between gap-2 rounded-lg border p-2 ${sel === s.external_id ? 'border-brand bg-brand/5' : 'border-line'}`}>
          <span className="flex items-center gap-2"><input type="radio" name="pick" checked={sel === s.external_id} onChange={() => setSel(s.external_id)} />{bi(s, lang)}{s.rating != null && <span className="text-[12px] text-ink-3"> ★{s.rating}</span>}</span>
          <button type="button" className="text-ink-3 hover:text-danger" onClick={() => setShort(shortIds.filter(i => i !== s.external_id))} aria-label={L('إزالة', 'Remove')}><X size={14} /></button></label>)}</div>}
        {a.shortlist.length > 0 && <div className="mt-3 space-y-2">
          <Field label={L('سبب الاختيار', 'Reason for the choice')} req error={e?.field('reason')}><textarea className="field" rows={2} value={reason} onChange={x => setReason(x.target.value)} /></Field>
          {e?.code === 'overlap' && <label className="flex items-center gap-2 text-[13px] text-warn"><input type="checkbox" checked={confirm} onChange={x => setConfirm(x.target.checked)} />{e.message}</label>}
          <Btn kind="primary" disabled={!sel || reason.trim().length < 5 || act.isPending} onClick={() => act.mutate({ path: `assignments/${a.id}/select`, v: { external_id: sel, reason, confirm } })}>{L('اختيار الخبير', 'Select the expert')}</Btn></div>}
      </Panel>}
      {a.expert && <Panel title={L('الخبير المختار', 'Selected expert')}><KV k={L('الاسم', 'Name')} v={bi(a.expert, lang)} />{a.selection_reason && <KV k={L('سبب الاختيار', 'Reason')} v={a.selection_reason} />}
        {a.pledges && <KV k={L('التعهدات', 'Pledges')} v={L('وُقّع تعهدا السرية وعدم تضارب المصالح', 'Confidentiality and conflict-of-interest signed')} />}
        {a.pledges?.conflict_note && <p className="text-[13px] text-warn mt-1">{L('إفصاح عن تضارب', 'Disclosed conflict')}: {a.pledges.conflict_note}</p>}
        {a.relation_score > 0 && <KV k={L('فحص طبيعة العلاقة', 'Relationship check')} v={`${a.relation_score} / 6`} />}
        <div className="flex flex-wrap gap-1.5 mt-2">
          {a.status === 'selected' && fees && <Btn size="sm" kind="primary" onClick={() => setContract(true)}>{L('العقد والمخرجات', 'Contract & deliverables')}</Btn>}
          {a.status === 'contracted' && <ConfirmSend label={L('تسجيل التعهدات ورقياً', 'Record pledges on paper')} onConfirm={() => act.mutateAsync({ path: `assignments/${a.id}/pledge`, v: { kinds: ['confidentiality', 'conflict'] } })} />}
          {['in_progress', 'delivered'].includes(a.status) && <Btn size="sm" onClick={() => setRate(true)}>{L('التقييم والإغلاق', 'Rate & close')}</Btn>}
          {['request', 'shortlist', 'selected', 'contracted'].includes(a.status) && <Btn size="sm" kind="danger" onClick={() => setCancel(true)}>{L('إلغاء التكليف', 'Cancel')}</Btn>}
        </div></Panel>}
      {a.deliverables.length > 0 && <Panel title={L('المخرجات والدفعات', 'Deliverables & payments')} pad={false}><table className="tbl"><tbody>{a.deliverables.map(d => <tr key={d.id}>
        <td><div className="font-medium">{d.title}</div><div className={`text-[12px] num ${d.late ? 'text-danger' : 'text-ink-3'}`}>{d.due_on ? fmtDate(d.due_on, lang) : ''}{d.note ? ` · ${d.note}` : ''}</div></td>
        <td className="num">{d.payment != null ? money(d.payment, a.currency, lang) : '—'}</td>
        <td><Badge tone={DLV[d.status][2]}>{L(DLV[d.status][0], DLV[d.status][1])}</Badge></td>
        <td><div className="flex justify-end gap-1">{d.file_id && <a className="text-brand text-[13px] self-center" href={fileUrl(d.file_id)} target="_blank" rel="noreferrer">{L('الملف', 'File')}</a>}
          {['pending', 'returned'].includes(d.status) && ['in_progress', 'delivered'].includes(a.status) && <FilePick label={L('تسليم', 'Submit')} onDone={f => act.mutate({ path: `deliverables/${d.id}/submit`, v: { file_id: f.id } })} />}
          {d.status === 'submitted' && <><Btn size="sm" kind="primary" icon={<Check size={13} />} onClick={() => setRev({ id: d.id, ok: true })}>{L('اعتماد', 'Approve')}</Btn><Btn size="sm" kind="danger" onClick={() => setRev({ id: d.id, ok: false })}>{L('إعادة', 'Return')}</Btn></>}</div></td></tr>)}</tbody></table>
        <p className="px-4 py-2 text-[12px] text-ink-3">{L('اعتماد المخرج يولّد طلب دفع للمالية بقيمة دفعته — خارج مسيّر الرواتب.', 'Approving a deliverable creates a payment request for finance — outside payroll.')}</p></Panel>}
      {a.rating && <Panel title={L('التقييم', 'Rating')}><div className="text-[14px]"><Star size={14} className="inline text-brass" /> <b className="num">{a.rating.score}</b> / 5 · {L('الجودة', 'Quality')} {a.rating.quality} · {L('المواعيد', 'Timeliness')} {a.rating.timeliness} · {L('التواصل', 'Communication')} {a.rating.communication} · {L('القيمة', 'Value')} {a.rating.value}</div>
        <div className="text-[13px] text-ink-3">{a.rating.rehire ? L('يُوصى بإعادة التكليف', 'Recommended for rehire') : L('لا يُوصى بإعادة التكليف', 'Not recommended for rehire')}{a.rating.notes ? ` · ${a.rating.notes}` : ''}</div></Panel>}
    </div>
    {edit && <TorForm lk={lk} a={a} onClose={() => setEdit(false)} />}
    {contract && <ContractModal a={a} lk={lk} onClose={() => setContract(false)} />}
    {rate && <RateModal a={a} onClose={() => setRate(false)} />}
    {cancel && <DecisionDialog open onClose={() => setCancel(false)} tone="danger" reasonRequired title={L('إلغاء التكليف', 'Cancel the assignment')} confirmLabel={L('إلغاء التكليف', 'Cancel')} onConfirm={r => act.mutateAsync({ path: `assignments/${a.id}/cancel`, v: { reason: r } }).then(() => setCancel(false))} />}
    {rev && <DecisionDialog open onClose={() => setRev(null)} tone={rev.ok ? 'primary' : 'danger'} reasonRequired={!rev.ok} title={rev.ok ? L('اعتماد المخرج', 'Approve the deliverable') : L('إعادة المخرج بملاحظات', 'Return with notes')}
      confirmLabel={rev.ok ? L('اعتماد', 'Approve') : L('إعادة', 'Return')} onConfirm={note => act.mutateAsync({ path: `deliverables/${rev.id}/review`, v: { approve: rev.ok, note } }).then(() => setRev(null))} />}
  </Drawer>
}

function ContractModal({ a, lk, onClose }: { a: AssignmentRow; lk: Lookups; onClose: () => void }) {
  const L = useL()
  const act = useExtAct()
  const [v, setV] = useState({ total_fee: a.budget ?? 0, currency: a.currency, tax_treatment: '', file_id: null as number | null, relation_test: {} as Record<string, boolean>, deliverables: [{ title: '', due_on: a.ends_on ?? '', payment: a.budget ?? 0 }] })
  const e = errOf(act.error)
  const sum = v.deliverables.reduce((s, d) => s + (Number(d.payment) || 0), 0)
  return <Modal open onClose={onClose} width={720} title={L('العقد المؤقت والمخرجات', 'Temporary contract & deliverables')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><ConfirmSend label={L('حفظ العقد', 'Save contract')} disabled={!v.deliverables.every(d => d.title) || Math.abs(sum - v.total_fee) > 0.01} onConfirm={() => act.mutateAsync({ path: `assignments/${a.id}/contract`, v }).then(() => { toast(L('حُفظ العقد — بانتظار تعهدات الخبير', 'Saved — awaiting the expert’s pledges')); onClose() })} /></>}>
    <div className="grid sm:grid-cols-3 gap-3">
      <Field label={L('الأتعاب الإجمالية', 'Total fee')} error={e?.field('total_fee')}><input className="field num" value={v.total_fee} onChange={x => setV({ ...v, total_fee: Number(x.target.value) || 0 })} /></Field>
      <Field label={L('العملة', 'Currency')}><select className="field ltr" value={v.currency} onChange={x => setV({ ...v, currency: x.target.value })}>{lk.currencies.map(c => <option key={c.code}>{c.code}</option>)}</select></Field>
      <Field label={L('المعاملة الضريبية', 'Tax treatment')}><input className="field" value={v.tax_treatment} onChange={x => setV({ ...v, tax_treatment: x.target.value })} /></Field>
    </div>
    <div className="mt-3 space-y-2">{v.deliverables.map((d, i) => <div key={i} className="grid grid-cols-[1fr_140px_110px_32px] gap-2 items-end">
      <Field label={i === 0 ? L('المخرج', 'Deliverable') : ''}><input className="field" value={d.title} onChange={x => setV({ ...v, deliverables: v.deliverables.map((y, j) => j === i ? { ...y, title: x.target.value } : y) })} /></Field>
      <Field label={i === 0 ? L('الموعد', 'Due') : ''}><input type="date" className="field" value={d.due_on} onChange={x => setV({ ...v, deliverables: v.deliverables.map((y, j) => j === i ? { ...y, due_on: x.target.value } : y) })} /></Field>
      <Field label={i === 0 ? L('الدفعة', 'Payment') : ''}><input className="field num" value={d.payment} onChange={x => setV({ ...v, deliverables: v.deliverables.map((y, j) => j === i ? { ...y, payment: Number(x.target.value) || 0 } : y) })} /></Field>
      <button className="h-10 text-ink-3 hover:text-danger" onClick={() => setV({ ...v, deliverables: v.deliverables.filter((_, j) => j !== i) })} aria-label={L('حذف', 'Remove')} disabled={v.deliverables.length === 1}><X size={15} /></button></div>)}
      <div className="flex justify-between items-center"><Btn size="sm" kind="ghost" icon={<Plus size={13} />} onClick={() => setV({ ...v, deliverables: [...v.deliverables, { title: '', due_on: '', payment: 0 }] })}>{L('مخرج آخر', 'Another deliverable')}</Btn>
        <span className={`text-[13px] num ${Math.abs(sum - v.total_fee) > 0.01 ? 'text-danger' : 'text-ink-3'}`}>{L('مجموع الدفعات', 'Payments total')}: {sum}</span></div></div>
    <div className="mt-3 rounded-lg border border-line p-3"><div className="font-semibold text-[13.5px] mb-1">{L('فحص طبيعة العلاقة', 'Relationship check')}</div>
      {lk.test.map(k => <label key={k} className="flex items-start gap-2 text-[13px] py-0.5"><input type="checkbox" className="mt-1" checked={!!v.relation_test[k]} onChange={x => setV({ ...v, relation_test: { ...v.relation_test, [k]: x.target.checked } })} />{TEST_Q[k] ? L(TEST_Q[k][0], TEST_Q[k][1]) : k}</label>)}</div>
    <div className="mt-3 flex items-center gap-2"><FilePick label={L('العقد الموقّع (اختياري)', 'Signed contract (optional)')} onDone={f => setV({ ...v, file_id: f.id })} />{v.file_id && <span className="text-[13px] text-brand">✓</span>}</div>
    {e && !Object.keys(e.errors).length && <p className="mt-2 text-[13px] text-danger">{e.message}</p>}
  </Modal>
}

function RateModal({ a, onClose }: { a: AssignmentRow; onClose: () => void }) {
  const L = useL()
  const act = useExtAct()
  const [v, setV] = useState({ quality: 4, timeliness: 4, communication: 4, value: 4, rehire: true, notes: '', early: a.status !== 'delivered' })
  const row = (k: 'quality' | 'timeliness' | 'communication' | 'value', label: string) => <div className="flex items-center justify-between gap-2"><span className="text-[13.5px]">{label}</span>
    <div className="flex gap-1">{[1, 2, 3, 4, 5].map(n => <button key={n} type="button" onClick={() => setV({ ...v, [k]: n })} className={`size-8 rounded-lg border text-[13px] num ${v[k] >= n ? 'bg-brass/20 border-brass' : 'border-line'}`}>{n}</button>)}</div></div>
  return <Modal open onClose={onClose} width={520} title={L('تقييم الخبير وإغلاق التكليف', 'Rate the expert & close')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><ConfirmSend label={L('حفظ التقييم والإغلاق', 'Save & close')} onConfirm={() => act.mutateAsync({ path: `assignments/${a.id}/rate`, v }).then(() => { toast(L('أُغلق التكليف', 'Assignment closed')); onClose() })} /></>}>
    <div className="space-y-2">{row('quality', L('الجودة', 'Quality'))}{row('timeliness', L('الالتزام بالمواعيد', 'Timeliness'))}{row('communication', L('التواصل', 'Communication'))}{row('value', L('القيمة مقابل الأتعاب', 'Value for money'))}</div>
    <label className="flex items-center gap-2 text-[13px] mt-3"><input type="checkbox" checked={v.rehire} onChange={x => setV({ ...v, rehire: x.target.checked })} />{L('يُوصى بإعادة التكليف', 'Recommend for rehire')}</label>
    {a.status !== 'delivered' && <p className="text-[12.5px] text-warn mt-2">{L('لم تُعتمد كل المخرجات — يُسجَّل «إنهاء مبكر» وتُلغى المخرجات المتبقية.', 'Not all deliverables are approved — this is recorded as an early end and the rest are dropped.')}</p>}
    <Field label={L('ملاحظات (سرية)', 'Notes (confidential)')}><textarea className="field" rows={2} value={v.notes} onChange={x => setV({ ...v, notes: x.target.value })} /></Field>
  </Modal>
}

export function PaymentsTab() {
  const L = useL(); const lang = useLang()
  const [status, setStatus] = useState('open')
  const { data } = usePayments(status)
  const act = useExtAct()
  const [d, setD] = useState<{ id: number; action: 'pay' | 'reject' } | null>(null); const [ref, setRef] = useState('')
  return <>
    <Seg value={status} onChange={setStatus} options={[{ v: 'open', label: L('المفتوحة', 'Open') }, { v: 'paid', label: L('المدفوعة', 'Paid') }, { v: 'rejected', label: L('المرفوضة', 'Rejected') }]} />
    <p className="text-[12.5px] text-ink-3 my-2">{L('مخرجات خبراء معتمدة وتعويضات مصاريف متطوعين — تُصرف من المالية مباشرة، لا من مسيّر الرواتب.', 'Approved expert deliverables and volunteer expense claims — paid by finance directly, not through payroll.')}</p>
    {!data ? <Loader2 className="animate-spin text-brand mx-auto" /> : data.data.length === 0 ? <Panel><Empty title={L('لا توجد طلبات', 'No requests')} /></Panel> :
      <Panel pad={false}><table className="tbl"><tbody>{data.data.map(p => <tr key={p.id}>
        <td><div className="font-medium">{p.description}</div><div className="text-[12px] text-ink-3"><span className="ltr inline-block">{p.ref}</span> · {p.external ? bi(p.external, lang) : ''} · {p.source_type === 'deliverable' ? L('مخرج', 'Deliverable') : L('مصاريف', 'Expenses')}</div></td>
        <td className="num font-semibold">{money(p.amount, p.currency, lang)}</td>
        <td><Badge tone={PAY[p.status][2]}>{L(PAY[p.status][0], PAY[p.status][1])}</Badge>{p.finance_ref && <div className="text-[12px] text-ink-3 ltr">{p.finance_ref}</div>}</td>
        <td><div className="flex justify-end gap-1">{p.file_id && <a className="text-brand text-[13px] self-center" href={fileUrl(p.file_id)} target="_blank" rel="noreferrer">{L('المرفق', 'File')}</a>}
          {data.can.pay && p.status === 'pending' && <Btn size="sm" onClick={() => act.mutate({ path: `payments/${p.id}`, v: { action: 'approve' } })}>{L('اعتماد', 'Approve')}</Btn>}
          {data.can.pay && ['pending', 'approved'].includes(p.status) && <><Btn size="sm" kind="primary" onClick={() => { setRef(''); setD({ id: p.id, action: 'pay' }) }}>{L('تسجيل الدفع', 'Record payment')}</Btn><Btn size="sm" kind="danger" onClick={() => setD({ id: p.id, action: 'reject' })}>{L('رفض', 'Reject')}</Btn></>}</div></td></tr>)}</tbody></table></Panel>}
    {d?.action === 'pay' && <Modal open onClose={() => setD(null)} width={420} title={L('تسجيل الدفع', 'Record the payment')} footer={<><Btn onClick={() => setD(null)}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={ref.length < 2} onClick={() => act.mutate({ path: `payments/${d.id}`, v: { action: 'pay', finance_ref: ref } }, { onSuccess: () => { toast(L('سُجّل الدفع', 'Payment recorded')); setD(null) } })}>{L('حفظ', 'Save')}</Btn></>}>
      <Field label={L('رقم سند الدفع', 'Payment voucher no.')} req><input className="field ltr" value={ref} onChange={x => setRef(x.target.value)} /></Field></Modal>}
    {d?.action === 'reject' && <DecisionDialog open onClose={() => setD(null)} tone="danger" reasonRequired minLength={5} title={L('رفض طلب الدفع', 'Reject the payment request')} confirmLabel={L('رفض', 'Reject')} onConfirm={note => act.mutateAsync({ path: `payments/${d.id}`, v: { action: 'reject', note } }).then(() => setD(null))} />}
  </>
}

export function ReportsTab() {
  const L = useL(); const lang = useLang()
  const [from, setFrom] = useState(`${new Date().getFullYear()}-01-01`); const [to, setTo] = useState(today())
  const { data } = useExtReports(from, to)
  if (!data) return <Loader2 className="animate-spin text-brand mx-auto" />
  const r = data.data; const lk = data.lookups
  const dept = new Map(lk.departments.map(d => [String(d.id), d])); const br = new Map(lk.branches.map(b => [String(b.id), b])); const sp = new Map(lk.specialties.map(s => [String(s.id), s]))
  const Tile = ({ k, v }: { k: string; v: string | number }) => <div className="rounded-xl border border-line p-3"><div className="text-[12px] text-ink-3">{k}</div><div className="text-[22px] font-black num">{v}</div></div>
  const Map2 = ({ title, m, names }: { title: string; m: Record<string, number>; names?: Map<string, { ar: string; en: string }> }) => <Panel title={title}>{Object.keys(m).length === 0 ? <p className="text-[13px] text-ink-3">—</p> :
    <div className="space-y-1">{Object.entries(m).map(([k, n]) => <div key={k} className="flex justify-between text-[13.5px]"><span>{names?.get(k) ? bi(names.get(k)!, lang) : k === 'null' || k === '' ? '—' : k}</span><span className="num font-semibold">{n}</span></div>)}</div>}</Panel>
  return <div className="space-y-4">
    <div className="flex flex-wrap gap-2"><input type="date" className="field !w-[calc(50%-4px)] sm:!w-40" value={from} onChange={e => setFrom(e.target.value)} aria-label={L('من', 'From')} /><input type="date" className="field !w-[calc(50%-4px)] sm:!w-40" value={to} onChange={e => setTo(e.target.value)} aria-label={L('إلى', 'To')} /></div>
    <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(160px,1fr))]">
      <Tile k={L('متطوعون فعّالون', 'Active volunteers')} v={r.volunteers.active} /><Tile k={L('ساعات معتمدة', 'Approved hours')} v={r.volunteers.hours} />
      {r.volunteers.value != null && <Tile k={L('القيمة التقديرية', 'Estimated value')} v={money(r.volunteers.value, r.volunteers.value_currency, lang)} />}
      <Tile k={L('معدل الاستمرار', 'Completion rate')} v={r.volunteers.retention != null ? `${r.volunteers.retention}%` : '—'} />
      <Tile k={L('تكليفات بسمات علاقة عمل', 'Employment-like engagements')} v={r.volunteers.high} />
      <Tile k={L('خبراء معتمدون', 'Approved experts')} v={r.experts.approved} /><Tile k={L('تكليفات جارية', 'Live assignments')} v={r.experts.live} />
    </div>
    <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(300px,1fr))]">
      <Map2 title={L('المتطوعون حسب الفرع', 'Volunteers by branch')} m={r.volunteers.by_branch} names={br} />
      <Map2 title={L('حسب الإدارة', 'By department')} m={r.volunteers.by_department} names={dept} />
      <Map2 title={L('حسب العلاقة التشغيلية', 'By operational relation')} m={r.volunteers.by_relation} names={new Map(Object.entries({ replaces: { ar: 'يحل محل موظف', en: 'Replaces' }, alongside: { ar: 'إلى جانب موظف', en: 'Alongside' }, independent: { ar: 'مهمة مستقلة', en: 'Independent' } }))} />
      <Map2 title={L('حسب المشروع', 'By project')} m={(r.volunteers as { by_project?: Record<string, number> }).by_project ?? {}} />
      <Map2 title={L('الساعات المعتمدة حسب المشروع', 'Approved hours by project')} m={(r.volunteers as { hours_by_project?: Record<string, number> }).hours_by_project ?? {}} />
      <Map2 title={L('الخبراء حسب التخصص', 'Experts by specialty')} m={r.experts.by_specialty} names={sp} />
      <Map2 title={L('الخبراء حسب الدولة', 'Experts by country')} m={r.experts.by_country} />
      <Panel title={L('أعلى الخبراء تقييماً', 'Top-rated experts')}>{r.experts.top.length === 0 ? <p className="text-[13px] text-ink-3">—</p> : <div className="space-y-1">{r.experts.top.map(t => <div key={t.id} className="flex justify-between text-[13.5px]"><span>{bi(t, lang)}</span><span className="num">★ {t.rating} ({t.n})</span></div>)}</div>}</Panel>
      {r.experts.paid && <Panel title={L('المدفوع خلال الفترة', 'Paid in the period')}>{r.experts.paid.length === 0 ? <p className="text-[13px] text-ink-3">—</p> : <div className="space-y-1">{r.experts.paid.map((p, i) => <div key={i} className="flex justify-between text-[13.5px]"><span>{p.source_type === 'deliverable' ? L('أتعاب مخرجات', 'Deliverable fees') : L('تعويض مصاريف', 'Expenses')}</span><span className="num">{money(p.s, p.currency, lang)}</span></div>)}</div>}</Panel>}
      {r.experts.paid_by_department && r.experts.paid_by_department.length > 0 && <Panel title={L('الأتعاب المدفوعة حسب القسم', 'Fees paid by department')}><div className="space-y-1">{r.experts.paid_by_department.map((p, i) => <div key={i} className="flex justify-between text-[13.5px]"><span>{p.d && dept.get(String(p.d)) ? bi(dept.get(String(p.d))!, lang) : '—'}</span><span className="num">{money(p.s, p.c, lang)}</span></div>)}</div></Panel>}
    </div>
  </div>
}
