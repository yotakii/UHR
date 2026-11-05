import { useState } from 'react'
import { CalendarClock, Check, CheckCircle2, Coins, Gauge, Loader2, MessageSquare, Pencil, Plus, Search, Settings2, Target, Undo2, X } from 'lucide-react'
import { useL, useLang, bi, fmtDate, fmtDateTime } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { setQuery, useRoute } from '../../lib/router'
import {
  achTone, usePointsSettings, useKpis, useRecordKpi, useSaveKpi, useSavePoints, useSaveTask, useTask, useTaskCancel, useTaskComment, useTaskDecide, useTaskProgress, useTasks,
  type Kpi, type KpiInput, type KpisPage, type PointsCfg, type Priority, type Task, type TaskFilter, type TaskStatus, type TaskView, type TasksPage,
} from '../../lib/work'
import { Badge, Btn, Drawer, Empty, Field, Modal, PageHead, Panel, Progress, Seg, Tabs, toast } from '../../ui/kit'
import { Sparkline } from '../../ui/viz'
import { DecisionDialog } from '../../ui/decision'

type Tab = TaskView | 'kpis'

/**
 * المهام ومؤشرات الأداء (ملاحظات 61/62) — من البوابتين: مهامي، مهام الفريق، ما كلّفتُه، ومؤشرات الأداء.
 * إنجاز المهام وتحقيق المؤشرات يضيف نقاطاً إلى رصيد المكافآت حسب إعداد «النقاط».
 */
export function Work() {
  const L = useL(); const route = useRoute()
  const tab = (['mine', 'team', 'created', 'kpis'] as Tab[]).find(t => t === route.query.tab) ?? 'mine'
  const [status, setStatus] = useState<TaskFilter>('open')
  const [q, setQ] = useState('')
  const view: TaskView = tab === 'kpis' ? 'mine' : tab
  const tq = useTasks(view, status, q); const d = tq.data
  const [form, setForm] = useState<{ t: Task | null } | null>(null)
  const [open, setOpen] = useState<number | null>(null)
  const [pts, setPts] = useState(false)
  return <div className="max-w-[1200px] mx-auto space-y-4">
    <PageHead title={L('المهام ومؤشرات الأداء', 'Tasks & KPIs')} sub={L('كلّف المهام وتابع إنجازها، وقِس الأداء بمؤشرات واضحة. الإنجاز يضيف نقاطاً إلى رصيد المكافآت.', 'Assign and follow tasks, and measure performance with clear KPIs. Results add points to the rewards balance.')}
      actions={<div className="flex flex-wrap gap-2">
        {d?.can.settings && <Btn size="sm" icon={<Coins size={14} />} onClick={() => setPts(true)}>{L('إعدادات النقاط', 'Points settings')}</Btn>}
        {tab !== 'kpis' && d && (d.can.self || d.can.assign) && <Btn kind="primary" icon={<Plus size={15} />} onClick={() => setForm({ t: null })}>{L('مهمة جديدة', 'New task')}</Btn>}</div>} />
    <Tabs value={tab} onChange={v => setQuery({ tab: v === 'mine' ? undefined : v })} tabs={[
      { v: 'mine', label: L('مهامي', 'My tasks'), count: d?.counts.mine },
      ...(d?.can.team ? [{ v: 'team' as Tab, label: L('مهام الفريق', 'Team tasks'), count: d.counts.team_review || undefined }] : []),
      ...(d?.can.assign ? [{ v: 'created' as Tab, label: L('ما كلّفتُه', 'Assigned by me') }] : []),
      { v: 'kpis', label: L('مؤشرات الأداء', 'KPIs') }]} />
    {tab === 'kpis' ? <KpisView /> : <>
      <div className="flex flex-wrap items-center gap-2">
        <Seg value={status} onChange={setStatus} options={[{ v: 'open', label: L('الجارية', 'Open') }, { v: 'review', label: L('بانتظار الاعتماد', 'Awaiting approval') }, { v: 'done', label: L('المنجزة', 'Done') }, { v: 'all', label: L('الكل', 'All') }]} />
        <label className="relative flex-1 min-w-[200px] max-w-sm"><Search size={15} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" />
          <input className="field !ps-9" value={q} onChange={e => setQ(e.target.value)} placeholder={L('بحث في المهام…', 'Search tasks…')} /></label>
        {tab === 'mine' && d && d.counts.overdue > 0 && <Badge tone="danger">{L(`${d.counts.overdue} متأخرة`, `${d.counts.overdue} overdue`)}</Badge>}
      </div>
      {!d ? <div className="grid place-items-center py-16"><Loader2 className="animate-spin text-brand" /></div> : d.data.length === 0 ? <Panel><Empty title={L('لا مهام هنا', 'No tasks here')} /></Panel> :
        <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(280px,1fr))]">{d.data.map(t => <TaskCard key={t.id} t={t} team={tab !== 'mine'} onOpen={() => setOpen(t.id)} />)}</div>}
    </>}
    {form && d && <TaskForm page={d} t={form.t} onClose={() => setForm(null)} />}
    {open && d && <TaskDrawer id={open} onEdit={t => { setOpen(null); setForm({ t }) }} onClose={() => setOpen(null)} />}
    {pts && <PointsModal onClose={() => setPts(false)} />}
  </div>
}

