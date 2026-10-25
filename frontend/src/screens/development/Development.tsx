import { useState } from 'react'
import { Plus, Pencil, Play, ClipboardCheck, Trash2, UserPlus, Settings2, AlertTriangle, Target, Sprout } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../lib/store'
import { setQuery, useRoute } from '../../lib/router'
import { ApiError } from '../../lib/api'
import { useAssess, useDevelopment, useNominate, usePlanAction, useProgress, useRemoveCandidate, useSaveDevSettings, useSavePlan, useSaveSPlan, useSPlanAction,
  type Activity, type ActKind, type ActStatus, type Candidate, type DevPage, type DevSettings, type Plan, type PlanInput, type Readiness, type SPlan, type SPlanInput } from '../../lib/development'
import { Avatar, Badge, Btn, Empty, Field, Modal, PageHead, Panel, Progress, Seg, Tabs, toast } from '../../ui/kit'
import { DecisionDialog } from '../../ui/decision'

const err = (e: unknown, f: string) => toast(e instanceof ApiError ? e.message : f)
type Tab = 'plans' | 'succession'

function useNames() {
  const L = useL()
  const kind: Record<ActKind, string> = { course: L('دورة', 'Course'), mentoring: L('إرشاد', 'Mentoring'), project: L('مشروع', 'Project'), reading: L('قراءة', 'Reading'), rotation: L('تدوير وظيفي', 'Rotation'), other: L('أخرى', 'Other') }
  const act: Record<ActStatus, string> = { planned: L('مخطط', 'Planned'), in_progress: L('جارٍ', 'In progress'), done: L('منجز', 'Done'), cancelled: L('ملغى', 'Cancelled') }
  const risk = { low: L('منخفض', 'Low'), medium: L('متوسط', 'Medium'), high: L('مرتفع', 'High') }
  return { kind, act, risk }
}

function PlanBadge({ s }: { s: Plan['status'] }) {
  const L = useL()
  return s === 'active' ? <Badge tone="brand">{L('جارية', 'Active')}</Badge> : s === 'draft' ? <Badge>{L('مسودة', 'Draft')}</Badge> : <Badge tone="info">{L('مكتملة', 'Completed')}</Badge>
}
function SBadge({ s }: { s: SPlan['status'] }) {
  const L = useL()
  return s === 'active' ? <Badge tone="brand">{L('فعّالة', 'Active')}</Badge> : s === 'draft' ? <Badge>{L('مسودة', 'Draft')}</Badge> : <Badge tone="info">{L('مراجَعة', 'Reviewed')}</Badge>
}
function ReadyBadge({ r, d }: { r: Readiness; d: DevPage }) {
  const lang = useLang()
  const label = d.settings.readiness.find(x => x.key === r)
  return <Badge tone={r === 'now' ? 'brand' : r === '1y' ? 'warn' : 'neutral'}>{label ? bi(label, lang) : r}</Badge>
}

/**
 * التعاقب وخطط التطوير (D-23، D-24): الموظف يرى خطته ويحدّث أنشطته، المدير يبني خطط فريقه ويرشّح للمناصب الحرجة،
 * والموارد البشرية تدير المناصب والفجوات وتربط المرشحين بخطط تطوير.
 */
export function Development({ title }: { title?: string }) {
  const L = useL(); const route = useRoute()
  const q = useDevelopment(); const d = q.data
  const [plan, setPlan] = useState<Plan | 'new' | null>(null); const [sp, setSp] = useState<SPlan | 'new' | null>(null); const [cfg, setCfg] = useState(false)
  if (!d) return <div className="max-w-[1200px] mx-auto"><PageHead title={title ?? L('التعاقب وخطط التطوير', 'Succession & development')} /></div>
  const tab = (route.query.tab === 'succession' && d.can.team ? 'succession' : 'plans') as Tab
  const gaps = d.succession.filter(s => s.gap).length
  return <div className="max-w-[1200px] mx-auto">
    <PageHead title={title ?? L('التعاقب وخطط التطوير', 'Succession & development')}
      sub={d.can.manage ? L('المناصب الحرجة ومرشحوها، وخطط التطوير الفردية بأنشطتها ومواعيدها.', 'Critical positions and successors, and individual development plans with their activities.') : L('خطط التطوير بأهدافها وأنشطتها ومتابعتها.', 'Development plans with goals, activities and follow-up.')}
      actions={<>{d.can.settings && <Btn icon={<Settings2 size={16} />} onClick={() => setCfg(true)}>{L('الإعدادات', 'Settings')}</Btn>}
        {tab === 'plans' && d.can.create && d.employees.length > 0 && <Btn kind="primary" icon={<Plus size={16} />} onClick={() => setPlan('new')}>{L('خطة تطوير', 'Development plan')}</Btn>}
        {tab === 'succession' && d.can.manage && <Btn kind="primary" icon={<Plus size={16} />} onClick={() => setSp('new')}>{L('منصب حرج', 'Critical position')}</Btn>}</>} />
    {d.can.team && <Tabs value={tab} onChange={v => setQuery({ tab: v === 'plans' ? undefined : v })} tabs={[{ v: 'plans', label: L('خطط التطوير', 'Development plans'), count: d.plans.filter(p => p.status !== 'done').length },
      { v: 'succession', label: <span className="flex items-center gap-1.5">{L('التعاقب', 'Succession')}{gaps > 0 && <AlertTriangle size={14} className="text-warn" />}</span>, count: d.succession.length }]} />}
    <div className="mt-4 space-y-4">
      {tab === 'plans' ? <Plans d={d} onEdit={setPlan} /> : <SuccessionList d={d} onEdit={setSp} />}
    </div>
    {plan && <PlanForm d={d} plan={plan === 'new' ? null : plan} onClose={() => setPlan(null)} />}
    {sp && <SPlanForm d={d} plan={sp === 'new' ? null : sp} onClose={() => setSp(null)} />}
    {cfg && <SettingsModal s={d.settings} onClose={() => setCfg(false)} />}
  </div>
}

