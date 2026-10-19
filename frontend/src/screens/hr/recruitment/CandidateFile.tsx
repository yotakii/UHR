import { useState } from 'react'
import { FileText, Download, ArrowRight, ArrowLeft, Loader2 } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../../lib/store'
import { navigate, setQuery, useRoute } from '../../../lib/router'
import { can, useMe } from '../../../lib/session'
import { fileUrl } from '../../../lib/console'
import { useCandidate, useMove, useNote, f25Axes, type CandFile } from '../../../lib/candidates'
import { STAGES, DEGREES, ENGLISH, type Stage } from '../../../lib/recruitment'
import type { ApiError } from '../../../lib/api'
import { Avatar, Badge, Btn, Empty, KV, Panel, Progress, Tabs, toast } from '../../../ui/kit'
import { Lifecycle, Paper, Timeline, DecisionDoc } from '../../../ui/docs'
import { TestTab, testStatus } from './TestTab'
import { Radar } from '../../../ui/viz'
import { DecisionDialog } from '../../../ui/decision'
import { rejectPresets } from './Board'
import { InterviewForm } from './InterviewForm'
import { OfferTab } from './OfferTab'
import { ApprovalActions } from './Approvals'

type Tab = 'file' | 'match' | 'test' | 'interview' | 'offer' | 'decision' | 'log'
const ORDER: Stage[] = ['new', 'screening', 'interview', 'technical', 'offer']

/** ملف المتقدم (M04 — CandidateFile في الديمو): البيانات، المطابقة، F-25، F-29، F-31، السجل */
export function CandidateFile({ id }: { id: number }) {
  const L = useL(); const lang = useLang()
  const { data: c, isLoading, error } = useCandidate(id)
  if (isLoading) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  if (error || !c) return <div className="max-w-[1200px] mx-auto bg-surface border border-line rounded-2xl"><Empty title={L('المتقدم غير موجود', 'Applicant not found')} action={<a href="/hr/candidates" className="text-brand font-semibold">{L('العودة للمتقدمين', 'Back to applicants')}</a>} /></div>
  return <File c={c} key={c.id} lang={lang} />
}

