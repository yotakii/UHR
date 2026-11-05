import { useState } from 'react'
import { Plus, ExternalLink, Globe, Building2, Lock, Lock as LockIcon, Unlock, Link2, ClipboardCheck, Users, Send, X } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../lib/store'
import { navigate, setQuery, useRoute } from '../../lib/router'
import { useCvMatches, useHrJobs, useInviteCv, useJobStatus, type HrJob } from '../../lib/hr'
import { ApiError } from '../../lib/api'
import { can, useMe } from '../../lib/session'
import { DEGREES, STAGES, type Stage } from '../../lib/recruitment'
import { Badge, Btn, Drawer, Empty, PageHead, Panel, toast } from '../../ui/kit'
import { DecisionDialog } from '../../ui/decision'
import { JobForm } from './JobForm'

const FIVE: Stage[] = ['new', 'screening', 'interview', 'technical', 'offer']

/** الوظائف الشاغرة (منقولة من Jobs في الديمو) + مكان النشر وحالة مراجعة المنصة (SRS §10.15) */
export function Jobs() {
  const L = useL(); const lang = useLang()
  const { data, isLoading, error, refetch } = useHrJobs()
  const status = useJobStatus()
  // الواجهة تخفي ما لا تسمح به صلاحيات الدور — والخادم يرفضه على كل حال (SRS §10.2)
  const me = useMe().data
  const canCreate = can(me, 'jobs', 'create'), canEdit = can(me, 'jobs', 'edit')
  const [edit, setEdit] = useState<{ open: boolean; id: number | null }>({ open: false, id: null })
  const [toggle, setToggle] = useState<HrJob | null>(null)
  const company = data?.company
  const route = useRoute()
  const matches = route.query.matches ? Number(route.query.matches) : null
  const canCand = can(me, 'candidates')
  const publicUrl = (j: HrJob) => `/jobs/${company?.slug}/${encodeURIComponent(j.code)}`
  const copy = (j: HrJob) => navigator.clipboard?.writeText(location.origin + publicUrl(j)).then(() => toast(L('تم نسخ رابط الوظيفة', 'Job link copied')), () => {})
  return <div className="max-w-[1280px] mx-auto">
    <PageHead title={L('الوظائف الشاغرة', 'Job openings')} sub={L('الوظائف المنشورة على بوابة التوظيف وعدد المتقدمين في كل مرحلة.', 'Roles published on the careers portal and applicants per stage.')}
      actions={canCreate && <Btn kind="primary" icon={<Plus size={16} />} onClick={() => setEdit({ open: true, id: null })}>{L('شاغر جديد', 'New position')}</Btn>} />
    {company && <div className={`mb-4 flex flex-wrap items-center gap-2 rounded-xl border px-4 py-2.5 text-[13px] ${company.portal_enabled ? 'border-brand/30 bg-brand-soft text-brand-2' : 'border-line bg-sub text-ink-2'}`}>
      <Globe size={16} />
      {company.portal_enabled
        ? (company.portal_review ? L('النشر على بوابة فرص العمل مفعّل — الشواغر العامة تظهر في البوابة بعد مراجعة المنصة، وتظهر فوراً في صفحة شركتكم.', 'Publishing on the jobs portal is on — public positions appear on the portal after platform review, and on your company page immediately.')
          : L('النشر على بوابة فرص العمل مفعّل ومباشر.', 'Publishing on the jobs portal is on and immediate.'))
        : L('النشر على البوابة العامة غير مفعّل لشركتكم — الشواغر تظهر في صفحة شركتكم فقط.', 'Public portal publishing is off for your company — positions show on your company page only.')}
      <a href={`/c/${company.slug}`} target="_blank" rel="noopener" className="ms-auto inline-flex items-center gap-1 font-semibold underline-offset-2 hover:underline"><ExternalLink size={14} />{L('صفحة الشركة', 'Company page')}</a>
    </div>}
    <Panel pad={false}>
      {isLoading && <div className="h-48 animate-pulse" />}
      {error && <Empty title={L('تعذّر تحميل الوظائف', 'Could not load positions')} action={<Btn onClick={() => refetch()}>{L('إعادة المحاولة', 'Retry')}</Btn>}>{error.message}</Empty>}
      {data?.data.length === 0 && <Empty title={L('لا توجد شواغر بعد', 'No positions yet')} action={canCreate && <Btn kind="primary" icon={<Plus size={16} />} onClick={() => setEdit({ open: true, id: null })}>{L('أضف أول شاغر', 'Add the first position')}</Btn>}>{L('أنشئ شاغراً واختر مكان نشره: داخلي، صفحة الشركة، أو البوابة العامة.', 'Create a position and choose where to publish it: internal, company page, or the public portal.')}</Empty>}
      {!!data?.data.length && <div className="overflow-hidden"><table className="tbl w-full"><thead><tr>
        <th>{L('الوظيفة', 'Role')}</th><th className="hidden md:table-cell">{L('المتطلبات والراتب', 'Requirements & pay')}</th>
        <th className="hidden sm:table-cell">{L('النشر', 'Publishing')}</th><th className="hidden lg:table-cell">{L('المتقدمون حسب المرحلة', 'Applicants by stage')}</th><th /></tr></thead>
        <tbody>{data.data.map(j => <tr key={j.id} className={canEdit ? 'click' : undefined} onClick={canEdit ? () => setEdit({ open: true, id: j.id }) : undefined}>
          <td><div className="font-medium">{bi(j.title, lang)}</div>
            <div className="text-[12px] text-ink-3"><span className="ltr inline-block whitespace-nowrap">{j.code}</span> · {bi(j.dept, lang)} · {bi(j.city, lang)} · {L(`${j.openings} شاغر`, `${j.openings} opening${j.openings > 1 ? 's' : ''}`)}</div>
            <div className="sm:hidden mt-1.5"><Publishing j={j} /></div></td>
          <td className="hidden md:table-cell text-[13px] text-ink-2"><div className="whitespace-nowrap">{j.min_years} {L('سنوات', 'yrs')} · {DEGREES[j.degree][lang]}</div>
            <div className="num whitespace-nowrap"><bdi dir="ltr">{j.currency === 'USD' ? '$' : ''}{j.salary_min}–{j.salary_max}{j.currency === 'SYP' ? ' SYP' : ''}</bdi></div></td>
          <td className="hidden sm:table-cell"><Publishing j={j} /></td>
          <td className="hidden lg:table-cell"><div className="flex gap-1">{FIVE.map(s => { const n = j.pipeline?.[s] ?? 0
            return <span key={s} title={STAGES[s][lang]} className={`min-w-[44px] rounded-lg border px-1.5 py-1 text-center ${n ? 'border-brand/40 bg-brand-soft' : 'border-line'}`}>
              <span className={`block text-[14px] num ${n ? 'font-bold text-brand' : 'text-ink-3'}`}>{n || '·'}</span><span className="block text-[10px] text-ink-3 truncate max-w-[56px]">{STAGES[s][lang]}</span></span> })}</div></td>
          <td onClick={e => e.stopPropagation()}><div className="flex flex-wrap gap-1 justify-end">
            <Btn size="sm" kind={j.test_enabled ? 'secondary' : 'ghost'} onClick={() => navigate(`/hr/jobs/${j.id}/test`)} title={L('الاختبار الأولي', 'Preliminary test')} aria-label={L('الاختبار الأولي', 'Preliminary test')}>
              <ClipboardCheck size={15} className={j.test_enabled ? 'text-brand' : undefined} /><span className="hidden xl:inline">{L('الاختبار', 'Test')}</span></Btn>
            {canCand && <Btn size="sm" kind="ghost" onClick={() => setQuery({ matches: String(j.id) })} title={L('مطابقة من قاعدة السير', 'Matches from the CV database')} aria-label={L('مطابقة من قاعدة السير', 'Matches from the CV database')}><Users size={15} /></Btn>}
            {j.visibility !== 'internal' && j.accepting && <Btn size="sm" kind="ghost" onClick={() => copy(j)} aria-label={L('نسخ الرابط', 'Copy link')} title={L('نسخ الرابط', 'Copy link')}><Link2 size={15} /></Btn>}
            {canEdit && j.status !== 'draft' && <Btn size="sm" kind={j.status === 'open' ? 'danger' : 'secondary'} icon={j.status === 'open' ? <LockIcon size={14} /> : <Unlock size={14} />} onClick={() => setToggle(j)}>{j.status === 'open' ? L('إغلاق', 'Close') : L('إعادة فتح', 'Reopen')}</Btn>}
          </div></td>
        </tr>)}</tbody></table></div>}
    </Panel>
    {matches && <CvMatchesDrawer id={matches} onClose={() => setQuery({ matches: undefined })} canInvite={can(me, 'candidates', 'edit')} />}
    <JobForm open={edit.open} id={edit.id} company={company} onClose={() => setEdit({ open: false, id: null })} />
    <DecisionDialog open={!!toggle} onClose={() => setToggle(null)} tone={toggle?.status === 'open' ? 'danger' : 'primary'}
      title={toggle?.status === 'open' ? L('إغلاق الشاغر', 'Close position') : L('إعادة فتح الشاغر', 'Reopen position')}
      subject={toggle && <><span className="font-semibold">{bi(toggle.title, lang)}</span> <span className="ltr text-ink-3">{toggle.code}</span>
        <div className="text-[13px] text-ink-2 mt-1">{toggle.status === 'open' ? L('سيختفي من البوابة وصفحة الشركة ويتوقف التقديم عليه. الطلبات الحالية تبقى.', 'It disappears from the portal and company page and stops taking applications. Existing applications stay.') : L('يعود للظهور حسب مكان نشره ويُقبل التقديم عليه.', 'It reappears where it is published and accepts applications again.')}</div></>}
      confirmLabel={toggle?.status === 'open' ? L('إغلاق الشاغر', 'Close position') : L('إعادة الفتح', 'Reopen')}
      onConfirm={() => status.mutateAsync({ id: toggle!.id, status: toggle!.status === 'open' ? 'closed' : 'open' }).then(() => toast(L('تم تحديث حالة الشاغر', 'Position updated')))} />
  </div>
}

