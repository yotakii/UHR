import { useState } from 'react'
import { FileText, ArrowRight, ArrowLeft, Download, Clock, Settings2 } from 'lucide-react'
import { RulesModal, Radar, f25Axes } from './Rewards'
import { useStore, useL, useLang, bi, fmtDate, todayISO, addDays, money } from '../lib/store'
import { moveStage, saveInterview, sendOffer, approveAppointment, addNote } from '../lib/actions'
import { useNav } from '../lib/nav'
import { Btn, Panel, Badge, Avatar, PageHead, Tabs, Modal, Field, KV, Progress, toast } from '../ui/kit'
import { Paper, OfferDoc, DecisionDoc, Timeline, Lifecycle, SigBox } from '../ui/docs'
import { STAGES, stageInfo, matchScore, slaState, daysInStage, F25, f25Score, f25Label, DEGREES, ENG } from '../lib/rules'
import type { Stage, Candidate, Offer } from '../lib/types'

export function Recruitment() {
  const L = useL(); const lang = useLang(); const { go } = useNav(); const P = lang === 'ar' ? '٪' : '%'
  const cands = useStore(s => s.candidates); const jobs = useStore(s => s.jobs)
  const [job, setJob] = useState('all'); const [q, setQ] = useState('')
  const [drag, setDrag] = useState<string | null>(null); const [over, setOver] = useState<Stage | null>(null)
  const [reject, setReject] = useState<string | null>(null); const [reason, setReason] = useState(''); const [rulesOpen, setRulesOpen] = useState(false)
  const list = cands.filter(c => (job === 'all' || c.jobId === job) && (!q || c.name.includes(q) || c.ref.includes(q)))
  const drop = (to: Stage) => {
    setOver(null); if (!drag) return; const c = cands.find(x => x.id === drag)!; setDrag(null)
    if (c.stage === to) return
    if (c.appointment && to !== 'rejected') { toast(L('لا يمكن نقل المتقدم بعد قبول العرض — أكمل قرار التعيين', 'Cannot move after offer acceptance — complete the appointment')); return }
    if (to === 'hired') { toast(L('التعيين يتم عبر قرار التعيين بعد قبول العرض', 'Hiring happens through the appointment decision after offer acceptance')); return }
    if (to === 'offer' && !c.offer) { go('candidate', c.id + ':offer'); return }
    if (to === 'rejected') { setReject(c.id); return }
    moveStage(c.id, to); toast(L(`نُقل ${c.name} إلى ${stageInfo(to).ar}`, `${c.name} moved to ${stageInfo(to).en}`))
  }
  return <div className="max-w-[1500px] mx-auto">
    <PageHead title={L('المتقدمون', 'Applicants')} sub={L('اسحب البطاقة لنقل المتقدم بين المراحل، أو افتحها لعرض ملفه.', 'Drag a card to move it between stages, or open it to see the file.')}
      actions={<><Btn icon={<Settings2 size={15} />} onClick={() => setRulesOpen(true)}>{L('قواعد الأتمتة', 'Automation rules')}</Btn><input className="field !w-56" placeholder={L('بحث بالاسم أو رقم الطلب', 'Search name or reference')} value={q} onChange={e => setQ(e.target.value)} />
        <select className="field !w-60" value={job} onChange={e => setJob(e.target.value)}><option value="all">{L('كل الوظائف', 'All positions')}</option>{jobs.map(j => <option key={j.id} value={j.id}>{bi(j.title, lang)} — {j.code}</option>)}</select></>} />
    <div className="flex gap-3 overflow-x-auto pb-3 items-start">
      {STAGES.map(st => { const items = list.filter(c => c.stage === st.id).sort((a, b) => b.createdAt.localeCompare(a.createdAt))
        return <section key={st.id} onDragOver={e => { e.preventDefault(); setOver(st.id) }} onDragLeave={() => setOver(null)} onDrop={() => drop(st.id)}
          className={`w-[280px] shrink-0 rounded-2xl border ${over === st.id ? 'border-brand bg-brand-soft' : 'border-line bg-sub/70'}`}>
          <header className="h-11 px-3 flex items-center justify-between"><span className="font-semibold text-[14px]">{st[lang]}</span>
            <span className="text-[12px] text-ink-3 num" title={st.sla ? L(`مهلة المرحلة ${st.sla} أيام`, `Stage limit ${st.sla} days`) : ''}>{items.length}</span></header>
          <div className="px-2 pb-2 space-y-2 min-h-[120px] max-h-[calc(100vh-250px)] overflow-y-auto">
            {items.map(c => { const j = jobs.find(x => x.id === c.jobId); const m = matchScore(c, j).total; const sla = slaState(c)
              return <article key={c.id} draggable onDragStart={() => setDrag(c.id)} onDragEnd={() => setDrag(null)} onClick={() => go('candidate', c.id)}
                className={`hoverlift bg-surface rounded-xl border p-3 cursor-pointer ${c.visitor ? 'border-brass' : 'border-line'} ${drag === c.id ? 'opacity-50' : ''}`}>
                <div className="flex items-start gap-2.5"><Avatar name={c.name} size={30} tone={c.visitor ? 'brass' : 'brand'} />
                  <div className="flex-1 min-w-0"><div className="font-semibold text-[14px] truncate">{lang === 'ar' ? c.name : (c.nameEn || c.name)}</div><div className="text-[12px] text-ink-3 truncate">{j ? bi(j.title, lang) : ''}</div></div>
                  <span className={`text-[13px] font-bold num ${m >= 75 ? 'text-brand' : m >= 55 ? 'text-brass' : 'text-ink-3'}`}>{m}{P}</span></div>
                <div className="flex items-center justify-between mt-2.5 text-[12px]">
                  <span className="flex items-center gap-1.5"><span className="text-ink-3 ltr num">{c.ref.replace('UNQ-2026-APP-', '#').replace('UNQ-2026-INT-', 'INT-')}</span>{c.internal && <Badge tone="info">{L('داخلي', 'Internal')}</Badge>}{c.referrerId && <Badge tone="brass">{L('إحالة', 'Referral')}</Badge>}{c.autoFlag === 'below' && c.stage === 'new' && <Badge tone="warn">{L('دون الحد', 'Below gate')}</Badge>}</span>
                  {sla === 'late' ? <Badge tone="danger"><Clock size={11} />{L(`متأخر ${daysInStage(c) - stageInfo(c.stage).sla} ي`, `${daysInStage(c) - stageInfo(c.stage).sla}d over`)}</Badge>
                    : sla === 'due' ? <Badge tone="warn">{L('يستحق اليوم', 'Due today')}</Badge>
                    : c.offer?.status === 'accepted' ? <Badge tone="brand">{L('وقّع العرض', 'Offer signed')}</Badge>
                    : c.offer?.status === 'negotiation' ? <Badge tone="warn">{L('طلب تعديل', 'Negotiating')}</Badge>
                    : <span className="text-ink-3">{daysInStage(c)} {L('ي بالمرحلة', 'd in stage')}</span>}
                </div>
              </article> })}
          </div>
        </section> })}
    </div>
    <RulesModal key={String(rulesOpen)} open={rulesOpen} onClose={() => setRulesOpen(false)} />
    <Modal open={!!reject} onClose={() => setReject(null)} title={L('سبب عدم القبول', 'Reason for not selecting')}
      footer={<><Btn onClick={() => setReject(null)}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="danger" onClick={() => { moveStage(reject!, 'rejected', reason || L('تم شغل الوظيفة', 'Position filled')); setReject(null); setReason('') }}>{L('تأكيد', 'Confirm')}</Btn></>}>
      <select className="field" value={reason} onChange={e => setReason(e.target.value)}><option value="">—</option>{[L('لا تتوفر الخبرة المطلوبة', 'Insufficient experience'), L('الراتب المتوقع خارج النطاق', 'Salary expectation out of range'), L('نتيجة المقابلة غير كافية', 'Interview result insufficient'), L('تم شغل الوظيفة', 'Position filled')].map(r => <option key={r}>{r}</option>)}</select>
      <p className="text-[13px] text-ink-3 mt-2">{L('سيصل المتقدم إشعار مهذب بانتهاء مراجعة طلبه.', 'The applicant will receive a courteous notice.')}</p>
    </Modal>
  </div>
}

