import { useEffect, useState } from 'react'
import { Send, Loader2, Check, X, Sparkles, BadgeCheck } from 'lucide-react'
import { useL, useLang, fmtDateTime } from '../../../lib/store'
import { can, useMe } from '../../../lib/session'
import { ApiError } from '../../../lib/api'
import type { CandFile } from '../../../lib/candidates'
import { useReviewTest, useSendTest, type TestStatus } from '../../../lib/tests'
import { Badge, Btn, Empty, Field, Panel, toast } from '../../../ui/kit'

export const testStatus = (s: TestStatus, L: (a: string, e: string) => string): [string, 'neutral' | 'brand' | 'brass' | 'info' | 'warn' | 'danger'] =>
  s === 'sent' ? [L('أُرسل الرابط', 'Link sent'), 'info'] : s === 'started' ? [L('جارٍ', 'In progress'), 'info'] : s === 'completed' ? [L('مكتمل — بانتظار المراجعة', 'Completed — awaiting review'), 'brass']
    : s === 'reviewed' ? [L('مُراجَع', 'Reviewed'), 'brand'] : [L('غير مكتمل', 'Incomplete'), 'warn']

/**
 * تبويب «الاختبار الأولي» في ملف المتقدم (D-34): الحالة والمهلة، إرسال/إعادة إرسال ضمن المحاولات،
 * الإجابات مع التصحيح الآلي للموضوعية والتقييم المقترح للمفتوحة، ومراجعة HR بالدرجة النهائية.
 */
