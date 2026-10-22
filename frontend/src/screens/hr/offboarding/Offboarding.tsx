import { useEffect, useState } from 'react'
import { ArrowLeft, ArrowRight, BarChart3, Loader2, Search, Settings2, UserX } from 'lucide-react'
import { useL, useLang, bi, fmtDate, fmtDateTime, todayISO, addDays } from '../../../lib/store'
import { ApiError } from '../../../lib/api'
import { navigate, setQuery, useRoute } from '../../../lib/router'
import { useExit, useExitAction, useExitReport, useOffboarding, useTerminate, type ExitFull, type OffboardingPage } from '../../../lib/exit'
import { useMe } from '../../../lib/session'
import { Avatar, Badge, Btn, Empty, Field, KV, Modal, PageHead, Panel, Tabs, toast } from '../../../ui/kit'
import { DecisionDialog } from '../../../ui/decision'
import { StepsPath } from '../../requests/parts'
import { ClearancePanel, ExitBadge, ExitDoc, HandoverPanel, PrintLinks, SettlementTable, signedBy, type PrintForm } from '../../exit/parts'

/**
 * M11 الاستقالات وإنهاء الخدمة (Offboarding في الديمو، بإجراء الشركة HR.01-03): القائمة 320px + التفاصيل.
 * القرار في «اعتماداتي» (المدير ← HR ← المدير العام)؛ هنا مقابلة HR، الإعفاء، الرجوع، F-05، F-07 ← F-04، مقابلة الخروج، البديل، الإشعارات، المخالصة والإغلاق.
 */
export function Offboarding() {
  const route = useRoute()
  if (route.parts[2] && /^\d+$/.test(route.parts[2]) && route.parts[3] === 'print') return <PrintPage id={Number(route.parts[2])} form={route.parts[4] as PrintForm} />
  return <ExitList />
}

function ExitList() {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const st = route.query.status ?? 'open'
  const [q, setQ] = useState(route.query.q ?? '')
  useEffect(() => { const t = setTimeout(() => q !== (route.query.q ?? '') && setQuery({ ...route.query, q: q || undefined }), 300); return () => clearTimeout(t) }, [q])   // eslint-disable-line react-hooks/exhaustive-deps
  const list = useOffboarding(st, route.query.q ?? ''); const d = list.data
  const sel = route.query.id ? Number(route.query.id) : d?.data[0]?.id ?? null
  const [term, setTerm] = useState(false); const [report, setReport] = useState(false)
  return <div className="max-w-[1280px] mx-auto">
    <PageHead title={L('الاستقالات وإنهاء الخدمة', 'Resignations & exits')} sub={d ? L(`${d.counts.pending} بانتظار الاعتماد · ${d.counts.clearance} في إجراءات الخروج${d.counts.followups ? ` · ${d.counts.followups} متابعة بعد الخروج` : ''}`,
      `${d.counts.pending} awaiting approval · ${d.counts.clearance} in exit procedures${d.counts.followups ? ` · ${d.counts.followups} follow-ups` : ''}`) : L('من طلب الاستقالة حتى إبراء الذمة والمخالصة النهائية.', 'From resignation request to clearance and final settlement.')}
      actions={d && <div className="flex flex-wrap gap-2">
        {d.can.report && <Btn icon={<BarChart3 size={16} />} onClick={() => setReport(true)}>{L('تحليل الأسباب', 'Reasons report')}</Btn>}
        {d.can.settings && <Btn icon={<Settings2 size={16} />} onClick={() => navigate('/hr/settings/exit')}>{L('إعدادات الخروج', 'Exit settings')}</Btn>}
        {d.can.terminate && <Btn kind="danger" icon={<UserX size={16} />} onClick={() => setTerm(true)}>{L('إنهاء عقد', 'Terminate contract')}</Btn>}
      </div>} />
    <div className="grid lg:grid-cols-[320px_1fr] gap-5 items-start">
      <div className="space-y-3">
        <Tabs value={st} onChange={v => setQuery({ status: v === 'open' ? undefined : v })} tabs={[{ v: 'open', label: L('المفتوحة', 'Open') }, { v: 'closed', label: L('المنتهية', 'Closed') }, { v: 'all', label: L('الكل', 'All') }]} />
        <label className="relative block"><Search size={15} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" /><input className="field !ps-9" placeholder={L('بحث بالاسم أو الرقم', 'Search name or ref')} value={q} onChange={e => setQ(e.target.value)} /></label>
        <Panel pad={false}>{!d ? <Empty title={list.isError ? L('تعذّر التحميل', 'Could not load') : L('جارٍ التحميل…', 'Loading…')} /> : d.data.length === 0 ? <Empty title={L('لا توجد طلبات', 'No requests')} /> :
          <ul className="divide-y divide-line-2">{d.data.map(x => <li key={x.id}><button onClick={() => setQuery({ ...route.query, id: String(x.id) })} className={`w-full text-start px-4 py-3 flex gap-3 items-center ${sel === x.id ? 'bg-brand-soft/60' : 'hover:bg-sub'}`}>
            <Avatar name={x.employee?.name.ar ?? ''} size={32} /><div className="flex-1 min-w-0"><div className="font-medium truncate">{x.employee ? bi(x.employee.name, lang) : '—'}</div>
              <div className="text-[12px] text-ink-3">{x.kind === 'termination' ? L('إنهاء عقد', 'Termination') : L('استقالة', 'Resignation')} · {fmtDate(x.created_at, lang)}{x.overdue ? <span className="text-danger"> · {L('تأخر القرار', 'overdue')}</span> : null}</div></div>
            <ExitBadge s={x.status} kind={x.kind} /></button></li>)}</ul>}</Panel>
      </div>
      {d && sel ? <Detail key={sel} id={sel} d={d} /> : <Panel><Empty title={L('اختر طلباً لعرض تفاصيله', 'Select a request')} /></Panel>}
    </div>
    {d && term && <TerminateModal d={d} onClose={() => setTerm(false)} />}
    {report && <ReportModal onClose={() => setReport(false)} />}
  </div>
}

