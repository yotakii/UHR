import { useState } from 'react'
import { LogOut, Loader2, Search } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../lib/store'
import { useRoute } from '../../lib/router'
import { Back, CompanyLogo } from '../public/parts'
import { ApiError } from '../../lib/api'
import { queryClient, useLogout, type SessionUser } from '../../lib/session'
import { STAGES, useMyApplication, useMyApplications, useRespondOffer, type MyApplication } from '../../lib/recruitment'
import { Btn, Panel, Badge, SignaturePad, Field, KV, Seg, Empty, toast } from '../../ui/kit'
import { Logo, LangToggle, ThemeToggle } from '../../ui/brand'
import { Lifecycle, Paper, OfferDoc, DecisionDoc, SigBox } from '../../ui/docs'

type Choice = 'accepted' | 'negotiation' | 'declined'
const ORDER = ['new', 'screening', 'interview', 'technical', 'offer', 'hired']

/** M05 — بوابة المتقدم: كل طلباته لدى كل الشركات (SRS §10.15) */
export function CandidateHome({ user }: { user: SessionUser }) {
  const L = useL(); const lang = useLang()
  const logout = useLogout()
  const route = useRoute()
  const id = route.page === 'my' && route.id ? Number(route.id) : null
  return <div className="min-h-full flex flex-col">
    <header className="h-16 bg-surface border-b border-line flex items-center justify-between gap-3 px-5 md:px-8">
      <div className="flex items-center gap-4"><a href="/" aria-label="UHR"><Logo /></a>
        <a href="/jobs" className="hidden sm:inline-flex items-center gap-1.5 h-9 px-3 rounded-lg text-[13px] font-semibold text-ink-2 hover:text-ink hover:bg-sub"><Search size={15} />{L('فرص العمل', 'Jobs')}</a></div>
      <div className="flex items-center gap-3"><span className="text-[13px] text-ink-2 hidden sm:block">{bi(user.name, lang)}</span><LangToggle /><span className="hidden sm:inline-flex"><ThemeToggle /></span><Btn size="sm" kind="ghost" icon={<LogOut size={15} />} onClick={() => logout.mutate()} disabled={logout.isPending}><span className="hidden sm:inline">{L('خروج', 'Sign out')}</span></Btn></div>
    </header>
    <main className="flex-1 max-w-5xl w-full mx-auto px-5 md:px-8 py-6 space-y-5">
      {id ? <ApplicationDetail id={id} /> : <MyApplications />}
    </main>
  </div>
}

const STAGE_TONE: Record<string, 'neutral' | 'brand' | 'brass' | 'info' | 'danger'> = { new: 'info', screening: 'info', interview: 'brass', technical: 'brass', offer: 'brand', hired: 'brand', rejected: 'neutral' }

/** «طلباتي»: بطاقة لكل طلب بشركته ومرحلته */
function MyApplications() {
  const L = useL(); const lang = useLang()
  const { data, isLoading, error, refetch } = useMyApplications()
  return <>
    <div className="flex flex-wrap items-end justify-between gap-3">
      <div><h1 className="text-[22px] font-bold">{L('طلباتي', 'My applications')}</h1><p className="text-ink-2 text-[14px]">{L('كل طلبات التوظيف التي قدّمتها، لدى كل الشركات.', 'All the job applications you submitted, across companies.')}</p></div>
      <a href="/jobs" className="inline-flex items-center gap-2 rounded-lg border font-semibold h-9 px-3.5 bg-brand text-on-brand border-brand hover:brightness-110"><Search size={15} />{L('تصفّح فرص العمل', 'Browse jobs')}</a>
    </div>
    {isLoading && <div className="grid place-items-center py-20"><Loader2 className="animate-spin text-brand" /></div>}
    {error && <Panel><Empty title={L('تعذّر تحميل طلباتك', 'Could not load your applications')} action={<Btn onClick={() => refetch()}>{L('إعادة المحاولة', 'Retry')}</Btn>}>{error.message}</Empty></Panel>}
    {data?.length === 0 && <Panel><Empty title={L('لا توجد طلبات بعد', 'No applications yet')} action={<a href="/jobs" className="underline text-brand">{L('ابدأ بالتقديم على وظيفة', 'Start by applying for a job')}</a>} /></Panel>}
    <div className="grid gap-3">{data?.map(a => <a key={a.id} href={`/my/${a.id}`} className="hoverlift rounded-2xl border border-line bg-surface p-4 flex flex-col sm:flex-row sm:items-center gap-3">
      <div className="flex items-center gap-3 flex-1 min-w-0"><CompanyLogo c={a.company} size={48} />
        <div className="min-w-0"><div className="font-semibold text-[15px] truncate">{bi(a.job.title, lang)}</div>
          <div className="text-[13px] text-ink-2 truncate">{bi(a.company.name, lang)}</div>
          <div className="text-[12px] text-ink-3"><span className="ltr num">{a.ref}</span> · {L('قُدّم', 'Submitted')} {fmtDate(a.created_at, lang)}</div></div></div>
      <div className="flex flex-wrap gap-1.5 sm:justify-end">
        {a.offer === 'sent' && <Badge tone="brass" dot>{L('عرض عمل بانتظار ردّك', 'Offer awaiting your reply')}</Badge>}
        {a.appointment && !a.appointment.complete && <Badge tone="info">{L('قرار التعيين قيد الاعتماد', 'Appointment under approval')}</Badge>}
        <Badge tone={STAGE_TONE[a.stage]}>{a.stage === 'rejected' ? L('انتهت المراجعة', 'Review completed') : STAGES[a.stage][lang]}</Badge>
      </div>
    </a>)}</div>
  </>
}