/** مكان النشر + حالة المراجعة + الحالة/الموعد */
function Publishing({ j }: { j: HrJob }) {
  const L = useL(); const lang = useLang()
  const where = j.visibility === 'internal' ? <Badge><Lock size={12} />{L('داخلي', 'Internal')}</Badge> : j.visibility === 'company' ? <Badge><Building2 size={12} />{L('صفحة الشركة', 'Company page')}</Badge> : <Badge tone="info"><Globe size={12} />{L('البوابة العامة', 'Public portal')}</Badge>
  const review = j.visibility !== 'public' ? null : j.portal_status === 'approved' ? <Badge tone="brand" dot>{L('منشور في البوابة', 'Live on portal')}</Badge>
    : j.portal_status === 'pending' ? <Badge tone="brass" dot>{L('بانتظار مراجعة المنصة', 'Awaiting platform review')}</Badge>
    : j.portal_status === 'rejected' ? <span title={j.portal_note ?? ''}><Badge tone="danger" dot>{L('لم يُنشر — اقرأ السبب', 'Not published — see reason')}</Badge></span> : null
  const state = j.status === 'draft' ? <Badge>{L('مسودة', 'Draft')}</Badge> : j.status === 'closed' || !j.accepting ? <Badge tone="danger">{L('مغلق', 'Closed')}</Badge> : j.closes_at ? <span className="text-[11px] text-ink-3">{L('حتى', 'until')} {fmtDate(j.closes_at, lang)}</span> : null
  return <div className="flex flex-col items-start gap-1">{where}{review}{state}</div>
}

