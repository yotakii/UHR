import { type ReactNode } from 'react'
import { MapPin, Briefcase, Building2, Flame, ArrowRight, ArrowLeft, CalendarClock } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../lib/store'
import { applyUrl, jobUrl, type PublicJob } from '../../lib/public'
import type { CompanyCard } from '../../lib/session'
import { Badge } from '../../ui/kit'

/** شعار الشركة على خلفية بيضاء (الشعارات مصممة للخلفيات الفاتحة) — أو أيقونة عند غيابه */
export function CompanyLogo({ c, size = 44 }: { c: Pick<CompanyCard, 'logo' | 'name'>; size?: number }) {
  const lang = useLang()
  return <span className="shrink-0 rounded-xl bg-white border border-line grid place-items-center overflow-hidden" style={{ width: size, height: size, padding: Math.round(size / 9) }}>
    {c.logo ? <img src={c.logo} alt={bi(c.name, lang)} className="max-w-full max-h-full object-contain" loading="lazy" /> : <Building2 size={size / 2.2} className="text-ink-3" />}
  </span>
}

export function JobMeta({ j }: { j: PublicJob }) {
  const L = useL(); const lang = useLang()
  return <div className="flex flex-wrap gap-x-4 gap-y-1 mt-2 text-[12px] text-ink-3">
    <span className="flex items-center gap-1"><Briefcase size={13} />{bi(j.dept, lang)}</span>
    <span className="flex items-center gap-1"><MapPin size={13} />{bi(j.city, lang)}</span>
    <span>{bi(j.type, lang)}</span>
    <span>{L('نُشرت', 'Posted')} {fmtDate(j.posted_at, lang)}</span>
    {j.closes_at && <span className="flex items-center gap-1"><CalendarClock size={13} />{L('آخر موعد', 'Apply by')} {fmtDate(j.closes_at, lang)}</span>}
  </div>
}

/** بطاقة شاغر في البوابة وصفحة الشركة */
export function JobCard({ j, k = 0, hideCompany }: { j: PublicJob; k?: number; hideCompany?: boolean }) {
  const L = useL(); const lang = useLang()
  return <article className="hoverlift fadeup rounded-2xl border border-line bg-surface p-4 md:p-5 flex flex-col md:flex-row md:items-center gap-3" style={{ animationDelay: `${Math.min(k, 8) * 50}ms` }}>
    <div className="flex gap-3 flex-1 min-w-0">
      {!hideCompany && <a href={`/c/${j.company.slug}`} aria-label={bi(j.company.name, lang)}><CompanyLogo c={j.company} /></a>}
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2 flex-wrap">
          <a href={jobUrl(j)} className="font-semibold text-[16px] hover:text-brand">{bi(j.title, lang)}</a>
          {j.hard_to_fill && <Badge tone="brass"><Flame size={12} />{L('مطلوب بشدة', 'Hiring urgently')}</Badge>}
          <span className="text-[12px] text-ink-3 ltr">{j.code}</span>
        </div>
        {!hideCompany && <a href={`/c/${j.company.slug}`} className="text-[13px] text-ink-2 hover:text-brand">{bi(j.company.name, lang)}</a>}
        <p className="text-[13px] text-ink-2 mt-1 max-w-[62ch]">{bi(j.summary, lang)}</p>
        <JobMeta j={j} />
      </div>
    </div>
    <a href={applyUrl(j)} className="inline-flex items-center justify-center gap-2 rounded-lg border font-semibold h-9 px-3.5 bg-brand text-on-brand border-brand hover:brightness-110 shrink-0">{L('قدّم على الوظيفة', 'Apply for this role')}</a>
  </article>
}

export function Back({ to = '/', children }: { to?: string; children: ReactNode }) {
  const lang = useLang()
  return <a href={to} className="text-[13px] text-ink-3 hover:text-ink inline-flex items-center gap-1">{lang === 'ar' ? <ArrowRight size={14} /> : <ArrowLeft size={14} />}{children}</a>
}

/** بطاقة الصفحات القصيرة (الدخول، نسيت كلمة المرور…) */
export function AuthCard({ title, sub, children }: { title: ReactNode; sub?: ReactNode; children: ReactNode }) {
  const L = useL()
  return <div className="max-w-md mx-auto px-5 py-10 space-y-4">
    <Back>{L('الصفحة الرئيسية', 'Home')}</Back>
    <section className="bg-surface border border-line rounded-2xl">
      <div className="p-6 border-b border-line-2"><h1 className="text-[20px] font-bold">{title}</h1>{sub && <p className="text-ink-2 text-[13px] mt-1">{sub}</p>}</div>
      <div className="p-6">{children}</div>
    </section>
  </div>
}
