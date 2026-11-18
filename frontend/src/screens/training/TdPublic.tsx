import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { BadgeCheck, Check, Loader2, Search, X, XCircle } from 'lucide-react'
import { api, ApiError } from '../../lib/api'
import { useL, useLang, bi, fmtDate } from '../../lib/store'
import type { SessionUser } from '../../lib/session'
import type { Bi } from '../../lib/types'
import { CERT } from '../../lib/training'
import { Logo } from '../../ui/brand'
import { Btn, Field, Panel } from '../../ui/kit'

/** استبيان رضا المتدربين (المستوى الأول) — رابط عام بلا حساب، مجهول افتراضياً */
export function TdSurvey({ token }: { token: string }) {
  const L = useL(); const lang = useLang()
  const q = useQuery({ queryKey: ['public', 'td-survey', token], retry: false, queryFn: () => api<{ title: string; trainer: string | null; place: string | null; from: string; to: string; open: boolean; items: string[] }>(`/public/td-survey/${token}`) })
  const [r, setR] = useState<Record<number, number>>({})
  const [a, setA] = useState({ best: '', improve: '' })
  const send = useMutation({ mutationFn: () => api('/public/td-survey/' + token, { method: 'POST', json: { ratings: q.data!.items.map((_, i) => r[i] ?? 0), answers: a, anonymous: true } }) })
  const d = q.data
  return <div className="max-w-[640px] mx-auto px-4 py-10">
    {q.isLoading ? <Loader2 className="animate-spin text-brand mx-auto" /> : !d ? <Panel><div className="flex items-center gap-2 text-danger"><XCircle size={18} />{L('الرابط غير صالح.', 'This link is not valid.')}</div></Panel>
      : send.isSuccess ? <Panel><div className="text-center py-6"><span className="mx-auto size-14 rounded-2xl grid place-items-center bg-brand-soft text-brand"><Check size={28} /></span>
        <div className="mt-3 font-bold text-[18px]">{L('شكراً لك', 'Thank you')}</div><p className="text-[13.5px] text-ink-3 mt-1">{L('وصل تقييمك، وسيُستعمل لتحسين الدورات القادمة.', 'Your feedback was received and will improve future courses.')}</p></div></Panel>
      : <>
        <h1 className="text-[22px] font-black">{L('تقييم الدورة التدريبية', 'Course evaluation')}</h1>
        <div className="text-[14px] mt-1"><b>{d.title}</b>{d.trainer ? ` · ${d.trainer}` : ''}</div>
        <div className="text-[12.5px] text-ink-3">{fmtDate(d.from, lang)} — {fmtDate(d.to, lang)}{d.place ? ` · ${d.place}` : ''}</div>
        {!d.open ? <Panel className="mt-5"><div className="text-[13.5px] text-ink-3">{L('الاستبيان غير متاح الآن — يُفتح أثناء الدورة وبعدها.', 'The survey is not open now — it opens during and after the course.')}</div></Panel> : <Panel className="mt-5">
          <div className="text-[12.5px] text-ink-3 mb-3">{L('قيّم كل بند من 1 (ضعيف) إلى 5 (ممتاز). إجابتك مجهولة الاسم.', 'Rate each item from 1 (poor) to 5 (excellent). Your answer is anonymous.')}</div>
          <div className="space-y-3">{d.items.map((t, i) => <div key={i} className="flex flex-wrap items-center justify-between gap-2"><span className="text-[13.5px]">{t}</span>
            <div className="flex gap-1">{[1, 2, 3, 4, 5].map(n => <button key={n} onClick={() => setR({ ...r, [i]: n })} className={`size-9 rounded-lg border num ${r[i] === n ? 'bg-brand text-white border-brand' : 'border-line'}`}>{n}</button>)}</div></div>)}</div>
          <Field label={L('أكثر ما استفدت منه', 'What helped you most')} className="mt-4"><textarea className="field" rows={2} value={a.best} onChange={e => setA({ ...a, best: e.target.value })} /></Field>
          <Field label={L('ما الذي يمكن تحسينه؟', 'What could be improved?')} className="mt-2"><textarea className="field" rows={2} value={a.improve} onChange={e => setA({ ...a, improve: e.target.value })} /></Field>
          {send.error && <div className="text-[13px] text-danger mt-2">{(send.error as Error).message}</div>}
          <Btn kind="primary" className="mt-4 w-full" disabled={Object.keys(r).length < d.items.length || send.isPending} onClick={() => send.mutate()}>{L('إرسال التقييم', 'Send')}</Btn>
        </Panel>}
      </>}
  </div>
}

