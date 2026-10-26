import { useEffect, useState } from 'react'
import { Check, Loader2, Monitor, Smartphone, X } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { useL, useLang, fmtDateTime } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { useLogout, type SessionUser } from '../../lib/session'
import { linkDecide, linkInfo, mmss } from '../../lib/vault'
import { Logo } from '../../ui/brand'
import { Btn } from '../../ui/kit'

/** صفحة الموبايل لطلب فتح الرواتب على الحاسوب (10b): تفاصيل الجهاز والوقت ← «موافق» أو «ليس أنا» */
export function ApproveLink({ token, user }: { token: string; user: SessionUser }) {
  const L = useL(); const lang = useLang(); const logout = useLogout()
  const q = useQuery({ queryKey: ['mfa-link', token], queryFn: () => linkInfo(token), retry: false, refetchOnWindowFocus: false })
  const [left, setLeft] = useState(0)
  const [result, setResult] = useState<'approved' | 'rejected' | null>(null)
  const [busy, setBusy] = useState(false); const [err, setErr] = useState('')
  useEffect(() => { if (q.data) setLeft(q.data.expires_in) }, [q.data])
  useEffect(() => { if (left <= 0) return; const t = setTimeout(() => setLeft(s => s - 1), 1000); return () => clearTimeout(t) }, [left])
  const act = async (ok: boolean) => {
    setBusy(true); setErr('')
    try { await linkDecide(token, ok); setResult(ok ? 'approved' : 'rejected') } catch (e) { setErr(e instanceof ApiError ? e.message : L('تعذّر الاتصال بالخادم.', 'Could not reach the server.')) } finally { setBusy(false) }
  }
  const e = q.error instanceof ApiError ? q.error : null
  let body
  if (q.isLoading) body = <div className="py-10 grid place-items-center"><Loader2 className="animate-spin text-brand" /></div>
  else if (result) body = <div className="text-center py-4">
    <span className={`mx-auto size-16 rounded-2xl grid place-items-center ${result === 'approved' ? 'bg-brand-soft text-brand' : 'bg-danger-soft text-danger'}`}>{result === 'approved' ? <Check size={30} /> : <X size={30} />}</span>
    <div className="mt-4 font-bold text-[18px]">{result === 'approved' ? L('تمت الموافقة', 'Approved') : L('رُفض الطلب', 'Declined')}</div>
    <p className="mt-1 text-[13.5px] text-ink-3">{result === 'approved' ? L('ارجع إلى الحاسوب — الرواتب فُتحت هناك.', 'Go back to the computer — salaries are unlocked there.') : L('لم تُفتح الرواتب. إن لم تكن أنت، غيّر كلمة مرورك.', 'Salaries stay locked. If it wasn’t you, change your password.')}</p></div>
  else if (e || !q.data || left <= 0) body = <div className="text-center py-4">
    <span className="mx-auto size-16 rounded-2xl grid place-items-center bg-sub text-ink-3"><X size={30} /></span>
    <div className="mt-4 font-bold text-[17px]">{e?.code === 'link_other_user' ? L('حساب مختلف', 'Different account') : e?.code === 'link_same_device' ? L('افتحه من جوالك', 'Open it on your phone') : L('انتهت صلاحية الطلب', 'This request expired')}</div>
    <p className="mt-1 text-[13.5px] text-ink-3">{e?.message ?? L('اطلب رمزاً جديداً من الحاسوب.', 'Start a new one on the computer.')}</p>
    {e?.code === 'link_other_user' && <Btn className="mt-4" onClick={() => logout.mutate()}>{L('تسجيل الخروج والدخول بحسابي', 'Sign out and use my account')}</Btn>}</div>
  else body = <>
    <div className="text-center"><span className="mx-auto size-16 rounded-2xl grid place-items-center bg-brand-soft text-brand"><Monitor size={30} /></span>
      <div className="mt-4 font-bold text-[18px]">{L('طلب فتح الرواتب على حاسوب', 'Unlock salaries on a computer')}</div>
      <p className="mt-1 text-[13.5px] text-ink-3">{L('هل أنت من يحاول عرض بيانات الرواتب الآن؟', 'Is it you trying to view salary data now?')}</p></div>
    <div className="mt-5 rounded-xl border border-line-2 divide-y divide-line-2 text-[13.5px]">
      <div className="flex justify-between gap-3 px-4 py-2.5"><span className="text-ink-3">{L('الحساب', 'Account')}</span><b className="ltr">{user.email}</b></div>
      <div className="flex justify-between gap-3 px-4 py-2.5"><span className="text-ink-3">{L('الجهاز', 'Device')}</span><b>{q.data.device ?? L('غير معروف', 'Unknown')}</b></div>
      <div className="flex justify-between gap-3 px-4 py-2.5"><span className="text-ink-3">{L('الوقت', 'Time')}</span><b className="num">{fmtDateTime(q.data.at, lang)}</b></div>
      {q.data.ip && <div className="flex justify-between gap-3 px-4 py-2.5"><span className="text-ink-3">IP</span><b className="ltr num">{q.data.ip}</b></div>}
    </div>
    {err && <div role="alert" className="mt-4 rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2 text-center">{err}</div>}
    <div className="mt-5 grid grid-cols-2 gap-3">
      <Btn kind="danger" size="lg" disabled={busy} onClick={() => void act(false)}>{L('ليس أنا', 'Not me')}</Btn>
      <Btn kind="primary" size="lg" disabled={busy} icon={<Check size={18} />} onClick={() => void act(true)}>{L('موافق', 'Approve')}</Btn>
    </div>
    <p className="mt-3 text-center text-[12px] text-ink-3 num">{L('ينتهي الطلب خلال', 'Expires in')} {mmss(left)}</p>
  </>
  return <div className="min-h-full app-glow grid place-items-center p-4">
    <div className="w-full max-w-[420px]">
      <div className="flex items-center justify-center gap-2 mb-5"><Logo h={28} /><span className="text-[12px] text-ink-3 border-s border-line ps-2 inline-flex items-center gap-1"><Smartphone size={13} />{L('الموافقة من الجوال', 'Phone approval')}</span></div>
      <div className="bg-surface border border-line rounded-2xl p-6 shadow-xl">{body}</div>
    </div>
  </div>
}
