import { useState, type ReactNode } from 'react'
import { useL, useLang, bi } from '../../../lib/store'
import { navigate } from '../../../lib/router'
import type { CvFit } from '../../../lib/candidates'
import type { ScreenRow } from '../../../lib/screening'

export type FitKey = CvFit | 'none'
export const FIT_ORDER: FitKey[] = ['strong', 'good', 'partial', 'weak', 'none']
export const fitColor = (k: FitKey) => `var(--fit-${k})`
export const fitLabel = (k: FitKey, ar: boolean) => ({
  strong: ar ? 'ملاءمة عالية' : 'Strong fit', good: ar ? 'ملاءمة جيدة' : 'Good fit', partial: ar ? 'ملاءمة جزئية' : 'Partial fit', weak: ar ? 'ملاءمة ضعيفة' : 'Weak fit', none: ar ? 'لم تُقرأ بعد' : 'Not read yet',
}[k])
export const fitOf = (r: ScreenRow): FitKey => r.review?.status === 'done' && r.review.fit ? r.review.fit : 'none'

/** تلميح يتبع المؤشر — لكل قطعة في المخططات */
function useTip() {
  const [t, setT] = useState<{ x: number; y: number; body: ReactNode } | null>(null)
  const on = (body: ReactNode) => ({ onMouseMove: (e: React.MouseEvent) => setT({ x: e.clientX, y: e.clientY, body }), onMouseLeave: () => setT(null) })
  const el = t && <div role="tooltip" className="fixed z-50 pointer-events-none rounded-lg border border-line bg-surface shadow-lg px-3 py-2 text-[12.5px] text-ink max-w-[260px]" style={{ left: t.x + 14, top: t.y + 14 }}>{t.body}</div>
  return { on, el }
}

const Swatch = ({ k }: { k: FitKey }) => <span className={`size-3 rounded-[3px] shrink-0 ${k === 'none' ? 'border border-line bg-sub' : ''}`} style={k === 'none' ? undefined : { background: fitColor(k) }} />

/** مؤشرات سريعة أعلى الصفحة */
export function ScreeningKpis({ rows, total }: { rows: ScreenRow[]; total: number }) {
  const L = useL()
  const done = rows.filter(r => r.review?.status === 'done')
  const strong = done.filter(r => r.review!.fit === 'strong').length
  const avg = done.length ? Math.round(done.reduce((a, r) => a + (r.review!.score ?? 0), 0) / done.length) : null
  const pct = rows.length ? Math.round(done.length / rows.length * 100) : 0
  const tile = (label: string, value: ReactNode, sub?: ReactNode) => <div className="rounded-2xl border border-line bg-surface px-4 py-3">
    <div className="text-[12.5px] text-ink-3">{label}</div><div className="text-[26px] font-bold num leading-tight mt-0.5">{value}</div>{sub && <div className="mt-1.5">{sub}</div>}</div>
  return <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(170px,1fr))]">
    {tile(L('المتقدمون', 'Applicants'), total)}
    {tile(L('قُرئت سيرهم', 'CVs read'), <>{done.length}<span className="text-[14px] text-ink-3 font-normal"> / {rows.length}</span></>,
      <div className="h-1.5 rounded-full bg-sub overflow-hidden"><div className="h-full rounded-full" style={{ width: `${pct}%`, background: 'var(--fit-good)' }} /></div>)}
    {tile(L('ملاءمة عالية', 'Strong fit'), strong, <span className="inline-flex items-center gap-1.5 text-[12px] text-ink-3"><Swatch k="strong" />{L('75 فأكثر', '75 or more')}</span>)}
    {tile(L('متوسط الدرجة', 'Average score'), avg === null ? '—' : <>{avg}<span className="text-[14px] text-ink-3 font-normal">/100</span></>)}
  </div>
}

/** توزيع الملاءمة: شريط واحد مقسّم + وسيلة إيضاح قابلة للنقر (تصفية) */
export function FitDistribution({ rows, active, onPick }: { rows: ScreenRow[]; active?: string; onPick: (k: FitKey | '') => void }) {
  const L = useL(); const lang = useLang(); const ar = lang === 'ar'
  const tip = useTip()
  const counts = FIT_ORDER.map(k => ({ k, n: rows.filter(r => fitOf(r) === k).length })).filter(x => x.n > 0)
  const all = rows.length || 1
  return <div>
    <div className="flex h-9 w-full gap-[2px]">{counts.map(({ k, n }) =>
      <button key={k} type="button" {...tip.on(<><b>{fitLabel(k, ar)}</b><div className="num text-ink-2">{n} · {Math.round(n / all * 100)}%</div></>)} onClick={() => onPick(k === 'none' ? '' : active === k ? '' : k)}
        className={`h-full first:rounded-s-[6px] last:rounded-e-[6px] transition-opacity ${active && active !== k ? 'opacity-35' : ''} ${k === 'none' ? 'bg-sub border border-line' : ''}`}
        style={{ width: `${n / all * 100}%`, minWidth: 6, ...(k === 'none' ? {} : { background: fitColor(k) }) }} aria-label={`${fitLabel(k, ar)} ${n}`} />)}</div>
    <ul className="mt-3 grid gap-x-4 gap-y-1.5 [grid-template-columns:repeat(auto-fill,minmax(150px,1fr))] text-[13px]">{counts.map(({ k, n }) =>
      <li key={k}><button type="button" disabled={k === 'none'} onClick={() => onPick(active === k ? '' : k)} className={`flex items-center gap-2 w-full text-start rounded-md px-1 -mx-1 ${k !== 'none' ? 'hover:bg-sub' : ''} ${active === k ? 'font-semibold' : ''}`}>
        <Swatch k={k} /><span className="flex-1">{fitLabel(k, ar)}</span><span className="num text-ink-2">{n}</span><span className="num text-ink-3 w-10 text-end">{Math.round(n / all * 100)}%</span></button></li>)}</ul>
    {tip.el}
    <p className="mt-2 text-[12px] text-ink-3">{L('اضغط مستوى لعرض متقدميه فقط.', 'Click a level to show only those applicants.')}</p>
  </div>
}

