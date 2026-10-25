import { useState } from 'react'
import { Megaphone, Plus, Pencil, Paperclip, Check, Undo2, Archive, Settings2 } from 'lucide-react'
import { useL, useLang, bi, fmtDate, fmtDateTime } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { fileUrl } from '../../lib/console'
import { setQuery, useRoute } from '../../lib/router'
import { useAnnouncementsHr, useAnnSettings, useArchiveAnnouncement, useDecideAnnouncement, useMyAnnouncements, useSaveAnnouncement, type AnnInput, type AnnTag, type Announcement, type AnnouncementsHr } from '../../lib/rewards'
import { Badge, Btn, Empty, Field, Modal, PageHead, Panel, Seg, Tabs, toast } from '../../ui/kit'
import { DecisionDialog } from '../../ui/decision'

const tagName = (t: AnnTag, L: (a: string, e: string) => string) => ({ people: L('موظفون', 'People'), payroll: L('رواتب', 'Payroll'), training: L('تدريب', 'Training'), policy: L('سياسات', 'Policy'), event: L('فعالية', 'Event') })[t]

function StatusBadge({ a }: { a: Announcement }) {
  const L = useL()
  if (a.status === 'pending') return <Badge tone="warn">{L('بانتظار الاعتماد', 'Awaiting approval')}</Badge>
  if (a.status === 'draft') return <Badge>{L('مسودة', 'Draft')}</Badge>
  if (a.status === 'expired') return <Badge>{L('منتهٍ', 'Expired')}</Badge>
  if (a.published_at && new Date(a.published_at) > new Date()) return <Badge tone="info">{L('مجدول', 'Scheduled')}</Badge>
  return <Badge tone="brand">{L('منشور', 'Published')}</Badge>
}

/** بطاقة إعلان كما يراها الموظف */
export function AnnouncementCard({ a }: { a: Announcement }) {
  const L = useL(); const lang = useLang()
  return <article className="rounded-2xl border border-line bg-surface overflow-hidden">
    {a.image && <img src={fileUrl(a.image)} alt="" className="w-full max-h-[260px] object-cover" />}
    <div className="p-4">
      <div className="flex flex-wrap items-center gap-2 text-[12px] text-ink-3"><Badge tone="info">{tagName(a.tag, L)}</Badge>{a.department && <Badge>{bi(a.department, lang)}</Badge>}<span className="num">{fmtDate(a.published_at ?? undefined, lang)}</span></div>
      <h3 className="mt-2 font-display font-bold text-[16px]">{bi(a.title, lang)}</h3>
      <p className="mt-1 text-[14px] text-ink-2 whitespace-pre-line">{bi(a.text, lang)}</p>
      {a.attachment && <a href={fileUrl(a.attachment.id)} target="_blank" rel="noreferrer" className="mt-2 inline-flex items-center gap-1.5 text-brand text-[13px] font-semibold"><Paperclip size={14} />{a.attachment.name}</a>}
    </div>
  </article>
}

/** إعلانات الشركة للموظف (الرئيسية تعرض أحدثها، والصفحة كلها) */
export function MyAnnouncements({ limit, panel }: { limit?: number; panel?: boolean }) {
  const L = useL()
  const q = useMyAnnouncements(); const rows = (q.data ?? []).slice(0, limit ?? 100)
  const body = rows.length === 0 ? <Empty title={L('لا إعلانات حالياً', 'No announcements now')} /> : <div className="grid [grid-template-columns:repeat(auto-fill,minmax(280px,1fr))] gap-4">{rows.map(a => <AnnouncementCard key={a.id} a={a} />)}</div>
  if (panel) return q.data && q.data.length > 0 ? <Panel title={<span className="flex items-center gap-2"><Megaphone size={16} className="text-brand" />{L('إعلانات الشركة', 'Company news')}</span>}
    action={q.data.length > (limit ?? 0) ? <a className="text-brand text-[13px] font-semibold" href="/me/news">{L('الكل', 'All')}</a> : undefined}>{body}</Panel> : null
  return <div className="max-w-[1200px] mx-auto"><PageHead title={L('إعلانات الشركة', 'Company news')} />{body}</div>
}

type Tab = 'live' | 'pending' | 'drafts' | 'expired'

