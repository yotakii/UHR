import { useEffect, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Check, Search, X } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { fmtDate } from '../../lib/dates'
import { api, ApiError } from '../../lib/api'
import { go, useRoute } from '../../lib/router'
import type { CompanyCard } from '../../lib/session'
import type { Bi } from '../../lib/types'
import { Btn, Field } from '../../ui/kit'
import { AuthCard, CompanyLogo } from './parts'

export type TrackStatus = 'received' | 'review' | 'interview' | 'offer' | 'hired' | 'closed'
export type TrackResult = { ref: string; status: TrackStatus; job: Bi & { code: string }; company: CompanyCard; submitted_at: string; updated_at: string; has_account: boolean }

/** مراحل المتابعة كما يراها المتقدم (بلا تفاصيل داخلية) */
export const TRACK_STEPS: { k: Exclude<TrackStatus, 'closed'>; ar: string; en: string }[] = [
  { k: 'received', ar: 'استُلم الطلب', en: 'Received' }, { k: 'review', ar: 'قيد المراجعة', en: 'Under review' },
  { k: 'interview', ar: 'المقابلات', en: 'Interviews' }, { k: 'offer', ar: 'عرض العمل', en: 'Job offer' }, { k: 'hired', ar: 'التعيين', en: 'Hired' },
]

/** متابعة طلب التوظيف برقم المتابعة والبريد — بلا تسجيل دخول */
export function Track() {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const [ref, setRef] = useState(route.query.ref ?? ''); const [email, setEmail] = useState(route.query.email ?? '')
  const m = useMutation({ mutationFn: () => api<{ data: TrackResult }>('/public/track', { json: { ref: ref.trim(), email: email.trim() } }).then(r => r.data) })
  useEffect(() => { if (route.query.ref && route.query.email) m.mutate() }, [])   // eslint-disable-line react-hooks/exhaustive-deps
  const e = m.error instanceof ApiError ? m.error : null
  const r = m.data
  return <AuthCard title={L('متابعة طلب توظيف', 'Track an application')} sub={L('أدخل رقم المتابعة الذي ظهر لك بعد التقديم ووصلك في رسالة التأكيد، والبريد الذي قدّمت به.', 'Enter the reference shown after you applied (also in the confirmation email) and the email you applied with.')}>
    <form onSubmit={ev => { ev.preventDefault(); m.mutate() }} className="space-y-4" noValidate>
      <Field label={L('رقم المتابعة', 'Reference number')} req error={e?.field('ref')}><input className="field ltr text-start num" value={ref} onChange={ev => { setRef(ev.target.value); m.reset() }} placeholder="UNQ-2026-APP-00000" autoFocus={!ref} /></Field>
      <Field label={L('البريد الإلكتروني', 'Email')} req error={e?.field('email')}><input className="field ltr text-start" type="email" value={email} onChange={ev => { setEmail(ev.target.value); m.reset() }} autoComplete="email" autoFocus={!!ref} /></Field>
      {e && !e.field('ref') && !e.field('email') && <div role="alert" className="text-[13px] text-danger bg-danger-soft rounded px-3 py-2">{e.message}</div>}
      <Btn kind="primary" size="lg" className="w-full" type="submit" disabled={m.isPending || !ref.trim() || !email.trim()}><Search size={16} />{m.isPending ? L('جارٍ البحث…', 'Searching…') : L('عرض حالة الطلب', 'Show status')}</Btn>
    </form>
    {r && <div className="mt-6 border-t border-line-2 pt-5 space-y-4" aria-live="polite">
      <div className="flex items-center gap-3"><CompanyLogo c={r.company} size={40} /><div className="min-w-0">
        <div className="font-bold leading-tight">{bi(r.job, lang)}</div>
        <div className="text-[12.5px] text-ink-3">{bi(r.company.name, lang)} · <span className="ltr num inline-block whitespace-nowrap">{r.ref}</span></div></div></div>
      {r.status === 'closed'
        ? <div className="rounded-xl bg-sub px-4 py-3 text-[13.5px] flex gap-2"><X size={16} className="shrink-0 mt-0.5 text-ink-3" />{L('أُغلق هذا الطلب. شكراً لاهتمامك، ونرحب بتقديمك على فرص أخرى.', 'This application is closed. Thank you for your interest — you are welcome to apply for other openings.')}</div>
        : <ol className="space-y-2">{TRACK_STEPS.map((s, i) => {
            const at = TRACK_STEPS.findIndex(x => x.k === r.status); const done = i < at; const now = i === at
            return <li key={s.k} className={`flex items-center gap-3 text-[14px] ${done || now ? '' : 'text-ink-3'}`}>
              <span className={`size-7 shrink-0 rounded-full grid place-items-center text-[12px] font-bold ${done ? 'bg-brand text-on-brand' : now ? 'bg-brand-soft text-brand ring-2 ring-[var(--brand)]' : 'bg-sub'}`}>{done ? <Check size={14} /> : i + 1}</span>
              <span className={now ? 'font-semibold' : ''}>{L(s.ar, s.en)}</span>{now && <span className="text-[12px] text-brand">{L('المرحلة الحالية', 'Current stage')}</span>}</li>
          })}</ol>}
      <div className="text-[12.5px] text-ink-3">{L('تاريخ التقديم', 'Applied on')} {fmtDate(r.submitted_at, lang)} · {L('آخر تحديث', 'Last update')} {fmtDate(r.updated_at, lang)}</div>
      {r.status === 'offer' && <p className="text-[13px] text-ink-2">{L('للاطلاع على عرض العمل والرد عليه سجّل الدخول إلى حسابك.', 'Sign in to your account to view and answer the job offer.')}</p>}
      {r.has_account && <Btn className="w-full" onClick={() => go('login')}>{L('الدخول إلى حسابي لتفاصيل أكثر', 'Sign in for more details')}</Btn>}
    </div>}
  </AuthCard>
}
