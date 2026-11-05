import { useState } from 'react'
import { Plus, Repeat2, Trash2, X } from 'lucide-react'
import { useL, useLang, bi } from '../../../lib/store'
import type { CandFile, InterviewAnswers } from '../../../lib/candidates'

const Score = ({ v, set, locked, label }: { v: number | null; set: (n: number | null) => void; locked: boolean; label: string }) =>
  <div className="flex gap-1 shrink-0" role="radiogroup" aria-label={label}>{[1, 2, 3, 4, 5].map(n => <button key={n} type="button" role="radio" aria-checked={v === n} disabled={locked} onClick={() => set(v === n ? null : n)}
    className={`size-7 rounded border text-[12px] num disabled:cursor-default ${v === n ? 'bg-ink text-canvas border-ink' : v && v > n ? 'bg-sub border-line' : 'border-line bg-surface'}`}>{n}</button>)}</div>

/** الإجابات الأولية من خطة الشاغر (أو بنك الشركة) */
export function initialAnswers(c: CandFile, lang: 'ar' | 'en'): InterviewAnswers {
  return c.interview?.answers ?? { questions: c.interview_plan.questions.map(q => ({ q: bi(q, lang), note: '', score: null })), skills: c.interview_plan.skills.map(s => ({ s: bi(s, lang), score: null, note: '' })) }
}

/**
 * أسئلة المقابلة والمهارات الخاصة بالشاغر (ملاحظتا الاختبار 30 و58): دليل للمقابِل بجانب نموذج F-25 —
 * يمكن تبديل السؤال من بنك الأسئلة أو كتابة غيره، وتسجيل ملاحظة ودرجة اختيارية لكل سؤال ومهارة. لا تغيّر نتيجة F-25 الرسمية.
 */
