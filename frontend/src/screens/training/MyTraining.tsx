import { useState } from 'react'
import { CalendarDays, Check, FileSignature, GraduationCap, Plus, Send, Upload, X } from 'lucide-react'
import { useL, useLang, bi, fmtDate, money } from '../../lib/store'
import { PATH, S_STATUS, E_STATUS, useMyTraining, useSession, useTdAct, type MyTraining as MT, type Path } from '../../lib/training'
import { Btn, Empty, Field, Modal, PageHead, Panel, SignaturePad, Tabs, toast } from '../../ui/kit'
import { ConfirmSend, DecisionDialog } from '../../ui/decision'
import { PathBadge, Spin, StatusBadge, fail } from './parts'
import { ImpactsTab, QualEval, RecordView, UploadCert } from './TdLibrary'
import { AttendanceGrid, Scores } from './TdSessions'
import { PrintBtn } from './TdMore'
import { useCalendarLink } from '../../lib/training'

/** رابط اشتراك للقراءة فقط (Outlook / Google) + تنزيل ملف التقويم */
function CalLink() {
  const L = useL()
  const { data } = useCalendarLink()
  return <span className="inline-flex gap-1"><a className="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg border border-line text-[13px]" href="/api/me/training/calendar.ics"><CalendarDays size={15} />{L('أضف إلى تقويمي', 'Add to my calendar')}</a>
    {data && <Btn kind="ghost" onClick={() => navigator.clipboard?.writeText(data.url).then(() => toast(L('نُسخ رابط الاشتراك — أضفه في Outlook أو Google Calendar ليتحدّث تلقائياً', 'Subscription link copied — add it in Outlook or Google Calendar to stay updated')))}>{L('رابط الاشتراك', 'Subscription link')}</Btn>}</span>
}

