import { useEffect, useState } from 'react'
import { Loader2, RotateCcw, Save } from 'lucide-react'
import { useL, useLang, bi, fmtDateTime } from '../../lib/store'
import { can, useMe } from '../../lib/session'
import { ApiError } from '../../lib/api'
import { useAllGrants, useRevokeGrant, useSaveVaultSettings, useVaultSettings, type GrantRow, type VaultMethod, type VaultSettings as Cfg } from '../../lib/vault'
import { Badge, Btn, Empty, Field, PageHead, Panel, toast } from '../../ui/kit'
import { DecisionDialog } from '../../ui/decision'

type NumKey = 'unlock_minutes' | 'max_minutes' | 'max_attempts' | 'lockout_seconds' | 'otp_minutes' | 'link_seconds' | 'backup_count' | 'audit_keep' | 'grant_hours'

/**
 * «حماية الرواتب» (SRS §4.4 — M16 لكل شركة): الفتح المتجدد مع الاستخدام وحده الأقصى، المحاولات والإيقاف، صلاحية الرمز وطلب الموبايل،
 * رموز الطوارئ، طرق التحقق، وإذن الاطلاع على رواتب الآخرين (يعتمده المدير العام، مدته، والأدوار المعفاة) + الأذونات القائمة وسحبها.
 */
