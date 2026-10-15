import { useState } from 'react'
import { Loader2 } from 'lucide-react'
import { useL, useLang, bi, fmtDate, todayISO } from '../../../lib/store'
import { useMe } from '../../../lib/session'
import { F25, f25Label, f25Score, useInterview, type CandFile } from '../../../lib/candidates'
import type { ApiError } from '../../../lib/api'
import { Btn, Field, toast } from '../../../ui/kit'
import { Paper } from '../../../ui/docs'

/** تقييم المقابلة الأولية F-25 (InterviewForm في الديمو) — المعاينة فورية والنتيجة المعتمدة من الخادم */
export function InterviewForm({ c, readOnly }: { c: CandFile; readOnly: boolean }) {
  const L = useL(); const lang = useLang(); const P = lang === 'ar' ? '٪' : '%'
  const me = useMe().data
  const save = useInterview(c.id)
  const closed = c.stage === 'hired' || c.stage === 'rejected'
  const locked = readOnly || closed
  const [r, setR] = useState<Record<string, number>>(() => c.interview?.ratings ?? Object.fromEntries(F25.flatMap(g => g.items.map(i => [i.id, 3]))))
  const [notes, setNotes] = useState(c.interview?.notes ?? '')
  const [rec, setRec] = useState<'recommended' | 'reserve' | 'rejected'>(c.interview?.recommendation ?? 'recommended')
  const sc = f25Score(r)
  const submit = () => save.mutate({ ratings: r, notes, recommendation: rec }, {
    onSuccess: d => toast(d.stage === 'technical' && c.stage !== 'technical' ? L('حُفظ التقييم ونُقل للتقييم الفني', 'Saved — moved to technical review') : L('حُفظ التقييم', 'Evaluation saved')),
    onError: e => toast((e as ApiError).message),
  })
  return <Paper code="F-25" subject={c.ref} title={L('تقييم المقابلة الأولية', 'HR initial interview evaluation')}
    actions={!locked && <Btn size="sm" kind="primary" disabled={save.isPending} icon={save.isPending ? <Loader2 size={14} className="animate-spin" /> : undefined} onClick={submit}>{L('اعتماد التقييم', 'Save evaluation')}</Btn>}>
    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">{[[L('المتقدم', 'Applicant'), c.name_ar], [L('الوظيفة', 'Position'), bi(c.job.title, lang)], [L('الإدارة', 'Department'), bi(c.job.dept, lang)], [L('تاريخ المقابلة', 'Date'), fmtDate(c.interview?.date ?? todayISO(), lang)]].map(([k, v]) =>
      <div key={k} className="border border-line rounded-md p-2.5 min-w-0"><div className="text-[12px] text-ink-3">{k}</div><div className="font-medium truncate">{v}</div></div>)}</div>
    <div className="grid lg:grid-cols-2 gap-5">
      {F25.map((g, gi) => <div key={g.key} className="border border-line rounded-md">
        <div className="flex justify-between gap-2 px-3 h-10 items-center border-b border-line-2 bg-sub"><span className="font-semibold text-[14px] truncate">{gi + 1}. {g[lang]}</span><span className="num text-[13px] shrink-0">{sc.cats[gi].toFixed(1)} / 7.5</span></div>
        <div className="p-3 space-y-2">{g.items.map(it => <div key={it.id} className="grid grid-cols-[1fr_auto] items-center gap-3 text-[13px]">
          <span>{it[lang]}</span>
          <div className="flex gap-1" role="radiogroup" aria-label={it[lang]}>{[1, 2, 3, 4, 5].map(v => <button key={v} type="button" role="radio" aria-checked={r[it.id] === v} disabled={locked} onClick={() => setR({ ...r, [it.id]: v })}
            className={`size-7 rounded border text-[12px] num disabled:cursor-default ${r[it.id] === v ? 'bg-ink text-canvas border-ink' : r[it.id] > v ? 'bg-sub border-line' : 'border-line bg-surface'}`}>{v}</button>)}</div>
        </div>)}</div>
      </div>)}
    </div>
    <div className="grid lg:grid-cols-[1fr_280px] gap-5 mt-5">
      <div className="space-y-3">
        <Field label={L('انطباعات اللجنة وملاحظات التوصية', 'Panel notes')}><textarea className="field" disabled={locked} value={notes} onChange={e => setNotes(e.target.value)} maxLength={2000} /></Field>
        <div className="flex flex-wrap gap-2">{([['recommended', L('موصى به لعرض العمل', 'Recommend for offer')], ['reserve', L('احتياط', 'Reserve')], ['rejected', L('غير موصى به', 'Not recommended')]] as const).map(([v, t]) =>
          <button key={v} type="button" disabled={locked} onClick={() => setRec(v)} aria-pressed={rec === v} className={`h-9 px-3 rounded-md border text-[13px] disabled:cursor-default ${rec === v ? (v === 'rejected' ? 'bg-danger text-on-brand border-danger' : 'bg-brand text-on-brand border-brand') : 'border-line bg-surface'}`}>{t}</button>)}</div>
      </div>
      <div className="border-2 border-ink rounded-md p-4 text-center"><div className="text-[12px] text-ink-3">{L('النتيجة الإجمالية', 'Total score')}</div><div className="text-[30px] font-bold num">{sc.total.toFixed(1)} <span className="text-[16px] text-ink-3">/ 30</span></div>
        <div className="text-[13px] font-medium"><span className="num">{sc.pct}{P}</span> — {f25Label(sc.pct)[lang]}</div>
        <div className="text-[12px] text-ink-3 mt-2">{L('المقابِل', 'Interviewer')}: {c.interview?.interviewer ?? bi(me?.name, lang)}</div></div>
    </div>
  </Paper>
}
