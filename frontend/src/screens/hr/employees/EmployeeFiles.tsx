import { useRef, useState } from 'react'
import { Fingerprint, Link2, Loader2, PenLine, Upload } from 'lucide-react'
import { useL, useLang, fmtDateTime } from '../../../lib/store'
import { ApiError } from '../../../lib/api'
import { fileUrl } from '../../../lib/console'
import { useLinkable, useLinkDoc, useSaveBiometrics, type Biometrics } from '../../../lib/employees'
import { Btn, Empty, Field, Modal, Panel, SignaturePad, toast } from '../../../ui/kit'

/** توقيع الموظف وبصمته (ملاحظة 49): يُسجَّلان عند المباشرة ويبقيان في ملفه */
export function BiometricsPanel({ emp, b, canEdit }: { emp: number; b?: Biometrics; canEdit: boolean }) {
  const L = useL(); const lang = useLang()
  const [open, setOpen] = useState(false)
  return <Panel title={L('توقيع الموظف وبصمته', 'Signature & fingerprint')} sub={b?.at ? L(`سُجّلا ${fmtDateTime(b.at, 'ar')}`, `Recorded ${fmtDateTime(b.at, lang)}`) : L('يُسجَّلان عند المباشرة.', 'Recorded at onboarding.')}
    action={canEdit && <Btn size="sm" icon={<PenLine size={14} />} onClick={() => setOpen(true)}>{b?.at ? L('تحديث', 'Update') : L('تسجيل', 'Record')}</Btn>}>
    <div className="grid grid-cols-2 gap-3">
      <div className="rounded-xl border border-line p-3 min-h-[110px] grid place-items-center">{b?.signature ? <img src={b.signature} alt={L('التوقيع', 'Signature')} className="max-h-20 bg-white rounded p-1" /> : <span className="text-[12.5px] text-ink-3">{L('لا توقيع', 'No signature')}</span>}</div>
      <div className="rounded-xl border border-line p-3 min-h-[110px] grid place-items-center">{b?.fingerprint_id ? <img src={fileUrl(b.fingerprint_id, true)} alt={L('البصمة', 'Fingerprint')} className="max-h-24 bg-white rounded p-1" /> : <span className="text-[12.5px] text-ink-3 inline-flex items-center gap-1"><Fingerprint size={15} />{L('لا بصمة', 'No fingerprint')}</span>}</div>
    </div>
    {open && <BiometricsModal emp={emp} onClose={() => setOpen(false)} />}
  </Panel>
}

function BiometricsModal({ emp, onClose }: { emp: number; onClose: () => void }) {
  const L = useL()
  const save = useSaveBiometrics(emp)
  const [sig, setSig] = useState<string | undefined>()
  const [finger, setFinger] = useState<File | null>(null)
  const input = useRef<HTMLInputElement>(null)
  const err = save.error instanceof ApiError ? save.error : null
  return <Modal open onClose={() => !save.isPending && onClose()} title={L('تسجيل توقيع الموظف وبصمته', "Record the employee's signature & fingerprint")} width={560}
    footer={<><Btn onClick={onClose} disabled={save.isPending}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" disabled={(!sig && !finger) || save.isPending} icon={save.isPending ? <Loader2 size={15} className="animate-spin" /> : undefined}
        onClick={() => save.mutate({ signature: sig, fingerprint: finger }, { onSuccess: () => { toast(L('حُفظ', 'Saved')); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="space-y-4">
      <Field label={L('التوقيع', 'Signature')} div hint={L('يوقّع الموظف بنفسه على هذا الجهاز.', 'The employee signs on this device.')} error={err?.field('signature')}><SignaturePad value={sig} onChange={setSig} /></Field>
      <Field label={L('البصمة', 'Fingerprint')} div hint={L('صورة من ماسح البصمة (PNG أو JPG).', 'An image from the fingerprint scanner (PNG or JPG).')} error={err?.field('fingerprint')}>
        <div className="flex items-center gap-2 min-w-0"><Btn type="button" size="sm" icon={<Upload size={14} />} onClick={() => input.current?.click()}>{L('اختر الصورة', 'Choose image')}</Btn>
          <span className="text-[13px] text-ink-2 truncate">{finger?.name ?? L('لم تُختر صورة', 'No image chosen')}</span>
          <input ref={input} type="file" accept="image/png,image/jpeg,image/webp,image/bmp" className="hidden" onChange={v => setFinger(v.target.files?.[0] ?? null)} /></div></Field>
      {err && !Object.keys(err.errors).length && <p role="alert" className="text-[13px] text-danger">{err.message}</p>}
    </div>
  </Modal>
}

/** ربط ملف موجود في النظام بملف الموظف (49): السيرة الذاتية، مستندات الإجازات، توقيعات المباشرة */
export function LinkFileModal({ emp, onClose }: { emp: number; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const { data, isLoading } = useLinkable(emp, true)
  const link = useLinkDoc(emp)
  return <Modal open onClose={onClose} title={L('ربط ملف من النظام', 'Link a file from the system')} width={560}>
    {isLoading || !data ? <div className="grid place-items-center py-8"><Loader2 className="animate-spin text-brand" /></div> : data.length === 0 ? <Empty title={L('لا ملفات للموظف في النظام', 'No system files for this employee')} /> :
      <ul className="divide-y divide-line-2">{data.map(f => <li key={f.id} className="flex items-center gap-3 py-2.5">
        <div className="flex-1 min-w-0"><div className="font-medium text-[14px]">{lang === 'ar' ? f.ar : f.en}</div><div className="text-[12px] text-ink-3 truncate">{f.name}</div></div>
        <a className="text-[13px] text-brand" href={fileUrl(f.id, true)} target="_blank" rel="noreferrer">{L('عرض', 'View')}</a>
        {f.linked ? <span className="text-[12.5px] text-ink-3">{L('مربوط', 'Linked')}</span> :
          <Btn size="sm" icon={<Link2 size={14} />} disabled={link.isPending} onClick={() => link.mutate({ file_id: f.id, title: lang === 'ar' ? f.ar : f.en }, { onSuccess: () => toast(L('رُبط الملف', 'File linked')), onError: x => toast((x as Error).message) })}>{L('ربط', 'Link')}</Btn>}
      </li>)}</ul>}
  </Modal>
}