function File({ c, lang }: { c: CandFile; lang: 'ar' | 'en' }) {
  const L = useL(); const P = lang === 'ar' ? '٪' : '%'
  const route = useRoute(); const me = useMe().data
  const canEdit = can(me, 'candidates', 'edit'), canDecide = can(me, 'candidates', 'decide')
  const appointed = !!c.appointment?.complete
  const tab = (route.query.tab as Tab) || (c.appointment && c.stage !== 'hired' ? 'decision' : 'file')
  const setTab = (t: Tab) => setQuery({ tab: t })
  const move = useMove()
  const [reject, setReject] = useState(false)
  const next = ORDER.indexOf(c.stage) >= 0 ? ORDER[ORDER.indexOf(c.stage) + 1] : undefined
  const Back = lang === 'ar' ? ArrowRight : ArrowLeft
  const name = bi(c.name, lang)
  const doMove = (to: Stage) => move.mutate({ id: c.id, stage: to }, { onSuccess: () => toast(L(`نُقل إلى ${STAGES[to].ar}`, `Moved to ${STAGES[to].en}`)), onError: e => toast((e as ApiError).message) })

  return <div className="max-w-[1200px] mx-auto">
    <a href="/hr/candidates" className="text-[13px] text-ink-3 hover:text-ink inline-flex items-center gap-1 mb-2" data-noprint><Back size={14} />{L('المتقدمون', 'Applicants')}</a>
    <div className="bg-surface border border-line rounded-2xl p-5 mb-4" data-noprint>
      <div className="flex flex-wrap items-start gap-4">
        {c.photo ? <img src={fileUrl(c.photo.id, true)} alt="" className="size-[52px] rounded-full object-cover border border-line" /> : <Avatar size={52} tone="brand" />}
        <div className="flex-1 min-w-[220px]">
          <div className="flex items-center gap-2 flex-wrap"><h1 className="text-[21px] font-bold">{name}</h1>
            {c.internal && <Badge tone="info">{L('طلب داخلي F-27', 'Internal F-27')}</Badge>}{c.referrer && <Badge tone="brass">{L(`إحالة من ${c.referrer.name.ar}`, `Referred by ${c.referrer.name.en}`)}</Badge>}
            {c.cv_status === 'erased' && <Badge tone="danger">{L('محذوف بطلب المتقدم', 'Erased on request')}</Badge>}
            {c.band === 'review' && <Badge tone="warn">{L('مطابقة منخفضة — للمراجعة', 'Low match — review')}</Badge>}
            <Badge tone={c.stage === 'rejected' ? 'danger' : c.stage === 'hired' ? 'brand' : 'info'}>{STAGES[c.stage][lang]}</Badge></div>
          <div className="text-ink-2 text-[14px]">{bi(c.job.title, lang)} — <span className="ltr inline-block">{c.job.code}</span></div>
          <div className="text-[12px] text-ink-3 mt-0.5"><span className="ltr inline-block num">{c.ref}</span> — {L('قُدّم', 'Applied')} {fmtDate(c.created_at, lang)}</div>
          {c.stage === 'rejected' && c.reject_reason && <div className="text-[13px] text-danger mt-1">{L('السبب:', 'Reason:')} {c.reject_reason}</div>}
        </div>
        <div className="text-center px-4 border-s border-line-2"><div className="text-[12px] text-ink-3">{L('مطابقة المتطلبات', 'Requirement match')}</div><div className={`text-[28px] font-bold num ${c.match >= 75 ? 'text-brand' : c.match >= 55 ? 'text-brass' : 'text-ink-3'}`}>{c.match}{P}</div></div>
        {c.test && <button type="button" onClick={() => setTab('test')} className="text-center px-4 border-s border-line-2"><div className="text-[12px] text-ink-3">{L('الاختبار الأولي', 'Preliminary test')}</div>
          {c.test.pct !== null ? <div className="text-[28px] font-bold num text-info">{c.test.pct}{P}</div> : <div className="mt-1.5"><Badge tone={testStatus(c.test.status, L)[1]}>{testStatus(c.test.status, L)[0]}</Badge></div>}</button>}
        <div className="flex gap-2 flex-wrap">
          {canEdit && next && c.stage !== 'offer' && next !== 'offer' && !c.appointment && <Btn kind="primary" disabled={move.isPending} onClick={() => doMove(next)}>{L('نقل إلى', 'Move to')} {STAGES[next][lang]}</Btn>}
          {canEdit && c.stage === 'technical' && !c.offer && <Btn kind="primary" onClick={() => setTab('offer')}>{L('إعداد عرض العمل', 'Prepare offer')}</Btn>}
          {canEdit && c.stage === 'rejected' && <Btn onClick={() => doMove('screening')}>{L('إعادة فتح الطلب', 'Reopen application')}</Btn>}
          {canDecide && !['hired', 'rejected'].includes(c.stage) && <Btn kind="danger" onClick={() => setReject(true)}>{L('عدم قبول', 'Not selected')}</Btn>}
          {c.stage === 'hired' && c.appointment?.employee_id && <Btn kind="primary" onClick={() => navigate(`/hr/onboarding/${c.appointment!.employee_id}`)}>{L('فتح إجراءات المباشرة', 'Open onboarding')}</Btn>}
        </div>
      </div>
      <div className="mt-5"><Lifecycle steps={[
        { label: L('استلام الطلب', 'Received'), code: 'F-28', done: true },
        { label: L('الفرز', 'Screening'), done: ['interview', 'technical', 'offer', 'hired'].includes(c.stage), current: c.stage === 'screening' || c.stage === 'new' },
        { label: L('المقابلة', 'Interview'), code: 'F-25', done: !!c.interview, current: c.stage === 'interview' },
        { label: L('التقييم الفني', 'Technical'), done: ['offer', 'hired'].includes(c.stage), current: c.stage === 'technical' },
        { label: L('عرض العمل', 'Offer'), code: 'F-29', done: c.offer?.status === 'accepted', current: c.stage === 'offer' && c.offer?.status !== 'accepted' },
        { label: L('قرار التعيين', 'Appointment'), code: 'F-31', done: appointed, current: c.offer?.status === 'accepted' && !appointed },
        { label: L('المباشرة', 'Onboarding'), code: 'F-19', done: false, current: c.stage === 'hired' },
      ]} /></div>
    </div>

    <Tabs value={tab} onChange={setTab} tabs={[
      { v: 'file', label: L('بيانات الطلب', 'Application') }, { v: 'match', label: L('المطابقة', 'Match') }, { v: 'test', label: L('الاختبار الأولي', 'Preliminary test') }, { v: 'interview', label: L('المقابلة الأولية', 'HR interview') },
      { v: 'offer', label: L('عرض العمل', 'Offer') }, { v: 'decision', label: L('قرار التعيين', 'Appointment') }, { v: 'log', label: L('السجل', 'History'), count: c.logs.length }]} />
    <div className="mt-4">
      {tab === 'file' && <FileTab c={c} />}
      {tab === 'match' && <MatchTab c={c} />}
      {tab === 'test' && <TestTab c={c} />}
      {tab === 'interview' && <InterviewForm c={c} readOnly={!canEdit} />}
      {tab === 'offer' && <OfferTab c={c} readOnly={!canEdit} />}
      {tab === 'decision' && (c.appointment
        ? <Paper code="F-31" subject={c.appointment.decision_no} title={L('قرار التعيين المالي والإداري', 'Appointment decision')} sub={L('مطابقة الملاك العددي وسلسلة الاعتماد', 'Headcount check and approval chain')}
            actions={<ApprovalActions ap={c.appointment} who={name} />}>
            <DecisionDoc c={{ name: c.name_ar, nameEn: c.name_en ?? undefined }} ap={c.appointment} jobTitle={bi(c.job.title, lang)} deptName={bi(c.job.dept, lang)} />
            <div className="grid sm:grid-cols-3 gap-3 mt-5">
              <KV k={L('الملاك العددي المعتمد للإدارة', 'Approved headcount')} v={<span className="num">{c.appointment.headcount.approved || '—'}</span>} />
              <KV k={L('الموجود حالياً', 'Current')} v={<span className={`num ${c.appointment.headcount.approved && c.appointment.headcount.current >= c.appointment.headcount.approved ? 'text-danger' : ''}`}>{c.appointment.headcount.current}</span>} />
              <KV k={L('مطابقة الشهادة', 'Degree check')} v={c.appointment.degree_ok ? L('مطابق', 'Matches') : <span className="text-danger">{L('أدنى من المطلوب', 'Below requirement')}</span>} />
            </div>
          </Paper>
        : <Panel><div className="text-ink-2 text-[14px]">{L('يُفتح قرار التعيين تلقائياً بعد أن يقبل المتقدم عرض العمل ويوقّعه.', 'The appointment decision opens automatically once the applicant accepts and signs the offer.')}</div></Panel>)}
      {tab === 'log' && <LogTab c={c} canEdit={canEdit} />}
    </div>

    <DecisionDialog open={reject} onClose={() => setReject(false)} tone="danger" reasonRequired presets={rejectPresets(L)}
      title={L('عدم قبول المتقدم', 'Not selecting the applicant')} reasonLabel={L('سبب عدم القبول', 'Reason for not selecting')}
      subject={<><span className="font-semibold">{name}</span> · {bi(c.job.title, lang)}<div className="text-[13px] text-ink-2 mt-1">{L('سيصل المتقدم إشعار مهذب بانتهاء مراجعة طلبه.', 'The applicant will receive a courteous notice.')}</div></>}
      confirmLabel={L('تأكيد عدم القبول', 'Confirm')}
      onConfirm={reason => move.mutateAsync({ id: c.id, stage: 'rejected', reason }).then(() => toast(L('أُغلق الطلب وأُبلغ المتقدم', 'Application closed and applicant notified')))} />
  </div>
}