/** «تدريبي» في بوابة الموظف (الدفعة 23): سجلي التدريبي، التعهدات للتوقيع، خطة التطبيق F-12، وللمدير: الترشيحات وقياس الأثر وتقييم التأهيل، وللمدرب الداخلي: الحضور والنتائج */
export function MyTraining() {
  const L = useL(); const lang = useLang()
  const { data } = useMyTraining()
  const act = useTdAct()
  const [tab, setTab] = useState<'mine' | 'team' | 'trainer'>('mine')
  const [sign, setSign] = useState<MT['to_sign'][number] | null>(null)
  const [apply, setApply] = useState<{ id: number; title: string } | null>(null)
  const [need, setNeed] = useState(false)
  const [cert, setCert] = useState(false)
  const [wd, setWd] = useState<number | null>(null)
  const [ev, setEv] = useState<number | null>(null)
  const [trainer, setTrainer] = useState<number | null>(null)
  if (!data) return <Spin />
  if (!data.record) return <Panel><Empty title={L('لا يوجد ملف موظف مرتبط بحسابك', 'No employee file is linked to your account')} /></Panel>
  const r = data.record
  const teamCount = data.team_nominations.length + data.impacts + data.stations.length + data.evaluations.length
  const toApply = r.enrollments.filter(e => e.result === 'passed' && !e.apply_plan)
  return <div className="max-w-[1100px] mx-auto">
    <PageHead title={L('تدريبي', 'My training')} sub={L('دوراتك وشهاداتك وكفاءاتك، والتعهدات التي تحتاج توقيعك.', 'Your courses, certificates and competencies, and the commitments that need your signature.')}
      actions={<span className="flex flex-wrap gap-2"><CalLink />
        <Btn icon={<Upload size={15} />} onClick={() => setCert(true)}>{L('شهادة خارجية', 'External certificate')}</Btn>
        {(data.mode === 'self_service' || data.is_manager) && <Btn kind="primary" icon={<Plus size={15} />} onClick={() => setNeed(true)}>{L('طلب تدريب', 'Request training')}</Btn>}</span>} />
    {(data.is_manager || data.trainer_sessions.length > 0) && <div className="mb-4"><Tabs value={tab} onChange={setTab} tabs={[{ v: 'mine', label: L('تدريبي', 'Mine') },
      ...(data.is_manager || data.stations.length || data.evaluations.length ? [{ v: 'team' as const, label: L('فريقي', 'My team'), count: teamCount || undefined }] : []),
      ...(data.trainer_sessions.length ? [{ v: 'trainer' as const, label: L('دوراتي كمدرب', 'As trainer'), count: data.trainer_sessions.length }] : [])]} /></div>}

    {tab === 'mine' && <div className="space-y-4">
      {data.to_sign.length > 0 && <Panel title={L('تعهدات بانتظار توقيعك', 'Commitments awaiting your signature')}>
        <ul className="divide-y divide-line">{data.to_sign.map(c => <li key={c.id} className="py-2 flex flex-wrap items-center justify-between gap-2"><div><div className="font-medium">{c.title}</div><div className="text-[12px] text-ink-3">{money(c.cost, c.currency, lang)} · {L(`${c.months} شهراً`, `${c.months} months`)}</div></div>
          <Btn kind="primary" size="sm" icon={<FileSignature size={14} />} onClick={() => setSign(c)}>{L('قراءة وتوقيع', 'Read and sign')}</Btn></li>)}</ul></Panel>}
      {toApply.length > 0 && <Panel title={L('خطة التطبيق بعد الدورة', 'Application plan after the course')} sub={L('اكتب حتى ثلاثة أشياء ستطبقها في عملك ومتى؛ تصبح مهاماً يتابعها مديرك.', 'Write up to three things you will apply and when; they become tasks your manager follows.')}>
        <ul className="divide-y divide-line">{toApply.map(e => <li key={e.id} className="py-2 flex items-center justify-between gap-2"><span>{e.title}</span><Btn size="sm" onClick={() => setApply({ id: e.id, title: e.title })}>{L('كتابة الخطة', 'Write the plan')}</Btn></li>)}</ul></Panel>}
      {data.upcoming.length > 0 && <Panel title={L('دوراتي القادمة', 'Upcoming courses')}><ul className="divide-y divide-line">{data.upcoming.map(u => <li key={u.id} className="py-2">
        <div className="flex flex-wrap items-center justify-between gap-2"><span className="font-medium">{u.session.title}</span><span className="flex gap-1.5"><StatusBadge map={E_STATUS} s={u.status} /><PathBadge p={u.session.program?.path} /></span></div>
        <div className="flex flex-wrap items-center justify-between gap-2 text-[12.5px] text-ink-3"><span>{fmtDate(u.session.starts_on, lang)} — {fmtDate(u.session.ends_on, lang)}{u.session.place ? ` · ${u.session.place}` : ''}{u.session.trainer ? ` · ${bi(u.session.trainer, lang)}` : ''}</span>
          {u.session.link && <a className="underline" href={u.session.link} target="_blank" rel="noreferrer">{L('رابط الحضور عن بعد', 'Online link')}</a>}
          {u.status !== 'confirmed' && <Btn size="sm" kind="ghost" onClick={() => setWd(u.id)}>{L('اعتذار', 'Withdraw')}</Btn>}</div></li>)}</ul></Panel>}
      {r.qualification && r.qualification.status !== 'done' && <Panel title={L('برنامج التأهيل', 'Qualification programme')}><div className="text-[13px]">{L(`أنجزت ${r.qualification.progress}% من المحطات.`, `${r.qualification.progress}% of the stations done.`)}</div></Panel>}
      <div className="flex justify-end"><PrintBtn me code="TD6" id={data.employee_id ?? 0} label={L('طباعة سجلي التدريبي', 'Print my training record')} /></div>
      <RecordView r={r} L={L} lang={lang} />
      {r.certificates.some(c => c.source !== 'external') && <Panel title={L('طباعة شهاداتي', 'Print my certificates')}><div className="flex flex-wrap gap-2">{r.certificates.filter(c => c.source !== 'external').map(c => <PrintBtn key={c.id} me code="TDC" id={c.id} label={c.title} />)}</div></Panel>}
    </div>}

    {tab === 'team' && <div className="space-y-4">
      {data.team_nominations.length > 0 && <Panel title={L('ترشيحات أعضاء فريقك', 'Your team\'s nominations')} sub={L('وافق على مشاركة الموظف أو اعتذر عنها لظروف العمل.', 'Approve or decline the participation for work reasons.')}>
        <ul className="divide-y divide-line">{data.team_nominations.map(n => <li key={n.id} className="py-2 flex flex-wrap items-center justify-between gap-2"><div><div className="font-medium">{bi(n.employee, lang)}</div><div className="text-[12px] text-ink-3">{n.session.title} · {fmtDate(n.session.starts_on, lang)}</div></div>
          <span className="flex gap-1"><Btn size="sm" kind="primary" icon={<Check size={14} />} onClick={() => act.mutate({ path: `/me/training/enrollments/${n.id}/manager`, v: { ok: true } }, { onError: fail })}>{L('موافقة', 'Approve')}</Btn>
            <Btn size="sm" icon={<X size={14} />} onClick={() => act.mutate({ path: `/me/training/enrollments/${n.id}/manager`, v: { ok: false } }, { onError: fail })}>{L('اعتذار', 'Decline')}</Btn></span></li>)}</ul></Panel>}
      {data.stations.length > 0 && <Panel title={L('محطات تأهيل تشرف عليها', 'Qualification stations you supervise')}><ul className="divide-y divide-line">{data.stations.map(s => <li key={`${s.q}-${s.i}`} className="py-2 flex flex-wrap items-center justify-between gap-2">
        <div><div className="font-medium">{bi(s.employee, lang)}</div><div className="text-[12px] text-ink-3">{s.topics} · {fmtDate(s.from, lang)} — {fmtDate(s.to, lang)}</div></div>
        <Btn size="sm" kind="primary" onClick={() => act.mutate({ path: `/me/training/qualifications/${s.q}/sign`, v: { i: s.i } }, { onSuccess: () => toast(L('وُقّعت المحطة', 'Station signed')), onError: fail })}>{L('توقيع الإتمام', 'Sign completion')}</Btn></li>)}</ul></Panel>}
      {data.evaluations.length > 0 && <Panel title={L('تقييمات تأهيل بانتظارك', 'Qualification evaluations awaiting you')}><ul className="divide-y divide-line">{data.evaluations.map(e => <li key={e.id} className="py-2 flex items-center justify-between gap-2"><span>{bi(e.employee, lang)}</span>
        <Btn size="sm" kind="primary" onClick={() => setEv(e.id)}>{L('التقييم والتوصية', 'Evaluate')}</Btn></li>)}</ul></Panel>}
      <div><div className="font-semibold mb-2">{L('قياس أثر التدريب لفريقك', 'Training impact for your team')}</div><ImpactsTab me /></div>
    </div>}

    {tab === 'trainer' && <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(280px,1fr))]">{data.trainer_sessions.map(s => <button key={s.id} onClick={() => setTrainer(s.id)} className="text-start bg-surface border border-line rounded-2xl p-4 hover:border-brand/50">
      <div className="flex items-start justify-between gap-2"><span className="font-semibold">{s.title}</span><StatusBadge map={S_STATUS} s={s.status} /></div>
      <div className="text-[12.5px] text-ink-3 mt-1">{fmtDate(s.starts_on, lang)} — {fmtDate(s.ends_on, lang)} · {L(`${s.counts.confirmed ?? 0} متدرب`, `${s.counts.confirmed ?? 0} trainees`)}</div></button>)}</div>}

    {sign && <SignCommitment c={sign} onClose={() => setSign(null)} />}
    {apply && <ApplyPlan e={apply} onClose={() => setApply(null)} />}
    {need && <RequestNeed comps={data.competencies} onClose={() => setNeed(false)} />}
    {cert && <UploadCert me onClose={() => setCert(false)} />}
    {ev && <QualEval me id={ev} onClose={() => setEv(null)} />}
    {trainer && <TrainerSession id={trainer} onClose={() => setTrainer(null)} />}
    <DecisionDialog open={wd !== null} onClose={() => setWd(null)} tone="danger" reasonRequired minLength={5} title={L('الاعتذار عن الدورة', 'Withdraw from the course')} confirmLabel={L('اعتذار', 'Withdraw')}
      onConfirm={x => act.mutateAsync({ path: `/me/training/enrollments/${wd}/withdraw`, v: { reason: x } })} />
  </div>
}

