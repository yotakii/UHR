import { useState } from 'react'
import { LogOut, ShieldAlert, LayoutDashboard, UserRound, FileSearch } from 'lucide-react'
import { useL, useLang, bi } from '../lib/store'
import { ApiError } from '../lib/api'
import { useChangePassword, useLogout, type SessionUser } from '../lib/session'
import { Btn, Field, Badge, Avatar, Empty } from '../ui/kit'
import { Logo, LangToggle, ThemeToggle } from '../ui/brand'
import { PasswordFields, passwordOk } from './Public'

function Bar({ user }: { user: SessionUser }) {
  const L = useL(); const lang = useLang(); const logout = useLogout()
  return <header className="h-[68px] border-b border-line bg-canvas/70 backdrop-blur flex items-center justify-between gap-3 px-5 md:px-8 sticky top-0 z-20">
    <Logo h={30} />
    <div className="flex items-center gap-2">
      <span className="hidden md:flex items-center gap-2 me-2"><Avatar size={28} /><span className="text-[13px] font-semibold">{bi(user.name, lang)}</span></span>
      <LangToggle /><span className="hidden sm:inline-flex"><ThemeToggle /></span>
      <Btn size="sm" kind="ghost" icon={<LogOut size={15} />} onClick={() => logout.mutate()} disabled={logout.isPending}><span className="hidden sm:inline">{L('خروج', 'Sign out')}</span></Btn>
    </div>
  </header>
}

/** كلمة مرور مؤقتة: لا شيء قبل تغييرها */
export function ForceChangePassword({ user }: { user: SessionUser }) {
  const L = useL()
  const [cur, setCur] = useState('')
  const [v, setV] = useState({ password: '', password_confirmation: '' })
  const m = useChangePassword()
  const e = m.error instanceof ApiError ? m.error : null
  return <div className="min-h-full flex flex-col app-glow">
    <Bar user={user} />
    <main className="flex-1 max-w-md w-full mx-auto px-5 py-10">
      <section className="bg-surface border border-line rounded-2xl">
        <div className="p-6 border-b border-line-2 flex gap-3"><span className="size-10 rounded-full bg-brass-soft text-brass grid place-items-center shrink-0"><ShieldAlert size={20} /></span>
          <div><h1 className="text-[20px] font-bold">{L('غيّر كلمة المرور المؤقتة', 'Change your temporary password')}</h1>
            <p className="text-ink-2 text-[13px] mt-1">{L('لحماية حسابك، اختر كلمة مرور خاصة بك قبل المتابعة.', 'To protect your account, choose your own password before continuing.')}</p></div></div>
        <form className="p-6 space-y-4" noValidate onSubmit={x => { x.preventDefault(); m.mutate({ current_password: cur, ...v }) }}>
          <Field label={L('كلمة المرور المؤقتة', 'Temporary password')} req error={e?.field('current_password')}><input className="field ltr text-start" type="password" value={cur} onChange={x => { setCur(x.target.value); m.reset() }} autoComplete="current-password" autoFocus /></Field>
          <PasswordFields v={v} set={(k, x) => { setV(s => ({ ...s, [k]: x })); m.reset() }} errs={{ password: e?.field('password') }} />
          {e && !e.field('password') && !e.field('current_password') && <div role="alert" className="text-[13px] text-danger bg-danger-soft rounded px-3 py-2">{e.message}</div>}
          <Btn kind="primary" size="lg" className="w-full" type="submit" disabled={m.isPending || !cur || !passwordOk(v)}>{L('حفظ والمتابعة', 'Save and continue')}</Btn>
        </form>
      </section>
    </main>
  </div>
}

/** واجهة كل دور بعد الدخول — تُستبدل بالإطار الكامل: HR (المهمة 05)، المتقدم (04)، الموظف (07) */
export function PortalHome({ user }: { user: SessionUser }) {
  const L = useL(); const lang = useLang()
  const meta = user.portal === 'hr' ? { icon: <LayoutDashboard />, t: L('لوحة الموارد البشرية', 'HR console'), task: '05' }
    : user.portal === 'employee' ? { icon: <UserRound />, t: L('بوابة الموظف', 'Employee portal'), task: '07' }
    : { icon: <FileSearch />, t: L('بوابة المتقدم', 'Applicant portal'), task: '04' }
  return <div className="min-h-full flex flex-col app-glow">
    <Bar user={user} />
    <main className="flex-1 max-w-3xl w-full mx-auto px-5 py-10 space-y-4">
      <section className="bg-surface border border-line rounded-2xl p-6 flex items-center gap-4">
        <span className="size-12 rounded-2xl bg-brand-soft text-brand grid place-items-center">{meta.icon}</span>
        <div className="min-w-0 flex-1"><div className="text-[13px] text-ink-3">{meta.t}</div>
          <h1 className="text-[22px] font-bold">{L('أهلاً', 'Welcome')}، {bi(user.name, lang)}</h1>
          <div className="text-[13px] text-ink-2 mt-0.5">{user.employee ? <>{bi(user.employee.title, lang)} · {bi(user.employee.dept, lang)} · <span className="ltr">{user.employee.code}</span></> : user.candidate ? <span className="ltr">{user.candidate.ref}</span> : <span className="ltr">{user.email}</span>}</div></div>
      </section>
      <div className="flex flex-wrap gap-1.5">{user.roles.map(r => <Badge key={r} tone="brand">{r}</Badge>)}</div>
      <section className="rounded-2xl border border-dashed border-line bg-surface/60"><Empty title={meta.t}>{L(`تُبنى هذه الواجهة في المهمة ${meta.task}.`, `This portal is built in task ${meta.task}.`)}</Empty></section>
    </main>
  </div>
}
