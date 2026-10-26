import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import qrcode from 'qrcode-generator'
import { Lock, LockOpen, ShieldCheck, Mail, FingerprintPattern, KeyRound, CircleAlert, Timer, ChevronLeft, ChevronRight, Copy, Check, Smartphone, UserCheck, Hourglass } from 'lucide-react'
import { useL, useLang } from '../lib/store'
import { fmtDateTime } from '../lib/dates'
import { ApiError } from '../lib/api'
import { queryClient } from '../lib/session'
import { useVault, useVaultDialog, openVault, closeVault, vaultCall, lockVault, passkeyAuth, passkeySupported, deviceLabel, mmss, grantActive, linkPoll, useMyGrants, useRequestGrant, useCancelGrant, type VaultState, type LinkStart } from '../lib/vault'
import { Btn, Field, Seg, toast } from './kit'

// حماية الرواتب بخطوتين (SRS §4.4) — منقولة من الديمو v26: القيمة المقنّعة، الكتلة المحمية، زر الحالة في الهيدر، ونافذة التحقق.

/** قيمة مال: تظهر بعد التحقق، وإلا زر مقنّع •••••• يفتح نافذة التحقق */
export function Masked({ children, compact, value, plain, others }: { children: ReactNode; compact?: boolean; value?: unknown; plain?: boolean; others?: boolean }) {
  const { unlocked } = useVault()
  const L = useL()
  if (unlocked && value !== null) return <>{children}</>
  const ctx = others ? 'others' : 'self'
  const cls = 'inline-flex items-center gap-1.5 rounded-md border border-dashed border-line bg-sub px-2 h-6 text-[12px] text-ink-3 align-middle'
  const inner = <><Lock size={12} /><span className="tracking-[.25em] leading-none">{compact ? '•••' : '••••••'}</span></>
  // plain: داخل زر آخر (لا أزرار متداخلة) — شكل مقنّع فقط
  if (plain) return <span className={cls} title={L('محمي', 'Protected')}>{inner}</span>
  return <button type="button" onClick={e => { e.preventDefault(); e.stopPropagation(); openVault(ctx) }} title={unlocked ? L('يحتاج إذن اطلاع', 'Needs salary access') : L('محمي — تحقق من هويتك للعرض', 'Protected — verify to view')}
    className={`${cls} hover:text-brand hover:border-brand/50`}>{inner}</button>
}

/** كتلة كاملة محمية: بطاقة «بيانات الرواتب محمية» وزر «تحقق من هويتي» */
export function ProtectedBlock({ children, title, className = '', masked, others }: { children: ReactNode; title?: ReactNode; className?: string; masked?: boolean; others?: boolean }) {
  const { unlocked, state } = useVault()
  const L = useL()
  if (unlocked && !masked) return <>{children}</>
  // بعد التحقق: رواتب الآخرين تحتاج إذناً معتمداً من المدير العام (10b)
  if (unlocked && others && state && !grantActive(state)) {
    const pending = state.grant.last?.status.startsWith('pending')
    return <div className={`rounded-2xl border border-dashed border-line bg-sub/50 px-6 py-10 text-center ${className}`}>
      <span className="mx-auto size-14 rounded-2xl grid place-items-center bg-brass/15 text-brass">{pending ? <Hourglass size={24} /> : <UserCheck size={24} />}</span>
      <div className="mt-3 font-bold text-[16px]">{pending ? L('طلب الإذن بانتظار الموافقة', 'Access request awaiting approval') : L('تحتاج إذن اطلاع على الرواتب', 'You need salary access')}</div>
      <p className="mt-1 text-[13px] text-ink-3 max-w-sm mx-auto">{pending ? L('سيصلك إشعار عند الموافقة وتظهر المبالغ هنا.', 'You’ll be notified when it’s approved and the amounts will show here.')
        : L(`رواتب الموظفين الآخرين تظهر بإذن من المدير العام، يصلح ${state.grant.hours} ساعات ثم يُجدَّد.`, `Other employees’ salaries need access from the general manager, valid ${state.grant.hours} hours, then renewed.`)}</p>
      <Btn kind="primary" className="mt-4" icon={<UserCheck size={16} />} onClick={() => openVault('others')}>{pending ? L('عرض الطلب', 'View request') : L('طلب إذن', 'Request access')}</Btn>
    </div>
  }
  return <div className={`rounded-2xl border border-dashed border-line bg-sub/50 px-6 py-10 text-center ${className}`}>
    <span className="mx-auto size-14 rounded-2xl grid place-items-center bg-brand-soft text-brand"><Lock size={24} /></span>
    <div className="mt-3 font-bold text-[16px]">{title ?? L('بيانات الرواتب محمية', 'Salary data is protected')}</div>
    <p className="mt-1 text-[13px] text-ink-3 max-w-sm mx-auto">{L('لا تُعرض الرواتب إلا بعد التحقق من الهوية — برمز لمرة واحدة أو بالبصمة أو بتطبيق المصادقة.', 'Salaries are shown only after identity verification — a one-time code, biometrics or an authenticator app.')}</p>
    <Btn kind="primary" className="mt-4" icon={<ShieldCheck size={16} />} onClick={() => openVault(others ? 'others' : 'self')}>{L('تحقق من هويتي', 'Verify it’s me')}</Btn>
  </div>
}

