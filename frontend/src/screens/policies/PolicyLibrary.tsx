import { useState, type ReactNode } from 'react'
import { Archive, ArchiveRestore, ArrowRight, BookOpen, CheckCircle2, Download, Eye, FilePlus2, FileText, Link2, Loader2, Pencil, Search, Send, Settings2, Trash2, Upload, Users } from 'lucide-react'
import { useL, useLang, bi, fmtDate, fmtDateTime } from '../../lib/store'
import { setQuery, useRoute } from '../../lib/router'
import { ApiError } from '../../lib/api'
import { fileUrl } from '../../lib/console'
import { normAr, useArchivePolicy, useCreatePolicy, useDeleteVersion, useLinkParagraph, useNewVersion, usePolicies, usePolicy, usePolicyReads, usePolicySearch, usePublishVersion, useUpdatePolicy, useUpdateVersion,
  type DocInput, type Paragraph, type PolicyDoc, type PolicyFull, type PolicyRule, type PolicyStatus, type VersionInput } from '../../lib/policies'
import { Badge, Btn, Empty, Field, Modal, PageHead, Panel, Seg, toast } from '../../ui/kit'
import { DecisionDialog } from '../../ui/decision'
import type { Bi } from '../../lib/types'

/**
 * مكتبة السياسات (D-26، SRS §10.12): الوثائق المعتمدة بإصداراتها، والبحث بكلمة يرجع الفقرة.
 * الفقرة المربوطة بإعداد = «مطبّقة في النظام» (تُضبط قيمها من صفحة الإعداد)، وغير المربوطة نص مرجعي فقط.
 * الموظف يقرأ المنشور (ويُسجَّل اطلاعه)، ومن يدير المكتبة يرفع وينشر ويؤرشف ويربط.
 */
export function PolicyLibrary({ settingsLinks = true }: { settingsLinks?: boolean }) {
  const L = useL()
  const route = useRoute()
  const doc = Number(route.query.doc) || null
  const [q, setQ] = useState(route.query.q ?? '')
  const [archived, setArchived] = useState(false)
  const list = usePolicies()
  const search = usePolicySearch(q, archived)
  const [create, setCreate] = useState(false)
  if (doc) return <PolicyView id={doc} version={Number(route.query.version) || undefined} settingsLinks={settingsLinks} />
  const d = list.data
  if (!d) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const searching = q.trim().length >= 2
  return <div className="max-w-[1200px] mx-auto space-y-5">
    <PageHead title={L('مكتبة السياسات', 'Policy library')} sub={L('الأنظمة والسياسات والإجراءات المعتمدة في الشركة — ابحث بكلمة لتصل إلى الفقرة.', 'The company’s approved regulations, policies and procedures — search a word to reach the paragraph.')}
      actions={d.can.create && <Btn kind="primary" icon={<FilePlus2 size={16} />} onClick={() => setCreate(true)}>{L('رفع وثيقة', 'Upload a document')}</Btn>} />
    <div className="flex flex-wrap items-center gap-3">
      <div className="relative flex-1 min-w-[220px]"><Search size={16} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" />
        <input className="field !ps-9" placeholder={L('ابحث في نصوص السياسات… مثل: الإجازة المرضية', 'Search the policy texts… e.g. sick leave')} value={q}
          onChange={e => { setQ(e.target.value); setQuery({ q: e.target.value || undefined }) }} /></div>
      {d.can.edit && searching && <label className="flex items-center gap-2 text-[13px]"><input type="checkbox" checked={archived} onChange={e => setArchived(e.target.checked)} />{L('يشمل الإصدارات المؤرشفة', 'Include archived versions')}</label>}
    </div>
    {searching ? <SearchResults q={q} hits={search.data?.data} loading={search.isFetching} /> : <>
      {d.data.length === 0 ? <div className="bg-surface border border-line rounded-2xl"><Empty title={L('لا توجد وثائق بعد', 'No documents yet')} /></div>
        : <div className="grid [grid-template-columns:repeat(auto-fill,minmax(290px,1fr))] gap-3">{d.data.map(x => <DocCard key={x.id} d={x} categories={d.categories} manage={d.can.edit} />)}</div>}
      <Legend />
    </>}
    {create && <DocForm categories={d.categories} onClose={() => setCreate(false)} />}
  </div>
}