/* ======================= candidate file ======================= */
export function CandidateFile({ id: raw }: { id: string }) {
  const [cid, initTab] = raw.split(':')
  const L = useL(); const lang = useLang(); const { go } = useNav(); const P = lang === 'ar' ? '٪' : '%'
  const c = useStore(s => s.candidates.find(x => x.id === cid))
  const job = useStore(s => s.jobs.find(j => j.id === c?.jobId))
  const depts = useStore(s => s.depts)
  const hr = useStore(s => s.employees.find(e => e.id === s.session?.id))
  const [tab, setTab] = useState(initTab || (c?.appointment && c.stage !== 'hired' ? 'decision' : 'file'))
  const [note, setNote] = useState('')
  if (!c || !job) return null
  const m = matchScore(c, job)
  const order: Stage[] = ['new', 'screening', 'interview', 'technical', 'offer']
  const nextStage = order.indexOf(c.stage) >= 0 ? order[order.indexOf(c.stage) + 1] : undefined
  const appointed = c.appointment?.approvals.every(a => a.status === 'approved')
  const Back = lang === 'ar' ? ArrowRight : ArrowLeft
  return <div className="max-w-[1200px] mx-auto">
    <button onClick={() => go('recruitment')} className="text-[13px] text-ink-3 hover:text-ink flex items-center gap-1 mb-2" data-noprint><Back size={14} />{L('المتقدمون', 'Applicants')}</button>
    <div className="bg-surface border border-line rounded-2xl p-5 mb-4" data-noprint>
      <div className="flex flex-wrap items-start gap-4">
        <Avatar name={c.name} size={52} tone={c.visitor ? 'brass' : 'brand'} />
        <div className="flex-1 min-w-[220px]">
          <div className="flex items-center gap-2 flex-wrap"><h1 className="text-[21px] font-bold">{lang === 'ar' ? c.name : (c.nameEn || c.name)}</h1>{c.visitor && <Badge tone="brass">{L('زائر المعرض', 'Expo visitor')}</Badge>}{c.internal && <Badge tone="info">{L('طلب داخلي F-27', 'Internal F-27')}</Badge>}{c.referrerId && <Badge tone="brass">{L('عبر إحالة', 'Referred')}</Badge>}<Badge tone={c.stage === 'rejected' ? 'danger' : c.stage === 'hired' ? 'brand' : 'info'}>{stageInfo(c.stage)[lang]}</Badge></div>
          <div className="text-ink-2 text-[14px]">{bi(job.title, lang)} — <span className="ltr">{job.code}</span></div>
          <div className="text-[12px] text-ink-3 mt-0.5"><span className="ltr num">{c.ref}</span> — {L('قُدّم', 'Applied')} {fmtDate(c.createdAt, lang)}</div>
        </div>
        <div className="text-center px-4 border-s border-line-2"><div className="text-[12px] text-ink-3">{L('مطابقة المتطلبات', 'Requirement match')}</div><div className={`text-[28px] font-bold num ${m.total >= 75 ? 'text-brand' : m.total >= 55 ? 'text-brass' : 'text-ink-3'}`}>{m.total}{P}</div></div>
        <div className="flex gap-2 flex-wrap">
          {nextStage && c.stage !== 'offer' && nextStage !== 'offer' && <Btn kind="primary" onClick={() => moveStage(c.id, nextStage)}>{L('نقل إلى', 'Move to')} {stageInfo(nextStage)[lang]}</Btn>}
          {c.stage === 'technical' && !c.offer && <Btn kind="primary" onClick={() => setTab('offer')}>{L('إعداد عرض العمل', 'Prepare offer')}</Btn>}
          {!['hired', 'rejected'].includes(c.stage) && <Btn kind="danger" onClick={() => moveStage(c.id, 'rejected', L('نتيجة المقابلة غير كافية', 'Interview result insufficient'))}>{L('عدم قبول', 'Not selected')}</Btn>}
          {c.stage === 'hired' && c.appointment?.employeeId && <Btn kind="primary" onClick={() => go('onboard', c.appointment!.employeeId!)}>{L('فتح إجراءات المباشرة', 'Open onboarding')}</Btn>}
        </div>
      </div>
      <div className="mt-5"><Lifecycle steps={[
        { label: L('استلام الطلب', 'Received'), code: 'F-28', done: true },
        { label: L('الفرز', 'Screening'), done: ['interview', 'technical', 'offer', 'hired'].includes(c.stage), current: c.stage === 'screening' || c.stage === 'new' },
        { label: L('المقابلة', 'Interview'), code: 'F-25', done: !!c.interview, current: c.stage === 'interview' },
        { label: L('التقييم الفني', 'Technical'), done: ['offer', 'hired'].includes(c.stage), current: c.stage === 'technical' },
        { label: L('عرض العمل', 'Offer'), code: 'F-29', done: c.offer?.status === 'accepted', current: c.stage === 'offer' && c.offer?.status !== 'accepted' },
        { label: L('قرار التعيين', 'Appointment'), code: 'F-31', done: !!appointed, current: c.offer?.status === 'accepted' && !appointed },
        { label: L('المباشرة', 'Onboarding'), code: 'F-19', done: false, current: c.stage === 'hired' },
      ]} /></div>
    </div>

    <Tabs value={tab} onChange={setTab} tabs={[
      { v: 'file', label: L('بيانات الطلب', 'Application') }, { v: 'match', label: L('المطابقة', 'Match') }, { v: 'interview', label: L('المقابلة الأولية', 'HR interview') },
      { v: 'offer', label: L('عرض العمل', 'Offer') }, { v: 'decision', label: L('قرار التعيين', 'Appointment') }, { v: 'log', label: L('السجل', 'History'), count: c.log.length }]} />
    <div className="mt-4">
      {tab === 'file' && <div className="grid lg:grid-cols-3 gap-4">
        <Panel title={L('البيانات الشخصية', 'Personal')}>
          <KV k={L('الجنس', 'Gender')} v={c.gender === 'M' ? L('ذكر', 'Male') : L('أنثى', 'Female')} /><KV k={L('تاريخ الولادة', 'Born')} v={`${fmtDate(c.dob, lang)} — ${c.pob}`} />
          <KV k={L('الجنسية', 'Nationality')} v={c.nationality} /><KV k={L('الرقم الوطني', 'National ID')} v={<span className="ltr num">{c.nationalId}</span>} />
          <KV k={L('الجوال', 'Mobile')} v={<span className="ltr">{c.mobile}</span>} /><KV k={L('البريد', 'Email')} v={<span className="ltr">{c.email}</span>} /><KV k={L('العنوان', 'Address')} v={c.address} />
        </Panel>
        <Panel title={L('المؤهلات والخبرة', 'Qualifications & experience')}>
          {c.education.map((e, i) => <div key={i} className="pb-2 mb-2 border-b border-line-2"><div className="font-medium">{e.phase} — {e.major}</div><div className="text-[13px] text-ink-3">{e.institute} {e.year && `· ${e.year}`} {e.grade && `· ${e.grade}`}</div></div>)}
          {c.experience.map((e, i) => <div key={i} className="pb-2 mb-2 border-b border-line-2"><div className="font-medium">{e.position}</div><div className="text-[13px] text-ink-3">{e.company} · {e.from}–{e.to}</div></div>)}
          <KV k={L('سنوات الخبرة', 'Years')} v={c.yearsExp} /><KV k={L('الإنكليزية', 'English')} v={ENG[c.english]?.[lang] ?? c.english} />
        </Panel>
        <Panel title={L('الأهلية والمرفقات', 'Eligibility & files')}>
          <KV k={L('الراتب المتوقع', 'Expected salary')} v={`$${c.salary}`} />
          <KV k={L('العمل بالورديات', 'Shift work')} v={c.shifts ? L('يمانع', 'Not OK') : L('لا يمانع', 'OK')} />
          <KV k={L('السفر', 'Travel')} v={c.travel ? L('يمانع', 'Not OK') : L('لا يمانع', 'OK')} />
          <KV k={L('حكم قضائي', 'Conviction')} v={c.conviction ? L('نعم', 'Yes') : L('لا', 'No')} />
          {c.refs.map((r, i) => <KV key={i} k={L('مرجع', 'Reference')} v={`${r.name} — ${r.phone}`} />)}
          <div className="mt-3 flex items-center gap-2 border border-line rounded-md p-2.5"><FileText size={18} className="text-brand" /><span className="flex-1 truncate text-[13px] ltr text-start">{c.cvName ?? L('لا توجد سيرة ذاتية', 'No CV')}</span>
            {c.cvData && <a href={c.cvData} download={c.cvName} className="text-ink-3 hover:text-ink"><Download size={16} /></a>}</div>
        </Panel>
      </div>}

      {tab === 'match' && <Panel title={L('مطابقة المتقدم مع متطلبات الوظيفة', 'Applicant vs role requirements')} sub={L('حساب بقواعد ثابتة وأوزان قابلة للتعديل من سياسات التوظيف', 'Rule-based calculation with adjustable weights')}>
        <div className="grid lg:grid-cols-[1fr_auto] gap-8 items-center"><div className="space-y-4 max-w-2xl">{m.parts.map(p => <div key={p.key} className="grid grid-cols-[150px_1fr_120px] gap-4 items-center text-[14px]">
          <span>{p[lang]} <span className="text-ink-3 text-[12px]">({p.weight}{P})</span></span><Progress value={p.score * 100} tone={p.score >= 0.75 ? 'brand' : p.score >= 0.45 ? 'brass' : 'danger'} /><span className="text-[13px] text-ink-2">{lang === 'ar' ? p.noteAr : p.noteEn}</span></div>)}
          <div className="pt-3 border-t border-line-2 flex justify-between font-semibold"><span>{L('النتيجة', 'Result')}</span><span className="num">{m.total}{P}</span></div>
          <div className="text-[13px] text-ink-3">{L('المطلوب', 'Required')}: {job.minYears} {L('سنوات خبرة', 'years')} · {DEGREES[job.degree][lang]} · ${job.salaryMin}–${job.salaryMax}</div>
          {c.internal && <div className="text-[13px] bg-info-soft rounded-lg p-3"><b>F-27 · </b>{c.internal.reason}</div>}
        </div>
        <div className="flex flex-wrap justify-center gap-4"><div className="text-center"><Radar axes={m.parts.map(p => ({ label: p[lang], value: p.score }))} /><div className="text-[12px] text-ink-3">{L('مطابقة المتطلبات', 'Requirement match')}</div></div>
          {c.interview && <div className="text-center"><Radar axes={f25Axes(c.interview.ratings, lang)} /><div className="text-[12px] text-ink-3">{L('تقييم المقابلة F-25', 'Interview F-25')}</div></div>}</div></div>
      </Panel>}

      {tab === 'interview' && <InterviewForm c={c} jobTitle={bi(job.title, lang)} deptName={depts.find(d => d.id === job.dept)![lang]} interviewer={hr?.name ?? ''} />}
      {tab === 'offer' && <OfferTab c={c} jobTitle={job.title.ar} salaryMin={job.salaryMin} />}
      {tab === 'decision' && (c.appointment ? <Paper code="F-31" title={L('قرار التعيين المالي والإداري', 'Appointment decision')} sub={L('مطابقة الملاك العددي وسلسلة الاعتماد الرباعية', 'Headcount check and four-level approval')}
        actions={!appointed ? <Btn size="sm" kind="primary" onClick={() => { const eid = approveAppointment(c.id); if (eid) toast(L('اكتمل الاعتماد وأُنشئ ملف الموظف', 'Approved — employee file created')) }}>{L('اعتماد المستوى التالي', 'Approve next level')} ({c.appointment.approvals.find(a => a.status === 'pending')?.role[lang]})</Btn> : undefined}>
        <DecisionDoc c={c} ap={c.appointment} jobTitle={bi(job.title, lang)} deptName={depts.find(d => d.id === job.dept)![lang]} />
        <div className="grid sm:grid-cols-3 gap-3 mt-5">
          <KV k={L('الملاك العددي المعتمد للإدارة', 'Approved headcount')} v={job.openings + 6} /><KV k={L('الموجود حالياً', 'Current')} v={5} /><KV k={L('مطابقة الشهادة', 'Degree check')} v={c.degreeLevel >= job.degree ? L('مطابق', 'Matches') : L('أدنى من المطلوب', 'Below requirement')} />
        </div>
      </Paper> : <Panel><div className="text-ink-2 text-[14px]">{L('يُفتح قرار التعيين تلقائياً بعد أن يقبل المتقدم عرض العمل ويوقّعه.', 'The appointment decision opens automatically once the applicant accepts and signs the offer.')}</div></Panel>)}
      {tab === 'log' && <Panel>
        <div className="flex gap-2 mb-4" data-noprint><input className="field" placeholder={L('أضف ملاحظة على الملف', 'Add a note to the file')} value={note} onChange={e => setNote(e.target.value)} /><Btn kind="primary" disabled={!note.trim()} onClick={() => { addNote(c.id, note, hr?.name ?? 'HR'); setNote('') }}>{L('إضافة', 'Add')}</Btn></div>
        <Timeline items={c.log} />
      </Panel>}
    </div>
  </div>
}