/** زر الحالة في الهيدر: «الرواتب مقفلة» أو «الرواتب مفتوحة» مع عدّاد؛ النقر وهي مفتوحة يقفلها فوراً */
export function VaultButton({ others }: { others?: boolean }) {
  const { unlocked, remaining } = useVault()
  const L = useL()
  return unlocked
    ? <button onClick={() => { void lockVault().then(() => toast(L('أُقفلت بيانات الرواتب', 'Salary data locked'))) }} title={L('اضغط للقفل الآن', 'Click to lock now')}
      className="h-9 px-2.5 rounded-lg border border-brand/50 bg-brand-soft text-brand flex items-center gap-1.5 text-[12.5px] font-semibold">
      <LockOpen size={15} /><span className="hidden xl:inline whitespace-nowrap">{L('الرواتب مفتوحة', 'Pay unlocked')}</span><span className="num ltr">{mmss(remaining)}</span></button>
    : <button onClick={() => openVault(others ? 'others' : 'self')} title={L('الرواتب مقفلة', 'Salaries locked')}
      className="h-9 px-2.5 rounded-lg border border-line bg-surface hover:bg-sub flex items-center gap-1.5 text-[12.5px] text-ink-3">
      <Lock size={15} /><span className="hidden xl:inline whitespace-nowrap">{L('الرواتب مقفلة', 'Pay locked')}</span></button>
}

/** ستة مربعات للرمز: لصق كامل، تنقّل تلقائي، Backspace للخلف */
export function OtpInput({ onDone, disabled, resetKey }: { onDone: (code: string) => void; disabled?: boolean; resetKey: number }) {
  const [d, setD] = useState(['', '', '', '', '', ''])
  const refs = useRef<(HTMLInputElement | null)[]>([])
  useEffect(() => { setD(['', '', '', '', '', '']); const t = setTimeout(() => refs.current[0]?.focus(), 50); return () => clearTimeout(t) }, [resetKey])
  const put = (i: number, v: string) => {
    const x = v.replace(/\D/g, '')
    if (!x && v) return
    const n = [...d]
    if (x.length > 1) x.slice(0, 6 - i).split('').forEach((c, k) => { n[i + k] = c }); else n[i] = x
    setD(n)
    const next = n.findIndex(c => !c)
    if (x) refs.current[next === -1 ? 5 : next]?.focus()
    if (n.every(Boolean)) onDone(n.join(''))
  }
  return <div className="flex justify-center gap-2 ltr" dir="ltr">
    {d.map((c, i) => <input key={i} ref={el => { refs.current[i] = el }} value={c} disabled={disabled} inputMode="numeric" autoComplete={i === 0 ? 'one-time-code' : 'off'} maxLength={6} aria-label={`digit ${i + 1}`}
      onChange={e => put(i, e.target.value)} onKeyDown={e => { if (e.key === 'Backspace' && !d[i] && i > 0) refs.current[i - 1]?.focus() }} onFocus={e => e.target.select()}
      className="size-12 sm:size-13 rounded-xl border border-line bg-canvas text-center text-[22px] font-bold num focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/30 disabled:opacity-50" />)}
  </div>
}

export function QR({ text, size = 200 }: { text: string; size?: number }) {
  const { n, path } = useMemo(() => {
    const q = qrcode(0, 'M'); q.addData(text); q.make()
    const n = q.getModuleCount(); const p: string[] = []
    for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) if (q.isDark(r, c)) p.push(`M${c} ${r}h1v1h-1z`)
    return { n, path: p.join('') }
  }, [text])
  return <svg viewBox={`-4 -4 ${n + 8} ${n + 8}`} width={size} height={size} shapeRendering="crispEdges" className="bg-white rounded-xl" role="img" aria-label="QR">
    <rect x="-4" y="-4" width={n + 8} height={n + 8} fill="#fff" /><path d={path} fill="#0f172a" /></svg>
}

/** شريط الثواني المتبقية من خطوة الـ 30 ثانية */
function StepBar() {
  const left = () => 30 - Math.floor(Date.now() / 1000) % 30
  const [s, setS] = useState(left())
  useEffect(() => { const t = setInterval(() => setS(left()), 500); return () => clearInterval(t) }, [])
  return <div className="mx-auto mt-3 w-40"><div className="h-1 rounded-full bg-sub overflow-hidden"><div className="h-full bg-brand transition-[width] duration-500" style={{ width: `${s / 30 * 100}%` }} /></div></div>
}

const STORES = { android: 'https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2', ios: 'https://apps.apple.com/app/google-authenticator/id388497605' }

function GetApp() {
  const L = useL()
  return <div className="rounded-xl border border-line bg-sub/60 p-3">
    <div className="text-[12.5px] font-semibold text-center">{L('ليس لديك التطبيق؟ امسح الرمز بكاميرا الجوال لتنزيل Google Authenticator', 'No app yet? Scan with your phone camera to get Google Authenticator')}</div>
    <div className="mt-2 flex justify-center gap-6">{([['android', 'Android'], ['ios', 'iPhone']] as const).map(([k, t]) =>
      <a key={k} href={STORES[k]} target="_blank" rel="noreferrer" className="flex flex-col items-center gap-1 text-[12px] text-ink-2"><QR text={STORES[k]} size={92} />{t}</a>)}</div>
  </div>
}

