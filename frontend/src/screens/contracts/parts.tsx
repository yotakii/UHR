import { useState } from 'react'
import { Check, CircleDashed, FileSignature, Printer } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { fmtDate } from '../../lib/dates'
import { ApiError } from '../../lib/api'
import { fileUrl } from '../../lib/console'
import { CASE_STATUS, OUTCOME, RECOMMEND, STATUS, useEvaluate, type CaseFull, type ContractRow } from '../../lib/contracts'
import { Badge, Field, Seg, SignaturePad, toast } from '../../ui/kit'
import { ConfirmSend } from '../../ui/decision'

export function ContractBadge({ s }: { s: ContractRow['status'] }) { const L = useL(); const x = STATUS[s]; return <Badge tone={x[2]}>{L(x[0], x[1])}</Badge> }
export function CaseBadge({ c }: { c: Pick<CaseFull, 'status' | 'outcome'> }) {
  const L = useL()
  if (c.status === 'closed' && c.outcome && OUTCOME[c.outcome]) return <Badge tone={['terminated', 'not_renewed'].includes(c.outcome) ? 'danger' : 'brand'}>{L(...OUTCOME[c.outcome])}</Badge>
  const x = CASE_STATUS[c.status]; return <Badge tone={x[2]}>{L(x[0], x[1])}</Badge>
}

/** المدة المتبقية بالأيام بلون حسب القرب */
export function Left({ d }: { d: number }) {
  const L = useL()
  const tone = d < 0 ? 'text-danger' : d <= 15 ? 'text-danger' : d <= 30 ? 'text-brass' : 'text-ink-2'
  return <span className={`num font-semibold ${tone}`}>{d < 0 ? L(`منذ ${-d} يوم`, `${-d} d ago`) : d === 0 ? L('اليوم', 'today') : L(`بعد ${d} يوم`, `in ${d} d`)}</span>
}

/** تواقيع العقد وحالة النسخ */
export function ContractSigs({ c }: { c: ContractRow }) {
  const L = useL(); const lang = useLang()
  return <div className="space-y-1.5">{c.signatures.map((s, i) => <div key={i} className="flex items-center gap-2 text-[13px]">
    {s.at ? <Check size={15} className="text-brand" /> : <CircleDashed size={15} className="text-ink-3" />}
    <span className={s.at ? '' : 'text-ink-3'}>{L(s.ar, s.en)}</span>{s.at && <span className="text-[12px] text-ink-3 num">{fmtDate(s.at, lang)}{s.paper ? ` · ${L('نسخة ورقية', 'paper copy')}` : ''}</span>}
  </div>)}{c.signatures.length === 0 && <div className="text-[13px] text-ink-3">{L('عقد مسجّل من بيانات سابقة دون تواقيع إلكترونية.', 'Recorded from earlier data without electronic signatures.')}</div>}
    {c.signed_file_id && <a className="text-brand text-[13px] font-semibold inline-flex items-center gap-1" href={fileUrl(c.signed_file_id)} target="_blank" rel="noreferrer"><FileSignature size={14} />{L('النسخة الموقعة', 'Signed copy')}</a>}</div>
}

