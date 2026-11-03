import { useEffect, useMemo, useRef, useState } from 'react'
import { ChevronDown, Download, FileText, Loader2, RotateCcw, ScanText, Search, Sparkles, XCircle } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../../lib/store'
import { navigate, setQuery, useRoute } from '../../../lib/router'
import { ApiError } from '../../../lib/api'
import { STAGES } from '../../../lib/recruitment'
import { FIT } from '../../../lib/candidates'
import { fmtEta, screeningExportUrl, useRunScreening, useScreening, type ScreenFilters, type ScreenRow } from '../../../lib/screening'
import { Badge, Btn, Empty, PageHead, Panel, Progress, Seg, toast } from '../../../ui/kit'

const KEYS = ['job', 'dept', 'major', 'q', 'degree', 'years_min', 'years_max', 'city', 'english', 'match_min', 'stage', 'read', 'fit', 'from', 'to', 'sort'] as const

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
  const [more, setMore] = useState(false)
  const adv = more ? '' : 'max-md:hidden'
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
      sub={L('اختر المتقدمين بالفلاتر، ثم «قراءة المحدد»: تُقرأ كل سيرة مقابل متطلبات شاغرها، وتظهر النتائج مرتبةً من الأنسب فور وصولها. القراءة استرشادية ولا تنقل أحداً ولا ترفضه.', 'Pick applicants with the filters, then “Read selected”: each CV is read against its job’s requirements, and results appear ranked from the best fit as they arrive. Readings are guidance only and never move or reject anyone.')}
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
        <select className="field" value={f.dept ?? ''} onChange={e => setQuery({ dept: e.target.value || undefined, job: undefined })} aria-label={L('الإدارة', 'Department')}>
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
        <select className={`field ${adv}`} value={f.read ?? ''} onChange={e => set('read', e.target.value)} aria-label={L('حالة القراءة', 'Reading status')}>
          <option value="">{L('كل حالات القراءة', 'Any reading status')}</option><option value="none">{L('لم تُقرأ', 'Not read')}</option><option value="done">{L('مقروءة', 'Read')}</option>
          <option value="running">{L('قيد القراءة', 'Reading')}</option><option value="failed">{L('تعذّرت قراءتها', 'Failed')}</option></select>
        <select className={`field ${adv}`} value={f.fit ?? ''} onChange={e => set('fit', e.target.value)} aria-label={L('نتيجة القراءة', 'Reading result')}>
          <option value="">{L('كل النتائج', 'Any result')}</option>{Object.entries(FIT).map(([k, v]) => <option key={k} value={k}>{lang === 'ar' ? v[0] : v[1]}</option>)}</select>
        <label className={`flex items-center gap-2 text-[12.5px] text-ink-3 ${adv}`}>{L('من', 'From')}<input className="field num" type="date" value={f.from ?? ''} onChange={e => set('from', e.target.value)} /></label>
        <label className={`flex items-center gap-2 text-[12.5px] text-ink-3 ${adv}`}>{L('إلى', 'To')}<input className="field num" type="date" value={f.to ?? ''} onChange={e => set('to', e.target.value)} /></label>
      </div>
      <button className="md:hidden mt-3 me-4 inline-flex items-center gap-1.5 text-[13px] font-semibold text-brand" onClick={() => setMore(m => !m)} aria-expanded={more}>
        <ChevronDown size={14} className={more ? 'rotate-180' : ''} />{more ? L('فلاتر أقل', 'Fewer filters') : L('فلاتر إضافية', 'More filters')}</button>
      {Object.keys(f).some(k => k !== 'sort') && <button className="mt-3 inline-flex items-center gap-1.5 text-[12.5px] text-ink-3 hover:text-ink" onClick={() => { setQ(''); setMajor(''); setQuery(Object.fromEntries(KEYS.map(k => [k, undefined]))) }}><RotateCcw size={13} />{L('مسح الفلاتر', 'Clear filters')}</button>}
    </Panel>

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
  const L = useL(); const lang = useLang()
  const [open, setOpen] = useState(false)
  const rv = x.review; const done = rv?.status === 'done'
  const P = lang === 'ar' ? '٪' : '%'
  return <li className={`px-4 py-3 ${checked ? 'bg-brand-soft/30' : ''}`}>
    <div className="flex flex-wrap items-start gap-x-4 gap-y-2">
      {canSelect && <input type="checkbox" className="accent-[var(--brand)] size-4 mt-1" checked={checked} onChange={e => onCheck(e.target.checked)} aria-label={bi(x.name, lang)} />}
      {sortFit && done && <span className="num text-[12px] font-bold text-ink-3 w-6 mt-0.5 text-center">{x.rank}</span>}
      <button type="button" onClick={() => navigate(`/hr/candidates/${x.id}?tab=match`)} className="min-w-0 flex-1 basis-[240px] text-start">
        <span className="block font-semibold">{bi(x.name, lang)} <span className="text-[12px] text-ink-3 ltr inline-block font-normal">{x.ref}</span></span>
        <span className="block text-[12.5px] text-ink-2">{bi(x.job.title, lang)}{x.job.dept && <span className="text-ink-3"> · {bi(x.job.dept, lang)}</span>}</span>
        <span className="block text-[12.5px] text-ink-3">{[x.major && [x.phase, x.major].filter(Boolean).join(' — '), L(`${x.years} سنوات خبرة`, `${x.years} yrs`), x.city, x.last].filter(Boolean).join(' · ')}</span>
      </button>
      <div className="flex flex-wrap items-center gap-2">
        <Badge tone={x.stage === 'rejected' ? 'neutral' : 'info'}>{STAGES[x.stage as keyof typeof STAGES] ? bi(STAGES[x.stage as keyof typeof STAGES], lang) : x.stage}</Badge>
        <span className="text-[12px] text-ink-3">{L('المطابقة', 'Match')} <b className={`num text-[13px] ${x.match >= 75 ? 'text-brand' : x.match >= 55 ? 'text-brass' : 'text-ink-3'}`}>{x.match}{P}</b></span>
        {!rv ? <Badge>{L('لم تُقرأ', 'Not read')}</Badge>
          : rv.status === 'failed' ? <Badge tone="danger">{L('تعذّرت القراءة', 'Failed')}</Badge>
          : !done ? <Badge tone="brass"><Loader2 size={12} className="animate-spin" />{L('قيد القراءة', 'Reading')}</Badge>
          : <span className="inline-flex items-center gap-2"><span className="num text-[18px] font-bold leading-none">{rv.score}<span className="text-[11px] text-ink-3 font-normal">/100</span></span>
            {rv.fit && <Badge tone={FIT[rv.fit][2]}>{lang === 'ar' ? FIT[rv.fit][0] : FIT[rv.fit][1]}</Badge>}</span>}
        {done && <button className="size-8 grid place-items-center rounded-lg border border-line hover:bg-sub" onClick={() => setOpen(o => !o)} aria-expanded={open} aria-label={L('التفاصيل', 'Details')}><ChevronDown size={15} className={open ? 'rotate-180' : ''} /></button>}
        {x.cv && <FileText size={15} className="text-ink-3" aria-label={L('مرفق سيرة', 'CV attached')} />}
      </div>
    </div>
    {done && rv.summary && <p className={`mt-1.5 text-[13px] text-ink-2 ${canSelect ? 'ps-8' : ''} ${open ? '' : 'line-clamp-1'}`}>{rv.summary}</p>}
    {done && open && <div className={`mt-2 grid gap-3 sm:grid-cols-2 text-[13px] ${canSelect ? 'ps-8' : ''}`}>
      <div><div className="text-[12px] font-semibold text-brand mb-1">{L('نقاط القوة', 'Strengths')}</div><ul className="list-disc ps-5 space-y-0.5">{rv.strengths.map((s, i) => <li key={i}>{s}</li>)}</ul></div>
      <div><div className="text-[12px] font-semibold text-brass mb-1">{L('النواقص', 'Gaps')}</div><ul className="list-disc ps-5 space-y-0.5">{rv.gaps.map((s, i) => <li key={i}>{s}</li>)}</ul></div>
      <div className="sm:col-span-2 text-[12px] text-ink-3">{L('تقديم الطلب', 'Applied')} <span className="num">{fmtDate(x.applied_at, lang)}</span></div>
    </div>}
  </li>
}
