import { useEffect, useState, type ReactNode } from 'react'
import { ShieldCheck } from 'lucide-react'
import { useL } from '../lib/store'
import { ApiError } from '../lib/api'
import { Btn, Field, Modal } from './kit'

/**
 * نافذة القرار (SRS §10.1): كل اعتماد/رفض/تحويل يمر بخطوتين — تبرير (إلزامي للرفض)، ثم تأكيد ثانٍ —
 * حتى لا يُتخذ قرار بكبسة خاطئة. الإلغاء يترك السجل كما هو.
 */
export function DecisionDialog({ open, onClose, title, subject, confirmLabel, tone = 'primary', reasonRequired = false, minLength = 10, reasonLabel, presets, onConfirm }: {
  open: boolean; onClose: () => void; title: ReactNode; subject?: ReactNode; confirmLabel: string; tone?: 'primary' | 'danger'
  reasonRequired?: boolean; minLength?: number; reasonLabel?: string; presets?: string[]; onConfirm: (reason: string) => Promise<unknown>
}) {
  const L = useL()
  const [step, setStep] = useState<1 | 2>(1)
  const [reason, setReason] = useState('')
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => { if (open) { setStep(1); setReason(''); setErr(''); setBusy(false) } }, [open])
  const short = reason.trim().length < (reasonRequired ? minLength : 0)
  const next = () => { if (short) { setErr(L(`اكتب سبباً واضحاً (${minLength} أحرف على الأقل).`, `Write a clear reason (at least ${minLength} characters).`)); return } setStep(2) }
  const confirm = async () => {
    setBusy(true); setErr('')
    try { await onConfirm(reason.trim()); onClose() }
    catch (e) { setErr(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : L('تعذّر تنفيذ القرار. حاول مجدداً.', 'Could not complete this decision. Try again.')); setStep(1) }
    finally { setBusy(false) }
  }
  return <Modal open={open} onClose={() => !busy && onClose()} title={title} width={520}
    footer={step === 1
      ? <><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind={tone} onClick={next}>{L('متابعة', 'Continue')}</Btn></>
      : <><Btn onClick={() => setStep(1)} disabled={busy}>{L('رجوع', 'Back')}</Btn><Btn kind={tone} onClick={confirm} disabled={busy}>{busy ? L('جارٍ التنفيذ…', 'Working…') : confirmLabel}</Btn></>}>
    <div className="space-y-4">
      {subject && <div className="rounded-lg bg-sub px-3 py-2 text-[14px]">{subject}</div>}
      {step === 1
        ? <Field div={!!presets} label={reasonLabel ?? (reasonRequired ? L('سبب القرار', 'Reason for this decision') : L('ملاحظة (اختيارية)', 'Note (optional)'))} req={reasonRequired} error={err}>
            <>{presets && <div className="flex flex-wrap gap-1.5 mb-2">{presets.map(p => <button key={p} type="button" onClick={() => { setReason(p); setErr('') }} className={`h-8 px-2.5 rounded-lg border text-[12.5px] ${reason === p ? 'bg-brand-soft border-brand/50 text-brand' : 'border-line bg-surface hover:border-brand/40'}`}>{p}</button>)}</div>}
            <textarea className="field min-h-[96px]" value={reason} maxLength={500} autoFocus onChange={e => { setReason(e.target.value); setErr('') }} /></>
          </Field>
        : <div className="flex gap-3"><span className={`size-10 rounded-full grid place-items-center shrink-0 ${tone === 'danger' ? 'bg-danger-soft text-danger' : 'bg-brand-soft text-brand'}`}><ShieldCheck size={20} /></span>
            <div className="text-[14px]"><div className="font-semibold">{L('تأكيد القرار', 'Confirm the decision')}</div>
              <p className="text-ink-2 mt-0.5">{L('هذا القرار يُسجَّل باسمك ووقته في سجل التدقيق، ولا يمكن التراجع عنه من هنا.', 'This decision is recorded with your name and time in the audit log, and cannot be undone from here.')}</p>
              {reason.trim() && <p className="mt-2 text-ink-2"><span className="text-ink-3">{L('السبب:', 'Reason:')}</span> {reason.trim()}</p>}
              {err && <p className="mt-2 text-danger text-[13px]">{err}</p>}</div></div>}
    </div>
  </Modal>
}

/**
 * زر إرسال بخطوتين (ملاحظة الاختبار 84): الضغطة الأولى تعرض «تأكيد الإرسال / تراجع» مع ملخص قصير، والثانية ترسل.
 * يُستخدم لطلبات الموظف (الإجازة، الخدمات…) — القرارات تمر أصلاً بنافذة القرار ذات الخطوتين.
 */
export function ConfirmSend({ label, confirmLabel, summary, busy, disabled, onConfirm, className = '', block }: {
  label: ReactNode; confirmLabel?: ReactNode; summary?: ReactNode; busy?: boolean; disabled?: boolean; onConfirm: () => void; className?: string; block?: boolean
}) {
  const L = useL()
  const [ask, setAsk] = useState(false)
  useEffect(() => { if (disabled) setAsk(false) }, [disabled])
  if (!ask) return <Btn kind="primary" className={`${block ? 'w-full' : ''} ${className}`} disabled={disabled || busy} onClick={() => setAsk(true)}>{label}</Btn>
  return <div className={`rounded-lg border border-brand/40 bg-brand-soft/60 p-2.5 ${block ? 'w-full' : ''} ${className}`} role="group" aria-label={L('تأكيد الإرسال', 'Confirm sending')}>
    <div className="text-[13px] font-semibold flex items-center gap-1.5"><ShieldCheck size={15} className="text-brand" />{L('تأكيد إرسال الطلب؟', 'Send this request?')}</div>
    {summary && <div className="text-[12.5px] text-ink-2 mt-1">{summary}</div>}
    <div className="flex gap-2 mt-2 justify-end"><Btn size="sm" onClick={() => setAsk(false)} disabled={busy}>{L('تراجع', 'Back')}</Btn>
      <Btn size="sm" kind="primary" disabled={busy} onClick={() => { onConfirm() }} autoFocus>{busy ? L('جارٍ الإرسال…', 'Sending…') : confirmLabel ?? L('تأكيد الإرسال', 'Confirm and send')}</Btn></div>
  </div>
}
