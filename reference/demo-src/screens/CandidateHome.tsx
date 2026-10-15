import { useState } from 'react'
import { LogOut } from 'lucide-react'
import { useStore, useL, useLang, bi, fmtDate, useRelaxed, autoSig } from '../lib/store'
import { logout, respondOffer, login } from '../lib/actions'
import { Btn, Panel, Badge, SignaturePad, Field, KV, Seg } from '../ui/kit'
import { Logo, LangToggle } from '../ui/brand'
import { Lifecycle, Paper, OfferDoc, DecisionDoc, SigBox } from '../ui/docs'
import { stageInfo } from '../lib/rules'

export function CandidateHome() {
  const L = useL(); const lang = useLang()
  const id = useStore(s => s.session!.id)
  const c = useStore(s => s.candidates.find(x => x.id === id))
  const job = useStore(s => s.jobs.find(j => j.id === c?.jobId))
  const depts = useStore(s => s.depts)
  const [choice, setChoice] = useState<'accepted' | 'declined' | 'negotiation'>('accepted')
  const [sig, setSig] = useState<string>()
  const [note, setNote] = useState('')
  const relaxed = useRelaxed()
  if (!c || !job) return null
  const order = ['new', 'screening', 'interview', 'technical', 'offer', 'hired']
  const idx = order.indexOf(c.stage)
  const appointed = c.appointment?.approvals.every(a => a.status === 'approved')
  const steps = [
    { label: L('استلام الطلب', 'Received'), code: 'F-28' }, { label: L('الفرز الأولي', 'Screening') }, { label: L('المقابلة', 'Interview'), code: 'F-25' },
    { label: L('التقييم الفني', 'Technical') }, { label: L('عرض العمل', 'Offer'), code: 'F-29' }, { label: L('قرار التعيين', 'Appointment'), code: 'F-31' },
  ].map((s, i) => ({ ...s, done: c.stage === 'hired' || (i < 5 ? i < idx || (i === 4 && c.offer?.status === 'accepted') : !!appointed), current: c.stage !== 'rejected' && (i === idx || (i === 5 && c.offer?.status === 'accepted' && !appointed)) }))

  return <div className="min-h-full">
    <header className="h-16 bg-surface border-b border-line flex items-center justify-between px-5 md:px-8">
      <Logo />
      <div className="flex items-center gap-3"><span className="text-[13px] text-ink-2 hidden sm:block">{c.name}</span><LangToggle /><Btn size="sm" kind="ghost" icon={<LogOut size={15} />} onClick={logout}>{L('خروج', 'Sign out')}</Btn></div>
    </header>
    <main className="max-w-5xl mx-auto px-5 md:px-8 py-6 space-y-5">
      <div>
        <div className="text-[13px] text-ink-3">{L('طلب التوظيف', 'Application')} <span className="ltr num">{c.ref}</span></div>
        <h1 className="text-[22px] font-bold">{bi(job.title, lang)}</h1>
      </div>
      <Panel>
        {c.stage === 'rejected'
          ? <div><Badge tone="neutral">{L('انتهت مراجعة الطلب', 'Review completed')}</Badge><p className="mt-2 text-ink-2 max-w-[65ch]">{L('شكراً لاهتمامك بالعمل معنا. لم يتم اختيار طلبك لهذه الوظيفة، وسيبقى ملفك في قاعدة بياناتنا للفرص القادمة.', 'Thank you for your interest. You were not selected for this role; your file stays on record for future openings.')}</p></div>
          : <Lifecycle steps={steps} />}
      </Panel>

      {c.stage === 'hired' && <Panel>
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div><h2 className="font-bold text-[17px]">{L('مبارك، صدر قرار تعيينك', 'Congratulations — your appointment is final')}</h2><p className="text-ink-2 text-[14px]">{L('تم تفعيل حسابك كموظف بنفس البريد وكلمة المرور. أكمل إجراءات المباشرة من ملفك الوظيفي.', 'Your employee account is active with the same email and password. Complete onboarding from your employee file.')}</p></div>
          <Btn kind="primary" onClick={() => { const e = c.email, p = c.password!; logout(); login(e, p) }}>{L('الدخول كموظف', 'Continue as employee')}</Btn>
        </div>
      </Panel>}

      {c.offer && c.offer.status === 'sent' && <div className="grid lg:grid-cols-[1fr_340px] gap-5 items-start">
        <Paper code="F-29" title={L('عرض العمل الرسمي', 'Official job offer')}><OfferDoc c={c} offer={c.offer} jobTitle={job.title.ar} /></Paper>
        <Panel title={L('ردّك على العرض', 'Your response')}>
          <div className="space-y-4">
            <Seg value={choice} onChange={setChoice} options={[{ v: 'accepted', label: L('قبول', 'Accept') }, { v: 'negotiation', label: L('طلب تعديل', 'Negotiate') }, { v: 'declined', label: L('اعتذار', 'Decline') }]} />
            {choice === 'accepted' && <Field div label={L('توقيعك الإلكتروني', 'Your e-signature')}><SignaturePad value={sig} onChange={setSig} /></Field>}
            {choice !== 'accepted' && <Field label={choice === 'negotiation' ? L('ما البنود التي تطلب تعديلها؟', 'Which terms would you like changed?') : L('سبب الاعتذار (اختياري)', 'Reason (optional)')}><textarea className="field" value={note} onChange={e => setNote(e.target.value)} /></Field>}
            <Btn kind={choice === 'declined' ? 'danger' : 'primary'} className="w-full" disabled={relaxed ? false : choice === 'accepted' ? !sig : choice === 'negotiation' ? !note.trim() : false} onClick={() => respondOffer(c.id, choice, choice === 'accepted' ? (sig ?? autoSig(c.name)) : sig, note || (choice === 'negotiation' ? 'زيادة بدل المواصلات' : ''))}>
              {choice === 'accepted' ? L('قبول العرض وتوقيعه', 'Accept and sign') : choice === 'negotiation' ? L('إرسال طلب التعديل', 'Send change request') : L('تأكيد الاعتذار', 'Confirm decline')}</Btn>
          </div>
        </Panel>
      </div>}

      {c.offer && c.offer.status === 'negotiation' && <Panel><Badge tone="warn">{L('طلب التعديل قيد الدراسة', 'Change request under review')}</Badge><p className="text-ink-2 mt-2">{c.offer.note}</p></Panel>}

      {c.offer?.status === 'accepted' && c.appointment && c.stage !== 'hired' && <Paper code="F-31" title={L('قرار التعيين المالي والإداري', 'Appointment decision')} sub={L('قيد الاعتماد — ستصلك رسالة عند اكتماله', 'Under approval — you will be notified when complete')}>
        <DecisionDoc c={c} ap={c.appointment} jobTitle={job.title[lang]} deptName={depts.find(d => d.id === job.dept)![lang]} />
        <div className="mt-5 max-w-xs"><SigBox label={L('توقيعك على عرض العمل', 'Your signature on the offer')} sig={c.offer.signature} at={c.offer.respondedAt} /></div>
      </Paper>}

      <Panel title={L('ملخص الطلب', 'Application summary')}>
        <div className="grid sm:grid-cols-2 gap-x-8">
          <KV k={L('تاريخ التقديم', 'Submitted')} v={fmtDate(c.createdAt, lang)} />
          <KV k={L('المرحلة الحالية', 'Current stage')} v={stageInfo(c.stage)[lang]} />
          <KV k={L('البريد', 'Email')} v={<span className="ltr">{c.email}</span>} />
          <KV k={L('الجوال', 'Mobile')} v={<span className="ltr">{c.mobile}</span>} />
          <KV k={L('الراتب المتوقع', 'Expected salary')} v={`$${c.salary}`} />
          <KV k={L('السيرة الذاتية', 'CV')} v={c.cvName ?? '—'} />
        </div>
      </Panel>
    </main>
  </div>
}
