import { useState } from 'react'
import { KeyRound, Loader2, Lock, LockOpen, Pencil, Plus, Search, ShieldOff, Unlock, Send, Settings2, MailCheck } from 'lucide-react'
import { useL, useLang, bi, fmtDateTime } from '../../../lib/store'
import { ApiError } from '../../../lib/api'
import { setQuery, useRoute } from '../../../lib/router'
import { useAdminUsers, useCreateUser, useUpdateUser, useUserAction, useSaveAccountSettings, type AdminUser, type UserAction, type AccountSettings } from '../../../lib/admin'
import { Badge, Btn, Empty, Field, Modal, PageHead, Panel, Seg, toast } from '../../../ui/kit'
import { DecisionDialog } from '../../../ui/decision'
import { InviteLinkModal } from '../../../ui/invite'

type Roles = { key: string; ar: string; en: string }[]

/** M16 «المستخدمون والأدوار»: الحسابات وأدوارها، إنشاء حساب برابط تفعيل، رابط كلمة مرور جديدة، إيقاف/تفعيل، فك القفل، إلغاء ربط التحقق بخطوتين */
export function Users() {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const [q, setQ] = useState(route.query.q ?? '')
  const r = useAdminUsers({ q: route.query.q, role: route.query.role, status: route.query.status }); const d = r.data
  const [edit, setEdit] = useState<AdminUser | null>(null); const [create, setCreate] = useState(false)
  const [ask, setAsk] = useState<{ u: AdminUser; a: UserAction } | null>(null)
  const [link, setLink] = useState<{ email: string; url: string; reset: boolean } | null>(null)
  const [settings, setSettings] = useState(false)
  const act = useUserAction()
  const roleName = (k: string) => { const x = d?.roles.find(r => r.key === k); return x ? bi(x, lang) : k }
  const resetLabel = (u: AdminUser) => u.activated ? L('إرسال رابط لتعيين كلمة مرور جديدة', 'Send a new-password link') : L('إعادة إرسال رابط التفعيل', 'Resend activation link')
  const label: Record<UserAction, string> = { 'reset-password': L('رابط كلمة المرور', 'Password link'), deactivate: L('إيقاف الحساب', 'Deactivate'), activate: L('تفعيل الحساب', 'Activate'),
    unlock: L('فك قفل الدخول', 'Unlock sign-in'), 'reset-mfa': L('إلغاء ربط التحقق بخطوتين', 'Reset two-step verification') }
  return <div className="max-w-[1200px] mx-auto space-y-5">
    <PageHead title={L('المستخدمون والأدوار', 'Users & roles')} sub={L('حسابات الشركة وأدوارها. الصلاحيات التفصيلية لكل دور من صفحة «الصلاحيات».', 'Company accounts and their roles. Detailed permissions per role are on the “Permissions” page.')}
      actions={<>{d?.can.settings && <Btn icon={<Settings2 size={16} />} onClick={() => setSettings(true)}>{L('سياسة الحسابات', 'Account policy')}</Btn>}
        {d?.can.create && <Btn kind="primary" icon={<Plus size={16} />} onClick={() => setCreate(true)}>{L('حساب جديد', 'New account')}</Btn>}</>} />
    <div className="flex flex-wrap items-center gap-2">
      <form className="relative flex-1 min-w-[220px] max-w-md" onSubmit={e => { e.preventDefault(); setQuery({ q: q || undefined }) }}>
        <Search size={15} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" /><input className="field !ps-9" value={q} onChange={e => setQ(e.target.value)} placeholder={L('اسم، بريد أو رقم وظيفي…', 'Name, email or employee code…')} /></form>
      <select className="field !w-auto" value={route.query.role ?? ''} onChange={e => setQuery({ role: e.target.value || undefined })}><option value="">{L('كل الأدوار', 'All roles')}</option>{d?.roles.map(x => <option key={x.key} value={x.key}>{bi(x, lang)}</option>)}</select>
      <Seg value={route.query.status ?? ''} onChange={v => setQuery({ status: v || undefined })} options={[{ v: '', label: L('الكل', 'All') }, { v: 'active', label: L('فعّال', 'Active') }, { v: 'inactive', label: L('موقوف', 'Inactive') }, { v: 'locked', label: L('مقفول', 'Locked') }, { v: 'pending', label: L('بانتظار التفعيل', 'Not activated') }]} />
    </div>
    <Panel pad={false}>{!d ? <div className="grid place-items-center py-16"><Loader2 className="animate-spin text-brand" /></div> : !d.data.length ? <Empty title={L('لا توجد حسابات', 'No accounts')} /> :
      <ul className="divide-y divide-line-2">{d.data.map(u => <li key={u.id} className={`flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3 ${u.active ? '' : 'opacity-60'}`}>
        <span className="flex-1 min-w-[220px]"><span className="block font-semibold">{bi(u.name, lang)} {u.employee && <span className="text-[12px] text-ink-3 ltr inline-block">{u.employee.code}</span>}</span>
          <span className="block text-[12.5px] text-ink-3 ltr text-start">{u.email}</span>
          <span className="flex flex-wrap gap-1 mt-1">{u.roles.map(k => <Badge key={k} tone={k === 'admin' ? 'danger' : k === 'employee' ? 'neutral' : 'info'}>{roleName(k)}</Badge>)}</span></span>
        <span className="text-[12px] text-ink-3 min-w-[150px]">{u.last_login_at ? <>{L('آخر دخول', 'Last sign-in')}: <span className="num">{fmtDateTime(u.last_login_at, lang)}</span></> : L('لم يدخل بعد', 'Never signed in')}
          <span className="flex flex-wrap gap-1 mt-1">{!u.active && <Badge>{L('موقوف', 'Inactive')}</Badge>}{u.locked && <Badge tone="danger">{L('مقفول مؤقتاً', 'Locked')}</Badge>}
            {!u.activated && (u.invite?.state === 'expired' ? <Badge tone="danger">{L('انتهى رابط التفعيل', 'Activation link expired')}</Badge> : <Badge tone="brass">{L('بانتظار التفعيل', 'Not activated')}</Badge>)}
            {u.activated && u.invite?.state === 'pending' && <Badge tone="info">{L('أُرسل رابط كلمة مرور', 'Password link sent')}</Badge>}
            {u.activated && u.must_change_password && <Badge tone="brass">{L('كلمة مرور مؤقتة', 'Temp password')}</Badge>}{(u.mfa || u.passkeys > 0) && <Badge tone="brand">2FA</Badge>}</span></span>
        {d.can.edit && <span className="flex flex-wrap gap-1">
          <IconBtn t={L('الأدوار', 'Roles')} onClick={() => setEdit(u)}><Pencil size={15} /></IconBtn>
          {u.active && <IconBtn t={resetLabel(u)} onClick={() => setAsk({ u, a: 'reset-password' })}>{u.activated ? <KeyRound size={15} /> : <Send size={15} />}</IconBtn>}
          {u.locked && <IconBtn t={label.unlock} onClick={() => setAsk({ u, a: 'unlock' })}><Unlock size={15} /></IconBtn>}
          {(u.mfa || u.passkeys > 0) && <IconBtn t={label['reset-mfa']} onClick={() => setAsk({ u, a: 'reset-mfa' })}><ShieldOff size={15} /></IconBtn>}
          {u.active ? <IconBtn t={label.deactivate} danger onClick={() => setAsk({ u, a: 'deactivate' })}><Lock size={15} /></IconBtn>
            : <IconBtn t={label.activate} onClick={() => setAsk({ u, a: 'activate' })}><LockOpen size={15} /></IconBtn>}
        </span>}
      </li>)}</ul>}</Panel>
    {edit && d && <RolesModal u={edit} roles={d.roles} onClose={() => setEdit(null)} />}
    {create && d && <CreateModal roles={d.roles} employees={d.employees} hours={d.settings.invite_hours} onClose={() => setCreate(false)} onDone={(email, url) => { setCreate(false); setLink({ email, url, reset: false }) }} />}
    <DecisionDialog open={!!ask} onClose={() => setAsk(null)} tone={ask?.a === 'deactivate' || ask?.a === 'reset-mfa' ? 'danger' : 'primary'} title={ask ? (ask.a === 'reset-password' ? resetLabel(ask.u) : label[ask.a]) : ''} confirmLabel={L('تأكيد', 'Confirm')}
      subject={ask && <>{bi(ask.u.name, lang)} <span className="ltr inline-block text-ink-3">{ask.u.email}</span>{ask.a === 'reset-password' && <div className="text-[12.5px] text-ink-3 mt-1">{ask.u.activated
        ? L('يصله رابط يختار به كلمة مرور جديدة. تبقى كلمة مروره الحالية صالحة حتى يستخدم الرابط، ويُفك قفل الدخول إن وُجد.', 'They receive a link to choose a new password. The current password keeps working until the link is used; any sign-in lock is cleared.')
        : L('يصله رابط تفعيل جديد، ويتوقف الرابط السابق.', 'They receive a new activation link; the previous one stops working.')}</div>}</>}
      onConfirm={() => act.mutateAsync({ id: ask!.u.id, action: ask!.a }).then(r => { if (r.link) setLink({ email: r.data.email, url: r.link, reset: r.data.activated }); else toast(L('تم', 'Done')) }).catch(e => { toast(e instanceof ApiError ? e.message : String(e)); throw e })} />
    {link && <InviteLinkModal email={link.email} link={link.url} reset={link.reset} onClose={() => setLink(null)} />}
    {settings && d && <LinkSettings s={d.settings} onClose={() => setSettings(false)} />}
  </div>
}

