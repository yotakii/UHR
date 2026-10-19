import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { Clock, Loader2, CheckCircle2, AlertTriangle, Send, PlayCircle } from 'lucide-react'
import { useL, useLang, bi, fmtDateTime } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { usePublicTest, useStartTest, useSubmitTest } from '../../lib/tests'
import { Btn, Empty, toast } from '../../ui/kit'

type Answers = Record<string, number | string | null>
const store = (token: string) => `uhr-test-${token.slice(0, 12)}`
const load = (token: string): Answers => { try { return JSON.parse(localStorage.getItem(store(token)) ?? '{}') } catch { return {} } }
const keep = (token: string, a: Answers) => { try { localStorage.setItem(store(token), JSON.stringify(a)) } catch { /* وضع خاص */ } }

/**
 * صفحة الاختبار الأولي للمتقدم (/test/{token}): مقدمة ← «بدء الاختبار» (يبدأ الوقت على الخادم) ← الأسئلة مع عدّاد
 * ← إرسال (أو إرسال تلقائي عند انتهاء الوقت). الإجابات تُحفظ في المتصفح حتى لا تضيع عند التحديث.
 */
export function TakeTest({ token }: { token: string }) {
  const L = useL(); const lang = useLang()
  const { data: t, isLoading, error } = usePublicTest(token)
  const start = useStartTest(token); const submit = useSubmitTest(token)
  const [answers, setAnswers] = useState<Answers>(() => load(token))
  const [left, setLeft] = useState<number | null>(null)
  const sent = useRef(false)
  const skew = useMemo(() => t ? new Date(t.now).getTime() - Date.now() : 0, [t?.now])   // فرق ساعة الجهاز عن الخادم

  const send = () => { if (sent.current || !t) return; sent.current = true
    submit.mutate(answers, { onSuccess: () => { try { localStorage.removeItem(store(token)) } catch { /* */ } }, onError: x => { sent.current = false; toast((x as ApiError).message) } }) }

  useEffect(() => {
    if (t?.status !== 'started' || !t.deadline_at) return
    const end = new Date(t.deadline_at).getTime()
    const tick = () => { const s = Math.max(0, Math.round((end - Date.now() - skew) / 1000)); setLeft(s); if (s === 0) send() }
    tick(); const id = setInterval(tick, 1000); return () => clearInterval(id)
  }, [t?.status, t?.deadline_at, skew])   // eslint-disable-line react-hooks/exhaustive-deps

  const set = (id: string, v: number | string) => setAnswers(a => { const n = { ...a, [id]: v }; keep(token, n); return n })
  const wrap = (body: ReactNode) => <div className="max-w-[820px] mx-auto px-4 sm:px-6 py-8">{body}</div>
  if (isLoading) return wrap(<div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>)
  if (error || !t) return wrap(<div className="bg-surface border border-line rounded-2xl"><Empty title={(error as ApiError)?.message ?? L('رابط الاختبار غير صالح.', 'This test link is not valid.')} action={<a href="/jobs" className="text-brand font-semibold">{L('فرص العمل', 'Browse jobs')}</a>} /></div>)

  const header = <div className="bg-surface border border-line rounded-2xl p-5 flex flex-wrap items-center gap-4 mb-5">
    {t.company.logo ? <img src={t.company.logo} alt="" className="h-12 w-auto max-w-[140px] object-contain bg-white rounded-lg p-1" /> : null}
    <div className="flex-1 min-w-[200px]"><div className="text-[12.5px] text-ink-3">{bi(t.company, lang)}</div>
      <h1 className="text-[20px] font-bold">{L('الاختبار الأولي', 'Preliminary test')} — {bi(t.job, lang)}</h1></div>
    {t.status === 'started' && left !== null && <div className={`flex items-center gap-2 rounded-xl px-4 py-2 font-bold num text-[20px] ${left < 120 ? 'bg-danger-soft text-danger' : 'bg-brand-soft text-brand'}`} role="timer" aria-live="off">
      <Clock size={18} /><bdi dir="ltr">{Math.floor(left / 60)}:{String(left % 60).padStart(2, '0')}</bdi></div>}
  </div>

  if (t.status === 'completed' || t.status === 'reviewed') return wrap(<>{header}<div className="bg-surface border border-line rounded-2xl"><Empty title={<span className="inline-flex items-center gap-2 text-brand"><CheckCircle2 size={20} />{L('وصلت إجاباتك — شكراً لك', 'Your answers were received — thank you')}</span>}>
    {L('تُراجع الموارد البشرية النتيجة مع بقية طلبك، وستصلك أي خطوة تالية بالبريد ومن حسابك.', 'HR reviews the result together with your application. You will hear about any next step by email and in your account.')}</Empty></div></>)
  if (t.status === 'expired') return wrap(<>{header}<div className="bg-surface border border-line rounded-2xl"><Empty title={<span className="inline-flex items-center gap-2 text-warn"><AlertTriangle size={20} />{L('انتهت مدة هذا الاختبار', 'This test has expired')}</span>}>
    {L('يمكنك متابعة طلبك من حسابك، وقد تتواصل معك الموارد البشرية بخصوص محاولة أخرى.', 'You can follow your application from your account; HR may contact you about another attempt.')}</Empty></div></>)

  if (t.status === 'sent') return wrap(<>{header}<section className="bg-surface border border-line rounded-2xl p-6 space-y-4">
    <p className="text-[15px]">{L(`أهلاً ${t.name}،`, `Hello ${t.name},`)}</p>
    <ul className="text-[14px] text-ink-2 space-y-1.5 list-disc ps-5">
      <li>{L(`عدد الأسئلة ${t.count}، والمدة ${t.minutes} دقيقة تبدأ عند الضغط على «بدء الاختبار».`, `${t.count} questions, ${t.minutes} minutes starting when you press "Start the test".`)}</li>
      <li>{L('أجب بنفسك ومن معرفتك. تُرسل الإجابات تلقائياً عند انتهاء الوقت.', 'Answer on your own, from your own knowledge. Answers are sent automatically when time is up.')}</li>
      <li>{L('الرابط صالح حتى', 'The link is valid until')} <span className="num">{fmtDateTime(t.expires_at, lang)}</span>.</li>
    </ul>
    <Btn kind="primary" size="lg" icon={start.isPending ? <Loader2 size={18} className="animate-spin" /> : <PlayCircle size={18} />} disabled={start.isPending} onClick={() => start.mutate(undefined, { onError: x => toast((x as ApiError).message) })}>{L('بدء الاختبار', 'Start the test')}</Btn>
  </section></>)

  const done = t.questions.filter(q => answers[q.id] !== undefined && answers[q.id] !== null && answers[q.id] !== '').length
  return wrap(<>{header}<ol className="space-y-4">{t.questions.map((q, i) => <li key={q.id} className="bg-surface border border-line rounded-2xl p-5">
    <div className="flex items-start gap-3 mb-3"><span className="size-7 rounded-full bg-brand-soft text-brand grid place-items-center text-[13px] font-bold num shrink-0">{i + 1}</span>
      <div className="flex-1 text-[15px] font-medium whitespace-pre-wrap">{q.text}</div><span className="text-[12px] text-ink-3 num shrink-0">{q.points} {L('درجة', 'pts')}</span></div>
    {q.type === 'mcq' ? <div className="grid gap-2">{q.options.map((o, k) => <label key={k} className={`flex items-center gap-3 rounded-xl border px-3 py-2.5 cursor-pointer text-[14.5px] ${answers[q.id] === k ? 'border-brand bg-brand-soft' : 'border-line hover:border-brand/40'}`}>
      <input type="radio" name={q.id} checked={answers[q.id] === k} onChange={() => set(q.id, k)} className="accent-[var(--primary)] size-4" />{o}</label>)}</div>
      : <textarea className="field min-h-[110px]" maxLength={4000} value={(answers[q.id] as string) ?? ''} onChange={e => set(q.id, e.target.value)} placeholder={L('اكتب إجابتك…', 'Write your answer…')} aria-label={L(`إجابة السؤال ${i + 1}`, `Answer to question ${i + 1}`)} />}
  </li>)}</ol>
    <div className="sticky bottom-0 mt-5 bg-canvas/90 backdrop-blur border-t border-line py-3 flex flex-wrap items-center justify-between gap-3">
      <span className="text-[13px] text-ink-2 num">{L(`أجبت عن ${done} من ${t.questions.length}`, `Answered ${done} of ${t.questions.length}`)}</span>
      <Btn kind="primary" icon={submit.isPending ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />} disabled={submit.isPending}
        onClick={() => { if (done < t.questions.length && !confirm(L('بعض الأسئلة بلا إجابة. إرسال على أي حال؟', 'Some questions are unanswered. Submit anyway?'))) return; send() }}>{L('إرسال الإجابات', 'Submit answers')}</Btn>
    </div></>)
}