/** التحقق العام من شهادة تدريب برمزها */
export function TdVerify({ code: initial }: { code?: string }) {
  const L = useL(); const lang = useLang()
  const [code, setCode] = useState(initial ?? '')
  const [q, setQ] = useState(initial ?? '')
  const r = useQuery({ queryKey: ['public', 'td-verify', q], enabled: !!q, retry: false, queryFn: () => api<{ data: { no: string | null; type: string; title: string; name: Bi; issued_on: string; valid_to: string | null; status: string; company: Bi } }>(`/public/td-verify/${encodeURIComponent(q)}`).then(x => x.data) })
  const ok = r.data?.status === 'valid'
  return <div className="max-w-[560px] mx-auto px-4 py-12">
    <h1 className="text-[24px] font-black">{L('التحقق من شهادة تدريب', 'Verify a training certificate')}</h1>
    <div className="flex gap-2 mt-4"><input className="field ltr font-mono" value={code} onChange={e => setCode(e.target.value)} placeholder="ABCD1234EF" aria-label={L('رمز التحقق', 'Verification code')} onKeyDown={e => e.key === 'Enter' && setQ(code.trim())} />
      <Btn kind="primary" icon={<Search size={15} />} onClick={() => setQ(code.trim())}>{L('تحقق', 'Verify')}</Btn></div>
    {r.isLoading && q && <Loader2 className="animate-spin text-brand mx-auto mt-6" />}
    {r.data && <Panel className="mt-6"><div className={`flex items-center gap-2 font-semibold ${ok ? 'text-brand' : 'text-danger'}`}>{ok ? <BadgeCheck size={18} /> : <XCircle size={18} />}
      {ok ? L('شهادة صحيحة وسارية', 'Valid certificate') : r.data.status === 'expired' ? L('شهادة صحيحة لكنها منتهية الصلاحية', 'Genuine but expired') : L('شهادة ملغاة', 'Revoked certificate')}</div>
      <div className="mt-2 text-[15px]"><b>{bi(r.data.name, lang)}</b> · {bi(r.data.company, lang)}</div>
      <div className="text-[13.5px] text-ink-2">{r.data.title} · {CERT[r.data.type] ? L(CERT[r.data.type][0], CERT[r.data.type][1]) : r.data.type}</div>
      <div className="text-[13px] text-ink-3"><span className="num">{fmtDate(r.data.issued_on, lang)}</span>{r.data.valid_to ? <> — <span className="num">{fmtDate(r.data.valid_to, lang)}</span></> : ''}</div>
      {r.data.no && <div className="text-[12px] text-ink-3 ltr">{r.data.no}</div>}</Panel>}
    {r.isError && <Panel className="mt-6"><div className="flex items-center gap-2 text-danger"><XCircle size={18} />{L('لا توجد شهادة بهذا الرمز.', 'No certificate has this code.')}</div></Panel>}
  </div>
}

/** تسجيل حضور جلسة تدريب بمسح رمز QR (يُفتح من هاتف المتدرب وهو داخل حسابه) */
export function TdCheckin({ token, user }: { token: string; user: SessionUser }) {
  const L = useL(); const lang = useLang()
  const go = useMutation({ mutationFn: () => api<{ data: { title: string; meeting: number; status: string } }>(`/me/training/checkin/${token}`, { method: 'POST', json: {} }).then(x => x.data) })
  const err = go.error instanceof ApiError ? go.error.message : go.error ? L('تعذّر الاتصال بالخادم.', 'Could not reach the server.') : ''
  return <div className="min-h-full app-glow grid place-items-center p-4">
    <div className="w-full max-w-[420px]">
      <div className="flex items-center justify-center gap-2 mb-5"><Logo h={28} /><span className="text-[12px] text-ink-3 border-s border-line ps-2">{L('حضور التدريب', 'Training attendance')}</span></div>
      <div className="bg-surface border border-line rounded-2xl p-6 shadow-xl text-center">
        {go.data ? <><span className="mx-auto size-16 rounded-2xl grid place-items-center bg-brand-soft text-brand"><Check size={30} /></span>
          <div className="mt-4 font-bold text-[18px]">{go.data.status === 'late' ? L('سُجّل حضورك (متأخر)', 'Recorded (late)') : L('سُجّل حضورك', 'Attendance recorded')}</div>
          <p className="mt-1 text-[13.5px] text-ink-3">{go.data.title} · {L(`الجلسة ${go.data.meeting}`, `Meeting ${go.data.meeting}`)}</p></>
          : <><div className="font-bold text-[17px]">{bi(user.name, lang)}</div>
            <p className="mt-1 text-[13.5px] text-ink-3">{L('اضغط لتسجيل حضورك في جلسة اليوم.', 'Tap to record your attendance for today\'s meeting.')}</p>
            {err && <div role="alert" className="mt-4 rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2 flex items-center gap-2 justify-center"><X size={15} />{err}</div>}
            <Btn kind="primary" size="lg" className="mt-5 w-full" disabled={go.isPending} icon={go.isPending ? <Loader2 size={18} className="animate-spin" /> : <Check size={18} />} onClick={() => go.mutate()}>{L('تسجيل الحضور', 'Check in')}</Btn></>}
      </div>
    </div>
  </div>
}
