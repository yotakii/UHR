import { useState } from 'react'
import { Link2, Copy, Gift, Sparkles, Send, Ban, History } from 'lucide-react'
import { useL, useLang, bi, fmtDate, money } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { useApplyInternal, useCancelInternal, useInternalOffer, useMyInternal, useMyRewards, useRedeem, useShare, type InternalJob, type MyInternal } from '../../lib/rewards'
import { Badge, Btn, Empty, Field, Modal, Panel, Seg, SignaturePad, toast } from '../../ui/kit'
import { CountUp } from '../../ui/viz'
import { DecisionDialog } from '../../ui/decision'
import { StepsPath } from '../requests/parts'
import { LeaveStatus } from '../hr/employees/EmployeeFile'
import { Leaderboard, RedemptionBadge, ReferralBadge, rewardIcon, stagesText } from './parts'

const copy = (text: string, done: () => void) => { navigator.clipboard?.writeText(text).then(done, done) }

/** بوابة الموظف — «المكافآت» (MyRewards في الديمو): الرصيد، مشاركة الشواغر، المتصدرون، المتجر، و«ترشيح نفسي» F-27 */
export function MyRewards() {
  const L = useL(); const lang = useLang()
  const q = useMyRewards(); const d = q.data
  const iq = useMyInternal(); const im = iq.data
  const share = useShare(); const redeem = useRedeem()
  const [internal, setInternal] = useState<InternalJob | null>(null)
  if (!d) return <div className="max-w-[1200px] mx-auto" />
  const err = (e: unknown, f: string) => toast(e instanceof ApiError ? e.message : f)
  const internalOf = (jobId: number) => im?.jobs.find(j => j.id === jobId)
  return <div className="max-w-[1200px] mx-auto space-y-5">
    <section className="show-root relative overflow-hidden rounded-3xl p-7 md:p-9"><div className="show-bg" aria-hidden><i /><i /></div>
      <div className="relative flex flex-wrap items-center gap-6 justify-between">
        <div><div className="text-[13px] text-show-fg/60">{L('رصيد نقاطك', 'Your points')}</div><div className="text-[52px] font-black font-display text-brand num leading-none mt-1"><CountUp to={d.points} /></div>
          <div className="text-[13px] text-show-fg/60 mt-2 max-w-[560px]">{L('رشّح شخصاً مناسباً واكسب النقاط مع تقدّمه.', 'Refer the right person and earn points as they progress.')} {stagesText(d.stages, d.hard_to_fill_x, L)}</div>
          <div className="text-[12px] text-show-fg/50 mt-1">{L('مجموع ما اكتسبته', 'Earned in total')}: <span className="num">{d.lifetime.toLocaleString('en-US')}</span></div></div>
        <Sparkles size={64} className="text-brand/60" />
      </div>
    </section>
    <div className="grid lg:grid-cols-[1.4fr_1fr] gap-5">
      <Panel title={L('شارك وظيفة شاغرة', 'Share an opening')} pad={false}>{d.jobs.length === 0 ? <Empty title={L('لا شواغر منشورة الآن', 'No published openings now')} /> : <ul className="divide-y divide-line-2">{d.jobs.map(j => {
        const ij = internalOf(j.id); const app = ij?.application && !['rejected', 'cancelled'].includes(ij.application.status) ? ij.application : null
        return <li key={j.id} className="px-5 py-3 flex flex-wrap items-center gap-3"><div className="flex-1 min-w-[180px]"><div className="font-semibold">{bi(j.title, lang)}{j.hard_to_fill && <span className="ms-1.5"><Badge tone="brass">×{d.hard_to_fill_x}</Badge></span>}</div>
          <div className="text-[12px] text-ink-3">{j.dept ? bi(j.dept, lang) + ' · ' : ''}<span className="ltr inline-block">{j.code}</span>{j.referral ? <> · <span className="num">{j.referral.clicks}</span> {L('زيارة', 'visits')}</> : null}</div></div>
          {j.referral ? <button onClick={() => copy(j.referral!.link, () => toast(L('نُسخ رابط الإحالة', 'Referral link copied')))} className="flex items-center gap-2 rounded-lg border border-brand/40 bg-brand-soft px-3 h-9 text-[13px] font-semibold text-brand"><Copy size={14} /><span className="ltr num">{j.referral.code}</span></button>
            : <Btn size="sm" icon={<Link2 size={14} />} disabled={share.isPending} onClick={() => share.mutate(j.id, { onSuccess: r => copy(r.link, () => toast(L('أُنشئ الرابط ونُسخ', 'Link created and copied'))), onError: e => err(e, L('تعذّر إنشاء الرابط', 'Could not create the link')) })}>{L('إنشاء رابط إحالة', 'Create referral link')}</Btn>}
          {ij && !app && !ij.own_dept && <Btn size="sm" kind="ghost" onClick={() => setInternal(ij)}>{L('ترشيح نفسي', 'Apply internally')}</Btn>}
          {app && <Badge tone="info">{L('طلب داخلي مُقدّم', 'Internal application sent')}</Badge>}
        </li> })}</ul>}</Panel>
      <Panel title={L('لوحة المتصدرين', 'Leaderboard')}><Leaderboard rows={d.leaderboard} /></Panel>
    </div>
    {im && <InternalPanel im={im} onApply={setInternal} />}
    <Panel title={<span className="flex items-center gap-2"><Gift size={17} className="text-brand" />{L('متجر المكافآت', 'Rewards store')}</span>}>
      {d.rewards.length === 0 ? <Empty title={L('المتجر فارغ الآن', 'The store is empty now')} /> : <div className="grid [grid-template-columns:repeat(auto-fill,minmax(250px,1fr))] gap-3">{d.rewards.map(w => { const can = d.points >= w.cost && w.stock > 0
        return <div key={w.id} className={`rounded-2xl border p-4 flex items-center gap-3 ${can ? 'border-line hoverlift' : 'border-line-2 opacity-60'}`}><div className="size-11 rounded-xl grid place-items-center bg-brand-soft text-brand">{rewardIcon[w.kind]}</div>
          <div className="flex-1 min-w-0"><div className="font-semibold text-[14px] truncate">{bi(w.title, lang)}</div><div className="text-[12px] text-brass font-bold num">{w.cost} {L('نقطة', 'pts')}{w.stock < 1 && <span className="text-ink-3 font-normal"> · {L('نفد', 'out of stock')}</span>}</div></div>
          <Btn size="sm" kind={can ? 'primary' : 'secondary'} disabled={!can || redeem.isPending} onClick={() => redeem.mutate(w.id, { onSuccess: () => toast(L('أُرسل الطلب وحُجزت النقاط بانتظار الموارد البشرية', 'Requested — points held pending HR')), onError: e => err(e, L('تعذّر الاستبدال', 'Could not redeem')) })}>{L('استبدال', 'Redeem')}</Btn></div> })}</div>}
      {d.redemptions.length > 0 && <div className="mt-4 space-y-1.5">{d.redemptions.map(r => <div key={r.id} className="flex flex-wrap gap-2 justify-between text-[13px] border-t border-line-2 pt-1.5"><span>{r.reward ? bi(r.reward.title, lang) : ''} · <span className="text-ink-3 num">{fmtDate(r.at, lang)}</span>{r.note && <span className="block text-[12px] text-ink-3">{r.note}</span>}</span><RedemptionBadge s={r.status} /></div>)}</div>}
    </Panel>
    <div className="grid lg:grid-cols-2 gap-5">
      <Panel title={L('إحالاتي', 'My referrals')} pad={false}>{d.referrals.length === 0 ? <Empty title={L('لم تشارك رابطاً بعد', 'No links shared yet')} /> : <ul className="divide-y divide-line-2">{d.referrals.map(r =>
        <li key={r.id} className="px-5 py-3 flex flex-wrap items-center gap-2 justify-between"><div className="min-w-0"><div className="font-medium">{r.job ? bi(r.job.title, lang) : '—'}</div><div className="text-[12px] text-ink-3">{r.candidate ? bi(r.candidate.name, lang) : L('لم يتقدّم أحد بعد', 'Nobody applied yet')} · <span className="num">{r.clicks}</span> {L('زيارة', 'visits')}</div></div><ReferralBadge s={r.status} awarded={r.awarded} /></li>)}</ul>}</Panel>
      <Panel title={<span className="flex items-center gap-2"><History size={16} className="text-brand" />{L('سجل النقاط', 'Points log')}</span>} pad={false}>{d.log.length === 0 ? <Empty title={L('لا حركات بعد', 'No movements yet')} /> : <ul className="divide-y divide-line-2">{d.log.map((l, i) =>
        <li key={i} className="px-5 py-2.5 flex items-center gap-3 text-[13px]"><span className={`font-bold num w-14 shrink-0 ${l.points >= 0 ? 'text-brand' : 'text-danger'}`}>{l.points > 0 ? '+' : ''}{l.points}</span><span className="flex-1 min-w-0">{bi(l.reason, lang)}</span><span className="text-ink-3 num text-[12px]">{fmtDate(l.at, lang)}</span></li>)}</ul>}</Panel>
    </div>
    {internal && im && <InternalModal job={internal} im={im} onClose={() => setInternal(null)} />}
  </div>
}