function ApplicationDetail({ id }: { id: number }) {
  const L = useL()
  const { data: c, isLoading, error, refetch } = useMyApplication(id)
  return <>
    <Back to="/">{L('كل طلباتي', 'All my applications')}</Back>
    {isLoading && <div className="grid place-items-center py-20"><Loader2 className="animate-spin text-brand" /></div>}
    {error && <Panel><Empty title={L('تعذّر تحميل الطلب', 'Could not load this application')} action={<Btn onClick={() => refetch()}>{L('إعادة المحاولة', 'Retry')}</Btn>}>{error.message}</Empty></Panel>}
    {c && <Application c={c} />}
  </>
}

function Application({ c }: { c: MyApplication }) {
  const L = useL(); const lang = useLang()
  const idx = ORDER.indexOf(c.stage)
  const accepted = c.offer?.status === 'accepted'
  const appointed = !!c.appointment?.complete
  const steps = [
    { label: L('استلام الطلب', 'Received'), code: 'F-28' }, { label: L('الفرز الأولي', 'Screening') }, { label: L('المقابلة', 'Interview'), code: 'F-25' },
    { label: L('التقييم الفني', 'Technical') }, { label: L('عرض العمل', 'Offer'), code: 'F-29' }, { label: L('قرار التعيين', 'Appointment'), code: 'F-31' },
  ].map((s, i) => ({ ...s, done: c.stage === 'hired' || (i < 5 ? i < idx || (i === 4 && accepted) : appointed), current: c.stage !== 'rejected' && (i === idx || (i === 5 && accepted && !appointed)) }))
  const person = { name: c.name, nameEn: c.name_en ?? undefined }

  return <>
    <div className="flex items-center gap-3">
      <CompanyLogo c={c.company} size={52} />
      <div className="min-w-0"><div className="text-[13px] text-ink-3">{bi(c.company.name, lang)} · {L('طلب التوظيف', 'Application')} <span className="ltr num">{c.ref}</span></div>
        <h1 className="text-[22px] font-bold">{bi(c.job.title, lang)}</h1></div>
    </div>
    <Panel>
      {c.stage === 'rejected'
        ? <div><Badge tone="neutral">{L('انتهت مراجعة الطلب', 'Review completed')}</Badge><p className="mt-2 text-ink-2 max-w-[65ch]">{L('شكراً لاهتمامك بالعمل معنا. لم يتم اختيار طلبك لهذه الوظيفة، وسيبقى ملفك في قاعدة بياناتنا للفرص القادمة.', 'Thank you for your interest. You were not selected for this role; your file stays on record for future openings.')}</p></div>
        : <Lifecycle steps={steps} />}
    </Panel>

    {c.stage === 'hired' && <Panel>
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div><h2 className="font-bold text-[17px]">{L('مبارك، صدر قرار تعيينك', 'Congratulations — your appointment is final')}</h2><p className="text-ink-2 text-[14px]">{L('تم تفعيل حسابك كموظف بنفس البريد وكلمة المرور. أكمل إجراءات المباشرة من ملفك الوظيفي.', 'Your employee account is active with the same email and password. Complete onboarding from your employee file.')}</p></div>
        {/* نفس حساب الدخول صار مرتبطاً بملف الموظف — يكفي تحديث الجلسة لتنتقل الواجهة لبوابة الموظف */}
        <Btn kind="primary" onClick={() => queryClient.invalidateQueries({ queryKey: ['me'] })}>{L('الدخول كموظف', 'Continue as employee')}</Btn>
      </div>
    </Panel>}

    {c.offer?.status === 'sent' && <div className="grid lg:grid-cols-[1fr_340px] gap-5 items-start">
      <Paper code="F-29" title={L('عرض العمل الرسمي', 'Official job offer')}><OfferDoc c={person} offer={c.offer} jobTitle={c.job.title.ar} /></Paper>
      <OfferResponse id={c.id} />
    </div>}

    {c.offer?.status === 'negotiation' && <Panel><Badge tone="warn">{L('طلب التعديل قيد الدراسة', 'Change request under review')}</Badge><p className="text-ink-2 mt-2">{c.offer.note}</p></Panel>}
    {c.offer?.status === 'declined' && <Panel><Badge tone="neutral">{L('اعتذرت عن عرض العمل', 'You declined the offer')}</Badge>{c.offer.note && <p className="text-ink-2 mt-2">{c.offer.note}</p>}</Panel>}

    {accepted && c.appointment && c.stage !== 'hired' && <Paper code="F-31" title={L('قرار التعيين المالي والإداري', 'Appointment decision')} sub={L('قيد الاعتماد — ستصلك رسالة عند اكتماله', 'Under approval — you will be notified when complete')}>
      <DecisionDoc c={person} ap={c.appointment} jobTitle={bi(c.job.title, lang)} deptName={bi(c.job.dept, lang)} />
      <div className="mt-5 max-w-xs"><SigBox label={L('توقيعك على عرض العمل', 'Your signature on the offer')} sig={c.offer?.signature ?? undefined} at={c.offer?.responded_at ?? undefined} /></div>
    </Paper>}

    <Panel title={L('ملخص الطلب', 'Application summary')}>
      <div className="grid sm:grid-cols-2 gap-x-8">
        <KV k={L('تاريخ التقديم', 'Submitted')} v={fmtDate(c.created_at, lang)} />
        <KV k={L('المرحلة الحالية', 'Current stage')} v={STAGES[c.stage][lang]} />
        <KV k={L('البريد', 'Email')} v={<span className="ltr">{c.email}</span>} />
        <KV k={L('الجوال', 'Mobile')} v={<span className="ltr">{c.mobile}</span>} />
        <KV k={L('الراتب المتوقع', 'Expected salary')} v={`$${c.salary}`} />
        <KV k={L('السيرة الذاتية', 'CV')} v={c.cv?.name ?? '—'} />
      </div>
    </Panel>
  </>
}

