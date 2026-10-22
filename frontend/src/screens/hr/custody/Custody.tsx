import { useEffect, useState, type ReactNode } from 'react'
import { Download, FileSpreadsheet, Loader2, Pencil, Plus, Search, Wrench, X } from 'lucide-react'
import { useL, useLang, bi, fmtDate, fmtDateTime } from '../../../lib/store'
import { ApiError } from '../../../lib/api'
import { setQuery, useRoute } from '../../../lib/router'
import { downloadCustody, useAssetAction, useAssetHistory, useCustody, useSaveAsset, type AssetInput, type AssetRow, type AssetStatus, type Condition, type CustodyPage } from '../../../lib/custody'
import { Badge, Btn, Drawer, Empty, Field, Modal, PageHead, Panel, Seg, toast } from '../../../ui/kit'
import { DecisionDialog } from '../../../ui/decision'
import { ImportAssets } from './ImportAssets'

export function AssetStatusBadge({ s }: { s: AssetStatus }) {
  const L = useL()
  return s === 'assigned' ? <Badge tone="brand">{L('مسلّمة وموقّعة', 'Signed')}</Badge> : s === 'pending_ack' ? <Badge tone="brass">{L('بانتظار التوقيع', 'Awaiting signature')}</Badge>
    : s === 'maintenance' ? <Badge tone="warn">{L('في الصيانة', 'In maintenance')}</Badge> : s === 'retired' ? <Badge tone="danger">{L('مستبعد', 'Retired')}</Badge> : <Badge>{L('في المستودع', 'In stock')}</Badge>
}

const money = (n: number) => '$' + Math.round(n).toLocaleString('en-US')

/**
 * العهد M10 (Custody في الديمو): سطر «N أصلاً مسلّماً بقيمة $X — M في المستودع»، بحث وتصفية، تسليم (بانتظار توقيع الموظف) واسترجاع بحالة الأصل،
 * الصيانة والاستبعاد، سجل حركة لكل أصل، تصدير Excel، واستيراد العهد من Excel مع تقرير الأخطاء (D-13).
 */