export function VaultSettings() {
  const L = useL(); const lang = useLang()
  const editable = can(useMe().data, 'settings', 'edit')
  const { data } = useVaultSettings()
  const save = useSaveVaultSettings()
  const [c, setC] = useState<Cfg | null>(null)
  useEffect(() => { if (data) setC(data.data) }, [data])
  if (!data || !c) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const err = save.error instanceof ApiError ? save.error : null
  const dirty = JSON.stringify(c) !== JSON.stringify(data.data)
  const submit = () => save.mutate(c, { onSuccess: () => toast(L('حُفظت الإعدادات', 'Settings saved')), onError: x => toast((x as Error).message) })
  const num = (k: NumKey, label: string, hint: string, min: number, max: number) =>
    <Field label={label} hint={`${hint} (${min}–${max})`} error={err?.field(k)}>
      <input type="number" min={min} max={max} className="field num" disabled={!editable} value={c[k]} onChange={e => setC({ ...c, [k]: Number(e.target.value) })} /></Field>
  const methods: [VaultMethod, string, string][] = [
    ['link', L('موافقة من الموبايل', 'Approve on the phone'), L('QR على الحاسوب يمسحه الموبايل المسجّل دخوله بنفس الحساب، ثم «موافق»', 'A QR on the computer scanned by the phone signed in to the same account, then “Approve”')],
    ['passkey', L('البصمة / Windows Hello', 'Fingerprint / Windows Hello'), L('مفتاح مرور على الجهاز — لمسة واحدة، يتطلب https', 'A passkey on the device — one touch, requires https')],
    ['phone', L('رمز لمرة واحدة بالبريد', 'One-time code by email'), L('يُرسل من الخادم إلى بريد الحساب (الرسائل القصيرة عند اعتماد مزوّد)', 'Sent by the server to the account email (SMS once a provider is chosen)')],
    ['app', L('تطبيق المصادقة', 'Authenticator app'), L('Google / Microsoft Authenticator مع رموز طوارئ', 'Google / Microsoft Authenticator with backup codes')],
  ]
  const role = (k: string) => data.roles.find(r => r.key === k)

  return <div className="max-w-[960px] mx-auto space-y-5">
    <PageHead title={L('حماية الرواتب', 'Salary protection')} sub={L('لا تُعرض أي مبالغ رواتب إلا بعد التحقق، ورواتب الآخرين بإذن معتمد — والخادم لا يُرسلها قبل ذلك.', 'No salary amount is shown before verification, and other people’s salaries need approved access — the server does not send them before then.')}
      actions={editable && <><Btn icon={<RotateCcw size={16} />} onClick={() => setC(data.defaults)}>{L('القيم الافتراضية', 'Defaults')}</Btn>
        <Btn kind="primary" icon={save.isPending ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />} disabled={!dirty || save.isPending} onClick={submit}>{L('حفظ', 'Save')}</Btn></>} />
    {!editable && <Badge>{L('عرض فقط — التعديل لمدير الإعدادات', 'Read only — settings managers can edit')}</Badge>}
    <Panel title={L('طرق التحقق المتاحة', 'Available methods')} sub={err?.field('methods') ?? L('النافذة تفتح مباشرة على آخر طريقة نجحت مع المستخدم.', 'The dialog opens straight on the user’s last successful method.')}>
      <div className="grid sm:grid-cols-2 gap-2">{methods.map(([k, t, s]) => <label key={k} className="flex items-start gap-3 rounded-xl border border-line px-3 py-2.5 cursor-pointer">
        <input type="checkbox" className="mt-1 accent-[var(--brand)]" disabled={!editable} checked={c.methods[k]} onChange={e => setC({ ...c, methods: { ...c.methods, [k]: e.target.checked } })} />
        <span><span className="block font-semibold text-[14px]">{t}</span><span className="block text-[12.5px] text-ink-3">{s}</span></span></label>)}</div>
    </Panel>
    <Panel title={L('مدة الفتح والمحاولات', 'Unlock window & attempts')}>
      <div className="grid sm:grid-cols-2 gap-4">
        {num('unlock_minutes', L('تُقفل بعد (دقيقة بلا استخدام)', 'Locks after (idle minutes)'), L('كل اطلاع على الرواتب يجدّدها', 'Every salary view renews it'), 1, 60)}
        {num('max_minutes', L('الحد الأقصى للفتح (دقيقة)', 'Maximum unlock (minutes)'), L('من لحظة التحقق مهما استُخدمت', 'From verification, however it’s used'), 1, 720)}
        {num('max_attempts', L('المحاولات الخاطئة قبل الإيقاف', 'Wrong attempts before lockout'), L('رمز أو كلمة مرور خاطئة', 'Wrong code or password'), 3, 10)}
        {num('lockout_seconds', L('مدة الإيقاف (ثانية)', 'Lockout (seconds)'), L('لا محاولات خلالها', 'No attempts during it'), 30, 3600)}
        {num('link_seconds', L('صلاحية طلب الموبايل (ثانية)', 'Phone request validity (seconds)'), L('مدة QR الموافقة', 'How long the approval QR lasts'), 30, 600)}
        {num('otp_minutes', L('صلاحية الرمز المرسل (دقيقة)', 'Sent code validity (minutes)'), L('لمرة واحدة', 'Single use'), 1, 10)}
        {num('backup_count', L('عدد رموز الطوارئ', 'Backup codes'), L('تُعطى عند ربط التطبيق', 'Given when the app is linked'), 4, 16)}
        {num('audit_keep', L('سجل المحاولات لكل مستخدم', 'Attempt log per user'), L('آخر N محاولة ناجحة أو فاشلة', 'Last N successful or failed attempts'), 20, 1000)}
      </div>
    </Panel>
    <Panel title={L('إذن الاطلاع على رواتب الآخرين', 'Access to other people’s salaries')} sub={L('بعد التحقق، رواتب الموظفين الآخرين تحتاج إذناً يعتمده المدير العام من صندوق الاعتماد. كشف الموظف نفسه لا يحتاج إذناً.', 'After verification, other employees’ salaries need access approved by the general manager from the approvals inbox. An employee’s own sheet needs none.')}>
      <label className="flex items-center gap-3 mb-4 cursor-pointer"><input type="checkbox" className="accent-[var(--brand)]" disabled={!editable} checked={c.grant_required} onChange={e => setC({ ...c, grant_required: e.target.checked })} />
        <span className="font-semibold text-[14px]">{L('الإذن مطلوب', 'Access approval required')}</span></label>
      {c.grant_required && <div className="grid sm:grid-cols-2 gap-4">
        {num('grant_hours', L('مدة الإذن (ساعة)', 'Access duration (hours)'), L('من لحظة الموافقة، ثم يُجدَّد بطلب جديد', 'From approval; then renewed with a new request'), 1, 720)}
        <Field label={L('أدوار معفاة (المعتمِد)', 'Exempt roles (the approver)')} hint={L('تُرى لهم الرواتب بالتحقق وحده، ولهم سحب الأذونات', 'They see salaries with verification alone and can revoke access')} error={err?.field('grant_exempt_roles')} div>
          <div className="flex flex-wrap gap-2">{data.roles.map(r => <label key={r.key} className="inline-flex items-center gap-1.5 rounded-lg border border-line px-2.5 h-8 text-[13px] cursor-pointer">
            <input type="checkbox" className="accent-[var(--brand)]" disabled={!editable} checked={c.grant_exempt_roles.includes(r.key)}
              onChange={e => setC({ ...c, grant_exempt_roles: e.target.checked ? [...c.grant_exempt_roles, r.key] : c.grant_exempt_roles.filter(x => x !== r.key) })} />{bi(r, lang)}</label>)}</div></Field>
      </div>}
      {!c.grant_exempt_roles.length && c.grant_required && <p className="mt-2 text-[12.5px] text-warn">{L('بدون دور معفى لن يرى أحد رواتب الآخرين إلا بإذن.', 'With no exempt role nobody sees others’ salaries without access.')}</p>}
      {c.grant_exempt_roles.length > 0 && <p className="mt-3 text-[12px] text-ink-3">{L('المعتمِد في صندوق الاعتماد: ', 'Approver in the inbox: ')}{c.grant_exempt_roles.map(k => role(k) ? bi(role(k)!, lang) : k).join('، ')}</p>}
    </Panel>
    <GrantsPanel />
  </div>
}