/**
 * سير قاعدة الشركة المطابقة للشاغر (ملاحظة الاختبار 46): تُقاس بنفس معادلة المطابقة على متطلبات الشاغر،
 * لمن وافق على حفظ سيرته ولم يتقدم لهذا الشاغر. «دعوة للتقديم» ترسل بريداً برابط التقديم — لا يُنشأ طلب تلقائياً.
 */
function CvMatchesDrawer({ id, onClose, canInvite }: { id: number; onClose: () => void; canInvite: boolean }) {
  const L = useL(); const lang = useLang()
  const q = useCvMatches(id)
  const inv = useInviteCv(id)
  const d = q.data
  return <Drawer open onClose={onClose} width={640}>
    <div className="flex items-start justify-between gap-3 p-5 border-b border-line-2">
      <div><h3 className="font-semibold text-[16px]">{L('مطابقة من قاعدة السير الذاتية', 'Matches from the CV database')}</h3>
        {d && <div className="text-[13px] text-ink-3">{bi(d.job.title, lang)} · <span className="ltr inline-block">{d.job.code}</span> · {L(`مطابقة ${d.min}٪ فأكثر`, `${d.min}%+ match`)}</div>}</div>
      <button onClick={onClose} className="p-1 rounded hover:bg-sub text-ink-3" aria-label={L('إغلاق', 'Close')}><X size={18} /></button>
    </div>
    <div className="p-5">
      {!d ? <Empty title={q.isError ? L('تعذّر التحميل', 'Could not load') : L('جارٍ الحساب…', 'Calculating…')} />
        : d.data.length === 0 ? <Empty title={L('لا توجد سير مطابقة حالياً', 'No matching CVs right now')}>{L('تُعرض هنا سير من وافقوا على حفظ سيرتهم لفرص قادمة، إذا طابقت متطلبات هذا الشاغر.', 'CVs of applicants who agreed to be kept for future openings appear here when they match this job.')}</Empty>
        : <ul className="divide-y divide-line-2 border border-line rounded-lg">{d.data.map(m => <li key={m.id} className="p-3 flex items-center gap-3">
          <span className={`size-11 shrink-0 rounded-full grid place-items-center text-[13px] font-bold num ${m.score >= 75 ? 'bg-brand text-on-brand' : m.score >= 60 ? 'bg-brand-soft text-brand' : 'bg-sub text-ink-2'}`}>{m.score}</span>
          <div className="flex-1 min-w-0"><a className="font-semibold hover:text-brand" href={`/hr/candidates/${m.id}`}>{bi(m.name, lang)}</a>
            <div className="text-[12.5px] text-ink-3 truncate">{[m.major, L(`${m.years} سنوات خبرة`, `${m.years} yrs`), L(`تقدّم سابقاً: ${m.last_job.ar ?? ''}`, `Applied before: ${m.last_job.en ?? ''}`)].filter(Boolean).join(' · ')}</div></div>
          {m.invited ? <Badge tone="info">{L('دُعي', 'Invited')}</Badge>
            : canInvite && d.job.accepting && <Btn size="sm" icon={<Send size={14} />} disabled={inv.isPending} onClick={() => inv.mutateAsync(m.id).then(() => toast(L('أُرسلت الدعوة بالبريد', 'Invitation emailed'))).catch(e => toast(e instanceof ApiError ? e.message : L('تعذّر الإرسال', 'Could not send')))}>{L('دعوة للتقديم', 'Invite to apply')}</Btn>}
        </li>)}</ul>}
      {d && !d.job.accepting && d.data.length > 0 && <p className="text-[12.5px] text-warn mt-3">{L('الشاغر لا يستقبل طلبات الآن — افتحه لإرسال الدعوات.', 'The job is not accepting applications — open it to send invitations.')}</p>}
    </div>
  </Drawer>
}
