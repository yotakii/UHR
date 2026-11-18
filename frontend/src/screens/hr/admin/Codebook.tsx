import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { ArrowLeftRight, Check, CheckCircle2, Download, FileSpreadsheet, GitMerge, Loader2, Lock, Pencil, Plus, Power, Printer, RotateCcw, Search, Settings2, Upload, X } from 'lucide-react'
import { useL, useLang, bi } from '../../../lib/store'
import { fmtDate, fmtDateTime } from '../../../lib/dates'
import { ApiError } from '../../../lib/api'
import { navigate, setQuery, useRoute } from '../../../lib/router'
import { download } from '../../../lib/admin'
import {
  GROUPS, OPTION_NAMES, ROLE_NAMES, TABLE_NAMES, TYPE_NAMES, importCommit, importPreview, previewCode, simulate, useApproveBook, useCancelRequest, useCodeList, useCodes, useCompare,
  useDecideRequest, useDocRegister, useInherit, useItemStatus, useLoadSector, suggestCode, useRequests, useReports, useSaveCodesSettings, useSaveItem, useSaveList, useSubmitRequest, useVersions,
  type AttrField, type ChangeRequest, type ChangeType, type CodeListDef, type CodeRow, type CodesOverview, type ListDetail, type Simulation,
} from '../../../lib/codes'
import { Badge, Btn, Empty, Field, Modal, PageHead, Panel, Progress, Seg, Tabs, toast } from '../../../ui/kit'

type Tab = 'lists' | 'requests' | 'versions' | 'templates' | 'reports' | 'documents'
const today = () => new Date().toISOString().slice(0, 10)

/**
 * «دليل الترميز» (SRS الأنظمة الأساسية، الجزء A — الدفعة 18): القوائم المرجعية ورموزها، بوابة التأسيس والاعتماد،
 * طلبات التغيير وإعادة الهيكلة، الإصدارات ومقارنتها، قوالب الرموز المركّبة، وتقارير الرموز غير المستخدمة والمكررة.
 */
export function Codebook() {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const { data: d } = useCodes()
  const tab = (route.query.tab as Tab) || 'lists'
  const [settings, setSettings] = useState(false)
  if (!d) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  return <div className="max-w-[1280px] mx-auto space-y-5">
    <PageHead title={L('دليل الترميز', 'Codebook')}
      sub={d.version ? <>{L('الإصدار المعتمد', 'Approved version')} <b className="num">{d.version.version}</b> · {fmtDate(d.version.approved_at, lang)}</> : L('مسودة — لم يُعتمد الدليل بعد.', 'Draft — the codebook is not approved yet.')}
      actions={<div className="flex flex-wrap gap-2">
        {d.can.print && <Btn icon={<Printer size={16} />} onClick={() => navigate('/hr/settings/codes/print')}>{L('طباعة الدليل', 'Print codebook')}</Btn>}
        {d.can.settings && <Btn icon={<Settings2 size={16} />} onClick={() => setSettings(true)}>{L('إعدادات الدليل', 'Codebook settings')}</Btn>}
      </div>} />
    {!d.golive.approved && <GoLive d={d} />}
    <Tabs value={tab} onChange={v => setQuery({ tab: v === 'lists' ? undefined : v })} tabs={[
      { v: 'lists', label: L('القوائم والرموز', 'Lists & codes') }, { v: 'requests', label: L('طلبات التغيير', 'Change requests'), count: d.pending || undefined },
      { v: 'versions', label: L('الإصدارات', 'Versions') }, { v: 'templates', label: L('الرموز المركّبة', 'Composite codes') }, { v: 'documents', label: L('سجل ترميز الوثائق', 'Document codes') }, { v: 'reports', label: L('التقارير', 'Reports') }]} />
    {tab === 'lists' && <Lists d={d} />}
    {tab === 'requests' && <Requests />}
    {tab === 'versions' && <Versions />}
    {tab === 'templates' && <Templates d={d} />}
    {tab === 'reports' && <Reports />}
    {tab === 'documents' && <DocRegister />}
    {settings && <SettingsModal d={d} onClose={() => setSettings(false)} />}
  </div>
}

/** بوابة التأسيس: نسبة الاكتمال، النواقص، قوالب القطاعات، والاعتماد (WF-CS-01) */
function GoLive({ d }: { d: CodesOverview }) {
  const L = useL(); const lang = useLang()
  const g = d.golive
  const approve = useApproveBook(); const sector = useLoadSector()
  const [confirm, setConfirm] = useState(false); const [note, setNote] = useState('')
  return <Panel title={L('تأسيس دليل الترميز', 'Setting up the codebook')} sub={g.enabled ? L('لا يمكن إنشاء ملفات موظفين قبل اعتماد الدليل.', 'Employee files cannot be created before the codebook is approved.') : L('بوابة التأسيس غير مفعّلة — يمكن العمل قبل الاعتماد.', 'The go-live gate is off — work can start before approval.')}
    action={<Badge tone={g.ready ? 'brand' : 'brass'}>{g.percent}%</Badge>}>
    <ol className="grid gap-2 mb-3 [grid-template-columns:repeat(auto-fill,minmax(180px,1fr))]">{([[L('قالب القطاع (اختياري)', 'Sector template (optional)'), d.lists.some(x => x.live > 0)], [L('القوائم الإلزامية', 'Required lists'), g.ready], [L('مراجعة التنبيهات', 'Review the warnings'), g.ready && g.warnings.length === 0], [L('اعتماد الدليل', 'Approve the codebook'), g.approved]] as const).map(([t, ok], i) =>
      <li key={i} className={`flex items-center gap-2 rounded-lg px-3 py-2 text-[13px] border ${ok ? 'border-brand/40 bg-brand-soft' : 'border-line'}`}><span className={`size-6 rounded-full grid place-items-center text-[12px] font-bold ${ok ? 'bg-brand text-white' : 'bg-sub'}`}>{ok ? <Check size={13} /> : i + 1}</span>{t}</li>)}</ol>
    <Progress value={g.percent} tone={g.ready ? 'brand' : 'brass'} />
    <div className="mt-4 grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(210px,1fr))]">
      {g.checks.map(c => <div key={c.key} className={`flex items-center gap-2 rounded-lg px-3 py-2 text-[13px] ${c.ok ? 'bg-brand-soft' : 'bg-sub'}`}>
        {c.ok ? <CheckCircle2 size={16} className="text-brand shrink-0" /> : <X size={16} className="text-danger shrink-0" />}
        <span className="flex-1 min-w-0 truncate">{bi(c, lang)}</span><span className="num text-ink-3">{c.count}</span></div>)}
    </div>
    {g.warnings.map(w => <div key={w.key} className="mt-3 text-[13px] text-brass">{bi(w, lang)}</div>)}
    {d.can.create && d.direct && <div className="mt-4">
      <div className="text-[13px] text-ink-2 mb-2">{L('قوالب جاهزة حسب القطاع (تضيف مسميات وظيفية مقترحة قابلة للتعديل):', 'Ready sector templates (add suggested job titles you can edit):')}</div>
      <div className="flex flex-wrap gap-2">{d.sectors.map(s => <Btn key={s.key} size="sm" disabled={sector.isPending}
        onClick={() => sector.mutate(s.key, { onSuccess: r => toast(L(`أُضيف ${r.added} مسمى و${r.depts} إدارة و${r.elements} عنصر راتب`, `${r.added} title(s), ${r.depts} department(s) and ${r.elements} pay element(s) added`)) })}>{bi(s, lang)} <span className="text-ink-3 num">({s.count})</span></Btn>)}</div>
    </div>}
    <div className="mt-4 flex flex-wrap items-center gap-3">
      {d.can.approve ? <Btn kind="primary" icon={<Check size={16} />} disabled={!g.ready} onClick={() => setConfirm(true)}>{L('اعتماد الدليل', 'Approve the codebook')}</Btn>
        : <span className="text-[13px] text-ink-3">{L('يعتمد الدليلَ: ', 'Approved by: ')}{d.settings.approver_roles.map(r => L(...(ROLE_NAMES[r] ?? [r, r]))).join('، ')}</span>}
      {!g.ready && <span className="text-[13px] text-ink-3">{L('أكمل القوائم الإلزامية أولاً.', 'Complete the required lists first.')}</span>}
    </div>
    <Modal open={confirm} onClose={() => setConfirm(false)} title={L('اعتماد دليل الترميز', 'Approve the codebook')}
      footer={<><Btn onClick={() => setConfirm(false)}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={approve.isPending}
        onClick={() => approve.mutate(note, { onSuccess: r => { toast(L(`اعتُمد الإصدار ${r.version}`, `Version ${r.version} approved`)); setConfirm(false) } })}>{L('تأكيد الاعتماد', 'Confirm approval')}</Btn></>}>
      <p className="text-[14px] text-ink-2">{L('بعد الاعتماد تُقفل الرموز، ويمر كل تعديل لاحق بطلب تغيير يعتمده مالك القائمة (حسب الإعدادات).', 'After approval codes are locked and every later change goes through a change request approved by the list owner (per the settings).')}</p>
      <Field label={L('ملاحظة الاعتماد', 'Approval note')} className="mt-4"><input className="field" value={note} onChange={e => setNote(e.target.value)} /></Field>
      {approve.error instanceof ApiError && <div role="alert" className="mt-3 text-[13px] text-danger">{approve.error.message}</div>}
    </Modal>
  </Panel>
}