/** «ترشيح نفسي» F-27: الشروط من قواعد التوظيف، وطلباتي الداخلية بمسارها وعرض النقل */
function InternalPanel({ im, onApply }: { im: MyInternal; onApply: (j: InternalJob) => void }) {
  const L = useL(); const lang = useLang()
  const cancel = useCancelInternal()
  const [stop, setStop] = useState<number | null>(null)
  const open = im.jobs.filter(j => !j.application || ['rejected', 'cancelled'].includes(j.application.status))
  return <Panel title={L('الاستقطاب الداخلي — ترشيح نفسي (F-27)', 'Internal mobility — apply internally (F-27)')} sub={L(`الشروط: خدمة ${im.rules.min_months} شهراً على الأقل${im.rules.no_probation ? '، خارج فترة التجربة' : ''}${im.rules.clean_months ? `، بلا جزاء خلال ${im.rules.clean_months} أشهر` : ''}. يصل طلبك لمديرك المباشر أولاً.`,
    `Rules: at least ${im.rules.min_months} months of service${im.rules.no_probation ? ', past probation' : ''}${im.rules.clean_months ? `, no penalty in ${im.rules.clean_months} months` : ''}. Your manager sees it first.`)}>
    {!im.eligibility.ok && <div className="rounded-xl border border-warn/40 bg-warn/10 px-4 py-3 text-[13px] mb-3"><b>{L('لا يمكنك الترشيح الآن', 'You cannot apply yet')}:</b> {im.eligibility.reasons.map(r => bi(r, lang)).join('، ')}</div>}
    {im.eligibility.ok && open.length > 0 && <div className="flex flex-wrap gap-2 mb-3">{open.map(j => <Btn key={j.id} size="sm" icon={<Send size={14} />} onClick={() => onApply(j)}>{bi(j.title, lang)}{j.visibility === 'internal' && <span className="ms-1 text-[11px] text-ink-3">({L('داخلي', 'internal')})</span>}</Btn>)}</div>}
    {im.data.length === 0 ? <p className="text-[13px] text-ink-3">{L('لا طلبات داخلية لك.', 'You have no internal applications.')}</p> : <ul className="space-y-3">{im.data.map(r =>
      <li key={r.id} className="rounded-xl border border-line-2 p-3">
        <div className="flex flex-wrap items-center gap-2 justify-between"><div><b>{r.job ? bi(r.job.title, lang) : '—'}</b> <span className="ltr inline-block text-[12px] text-ink-3">{r.job?.code}</span><div className="text-[12px] text-ink-3 num">{fmtDate(r.created_at, lang)}</div></div>
          <div className="flex items-center gap-2"><LeaveStatus s={r.status} />{r.candidate_stage && <Badge tone="info">{stageName(r.candidate_stage, L)}</Badge>}
            {r.can_cancel && <Btn size="sm" kind="ghost" icon={<Ban size={14} />} onClick={() => setStop(r.id)}>{L('سحب', 'Withdraw')}</Btn>}</div></div>
        <div className="mt-2"><StepsPath steps={r.steps} /></div>
        <p className="text-[12.5px] text-ink-2 mt-1.5">{r.reason}</p>
        {r.offer && <OfferBox id={r.id} offer={r.offer} />}
      </li>)}</ul>}
    {stop && <DecisionDialog open onClose={() => setStop(null)} tone="danger" title={L('سحب الطلب الداخلي', 'Withdraw the internal application')} confirmLabel={L('سحب', 'Withdraw')}
      onConfirm={() => cancel.mutateAsync(stop).then(() => toast(L('سُحب الطلب', 'Withdrawn')))} />}
  </Panel>
}