function SignCommitment({ c, onClose }: { c: MT['to_sign'][number]; onClose: () => void }) {
  const L = useL()
  const act = useTdAct()
  const [sig, setSig] = useState<string | undefined>()
  return <Modal open onClose={onClose} width={680} title={c.title}>
    {c.body && <pre className="whitespace-pre-wrap font-sans text-[13.5px] leading-7 bg-sub rounded-xl p-3 max-h-[45vh] overflow-y-auto">{c.body}</pre>}
    <div className="mt-3 text-[13px] font-semibold">{L('توقيعك', 'Your signature')}</div>
    <SignaturePad value={sig} onChange={setSig} />
    <div className="mt-3"><ConfirmSend block label={L('توقيع التعهد', 'Sign the commitment')} confirmLabel={L('تأكيد التوقيع', 'Confirm signature')} disabled={!sig} busy={act.isPending}
      summary={L('بعد التوقيع يصبح ترشيحك مؤكداً ويبدأ التعهد من تاريخ انتهاء الدورة.', 'After signing, your nomination is confirmed and the commitment starts when the course ends.')}
      onConfirm={() => act.mutate({ path: `/me/training/commitments/${c.id}/sign`, v: { signature: sig } }, { onSuccess: () => { toast(L('وُقّع التعهد', 'Commitment signed')); onClose() }, onError: fail })} /></div>
  </Modal>
}