/** نموذج التقييم: تقييم التجديد (تقدير عام + توصية + مبرر) أو تقييم التجربة بالعناصر العشرين (HR-05-F-03) */
export function EvalForm({ c, me = false, onDone }: { c: CaseFull; me?: boolean; onDone?: () => void }) {
  const L = useL(); const lang = useLang()
  const ev = useEvaluate(me)
  const scale = c.form.scale
  const [items, setItems] = useState<Record<string, string>>(c.evaluation?.items ?? {})
  const [just, setJust] = useState<Record<string, string>>(c.evaluation?.justify ?? {})
  const [rating, setRating] = useState(c.evaluation?.rating ?? '')
  const [rec, setRec] = useState(c.evaluation?.recommendation ?? '')
  const [notes, setNotes] = useState(c.evaluation?.notes ?? '')
  const err = ev.error instanceof ApiError ? ev.error : null
  const max = (c.form.items?.length ?? 0) * Math.max(...scale.map(s => s.score))
  const total = max ? Math.round(Object.values(items).reduce((a, g) => a + (scale.find(s => s.key === g)?.score ?? 0), 0) * 100 / max) : 0
  const submit = () => ev.mutate({ id: c.id, items, justify: just, rating, recommendation: rec, notes }, { onSuccess: () => { toast(L('حُفظ التقييم', 'Evaluation saved')); onDone?.() } })
  if (c.kind === 'renewal') return <div className="space-y-4">
    {me && c.summary && <SummaryLine c={c} />}
    <Field label={L('التقدير العام', 'Overall rating')} req error={err?.field('rating')} div><Seg value={rating} onChange={setRating} options={scale.map(s => ({ v: s.key, label: L(s.ar, s.en) }))} /></Field>
    <Field label={L('التوصية', 'Recommendation')} req error={err?.field('recommendation')} div><Seg value={rec} onChange={setRec} options={['renew', 'permanent', 'not_renew'].map(k => ({ v: k, label: L(...RECOMMEND[k]) }))} /></Field>
    <Field label={L('المبرر', 'Reason')} req error={err?.field('notes')}><textarea className="field min-h-20" value={notes} onChange={e => setNotes(e.target.value)} /></Field>
    <ConfirmSend label={L('إرسال التقييم', 'Send the evaluation')} busy={ev.isPending} disabled={!rating || !rec} onConfirm={submit} />
    {err && !Object.keys(err.errors).length && <div role="alert" className="text-[13px] text-danger">{err.message}</div>}
  </div>
  return <div className="space-y-3">
    <div className="text-[13px] text-ink-2">{L('برّر أي تقدير غير «جيد». المجموع من 100 ويُحسب التقدير العام آلياً.', 'Justify any rating other than «good». The total is out of 100 and the overall grade is computed automatically.')}</div>
    <ol className="space-y-2">{(c.form.items ?? []).map((it, i) => {
      const g = items[it.key]; const need = g && g !== c.form.justify_other_than
      return <li key={it.key} className="rounded-xl border border-line p-3">
        <div className="flex flex-wrap items-center gap-2 justify-between"><span className="text-[13.5px] font-medium"><span className="num text-ink-3">{i + 1}.</span> {bi(it, lang)}</span>
          <div className="flex flex-wrap gap-1">{scale.map(s => <button key={s.key} type="button" onClick={() => setItems({ ...items, [it.key]: s.key })}
            className={`h-7 px-2 rounded-md border text-[12px] ${g === s.key ? 'bg-brand text-on-brand border-brand' : 'border-line hover:bg-sub'}`}>{L(s.ar, s.en)}</button>)}</div></div>
        {(need || just[it.key]) && <input className="field mt-2" placeholder={L('مبرر التقدير', 'Reason for the rating')} value={just[it.key] ?? ''} onChange={e => setJust({ ...just, [it.key]: e.target.value })} />}
        {(err?.field(`items.${it.key}`) || err?.field(`justify.${it.key}`)) && <div className="text-[12px] text-danger mt-1">{err?.field(`items.${it.key}`) ?? err?.field(`justify.${it.key}`)}</div>}
      </li>
    })}</ol>
    <div className="rounded-xl bg-sub px-3 py-2 text-[14px]">{L('المجموع', 'Total')}: <b className="num">{total}</b> / 100</div>
    <Field label={L('التوصية', 'Recommendation')} req error={err?.field('recommendation')} div><Seg value={rec} onChange={setRec} options={['confirm', 'not_confirm'].map(k => ({ v: k, label: L(...RECOMMEND[k]) }))} /></Field>
    <Field label={L('ملاحظات', 'Notes')}><textarea className="field min-h-16" value={notes} onChange={e => setNotes(e.target.value)} /></Field>
    <ConfirmSend label={L('إرسال التقييم', 'Send the evaluation')} busy={ev.isPending} disabled={!rec || Object.keys(items).length < (c.form.items?.length ?? 0)} onConfirm={submit} />
    {err && !Object.keys(err.errors).length && <div role="alert" className="text-[13px] text-danger">{err.message}</div>}
  </div>
}