export function Custody() {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const f = { q: route.query.q ?? '', status: route.query.status ?? 'all', category: route.query.category ?? '' }
  const [q, setQ] = useState(f.q)
  useEffect(() => { const t = setTimeout(() => q !== f.q && setQuery({ ...route.query, q: q || undefined }), 300); return () => clearTimeout(t) }, [q])   // eslint-disable-line react-hooks/exhaustive-deps
  const { data: d, isError } = useCustody(f)
  const [issue, setIssue] = useState<AssetRow | null>(null)
  const [back, setBack] = useState<AssetRow | null>(null)
  const [form, setForm] = useState<AssetRow | 'new' | null>(null)
  const [imp, setImp] = useState(false)
  const open = route.query.asset ? Number(route.query.asset) : null
  const set = (k: string, v: string) => setQuery({ ...route.query, [k]: v && v !== 'all' ? v : undefined })
  const s = d?.summary
  return <div className="max-w-[1200px] mx-auto">
    <PageHead title={L('العهد', 'Custody')}
      sub={s ? L(`${s.issued} أصلاً مسلّماً بقيمة ${money(s.issued_value)} — ${s.available} في المستودع${s.pending ? ` · ${s.pending} بانتظار التوقيع` : ''}${s.maintenance ? ` · ${s.maintenance} في الصيانة` : ''}`,
        `${s.issued} assets issued worth ${money(s.issued_value)} — ${s.available} in stock${s.pending ? ` · ${s.pending} awaiting signature` : ''}${s.maintenance ? ` · ${s.maintenance} in maintenance` : ''}`) : undefined}
      actions={d && <div className="flex flex-wrap gap-2">
        {d.can.create && <Btn icon={<FileSpreadsheet size={16} />} onClick={() => setImp(true)}>{L('استيراد من Excel', 'Import from Excel')}</Btn>}
        {d.can.export && <Btn icon={<Download size={16} />} onClick={() => downloadCustody(f).catch(() => toast(L('تعذّر التصدير', 'Export failed')))}>Excel</Btn>}
        {d.can.create && <Btn kind="primary" icon={<Plus size={16} />} onClick={() => setForm('new')}>{L('إضافة أصل', 'Add asset')}</Btn>}
      </div>} />
    <div className="flex flex-wrap gap-2 mb-4">
      <label className="relative flex-1 min-w-[220px] sm:max-w-xs"><Search size={15} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" />
        <input className="field !ps-9" placeholder={L('بحث بالرمز أو الاسم أو الحائز', 'Search tag, name or holder')} value={q} onChange={e => setQ(e.target.value)} /></label>
      <select className="field !w-auto" value={f.status} onChange={e => set('status', e.target.value)} aria-label={L('الحالة', 'Status')}>
        <option value="all">{L('كل الحالات', 'All')}</option><option value="assigned">{L('مسلّمة', 'Issued')}</option><option value="pending_ack">{L('بانتظار التوقيع', 'Awaiting signature')}</option>
        <option value="available">{L('في المستودع', 'In stock')}</option><option value="maintenance">{L('في الصيانة', 'In maintenance')}</option><option value="retired">{L('مستبعد', 'Retired')}</option></select>
      <select className="field !w-auto" value={f.category} onChange={e => set('category', e.target.value)} aria-label={L('الفئة', 'Category')}>
        <option value="">{L('كل الفئات', 'All categories')}</option>{d?.categories.map(c => <option key={c.id} value={c.id}>{bi(c, lang)}</option>)}</select>
    </div>
    <Panel pad={false}>{!d ? <Empty title={isError ? L('تعذّر التحميل', 'Could not load') : L('جارٍ التحميل…', 'Loading…')} /> : d.data.length === 0 ? <Empty title={L('لا توجد أصول مطابقة', 'No matching assets')} /> :
      <table className="tbl"><thead><tr><th className="hidden sm:table-cell">{L('الرمز', 'Tag')}</th><th>{L('الأصل', 'Asset')}</th><th className="hidden md:table-cell">{L('الفئة', 'Category')}</th>
        <th className="hidden lg:table-cell">{L('الرقم التسلسلي', 'Serial')}</th><th className="hidden sm:table-cell">{L('القيمة', 'Value')}</th><th className="hidden sm:table-cell">{L('بعهدة', 'Holder')}</th><th>{L('الحالة', 'Status')}</th><th /></tr></thead>
        <tbody>{d.data.map(a => <tr key={a.id} className="cursor-pointer hover:bg-sub" onClick={() => setQuery({ ...route.query, asset: String(a.id) })}>
          <td className="ltr num whitespace-nowrap hidden sm:table-cell">{a.tag}</td>
          <td><div>{bi(a.name, lang)}</div><div className="sm:hidden text-[12px] text-ink-3"><span className="ltr inline-block num">{a.tag}</span>{a.holder ? <> · {bi(a.holder, lang)}</> : ''}</div></td>
          <td className="text-ink-2 hidden md:table-cell">{bi(a.category, lang)}</td>
          <td className="ltr num text-ink-3 text-[13px] hidden lg:table-cell">{a.serial ?? '—'}</td>
          <td className="num hidden sm:table-cell">{money(a.value)}</td>
          <td className="hidden sm:table-cell">{a.holder ? <a className="hover:underline" href={`/hr/employees/${a.holder.id}`} onClick={e => e.stopPropagation()}>{bi(a.holder, lang)}</a> : '—'}</td>
          <td><AssetStatusBadge s={a.status} /></td>
          <td className="text-end" onClick={e => e.stopPropagation()}>{d.can.edit && (a.status === 'available' ? <Btn size="sm" onClick={() => setIssue(a)}>{L('تسليم', 'Issue')}</Btn>
            : a.status === 'assigned' || a.status === 'pending_ack' ? <Btn size="sm" kind="ghost" onClick={() => setBack(a)}>{a.status === 'pending_ack' ? L('إلغاء التسليم', 'Cancel issue') : L('استرجاع', 'Return')}</Btn> : null)}</td>
        </tr>)}</tbody></table>}</Panel>
    {d && issue && <IssueModal a={issue} d={d} onClose={() => setIssue(null)} />}
    {back && <ReturnModal a={back} onClose={() => setBack(null)} />}
    {d && form && <AssetForm a={form === 'new' ? null : form} d={d} onClose={() => setForm(null)} />}
    {imp && <ImportAssets onClose={() => setImp(false)} />}
    {d && open && <AssetDrawer id={open} d={d} onClose={() => setQuery({ ...route.query, asset: undefined })} onEdit={a => setForm(a)} onIssue={setIssue} onReturn={setBack} />}
  </div>
}

