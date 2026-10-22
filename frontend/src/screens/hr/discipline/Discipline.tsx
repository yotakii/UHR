import { useEffect, useState, type ReactNode } from 'react'
import { ArrowLeft, ArrowRight, BookOpen, FileWarning, Loader2, Plus, Printer, Search, X } from 'lucide-react'
import { useL, useLang, bi, fmtDate, fmtDateTime, todayISO } from '../../../lib/store'
import { ApiError } from '../../../lib/api'
import { navigate, setQuery, useRoute } from '../../../lib/router'
import { rank, useCase, useCaseAction, useComplaintAction, useComplaints, useDiscipline, useOpenCase, useSuggestions, useWarning,
  type CaseInput, type CaseRow, type ComplaintRow, type DisciplinePage, type Opt, type PenaltyKind, type Suggestion, type ViolationType } from '../../../lib/discipline'
import { Badge, Btn, Drawer, Empty, Field, Modal, PageHead, Panel, Tabs, toast } from '../../../ui/kit'
import { DecisionDialog } from '../../../ui/decision'
import { StepsPath } from '../../requests/parts'
import { CaseStatusBadge, ComplaintBadge, ComplaintDoc, WarningDoc } from '../../discipline/docs'

type Tab = 'open' | 'suggestions' | 'decided' | 'all' | 'complaints'

/**
 * M17 المخالفات والجزاءات (SRS §10.7): الحالات المفتوحة بمهلة القرار، الاقتراح الآلي من الحضور، سجل القرارات، والشكاوى السرية.
 * الحالة: فتح ← إبلاغ الموظف (يكتب أقواله) ← محضر التحقيق ← القرار (أو الحفظ) ← إقرار الموظف بتوقيعه.
 */
export function Discipline() {
  const route = useRoute()
  return route.parts[2] && /^\d+$/.test(route.parts[2]) && route.parts[3] === 'warning' ? <WarningPage id={Number(route.parts[2])} /> : <Cases />
}

function Cases() {
  const L = useL(); const route = useRoute()
  const tab = (route.query.tab as Tab) ?? 'open'
  const [q, setQ] = useState(route.query.q ?? '')
  useEffect(() => { const t = setTimeout(() => q !== (route.query.q ?? '') && setQuery({ ...route.query, q: q || undefined }), 300); return () => clearTimeout(t) }, [q])   // eslint-disable-line react-hooks/exhaustive-deps
  const list = useDiscipline({ tab: tab === 'decided' || tab === 'all' ? tab : 'open', q: route.query.q ?? '' })
  const d = list.data
  const [open, setOpen] = useState<Partial<CaseInput> | null>(null)
  const caseId = route.query.case ? Number(route.query.case) : null
  const set = (t: Tab) => setQuery({ tab: t === 'open' ? undefined : t, q: route.query.q })
  return <div className="max-w-[1200px] mx-auto">
    <PageHead title={L('المخالفات والجزاءات', 'Violations & penalties')}
      sub={d ? L(`${d.counts.open} حالة مفتوحة${d.counts.due_soon ? ` · ${d.counts.due_soon} تنتهي مهلتها خلال 3 أيام` : ''} · ${d.counts.month} قرار هذا الشهر`,
        `${d.counts.open} open case(s)${d.counts.due_soon ? ` · ${d.counts.due_soon} due within 3 days` : ''} · ${d.counts.month} decision(s) this month`) : undefined}
      actions={d && <div className="flex flex-wrap gap-2">
        <Btn icon={<BookOpen size={16} />} onClick={() => navigate('/hr/settings/violations')}>{L('دليل المخالفات', 'Violations catalogue')}</Btn>
        {d.can.create && <Btn kind="primary" icon={<Plus size={16} />} onClick={() => setOpen({})}>{L('فتح حالة', 'Open a case')}</Btn>}
      </div>} />
    <Tabs value={tab} onChange={set} tabs={[{ v: 'open', label: L('الحالات المفتوحة', 'Open cases'), count: d?.counts.open }, { v: 'suggestions', label: L('اقتراحات الحضور', 'From attendance') },
      { v: 'decided', label: L('القرارات', 'Decisions') }, { v: 'all', label: L('السجل الكامل', 'Full register') },
      ...(d?.can.complaints ? [{ v: 'complaints' as Tab, label: L('الشكاوى', 'Complaints'), count: d.counts.complaints ?? undefined }] : [])]} />
    <div className="mt-4">
      {tab === 'suggestions' ? <Suggestions d={d} onOpen={setOpen} /> : tab === 'complaints' ? <Complaints /> : <>
        <label className="relative block mb-3 max-w-xs"><Search size={15} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" />
          <input className="field !ps-9" placeholder={L('بحث بالرقم أو اسم الموظف', 'Search ref or employee')} value={q} onChange={e => setQ(e.target.value)} /></label>
        <Panel pad={false}>{!d ? <Empty title={list.isError ? L('تعذّر التحميل', 'Could not load') : L('جارٍ التحميل…', 'Loading…')} /> : d.data.length === 0
          ? <Empty title={tab === 'open' ? L('لا حالات مفتوحة', 'No open cases') : L('لا سجلات', 'No records')}>{L('الحالات تُفتح يدوياً أو من اقتراحات الحضور.', 'Cases are opened manually or from attendance suggestions.')}</Empty>
          : <CaseTable rows={d.data} onPick={id => setQuery({ ...route.query, case: String(id) })} />}</Panel>
      </>}
    </div>
    {d && open && <OpenCaseModal d={d} init={open} onClose={() => setOpen(null)} />}
    {d && caseId && <CaseDrawer id={caseId} d={d} onClose={() => setQuery({ ...route.query, case: undefined })} />}
  </div>
}

