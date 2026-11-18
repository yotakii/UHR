import { api } from '../../lib/api'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Copy, Loader2, Plus, Search, Settings2, Star, UserPlus, Link2 } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../lib/store'
import { navigate, setQuery, useRoute } from '../../lib/router'
import { fileUrl } from '../../lib/console'
import { KIND, LEVEL, STATUS, useExpertLink, useExternal, useExternals, useExtAct, useOpportunities, useSaveExternal, type ExtFull, type ExtRow, type Lookups } from '../../lib/externals'
import { Badge, Btn, Drawer, Empty, Field, KV, Modal, PageHead, Panel, Tabs, toast } from '../../ui/kit'
import { DecisionDialog } from '../../ui/decision'
import { EngagementCard, EngagementForm, FilePick, errOf } from './parts'
import { AssignmentsTab, PaymentsTab, ReportsTab } from './Roster'

/**
 * «غير الموظفين» (الدفعة 22 — الجزء E): المتطوعون والعقود القصيرة والاستشاريون في سجل منفصل عن الموظفين لا يدخل التعداد ولا الرواتب،
 * وقائمة الخبراء وتكليفاتهم بالشروط المرجعية والمخرجات، وطلبات الدفع للمالية، وفرص التطوع العامة، والتقارير.
 */
export function ExternalsApp() {
  const L = useL()
  const route = useRoute(); const q = route.query
  const tab = (['experts', 'assignments', 'payments', 'opportunities', 'reports'] as const).find(t => t === q.tab) ?? 'people'
  const list = useExternals({ tab: tab === 'experts' ? 'experts' : 'people' })
  const c = list.data?.counts
  return <div className="max-w-[1200px] mx-auto">
    <PageHead title={L('غير الموظفين والخبراء', 'Non-employees & experts')} sub={L('متطوعون وعقود قصيرة واستشاريون وخبراء من القائمة — سجل منفصل لا يدخل تعداد الموظفين ولا مسيّر الرواتب.', 'Volunteers, short contracts, consultants and roster experts — a separate register outside headcount and payroll.')}
      actions={list.data?.can.settings && <Btn icon={<Settings2 size={15} />} onClick={() => navigate('/hr/settings/externals')}>{L('الإعدادات', 'Settings')}</Btn>} />
    <Tabs value={tab} onChange={v => setQuery({ tab: v === 'people' ? undefined : v, id: undefined, a: undefined })} tabs={[
      { v: 'people', label: L('المتطوعون والعقود القصيرة', 'Volunteers & short contracts'), count: c?.volunteers }, { v: 'experts', label: L('قائمة الخبراء', 'Expert roster'), count: c?.experts },
      { v: 'assignments', label: L('التكليفات', 'Assignments'), count: c?.assignments }, { v: 'payments', label: L('طلبات الدفع', 'Payment requests'), count: c?.payments },
      { v: 'opportunities', label: L('فرص التطوع', 'Opportunities') }, { v: 'reports', label: L('التقارير', 'Reports') }]} />
    <div className="mt-4">{tab === 'people' || tab === 'experts' ? <People experts={tab === 'experts'} /> : tab === 'assignments' ? <AssignmentsTab /> : tab === 'payments' ? <PaymentsTab /> : tab === 'opportunities' ? <Opportunities lk={list.data?.lookups} /> : <ReportsTab />}</div>
    {q.id && list.data && <PersonDrawer id={Number(q.id)} lk={list.data.lookups} onClose={() => setQuery({ id: undefined })} />}
  </div>
}