export function InterviewPlan({ c, a, set, locked }: { c: CandFile; a: InterviewAnswers; set: (a: InterviewAnswers) => void; locked: boolean }) {
  const L = useL(); const lang = useLang()
  const [swap, setSwap] = useState<number | null>(null)
  const scored = a.skills.filter(s => s.score)
  const avg = scored.length ? scored.reduce((x, s) => x + (s.score ?? 0), 0) / scored.length : null
  const q = (i: number, p: Partial<InterviewAnswers['questions'][number]>) => set({ ...a, questions: a.questions.map((x, j) => j === i ? { ...x, ...p } : x) })
  const sk = (i: number, p: Partial<InterviewAnswers['skills'][number]>) => set({ ...a, skills: a.skills.map((x, j) => j === i ? { ...x, ...p } : x) })
  const used = new Set(a.questions.map(x => x.q))
  return <div className="grid lg:grid-cols-[1.4fr_1fr] gap-5 mt-5">
    <div className="border border-line rounded-md">
      <div className="flex justify-between items-center gap-2 px-3 h-10 border-b border-line-2 bg-sub"><span className="font-semibold text-[14px]">{L('أسئلة المقابلة', 'Interview questions')}</span>
        <span className="text-[12px] text-ink-3">{c.interview_plan.from_job ? L('من إعلان الشاغر', 'From the job') : L('من بنك أسئلة الشركة', 'From the company bank')}</span></div>
      <ol className="p-3 space-y-3">{a.questions.map((x, i) => <li key={i} className="text-[13px]">
        <div className="flex items-start gap-2"><span className="num text-ink-3 mt-1.5 w-4 shrink-0">{i + 1}.</span>
          {locked ? <span className="flex-1 mt-1">{x.q}</span> : <textarea rows={1} className="field flex-1 !min-h-0 !py-1.5 text-[13px]" value={x.q} onChange={e => q(i, { q: e.target.value })} aria-label={L('نص السؤال', 'Question text')} />}
          {!locked && <><button type="button" className="p-1.5 mt-0.5 rounded hover:bg-sub text-ink-3" title={L('تبديل السؤال', 'Swap question')} onClick={() => setSwap(swap === i ? null : i)}><Repeat2 size={15} /></button>
            <button type="button" className="p-1.5 mt-0.5 rounded hover:bg-sub text-ink-3 hover:text-danger" title={L('حذف', 'Remove')} onClick={() => set({ ...a, questions: a.questions.filter((_, j) => j !== i) })}><Trash2 size={15} /></button></>}
        </div>
        {swap === i && <div className="ms-6 mt-1.5 rounded-md border border-line bg-sub/50 p-2">
          <div className="flex justify-between items-center mb-1 text-[12px] text-ink-3">{L('اختر سؤالاً بديلاً من البنك', 'Pick a replacement from the bank')}<button type="button" onClick={() => setSwap(null)} aria-label={L('إغلاق', 'Close')}><X size={14} /></button></div>
          {c.interview_plan.bank.filter(b => !used.has(bi(b, lang))).length === 0
            ? <p className="text-[12.5px] text-ink-3 px-2 py-1">{L('كل أسئلة البنك مستخدمة في هذه المقابلة — عدّل نص السؤال مباشرة، أو أضف أسئلة للبنك من «قواعد التوظيف والمطابقة».', 'All bank questions are already in use — edit the question text directly, or add questions to the bank in “Recruitment & matching rules”.')}</p>
            : <ul className="space-y-1 max-h-48 overflow-y-auto">{c.interview_plan.bank.filter(b => !used.has(bi(b, lang))).map((b, k) =>
            <li key={k}><button type="button" className="text-start w-full rounded px-2 py-1 hover:bg-surface" onClick={() => { q(i, { q: bi(b, lang) }); setSwap(null) }}>{bi(b, lang)}</button></li>)}</ul>}
        </div>}
        <div className="ms-6 mt-1.5 flex flex-wrap items-center gap-2">
          <input className="field flex-1 min-w-[180px] h-8 text-[12.5px]" placeholder={L('ملاحظة على الإجابة', 'Note on the answer')} disabled={locked} value={x.note} maxLength={1000} onChange={e => q(i, { note: e.target.value })} />
          <Score v={x.score} set={n => q(i, { score: n })} locked={locked} label={x.q} />
        </div>
      </li>)}</ol>
      {!locked && a.questions.length < 20 && <button type="button" className="mx-3 mb-3 text-brand text-[13px] font-semibold inline-flex items-center gap-1" onClick={() => set({ ...a, questions: [...a.questions, { q: '', note: '', score: null }] })}><Plus size={14} />{L('سؤال إضافي', 'Add a question')}</button>}
    </div>
    <div className="border border-line rounded-md self-start">
      <div className="flex justify-between items-center gap-2 px-3 h-10 border-b border-line-2 bg-sub"><span className="font-semibold text-[14px]">{L('المهارات المطلوب قياسها', 'Skills to assess')}</span>
        {avg !== null && <span className="num text-[13px]">{avg.toFixed(1)} / 5</span>}</div>
      <ul className="p-3 space-y-2.5">{a.skills.map((x, i) => <li key={i} className="text-[13px]">
        <div className="flex items-center gap-2 justify-between"><span className="min-w-0">{x.s}</span><Score v={x.score} set={n => sk(i, { score: n })} locked={locked} label={x.s} /></div>
        {!locked && <input className="field h-8 mt-1 text-[12.5px]" placeholder={L('ملاحظة (اختيارية)', 'Note (optional)')} value={x.note} maxLength={500} onChange={e => sk(i, { note: e.target.value })} />}
        {locked && x.note && <div className="text-[12px] text-ink-3 mt-0.5">{x.note}</div>}
      </li>)}{a.skills.length === 0 && <li className="text-[13px] text-ink-3">{L('لم تُحدَّد مهارات لهذا الشاغر.', 'No skills set for this job.')}</li>}</ul>
      <p className="px-3 pb-3 text-[12px] text-ink-3">{L('الدرجات هنا للاسترشاد ولا تغيّر نتيجة نموذج F-25.', 'These scores guide the panel and do not change the F-25 result.')}</p>
    </div>
  </div>
}