function Detail({ id, d }: { id: number; d: OffboardingPage }) {
  const L = useL(); const lang = useLang()
  const q = useExit(id); const r = q.data
  const act = useExitAction()
  const me = useMe().data
  const [dlg, setDlg] = useState<'interview' | 'waive' | 'wd_accept' | 'wd_reject' | 'close' | null>(null)
  if (!r) return <Panel>{q.isError ? <Empty title={L('غير متاح', 'Not available')} /> : <div className="grid place-items-center py-16"><Loader2 className="animate-spin text-brand" /></div>}</Panel>
  const run = (path: string, body?: Record<string, unknown>, method?: 'POST' | 'PUT') => act.mutateAsync({ id: r.id, path, body, method })
  const fail = (e: unknown) => toast(e instanceof ApiError ? e.message : L('تعذّر التنفيذ', 'Could not complete'))
  const e = r.employee!
  const qs = d.lists.questions
  return <div className="space-y-4 min-w-0">
    <Panel title={<span className="flex flex-wrap items-center gap-2">{bi(e.name, lang)} <ExitBadge s={r.status} kind={r.kind} /></span>} sub={`${e.code} — ${bi(e.title, lang)}${r.ref_no ? ` · ${r.ref_no}` : ''}`}
      action={<Btn size="sm" onClick={() => navigate(`/hr/employees/${e.id}`)}>{L('الملف الوظيفي', 'Employee file')}</Btn>}>
      <div className="grid md:grid-cols-[1fr_260px] gap-5">
        <div className="min-w-0">
          <div className="text-[12px] text-ink-3 mb-1">{r.kind === 'termination' ? L('ملاحظات إنهاء العقد — HR.01-03-F-03', 'Termination notes — HR.01-03-F-03') : L('نص طلب الاستقالة — HR.01-03-F-02', 'Resignation letter — HR.01-03-F-02')}</div>
          <p className="text-[15px] leading-7 bg-canvas rounded-md p-3 whitespace-pre-wrap">{r.text}</p>
          <div className="text-[13px] text-ink-2 mt-2">{r.kind === 'termination' ? L('النوع والأسباب', 'Type & reasons') : L('الأسباب', 'Reasons')}: {r.reasons.map(x => bi(x, lang)).join('، ')}{r.termination?.other ? ` — ${r.termination.other}` : ''}</div>
          {r.rehire !== null && <div className="text-[13px] text-ink-2">{L('أهلية إعادة التعيين', 'Rehire eligibility')}: {r.rehire ? L('نعم', 'Yes') : L('لا', 'No')}{r.rehire_reason ? ` — ${r.rehire_reason}` : ''}</div>}
          {r.notice_waived ? <div className="text-[13px] text-brand mt-1">{L('أُعفي من فترة الإخطار', 'Notice waived')}{r.waive_note ? `: ${r.waive_note}` : ''}</div>
            : r.notice_short && <div className="text-[13px] text-warn mt-1">{L(`آخر يوم قبل نهاية الإخطار (${fmtDate(r.notice_until ?? undefined, lang)}) — بدل إشعار ${d.policy.notice_pay_months} شهر أجر ما لم يُعفَ`, `Last day before the notice ends (${fmtDate(r.notice_until ?? undefined, lang)}) — ${d.policy.notice_pay_months} months’ pay unless waived`)}</div>}
          {r.withdrawal && <div className="mt-2 rounded-lg border border-line px-3 py-2 text-[13px]"><b>{L('طلب الرجوع عن الاستقالة', 'Withdrawal request')}</b> · {r.withdrawal.status === 'pending' ? <Badge tone="brass">{L('قيد الدراسة', 'Pending')}</Badge> : r.withdrawal.status === 'accepted' ? <Badge tone="brand">{L('مقبول', 'Accepted')}</Badge> : <Badge>{L('مرفوض', 'Declined')}</Badge>}
            <p className="mt-1 whitespace-pre-wrap">{r.withdrawal.text}</p>{r.withdrawal.note && <p className="text-ink-3">{r.withdrawal.note}</p>}</div>}
        </div>
        <div><KV k={L('تاريخ التقديم', 'Submitted')} v={fmtDate(r.created_at, lang)} /><KV k={L('آخر يوم عمل', 'Last day')} v={fmtDate(r.last_day, lang)} />
          {r.notice_until && <KV k={L('نهاية الإخطار', 'Notice ends')} v={fmtDate(r.notice_until, lang)} />}
          {r.requested_by && r.kind === 'termination' && <KV k={L('رفعه', 'Raised by')} v={bi(r.requested_by, lang)} />}
          <div className="mt-2"><StepsPath steps={r.steps} /></div></div>
      </div>
      {(r.can.interview || r.can.waive || r.can.withdrawal || r.can_act) && <div className="flex flex-wrap gap-2 mt-4 pt-4 border-t border-line-2">
        {r.can.interview && <Btn kind={r.hr_interview ? 'secondary' : 'primary'} onClick={() => setDlg('interview')}>{r.hr_interview ? L('تعديل مقابلة الاستقالة', 'Edit interview') : L('مقابلة الاستقالة وتأكيد الأسباب', 'Resignation interview')}</Btn>}
        {r.can_act && <Btn onClick={() => navigate('/hr/approvals')}>{L('القرار من «اعتماداتي»', 'Decide in «My approvals»')}</Btn>}
        {r.can.waive && !r.notice_waived && <Btn onClick={() => setDlg('waive')}>{L('إعفاء من فترة الإخطار', 'Waive notice')}</Btn>}
        {r.can.withdrawal && <><Btn kind="primary" onClick={() => setDlg('wd_accept')}>{L('قبول الرجوع', 'Accept withdrawal')}</Btn><Btn kind="ghost" onClick={() => setDlg('wd_reject')}>{L('رفض الرجوع', 'Decline withdrawal')}</Btn></>}
      </div>}
      <div className="mt-3"><PrintLinks r={r} href={f => `/hr/offboarding/${r.id}/print/${f}`} /></div>
    </Panel>

    {r.hr_interview && <Panel title={L('مقابلة الاستقالة (HR)', 'Resignation interview (HR)')} sub={signedBy({ by: r.hr_interview.by, by_en: r.hr_interview.by_en, at: r.hr_interview.at }, lang) ?? ''}>
      <div className="text-[13.5px] space-y-1"><div>{L('الأسباب المؤكدة', 'Confirmed reasons')}: {r.hr_interview.reasons.map(k => { const x = d.lists.reasons.find(i => i.key === k); return x ? (lang === 'ar' ? x.ar : x.en) : k }).join('، ')}</div>
        <div>{L('التوصية', 'Recommendation')}: <b>{r.hr_interview.recommendation === 'accept' ? L('قبول الاستقالة', 'Accept') : L('محاولة الاحتفاظ بالموظف', 'Try to retain')}</b></div><p className="whitespace-pre-wrap text-ink-2">{r.hr_interview.notes}</p></div></Panel>}
    {r.survey && Object.keys(r.survey).length > 0 && <Panel title={L('استبيان الاستقالة', 'Resignation survey')}><dl className="space-y-2 text-[13.5px]">{qs.filter(x => r.survey![x.key]).map(x => <div key={x.key}><dt className="text-ink-3">{lang === 'ar' ? x.ar : x.en}</dt><dd>{r.survey![x.key]}</dd></div>)}</dl></Panel>}

    {(r.status === 'clearance' || r.status === 'closed') && <>
      <HandoverPanel r={r} canEdit={r.can.handover} colleagues={d.employees.filter(x => x.id !== e.id)} onSave={v => run('handover', v as unknown as Record<string, unknown>, 'PUT')} sign={as => run('handover/sign', { as })}
        signAs={['hr', ...(me?.employee && r.manager ? ['manager'] : [])]} />
      <ClearancePanel r={r} mark={(s, i, b) => run(`clearance/${s}/${i}`, b)} sign={s => run(`clearance/${s}/sign`)} custodyHref={a => `/hr/custody?asset=${a}`} />
      <div className="grid xl:grid-cols-2 gap-4 items-start">
        {r.can.exit && <ExitInterview r={r} d={d} save={b => run('exit', b)} />}
        <AccountPanel r={r} d={d} run={run} />
      </div>
      {r.notices.length > 0 && <Panel title={L('إشعار الجهات الخارجية', 'External notices')} sub="D-17" pad={false}><ul className="divide-y divide-line-2">{r.notices.map(n => <li key={n.id} className="px-4 py-3 flex flex-wrap items-center gap-2 text-[13.5px]">
        <span className="flex-1 min-w-0">{n.to.name} <span className="ltr inline-block text-ink-3">{n.to.email}</span></span>
        {n.status === 'blocked' ? <Badge>{L('بلا موافقة — لا يُرسل', 'No consent — not sent')}</Badge> : n.status === 'sent' ? <Badge tone="brand">{L('أُرسل', 'Sent')} {fmtDateTime(n.sent_at ?? undefined, lang)}</Badge>
          : <Btn size="sm" kind="primary" onClick={() => run(`notices/${n.id}/send`).then(() => toast(L('أُرسل الإشعار', 'Notice sent'))).catch(fail)}>{L('اعتماد وإرسال', 'Approve & send')}</Btn>}</li>)}</ul></Panel>}
      {r.settlement && <Panel title={L('المخالصة النهائية', 'Final settlement')} sub={L('F-33 — تقديري وفق بيانات النظام، يُراجع من الإدارة المالية', 'F-33 — estimate from system data, reviewed by Finance')}
        action={r.can.close && <Btn kind="danger" onClick={() => setDlg('close')}>{L('اعتماد إنهاء الخدمة', 'Close employment')}</Btn>}>
        <SettlementTable s={r.settlement} />
        {r.status === 'closed' && <div className="mt-4 flex flex-wrap items-center gap-3"><span className="stamp text-danger">{L('انتهت الخدمة', 'SERVICE ENDED')} — {fmtDate(r.last_day, lang)}</span></div>}
      </Panel>}
      {r.status === 'closed' && r.followups && <Panel title={L('ما بعد الخروج', 'After exit')}>
        {([['labor', L('توثيق الاستقالة لدى مديرية العمل', 'Registered with the labour office')], ['insurance', L(`الانفكاك من التأمينات الاجتماعية (حتى ${r.followups.insurance?.due ?? ''})`, `Social insurance disengagement (by ${r.followups.insurance?.due ?? ''})`)]] as const).map(([k, t]) =>
          <label key={k} className="flex items-center gap-2 py-1.5 text-[14px]"><input type="checkbox" checked={!!r.followups?.[k]?.done} onChange={x => run(`followups/${k}`, { done: x.target.checked }).catch(fail)} />{t}</label>)}
        <div className="text-[13px] text-ink-3">{L('أُعلم كل الموظفين بانتهاء الخدمة.', 'All staff were informed.')}</div>
        {r.can.certificate && <div className="mt-2">{r.service_cert_no ? <a className="text-brand text-[13px] font-semibold" href={`/hr/offboarding/${r.id}/print/f06`}>{L('شهادة الخدمة', 'Service certificate')} {r.service_cert_no}</a>
          : <Btn size="sm" onClick={() => run('certificate').then(() => toast(L('أُصدرت شهادة الخدمة', 'Service certificate issued'))).catch(fail)}>{L('إصدار شهادة الخدمة F-06', 'Issue service certificate F-06')}</Btn>}</div>}
      </Panel>}
    </>}

    {dlg === 'interview' && <InterviewModal r={r} d={d} onClose={() => setDlg(null)} save={b => run('interview', b)} />}
    {dlg === 'waive' && <WaiveModal r={r} onClose={() => setDlg(null)} save={b => run('waive', b)} />}
    <DecisionDialog open={dlg === 'wd_accept' || dlg === 'wd_reject'} onClose={() => setDlg(null)} tone={dlg === 'wd_reject' ? 'danger' : 'primary'} title={dlg === 'wd_accept' ? L('قبول الرجوع عن الاستقالة', 'Accept the withdrawal') : L('رفض الرجوع', 'Decline the withdrawal')}
      confirmLabel={L('تأكيد', 'Confirm')} reasonRequired minLength={d.reject_min} reasonLabel={L('ملاحظة القرار وأي تغيير في العقد', 'Decision note and any contract change')}
      onConfirm={note => run('withdrawal', { accept: dlg === 'wd_accept', note }).then(() => toast(L('تم', 'Done')))} />
    <DecisionDialog open={dlg === 'close'} onClose={() => setDlg(null)} tone="danger" title={L('اعتماد إنهاء الخدمة', 'Close employment')}
      subject={<>{bi(e.name, lang)} — {L('آخر يوم', 'last day')} {fmtDate(r.last_day, lang)}<div className="text-[12.5px] text-ink-3 mt-1">{L('يصبح «منتهي الخدمة»، ويُعطّل حسابه وتُلغى طلباته المعلّقة، ويُعلم كل الموظفين.', 'The employee becomes terminated, the account is disabled, pending requests are cancelled and staff are informed.')}</div></>}
      confirmLabel={L('إنهاء الخدمة', 'Close employment')} onConfirm={() => run('close').then(() => toast(L('أُنهيت الخدمة', 'Service ended')))} />
  </div>
}