function Legend() {
  const L = useL()
  return <p className="text-[12.5px] text-ink-3 flex flex-wrap items-center gap-x-4 gap-y-1">
    <span className="flex items-center gap-1.5"><Badge tone="brand"><Settings2 size={12} />{L('مطبّقة في النظام', 'Applied in the system')}</Badge>{L('فقرة قيمها مضبوطة في إعدادات النظام', 'a paragraph whose values are set in the system settings')}</span>
    <span>{L('وبقية الفقرات نص مرجعي للاطلاع.', 'Other paragraphs are reference text.')}</span>
  </p>
}

const STATUS: Record<PolicyStatus, [string, string, 'brand' | 'brass' | 'neutral']> = { published: ['منشورة', 'Published', 'brand'], draft: ['مسودة', 'Draft', 'brass'], archived: ['مؤرشفة', 'Archived', 'neutral'] }
function StatusBadge({ s }: { s: PolicyStatus }) { const L = useL(); const [ar, en, tone] = STATUS[s]; return <Badge tone={tone}>{L(ar, en)}</Badge> }
const catLabel = (cats: (Bi & { key: string })[], k: string, lang: 'ar' | 'en') => { const c = cats.find(x => x.key === k); return c ? bi(c, lang) : k }

function DocCard({ d, categories, manage }: { d: PolicyDoc; categories: (Bi & { key: string })[]; manage: boolean }) {
  const L = useL(); const lang = useLang()
  return <button onClick={() => setQuery({ doc: String(d.id), q: undefined })} className="hoverlift text-start rounded-2xl border border-line bg-surface p-4 flex flex-col gap-2">
    <div className="flex items-start gap-3">
      <span className="size-10 rounded-xl grid place-items-center bg-brand-soft text-brand shrink-0"><BookOpen size={18} /></span>
      <div className="min-w-0 flex-1"><div className="font-semibold text-[14.5px] leading-snug">{bi(d, lang)}</div>
        <div className="text-[12px] text-ink-3 mt-0.5"><span className="ltr inline-block">{d.code ?? '—'}</span> · {catLabel(categories, d.category, lang)}</div></div>
    </div>
    <div className="flex flex-wrap items-center gap-1.5 mt-auto pt-1">
      {d.status !== 'published' && <StatusBadge s={d.status} />}
      {d.current && <Badge>{L('الإصدار', 'Issue')} <span className="num">{d.current.issue ?? d.current.number}</span></Badge>}
      {d.current?.effective_from && <span className="text-[12px] text-ink-3 num">{L('نافذة من', 'Effective')} {fmtDate(d.current.effective_from, lang)}</span>}
      {manage && d.has_draft && d.status === 'published' && <Badge tone="brass">{L('إصدار جديد قيد الإعداد', 'New version in draft')}</Badge>}
      {!manage && d.status === 'published' && (d.read ? <Badge tone="brand"><CheckCircle2 size={12} />{L('اطّلعت', 'Read')}</Badge> : <Badge tone="brass">{L('جديدة عليك', 'New to you')}</Badge>)}
      {manage && d.reads && <span className="ms-auto text-[12px] text-ink-3 flex items-center gap-1"><Eye size={13} /><span className="num">{d.reads.n} / {d.reads.of}</span></span>}
    </div>
  </button>
}

/** تمييز كلمات البحث داخل الفقرة: المقارنة على النص المطبَّع مع خريطة مواضع إلى النص الأصلي (التشكيل يُحذف) */
function Highlight({ text, q }: { text: string; q: string }) {
  const words = normAr(q).split(/\s+/).filter(w => w.length >= 2)
  let n = ''; const at: number[] = []
  for (let i = 0; i < text.length; i++) { const c = normAr(text[i]); for (const ch of c) { n += ch; at.push(i) } }
  const marks: [number, number][] = []
  for (const w of words) { let i = n.indexOf(w); while (i >= 0) { marks.push([at[i], at[i + w.length - 1] + 1]); i = n.indexOf(w, i + w.length) } }
  if (!marks.length) return <>{text}</>
  marks.sort((a, b) => a[0] - b[0])
  const out: ReactNode[] = []; let pos = 0
  marks.forEach(([s, e], k) => { if (s < pos) return; out.push(text.slice(pos, s)); out.push(<mark key={k} className="bg-brass-soft text-ink rounded px-0.5">{text.slice(s, e)}</mark>); pos = e })
  out.push(text.slice(pos))
  return <>{out}</>
}

