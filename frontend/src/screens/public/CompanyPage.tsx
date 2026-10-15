import { useEffect } from 'react'
import { Globe, Link2 } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { useCompanyPage } from '../../lib/public'
import { setMeta } from '../../lib/seo'
import { Btn, Empty, toast } from '../../ui/kit'
import { Back, CompanyLogo, JobCard } from './parts'

/** صفحة الشركة العامة: هويتها ونبذة عنها وشواغرها المنشورة */
export function CompanyPage({ slug }: { slug: string }) {
  const L = useL(); const lang = useLang()
  const { data: c, isLoading, error } = useCompanyPage(slug)
  useEffect(() => { if (c) setMeta({ title: `${bi(c.name, lang)} — ${L('فرص العمل', 'Jobs')}`, description: bi(c.about, lang) || undefined, image: c.logo ?? undefined }) }, [c, lang])
  const copy = () => navigator.clipboard?.writeText(location.origin + `/c/${slug}`).then(() => toast(L('تم نسخ رابط الشركة', 'Company link copied')), () => {})
  return <div className="max-w-5xl mx-auto px-5 md:px-8 py-6 space-y-5">
    <Back to="/jobs">{L('كل فرص العمل', 'All jobs')}</Back>
    {isLoading && <div className="rounded-3xl border border-line bg-surface h-48 animate-pulse" />}
    {error && <div className="rounded-2xl border border-line bg-surface"><Empty title={L('هذه الشركة غير متاحة', 'This company is not available')} action={<Btn onClick={() => history.back()}>{L('رجوع', 'Back')}</Btn>}>{error.message}</Empty></div>}
    {c && <>
      <section className="show-root relative overflow-hidden rounded-3xl px-6 py-8 md:px-10">
        <div className="show-bg" aria-hidden><i /><i /></div><div className="show-grid" aria-hidden />
        <div className="relative flex flex-col md:flex-row md:items-center gap-5 show-in">
          <CompanyLogo c={c} size={88} />
          <div className="flex-1 min-w-0">
            {c.brand_latin && <div className="text-[12px] font-bold tracking-[.3em] text-brand ltr text-start">{c.brand_latin}</div>}
            <h1 className="text-[clamp(24px,3vw,36px)] font-black leading-tight mt-1">{bi(c.name, lang)}</h1>
            {bi(c.about, lang) && <p className="mt-2 text-show-fg/70 max-w-[62ch]">{bi(c.about, lang)}</p>}
            <div className="flex flex-wrap items-center gap-3 mt-3 text-[13px] text-show-fg/75">
              <span className="num">{c.jobs.length} {L('فرص مفتوحة', 'open roles')}</span>
              {c.website && <a href={c.website} target="_blank" rel="noopener" className="inline-flex items-center gap-1 hover:text-brand ltr"><Globe size={14} />{c.website.replace(/^https?:\/\//, '')}</a>}
            </div>
          </div>
          <Btn size="sm" icon={<Link2 size={14} />} onClick={copy}>{L('نسخ الرابط', 'Copy link')}</Btn>
        </div>
      </section>
      <h2 className="text-[18px] font-bold">{L('الوظائف الشاغرة', 'Open positions')}</h2>
      <div className="grid gap-3">
        {c.jobs.length === 0 && <div className="rounded-2xl border border-line bg-surface"><Empty title={L('لا توجد شواغر حالياً', 'No open positions right now')}>{L('تابع صفحة الشركة — تُنشر الوظائف الجديدة هنا.', 'Follow this page — new roles are posted here.')}</Empty></div>}
        {c.jobs.map((j, k) => <JobCard key={j.code} j={j} k={k} hideCompany />)}
      </div>
    </>}
  </div>
}