export function SummaryLine({ c }: { c: CaseFull }) {
  const L = useL()
  const s = c.summary; if (!s) return null
  return <div className="rounded-xl bg-sub px-3 py-2 text-[13px] flex flex-wrap gap-x-4 gap-y-1">
    <span>{L('سنوات الخدمة التعاقدية المتواصلة', 'Continuous contract years')}: <b className="num">{s.years}</b></span>
    <span>{L('آخر تقييم أداء', 'Last performance score')}: <b className="num">{s.review ? s.review.score : '—'}</b></span>
    <span>{L('جزاءات خلال سنة', 'Penalties in a year')}: <b className="num">{s.penalties}</b></span>
    <span>{L('عدد العقود', 'Contracts')}: <b className="num">{s.contracts}</b></span>
  </div>
}

/** عرض التقييم المعبأ وتواقيعه */
export function EvalView({ c }: { c: CaseFull }) {
  const L = useL(); const lang = useLang()
  const ev = c.evaluation; if (!ev) return <div className="text-[13px] text-ink-3">{L('لم يُعبأ التقييم بعد.', 'Not filled yet.')}</div>
  const scale = Object.fromEntries(c.form.scale.map(s => [s.key, s]))
  return <div className="space-y-2 text-[13px]">
    {c.kind === 'probation' ? <>
      <div className="flex flex-wrap gap-2"><Badge tone="info">{L('المجموع', 'Total')} {ev.total}/100</Badge>{ev.grade && scale[ev.grade] && <Badge tone="brand">{L(scale[ev.grade].ar, scale[ev.grade].en)}</Badge>}
        <Badge>{L(...(RECOMMEND[ev.recommendation] ?? [ev.recommendation, ev.recommendation]))}</Badge></div>
      <details><summary className="cursor-pointer text-brand">{L('العناصر العشرون', 'The items')}</summary>
        <ol className="mt-2 space-y-1">{(c.form.items ?? []).map(it => <li key={it.key} className="flex gap-2"><span className="flex-1">{bi(it, lang)}</span><b>{ev.items?.[it.key] && scale[ev.items[it.key]] ? L(scale[ev.items[it.key]].ar, scale[ev.items[it.key]].en) : '—'}</b>
          {ev.justify?.[it.key] && <span className="text-ink-3">— {ev.justify[it.key]}</span>}</li>)}</ol></details>
    </> : <div className="flex flex-wrap gap-2">{ev.rating && scale[ev.rating] && <Badge tone="brand">{L(scale[ev.rating].ar, scale[ev.rating].en)}</Badge>}<Badge>{L(...(RECOMMEND[ev.recommendation] ?? [ev.recommendation, ev.recommendation]))}</Badge></div>}
    {ev.notes && <p className="text-ink-2">{ev.notes}</p>}
    {c.signatures.length > 0 && <div className="space-y-1">{c.signatures.map((s, i) => <div key={i} className="flex items-center gap-2">
      {s.at ? <Check size={14} className="text-brand" /> : <CircleDashed size={14} className="text-ink-3" />}
      {s.role === 'employee' ? L('توقيع الموظف', 'Employee') : L('توقيع مدير القسم', 'Department head')}{s.at && <span className="text-ink-3 num">· {fmtDate(s.at, lang)}{s.user ? ` · ${s.user}` : ''}</span>}
      {s.note && <span className="text-ink-3">«{s.note}»</span>}</div>)}</div>}
  </div>
}

/** توقيع الموظف على عقده بلوح التوقيع */
export function SignContract({ onSign, busy }: { onSign: (sig: string) => void; busy: boolean }) {
  const L = useL()
  const [sig, setSig] = useState<string | undefined>()
  return <div className="space-y-2"><SignaturePad value={sig} onChange={setSig} />
    <ConfirmSend label={L('توقيع العقد', 'Sign the contract')} busy={busy} disabled={!sig} onConfirm={() => sig && onSign(sig)} /></div>
}

export function PrintLink({ href, children }: { href: string; children: React.ReactNode }) {
  return <a href={href} className="inline-flex items-center gap-1 text-brand text-[13px] font-semibold"><Printer size={14} />{children}</a>
}