function IssueModal({ a, d, onClose }: { a: AssetRow; d: CustodyPage; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const act = useAssetAction()
  const [to, setTo] = useState(''); const [note, setNote] = useState('')
  return <Modal open onClose={onClose} title={L('تسليم عهدة', 'Issue asset')} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" disabled={!to || act.isPending} onClick={() => act.mutateAsync({ id: a.id, action: 'issue', body: { employee_id: Number(to), note: note || null } })
      .then(() => { toast(L('سُلّمت — بانتظار توقيع الموظف', 'Issued — awaiting signature')); onClose() }).catch(e => toast(e instanceof ApiError ? e.message : L('تعذّر التسليم', 'Could not issue')))}>{L('تسليم', 'Issue')}</Btn></>}>
    <p className="text-[14px] mb-3"><b>{bi(a.name, lang)}</b> <span className="ltr inline-block text-ink-3">{a.tag}</span></p>
    <div className="space-y-3">
      <Field label={L('الموظف المستلم', 'Recipient')}><select className="field" value={to} onChange={e => setTo(e.target.value)}><option value="">—</option>
        {d.employees.map(e => <option key={e.id} value={e.id}>{bi(e, lang)} — {e.code}</option>)}</select></Field>
      <Field label={L('ملاحظة (اختيارية)', 'Note (optional)')}><input className="field" value={note} maxLength={500} onChange={e => setNote(e.target.value)} /></Field>
      <p className="text-[13px] text-ink-3">{L('يصل للموظف طلب توقيع استلام إلكتروني، ولا تُعتبر العهدة مسلّمة حتى يوقّع.', 'The employee receives an e-signature request; the asset counts as issued only after signing.')}</p>
    </div>
  </Modal>
}

function ReturnModal({ a, onClose }: { a: AssetRow; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const act = useAssetAction()
  const [c, setC] = useState<Condition>('good'); const [note, setNote] = useState('')
  const signed = a.status === 'assigned'
  return <Modal open onClose={onClose} title={signed ? L('استرجاع عهدة', 'Return asset') : L('إلغاء التسليم', 'Cancel the issue')} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" disabled={act.isPending} onClick={() => act.mutateAsync({ id: a.id, action: 'return', body: { condition: c, note: note || null } })
      .then(() => { toast(c === 'damaged' ? L('استُرجعت وأُحيلت للصيانة', 'Returned and sent to maintenance') : L('أُعيدت للمستودع', 'Returned to stock')); onClose() })
      .catch(e => toast(e instanceof ApiError ? e.message : L('تعذّر الاسترجاع', 'Could not return')))}>{L('تأكيد', 'Confirm')}</Btn></>}>
    <p className="text-[14px] mb-3"><b>{bi(a.name, lang)}</b> <span className="ltr inline-block text-ink-3">{a.tag}</span> — {a.holder ? bi(a.holder, lang) : ''}</p>
    <div className="space-y-3">
      <Field div label={L('حالة الأصل عند الاستلام', 'Condition on return')}>
        <Seg value={c} onChange={setC} options={[{ v: 'good', label: L('سليمة', 'Good') }, { v: 'fair', label: L('مقبولة', 'Fair') }, { v: 'damaged', label: L('تالفة', 'Damaged') }]} /></Field>
      {c === 'damaged' && <p className="text-[13px] text-warn">{L('الأصل التالف يذهب للصيانة ولا يُسلَّم حتى يُعاد للمستودع.', 'A damaged asset goes to maintenance and cannot be issued until it is back in stock.')}</p>}
      <Field label={L('ملاحظة', 'Note')}><textarea className="field" value={note} maxLength={500} onChange={e => setNote(e.target.value)} /></Field>
    </div>
  </Modal>
}