function SearchResults({ q, hits, loading }: { q: string; hits?: import('../../lib/policies').SearchHit[]; loading: boolean }) {
  const L = useL(); const lang = useLang()
  if (!hits) return <div className="grid place-items-center py-16"><Loader2 className="animate-spin text-brand" /></div>
  return <Panel title={<span className="flex items-center gap-2">{L('نتائج البحث', 'Search results')} <Badge><span className="num">{hits.length}</span></Badge>{loading && <Loader2 size={14} className="animate-spin text-ink-3" />}</span>} pad={false}>
    {hits.length === 0 ? <Empty title={L('لا توجد فقرة تطابق البحث', 'No paragraph matches your search')} /> : <ul className="divide-y divide-line-2">{hits.map(h => <li key={h.id}>
      <button onClick={() => setQuery({ doc: String(h.document.id), version: String(h.version_id), q: undefined, p: String(h.n) })} className="w-full text-start px-4 py-3 hover:bg-sub">
        <div className="flex flex-wrap items-center gap-2 text-[12.5px] text-ink-3 mb-1"><span className="font-semibold text-ink-2">{bi(h.document, lang)}</span><span className="ltr">{h.document.code}</span>
          <span>· {L('الفقرة', 'Paragraph')} <span className="num">{h.n}</span></span>{h.version_status === 'archived' && <Badge>{L('إصدار مؤرشف', 'Archived version')}</Badge>}
          {h.rule && <Badge tone="brand"><Settings2 size={12} />{bi(h.rule, lang)}</Badge>}</div>
        <p className="text-[14px] leading-7 line-clamp-4 [overflow-wrap:anywhere]"><Highlight text={h.text} q={q} /></p>
      </button></li>)}</ul>}
  </Panel>
}

