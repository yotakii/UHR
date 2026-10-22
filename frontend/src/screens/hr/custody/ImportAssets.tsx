import { useState } from 'react'
import { AlertTriangle, CheckCircle2, Download, FileSpreadsheet, Loader2, Upload } from 'lucide-react'
import { useL } from '../../../lib/store'
import { ApiError } from '../../../lib/api'
import { ASSET_TEMPLATE, useImportAssets, usePreviewAssets, type AssetImportRow } from '../../../lib/custody'
import { Btn, Modal, toast } from '../../../ui/kit'

/** تقرير الأخطاء CSV (يفتح في Excel بالعربية) */
function errorReport(rows: AssetImportRow[]) {
  const esc = (v: unknown) => `"${String(v ?? '').replace(/"/g, '""')}"`
  const lines = [['row', 'name_ar', 'category', 'serial', 'employee_code', 'errors'].join(','),
    ...rows.map(r => [r.row, r.values.name_ar, r.values.category, r.values.serial, r.values.employee_code, Object.values(r.errors).join(' | ')].map(esc).join(','))]
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'UHR-custody-import-errors.csv'; a.click(); URL.revokeObjectURL(a.href)
}

/**
 * استيراد العهد (D-13): القالب ← الرفع ← المعاينة (تحقق الحقول، مطابقة الموظفين والفئات، كشف التكرار) ← التأكيد.
 * الأسطر السليمة تُستورد والخاطئة تُستثنى في تقرير أخطاء قابل للتنزيل.
 */
