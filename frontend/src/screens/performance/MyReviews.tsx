import { useState } from 'react'
import { ClipboardCheck, Award as AwardIcon } from 'lucide-react'
import { useL, useLang, bi, fmtDate, money } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { BOXES, useFillReview, useMyReviews, type MyForm, type MyReviews as Data } from '../../lib/performance'
import { Avatar, Badge, Btn, Empty, Field, Modal, PageHead, Panel, toast } from '../../ui/kit'
import { Radar } from '../../ui/viz'

/** «تقييماتي» (M13): نماذج 360 المطلوبة مني — ذاتي، كمدير، كزميل — ونتائجي المعتمدة */
export function MyReviews() {
  const L = useL(); const lang = useLang()
  const q = useMyReviews(); const d = q.data
  const [open, setOpen] = useState<MyForm | null>(null)
  if (!d) return <div className="max-w-[1100px] mx-auto"><PageHead title={L('تقييماتي', 'My reviews')} /></div>
  const roleName = { self: L('تقييمي الذاتي', 'My self-review'), manager: L('بصفتك المدير المباشر', 'As direct manager'), peer: L('بصفتك زميلاً', 'As a peer') }
  const pending = d.forms.filter(f => f.status === 'pending')
  return <div className="max-w-[1100px] mx-auto space-y-5">
    <PageHead title={L('تقييماتي', 'My reviews')} sub={L('قيّم بموضوعية — تقييم الزملاء يظهر مجمّعاً وبلا أسماء.', 'Be objective — peer scores are shown aggregated and anonymous.')} />
    <Panel title={<span className="flex items-center gap-2"><ClipboardCheck size={17} className="text-brand" />{L('نماذج بانتظارك', 'Forms waiting for you')}{pending.length > 0 && <Badge tone="warn">{pending.length}</Badge>}</span>} pad={false}>
      {d.forms.length === 0 ? <Empty title={L('لا نماذج تقييم بانتظارك', 'No review forms for you')}>{d.cycles.open.length
        ? L(`دورة «${d.cycles.open.map(c => c.ar).join('، ')}» مفتوحة لكن لم يُطلب منك فيها أي تقييم.`, `«${d.cycles.open.map(c => c.en).join(', ')}» is open but has no form for you.`)
        : d.cycles.draft ? L('توجد دورة تقييم مسودة — تصلك النماذج عندما تفتحها الموارد البشرية.', 'A review cycle is in draft — forms reach you once HR opens it.')
        : L('التقييم يتم ضمن دورة تفتحها الموارد البشرية، ولا دورة مفتوحة حالياً.', 'Reviews happen inside a cycle opened by HR; none is open now.')}</Empty> : <ul className="divide-y divide-line-2">{d.forms.map(f =>
        <li key={f.id} className="px-5 py-3 flex flex-wrap items-center gap-3"><Avatar name={f.employee.name.ar} size={36} />
          <div className="flex-1 min-w-[180px]"><div className="font-semibold">{f.role === 'self' ? L('أنا', 'Me') : bi(f.employee.name, lang)}</div>
            <div className="text-[12px] text-ink-3">{roleName[f.role]} · {bi(f.cycle.name, lang)} · {L('حتى', 'due')} <span className="num">{fmtDate(f.cycle.ends_on, lang)}</span></div></div>
          {f.status === 'submitted' ? <Badge tone="brand">{L('أُرسل', 'Sent')}</Badge> : <Btn size="sm" kind="primary" onClick={() => setOpen(f)}>{Object.keys(f.ratings).length ? L('متابعة', 'Continue') : L('ابدأ التقييم', 'Start')}</Btn>}
        </li>)}</ul>}
    </Panel>
    {d.results.map(r => <Panel key={r.id} title={<span className="flex items-center gap-2"><AwardIcon size={17} className="text-brand" />{L('نتيجتي', 'My result')} — {bi(r.cycle, lang)}</span>}>
      <div className="grid md:grid-cols-[300px_1fr] gap-5 items-start">
        <div className="flex justify-center"><Radar size={280} axes={d.competencies.map(c => ({ label: bi(c, lang), value: (r.ratings[c.id]?.total ?? 0) / d.scale }))} /></div>
        <div className="min-w-0 space-y-3">
          <div className="flex flex-wrap items-center gap-2"><span className="text-[28px] font-black font-display num text-brand">{r.score?.toFixed(1) ?? '—'}</span><span className="text-ink-3">/ <span className="num">{d.scale}</span></span>
            {r.performance && r.potential && <Badge tone="info">{BOXES[`${r.performance}${r.potential}`][lang === 'ar' ? 0 : 1]}</Badge>}</div>
          <table className="tbl"><thead><tr><th>{L('الكفاءة', 'Competency')}</th><th>{L('ذاتي', 'Self')}</th><th>{L('المدير', 'Manager')}</th><th>{L('الزملاء', 'Peers')}</th></tr></thead>
            <tbody>{d.competencies.map(c => { const x = r.ratings[c.id]; return <tr key={c.id}><td>{bi(c, lang)}</td>{(['self', 'manager', 'peers'] as const).map(k => <td key={k} className="num">{x?.[k] != null ? x[k]!.toFixed(1) : '—'}</td>)}</tr> })}</tbody></table>
          {r.award && <div className="rounded-xl border border-brass/40 bg-brass-soft px-4 py-3 text-[13.5px]">{L('مكافأة التقييم', 'Review reward')}: <b className="num">{r.award.points ? `${r.award.points} ${L('نقطة', 'pts')}` : money(r.award.amount, r.award.currency, lang)}</b>
            {r.award.status === 'approved' && r.award.month && <> — {L('تُصرف مع كشف', 'paid with the sheet of')} <span className="num">{r.award.month}</span></>}{r.award.status === 'paid' && <> — {L('صُرفت', 'paid')}</>}</div>}
        </div>
      </div>
    </Panel>)}
    {open && <FillModal f={open} d={d} onClose={() => setOpen(null)} />}
  </div>
}

