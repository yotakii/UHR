import { useEffect, useMemo, useRef, useState } from 'react'
import { ChevronDown, Download, FileText, Loader2, RotateCcw, ScanText, Search, SlidersHorizontal, Sparkles, XCircle } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../../lib/store'
import { navigate, setQuery, useRoute } from '../../../lib/router'
import { ApiError } from '../../../lib/api'
import { STAGES } from '../../../lib/recruitment'
import { fmtEta, screeningExportUrl, useRunScreening, useScreening, type ScreenFilters, type ScreenRow } from '../../../lib/screening'
import { Badge, Btn, Empty, PageHead, Panel, Progress, Seg, toast } from '../../../ui/kit'
import { ByJob, FIT_ORDER, FitDistribution, ScreeningKpis, Swatch, TopScores, fitColor, fitLabel, fitOf } from './ScreeningCharts'

const KEYS = ['job', 'dept', 'major', 'q', 'degree', 'years_min', 'years_max', 'city', 'english', 'match_min', 'stage', 'read', 'fit', 'from', 'to', 'sort'] as const
const ADVANCED = ['dept', 'degree', 'years_min', 'years_max', 'city', 'english', 'match_min', 'stage', 'fit', 'from', 'to'] as const

/**
 * فرز السير (11f): فلاتر بالشاغر والإدارة والاختصاص والمؤهل والخبرة والمدينة والمرحلة وحالة القراءة،
 * ثم «قراءة المحدد» دفعةً واحدة — النتائج تظهر وتُرتَّب تلقائياً عند وصولها مع الوقت المتبقي.
 */
