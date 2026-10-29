import { useState } from 'react'
import { LayoutGrid, Building2, ClipboardCheck, Type, Briefcase, Inbox, Clock, CheckCircle2, XCircle, MapPin, DatabaseBackup, ScanText } from 'lucide-react'
import { useL, useLang, bi, fmtDateTime } from '../../lib/store'
import { useRoute } from '../../lib/router'
import type { SessionUser } from '../../lib/session'
import { useOverview, useReview, useReviews, type ReviewJob } from '../../lib/platform'
import { Badge, Btn, Empty, PageHead, Panel, Tabs, toast } from '../../ui/kit'
import { DecisionDialog } from '../../ui/decision'
import { CompanyLogo } from '../public/parts'
import { Shell, type NavItem } from '../console/Shell'
import { Companies } from './Companies'
import { PortalTexts } from './PortalTexts'
import { PlatformBackups } from '../hr/admin/Backups'
import { CvReading } from './CvReading'

/** لوحة مدير المنصة: نظرة عامة، الشركات، مراجعة الشواغر، نصوص البوابة */
export function PlatformApp({ user }: { user: SessionUser }) {
  const L = useL()
  const route = useRoute()
  const pending = useOverview().data?.pending
  const items: NavItem[] = [
    { to: '/platform', icon: <LayoutGrid size={18} />, label: L('نظرة عامة', 'Overview') },
    { to: '/platform/companies', icon: <Building2 size={18} />, label: L('الشركات', 'Companies'), group: L('المنصة', 'Platform') },
    { to: '/platform/reviews', icon: <ClipboardCheck size={18} />, label: L('مراجعة الشواغر', 'Job reviews'), count: pending, group: L('المنصة', 'Platform') },
    { to: '/platform/portal', icon: <Type size={18} />, label: L('نصوص البوابة', 'Portal texts'), group: L('المنصة', 'Platform') },
    { to: '/platform/cv-reading', icon: <ScanText size={18} />, label: L('قراءة السير', 'CV reading'), group: L('المنصة', 'Platform') },
    { to: '/platform/backups', icon: <DatabaseBackup size={18} />, label: L('النسخ الاحتياطي', 'Backups'), group: L('المنصة', 'Platform') },
  ]
  const sub = route.parts[1]
  return <Shell items={items} user={user} org="Atmata · UHR" sub={L('إدارة منصة UHR وبوابة فرص العمل', 'UHR platform & jobs portal administration')}>
    {sub === 'companies' ? <Companies /> : sub === 'reviews' ? <Reviews /> : sub === 'portal' ? <PortalTexts /> : sub === 'backups' ? <PlatformBackups /> : sub === 'cv-reading' ? <CvReading /> : <OverviewPage />}
  </Shell>
}

function Kpi({ icon, label, value, tone = 'brand' }: { icon: React.ReactNode; label: string; value?: number; tone?: 'brand' | 'brass' | 'info' }) {
  const c = tone === 'brand' ? 'bg-brand-soft text-brand' : tone === 'brass' ? 'bg-brass-soft text-brass' : 'bg-info-soft text-info'
  return <div className="bg-surface border border-line rounded-2xl p-4 flex items-center gap-3">
    <span className={`size-11 rounded-xl grid place-items-center ${c}`}>{icon}</span>
    <div><div className="text-[24px] font-black num leading-none">{value ?? '—'}</div><div className="text-[12px] text-ink-3 mt-1">{label}</div></div>
  </div>
}

function OverviewPage() {
  const L = useL(); const lang = useLang()
  const { data } = useOverview()
  return <div className="max-w-[1200px] mx-auto space-y-5">
    <PageHead title={L('نظرة عامة', 'Overview')} sub={L('حركة بوابة فرص العمل والشركات على المنصة.', 'Jobs-portal activity and companies on the platform.')} />
    <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
      <Kpi icon={<Building2 size={20} />} label={L('شركات فعّالة', 'Active companies')} value={data?.active} />
      <Kpi icon={<Briefcase size={20} />} label={L('فرص منشورة في البوابة', 'Jobs live on the portal')} value={data?.portal_jobs} tone="info" />
      <Kpi icon={<Clock size={20} />} label={L('بانتظار مراجعتك', 'Awaiting your review')} value={data?.pending} tone="brass" />
      <Kpi icon={<Inbox size={20} />} label={L('طلبات توظيف (30 يوماً)', 'Applications (30 days)')} value={data?.applications_30d} />
    </div>
    <Panel title={L('التقديم حسب الشركة', 'Applications by company')} pad={false}>
      <div><table className="tbl w-full"><thead><tr><th>{L('الشركة', 'Company')}</th><th>{L('فرص في البوابة', 'Portal jobs')}</th><th>{L('طلبات (30 يوماً)', 'Applications (30d)')}</th><th className="hidden sm:table-cell">{L('كل الطلبات', 'All applications')}</th></tr></thead>
        <tbody>{data?.by_company.map(c => <tr key={c.slug}><td><div className="flex items-center gap-2"><CompanyLogo c={c} size={32} /><span className="font-medium">{bi(c.name, lang)}</span></div></td>
          <td className="num">{c.jobs}</td><td className="num">{c.applications_30d}</td><td className="num hidden sm:table-cell">{c.applications}</td></tr>)}</tbody></table></div>
    </Panel>
  </div>
}