function Plans({ d, onEdit }: { d: DevPage; onEdit: (p: Plan) => void }) {
  const L = useL(); const lang = useLang(); const n = useNames()
  const act = usePlanAction()
  const [rev, setRev] = useState<Plan | null>(null); const [upd, setUpd] = useState<Activity | null>(null)
  const mine = d.plans.filter(p => p.employee.id === d.me), team = d.plans.filter(p => p.employee.id !== d.me)
  const card = (p: Plan) => <Panel key={p.id} title={<span className="flex flex-wrap items-center gap-2">{p.title}<PlanBadge s={p.status} />{p.source === 'succession' && <Badge tone="info">{L('من التعاقب', 'Succession')}</Badge>}{p.review_due && <Badge tone="warn">{L('مراجعة مستحقة', 'Review due')}</Badge>}</span>}
    sub={p.employee.id !== d.me ? <span className="flex items-center gap-2"><Avatar name={p.employee.name.ar} size={22} />{bi(p.employee.name, lang)} · {bi(p.employee.title, lang)}</span> : undefined}
    action={p.can.edit && p.status !== 'done' ? <div className="flex flex-wrap gap-1.5"><Btn size="sm" icon={<Pencil size={14} />} onClick={() => onEdit(p)}>{L('تعديل', 'Edit')}</Btn>
      {p.status === 'draft' && <Btn size="sm" kind="primary" icon={<Play size={14} />} disabled={act.isPending} onClick={() => act.mutate({ id: p.id, action: 'activate' }, { onSuccess: () => toast(L('فُعّلت الخطة', 'Plan activated')), onError: e => err(e, L('تعذّر التفعيل', 'Could not activate')) })}>{L('تفعيل', 'Activate')}</Btn>}
      {p.status === 'active' && <Btn size="sm" kind="primary" icon={<ClipboardCheck size={14} />} onClick={() => setRev(p)}>{L('مراجعة', 'Review')}</Btn>}</div> : undefined}>
    <div className="grid md:grid-cols-[1fr_1.3fr] gap-5">
      <div className="space-y-3 min-w-0">
        {p.gaps && <div><div className="text-[12px] font-semibold text-ink-3">{L('الفجوات', 'Gaps')}</div><p className="text-[13.5px] whitespace-pre-line">{p.gaps}</p></div>}
        <div><div className="text-[12px] font-semibold text-ink-3 mb-1">{L('الأهداف', 'Goals')}</div><ul className="space-y-1">{p.goals.map((g, i) => <li key={i} className="flex items-start gap-2 text-[13.5px]"><Target size={14} className={`mt-1 shrink-0 ${g.done ? 'text-brand' : 'text-ink-3'}`} /><span className={g.done ? 'line-through text-ink-3' : ''}>{g.text}</span></li>)}</ul></div>
        <div><div className="flex justify-between text-[12px] mb-1"><span className="text-ink-3">{L('التقدم', 'Progress')}</span><span className="num font-semibold">{p.progress}%</span></div><Progress value={p.progress} /></div>
        {p.next_review && <div className="text-[12.5px] text-ink-3">{L('المراجعة القادمة', 'Next review')}: <span className="num">{fmtDate(p.next_review, lang)}</span></div>}
        {p.reviews.length > 0 && <details className="text-[12.5px]"><summary className="cursor-pointer text-brand">{L(`المراجعات (${p.reviews.length})`, `Reviews (${p.reviews.length})`)}</summary>
          <ul className="mt-1 space-y-1">{p.reviews.map((r, i) => <li key={i} className="border-s-2 border-line ps-2"><span className="num">{fmtDate(r.at, lang)}</span> · {lang === 'ar' ? r.by : r.by_en} · <span className="num">{r.rating}/5</span><div className="text-ink-2">{r.note}</div></li>)}</ul></details>}
      </div>
      <div className="min-w-0"><div className="text-[12px] font-semibold text-ink-3 mb-1">{L('الأنشطة', 'Activities')}</div>
        {p.activities.length === 0 ? <p className="text-[13px] text-ink-3">{L('لا أنشطة بعد.', 'No activities yet.')}</p> : <ul className="space-y-2">{p.activities.map(a => <li key={a.id} className={`rounded-xl border p-2.5 ${a.overdue ? 'border-danger/50 bg-danger-soft' : 'border-line-2'}`}>
          <div className="flex flex-wrap items-center gap-2"><span className="font-semibold text-[13.5px] flex-1 min-w-[140px]">{a.title}</span><Badge>{n.kind[a.kind]}</Badge>
            <Badge tone={a.status === 'done' ? 'brand' : a.status === 'cancelled' ? 'neutral' : a.overdue ? 'danger' : 'info'}>{a.overdue ? L('متأخر', 'Overdue') : n.act[a.status]}</Badge>
            {a.can_update && a.status !== 'done' && a.status !== 'cancelled' && <Btn size="sm" onClick={() => setUpd(a)}>{L('تحديث', 'Update')}</Btn>}</div>
          <div className="mt-1 text-[12px] text-ink-3">{L('الموعد', 'Due')} <span className="num">{fmtDate(a.due_on, lang)}</span>{a.owner && <> · {L('المسؤول', 'Owner')}: {bi(a.owner.name, lang)}</>}{a.progress > 0 && <> · <span className="num">{a.progress}%</span></>}</div>
          {a.note && <div className="mt-1 text-[12.5px] text-ink-2">{a.note}</div>}</li>)}</ul>}
      </div>
    </div>
  </Panel>
  return <>
    {d.owned.length > 0 && <Panel title={L('أنشطة أنا مسؤول عنها', 'Activities I own')} pad={false}><ul className="divide-y divide-line-2">{d.owned.map(a => <li key={a.id} className="px-5 py-2.5 flex flex-wrap items-center gap-2 text-[13px]">
      <span className="flex-1 min-w-[160px]"><b>{a.title}</b> · {bi(a.employee, lang)}</span><span className="num text-ink-3">{fmtDate(a.due_on, lang)}</span>{a.overdue ? <Badge tone="danger">{L('متأخر', 'Overdue')}</Badge> : <Badge tone="info">{n.act[a.status]}</Badge>}
      <Btn size="sm" onClick={() => setUpd({ ...a, owner: null, note: null, can_update: true })}>{L('تحديث', 'Update')}</Btn></li>)}</ul></Panel>}
    {d.plans.length === 0 && <div className="bg-surface border border-line rounded-2xl"><Empty title={L('لا خطط تطوير بعد', 'No development plans yet')}>{d.can.create ? L('ابدأ بخطة لأحد أعضاء فريقك: الفجوات، الأهداف، والأنشطة بمسؤول وموعد.', 'Start a plan for a team member: gaps, goals and activities with an owner and a date.') : L('يضع مديرك أو الموارد البشرية خطة تطويرك، وتظهر هنا لتتابع أنشطتها.', 'Your manager or HR sets up your plan; it appears here so you can follow its activities.')}</Empty></div>}
    {mine.length > 0 && team.length > 0 && <h2 className="text-[15px] font-bold">{L('خطتي', 'My plan')}</h2>}
    {mine.map(card)}
    {team.length > 0 && mine.length > 0 && <h2 className="text-[15px] font-bold pt-2">{L('خطط فريقي', 'My team’s plans')}</h2>}
    {team.map(card)}
    {rev && <ReviewModal p={rev} onClose={() => setRev(null)} />}
    {upd && <ProgressModal a={upd} onClose={() => setUpd(null)} />}
  </>
}