function InterviewModal({ r, d, onClose, save }: { r: ExitFull; d: OffboardingPage; onClose: () => void; save: (b: Record<string, unknown>) => Promise<unknown> }) {
  const L = useL(); const lang = useLang()
  // افتراضياً: أسباب الموظف كما قدّمها
  const [v, setV] = useState({ reasons: r.hr_interview?.reasons ?? d.lists.reasons.filter(x => r.reasons.some(y => y.ar === x.ar)).map(x => x.key), notes: r.hr_interview?.notes ?? '', recommendation: r.hr_interview?.recommendation ?? 'accept' })
  const [err, setErr] = useState('')
  const tog = (k: string) => setV({ ...v, reasons: v.reasons.includes(k) ? v.reasons.filter(x => x !== k) : [...v.reasons, k] })
  return <Modal open onClose={onClose} title={L('مقابلة الاستقالة', 'Resignation interview')} width={600} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" disabled={!v.reasons.length || v.notes.trim().length < 5} onClick={() => save(v).then(() => { toast(L('حُفظت المقابلة — اعتمد من «اعتماداتي»', 'Saved — decide in «My approvals»')); onClose() })
      .catch(e => setErr(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : L('تعذّر الحفظ', 'Could not save')))}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="space-y-3">
      <Field div label={L('الأسباب بعد المقابلة', 'Reasons confirmed')} req><div className="flex flex-wrap gap-2">{d.lists.reasons.map(x => <button key={x.key} onClick={() => tog(x.key)}
        className={`h-8 px-3 rounded-lg border text-[13px] ${v.reasons.includes(x.key) ? 'bg-brand text-on-brand border-brand' : 'border-line'}`}>{lang === 'ar' ? x.ar : x.en}</button>)}</div></Field>
      <Field div label={L('التوصية', 'Recommendation')}><div className="flex flex-wrap gap-2">{([['accept', L('قبول الاستقالة', 'Accept')], ['retain', L('محاولة الاحتفاظ', 'Try to retain')]] as const).map(([k, t]) =>
        <button key={k} onClick={() => setV({ ...v, recommendation: k })} className={`h-8 px-3 rounded-lg border text-[13px] ${v.recommendation === k ? 'bg-brand text-on-brand border-brand' : 'border-line'}`}>{t}</button>)}</div></Field>
      <Field label={L('ملاحظات المقابلة', 'Interview notes')} req><textarea className="field" rows={4} maxLength={4000} value={v.notes} onChange={e => setV({ ...v, notes: e.target.value })} /></Field>
      {err && <p role="alert" className="text-[13px] text-danger">{err}</p>}
    </div>
  </Modal>
}