// ———————————————— القوائم والرموز ————————————————

function Lists({ d }: { d: CodesOverview }) {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const key = route.query.list || d.lists[0]?.key
  const groups = Object.keys(GROUPS).map(g => ({ g, lists: d.lists.filter(l => (l.system ? 'system' : l.group) === g) })).filter(x => x.lists.length)
  const [newList, setNewList] = useState(false)
  return <div className="grid gap-5 lg:grid-cols-[270px_1fr] items-start">
    <aside className="space-y-4">
      <select className="field lg:hidden" value={key} onChange={e => setQuery({ list: e.target.value })} aria-label={L('القائمة', 'List')}>
        {groups.map(x => <optgroup key={x.g} label={L(...GROUPS[x.g])}>{x.lists.map(l => <option key={l.key} value={l.key}>{bi(l, lang)} ({l.live})</option>)}</optgroup>)}</select>
      <nav className="hidden lg:block bg-surface border border-line rounded-2xl p-2">
        {groups.map(x => <div key={x.g} className="mb-2">
          <div className="px-2 pt-2 pb-1 text-[11.5px] font-semibold text-ink-3">{L(...GROUPS[x.g])}</div>
          {x.lists.map(l => <button key={l.key} onClick={() => setQuery({ list: l.key })} className={`w-full flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-start text-[13.5px] ${l.key === key ? 'bg-brand-soft text-brand font-semibold' : 'hover:bg-sub'}`}>
            <span className="flex-1 min-w-0 truncate">{bi(l, lang)}</span>
            {l.required && <span className="size-1.5 rounded-full bg-brass shrink-0" title={L('إلزامية للتأسيس', 'Required for go-live')} />}
            <span className="num text-[12px] text-ink-3">{l.live}</span></button>)}
        </div>)}
        {d.can.create && d.can.settings && <button onClick={() => setNewList(true)} className="w-full flex items-center gap-2 rounded-lg px-2.5 py-2 text-[13px] text-brand hover:bg-sub"><Plus size={15} />{L('قائمة جديدة', 'New list')}</button>}
      </nav>
    </aside>
    {key && <ListView key={key} listKey={key} d={d} />}
    {newList && <ListDefModal onClose={() => setNewList(false)} roles={d.roles} />}
  </div>
}