export function TestTab({ c }: { c: CandFile }) {
  const L = useL(); const lang = useLang()
  const editable = can(useMe().data, 'candidates', 'edit')
  const d = c.test_detail; const t = d.current
  const send = useSendTest(c.id); const review = useReviewTest(c.id)
  const [scores, setScores] = useState<Record<string, number>>({})
  const [note, setNote] = useState('')
  useEffect(() => {
    if (!t) return
    setScores(Object.fromEntries(t.questions.filter(q => q.type !== 'mcq').map(q => [q.id, t.scores[q.id] ?? t.suggested[q.id]?.score ?? 0])))
    setNote(t.review_note ?? '')
  }, [t])
  const closed = ['hired', 'rejected'].includes(c.stage)
  const canSend = editable && d.approved && !closed && (!t || (t.status === 'expired' && t.attempt < d.attempts))
  const doSend = () => send.mutate(undefined, { onSuccess: () => toast(L('أُرسل رابط الاختبار للمتقدم', 'Test link sent to the applicant')), onError: x => toast((x as ApiError).message) })

  if (!t) return <Panel><Empty title={L('لم يُرسل اختبار لهذا المتقدم', 'No test sent to this applicant')}
    action={canSend && <Btn kind="primary" icon={<Send size={15} />} disabled={send.isPending} onClick={doSend}>{L('إرسال الاختبار الأولي', 'Send the preliminary test')}</Btn>}>
    {!d.approved ? L('لا يوجد اختبار معتمد لهذا الشاغر — أعدّه من «الوظائف الشاغرة».', 'This position has no approved test — set it up from "Job openings".')
      : !d.enabled ? L('الإرسال التلقائي متوقف لهذا الشاغر، ويمكن الإرسال يدوياً.', 'Automatic sending is off for this position; you can send it manually.') : L('يُرسل تلقائياً حسب إعداد الشاغر، أو يدوياً من هنا.', 'It is sent automatically per the position settings, or manually from here.')}</Empty></Panel>

  const [label, tone] = testStatus(t.status, L)
  const total = t.questions.reduce((a, q) => a + q.points, 0)
  const live = Object.entries(scores).reduce((a, [, v]) => a + (v || 0), 0) + t.questions.filter(q => q.type === 'mcq').reduce((a, q) => a + (t.scores[q.id] ?? 0), 0)
  const answered = ['completed', 'reviewed'].includes(t.status)
  return <div className="space-y-4">
    <Panel>
      <div className="flex flex-wrap items-center gap-x-6 gap-y-3">
        <div><div className="text-[12px] text-ink-3">{L('الحالة', 'Status')}</div><Badge tone={tone}>{label}</Badge></div>
        <div><div className="text-[12px] text-ink-3">{L('المحاولة', 'Attempt')}</div><div className="font-semibold num">{t.attempt} / {d.attempts}</div></div>
        <div><div className="text-[12px] text-ink-3">{L('أُرسل', 'Sent')}</div><div className="text-[13.5px] num">{fmtDateTime(t.sent_at, lang)}</div></div>
        {t.submitted_at ? <div><div className="text-[12px] text-ink-3">{L('أُرسلت الإجابات', 'Submitted')}</div><div className="text-[13.5px] num">{fmtDateTime(t.submitted_at, lang)}</div></div>
          : <div><div className="text-[12px] text-ink-3">{L('الرابط صالح حتى', 'Link valid until')}</div><div className="text-[13.5px] num">{fmtDateTime(t.expires_at, lang)}</div></div>}
        {t.auto_pct !== null && <div><div className="text-[12px] text-ink-3">{L('الأسئلة الموضوعية', 'Objective questions')}</div><div className="font-bold num text-[18px]">{t.auto_pct}%</div></div>}
        {t.pct !== null && <div><div className="text-[12px] text-ink-3">{L('النتيجة النهائية', 'Final result')}</div><div className="font-black num text-[22px] text-brand">{t.pct}%</div></div>}
        <div className="ms-auto">{canSend && <Btn icon={<Send size={15} />} disabled={send.isPending} onClick={doSend}>{L('إرسال محاولة جديدة', 'Send a new attempt')}</Btn>}</div>
      </div>
      <p className="text-[12.5px] text-ink-3 mt-3">{L('النتيجة إلى جانب نسبة المطابقة، والقرار لفريق التوظيف — لا رفض آلي بالاختبار.', 'The result sits beside the match score; the decision stays with the hiring team — no automatic rejection by test.')}</p>
    </Panel>

    {answered ? <Panel title={L('الإجابات والتصحيح', 'Answers & grading')} sub={L(`المجموع الحالي ${live} من ${total}`, `Current total ${live} of ${total}`)}>
      <ol className="space-y-4">{t.questions.map((q, i) => { const a = t.answers[q.id]
        return <li key={q.id} className="rounded-xl border border-line p-4">
          <div className="flex items-start gap-2"><span className="size-6 rounded-full bg-sub grid place-items-center text-[12px] font-bold num shrink-0">{i + 1}</span>
            <div className="flex-1 min-w-0"><div className="font-medium text-[14.5px] whitespace-pre-wrap">{q.text}</div><div className="text-[12px] text-ink-3 num">{q.points} {L('درجة', 'pts')}</div></div></div>
          {q.type === 'mcq' ? <ul className="mt-3 grid sm:grid-cols-2 gap-1.5">{q.options.map((o, k) => { const chosen = a === k, right = q.answer === k
            return <li key={k} className={`flex items-center gap-2 rounded-lg border px-2.5 py-1.5 text-[13.5px] ${right ? 'border-brand bg-brand-soft/60' : chosen ? 'border-danger/50 bg-danger-soft/50' : 'border-line'}`}>
              {right ? <Check size={14} className="text-brand shrink-0" /> : chosen ? <X size={14} className="text-danger shrink-0" /> : <span className="size-3.5 shrink-0" />}<span className="flex-1">{o}</span>{chosen && <span className="text-[11px] text-ink-3">{L('إجابته', 'Chosen')}</span>}</li> })}
            {a === null || a === undefined ? <li className="text-[12.5px] text-ink-3">{L('لم يُجب', 'Not answered')}</li> : null}</ul>
            : <div className="mt-3 space-y-2">
              <div className="rounded-lg bg-sub px-3 py-2 text-[14px] whitespace-pre-wrap">{(a as string) || <span className="text-ink-3">{L('لم يُجب', 'Not answered')}</span>}</div>
              {q.rubric && <div className="text-[12.5px] text-ink-3"><span className="font-semibold">{L('المعيار:', 'Rubric:')}</span> {q.rubric}</div>}
              {t.suggested[q.id] && <div className="text-[12.5px] flex gap-1.5 items-start text-info"><Sparkles size={13} className="mt-0.5 shrink-0" /><span>{L('تقييم مقترح بالذكاء الاصطناعي', 'AI-suggested score')}: <b className="num">{t.suggested[q.id].score}/{q.points}</b> — {t.suggested[q.id].note}</span></div>}
              <label className="flex items-center gap-2 text-[13px]">{L('الدرجة المعتمدة', 'Approved score')}
                <input type="number" min={0} max={q.points} disabled={!editable} className="field !h-8 !w-20 num" value={scores[q.id] ?? 0} onChange={e => setScores(s => ({ ...s, [q.id]: Math.max(0, Math.min(q.points, +e.target.value)) }))} />
                <span className="text-ink-3 num">/ {q.points}</span></label>
            </div>}
        </li> })}</ol>
      {editable && <div className="mt-4 flex flex-wrap items-end gap-3">
        <Field label={L('ملاحظة المراجعة', 'Review note')} className="flex-1 min-w-[220px]"><input className="field" value={note} onChange={e => setNote(e.target.value)} maxLength={1000} /></Field>
        <Btn kind="primary" icon={review.isPending ? <Loader2 size={15} className="animate-spin" /> : <BadgeCheck size={15} />} disabled={review.isPending}
          onClick={() => review.mutate({ scores, note }, { onSuccess: r => toast(L(`اعتُمدت النتيجة ${r.test_detail.current?.pct}٪`, `Result approved: ${r.test_detail.current?.pct}%`)), onError: x => toast((x as ApiError).message) })}>
          {t.status === 'reviewed' ? L('تحديث المراجعة', 'Update review') : L('اعتماد النتيجة', 'Approve result')}</Btn></div>}
      {t.reviewed_at && <div className="text-[12.5px] text-ink-3 mt-2">{L('رُوجعت', 'Reviewed')} <span className="num">{fmtDateTime(t.reviewed_at, lang)}</span>{t.review_note && <> — {t.review_note}</>}</div>}
    </Panel> : <Panel><Empty title={t.status === 'expired' ? L('انتهت المهلة دون إكمال الاختبار', 'The test expired without completion') : L('بانتظار إجابات المتقدم', 'Waiting for the applicant\'s answers')}>
      {t.status === 'expired' ? (t.attempt < d.attempts ? L('يمكن إرسال محاولة جديدة.', 'You can send a new attempt.') : L('استُنفدت المحاولات المسموحة.', 'All allowed attempts are used.')) : L(`المدة ${t.minutes} دقيقة تبدأ عند فتح الأسئلة.`, `${t.minutes} minutes, starting when the questions are opened.`)}</Empty></Panel>}
  </div>
}
