import { useRef, useState } from 'react'
import { Archive, Download, Eye, FilePlus2, History, Loader2, ShieldAlert, Undo2, Upload } from 'lucide-react'
import { useL, useLang, bi, fmtDate, fmtDateTime } from '../../../lib/store'
import { ApiError } from '../../../lib/api'
import { fileUrl } from '../../../lib/console'
import { useArchiveDoc, useDocHistory, useDocSettings, useUploadDoc, type EmpDoc, type EmpFile } from '../../../lib/employees'
import { Badge, Btn, Empty, Field, Modal, Panel, Seg, toast } from '../../../ui/kit'

/** تبويب «الملفات الإضافية» (SRS §10.11، D-25): النوع، الانتهاء، الحساسية، السجل، الأرشفة بلا حذف */
export function DocumentsTab({ e }: { e: EmpFile }) {
  const L = useL(); const lang = useLang()
  const [up, setUp] = useState(false); const [hist, setHist] = useState<EmpDoc | null>(null)
  const [showArchived, setShowArchived] = useState(false)
  const archive = useArchiveDoc(e.id)
  const list = e.documents.filter(d => showArchived || d.status !== 'archived')
  const archived = e.documents.filter(d => d.status === 'archived').length
  const state = (d: EmpDoc) => d.status === 'archived' ? <Badge>{L('مؤرشف', 'Archived')}</Badge> : d.status === 'expired' ? <Badge tone="danger">{L('منتهي', 'Expired')}</Badge>
    : d.expiring ? <Badge tone="warn">{L('قارب على الانتهاء', 'Expiring soon')}</Badge> : <Badge tone="brand">{L('ساري', 'Valid')}</Badge>
  const viewable = (d: EmpDoc) => /^(image\/|application\/pdf)/.test(d.file.mime ?? '')

  return <Panel pad={false} title={L('الملفات الإضافية', 'Documents')} sub={L('كل مشاهدة وتنزيل تُسجَّل باسم صاحبها.', 'Every view and download is logged by name.')}
    action={<div className="flex flex-wrap items-center gap-2">
      {archived > 0 && <label className="text-[12.5px] text-ink-3 inline-flex items-center gap-1.5"><input type="checkbox" checked={showArchived} onChange={v => setShowArchived(v.target.checked)} />{L(`عرض المؤرشف (${archived})`, `Show archived (${archived})`)}</label>}
      {e.can.edit && <Btn size="sm" kind="primary" icon={<FilePlus2 size={14} />} onClick={() => setUp(true)}>{L('رفع مستند', 'Upload document')}</Btn>}</div>}>
    {list.length === 0 ? <Empty title={L('لا توجد مستندات', 'No documents')}>{L('الهوية، الشهادات، العقود، التقارير الطبية… مع تاريخ انتهاء وتنبيه قبل انتهائها.', 'IDs, certificates, contracts, medical reports… with an expiry date and an alert before it.')}</Empty> :
      <table className="tbl"><thead><tr><th>{L('المستند', 'Document')}</th><th className="hidden md:table-cell">{L('ينتهي في', 'Expires')}</th><th className="hidden lg:table-cell">{L('رُفع', 'Uploaded')}</th><th>{L('الحالة', 'Status')}</th><th /></tr></thead>
        <tbody>{list.map(d => <tr key={d.id} className={d.status === 'archived' ? 'opacity-60' : ''}>
          <td><div className="font-medium flex items-center gap-1.5 flex-wrap">{bi(d.type_name, lang)}{d.sensitive && <Badge tone="danger"><ShieldAlert size={11} className="inline -mt-0.5" /> {L('حساس', 'Sensitive')}</Badge>}</div>
            <div className="text-[12px] text-ink-3 truncate max-w-[260px]">{d.title || d.file.name}</div>
            <div className="text-[12px] text-ink-3 md:hidden">{d.expires_at ? <>{L('ينتهي', 'Expires')} <span className="num">{fmtDate(d.expires_at, lang)}</span></> : null}</div></td>
          <td className="hidden md:table-cell num">{d.expires_at ? fmtDate(d.expires_at, lang) : '—'}</td>
          <td className="hidden lg:table-cell text-[12.5px]"><span className="num">{fmtDateTime(d.uploaded_at, lang)}</span>{d.uploaded_by && <div className="text-ink-3">{bi(d.uploaded_by, lang)}</div>}</td>
          <td>{state(d)}</td>
          <td><div className="flex justify-end gap-0.5">
            {viewable(d) && <a className="p-1.5 rounded hover:bg-sub text-ink-2" href={fileUrl(d.file.id, true)} target="_blank" rel="noreferrer" title={L('عرض', 'View')} aria-label={L('عرض', 'View')}><Eye size={16} /></a>}
            <a className="p-1.5 rounded hover:bg-sub text-ink-2" href={fileUrl(d.file.id)} title={L('تنزيل', 'Download')} aria-label={L('تنزيل', 'Download')}><Download size={16} /></a>
            <button className="p-1.5 rounded hover:bg-sub text-ink-2" onClick={() => setHist(d)} title={L('السجل', 'History')} aria-label={L('السجل', 'History')}><History size={16} /></button>
            {e.can.edit && (!d.sensitive || e.can.sensitive) && <button className="p-1.5 rounded hover:bg-sub text-ink-2 disabled:opacity-40" disabled={archive.isPending}
              onClick={() => archive.mutate({ doc: d.id, restore: d.status === 'archived' }, { onSuccess: () => toast(d.status === 'archived' ? L('استُعيد المستند', 'Document restored') : L('أُرشف المستند', 'Document archived')), onError: x => toast((x as Error).message) })}
              title={d.status === 'archived' ? L('استعادة', 'Restore') : L('أرشفة', 'Archive')} aria-label={d.status === 'archived' ? L('استعادة', 'Restore') : L('أرشفة', 'Archive')}>{d.status === 'archived' ? <Undo2 size={16} /> : <Archive size={16} />}</button>}
          </div></td></tr>)}</tbody></table>}
    {up && <UploadDoc e={e} onClose={() => setUp(false)} />}
    {hist && <DocHistoryModal emp={e.id} d={hist} onClose={() => setHist(null)} />}
  </Panel>
}