function ProgressModal({ a, onClose }: { a: Pick<Activity, 'id' | 'title' | 'status' | 'progress'>; onClose: () => void }) {
  const L = useL(); const n = useNames()
  const save = useProgress()
  const [status, setStatus] = useState<ActStatus>(a.status === 'planned' ? 'in_progress' : a.status); const [progress, setProgress] = useState(a.progress); const [note, setNote] = useState('')
  return <Modal open onClose={onClose} title={a.title} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate({ id: a.id, status, progress, note: note || undefined }, { onSuccess: () => { toast(L('حُدّث النشاط', 'Activity updated')); onClose() }, onError: e => err(e, L('تعذّر التحديث', 'Could not update')) })}>{L('حفظ', 'Save')}</Btn></>}>
    <Field div label={L('الحالة', 'Status')}><Seg value={status} onChange={setStatus} options={(['planned', 'in_progress', 'done'] as ActStatus[]).map(v => ({ v, label: n.act[v] }))} /></Field>
    {status !== 'done' && <Field label={<>{L('نسبة الإنجاز', 'Progress')} <span className="num">{progress}%</span></>} className="mt-3"><input type="range" min={0} max={100} step={10} value={progress} onChange={e => setProgress(Number(e.target.value))} className="w-full" /></Field>}
    <Field label={L('ملاحظة', 'Note')} className="mt-3"><textarea className="field" value={note} onChange={e => setNote(e.target.value)} /></Field>
  </Modal>
}