export function CvScreening() {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const f: ScreenFilters = useMemo(() => Object.fromEntries(KEYS.map(k => [k, route.query[k]]).filter(([, v]) => v)), [route.query])
  const [live, setLive] = useState(false)
  const r = useScreening(f, live); const d = r.data
  const running = !!d?.batch?.running
  useEffect(() => { setLive(running) }, [running])
  const was = useRef(false)
  useEffect(() => { if (was.current && d?.batch && !d.batch.running) toast(L(`اكتملت القراءة: ${d.batch.done} سيرة`, `Reading finished: ${d.batch.done} CVs`)); was.current = running }, [running]) // eslint-disable-line react-hooks/exhaustive-deps
  const [sel, setSel] = useState<Set<number>>(new Set())
  const [force, setForce] = useState(false)
  const advCount = ADVANCED.filter(k => f[k]).length
  const [more, setMore] = useState(advCount > 0)
  const adv = more ? '' : 'hidden'
  const [q, setQ] = useState(route.query.q ?? ''); const [major, setMajor] = useState(route.query.major ?? '')
  useEffect(() => { const t = setTimeout(() => { if ((route.query.q ?? '') !== q.trim()) setQuery({ q: q.trim() || undefined }) }, 400); return () => clearTimeout(t) }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { const t = setTimeout(() => { if ((route.query.major ?? '') !== major.trim()) setQuery({ major: major.trim() || undefined }) }, 400); return () => clearTimeout(t) }, [major]) // eslint-disable-line react-hooks/exhaustive-deps
  const run = useRunScreening()
  const set = (k: (typeof KEYS)[number], v: string) => setQuery({ [k]: v || undefined })
  const rows = d?.data ?? []
  const ids = rows.map(x => x.id)
  const selected = ids.filter(i => sel.has(i))
  const unread = rows.filter(x => !x.review || x.review.status === 'failed').map(x => x.id)
  const toRead = rows.filter(x => sel.has(x.id) && (force || !x.review || x.review.status === 'failed')).length
  const P = lang === 'ar' ? '٪' : '%'
  const start = () => run.mutate({ ids: selected, force, filters: f }, {
    onSuccess: ({ batch: b }) => {
      const c = b.counts
      toast([L(`بدأت قراءة ${c.queued} سيرة`, `Reading ${c.queued} CVs`), c.already ? L(`${c.already} مقروءة سابقاً`, `${c.already} already read`) : '', c.limit ? L(`${c.limit} تجاوزت الحد الشهري`, `${c.limit} over the monthly limit`) : ''].filter(Boolean).join(' · '))
      setSel(new Set()); setLive(true)
    }, onError: e => toast(e instanceof ApiError ? e.message : String(e)),
  })
  const st = d?.status
  const b = d?.batch

  return <div className="max-w-[1200px] mx-auto space-y-5">
    <PageHead title={L('فرز السير', 'CV screening')}
      sub={L('1. اختر المتقدمين بالفلاتر · 2. اضغط «قراءة المحدد» · 3. تظهر النتائج مرتبة من الأنسب. القراءة للاسترشاد فقط.', '1. Filter applicants · 2. Press “Read selected” · 3. Results appear ranked by best fit. Readings are guidance only.')}
      actions={d?.can.export && <a href={screeningExportUrl(f)} className="inline-flex items-center gap-2 h-10 px-4 rounded-lg border border-line bg-surface font-semibold text-[14px] hover:bg-sub"><Download size={16} />{L('تصدير Excel', 'Export to Excel')}</a>} />

    {st && !st.available && <div className="rounded-xl border border-line bg-sub px-4 py-3 text-[13.5px] text-ink-2 flex items-center gap-2"><XCircle size={16} className="text-ink-3" />{L('قراءة السير غير متاحة لشركتك حالياً. يمكنك الفرز بالفلاتر والمطابقة.', 'CV reading is not available for your company right now. You can still filter and sort by match.')}</div>}

    {b && (b.running || Date.now() - new Date(b.at).getTime() < 30 * 60_000) && b.total > 0 && <Panel>
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
        {b.running ? <Loader2 size={18} className="animate-spin text-brand" /> : <Sparkles size={18} className="text-brand" />}
        <span className="font-semibold">{b.running ? L('القراءة جارية', 'Reading in progress') : L('اكتملت آخر دفعة', 'Last batch finished')}</span>
        <span className="text-[13px] text-ink-2 num">{b.done} / {b.total}{b.failed ? <span className="text-danger"> · {L(`تعذّرت ${b.failed}`, `${b.failed} failed`)}</span> : null}</span>
        {b.running && <span className="text-[13px] text-ink-3">{L('المتبقي تقريباً', 'About')} <b className="num text-ink">{fmtEta(b.eta_seconds, lang === 'ar')}</b>{lang === 'en' && ' left'}</span>}
      </div>
      <div className="mt-3"><Progress value={b.total ? ((b.done + b.failed) / b.total) * 100 : 0} /></div>
      {b.running && <p className="mt-2 text-[12.5px] text-ink-3">{L('يمكنك متابعة عملك أو إغلاق الصفحة؛ القراءة مستمرة على الخادم، والنتائج تُرتَّب هنا تلقائياً.', 'You can keep working or close the page; reading continues on the server and results rank here automatically.')}</p>}
    </Panel>}

    <Panel>
      <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(190px,1fr))]">
        <label className="relative col-span-full sm:col-span-2"><Search size={16} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" />
          <input className="field !ps-9" value={q} onChange={e => setQ(e.target.value)} placeholder={L('اسم، رقم الطلب، مهارة، جهة عمل…', 'Name, ref, skill, employer…')} aria-label={L('بحث', 'Search')} /></label>
        <input className="field" list="uhr-majors" value={major} onChange={e => setMajor(e.target.value)} placeholder={L('الاختصاص الدراسي', 'Field of study')} aria-label={L('الاختصاص', 'Field of study')} />
        <datalist id="uhr-majors">{d?.majors.map(m => <option key={m} value={m} />)}</datalist>
        <select className="field" value={f.job ?? ''} onChange={e => set('job', e.target.value)} aria-label={L('الشاغر', 'Job')}>
          <option value="">{L('كل الشواغر', 'All jobs')}</option>{d?.jobs.filter(j => !f.dept || String(j.dept) === f.dept).map(j => <option key={j.id} value={j.id}>{bi(j, lang)} · {j.code}</option>)}</select>
        <select className="field" value={f.read ?? ''} onChange={e => set('read', e.target.value)} aria-label={L('حالة القراءة', 'Reading status')}>
          <option value="">{L('كل حالات القراءة', 'Any reading status')}</option><option value="none">{L('لم تُقرأ', 'Not read')}</option><option value="done">{L('مقروءة', 'Read')}</option>
          <option value="running">{L('قيد القراءة', 'Reading')}</option><option value="failed">{L('تعذّرت قراءتها', 'Failed')}</option></select>
        <select className={`field ${adv}`} value={f.dept ?? ''} onChange={e => setQuery({ dept: e.target.value || undefined, job: undefined })} aria-label={L('الإدارة', 'Department')}>
          <option value="">{L('كل الإدارات', 'All departments')}</option>{d?.departments.map(x => <option key={x.id} value={x.id}>{bi(x, lang)}</option>)}</select>
        <select className={`field ${adv}`} value={f.degree ?? ''} onChange={e => set('degree', e.target.value)} aria-label={L('المؤهل', 'Degree')}>
          <option value="">{L('كل المؤهلات', 'Any degree')}</option>{d?.degrees.map((x, i) => <option key={i} value={i}>{L('على الأقل', 'At least')} {bi(x, lang)}</option>)}</select>
        <div className={`flex items-center gap-2 ${adv}`}><input className="field num" type="number" min={0} max={50} value={f.years_min ?? ''} onChange={e => set('years_min', e.target.value)} placeholder={L('خبرة من', 'Years from')} aria-label={L('سنوات الخبرة من', 'Years from')} />
          <input className="field num" type="number" min={0} max={50} value={f.years_max ?? ''} onChange={e => set('years_max', e.target.value)} placeholder={L('إلى', 'to')} aria-label={L('إلى', 'to')} /></div>
        <select className={`field ${adv}`} value={f.city ?? ''} onChange={e => set('city', e.target.value)} aria-label={L('المدينة', 'City')}>
          <option value="">{L('كل المدن', 'All cities')}</option>{d?.cities.map(c => <option key={c.code} value={c.code}>{bi(c, lang)}</option>)}</select>
        <select className={`field ${adv}`} value={f.english ?? ''} onChange={e => set('english', e.target.value)} aria-label={L('الإنكليزية', 'English')}>
          <option value="">{L('الإنكليزية: أي مستوى', 'English: any')}</option>
          {[['excellent', 'ممتاز', 'Excellent'], ['good', 'جيد', 'Good'], ['fair', 'مقبول', 'Fair'], ['weak', 'ضعيف', 'Weak']].map(([v, a, e]) => <option key={v} value={v}>{L(`الإنكليزية: ${a}`, `English: ${e}`)}</option>)}</select>
        <select className={`field ${adv}`} value={f.match_min ?? ''} onChange={e => set('match_min', e.target.value)} aria-label={L('المطابقة', 'Match')}>
          <option value="">{L('أي مطابقة', 'Any match')}</option>{[40, 50, 60, 70, 80].map(v => <option key={v} value={v}>{L(`مطابقة ${v}${P}+`, `Match ${v}%+`)}</option>)}</select>
        <select className={`field ${adv}`} value={f.stage ?? 'open'} onChange={e => set('stage', e.target.value === 'open' ? '' : e.target.value)} aria-label={L('المرحلة', 'Stage')}>
          <option value="open">{L('الطلبات المفتوحة', 'Open applications')}</option><option value="all">{L('كل المراحل', 'All stages')}</option>
          {Object.entries(STAGES).map(([k, v]) => <option key={k} value={k}>{bi(v, lang)}</option>)}</select>
        <select className={`field ${adv}`} value={f.fit ?? ''} onChange={e => set('fit', e.target.value)} aria-label={L('نتيجة القراءة', 'Reading result')}>
          <option value="">{L('كل النتائج', 'Any result')}</option>{FIT_ORDER.filter(k => k !== 'none').map(k => <option key={k} value={k}>{fitLabel(k, lang === 'ar')}</option>)}</select>
        <label className={`flex items-center gap-2 text-[12.5px] text-ink-3 ${adv}`}>{L('من', 'From')}<input className="field num" type="date" value={f.from ?? ''} onChange={e => set('from', e.target.value)} /></label>
        <label className={`flex items-center gap-2 text-[12.5px] text-ink-3 ${adv}`}>{L('إلى', 'To')}<input className="field num" type="date" value={f.to ?? ''} onChange={e => set('to', e.target.value)} /></label>
      </div>
      <div className="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2">
        <button className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-brand" onClick={() => setMore(m => !m)} aria-expanded={more}>
          <SlidersHorizontal size={14} />{more ? L('فلاتر أقل', 'Fewer filters') : L('فلاتر أكثر', 'More filters')}{advCount > 0 && <span className="num rounded-full text-[11px] leading-[18px] min-w-[18px] px-1 text-center" style={{ background: 'var(--primary)', color: 'var(--on-primary)' }}>{advCount}</span>}</button>
        {Object.keys(f).some(k => k !== 'sort') && <button className="inline-flex items-center gap-1.5 text-[12.5px] text-ink-3 hover:text-ink" onClick={() => { setQ(''); setMajor(''); setQuery(Object.fromEntries(KEYS.map(k => [k, undefined]))) }}><RotateCcw size={13} />{L('مسح الفلاتر', 'Clear filters')}</button>}
      </div>
    </Panel>

    {d && rows.length > 0 && <ScreeningKpis rows={rows} total={d.total} />}
    {d && rows.some(x => x.review?.status === 'done') && <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(320px,1fr))]">
      <Panel title={L('توزيع الملاءمة', 'Fit distribution')}><FitDistribution rows={rows} active={f.fit} onPick={k => setQuery({ fit: k || undefined })} /></Panel>
      <Panel title={L('الأعلى درجة', 'Top scores')} sub={L('من 100 — اضغط الاسم لفتح الملف', 'Out of 100 — click a name to open the file')}><TopScores rows={rows} /></Panel>
      {new Set(rows.map(x => x.job.id)).size > 1 && <Panel title={L('حسب الشاغر', 'By job')} sub={L('طول الشريط = عدد المتقدمين', 'Bar length = number of applicants')}><ByJob rows={rows} onJob={id => setQuery({ job: String(id) })} /></Panel>}
    </div>}
    {d && rows.length > 0 && !rows.some(x => x.review?.status === 'done') && <div className="rounded-xl border border-dashed border-line px-4 py-6 text-center text-[13.5px] text-ink-3">{L('لم تُقرأ سير هذه القائمة بعد. حدّد المتقدمين واضغط «قراءة المحدد» لتظهر المخططات والترتيب.', 'These CVs are not read yet. Select applicants and press “Read selected” to see the charts and ranking.')}</div>}

    <Panel pad={false} title={<span className="flex items-center gap-2">{L('المتقدمون', 'Applicants')} {d && <span className="text-[13px] text-ink-3 font-normal num">{d.total > d.shown ? `${d.shown} / ${d.total}` : d.total}</span>}{r.isFetching && <Loader2 size={14} className="animate-spin text-brand" />}</span>}
      sub={st?.available && <span className="num">{L(`المستخدم هذا الشهر ${st.used} من ${st.limit} قراءة · متوسط القراءة ${fmtEta(st.avg_seconds, true)}`, `This month ${st.used} of ${st.limit} readings · ${fmtEta(st.avg_seconds, false)} per CV`)}</span>}>
      <div className="px-4 py-3 border-b border-line-2 flex flex-wrap items-center gap-x-4 gap-y-2">
        <label className="inline-flex items-center gap-2 text-[13.5px]"><input type="checkbox" className="accent-[var(--brand)] size-4" checked={!!ids.length && selected.length === ids.length}
          ref={el => { if (el) el.indeterminate = selected.length > 0 && selected.length < ids.length }} onChange={e => setSel(e.target.checked ? new Set(ids) : new Set())} />{L('تحديد الكل', 'Select all')}</label>
        {d?.can.run && unread.length > 0 && <button className="text-[13px] text-brand font-semibold" onClick={() => setSel(new Set(unread))}>{L(`تحديد غير المقروء (${unread.length})`, `Select unread (${unread.length})`)}</button>}
        <span className="flex-1" />
        <Seg value={f.sort ?? 'fit'} onChange={v => set('sort', v === 'fit' ? '' : v)} options={[{ v: 'fit', label: L('الأنسب', 'Best fit') }, { v: 'match', label: L('المطابقة', 'Match') }, { v: 'years', label: L('الخبرة', 'Experience') }, { v: 'newest', label: L('الأحدث', 'Newest') }]} />
      </div>
      {d?.can.run && selected.length > 0 && <div className="px-4 py-3 border-b border-line-2 bg-brand-soft/40 flex flex-wrap items-center gap-x-4 gap-y-2">
        <span className="text-[13.5px] font-semibold num">{L(`محدد ${selected.length}`, `${selected.length} selected`)}</span>
        <label className="inline-flex items-center gap-2 text-[13px]"><input type="checkbox" className="accent-[var(--brand)]" checked={force} onChange={e => setForce(e.target.checked)} />{L('إعادة قراءة المقروء', 'Re-read already read')}</label>
        {st && toRead > 0 && <span className="text-[12.5px] text-ink-3 num">{L(`${toRead} للقراءة · نحو ${fmtEta(toRead * st.avg_seconds, true)}`, `${toRead} to read · about ${fmtEta(toRead * st.avg_seconds, false)}`)}</span>}
        <span className="flex-1" />
        <Btn size="sm" onClick={() => setSel(new Set())}>{L('إلغاء التحديد', 'Clear')}</Btn>
        <Btn size="sm" kind="primary" icon={run.isPending ? <Loader2 size={15} className="animate-spin" /> : <ScanText size={15} />} disabled={run.isPending || !st?.available || toRead === 0} onClick={start}>{L('قراءة المحدد', 'Read selected')}</Btn>
      </div>}
      {!d ? <div className="grid place-items-center py-16"><Loader2 className="animate-spin text-brand" /></div>
        : !rows.length ? <Empty title={L('لا يوجد متقدمون بهذه الفلاتر', 'No applicants match these filters')}>{L('وسّع الفلاتر أو امسحها.', 'Widen or clear the filters.')}</Empty>
        : <ul className="divide-y divide-line-2">{rows.map(x => <Row key={x.id} x={x} checked={sel.has(x.id)} canSelect={!!d.can.run} onCheck={v => setSel(s => { const n = new Set(s); if (v) n.add(x.id); else n.delete(x.id); return n })} sortFit={(f.sort ?? 'fit') === 'fit'} />)}</ul>}
      {d && d.total > d.shown && <p className="px-4 py-3 text-[12.5px] text-ink-3 border-t border-line-2">{L(`تظهر أول ${d.shown} نتيجة. ضيّق الفلاتر لرؤية البقية.`, `Showing the first ${d.shown}. Narrow the filters to see the rest.`)}</p>}
    </Panel>
  </div>
}