function stageName(s: string, L: (a: string, e: string) => string) {
  return ({ new: L('جديد', 'New'), screening: L('فرز', 'Screening'), interview: L('مقابلة', 'Interview'), technical: L('تقييم فني', 'Technical'), offer: L('عرض', 'Offer'), appointment: L('قرار التعيين', 'Appointment'), hired: L('نُقلت', 'Moved'), rejected: L('لم يُقبل', 'Not selected') } as Record<string, string>)[s] ?? s
}

/** عرض النقل/الترقية F-29: قبول بتوقيع، رفض، أو تفاوض */
function OfferBox({ id, offer }: { id: number; offer: NonNullable<MyInternal['data'][number]['offer']> }) {
  const L = useL(); const lang = useLang()
  const respond = useInternalOffer()
  const [choice, setChoice] = useState<'accepted' | 'declined' | 'negotiation'>('accepted')
  const [sig, setSig] = useState<string | undefined>(); const [note, setNote] = useState('')
  return <div className="mt-3 rounded-xl border border-brand/30 bg-brand-soft/40 p-3">
    <div className="font-semibold text-[14px]">{L('عرض النقل الداخلي (F-29)', 'Internal move offer (F-29)')}</div>
    <div className="text-[13px] mt-1">{offer.title && <>{offer.title} · </>}<span className="num">{money(offer.total, offer.currency, lang)}</span>{offer.start_date && <> · {L('من', 'from')} <span className="num">{fmtDate(offer.start_date, lang)}</span></>}</div>
    {offer.status !== 'sent' ? <div className="mt-2"><Badge tone={offer.status === 'accepted' ? 'brand' : offer.status === 'declined' ? 'danger' : 'warn'}>{offer.status === 'accepted' ? L('قبلت العرض — بانتظار قرار النقل', 'Accepted — awaiting the move decision') : offer.status === 'declined' ? L('رفضت العرض', 'Declined') : L('طلبت التفاوض', 'Negotiation requested')}</Badge></div>
      : <div className="mt-3 space-y-3">
        <Seg value={choice} onChange={setChoice} options={[{ v: 'accepted', label: L('قبول', 'Accept') }, { v: 'negotiation', label: L('تفاوض', 'Negotiate') }, { v: 'declined', label: L('رفض', 'Decline') }]} />
        {choice === 'accepted' ? <Field div req label={L('توقيعك الإلكتروني', 'Your e-signature')}><SignaturePad value={sig} onChange={setSig} /></Field>
          : <Field label={L('ملاحظة', 'Note')}><textarea className="field" value={note} onChange={e => setNote(e.target.value)} /></Field>}
        <Btn kind="primary" disabled={respond.isPending || (choice === 'accepted' && !sig)} onClick={() => respond.mutate({ id, status: choice, signature: sig, note: note || undefined }, {
          onSuccess: () => toast(L('أُرسل ردّك', 'Response sent')), onError: e => toast(e instanceof ApiError ? e.message : L('تعذّر الإرسال', 'Could not send')) })}>{L('إرسال الرد', 'Send response')}</Btn>
      </div>}
  </div>
}

