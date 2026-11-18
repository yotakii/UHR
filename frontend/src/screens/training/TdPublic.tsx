import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { BadgeCheck, Check, Loader2, Search, X, XCircle } from 'lucide-react'
import { api, ApiError } from '../../lib/api'
import { useL, useLang, bi, fmtDate } from '../../lib/store'
import type { SessionUser } from '../../lib/session'
import type { Bi } from '../../lib/types'
import { CERT } from '../../lib/training'
import { Logo } from '../../ui/brand'
import { Btn, Field, Panel, SignaturePad } from '../../ui/kit'

/** استبيان رضا المتدربين (المستوى الأول) — رابط عام بلا حساب، مجهول افتراضياً */
export function TdSurvey({ token }: { token: string }) {
  const L = useL(); const lang = useLang()
  const q = useQuery({ queryKey: ['public', 'td-survey', token], retry: false, queryFn: () => api<{ title: string; trainer: string | null; place: string | null; from: string; to: string; open: boolean; items: string[] }>(`/public/td-survey/${token}`) })
  const [r, setR] = useState<Record<number, number>>({})
  const [a, setA] = useState({ best: '', improve: '' })
  const [anon, setAnon] = useState(true)
  const send = useMutation({ mutationFn: () => api('/public/td-survey/' + token, { method: 'POST', json: { ratings: q.data!.items.map((_, i) => r[i] ?? 0), answers: a, anonymous: anon } }) })
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
          <label className="flex items-center gap-2 text-[13px] mt-3"><input type="checkbox" checked={anon} onChange={e => setAnon(e.target.checked)} />{L('إرسال التقييم دون ربطه باسمي', 'Send without linking it to my name')}</label>
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

/** موافقة المدير على ترشيح موظف من رابط البريد (دون الدخول للنظام) */
export function TdApprove({ token }: { token: string }) {
  const L = useL(); const lang = useLang()
  const q = useQuery({ queryKey: ['public', 'td-approve', token], retry: false, queryFn: () => api<{ data: { employee: Bi; title: string; from: string; to: string; place: string | null; reason: string | null; answered: boolean; ok: boolean | null; company: { name: Bi } | null } }>(`/public/td-approve/${token}`).then(r => r.data) })
  const [step, setStep] = useState<boolean | null>(null)
  const send = useMutation({ mutationFn: (ok: boolean) => api<{ data: { ok: boolean } }>(`/public/td-approve/${token}`, { method: 'POST', json: { ok } }) })
  const d = q.data
  return <div className="max-w-[560px] mx-auto px-4 py-10">
    <div className="mb-6"><Logo /></div>
    {q.isLoading ? <Loader2 className="animate-spin text-brand mx-auto" /> : !d ? <Panel><div className="flex items-center gap-2 text-danger"><XCircle size={18} />{L('الرابط غير صالح أو انتهت صلاحيته.', 'The link is invalid or has expired.')}</div></Panel>
      : send.isSuccess || d.answered ? <Panel><div className="text-center py-6"><span className="mx-auto size-14 rounded-2xl grid place-items-center bg-brand-soft text-brand"><Check size={28} /></span>
        <div className="mt-3 font-bold text-[18px]">{(send.data?.data.ok ?? d.ok) ? L('سُجّلت موافقتك', 'Your approval was recorded') : L('سُجّل رفضك', 'Your decision was recorded')}</div></div></Panel>
      : <Panel>
        <div className="text-[12.5px] text-ink-3">{d.company ? bi(d.company.name, lang) : ''}</div>
        <h1 className="text-[20px] font-black mt-1">{L('ترشيح لدورة تدريبية', 'Training nomination')}</h1>
        <div className="mt-3 space-y-1 text-[14px]"><div>{L('الموظف', 'Employee')}: <b>{bi(d.employee, lang)}</b></div><div>{L('الدورة', 'Course')}: <b>{d.title}</b></div>
          <div className="text-ink-3">{fmtDate(d.from, lang)} — {fmtDate(d.to, lang)}{d.place ? ` · ${d.place}` : ''}</div>{d.reason && <div className="text-ink-3">{L('سبب الترشيح', 'Reason')}: {d.reason}</div>}</div>
        {step === null ? <div className="flex gap-2 mt-5"><Btn kind="primary" className="flex-1" onClick={() => setStep(true)}>{L('موافقة', 'Approve')}</Btn><Btn kind="danger" className="flex-1" onClick={() => setStep(false)}>{L('رفض', 'Decline')}</Btn></div>
          : <div className="mt-5 rounded-xl bg-sub p-3"><div className="text-[13.5px]">{step ? L('تأكيد الموافقة على مشاركة الموظف؟', 'Confirm approving the employee’s participation?') : L('تأكيد رفض الترشيح؟ سيُسحب الموظف من الدورة.', 'Confirm declining? The employee will be withdrawn from the course.')}</div>
            <div className="flex gap-2 mt-3"><Btn onClick={() => setStep(null)}>{L('رجوع', 'Back')}</Btn><Btn kind={step ? 'primary' : 'danger'} disabled={send.isPending} onClick={() => send.mutate(step)}>{L('تأكيد', 'Confirm')}</Btn></div></div>}
        {send.error && <div className="text-[13px] text-danger mt-2">{(send.error as Error).message}</div>}
      </Panel>}
  </div>
}

/** توقيع تعهد الالتزام عن بعد: قراءة النص ← رمز تحقق إلى البريد ← التوقيع */
export function TdCommit({ token }: { token: string }) {
  const L = useL(); const lang = useLang()
  const q = useQuery({ queryKey: ['public', 'td-commit', token], retry: false, queryFn: () => api<{ data: { title: string; body: string; months: number; signed: boolean; email: string; company: { name: Bi } | null } }>(`/public/td-commit/${token}`).then(r => r.data) })
  const [sent, setSent] = useState(false)
  const [code, setCode] = useState('')
  const [sig, setSig] = useState<string | null>(null)
  const ask = useMutation({ mutationFn: () => api(`/public/td-commit/${token}/code`, { method: 'POST', json: {} }), onSuccess: () => setSent(true) })
  const sign = useMutation({ mutationFn: () => api(`/public/td-commit/${token}`, { method: 'POST', json: { code, signature: sig } }) })
  const d = q.data
  const err = (sign.error ?? ask.error) as ApiError | null
  return <div className="max-w-[680px] mx-auto px-4 py-10">
    <div className="mb-6"><Logo /></div>
    {q.isLoading ? <Loader2 className="animate-spin text-brand mx-auto" /> : !d ? <Panel><div className="flex items-center gap-2 text-danger"><XCircle size={18} />{L('الرابط غير صالح أو انتهت صلاحيته.', 'The link is invalid or has expired.')}</div></Panel>
      : sign.isSuccess || d.signed ? <Panel><div className="text-center py-6"><span className="mx-auto size-14 rounded-2xl grid place-items-center bg-brand-soft text-brand"><BadgeCheck size={28} /></span>
        <div className="mt-3 font-bold text-[18px]">{L('تم توقيع التعهد وتأكيد تسجيلك', 'The commitment is signed and your place is confirmed')}</div></div></Panel>
      : <Panel>
        <div className="text-[12.5px] text-ink-3">{d.company ? bi(d.company.name, lang) : ''}</div>
        <h1 className="text-[20px] font-black mt-1">{L('تعهد الالتزام بالتدريب والعمل', 'Training & service commitment')}</h1>
        <div className="text-[13.5px] mt-1">{d.title} · {L(`${d.months} شهراً`, `${d.months} months`)}</div>
        <pre className="whitespace-pre-wrap font-sans text-[13.5px] leading-7 bg-sub rounded-xl p-3 mt-3">{d.body}</pre>
        {!sent ? <Btn kind="primary" className="mt-4 w-full" disabled={ask.isPending} onClick={() => ask.mutate()}>{L(`إرسال رمز التحقق إلى ${d.email}`, `Send the verification code to ${d.email}`)}</Btn>
          : <div className="mt-4 space-y-3">
            <Field label={L('رمز التحقق من البريد', 'Verification code from the email')} req error={err?.field?.('code')}><input className="field num ltr text-center tracking-[.3em] text-[18px]" inputMode="numeric" maxLength={6} value={code} onChange={e => setCode(e.target.value.replace(/\D/g, ''))} /></Field>
            <div><div className="text-[13px] font-semibold mb-1">{L('التوقيع', 'Signature')}</div><SignaturePad value={sig ?? undefined} onChange={d => setSig(d ?? null)} /></div>
            <Btn kind="primary" className="w-full" disabled={code.length !== 6 || !sig || sign.isPending} onClick={() => sign.mutate()}>{L('أوافق وأوقّع', 'I agree and sign')}</Btn>
            <button className="text-[12.5px] underline text-ink-3" onClick={() => ask.mutate()}>{L('إعادة إرسال الرمز', 'Resend the code')}</button></div>}
        {err && !err.field?.('code') && <div className="text-[13px] text-danger mt-2">{err.message}</div>}
      </Panel>}
  </div>
}