function FileTab({ c }: { c: CandFile }) {
  const L = useL(); const lang = useLang()
  const yes = (b: boolean) => b ? L('يمانع', 'Not OK') : L('لا يمانع', 'OK')
  return <div className="grid lg:grid-cols-3 gap-4">
    <Panel title={L('البيانات الشخصية', 'Personal')}>
      <KV k={L('الجنس', 'Gender')} v={c.gender === 'M' ? L('ذكر', 'Male') : L('أنثى', 'Female')} />
      <KV k={L('تاريخ الولادة', 'Born')} v={`${fmtDate(c.dob ?? undefined, lang)}${c.pob ? ' — ' + c.pob : ''}`} />
      <KV k={L('الجنسية', 'Nationality')} v={c.nationality ?? '—'} /><KV k={L('الرقم الوطني', 'National ID')} v={<span className="ltr inline-block num">{c.national_id ?? '—'}</span>} />
      <KV k={L('الجوال', 'Mobile')} v={<span className="ltr inline-block">{c.mobile}</span>} /><KV k={L('البريد', 'Email')} v={<span className="ltr inline-block break-all">{c.email}</span>} />
      <KV k={L('العنوان', 'Address')} v={c.address ?? '—'} />
      {c.linkedin && <KV k="LinkedIn" v={<a href={c.linkedin} target="_blank" rel="noopener noreferrer" className="text-brand ltr inline-block truncate max-w-[180px]">{c.linkedin.replace(/^https?:\/\//, '')}</a>} />}
    </Panel>
    <Panel title={L('المؤهلات والخبرة', 'Qualifications & experience')}>
      {c.education.map((e, i) => <div key={i} className="pb-2 mb-2 border-b border-line-2"><div className="font-medium">{e.phase} — {e.major}</div><div className="text-[13px] text-ink-3">{e.institute} {e.year && `· ${e.year}`} {e.grade && `· ${e.grade}`}</div></div>)}
      {c.experience.map((e, i) => <div key={i} className="pb-2 mb-2 border-b border-line-2"><div className="font-medium">{e.position || '—'}</div><div className="text-[13px] text-ink-3">{e.company} · {e.from}–{e.to}</div></div>)}
      <KV k={L('سنوات الخبرة', 'Years')} v={<span className="num">{c.years_exp}</span>} /><KV k={L('المؤهل', 'Degree')} v={DEGREES[c.degree_level]?.[lang]} /><KV k={L('الإنكليزية', 'English')} v={ENGLISH[c.english]?.[lang] ?? c.english} />
    </Panel>
    <Panel title={L('الأهلية والمرفقات', 'Eligibility & files')}>
      <KV k={L('الراتب المتوقع', 'Expected salary')} v={<span className="num">${c.salary}</span>} />
      <KV k={L('العمل بالورديات', 'Shift work')} v={yes(c.shifts)} /><KV k={L('السفر', 'Travel')} v={yes(c.travel)} />
      <KV k={L('حكم قضائي', 'Conviction')} v={c.conviction ? L('نعم', 'Yes') : L('لا', 'No')} />
      {c.referrer && <KV k={L('المُحيل', 'Referred by')} v={`${bi(c.referrer.name, lang)} · ${c.referrer.code}`} />}
      {c.refs.map((r, i) => <KV key={i} k={L('مرجع', 'Reference')} v={`${r.name} — ${r.phone}`} />)}
      <div className="mt-3 flex items-center gap-2 border border-line rounded-md p-2.5"><FileText size={18} className="text-brand shrink-0" /><span className="flex-1 truncate text-[13px] ltr text-start">{c.cv?.name ?? L('لا توجد سيرة ذاتية', 'No CV')}</span>
        {c.cv && <a href={fileUrl(c.cv.id)} target="_blank" rel="noopener" className="text-ink-3 hover:text-ink" aria-label={L('تنزيل السيرة', 'Download CV')}><Download size={16} /></a>}</div>
    </Panel>
  </div>
}

function MatchTab({ c }: { c: CandFile }) {
  const L = useL(); const lang = useLang(); const P = lang === 'ar' ? '٪' : '%'
  return <Panel title={L('مطابقة المتقدم مع متطلبات الوظيفة', 'Applicant vs role requirements')} sub={L('حساب بقواعد ثابتة وأوزان معتمدة في سياسات التوظيف', 'Rule-based calculation with the weights set in hiring policy')}>
    <div className="grid lg:grid-cols-[1fr_auto] gap-8 items-center"><div className="space-y-4 max-w-2xl min-w-0">{c.match_parts.map(p => <div key={p.key} className="grid grid-cols-[120px_1fr] sm:grid-cols-[150px_1fr_120px] gap-x-4 gap-y-1 items-center text-[14px]">
      <span>{p[lang]} <span className="text-ink-3 text-[12px] num">({p.weight}{P})</span></span><Progress value={p.score * 100} tone={p.score >= 0.75 ? 'brand' : p.score >= 0.45 ? 'brass' : 'danger'} /><span className="text-[13px] text-ink-2 col-span-2 sm:col-span-1">{lang === 'ar' ? p.note_ar : p.note_en}</span></div>)}
      <div className="pt-3 border-t border-line-2 flex justify-between font-semibold"><span>{L('النتيجة', 'Result')}</span><span className="num">{c.match}{P}</span></div>
      <div className="text-[13px] text-ink-3">{L('المطلوب', 'Required')}: {c.job.min_years} {L('سنوات خبرة', 'years')} · {DEGREES[c.job.degree]?.[lang]} · <bdi dir="ltr" className="num">${c.job.salary_min}–${c.job.salary_max}</bdi></div>
      {c.internal_reason && <div className="text-[13px] bg-info-soft rounded-lg p-3"><b>F-27 · </b>{c.internal_reason}</div>}
    </div>
    <div className="flex flex-wrap justify-center gap-4"><div className="text-center"><Radar size={240} axes={c.match_parts.map(p => ({ label: p[lang], value: p.score }))} /><div className="text-[12px] text-ink-3">{L('مطابقة المتطلبات', 'Requirement match')}</div></div>
      {c.interview && <div className="text-center"><Radar size={240} axes={f25Axes(c.interview.ratings, lang)} /><div className="text-[12px] text-ink-3">{L('تقييم المقابلة F-25', 'Interview F-25')}</div></div>}</div></div>
  </Panel>
}

function LogTab({ c, canEdit }: { c: CandFile; canEdit: boolean }) {
  const L = useL()
  const add = useNote(c.id)
  const [note, setNote] = useState('')
  return <Panel>
    {canEdit && <div className="flex gap-2 mb-4" data-noprint><input className="field" placeholder={L('أضف ملاحظة على الملف', 'Add a note to the file')} value={note} onChange={e => setNote(e.target.value)} maxLength={1000} />
      <Btn kind="primary" disabled={!note.trim() || add.isPending} onClick={() => add.mutate(note, { onSuccess: () => setNote('') })}>{L('إضافة', 'Add')}</Btn></div>}
    <Timeline items={c.logs.map(l => ({ ...l, form: l.form ?? undefined }))} />
  </Panel>
}