function UploadDoc({ e, onClose }: { e: EmpFile; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const { data: cfg } = useDocSettings()
  const up = useUploadDoc(e.id)
  const input = useRef<HTMLInputElement>(null)
  const [f, setF] = useState<File | null>(null)
  const [d, setD] = useState({ type: '', title: '', expires_at: '', sensitive: false, note: '' })
  const err = up.error instanceof ApiError ? up.error : null
  const s = cfg?.data
  const tooBig = !!(f && s && f.size > s.max_mb * 1024 * 1024)
  const badExt = !!(f && s && !s.extensions.includes(f.name.split('.').pop()?.toLowerCase() ?? ''))
  const submit = () => f && up.mutate({ file: f, ...d }, { onSuccess: () => { toast(L('رُفع المستند', 'Document uploaded')); onClose() }, onError: x => { if (!(x instanceof ApiError) || !Object.keys(x.errors).length) toast((x as Error).message) } })
  return <Modal open onClose={() => !up.isPending && onClose()} title={L('رفع مستند للموظف', 'Upload employee document')} width={560}
    footer={<><Btn onClick={onClose} disabled={up.isPending}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" disabled={!f || !d.type || tooBig || badExt || up.isPending} icon={up.isPending ? <Loader2 size={16} className="animate-spin" /> : <Upload size={15} />} onClick={submit}>{L('رفع', 'Upload')}</Btn></>}>
    {!s ? <div className="grid place-items-center py-10"><Loader2 className="animate-spin text-brand" /></div> : <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('الملف', 'File')} req className="sm:col-span-2" error={err?.field('file') ?? (tooBig ? L(`الحجم الأقصى ${s.max_mb} ميغابايت`, `Max size ${s.max_mb} MB`) : badExt ? L('صيغة غير مسموحة', 'File type not allowed') : undefined)}
        hint={L(`المسموح: ${s.extensions.join('، ').toUpperCase()} حتى ${s.max_mb} ميغابايت`, `Allowed: ${s.extensions.join(', ').toUpperCase()} up to ${s.max_mb} MB`)}>
        <div className="flex items-center gap-2 min-w-0"><Btn type="button" size="sm" icon={<Upload size={14} />} onClick={() => input.current?.click()}>{L('اختر ملفاً', 'Choose file')}</Btn>
          <span className="text-[13px] text-ink-2 truncate min-w-0">{f?.name ?? L('لم يُختر ملف', 'No file chosen')}</span>
          <input ref={input} type="file" className="hidden" accept={s.extensions.map(x => '.' + x).join(',')} onChange={v => { setF(v.target.files?.[0] ?? null); up.reset() }} /></div></Field>
      <Field label={L('نوع المستند', 'Document type')} req error={err?.field('type')}><select className="field" value={d.type} onChange={v => setD({ ...d, type: v.target.value })}>
        <option value="">{L('اختر…', 'Choose…')}</option>{s.types.map(t => <option key={t.key} value={t.key}>{bi(t, lang)}</option>)}</select></Field>
      <Field label={L('تاريخ الانتهاء', 'Expiry date')} error={err?.field('expires_at')} hint={L(`تنبيه قبل ${s.alert_days} يوماً`, `Alert ${s.alert_days} days before`)}><input type="date" className="field" value={d.expires_at} onChange={v => setD({ ...d, expires_at: v.target.value })} /></Field>
      <Field label={L('عنوان (اختياري)', 'Title (optional)')} className="sm:col-span-2"><input className="field" value={d.title} onChange={v => setD({ ...d, title: v.target.value })} /></Field>
      <Field label={L('الحساسية', 'Sensitivity')} div className="sm:col-span-2" error={err?.field('sensitive')}
        hint={d.sensitive ? L('يراه المخوّلون بالمستندات الحساسة والموظف نفسه فقط.', 'Only users cleared for sensitive documents, and the employee, can see it.') : undefined}>
        <Seg value={d.sensitive ? 's' : 'n'} onChange={v => setD({ ...d, sensitive: v === 's' })} options={[{ v: 'n', label: L('عادي', 'Normal') }, ...(e.can.sensitive ? [{ v: 's' as const, label: L('حساس', 'Sensitive') }] : [])]} /></Field>
      <Field label={L('ملاحظة', 'Note')} className="sm:col-span-2"><textarea className="field min-h-16" value={d.note} onChange={v => setD({ ...d, note: v.target.value })} maxLength={500} /></Field>
    </div>}
  </Modal>
}

function DocHistoryModal({ emp, d, onClose }: { emp: number; d: EmpDoc; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const { data, isLoading } = useDocHistory(emp, d.id)
  const label = { upload: L('رفع', 'Uploaded'), view: L('مشاهدة', 'Viewed'), download: L('تنزيل', 'Downloaded'), archive: L('أرشفة', 'Archived'), restore: L('استعادة', 'Restored'), update: L('تعديل', 'Updated') }
  return <Modal open onClose={onClose} title={<>{L('سجل المستند', 'Document history')} — {bi(d.type_name, lang)}</>} width={520}>
    {isLoading || !data ? <div className="grid place-items-center py-8"><Loader2 className="animate-spin text-brand" /></div> : data.length === 0 ? <Empty title={L('لا توجد أحداث', 'No events')} /> :
      <ul className="divide-y divide-line-2">{data.map((h, i) => <li key={i} className="flex justify-between gap-3 py-2 text-[13.5px]">
        <span><span className="font-semibold">{label[h.action]}</span>{h.by && <span className="text-ink-2"> — {bi(h.by, lang)}</span>}</span><span className="num text-ink-3 shrink-0">{fmtDateTime(h.at, lang)}</span></li>)}</ul>}
  </Modal>
}