/** إدارة الإعلانات (D-06): كتابة، صورة أو مرفق، للكل أو لإدارة، جدولة وانتهاء، واعتماد اختياري قبل النشر */
export function AnnouncementsHR() {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const q = useAnnouncementsHr(); const d = q.data
  const decide = useDecideAnnouncement(); const archive = useArchiveAnnouncement()
  const [edit, setEdit] = useState<Announcement | 'new' | null>(null)
  const [back, setBack] = useState<Announcement | null>(null)
  const [stop, setStop] = useState<Announcement | null>(null)
  const [cfg, setCfg] = useState(false)
  if (!d) return <div className="max-w-[1200px] mx-auto"><PageHead title={L('الإعلانات', 'Announcements')} /></div>
  const tab = (['live', 'pending', 'drafts', 'expired'].includes(route.query.tab ?? '') ? route.query.tab : 'live') as Tab
  const by: Record<Tab, Announcement[]> = { live: d.data.filter(a => a.status === 'published'), pending: d.data.filter(a => a.status === 'pending'), drafts: d.data.filter(a => a.status === 'draft'), expired: d.data.filter(a => a.status === 'expired') }
  const rows = by[tab]
  return <div className="max-w-[1200px] mx-auto">
    <PageHead title={L('الإعلانات', 'Announcements')} sub={d.config.require_approval ? L('الإعلانات تحتاج اعتماداً قبل النشر.', 'Announcements need approval before publishing.') : L('تُنشر الإعلانات مباشرة أو في موعدها المجدول.', 'Announcements publish right away or at their scheduled time.')}
      actions={<>{d.can.settings && <Btn icon={<Settings2 size={16} />} onClick={() => setCfg(true)}>{L('الإعدادات', 'Settings')}</Btn>}{d.can.create && <Btn kind="primary" icon={<Plus size={16} />} onClick={() => setEdit('new')}>{L('إعلان جديد', 'New announcement')}</Btn>}</>} />
    <Tabs value={tab} onChange={v => setQuery({ tab: v === 'live' ? undefined : v })} tabs={[{ v: 'live', label: L('المنشورة', 'Live'), count: by.live.length }, { v: 'pending', label: L('بانتظار الاعتماد', 'Pending'), count: by.pending.length },
      { v: 'drafts', label: L('المسودات', 'Drafts'), count: by.drafts.length }, { v: 'expired', label: L('المنتهية', 'Expired'), count: by.expired.length }]} />
    <div className="mt-4">{rows.length === 0 ? <div className="bg-surface border border-line rounded-2xl"><Empty title={L('لا إعلانات هنا', 'Nothing here')} /></div> : <div className="grid [grid-template-columns:repeat(auto-fill,minmax(300px,1fr))] gap-4">{rows.map(a =>
      <div key={a.id} className="rounded-2xl border border-line bg-surface overflow-hidden flex flex-col">
        {a.image && <img src={fileUrl(a.image)} alt="" className="w-full h-[150px] object-cover" />}
        <div className="p-4 flex-1">
          <div className="flex flex-wrap items-center gap-1.5"><StatusBadge a={a} /><Badge tone="info">{tagName(a.tag, L)}</Badge><Badge>{a.department ? bi(a.department, lang) : L('كل الموظفين', 'Everyone')}</Badge></div>
          <h3 className="mt-2 font-display font-bold">{bi(a.title, lang)}</h3>
          <p className="mt-1 text-[13px] text-ink-2 line-clamp-3 whitespace-pre-line">{bi(a.text, lang)}</p>
          {a.attachment && <a href={fileUrl(a.attachment.id)} target="_blank" rel="noreferrer" className="mt-1.5 inline-flex items-center gap-1 text-brand text-[12.5px]"><Paperclip size={13} />{a.attachment.name}</a>}
          <div className="mt-2 text-[12px] text-ink-3 num">{L('النشر', 'Publish')}: {fmtDateTime(a.published_at ?? undefined, lang)}{a.expires_at && <> · {L('ينتهي', 'Expires')}: {fmtDate(a.expires_at, lang)}</>}</div>
          {a.author && <div className="text-[12px] text-ink-3">{L('كتبه', 'By')}: {bi(a.author, lang)}</div>}
        </div>
        <div className="px-4 pb-4 flex flex-wrap gap-1.5">
          {d.can.create && (a.status === 'draft' || a.status === 'pending' || (a.status === 'published' && a.published_at && new Date(a.published_at) > new Date())) && <Btn size="sm" icon={<Pencil size={14} />} onClick={() => setEdit(a)}>{L('تعديل', 'Edit')}</Btn>}
          {a.status === 'pending' && d.can.decide && <><Btn size="sm" kind="primary" icon={<Check size={14} />} disabled={decide.isPending} onClick={() => decide.mutate({ id: a.id, approve: true }, { onSuccess: () => toast(L('اعتُمد ونُشر', 'Approved and published')), onError: e => toast(e instanceof ApiError ? e.message : L('تعذّر الاعتماد', 'Could not approve')) })}>{L('اعتماد', 'Approve')}</Btn>
            <Btn size="sm" icon={<Undo2 size={14} />} onClick={() => setBack(a)}>{L('إرجاع', 'Return')}</Btn></>}
          {a.status === 'published' && d.can.archive && <Btn size="sm" kind="ghost" icon={<Archive size={14} />} onClick={() => setStop(a)}>{L('أرشفة', 'Archive')}</Btn>}
        </div>
      </div>)}</div>}</div>
    {edit && <AnnForm a={edit === 'new' ? null : edit} d={d} onClose={() => setEdit(null)} />}
    {back && <DecisionDialog open onClose={() => setBack(null)} reasonRequired minLength={d.reject_min} title={L('إرجاع الإعلان لكاتبه', 'Return to the author')} subject={bi(back.title, lang)} confirmLabel={L('إرجاع', 'Return')}
      onConfirm={note => decide.mutateAsync({ id: back.id, approve: false, note }).then(() => toast(L('أُرجع الإعلان', 'Returned')))} />}
    {stop && <DecisionDialog open onClose={() => setStop(null)} tone="danger" title={L('أرشفة الإعلان', 'Archive the announcement')} subject={bi(stop.title, lang)} confirmLabel={L('أرشفة', 'Archive')}
      onConfirm={() => archive.mutateAsync(stop.id).then(() => toast(L('أُرشف الإعلان', 'Archived')))} />}
    {cfg && <AnnSettings d={d} onClose={() => setCfg(false)} />}
  </div>
}