type Step = 'link' | 'grant' | 'choose' | 'phone' | 'passkey' | 'intro' | 'scan' | 'backup' | 'verify' | 'useBackup' | 'reset'

/** نافذة «تحقق من هويتك» — تُركَّب مرة واحدة في كل إطار (HR والموظف) */
export function VaultDialog() {
  const open = useVaultDialog()
  if (!open) return null
  return <VaultFlow ctx={open} />
}

function VaultFlow({ ctx }: { ctx: 'self' | 'others' }) {
  const L = useL(); const lang = useLang()
  const { state: v, lockout } = useVault()
  const [step, setStep] = useState<Step>('choose')
  const [err, setErr] = useState('')
  const [nonce, setNonce] = useState(0)
  const [busy, setBusy] = useState(false)
  const [enrol, setEnrol] = useState<{ secret: string; uri: string } | null>(null)
  const [codes, setCodes] = useState<string[]>([])
  const [text, setText] = useState('')
  const [copied, setCopied] = useState(false)
  const [otpLeft, setOtpLeft] = useState(0)
  useEffect(() => { const k = (e: KeyboardEvent) => e.key === 'Escape' && closeVault(); window.addEventListener('keydown', k); return () => window.removeEventListener('keydown', k) }, [])
  useEffect(() => { if (otpLeft <= 0) return; const t = setTimeout(() => setOtpLeft(s => s - 1), 1000); return () => clearTimeout(t) }, [otpLeft])
  // البداية: بعد التحقق ومن بيانات الآخرين ← طلب الإذن؛ وإلا آخر طريقة نجحت مباشرة (البصمة تُطلب فوراً — لمسة واحدة)
  const [started, setStarted] = useState(false)
  useEffect(() => {
    if (!v || started) return
    setStarted(true)
    if (v.unlocked) { setStep(ctx === 'others' && !grantActive(v) ? 'grant' : 'choose'); if (ctx !== 'others' || grantActive(v)) closeVault(); return }
    const m = v.last_ok?.method
    if (m === 'passkey' && v.methods.passkey && passkeySupported() && v.passkeys.length) { setStep('passkey'); void doPasskey(false) }
    else if ((m === 'app' || m === 'backup') && v.methods.app && v.enrolled) setStep('verify')
    else if (m === 'phone' && v.methods.phone) { setStep('phone'); if (!v.otp || v.otp.expires_in < 10) void sendOtp(); else setOtpLeft(v.otp.expires_in) }
    else if (m === 'link' && v.methods.link) setStep('link')
  }, [v]) // eslint-disable-line react-hooks/exhaustive-deps
  if (!v) return null

  const fail = (m: string) => { setErr(m); setNonce(n => n + 1) }
  const done = (m = L('تم التحقق — الرواتب مفتوحة وتبقى طالما تستخدمها', 'Verified — salaries stay unlocked while you use them')) => {
    toast(m.replace('{n}', String(v.unlock_minutes)))
    const s = queryClient.getQueryData<VaultState>(['mfa'])
    if (ctx === 'others' && s && !grantActive(s)) { setStep('grant'); setErr('') } else closeVault()
  }
  async function doPasskey(register: boolean) {
    setBusy(true); setErr('')
    try { await passkeyAuth(register, deviceLabel()); done() }
    catch (e) {
      const n = (e as { name?: string })?.name
      if (e instanceof ApiError) onError(e)
      else if (n === 'NotAllowedError') fail(L('أُلغي التحقق أو انتهت مهلته. أعد المحاولة.', 'Cancelled or timed out. Try again.'))
      else if (n === 'InvalidStateError') fail(L('هذا الجهاز مسجّل مسبقاً — استخدم «تحقق الآن».', 'This device is already registered — use “Verify now”.'))
      else fail(L('تعذّر استخدام البصمة على هذا الجهاز.', 'Couldn’t use biometrics on this device.'))
    } finally { setBusy(false) }
  }
  /** خطأ من الخادم: الإيقاف يأتي مع الحالة، والباقي نصه ثنائي اللغة جاهز */
  const onError = (e: unknown) => {
    if (e instanceof ApiError && e.code === 'locked') { void queryClient.invalidateQueries({ queryKey: ['mfa'] }); return fail('') }
    fail(e instanceof ApiError ? e.message : L('تعذّر الاتصال بالخادم.', 'Could not reach the server.'))
  }
  const run = async (f: () => Promise<unknown>, ok?: () => void) => {
    setBusy(true); setErr('')
    try { await f(); (ok ?? done)() } catch (e) { onError(e) } finally { setBusy(false) }
  }
  const sendOtp = () => run(async () => { const s = await vaultCall<VaultState>('/otp/send'); setOtpLeft(s.otp?.expires_in ?? 0) }, () => setNonce(n => n + 1))
  const startApp = () => { setStep(v.enrolled ? 'verify' : 'intro'); setErr(''); setNonce(n => n + 1) }
  const startEnrol = () => run(async () => { setEnrol(await vaultCall<{ secret: string; uri: string }>('/totp/enrol')); setStep('scan') }, () => setNonce(n => n + 1))
  const canPasskey = passkeySupported()
  const hasPasskey = v.passkeys.length > 0
  const locked = lockout > 0
  const lockMsg = L(`محاولات كثيرة خاطئة. أعد المحاولة بعد ${lockout} ثانية.`, `Too many wrong attempts. Try again in ${lockout}s.`)
  const Arrow = lang === 'ar' ? ChevronLeft : ChevronRight

  const head = (icon: ReactNode, title: ReactNode, sub: ReactNode) => <div className="text-center mb-5">
    <span className="mx-auto size-14 rounded-2xl grid place-items-center bg-brand-soft text-brand">{icon}</span>
    <div className="mt-3 font-bold text-[17px]">{title}</div><p className="text-[13px] text-ink-3 mt-1 max-w-sm mx-auto">{sub}</p></div>
  const alert = (locked ? lockMsg : err) && <div className="mt-4 rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2 text-center flex items-center justify-center gap-2"><CircleAlert size={15} className="shrink-0" />{locked ? lockMsg : err}</div>
  const back = <button onClick={() => { setStep('choose'); setErr('') }} className="text-[12.5px] text-ink-3 hover:text-ink">{lang === 'ar' ? '→' : '←'} {L('طرق تحقق أخرى', 'Other methods')}</button>

  let body: ReactNode = null
  if (step === 'link') body = <LinkStep onDone={() => done()} back={back} />
  else if (step === 'grant') body = <GrantStep />
  else if (step === 'choose') {
    const item = (icon: ReactNode, title: ReactNode, sub: ReactNode, on: () => void, o: { badge?: string; disabled?: boolean } = {}) =>
      <button disabled={o.disabled} onClick={on} className="w-full flex items-center gap-3 rounded-xl border border-line bg-canvas/40 p-3.5 text-start hover:border-brand/60 hover:bg-brand-soft/40 disabled:opacity-45 disabled:pointer-events-none">
        <span className="size-11 shrink-0 rounded-xl grid place-items-center bg-brand-soft text-brand">{icon}</span>
        <span className="flex-1 min-w-0"><span className="flex items-center gap-2 font-semibold text-[14px]">{title}{o.badge && <span className="rounded-md bg-brand text-on-brand px-1.5 py-0.5 text-[10.5px]">{o.badge}</span>}</span>
          <span className="block text-[12px] text-ink-3 mt-0.5">{sub}</span></span><Arrow size={16} className="text-ink-3" /></button>
    body = <>
      {head(<ShieldCheck size={26} />, L('تحقق من هويتك', 'Verify it’s you'), L('اختر طريقة التحقق لعرض بيانات الرواتب.', 'Choose how to verify before salary data is shown.'))}
      <div className="space-y-2.5">
        {v.methods.link && item(<Smartphone size={22} />, L('الموافقة من جوالك', 'Approve on your phone'),
          L('امسح رمز QR بكاميرا الجوال وأنت مسجّل الدخول، ثم اضغط «موافق» — دون إدخال رموز', 'Scan the QR with your signed-in phone and tap “Approve” — no codes'), () => { setStep('link'); setErr('') }, { badge: L('الأسهل', 'Easiest') })}
        {v.methods.phone && item(<Mail size={22} />, L('رمز على بريدك', 'Code by email'),
          <>{L('نرسل رمزاً من 6 أرقام إلى', 'We send a 6-digit code to')} <span className="ltr inline-block">{v.destination}</span> {L(`— صالح لمدة ${v.otp_minutes} دقيقة ولمرة واحدة`, `— valid ${v.otp_minutes} min, once`)}</>,
          () => { setStep('phone'); setErr(''); if (!v.otp || v.otp.expires_in < 10) void sendOtp(); else setOtpLeft(v.otp.expires_in) })}
        {v.methods.passkey && item(<FingerprintPattern size={22} />, L('البصمة / Windows Hello', 'Fingerprint / Windows Hello'),
          canPasskey ? hasPasskey ? L('تأكيد بالبصمة أو الوجه أو PIN الجهاز', 'Confirm with fingerprint, face or device PIN') : L('سجّل بصمة هذا الجهاز مرة واحدة ثم تأكيد بلمسة', 'Register this device once, then one touch')
            : L('يتطلب فتح النظام من رابط https', 'Requires opening the system from an https link'), () => { setStep('passkey'); setErr('') }, { disabled: !canPasskey })}
        {v.methods.app && item(<KeyRound size={22} />, L('تطبيق المصادقة', 'Authenticator app'), L('Google / Microsoft Authenticator — الأعلى أماناً', 'Google / Microsoft Authenticator — strongest'), startApp)}
      </div>
      {alert}
      {v.last_ok && <div className="mt-4 pt-3 border-t border-line-2 text-[11.5px] text-ink-3 text-center">{L('آخر وصول ناجح', 'Last successful access')}: <span className="num">{fmtDateTime(v.last_ok.at, lang)}</span></div>}
    </>
  } else if (step === 'phone') {
    body = <>
      {head(<Mail size={26} />, L('أدخل الرمز المرسل إلى بريدك', 'Enter the code sent to your email'), v.destination ? <span className="ltr inline-block">{v.destination}</span> : null)}
      <div className="flex items-center justify-center gap-3 text-[12.5px] mb-4">
        {otpLeft > 0 ? <span className={`inline-flex items-center gap-1 num ${otpLeft < 20 ? 'text-danger' : 'text-ink-3'}`}><Timer size={14} />{mmss(otpLeft)}</span>
          : <span className="text-ink-3">{L('انتهت صلاحية الرمز', 'Code expired')}</span>}
        <button className="text-brand font-semibold disabled:opacity-50" disabled={busy} onClick={() => void sendOtp()}>{L('رمز جديد', 'New code')}</button>
      </div>
      <OtpInput resetKey={nonce} disabled={busy || locked} onDone={c => void run(() => vaultCall('/otp/verify', { code: c }))} />
      {alert}
      <div className="mt-3 text-[11.5px] text-ink-3 text-center">{L('الرمز صالح لمرة واحدة. إن لم يصل خلال دقيقة تحقق من مجلد الرسائل غير المرغوب فيها.', 'The code works once. If it doesn’t arrive within a minute, check your spam folder.')}</div>
      <div className="mt-3 text-center">{back}</div>
    </>
  } else if (step === 'passkey') {
    body = <>
      {head(<FingerprintPattern size={26} />, hasPasskey ? L('تأكيد بالبصمة', 'Confirm with biometrics') : L('تسجيل البصمة لهذا الجهاز', 'Register biometrics on this device'),
        hasPasskey ? L('ستظهر نافذة الجهاز — استخدم البصمة أو الوجه أو رمز PIN.', 'Your device will ask for fingerprint, face or PIN.') : L('مرة واحدة فقط: يطلب الجهاز بصمتك أو Windows Hello، وبعدها يكفي لمسة للتحقق.', 'One time only: your device asks for fingerprint or Windows Hello; afterwards one touch is enough.'))}
      <Btn kind="primary" className="w-full" disabled={busy || locked} icon={<FingerprintPattern size={16} />} onClick={() => void doPasskey(!hasPasskey)}>{busy ? L('بانتظار الجهاز…', 'Waiting for device…') : hasPasskey ? L('تحقق الآن', 'Verify now') : L('تسجيل وتحقق', 'Register & verify')}</Btn>
      {hasPasskey && <button onClick={() => void run(() => passkeyAuth(true, deviceLabel()))} disabled={busy || locked} className="mt-3 w-full text-[12.5px] text-brand font-semibold disabled:opacity-50">{L('تسجيل هذا الجهاز أيضاً', 'Register this device too')}</button>}
      {alert}
      {hasPasskey && <div className="mt-4 rounded-xl border border-line-2 divide-y divide-line-2">{v.passkeys.map(p => <div key={p.id} className="flex items-center gap-2 px-3 py-2 text-[12.5px]">
        <FingerprintPattern size={14} className="text-ink-3" /><span className="flex-1 min-w-0 truncate">{p.label ?? L('جهاز', 'Device')}{p.last_used_at && <span className="text-ink-3 num"> · {fmtDateTime(p.last_used_at, lang)}</span>}</span>
        <button className="text-ink-3 hover:text-danger" disabled={busy} onClick={() => void run(() => vaultCall(`/passkey/${p.id}`, undefined, 'DELETE'), () => toast(L('أُزيلت البصمة المسجّلة', 'Biometric removed')))}>{L('إزالة البصمة المسجّلة', 'Remove biometric')}</button></div>)}</div>}
      <div className="mt-4">{back}</div>
    </>
  } else if (step === 'intro') {
    body = <>
      {head(<KeyRound size={26} />, L('حماية بيانات الرواتب', 'Protect salary data'), L('قبل عرض أي راتب نتحقق أنك صاحب الحساب فعلاً — عبر رمز يتغيّر كل 30 ثانية على جوالك.', 'Before showing any salary we confirm it’s really you — with a code on your phone that changes every 30 seconds.'))}
      <ol className="space-y-2.5 text-[13.5px]">{[
        L('ثبّت تطبيق Google Authenticator أو Microsoft Authenticator على جوالك (مجاني).', 'Install Google Authenticator or Microsoft Authenticator on your phone (free).'),
        L('امسح رمز QR الذي سيظهر لك في الخطوة التالية.', 'Scan the QR code shown in the next step.'),
        L('أدخل الرمز المكوّن من 6 أرقام — وانتهى. لا يحتاج إنترنت.', 'Enter the 6-digit code — done. No internet needed.'),
      ].map((t, i) => <li key={i} className="flex gap-3"><span className="size-6 shrink-0 rounded-full bg-sub grid place-items-center text-[12px] font-bold num">{i + 1}</span><span className="pt-0.5">{t}</span></li>)}</ol>
      <div className="mt-5"><GetApp /></div>
      <Btn kind="primary" className="w-full mt-5" disabled={busy || locked} onClick={() => void startEnrol()}>{L('التطبيق جاهز على جوالي — متابعة', 'The app is on my phone — continue')}</Btn>
      {alert}
      <div className="mt-3 text-center">{back}</div>
    </>
  } else if (step === 'scan' && enrol) {
    body = <>
      <div className="text-center text-[13.5px] text-ink-2">{L('افتح تطبيق Google Authenticator ← زر (+) ← «مسح رمز QR»', 'Open Google Authenticator → (+) → “Scan a QR code”')}</div>
      <div className="mt-2 mb-4 rounded-lg bg-warn-soft text-warn text-[12.5px] px-3 py-2 text-center">{L('امسح الرمز من داخل التطبيق وليس بكاميرا الجوال — الكاميرا وحدها لا تُظهر أي رقم. بعد المسح يظهر حساب UHR داخل التطبيق برقم من 6 خانات يتجدد كل 30 ثانية.', 'Scan from inside the app, not the phone camera — the camera alone shows no number. After scanning, a UHR entry with a 6-digit code (renews every 30 s) appears in the app.')}</div>
      <div className="flex justify-center"><QR text={enrol.uri} /></div>
      <details className="mt-3 text-center text-[12.5px]"><summary className="cursor-pointer text-brand">{L('لا يمكنك المسح؟ أدخل المفتاح يدوياً', 'Can’t scan? Enter the key manually')}</summary>
        <div className="mt-2 inline-flex items-center gap-2 rounded-lg bg-sub px-3 py-2"><span className="ltr num tracking-wider text-[13px] break-all">{enrol.secret.match(/.{1,4}/g)?.join(' ')}</span>
          <button onClick={() => { void navigator.clipboard?.writeText(enrol.secret); setCopied(true); setTimeout(() => setCopied(false), 1500) }} className="text-ink-3 hover:text-brand" aria-label={L('نسخ', 'Copy')}>{copied ? <Check size={15} /> : <Copy size={15} />}</button></div></details>
      <details className="mt-2 text-center text-[12.5px]"><summary className="cursor-pointer text-brand">{L('ليس لديك تطبيق المصادقة؟', 'Don’t have the authenticator app?')}</summary><div className="mt-2 text-start"><GetApp /></div></details>
      <div className="mt-5 text-center text-[13px] font-semibold mb-2">{L('أدخل الرمز الظاهر في التطبيق', 'Enter the code shown in the app')}</div>
      <OtpInput resetKey={nonce} disabled={busy || locked} onDone={c => void run(async () => { const r = await vaultCall<VaultState & { backup: string[] }>('/totp/confirm', { code: c }); setCodes(r.backup); setStep('backup') }, () => {})} />
      <StepBar />{alert}
      <div className="mt-3 text-center">{back}</div>
    </>
  } else if (step === 'backup') {
    body = <>
      {head(<ShieldCheck size={26} />, L('رموز الطوارئ', 'Backup codes'), L('احتفظ بها في مكان آمن. كل رمز يُستخدم مرة واحدة إذا لم يكن جوالك معك.', 'Keep them somewhere safe. Each works once if you don’t have your phone.'))}
      <div className="grid grid-cols-2 gap-2 rounded-xl bg-sub p-4 ltr">{codes.map(c => <div key={c} className="text-center num font-semibold tracking-wider text-[14px]">{c}</div>)}</div>
      <div className="flex gap-2 mt-4">
        <Btn className="flex-1" icon={copied ? <Check size={15} /> : <Copy size={15} />} onClick={() => { void navigator.clipboard?.writeText(codes.join('\n')); setCopied(true); setTimeout(() => setCopied(false), 1500) }}>{L('نسخ', 'Copy')}</Btn>
        <Btn kind="primary" className="flex-1 !h-auto min-h-9 py-1.5" onClick={() => done(L('فُعّل التحقق بخطوتين — الرواتب مفتوحة', 'Two-step verification on — salaries unlocked'))}>{L('حفظتها — متابعة', 'Saved them — continue')}</Btn>
      </div>
    </>
  } else if (step === 'verify') {
    body = <>
      {head(<KeyRound size={26} />, L('تحقق من هويتك', 'Verify it’s you'), L('افتح تطبيق المصادقة على جوالك وأدخل الرمز الحالي لحساب UHR.', 'Open the authenticator app on your phone and enter the current UHR code.'))}
      <OtpInput resetKey={nonce} disabled={busy || locked} onDone={c => void run(() => vaultCall('/totp/verify', { code: c }))} />
      <StepBar />{alert}
      <div className="mt-5 flex justify-between text-[12.5px]">
        <button className="text-brand font-semibold" onClick={() => { setStep('useBackup'); setErr(''); setText('') }}>{L('استخدام رمز طوارئ', 'Use a backup code')}</button>
        <button className="text-ink-3 hover:text-ink" onClick={() => { setStep('reset'); setErr(''); setText('') }}>{L('غيّرت جوالك؟', 'New phone?')}</button>
      </div>
      <div className="mt-3 text-center">{back}</div>
    </>
  } else if (step === 'useBackup') {
    body = <>
      {head(<ShieldCheck size={26} />, L('رمز طوارئ', 'Backup code'), L(`أدخل أحد رموز الطوارئ (متبقٍ ${v.backup_left}).`, `Enter one of your backup codes (${v.backup_left} left).`))}
      <input value={text} onChange={e => setText(e.target.value.toUpperCase())} placeholder="XXXX-XXXX" autoFocus className="field text-center num ltr tracking-widest text-[18px]" />
      {alert}
      <div className="flex gap-2 mt-4">
        <Btn className="flex-1" onClick={() => setStep('verify')}>{L('رجوع', 'Back')}</Btn>
        <Btn kind="primary" className="flex-1" disabled={busy || locked || text.replace(/[^A-Z0-9]/g, '').length < 8}
          onClick={() => void run(async () => { const s = await vaultCall<VaultState>('/backup/verify', { code: text }); done(L(`تم التحقق — تبقّى ${s.backup_left} رموز طوارئ`, `Verified — ${s.backup_left} backup codes left`)) }, () => {})}>{L('تحقق', 'Verify')}</Btn>
      </div>
    </>
  } else if (step === 'reset') {
    body = <>
      {head(<KeyRound size={26} />, L('إعادة ربط جوال جديد', 'Link a new phone'), L('أدخل كلمة مرور حسابك لإلغاء الربط الحالي، ثم امسح رمز QR جديداً.', 'Enter your account password to remove the current link, then scan a new QR code.'))}
      <input type="password" value={text} onChange={e => setText(e.target.value)} placeholder={L('كلمة المرور', 'Password')} autoFocus className="field" />
      {alert}
      <div className="flex gap-2 mt-4">
        <Btn className="flex-1" onClick={() => setStep('verify')}>{L('رجوع', 'Back')}</Btn>
        <Btn kind="danger" className="flex-1" disabled={busy || locked || !text} onClick={() => void run(() => vaultCall('/', { password: text }, 'DELETE'), () => { setStep('intro'); setText(''); toast(L('أُلغي الربط — أعد الإعداد', 'Unlinked — set it up again')) })}>{L('إلغاء الربط', 'Unlink')}</Btn>
      </div>
    </>
  }

  return <div className="fixed inset-0 z-[60] grid place-items-center bg-black/55 p-4" onMouseDown={closeVault} data-noprint>
    <div className="bg-surface rounded-2xl border border-line w-full max-w-[440px] max-h-[92vh] flex flex-col shadow-2xl fadeup" onMouseDown={e => e.stopPropagation()} role="dialog" aria-modal aria-label={L('التحقق بخطوتين — الرواتب', 'Two-step verification — Pay')}>
      <header className="flex items-center justify-between px-5 py-3 border-b border-line-2"><h3 className="font-semibold text-[15px] flex items-center gap-2"><Lock size={16} className="text-brand" />{L('التحقق بخطوتين — الرواتب', 'Two-step verification — Pay')}</h3>
        <button onClick={closeVault} className="p-1 rounded hover:bg-sub text-ink-3" aria-label="close">✕</button></header>
      <div className="p-5 overflow-y-auto">{body}</div>
    </div>
  </div>
}