function ListView({ listKey, d }: { listKey: string; d: CodesOverview }) {
  const L = useL(); const lang = useLang()
  const { data: x } = useCodeList(listKey)
  const [q, setQ] = useState(''); const [status, setStatus] = useState<'live' | 'all'>('live')
  const [edit, setEdit] = useState<CodeRow | 'new' | null>(null)
  const [act, setAct] = useState<{ type: ChangeType; row: CodeRow } | null>(null)
  const [def, setDef] = useState(false); const [imp, setImp] = useState(false)
  const status$ = useItemStatus(); const inherit = useInherit()
  if (!x) return <div className="grid place-items-center py-16"><Loader2 className="animate-spin text-brand" /></div>
  const l = x.list
  const rows = x.rows.filter(r => (status === 'all' || r.live) && (!q || `${r.code} ${r.ar} ${r.en} ${r.legacy_code ?? ''}`.toLowerCase().includes(q.toLowerCase())))
  const parentOf = (id: number | null) => id ? x.parents.find(p => p.id === id) : null
  const editable = !l.system && !l.source
  const canChange = d.can.create || d.can.edit
  const bound = l.source === 'departments' ? '/hr/settings/departments' : l.source === 'locations' ? '/hr/settings/branches' : null
  const toggle = (r: CodeRow) => d.direct && !l.source ? status$.mutate({ key: l.key, id: r.id, active: !r.live }, { onSuccess: () => toast(L('حُفظ', 'Saved')), onError: e => toast((e as Error).message) })
    : setAct({ type: r.live ? 'deactivate' : 'reactivate', row: r })
  return <Panel pad={false} title={bi(l, lang)}
    sub={<span className="flex flex-wrap items-center gap-1.5 mt-0.5">{l.required && <Badge tone="brass">{L('إلزامية', 'Required')}</Badge>}{l.system && <Badge><Lock size={12} />{L('قياسية مقفلة', 'Locked standard')}</Badge>}
      {l.example && <span>{L('مثال', 'e.g.')} <span className="ltr inline-block">{l.example}</span></span>}{l.pattern && <code className="hidden md:inline ltr text-[11px]" title={L('النمط', 'Pattern')}>{l.pattern}</code>}</span>}
    action={<div className="flex flex-wrap gap-1.5 justify-end">
      {editable && canChange && <Btn size="sm" kind="primary" icon={<Plus size={15} />} onClick={() => setEdit('new')}>{d.direct ? L('رمز جديد', 'New code') : L('طلب إضافة', 'Request addition')}</Btn>}
      {bound && <Btn size="sm" onClick={() => navigate(bound)}>{L('إدارتها من صفحتها', 'Manage on its page')}</Btn>}
      {editable && d.can.create && <Btn size="sm" icon={<Upload size={15} />} onClick={() => setImp(true)}>{L('استيراد', 'Import')}</Btn>}
      {d.can.export && !l.system && <Btn size="sm" icon={<FileSpreadsheet size={15} />} onClick={() => download(`/cs/lists/${l.key}/export`, `UHR-codes-${l.key}.xlsx`).catch(e => toast(e.message))}>Excel</Btn>}
      {!l.system && d.can.settings && d.can.edit && <Btn size="sm" icon={<Settings2 size={15} />} onClick={() => setDef(true)} aria-label={L('تعريف القائمة', 'List definition')} />}
      {editable && l.group_role === 'parent' && d.can.settings && <label className="flex items-center gap-1.5 text-[12.5px]"><input type="checkbox" checked={!!l.inherit} onChange={e => inherit.mutate({ key: l.key, inherit: e.target.checked }, { onSuccess: r => toast(r.inherit ? L(`تُورَّث للشركات التابعة (${r.children}) — نُسخ ${r.synced} رمزاً`, `Inherited by ${r.children} subsidiaries — ${r.synced} code(s) copied`) : L('أُوقف التوريث', 'Inheritance stopped')) })} />{L('قائمة مجموعة تُورَّث', 'Group list (inherited)')}</label>}
    </div>}>
    <div className="px-4 py-3 flex flex-wrap gap-2 items-center border-b border-line-2">
      <div className="relative flex-1 min-w-[180px]"><Search size={15} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" />
        <input className="field !ps-9" value={q} onChange={e => setQ(e.target.value)} placeholder={L('بحث بالرمز أو الاسم أو الرمز السابق', 'Search code, name or legacy code')} /></div>
      <Seg value={status} onChange={setStatus} options={[{ v: 'live', label: L('الفعّالة', 'Active') }, { v: 'all', label: L('الكل', 'All') }]} />
      {l.owner_role && <span className="text-[12px] text-ink-3">{L('المالك: ', 'Owner: ')}{L(...(ROLE_NAMES[l.owner_role] ?? [l.owner_role, l.owner_role]))}</span>}
    </div>
    {rows.length === 0 ? <Empty title={L('لا رموز', 'No codes')}>{editable && L('أضف أول رمز أو استورد القائمة من Excel.', 'Add the first code or import the list from Excel.')}</Empty> :
      <table className="tbl"><thead><tr><th>{L('الرمز', 'Code')}</th><th>{L('الاسم', 'Name')}</th>{(l.parent_key || l.hierarchical) && <th className="hidden md:table-cell">{L('الأب', 'Parent')}</th>}
        {l.attrs_schema.length > 0 && <th className="hidden xl:table-cell">{L('الخصائص', 'Properties')}</th>}<th className="hidden sm:table-cell">{L('الحالة', 'Status')}</th><th /></tr></thead>
        <tbody>{rows.map(r => <tr key={r.id} className={r.live ? '' : 'opacity-60'}>
          <td><span className="ltr inline-block font-semibold num whitespace-nowrap">{r.code}</span>{r.legacy_code && <div className="text-[11.5px] text-ink-3">{L('سابقاً', 'was')} {r.legacy_code}</div>}</td>
          <td><div className="font-medium">{lang === 'ar' ? r.ar : r.en}</div><div className="text-[12px] text-ink-3">{lang === 'ar' ? r.en : r.ar}</div></td>
          {(l.parent_key || l.hierarchical) && <td className="hidden md:table-cell text-[13px]">{parentOf(r.parent_id) ? <><span className="ltr inline-block">{parentOf(r.parent_id)!.code}</span> · {bi(parentOf(r.parent_id)!, lang)}</> : <span className="text-ink-3">—</span>}</td>}
          {l.attrs_schema.length > 0 && <td className="hidden xl:table-cell text-[12px] text-ink-2"><Attrs schema={l.attrs_schema} v={r.attrs} /></td>}
          <td className="hidden sm:table-cell"><div className="flex flex-wrap gap-1">
            {r.status === 'replaced' ? <Badge>{L('مستبدَل', 'Replaced')}</Badge> : !r.live ? <Badge>{L('معطّل', 'Inactive')}</Badge> : <Badge tone="brand">{L('فعّال', 'Active')}</Badge>}
            {r.inherited && <Badge tone="brass">{L('من المجموعة', 'Group')}</Badge>}
            {r.locked && <Badge tone="info"><Lock size={11} />{r.usage ? <span className="num">{r.usage}</span> : L('مستخدم', 'Used')}</Badge>}
            {r.shared && <Badge>{L('مشترك', 'Shared')}</Badge>}</div></td>
          <td className="text-end whitespace-nowrap">{!l.system && canChange && !r.shared && r.status !== 'replaced' && <RowMenu>
            {editable && <IconBtn title={L('تعديل', 'Edit')} onClick={() => setEdit(r)}><Pencil size={15} /></IconBtn>}
            {(editable || l.source) && <IconBtn title={r.live ? L('تعطيل', 'Deactivate') : L('إعادة تفعيل', 'Reactivate')} onClick={() => toggle(r)}>{r.live ? <Power size={15} /> : <RotateCcw size={15} />}</IconBtn>}
            {r.live && <IconBtn title={L('دمج في رمز آخر', 'Merge into another code')} onClick={() => setAct({ type: 'merge', row: r })}><GitMerge size={15} /></IconBtn>}
            {l.parent_key && r.live && <IconBtn title={L('نقل التبعية', 'Move under another parent')} onClick={() => setAct({ type: 'move', row: r })}><ArrowLeftRight size={15} /></IconBtn>}
          </RowMenu>}</td>
        </tr>)}</tbody></table>}
    {x.mappings.length > 0 && <div className="px-5 py-3 border-t border-line-2 text-[12.5px] text-ink-3">{L('جدول الربط: ', 'Mapping: ')}{x.mappings.map(m => `${m.old_code} → ${m.new_code} (${fmtDate(m.effective_on, lang)})`).join(' · ')}</div>}
    {edit && <ItemForm x={x} row={edit === 'new' ? null : edit} direct={d.direct} onClose={() => setEdit(null)} />}
    {act && <ActionModal x={x} type={act.type} row={act.row} onClose={() => setAct(null)} />}
    {def && <ListDefModal list={l} roles={d.roles} onClose={() => setDef(false)} />}
    {imp && <ImportModal list={l} onClose={() => setImp(false)} />}
  </Panel>
}

const RowMenu = ({ children }: { children: ReactNode }) => <div className="inline-flex flex-wrap justify-end gap-0.5 max-w-[72px] sm:max-w-none">{children}</div>
const IconBtn = ({ title, onClick, children }: { title: string; onClick: () => void; children: ReactNode }) =>
  <button className="p-1.5 rounded-lg hover:bg-sub text-ink-3 hover:text-ink" title={title} aria-label={title} onClick={onClick}>{children}</button>

function Attrs({ schema, v }: { schema: AttrField[]; v: Record<string, unknown> | null }) {
  const L = useL()
  const parts = schema.filter(f => v && v[f.key] !== undefined && v[f.key] !== null && v[f.key] !== '').map(f => {
    const val = v![f.key]
    const shown = f.type === 'bool' ? (val ? L('نعم', 'Yes') : L('لا', 'No')) : f.type === 'select' && OPTION_NAMES[String(val)] ? L(...OPTION_NAMES[String(val)]) : String(val)
    return `${L(f.ar, f.en)}: ${shown}`
  })
  return parts.length ? <>{parts.join(' · ')}</> : <span className="text-ink-3">—</span>
}