export function ImportAssets({ onClose }: { onClose: () => void }) {
  const L = useL()
  const [file, setFile] = useState<File | null>(null)
  const [paper, setPaper] = useState(false)
  const preview = usePreviewAssets(); const commit = useImportAssets()
  const p = preview.data; const done = commit.data
  const err = (preview.error ?? commit.error) as ApiError | null
  const pick = (f: File | null) => { setFile(f); commit.reset(); if (f) preview.mutate(f); else preview.reset() }
  const save = () => file && commit.mutate({ file, paper }, { onSuccess: r => toast(L(`استُورد ${r.created} أصلاً`, `${r.created} assets imported`)) })
  return <Modal open onClose={() => !commit.isPending && onClose()} title={L('استيراد العهد من Excel', 'Import custody from Excel')} width={820}
    footer={done ? <Btn kind="primary" onClick={onClose}>{L('تم', 'Done')}</Btn> : <><Btn onClick={onClose} disabled={commit.isPending}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" disabled={!p || p.ok === 0 || commit.isPending} icon={commit.isPending ? <Loader2 size={16} className="animate-spin" /> : <Upload size={16} />} onClick={save}>
        {p ? (p.errors ? L(`استيراد ${p.ok} وتخطي ${p.errors}`, `Import ${p.ok}, skip ${p.errors}`) : L(`استيراد ${p.ok} أصلاً`, `Import ${p.ok} assets`)) : L('استيراد', 'Import')}</Btn></>}>
    {done ? <div className="space-y-4">
      <div className="flex flex-wrap gap-2 text-[14px]">
        <span className="inline-flex items-center gap-1.5 rounded-lg bg-brand-soft text-brand px-3 py-1.5 font-semibold"><CheckCircle2 size={16} />{L(`استُورد ${done.created} أصلاً`, `${done.created} assets imported`)}</span>
        {done.failed > 0 && <span className="inline-flex items-center gap-1.5 rounded-lg bg-danger-soft text-danger px-3 py-1.5 font-semibold"><AlertTriangle size={16} />{L(`${done.failed} سطر مستثنى`, `${done.failed} rows skipped`)}</span>}
      </div>
      {done.failed > 0 && <Btn icon={<Download size={15} />} onClick={() => errorReport(done.errors)}>{L('تنزيل تقرير الأخطاء', 'Download error report')}</Btn>}
      <p className="text-[13px] text-ink-3">{paper ? L('العهد المسلّمة سُجّلت موقّعة ورقياً.', 'Issued assets were recorded as signed on paper.') : L('وصل لكل حائز طلب توقيع سند الاستلام في «وثائقي».', 'Each holder received a receipt to sign in "My documents".')}</p>
    </div> : <div className="space-y-4">
      <ol className="grid sm:grid-cols-3 gap-2 text-[13px]">
        <li className="rounded-xl border border-line p-3"><div className="font-semibold mb-1">1. {L('نزّل القالب', 'Download the template')}</div>
          <a href={ASSET_TEMPLATE} className="inline-flex items-center gap-1.5 text-brand font-semibold" download><Download size={15} />UHR-custody-template.xlsx</a></li>
        <li className="rounded-xl border border-line p-3"><div className="font-semibold mb-1">2. {L('املأه واحفظه', 'Fill and save it')}</div><div className="text-ink-3">{L('سطر لكل أصل. رموز الفئات في الورقة الثانية، والحائز بالرقم الوظيفي.', 'One row per asset. Category codes are on the second sheet; the holder by employee code.')}</div></li>
        <li className="rounded-xl border border-line p-3"><div className="font-semibold mb-1">3. {L('ارفعه وراجع المعاينة', 'Upload and review')}</div><div className="text-ink-3">{L('الأسطر الخاطئة تُستثنى وتظهر في تقرير الأخطاء.', 'Rows with errors are skipped and listed in the error report.')}</div></li>
      </ol>
      <label className="flex flex-wrap items-center gap-3 rounded-xl border border-dashed border-line p-4 cursor-pointer hover:border-brand/50">
        <FileSpreadsheet size={22} className="text-brand" />
        <span className="flex-1 min-w-0 text-[14px]">{file ? <span className="font-semibold break-all">{file.name}</span> : L('اختر ملف xlsx أو csv…', 'Choose an xlsx or csv file…')}</span>
        {preview.isPending && <Loader2 size={18} className="animate-spin text-brand" />}
        <input type="file" accept=".xlsx,.csv" className="sr-only" onChange={x => pick(x.target.files?.[0] ?? null)} />
        <span className="text-[13px] text-brand font-semibold">{file ? L('تغيير', 'Change') : L('استعراض', 'Browse')}</span>
      </label>
      {err && <div role="alert" className="text-[13px] text-danger bg-danger-soft rounded px-3 py-2">{err.field('file') ?? err.message}</div>}
      {p && <>
        <div className="flex flex-wrap gap-2 text-[13px]">
          <span className="inline-flex items-center gap-1.5 rounded-lg bg-brand-soft text-brand px-2.5 py-1 font-semibold"><CheckCircle2 size={15} />{L(`${p.ok} سطر سليم`, `${p.ok} valid rows`)}</span>
          {p.errors > 0 && <span className="inline-flex items-center gap-1.5 rounded-lg bg-danger-soft text-danger px-2.5 py-1 font-semibold"><AlertTriangle size={15} />{L(`${p.errors} سطر فيه أخطاء — سيُستثنى`, `${p.errors} rows with errors — will be skipped`)}</span>}
        </div>
        <div className="border border-line rounded-xl max-h-[42vh] overflow-y-auto"><table className="tbl w-full text-[13px]">
          <thead><tr><th className="w-12">{L('السطر', 'Row')}</th><th>{L('الأصل', 'Asset')}</th><th className="hidden md:table-cell">{L('الفئة والحائز', 'Category & holder')}</th><th>{L('النتيجة', 'Result')}</th></tr></thead>
          <tbody>{p.rows.map(r => { const bad = Object.keys(r.errors).length > 0
            return <tr key={r.row} className={bad ? 'bg-danger-soft/40' : ''}><td className="num">{r.row}</td>
              <td><div className="font-medium">{String(r.values.name_ar || '—')}</div><div className="text-ink-3 ltr text-start break-all">{String(r.values.serial || '')}</div></td>
              <td className="hidden md:table-cell">{String(r.values.category)}{r.values.employee_code ? <> · <span className="ltr inline-block">{String(r.values.employee_code)}</span></> : null}</td>
              <td>{bad ? <ul className="text-danger space-y-0.5">{Object.values(r.errors).map((m, i) => <li key={i}>{m}</li>)}</ul> : <span className="text-brand inline-flex items-center gap-1"><CheckCircle2 size={14} />{L('جاهز', 'Ready')}</span>}</td></tr> })}</tbody>
        </table></div>
        <label className="flex items-start gap-2 text-[14px]"><input type="checkbox" checked={paper} onChange={x => setPaper(x.target.checked)} className="mt-1 size-4 accent-[var(--primary)]" />
          <span>{L('العهد المسلّمة موقّعة ورقياً من قبل', 'Issued assets were already signed on paper')}<span className="block text-[12px] text-ink-3">{L('بدون هذا الخيار تصبح «بانتظار التوقيع» ويوقّعها كل موظف من حسابه.', 'Without it they become "awaiting signature" and each employee signs from their account.')}</span></span></label>
      </>}
    </div>}
  </Modal>
}