function People({ experts }: { experts: boolean }) {
  const L = useL(); const lang = useLang()
  const route = useRoute(); const q = route.query
  const f = experts ? { tab: 'experts', specialty: q.specialty, years: q.years, level: q.level, country: q.country, city: q.city, available: q.available, rating: q.rating, q: q.q, status: q.status }
    : { tab: 'people', kind: q.kind, status: q.status, q: q.q }
  const { data } = useExternals(f)
  const [add, setAdd] = useState(false)
  const link = useExpertLink()
  const set = (o: Record<string, string | undefined>) => setQuery(o)
  if (!data) return <div className="grid place-items-center py-16"><Loader2 className="animate-spin text-brand" /></div>
  const spec = new Map(data.lookups.specialties.map(s => [s.id, s]))
  return <>
    <div className="flex flex-wrap gap-2 mb-3">
      <div className="relative w-full sm:w-56"><Search size={15} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" /><input className="field ps-9" placeholder={L('اسم، رقم، بريد، كلمة مفتاحية…', 'Name, ref, email, keyword…')} defaultValue={q.q ?? ''} onKeyDown={e => { if (e.key === 'Enter') set({ q: (e.target as HTMLInputElement).value || undefined }) }} /></div>
      {experts ? <>
        <select className="field !w-full sm:!w-52" value={q.specialty ?? ''} onChange={e => set({ specialty: e.target.value || undefined })} aria-label={L('التخصص', 'Specialty')}><option value="">{L('كل التخصصات', 'All specialties')}</option>
          {data.lookups.specialties.map(s => <option key={s.id} value={s.id}>{s.parent_id ? '— ' : ''}{bi(s, lang)}</option>)}</select>
        <select className="field !w-[calc(50%-4px)] sm:!w-32" value={q.years ?? ''} onChange={e => set({ years: e.target.value || undefined })} aria-label={L('الخبرة', 'Experience')}><option value="">{L('الخبرة', 'Experience')}</option>{[3, 5, 10, 15].map(n => <option key={n} value={n}>{L(`${n}+ سنوات`, `${n}+ years`)}</option>)}</select>
        <select className="field !w-[calc(50%-4px)] sm:!w-32" value={q.level ?? ''} onChange={e => set({ level: e.target.value || undefined })} aria-label={L('المستوى', 'Level')}><option value="">{L('المستوى', 'Level')}</option>{Object.entries(LEVEL).map(([k, v]) => <option key={k} value={k}>{L(v[0], v[1])}</option>)}</select>
        <input className="field !w-[calc(50%-4px)] sm:!w-32" placeholder={L('المدينة', 'City')} defaultValue={q.city ?? ''} onKeyDown={e => { if (e.key === 'Enter') set({ city: (e.target as HTMLInputElement).value || undefined }) }} />
        <select className="field !w-[calc(50%-4px)] sm:!w-36" value={q.rating ?? ''} onChange={e => set({ rating: e.target.value || undefined })} aria-label={L('التقييم', 'Rating')}><option value="">{L('أي تقييم', 'Any rating')}</option>{[3, 4, 4.5].map(n => <option key={n} value={n}>{n}+ ★</option>)}</select>
        <label className="flex items-center gap-2 text-[13px]"><input type="checkbox" checked={q.available === '1'} onChange={e => set({ available: e.target.checked ? '1' : undefined })} />{L('متاح الآن', 'Available now')}</label>
        <select className="field !w-full sm:!w-40" value={q.status ?? ''} onChange={e => set({ status: e.target.value || undefined })} aria-label={L('الحالة', 'Status')}><option value="">{L('المعتمدون', 'Approved')}</option>
          {['applicant', 'vetting', 'approved', 'assigned', 'inactive', 'rejected', 'excluded'].map(s => <option key={s} value={s}>{L(STATUS[s][0], STATUS[s][1])}</option>)}</select>
      </> : <>
        <select className="field !w-[calc(50%-4px)] sm:!w-40" value={q.kind ?? ''} onChange={e => set({ kind: e.target.value || undefined })} aria-label={L('النوع', 'Type')}><option value="">{L('كل الأنواع', 'All types')}</option>{(['volunteer', 'trial', 'intern'] as const).map(k => <option key={k} value={k}>{L(KIND[k][0], KIND[k][1])}</option>)}</select>
        <select className="field !w-[calc(50%-4px)] sm:!w-40" value={q.status ?? ''} onChange={e => set({ status: e.target.value || undefined })} aria-label={L('الحالة', 'Status')}><option value="">{L('كل الحالات', 'All statuses')}</option>
          {['applicant', 'accepted', 'waitlist', 'pending_sign', 'active', 'ended', 'suspended', 'excluded'].map(s => <option key={s} value={s}>{L(STATUS[s][0], STATUS[s][1])}</option>)}</select></>}
      <div className="flex-1" />
      {experts && link.data && <Btn kind="ghost" icon={<Link2 size={15} />} onClick={() => navigator.clipboard?.writeText(link.data!.url).then(() => toast(L('نُسخ رابط التسجيل العام للخبراء', 'Public expert registration link copied')))}>{L('رابط التسجيل', 'Sign-up link')}</Btn>}
      {data.can.create && <Btn kind="primary" icon={<UserPlus size={15} />} onClick={() => setAdd(true)}>{experts ? L('إضافة خبير', 'Add expert') : L('إضافة شخص', 'Add person')}</Btn>}
    </div>
    {data.data.length === 0 ? <Panel><Empty title={L('لا توجد نتائج', 'No results')}>{experts ? L('غيّر معايير البحث، أو أضف خبراء، أو انشر رابط التسجيل العام.', 'Change the criteria, add experts, or share the public sign-up link.') : L('أضف متطوعاً أو انشر فرصة تطوع برابط عام.', 'Add a volunteer or publish an opportunity with a public link.')}</Empty></Panel>
      : experts ? <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(280px,1fr))]">{data.data.map(x => <button key={x.id} onClick={() => setQuery({ id: String(x.id) })} className="text-start bg-surface border border-line rounded-2xl p-4 hover:border-brand/50">
          <div className="flex items-start justify-between gap-2"><div><div className="font-semibold">{bi(x, lang)}</div><div className="text-[12px] text-ink-3">{x.level ? L(LEVEL[x.level][0], LEVEL[x.level][1]) : ''}{x.years != null ? ` · ${x.years} ${L('سنة', 'yrs')}` : ''}{x.city ? ` · ${x.city}` : ''}</div></div>
            <Badge tone={STATUS[x.status]?.[2] ?? 'neutral'}>{STATUS[x.status] ? L(STATUS[x.status][0], STATUS[x.status][1]) : x.status}</Badge></div>
          <div className="mt-2 flex flex-wrap gap-1">{x.specialties.slice(0, 4).map(s => spec.get(s) && <span key={s} className="text-[11.5px] rounded-md bg-sub px-1.5 py-0.5">{bi(spec.get(s)!, lang)}</span>)}</div>
          <div className="mt-2 flex items-center justify-between text-[12.5px]"><span>{x.avg_rating != null ? <><Star size={12} className="inline text-brass" /> <span className="num">{x.avg_rating}</span> <span className="text-ink-3">({x.ratings})</span></> : <span className="text-ink-3">{L('دون تقييم', 'Not rated')}</span>}</span>
            <span className="text-ink-3">{x.available === 'now' ? L('متاح', 'Available') : x.available === 'from' ? `${L('من', 'From')} ${x.available_from ? fmtDate(x.available_from, lang) : ''}` : L('غير متاح', 'Unavailable')}</span></div>
        </button>)}</div>
      : <Panel pad={false}><table className="tbl"><thead><tr><th>{L('الاسم', 'Name')}</th><th className="hidden md:table-cell">{L('الفئة', 'Category')}</th><th className="hidden sm:table-cell">{L('المشرف', 'Supervisor')}</th><th className="hidden sm:table-cell">{L('الساعات', 'Hours')}</th><th>{L('الحالة', 'Status')}</th></tr></thead>
        <tbody>{data.data.map(x => <tr key={x.id} className="click" onClick={() => setQuery({ id: String(x.id) })}>
          <td><div className="font-medium">{bi(x, lang)}</div><div className="text-[12px] text-ink-3"><span className="ltr inline-block">{x.ref}</span>{x.source === 'public' ? ` · ${L('تسجيل عام', 'Public sign-up')}` : ''}</div></td>
          <td className="hidden md:table-cell text-[13px]">{x.category ? bi(x.category, lang) : L(KIND[x.kind][0], KIND[x.kind][1])}</td>
          <td className="hidden sm:table-cell text-[13px]">{x.supervisor ? bi(x.supervisor, lang) : '—'}</td><td className="hidden sm:table-cell num">{x.hours ?? 0}</td>
          <td><Badge tone={STATUS[x.status]?.[2] ?? 'neutral'}>{STATUS[x.status] ? L(STATUS[x.status][0], STATUS[x.status][1]) : x.status}</Badge></td></tr>)}</tbody></table></Panel>}
    {add && <PersonForm lk={data.lookups} experts={experts} onClose={() => setAdd(false)} />}
  </>
}