function PolicyView({ id, version, settingsLinks }: { id: number; version?: number; settingsLinks: boolean }) {
  const L = useL(); const lang = useLang()
  const route = useRoute()
  const q = usePolicy(id, version)
  const [edit, setEdit] = useState<'doc' | 'version' | 'new' | null>(null)
  const [link, setLink] = useState<Paragraph | null>(null)
  const [reads, setReads] = useState(false)
  const [confirm, setConfirm] = useState<'publish' | 'delete' | 'archive' | null>(null)
  const publish = usePublishVersion(); const del = useDeleteVersion(); const archive = useArchivePolicy()
  if (q.isError) return <div className="max-w-[900px] mx-auto"><Empty title={L('الوثيقة غير متاحة', 'This document is not available')} action={<Btn onClick={() => setQuery({ doc: undefined, version: undefined })}>{L('العودة للمكتبة', 'Back to the library')}</Btn>} /></div>
  const d = q.data
  if (!d) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const p = d.data; const v = p.version; const can = d.can
  const focus = Number(route.query.p) || 0
  const applied = v?.paragraphs.filter(x => x.rule).length ?? 0
  return <div className="max-w-[1000px] mx-auto space-y-5">
    <button className="text-[13px] text-ink-3 hover:text-ink flex items-center gap-1" onClick={() => setQuery({ doc: undefined, version: undefined, p: undefined })}><ArrowRight size={14} className={lang === 'en' ? 'rotate-180' : ''} />{L('مكتبة السياسات', 'Policy library')}</button>
    <PageHead title={bi(p, lang)} sub={<span className="flex flex-wrap items-center gap-2"><span className="ltr">{p.code ?? ''}</span><span>{catLabel(d.categories, p.category, lang)}</span><StatusBadge s={p.status} /></span>}
      actions={<>
        {v?.file && <a className="inline-flex items-center justify-center gap-2 rounded-lg border font-semibold h-9 px-3.5 bg-surface text-ink border-line hover:bg-sub" href={fileUrl(v.file.id)} target="_blank" rel="noreferrer"><Download size={15} />{L('الملف الأصلي', 'Original file')}</a>}
        {can.edit && <Btn icon={<Pencil size={15} />} onClick={() => setEdit('doc')}>{L('البيانات', 'Details')}</Btn>}
        {can.edit && p.status !== 'archived' && !p.versions.some(x => x.status === 'draft') && <Btn icon={<Upload size={15} />} onClick={() => setEdit('new')}>{L('إصدار جديد', 'New version')}</Btn>}
        {can.archive && p.status !== 'draft' && <Btn icon={p.status === 'archived' ? <ArchiveRestore size={15} /> : <Archive size={15} />} onClick={() => p.status === 'archived' ? archive.mutate({ id: p.id, restore: true }) : setConfirm('archive')}>{p.status === 'archived' ? L('استعادة', 'Restore') : L('أرشفة', 'Archive')}</Btn>}
      </>} />

    {p.versions.length > 1 && <div className="flex flex-wrap gap-2">{p.versions.map(x => <button key={x.id} onClick={() => setQuery({ version: String(x.id), p: undefined })}
      className={`rounded-full border px-3 h-8 text-[12.5px] flex items-center gap-1.5 ${x.id === v?.id ? 'border-brand bg-brand-soft text-brand font-semibold' : 'border-line bg-surface'}`}>
      {L('الإصدار', 'Issue')} <span className="num">{x.issue ?? x.number}</span><span className="text-ink-3">· {L(STATUS[x.status][0], STATUS[x.status][1])}</span></button>)}</div>}

    {v && <Panel>
      <div className="grid gap-3 sm:grid-cols-4 text-[13px]">
        <Info k={L('رقم الإصدار', 'Issue')} v={<span className="num">{v.issue ?? v.number}</span>} />
        <Info k={L('نافذ من', 'Effective from')} v={v.effective_from ? <span className="num">{fmtDate(v.effective_from, lang)}</span> : '—'} />
        <Info k={L('النشر', 'Published')} v={v.published_at ? <span>{fmtDateTime(v.published_at, lang)}{v.publisher ? ` — ${bi(v.publisher, lang)}` : ''}</span> : L('لم يُنشر بعد', 'Not published yet')} />
        {v.reads ? <Info k={L('الاطلاع', 'Read by')} v={<button className="text-brand flex items-center gap-1" onClick={() => setReads(true)}><Users size={14} /><span className="num">{v.reads.n} / {v.reads.of}</span></button>} />
          : <Info k={L('اطّلاعك', 'Your reading')} v={v.read_at ? <span className="text-brand flex items-center gap-1"><CheckCircle2 size={14} />{fmtDateTime(v.read_at, lang)}</span> : '—'} />}
      </div>
      {v.note && <p className="mt-3 text-[13px] text-ink-2">{v.note}</p>}
      {v.status === 'draft' && can.edit && <div className="mt-4 flex flex-wrap items-center gap-2 rounded-xl bg-brass-soft px-3 py-2.5 text-[13px]">
        <span className="flex-1 min-w-[200px]">{L('هذا الإصدار مسودة لا يراها الموظفون. راجع النص واربط الفقرات المطبّقة ثم انشره.', 'This version is a draft employees cannot see. Review the text, link the applied paragraphs, then publish.')}</span>
        <Btn size="sm" icon={<Pencil size={14} />} onClick={() => setEdit('version')}>{L('تعديل الإصدار', 'Edit version')}</Btn>
        <Btn size="sm" icon={<Trash2 size={14} />} onClick={() => setConfirm('delete')}>{L('حذف المسودة', 'Delete draft')}</Btn>
        <Btn size="sm" kind="primary" icon={<Send size={14} />} onClick={() => setConfirm('publish')}>{L('نشر وإشعار الموظفين', 'Publish & notify staff')}</Btn>
      </div>}
      {v.status === 'archived' && <div className="mt-4 rounded-xl bg-sub px-3 py-2.5 text-[13px] text-ink-2">{L('إصدار مؤرشف — للاطلاع فقط، والنافذ هو الإصدار المنشور.', 'An archived version — for reference only; the published version is in force.')}</div>}
      {v.text_missing && <div className="mt-4 rounded-xl bg-danger-soft text-danger px-3 py-2.5 text-[13px]">{L('لم يُستخرج نص من الملف — أضف النص يدوياً ليظهر في البحث.', 'No text could be extracted from the file — add the text manually so it can be searched.')}</div>}
    </Panel>}

    {v && v.paragraphs.length > 0 && <Panel title={<span className="flex items-center gap-2">{L('النص', 'Text')}<span className="text-[12px] font-normal text-ink-3">{L(`${v.paragraphs.length} فقرة · ${applied} مطبّقة في النظام`, `${v.paragraphs.length} paragraphs · ${applied} applied in the system`)}</span></span>} pad={false}>
      <ol className="divide-y divide-line-2">{v.paragraphs.map(x => <li key={x.id} id={`p${x.n}`} ref={el => { if (el && x.n === focus) el.scrollIntoView({ block: 'center' }) }}
        className={`px-4 py-3 flex gap-3 ${x.n === focus ? 'bg-brass-soft/60' : ''} ${x.rule ? 'border-s-[3px] border-s-brand' : ''}`}>
        <span className="text-[11px] text-ink-3 num w-7 shrink-0 pt-1">{x.n}</span>
        <div className="min-w-0 flex-1">
          <p className="text-[14px] leading-7 whitespace-pre-line [overflow-wrap:anywhere]">{x.text}</p>
          {x.rule && <div className="mt-1.5 flex flex-wrap items-center gap-2 text-[12.5px]"><Badge tone="brand"><Settings2 size={12} />{L('مطبّقة في النظام:', 'Applied in the system:')} {bi(x.rule, lang)}</Badge>
            {x.rule_note && <span className="text-ink-3">{x.rule_note}</span>}
            {settingsLinks && can.edit && <a className="text-brand hover:underline" href={x.rule.path}>{L('فتح الإعداد', 'Open the setting')}</a>}</div>}
        </div>
        {can.edit && <button className="self-start p-1.5 rounded-lg hover:bg-sub text-ink-3" onClick={() => setLink(x)} title={L('ربط بإعداد', 'Link to a setting')} aria-label={L('ربط بإعداد', 'Link to a setting')}><Link2 size={15} /></button>}
      </li>)}</ol>
    </Panel>}
    {v && !v.paragraphs.length && !v.text_missing && <Panel><Empty title={L('لا يوجد نص', 'No text')} /></Panel>}

    {edit === 'doc' && <DocForm categories={d.categories} doc={p} onClose={() => setEdit(null)} />}
    {edit === 'new' && <VersionForm docId={p.id} onClose={() => setEdit(null)} />}
    {edit === 'version' && v && <VersionForm docId={p.id} version={v} onClose={() => setEdit(null)} />}
    {link && <LinkForm p={link} rules={d.rules} onClose={() => setLink(null)} />}
    {reads && v && <ReadsModal versionId={v.id} onClose={() => setReads(false)} />}
    <DecisionDialog open={confirm === 'publish'} onClose={() => setConfirm(null)} title={L('نشر الإصدار', 'Publish the version')} confirmLabel={L('نشر', 'Publish')}
      subject={L('يصبح هذا الإصدار هو النافذ، وينتقل السابق إلى الأرشيف، ويصل إشعار لكل الموظفين.', 'This version becomes the one in force, the previous one is archived, and every employee is notified.')}
      onConfirm={async () => { if (v) { await publish.mutateAsync(v.id); toast(L('نُشرت الوثيقة', 'Document published')) } }} />
    <DecisionDialog open={confirm === 'delete'} onClose={() => setConfirm(null)} title={L('حذف المسودة', 'Delete the draft')} confirmLabel={L('حذف', 'Delete')} tone="danger"
      onConfirm={async () => { if (v) { const r = await del.mutateAsync(v.id); if (!r.data) setQuery({ doc: undefined, version: undefined }); else setQuery({ version: undefined }) } }} />
    <DecisionDialog open={confirm === 'archive'} onClose={() => setConfirm(null)} title={L('أرشفة الوثيقة', 'Archive the document')} confirmLabel={L('أرشفة', 'Archive')} tone="danger"
      subject={L('تختفي عن الموظفين وتبقى في الأرشيف ويمكن استعادتها.', 'It disappears for employees, stays in the archive and can be restored.')}
      onConfirm={async () => { await archive.mutateAsync({ id: p.id, restore: false }) }} />
  </div>
}

