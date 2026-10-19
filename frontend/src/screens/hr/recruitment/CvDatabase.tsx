import { useEffect, useState } from 'react'
import { Search, Download, FileText, Archive, ArchiveRestore, Loader2, ShieldCheck } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../../lib/store'
import { navigate } from '../../../lib/router'
import { can, useMe } from '../../../lib/session'
import { fileUrl } from '../../../lib/console'
import type { ApiError } from '../../../lib/api'
import { STAGES } from '../../../lib/recruitment'
import { cvExportUrl, useArchiveCv, useCvDb, type CvFilters } from '../../../lib/tests'
import { Avatar, Badge, Btn, Empty, PageHead, Panel, Seg, toast } from '../../../ui/kit'

/**
 * قاعدة السير الذاتية (SRS §10.9، D-02): المتقدمون الذين وافقوا على الاحتفاظ بسيرهم فقط.
 * بحث وفلترة بالاختصاص والخبرة والمؤهل والمدينة؛ كل بحث وتصدير وتنزيل يُسجَّل في التدقيق.
 */
export function CvDatabase() {
  const L = useL(); const lang = useLang()
  const me = useMe().data
  const canExport = can(me, 'candidates', 'export'); const canEdit = can(me, 'candidates', 'edit')
  const [f, setF] = useState<CvFilters>({ status: 'active' })
  const [q, setQ] = useState('')
  useEffect(() => { const t = setTimeout(() => setF(x => (x.q ?? '') === q.trim() ? x : { ...x, q: q.trim() || undefined, page: undefined }), 350); return () => clearTimeout(t) }, [q])
  const { data, isLoading, isFetching } = useCvDb(f)
  const archive = useArchiveCv()
  const set = (p: Partial<CvFilters>) => setF(x => ({ ...x, ...p, page: undefined }))
  const P = lang === 'ar' ? '٪' : '%'

  return <div className="max-w-[1200px] mx-auto space-y-5">
    <PageHead title={L('قاعدة السير الذاتية', 'CV database')} sub={L('المتقدمون الذين وافقوا على الاحتفاظ بسيرهم لفرص قادمة. كل بحث وتنزيل وتصدير يُسجَّل باسمك.', 'Applicants who agreed to keep their CV for future openings. Every search, download and export is logged under your name.')}
      actions={canExport && <a href={cvExportUrl(f)} className="inline-flex items-center gap-2 h-10 px-4 rounded-lg border border-line bg-surface font-semibold text-[14px] hover:bg-sub"><Download size={16} />{L('تصدير Excel', 'Export to Excel')}</a>} />

    <Panel>
      <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(180px,1fr))]">
        <label className="relative col-span-full sm:col-span-2"><Search size={16} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" />
          <input className="field !ps-9" value={q} onChange={e => setQ(e.target.value)} placeholder={L('اختصاص، مهارة، جهة عمل، اسم…', 'Major, skill, employer, name…')} aria-label={L('بحث', 'Search')} /></label>
        <select className="field" value={f.degree ?? ''} onChange={e => set({ degree: e.target.value || undefined })} aria-label={L('المؤهل', 'Degree')}>
          <option value="">{L('كل المؤهلات', 'Any degree')}</option>{data?.degrees.map((d, i) => <option key={i} value={i}>{L('على الأقل', 'At least')} {bi(d, lang)}</option>)}</select>
        <select className="field" value={f.years ?? ''} onChange={e => set({ years: e.target.value || undefined })} aria-label={L('الخبرة', 'Experience')}>
          <option value="">{L('أي خبرة', 'Any experience')}</option>{[1, 2, 3, 5, 8, 10].map(y => <option key={y} value={y}>{L(`${y}+ سنوات`, `${y}+ years`)}</option>)}</select>
        <select className="field" value={f.city ?? ''} onChange={e => set({ city: e.target.value || undefined })} aria-label={L('المدينة', 'City')}>
          <option value="">{L('كل المدن', 'All cities')}</option>{data?.cities.map(c => <option key={c.code} value={c.code}>{bi(c, lang)}</option>)}</select>
        <div><Seg value={f.status ?? 'active'} onChange={v => set({ status: v })} options={[{ v: 'active', label: L('نشطة', 'Active') }, { v: 'archived', label: L('مؤرشفة', 'Archived') }]} /></div>
      </div>
    </Panel>

    <Panel title={<span className="flex items-center gap-2">{L('النتائج', 'Results')} {data && <span className="text-[13px] text-ink-3 font-normal num">{data.meta.total}</span>}{isFetching && <Loader2 size={14} className="animate-spin text-brand" />}</span>}
      sub={<span className="flex items-center gap-1.5"><ShieldCheck size={13} />{L('المشاركة الخارجية ممنوعة إلا بموافقة صريحة من المتقدم.', 'External sharing is not allowed without the applicant\'s explicit consent.')}</span>} pad={false}>
      {isLoading || !data ? <div className="grid place-items-center py-16"><Loader2 className="animate-spin text-brand" /></div>
        : data.data.length === 0 ? <Empty title={L('لا نتائج', 'No results')}>{L('غيّر كلمات البحث أو الفلاتر.', 'Change the search words or filters.')}</Empty>
        : <ul className="divide-y divide-line-2">{data.data.map(r => <li key={r.id} className="px-4 py-3 flex flex-wrap items-center gap-x-4 gap-y-2">
          <button type="button" onClick={() => navigate(`/hr/candidates/${r.id}`)} className="flex items-center gap-3 min-w-0 flex-1 basis-[260px] text-start">
            <Avatar size={34} /><span className="min-w-0"><span className="block font-semibold truncate">{bi(r.name, lang)}</span>
              <span className="block text-[12.5px] text-ink-2 truncate">{[r.phase, r.major].filter(Boolean).join(' — ')}{r.institute && <span className="text-ink-3"> · {r.institute}</span>}</span></span></button>
          <div className="text-[12.5px] text-ink-2 basis-[200px] flex-1 min-w-0">
            <div className="truncate">{L(`${r.years_exp} سنوات خبرة`, `${r.years_exp} yrs experience`)}{r.last && <span className="text-ink-3"> · {r.last.position} — {r.last.company}</span>}</div>
            <div className="text-ink-3 truncate">{bi(r.job.title, lang)} · {bi(r.job.city, lang)} · <span className="num">{fmtDate(r.applied_at, lang)}</span></div></div>
          <div className="flex items-center gap-2">
            <Badge tone={r.stage === 'rejected' ? 'neutral' : 'info'}>{STAGES[r.stage][lang]}</Badge>
            <span className={`text-[13px] font-bold num ${r.match >= 75 ? 'text-brand' : r.match >= 55 ? 'text-brass' : 'text-ink-3'}`}>{r.match}{P}</span>
            {r.cv && <a href={fileUrl(r.cv.id)} className="inline-flex items-center gap-1 h-8 px-2.5 rounded-lg border border-line text-[12.5px] hover:bg-sub" title={r.cv.name}><FileText size={14} />{L('السيرة', 'CV')}</a>}
            {canEdit && <Btn size="sm" kind="ghost" disabled={archive.isPending} icon={r.cv_status === 'active' ? <Archive size={14} /> : <ArchiveRestore size={14} />}
              onClick={() => archive.mutate({ id: r.id, restore: r.cv_status === 'archived' }, { onSuccess: () => toast(r.cv_status === 'active' ? L('أُرشفت السيرة', 'CV archived') : L('أُعيد تنشيط السيرة', 'CV restored')), onError: x => toast((x as ApiError).message) })}>
              <span className="hidden sm:inline">{r.cv_status === 'active' ? L('أرشفة', 'Archive') : L('تنشيط', 'Restore')}</span></Btn>}
          </div>
        </li>)}</ul>}
      {data && data.meta.pages > 1 && <div className="flex items-center justify-center gap-2 py-3 border-t border-line-2">
        <Btn size="sm" disabled={data.meta.page <= 1} onClick={() => setF(x => ({ ...x, page: String(data.meta.page - 1) }))}>{L('السابق', 'Previous')}</Btn>
        <span className="text-[13px] num">{data.meta.page} / {data.meta.pages}</span>
        <Btn size="sm" disabled={data.meta.page >= data.meta.pages} onClick={() => setF(x => ({ ...x, page: String(data.meta.page + 1) }))}>{L('التالي', 'Next')}</Btn></div>}
    </Panel>
  </div>
}
