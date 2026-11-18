import { useState } from 'react'
import { Check, FileSignature, Plus, Printer, Star, Upload, X } from 'lucide-react'
import { useL, useLang, bi, fmtDate, money } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { fileUrl } from '../../lib/console'
import { ENG_STATUS, PLEDGE, RELATION, TEST_Q, uploadExtFile, useExtAct, type EngagementRow, type ExtFull, type Lookups } from '../../lib/externals'
import { Badge, Btn, Field, Modal, Seg, SignaturePad, toast } from '../../ui/kit'
import { ConfirmSend, DecisionDialog } from '../../ui/decision'
import { StepsPath } from '../requests/parts'

export const errOf = (e: unknown) => e instanceof ApiError ? e : null

/** رفع ملف صغير يعيد معرّفه */
export function FilePick({ onDone, portal = false, label }: { onDone: (f: { id: number; name: string }) => void; portal?: boolean; label?: string }) {
  const L = useL(); const [busy, setBusy] = useState(false)
  return <label className="inline-flex items-center gap-2 h-9 px-3 rounded-lg border border-line bg-surface text-[13px] font-semibold cursor-pointer hover:bg-sub">
    <Upload size={14} />{busy ? L('جارٍ الرفع…', 'Uploading…') : label ?? L('إرفاق ملف', 'Attach a file')}
    <input type="file" className="hidden" onChange={e => { const f = e.target.files?.[0]; if (!f) return; setBusy(true); uploadExtFile(f, portal).then(onDone).catch(x => toast((x as Error).message)).finally(() => setBusy(false)) }} /></label>
}