function ReviewModal({ p, onClose }: { p: Plan; onClose: () => void }) {
  const L = useL()
  const act = usePlanAction()
  const [note, setNote] = useState(''); const [rating, setRating] = useState(3); const [done, setDone] = useState<number[]>([]); const [complete, setComplete] = useState(false)
  const e = act.error instanceof ApiError ? act.error : null
  return <Modal open onClose={onClose} title={L('مراجعة خطة التطوير', 'Development plan review')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={act.isPending || note.trim().length < 5} onClick={() => act.mutate({ id: p.id, action: 'review', body: { note, rating, goals_done: done, complete } }, { onSuccess: () => { toast(complete ? L('اكتملت الخطة', 'Plan completed') : L('سُجّلت المراجعة', 'Review saved')); onClose() } })}>{complete ? L('إكمال الخطة', 'Complete plan') : L('حفظ المراجعة', 'Save review')}</Btn></>}>
    <Field div label={L('التقدير العام', 'Overall rating')}><div className="flex gap-1">{[1, 2, 3, 4, 5].map(v => <button key={v} type="button" onClick={() => setRating(v)} className={`size-10 rounded-lg border font-bold num ${rating === v ? 'bg-brand text-on-brand border-brand' : 'border-line'}`}>{v}</button>)}</div></Field>
    <Field div label={L('الأهداف المنجزة', 'Goals achieved')} className="mt-3"><div className="space-y-1">{p.goals.map((g, i) => <label key={i} className="flex items-center gap-2 text-[13.5px]"><input type="checkbox" disabled={g.done} checked={g.done || done.includes(i)} onChange={x => setDone(x.target.checked ? [...done, i] : done.filter(j => j !== i))} />{g.text}</label>)}</div></Field>
    <Field req label={L('ملاحظات المراجعة', 'Review notes')} className="mt-3" error={e?.field('note')}><textarea className="field" value={note} onChange={x => setNote(x.target.value)} /></Field>
    <label className="mt-3 flex items-center gap-2 text-[13.5px]"><input type="checkbox" checked={complete} onChange={x => setComplete(x.target.checked)} />{L('اكتملت الخطة (تُلغى الأنشطة غير المنجزة)', 'The plan is complete (unfinished activities are cancelled)')}</label>
    {e && !e.field('note') && <p className="text-danger text-[13px] mt-2">{e.message}</p>}
  </Modal>
}