export function PersonForm({ lk, experts, x, onClose }: { lk: Lookups; experts: boolean; x?: ExtFull; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const save = useSaveExternal()
  const cats = lk.categories.filter(c => experts ? c.attrs?.portal === 'expert' : c.attrs?.portal !== 'expert')
  const [v, setV] = useState<Record<string, unknown>>(x ? { ...x } : { worker_category_id: cats[0]?.id ?? 0, entity: 'person', name: '', name_en: '', email: '', phone: '', city: '', country: 'SY', specialties: [], years: null, level: experts ? 'senior' : null, available: 'now', languages: ['ar'], keywords: [], documents: [] })
  const e = errOf(save.error)
  const set = (k: string, val: unknown) => setV(o => ({ ...o, [k]: val }))
  const str = (k: string) => (v[k] as string | null) ?? ''
  const list = (k: string) => ((v[k] as string[] | null) ?? []).join('، ')
  const toList = (s: string) => s.split(/[,،]\s*/).map(t => t.trim()).filter(Boolean)
  return <Modal open onClose={onClose} width={720} title={x ? L('تعديل البيانات', 'Edit details') : experts ? L('إضافة خبير للقائمة', 'Add an expert') : L('إضافة شخص', 'Add a person')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending || !str('name')} onClick={() => save.mutate({ id: x?.id, v }, { onSuccess: r => { toast(L('حُفظ', 'Saved')); onClose(); if (!x && r.id) setQuery({ id: String(r.id) }) } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('الفئة', 'Category')} req error={e?.field('worker_category_id')}><select className="field" value={Number(v.worker_category_id)} onChange={x => set('worker_category_id', Number(x.target.value))}>{cats.map(c => <option key={c.id} value={c.id}>{c.code} · {bi(c, lang)}</option>)}</select></Field>
      {experts && <Field label={L('الصفة', 'Entity')}><select className="field" value={str('entity')} onChange={x => set('entity', x.target.value)}><option value="person">{L('فرد', 'Individual')}</option><option value="firm">{L('شركة استشارية', 'Consulting firm')}</option></select></Field>}
      <Field label={L('الاسم', 'Name')} req error={e?.field('name')}><input className="field" value={str('name')} onChange={x => set('name', x.target.value)} /></Field>
      <Field label={L('الاسم بالإنكليزية', 'Name (EN)')}><input className="field ltr" value={str('name_en')} onChange={x => set('name_en', x.target.value)} /></Field>
      <Field label={L('البريد', 'Email')} error={e?.field('email')} hint={L('مطلوب لحساب البوابة', 'Needed for the portal account')}><input className="field ltr" value={str('email')} onChange={x => set('email', x.target.value)} /></Field>
      <Field label={L('الهاتف', 'Phone')}><input className="field ltr" value={str('phone')} onChange={x => set('phone', x.target.value)} /></Field>
      <Field label={L('الدولة', 'Country')}><select className="field" value={str('country')} onChange={x => set('country', x.target.value)}><option value="">—</option>{lk.countries.map(c => <option key={c.code} value={c.code}>{bi(c, lang)}</option>)}</select></Field>
      <Field label={L('المدينة', 'City')}><input className="field" value={str('city')} onChange={x => set('city', x.target.value)} /></Field>
      {experts ? <>
        <Field label={L('التخصصات', 'Specialties')} className="sm:col-span-2" error={e?.field('specialties')}><select multiple className="field !h-28" value={((v.specialties as number[]) ?? []).map(String)} onChange={x => set('specialties', [...x.target.selectedOptions].map(o => Number(o.value)))}>
          {lk.specialties.map(s => <option key={s.id} value={s.id}>{s.parent_id ? '— ' : ''}{bi(s, lang)}</option>)}</select></Field>
        <Field label={L('سنوات الخبرة', 'Years of experience')}><input className="field num" inputMode="numeric" value={(v.years as number | null) ?? ''} onChange={x => set('years', x.target.value === '' ? null : Number(x.target.value))} /></Field>
        <Field label={L('المستوى', 'Level')}><select className="field" value={str('level')} onChange={x => set('level', x.target.value)}>{Object.entries(LEVEL).map(([k, l]) => <option key={k} value={k}>{L(l[0], l[1])}</option>)}</select></Field>
        <Field label={L('اللغات', 'Languages')} hint="ar، en"><input className="field" value={list('languages')} onChange={x => set('languages', toList(x.target.value))} /></Field>
        <Field label={L('كلمات مفتاحية', 'Keywords')}><input className="field" value={list('keywords')} onChange={x => set('keywords', toList(x.target.value))} /></Field>
        <Field label={L('أبرز المشاريع والجهات', 'Key projects & clients')} className="sm:col-span-2"><textarea className="field" rows={2} value={str('projects')} onChange={x => set('projects', x.target.value)} /></Field>
        {lk && <Field label={L('الأجر الاسترشادي (سري)', 'Indicative rate (confidential)')} className="sm:col-span-2"><div className="flex gap-2">
          <input className="field num !w-32" value={(v.rate as number | null) ?? ''} onChange={x => set('rate', x.target.value === '' ? null : Number(x.target.value))} />
          <select className="field !w-28" value={str('rate_unit') || 'day'} onChange={x => set('rate_unit', x.target.value)}><option value="day">{L('يومي', 'per day')}</option><option value="hour">{L('ساعي', 'per hour')}</option></select>
          <select className="field !w-24 ltr" value={str('rate_currency') || 'USD'} onChange={x => set('rate_currency', x.target.value)}>{lk.currencies.map(c => <option key={c.code}>{c.code}</option>)}</select></div></Field>}
        <div className="sm:col-span-2 flex flex-wrap items-center gap-2"><FilePick label={L('السيرة الذاتية', 'CV')} onDone={f => set('cv_file_id', f.id)} />{v.cv_file_id ? <a className="text-brand text-[13px]" href={fileUrl(Number(v.cv_file_id))} target="_blank" rel="noreferrer">{L('عرض', 'View')}</a> : null}
          <FilePick label={L('شهادة', 'Certificate')} onDone={f => set('documents', [...((v.documents as unknown[]) ?? []), { type: 'certificate', file_id: f.id, note: f.name }])} />
          <span className="text-[12px] text-ink-3">{L(`${((v.documents as unknown[]) ?? []).length} ملف`, `${((v.documents as unknown[]) ?? []).length} file(s)`)}</span></div>
      </> : <>
        <Field label={L('رقم الهوية', 'National ID')}><input className="field ltr" value={str('national_id')} onChange={x => set('national_id', x.target.value)} /></Field>
        <Field label={L('المشرف', 'Supervisor')}><select className="field" value={(v.supervisor_id as number | null) ?? ''} onChange={x => set('supervisor_id', x.target.value ? Number(x.target.value) : null)}><option value="">—</option>{lk.employees.map(p => <option key={p.id} value={p.id}>{bi(p, lang)}</option>)}</select></Field>
        <Field label={L('مجالات التطوع', 'Volunteering areas')} className="sm:col-span-2"><input className="field" value={list('areas')} onChange={x => set('areas', toList(x.target.value))} /></Field>
        <Field label={L('جهة الطوارئ', 'Emergency contact')} className="sm:col-span-2"><div className="grid grid-cols-3 gap-2">
          {(['name', 'phone', 'relation'] as const).map(k => <input key={k} className="field" placeholder={L(({ name: 'الاسم', phone: 'الهاتف', relation: 'الصلة' })[k], k)} value={((v.emergency as Record<string, string> | null) ?? {})[k] ?? ''} onChange={x => set('emergency', { ...((v.emergency as Record<string, string> | null) ?? {}), [k]: x.target.value })} />)}</div></Field>
      </>}
    </div>
    {e && !Object.keys(e.errors).length && <p className="mt-2 text-[13px] text-danger">{e.message}</p>}
  </Modal>
}

function PersonDrawer({ id, lk, onClose }: { id: number; lk: Lookups; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const { data: x } = useExternal(id)
  const act = useExtAct()
  const [edit, setEdit] = useState(false); const [eng, setEng] = useState<number | 'new' | null>(null); const [st, setSt] = useState<string | null>(null); const [conv, setConv] = useState(false)
  if (!x) return <Drawer open onClose={onClose}><div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div></Drawer>
  const expert = x.kind === 'expert' || x.kind === 'consultant'
  const spec = new Map(lk.specialties.map(s => [s.id, s]))
  const moves = expert ? ({ applicant: ['vetting', 'rejected'], vetting: ['approved', 'rejected'], approved: ['inactive', 'excluded'], assigned: ['excluded'], inactive: ['approved'], rejected: ['vetting'], excluded: ['vetting'] } as Record<string, string[]>)[x.status] ?? []
    : ({ applicant: ['accepted', 'waitlist', 'excluded'], accepted: ['waitlist', 'excluded'], waitlist: ['accepted', 'excluded'], active: ['suspended'], suspended: ['active'], ended: ['accepted'], excluded: ['accepted'] } as Record<string, string[]>)[x.status]?.filter(s => s !== 'active') ?? []
  const open = x.engagements.find(g => !['ended', 'terminated', 'cancelled', 'rejected'].includes(g.status))
  return <Drawer open onClose={onClose} width={760}>
    <div className="p-5 space-y-4">
      <div className="flex items-start justify-between gap-3"><div><h2 className="text-[20px] font-bold">{bi(x, lang)}</h2>
        <div className="text-[13px] text-ink-3"><span className="ltr inline-block">{x.ref}</span> · {x.category ? bi(x.category, lang) : L(KIND[x.kind][0], KIND[x.kind][1])}{x.email ? <> · <span className="ltr inline-block">{x.email}</span></> : ''}</div></div>
        <Badge tone={STATUS[x.status]?.[2] ?? 'neutral'}>{STATUS[x.status] ? L(STATUS[x.status][0], STATUS[x.status][1]) : x.status}</Badge></div>
      {x.status_note && <p className="text-[13px] text-ink-3">{x.status_note}</p>}
      <div className="flex flex-wrap gap-1.5">
        <Btn size="sm" kind="ghost" onClick={() => setEdit(true)}>{L('تعديل البيانات', 'Edit details')}</Btn>
        {moves.map(m => <Btn key={m} size="sm" kind={['rejected', 'excluded', 'suspended'].includes(m) ? 'danger' : m === 'approved' || m === 'accepted' ? 'primary' : 'secondary'} onClick={() => setSt(m)}>{L(STATUS[m][0], STATUS[m][1])}</Btn>)}
        {!expert && !open && ['accepted', 'ended'].includes(x.status) && <Btn size="sm" kind="primary" icon={<Plus size={13} />} onClick={() => setEng('new')}>{L('تكليف جديد', 'New engagement')}</Btn>}
        {expert && !x.has_account && x.email && x.status === 'approved' && <Btn size="sm" kind="ghost" onClick={() => act.mutate({ path: `${x.id}/account` }, { onSuccess: () => toast(L('أُرسل رابط تفعيل البوابة', 'Portal activation link sent')) })}>{L('دعوة للبوابة', 'Invite to portal')}</Btn>}
        {!x.converted_candidate_id && x.email && <Btn size="sm" kind="ghost" onClick={() => setConv(true)}>{L('تحويل إلى متقدم لوظيفة', 'Convert to a job applicant')}</Btn>}
        {x.converted_candidate_id && <a className="text-brand text-[13px] font-semibold self-center" href={`/hr/candidates/${x.converted_candidate_id}`}>{L('ملف المتقدم', 'Applicant file')}</a>}
      </div>
      <Panel title={L('البيانات', 'Details')}>
        {x.phone && <KV k={L('الهاتف', 'Phone')} v={<span className="ltr">{x.phone}</span>} />}
        {(x.city || x.country) && <KV k={L('الإقامة', 'Residence')} v={[x.city, x.country].filter(Boolean).join(' · ')} />}
        {expert ? <>
          <KV k={L('التخصصات', 'Specialties')} v={x.specialties.map(s => spec.get(s) ? bi(spec.get(s)!, lang) : s).join('، ') || '—'} />
          <KV k={L('الخبرة والمستوى', 'Experience & level')} v={`${x.years ?? '—'} ${L('سنة', 'yrs')}${x.level ? ` · ${L(LEVEL[x.level][0], LEVEL[x.level][1])}` : ''}`} />
          {x.languages.length > 0 && <KV k={L('اللغات', 'Languages')} v={x.languages.join('، ')} />}
          <KV k={L('التقييم', 'Rating')} v={x.avg_rating != null ? `${x.avg_rating} / 5 (${x.ratings})` : '—'} />
          <KV k={L('الأجر الاسترشادي', 'Indicative rate')} v={x.fees_hidden ? <span className="text-ink-3">{L('محجوب', 'Hidden')}</span> : x.rate != null ? `${x.rate} ${x.rate_currency} / ${x.rate_unit === 'hour' ? L('ساعة', 'hour') : L('يوم', 'day')}` : '—'} />
          {x.review_due && <KV k={L('المراجعة الدورية', 'Periodic review')} v={fmtDate(x.review_due, lang)} />}
          {x.projects && <p className="text-[13px] mt-2 whitespace-pre-line">{x.projects}</p>}
        </> : <>
          {x.national_id && <KV k={L('الهوية', 'ID')} v={<span className="ltr">{x.national_id}</span>} />}
          {x.emergency?.name && <KV k={L('جهة الطوارئ', 'Emergency contact')} v={`${x.emergency.name}${x.emergency.phone ? ` · ${x.emergency.phone}` : ''}${x.emergency.relation ? ` (${x.emergency.relation})` : ''}`} />}
          {x.areas.length > 0 && <KV k={L('المجالات', 'Areas')} v={x.areas.join('، ')} />}
        </>}
        {(x.cv_file_id || x.documents.length > 0) && <div className="flex flex-wrap gap-2 mt-2 text-[13px]">{x.cv_file_id && <a className="text-brand" href={fileUrl(x.cv_file_id)} target="_blank" rel="noreferrer">{L('السيرة الذاتية', 'CV')}</a>}
          {x.documents.map((d, i) => <a key={i} className="text-brand" href={fileUrl(d.file_id)} target="_blank" rel="noreferrer">{d.note || d.type}{d.valid_to ? ` (${fmtDate(d.valid_to, lang)})` : ''}</a>)}</div>}
        {x.notes && !x.fees_hidden && <p className="text-[12.5px] text-ink-3 mt-2">{L('ملاحظات سرية', 'Confidential notes')}: {x.notes}</p>}
      </Panel>
      {!expert && <Panel title={L('التكليفات والاتفاقيات', 'Engagements & agreements')}>{x.engagements.length === 0 ? <p className="text-[13px] text-ink-3">{L('لا تكليفات بعد.', 'No engagements yet.')}</p> : <div className="space-y-3">{x.engagements.map(g => <EngagementCard key={g.id} x={x} g={g} lk={lk} onEdit={() => setEng(g.id)} />)}</div>}</Panel>}
      {expert && <Panel title={L('تاريخ التعاون', 'Engagement history')}>{x.assignments.length === 0 ? <p className="text-[13px] text-ink-3">{L('لم يُكلَّف بعد.', 'No assignments yet.')}</p> :
        <div className="space-y-2">{x.assignments.map(a => <button key={a.id} onClick={() => navigate(`/hr/externals?tab=assignments&a=${a.id}`)} className="w-full text-start rounded-lg border border-line p-2.5 hover:bg-sub">
          <div className="flex justify-between gap-2"><span className="font-medium">{a.title}</span><span className="ltr text-[12px] text-ink-3">{a.ref}</span></div>
          <div className="text-[12.5px] text-ink-3">{a.rating ? `★ ${a.rating.score}` : ''}{a.rating?.notes ? ` · ${a.rating.notes}` : ''}</div></button>)}</div>}</Panel>}
      {!expert && <ExtCard id={x.id} />}
      {x.payments.length > 0 && <Panel title={L('طلبات الدفع', 'Payment requests')}><div className="space-y-1">{x.payments.map(p => <div key={p.id} className="flex justify-between text-[13px]"><span>{p.description}</span><span className="num">{p.amount} {p.currency}</span></div>)}</div></Panel>}
    </div>
    {edit && <PersonForm lk={lk} experts={expert} x={x} onClose={() => setEdit(false)} />}
    {eng && <EngagementForm x={x} g={eng === 'new' ? undefined : x.engagements.find(g => g.id === eng)} lk={lk} onClose={() => setEng(null)} />}
    {st && <DecisionDialog open onClose={() => setSt(null)} tone={['rejected', 'excluded', 'suspended'].includes(st) ? 'danger' : 'primary'} reasonRequired={['rejected', 'excluded', 'suspended'].includes(st)}
      title={L(STATUS[st][0], STATUS[st][1])} confirmLabel={L('تأكيد', 'Confirm')} onConfirm={note => act.mutateAsync({ path: `${x.id}/status`, v: { status: st, note } }).then(() => setSt(null))} />}
    {conv && <ConvertModal id={x.id} lk={lk} onClose={() => setConv(false)} />}
  </Drawer>
}

function ConvertModal({ id, lk, onClose }: { id: number; lk: Lookups; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const act = useExtAct(); const [job, setJob] = useState(lk.jobs[0]?.id ?? 0)
  return <Modal open onClose={onClose} width={460} title={L('تحويل إلى متقدم لوظيفة', 'Convert to a job applicant')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={!job || act.isPending} onClick={() => act.mutate({ path: `${id}/convert`, v: { job_id: job } }, { onSuccess: r => { toast(L('أُنشئ ملف متقدم', 'Applicant file created')); onClose(); navigate(`/hr/candidates/${(r as { candidate_id: number }).candidate_id}`) } })}>{L('تحويل', 'Convert')}</Btn></>}>
    <p className="text-[13px] text-ink-2 mb-3">{L('تُنقل بياناته إلى ملف متقدم على الوظيفة المختارة، ويبقى سجله وتاريخه هنا.', 'The details move to an applicant file on the chosen job; this record and its history stay here.')}</p>
    {lk.jobs.length === 0 ? <p className="text-[13px] text-warn">{L('لا توجد وظائف مفتوحة.', 'No open jobs.')}</p> : <Field label={L('الوظيفة', 'Job')}><select className="field" value={job} onChange={e => setJob(Number(e.target.value))}>{lk.jobs.map(j => <option key={j.id} value={j.id}>{j.code} · {bi(j, lang)}</option>)}</select></Field>}
  </Modal>
}

function Opportunities({ lk }: { lk?: Lookups }) {
  const L = useL(); const lang = useLang()
  const { data } = useOpportunities()
  const act = useExtAct()
  const [edit, setEdit] = useState<Record<string, unknown> | null>(null)
  return <>
    <div className="flex justify-end mb-3"><Btn kind="primary" icon={<Plus size={15} />} onClick={() => setEdit({ title: '', status: 'open', slots: 0 })}>{L('فرصة جديدة', 'New opportunity')}</Btn></div>
    {!data ? <Loader2 className="animate-spin text-brand mx-auto" /> : data.data.length === 0 ? <Panel><Empty title={L('لا توجد فرص', 'No opportunities')}>{L('انشر فرصة تطوع برابط عام يسجّل فيه المتقدمون بياناتهم.', 'Publish a volunteering opportunity with a public sign-up link.')}</Empty></Panel> :
      <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(300px,1fr))]">{data.data.map(o => <Panel key={o.id} title={<span>{o.title}{o.kind === 'intern' && <span className="text-[11.5px] text-ink-3"> · {L('ستاج', 'Internship')}{o.stage_type === 'fee' && o.fee ? ` · ${o.fee} ${o.fee_currency ?? ''}` : ''}</span>}</span>} action={<Badge tone={o.status === 'open' ? 'brand' : 'neutral'}>{o.status === 'open' ? L('مفتوحة', 'Open') : o.status === 'closed' ? L('مغلقة', 'Closed') : L('مسودة', 'Draft')}</Badge>}>
        {o.description && <p className="text-[13px] text-ink-2 line-clamp-3">{o.description}</p>}
        <div className="text-[12.5px] text-ink-3 mt-1 num">{o.starts_on ? fmtDate(o.starts_on, lang) : ''}{o.ends_on ? ` — ${fmtDate(o.ends_on, lang)}` : ''}{o.weekly_hours ? ` · ${o.weekly_hours} ${L('س/أسبوع', 'h/wk')}` : ''} · {L(`${o.applicants} متقدم`, `${o.applicants} applicants`)}</div>
        <div className="flex gap-1 mt-2"><Btn size="sm" kind="ghost" icon={<Copy size={13} />} onClick={() => navigator.clipboard?.writeText(o.url).then(() => toast(L('نُسخ الرابط العام', 'Public link copied')))}>{L('نسخ الرابط', 'Copy link')}</Btn>
          <Btn size="sm" kind="ghost" onClick={() => setEdit({ ...o })}>{L('تعديل', 'Edit')}</Btn></div></Panel>)}</div>}
    {edit && <Modal open onClose={() => setEdit(null)} width={560} title={edit.id ? L('تعديل الفرصة', 'Edit opportunity') : L('فرصة تطوع جديدة', 'New opportunity')}
      footer={<><Btn onClick={() => setEdit(null)}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={act.isPending || !edit.title} onClick={() => act.mutate({ path: edit.id ? `opportunities/${edit.id}` : 'opportunities', method: edit.id ? 'PUT' : 'POST', v: edit }, { onSuccess: () => { toast(L('حُفظت', 'Saved')); setEdit(null) } })}>{L('حفظ', 'Save')}</Btn></>}>
      <div className="grid sm:grid-cols-2 gap-3">
        <Field label={L('العنوان', 'Title')} req className="sm:col-span-2"><input className="field" value={String(edit.title ?? '')} onChange={e => setEdit({ ...edit, title: e.target.value })} /></Field>
        <Field label={L('الوصف', 'Description')} className="sm:col-span-2"><textarea className="field" rows={3} value={String(edit.description ?? '')} onChange={e => setEdit({ ...edit, description: e.target.value })} /></Field>
        <Field label={L('من', 'From')}><input type="date" className="field" value={String(edit.starts_on ?? '')} onChange={e => setEdit({ ...edit, starts_on: e.target.value || null })} /></Field>
        <Field label={L('إلى', 'To')}><input type="date" className="field" value={String(edit.ends_on ?? '')} onChange={e => setEdit({ ...edit, ends_on: e.target.value || null })} /></Field>
        <Field label={L('الإدارة', 'Department')}><select className="field" value={String(edit.department_id ?? '')} onChange={e => setEdit({ ...edit, department_id: e.target.value ? Number(e.target.value) : null })}><option value="">—</option>{lk?.departments.map(d => <option key={d.id} value={d.id}>{bi(d, lang)}</option>)}</select></Field>
        <Field label={L('الساعات الأسبوعية', 'Weekly hours')}><input className="field num" value={String(edit.weekly_hours ?? '')} onChange={e => setEdit({ ...edit, weekly_hours: Number(e.target.value) || null })} /></Field>
        <Field label={L('نوع الفرصة', 'Opportunity type')}><select className="field" value={String(edit.kind ?? 'volunteer')} onChange={e => setEdit({ ...edit, kind: e.target.value, stage_type: e.target.value === 'intern' ? (edit.stage_type ?? 'free') : null })}>
          <option value="volunteer">{L('تطوع', 'Volunteering')}</option><option value="intern">{L('تدريب عملي (ستاج)', 'Internship')}</option></select></Field>
        {edit.kind === 'intern' && <Field label={L('نوع الستاج', 'Internship type')}><select className="field" value={String(edit.stage_type ?? 'free')} onChange={e => setEdit({ ...edit, stage_type: e.target.value })}>
          <option value="free">{L('غير مأجور', 'Unpaid')}</option><option value="paid">{L('مأجور', 'Paid')}</option><option value="fee">{L('برسوم على المتدرب', 'Trainee pays a fee')}</option></select></Field>}
        {edit.kind === 'intern' && edit.stage_type === 'fee' && <Field label={L('الرسوم', 'Fee')}><div className="flex gap-2"><input className="field num" inputMode="decimal" value={String(edit.fee ?? '')} onChange={e => setEdit({ ...edit, fee: Number(e.target.value) || null })} />
          <input className="field ltr !w-20" maxLength={3} value={String(edit.fee_currency ?? 'USD')} aria-label={L('العملة', 'Currency')} onChange={e => setEdit({ ...edit, fee_currency: e.target.value.toUpperCase() })} /></div></Field>}
        <Field label={L('الحالة', 'Status')}><select className="field" value={String(edit.status)} onChange={e => setEdit({ ...edit, status: e.target.value })}><option value="open">{L('مفتوحة', 'Open')}</option><option value="draft">{L('مسودة', 'Draft')}</option><option value="closed">{L('مغلقة', 'Closed')}</option></select></Field>
      </div>
    </Modal>}
  </>
}

export type { ExtRow }

/** FR-VL-007/015: بطاقة NFC لغير الموظف — النقرتان في اليوم تسجّلان ساعات بانتظار المشرف، وتُعطَّل آلياً بانتهاء الاتفاقية */
function ExtCard({ id }: { id: number }) {
  const L = useL()
  const q = useQuery({ queryKey: ['externals', 'card', id], queryFn: () => api<{ data: { id: number; identifier: string; status: string; valid_from: string | null }[] }>(`/hr/externals/${id}/card`).then(r => r.data) })
  const [uid, setUid] = useState('')
  const add = useMutation({ mutationFn: () => api<{ data: unknown }>(`/hr/externals/${id}/card`, { json: { identifier: uid } }), onSuccess: () => { setUid(''); q.refetch(); toast(L('رُبطت البطاقة', 'Card linked')) }, onError: e => toast((e as Error).message) })
  const live = (q.data ?? []).find(c => c.status === 'active')
  return <Panel title={L('بطاقة الحضور', 'Attendance card')} sub={L('النقرة الأولى في اليوم بداية والتالية نهاية — تُسجَّل ساعات بانتظار مصادقة المشرف.', 'First tap of the day starts, the next ends — hours await the supervisor.')}>
    {live ? <div className="text-[13px]"><span className="ltr font-mono">{live.identifier}</span> · <Badge tone="brand">{L('فعّالة', 'Active')}</Badge></div> : <div className="text-[13px] text-ink-3">{L('لا بطاقة مربوطة.', 'No card linked.')}</div>}
    <div className="flex gap-2 mt-2"><input className="field ltr" placeholder="04:A1:B2:C3" value={uid} onChange={e => setUid(e.target.value)} /><Btn disabled={uid.trim().length < 4 || add.isPending} onClick={() => add.mutate()}>{live ? L('استبدال', 'Replace') : L('ربط', 'Link')}</Btn></div>
  </Panel>
}