export function CaseTable({ rows, onPick }: { rows: CaseRow[]; onPick?: (id: number) => void }) {
  const L = useL(); const lang = useLang()
  return <table className="tbl"><thead><tr><th className="hidden sm:table-cell">{L('الرقم', 'Ref')}</th><th>{L('الموظف والمخالفة', 'Employee & violation')}</th>
    <th className="hidden md:table-cell">{L('التاريخ', 'Date')}</th><th className="hidden lg:table-cell">{L('الدرجة', 'Grade')}</th><th>{L('الحالة', 'Status')}</th></tr></thead>
    <tbody>{rows.map(c => <tr key={c.id} className={onPick ? 'cursor-pointer hover:bg-sub' : ''} onClick={() => onPick?.(c.id)}>
      <td className="hidden sm:table-cell ltr num whitespace-nowrap">{c.ref_no}</td>
      <td><div className="font-medium">{c.employee ? bi(c.employee.name, lang) : '—'} <span className="text-[12px] text-ink-3 ltr inline-block">{c.employee?.code}</span></div>
        <div className="text-[13px] text-ink-2 line-clamp-2 max-w-[460px]">{c.type ? bi(c.type.title, lang) : ''}</div>
        <div className="sm:hidden text-[12px] text-ink-3"><span className="ltr inline-block num">{c.ref_no}</span> · <span className="num">{fmtDate(c.occurred_on, lang)}</span></div>
        {c.source === 'attendance' && <span className="text-[11.5px] text-info">{L('من الحضور', 'From attendance')}</span>}</td>
      <td className="hidden md:table-cell num whitespace-nowrap text-[13px]">{fmtDate(c.occurred_on, lang)}</td>
      <td className="hidden lg:table-cell text-[13px]"><div>{L(`المرة ${c.occurrence}`, `#${c.occurrence}`)}</div><div className="text-ink-3">{c.penalty ? bi(c.penalty.label, lang) : c.suggested ? bi(c.suggested.label, lang) : ''}</div></td>
      <td><CaseStatusBadge s={c.status} daysLeft={c.days_left} />{c.status === 'decided' && <div className="text-[12px] mt-0.5">{c.penalty ? bi(c.penalty.label, lang) : ''}
        {c.capped && <span className="text-warn"> · {L(`بسقف الشهر: ${c.deduct_days}`, `capped: ${c.deduct_days}`)}</span>}{!c.acknowledged_at && <span className="text-ink-3"> · {L('لم يُقرّ', 'not acknowledged')}</span>}</div>}</td>
    </tr>)}</tbody></table>
}

function typeOptions(types: ViolationType[], groups: Opt[], lang: 'ar' | 'en') {
  return groups.map(g => <optgroup key={g.key} label={lang === 'ar' ? g.ar : g.en}>{types.filter(t => t.group === g.key).map(t => <option key={t.id} value={t.id}>{t.number}. {bi(t.title, lang)}</option>)}</optgroup>)
}

