import { useEffect } from 'react'
import { CheckCircle2, Link2, Flame, UserCheck, AlertTriangle } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { applyUrl, useJob } from '../../lib/public'
import { go } from '../../lib/router'
import { jobPostingLd, setMeta } from '../../lib/seo'
import { Badge, Btn, Empty, toast } from '../../ui/kit'
import { Back, CompanyLogo, JobMeta } from './parts'

/** صفحة الشاغر (رابط مباشر قابل للمشاركة والفهرسة) — مع رابط الإحالة ?ref= إن وُجد */
export function JobPage({ slug, code, refCode }: { slug: string; code: string; refCode?: string }) {
  const L = useL(); const lang = useLang()
  const { data: j, isLoading, error } = useJob(slug, code, refCode)
  useEffect(() => {
    if (j) setMeta({ title: `${bi(j.title, lang)} — ${bi(j.company.name, lang)}`, description: bi(j.summary, lang), image: j.company.logo ?? undefined, jsonLd: jobPostingLd(j, lang) })
    return () => setMeta({ title: 'UHR', jsonLd: null })
  }, [j, lang])
  const ref = j?.referral?.valid ? j.referral.code : undefined
  const copy = () => navigator.clipboard?.writeText(location.origin + location.pathname + (ref ? `?ref=${ref}` : '')).then(() => toast(L('تم نسخ رابط الوظيفة', 'Job link copied')), () => {})
  return <div className="max-w-4xl mx-auto px-5 py-8 space-y-4">
    <Back to="/jobs">{L('كل فرص العمل', 'All jobs')}</Back>
    {isLoading && <div className="rounded-2xl border border-line bg-surface h-56 animate-pulse" />}
    {error && <div className="rounded-2xl border border-line bg-surface"><Empty title={L('هذه الوظيفة غير متاحة', 'This position is not available')} action={<Btn kind="primary" onClick={() => go('jobs')}>{L('عرض فرص العمل المفتوحة', 'See open jobs')}</Btn>}>{error.message}</Empty></div>}
    {j && <>
      {j.referral && (j.referral.valid
        ? <div className="flex items-center gap-2 rounded-xl bg-brand-soft text-brand-2 px-4 py-2.5 text-[14px]"><UserCheck size={17} />{L(`رشّحك ${j.referral.by?.ar} لهذه الوظيفة — سيُربط طلبك برابط إحالته.`, `${j.referral.by?.en} referred you for this role — your application will be linked to their referral.`)}</div>
        : <div className="flex items-center gap-2 rounded-xl bg-warn-soft text-warn px-4 py-2.5 text-[14px]"><AlertTriangle size={17} />{L('رابط الإحالة غير صالح أو منتهٍ. يمكنك التقديم بدونه، أو تصفّح باقي الفرص.', 'This referral link is invalid or expired. You can still apply without it, or browse other jobs.')}</div>)}
      <article className="rounded-2xl border border-line bg-surface p-5 md:p-6">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0"><div className="flex items-center gap-2 flex-wrap"><h1 className="text-[22px] font-bold">{bi(j.title, lang)}</h1>
            {j.hard_to_fill && <Badge tone="brass"><Flame size={12} />{L('مطلوب بشدة', 'Hiring urgently')}</Badge>}<span className="text-[12px] text-ink-3 ltr">{j.code}</span></div>
            <a href={`/c/${j.company.slug}`} className="text-[14px] text-ink-2 hover:text-brand">{bi(j.company.name, lang)}</a>
            <JobMeta j={j} /></div>
          <Btn size="sm" icon={<Link2 size={14} />} onClick={copy}><span className="hidden sm:inline">{L('نسخ الرابط', 'Copy link')}</span></Btn>
        </div>
        <p className="text-ink-2 mt-4 max-w-[70ch]">{bi(j.summary, lang)}</p>
        {!!j.requirements?.length && <><h2 className="font-semibold mt-5 mb-2">{L('المتطلبات', 'Requirements')}</h2>
          <ul className="space-y-1.5">{j.requirements.map((r, i) => <li key={i} className="flex gap-2 text-[14px]"><CheckCircle2 size={16} className="text-brand shrink-0 mt-0.5" />{bi(r, lang)}</li>)}</ul></>}
        <div className="flex flex-wrap gap-2 mt-5">
          {!!j.openings && <Badge tone="brand">{L(`${j.openings} شواغر`, `${j.openings} openings`)}</Badge>}
          <Badge>{j.min_years ? L(`خبرة ${j.min_years}+ سنوات`, `${j.min_years}+ years experience`) : L('بدون خبرة سابقة', 'No prior experience')}</Badge>
          {j.degree_label && <Badge>{bi(j.degree_label, lang)}</Badge>}
          {j.requires_photo && <Badge tone="info">{L('تتطلب صورة شخصية', 'Photo required')}</Badge>}
        </div>
      </article>
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-line bg-surface p-5">
        <div><div className="font-semibold">{L('نموذج طلب التوظيف', 'Employment application form')} <span className="ltr text-ink-3 text-[13px]">HR-01-F-28</span></div>
          <p className="text-[13px] text-ink-2">{L('أربع خطوات قصيرة، وتتابع حالة طلبك من حسابك بعد الإرسال.', 'Four short steps — then follow your application from your account.')}</p></div>
        <a href={applyUrl(j, ref)} className="inline-flex items-center justify-center gap-2 rounded-lg border font-semibold h-11 px-5 text-[15px] bg-brand text-on-brand border-brand hover:brightness-110">{L('قدّم على الوظيفة', 'Apply for this role')}</a>
      </div>
      <a href={`/c/${j.company.slug}`} className="hoverlift flex items-center gap-4 rounded-2xl border border-line bg-surface p-4">
        <CompanyLogo c={j.company} size={56} />
        <div className="min-w-0"><div className="text-[12px] text-ink-3">{L('عن الشركة', 'About the company')}</div><div className="font-semibold">{bi(j.company.name, lang)}</div>
          {bi(j.company_about, lang) && <p className="text-[13px] text-ink-2 line-clamp-2">{bi(j.company_about, lang)}</p>}</div>
      </a>
    </>}
  </div>
}