/** أعلى الدرجات: أشرطة أفقية على مقياس 0–100 */
export function TopScores({ rows, n = 8 }: { rows: ScreenRow[]; n?: number }) {
  const L = useL(); const lang = useLang(); const ar = lang === 'ar'
  const tip = useTip()
  const top = rows.filter(r => r.review?.status === 'done' && r.review.score !== null).sort((a, b) => (b.review!.score! - a.review!.score!) || b.match - a.match).slice(0, n)
  if (!top.length) return <p className="text-[13px] text-ink-3 py-6 text-center">{L('لا توجد نتائج مقروءة بعد.', 'No readings yet.')}</p>
  return <ul className="space-y-2.5">{top.map(r => { const s = r.review!.score!; const k = fitOf(r)
    return <li key={r.id}><button type="button" onClick={() => navigate(`/hr/candidates/${r.id}?tab=match`)} className="block w-full text-start group"
      {...tip.on(<><b>{bi(r.name, lang)}</b><div className="text-ink-2">{bi(r.job.title, lang)}</div><div className="num mt-1">{L('القراءة', 'Reading')} {s}/100 · {L('المطابقة', 'Match')} {r.match}%</div><div className="text-ink-3">{fitLabel(k, ar)}</div></>)}>
      <div className="flex items-baseline justify-between gap-2 text-[13px]"><span className="truncate group-hover:underline">{bi(r.name, lang)}</span><span className="num font-semibold shrink-0">{s}</span></div>
      <div className="mt-1 h-2.5 rounded-full bg-sub"><div className="h-full rounded-full" style={{ width: `${s}%`, background: fitColor(k) }} /></div></button></li> })}
    {tip.el}</ul>
}

/** حسب الشاغر: شريط مقسّم لكل شاغر بأطوال مطلقة (عدد المتقدمين) */
export function ByJob({ rows, onJob }: { rows: ScreenRow[]; onJob: (id: number) => void }) {
  const lang = useLang(); const ar = lang === 'ar'
  const tip = useTip()
  const jobs = [...rows.reduce((m, r) => { const j = m.get(r.job.id) ?? { job: r.job, rows: [] as ScreenRow[] }; j.rows.push(r); m.set(r.job.id, j); return m }, new Map<number, { job: ScreenRow['job']; rows: ScreenRow[] }>()).values()]
    .sort((a, b) => b.rows.filter(r => fitOf(r) === 'strong').length - a.rows.filter(r => fitOf(r) === 'strong').length || b.rows.length - a.rows.length).slice(0, 8)
  const max = Math.max(1, ...jobs.map(j => j.rows.length))
  return <div>
    <ul className="space-y-3">{jobs.map(({ job, rows: jr }) => <li key={job.id}>
      <button type="button" onClick={() => onJob(job.id)} className="flex items-baseline justify-between gap-2 w-full text-start text-[13px] hover:underline"><span className="truncate">{bi(job.title, lang)}</span><span className="num text-ink-3 shrink-0">{jr.length}</span></button>
      <div className="mt-1 flex h-3.5 gap-[2px]" style={{ width: `${Math.max(6, jr.length / max * 100)}%` }}>{FIT_ORDER.map(k => { const n = jr.filter(r => fitOf(r) === k).length; if (!n) return null
        return <span key={k} {...tip.on(<><b>{bi(job.title, lang)}</b><div>{fitLabel(k, ar)}: <span className="num">{n}</span></div></>)} className={`h-full first:rounded-s-[4px] last:rounded-e-[4px] ${k === 'none' ? 'bg-sub border border-line' : ''}`}
          style={{ flex: n, ...(k === 'none' ? {} : { background: fitColor(k) }) }} /> })}</div></li>)}</ul>
    <ul className="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-[12px] text-ink-2">{FIT_ORDER.map(k => <li key={k} className="flex items-center gap-1.5"><Swatch k={k} />{fitLabel(k, ar)}</li>)}</ul>
    {tip.el}
  </div>
}

export { Swatch }