function WaiveModal({ r, onClose, save }: { r: ExitFull; onClose: () => void; save: (b: Record<string, unknown>) => Promise<unknown> }) {
  const L = useL()
  const [note, setNote] = useState(''); const [day, setDay] = useState(r.last_day)
  return <Modal open onClose={onClose} title={L('الإعفاء من فترة الإخطار', 'Waive the notice period')} width={480} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" disabled={note.trim().length < 5} onClick={() => save({ note, last_day: day }).then(() => { toast(L('سُجّل الإعفاء', 'Notice waived')); onClose() }).catch(e => toast(e instanceof ApiError ? e.message : L('تعذّر', 'Failed')))}>{L('تأكيد', 'Confirm')}</Btn></>}>
    <div className="space-y-3">
      <p className="text-[13px] text-ink-3">{L('لا يُحسب بدل إشعار في المخالصة بعد الإعفاء، ويمكن تقديم آخر يوم عمل.', 'No notice pay is deducted after a waiver; the last day can be brought forward.')}</p>
      <Field label={L('آخر يوم عمل', 'Last working day')}><input type="date" className="field" min={todayISO()} value={day} onChange={e => setDay(e.target.value)} /></Field>
      <Field label={L('سبب الإعفاء', 'Reason')} req><textarea className="field" rows={2} maxLength={1000} value={note} onChange={e => setNote(e.target.value)} /></Field>
    </div>
  </Modal>
}