const PRI: Record<Priority, [string, string, 'neutral' | 'info' | 'warn' | 'danger']> = { low: ['منخفضة', 'Low', 'neutral'], normal: ['عادية', 'Normal', 'info'], high: ['عالية', 'High', 'warn'], urgent: ['عاجلة', 'Urgent', 'danger'] }
const ST: Record<TaskStatus, [string, string, 'neutral' | 'info' | 'brass' | 'brand' | 'danger']> = { todo: ['جديدة', 'New', 'neutral'], in_progress: ['قيد التنفيذ', 'In progress', 'info'], review: ['بانتظار الاعتماد', 'Awaiting approval', 'brass'], done: ['منجزة', 'Done', 'brand'], cancelled: ['ملغاة', 'Cancelled', 'danger'] }

function TaskCard({ t, team, onOpen }: { t: Task; team: boolean; onOpen: () => void }) {
  const L = useL(); const lang = useLang()
  return <button onClick={onOpen} className="text-start rounded-2xl border border-line bg-surface p-4 hover:border-brand/50 transition-colors flex flex-col gap-2 min-w-0">
    <div className="flex flex-wrap items-center gap-1.5"><Badge tone={ST[t.status][2]}>{L(ST[t.status][0], ST[t.status][1])}</Badge><Badge tone={PRI[t.priority][2]}>{L(PRI[t.priority][0], PRI[t.priority][1])}</Badge>
      {t.points > 0 && <Badge tone="brand">+{t.points}</Badge>}</div>
    <div className="font-semibold leading-snug break-words">{t.title}</div>
    {team && t.employee && <div className="text-[12.5px] text-ink-2">{bi(t.employee.name, lang)}</div>}
    {!team && !t.self && t.by && <div className="text-[12.5px] text-ink-3">{L('من', 'From')} {bi(t.by, lang)}</div>}
    <div className="flex items-center gap-2"><div className="flex-1"><Progress value={t.progress} tone={t.overdue ? 'danger' : 'brand'} /></div><span className="text-[12px] num text-ink-3">{t.progress}%</span></div>
    <div className="flex flex-wrap items-center gap-3 text-[12px] text-ink-3">
      {t.due_on && <span className={`inline-flex items-center gap-1 ${t.overdue ? 'text-danger font-semibold' : ''}`}><CalendarClock size={13} /><span className="num">{fmtDate(t.due_on, lang)}</span></span>}
      {!!t.comments_count && <span className="inline-flex items-center gap-1"><MessageSquare size={13} /><span className="num">{t.comments_count}</span></span>}
      {t.kpi && <span className="inline-flex items-center gap-1 truncate"><Target size={13} />{bi(t.kpi, lang)}</span>}
    </div>
  </button>
}