function AssetForm({ a, d, onClose }: { a: AssetRow | null; d: CustodyPage; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const save = useSaveAsset(a?.id ?? null)
  const [v, setV] = useState<AssetInput>(a ? { name_ar: a.name_ar, name_en: a.name_en, asset_category_id: a.category.id, serial: a.serial ?? '', value: a.value, condition: a.condition, purchased_at: a.purchased_at ?? '', notes: a.notes ?? '' }
    : { name_ar: '', name_en: '', asset_category_id: '', serial: '', value: '', condition: 'good', purchased_at: '', notes: '' })
  const err = save.error instanceof ApiError ? save.error : null
  const fe = (k: string) => err?.field(k)
  return <Modal open onClose={onClose} title={a ? L('تعديل أصل', 'Edit asset') : L('إضافة أصل', 'Add asset')} width={620} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate(v, { onSuccess: r => { toast(a ? L('حُفظ', 'Saved') : L(`أُضيف ${r.tag}`, `${r.tag} added`)); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('اسم الأصل', 'Asset name')} req error={fe('name_ar')}><input className="field" value={v.name_ar} onChange={e => setV({ ...v, name_ar: e.target.value })} /></Field>
      <Field label={L('الاسم بالإنكليزية', 'English name')} req error={fe('name_en')}><input className="field ltr" value={v.name_en} onChange={e => setV({ ...v, name_en: e.target.value })} /></Field>
      <Field label={L('الفئة', 'Category')} req error={fe('asset_category_id')}><select className="field" value={v.asset_category_id} onChange={e => setV({ ...v, asset_category_id: e.target.value ? Number(e.target.value) : '' })}>
        <option value="">—</option>{d.categories.map(c => <option key={c.id} value={c.id}>{bi(c, lang)}</option>)}</select></Field>
      <Field label={L('الرقم التسلسلي', 'Serial number')} error={fe('serial')}><input className="field ltr" value={v.serial} onChange={e => setV({ ...v, serial: e.target.value })} /></Field>
      <Field label={L('القيمة التقديرية ($)', 'Estimated value ($)')} req error={fe('value')}><input type="number" min={0} className="field num" value={v.value} onChange={e => setV({ ...v, value: e.target.value === '' ? '' : Number(e.target.value) })} /></Field>
      <Field label={L('تاريخ الشراء', 'Purchase date')} error={fe('purchased_at')}><input type="date" className="field" value={v.purchased_at} onChange={e => setV({ ...v, purchased_at: e.target.value })} /></Field>
      <Field div label={L('الحالة الفنية', 'Condition')} className="sm:col-span-2"><Seg value={v.condition} onChange={x => setV({ ...v, condition: x })} options={[{ v: 'good', label: L('سليمة', 'Good') }, { v: 'fair', label: L('مقبولة', 'Fair') }, { v: 'damaged', label: L('تالفة', 'Damaged') }]} /></Field>
      <Field label={L('ملاحظات', 'Notes')} className="sm:col-span-2"><textarea className="field" value={v.notes} maxLength={1000} onChange={e => setV({ ...v, notes: e.target.value })} /></Field>
    </div>
    {err && !Object.keys(err.errors).length && <p role="alert" className="mt-3 text-[13px] text-danger">{err.message}</p>}
  </Modal>
}

function AssetDrawer({ id, d, onClose, onEdit, onIssue, onReturn }: { id: number; d: CustodyPage; onClose: () => void; onEdit: (a: AssetRow) => void; onIssue: (a: AssetRow) => void; onReturn: (a: AssetRow) => void }) {
  const L = useL(); const lang = useLang()
  const q = useAssetHistory(id); const act = useAssetAction()
  const [retire, setRetire] = useState(false)
  const a = q.data?.data
  const label: Record<string, string> = { created: L('أُضيف للمستودع', 'Added to stock'), imported: L('استيراد', 'Imported'), issued: L('تسليم — بانتظار التوقيع', 'Issued — awaiting signature'),
    signed: L('وقّع الموظف الاستلام', 'Receipt signed'), returned: L('استرجاع', 'Returned'), cancelled: L('إلغاء التسليم', 'Issue cancelled'), maintenance: L('إلى الصيانة', 'To maintenance'),
    available: L('عاد للمستودع', 'Back in stock'), retired: L('استبعاد', 'Retired'), edited: L('تعديل البيانات', 'Details edited') }
  const cond = (c: Condition | null) => c === 'damaged' ? L('تالفة', 'damaged') : c === 'fair' ? L('مقبولة', 'fair') : c === 'good' ? L('سليمة', 'good') : ''
  const kv = (k: string, v: ReactNode) => <div className="flex justify-between gap-3 py-1.5 border-b border-line-2 text-[13.5px]"><span className="text-ink-3">{k}</span><span className="text-end">{v}</span></div>
  return <Drawer open onClose={onClose} width={520}>
    <header className="flex items-center justify-between gap-3 px-5 h-14 border-b border-line-2"><h3 className="font-display font-bold text-[15px] truncate">{a ? bi(a.name, lang) : '…'}</h3>
      <button className="p-1.5 rounded-lg hover:bg-sub" onClick={onClose} aria-label={L('إغلاق', 'Close')}><X size={18} /></button></header>
    <div className="flex-1 overflow-y-auto p-5 space-y-5">{!a ? <div className="grid place-items-center py-16"><Loader2 className="animate-spin text-brand" /></div> : <>
      <div>
        {kv(L('الرمز', 'Tag'), <span className="ltr inline-block num">{a.tag}</span>)}{kv(L('الفئة', 'Category'), bi(a.category, lang))}
        {kv(L('الرقم التسلسلي', 'Serial'), <span className="ltr inline-block">{a.serial ?? '—'}</span>)}{kv(L('القيمة', 'Value'), <span className="num">{money(a.value)}</span>)}
        {kv(L('الحالة الفنية', 'Condition'), cond(a.condition))}{kv(L('تاريخ الشراء', 'Purchased'), a.purchased_at ? fmtDate(a.purchased_at, lang) : '—')}
        {kv(L('الحالة', 'Status'), <AssetStatusBadge s={a.status} />)}
        {kv(L('بعهدة', 'Holder'), a.holder ? <a className="text-brand hover:underline" href={`/hr/employees/${a.holder.id}`}>{bi(a.holder, lang)}</a> : '—')}
        {a.notes && <p className="mt-2 text-[13px] text-ink-2">{a.notes}</p>}
      </div>
      {d.can.edit && <div className="flex flex-wrap gap-2">
        <Btn size="sm" icon={<Pencil size={14} />} onClick={() => onEdit(a)}>{L('تعديل', 'Edit')}</Btn>
        {a.status === 'available' && <Btn size="sm" kind="primary" onClick={() => onIssue(a)}>{L('تسليم', 'Issue')}</Btn>}
        {(a.status === 'assigned' || a.status === 'pending_ack') && <Btn size="sm" onClick={() => onReturn(a)}>{a.status === 'pending_ack' ? L('إلغاء التسليم', 'Cancel issue') : L('استرجاع', 'Return')}</Btn>}
        {a.status === 'available' && <Btn size="sm" icon={<Wrench size={14} />} onClick={() => act.mutateAsync({ id: a.id, action: 'status', body: { status: 'maintenance' } }).then(() => q.refetch())}>{L('إلى الصيانة', 'To maintenance')}</Btn>}
        {a.status === 'maintenance' && <Btn size="sm" onClick={() => act.mutateAsync({ id: a.id, action: 'status', body: { status: 'available' } }).then(() => q.refetch())}>{L('عاد للمستودع', 'Back in stock')}</Btn>}
        {(a.status === 'available' || a.status === 'maintenance') && <Btn size="sm" kind="danger" onClick={() => setRetire(true)}>{L('استبعاد', 'Retire')}</Btn>}
      </div>}
      <div><h4 className="font-semibold text-[14px] mb-2">{L('سجل الحركة', 'Movement history')}</h4>
        <ol className="relative border-s border-line ms-2 space-y-3">{q.data!.history.map((m, i) => <li key={i} className="ms-4">
          <span className={`absolute -start-[5px] mt-1.5 size-2.5 rounded-full ${m.action === 'signed' ? 'bg-brand' : m.action === 'retired' || m.condition === 'damaged' ? 'bg-danger' : 'bg-ink-3'}`} />
          <div className="text-[13.5px] font-medium">{label[m.action] ?? m.action}{m.employee ? <> — {bi(m.employee, lang)}</> : null}{m.condition && m.action !== 'created' ? <span className="text-ink-3"> · {cond(m.condition)}</span> : null}</div>
          <div className="text-[12px] text-ink-3 num">{fmtDateTime(m.at, lang)}{m.by ? ` · ${bi(m.by, lang)}` : ''}</div>
          {m.note && <div className="text-[12.5px] text-ink-2">«{m.note}»</div>}</li>)}</ol></div>
    </>}</div>
    {a && <DecisionDialog open={retire} onClose={() => setRetire(false)} tone="danger" title={L('استبعاد الأصل', 'Retire the asset')} subject={<>{bi(a.name, lang)} <span className="ltr inline-block">{a.tag}</span></>}
      confirmLabel={L('تأكيد الاستبعاد', 'Confirm')} reasonRequired minLength={5}
      onConfirm={note => act.mutateAsync({ id: a.id, action: 'status', body: { status: 'retired', note } }).then(() => { toast(L('استُبعد الأصل', 'Asset retired')); q.refetch() })} />}
  </Drawer>
}