export function OpenCaseModal({ d, init, onClose, employees, onSubmit }: { d: Pick<DisciplinePage, 'types' | 'groups'>; init: Partial<CaseInput>; onClose: () => void
  employees?: DisciplinePage['employees']; onSubmit?: (v: CaseInput) => Promise<unknown> }) {
  const L = useL(); const lang = useLang()
  const save = useOpenCase()
  const [v, setV] = useState<CaseInput>({ employee_id: 0, violation_type_id: 0, occurred_on: todayISO(), description: '', source: 'manual', ...init })
  const [err, setErr] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const t = d.types.find(x => x.id === v.violation_type_id)
  const emps = employees ?? (d as DisciplinePage).employees ?? []
  const submit = async () => {
    setBusy(true); setErr(null)
    try { await (onSubmit ? onSubmit(v) : save.mutateAsync(v)); toast(L('فُتحت الحالة وأُبلغ الموظف', 'Case opened and the employee notified')); onClose() }
    catch (e) { setErr(e instanceof ApiError ? e : null); if (!(e instanceof ApiError)) toast(L('تعذّر الحفظ', 'Could not save')) }
    finally { setBusy(false) }
  }
  return <Modal open onClose={onClose} title={L('فتح حالة مخالفة', 'Open a disciplinary case')} width={640} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" disabled={busy || !v.employee_id || !v.violation_type_id || v.description.trim().length < 5} onClick={submit}>{busy ? L('جارٍ الحفظ…', 'Saving…') : L('فتح الحالة', 'Open case')}</Btn></>}>
    <div className="space-y-3">
      <Field label={L('الموظف', 'Employee')} req error={err?.field('employee_id')}><select className="field" value={v.employee_id || ''} onChange={e => setV({ ...v, employee_id: Number(e.target.value) })}>
        <option value="">—</option>{emps.map(e => <option key={e.id} value={e.id}>{bi(e, lang)} — {e.code}</option>)}</select></Field>
      <Field label={L('المخالفة من الدليل', 'Violation (catalogue)')} req error={err?.field('violation_type_id')}><select className="field" value={v.violation_type_id || ''} onChange={e => setV({ ...v, violation_type_id: Number(e.target.value) })}>
        <option value="">—</option>{typeOptions(d.types, d.groups, lang)}</select></Field>
      {t && <div className="rounded-lg bg-sub px-3 py-2 text-[12.5px]"><div className="text-ink-3 mb-1">{L('الجزاء حسب التكرار', 'Penalty by occurrence')}{t.article ? <> · {L('المادة', 'Art.')} <bdi>{t.article}</bdi></> : ''}</div>
        <div className="flex flex-wrap gap-1.5">{t.penalties.map((p, i) => <span key={i} className="rounded-md bg-surface px-2 py-0.5 font-semibold">{i + 1}. {bi(p.label, lang)}</span>)}</div></div>}
      <Field label={L('تاريخ المخالفة', 'Violation date')} req error={err?.field('occurred_on')}><input type="date" className="field" max={todayISO()} value={v.occurred_on} onChange={e => setV({ ...v, occurred_on: e.target.value })} /></Field>
      <Field label={L('الوصف والوقائع', 'Description & facts')} req error={err?.field('description')}><textarea className="field" rows={3} maxLength={2000} value={v.description} onChange={e => setV({ ...v, description: e.target.value })} /></Field>
      <p className="text-[12.5px] text-ink-3">{L('يُبلَّغ الموظف كتابةً ويكتب أقواله من بوابته. لا جزاء قبل محضر التحقيق، ولا بعد مهلة القرار من تاريخ الاكتشاف.', 'The employee is notified in writing and may reply from their portal. No penalty before the investigation record, or after the decision period from discovery.')}</p>
      {err && !Object.keys(err.errors).length && <p role="alert" className="text-[13px] text-danger">{err.message}</p>}
    </div>
  </Modal>
}