/** مراجعة شواغر الشركات قبل ظهورها في البوابة — الرفض بسبب يصل للشركة */
function Reviews() {
  const L = useL()
  const [tab, setTab] = useState<'pending' | 'approved' | 'rejected'>('pending')
  const { data, isLoading } = useReviews(tab)
  return <div className="max-w-[1100px] mx-auto">
    <PageHead title={L('مراجعة الشواغر', 'Job reviews')} sub={L('الشواغر التي طلبت الشركات نشرها على بوابة فرص العمل. راجع المحتوى قبل ظهوره للزوار.', 'Positions companies asked to publish on the jobs portal. Review the content before visitors see it.')} />
    <div className="mb-4"><Tabs value={tab} onChange={setTab} tabs={[{ v: 'pending', label: L('بانتظار المراجعة', 'Pending') }, { v: 'approved', label: L('منشورة', 'Approved') }, { v: 'rejected', label: L('مرفوضة', 'Rejected') }]} /></div>
    {isLoading && <div className="h-40 rounded-2xl border border-line bg-surface animate-pulse" />}
    {data?.length === 0 && <Panel><Empty title={tab === 'pending' ? L('لا شواغر بانتظار المراجعة', 'Nothing awaiting review') : L('لا توجد شواغر هنا', 'No positions here')} /></Panel>}
    <div className="grid gap-3">{data?.map(j => <ReviewCard key={j.id} j={j} />)}</div>
  </div>
}

function ReviewCard({ j }: { j: ReviewJob }) {
  const L = useL(); const lang = useLang()
  const review = useReview()
  const [d, setD] = useState<'approved' | 'rejected' | null>(null)
  return <article className="rounded-2xl border border-line bg-surface p-5">
    <div className="flex flex-wrap items-start gap-3">
      <CompanyLogo c={j.company} size={48} />
      <div className="flex-1 min-w-0">
        <div className="flex flex-wrap items-center gap-2"><h3 className="font-semibold text-[16px]">{bi(j.title, lang)}</h3><span className="ltr text-[12px] text-ink-3">{j.code}</span>
          {j.portal_status === 'approved' && <Badge tone="brand" dot>{L('منشور', 'Live')}</Badge>}{j.portal_status === 'rejected' && <Badge tone="danger" dot>{L('مرفوض', 'Rejected')}</Badge>}</div>
        <div className="text-[13px] text-ink-2">{bi(j.company.name, lang)} · {bi(j.dept, lang)} · <MapPin size={12} className="inline" /> {bi(j.city, lang)} · {bi(j.type, lang)}</div>
        <div className="text-[12px] text-ink-3 mt-0.5">{L('أُرسل', 'Submitted')} {fmtDateTime(j.submitted_at, lang)} · {L('الراتب الداخلي', 'Internal pay')} <span className="num ltr">{j.salary.min}–{j.salary.max} {j.salary.currency}</span></div>
      </div>
      {j.portal_status !== 'approved' && <Btn kind="primary" icon={<CheckCircle2 size={15} />} onClick={() => setD('approved')}>{L('نشر في البوابة', 'Publish')}</Btn>}
      {j.portal_status !== 'rejected' && <Btn kind="danger" icon={<XCircle size={15} />} onClick={() => setD('rejected')}>{j.portal_status === 'approved' ? L('إيقاف النشر', 'Unpublish') : L('رفض', 'Reject')}</Btn>}
    </div>
    <p className="text-[14px] text-ink-2 mt-3 max-w-[80ch]">{bi(j.summary, lang)}</p>
    <ul className="mt-2 text-[13px] text-ink-2 list-disc ps-5 space-y-0.5">{j.requirements.map((r, i) => <li key={i}>{bi(r, lang)}</li>)}</ul>
    {j.portal_note && <div className="mt-3 text-[13px] rounded-lg bg-danger-soft text-danger px-3 py-2">{L('سبب الرفض:', 'Reason:')} {j.portal_note}</div>}
    <DecisionDialog open={!!d} onClose={() => setD(null)} tone={d === 'rejected' ? 'danger' : 'primary'} reasonRequired={d === 'rejected'}
      title={d === 'rejected' ? L('رفض نشر الشاغر', 'Reject publishing') : L('نشر الشاغر في البوابة', 'Publish on the portal')}
      subject={<><span className="font-semibold">{bi(j.title, lang)}</span> — {bi(j.company.name, lang)}</>}
      reasonLabel={d === 'rejected' ? L('سبب الرفض (يصل للموارد البشرية في الشركة)', 'Reason (sent to the company HR)') : undefined}
      confirmLabel={d === 'rejected' ? L('تأكيد الرفض', 'Confirm rejection') : L('تأكيد النشر', 'Confirm publishing')}
      onConfirm={reason => review.mutateAsync({ id: j.id, decision: d!, reason }).then(() => toast(d === 'approved' ? L('نُشر الشاغر في البوابة', 'Published on the portal') : L('رُفض النشر وأُبلغت الشركة', 'Rejected — the company was notified')))} />
  </article>
}