/** نموذج التكليف: المهام، المدة، الساعات، المشرف، العلاقة التشغيلية وفحص طبيعة العلاقة (E.2) */
export function EngagementForm({ x, g, lk, onClose }: { x: ExtFull; g?: EngagementRow; lk: Lookups; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const act = useExtAct()
  const [v, setV] = useState({ title: g?.title ?? '', tasks: g?.tasks ?? '', supervisor_id: g?.supervisor_id ?? x.supervisor_id ?? 0, department_id: g?.department_id ?? x.department_id ?? null, location_id: g?.location_id ?? x.location_id ?? null,
    starts_on: g?.starts_on ?? new Date().toISOString().slice(0, 10), ends_on: g?.ends_on ?? '', weekly_hours: g?.weekly_hours ?? 10, relation: g?.relation ?? 'alongside', replaces_position: g?.replaces_position ?? '',
    justification: g?.justification ?? '', relation_test: g?.relation_test ?? {} as Record<string, boolean>, fee_model: g?.fee_model ?? 'none', amount: g?.amount ?? null as number | null, currency: g?.currency ?? lk.cfg.expense_currency, expense_cap: g?.expense_cap ?? lk.cfg.expense_cap, tax_treatment: g?.tax_treatment ?? '' })
  const e = errOf(act.error)
  const score = Object.values(v.relation_test).filter(Boolean).length
  const high = v.relation === 'replaces' || score >= lk.cfg.relation_threshold
  const vol = x.kind === 'volunteer'
  const save = () => act.mutate({ path: g ? `engagements/${g.id}` : `${x.id}/engagements`, method: g ? 'PUT' : 'POST', v: { ...v, department_id: v.department_id || null, location_id: v.location_id || null } }, { onSuccess: () => { toast(L('حُفظ التكليف كمسودة', 'Saved as draft')); onClose() } })
  return <Modal open onClose={onClose} width={720} title={g ? L('تعديل التكليف', 'Edit engagement') : L('تكليف جديد', 'New engagement')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={act.isPending || !v.title || !v.ends_on || !v.supervisor_id} onClick={save}>{L('حفظ مسودة', 'Save draft')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('عنوان التكليف', 'Title')} req className="sm:col-span-2" error={e?.field('title')}><input className="field" value={v.title} onChange={x => setV({ ...v, title: x.target.value })} /></Field>
      <Field label={L('المهام والمخرجات المتوقعة', 'Tasks & expected outputs')} className="sm:col-span-2"><textarea className="field" rows={2} value={v.tasks} onChange={x => setV({ ...v, tasks: x.target.value })} /></Field>
      <Field label={L('المشرف', 'Supervisor')} req error={e?.field('supervisor_id')}><select className="field" value={v.supervisor_id} onChange={x => setV({ ...v, supervisor_id: Number(x.target.value) })}><option value={0}>—</option>{lk.employees.map(p => <option key={p.id} value={p.id}>{bi(p, lang)} · {p.code}</option>)}</select></Field>
      <Field label={L('الإدارة', 'Department')}><select className="field" value={v.department_id ?? ''} onChange={x => setV({ ...v, department_id: x.target.value ? Number(x.target.value) : null })}><option value="">—</option>{lk.departments.map(d => <option key={d.id} value={d.id}>{bi(d, lang)}</option>)}</select></Field>
      <Field label={L('من', 'From')} req error={e?.field('starts_on')}><input type="date" className="field" value={v.starts_on} onChange={x => setV({ ...v, starts_on: x.target.value })} /></Field>
      <Field label={L('إلى', 'To')} req error={e?.field('ends_on')} hint={vol ? L(`المدة القصوى ${lk.cfg.max_months} أشهر`, `Max ${lk.cfg.max_months} months`) : undefined}><input type="date" className="field" min={v.starts_on} value={v.ends_on} onChange={x => setV({ ...v, ends_on: x.target.value })} /></Field>
      <Field label={L('الساعات الأسبوعية', 'Weekly hours')} req error={e?.field('weekly_hours')} hint={vol ? L(`الحد الأعلى ${lk.cfg.max_weekly_hours}`, `Max ${lk.cfg.max_weekly_hours}`) : undefined}><input className="field num" inputMode="numeric" value={v.weekly_hours} onChange={x => setV({ ...v, weekly_hours: Number(x.target.value.replace(/\D/g, '')) || 0 })} /></Field>
      <Field label={L('الفرع', 'Branch')}><select className="field" value={v.location_id ?? ''} onChange={x => setV({ ...v, location_id: x.target.value ? Number(x.target.value) : null })}><option value="">—</option>{lk.branches.map(b => <option key={b.id} value={b.id}>{bi(b, lang)}</option>)}</select></Field>
      <Field label={L('العلاقة التشغيلية', 'Operational relation')} req div className="sm:col-span-2"><Seg value={v.relation} onChange={r => setV({ ...v, relation: r as typeof v.relation })} options={(['alongside', 'independent', 'replaces'] as const).map(k => ({ v: k, label: L(RELATION[k][0], RELATION[k][1]) }))} /></Field>
      {v.relation === 'replaces' && <>
        <Field label={L('الموقع الوظيفي أو الموظف', 'Post or employee')} req error={e?.field('replaces_position')}><input className="field" value={v.replaces_position} onChange={x => setV({ ...v, replaces_position: x.target.value })} /></Field>
        <Field label={L('المبرر', 'Justification')} req error={e?.field('justification')} hint={L(`مدة قصوى ${lk.cfg.replaces_max_months} أشهر واعتماد الإدارة العليا`, `Max ${lk.cfg.replaces_max_months} months, top-management approval`)}><textarea className="field" rows={2} value={v.justification} onChange={x => setV({ ...v, justification: x.target.value })} /></Field></>}
      <div className="sm:col-span-2 rounded-lg border border-line p-3">
        <div className="font-semibold text-[13.5px]">{L('فحص طبيعة العلاقة', 'Relationship check')}</div>
        <p className="text-[12px] text-ink-3 mb-2">{L('كل «نعم» مؤشر على أن العلاقة أقرب إلى علاقة عمل.', 'Each “yes” suggests an employment relationship.')}</p>
        {lk.test.map(k => <label key={k} className="flex items-start gap-2 text-[13px] py-0.5"><input type="checkbox" className="mt-1" checked={!!v.relation_test[k]} onChange={x => setV({ ...v, relation_test: { ...v.relation_test, [k]: x.target.checked } })} />{TEST_Q[k] ? L(TEST_Q[k][0], TEST_Q[k][1]) : k}</label>)}
        <div className={`mt-2 text-[12.5px] ${high ? 'text-warn font-semibold' : 'text-ink-3'}`}>{L(`النتيجة ${score} من 6`, `Score ${score} of 6`)} · {high ? L('يحتاج اعتماداً أعلى ومراجعة قانونية', 'Needs higher approval and a legal review') : L('اعتماد عادي', 'Normal approval')}</div>
      </div>
      <Field label={L('التعويض', 'Compensation')} div className="sm:col-span-2"><Seg value={v.fee_model} onChange={f => setV({ ...v, fee_model: f })} options={(vol ? ['none', 'expenses'] : ['none', 'lump', 'daily', 'monthly', 'expenses']).map(k => ({ v: k, label: L(({ none: 'دون مقابل', expenses: 'تعويض مصاريف', lump: 'مبلغ مقطوع', daily: 'يومي', monthly: 'شهري' } as Record<string, string>)[k], ({ none: 'None', expenses: 'Expenses', lump: 'Lump sum', daily: 'Daily', monthly: 'Monthly' } as Record<string, string>)[k]) }))} /></Field>
      {v.fee_model === 'expenses' && <Field label={L('حد التعويض لكل طلب', 'Cap per claim')}><div className="flex gap-2"><input className="field num" value={v.expense_cap ?? ''} onChange={x => setV({ ...v, expense_cap: Number(x.target.value) || 0 })} /><span className="self-center ltr">{v.currency}</span></div></Field>}
      {['lump', 'daily', 'monthly'].includes(v.fee_model) && <><Field label={L('المبلغ', 'Amount')}><input className="field num" value={v.amount ?? ''} onChange={x => setV({ ...v, amount: Number(x.target.value) || null })} /></Field>
        <Field label={L('المعاملة الضريبية', 'Tax treatment')} hint={L('يحددها القانون ومحاسب المنشأة — يُوثَّق هنا.', 'Set by law and the accountant — documented here.')}><input className="field" value={v.tax_treatment} onChange={x => setV({ ...v, tax_treatment: x.target.value })} /></Field></>}
    </div>
    {e && !Object.keys(e.errors).length && <p className="mt-2 text-[13px] text-danger">{e.message}</p>}
  </Modal>
}

/** بطاقة التكليف: المسار، الاعتماد، التوقيع، التأهيل، الساعات، التعويض، الإنهاء والشهادة */
export function EngagementCard({ x, g, lk, onEdit, portal = false }: { x: ExtFull | null; g: EngagementRow; lk?: Lookups; onEdit?: () => void; portal?: boolean }) {
  const L = useL(); const lang = useLang()
  const act = useExtAct()
  const [log, setLog] = useState(false); const [end, setEnd] = useState(false); const [sign, setSign] = useState(false); const [exp, setExp] = useState(false); const [body, setBody] = useState(false)
  const [decide, setDecide] = useState<null | boolean>(null)
  const st = ENG_STATUS[g.status] ?? [g.status, g.status, 'neutral']
  const pending = g.logs.filter(l => l.status === 'pending')
  return <div className="rounded-xl border border-line p-3 space-y-2">
    <div className="flex items-center justify-between gap-2"><div><div className="font-semibold">{g.title}</div><div className="text-[12px] text-ink-3 num"><span className="ltr inline-block">{g.ref}</span> · {fmtDate(g.starts_on, lang)} — {fmtDate(g.ends_on, lang)} · {g.weekly_hours} {L('س/أسبوع', 'h/wk')}</div></div>
      <Badge tone={st[2]}>{L(st[0], st[1])}</Badge></div>
    <div className="text-[13px] text-ink-2">{L(RELATION[g.relation][0], RELATION[g.relation][1])}{g.supervisor ? ` · ${L('المشرف', 'Supervisor')}: ${bi(g.supervisor, lang)}` : ''}
      {g.high && <span className="text-warn"> · {L(`فحص العلاقة ${g.relation_score}/6 — اعتماد أعلى`, `Relationship check ${g.relation_score}/6 — higher approval`)}</span>}</div>
    {g.steps.length > 0 && !['active', 'ended', 'terminated'].includes(g.status) && <StepsPath steps={g.steps} />}
    {g.status === 'active' || g.status === 'ended' || g.status === 'terminated' ? <div className="grid grid-cols-3 gap-2 text-center">
      <div className="rounded-lg bg-sub p-2"><div className="text-[11.5px] text-ink-3">{L('ساعات معتمدة', 'Approved hours')}</div><div className="font-bold num">{g.hours}</div></div>
      <div className="rounded-lg bg-sub p-2"><div className="text-[11.5px] text-ink-3">{L('بانتظار المصادقة', 'Pending')}</div><div className="font-bold num">{g.pending_hours}</div></div>
      <div className="rounded-lg bg-sub p-2"><div className="text-[11.5px] text-ink-3">{L('التأهيل', 'Onboarding')}</div><div className="font-bold num">{g.onboarding.filter(i => i.done).length}/{g.onboarding.length}</div></div></div> : null}
    {g.status === 'active' && g.onboarding.length > 0 && <div className="space-y-1">{g.onboarding.map((i, n) => <label key={n} className="flex items-center gap-2 text-[13px]">
      <input type="checkbox" checked={i.done} disabled={portal || !g.can.approve_logs} onChange={e => act.mutate({ path: `engagements/${g.id}/checklist`, v: { i: n, done: e.target.checked } })} />{bi(i, lang)}</label>)}</div>}
    {g.evaluation && <div className="text-[13px]"><Star size={13} className="inline text-brass" /> {g.evaluation.rating}/5{g.evaluation.note ? ` — ${g.evaluation.note}` : ''}</div>}
    <div className="flex flex-wrap gap-1.5">
      {!portal && g.can.edit && onEdit && <Btn size="sm" kind="ghost" onClick={onEdit}>{L('تعديل', 'Edit')}</Btn>}
      {!portal && g.can.edit && <ConfirmSend label={L('إرسال للاعتماد', 'Send for approval')} onConfirm={() => act.mutateAsync({ path: `engagements/${g.id}/submit` }).then(() => toast(L('أُرسل للاعتماد', 'Sent for approval')))} />}
      {!portal && g.can_act && <><Btn size="sm" kind="primary" icon={<Check size={13} />} onClick={() => setDecide(true)}>{L('اعتماد', 'Approve')}</Btn><Btn size="sm" kind="danger" icon={<X size={13} />} onClick={() => setDecide(false)}>{L('رفض', 'Reject')}</Btn></>}
      {(g.status === 'approved' || g.body) && <Btn size="sm" kind="ghost" icon={<FileSignature size={13} />} onClick={() => setBody(true)}>{L('نص الاتفاقية', 'Agreement')}</Btn>}
      {g.status === 'approved' && (portal || g.can.edit !== undefined) && <Btn size="sm" kind={portal ? 'primary' : 'secondary'} onClick={() => setSign(true)}>{portal ? L('التوقيع على الاتفاقية', 'Sign the agreement') : L('توقيع ورقي', 'Paper signature')}</Btn>}
      {g.status === 'active' && (portal || g.can.approve_logs) && <Btn size="sm" icon={<Plus size={13} />} onClick={() => setLog(true)}>{L('تسجيل ساعات', 'Log hours')}</Btn>}
      {g.status === 'active' && g.fee_model === 'expenses' && <Btn size="sm" kind="ghost" onClick={() => setExp(true)}>{L('طلب تعويض مصاريف', 'Expense claim')}</Btn>}
      {!portal && g.can.end && <Btn size="sm" kind="ghost" onClick={() => setEnd(true)}>{L('إنهاء وتقييم', 'End & rate')}</Btn>}
      {g.verify_code && <a className="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-line text-[13px] font-semibold hover:bg-sub" href={portal ? `/ext/cert/${g.id}` : `/hr/externals/cert/${g.id}`}><Printer size={13} />{L('شهادة التطوع', 'Certificate')}</a>}
    </div>
    {g.logs.length > 0 && <details className="text-[13px]"><summary className="cursor-pointer text-ink-3">{L('سجل الساعات', 'Hours log')} ({g.logs.length})</summary>
      {!portal && g.can.approve_logs && pending.length > 0 && <div className="flex gap-1 my-1"><Btn size="sm" kind="primary" onClick={() => act.mutate({ path: 'logs/decide', v: { ids: pending.map(l => l.id), approve: true } }, { onSuccess: () => toast(L('صودقت الساعات', 'Hours approved')) })}>{L(`مصادقة ${pending.length}`, `Approve ${pending.length}`)}</Btn></div>}
      <table className="tbl mt-1"><tbody>{g.logs.map(l => <tr key={l.id}><td className="num">{fmtDate(l.date, lang)}</td><td>{l.activity}</td><td className="num">{l.hours}</td>
        <td><Badge tone={l.status === 'approved' ? 'brand' : l.status === 'rejected' ? 'danger' : 'brass'}>{l.status === 'approved' ? L('معتمد', 'Approved') : l.status === 'rejected' ? L('مرفوض', 'Rejected') : L('بانتظار', 'Pending')}</Badge></td></tr>)}</tbody></table></details>}
    {decide !== null && <DecisionDialog open onClose={() => setDecide(null)} tone={decide ? 'primary' : 'danger'} reasonRequired={!decide} title={decide ? L('اعتماد التكليف', 'Approve the engagement') : L('رفض التكليف', 'Reject the engagement')}
      confirmLabel={decide ? L('اعتماد', 'Approve') : L('رفض', 'Reject')} onConfirm={note => act.mutateAsync({ path: `engagements/${g.id}/decide`, v: { approve: decide, note } }).then(() => setDecide(null))} />}
    {log && <LogModal g={g} portal={portal} onClose={() => setLog(false)} />}
    {end && <EndModal g={g} onClose={() => setEnd(false)} />}
    {sign && <SignModal g={g} portal={portal} pledges={lk?.cfg.pledges ?? Object.keys(PLEDGE)} onClose={() => setSign(false)} />}
    {exp && <ExpenseModal g={g} portal={portal} onClose={() => setExp(false)} />}
    {body && <Modal open onClose={() => setBody(false)} width={720} title={L('اتفاقية تطوع مؤقتة', 'Temporary volunteer agreement')} footer={<Btn kind="primary" onClick={() => setBody(false)}>{L('إغلاق', 'Close')}</Btn>}>
      <pre className="whitespace-pre-wrap font-sans text-[13.5px] leading-7">{g.body ?? '—'}</pre>{x && g.signed_at && <p className="mt-2 text-[12.5px] text-ink-3">{L('وُقّعت في', 'Signed on')} {fmtDate(g.signed_at, lang)}</p>}</Modal>}
  </div>
}

function LogModal({ g, portal, onClose }: { g: EngagementRow; portal: boolean; onClose: () => void }) {
  const L = useL()
  const hr = useExtAct()
  const [v, setV] = useState({ date: new Date().toISOString().slice(0, 10), hours: 2, activity: '' })
  const m = hr
  const e = errOf(m.error)
  return <Modal open onClose={onClose} width={460} title={L('تسجيل ساعات', 'Log hours')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={m.isPending || v.activity.length < 3} onClick={() => m.mutate({ path: portal ? `/ext/engagements/${g.id}/logs` : `engagements/${g.id}/logs`, v }, { onSuccess: r => { toast((r as { warning?: string }).warning ?? L('سُجّلت الساعات', 'Hours logged')); onClose() } })}>{L('تسجيل', 'Log')}</Btn></>}>
    <div className="grid grid-cols-2 gap-3">
      <Field label={L('اليوم', 'Day')} error={e?.field('date')}><input type="date" className="field" min={g.starts_on} max={new Date().toISOString().slice(0, 10)} value={v.date} onChange={x => setV({ ...v, date: x.target.value })} /></Field>
      <Field label={L('الساعات', 'Hours')} error={e?.field('hours')}><input className="field num" inputMode="decimal" value={v.hours} onChange={x => setV({ ...v, hours: Number(x.target.value) || 0 })} /></Field>
      <Field label={L('النشاط', 'Activity')} className="col-span-2" error={e?.field('activity')}><input className="field" value={v.activity} onChange={x => setV({ ...v, activity: x.target.value })} /></Field>
    </div>
  </Modal>
}

function EndModal({ g, onClose }: { g: EngagementRow; onClose: () => void }) {
  const L = useL()
  const m = useExtAct()
  const [v, setV] = useState({ rating: 5, note: '', early: false })
  return <Modal open onClose={onClose} width={460} title={L('إنهاء التكليف وتقييم المشرف', 'End & supervisor rating')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><ConfirmSend label={L('إنهاء وإصدار الشهادة', 'End & issue certificate')} onConfirm={() => m.mutateAsync({ path: `engagements/${g.id}/end`, v }).then(() => { toast(L('أُنهي التكليف وصدرت الشهادة', 'Ended; certificate issued')); onClose() })} /></>}>
    <Field label={L('التقييم', 'Rating')} div><div className="flex gap-1">{[1, 2, 3, 4, 5].map(n => <button key={n} type="button" onClick={() => setV({ ...v, rating: n })} className={`size-9 rounded-lg border ${v.rating >= n ? 'bg-brass/20 border-brass text-brass' : 'border-line'}`}><Star size={15} className="mx-auto" /></button>)}</div></Field>
    <Field label={L('ملاحظات', 'Notes')}><textarea className="field" rows={2} value={v.note} onChange={x => setV({ ...v, note: x.target.value })} /></Field>
    <label className="flex items-center gap-2 text-[13px] mt-2"><input type="checkbox" checked={v.early} onChange={x => setV({ ...v, early: x.target.checked })} />{L('إنهاء قبل الموعد', 'End before the due date')}</label>
    <p className="mt-2 text-[12px] text-ink-3">{L('يُعطَّل حساب البوابة، ويبقى الشخص في القاعدة لفرص لاحقة.', 'The portal account is disabled; the person stays in the register for later opportunities.')}</p>
  </Modal>
}

function SignModal({ g, portal, pledges, onClose }: { g: EngagementRow; portal: boolean; pledges: string[]; onClose: () => void }) {
  const L = useL()
  const m = useExtAct()
  const [ok, setOk] = useState<string[]>([]); const [sig, setSig] = useState<string | undefined>(undefined); const [file, setFile] = useState<{ id: number; name: string } | null>(null)
  const e = errOf(m.error)
  const send = () => m.mutate({ path: portal ? `/ext/engagements/${g.id}/sign` : `engagements/${g.id}/sign`, v: { pledges: ok, signature: sig, file_id: file?.id } }, { onSuccess: () => { toast(L('وُقّعت الاتفاقية — التكليف فعّال', 'Signed — the engagement is active')); onClose() } })
  return <Modal open onClose={onClose} width={560} title={portal ? L('التوقيع على الاتفاقية', 'Sign the agreement') : L('توقيع ورقي', 'Paper signature')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={m.isPending || ok.length < pledges.length || (!sig && !file)} onClick={send}>{L('توقيع', 'Sign')}</Btn></>}>
    <div className="space-y-2">{pledges.map(p => <label key={p} className="flex items-center gap-2 text-[14px]"><input type="checkbox" checked={ok.includes(p)} onChange={x => setOk(x.target.checked ? [...ok, p] : ok.filter(y => y !== p))} />{L('أقرّ وأوافق على', 'I accept')} {PLEDGE[p] ? L(PLEDGE[p][0], PLEDGE[p][1]) : p}</label>)}</div>
    <div className="mt-3">{portal ? <SignaturePad onChange={setSig} /> : <div className="flex items-center gap-2"><FilePick onDone={setFile} label={L('رفع الاتفاقية الموقّعة', 'Upload the signed agreement')} />{file && <span className="text-[13px]">{file.name}</span>}</div>}</div>
    {e && <p className="mt-2 text-[13px] text-danger">{e.message}</p>}
  </Modal>
}

function ExpenseModal({ g, portal, onClose }: { g: EngagementRow; portal: boolean; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const m = useExtAct()
  const [v, setV] = useState({ amount: 0, description: '' }); const [file, setFile] = useState<{ id: number; name: string } | null>(null)
  const e = errOf(m.error)
  return <Modal open onClose={onClose} width={460} title={L('طلب تعويض مصاريف', 'Expense claim')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><ConfirmSend label={L('إرسال للمالية', 'Send to finance')} disabled={!file || !v.amount || !v.description} onConfirm={() => m.mutateAsync({ path: portal ? `/ext/engagements/${g.id}/expense` : `engagements/${g.id}/expense`, v: { ...v, file_id: file?.id } }).then(() => { toast(L('أُرسل الطلب للمالية', 'Sent to finance')); onClose() })} /></>}>
    <div className="space-y-3">
      <Field label={L('المبلغ', 'Amount')} error={e?.field('amount')} hint={g.expense_cap ? L(`الحد ${money(g.expense_cap, g.currency ?? 'SYP', lang)}`, `Cap ${money(g.expense_cap, g.currency ?? 'SYP', lang)}`) : undefined}><input className="field num" inputMode="decimal" value={v.amount || ''} onChange={x => setV({ ...v, amount: Number(x.target.value) || 0 })} /></Field>
      <Field label={L('البيان', 'Description')} error={e?.field('description')}><input className="field" value={v.description} onChange={x => setV({ ...v, description: x.target.value })} placeholder={L('نقل، وجبة، اتصالات…', 'Transport, meal, calls…')} /></Field>
      <div className="flex items-center gap-2"><FilePick portal={portal} onDone={setFile} label={L('الفاتورة', 'Receipt')} />{file && <a className="text-brand text-[13px]" href={fileUrl(file.id)} target="_blank" rel="noreferrer">{file.name}</a>}</div>
      {e && !e.field('amount') && <p className="text-[13px] text-danger">{e.message}</p>}
    </div>
  </Modal>
}