/** موافقة من الموبايل: QR برابط /approve/{token}، والحاسوب يسأل كل ثانيتين حتى يوافق الموبايل */
function LinkStep({ onDone, back }: { onDone: () => void; back: ReactNode }) {
  const L = useL()
  const [l, setL] = useState<LinkStart | null>(null)
  const [left, setLeft] = useState(0)
  const [st, setSt] = useState<'pending' | 'rejected' | 'expired' | 'error'>('pending')
  const [msg, setMsg] = useState('')
  const start = () => { setSt('pending'); setMsg(''); vaultCall<LinkStart>('/link').then(x => { setL(x); setLeft(x.expires_in) }).catch(e => { setSt('error'); setMsg(e instanceof ApiError ? e.message : L('تعذّر الاتصال بالخادم.', 'Could not reach the server.')) }) }
  useEffect(start, []) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (!l || st !== 'pending') return; const t = setInterval(() => setLeft(s => Math.max(0, s - 1)), 1000); return () => clearInterval(t) }, [l, st])
  useEffect(() => {
    if (!l || st !== 'pending') return
    const t = setInterval(() => { void linkPoll(l.token).then(r => { if (r.status === 'approved') { clearInterval(t); onDone() } else if (r.status !== 'pending') setSt(r.status) }).catch(() => {}) }, 2000)
    return () => clearInterval(t)
  }, [l, st]) // eslint-disable-line react-hooks/exhaustive-deps
  const over = st !== 'pending' || left <= 0
  return <>
    <div className="text-center mb-4"><span className="mx-auto size-14 rounded-2xl grid place-items-center bg-brand-soft text-brand"><Smartphone size={26} /></span>
      <div className="mt-3 font-bold text-[17px]">{L('امسح الرمز بكاميرا جوالك', 'Scan with your phone camera')}</div>
      <p className="text-[13px] text-ink-3 mt-1 max-w-sm mx-auto">{L('يُفتح الرابط على الجوال وأنت مسجّل الدخول بالحساب نفسه — اضغط «موافق» فتُفتح الرواتب هنا تلقائياً.', 'The link opens on your phone, signed in to the same account — tap “Approve” and salaries unlock here automatically.')}</p></div>
    <div className="flex justify-center">{l && !over ? <a href={l.url} target="_blank" rel="noreferrer" aria-label="QR"><QR text={l.url} /></a>
      : <div className="size-[200px] rounded-xl bg-sub grid place-items-center text-[13px] text-ink-3 text-center p-4">{st === 'rejected' ? L('رُفض الطلب من الجوال', 'Declined on the phone') : st === 'error' ? msg : l ? L('انتهت صلاحية الرمز', 'Code expired') : L('جارٍ التحضير…', 'Preparing…')}</div>}</div>
    <div className="mt-3 flex items-center justify-center gap-3 text-[12.5px]">
      {!over && <span className={`inline-flex items-center gap-1 num ${left < 20 ? 'text-danger' : 'text-ink-3'}`}><Timer size={14} />{mmss(left)}</span>}
      {!over && <span className="inline-flex items-center gap-1.5 text-ink-3"><span className="size-2 rounded-full bg-brand animate-pulse" />{L('بانتظار الموافقة…', 'Waiting for approval…')}</span>}
      {over && st !== 'error' && <Btn size="sm" kind="primary" onClick={start}>{L('رمز جديد', 'New code')}</Btn>}
    </div>
    <div className="mt-4 text-center">{back}</div>
  </>
}

