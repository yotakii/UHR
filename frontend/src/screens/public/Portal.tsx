import { useEffect, type ReactNode } from 'react'
import { Briefcase, Building2, MapPin } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { usePortal } from '../../lib/public'
import { useMe } from '../../lib/session'
import { setMeta } from '../../lib/seo'
import { LoginCard } from './Auth'
import { JobsBoard } from './JobsBoard'
import { CompanyLogo } from './parts'

/** الصفحة الرئيسية = بوابة فرص العمل لكل الشركات (SRS §10.15) */
export function PortalHome() {
  const L = useL(); const lang = useLang()
  const p = usePortal().data
  const signedIn = !!useMe().data
  useEffect(() => { if (p) setMeta({ title: `${bi(p.hero.title, lang)} — UHR`, description: bi(p.hero.text, lang) }) }, [p, lang])
  const stats: [ReactNode, number | undefined, string][] = [
    [<Briefcase key="j" />, p?.stats.jobs, L('فرصة عمل مفتوحة', 'open positions')],
    [<Building2 key="c" />, p?.stats.companies, L('شركات توظّف الآن', 'companies hiring')],
    [<MapPin key="m" />, p?.stats.cities, L('مدن', 'cities')],
  ]
  return <div className="max-w-6xl mx-auto px-5 md:px-8 py-6 md:py-8 space-y-7">
    <section className="show-root relative overflow-hidden rounded-3xl px-7 py-9 md:px-12 md:py-12">
      <div className="show-bg" aria-hidden><i /><i /></div><div className="show-grid" aria-hidden />
      <div className="relative grid md:grid-cols-[1.3fr_1fr] gap-8 items-center show-in">
        <div><div className="text-[12px] font-bold tracking-[.3em] text-brand min-h-[18px] ltr text-start">{p?.brand_latin}</div>
          <h1 className="mt-3 text-[clamp(28px,4vw,50px)] font-black leading-[1.15]">{p ? bi(p.hero.title, lang) : ' '}</h1>
          <p className="mt-3 text-show-fg/70 text-[16px] max-w-[52ch]">{p && bi(p.hero.text, lang)}</p>
          <div className="mt-6 flex flex-wrap gap-2"><button onClick={() => document.getElementById('jobs')?.scrollIntoView({ behavior: 'smooth' })} className="h-11 px-5 rounded-xl bg-brand text-on-brand font-bold hover:brightness-110">{L('تصفّح فرص العمل', 'Browse jobs')}</button></div></div>
        <div className="grid grid-cols-3 gap-3">{stats.map(([ic, n, t], k) =>
          <div key={k} className="rounded-2xl border border-show-fg/10 bg-show-fg/[.05] p-4 text-center"><div className="icon-orb mx-auto size-12 rounded-full border border-brand/40 grid place-items-center text-brand">{ic}</div>
            <div className="mt-3 text-[22px] font-black num">{n ?? '—'}</div><div className="text-[12px] font-semibold text-show-fg/75">{t}</div></div>)}</div>
      </div>
    </section>

    {!!p?.companies.length && <section>
      <h2 className="text-[15px] font-bold mb-3">{L('شركات توظّف الآن', 'Companies hiring now')}</h2>
      <div className="flex gap-3 overflow-x-auto pb-1">{p.companies.map(c =>
        <a key={c.slug} href={`/c/${c.slug}`} className="hoverlift shrink-0 flex items-center gap-3 rounded-2xl border border-line bg-surface ps-2 pe-4 py-2 min-w-[220px]">
          <CompanyLogo c={c} size={48} /><span className="min-w-0"><span className="block font-semibold text-[14px] truncate">{bi(c.short, lang)}</span><span className="block text-[12px] text-ink-3 num">{c.jobs} {L('فرص', 'jobs')}</span></span></a>)}</div>
    </section>}

    <div className={signedIn ? '' : 'grid lg:grid-cols-[380px_1fr] gap-7 items-start'}>
      {!signedIn && <LoginCard />}
      <JobsBoard />
    </div>
  </div>
}
