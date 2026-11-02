import { useEffect, useState } from 'react'
import { Loader2, Send } from 'lucide-react'
import { useL } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { useMailSettings, useSaveMail, useTestMail, type MailSettings as Data } from '../../lib/platform'
import { Badge, Btn, Field, PageHead, Panel, Seg, toast } from '../../ui/kit'
import { MailLogList } from '../../ui/maillog'

/** المنصة: البريد الصادر لكل الشركات — خادم SMTP (بريد الشركة أو خدمة إرسال)، رسالة تجريبية، وسجل الرسائل */
export function MailSettings() {
  const L = useL()
  const q = useMailSettings(); const save = useSaveMail(); const test = useTestMail()
  const [v, setV] = useState<Data | null>(null); const [pw, setPw] = useState(''); const [to, setTo] = useState('')
  useEffect(() => { if (q.data && !v) setV(q.data) }, [q.data, v])
  const d = q.data
  if (!d || !v) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const err = save.error instanceof ApiError ? save.error : null
  const set = <K extends keyof Data>(k: K, x: Data[K]) => setV(s => s && ({ ...s, [k]: x }))
  const submit = (clear = false) => save.mutate({ host: v.host, port: Number(v.port), encryption: v.encryption, username: v.username, from_address: v.from_address, from_name: v.from_name, log_days: Number(v.log_days),
    ...(pw ? { password: pw } : {}), ...(clear ? { clear_password: true } : {}) }, { onSuccess: r => { setV(r); setPw(''); toast(L('حُفظ إعداد البريد', 'Mail settings saved')) } })
  return <div className="max-w-[1100px] mx-auto space-y-5">
    <PageHead title={L('البريد الصادر', 'Outgoing mail')} sub={L('الخادم الذي تُرسل منه كل رسائل النظام لكل الشركات: روابط التفعيل، كلمات المرور، الدعوات، الإشعارات. كل شركة تظهر باسمها كمرسل.', 'The server every system email is sent through, for every company: activation links, passwords, invitations, notices. Each company appears under its own sender name.')}
      actions={<Btn kind="primary" disabled={save.isPending} onClick={() => submit()}>{L('حفظ', 'Save')}</Btn>} />
    <Panel>
      <div className="flex flex-wrap items-center gap-3 text-[13.5px]">
        <span>{L('المصدر الحالي', 'Current source')}:</span>
        {d.source === 'settings' ? <Badge tone="brand">{L('الإعداد أدناه', 'The settings below')} · <span className="ltr">{d.host}</span></Badge>
          : <Badge tone="brass">{L('إعداد الخادم', 'Server environment')} · <span className="ltr">{d.server.host ?? '—'}</span></Badge>}
        <span className="text-ink-3">{L('آخر 24 ساعة', 'Last 24 h')}: <span className="num">{d.stats.sent}</span> {L('أُرسلت', 'sent')} · <span className={`num ${d.stats.failed ? 'text-danger font-semibold' : ''}`}>{d.stats.failed}</span> {L('فشلت', 'failed')}</span>
      </div>
      {d.source === 'server' && <p className="mt-2 text-[12.5px] text-ink-3">{L('الحقل «الخادم» فارغ، فتُستخدم إعدادات بيئة الخادم (صندوق التجربة الداخلي Mailpit غالباً — لا يصل منه بريد حقيقي). أدخل بيانات خادم بريد حقيقي ليبدأ الإرسال الفعلي.', 'Server is empty, so the server environment is used (usually the internal Mailpit test box — no real delivery). Enter a real mail server to start real delivery.')}</p>}
    </Panel>
    <Panel title={L('خادم SMTP', 'SMTP server')} sub={L('من مزوّد البريد: بريد الشركة (Google Workspace، Microsoft 365، استضافة النطاق) أو خدمة إرسال مثل Brevo أو Amazon SES.', 'From your mail provider: company mail (Google Workspace, Microsoft 365, domain hosting) or a sending service such as Brevo or Amazon SES.')}>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(240px,1fr))]">
        <Field label={L('الخادم', 'Server')} error={err?.field('host')} hint={L('مثل smtp.gmail.com — فارغ = إعداد الخادم', 'e.g. smtp.gmail.com — empty = server environment')}><input className="field ltr text-start" value={v.host} onChange={e => set('host', e.target.value)} /></Field>
        <Field label={L('المنفذ', 'Port')} error={err?.field('port')} hint="587 · 465"><input className="field num ltr text-start" type="number" value={v.port} onChange={e => set('port', Number(e.target.value))} /></Field>
        <Field label={L('التشفير', 'Encryption')} div><Seg value={v.encryption} onChange={x => set('encryption', x)} options={[{ v: 'tls', label: 'STARTTLS' }, { v: 'ssl', label: 'SSL' }, { v: 'none', label: L('بلا', 'None') }]} /></Field>
        <Field label={L('اسم المستخدم', 'Username')}><input className="field ltr text-start" value={v.username} onChange={e => set('username', e.target.value)} autoComplete="off" /></Field>
        <Field label={L('كلمة المرور', 'Password')} error={err?.field('password')} hint={d.password_set ? L('محفوظة — اتركها فارغة لإبقائها', 'Saved — leave empty to keep it') : L('غير محفوظة', 'Not set')}>
          <input className="field ltr text-start" type="password" value={pw} onChange={e => setPw(e.target.value)} autoComplete="new-password" placeholder={d.password_set ? '••••••••' : ''} /></Field>
        <Field label={L('عنوان المرسل', 'From address')} error={err?.field('from_address')} hint={L('مثل no-reply@atmata-group.com', 'e.g. no-reply@atmata-group.com')}><input className="field ltr text-start" type="email" value={v.from_address} onChange={e => set('from_address', e.target.value)} /></Field>
        <Field label={L('اسم المرسل الافتراضي', 'Default sender name')} error={err?.field('from_name')} hint={L('للرسائل التي لا تخص شركة', 'For mail that belongs to no company')}><input className="field" value={v.from_name} onChange={e => set('from_name', e.target.value)} /></Field>
        <Field label={L('حفظ السجل (أيام)', 'Keep the log (days)')} error={err?.field('log_days')}><input className="field num ltr text-start" type="number" min={7} max={730} value={v.log_days} onChange={e => set('log_days', Number(e.target.value))} /></Field>
      </div>
      {d.password_set && <button className="mt-3 text-[12.5px] text-danger underline" onClick={() => submit(true)}>{L('حذف كلمة المرور المحفوظة', 'Remove the saved password')}</button>}
      {err && !Object.keys(err.errors).length && <div role="alert" className="mt-3 rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2">{err.message}</div>}
    </Panel>
    <Panel title={L('رسالة تجريبية', 'Test message')} sub={L('احفظ أولاً، ثم أرسل رسالة إلى بريدك للتأكد. إن فشلت يظهر سبب الخطأ كما أعاده الخادم.', 'Save first, then send a message to your inbox. If it fails, the server’s error is shown as is.')}>
      <form className="flex flex-wrap gap-2" onSubmit={e => { e.preventDefault(); test.mutate(to, { onSuccess: r => toast(r.message), onError: x => toast(x.message) }) }}>
        <input className="field ltr text-start flex-1 min-w-[220px] max-w-sm" type="email" value={to} onChange={e => setTo(e.target.value)} placeholder="you@example.com" />
        <Btn type="submit" icon={test.isPending ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />} disabled={!to || test.isPending}>{L('إرسال', 'Send')}</Btn></form>
      {test.error instanceof ApiError && <div role="alert" className="mt-3 rounded-lg bg-danger-soft text-danger text-[12.5px] px-3 py-2 ltr text-start break-words">{test.error.message}</div>}
    </Panel>
    <Panel title={L('سجل البريد — كل الشركات', 'Mail log — all companies')}><MailLogList base="/platform/mail/log" showCompany /></Panel>
  </div>
}