function InternalModal({ job, im, onClose }: { job: InternalJob; im: MyInternal; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const apply = useApplyInternal()
  const [reason, setReason] = useState('')
  const e = apply.error instanceof ApiError ? apply.error : null
  return <Modal open onClose={onClose} title={L('طلب توظيف / ترقية داخلي', 'Internal application')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={apply.isPending || !im.eligibility.ok || reason.trim().length < 5} onClick={() => apply.mutate({ job_id: job.id, reason }, { onSuccess: () => { toast(L('أُرسل الطلب لمديرك المباشر', 'Sent to your direct manager')); onClose() } })}>{L('إرسال الطلب', 'Submit')}</Btn></>}>
    <div className="text-[12px] text-ink-3 mb-3 ltr text-start">Uniqueness-HR-01-F-27</div>
    <p className="text-[14px] mb-3 font-semibold">{bi(job.title, lang)} <span className="ltr inline-block text-ink-3 font-normal text-[12px]">{job.code}</span></p>
    {!im.eligibility.ok && <p className="text-warn text-[13px] mb-2">{im.eligibility.reasons.map(r => bi(r, lang)).join('، ')}</p>}
    <Field req label={L('أسباب ودوافع التقدم لهذه الوظيفة', 'Why this role?')} error={e?.field('reason')}><textarea className="field" value={reason} onChange={x => setReason(x.target.value)} /></Field>
    <p className="text-[12px] text-ink-3 mt-2">{L('يراجع مديرك المباشر الطلب أولاً، ثم ينتقل لمسار التوظيف إن وافق.', 'Your direct manager reviews it first; if approved it joins the hiring pipeline.')}</p>
    {e && !e.field('reason') && <p className="text-danger text-[13px] mt-2">{e.message}</p>}
  </Modal>
}