/** سياسة الحسابات: صلاحية الروابط + كلمة المرور + محاولات الدخول (ملاحظة الاختبار 81) */
function LinkSettings({ s, onClose }: { s: AccountSettings; onClose: () => void }) {
  const L = useL()
  const save = useSaveAccountSettings()
  const [v, setV] = useState<AccountSettings>({ invite_hours: s.invite_hours, pw_min: s.pw_min ?? 8, pw_upper: !!s.pw_upper, pw_symbol: !!s.pw_symbol, max_attempts: s.max_attempts ?? 5, lock_minutes: s.lock_minutes ?? 5 })
  const err = save.error instanceof ApiError ? save.error : null
  const num = (k: 'invite_hours' | 'pw_min' | 'max_attempts' | 'lock_minutes', label: string, min: number, max: number, hint?: string) =>
    <Field label={label} error={err?.field(k)} hint={hint}><input className="field num ltr text-start" type="number" min={min} max={max} value={v[k]} onChange={e => setV({ ...v, [k]: Number(e.target.value) })} /></Field>
  const tog = (k: 'pw_upper' | 'pw_symbol', label: string) => <label className="flex items-center gap-2 text-[13.5px] cursor-pointer"><input type="checkbox" className="accent-[var(--primary)] size-4" checked={v[k]} onChange={e => setV({ ...v, [k]: e.target.checked })} />{label}</label>
  return <Modal open onClose={onClose} width={620} title={L('سياسة الحسابات', 'Account policy')}
    footer={<><Btn onClick={() => setV({ invite_hours: 72, pw_min: 8, pw_upper: false, pw_symbol: false, max_attempts: 5, lock_minutes: 5 })}>{L('القيم الافتراضية', 'Defaults')}</Btn><span className="flex-1" />
      <Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate(v, { onSuccess: () => { toast(L('حُفظت', 'Saved')); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="space-y-5">
      <section><h4 className="font-semibold text-[14px] mb-2">{L('كلمة المرور', 'Password')}</h4>
        <div className="grid sm:grid-cols-2 gap-3 items-end">{num('pw_min', L('أقل عدد أحرف', 'Minimum length'), 6, 64, L('من 6 إلى 64. الحرف والرقم مطلوبان دائماً.', '6–64. A letter and a number are always required.'))}
          <div className="space-y-2 pb-1">{tog('pw_upper', L('حرف لاتيني كبير وآخر صغير', 'Upper- and lowercase letters'))}{tog('pw_symbol', L('رمز واحد على الأقل', 'At least one symbol'))}</div></div>
        <p className="text-[12px] text-ink-3 mt-2">{L('تنطبق عند اختيار كلمة مرور جديدة أو تغييرها؛ كلمات المرور الحالية تبقى صالحة.', 'Applies when a password is chosen or changed; current passwords keep working.')}</p></section>
      <section><h4 className="font-semibold text-[14px] mb-2">{L('محاولات الدخول', 'Sign-in attempts')}</h4>
        <div className="grid sm:grid-cols-2 gap-3">{num('max_attempts', L('المحاولات الخاطئة قبل القفل', 'Wrong attempts before lock'), 3, 20)}{num('lock_minutes', L('مدة القفل (دقيقة)', 'Lock duration (minutes)'), 1, 1440)}</div></section>
      <section><h4 className="font-semibold text-[14px] mb-2">{L('روابط الحساب', 'Account links')}</h4>
        {num('invite_hours', L('صلاحية رابط التفعيل ورابط كلمة المرور (ساعة)', 'Activation and password links valid for (hours)'), 1, 720, L('من 1 إلى 720. تنطبق على الروابط الجديدة.', '1–720. Applies to new links.'))}</section>
    </div>
  </Modal>
}

function IconBtn({ t, onClick, children, danger }: { t: string; onClick: () => void; children: React.ReactNode; danger?: boolean }) {
  return <button title={t} aria-label={t} onClick={onClick} className={`size-8 grid place-items-center rounded-lg border border-line hover:bg-sub ${danger ? 'text-danger' : 'text-ink-2'}`}>{children}</button>
}

function RoleChecks({ roles, value, onChange, lock }: { roles: Roles; value: string[]; onChange: (v: string[]) => void; lock?: string[] }) {
  const lang = useLang()
  return <div className="flex flex-wrap gap-2">{roles.map(r => <label key={r.key} className={`inline-flex items-center gap-1.5 rounded-lg border border-line px-2.5 h-8 text-[13px] ${lock?.includes(r.key) ? 'opacity-60' : 'cursor-pointer'}`}>
    <input type="checkbox" className="accent-[var(--brand)]" disabled={lock?.includes(r.key)} checked={value.includes(r.key)} onChange={e => onChange(e.target.checked ? [...value, r.key] : value.filter(x => x !== r.key))} />{bi(r, lang)}</label>)}</div>
}

function RolesModal({ u, roles, onClose }: { u: AdminUser; roles: Roles; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const save = useUpdateUser()
  const [v, setV] = useState(u.roles)
  const err = save.error instanceof ApiError ? save.error : null
  return <Modal open onClose={onClose} title={<>{L('أدوار', 'Roles of')} {bi(u.name, lang)}</>}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending || !v.length} onClick={() => save.mutate({ id: u.id, roles: v }, { onSuccess: () => { toast(L('حُفظت الأدوار', 'Roles saved')); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <RoleChecks roles={roles} value={v} onChange={setV} lock={u.employee ? ['employee'] : []} />
    {err && <div role="alert" className="mt-4 rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2">{err.message}</div>}
  </Modal>
}

function CreateModal({ roles, employees, hours, onClose, onDone }: { roles: Roles; employees: { id: number; code: string; ar: string; en: string; email: string }[]; hours: number; onClose: () => void; onDone: (email: string, link: string) => void }) {
  const L = useL(); const lang = useLang()
  const save = useCreateUser()
  const [kind, setKind] = useState<'employee' | 'standalone'>(employees.length ? 'employee' : 'standalone')
  const [emp, setEmp] = useState<number | ''>(''); const [email, setEmail] = useState(''); const [name, setName] = useState(''); const [nameEn, setNameEn] = useState('')
  const [v, setV] = useState<string[]>([])
  const err = save.error instanceof ApiError ? save.error : null
  return <Modal open onClose={onClose} title={L('حساب جديد', 'New account')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending || !v.length || (kind === 'employee' ? !emp : !email || !name)}
      onClick={() => save.mutate(kind === 'employee' ? { employee_id: Number(emp), roles: v } : { email, name, name_en: nameEn, roles: v }, { onSuccess: r => onDone(r.email, r.link) })}>{L('إنشاء', 'Create')}</Btn></>}>
    <div className="space-y-4">
      <Seg value={kind} onChange={setKind} options={[{ v: 'employee', label: L('لموظف', 'For an employee') }, { v: 'standalone', label: L('بلا ملف موظف', 'Without employee file') }]} />
      {kind === 'employee' ? <Field label={L('الموظف (بلا حساب)', 'Employee (no account yet)')} req error={err?.field('employee_id')}>
        <select className="field" value={emp} onChange={e => setEmp(e.target.value ? Number(e.target.value) : '')}><option value="">{employees.length ? L('اختر…', 'Choose…') : L('كل الموظفين لهم حسابات', 'Every employee has an account')}</option>
          {employees.map(e => <option key={e.id} value={e.id}>{bi(e, lang)} · {e.code} · {e.email}</option>)}</select></Field>
        : <div className="grid sm:grid-cols-2 gap-4">
          <Field label={L('البريد', 'Email')} req error={err?.field('email')} className="sm:col-span-2"><input className="field ltr text-start" type="email" value={email} onChange={e => setEmail(e.target.value)} /></Field>
          <Field label={L('الاسم', 'Name')} req error={err?.field('name')}><input className="field" value={name} onChange={e => setName(e.target.value)} /></Field>
          <Field label={L('الاسم بالإنكليزية', 'English name')}><input className="field ltr text-start" value={nameEn} onChange={e => setNameEn(e.target.value)} /></Field></div>}
      <Field label={L('الأدوار', 'Roles')} req div error={err?.field('roles')}><RoleChecks roles={roles.filter(r => r.key !== 'employee')} value={v} onChange={setV} /></Field>
      <p className="text-[12.5px] text-ink-3 flex items-start gap-1.5"><MailCheck size={14} className="shrink-0 mt-0.5" />{L(`يصل إلى البريد رابط تفعيل يختار به صاحب الحساب كلمة مروره، صالح ${hours} ساعة ولمرة واحدة.`, `An activation link is emailed so the owner chooses their own password. It works once and is valid for ${hours} hours.`)}</p>
      {err && !Object.keys(err.errors).length && <div role="alert" className="rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2">{err.message}</div>}
    </div>
  </Modal>
}