function Info({ k, v }: { k: ReactNode; v: ReactNode }) { return <div><div className="text-[12px] text-ink-3">{k}</div><div className="mt-0.5 font-medium">{v}</div></div> }

function DocForm({ categories, doc, onClose }: { categories: (Bi & { key: string })[]; doc?: PolicyFull; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const create = useCreatePolicy(); const update = useUpdatePolicy()
  const [v, setV] = useState<DocInput>({ code: doc?.code ?? '', title_ar: doc?.ar ?? '', title_en: doc?.en ?? '', category: doc?.category ?? 'policy' })
  const [ver, setVer] = useState<VersionInput>({ issue: '01', effective_from: '', note: '', file: null, text: '' })
  const [mode, setMode] = useState<'file' | 'text'>('file')
  const m = doc ? update : create
  const err = m.error instanceof ApiError ? m.error : null
  const submit = () => doc ? update.mutate({ id: doc.id, v }, { onSuccess: () => { toast(L('حُفظت البيانات', 'Details saved')); onClose() } })
    : create.mutate({ ...v, ...ver, file: mode === 'file' ? ver.file : null, text: mode === 'text' ? ver.text : null }, { onSuccess: r => { toast(L('رُفعت الوثيقة مسودةً — راجعها ثم انشرها', 'Uploaded as a draft — review it, then publish')); onClose(); if (r.data) setQuery({ doc: String(r.data.id), q: undefined }) } })
  return <Modal open onClose={onClose} width={640} title={doc ? L('بيانات الوثيقة', 'Document details') : L('رفع وثيقة', 'Upload a document')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={m.isPending} onClick={submit}>{m.isPending && <Loader2 size={15} className="animate-spin" />}{doc ? L('حفظ', 'Save') : L('رفع كمسودة', 'Upload as draft')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-4">
      <Field label={L('العنوان بالعربية', 'Arabic title')} req error={err?.field('title_ar')} className="sm:col-span-2"><input className="field" value={v.title_ar} onChange={e => setV({ ...v, title_ar: e.target.value })} /></Field>
      <Field label={L('العنوان بالإنكليزية', 'English title')} error={err?.field('title_en')} className="sm:col-span-2"><input className="field ltr text-start" value={v.title_en} onChange={e => setV({ ...v, title_en: e.target.value })} /></Field>
      <Field label={L('الرمز', 'Code')} hint={L('مثل HR.01-01', 'e.g. HR.01-01')} error={err?.field('code')}><input className="field ltr text-start" value={v.code} onChange={e => setV({ ...v, code: e.target.value })} /></Field>
      <Field label={L('النوع', 'Type')}><select className="field" value={v.category} onChange={e => setV({ ...v, category: e.target.value })}>{categories.map(c => <option key={c.key} value={c.key}>{bi(c, lang)}</option>)}</select></Field>
    </div>
    {!doc && <div className="mt-5 pt-4 border-t border-line"><VersionFields v={ver} setV={setVer} mode={mode} setMode={setMode} err={err} /></div>}
    {err && !Object.keys(err.errors).length && <div role="alert" className="mt-4 rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2">{err.message}</div>}
  </Modal>
}

function VersionFields({ v, setV, mode, setMode, err, keep }: { v: VersionInput; setV: (v: VersionInput) => void; mode: 'file' | 'text'; setMode: (m: 'file' | 'text') => void; err: ApiError | null; keep?: boolean }) {
  const L = useL()
  return <div className="grid sm:grid-cols-2 gap-4">
    <Field label={L('رقم الإصدار', 'Issue')} error={err?.field('issue')}><input className="field ltr text-start num" value={v.issue} onChange={e => setV({ ...v, issue: e.target.value })} /></Field>
    <Field label={L('تاريخ النفاذ', 'Effective from')} hint={L('فارغ = تاريخ النشر', 'Empty = the publishing date')} error={err?.field('effective_from')}><input type="date" className="field num" value={v.effective_from} onChange={e => setV({ ...v, effective_from: e.target.value })} /></Field>
    <Field label={L('المحتوى', 'Content')} div className="sm:col-span-2"><Seg value={mode} onChange={setMode} options={[{ v: 'file', label: L('ملف PDF / DOCX / TXT', 'PDF / DOCX / TXT file') }, { v: 'text', label: L('لصق النص', 'Paste the text') }]} /></Field>
    {mode === 'file' ? <Field label={L('الملف', 'File')} hint={keep ? L('اتركه فارغاً للإبقاء على الملف الحالي. يُستخرج النص تلقائياً.', 'Leave empty to keep the current file. The text is extracted automatically.') : L('يُستخرج النص تلقائياً للبحث.', 'The text is extracted automatically for search.')} error={err?.field('file')} className="sm:col-span-2">
      <input type="file" accept=".pdf,.docx,.txt" className="field" onChange={e => setV({ ...v, file: e.target.files?.[0] ?? null })} /></Field>
      : <Field label={L('النص', 'Text')} hint={L('اترك سطراً فارغاً بين الفقرات.', 'Leave an empty line between paragraphs.')} error={err?.field('text')} className="sm:col-span-2">
        <textarea className="field min-h-[220px] leading-7" value={v.text ?? ''} onChange={e => setV({ ...v, text: e.target.value })} /></Field>}
    <Field label={L('ملاحظة الإصدار', 'Version note')} error={err?.field('note')} className="sm:col-span-2"><input className="field" value={v.note} onChange={e => setV({ ...v, note: e.target.value })} placeholder={L('ما الذي تغيّر؟', 'What changed?')} /></Field>
  </div>
}

function VersionForm({ docId, version, onClose }: { docId: number; version?: PolicyFull['version']; onClose: () => void }) {
  const L = useL()
  const add = useNewVersion(); const upd = useUpdateVersion()
  const [v, setV] = useState<VersionInput>({ issue: version?.issue ?? '', effective_from: version?.effective_from ?? '', note: version?.note ?? '', file: null, text: version?.text ?? '' })
  const [mode, setMode] = useState<'file' | 'text'>(version?.text_missing ? 'text' : 'file')
  const m = version ? upd : add
  const err = m.error instanceof ApiError ? m.error : null
  const submit = () => {
    const body = { ...v, file: mode === 'file' ? v.file : null, text: mode === 'text' ? v.text : null }
    const done = (r: { data: PolicyFull }) => { toast(L('حُفظ الإصدار مسودةً', 'Version saved as a draft')); onClose(); setQuery({ version: r.data.version ? String(r.data.version.id) : undefined, p: undefined }) }
    if (version) upd.mutate({ id: version.id, v: body }, { onSuccess: done }); else add.mutate({ id: docId, v: body }, { onSuccess: done })
  }
  return <Modal open onClose={onClose} width={640} title={version ? L('تعديل الإصدار', 'Edit the version') : L('إصدار جديد', 'New version')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={m.isPending} onClick={submit}>{m.isPending && <Loader2 size={15} className="animate-spin" />}{L('حفظ كمسودة', 'Save as draft')}</Btn></>}>
    {!version && <p className="text-[13px] text-ink-2 mb-4">{L('يبقى الإصدار الحالي نافذاً حتى تنشر الجديد، ثم ينتقل إلى الأرشيف. وتنتقل روابط الإعدادات للفقرات التي لم يتغير نصها.', 'The current version stays in force until you publish the new one, then it is archived. Setting links move to paragraphs whose text is unchanged.')}</p>}
    <VersionFields v={v} setV={setV} mode={mode} setMode={setMode} err={err} keep={!!version} />
    {err && !Object.keys(err.errors).length && <div role="alert" className="mt-4 rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2">{err.message}</div>}
  </Modal>
}

function LinkForm({ p, rules, onClose }: { p: Paragraph; rules: PolicyRule[]; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const save = useLinkParagraph()
  const [key, setKey] = useState(p.rule?.key ?? ''); const [note, setNote] = useState(p.rule_note ?? '')
  const submit = (k: string | null) => save.mutate({ id: p.id, rule_key: k, rule_note: k ? note : null }, { onSuccess: () => { toast(k ? L('رُبطت الفقرة بالإعداد', 'Paragraph linked') : L('أُلغي الربط', 'Link removed')); onClose() } })
  return <Modal open onClose={onClose} title={L('ربط الفقرة بإعداد', 'Link the paragraph to a setting')}
    footer={<>{p.rule && <Btn onClick={() => submit(null)}>{L('إلغاء الربط', 'Remove link')}</Btn>}<Btn onClick={onClose}>{L('إغلاق', 'Close')}</Btn><Btn kind="primary" disabled={!key || save.isPending} onClick={() => submit(key)}>{L('حفظ', 'Save')}</Btn></>}>
    <blockquote className="rounded-xl bg-sub px-3 py-2 text-[13.5px] leading-7 mb-4 line-clamp-6 [overflow-wrap:anywhere]">{p.text}</blockquote>
    <p className="text-[12.5px] text-ink-3 mb-3">{L('لا يقرأ النظام القواعد من النص: تُضبط القيم في صفحة الإعداد، وهذا الربط يبيّن أن الفقرة مطبّقة فعلاً.', 'The system does not read rules from text: values are set on the settings page, and this link shows the paragraph is actually applied.')}</p>
    <div className="grid gap-4">
      <Field label={L('الإعداد الذي يطبّق الفقرة', 'The setting that applies it')}><select className="field" value={key} onChange={e => setKey(e.target.value)}><option value="">—</option>{rules.map(r => <option key={r.key} value={r.key}>{bi(r, lang)}</option>)}</select></Field>
      <Field label={L('ملاحظة (اختياري)', 'Note (optional)')}><input className="field" value={note} onChange={e => setNote(e.target.value)} placeholder={L('مثل: الحسم يبدأ من الدقيقة الأولى', 'e.g. deduction starts from the first minute')} /></Field>
    </div>
  </Modal>
}

function ReadsModal({ versionId, onClose }: { versionId: number; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const q = usePolicyReads(versionId)
  const rows = q.data?.data ?? []
  const [tab, setTab] = useState<'no' | 'yes'>('no')
  const list = rows.filter(r => tab === 'yes' ? !!r.read_at : !r.read_at)
  return <Modal open onClose={onClose} title={L('الاطلاع على الإصدار', 'Who has read this version')} footer={<Btn onClick={onClose}>{L('إغلاق', 'Close')}</Btn>}>
    <Seg value={tab} onChange={setTab} options={[{ v: 'no', label: <>{L('لم يطّلعوا', 'Not read')} <span className="num">({rows.filter(r => !r.read_at).length})</span></> }, { v: 'yes', label: <>{L('اطّلعوا', 'Read')} <span className="num">({rows.filter(r => r.read_at).length})</span></> }]} />
    {!q.data ? <div className="grid place-items-center py-10"><Loader2 className="animate-spin text-brand" /></div> : <ul className="mt-3 max-h-[50vh] overflow-y-auto divide-y divide-line-2">{list.map(r => <li key={r.id} className="py-2 flex items-center gap-2 text-[13.5px]">
      <FileText size={14} className="text-ink-3" /><span className="flex-1">{bi(r, lang)} <span className="text-ink-3 ltr text-[12px]">{r.code}</span></span>{r.read_at && <span className="text-[12px] text-ink-3 num">{fmtDateTime(r.read_at, lang)}</span>}</li>)}</ul>}
  </Modal>
}