function ExitInterview({ r, d, save }: { r: ExitFull; d: OffboardingPage; save: (b: Record<string, unknown>) => Promise<unknown> }) {
  const L = useL(); const lang = useLang()
  const x = r.exit
  const [v, setV] = useState({ answers: x?.answers ?? { ...(r.survey ?? {}) }, rating: x?.rating ?? 4, would_return: x?.would_return ?? true, rehire: r.rehire ?? true, rehire_reason: r.rehire_reason ?? '',
    noncompete: x?.noncompete ?? false, rights: x?.rights ?? false, notes: x?.notes ?? '' })
  const [err, setErr] = useState('')
  return <Panel title={L('مقابلة الخروج', 'Exit interview')} sub={x ? signedBy({ by: x.by, by_en: x.by_en, at: x.at }, lang) ?? '' : L('في آخر يوم عمل أو الذي قبله، بدون حضور المدير', 'On the last day or the day before, without the manager')}>
    <div className="space-y-3">
      {d.lists.questions.map(q => <Field key={q.key} label={lang === 'ar' ? q.ar : q.en}><textarea className="field" rows={2} maxLength={2000} value={v.answers[q.key] ?? ''} onChange={e => setV({ ...v, answers: { ...v.answers, [q.key]: e.target.value } })} /></Field>)}
      <Field div label={L('تقييم الموظف لتجربة العمل (1–5)', 'Rating of the experience (1–5)')}><div className="flex gap-1">{[1, 2, 3, 4, 5].map(n => <button key={n} onClick={() => setV({ ...v, rating: n })} className={`size-8 rounded border num ${v.rating === n ? 'bg-ink text-canvas border-ink' : 'border-line'}`}>{n}</button>)}</div></Field>
      <label className="flex items-center gap-2 text-[14px]"><input type="checkbox" checked={v.would_return} onChange={e => setV({ ...v, would_return: e.target.checked })} />{L('يرغب بالعودة مستقبلاً', 'Would consider returning')}</label>
      <label className="flex items-center gap-2 text-[14px]"><input type="checkbox" checked={v.rehire} onChange={e => setV({ ...v, rehire: e.target.checked })} />{L('مؤهل لإعادة التعيين', 'Eligible for rehire')}</label>
      {!v.rehire && <Field label={L('سبب عدم الأهلية', 'Reason')} req><input className="field" maxLength={1000} value={v.rehire_reason} onChange={e => setV({ ...v, rehire_reason: e.target.value })} /></Field>}
      <label className="flex items-center gap-2 text-[14px]"><input type="checkbox" checked={v.noncompete} onChange={e => setV({ ...v, noncompete: e.target.checked })} />{L('رُوجعت شروط عدم المنافسة والسرية', 'Non-compete and confidentiality terms reviewed')}</label>
      <label className="flex items-center gap-2 text-[14px]"><input type="checkbox" checked={v.rights} onChange={e => setV({ ...v, rights: e.target.checked })} />{L('وُضّحت للموظف حقوقه القانونية', 'Legal rights explained')}</label>
      <Field label={L('ملاحظات', 'Notes')}><textarea className="field" rows={2} maxLength={4000} value={v.notes} onChange={e => setV({ ...v, notes: e.target.value })} /></Field>
      <Btn kind="primary" onClick={() => { setErr(''); save(v).then(() => toast(L('حُفظت المقابلة', 'Saved'))).catch(e => setErr(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : L('تعذّر الحفظ', 'Could not save'))) }}>{L('حفظ', 'Save')}</Btn>
      {err && <p role="alert" className="text-[13px] text-danger">{err}</p>}
    </div>
  </Panel>
}