function GrantsPanel() {
  const L = useL(); const lang = useLang()
  const q = useAllGrants(); const revoke = useRevokeGrant()
  const [sel, setSel] = useState<GrantRow | null>(null)
  const rows = q.data?.data ?? []
  const tone = (s: string) => s === 'approved' ? 'brand' : s.startsWith('pending') ? 'brass' : s === 'rejected' || s === 'revoked' ? 'danger' : undefined
  const label = (s: string) => s === 'approved' ? L('ساري', 'Active') : s.startsWith('pending') ? L('بانتظار الموافقة', 'Pending') : s === 'expired' ? L('منتهٍ', 'Expired')
    : s === 'revoked' ? L('مسحوب', 'Revoked') : s === 'rejected' ? L('مرفوض', 'Declined') : L('ملغى', 'Cancelled')
  return <Panel title={L('الأذونات', 'Access grants')} pad={false}>
    {!rows.length ? <Empty title={L('لا توجد طلبات إذن بعد', 'No access requests yet')} /> :
      <ul className="divide-y divide-line-2">{rows.map(g => <li key={g.id} className="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-5 py-3 text-[13.5px]">
        <span className="flex-1 min-w-[220px]"><span className="block font-semibold">{g.user ? (lang === 'ar' ? g.user.ar : g.user.en) : '—'}</span>
          <span className="block text-[12.5px] text-ink-2">{g.scope === 'all' ? L('كل الشركة', 'Whole company') : g.department ? bi(g.department, lang) : ''} · {g.reason}</span>
          <span className="block text-[12px] text-ink-3 num">{fmtDateTime(g.created_at ?? undefined, lang)}{g.status === 'approved' && g.expires_at ? ` → ${L('حتى', 'until')} ${fmtDateTime(g.expires_at, lang)}` : ''}{g.revoke_note ? ` · ${g.revoke_note}` : ''}</span></span>
        <Badge tone={tone(g.status)}>{label(g.status)}</Badge>
        {q.data?.can_revoke && (g.status === 'approved' || g.status.startsWith('pending')) && <Btn size="sm" kind="danger" onClick={() => setSel(g)}>{L('سحب', 'Revoke')}</Btn>}
      </li>)}</ul>}
    <DecisionDialog open={!!sel} onClose={() => setSel(null)} tone="danger" title={L('سحب الإذن', 'Revoke access')} confirmLabel={L('تأكيد السحب', 'Confirm revoke')}
      subject={sel?.user ? (lang === 'ar' ? sel.user.ar : sel.user.en) : ''} onConfirm={note => revoke.mutateAsync({ id: sel!.id, note }).then(() => toast(L('سُحب الإذن', 'Access revoked')))} />
  </Panel>
}
