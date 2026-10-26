import { useEffect, useState } from 'react'
import { Loader2, RotateCcw, Save } from 'lucide-react'
import { useL } from '../../lib/store'
import { can, useMe } from '../../lib/session'
import { ApiError } from '../../lib/api'
import { useSaveVaultSettings, useVaultSettings, type VaultMethod, type VaultSettings as Cfg } from '../../lib/vault'
import { Badge, Btn, Field, PageHead, Panel, toast } from '../../ui/kit'

/**
 * «حماية الرواتب» (SRS §4.4 — M16 لكل شركة): مدة الفتح بعد التحقق، المحاولات قبل الإيقاف ومدته، صلاحية الرمز المرسل،
 * عدد رموز الطوارئ، سجل المحاولات، وطرق التحقق المتاحة. الحفظ مدقَّق، ويسري على عمليات التحقق التالية.
 */
export function VaultSettings() {
  const L = useL()
  const editable = can(useMe().data, 'settings', 'edit')
  const { data } = useVaultSettings()
  const save = useSaveVaultSettings()
  const [c, setC] = useState<Cfg | null>(null)
  useEffect(() => { if (data) setC(data.data) }, [data])
  if (!data || !c) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const err = save.error instanceof ApiError ? save.error : null
  const dirty = JSON.stringify(c) !== JSON.stringify(data.data)
  const submit = () => save.mutate(c, { onSuccess: () => toast(L('حُفظت الإعدادات', 'Settings saved')), onError: x => toast((x as Error).message) })
  const num = (k: keyof Omit<Cfg, 'methods'>, label: string, hint: string, min: number, max: number) =>
    <Field label={label} hint={`${hint} (${min}–${max})`} error={err?.field(k)}>
      <input type="number" min={min} max={max} className="field num" disabled={!editable} value={c[k]} onChange={e => setC({ ...c, [k]: Number(e.target.value) })} /></Field>
  const methods: [VaultMethod, string, string][] = [
    ['phone', L('رمز لمرة واحدة بالبريد', 'One-time code by email'), L('يُرسل من الخادم إلى بريد الحساب (الرسائل القصيرة عند اعتماد مزوّد)', 'Sent by the server to the account email (SMS once a provider is chosen)')],
    ['passkey', L('البصمة / Windows Hello', 'Fingerprint / Windows Hello'), L('مفتاح مرور على الجهاز — يتطلب https', 'A passkey on the device — requires https')],
    ['app', L('تطبيق المصادقة', 'Authenticator app'), L('Google / Microsoft Authenticator مع رموز طوارئ', 'Google / Microsoft Authenticator with backup codes')],
  ]

  return <div className="max-w-[900px] mx-auto space-y-5">
    <PageHead title={L('حماية الرواتب', 'Salary protection')} sub={L('لا تُعرض أي مبالغ رواتب إلا بعد التحقق بخطوتين، والخادم لا يُرسلها قبل ذلك.', 'No salary amount is shown before two-step verification, and the server does not send it before then.')}
      actions={editable && <><Btn icon={<RotateCcw size={16} />} onClick={() => setC(data.defaults)}>{L('القيم الافتراضية', 'Defaults')}</Btn>
        <Btn kind="primary" icon={save.isPending ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />} disabled={!dirty || save.isPending} onClick={submit}>{L('حفظ', 'Save')}</Btn></>} />
    {!editable && <Badge>{L('عرض فقط — التعديل لمدير الإعدادات', 'Read only — settings managers can edit')}</Badge>}
    <Panel title={L('طرق التحقق المتاحة', 'Available methods')} sub={err?.field('methods')}>
      <div className="space-y-2">{methods.map(([k, t, s]) => <label key={k} className="flex items-start gap-3 rounded-xl border border-line px-3 py-2.5 cursor-pointer">
        <input type="checkbox" className="mt-1 accent-[var(--brand)]" disabled={!editable} checked={c.methods[k]} onChange={e => setC({ ...c, methods: { ...c.methods, [k]: e.target.checked } })} />
        <span><span className="block font-semibold text-[14px]">{t}</span><span className="block text-[12.5px] text-ink-3">{s}</span></span></label>)}</div>
    </Panel>
    <Panel title={L('القواعد', 'Rules')}>
      <div className="grid sm:grid-cols-2 gap-4">
        {num('unlock_minutes', L('مدة الفتح (دقيقة)', 'Unlock window (minutes)'), L('بعدها تُقفل الرواتب تلقائياً', 'Then salaries lock automatically'), 1, 60)}
        {num('max_attempts', L('المحاولات الخاطئة قبل الإيقاف', 'Wrong attempts before lockout'), L('رمز أو كلمة مرور خاطئة', 'Wrong code or password'), 3, 10)}
        {num('lockout_seconds', L('مدة الإيقاف (ثانية)', 'Lockout (seconds)'), L('لا محاولات خلالها', 'No attempts during it'), 30, 3600)}
        {num('otp_minutes', L('صلاحية الرمز المرسل (دقيقة)', 'Sent code validity (minutes)'), L('لمرة واحدة', 'Single use'), 1, 10)}
        {num('backup_count', L('عدد رموز الطوارئ', 'Backup codes'), L('تُعطى عند ربط التطبيق', 'Given when the app is linked'), 4, 16)}
        {num('audit_keep', L('سجل المحاولات لكل مستخدم', 'Attempt log per user'), L('آخر N محاولة ناجحة أو فاشلة', 'Last N successful or failed attempts'), 20, 1000)}
      </div>
    </Panel>
  </div>
}