/** طلب إذن الاطلاع على رواتب الآخرين (يعتمده المدير العام) — أو حالته إن كان قائماً */
function GrantStep() {
  const L = useL(); const lang = useLang()
  const q = useMyGrants(); const req = useRequestGrant(); const cancel = useCancelGrant()
  const [scope, setScope] = useState<'all' | 'department'>('all')
  const [dept, setDept] = useState<number | ''>('')
  const [reason, setReason] = useState('')
  const d = q.data
  if (!d) return <div className="py-10 text-center text-ink-3 text-[13px]">{L('جارٍ التحميل…', 'Loading…')}</div>
  const last = d.data[0]
  const err = req.error instanceof ApiError ? req.error : null
  const head = (icon: ReactNode, t: ReactNode, sub: ReactNode) => <div className="text-center mb-5"><span className="mx-auto size-14 rounded-2xl grid place-items-center bg-brass/15 text-brass">{icon}</span>
    <div className="mt-3 font-bold text-[17px]">{t}</div><p className="text-[13px] text-ink-3 mt-1 max-w-sm mx-auto">{sub}</p></div>
  if (last && last.status.startsWith('pending')) return <>
    {head(<Hourglass size={26} />, L('طلبك بانتظار موافقة المدير العام', 'Waiting for the general manager'), L('سيصلك إشعار عند القرار، وتظهر المبالغ مباشرة بعد الموافقة.', 'You’ll be notified of the decision; amounts show right after approval.'))}
    <div className="rounded-xl border border-line-2 p-3 text-[13px] space-y-1">
      <div><span className="text-ink-3">{L('النطاق', 'Scope')}: </span>{last.scope === 'all' ? L('كل الشركة', 'Whole company') : (last.department ? (lang === 'ar' ? last.department.ar : last.department.en) : '')}</div>
      <div><span className="text-ink-3">{L('السبب', 'Reason')}: </span>{last.reason}</div>
      <div><span className="text-ink-3">{L('المدة بعد الموافقة', 'Duration after approval')}: </span><span className="num">{last.hours}</span> {L('ساعات', 'hours')}</div>
    </div>
    {last.can_cancel && <Btn kind="danger" className="w-full mt-4" disabled={cancel.isPending} onClick={() => cancel.mutate(last.id, { onSuccess: () => toast(L('أُلغي الطلب', 'Request cancelled')) })}>{L('إلغاء الطلب', 'Cancel request')}</Btn>}
  </>
  return <>
    {head(<UserCheck size={26} />, L('طلب إذن اطلاع على الرواتب', 'Request salary access'),
      L(`تحققت من هويتك. رواتب الموظفين الآخرين تحتاج موافقة المدير العام، والإذن يصلح ${d.hours} ساعات ثم ينتهي وحده.`, `You’re verified. Other employees’ salaries need the general manager’s approval; access lasts ${d.hours} hours, then ends.`))}
    {last && ['rejected', 'expired', 'revoked'].includes(last.status) && <div className="mb-3 rounded-lg bg-sub px-3 py-2 text-[12.5px] text-ink-2 text-center">
      {last.status === 'expired' ? L('انتهى إذنك السابق — اطلب إذناً جديداً.', 'Your previous access ended — request a new one.') : last.status === 'revoked' ? L('سُحب إذنك السابق.', 'Your previous access was revoked.') : L('رُفض طلبك السابق.', 'Your previous request was declined.')}</div>}
    <div className="space-y-3">
      <Field label={L('النطاق', 'Scope')} div><Seg value={scope} onChange={setScope} options={[{ v: 'all', label: L('كل الشركة', 'Whole company') }, { v: 'department', label: L('إدارة واحدة', 'One department') }]} /></Field>
      {scope === 'department' && <Field label={L('الإدارة', 'Department')} error={err?.field('department_id')}><select className="field" value={dept} onChange={e => setDept(e.target.value ? Number(e.target.value) : '')}>
        <option value="">{L('اختر…', 'Choose…')}</option>{d.departments.map(x => <option key={x.id} value={x.id}>{lang === 'ar' ? x.ar : x.en}</option>)}</select></Field>}
      <Field label={L('سبب الطلب', 'Reason')} req error={err?.field('reason')}><textarea className="field" value={reason} onChange={e => setReason(e.target.value)} placeholder={L('مثال: مراجعة كشف الشهر قبل الإغلاق', 'e.g. reviewing the monthly sheet before closing')} /></Field>
      {err && !err.field('reason') && !err.field('department_id') && <div role="alert" className="rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2">{err.message}</div>}
      <Btn kind="primary" className="w-full" disabled={req.isPending || !reason.trim()} onClick={() => req.mutate({ scope, department_id: scope === 'department' ? (dept || null) : null, reason }, { onSuccess: () => toast(L('أُرسل الطلب للمدير العام', 'Request sent to the general manager')) })}>{L('إرسال الطلب', 'Send request')}</Btn>
    </div>
  </>
}