function Row({ x, checked, canSelect, onCheck, sortFit }: { x: ScreenRow; checked: boolean; canSelect: boolean; onCheck: (v: boolean) => void; sortFit: boolean }) {
  const L = useL(); const lang = useLang(); const ar = lang === 'ar'
  const [open, setOpen] = useState(false)
  const rv = x.review; const done = rv?.status === 'done'; const k = fitOf(x)
  const P = ar ? '٪' : '%'
  return <li className={`relative ps-5 pe-4 py-3 ${checked ? 'bg-brand-soft/30' : ''}`}>
    <span className="absolute inset-y-2 start-1.5 w-1 rounded-full" style={{ background: k === 'none' ? 'var(--line)' : fitColor(k) }} aria-hidden />
    <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
      {canSelect && <input type="checkbox" className="accent-[var(--brand)] size-4" checked={checked} onChange={e => onCheck(e.target.checked)} aria-label={bi(x.name, lang)} />}
      {sortFit && done && <span className="num text-[13px] font-bold text-ink-3 w-6 text-center">{x.rank}</span>}
      <button type="button" onClick={() => navigate(`/hr/candidates/${x.id}?tab=match`)} className="min-w-0 flex-1 basis-[230px] text-start">
        <span className="block font-semibold">{bi(x.name, lang)}</span>
        <span className="block text-[12.5px] text-ink-2">{bi(x.job.title, lang)}{x.major && <span className="text-ink-3"> · {x.major}</span>}<span className="text-ink-3"> · {L(`${x.years} سنوات`, `${x.years} yrs`)}</span></span>
      </button>
      <div className="flex items-center gap-3 basis-[260px] sm:basis-auto">
        <span className="text-[12px] text-ink-3 whitespace-nowrap">{L('مطابقة', 'Match')} <b className="num text-ink-2">{x.match}{P}</b></span>
        {done ? <span className="flex items-center gap-2 min-w-[150px]" title={fitLabel(k, ar)}>
            <span className="h-2.5 flex-1 rounded-full bg-sub min-w-[70px]"><span className="block h-full rounded-full" style={{ width: `${rv.score}%`, background: fitColor(k) }} /></span>
            <span className="num text-[16px] font-bold w-8 text-end">{rv.score}</span></span>
          : !rv ? <span className="text-[12.5px] text-ink-3 min-w-[150px]">{L('لم تُقرأ', 'Not read')}</span>
          : rv.status === 'failed' ? <Badge tone="danger">{L('تعذّرت القراءة', 'Failed')}</Badge>
          : <Badge tone="brass"><Loader2 size={12} className="animate-spin" />{L('قيد القراءة', 'Reading')}</Badge>}
        {done ? <button className="size-8 grid place-items-center rounded-lg border border-line hover:bg-sub" onClick={() => setOpen(o => !o)} aria-expanded={open} aria-label={L('التفاصيل', 'Details')}><ChevronDown size={15} className={open ? 'rotate-180' : ''} /></button> : <span className="size-8" />}
      </div>
    </div>
    {done && <div className={`mt-1 flex items-center gap-2 text-[12.5px] ${canSelect ? 'ps-8' : ''}`}><Swatch k={k} /><span className="font-semibold">{fitLabel(k, ar)}</span>
      {rv.summary && <span className={`text-ink-2 ${open ? '' : 'line-clamp-1'}`}>— {rv.summary}</span>}</div>}
    {done && open && <div className={`mt-2 grid gap-3 sm:grid-cols-2 text-[13px] ${canSelect ? 'ps-8' : ''}`}>
      <div className="rounded-lg bg-sub px-3 py-2"><div className="text-[12px] font-semibold mb-1">{L('نقاط القوة', 'Strengths')}</div><ul className="list-disc ps-5 space-y-0.5">{rv.strengths.map((t, i) => <li key={i}>{t}</li>)}</ul></div>
      <div className="rounded-lg bg-sub px-3 py-2"><div className="text-[12px] font-semibold mb-1">{L('النواقص', 'Gaps')}</div><ul className="list-disc ps-5 space-y-0.5">{rv.gaps.map((t, i) => <li key={i}>{t}</li>)}</ul></div>
      <div className="sm:col-span-2 text-[12px] text-ink-3 flex flex-wrap gap-x-3">{x.city && <span>{x.city}</span>}{x.last && <span>{x.last}</span>}<span>{STAGES[x.stage as keyof typeof STAGES] ? bi(STAGES[x.stage as keyof typeof STAGES], lang) : x.stage}</span><span>{L('تقديم', 'Applied')} <span className="num">{fmtDate(x.applied_at, lang)}</span></span>{x.cv && <span className="inline-flex items-center gap-1"><FileText size={12} />{L('سيرة مرفقة', 'CV attached')}</span>}</div>
    </div>}
  </li>
}