/** نموذج الرمز: حفظ مباشر في التأسيس، أو طلب تغيير بعد الاعتماد (مع السبب وتاريخ السريان) */
function ItemForm({ x, row, direct, onClose }: { x: ListDetail; row: CodeRow | null; direct: boolean; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const l = x.list
  const save = useSaveItem(); const req = useSubmitRequest()
  const [v, setV] = useState<Record<string, unknown>>({ code: row?.code ?? x.next ?? '', ar: row?.ar ?? '', en: row?.en ?? '', parent_id: row?.parent_id ?? null, attrs: { ...(row?.attrs ?? {}) },
    valid_from: row?.valid_from ?? '', valid_to: row?.valid_to ?? '', legacy_code: row?.legacy_code ?? '', description: row?.description ?? '' })
  const [reason, setReason] = useState(''); const [on, setOn] = useState(today())
  const [sug, setSug] = useState<Awaited<ReturnType<typeof suggestCode>> | null>(null)
  const ask = () => { const n = String(v.ar ?? '').trim(); if (n.length >= 2 && !l.source) suggestCode(l.key, n).then(setSug).catch(() => setSug(null)) }
  const m = direct ? save : req
  const err = m.error instanceof ApiError ? m.error : null
  const attrs = v.attrs as Record<string, unknown>
  const submit = () => {
    if (direct) save.mutate({ key: l.key, id: row?.id, v }, { onSuccess: () => { toast(L('حُفظ الرمز', 'Code saved')); onClose() } })
    else {
      const payload = row ? Object.fromEntries(Object.entries(v).filter(([k, val]) => JSON.stringify(val ?? '') !== JSON.stringify((({ code: row.code, ar: row.ar, en: row.en, parent_id: row.parent_id, attrs: row.attrs ?? {}, valid_from: row.valid_from ?? '', valid_to: row.valid_to ?? '', legacy_code: row.legacy_code ?? '', description: row.description ?? '' }) as Record<string, unknown>)[k] ?? ''))) : v
      req.mutate({ list: l.key, type: row ? 'edit' : 'add', item_id: row?.id, payload, reason, effective_on: on }, { onSuccess: () => { toast(L('أُرسل طلب التغيير لمالك القائمة', 'Change request sent to the list owner')); onClose() } })
    }
  }
  return <Modal open onClose={onClose} width={640} title={row ? L('تعديل الرمز', 'Edit code') : L('رمز جديد', 'New code')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={m.isPending} onClick={submit}>{direct ? L('حفظ', 'Save') : L('إرسال طلب التغيير', 'Send change request')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-4">
      <Field label={L('الرمز', 'Code')} req hint={row?.locked ? L('مستخدم في سجلات — الرمز مقفل، يمكن تعديل الاسم فقط.', 'In use — the code is locked; only the name can change.') : l.example ? `${L('مثال', 'e.g.')} ${l.example}` : undefined} error={err?.field('code')}>
        <input className="field ltr text-start num" disabled={row?.locked} value={String(v.code)} onChange={e => setV({ ...v, code: e.target.value.toUpperCase().replace(/\s/g, '') })} /></Field>
      <Field label={L('الرمز السابق', 'Legacy code')} hint={L('للبحث بالرمز القديم بعد الترحيل', 'Keeps the old code searchable')} error={err?.field('legacy_code')}><input className="field ltr text-start" value={String(v.legacy_code ?? '')} onChange={e => setV({ ...v, legacy_code: e.target.value })} /></Field>
      <Field label={L('الاسم بالعربية', 'Arabic name')} req error={err?.field('ar')}><input className="field" value={String(v.ar)} onChange={e => setV({ ...v, ar: e.target.value })} onBlur={ask} /></Field>
      <Field label={L('الاسم بالإنكليزية', 'English name')} req error={err?.field('en')}><input className="field ltr text-start" value={String(v.en)} onChange={e => setV({ ...v, en: e.target.value })} /></Field>
      {sug && (sug.en || sug.code || sug.duplicates.length > 0) && <div className="sm:col-span-2 rounded-xl bg-sub p-3 text-[13px] space-y-1.5">
        {(sug.en && sug.en !== v.en || (!row && sug.code && sug.code !== v.code)) && <div className="flex flex-wrap items-center gap-2">{L('اقتراح', 'Suggestion')}:
          {!row && sug.code && <button type="button" className="ltr rounded-md border border-line px-2 py-0.5 hover:border-brand" onClick={() => setV({ ...v, code: sug.code })}>{sug.code}</button>}
          {sug.en && <button type="button" className="ltr rounded-md border border-line px-2 py-0.5 hover:border-brand" onClick={() => setV({ ...v, en: sug.en })}>{sug.en}</button>}</div>}
        {sug.duplicates.filter(x => x.id !== row?.id).length > 0 && <div className="text-brass">{L('رموز مشابهة قد تكون تكراراً', 'Similar codes that may be duplicates')}: {sug.duplicates.filter(x => x.id !== row?.id).map(x => `${x.code} · ${lang === 'ar' ? x.ar : x.en}`).join('، ')}</div>}</div>}
      {(l.parent_key || l.hierarchical) && <Field label={L('الرمز الأب', 'Parent code')} className="sm:col-span-2" error={err?.field('parent_id')}>
        <select className="field" value={String(v.parent_id ?? '')} onChange={e => setV({ ...v, parent_id: e.target.value ? Number(e.target.value) : null })}><option value="">—</option>
          {x.parents.filter(p => p.live && p.id !== row?.id).map(p => <option key={p.id} value={p.id}>{p.code} · {bi(p, lang)}</option>)}</select></Field>}
      {l.attrs_schema.map(f => <AttrInput key={f.key} f={f} value={attrs[f.key]} error={err?.field(`attrs.${f.key}`)} onChange={val => setV({ ...v, attrs: { ...attrs, [f.key]: val } })} />)}
      <Field label={L('بداية السريان', 'Valid from')} error={err?.field('valid_from')}><input type="date" className="field" value={String(v.valid_from ?? '')} onChange={e => setV({ ...v, valid_from: e.target.value })} /></Field>
      <Field label={L('نهاية السريان', 'Valid to')} error={err?.field('valid_to')}><input type="date" className="field" value={String(v.valid_to ?? '')} onChange={e => setV({ ...v, valid_to: e.target.value })} /></Field>
      <Field label={L('الوصف', 'Description')} className="sm:col-span-2"><textarea className="field min-h-16" value={String(v.description ?? '')} onChange={e => setV({ ...v, description: e.target.value })} /></Field>
      {!direct && <>
        <Field label={L('سبب التغيير', 'Reason for the change')} req className="sm:col-span-2" error={err?.field('reason')}><textarea className="field min-h-16" value={reason} onChange={e => setReason(e.target.value)} /></Field>
        <Field label={L('تاريخ السريان', 'Effective date')} error={err?.field('effective_on')}><input type="date" className="field" min={today()} value={on} onChange={e => setOn(e.target.value)} /></Field>
      </>}
    </div>
    {err && !Object.keys(err.errors).length && <div role="alert" className="mt-4 rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2">{err.message}</div>}
  </Modal>
}

function AttrInput({ f, value, onChange, error }: { f: AttrField; value: unknown; onChange: (v: unknown) => void; error?: string }) {
  const L = useL(); const lang = useLang()
  const { data: list } = useCodeList(f.type === 'list' ? f.list ?? null : null)
  if (f.type === 'bool') return <Field label={L(f.ar, f.en)} div error={error}><Seg value={value ? 'y' : 'n'} onChange={x => onChange(x === 'y')} options={[{ v: 'y', label: L('نعم', 'Yes') }, { v: 'n', label: L('لا', 'No') }]} /></Field>
  if (f.type === 'select') return <Field label={L(f.ar, f.en)} error={error}><select className="field" value={String(value ?? '')} onChange={e => onChange(e.target.value || null)}><option value="">—</option>
    {(f.options ?? []).map(o => <option key={o} value={o}>{OPTION_NAMES[o] ? L(...OPTION_NAMES[o]) : o}</option>)}</select></Field>
  if (f.type === 'list') return <Field label={L(f.ar, f.en)} error={error}><select className="field" value={String(value ?? '')} onChange={e => onChange(e.target.value || null)}><option value="">—</option>
    {(list?.rows ?? []).filter(r => r.live).map(r => <option key={r.id} value={r.code}>{r.code} · {lang === 'ar' ? r.ar : r.en}</option>)}</select></Field>
  return <Field label={L(f.ar, f.en)} error={error}><input className="field num" type={f.type === 'number' ? 'number' : 'text'} value={String(value ?? '')} onChange={e => onChange(e.target.value)} /></Field>
}

/** تعطيل/إعادة تفعيل/دمج/نقل تبعية — بطلب تغيير مع تقرير الأثر والمحاكاة */
function ActionModal({ x, type, row, onClose }: { x: ListDetail; type: ChangeType; row: CodeRow; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const req = useSubmitRequest()
  const [reason, setReason] = useState(''); const [on, setOn] = useState(today())
  const [target, setTarget] = useState<number | null>(null); const [sim, setSim] = useState<Simulation | null>(null)
  const [parent, setParent] = useState<number | null>(row.parent_id)
  const err = req.error instanceof ApiError ? req.error : null
  useEffect(() => { setSim(null); if (type === 'merge' && target) simulate(x.list.key, row.id, target).then(setSim).catch(() => setSim(null)) }, [target, type, x.list.key, row.id])
  const targets = x.rows.filter(r => r.live && r.id !== row.id && !r.shared)
  const payload = type === 'merge' ? { target_id: target } : type === 'move' ? { parent_id: parent } : {}
  return <Modal open onClose={onClose} width={600} title={<>{L(...TYPE_NAMES[type])} <span className="ltr inline-block">{row.code}</span></>}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind={type === 'deactivate' || type === 'merge' ? 'danger' : 'primary'} disabled={req.isPending || (type === 'merge' && !target)}
      onClick={() => req.mutate({ list: x.list.key, type, item_id: row.id, payload, reason, effective_on: on }, { onSuccess: () => { toast(L('أُرسل الطلب للاعتماد', 'Request sent for approval')); onClose() } })}>{L('إرسال الطلب', 'Send request')}</Btn></>}>
    <div className="space-y-4">
      <div className="rounded-lg bg-sub px-3 py-2 text-[13.5px]"><b className="ltr inline-block">{row.code}</b> · {lang === 'ar' ? row.ar : row.en}
        {row.usage > 0 && <span className="text-ink-3"> — {L(`مرتبط بـ ${row.usage} سجل`, `linked to ${row.usage} record(s)`)}</span>}</div>
      {type === 'merge' && <>
        <Field label={L('يُدمج في', 'Merge into')} req error={err?.field('target_id')}><select className="field" value={target ?? ''} onChange={e => setTarget(e.target.value ? Number(e.target.value) : null)}><option value="">—</option>
          {targets.map(r => <option key={r.id} value={r.id}>{r.code} · {lang === 'ar' ? r.ar : r.en}</option>)}</select></Field>
        {sim && <div className="rounded-lg border border-line p-3 text-[13px]">
          <div className="font-semibold mb-1">{L('محاكاة قبل التنفيذ (لا يتغير شيء الآن):', 'Simulation before execution (nothing changes now):')}</div>
          {sim.records ? Object.entries(sim.tables).map(([t, n]) => <div key={t}>• {L(`ينتقل ${n} من ${TABLE_NAMES[t]?.[0] ?? t}`, `${n} ${TABLE_NAMES[t]?.[1] ?? t} move`)}</div>) : <div className="text-ink-3">{L('لا سجلات مرتبطة.', 'No linked records.')}</div>}
          <div className="text-ink-3 mt-1">{L('الرمز القديم يُعطَّل «مستبدَل» ويُحفظ في جدول الربط.', 'The old code becomes «replaced» and is kept in the mapping table.')}</div></div>}
      </>}
      {type === 'move' && <Field label={L('الأب الجديد', 'New parent')} error={err?.field('parent_id')}><select className="field" value={parent ?? ''} onChange={e => setParent(e.target.value ? Number(e.target.value) : null)}><option value="">—</option>
        {x.parents.filter(p => p.live).map(p => <option key={p.id} value={p.id}>{p.code} · {bi(p, lang)}</option>)}</select></Field>}
      <Field label={L('السبب', 'Reason')} req error={err?.field('reason')}><textarea className="field min-h-16" value={reason} onChange={e => setReason(e.target.value)} /></Field>
      <Field label={L('تاريخ السريان', 'Effective date')} error={err?.field('effective_on')}><input type="date" className="field" min={today()} value={on} onChange={e => setOn(e.target.value)} /></Field>
      {err && !Object.keys(err.errors).length && <div role="alert" className="rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2">{err.message}</div>}
    </div>
  </Modal>
}

function ListDefModal({ list, roles, onClose }: { list?: CodeListDef; roles: string[]; onClose: () => void }) {
  const L = useL()
  const save = useSaveList()
  const [v, setV] = useState({ key: '', ar: list?.ar ?? '', en: list?.en ?? '', owner_role: list?.owner_role ?? 'hr_director', pattern: list?.pattern ?? '', example: list?.example ?? '',
    required: list?.required ?? false, hierarchical: list?.hierarchical ?? false, active: list?.active ?? true, group: list?.group ?? 'org' })
  const err = save.error instanceof ApiError ? save.error : null
  return <Modal open onClose={onClose} title={list ? L('تعريف القائمة', 'List definition') : L('قائمة جديدة', 'New list')} width={600}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate({ key: list?.key, v: list ? { ...v, key: undefined } : v }, { onSuccess: () => { toast(L('حُفظت القائمة', 'List saved')); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-4">
      {!list && <Field label={L('مفتاح القائمة (لاتيني صغير)', 'List key (lowercase Latin)')} req className="sm:col-span-2" error={err?.field('key')}><input className="field ltr text-start" value={v.key} onChange={e => setV({ ...v, key: e.target.value.toLowerCase() })} placeholder="project_codes" /></Field>}
      <Field label={L('الاسم بالعربية', 'Arabic name')} req error={err?.field('ar')}><input className="field" value={v.ar} onChange={e => setV({ ...v, ar: e.target.value })} /></Field>
      <Field label={L('الاسم بالإنكليزية', 'English name')} req error={err?.field('en')}><input className="field ltr text-start" value={v.en} onChange={e => setV({ ...v, en: e.target.value })} /></Field>
      <Field label={L('مالك القائمة (يعتمد تغييراتها)', 'List owner (approves its changes)')}><select className="field" value={v.owner_role ?? ''} onChange={e => setV({ ...v, owner_role: e.target.value })}>
        {roles.filter(r => !['employee', 'manager', 'recruiter'].includes(r)).map(r => <option key={r} value={r}>{L(...(ROLE_NAMES[r] ?? [r, r]))}</option>)}</select></Field>
      {!list && <Field label={L('المجموعة', 'Group')}><select className="field" value={v.group} onChange={e => setV({ ...v, group: e.target.value as typeof v.group })}>
        {(['org', 'people', 'pay', 'ops', 'docs'] as const).map(g => <option key={g} value={g}>{L(...GROUPS[g])}</option>)}</select></Field>}
      <Field label={L('نمط الرمز (تعبير نمطي)', 'Code pattern (regular expression)')} hint={L('مثال: ^G[0-9]{2}$ — يطبق على الرموز الجديدة فقط', 'e.g. ^G[0-9]{2}$ — applies to new codes only')} error={err?.field('pattern')}>
        <input className="field ltr text-start" value={v.pattern ?? ''} onChange={e => setV({ ...v, pattern: e.target.value })} /></Field>
      <Field label={L('مثال على الرمز', 'Example code')} error={err?.field('example')}><input className="field ltr text-start" value={v.example ?? ''} onChange={e => setV({ ...v, example: e.target.value.toUpperCase() })} /></Field>
      <Field label={L('إلزامية لبوابة التأسيس', 'Required for go-live')} div><Seg value={v.required ? 'y' : 'n'} onChange={x => setV({ ...v, required: x === 'y' })} options={[{ v: 'y', label: L('نعم', 'Yes') }, { v: 'n', label: L('لا', 'No') }]} /></Field>
      {!list?.source && <Field label={L('هرمية (رمز أب من القائمة نفسها)', 'Hierarchical (parent in the same list)')} div><Seg value={v.hierarchical ? 'y' : 'n'} onChange={x => setV({ ...v, hierarchical: x === 'y' })} options={[{ v: 'y', label: L('نعم', 'Yes') }, { v: 'n', label: L('لا', 'No') }]} /></Field>}
      {list && !list.source && <Field label={L('الحالة', 'Status')} div><Seg value={v.active ? 'y' : 'n'} onChange={x => setV({ ...v, active: x === 'y' })} options={[{ v: 'y', label: L('فعّالة', 'Active') }, { v: 'n', label: L('موقوفة', 'Off') }]} /></Field>}
    </div>
    {err && !Object.keys(err.errors).length && <div role="alert" className="mt-4 rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2">{err.message}</div>}
  </Modal>
}

function ImportModal({ list, onClose }: { list: CodeListDef; onClose: () => void }) {
  const L = useL()
  const [file, setFile] = useState<File | null>(null)
  const [pre, setPre] = useState<Awaited<ReturnType<typeof importPreview>> | null>(null)
  const [busy, setBusy] = useState(false); const [error, setError] = useState('')
  const run = async (commit: boolean) => {
    if (!file) return
    setBusy(true); setError('')
    try {
      if (commit) { const r = await importCommit(list.key, file); toast(L(`استُورد ${r.imported} رمزاً`, `${r.imported} code(s) imported`)); onClose() }
      else setPre(await importPreview(list.key, file))
    } catch (e) { setError((e as Error).message) } finally { setBusy(false) }
  }
  return <Modal open onClose={onClose} width={680} title={<>{L('استيراد', 'Import')} — {L(list.ar, list.en)}</>}
    footer={<><Btn onClick={onClose}>{L('إغلاق', 'Close')}</Btn>{pre && pre.errors === 0 ? <Btn kind="primary" disabled={busy} onClick={() => run(true)}>{L(`حفظ ${pre.ok} رمزاً`, `Save ${pre.ok} code(s)`)}</Btn>
      : <Btn kind="primary" disabled={!file || busy} onClick={() => run(false)}>{L('معاينة', 'Preview')}</Btn>}</>}>
    <div className="space-y-3">
      <Btn size="sm" icon={<Download size={15} />} onClick={() => download(`/cs/lists/${list.key}/template`, `UHR-codes-${list.key}-template.xlsx`).catch(e => toast(e.message))}>{L('تنزيل القالب', 'Download template')}</Btn>
      <input type="file" accept=".xlsx,.csv" className="field" onChange={e => { setFile(e.target.files?.[0] ?? null); setPre(null) }} />
      <p className="text-[12.5px] text-ink-3">{L('يُحفظ الملف كاملاً أو لا شيء: يُفحص النمط والتكرار في الملف والدليل (شاملاً المعطّل) ووجود الرمز الأب.', 'All or nothing: the pattern, duplicates in the file and the codebook (including inactive) and the parent code are checked.')}</p>
      {error && <div role="alert" className="rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2">{error}</div>}
      {pre && <div className="text-[13px]">
        <div className="mb-2">{L(`${pre.ok} سطر صحيح · ${pre.errors} سطر فيه أخطاء`, `${pre.ok} valid · ${pre.errors} with errors`)}</div>
        {pre.rows.filter(r => Object.keys(r.errors).length).slice(0, 50).map(r => <div key={r.row} className="text-danger">{L('السطر', 'Row')} <span className="num">{r.row}</span>: {Object.values(r.errors).join(' · ')}</div>)}
      </div>}
    </div>
  </Modal>
}

// ———————————————— طلبات التغيير ————————————————

function Requests() {
  const L = useL(); const lang = useLang()
  const [status, setStatus] = useState<'open' | 'applied' | 'rejected' | 'all'>('open')
  const { data } = useRequests(status)
  const decide = useDecideRequest(); const cancel = useCancelRequest()
  const [reject, setReject] = useState<ChangeRequest | null>(null); const [note, setNote] = useState('')
  const tone = (s: ChangeRequest['status']) => s === 'applied' ? 'brand' : s === 'rejected' || s === 'failed' ? 'danger' : s === 'pending' ? 'brass' : s === 'approved' ? 'info' : 'neutral'
  const names: Record<ChangeRequest['status'], [string, string]> = { pending: ['بانتظار الاعتماد', 'Pending'], approved: ['معتمد — ينتظر تاريخ السريان', 'Approved — waiting for its date'], applied: ['مطبّق', 'Applied'],
    rejected: ['مرفوض', 'Rejected'], cancelled: ['ملغى', 'Cancelled'], failed: ['تعذّر تطبيقه', 'Could not be applied'] }
  return <div className="space-y-3">
    <Seg value={status} onChange={setStatus} options={[{ v: 'open', label: L('المفتوحة', 'Open') }, { v: 'applied', label: L('المطبّقة', 'Applied') }, { v: 'rejected', label: L('المرفوضة', 'Rejected') }, { v: 'all', label: L('الكل', 'All') }]} />
    {!data ? <Loader2 className="animate-spin text-brand" /> : data.length === 0 ? <Panel><Empty title={L('لا طلبات', 'No requests')}>{L('طلبات التغيير تظهر هنا بعد اعتماد الدليل.', 'Change requests appear here once the codebook is approved.')}</Empty></Panel> :
      data.map(r => <Panel key={r.id}>
        <div className="flex flex-wrap items-start gap-3">
          <div className="flex-1 min-w-[220px]">
            <div className="flex flex-wrap items-center gap-2"><Badge tone="info">{L(...TYPE_NAMES[r.type])}</Badge><b>{lang === 'ar' ? r.label_ar : r.label_en}</b><Badge tone={tone(r.status)}>{L(...names[r.status])}</Badge>{r.version && <Badge>{L('الإصدار', 'Version')} {r.version}</Badge>}</div>
            <p className="text-[13.5px] text-ink-2 mt-1.5">{r.reason}</p>
            <div className="text-[12.5px] text-ink-3 mt-1">{bi(r.requested_by ?? { ar: '—', en: '—' }, lang)} · {fmtDateTime(r.created_at, lang)} · {L('السريان', 'Effective')} {fmtDate(r.effective_on, lang)}
              {r.decided_by && <> · {L('القرار: ', 'Decided by: ')}{bi(r.decided_by, lang)}</>}</div>
            {r.impact && (r.impact.records > 0 || r.impact.simulation) && <div className="text-[12.5px] mt-1.5">{L('الأثر: ', 'Impact: ')}{Object.entries(r.impact.simulation?.tables ?? r.impact.usage).map(([t, n]) => L(`${n} ${TABLE_NAMES[t]?.[0] ?? t}`, `${n} ${TABLE_NAMES[t]?.[1] ?? t}`)).join('، ') || '—'}</div>}
            {r.note && <div className="text-[12.5px] mt-1 text-ink-2">{L('ملاحظة: ', 'Note: ')}{r.note}</div>}
          </div>
          <div className="flex gap-2">
            {r.can_decide && <><Btn size="sm" kind="primary" icon={<Check size={15} />} disabled={decide.isPending} onClick={() => decide.mutate({ id: r.id, approve: true }, { onSuccess: () => toast(L('اعتُمد الطلب', 'Request approved')), onError: e => toast((e as Error).message) })}>{L('اعتماد', 'Approve')}</Btn>
              <Btn size="sm" kind="danger" onClick={() => { setReject(r); setNote('') }}>{L('رفض', 'Reject')}</Btn></>}
            {r.can_cancel && <Btn size="sm" onClick={() => cancel.mutate(r.id, { onSuccess: () => toast(L('أُلغي الطلب', 'Request cancelled')) })}>{L('إلغاء الطلب', 'Cancel request')}</Btn>}
          </div>
        </div>
      </Panel>)}
    <Modal open={!!reject} onClose={() => setReject(null)} title={L('رفض طلب التغيير', 'Reject the change request')}
      footer={<><Btn onClick={() => setReject(null)}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="danger" disabled={note.trim().length < 5 || decide.isPending}
        onClick={() => reject && decide.mutate({ id: reject.id, approve: false, note }, { onSuccess: () => { toast(L('رُفض الطلب', 'Request rejected')); setReject(null) } })}>{L('تأكيد الرفض', 'Confirm rejection')}</Btn></>}>
      <Field label={L('سبب الرفض', 'Reason')} req><textarea className="field min-h-20" value={note} onChange={e => setNote(e.target.value)} /></Field>
    </Modal>
  </div>
}

// ———————————————— الإصدارات والمقارنة ————————————————

function Versions() {
  const L = useL(); const lang = useLang()
  const { data } = useVersions()
  const [a, setA] = useState<number | null>(null); const [b, setB] = useState<number | null>(null)
  useEffect(() => { if (data && data.length > 1 && a === null) { setA(data[data.length - 1].id); setB(data[0].id) } }, [data, a])
  const cmp = useCompare(a, b)
  if (!data) return <Loader2 className="animate-spin text-brand" />
  if (!data.length) return <Panel><Empty title={L('لا إصدارات بعد', 'No versions yet')}>{L('يُسجَّل الإصدار 1.0 عند اعتماد الدليل، ثم إصدار فرعي مع كل تغيير مطبّق.', 'Version 1.0 is recorded on approval, then a minor version with every applied change.')}</Empty></Panel>
  const label: Record<string, [string, string]> = { added: ['أُضيف', 'Added'], removed: ['حُذف من الفعّال', 'Removed'], changed: ['تغيّر', 'Changed'] }
  return <div className="grid gap-5 lg:grid-cols-[340px_1fr] items-start">
    <Panel pad={false} title={L('سجل الإصدارات', 'Version history')}>
      <ul className="divide-y divide-line-2">{data.map(v => <li key={v.id} className="px-4 py-2.5">
        <div className="flex items-center gap-2"><b className="num">{v.version}</b>{v.major && <Badge tone="brand">{L('اعتماد', 'Approval')}</Badge>}<span className="ms-auto text-[12px] text-ink-3">{fmtDate(v.approved_at, lang)}</span></div>
        <div className="text-[13px] text-ink-2">{v.summary}</div>{v.by && <div className="text-[12px] text-ink-3">{bi(v.by, lang)}</div>}</li>)}</ul>
    </Panel>
    <Panel title={L('مقارنة إصدارين', 'Compare two versions')}>
      <div className="flex flex-wrap gap-3 items-end">
        <Field label={L('من', 'From')}><select className="field" value={a ?? ''} onChange={e => setA(Number(e.target.value))}>{data.map(v => <option key={v.id} value={v.id}>{v.version}</option>)}</select></Field>
        <Field label={L('إلى', 'To')}><select className="field" value={b ?? ''} onChange={e => setB(Number(e.target.value))}>{data.map(v => <option key={v.id} value={v.id}>{v.version}</option>)}</select></Field>
      </div>
      <div className="mt-4 space-y-4">{!cmp.data ? (a === b || data.length < 2 ? <div className="text-[13px] text-ink-3">{L('اختر إصدارين مختلفين.', 'Pick two different versions.')}</div> : <Loader2 className="animate-spin text-brand" />)
        : cmp.data.lists.length === 0 ? <div className="text-[13px] text-ink-3">{L('لا فروق.', 'No differences.')}</div>
        : cmp.data.lists.map(x => <div key={x.key}><div className="font-semibold mb-1">{bi(x, lang)}</div>
          <ul className="text-[13px] space-y-0.5">{x.rows.map(r => <li key={r.code + r.change}><Badge tone={r.change === 'added' ? 'brand' : r.change === 'removed' ? 'danger' : 'brass'}>{L(...label[r.change])}</Badge> <span className="ltr inline-block font-semibold">{r.code}</span>
            {' '}{r.change === 'changed' ? `${lang === 'ar' ? r.from?.ar : r.from?.en} → ${lang === 'ar' ? r.to?.ar : r.to?.en}${r.fields?.includes('status') ? ` (${r.from?.status} → ${r.to?.status})` : ''}` : lang === 'ar' ? (r.to ?? r.from)?.ar : (r.to ?? r.from)?.en}</li>)}</ul></div>)}</div>
    </Panel>
  </div>
}

// ———————————————— الرموز المركّبة ————————————————

function Templates({ d }: { d: CodesOverview }) {
  const L = useL(); const lang = useLang()
  const save = useSaveCodesSettings()
  const [t, setT] = useState(() => Object.fromEntries(Object.entries(d.settings.templates).map(([k, x]) => [k, { template: x.template, start: x.start }])))
  const [co, setCo] = useState(d.settings.company_code)
  const [prev, setPrev] = useState<Record<string, string>>({})
  const err = save.error instanceof ApiError ? save.error : null
  useEffect(() => {
    const h = setTimeout(() => { for (const [k, x] of Object.entries(t)) previewCode(k, x.template, x.start).then(r => setPrev(p => ({ ...p, [k]: r.code }))).catch(e => setPrev(p => ({ ...p, [k]: `⚠ ${(e as Error).message}` }))) }, 350)
    return () => clearTimeout(h)
  }, [t])
  const { templates: _t, ...rest } = d.settings
  return <Panel title={L('قوالب الرموز المركّبة', 'Composite code templates')} sub={L('الرموز المتاحة: ', 'Tokens: ') + d.tokens.map(x => `{${x}}`).join(' ') + ' {SEQ4}'}
    action={d.can.settings && <Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate({ ...rest, company_code: co, templates: t }, { onSuccess: () => toast(L('حُفظت القوالب', 'Templates saved')) })}>{L('حفظ', 'Save')}</Btn>}>
    <p className="text-[13px] text-ink-2 mb-4">{L('{CO} رمز الشركة · {PREFIX} بادئة الرقم الوظيفي · {YY} السنة · {BR} الفرع · {DEPT} الإدارة · {SEQn} تسلسل بـ n خانات (عدّاد مستقل لكل سنة/فرع/إدارة في القالب). القالب الجديد يطبق على الأرقام الجديدة فقط.',
      '{CO} company code · {PREFIX} employee prefix · {YY} year · {BR} branch · {DEPT} department · {SEQn} n-digit sequence (separate counter per year/branch/department in the template). A new template applies to new numbers only.')}</p>
    <Field label={L('رمز الشركة {CO}', 'Company code {CO}')} hint={L('حرفان إلى خمسة أحرف لاتينية كبيرة، مثل UNQ', '2–5 capital Latin letters, e.g. UNQ')} className="max-w-xs mb-4" error={err?.field('company_code')}>
      <input className="field ltr text-start" disabled={!d.can.settings} value={co} onChange={e => setCo(e.target.value.toUpperCase().replace(/[^A-Z]/g, ''))} /></Field>
    <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(280px,1fr))]">{Object.entries(d.settings.templates).map(([k, x]) => <div key={k} className="rounded-xl border border-line p-3">
      <div className="font-semibold mb-2">{bi(x, lang)}</div>
      <div className="grid grid-cols-[1fr_96px] gap-2">
        <input className="field ltr text-start" disabled={!d.can.settings} value={t[k].template} onChange={e => setT({ ...t, [k]: { ...t[k], template: e.target.value.toUpperCase() } })} aria-label={L('القالب', 'Template')} />
        <input type="number" min={0} className="field num" disabled={!d.can.settings} value={t[k].start} onChange={e => setT({ ...t, [k]: { ...t[k], start: Number(e.target.value) } })} aria-label={L('يبدأ من', 'Starts at')} title={L('يبدأ من', 'Starts at')} />
      </div>
      {err?.field(`templates.${k}.template`) && <div className="text-[12px] text-danger mt-1">{err.field(`templates.${k}.template`)}</div>}
      <div className="mt-2 text-[13px]">{L('الرمز التالي: ', 'Next code: ')}<b className="ltr inline-block num">{prev[k] ?? '…'}</b></div>
      {t[k].template !== x.default && d.can.settings && <button className="text-[12px] text-brand mt-1" onClick={() => setT({ ...t, [k]: { ...t[k], template: x.default } })}>{L('القالب الافتراضي', 'Default template')} ({x.default})</button>}
    </div>)}</div>
  </Panel>
}

// ———————————————— التقارير ————————————————

function Reports() {
  const L = useL(); const lang = useLang()
  const { data } = useReports()
  if (!data) return <Loader2 className="animate-spin text-brand" />
  return <div className="grid gap-5 lg:grid-cols-2 items-start">
    <Panel pad={false} title={L(`رموز بلا استخدام منذ ${data.months} شهراً`, `Codes unused for ${data.months} months`)} sub={L('مرشحة للتعطيل في المراجعة السنوية', 'Candidates for deactivation in the yearly review')}>
      {data.unused.length === 0 ? <Empty title={L('لا شيء', 'Nothing')} /> : <ul className="divide-y divide-line-2 max-h-[480px] overflow-y-auto">{data.unused.map(u => <li key={`${u.list}-${u.id}`} className="px-4 py-2 text-[13px] flex gap-2">
        <span className="ltr inline-block font-semibold">{u.code}</span><span className="flex-1 min-w-0 truncate">{bi(u, lang)}</span><span className="text-ink-3">{lang === 'ar' ? u.list_ar : u.list_en}</span></li>)}</ul>}
    </Panel>
    <Panel pad={false} title={L('رموز مكررة بالاسم', 'Codes with the same name')}>
      {data.duplicates.length === 0 ? <Empty title={L('لا تكرار', 'No duplicates')} /> : <ul className="divide-y divide-line-2">{data.duplicates.map((x, i) => <li key={i} className="px-4 py-2 text-[13px]">
        <b>{x.name}</b> <span className="text-ink-3">· {lang === 'ar' ? x.list_ar : x.list_en}</span><div className="ltr text-start">{x.items.map(it => it.code).join(' · ')}</div></li>)}</ul>}
    </Panel>
  </div>
}

// ———————————————— الإعدادات ————————————————

function SettingsModal({ d, onClose }: { d: CodesOverview; onClose: () => void }) {
  const L = useL()
  const save = useSaveCodesSettings()
  const tpl = useMemo(() => Object.fromEntries(Object.entries(d.settings.templates).map(([k, x]) => [k, { template: x.template, start: x.start }])), [d.settings.templates])
  const { templates: _t, ...init } = d.settings
  const [v, setV] = useState(init)
  const err = save.error instanceof ApiError ? save.error : null
  const yes = (k: keyof typeof v, ar: string, en: string, hint?: string) => <Field label={L(ar, en)} div hint={hint}><Seg value={v[k] ? 'y' : 'n'} onChange={x => setV({ ...v, [k]: x === 'y' })} options={[{ v: 'y', label: L('نعم', 'Yes') }, { v: 'n', label: L('لا', 'No') }]} /></Field>
  const roles = d.roles.filter(r => !['employee', 'recruiter'].includes(r))
  return <Modal open onClose={onClose} width={640} title={L('إعدادات دليل الترميز', 'Codebook settings')}
    footer={<><Btn onClick={() => { const { templates: _x, ...def } = d.defaults; setV({ ...def, company_code: v.company_code }) }}>{L('القيم الافتراضية', 'Defaults')}</Btn><span className="flex-1" />
      <Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate({ ...v, templates: tpl }, { onSuccess: () => { toast(L('حُفظت الإعدادات', 'Settings saved')); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-4">
      {yes('gate', 'بوابة التأسيس: منع إنشاء الموظفين قبل الاعتماد', 'Go-live gate: no employees before approval')}
      {yes('change_requests', 'بعد الاعتماد: كل تغيير بطلب تغيير', 'After approval: every change by request', L('إن أُوقفت يُعدَّل الدليل مباشرة مع تسجيل إصدار', 'If off, edits apply directly and still record a version'))}
      {yes('self_approve', 'يجوز لمقدّم الطلب اعتماده بنفسه', 'The requester may approve their own request')}
      {yes('admin_approves', 'مدير النظام يعتمد أي طلب', 'The system admin may approve any request')}
      <Field label={L('من يعتمد الدليل كاملاً', 'Who approves the whole codebook')} div className="sm:col-span-2"><div className="flex flex-wrap gap-2">{roles.map(r => {
        const on = v.approver_roles.includes(r)
        return <button key={r} type="button" onClick={() => setV({ ...v, approver_roles: on ? v.approver_roles.filter(x => x !== r) : [...v.approver_roles, r] })}
          className={`h-8 px-3 rounded-full border text-[13px] ${on ? 'bg-brand text-on-brand border-brand' : 'border-line hover:bg-sub'}`}>{L(...(ROLE_NAMES[r] ?? [r, r]))}</button>
      })}</div>{err?.field('approver_roles') && <div className="text-[12px] text-danger mt-1">{err.field('approver_roles')}</div>}</Field>
      <Field label={L('من يعتمد الدمج ونقل التبعية', 'Who approves merges and moves')}><select className="field" value={v.restructure_role} onChange={e => setV({ ...v, restructure_role: e.target.value })}>
        {roles.map(r => <option key={r} value={r}>{L(...(ROLE_NAMES[r] ?? [r, r]))}</option>)}</select></Field>
      <Field label={L('تقرير الرموز غير المستخدمة بعد (شهر)', 'Unused-codes report after (months)')} error={err?.field('unused_months')}><input type="number" min={1} max={60} className="field num" value={v.unused_months} onChange={e => setV({ ...v, unused_months: Number(e.target.value) })} /></Field>
    </div>
    {err && !Object.keys(err.errors).length && <div role="alert" className="mt-4 rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2">{err.message}</div>}
  </Modal>
}

/** FR-CS-019: سجل ترميز الوثائق — النماذج والسياسات برموزها وإصداراتها وملفاتها والرمز السابق */
function DocRegister() {
  const L = useL(); const lang = useLang()
  const [q, setQ] = useState('')
  const { data } = useDocRegister(q)
  return <Panel pad={false} title={L('سجل ترميز الوثائق', 'Document code register')} sub={L('كل نموذج وسياسة برمزها المطبوع وإصدارها وتاريخها ورمزها السابق.', 'Every form and policy with its printed code, issue, date and legacy code.')}
    action={<input className="field !w-56" placeholder={L('بحث بالرمز أو الاسم…', 'Search by code or name…')} value={q} onChange={e => setQ(e.target.value)} />}>
    {!data ? <div className="grid place-items-center py-10"><Loader2 className="animate-spin text-brand" /></div> : data.length === 0 ? <div className="p-4 text-[13px] text-ink-3">{L('لا نتائج.', 'No results.')}</div>
      : <table className="tbl"><thead><tr><th>{L('الرمز', 'Code')}</th><th>{L('الوثيقة', 'Document')}</th><th className="hidden md:table-cell">{L('الإصدار', 'Issue')}</th><th className="hidden md:table-cell">{L('التاريخ', 'Date')}</th><th className="hidden sm:table-cell">{L('الرمز السابق', 'Legacy')}</th><th /></tr></thead>
        <tbody>{data.map(r => <tr key={`${r.kind}-${r.id}`}><td><div className="ltr inline-block font-semibold num">{r.ref ?? r.code}</div><div className="text-[11.5px] text-ink-3">{r.kind === 'form' ? L('نموذج', 'Form') : L('سياسة', 'Policy')}{r.sensitive ? ` · ${L('حساس', 'Sensitive')}` : ''}</div></td>
          <td>{lang === 'ar' ? r.ar : r.en}{r.versions ? <span className="text-[11.5px] text-ink-3"> · {L(`${r.versions} إصدار`, `${r.versions} version(s)`)}</span> : null}</td><td className="hidden md:table-cell num">{r.issue ?? '—'}</td>
          <td className="hidden md:table-cell num">{r.date ?? '—'}</td><td className="hidden sm:table-cell ltr">{r.legacy_code ?? '—'}</td>
          <td>{r.file_id ? <a className="text-brand text-[12.5px]" href={`/api/files/${r.file_id}`} target="_blank" rel="noreferrer">{L('الملف', 'File')}</a> : null}</td></tr>)}</tbody></table>}
  </Panel>
}
