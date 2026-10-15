import { Check, Copy } from 'lucide-react'
import { useL, useLang, fmtDate } from '../../lib/store'
import { go } from '../../lib/router'
import { useLogin } from '../../lib/session'
import { Btn, Badge, Empty, toast } from '../../ui/kit'
import { getSubmission, clearSubmission } from './submission'

/** شاشة النجاح بعد إرسال F-28 — «متابعة حالة الطلب» تسجّل الدخول مباشرة ببيانات الطلب */
export function Applied() {
  const L = useL(); const lang = useLang()
  const login = useLogin()
  const s = getSubmission()
  if (!s) return <div className="max-w-xl mx-auto px-5 py-12"><div className="bg-surface border border-line rounded-2xl">
    <Empty title={L('تابع طلبك من حسابك', 'Follow your application from your account')} action={<Btn kind="primary" onClick={() => go('login')}>{L('تسجيل الدخول', 'Sign in')}</Btn>}>{L('سجّل الدخول بالبريد وكلمة المرور اللذين استخدمتهما في الطلب.', 'Sign in with the email and password you used in your application.')}</Empty>
  </div></div>

  const copy = () => navigator.clipboard?.writeText(s.ref).then(() => toast(L('تم نسخ رقم المتابعة', 'Reference copied')), () => {})
  // المسجّل دخوله ينتقل لطلباته مباشرة؛ وإلا يُسجَّل دخوله ببيانات الطلب
  const track = () => s.signedIn ? (clearSubmission(), go('my', String(s.id))) : login.mutate({ email: s.email, password: s.password }, { onSuccess: () => { clearSubmission(); go('my', String(s.id)) } })
  return <div className="max-w-xl mx-auto px-5 py-12">
    <div className="bg-surface border border-line rounded-2xl p-8 text-center">
      <div className="size-12 rounded-full bg-brand text-on-brand grid place-items-center mx-auto"><Check /></div>
      <h1 className="text-[20px] font-bold mt-4">{L('استلمنا طلبك', 'We received your application')}</h1>
      <p className="text-ink-2 mt-1">{L(`طلبك على وظيفة ${s.job.title.ar} لدى ${s.company.name.ar} قيد المراجعة الآن لدى فريق التوظيف.`, `Your application for ${s.job.title.en} at ${s.company.name.en} is now with the recruitment team.`)}</p>
      {s.merged && <p className="text-[13px] text-warn mt-2">{L('أُلحق هذا الطلب بملفك السابق.', 'This application was added to your existing file.')}</p>}
      <div className="mt-6 inline-flex items-center gap-2 border border-line rounded-md px-4 py-2">
        <span className="text-[13px] text-ink-3">{L('رقم المتابعة', 'Reference')}</span><span className="font-bold ltr num">{s.ref}</span>
        <button onClick={copy} className="text-ink-3 hover:text-ink" aria-label={L('نسخ', 'Copy')}><Copy size={15} /></button>
      </div>
      <div className="text-[13px] text-ink-3 mt-3">{L('أُرسل تأكيد بالرقم إلى', 'Confirmation sent to')} <span className="ltr">{s.email}</span> — {fmtDate(s.created_at, lang)}</div>
      {login.error && <div role="alert" className="mt-4 text-[13px] text-danger bg-danger-soft rounded px-3 py-2">{login.error.message}</div>}
      <div className="flex flex-wrap justify-center gap-2 mt-6">
        <Btn kind="primary" onClick={track} disabled={login.isPending}>{L('متابعة حالة الطلب', 'Track my application')}</Btn>
        <Btn onClick={() => { clearSubmission(); go('jobs') }}>{L('تصفّح فرصاً أخرى', 'Browse more jobs')}</Btn>
      </div>
      <div className="mt-6"><Badge tone="info">{L('الخطوة التالية: الفرز الأولي من الموارد البشرية', 'Next: HR screening')}</Badge></div>
    </div>
  </div>
}
