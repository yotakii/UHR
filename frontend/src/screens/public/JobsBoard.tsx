import { Search, SlidersHorizontal, X } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { useJobs, usePortal, type JobFilters } from '../../lib/public'
import { setQuery, useRoute } from '../../lib/router'
import { Btn, Empty } from '../../ui/kit'
import { JobCard } from './parts'

const KEYS: (keyof JobFilters)[] = ['q', 'company', 'city', 'type', 'exp']

/** فرص العمل من كل الشركات مع التصفية — الفلاتر في الرابط حتى تُشارك وتبقى عند الرجوع */
export function JobsBoard({ full }: { full?: boolean }) {
  const L = useL(); const lang = useLang()
  const route = useRoute()
  const f: JobFilters = Object.fromEntries(KEYS.map(k => [k, route.query[k] ?? ''])) as JobFilters
  const { data: jobs, isLoading, isError, refetch, isFetching } = useJobs(f)
  const portal = usePortal().data
  const any = KEYS.some(k => f[k])
  const set = (k: keyof JobFilters, v: string) => setQuery({ [k]: v || undefined })
  const sel = 'field h-10 !py-0 text-[13px]'
  return <section id="jobs" className={full ? 'max-w-5xl mx-auto px-5 py-8' : 'scroll-mt-24'}>
    <div className="flex items-end justify-between mb-3 gap-3">
      <div><h2 className="text-[20px] font-bold">{L('فرص العمل', 'Job opportunities')}</h2>
        <p className="text-ink-2 text-[13px]">{L('قدّم طلبك مباشرة، وتابع حالته من حسابك بعد الإرسال.', 'Apply directly and follow your application from your account.')}</p></div>
      {jobs && <span className="text-[13px] text-ink-3 num shrink-0">{jobs.length} {L('فرصة', 'roles')}</span>}
    </div>

    <div className="rounded-2xl border border-line bg-surface p-3 mb-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-[1.3fr_1fr_1fr_1.15fr_1fr_auto]">
      <label className="relative sm:col-span-2 lg:col-span-1"><Search size={16} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" />
        <input className="field h-10 !ps-9 text-[13px]" placeholder={L('المسمى، الكلمة، أو الشركة…', 'Title, keyword or company…')} defaultValue={f.q} key={f.q}
          onKeyDown={e => { if (e.key === 'Enter') set('q', (e.target as HTMLInputElement).value.trim()) }} onBlur={e => { if (e.target.value.trim() !== (f.q ?? '')) set('q', e.target.value.trim()) }} aria-label={L('بحث', 'Search')} /></label>
      <select className={sel} value={f.company} onChange={e => set('company', e.target.value)} aria-label={L('الشركة', 'Company')}>
        <option value="">{L('كل الشركات', 'All companies')}</option>{portal?.companies.map(c => <option key={c.slug} value={c.slug}>{bi(c.short, lang)}</option>)}</select>
      <select className={sel} value={f.city} onChange={e => set('city', e.target.value)} aria-label={L('المدينة', 'City')}>
        <option value="">{L('كل المدن', 'All cities')}</option>{portal?.filters.cities.map(c => <option key={c.code} value={c.code}>{bi(c, lang)}</option>)}</select>
      <select className={sel} value={f.type} onChange={e => set('type', e.target.value)} aria-label={L('نوع الدوام', 'Job type')}>
        <option value="">{L('كل أنواع الدوام', 'Any type')}</option>{portal?.filters.types.map(t => <option key={t.code} value={t.code}>{bi(t, lang)}</option>)}</select>
      <select className={sel} value={f.exp} onChange={e => set('exp', e.target.value)} aria-label={L('الخبرة', 'Experience')}>
        <option value="">{L('أي خبرة', 'Any experience')}</option><option value="0">{L('بدون خبرة', 'No experience')}</option><option value="2">{L('حتى سنتين', 'Up to 2 years')}</option><option value="5">{L('حتى 5 سنوات', 'Up to 5 years')}</option></select>
      {any ? <Btn className="h-10" icon={<X size={15} />} onClick={() => setQuery(Object.fromEntries(KEYS.map(k => [k, undefined])))}>{L('مسح', 'Clear')}</Btn> : <span className="hidden lg:grid place-items-center text-ink-3 px-2"><SlidersHorizontal size={16} /></span>}
    </div>

    <div className={`grid gap-3 transition-opacity ${isFetching && !isLoading ? 'opacity-60' : ''}`}>
      {isLoading && [0, 1, 2].map(k => <div key={k} className="rounded-2xl border border-line bg-surface p-5 h-[118px] animate-pulse" />)}
      {isError && <div className="rounded-2xl border border-line bg-surface"><Empty title={L('تعذّر تحميل الفرص', 'Could not load jobs')} action={<Btn size="sm" onClick={() => refetch()}>{L('إعادة المحاولة', 'Retry')}</Btn>} /></div>}
      {jobs?.length === 0 && <div className="rounded-2xl border border-line bg-surface"><Empty title={any ? L('لا فرص مطابقة', 'No matching jobs') : L('لا توجد فرص منشورة حالياً', 'No open jobs right now')}
        action={any ? <Btn size="sm" onClick={() => setQuery(Object.fromEntries(KEYS.map(k => [k, undefined])))}>{L('مسح الفلاتر', 'Clear filters')}</Btn> : undefined}>{any ? L('جرّب توسيع البحث أو مسح بعض الفلاتر.', 'Try a broader search or clear some filters.') : L('تُنشر الفرص الجديدة هنا فور اعتمادها — عُد لاحقاً.', 'New roles appear here as soon as they are approved — check back later.')}</Empty></div>}
      {jobs?.map((j, k) => <JobCard key={j.company.slug + j.code} j={j} k={k} />)}
    </div>
  </section>
}