/** «ردّك على العرض»: القبول بتوقيع إلزامي، طلب تعديل بنص إلزامي، أو اعتذار بسبب اختياري */
function OfferResponse({ id }: { id: number }) {
  const L = useL()
  const [choice, setChoice] = useState<Choice>('accepted')
  const [sig, setSig] = useState<string>()
  const [note, setNote] = useState('')
  const respond = useRespondOffer(id)
  const err = respond.error instanceof ApiError ? (respond.error.field('signature') ?? respond.error.field('note') ?? respond.error.message) : respond.error?.message
  const ready = choice === 'accepted' ? !!sig : choice === 'negotiation' ? !!note.trim() : true
  const send = () => respond.mutate({ status: choice, signature: choice === 'accepted' ? sig : undefined, note: choice === 'accepted' ? undefined : note.trim() }, {
    onSuccess: () => toast(choice === 'accepted' ? L('تم قبول العرض وتوقيعه', 'Offer accepted and signed') : choice === 'negotiation' ? L('أُرسل طلب التعديل', 'Change request sent') : L('تم تسجيل اعتذارك', 'Your decline was recorded')),
  })
  return <Panel title={L('ردّك على العرض', 'Your response')}>
    <div className="space-y-4">
      <Seg value={choice} onChange={v => { setChoice(v); respond.reset() }} options={[{ v: 'accepted', label: L('قبول', 'Accept') }, { v: 'negotiation', label: L('طلب تعديل', 'Negotiate') }, { v: 'declined', label: L('اعتذار', 'Decline') }]} />
      {choice === 'accepted' && <Field div req label={L('توقيعك الإلكتروني', 'Your e-signature')}><SignaturePad value={sig} onChange={setSig} /></Field>}
      {choice !== 'accepted' && <Field req={choice === 'negotiation'} label={choice === 'negotiation' ? L('ما البنود التي تطلب تعديلها؟', 'Which terms would you like changed?') : L('سبب الاعتذار (اختياري)', 'Reason (optional)')}><textarea className="field" maxLength={1000} value={note} onChange={e => setNote(e.target.value)} /></Field>}
      {err && <div role="alert" className="text-[13px] text-danger bg-danger-soft rounded px-3 py-2">{err}</div>}
      <Btn kind={choice === 'declined' ? 'danger' : 'primary'} className="w-full" disabled={!ready || respond.isPending} onClick={send}>
        {choice === 'accepted' ? L('قبول العرض وتوقيعه', 'Accept and sign') : choice === 'negotiation' ? L('إرسال طلب التعديل', 'Send change request') : L('تأكيد الاعتذار', 'Confirm decline')}</Btn>
    </div>
  </Panel>
}
