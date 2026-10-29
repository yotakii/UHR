import { useEffect, useState } from 'react'
import { ArrowRight, ArrowLeft, Plus, Trash2, Save, BadgeCheck, Loader2, ChevronUp, ChevronDown } from 'lucide-react'
import { useL, useLang, bi } from '../../../lib/store'
import { can, useMe } from '../../../lib/session'
import { ApiError } from '../../../lib/api'
import { useJobTest, useSaveJobTest, type Question, type QType, type TestSettings } from '../../../lib/tests'
import { Badge, Btn, Empty, Field, PageHead, Panel, Seg, toast } from '../../../ui/kit'

const blank = (type: QType): Question => ({ id: 'q' + Math.random().toString(36).slice(2, 8), type, text: '', options: type === 'mcq' ? ['', '', '', ''] : [], answer: type === 'mcq' ? 0 : -1, points: type === 'mcq' ? 10 : type === 'short' ? 15 : 20, rubric: '' })

/**
 * الاختبار الأولي للشاغر (D-34): الإعداد (الجمهور، المدة، المحاولات، الصلاحية) + الأسئلة.
 * HR يكتب الأسئلة ويعتمدها قبل الإرسال.
 */
export function JobTest({ id }: { id: number }) {
  const L = useL(); const lang = useLang()
  const me = useMe().data
  const editable = can(me, 'jobs', 'edit')
  const { data, isLoading, error } = useJobTest(id)
  const save = useSaveJobTest(id)
  const [s, setS] = useState<TestSettings | null>(null)
  const [qs, setQs] = useState<Question[]>([])
  useEffect(() => { if (data) { setS(data.settings); setQs(data.test?.questions ?? []) } }, [data])
  if (isLoading || !s) return error ? <div className="max-w-[1000px] mx-auto bg-surface border border-line rounded-2xl"><Empty title={L('الشاغر غير موجود', 'Position not found')} /></div> : <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const d = data!
  const Back = lang === 'ar' ? ArrowRight : ArrowLeft
  const dirty = JSON.stringify({ s, qs }) !== JSON.stringify({ s: d.settings, qs: d.test?.questions ?? [] })
  const total = qs.reduce((a, q) => a + (q.points || 0), 0)
  const err = save.error instanceof ApiError ? save.error : null
  const setQ = (i: number, p: Partial<Question>) => setQs(x => x.map((q, j) => j === i ? { ...q, ...p } : q))
  const move = (i: number, by: number) => setQs(x => { const y = [...x]; const [q] = y.splice(i, 1); y.splice(i + by, 0, q); return y })
  const submit = (approve: boolean) => save.mutate({ ...s, questions: qs, approve }, {
    onSuccess: () => toast(approve ? L('اعتُمد الاختبار — يُرسل للمتقدمين الجدد حسب الإعداد', 'Test approved — sent to new applicants per the settings') : L('حُفظت المسودة', 'Draft saved')),
    onError: x => toast((x as ApiError).message),
  })

  return <div className="max-w-[1000px] mx-auto space-y-5">
    <a href="/hr/jobs" className="text-[13px] text-ink-3 hover:text-ink inline-flex items-center gap-1"><Back size={14} />{L('الوظائف الشاغرة', 'Job openings')}</a>
    <PageHead title={L('الاختبار الأولي', 'Preliminary test')} sub={<>{bi(d.job.title, lang)} · <span className="ltr inline-block">{d.job.code}</span>{d.sent > 0 && <> · {L(`أُرسل ${d.sent} اختباراً`, `${d.sent} sent`)}</>}</>}
      actions={editable && <>
        <Btn icon={save.isPending ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />} disabled={save.isPending || !dirty} onClick={() => submit(false)}>{L('حفظ كمسودة', 'Save draft')}</Btn>
        <Btn kind="primary" icon={<BadgeCheck size={16} />} disabled={save.isPending || !qs.length} onClick={() => submit(true)}>{L('حفظ واعتماد', 'Save & approve')}</Btn></>} />

    <Panel title={L('الإعداد', 'Settings')} action={d.test ? (d.test.status === 'approved' && !dirty ? <Badge tone="brand"><BadgeCheck size={13} />{L('معتمد', 'Approved')}</Badge> : <Badge tone="brass">{L('مسودة', 'Draft')}</Badge>) : undefined}>
      <fieldset disabled={!editable} className="min-w-0 grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <Field label={L('الاختبار', 'Test')} div><Seg value={s.enabled ? 'on' : 'off'} onChange={v => setS({ ...s, enabled: v === 'on' })} options={[{ v: 'on', label: L('مفعّل', 'Enabled') }, { v: 'off', label: L('متوقف', 'Off') }]} /></Field>
        <Field label={L('يُرسل إلى', 'Send to')} div><Seg value={s.audience} onChange={v => setS({ ...s, audience: v })} options={[{ v: 'passed', label: L('المطابقين فوق الحد', 'Above the match gate') }, { v: 'all', label: L('كل المتقدمين', 'Every applicant') }]} /></Field>
        <Field label={L('المدة (دقيقة)', 'Duration (min)')}><input type="number" min={5} max={180} className="field num" value={s.minutes} onChange={e => setS({ ...s, minutes: +e.target.value })} /></Field>
        <Field label={L('عدد المحاولات', 'Attempts')}><select className="field" value={s.attempts} onChange={e => setS({ ...s, attempts: +e.target.value })}>{[1, 2, 3].map(n => <option key={n} value={n}>{n}</option>)}</select></Field>
        <Field label={L('صلاحية الرابط (يوم)', 'Link valid for (days)')}><input type="number" min={1} max={30} className="field num" value={s.valid_days} onChange={e => setS({ ...s, valid_days: +e.target.value })} /></Field>
      </fieldset>
      <p className="text-[12.5px] text-ink-3 mt-3">{L('النتيجة تظهر بجانب نسبة المطابقة لا بديلاً عنها، ولا يُرفض أي متقدم آلياً بسبب الاختبار.', 'The result shows next to the match score, never instead of it, and no applicant is rejected automatically because of the test.')}</p>
    </Panel>

    <Panel title={<span className="flex items-center gap-2">{L('الأسئلة', 'Questions')} <span className="text-[12px] text-ink-3 font-normal num">{qs.length} · {L(`${total} درجة`, `${total} points`)}</span></span>}>
      {qs.length === 0 ? <Empty title={L('لا أسئلة بعد', 'No questions yet')}>{L('أضف الأسئلة من الزر أدناه.', 'Add questions with the button below.')}</Empty> :
        <ol className="space-y-4">{qs.map((q, i) => <li key={q.id} className="rounded-xl border border-line p-4">
          <fieldset disabled={!editable} className="space-y-3 min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <span className="size-7 rounded-full bg-brand-soft text-brand grid place-items-center text-[13px] font-bold num">{i + 1}</span>
              <select className="field !h-8 !w-auto text-[13px]" value={q.type} aria-label={L('نوع السؤال', 'Question type')} onChange={e => { const t = e.target.value as QType; setQ(i, { type: t, options: t === 'mcq' ? (q.options.length ? q.options : ['', '', '', '']) : [], answer: t === 'mcq' ? 0 : -1 }) }}>
                <option value="mcq">{L('اختيار من متعدد', 'Multiple choice')}</option><option value="short">{L('إجابة قصيرة', 'Short answer')}</option><option value="scenario">{L('سيناريو عملي', 'Scenario')}</option></select>
              <label className="flex items-center gap-1.5 text-[12.5px] text-ink-3">{L('الدرجة', 'Points')}<input type="number" min={1} max={100} className="field !h-8 !w-20 num" value={q.points} onChange={e => setQ(i, { points: +e.target.value })} /></label>
              {editable && <span className="ms-auto flex gap-1">
                <Btn size="sm" kind="ghost" disabled={i === 0} onClick={() => move(i, -1)} aria-label={L('أعلى', 'Up')}><ChevronUp size={15} /></Btn>
                <Btn size="sm" kind="ghost" disabled={i === qs.length - 1} onClick={() => move(i, 1)} aria-label={L('أسفل', 'Down')}><ChevronDown size={15} /></Btn>
                <Btn size="sm" kind="ghost" onClick={() => setQs(x => x.filter((_, j) => j !== i))} aria-label={L('حذف السؤال', 'Delete question')}><Trash2 size={15} className="text-danger" /></Btn></span>}
            </div>
            <textarea className="field min-h-[64px]" value={q.text} placeholder={L('نص السؤال', 'Question text')} onChange={e => setQ(i, { text: e.target.value })} aria-label={L('نص السؤال', 'Question text')} />
            {err?.field(`questions.${i}.text`) && <div className="text-[12px] text-danger">{err.field(`questions.${i}.text`)}</div>}
            {q.type === 'mcq' ? <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">{q.options.map((o, k) => <label key={k} className={`min-w-0 flex items-center gap-2 rounded-lg border px-2.5 py-1.5 ${q.answer === k ? 'border-brand bg-brand-soft/60' : 'border-line'}`}>
              <input type="radio" name={`ans-${q.id}`} checked={q.answer === k} onChange={() => setQ(i, { answer: k })} className="accent-[var(--primary)]" aria-label={L('الإجابة الصحيحة', 'Correct answer')} />
              <input className="flex-1 min-w-0 w-full bg-transparent outline-none text-[14px]" value={o} placeholder={L(`الخيار ${k + 1}`, `Option ${k + 1}`)} onChange={e => setQ(i, { options: q.options.map((x, m) => m === k ? e.target.value : x) })} />
              {q.options.length > 2 && editable && <button type="button" onClick={() => setQ(i, { options: q.options.filter((_, m) => m !== k), answer: q.answer >= k && q.answer > 0 ? q.answer - 1 : q.answer })} className="text-ink-3 hover:text-danger" aria-label={L('حذف الخيار', 'Remove option')}><Trash2 size={13} /></button>}
            </label>)}{q.options.length < 6 && editable && <button type="button" onClick={() => setQ(i, { options: [...q.options, ''] })} className="text-[13px] text-brand font-semibold text-start px-2">+ {L('خيار', 'Option')}</button>}</div>
              : <Field label={L('معيار التصحيح (يرشد مراجعة HR)', 'Grading rubric (guides the HR review)')}><textarea className="field min-h-[52px]" value={q.rubric} onChange={e => setQ(i, { rubric: e.target.value })} /></Field>}
          </fieldset>
        </li>)}</ol>}
      {editable && <div className="flex flex-wrap gap-2 mt-4">
        <Btn size="sm" icon={<Plus size={14} />} onClick={() => setQs(x => [...x, blank('mcq')])}>{L('اختيار من متعدد', 'Multiple choice')}</Btn>
        <Btn size="sm" icon={<Plus size={14} />} onClick={() => setQs(x => [...x, blank('short')])}>{L('إجابة قصيرة', 'Short answer')}</Btn>
        <Btn size="sm" icon={<Plus size={14} />} onClick={() => setQs(x => [...x, blank('scenario')])}>{L('سيناريو', 'Scenario')}</Btn></div>}
    </Panel>
  </div>
}
