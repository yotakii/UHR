import { useState } from 'react'
import { Check, Loader2, MapPin, X } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { position } from '../../lib/portal'
import { stickerCheckin, useStickerInfo, REASON } from '../../lib/nfc'
import type { SessionUser } from '../../lib/session'
import { Logo } from '../../ui/brand'
import { Btn } from '../../ui/kit'

/** الحضور بمسح ملصق الفرع (FR-NF-017): يُفتح من هاتف الموظف وهو داخل حسابه، ويتحقق من الموقع ضمن نطاق الفرع إن طُلب */
export function BranchCheckin({ token, user }: { token: string; user: SessionUser }) {
  const L = useL(); const lang = useLang()
  const q = useStickerInfo(token)
  const [busy, setBusy] = useState(false); const [err, setErr] = useState(''); const [done, setDone] = useState<{ direction: string | null; time?: string; reason: string | null; flagged: boolean } | null>(null)
  const go = async () => {
    setBusy(true); setErr('')
    try {
      const pos = q.data?.geo ? await position().catch(() => null) : null
      if (q.data?.geo && !pos) { setErr(L('اسمح للمتصفح بتحديد موقعك ثم أعد المحاولة.', 'Allow the browser to use your location, then try again.')); return }
      const r = await stickerCheckin(token, pos)
      setDone({ direction: r.data.direction, time: r.data.time, reason: r.data.reason, flagged: r.data.decision === 'flagged' })
    } catch (e) { setErr(e instanceof ApiError ? e.message : L('تعذّر الاتصال بالخادم.', 'Could not reach the server.')) } finally { setBusy(false) }
  }
  const e = q.error instanceof ApiError ? q.error : null
  let body
  if (q.isLoading) body = <div className="py-10 grid place-items-center"><Loader2 className="animate-spin text-brand" /></div>
  else if (e || !q.data) body = <div className="text-center py-4"><span className="mx-auto size-16 rounded-2xl grid place-items-center bg-sub text-ink-3"><X size={30} /></span>
    <div className="mt-4 font-bold text-[17px]">{L('ملصق غير صالح', 'Invalid sticker')}</div><p className="mt-1 text-[13.5px] text-ink-3">{e?.message}</p></div>
  else if (!q.data.employee) body = <p className="text-center text-[14px] text-ink-2 py-4">{L('الحضور للموظفين فقط.', 'Check-in is for employees only.')}</p>
  else if (done) body = <div className="text-center py-4">
    <span className="mx-auto size-16 rounded-2xl grid place-items-center bg-brand-soft text-brand"><Check size={30} /></span>
    <div className="mt-4 font-bold text-[18px]">{done.direction === 'out' ? L('سُجّل خروجك', 'Check-out recorded') : L('سُجّل حضورك', 'Check-in recorded')}{done.time ? <span className="num"> {done.time}</span> : ''}</div>
    <p className="mt-1 text-[13.5px] text-ink-3">{bi(q.data.branch, lang)}{done.reason && REASON[done.reason] && done.reason !== 'home' ? ` · ${L(REASON[done.reason][0], REASON[done.reason][1])}` : ''}</p>
    {done.flagged && <p className="mt-2 text-[13px] text-warn">{L('هذا الفرع ليس ضمن فروعك المصرّح بها — سيراجع الحضورَ قسمُ الموارد البشرية.', 'This branch is not among your authorised branches — HR will review the attendance.')}</p>}</div>
  else body = <div className="text-center">
    <span className="mx-auto size-16 rounded-2xl grid place-items-center bg-brand-soft text-brand"><MapPin size={30} /></span>
    <div className="mt-4 font-bold text-[18px]">{bi(q.data.branch, lang)}</div>
    <p className="mt-1 text-[13.5px] text-ink-3">{bi(user.name, lang)} · {q.data.geo ? L('سيُطلب موقعك للتحقق من وجودك في الفرع.', 'Your location will be checked against the branch area.') : L('اضغط لتسجيل الحضور أو الخروج.', 'Tap to record check-in or check-out.')}</p>
    {err && <div role="alert" className="mt-4 rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2">{err}</div>}
    <Btn kind="primary" size="lg" className="mt-5 w-full" disabled={busy} icon={busy ? <Loader2 size={18} className="animate-spin" /> : <Check size={18} />} onClick={() => void go()}>{L('تسجيل الحضور / الخروج', 'Check in / out')}</Btn>
  </div>
  return <div className="min-h-full app-glow grid place-items-center p-4">
    <div className="w-full max-w-[420px]">
      <div className="flex items-center justify-center gap-2 mb-5"><Logo h={28} /><span className="text-[12px] text-ink-3 border-s border-line ps-2">{L('ملصق الفرع', 'Branch sticker')}</span></div>
      <div className="bg-surface border border-line rounded-2xl p-6 shadow-xl">{body}</div>
    </div>
  </div>
}