function Suggestions({ d, onOpen }: { d?: DisciplinePage; onOpen: (v: Partial<CaseInput>) => void }) {
  const L = useL(); const lang = useLang()
  const [month, setMonth] = useState(todayISO().slice(0, 7))
  const q = useSuggestions(month, true)
  const rows = q.data?.data
  return <div className="space-y-3">
    <div className="flex flex-wrap items-center gap-3"><input type="month" className="field !w-auto" value={month} max={todayISO().slice(0, 7)} onChange={e => e.target.value && setMonth(e.target.value)} aria-label={L('الشهر', 'Month')} />
      {d && <span className="text-[13px] text-ink-3">{L(`تأخر ${d.policy.suggest_late} مرات فأكثر، غياب ${d.policy.suggest_absent} يوم فأكثر، خروج مبكر ${d.policy.suggest_early} مرات فأكثر — من سياسة المخالفات`,
        `Late ${d.policy.suggest_late}+ times, absent ${d.policy.suggest_absent}+ day(s), left early ${d.policy.suggest_early}+ times — from the discipline policy`)}</span>}</div>
    <Panel pad={false}>{!rows ? <Empty title={q.isError ? L('تعذّر التحميل', 'Could not load') : L('جارٍ الحساب…', 'Calculating…')} /> : rows.length === 0 ? <Empty title={L('لا اقتراحات لهذا الشهر', 'No suggestions for this month')} /> :
      <table className="tbl"><thead><tr><th>{L('الموظف', 'Employee')}</th><th className="hidden md:table-cell">{L('المخالفة المقترحة', 'Suggested violation')}</th><th className="hidden sm:table-cell">{L('الجزاء المقترح', 'Suggested penalty')}</th><th /></tr></thead>
        <tbody>{rows.map((s: Suggestion, i) => <tr key={i}>
          <td><div className="font-medium">{bi(s.employee.name, lang)} <span className="text-[12px] text-ink-3 ltr inline-block">{s.employee.code}</span></div><div className="text-[13px] text-ink-2">{bi(s.summary, lang)}</div>
            <div className="text-[12px] text-ink-3 num">{s.dates.slice(0, 6).map(x => x.slice(5)).join(' · ')}{s.dates.length > 6 ? ' …' : ''}</div>
            <div className="md:hidden text-[12.5px] text-ink-2">{bi(s.type.title, lang)}</div></td>
          <td className="hidden md:table-cell text-[13px] max-w-[380px]">{bi(s.type.title, lang)}</td>
          <td className="hidden sm:table-cell text-[13px]">{L(`المرة ${s.occurrence}`, `#${s.occurrence}`)}<div className="text-ink-3">{bi(s.suggested.label, lang)}</div></td>
          <td className="text-end">{d?.can.create && <Btn size="sm" onClick={() => onOpen({ employee_id: s.employee.id, violation_type_id: s.type.id, occurred_on: s.occurred_on, description: bi(s.summary, lang) + ' — ' + s.dates.join('، '), source: 'attendance' })}>{L('فتح حالة', 'Open case')}</Btn>}</td>
        </tr>)}</tbody></table>}</Panel>
  </div>
}