/** نسخ الحساب للبديل (D-14) */
function AccountPanel({ r, d, run }: { r: ExitFull; d: OffboardingPage; run: (path: string, body?: Record<string, unknown>) => Promise<{ password?: string | null; roles?: string[] }> }) {
  const L = useL(); const lang = useLang()
  const [user, setUser] = useState(0); const [reset, setReset] = useState(false)
  const [pw, setPw] = useState<string | null>(null)
  if (!r.needs_successor && !r.successor) return null
  return <Panel title={L('نقل الحساب للبديل', 'Move the account to a successor')} sub="D-14">
    {r.successor ? <p className="text-[14px]">{L('البديل', 'Successor')}: <b>{bi(r.successor, lang)}</b> · <span className="text-ink-3 num">{fmtDateTime(r.account_moved_at ?? undefined, lang)}</span></p>
      : <p className="text-[13px] text-warn">{L('حساب الموظف يحمل أدواراً إدارية — لا يُغلق الملف قبل تحديد البديل.', 'The account holds management roles — the file cannot close before a successor is chosen.')}</p>}
    {r.can.successor && <div className="mt-3 space-y-2">
      <Field label={L('الحساب البديل', 'Successor account')}><select className="field" value={user || ''} onChange={e => setUser(Number(e.target.value))}><option value="">—</option>
        {d.users.filter(u => u.id !== r.successor?.id).map(u => <option key={u.id} value={u.id}>{bi(u, lang)} — {u.code}</option>)}</select></Field>
      <label className="flex items-center gap-2 text-[14px]"><input type="checkbox" checked={reset} onChange={e => setReset(e.target.checked)} />{L('كلمة سر مؤقتة جديدة للبديل (تُغيَّر أول دخول)', 'New temporary password (changed at first sign-in)')}</label>
      <Btn kind="primary" disabled={!user} onClick={() => run('successor', { user_id: user, reset }).then(x => { setPw(x.password ?? null); toast(L('نُقلت الصلاحيات', 'Permissions moved')) }).catch(e => toast(e instanceof ApiError ? e.message : L('تعذّر', 'Failed')))}>{L('نقل الأدوار والصلاحيات', 'Move roles & permissions')}</Btn>
      {pw && <div className="rounded-lg border border-warn/50 bg-warn/10 p-3 text-[13px]">{L('كلمة السر المؤقتة (تظهر مرة واحدة — سلّمها بقناة آمنة):', 'Temporary password (shown once — hand it over securely):')} <b className="ltr inline-block num select-all">{pw}</b></div>}
    </div>}
  </Panel>
}