function ApplyPlan({ e, onClose }: { e: { id: number; title: string }; onClose: () => void }) {
  const L = useL()
  const act = useTdAct()
  const [items, setItems] = useState([{ what: '', when: '' }, { what: '', when: '' }, { what: '', when: '' }])
  const [support, setSupport] = useState('')
  const ok = items.some(i => i.what.trim().length >= 3)
  return <Modal open onClose={onClose} width={640} title={L(`خطة التطبيق — ${e.title}`, `Application plan — ${e.title}`)}>
    <div className="space-y-2">{items.map((i, k) => <div key={k} className="grid gap-2 sm:grid-cols-[1fr_150px]"><input className="field" placeholder={L(`الشيء ${k + 1} الذي سأطبقه`, `Thing ${k + 1} I will apply`)} value={i.what} onChange={x => setItems(items.map((y, j) => j === k ? { ...y, what: x.target.value } : y))} />
      <input type="date" className="field" value={i.when} aria-label={L('متى', 'When')} onChange={x => setItems(items.map((y, j) => j === k ? { ...y, when: x.target.value } : y))} /></div>)}</div>
    <Field label={L('الدعم الذي أحتاجه من مديري', 'Support I need from my manager')} className="mt-3"><input className="field" value={support} onChange={x => setSupport(x.target.value)} /></Field>
    <div className="mt-3"><ConfirmSend block label={L('إرسال الخطة', 'Send the plan')} disabled={!ok} busy={act.isPending} onConfirm={() => act.mutate({ path: `/me/training/enrollments/${e.id}/apply`, v: { items: items.filter(i => i.what.trim()).map(i => ({ what: i.what, when: i.when || null })), support: support || null } }, { onSuccess: () => { toast(L('أُرسلت الخطة وأصبحت مهاماً', 'Plan sent as tasks')); onClose() }, onError: fail })} /></div>
  </Modal>
}

function RequestNeed({ comps, onClose }: { comps: MT['competencies']; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const act = useTdAct()
  const [v, setV] = useState({ title: '', path: 'training' as Path, competency_id: null as number | null, note: '', preferred_date: '' })
  return <Modal open onClose={onClose} title={L('طلب تدريب', 'Training request')}>
    <div className="grid gap-3">
      <Field label={L('ما الذي تحتاج تعلمه؟', 'What do you need to learn?')} req><input className="field" value={v.title} onChange={e => setV({ ...v, title: e.target.value })} /></Field>
      <Field label={L('المسار', 'Path')}><select className="field" value={v.path} onChange={e => setV({ ...v, path: e.target.value as Path })}>{(Object.keys(PATH) as Path[]).map(p => <option key={p} value={p}>{L(PATH[p][0], PATH[p][1])}</option>)}</select></Field>
      <Field label={L('الكفاءة', 'Competency')}><select className="field" value={v.competency_id ?? ''} onChange={e => setV({ ...v, competency_id: e.target.value ? Number(e.target.value) : null })}><option value="">—</option>{comps.map(c => <option key={c.id} value={c.id}>{bi(c, lang)}</option>)}</select></Field>
      <Field label={L('لماذا تحتاجه في عملك؟', 'Why do you need it at work?')}><textarea className="field" rows={3} value={v.note} onChange={e => setV({ ...v, note: e.target.value })} /></Field>
      <Field label={L('التوقيت المفضل', 'Preferred timing')}><input className="field" value={v.preferred_date} onChange={e => setV({ ...v, preferred_date: e.target.value })} /></Field>
      <ConfirmSend block label={L('إرسال الطلب', 'Send the request')} disabled={v.title.trim().length < 3} busy={act.isPending} summary={L('يُراجع طلبك من الموارد البشرية بتحليل السبب والأدلة قبل إدراجه في الخطة.', 'HR reviews your request with a cause and evidence analysis before adding it to the plan.')}
        onConfirm={() => act.mutate({ path: '/me/training/needs', v: { ...v, source: 'request', level: 'individual', note: v.note || null, preferred_date: v.preferred_date || null } }, { onSuccess: () => { toast(L('أُرسل الطلب', 'Request sent')); onClose() }, onError: fail })} />
    </div>
  </Modal>
}

function TrainerSession({ id, onClose }: { id: number; onClose: () => void }) {
  const L = useL()
  const { data } = useSession(id, true)
  const [tab, setTab] = useState<'att' | 'scores'>('att')
  return <Modal open onClose={onClose} width={760} title={data?.title ?? ''}>
    {!data ? <Spin /> : <><Tabs value={tab} onChange={setTab} tabs={[{ v: 'att', label: L('الحضور', 'Attendance') }, { v: 'scores', label: L('النتائج', 'Results') }]} />
      <div className="mt-3">{tab === 'att' ? <AttendanceGrid s={data} base={`/me/training/sessions/${id}`} /> : <Scores s={data} base={`/me/training/sessions/${id}`} />}</div></>}
  </Modal>
}

export const MyTrainingIcon = GraduationCap
export const SendIcon = Send