function CaseDrawer({ id, d, onClose }: { id: number; d: DisciplinePage; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const q = useCase(id); const c = q.data
  const act = useCaseAction()
  const [inv, setInv] = useState(false); const [decide, setDecide] = useState(false); const [dismiss, setDismiss] = useState(false)
  const kv = (k: string, v: ReactNode) => <div className="flex justify-between gap-3 py-1.5 border-b border-line-2 text-[13.5px]"><span className="text-ink-3 shrink-0">{k}</span><span className="text-end">{v}</span></div>
  const live = c && (c.status === 'open' || c.status === 'investigated')
  return <Drawer open onClose={onClose} width={560}>
    <header className="flex items-center justify-between gap-3 px-5 h-14 border-b border-line-2"><h3 className="font-display font-bold text-[15px] truncate"><span className="ltr inline-block">{c?.ref_no ?? '…'}</span></h3>
      <button className="p-1.5 rounded-lg hover:bg-sub" onClick={onClose} aria-label={L('إغلاق', 'Close')}><X size={18} /></button></header>
    <div className="flex-1 overflow-y-auto p-5 space-y-5">{!c ? <div className="grid place-items-center py-16"><Loader2 className="animate-spin text-brand" /></div> : <>
      <div>
        {kv(L('الموظف', 'Employee'), c.employee ? <a className="text-brand hover:underline" href={`/hr/employees/${c.employee.id}`}>{bi(c.employee.name, lang)}</a> : '—')}
        {kv(L('المخالفة', 'Violation'), <span className="max-w-[320px] inline-block">{c.type ? bi(c.type.title, lang) : ''}{c.type?.article ? <span className="text-ink-3"> · <bdi>{c.type.article}</bdi></span> : null}</span>)}
        {kv(L('تاريخ المخالفة / الاكتشاف', 'Occurred / discovered'), <span className="num">{fmtDate(c.occurred_on, lang)} / {fmtDate(c.discovered_on, lang)}</span>)}
        {kv(L('مهلة القرار', 'Decision deadline'), <span className="num">{fmtDate(c.deadline, lang)}</span>)}
        {kv(L('الدرجة والجزاء المقترح', 'Grade & suggested penalty'), <>{L(`المرة ${c.occurrence}`, `occurrence ${c.occurrence}`)} — {c.suggested ? bi(c.suggested.label, lang) : ''}</>)}
        {kv(L('الحالة', 'Status'), <CaseStatusBadge s={c.status} daysLeft={c.days_left} />)}
        {kv(L('فتحها', 'Opened by'), <>{c.opened_by ? bi(c.opened_by, lang) : '—'}{c.source === 'attendance' ? <span className="text-info"> · {L('من الحضور', 'attendance')}</span> : null}</>)}
        <p className="mt-2 text-[13.5px] text-ink-2 whitespace-pre-wrap">{c.description}</p>
      </div>
      <section><h4 className="font-semibold text-[14px] mb-1.5">{L('أقوال الموظف ودفاعه', 'Employee statement')}</h4>
        {c.defense ? <div className="rounded-lg bg-sub px-3 py-2 text-[13.5px] whitespace-pre-wrap">{c.defense}<div className="text-[11.5px] text-ink-3 num mt-1">{fmtDateTime(c.defended_at ?? undefined, lang)}</div></div>
          : <p className="text-[13px] text-ink-3">{L('لم يكتب الموظف أقواله من بوابته بعد.', 'The employee has not replied from their portal yet.')}</p>}</section>
      <section><h4 className="font-semibold text-[14px] mb-1.5">{L('محضر التحقيق', 'Investigation record')}</h4>
        {c.investigation?.statement ? <div className="rounded-lg border border-line px-3 py-2 text-[13.5px] space-y-1">
          <p className="whitespace-pre-wrap">{c.investigation.statement}</p>
          {c.investigation.witnesses && <p className="text-ink-2">{L('الشهود', 'Witnesses')}: {c.investigation.witnesses}</p>}
          {c.investigation.notes && <p className="text-ink-2">{c.investigation.notes}</p>}
          <p className="text-[12px] text-ink-3">{c.investigation.union_notified ? L('أُبلغ التنظيم النقابي', 'Union notified') : L('لم يُبلَّغ التنظيم النقابي', 'Union not notified')} · {lang === 'ar' ? c.investigation.by : c.investigation.by_en} · <span className="num">{fmtDateTime(c.investigation.at ?? undefined, lang)}</span></p>
        </div> : <p className="text-[13px] text-ink-3">{L('لا جزاء قبل سماع أقوال العامل وتسجيل المحضر.', 'No penalty before hearing the employee and recording the investigation.')}</p>}
        {live && d.can.edit && <Btn size="sm" className="mt-2" onClick={() => setInv(true)}>{c.investigation ? L('تعديل المحضر', 'Edit record') : L('تسجيل محضر التحقيق', 'Record investigation')}</Btn>}</section>
      {c.approval && <section><h4 className="font-semibold text-[14px] mb-1.5">{L('موافقة المدير العام', 'General manager approval')} — {bi(c.approval.penalty.label, lang)}</h4><StepsPath steps={c.approval.steps} /></section>}
      {c.status === 'decided' && <section className="rounded-lg border border-line p-3 text-[13.5px] space-y-1">
        <div className="font-semibold">{L('القرار', 'Decision')}: {c.penalty ? bi(c.penalty.label, lang) : ''}</div>
        {c.penalty?.kind === 'deduct' && <div>{L('الحسم في كشف', 'Deducted in the sheet of')} <span className="num">{c.month}</span>: <b className="num">{c.deduct_days}</b> {L('يوم', 'day(s)')}{c.capped && <span className="text-warn"> — {L('خُفّض لسقف الحسم الشهري', 'reduced to the monthly cap')}</span>}</div>}
        {c.reduce_reason && <div className="text-ink-2">{L('سبب التخفيف', 'Reason for lighter penalty')}: {c.reduce_reason}</div>}
        <div className="text-ink-3 text-[12.5px]">{c.decided_by ? bi(c.decided_by, lang) : ''} · <span className="num">{fmtDateTime(c.decided_at ?? undefined, lang)}</span></div>
        <div>{c.acknowledged_at ? <Badge tone="brand">{L('أقرّ الموظف بالاستلام', 'Acknowledged by the employee')}</Badge> : <Badge tone="brass">{L('بانتظار إقرار الموظف', 'Awaiting acknowledgement')}</Badge>}</div>
        {c.penalty?.kind === 'warning' && d.can.print && <a className="inline-flex items-center gap-1.5 text-brand font-semibold text-[13px]" href={`/hr/discipline/${c.id}/warning`}><Printer size={14} />{L('الإنذار الكتابي F-01', 'Written warning F-01')}</a>}
      </section>}
      {(c.status === 'dismissed' || c.status === 'expired') && <p className="text-[13.5px] text-ink-2">{c.status === 'expired' ? L('انقضت مهلة القرار دون جزاء.', 'The decision period passed without a penalty.') : <>{L('سبب الحفظ', 'Reason')}: {c.close_reason}</>}</p>}
      {live && d.can.decide && <div className="flex flex-wrap gap-2 pt-1">
        <Btn kind="primary" icon={<FileWarning size={15} />} disabled={c.status !== 'investigated'} onClick={() => setDecide(true)}>{L('اتخاذ القرار', 'Decide')}</Btn>
        <Btn onClick={() => setDismiss(true)}>{L('حفظ بلا جزاء', 'Close without penalty')}</Btn></div>}
    </>}</div>
    {c && inv && <InvestigateModal c={c} onClose={() => setInv(false)} />}
    {c && decide && <DecideModal c={c} d={d} onClose={() => setDecide(false)} />}
    {c && <DecisionDialog open={dismiss} onClose={() => setDismiss(false)} title={L('حفظ الحالة بلا جزاء', 'Close the case without penalty')} subject={<span className="ltr inline-block">{c.ref_no}</span>}
      confirmLabel={L('تأكيد الحفظ', 'Confirm')} reasonRequired minLength={d.reject_min} onConfirm={reason => act.mutateAsync({ id: c.id, action: 'dismiss', body: { reason } }).then(() => toast(L('حُفظت الحالة', 'Case closed')))} />}
  </Drawer>
}

function InvestigateModal({ c, onClose }: { c: CaseRow; onClose: () => void }) {
  const L = useL()
  const act = useCaseAction()
  const [v, setV] = useState({ statement: c.investigation?.statement ?? c.defense ?? '', witnesses: c.investigation?.witnesses ?? '', notes: c.investigation?.notes ?? '', union_notified: c.investigation?.union_notified ?? false })
  const err = act.error instanceof ApiError ? act.error : null
  return <Modal open onClose={onClose} title={L('محضر التحقيق', 'Investigation record')} width={620} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" disabled={act.isPending || v.statement.trim().length < 5} onClick={() => act.mutate({ id: c.id, action: 'investigate', body: v }, { onSuccess: () => { toast(L('حُفظ المحضر', 'Record saved')); onClose() } })}>{L('حفظ المحضر', 'Save record')}</Btn></>}>
    <div className="space-y-3">
      <Field label={L('أقوال الموظف ودفاعه في التحقيق', 'Employee statement and defence')} req error={err?.field('statement')}><textarea className="field" rows={4} maxLength={4000} value={v.statement} onChange={e => setV({ ...v, statement: e.target.value })} /></Field>
      <Field label={L('الشهود', 'Witnesses')}><input className="field" maxLength={1000} value={v.witnesses} onChange={e => setV({ ...v, witnesses: e.target.value })} /></Field>
      <Field label={L('ملاحظات المحقق', 'Investigator notes')}><textarea className="field" rows={2} maxLength={2000} value={v.notes} onChange={e => setV({ ...v, notes: e.target.value })} /></Field>
      <label className="flex items-center gap-2 text-[14px]"><input type="checkbox" checked={v.union_notified} onChange={e => setV({ ...v, union_notified: e.target.checked })} />{L('أُبلغ التنظيم النقابي (يحق له حضور التحقيق)', 'The union was notified (it may attend)')}</label>
      {err && !Object.keys(err.errors).length && <p role="alert" className="text-[13px] text-danger">{err.message}</p>}
    </div>
  </Modal>
}

/** القرار بخطوتين: اختيار الجزاء (الدرجة أو أخف منها بسبب) ← تأكيد. الأشد من صلاحية HR يذهب للمدير العام */
function DecideModal({ c, d, onClose }: { c: CaseRow; d: DisciplinePage; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const act = useCaseAction()
  const sug = c.suggested!
  const [kind, setKind] = useState<PenaltyKind>(sug.kind)
  const [days, setDays] = useState<number>(sug.days ?? 0.25)
  const [reason, setReason] = useState('')
  const [step, setStep] = useState<1 | 2>(1)
  const [err, setErr] = useState('')
  const p = { kind, days: kind === 'deduct' ? days : undefined }
  const over = rank(p) > rank(sug) + 0.0001
  const lighter = rank(p) < rank(sug) - 0.0001
  const gm = ['promotion_delay', 'promotion_deny', 'dismissal'].includes(kind) || (kind === 'deduct' && days > d.policy.hr_max_days)
  const label = (k: string) => { const o = d.kinds.find(x => x.key === k); return o ? (lang === 'ar' ? o.ar : o.en) : k }
  const allowed = d.kinds.filter(k => rank({ kind: k.key, days: k.key === 'deduct' ? 0.25 : undefined }) <= rank(sug) + 0.0001)
  const next = () => {
    if (over) return setErr(L('لا يتجاوز الجزاء درجة المخالفة.', 'The penalty cannot exceed the grade.'))
    if (lighter && reason.trim().length < d.reject_min) return setErr(L(`سبب التخفيف إلزامي (${d.reject_min} أحرف على الأقل).`, `A reason is required (at least ${d.reject_min} characters).`))
    setErr(''); setStep(2)
  }
  const confirm = () => act.mutateAsync({ id: c.id, action: 'decide', body: { kind, days: kind === 'deduct' ? days : null, reason: reason.trim() || null } })
    .then(r => { toast(r.status === 'pending_gm' ? L('أُرسل للمدير العام للموافقة', 'Sent to the general manager') : L('صدر القرار وأُبلغ الموظف', 'Decision issued; the employee was notified')); onClose() })
    .catch(e => { setErr(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : L('تعذّر تنفيذ القرار', 'Could not complete the decision')); setStep(1) })
  return <Modal open onClose={() => !act.isPending && onClose()} title={L('قرار الجزاء', 'Penalty decision')} width={560}
    footer={step === 1 ? <><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" onClick={next}>{L('متابعة', 'Continue')}</Btn></>
      : <><Btn onClick={() => setStep(1)} disabled={act.isPending}>{L('رجوع', 'Back')}</Btn><Btn kind="danger" onClick={confirm} disabled={act.isPending}>{act.isPending ? L('جارٍ التنفيذ…', 'Working…') : gm ? L('إرسال للمدير العام', 'Send to the GM') : L('تأكيد القرار', 'Confirm decision')}</Btn></>}>
    <div className="space-y-3">
      <div className="rounded-lg bg-sub px-3 py-2 text-[13.5px]"><span className="ltr inline-block">{c.ref_no}</span> — {c.employee ? bi(c.employee.name, lang) : ''}<div className="text-ink-3">{L(`المرة ${c.occurrence}، الجزاء حسب الدرجة`, `Occurrence ${c.occurrence}; grade penalty`)}: <b className="text-ink">{bi(sug.label, lang)}</b></div></div>
      {step === 1 ? <>
        <Field label={L('الجزاء', 'Penalty')} req><select className="field" value={kind} onChange={e => setKind(e.target.value as PenaltyKind)}>{allowed.map(k => <option key={k.key} value={k.key}>{lang === 'ar' ? k.ar : k.en}</option>)}</select></Field>
        {kind === 'deduct' && <Field label={L('أيام الحسم', 'Days deducted')} req hint={L(`صلاحية الموارد البشرية حتى ${d.policy.hr_max_days} أيام، وسقف الشهر ${d.policy.cap_days} أيام`, `HR may decide up to ${d.policy.hr_max_days} days; monthly cap ${d.policy.cap_days} days`)}>
          <select className="field" value={days} onChange={e => setDays(Number(e.target.value))}>{[0.25, 0.5, 1, 2, 3, 4, 5].filter(x => rank({ kind: 'deduct', days: x }) <= rank(sug) + 0.0001).map(x => <option key={x} value={x}>{x}</option>)}</select></Field>}
        {lighter && <Field label={L('سبب التخفيف', 'Reason for a lighter penalty')} req><textarea className="field" rows={2} maxLength={1000} value={reason} onChange={e => setReason(e.target.value)} /></Field>}
        {gm && <p className="text-[13px] text-warn">{L('هذا الجزاء خارج صلاحية الموارد البشرية — يُرسل للمدير العام في صندوق اعتماده.', 'This penalty is beyond HR’s authority — it goes to the general manager’s inbox.')}</p>}
      </> : <p className="text-[14px]">{L('تأكيد الجزاء', 'Confirm the penalty')}: <b>{label(kind)}{kind === 'deduct' ? ` — ${days} ${L('يوم', 'day(s)')}` : ''}</b>{reason ? <span className="block text-ink-2 text-[13px] mt-1">«{reason}»</span> : null}
        <span className="block text-[12.5px] text-ink-3 mt-2">{L('يُبلَّغ الموظف ليقرّ بالاستلام بتوقيعه، ويظهر الحسم في الكشف الشهري.', 'The employee is notified to sign the acknowledgement; deductions appear in the monthly sheet.')}</span></p>}
      {err && <p role="alert" className="text-[13px] text-danger">{err}</p>}
    </div>
  </Modal>
}

function Complaints() {
  const L = useL(); const lang = useLang()
  const [st, setSt] = useState<'open' | 'closed' | 'all'>('open')
  const q = useComplaints(st, true)
  const act = useComplaintAction()
  const [pick, setPick] = useState<ComplaintRow | null>(null)
  const [reply, setReply] = useState('')
  const [print, setPrint] = useState<ComplaintRow | null>(null)
  if (print) return <div className="max-w-[900px] mx-auto space-y-3"><button className="inline-flex items-center gap-1.5 text-[13.5px] text-ink-2" onClick={() => setPrint(null)} data-noprint>{lang === 'ar' ? <ArrowRight size={15} /> : <ArrowLeft size={15} />}{L('رجوع', 'Back')}</button>
    <ComplaintDoc c={print} who={{ name: print.employee!.name, title: print.employee!.title, dept: print.employee!.dept }} /></div>
  return <div className="space-y-3">
    <p className="text-[13px] text-ink-3">{L('الشكاوى سرية: تصل للموارد البشرية فقط ولا يراها المدير المباشر.', 'Complaints are confidential: HR only, never the direct manager.')}</p>
    <select className="field !w-auto" value={st} onChange={e => setSt(e.target.value as typeof st)} aria-label={L('الحالة', 'Status')}>
      <option value="open">{L('المفتوحة', 'Open')}</option><option value="closed">{L('المغلقة', 'Closed')}</option><option value="all">{L('الكل', 'All')}</option></select>
    <Panel pad={false}>{!q.data ? <Empty title={L('جارٍ التحميل…', 'Loading…')} /> : q.data.length === 0 ? <Empty title={L('لا شكاوى', 'No complaints')} /> :
      <ul className="divide-y divide-line-2">{q.data.map(c => <li key={c.id} className="p-4 space-y-1.5">
        <div className="flex flex-wrap items-center gap-2"><b className="text-[14.5px]">{c.subject}</b><ComplaintBadge s={c.status} /><span className="text-[12px] text-ink-3 ltr inline-block">{c.ref_no}</span></div>
        <div className="text-[13px] text-ink-2">{c.employee ? bi(c.employee.name, lang) : ''} · <span className="num">{fmtDateTime(c.created_at, lang)}</span>{c.against ? <> · {L('بحق', 'against')}: {c.against}</> : null}</div>
        <p className="text-[13.5px] whitespace-pre-wrap">{c.body}</p>
        {c.response && <div className="rounded-lg bg-sub px-3 py-2 text-[13px]"><b>{L('الرد', 'Reply')}:</b> {c.response}</div>}
        <div className="flex flex-wrap gap-2 pt-1">
          {c.status === 'received' && <Btn size="sm" onClick={() => act.mutate({ id: c.id, action: 'review' })}>{L('قيد الدراسة', 'Mark under review')}</Btn>}
          {c.status !== 'closed' && <Btn size="sm" kind="primary" onClick={() => { setPick(c); setReply('') }}>{L('الرد والإغلاق', 'Reply & close')}</Btn>}
          <Btn size="sm" kind="ghost" icon={<Printer size={14} />} onClick={() => setPrint(c)}>F-02</Btn>
        </div></li>)}</ul>}</Panel>
    {pick && <Modal open onClose={() => setPick(null)} title={L('الرد على الشكوى', 'Reply to the complaint')} footer={<><Btn onClick={() => setPick(null)}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" disabled={reply.trim().length < 10 || act.isPending} onClick={() => act.mutate({ id: pick.id, action: 'close', response: reply }, { onSuccess: () => { toast(L('أُغلقت الشكوى وأُبلغ الموظف', 'Closed; the employee was notified')); setPick(null) } })}>{L('إرسال الرد وإغلاق', 'Send reply & close')}</Btn></>}>
      <p className="text-[14px] mb-2"><b>{pick.subject}</b></p>
      <Field label={L('الرد المكتوب', 'Written reply')} req hint={L('10 أحرف على الأقل', 'At least 10 characters')}><textarea className="field" rows={4} maxLength={4000} value={reply} onChange={e => setReply(e.target.value)} /></Field>
    </Modal>}
  </div>
}

function WarningPage({ id }: { id: number }) {
  const L = useL(); const lang = useLang()
  const q = useWarning(id, true)
  const Back = lang === 'ar' ? ArrowRight : ArrowLeft
  return <div className="max-w-[900px] mx-auto space-y-3">
    <a href={`/hr/discipline?tab=decided&case=${id}`} className="inline-flex items-center gap-1.5 text-[13.5px] text-ink-2 hover:text-ink" data-noprint><Back size={15} />{L('رجوع', 'Back')}</a>
    {q.data ? <WarningDoc c={q.data} company={q.data.company} /> : <Panel><Empty title={q.isError ? L('غير متاح', 'Not available') : L('جارٍ التحميل…', 'Loading…')} /></Panel>}
  </div>
}