function TerminateModal({ d, onClose }: { d: OffboardingPage; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const t = useTerminate()
  const [v, setV] = useState({ employee_id: 0, cause: 'dismissal', reasons: [] as string[], other: '', last_day: addDays(todayISO(), 30), rehire: true, rehire_reason: '', notes: '' })
  const err = t.error instanceof ApiError ? t.error : null
  const tog = (k: string) => setV({ ...v, reasons: v.reasons.includes(k) ? v.reasons.filter(x => x !== k) : [...v.reasons, k] })
  return <Modal open onClose={onClose} title={L('إنهاء عقد — HR.01-03-F-03', 'Contract termination — HR.01-03-F-03')} width={640} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="danger" disabled={t.isPending || !v.employee_id || v.notes.trim().length < 10} onClick={() => t.mutate(v, { onSuccess: r => { toast(L('رُفع للاعتماد', 'Sent for approval')); onClose(); setQuery({ id: String(r.id) }) } })}>{L('رفع للاعتماد', 'Send for approval')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('الموظف', 'Employee')} req error={err?.field('employee_id')} className="sm:col-span-2"><select className="field" value={v.employee_id || ''} onChange={e => setV({ ...v, employee_id: Number(e.target.value) })}><option value="">—</option>
        {d.employees.map(e => <option key={e.id} value={e.id}>{bi(e, lang)} — {e.code}</option>)}</select></Field>
      <Field label={L('نوع الإنهاء', 'Type')} req><select className="field" value={v.cause} onChange={e => setV({ ...v, cause: e.target.value })}>{d.lists.causes.map(c => <option key={c.key} value={c.key}>{lang === 'ar' ? c.ar : c.en}</option>)}</select></Field>
      <Field label={L('آخر يوم عمل', 'Last working day')} req error={err?.field('last_day')}><input type="date" className="field" min={todayISO()} value={v.last_day} onChange={e => setV({ ...v, last_day: e.target.value })} /></Field>
      {v.cause === 'dismissal' && <Field div label={L('أسباب الفصل', 'Dismissal reasons')} req error={err?.field('reasons')} className="sm:col-span-2"><div className="flex flex-wrap gap-2">{d.lists.dismissal_reasons.map(x => <button key={x.key} onClick={() => tog(x.key)}
        className={`h-8 px-3 rounded-lg border text-[13px] ${v.reasons.includes(x.key) ? 'bg-danger text-white border-danger' : 'border-line'}`}>{lang === 'ar' ? x.ar : x.en}</button>)}</div></Field>}
      {v.reasons.includes('other') && <Field label={L('ما هي الأسباب الأخرى', 'Other reasons')} req error={err?.field('other')} className="sm:col-span-2"><input className="field" maxLength={1000} value={v.other} onChange={e => setV({ ...v, other: e.target.value })} /></Field>}
      <Field div label={L('مؤهل للتعيين مرة أخرى', 'Eligible for rehire')} className="sm:col-span-2"><div className="flex gap-2">{([[true, L('نعم', 'Yes')], [false, L('لا', 'No')]] as const).map(([k, t2]) =>
        <button key={String(k)} onClick={() => setV({ ...v, rehire: k })} className={`h-8 px-4 rounded-lg border text-[13px] ${v.rehire === k ? 'bg-brand text-on-brand border-brand' : 'border-line'}`}>{t2}</button>)}</div></Field>
      {!v.rehire && <Field label={L('السبب', 'Reason')} req error={err?.field('rehire_reason')} className="sm:col-span-2"><input className="field" maxLength={1000} value={v.rehire_reason} onChange={e => setV({ ...v, rehire_reason: e.target.value })} /></Field>}
      <Field label={L('ملاحظات الموارد البشرية', 'HR notes')} req error={err?.field('notes')} className="sm:col-span-2"><textarea className="field" rows={3} maxLength={4000} value={v.notes} onChange={e => setV({ ...v, notes: e.target.value })} /></Field>
      <p className="sm:col-span-2 text-[12.5px] text-ink-3">{L('يمر على المدير المباشر ثم المدير العام. بعد الاعتماد تبدأ إجراءات الخروج نفسها (F-05، F-07، المخالصة).', 'Goes to the direct manager, then the general manager. After approval, the same exit procedures start (F-05, F-07, settlement).')}</p>
      {err && !Object.keys(err.errors).length && <p role="alert" className="sm:col-span-2 text-[13px] text-danger">{err.message}</p>}
    </div>
  </Modal>
}

function ReportModal({ onClose }: { onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const [month, setMonth] = useState(todayISO().slice(0, 7))
  const q = useExitReport(month, true); const d = q.data
  const max = Math.max(1, ...(d?.reasons.map(r => r.count) ?? [1]))
  return <Modal open onClose={onClose} title={L('تحليل أسباب الاستقالات', 'Resignation reasons')} width={640} footer={<Btn onClick={onClose}>{L('إغلاق', 'Close')}</Btn>}>
    <div className="space-y-4">
      <input type="month" className="field !w-auto" value={month} max={todayISO().slice(0, 7)} onChange={e => e.target.value && setMonth(e.target.value)} />
      {!d ? <Empty title={L('جارٍ التحميل…', 'Loading…')} /> : d.count === 0 && !d.terminations ? <Empty title={L('لا استقالات معتمدة في هذا الشهر', 'No approved resignations this month')} /> : <>
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-center">{([[L('استقالات', 'Resignations'), d.count], [L('إنهاء عقود', 'Terminations'), d.terminations], [L('متوسط التقييم', 'Avg. rating'), d.rating || '—'], [L('مؤهلون لإعادة التعيين', 'Rehire eligible'), d.rehire]] as const).map(([k, v]) =>
          <div key={k} className="rounded-lg bg-sub p-2"><div className="text-[20px] font-bold num">{v}</div><div className="text-[12px] text-ink-3">{k}</div></div>)}</div>
        <div><div className="font-semibold text-[14px] mb-2">{L('الأسباب', 'Reasons')}</div>{d.reasons.map(r => <div key={r.key} className="flex items-center gap-2 text-[13px] py-1"><span className="w-40 shrink-0 truncate">{bi(r, lang)}</span>
          <span className="flex-1 h-2 rounded-full bg-sub overflow-hidden"><span className="block h-full bg-brand" style={{ width: `${(r.count / max) * 100}%` }} /></span><span className="num w-6 text-end">{r.count}</span></div>)}</div>
        <div><div className="font-semibold text-[14px] mb-2">{L('حسب الإدارة', 'By department')}</div>{d.departments.map((x, i) => <div key={i} className="text-[13px] py-1 border-b border-line-2">{bi(x, lang)} — <b className="num">{x.count}</b>
          <span className="text-ink-3"> · {x.reasons.map(r => bi(r, lang)).join('، ')}</span></div>)}</div>
      </>}
    </div>
  </Modal>
}

function PrintPage({ id, form }: { id: number; form: PrintForm }) {
  const L = useL(); const lang = useLang()
  const q = useExit(id)
  const list = useOffboarding('all', '')
  const Back = lang === 'ar' ? ArrowRight : ArrowLeft
  const co = useMe().data?.company
  return <div className="max-w-[900px] mx-auto space-y-3">
    <a href={`/hr/offboarding?status=all&id=${id}`} className="inline-flex items-center gap-1.5 text-[13.5px] text-ink-2 hover:text-ink" data-noprint><Back size={15} />{L('رجوع', 'Back')}</a>
    {q.data && co ? <ExitDoc r={q.data} form={form} company={co.name} lists={list.data?.lists} /> : <Panel><Empty title={q.isError ? L('غير متاح', 'Not available') : L('جارٍ التحميل…', 'Loading…')} /></Panel>}
  </div>
}