const local = (iso: string | null | undefined) => { if (!iso) return ''; const x = new Date(iso); x.setMinutes(x.getMinutes() - x.getTimezoneOffset()); return x.toISOString().slice(0, 16) }

function AnnForm({ a, d, onClose }: { a: Announcement | null; d: AnnouncementsHr; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const save = useSaveAnnouncement(a?.id ?? null)
  const [v, setV] = useState<AnnInput>({ title_ar: a?.title_ar ?? '', title_en: a?.title_en ?? '', text_ar: a?.text_ar ?? '', text_en: a?.text_en ?? '', tag: a?.tag ?? 'people', audience: a?.audience ?? 'all',
    department_id: a?.department_id ?? '', publish_at: local(a?.published_at), expires_at: local(a?.expires_at) })
  const [image, setImage] = useState<File | null>(null); const [file, setFile] = useState<File | null>(null)
  const err = save.error instanceof ApiError ? save.error : null
  const set = <K extends keyof AnnInput>(k: K, x: AnnInput[K]) => setV({ ...v, [k]: x })
  const go = (submit: boolean) => save.mutate({ ...v, publish_at: v.publish_at ? new Date(v.publish_at).toISOString() : '', expires_at: v.expires_at ? new Date(v.expires_at).toISOString() : '', image, attachment: file, submit }, {
    onSuccess: r => { toast(r.status === 'draft' ? L('حُفظت المسودة', 'Draft saved') : r.status === 'pending' ? L('أُرسل للاعتماد', 'Sent for approval') : L('نُشر الإعلان', 'Published')); onClose() } })
  return <Modal open onClose={onClose} width={720} title={a ? L('تعديل إعلان', 'Edit announcement') : L('إعلان جديد', 'New announcement')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn disabled={save.isPending} onClick={() => go(false)}>{L('حفظ كمسودة', 'Save draft')}</Btn>
      <Btn kind="primary" disabled={save.isPending} onClick={() => go(true)}>{d.config.require_approval && !d.can.decide ? L('إرسال للاعتماد', 'Send for approval') : L('نشر', 'Publish')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      <Field req label={L('العنوان بالعربية', 'Arabic title')} error={err?.field('title_ar')}><input className="field" value={v.title_ar} onChange={e => set('title_ar', e.target.value)} /></Field>
      <Field label={L('العنوان بالإنكليزية', 'English title')} error={err?.field('title_en')}><input className="field ltr" value={v.title_en} onChange={e => set('title_en', e.target.value)} /></Field>
      <Field req label={L('النص بالعربية', 'Arabic text')} error={err?.field('text_ar')} className="sm:col-span-2"><textarea className="field min-h-[110px]" value={v.text_ar} onChange={e => set('text_ar', e.target.value)} /></Field>
      <Field label={L('النص بالإنكليزية', 'English text')} className="sm:col-span-2"><textarea className="field ltr" value={v.text_en} onChange={e => set('text_en', e.target.value)} /></Field>
    </div>
    <Field div label={L('الفئة', 'Category')} className="mt-3"><Seg value={v.tag} onChange={x => set('tag', x)} options={d.tags.map(t => ({ v: t, label: tagName(t, L) }))} /></Field>
    <div className="grid sm:grid-cols-2 gap-3 mt-3">
      <Field div label={L('الجمهور', 'Audience')}><Seg value={v.audience} onChange={x => set('audience', x)} options={[{ v: 'all', label: L('كل الموظفين', 'Everyone') }, { v: 'department', label: L('إدارة محددة', 'One department') }]} /></Field>
      {v.audience === 'department' && <Field req label={L('الإدارة', 'Department')} error={err?.field('department_id')}><select className="field" value={v.department_id} onChange={e => set('department_id', e.target.value ? Number(e.target.value) : '')}>
        <option value="">—</option>{d.departments.map(x => <option key={x.id} value={x.id}>{bi(x, lang)}</option>)}</select></Field>}
      <Field label={L('موعد النشر (فارغ = الآن)', 'Publish at (empty = now)')} error={err?.field('publish_at')}><input className="field num" type="datetime-local" value={v.publish_at} onChange={e => set('publish_at', e.target.value)} /></Field>
      <Field label={L('ينتهي في (اختياري)', 'Expires at (optional)')} error={err?.field('expires_at')}><input className="field num" type="datetime-local" value={v.expires_at} onChange={e => set('expires_at', e.target.value)} /></Field>
      <Field label={L('صورة (jpg/png/webp)', 'Image (jpg/png/webp)')} error={err?.field('image')} hint={L(`حتى ${d.config.max_mb} MB`, `Up to ${d.config.max_mb} MB`)}><input className="field" type="file" accept=".jpg,.jpeg,.png,.webp" onChange={e => setImage(e.target.files?.[0] ?? null)} /></Field>
      <Field label={L('مرفق (pdf/docx/xlsx)', 'Attachment (pdf/docx/xlsx)')} error={err?.field('attachment')} hint={a?.attachment ? a.attachment.name : undefined}><input className="field" type="file" accept=".pdf,.docx,.xlsx,.jpg,.png" onChange={e => setFile(e.target.files?.[0] ?? null)} /></Field>
    </div>
    {err && !Object.keys(err.errors).length && <p className="text-danger text-[13px] mt-2">{err.message}</p>}
  </Modal>
}

function AnnSettings({ d, onClose }: { d: AnnouncementsHr; onClose: () => void }) {
  const L = useL()
  const save = useAnnSettings()
  const [v, setV] = useState(d.config)
  return <Modal open onClose={onClose} title={L('إعدادات الإعلانات', 'Announcement settings')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn onClick={() => setV({ require_approval: false, max_mb: 10 })}>{L('القيم الافتراضية', 'Defaults')}</Btn>
      <Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate(v, { onSuccess: () => { toast(L('حُفظت الإعدادات', 'Settings saved')); onClose() }, onError: e => toast(e instanceof ApiError ? e.message : L('تعذّر الحفظ', 'Could not save')) })}>{L('حفظ', 'Save')}</Btn></>}>
    <Field div label={L('اعتماد قبل النشر', 'Approval before publishing')} hint={L('عند التفعيل يحتاج إعلان من لا يملك صلاحية «اعتماد» إلى موافقة قبل ظهوره.', 'When on, announcements by users without the approve right wait for approval.')}>
      <Seg value={v.require_approval ? 'on' : 'off'} onChange={x => setV({ ...v, require_approval: x === 'on' })} options={[{ v: 'off', label: L('نشر مباشر', 'Publish directly') }, { v: 'on', label: L('يحتاج اعتماداً', 'Needs approval') }]} /></Field>
    <Field label={L('الحد الأقصى لحجم الملف (MB)', 'Max file size (MB)')} className="mt-3"><input className="field num" type="number" min={1} max={25} value={v.max_mb} onChange={e => setV({ ...v, max_mb: Number(e.target.value) })} /></Field>
  </Modal>
}