function InterviewForm({ c, jobTitle, deptName, interviewer }: { c: Candidate; jobTitle: string; deptName: string; interviewer: string }) {
  const L = useL(); const lang = useLang(); const P = lang === 'ar' ? '٪' : '%'
  const [r, setR] = useState<Record<string, number>>(() => c.interview?.ratings ?? Object.fromEntries(F25.flatMap(g => g.items.map(i => [i.id, 3]))))
  const [notes, setNotes] = useState(c.interview?.notes ?? '')
  const [rec, setRec] = useState(c.interview?.recommendation ?? 'recommended')
  const sc = f25Score(r)
  return <Paper code="F-25" title={L('تقييم المقابلة الأولية', 'HR initial interview evaluation')} actions={<Btn size="sm" kind="primary" onClick={() => { saveInterview(c.id, { ratings: r, notes, recommendation: rec, interviewer, date: todayISO() }, sc.pct); toast(L('حُفظ التقييم', 'Evaluation saved')) }}>{L('اعتماد التقييم', 'Save evaluation')}</Btn>}>
    <div className="grid sm:grid-cols-4 gap-3 mb-5">{[[L('المتقدم', 'Applicant'), c.name], [L('الوظيفة', 'Position'), jobTitle], [L('الإدارة', 'Department'), deptName], [L('تاريخ المقابلة', 'Date'), fmtDate(c.interview?.date ?? todayISO(), lang)]].map(([k, v]) => <div key={k} className="border border-line rounded-md p-2.5"><div className="text-[12px] text-ink-3">{k}</div><div className="font-medium">{v}</div></div>)}</div>
    <div className="grid lg:grid-cols-2 gap-5">
      {F25.map((g, gi) => <div key={g.key} className="border border-line rounded-md">
        <div className="flex justify-between px-3 h-10 items-center border-b border-line-2 bg-sub"><span className="font-semibold text-[14px]">{gi + 1}. {g[lang]}</span><span className="num text-[13px]">{sc.cats[gi].toFixed(1)} / 7.5</span></div>
        <div className="p-3 space-y-2">{g.items.map(it => <div key={it.id} className="grid grid-cols-[1fr_auto] items-center gap-3 text-[13px]">
          <span>{it[lang]}</span>
          <div className="flex gap-1" data-noprint>{[1, 2, 3, 4, 5].map(v => <button key={v} type="button" onClick={() => setR({ ...r, [it.id]: v })} className={`size-7 rounded border text-[12px] num ${r[it.id] === v ? 'bg-ink text-canvas border-ink' : r[it.id] > v ? 'bg-sub border-line' : 'border-line bg-surface'}`}>{v}</button>)}</div>
        </div>)}</div>
      </div>)}
    </div>
    <div className="grid lg:grid-cols-[1fr_280px] gap-5 mt-5">
      <div className="space-y-3">
        <Field label={L('انطباعات اللجنة وملاحظات التوصية', 'Panel notes')}><textarea className="field" value={notes} onChange={e => setNotes(e.target.value)} /></Field>
        <div className="flex flex-wrap gap-2">{([['recommended', L('موصى به لعرض العمل', 'Recommend for offer')], ['reserve', L('احتياط', 'Reserve')], ['rejected', L('غير موصى به', 'Not recommended')]] as const).map(([v, t]) =>
          <button key={v} type="button" onClick={() => setRec(v)} className={`h-9 px-3 rounded-md border text-[13px] ${rec === v ? (v === 'rejected' ? 'bg-danger text-on-brand border-danger' : 'bg-brand text-on-brand border-brand') : 'border-line bg-surface'}`}>{t}</button>)}</div>
      </div>
      <div className="border-2 border-ink rounded-md p-4 text-center"><div className="text-[12px] text-ink-3">{L('النتيجة الإجمالية', 'Total score')}</div><div className="text-[30px] font-bold num">{sc.total.toFixed(1)} <span className="text-[16px] text-ink-3">/ 30</span></div><div className="text-[13px] font-medium">{sc.pct}{P} — {f25Label(sc.pct)[lang]}</div>
        <div className="text-[12px] text-ink-3 mt-2">{L('المقابِل', 'Interviewer')}: {c.interview?.interviewer ?? interviewer}</div></div>
    </div>
  </Paper>
}

