import { useState } from 'react'
import { ArrowRight, ArrowLeft, KeyRound, Loader2, Lock, LockOpen, Power, Search, ShieldCheck } from 'lucide-react'
import { useL, useLang, bi, fmtDate, fmtDateTime } from '../../lib/store'
import { useAccountAction, useCompanyDetail, type AccountState, type CompanyAccount } from '../../lib/platform'
import { fmtSize } from '../../lib/admin'
import { Badge, Btn, Empty, PageHead, Panel, toast } from '../../ui/kit'
import { DecisionDialog } from '../../ui/decision'

/**
 * تفاصيل الشركة في لوحة المنصة (ملاحظتا 47/48): بوابتها، استخدامها للمنصة، وحساباتها وحالة اعتماد كل حساب.
 * لا تُعرض كلمات المرور أبداً — إرسال رابط تعيين لصاحب الحساب، فك القفل، الإيقاف والتفعيل، وكلها تُسجَّل.
 */
export function CompanyDetailPage({ id }: { id: number }) {
  const L = useL(); const lang = useLang()
  const [q, setQ] = useState('')
  const { data: d, isLoading } = useCompanyDetail(id, q)
  const act = useAccountAction(id)
  const [confirm, setConfirm] = useState<{ a: CompanyAccount; action: 'deactivate' | 'reset-password' } | null>(null)
  const Back = lang === 'ar' ? ArrowRight : ArrowLeft
  if (isLoading || !d) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const u = d.usage
  const state: Record<AccountState, [string, string, 'brand' | 'warn' | 'danger' | 'neutral' | 'info']> = {
    active: ['فعّال', 'Active', 'brand'], pending: ['بانتظار التفعيل', 'Not activated', 'info'], expired: ['انتهى رابط التفعيل', 'Link expired', 'warn'], locked: ['مقفل مؤقتاً', 'Locked', 'danger'], inactive: ['موقوف', 'Disabled', 'neutral'],
  }
  const run = (a: CompanyAccount, action: 'reset-password' | 'unlock' | 'deactivate' | 'activate') => act.mutateAsync({ user: a.id, action })
    .then(r => toast(r.sent ? L('أُرسل رابط تعيين كلمة المرور إلى بريد صاحب الحساب', 'A password link was emailed to the account owner') : L('تم', 'Done')))
  const tile = (k: string, v: React.ReactNode, sub?: React.ReactNode) => <div className="rounded-xl border border-line px-3 py-2.5"><div className="text-[12px] text-ink-3">{k}</div><div className="text-[18px] font-bold num">{v}</div>{sub && <div className="text-[11.5px] text-ink-3">{sub}</div>}</div>
  const emp = Object.entries(u.employees).reduce((a, [, n]) => a + n, 0)
  return <div className="max-w-[1200px] mx-auto space-y-4">
    <a href="/platform/companies" className="text-[13px] text-ink-3 hover:text-ink inline-flex items-center gap-1"><Back size={14} />{L('الشركات', 'Companies')}</a>
    <PageHead title={<span className="flex flex-wrap items-center gap-2">{bi(d.name, lang)}{d.status === 'active' ? <Badge tone="brand" dot>{L('فعّالة', 'Active')}</Badge> : <Badge tone="danger" dot>{L('معلّقة', 'Suspended')}</Badge>}</span>}
      sub={L(`أُنشئت ${fmtDate(d.created_at ?? undefined, 'ar')} · آخر نشاط ${u.last_activity_at ? fmtDateTime(u.last_activity_at, 'ar') : '—'}`, `Created ${fmtDate(d.created_at ?? undefined, 'en')} · last activity ${u.last_activity_at ? fmtDateTime(u.last_activity_at, 'en') : '—'}`)} />
    <Panel title={L('بوابة الشركة', 'Company portal')} sub={L('عناوين الدخول وفرص العمل بهوية الشركة — البيانات معزولة في كل الأحوال.', 'Sign-in and jobs addresses with the company identity — data is isolated in all cases.')}>
      <ul className="space-y-1">{d.portal_urls.map(x => <li key={x}><a className="ltr inline-block text-brand break-all" href={x} target="_blank" rel="noopener">{x}</a></li>)}</ul>
    </Panel>
    <Panel title={L('الاستخدام', 'Usage')}>
      <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(160px,1fr))]">
        {tile(L('الحسابات', 'Accounts'), u.users.total, L(`${u.users.active} فعّال · ${u.users.pending} بانتظار التفعيل`, `${u.users.active} active · ${u.users.pending} pending`))}
        {tile(L('دخلوا خلال 30 يوماً', 'Signed in (30 d)'), u.users.signed_in_30d, u.users.last_login_at ? L(`آخر دخول ${fmtDateTime(u.users.last_login_at, 'ar')}`, `Last ${fmtDateTime(u.users.last_login_at, 'en')}`) : undefined)}
        {tile(L('الموظفون', 'Employees'), emp, L(`${u.departments} إدارات`, `${u.departments} departments`))}
        {tile(L('الشواغر', 'Jobs'), u.jobs.total, L(`${u.jobs.open} مفتوحة`, `${u.jobs.open} open`))}
        {tile(L('طلبات التوظيف', 'Applications'), u.applications.total, L(`${u.applications.month} هذا الشهر`, `${u.applications.month} this month`))}
        {tile(L('أيام حضور (30 يوماً)', 'Attendance days (30 d)'), u.attendance_30d)}
        {tile(L('طلبات الموظفين (30 يوماً)', 'Employee requests (30 d)'), u.requests_30d)}
        {tile(L('الملفات', 'Files'), u.files.count, fmtSize(u.files.bytes))}
        {tile(L('بريد هذا الشهر', 'Mail this month'), u.mail_month.sent, u.mail_month.failed ? L(`${u.mail_month.failed} فشل`, `${u.mail_month.failed} failed`) : undefined)}
        {tile(L('قراءة السير هذا الشهر', 'CV readings this month'), u.cv_readings_month)}
        {tile(L('عمليات مسجّلة (30 يوماً)', 'Logged actions (30 d)'), u.audit_30d)}
        {tile(L('حسابات مقفلة / موقوفة', 'Locked / disabled'), `${u.users.locked} / ${u.users.inactive}`)}
      </div>
    </Panel>
    <Panel pad={false} title={L('الحسابات وحالة الاعتماد', 'Accounts & credential status')} sub={L('لا تُعرض كلمات المرور. «رابط كلمة مرور» يصل إلى بريد صاحب الحساب فقط.', 'Passwords are never shown. "Password link" goes only to the account owner\'s email.')}
      action={<label className="relative block w-full sm:w-64"><Search size={15} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" />
        <input className="field !ps-9" value={q} onChange={e => setQ(e.target.value)} placeholder={L('بريد، اسم، رقم وظيفي…', 'Email, name, ID…')} /></label>}>
      {d.accounts.length === 0 ? <Empty title={L('لا حسابات', 'No accounts')} /> :
        <table className="tbl"><thead><tr><th>{L('الحساب', 'Account')}</th><th className="hidden md:table-cell">{L('الأدوار', 'Roles')}</th><th>{L('الحالة', 'Status')}</th><th className="hidden lg:table-cell">{L('آخر دخول', 'Last sign-in')}</th><th /></tr></thead>
          <tbody>{d.accounts.map(a => <tr key={a.id}>
            <td><div className="font-medium">{bi(a.name, lang)}</div><div className="text-[12px] text-ink-3 ltr text-start break-all">{a.email}</div>{a.code && <div className="text-[12px] text-ink-3 ltr text-start">{a.code}</div>}</td>
            <td className="hidden md:table-cell"><div className="flex flex-wrap gap-1">{a.roles.map(r => <Badge key={r.key}>{bi(r, lang)}</Badge>)}</div></td>
            <td><div className="flex flex-col items-start gap-1"><Badge tone={state[a.state][2]}>{L(state[a.state][0], state[a.state][1])}</Badge>
              {a.temp_password && <span className="text-[11.5px] text-warn">{L('كلمة مؤقتة', 'Temporary password')}</span>}
              {(a.mfa || a.passkeys > 0) && <span className="text-[11.5px] text-ink-3 inline-flex items-center gap-1"><ShieldCheck size={12} />{L('تحقق ثنائي', '2-step')}</span>}
              {a.failed_logins > 0 && <span className="text-[11.5px] text-ink-3 num">{L(`${a.failed_logins} محاولة خاطئة`, `${a.failed_logins} failed tries`)}</span>}</div></td>
            <td className="hidden lg:table-cell num text-[12.5px]">{a.last_login_at ? fmtDateTime(a.last_login_at, lang) : '—'}</td>
            <td><div className="flex flex-wrap justify-end gap-1">
              {a.state !== 'inactive' && <Btn size="sm" icon={<KeyRound size={13} />} onClick={() => setConfirm({ a, action: 'reset-password' })}>{L('رابط كلمة مرور', 'Password link')}</Btn>}
              {a.state === 'locked' && <Btn size="sm" icon={<LockOpen size={13} />} onClick={() => run(a, 'unlock')}>{L('فك القفل', 'Unlock')}</Btn>}
              {a.state === 'inactive' ? <Btn size="sm" icon={<Power size={13} />} onClick={() => run(a, 'activate')}>{L('تفعيل', 'Enable')}</Btn>
                : <Btn size="sm" kind="danger" icon={<Lock size={13} />} onClick={() => setConfirm({ a, action: 'deactivate' })}>{L('إيقاف', 'Disable')}</Btn>}
            </div></td></tr>)}</tbody></table>}
    </Panel>
    {confirm && <DecisionDialog open onClose={() => setConfirm(null)} tone={confirm.action === 'deactivate' ? 'danger' : 'primary'}
      title={confirm.action === 'deactivate' ? L('إيقاف الحساب', 'Disable account') : L('إرسال رابط تعيين كلمة المرور', 'Send a password link')}
      subject={<><div className="font-semibold">{bi(confirm.a.name, lang)}</div><div className="ltr text-start text-[13px] text-ink-3">{confirm.a.email}</div>
        <div className="text-[12.5px] text-ink-2 mt-1">{confirm.action === 'deactivate' ? L('لن يتمكن من الدخول حتى يُعاد تفعيله.', 'They cannot sign in until it is enabled again.') : L('يصل الرابط إلى بريد صاحب الحساب فقط، وكلمة مروره الحالية تبقى صالحة حتى يستخدمه.', 'The link goes only to the owner\'s email; the current password keeps working until it is used.')}</div></>}
      confirmLabel={confirm.action === 'deactivate' ? L('تأكيد الإيقاف', 'Confirm') : L('إرسال', 'Send')}
      onConfirm={() => run(confirm.a, confirm.action)} />}
  </div>
}