function FillModal({ f, d, onClose }: { f: MyForm; d: Data; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const fill = useFillReview()
  const [ratings, setRatings] = useState<Record<string, number>>(f.ratings ?? {})
  const [potential, setPotential] = useState<number | null>(f.potential)
  const [comment, setComment] = useState(f.comment ?? '')
  const [confirm, setConfirm] = useState(false)
  const e = fill.error instanceof ApiError ? fill.error : null
  const complete = d.competencies.every(c => ratings[c.id]) && (f.role !== 'manager' || !!potential)
  const go = (submit: boolean) => fill.mutate({ id: f.id, ratings, potential, comment, submit }, { onSuccess: () => { toast(submit ? L('أُرسل التقييم', 'Review submitted') : L('حُفظت المسودة', 'Draft saved')); onClose() }, onError: () => setConfirm(false) })
  return <Modal open onClose={onClose} width={680} title={<>{f.role === 'self' ? L('تقييمي الذاتي', 'My self-review') : bi(f.employee.name, lang)} <span className="text-[13px] font-normal text-ink-3">· {bi(f.cycle.name, lang)}</span></>}
    footer={confirm ? <><span className="text-[13px] me-auto">{L('بعد الإرسال لا يمكن التعديل. متأكد؟', 'You cannot edit after sending. Sure?')}</span><Btn onClick={() => setConfirm(false)}>{L('رجوع', 'Back')}</Btn><Btn kind="primary" disabled={fill.isPending} onClick={() => go(true)}>{L('تأكيد الإرسال', 'Confirm')}</Btn></>
      : <><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn disabled={fill.isPending} onClick={() => go(false)}>{L('حفظ مسودة', 'Save draft')}</Btn><Btn kind="primary" disabled={!complete} onClick={() => setConfirm(true)}>{L('إرسال', 'Submit')}</Btn></>}>
    {f.role !== 'self' && <p className="text-[13px] text-ink-3 mb-3">{bi(f.employee.title, lang)}{f.employee.dept ? ` · ${bi(f.employee.dept, lang)}` : ''}</p>}
    <div className="space-y-3">{d.competencies.map(c => <div key={c.id} className="rounded-xl border border-line-2 p-3">
      <div className="flex flex-wrap items-center justify-between gap-2"><span className="font-semibold text-[14px]">{bi(c, lang)}</span>
        <div className="flex flex-wrap gap-1">{Array.from({ length: d.scale }, (_, i) => i + 1).map(v => <button key={v} type="button" onClick={() => setRatings({ ...ratings, [c.id]: v })}
          className={`size-9 rounded-lg border text-[13px] font-bold num ${ratings[c.id] === v ? 'bg-brand text-on-brand border-brand' : 'border-line hover:border-brand'}`}>{v}</button>)}</div></div>
      {e?.field(`ratings.${c.id}`) && <p className="text-[12px] text-danger mt-1">{e.field(`ratings.${c.id}`)}</p>}
    </div>)}</div>
    <p className="text-[12px] text-ink-3 mt-2">{L(`1 = أقل من المتوقع بكثير · ${d.scale} = يفوق التوقعات`, `1 = far below expectations · ${d.scale} = exceeds expectations`)}</p>
    {f.role === 'manager' && <Field div req label={L('الإمكانات المستقبلية', 'Future potential')} className="mt-3" error={e?.field('potential')}>
      <div className="flex gap-1">{[1, 2, 3].map(v => <button key={v} type="button" onClick={() => setPotential(v)} className={`flex-1 h-10 rounded-lg border text-[13px] font-semibold ${potential === v ? 'bg-brand text-on-brand border-brand' : 'border-line'}`}>{[L('منخفضة', 'Low'), L('متوسطة', 'Mid'), L('مرتفعة', 'High')][v - 1]}</button>)}</div></Field>}
    <Field label={f.role === 'peer' ? L('ملاحظة (لا تظهر باسمك للموظف)', 'Comment (not shown with your name)') : L('ملاحظات', 'Comments')} className="mt-3"><textarea className="field" value={comment} maxLength={2000} onChange={x => setComment(x.target.value)} /></Field>
    {e && !Object.keys(e.errors).length && <p className="text-danger text-[13px] mt-2">{e.message}</p>}
  </Modal>
}
