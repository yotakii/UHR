import { useState } from 'react'
import { Download, FileSpreadsheet, Loader2, Upload, CheckCircle2, AlertTriangle } from 'lucide-react'
import { useL } from '../../../lib/store'
import { ApiError } from '../../../lib/api'
import { IMPORT_TEMPLATE, useCommitImport, usePreviewImport } from '../../../lib/onboarding'
import { Btn, Modal, toast } from '../../../ui/kit'

/**
 * استيراد الموظفين الحاليين (M06): تنزيل القالب ← رفع الملف ← معاينة بأخطاء كل سطر ← الحفظ لملف بلا أخطاء فقط.
 * المستوردون بحالة «على رأس العمل» بلا F-19، ولكلٍّ حساب بكلمة مرور مؤقتة (الإرسال بالبريد اختياري).
 */
export function ImportEmployees({ onClose }: { onClose: () => void }) {
  const L = useL()
  const [file, setFile] = useState<File | null>(null)
  const [invite, setInvite] = useState(true)
  const preview = usePreviewImport(); const commit = useCommitImport()
  const p = preview.data
  const err = (preview.error ?? commit.error) as ApiError | null
  const pick = (f: File | null) => { setFile(f); commit.reset(); if (f) preview.mutate(f); else preview.reset() }
  const save = () => file && commit.mutate({ file, invite }, { onSuccess: r => { toast(L(`استُورد ${r.created} موظفاً`, `${r.created} employees imported`)); onClose() } })
  return <Modal open onClose={() => !commit.isPending && onClose()} title={L('استيراد الموظفين الحاليين من Excel', 'Import current employees from Excel')} width={820}
    footer={<><Btn onClick={onClose} disabled={commit.isPending}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" disabled={!p || p.errors > 0 || commit.isPending} icon={commit.isPending ? <Loader2 size={16} className="animate-spin" /> : <Upload size={16} />} onClick={save}>{p ? L(`حفظ ${p.ok} موظفاً`, `Save ${p.ok} employees`) : L('حفظ', 'Save')}</Btn></>}>
    <div className="space-y-4">
      <ol className="grid sm:grid-cols-3 gap-2 text-[13px]">
        <li className="rounded-xl border border-line p-3"><div className="font-semibold mb-1">1. {L('نزّل القالب', 'Download the template')}</div>
          <a href={IMPORT_TEMPLATE} className="inline-flex items-center gap-1.5 text-brand font-semibold" download><Download size={15} />UHR-employees-template.xlsx</a></li>
        <li className="rounded-xl border border-line p-3"><div className="font-semibold mb-1">2. {L('املأه واحفظه', 'Fill and save it')}</div><div className="text-ink-3">{L('سطر لكل موظف. رموز الإدارات والمقرات في الورقة الثانية.', 'One row per employee. Department and location codes are on the second sheet.')}</div></li>
        <li className="rounded-xl border border-line p-3"><div className="font-semibold mb-1">3. {L('ارفعه وراجع المعاينة', 'Upload and review')}</div><div className="text-ink-3">{L('لا يُحفظ شيء حتى تخلو كل الأسطر من الأخطاء.', 'Nothing is saved until every row is error-free.')}</div></li>
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
          {p.errors > 0 && <span className="inline-flex items-center gap-1.5 rounded-lg bg-danger-soft text-danger px-2.5 py-1 font-semibold"><AlertTriangle size={15} />{L(`${p.errors} سطر فيه أخطاء — صحّحها بالملف وأعد الرفع`, `${p.errors} rows with errors — fix them in the file and upload again`)}</span>}
        </div>
        <div className="border border-line rounded-xl max-h-[42vh] overflow-y-auto"><table className="tbl w-full text-[13px]">
          <thead><tr><th className="w-12">{L('السطر', 'Row')}</th><th>{L('الموظف', 'Employee')}</th><th className="hidden md:table-cell">{L('الإدارة والمسمى', 'Department & title')}</th><th>{L('النتيجة', 'Result')}</th></tr></thead>
          <tbody>{p.rows.map(r => { const bad = Object.keys(r.errors).length > 0
            return <tr key={r.row} className={bad ? 'bg-danger-soft/40' : ''}><td className="num">{r.row}</td>
              <td><div className="font-medium">{r.raw.name || '—'}</div><div className="text-ink-3 ltr text-start break-all">{r.raw.email}</div></td>
              <td className="hidden md:table-cell">{r.raw.department} · {r.raw.title_ar}</td>
              <td>{bad ? <ul className="text-danger space-y-0.5">{Object.values(r.errors).map((m, i) => <li key={i}>{m}</li>)}</ul> : <span className="text-brand inline-flex items-center gap-1"><CheckCircle2 size={14} />{L('جاهز', 'Ready')}</span>}</td></tr> })}</tbody>
        </table></div>
        <label className="flex items-start gap-2 text-[14px]"><input type="checkbox" checked={invite} onChange={x => setInvite(x.target.checked)} className="mt-1 size-4 accent-[var(--primary)]" />
          <span>{L('أرسل لكل موظف بريد الدخول بكلمة مرور مؤقتة', 'Email each employee their sign-in with a temporary password')}<span className="block text-[12px] text-ink-3">{L('بدون الإرسال تُنشأ الحسابات ويمكن إعادة تعيين كلمة المرور لاحقاً من «نسيت كلمة المرور».', 'Without it, accounts are created and can be activated later with "Forgot password".')}</span></span></label>
      </>}
    </div>
  </Modal>
}