function TaskForm({ page, t, onClose }: { page: TasksPage; t: Task | null; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const save = useSaveTask(t?.id ?? null)
  const [v, setV] = useState({ title: t?.title ?? '', description: t?.description ?? '', employee_id: t?.employee?.id ?? page.me ?? 0, priority: t?.priority ?? 'normal' as Priority, due_on: t?.due_on ?? '', kpi_id: t?.kpi?.id ?? null as number | null })
  const err = save.error instanceof ApiError ? save.error : null
  return <Modal open onClose={() => !save.isPending && onClose()} width={620} title={t ? L('تعديل المهمة', 'Edit task') : L('مهمة جديدة', 'New task')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={v.title.trim().length < 3 || !v.employee_id || save.isPending}
      icon={save.isPending ? <Loader2 size={15} className="animate-spin" /> : undefined} onClick={() => save.mutate(v, { onSuccess: () => { toast(L('حُفظت المهمة', 'Task saved')); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('عنوان المهمة', 'Title')} req className="sm:col-span-2" error={err?.field('title')}><input className="field" value={v.title} maxLength={190} onChange={e => setV({ ...v, title: e.target.value })} /></Field>
      <Field label={L('التفاصيل', 'Details')} className="sm:col-span-2"><textarea className="field min-h-24" value={v.description} maxLength={5000} onChange={e => setV({ ...v, description: e.target.value })} /></Field>
      {!t && <Field label={L('المكلَّف', 'Assignee')} req error={err?.field('employee_id')}><select className="field" value={v.employee_id || ''} onChange={e => setV({ ...v, employee_id: Number(e.target.value) })}>
        {page.me && <option value={page.me}>{L('أنا (مهمة خاصة)', 'Me (own task)')}</option>}
        {page.people.map(p => <option key={p.id} value={p.id}>{lang === 'ar' ? p.ar : p.en} · {p.code}</option>)}</select></Field>}
      <Field label={L('الأولوية', 'Priority')}><select className="field" value={v.priority} onChange={e => setV({ ...v, priority: e.target.value as Priority })}>
        {(Object.keys(PRI) as Priority[]).map(p => <option key={p} value={p}>{L(PRI[p][0], PRI[p][1])} (+{page.points.task[p]} {L('نقطة', 'pts')})</option>)}</select></Field>
      <Field label={L('الموعد النهائي', 'Due date')} error={err?.field('due_on')}><input type="date" className="field" value={v.due_on} onChange={e => setV({ ...v, due_on: e.target.value })} /></Field>
      {page.kpis.length > 0 && <Field label={L('مرتبطة بمؤشر أداء', 'Linked KPI')}><select className="field" value={v.kpi_id ?? ''} onChange={e => setV({ ...v, kpi_id: e.target.value ? Number(e.target.value) : null })}>
        <option value="">—</option>{page.kpis.map(k => <option key={k.id} value={k.id}>{bi(k, lang)}</option>)}</select></Field>}
    </div>
  </Modal>
}

function TaskDrawer({ id, onEdit, onClose }: { id: number; onEdit: (t: Task) => void; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const { data: t } = useTask(id)
  const prog = useTaskProgress(id); const decide = useTaskDecide(id); const cancel = useTaskCancel(id); const comment = useTaskComment(id)
  const [pct, setPct] = useState<number | null>(null)
  const [note, setNote] = useState('')
  const [dlg, setDlg] = useState<'return' | 'cancel' | null>(null)
  const fail = (e: unknown) => toast(e instanceof ApiError ? e.message : L('تعذّر التنفيذ', 'Could not complete'))
  return <Drawer open onClose={onClose} width={560}>
    <header className="flex items-center justify-between gap-3 px-5 h-14 border-b border-line-2"><h3 className="font-display font-bold text-[15px] truncate">{t?.title ?? '…'}</h3>
      <button className="p-1.5 rounded-lg hover:bg-sub" onClick={onClose} aria-label={L('إغلاق', 'Close')}><X size={18} /></button></header>
    {!t ? <div className="grid place-items-center py-16"><Loader2 className="animate-spin text-brand" /></div> : <div className="flex-1 overflow-y-auto p-5 space-y-4">
      <div className="flex flex-wrap gap-1.5"><Badge tone={ST[t.status][2]}>{L(ST[t.status][0], ST[t.status][1])}</Badge><Badge tone={PRI[t.priority][2]}>{L(PRI[t.priority][0], PRI[t.priority][1])}</Badge>
        {t.points > 0 && <Badge tone="brand">{L(`+${t.points} نقطة`, `+${t.points} points`)}</Badge>}</div>
      {t.description && <p className="text-[14px] whitespace-pre-line">{t.description}</p>}
      <div className="grid grid-cols-2 gap-2 text-[13px]">
        <div><span className="text-ink-3">{L('المكلَّف', 'Assignee')}: </span>{t.employee ? bi(t.employee.name, lang) : '—'}</div>
        <div><span className="text-ink-3">{L('من', 'By')}: </span>{t.by ? bi(t.by, lang) : '—'}</div>
        <div><span className="text-ink-3">{L('الموعد', 'Due')}: </span><span className={`num ${t.overdue ? 'text-danger font-semibold' : ''}`}>{t.due_on ? fmtDate(t.due_on, lang) : '—'}</span></div>
        <div><span className="text-ink-3">{L('التقدّم', 'Progress')}: </span><span className="num">{t.progress}%</span></div>
        {t.kpi && <div className="col-span-2"><span className="text-ink-3">{L('المؤشر', 'KPI')}: </span>{bi(t.kpi, lang)}</div>}
        {t.close_note && <div className="col-span-2 text-danger">{t.close_note}</div>}
      </div>
      {t.can.progress && <Panel title={L('تحديث المهمة', 'Update')}>
        <div className="space-y-3">
          {t.status !== 'review' && <><label className="block text-[13px]">{L('نسبة الإنجاز', 'Progress')} <span className="num font-semibold">{pct ?? t.progress}%</span>
            <input type="range" min={0} max={100} step={10} value={pct ?? t.progress} onChange={e => setPct(Number(e.target.value))} className="w-full accent-[var(--primary)]" /></label>
          <div className="flex flex-wrap gap-2">
            <Btn size="sm" disabled={prog.isPending} onClick={() => prog.mutateAsync({ status: 'in_progress', progress: pct ?? Math.max(t.progress, 10) }).then(() => { setPct(null); toast(L('حُدّثت', 'Updated')) }).catch(fail)}>{L('حفظ التقدّم', 'Save progress')}</Btn>
            <Btn size="sm" kind="primary" icon={<CheckCircle2 size={14} />} disabled={prog.isPending} onClick={() => prog.mutateAsync({ status: 'done', note: note || undefined }).then(r => { setNote(''); toast(r.data.status === 'review' ? L('أُرسلت للاعتماد', 'Sent for approval') : L('أُنجزت المهمة', 'Task done')) }).catch(fail)}>
              {t.self ? L('تم الإنجاز', 'Mark done') : L('أنجزتُها — أرسل للاعتماد', 'Done — send for approval')}</Btn></div></>}
          {t.status === 'review' && <p className="text-[13px] text-ink-2">{L('بانتظار اعتماد من كلّفك.', 'Awaiting approval from the assigner.')}</p>}
        </div></Panel>}
      {t.can.decide && <div className="flex flex-wrap gap-2">
        <Btn kind="primary" icon={<Check size={15} />} disabled={decide.isPending} onClick={() => decide.mutateAsync({ approve: true }).then(() => toast(L('اعتُمد الإنجاز', 'Approved'))).catch(fail)}>{L('اعتماد الإنجاز', 'Approve')}</Btn>
        <Btn icon={<Undo2 size={15} />} onClick={() => setDlg('return')}>{L('إعادة للتنفيذ', 'Return')}</Btn></div>}
      {t.can.manage && !['done', 'cancelled'].includes(t.status) && <div className="flex flex-wrap gap-2">
        <Btn size="sm" icon={<Pencil size={14} />} onClick={() => onEdit(t)}>{L('تعديل', 'Edit')}</Btn>
        <Btn size="sm" kind="danger" onClick={() => setDlg('cancel')}>{L('إلغاء المهمة', 'Cancel task')}</Btn></div>}
      <Panel title={L('التعليقات', 'Comments')}>
        <ul className="space-y-2 mb-3">{(t.comments ?? []).map(c => <li key={c.id} className="rounded-lg bg-sub px-3 py-2 text-[13.5px]"><div className="text-[11.5px] text-ink-3">{c.by ? bi(c.by, lang) : ''} · <span className="num">{c.at ? fmtDateTime(c.at, lang) : ''}</span></div><div className="whitespace-pre-line">{c.body}</div></li>)}
          {!(t.comments ?? []).length && <li className="text-[13px] text-ink-3">{L('لا تعليقات', 'No comments')}</li>}</ul>
        {!['done', 'cancelled'].includes(t.status) && <div className="flex gap-2"><input className="field flex-1" value={note} maxLength={2000} onChange={e => setNote(e.target.value)} placeholder={L('اكتب تعليقاً…', 'Write a comment…')} />
          <Btn disabled={note.trim().length < 2 || comment.isPending} onClick={() => comment.mutateAsync(note).then(() => setNote('')).catch(fail)}>{L('إرسال', 'Send')}</Btn></div>}
      </Panel>
    </div>}
    <DecisionDialog open={!!dlg} onClose={() => setDlg(null)} tone="danger" reasonRequired title={dlg === 'return' ? L('إعادة المهمة للتنفيذ', 'Return the task') : L('إلغاء المهمة', 'Cancel the task')}
      subject={t?.title} confirmLabel={L('تأكيد', 'Confirm')}
      onConfirm={reason => (dlg === 'return' ? decide.mutateAsync({ approve: false, note: reason }) : cancel.mutateAsync(reason)).then(() => toast(L('تم', 'Done')))} />
  </Drawer>
}

function KpisView() {
  const L = useL(); const lang = useLang()
  const { data: d } = useKpis()
  const [form, setForm] = useState<{ k: Kpi | null } | null>(null)
  const [rec, setRec] = useState<Kpi | null>(null)
  const [scope, setScope] = useState<'all' | 'employee' | 'department' | 'company'>('all')
  if (!d) return <div className="grid place-items-center py-16"><Loader2 className="animate-spin text-brand" /></div>
  const canNew = d.can.employee || d.can.department || d.can.company
  const rows = d.data.filter(k => scope === 'all' || k.scope === scope)
  const scopeLabel = { employee: L('موظف', 'Employee'), department: L('إدارة', 'Department'), company: L('الشركة', 'Company') }
  const per = { month: L('شهري', 'Monthly'), quarter: L('ربعي', 'Quarterly'), year: L('سنوي', 'Yearly') }
  return <div className="space-y-3">
    <div className="flex flex-wrap items-center gap-2 justify-between">
      <Seg value={scope} onChange={setScope} options={[{ v: 'all', label: L('الكل', 'All') }, { v: 'company', label: scopeLabel.company }, { v: 'department', label: L('الإدارات', 'Departments') }, { v: 'employee', label: L('الموظفون', 'Employees') }]} />
      {canNew && <Btn kind="primary" icon={<Plus size={15} />} onClick={() => setForm({ k: null })}>{L('مؤشر جديد', 'New KPI')}</Btn>}
    </div>
    {rows.length === 0 ? <Panel><Empty title={L('لا مؤشرات', 'No KPIs')}>{L('المؤشر: هدف قابل للقياس لموظف أو إدارة أو للشركة، تُسجَّل قيمته كل فترة.', 'A KPI is a measurable target for an employee, a department or the company, recorded every period.')}</Empty></Panel> :
      <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(280px,1fr))]">{rows.map(k => <div key={k.id} className="rounded-2xl border border-line bg-surface p-4 flex flex-col gap-2 min-w-0">
        <div className="flex flex-wrap items-center gap-1.5"><Badge tone={k.scope === 'company' ? 'brand' : k.scope === 'department' ? 'info' : 'neutral'}>{scopeLabel[k.scope]}</Badge><Badge>{per[k.period]}</Badge>{!k.active && <Badge tone="danger">{L('موقوف', 'Inactive')}</Badge>}</div>
        <div className="font-semibold break-words">{bi(k.name, lang)}</div>
        <div className="text-[12.5px] text-ink-3">{k.employee ? bi(k.employee.name, lang) : k.department ? bi(k.department, lang) : L('كل الشركة', 'Whole company')}</div>
        <div className="flex items-end justify-between gap-2">
          <div><div className="text-[12px] text-ink-3">{L('الهدف', 'Target')} {k.direction === 'lower' ? L('(الأقل أفضل)', '(lower is better)') : ''}</div><div className="num font-bold ltr inline-block">{k.target}{k.unit === 'percent' ? '%' : ''}</div></div>
          {k.latest ? <div className="text-end"><div className="text-[12px] text-ink-3 num"><span className="ltr inline-block">{k.latest.period}</span> · {k.latest.actual}</div><div className={`text-[22px] font-bold num ${achTone(k.latest.achievement)}`}>{k.latest.achievement}%</div></div>
            : <div className="text-[12.5px] text-ink-3">{L('لا قيم بعد', 'No values yet')}</div>}
        </div>
        {k.entries.length > 1 && <Sparkline data={[...k.entries].reverse().map(e => e.achievement)} w={240} h={34} />}
        {k.can.manage && <div className="flex gap-2 mt-auto pt-1"><Btn size="sm" kind="primary" icon={<Gauge size={14} />} onClick={() => setRec(k)}>{L('تسجيل قيمة', 'Record value')}</Btn>
          <Btn size="sm" icon={<Pencil size={14} />} onClick={() => setForm({ k })}>{L('تعديل', 'Edit')}</Btn></div>}
      </div>)}</div>}
    {form && <KpiForm page={d} k={form.k} onClose={() => setForm(null)} />}
    {rec && <RecordModal k={rec} onClose={() => setRec(null)} />}
  </div>
}

function KpiForm({ page, k, onClose }: { page: KpisPage; k: Kpi | null; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const save = useSaveKpi(k?.id ?? null)
  const first = page.can.employee ? 'employee' : page.can.department ? 'department' : 'company'
  const [v, setV] = useState<KpiInput>({ scope: k?.scope ?? first, employee_id: k?.employee?.id ?? null, department_id: k?.department?.id ?? null, name_ar: k?.name_ar ?? '', name_en: k?.name_en ?? '',
    unit: k?.unit ?? 'number', target: k?.target ?? 0, direction: k?.direction ?? 'higher', period: k?.period ?? 'month', weight: k?.weight ?? 1, note: k?.note ?? '', active: k?.active ?? true })
  const err = save.error instanceof ApiError ? save.error : null
  const units: Record<string, string> = { number: L('رقم', 'Number'), percent: L('نسبة %', 'Percent'), currency: L('مبلغ', 'Amount'), count: L('عدد', 'Count'), hours: L('ساعات', 'Hours'), days: L('أيام', 'Days') }
  return <Modal open onClose={() => !save.isPending && onClose()} width={640} title={k ? L('تعديل المؤشر', 'Edit KPI') : L('مؤشر أداء جديد', 'New KPI')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={v.name_ar.trim().length < 3 || save.isPending} onClick={() => save.mutate(v, { onSuccess: () => { toast(L('حُفظ المؤشر', 'KPI saved')); onClose() }, onError: e => { if (!(e instanceof ApiError) || !Object.keys(e.errors).length) toast((e as Error).message) } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      {!k && <Field label={L('المؤشر لـ', 'KPI for')} div className="sm:col-span-2"><Seg value={v.scope} onChange={s => setV({ ...v, scope: s })} options={[
        ...(page.can.employee ? [{ v: 'employee' as const, label: L('موظف', 'Employee') }] : []), ...(page.can.department ? [{ v: 'department' as const, label: L('إدارة', 'Department') }] : []), ...(page.can.company ? [{ v: 'company' as const, label: L('الشركة', 'Company') }] : [])]} /></Field>}
      {!k && v.scope === 'employee' && <Field label={L('الموظف', 'Employee')} req error={err?.field('employee_id')} className="sm:col-span-2"><select className="field" value={v.employee_id ?? ''} onChange={e => setV({ ...v, employee_id: Number(e.target.value) || null })}>
        <option value="">{L('اختر…', 'Choose…')}</option>{page.people.map(p => <option key={p.id} value={p.id}>{lang === 'ar' ? p.ar : p.en} · {p.code}</option>)}</select></Field>}
      {!k && v.scope === 'department' && <Field label={L('الإدارة', 'Department')} req error={err?.field('department_id')} className="sm:col-span-2"><select className="field" value={v.department_id ?? ''} onChange={e => setV({ ...v, department_id: Number(e.target.value) || null })}>
        <option value="">{L('اختر…', 'Choose…')}</option>{page.departments.map(p => <option key={p.id} value={p.id}>{bi(p, lang)}</option>)}</select></Field>}
      <Field label={L('اسم المؤشر', 'KPI name')} req error={err?.field('name_ar')}><input className="field" value={v.name_ar} maxLength={190} onChange={e => setV({ ...v, name_ar: e.target.value })} placeholder={L('مثال: نسبة التذاكر المغلقة في موعدها', 'e.g. Tickets closed on time')} /></Field>
      <Field label={L('الاسم بالإنكليزية', 'English name')}><input className="field ltr text-start" value={v.name_en} maxLength={190} onChange={e => setV({ ...v, name_en: e.target.value })} /></Field>
      <Field label={L('الوحدة', 'Unit')}><select className="field" value={v.unit} onChange={e => setV({ ...v, unit: e.target.value })}>{page.units.map(u => <option key={u} value={u}>{units[u] ?? u}</option>)}</select></Field>
      <Field label={L('الهدف', 'Target')} req error={err?.field('target')}><input type="number" min={0} className="field num" value={v.target} onChange={e => setV({ ...v, target: Number(e.target.value) })} /></Field>
      <Field label={L('الاتجاه', 'Direction')} div><Seg value={v.direction} onChange={x => setV({ ...v, direction: x })} options={[{ v: 'higher', label: L('الأعلى أفضل', 'Higher is better') }, { v: 'lower', label: L('الأقل أفضل', 'Lower is better') }]} /></Field>
      <Field label={L('فترة القياس', 'Period')}><select className="field" value={v.period} onChange={e => setV({ ...v, period: e.target.value })}>
        <option value="month">{L('شهرية', 'Monthly')}</option><option value="quarter">{L('ربعية', 'Quarterly')}</option><option value="year">{L('سنوية', 'Yearly')}</option></select></Field>
      <Field label={L('ملاحظة / طريقة القياس', 'Note / how it is measured')} className="sm:col-span-2"><textarea className="field min-h-16" value={v.note} maxLength={1000} onChange={e => setV({ ...v, note: e.target.value })} /></Field>
      {k && <label className="sm:col-span-2 flex items-center gap-2 text-[14px]"><input type="checkbox" className="size-4 accent-[var(--primary)]" checked={v.active} onChange={e => setV({ ...v, active: e.target.checked })} />{L('مؤشر فعّال', 'Active')}</label>}
    </div>
  </Modal>
}

function RecordModal({ k, onClose }: { k: Kpi; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const rec = useRecordKpi(k.id)
  const [v, setV] = useState({ period: k.current_period, actual: '', note: '' })
  const err = rec.error instanceof ApiError ? rec.error : null
  const hint = k.period === 'month' ? '2026-10' : k.period === 'quarter' ? '2026-Q4' : '2026'
  return <Modal open onClose={onClose} title={<span>{L('تسجيل قيمة', 'Record value')} — {bi(k.name, lang)}</span>}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={v.actual === '' || rec.isPending} onClick={() => rec.mutate({ period: v.period, actual: Number(v.actual), note: v.note || undefined }, { onSuccess: r => { toast(L(`نسبة التحقيق ${r.data.latest?.achievement}%`, `Achievement ${r.data.latest?.achievement}%`)); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('الفترة', 'Period')} hint={hint} error={err?.field('period')}><input className="field ltr text-start num" value={v.period} onChange={e => setV({ ...v, period: e.target.value })} /></Field>
      <Field label={L('القيمة الفعلية', 'Actual value')} req hint={L(`الهدف ${k.target}`, `Target ${k.target}`)} error={err?.field('actual')}><input type="number" min={0} className="field num" value={v.actual} onChange={e => setV({ ...v, actual: e.target.value })} /></Field>
      <Field label={L('ملاحظة', 'Note')} className="sm:col-span-2"><input className="field" value={v.note} maxLength={1000} onChange={e => setV({ ...v, note: e.target.value })} /></Field>
      {k.entries.length > 0 && <div className="sm:col-span-2 text-[12.5px] text-ink-3">{L('آخر القيم:', 'Recent:')} {k.entries.slice(0, 6).map(e => `${e.period} (${e.achievement}%)`).join(' · ')}</div>}
    </div>
  </Modal>
}

/** إعداد النقاط من الأداء (ملاحظة 61) */
function PointsModal({ onClose }: { onClose: () => void }) {
  const L = useL()
  const { data } = usePointsSettings(true)
  const save = useSavePoints()
  const [v, setV] = useState<PointsCfg | null>(null)
  const c = v ?? data?.data ?? null
  const num = (val: number, set: (n: number) => void, max = 1000) => <input type="number" min={0} max={max} className="field num" value={val} onChange={e => set(Number(e.target.value))} />
  return <Modal open onClose={onClose} width={620} title={<span className="flex items-center gap-2"><Settings2 size={17} />{L('النقاط من الأداء', 'Points from performance')}</span>}
    footer={<>{data && <Btn onClick={() => setV(data.defaults)}>{L('الافتراضي', 'Defaults')}</Btn>}<Btn onClick={onClose}>{L('إغلاق', 'Close')}</Btn>
      <Btn kind="primary" disabled={!v || save.isPending} onClick={() => v && save.mutate(v, { onSuccess: () => { toast(L('حُفظ', 'Saved')); onClose() }, onError: e => toast((e as Error).message) })}>{L('حفظ', 'Save')}</Btn></>}>
    {!c ? <Loader2 className="animate-spin text-brand" /> : <div className="space-y-4">
      <p className="text-[13px] text-ink-3">{L('تُضاف إلى رصيد الموظف في متجر المكافآت مع نقاط الإحالة، مرة واحدة لكل مهمة أو فترة مؤشر أو تقييم.', 'Added to the employee\'s rewards balance along with referral points, once per task, KPI period or review.')}</p>
      <label className="flex items-center gap-2 text-[14px]"><input type="checkbox" className="size-4 accent-[var(--primary)]" checked={c.enabled} onChange={e => setV({ ...c, enabled: e.target.checked })} />{L('احتساب النقاط من الأداء', 'Award points from performance')}</label>
      <div><div className="font-semibold text-[13px] mb-2">{L('إنجاز مهمة حسب الأولوية', 'Task done, by priority')}</div>
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">{(['low', 'normal', 'high', 'urgent'] as Priority[]).map(p => <Field key={p} label={L(PRI[p][0], PRI[p][1])}>{num(c.task[p], n => setV({ ...c, task: { ...c.task, [p]: n } }))}</Field>)}</div>
        <Field label={L('معامل الإنجاز بعد الموعد (0–1)', 'Late completion factor (0–1)')} className="mt-2 max-w-xs"><input type="number" min={0} max={1} step={0.1} className="field num" value={c.late_factor} onChange={e => setV({ ...c, late_factor: Number(e.target.value) })} /></Field></div>
      <div><div className="font-semibold text-[13px] mb-2">{L('مؤشرات الأداء الفردية', 'Individual KPIs')}</div>
        <div className="grid grid-cols-3 gap-2"><Field label={L('تحقيق 100%', '100% achieved')}>{num(c.kpi_met, n => setV({ ...c, kpi_met: n }))}</Field>
          <Field label={L('تجاوز الهدف', 'Exceeded')}>{num(c.kpi_exceed, n => setV({ ...c, kpi_exceed: n }))}</Field>
          <Field label={L('نسبة التجاوز %', 'Exceed at %')}>{num(c.exceed_pct, n => setV({ ...c, exceed_pct: n }), 200)}</Field></div></div>
      <div><div className="font-semibold text-[13px] mb-2">{L('نتيجة التقييم المعتمد', 'Approved review result')}</div>
        <div className="grid grid-cols-3 gap-2">{(['high', 'mid', 'low'] as const).map(k => <Field key={k} label={k === 'high' ? L('أداء عالٍ', 'High') : k === 'mid' ? L('متوسط', 'Medium') : L('منخفض', 'Low')}>{num(c.review[k], n => setV({ ...c, review: { ...c.review, [k]: n } }), 5000)}</Field>)}</div></div>
    </div>}
  </Modal>
}