function PlanForm({ d, plan, onClose }: { d: DevPage; plan: Plan | null; onClose: () => void }) {
  const L = useL(); const lang = useLang(); const n = useNames()
  const save = useSavePlan(plan?.id ?? null)
  const [v, setV] = useState<PlanInput>(plan ? { employee_id: plan.employee.id, title: plan.title, gaps: plan.gaps ?? '', goals: plan.goals, activities: plan.activities.filter(a => a.status !== 'cancelled').map(a => ({ id: a.id, title: a.title, kind: a.kind, owner_employee_id: a.owner?.id ?? '', due_on: a.due_on })) }
    : { employee_id: '', title: '', gaps: '', goals: [{ text: '' }], activities: [] })
  const e = save.error instanceof ApiError ? save.error : null
  const setAct = (i: number, p: Partial<PlanInput['activities'][number]>) => setV({ ...v, activities: v.activities.map((a, j) => j === i ? { ...a, ...p } : a) })
  return <Modal open onClose={onClose} width={760} title={plan ? L('تعديل خطة التطوير', 'Edit development plan') : L('خطة تطوير فردية', 'Individual development plan')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate(v, { onSuccess: () => { toast(L('حُفظت الخطة', 'Plan saved')); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      {!plan && <Field req label={L('الموظف', 'Employee')} error={e?.field('employee_id')}><select className="field" value={v.employee_id} onChange={x => setV({ ...v, employee_id: x.target.value ? Number(x.target.value) : '' })}><option value="">—</option>{d.employees.map(x => <option key={x.id} value={x.id}>{lang === 'ar' ? x.ar : x.en} · {x.code}</option>)}</select></Field>}
      <Field req label={L('عنوان الخطة', 'Plan title')} error={e?.field('title')} className={plan ? 'sm:col-span-2' : ''}><input className="field" value={v.title} onChange={x => setV({ ...v, title: x.target.value })} /></Field>
      <Field label={L('الفجوات', 'Gaps')} className="sm:col-span-2"><textarea className="field" value={v.gaps} onChange={x => setV({ ...v, gaps: x.target.value })} /></Field>
    </div>
    <Field div label={L('الأهداف', 'Goals')} className="mt-3" error={e?.field('goals')}><div className="space-y-2">{v.goals.map((g, i) => <div key={i} className="flex gap-2"><input className="field flex-1 min-w-0" value={g.text} onChange={x => setV({ ...v, goals: v.goals.map((y, j) => j === i ? { ...y, text: x.target.value } : y) })} />
      {v.goals.length > 1 && <button className="p-2 text-ink-3 hover:text-danger" aria-label={L('حذف', 'Remove')} onClick={() => setV({ ...v, goals: v.goals.filter((_, j) => j !== i) })}><Trash2 size={15} /></button>}</div>)}
      {v.goals.length < 10 && <Btn size="sm" icon={<Plus size={14} />} onClick={() => setV({ ...v, goals: [...v.goals, { text: '' }] })}>{L('هدف', 'Goal')}</Btn>}</div></Field>
    <Field div label={L('الأنشطة (مسؤول وموعد)', 'Activities (owner and date)')} className="mt-3"><div className="space-y-2">{v.activities.map((a, i) => <div key={a.id ?? `n${i}`} className="rounded-xl border border-line-2 p-2 grid sm:grid-cols-[1fr_130px] gap-2">
      <input className="field" placeholder={L('النشاط', 'Activity')} value={a.title} onChange={x => setAct(i, { title: x.target.value })} />
      <select className="field" value={a.kind} onChange={x => setAct(i, { kind: x.target.value as ActKind })}>{d.kinds.map(k => <option key={k} value={k}>{n.kind[k]}</option>)}</select>
      <select className="field" value={a.owner_employee_id} onChange={x => setAct(i, { owner_employee_id: x.target.value ? Number(x.target.value) : '' })}><option value="">{L('المسؤول: الموظف نفسه', 'Owner: the employee')}</option>{d.owners.map(o => <option key={o.id} value={o.id}>{lang === 'ar' ? o.ar : o.en} · {o.code}</option>)}</select>
      <div className="flex gap-2"><input className="field num flex-1 min-w-0" type="date" value={a.due_on} onChange={x => setAct(i, { due_on: x.target.value })} /><button className="p-2 text-ink-3 hover:text-danger" aria-label={L('حذف', 'Remove')} onClick={() => setV({ ...v, activities: v.activities.filter((_, j) => j !== i) })}><Trash2 size={15} /></button></div>
      {(e?.field(`activities.${i}.title`) || e?.field(`activities.${i}.due_on`)) && <p className="sm:col-span-2 text-[12px] text-danger">{e?.field(`activities.${i}.title`) ?? e?.field(`activities.${i}.due_on`)}</p>}</div>)}
      {v.activities.length < 20 && <Btn size="sm" icon={<Plus size={14} />} onClick={() => setV({ ...v, activities: [...v.activities, { title: '', kind: 'course', owner_employee_id: '', due_on: '' }] })}>{L('نشاط', 'Activity')}</Btn>}</div></Field>
    {e && !Object.keys(e.errors).length && <p className="text-danger text-[13px] mt-2">{e.message}</p>}
  </Modal>
}

function SuccessionList({ d, onEdit }: { d: DevPage; onEdit: (p: SPlan) => void }) {
  const L = useL(); const lang = useLang(); const n = useNames()
  const act = useSPlanAction(); const remove = useRemoveCandidate()
  const [nom, setNom] = useState<SPlan | null>(null); const [ass, setAss] = useState<Candidate | null>(null); const [rev, setRev] = useState<SPlan | null>(null)
  if (!d.succession.length) return <div className="bg-surface border border-line rounded-2xl"><Empty title={L('لا مناصب حرجة بعد', 'No critical positions yet')}>{d.can.manage ? L('حدّد المنصب الحرج والكفاءات المطلوبة، ثم فعّل الخطة ليرشّح المديرون.', 'Define the position and competencies, then activate it for managers to nominate.') : L('تظهر هنا خطط التعاقب الفعّالة لترشّح من فريقك.', 'Active succession plans appear here so you can nominate from your team.')}</Empty></div>
  return <>
    <div className="grid [grid-template-columns:repeat(auto-fill,minmax(340px,1fr))] gap-4">{d.succession.map(p => <div key={p.id} className={`rounded-2xl border bg-surface p-4 flex flex-col gap-3 ${p.gap ? 'border-warn/60' : 'border-line'}`}>
      <div className="flex items-start gap-2"><div className="flex-1 min-w-0"><div className="font-display font-bold text-[16px]">{bi(p.title, lang)}</div>
        <div className="text-[12.5px] text-ink-3">{p.department ? bi(p.department, lang) : L('كل الشركة', 'Company-wide')}{p.incumbent && <> · {L('الشاغل', 'Incumbent')}: {bi(p.incumbent.name, lang)}</>}</div></div>
        <div className="flex flex-col items-end gap-1"><SBadge s={p.status} /><Badge tone={p.risk === 'high' ? 'danger' : p.risk === 'medium' ? 'warn' : 'neutral'}>{L('الخطورة', 'Risk')}: {n.risk[p.risk]}</Badge></div></div>
      {p.gap && <div className="flex items-center gap-2 rounded-lg bg-warn/10 border border-warn/40 px-3 py-2 text-[12.5px]"><AlertTriangle size={14} className="text-warn shrink-0" />{L('فجوة: لا مرشح جاهز الآن لهذا المنصب.', 'Gap: no ready-now successor for this position.')}</div>}
      {p.reason && <p className="text-[13px] text-ink-2">{p.reason}</p>}
      <div className="flex flex-wrap gap-1">{p.competencies.map(c => <span key={c.id} className="rounded-md border border-line bg-sub/60 px-2 py-0.5 text-[11.5px]">{bi(c, lang)}</span>)}</div>
      <div className="flex gap-3 text-[12px]">{d.settings.readiness.map(r => <span key={r.key} className="flex items-center gap-1"><ReadyBadge r={r.key} d={d} /><span className="num font-bold">{p.counts[r.key]}</span></span>)}</div>
      <ul className="space-y-1.5">{p.candidates.map(c => <li key={c.id} className="rounded-xl border border-line-2 p-2.5 text-[13px]">
        <div className="flex flex-wrap items-center gap-2"><Avatar name={c.employee.name.ar} size={26} /><span className="flex-1 min-w-0 font-semibold truncate">{bi(c.employee.name, lang)}</span><ReadyBadge r={c.readiness} d={d} />
          {d.can.manage && <Btn size="sm" onClick={() => setAss(c)}>{L('تقييم', 'Assess')}</Btn>}
          {(d.can.manage || c.mine) && <button className="p-1 text-ink-3 hover:text-danger" aria-label={L('سحب', 'Withdraw')} onClick={() => remove.mutate(c.id, { onError: e => err(e, L('تعذّر السحب', 'Could not withdraw')) })}><Trash2 size={14} /></button>}</div>
        {c.gaps && <div className="mt-1 text-[12px] text-ink-2">{L('الفجوات', 'Gaps')}: {c.gaps}</div>}
        {c.plan && <div className="mt-1 text-[12px] text-brand flex items-center gap-1"><Sprout size={13} />{c.plan.title}</div>}
        {c.note && <div className="mt-1 text-[12px] text-ink-3">{c.note}{c.nominated_by && <> — {c.nominated_by}</>}</div>}</li>)}</ul>
      <div className="mt-auto flex flex-wrap gap-1.5 pt-1">
        {p.status !== 'draft' && d.can.create && <Btn size="sm" icon={<UserPlus size={14} />} onClick={() => setNom(p)}>{L('ترشيح', 'Nominate')}</Btn>}
        {d.can.manage && <Btn size="sm" icon={<Pencil size={14} />} onClick={() => onEdit(p)}>{L('تعديل', 'Edit')}</Btn>}
        {d.can.manage && p.status === 'draft' && <Btn size="sm" kind="primary" icon={<Play size={14} />} onClick={() => act.mutate({ id: p.id, action: 'activate' }, { onSuccess: () => toast(L('فُعّلت الخطة وأُبلغ المديرون', 'Activated — managers notified')), onError: e => err(e, L('تعذّر التفعيل', 'Could not activate')) })}>{L('تفعيل', 'Activate')}</Btn>}
        {d.can.manage && p.status !== 'draft' && <Btn size="sm" icon={<ClipboardCheck size={14} />} onClick={() => setRev(p)}>{L('مراجعة', 'Review')}</Btn>}
        {p.next_review && <span className="text-[12px] text-ink-3 self-center">{L('المراجعة', 'Review')} <span className="num">{fmtDate(p.next_review, lang)}</span></span>}
      </div>
    </div>)}</div>
    {nom && <NominateModal d={d} p={nom} onClose={() => setNom(null)} />}
    {ass && <AssessModal d={d} c={ass} onClose={() => setAss(null)} />}
    {rev && <DecisionDialog open onClose={() => setRev(null)} reasonRequired minLength={5} reasonLabel={L('ملخص المراجعة', 'Review summary')} title={L('مراجعة خطة التعاقب', 'Review the succession plan')} subject={bi(rev.title, lang)}
      confirmLabel={L('تسجيل المراجعة', 'Save review')} onConfirm={note => act.mutateAsync({ id: rev.id, action: 'review', note }).then(() => toast(L('سُجّلت المراجعة', 'Review saved')))} />}
  </>
}

function NominateModal({ d, p, onClose }: { d: DevPage; p: SPlan; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const nom = useNominate()
  const [emp, setEmp] = useState(''); const [r, setR] = useState<Readiness>('1y'); const [note, setNote] = useState('')
  const e = nom.error instanceof ApiError ? nom.error : null
  return <Modal open onClose={onClose} title={<>{L('ترشيح لمنصب', 'Nominate for')} {bi(p.title, lang)}</>}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={!emp || nom.isPending} onClick={() => nom.mutate({ id: p.id, employee_id: Number(emp), readiness: r, note: note || undefined }, { onSuccess: () => { toast(L('سُجّل الترشيح', 'Nomination saved')); onClose() } })}>{L('ترشيح', 'Nominate')}</Btn></>}>
    <Field req label={L('الموظف', 'Employee')} error={e?.field('employee_id')}><select className="field" value={emp} onChange={x => setEmp(x.target.value)}><option value="">—</option>{d.employees.filter(x => x.id !== p.incumbent_id).map(x => <option key={x.id} value={x.id}>{lang === 'ar' ? x.ar : x.en} · {x.code}</option>)}</select></Field>
    <Field div label={L('الجاهزية', 'Readiness')} className="mt-3"><Seg value={r} onChange={setR} options={d.settings.readiness.map(x => ({ v: x.key, label: bi(x, lang) }))} /></Field>
    <Field label={L('مبررات الترشيح', 'Why this person?')} className="mt-3"><textarea className="field" value={note} onChange={x => setNote(x.target.value)} /></Field>
    {e && !e.field('employee_id') && <p className="text-danger text-[13px] mt-2">{e.message}</p>}
  </Modal>
}

function AssessModal({ d, c, onClose }: { d: DevPage; c: Candidate; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const ass = useAssess()
  const [r, setR] = useState<Readiness>(c.readiness); const [gaps, setGaps] = useState(c.gaps ?? ''); const [plan, setPlan] = useState(false)
  const e = ass.error instanceof ApiError ? ass.error : null
  return <Modal open onClose={onClose} title={<>{L('تقييم المرشح', 'Assess the candidate')} — {bi(c.employee.name, lang)}</>}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={ass.isPending} onClick={() => ass.mutate({ id: c.id, readiness: r, gaps, create_plan: plan }, { onSuccess: () => { toast(plan ? L('حُفظ وأُنشئت خطة تطوير (مسودة)', 'Saved — development plan drafted') : L('حُفظ التقييم', 'Saved')); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <Field div label={L('الجاهزية', 'Readiness')}><Seg value={r} onChange={setR} options={d.settings.readiness.map(x => ({ v: x.key, label: bi(x, lang) }))} /></Field>
    <Field label={L('فجوات التطوير', 'Development gaps')} className="mt-3" error={e?.field('gaps')}><textarea className="field" value={gaps} onChange={x => setGaps(x.target.value)} /></Field>
    {c.plan ? <p className="mt-3 text-[13px] text-brand flex items-center gap-1"><Sprout size={14} />{L('مرتبط بخطة', 'Linked plan')}: {c.plan.title}</p>
      : <label className="mt-3 flex items-center gap-2 text-[13.5px]"><input type="checkbox" checked={plan} onChange={x => setPlan(x.target.checked)} />{L('إنشاء خطة تطوير مرتبطة (مسودة) من هذه الفجوات', 'Draft a linked development plan from these gaps')}</label>}
    {e && !e.field('gaps') && <p className="text-danger text-[13px] mt-2">{e.message}</p>}
  </Modal>
}

function SPlanForm({ d, plan, onClose }: { d: DevPage; plan: SPlan | null; onClose: () => void }) {
  const L = useL(); const lang = useLang(); const n = useNames()
  const save = useSaveSPlan(plan?.id ?? null)
  const [v, setV] = useState<SPlanInput>(plan ? { title_ar: plan.title_ar, title_en: plan.title_en, department_id: plan.department_id ?? '', incumbent_id: plan.incumbent_id ?? '', risk: plan.risk, reason: plan.reason ?? '', competencies: plan.competency_ids }
    : { title_ar: '', title_en: '', department_id: '', incumbent_id: '', risk: 'medium', reason: '', competencies: [] })
  const e = save.error instanceof ApiError ? save.error : null
  return <Modal open onClose={onClose} width={680} title={plan ? L('تعديل المنصب الحرج', 'Edit critical position') : L('منصب حرج', 'Critical position')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate(v, { onSuccess: () => { toast(L('حُفظ المنصب', 'Saved')); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      <Field req label={L('المنصب بالعربية', 'Position (Arabic)')} error={e?.field('title_ar')}><input className="field" value={v.title_ar} onChange={x => setV({ ...v, title_ar: x.target.value })} /></Field>
      <Field req label={L('المنصب بالإنكليزية', 'Position (English)')} error={e?.field('title_en')}><input className="field ltr" value={v.title_en} onChange={x => setV({ ...v, title_en: x.target.value })} /></Field>
      <Field label={L('الإدارة', 'Department')}><select className="field" value={v.department_id} onChange={x => setV({ ...v, department_id: x.target.value ? Number(x.target.value) : '' })}><option value="">{L('كل الشركة', 'Company-wide')}</option>{d.departments.map(x => <option key={x.id} value={x.id}>{bi(x, lang)}</option>)}</select></Field>
      <Field label={L('شاغل المنصب حالياً', 'Current incumbent')}><select className="field" value={v.incumbent_id} onChange={x => setV({ ...v, incumbent_id: x.target.value ? Number(x.target.value) : '' })}><option value="">—</option>{d.owners.map(o => <option key={o.id} value={o.id}>{lang === 'ar' ? o.ar : o.en} · {o.code}</option>)}</select></Field>
    </div>
    <Field div label={L('خطورة شغور المنصب', 'Vacancy risk')} className="mt-3"><Seg value={v.risk} onChange={x => setV({ ...v, risk: x })} options={(['low', 'medium', 'high'] as const).map(k => ({ v: k, label: n.risk[k] }))} /></Field>
    <Field label={L('سبب اعتباره حرجاً', 'Why it is critical')} className="mt-3"><textarea className="field" value={v.reason} onChange={x => setV({ ...v, reason: x.target.value })} /></Field>
    <Field div req label={L('الكفاءات المطلوبة', 'Required competencies')} className="mt-3" error={e?.field('competencies')}><div className="flex flex-wrap gap-2">{d.competencies.map(c => { const on = v.competencies.includes(c.id)
      return <button key={c.id} type="button" onClick={() => setV({ ...v, competencies: on ? v.competencies.filter(x => x !== c.id) : [...v.competencies, c.id] })} className={`rounded-lg border px-3 h-9 text-[13px] ${on ? 'border-brand bg-brand-soft text-brand font-semibold' : 'border-line'}`}>{bi(c, lang)}</button> })}</div></Field>
  </Modal>
}

function SettingsModal({ s, onClose }: { s: DevSettings; onClose: () => void }) {
  const L = useL()
  const save = useSaveDevSettings()
  const [v, setV] = useState(s)
  const num = (k: 'review_months' | 'succession_review_months', label: string) => <div className="flex items-center justify-between gap-3 text-[14px]"><span>{label}</span><input className="field num !w-24" type="number" value={v[k]} onChange={x => setV({ ...v, [k]: Number(x.target.value) })} /></div>
  return <Modal open onClose={onClose} title={L('إعدادات التعاقب والتطوير', 'Succession & development settings')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate(v, { onSuccess: () => { toast(L('حُفظت الإعدادات', 'Settings saved')); onClose() }, onError: e => err(e, L('تعذّر الحفظ', 'Could not save')) })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="space-y-2">
      {num('review_months', L('مراجعة خطة التطوير كل (شهر)', 'Review development plans every (months)'))}
      {num('succession_review_months', L('مراجعة خطط التعاقب كل (شهر)', 'Review succession plans every (months)'))}
    </div>
    <div className="mt-4 text-[13px] font-semibold mb-1">{L('مقياس الجاهزية', 'Readiness scale')}</div>
    <div className="space-y-2">{v.readiness.map((r, i) => <div key={r.key} className="grid grid-cols-2 gap-2"><input className="field" value={r.ar} onChange={x => setV({ ...v, readiness: v.readiness.map((y, j) => j === i ? { ...y, ar: x.target.value } : y) })} />
      <input className="field ltr" value={r.en} onChange={x => setV({ ...v, readiness: v.readiness.map((y, j) => j === i ? { ...y, en: x.target.value } : y) })} /></div>)}</div>
  </Modal>
}