function OfferTab({ c, jobTitle, salaryMin }: { c: Candidate; jobTitle: string; salaryMin: number }) {
  const L = useL(); const lang = useLang()
  const base = Math.max(salaryMin, Math.round(Math.min(c.salary, c.salary) * 0.8 / 10) * 10)
  const [o, setO] = useState<Omit<Offer, 'issuedAt' | 'status'>>(() => c.offer ?? { title: jobTitle, contract: 'fixed', currency: 'USD', basic: base, housing: Math.round(base * 0.12), transport: 40, fuel: 30, leaveDays: 14, startDate: todayISO() })
  const [editing, setEditing] = useState(!c.offer || c.offer.status === 'negotiation')
  const total = o.basic + o.housing + o.transport + o.fuel
  if (c.offer && !editing) return <Paper code="F-29" title={L('عرض العمل الرسمي', 'Official job offer')} actions={c.offer.status !== 'accepted' ? <Btn size="sm" onClick={() => setEditing(true)}>{L('تعديل العرض', 'Edit offer')}</Btn> : undefined}>
    <div className="mb-4">{c.offer.status === 'sent' ? <Badge tone="info">{L('أُرسل — بانتظار رد المتقدم', 'Sent — awaiting response')}</Badge> : c.offer.status === 'accepted' ? <Badge tone="brand">{L('مقبول وموقّع', 'Accepted & signed')}</Badge> : c.offer.status === 'declined' ? <Badge tone="danger">{L('اعتذر المتقدم', 'Declined')}</Badge> : <Badge tone="warn">{L('طلب تعديل', 'Change requested')}</Badge>}</div>
    <OfferDoc c={c} offer={c.offer} jobTitle={jobTitle} />
    <div className="grid sm:grid-cols-2 gap-4 mt-6"><SigBox label={L('عن الشركة — الموارد البشرية', 'For the company — HR')} name="رشا الخطيب" at={c.offer.issuedAt} /><SigBox label={L('توقيع المتقدم', 'Applicant signature')} name={c.name} sig={c.offer.signature} at={c.offer.respondedAt} /></div>
  </Paper>
  const num = (k: 'basic' | 'housing' | 'transport' | 'fuel' | 'leaveDays') => <input type="number" className="field num" value={o[k]} onChange={e => setO({ ...o, [k]: Number(e.target.value) })} />
  return <Panel title={L('إعداد عرض العمل', 'Prepare job offer')} sub="HR-01-F-29">
    {c.offer?.status === 'negotiation' && <div className="mb-4 text-[13px] bg-warn-soft text-warn rounded px-3 py-2">{L('طلب المتقدم', 'Applicant asked')}: {c.offer.note}</div>}
    <div className="grid md:grid-cols-3 gap-4">
      <Field label={L('المسمى الوظيفي المعتمد', 'Approved title')}><input className="field" value={o.title} onChange={e => setO({ ...o, title: e.target.value })} /></Field>
      <Field label={L('طبيعة العقد', 'Contract')}><select className="field" value={o.contract} onChange={e => setO({ ...o, contract: e.target.value as Offer['contract'] })}><option value="fixed">{L('محدد المدة — سنة قابلة للتجديد', 'Fixed term 1yr')}</option><option value="open">{L('غير محدد المدة', 'Open-ended')}</option><option value="project">{L('عقد مشروع', 'Project')}</option></select></Field>
      <Field label={L('العملة', 'Currency')}><select className="field" value={o.currency} onChange={e => setO({ ...o, currency: e.target.value as 'USD' | 'SYP' })}><option value="USD">{L('دولار أمريكي', 'US dollar')}</option><option value="SYP">{L('ليرة سورية', 'Syrian pound')}</option></select></Field>
      <Field label={L('الراتب الأساسي', 'Basic salary')}>{num('basic')}</Field><Field label={L('بدل السكن / غلاء المعيشة', 'Housing / COL')}>{num('housing')}</Field><Field label={L('بدل المواصلات', 'Transport')}>{num('transport')}</Field>
      <Field label={L('بدل المحروقات', 'Fuel')}>{num('fuel')}</Field><Field label={L('أيام الإجازة السنوية', 'Annual leave days')}>{num('leaveDays')}</Field><Field label={L('تاريخ المباشرة', 'Start date')}><input type="date" className="field" value={o.startDate} onChange={e => setO({ ...o, startDate: e.target.value })} /></Field>
    </div>
    <div className="flex flex-wrap items-center justify-between gap-3 mt-5 pt-4 border-t border-line-2">
      <div className="text-[14px]">{L('الإجمالي الشهري', 'Monthly total')}: <span className="font-bold num">{money(total, o.currency, lang)}</span> <span className="text-ink-3 text-[13px]">— {L('توقّع المتقدم', 'expected')} ${c.salary}</span></div>
      <div className="flex gap-2">{c.offer && <Btn onClick={() => setEditing(false)}>{L('إلغاء', 'Cancel')}</Btn>}<Btn kind="primary" onClick={() => { sendOffer(c.id, o); setEditing(false); toast(L('أُرسل عرض العمل للمتقدم', 'Offer sent to applicant')) }}>{L('إصدار العرض وإرساله', 'Issue and send offer')}</Btn></div>
    </div>
  </Panel>
}
