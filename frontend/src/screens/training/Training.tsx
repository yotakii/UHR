import { useState } from 'react'
import { AlertTriangle, Check, Download, GraduationCap, Plus, Search, Send, Settings2, X } from 'lucide-react'
import { useL, useLang, bi, fmtDate, money } from '../../lib/store'
import { navigate, setQuery, useRoute } from '../../lib/router'
import { download } from '../../lib/admin'
import { AUDIENCE, CAUSE, DELIVERY, EVIDENCE, N_STATUS, P_STATUS, PATH, S_STATUS, useCampaignForm, useCampaigns, useMatrix, useNeeds, usePlan, usePlans, useTd, useTdAct,
  type Can, type Lookups, type Need, type NeedGroup, type Path, type PlanItem } from '../../lib/training'
import { Badge, Btn, Empty, Field, Modal, PageHead, Panel, Progress, Tabs, toast } from '../../ui/kit'
import { DecisionDialog } from '../../ui/decision'
import { StepsPath } from '../requests/parts'
import { EmpSelect, LevelBar, PathBadge, Spin, Stat, StatusBadge, errOf, fail } from './parts'
import { CalendarTab, SessionsTab } from './TdSessions'
import { CbpTab, CertificatesTab, CommitmentsTab, CompetenciesTab, ImpactsTab, ProgramsTab, ProvidersTab, QualificationsTab, ReportsTab } from './TdLibrary'

/**
 * «التأهيل والتدريب والتطوير» (الدفعة 23 — SRS التدريب TD-00…13): تحليل الاحتياجات بالأدلة وتحليل السبب، الخطة السنوية بالاعتماد،
 * التنفيذ والترشيح والتعهدات، الحضور والنتائج، التقييم على أربعة مستويات، الشهادات، التأهيل للموظفين الجدد، وبناء القدرات المؤسسية.
 */
const TABS = ['home', 'needs', 'plan', 'sessions', 'calendar', 'programs', 'providers', 'competencies', 'certificates', 'qualifications', 'impacts', 'commitments', 'cbp', 'reports'] as const
type Tab = typeof TABS[number]

export function TrainingApp() {
  const L = useL()
  const route = useRoute(); const q = route.query
  const tab = (TABS as readonly string[]).includes(q.tab ?? '') ? q.tab as Tab : 'home'
  const year = Number(q.year) || new Date().getFullYear()
  const td = useTd(year)
  if (!td.data) return <Spin />
  const { lookups: lk, can, pending } = td.data
  return <div className="max-w-[1240px] mx-auto">
    <PageHead title={L('التأهيل والتدريب والتطوير', 'Qualification, training & development')}
      sub={L('من الاحتياج المثبت بالأدلة إلى الخطة المعتمدة، ثم التنفيذ والقياس على أربعة مستويات وأثر التدريب في العمل.', 'From an evidenced need to an approved plan, then delivery and four-level evaluation of the impact on work.')}
      actions={can.settings && <Btn icon={<Settings2 size={15} />} onClick={() => navigate('/hr/settings/training')}>{L('الإعدادات', 'Settings')}</Btn>} />
    <Tabs value={tab} onChange={v => setQuery({ tab: v === 'home' ? undefined : v, s: undefined, plan: undefined, view: undefined })} tabs={[
      { v: 'home', label: L('اللوحة', 'Overview') }, { v: 'needs', label: L('الاحتياجات', 'Needs'), count: pending.needs || undefined }, { v: 'plan', label: L('الخطة السنوية', 'Annual plan') },
      { v: 'sessions', label: L('التنفيذ', 'Delivery'), count: (pending.nominations + pending.commitments) || undefined }, { v: 'calendar', label: L('التقويم', 'Calendar') },
      { v: 'programs', label: L('البرامج', 'Programmes') }, { v: 'providers', label: L('المدربون والمراكز', 'Trainers & centres') }, { v: 'competencies', label: L('الكفاءات', 'Competencies') },
      { v: 'certificates', label: L('الشهادات', 'Certificates'), count: pending.certificates || undefined }, { v: 'qualifications', label: L('التأهيل', 'Qualification'), count: pending.qualifications || undefined },
      { v: 'impacts', label: L('أثر التدريب', 'Impact'), count: pending.impacts || undefined }, { v: 'commitments', label: L('التعهدات', 'Commitments'), count: pending.breached || undefined },
      { v: 'cbp', label: L('بناء القدرات', 'Capacity building') }, { v: 'reports', label: L('التقارير', 'Reports') }]} />
    <div className="mt-4">
      {tab === 'home' ? <Home year={year} /> : tab === 'needs' ? <NeedsTab lk={lk} can={can} /> : tab === 'plan' ? <PlanTab lk={lk} can={can} /> : tab === 'sessions' ? <SessionsTab lk={lk} can={can} />
        : tab === 'calendar' ? <CalendarTab year={year} /> : tab === 'programs' ? <ProgramsTab lk={lk} can={can} /> : tab === 'providers' ? <ProvidersTab can={can} /> : tab === 'competencies' ? <CompetenciesTab can={can} />
        : tab === 'certificates' ? <CertificatesTab lk={lk} can={can} /> : tab === 'qualifications' ? <QualificationsTab lk={lk} can={can} /> : tab === 'impacts' ? <ImpactsTab /> : tab === 'commitments' ? <CommitmentsTab can={can} />
        : tab === 'cbp' ? <CbpTab lk={lk} /> : <ReportsTab year={year} lk={lk} />}
    </div>
  </div>
}

function Home({ year }: { year: number }) {
  const L = useL(); const lang = useLang()
  const { data } = useTd(year)
  if (!data) return null
  const pr = data.plan?.progress
  const alertText: Record<string, [string, string, string]> = { certs: ['شهادات تنتهي خلال 30 يوماً', 'Certificates expiring within 30 days', 'certificates'], review: ['برامج تحتاج مراجعة سنوية', 'Programmes due for the yearly review', 'programs'] }
  const todo: [string, string, number, string][] = [
    ['احتياجات بانتظار المراجعة', 'Needs awaiting review', data.pending.needs, 'needs'], ['ترشيحات بانتظار التأكيد', 'Nominations awaiting confirmation', data.pending.nominations, 'sessions'],
    ['تعهدات لم تُوقَّع', 'Unsigned commitments', data.pending.commitments, 'sessions'], ['قياسات أثر مستحقة', 'Impact follow-ups due', data.pending.impacts, 'impacts'],
    ['شهادات خارجية للتحقق', 'External certificates to verify', data.pending.certificates, 'certificates'], ['تعهدات مُخَلّ بها', 'Breached commitments', data.pending.breached, 'commitments'],
    ['دورات منفذة لم تُغلق', 'Delivered sessions not closed', data.pending.closable, 'sessions'], ['برامج تأهيل جارية', 'Qualification programmes in progress', data.pending.qualifications, 'qualifications']]
  return <div className="space-y-4">
    <div className="flex items-center gap-2"><span className="text-[13px] text-ink-3">{L('السنة', 'Year')}</span>
      <select className="field !w-28" value={year} onChange={e => setQuery({ year: e.target.value })} aria-label={L('السنة', 'Year')}>{[year - 1, year, year + 1].map(y => <option key={y} value={y}>{y}</option>)}</select></div>
    {pr ? <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(180px,1fr))]">
      <Stat label={L('بنود الخطة المنفذة', 'Plan items delivered')} value={`${pr.done}/${pr.items}`} sub={<Progress value={pr.items ? pr.done * 100 / pr.items : 0} />} />
      <Stat label={L('الصرف من الميزانية', 'Budget spent')} value={money(pr.actual_cost, data.plan!.currency, lang)} sub={`${L('من', 'of')} ${money(pr.budget, data.plan!.currency, lang)}`} />
      <Stat label={L('المتدربون', 'Trainees')} value={`${pr.trained}/${pr.planned_trainees}`} />
      <Stat label={L('ساعات التدريب', 'Training hours')} value={pr.hours} />
      <Stat label={L('حالة الخطة', 'Plan status')} value={<StatusBadge map={P_STATUS} s={data.plan!.status} />} />
    </div> : <Panel><Empty title={L(`لا توجد خطة لسنة ${year}`, `No plan for ${year}`)} action={<Btn kind="primary" icon={<Plus size={15} />} onClick={() => setQuery({ tab: 'plan' })}>{L('إنشاء الخطة', 'Create the plan')}</Btn>}>
      {L('ابدأ بتحليل الاحتياجات أو بحملة جمع الاحتياجات من الإدارات، ثم حوّل الاحتياجات المعتمدة إلى بنود في الخطة.', 'Start with the needs analysis or a collection campaign, then turn the approved needs into plan items.')}</Empty></Panel>}
    {data.alerts.length > 0 && <div className="space-y-2">{data.alerts.map(a => <button key={a.key} onClick={() => setQuery({ tab: alertText[a.key][2] })} className="w-full text-start flex items-center gap-2 rounded-xl border border-[color-mix(in_oklab,var(--warn)_40%,transparent)] bg-[color-mix(in_oklab,var(--warn)_10%,transparent)] px-3 py-2 text-[13px]">
      <AlertTriangle size={15} className="text-[var(--warn)]" />{L(alertText[a.key][0], alertText[a.key][1])}: <b className="num">{a.n}</b></button>)}</div>}
    <div className="grid gap-4 lg:grid-cols-2">
      <Panel title={L('بانتظارك', 'Waiting for you')}>
        {todo.filter(t => t[2] > 0).length === 0 ? <div className="text-[13px] text-ink-3">{L('لا يوجد ما ينتظرك الآن.', 'Nothing is waiting for you.')}</div>
          : <ul className="divide-y divide-line">{todo.filter(t => t[2] > 0).map(t => <li key={t[0]}><button className="w-full flex items-center justify-between py-2 text-[13.5px] hover:text-brand" onClick={() => setQuery({ tab: t[3] })}>
            <span>{L(t[0], t[1])}</span><Badge tone="brass">{t[2]}</Badge></button></li>)}</ul>}
      </Panel>
      <Panel title={L('دورات هذا الأسبوع', 'This week')}>
        {data.week.length === 0 ? <div className="text-[13px] text-ink-3">{L('لا دورات هذا الأسبوع.', 'No sessions this week.')}</div>
          : <ul className="divide-y divide-line">{data.week.map(s => <li key={s.id}><button className="w-full text-start py-2" onClick={() => setQuery({ tab: 'sessions', s: String(s.id) })}>
            <div className="flex items-center justify-between gap-2"><span className="font-medium">{s.title}</span><StatusBadge map={S_STATUS} s={s.status} /></div>
            <div className="text-[12px] text-ink-3">{fmtDate(s.starts_on, lang)} — {fmtDate(s.ends_on, lang)}{s.place ? ` · ${s.place}` : ''}{s.trainer ? ` · ${bi(s.trainer, lang)}` : ''}</div></button></li>)}</ul>}
      </Panel>
    </div>
    <Panel title={L('المسارات الثلاثة', 'The three paths')}>
      <div className="grid gap-3 sm:grid-cols-3">{(Object.keys(PATH) as Path[]).map(p => <div key={p} className="rounded-xl border border-line p-3">
        <div className="flex items-center gap-2 font-semibold"><span className="size-2.5 rounded-full" style={{ background: PATH[p][2] }} />{L(PATH[p][0], PATH[p][1])}</div>
        <div className="text-[12.5px] text-ink-3 mt-1">{p === 'qualification' ? L('للموظف الجديد أو المنقول: محطات في الأقسام بمدرب لكل محطة وتقييم يفضي للتثبيت.', 'For new or transferred staff: stations in departments, a trainer per station and an evaluation leading to confirmation.')
          : p === 'training' ? L('سد فجوة مهارة أو معرفة مثبتة بأدلة، في برنامج له أهداف وقياس قبلي وبعدي.', 'Closing an evidenced skill or knowledge gap in a programme with objectives and pre/post measurement.')
          : L('رفع أداء من يؤدي عمله إلى مستوى أعلى أو تحضيره لدور قادم، مرتبط بخطط التطوير والتعاقب.', 'Raising the performance of those already doing well or preparing them for a next role, linked to development and succession plans.')}</div>
        {pr && <div className="text-[12px] mt-2">{L('تكلفة بنود الخطة:', 'Plan items cost:')} <b className="num">{money(pr.by_path?.[p] ?? 0, data.plan!.currency, lang)}</b></div>}</div>)}</div>
    </Panel>
  </div>
}

// ———————————————— الاحتياجات ————————————————

function NeedsTab({ lk, can }: { lk: Lookups; can: Can }) {
  const L = useL()
  const q = useRoute().query
  const view = q.view === 'matrix' ? 'matrix' : q.view === 'campaigns' ? 'campaigns' : 'list'
  return <>
    <div className="mb-3"><Tabs value={view} onChange={v => setQuery({ view: v === 'list' ? undefined : v })} tabs={[{ v: 'list', label: L('سجل الاحتياجات', 'Needs register') }, { v: 'matrix', label: L('مصفوفة الكفاءات', 'Competency matrix') }, { v: 'campaigns', label: L('حملات الجمع', 'Collection campaigns') }]} /></div>
    {view === 'matrix' ? <Matrix lk={lk} /> : view === 'campaigns' ? <Campaigns lk={lk} can={can} /> : <NeedsList lk={lk} can={can} />}
  </>
}

function NeedsList({ lk, can }: { lk: Lookups; can: Can }) {
  const L = useL(); const lang = useLang()
  const q = useRoute().query
  const f = { status: q.status ?? 'open', path: q.path, source: q.source, department: q.department, q: q.q }
  const { data } = useNeeds(f)
  const [edit, setEdit] = useState<Need | 'new' | null>(null)
  const [decide, setDecide] = useState<{ n: Need; a: 'approve' | 'reject' } | null>(null)
  const [group, setGroup] = useState<NeedGroup | null>(null)
  const act = useTdAct()
  const SRC: Record<string, [string, string]> = { request: ['طلب', 'Request'], gap: ['فجوة كفاءة', 'Competency gap'], kpi: ['مؤشر أداء', 'KPI'], event: ['حدث', 'Event'], campaign: ['حملة', 'Campaign'], idp: ['خطة تطوير فردية', 'IDP'], appraisal: ['تقييم أداء', 'Appraisal'] }
  return <>
    <div className="flex flex-wrap gap-2 mb-3">
      <div className="relative w-full sm:w-56"><Search size={15} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" /><input className="field ps-9" placeholder={L('عنوان أو رقم…', 'Title or ref…')} defaultValue={q.q ?? ''} onKeyDown={e => { if (e.key === 'Enter') setQuery({ q: (e.target as HTMLInputElement).value || undefined }) }} /></div>
      <select className="field !w-[calc(50%-4px)] sm:!w-44" value={f.status} onChange={e => setQuery({ status: e.target.value })} aria-label={L('الحالة', 'Status')}>
        <option value="open">{L('بانتظار المراجعة', 'Awaiting review')}</option><option value="">{L('كل الحالات', 'All statuses')}</option>{Object.entries(N_STATUS).map(([k, v]) => <option key={k} value={k}>{L(v[0], v[1])}</option>)}</select>
      <select className="field !w-[calc(50%-4px)] sm:!w-36" value={q.path ?? ''} onChange={e => setQuery({ path: e.target.value || undefined })} aria-label={L('المسار', 'Path')}><option value="">{L('كل المسارات', 'All paths')}</option>{(Object.keys(PATH) as Path[]).map(p => <option key={p} value={p}>{L(PATH[p][0], PATH[p][1])}</option>)}</select>
      <select className="field !w-[calc(50%-4px)] sm:!w-40" value={q.source ?? ''} onChange={e => setQuery({ source: e.target.value || undefined })} aria-label={L('المصدر', 'Source')}><option value="">{L('كل المصادر', 'All sources')}</option>{Object.entries(SRC).map(([k, v]) => <option key={k} value={k}>{L(v[0], v[1])}</option>)}</select>
      <select className="field !w-[calc(50%-4px)] sm:!w-44" value={q.department ?? ''} onChange={e => setQuery({ department: e.target.value || undefined })} aria-label={L('الإدارة', 'Department')}><option value="">{L('كل الإدارات', 'All departments')}</option>{lk.departments.map(d => <option key={d.id} value={d.id}>{bi(d, lang)}</option>)}</select>
      <div className="flex-1" />
      <Btn kind="primary" icon={<Plus size={15} />} onClick={() => setEdit('new')}>{L('احتياج جديد', 'New need')}</Btn>
    </div>
    {data && data.groups.length > 0 && <Panel className="mb-3" title={L('احتياجات معتمدة جاهزة للخطة', 'Approved needs ready for the plan')} sub={L('مجمّعة حسب البرنامج أو الكفاءة ومرتبة بالأولوية — أضف كل مجموعة كبند واحد في الخطة.', 'Grouped by programme or competency and ranked by priority — add each group as one plan item.')}>
      <div className="grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]">{data.groups.map(g => <div key={g.key} className="rounded-xl border border-line p-3">
        <div className="flex items-start justify-between gap-2"><span className="font-medium">{g.title}</span><PathBadge p={g.path} /></div>
        <div className="text-[12px] text-ink-3 mt-1">{L(`${g.count} احتياج · أولوية ${g.score}`, `${g.count} needs · priority ${g.score}`)}{g.cost ? ` · ${g.cost}` : ''}</div>
        {can.create && <Btn size="sm" className="mt-2" icon={<Plus size={14} />} onClick={() => setGroup(g)}>{L('إضافة للخطة', 'Add to the plan')}</Btn>}</div>)}</div></Panel>}
    {!data ? <Spin /> : data.data.length === 0 ? <Panel><Empty title={L('لا توجد احتياجات', 'No needs')}>{L('تُقترح الاحتياجات آلياً من فجوات الكفاءات ونتائج تقييم الأداء وخطط التطوير الفردية، أو تُضاف يدوياً أو بحملة جمع.', 'Needs are suggested from competency gaps, appraisal results and IDPs, or added by hand or through a campaign.')}</Empty></Panel>
      : <Panel pad={false}><table className="tbl"><thead><tr><th>{L('الاحتياج', 'Need')}</th><th className="hidden md:table-cell">{L('لمن', 'For')}</th><th className="hidden lg:table-cell">{L('الكفاءة', 'Competency')}</th><th className="hidden sm:table-cell">{L('الأولوية', 'Priority')}</th><th>{L('الحالة', 'Status')}</th><th /></tr></thead>
        <tbody>{data.data.map(n => <tr key={n.id} className="click" onClick={() => setEdit(n)}>
          <td><div className="font-medium">{n.title}</div><div className="text-[12px] text-ink-3 flex flex-wrap items-center gap-1.5"><span className="ltr">{n.ref}</span><PathBadge p={n.path} />{SRC[n.source] ? L(SRC[n.source][0], SRC[n.source][1]) : n.source}</div></td>
          <td className="hidden md:table-cell text-[13px]">{n.employee ? bi(n.employee, lang) : n.employees.length ? L(`${n.employees.length} موظفين`, `${n.employees.length} staff`) : n.department ? bi(n.department, lang) : L('الشركة', 'Company')}</td>
          <td className="hidden lg:table-cell text-[13px]">{n.competency ? <><div>{bi(n.competency, lang)}</div><LevelBar current={n.current_level} required={n.required_level} /></> : '—'}</td>
          <td className="hidden sm:table-cell num">{n.score}</td>
          <td><StatusBadge map={N_STATUS} s={n.status} /></td>
          <td onClick={e => e.stopPropagation()} className="whitespace-nowrap">{['suggested', 'submitted'].includes(n.status) && can.manage && <span className="inline-flex gap-1">
            <Btn size="sm" kind="ghost" icon={<Check size={14} />} aria-label={L('اعتماد', 'Approve')} onClick={() => setDecide({ n, a: 'approve' })} />
            <Btn size="sm" kind="ghost" icon={<X size={14} />} aria-label={L('رفض', 'Reject')} onClick={() => setDecide({ n, a: 'reject' })} /></span>}</td></tr>)}</tbody></table></Panel>}
    {edit && <NeedForm lk={lk} n={edit === 'new' ? null : edit} onClose={() => setEdit(null)} />}
    {group && <GroupToPlan g={group} onClose={() => setGroup(null)} />}
    <DecisionDialog open={!!decide} onClose={() => setDecide(null)} tone={decide?.a === 'reject' ? 'danger' : 'primary'} reasonRequired={decide?.a === 'reject'} minLength={5}
      title={decide?.a === 'approve' ? L('اعتماد الاحتياج', 'Approve the need') : L('رفض الاحتياج', 'Reject the need')} subject={decide?.n.title}
      confirmLabel={decide?.a === 'approve' ? L('اعتماد', 'Approve') : L('رفض', 'Reject')}
      onConfirm={r => act.mutateAsync({ path: `needs/${decide!.n.id}/decide`, v: { action: decide!.a, note: r || null } }).then(x => {
        const st = (x.data as { status?: string } | undefined)?.status
        toast(st === 'not_training' ? L('السبب ليس نقص معرفة — أُغلق كتوصية إدارية ولم يدخل الخطة.', 'The cause is not knowledge — closed as a management recommendation, not added to the plan.') : L('تم الحفظ', 'Saved'))
      })} />
  </>
}

function NeedForm({ lk, n, onClose }: { lk: Lookups; n: Need | null; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const act = useTdAct()
  const [v, setV] = useState({
    title: n?.title ?? '', path: (n?.path ?? 'training') as Path, employee_id: n?.employee?.id ?? null as number | null, employees: n?.employees ?? [] as number[], department_id: n?.department_id ?? null as number | null,
    competency_id: n?.competency_id ?? null as number | null, current_level: n?.current_level ?? null as number | null, required_level: n?.required_level ?? null as number | null, level: n?.level ?? 'individual',
    priority: n?.priority ?? 'normal', factors: n?.factors ?? { impact: 3, gap: 3, affected: 3, urgency: 3 }, cause: n?.cause ?? '', evidence: n?.evidence ?? [] as string[], evidence_override: n?.evidence_override ?? '',
    objective: n?.objective ?? { behavior: '', condition: '', criterion: '' } as Record<string, string>, note: n?.note ?? '', program_id: n?.program_id ?? null as number | null, estimated_cost: n?.estimated_cost ?? null as number | null, preferred_date: n?.preferred_date ?? '',
  })
  const set = (o: Partial<typeof v>) => setV(p => ({ ...p, ...o }))
  const e = errOf(act.error)
  const locked = !!n && !['suggested', 'submitted', 'rejected', 'not_training'].includes(n.status)
  const minEv = lk.cfg.min_evidence
  const save = () => act.mutate({ path: n ? `needs/${n.id}` : 'needs', method: n ? 'PUT' : 'POST', v: { ...v, cause: v.cause || null, evidence_override: v.evidence_override || null, preferred_date: v.preferred_date || null } }, { onSuccess: () => { toast(L('تم الحفظ', 'Saved')); onClose() } })
  const Lv = ({ k }: { k: 'current_level' | 'required_level' }) => <select className="field" value={v[k] ?? ''} onChange={x => set({ [k]: x.target.value ? Number(x.target.value) : null })}><option value="">—</option>{[1, 2, 3, 4, 5].map(i => <option key={i} value={i}>{i}</option>)}</select>
  return <Modal open onClose={onClose} width={760} title={n ? `${n.ref} — ${n.title}` : L('احتياج تدريبي جديد', 'New training need')}
    footer={!locked && <><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={act.isPending || v.title.trim().length < 3} onClick={save}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid gap-3 sm:grid-cols-2">
      <Field label={L('العنوان', 'Title')} req error={e?.field('title')} className="sm:col-span-2"><input className="field" value={v.title} onChange={x => set({ title: x.target.value })} disabled={locked} /></Field>
      <Field label={L('المسار', 'Path')}><select className="field" value={v.path} onChange={x => set({ path: x.target.value as Path })} disabled={locked}>{(Object.keys(PATH) as Path[]).map(p => <option key={p} value={p}>{L(PATH[p][0], PATH[p][1])}</option>)}</select></Field>
      <Field label={L('المستوى', 'Level')}><select className="field" value={v.level} onChange={x => set({ level: x.target.value })} disabled={locked}>
        <option value="individual">{L('فردي', 'Individual')}</option><option value="job">{L('وظيفي (مجموعة)', 'Job (group)')}</option><option value="company">{L('على مستوى الشركة', 'Company-wide')}</option></select></Field>
      {v.level === 'individual' ? <Field label={L('الموظف', 'Employee')}><EmpSelect lk={lk} value={v.employee_id ? [v.employee_id] : []} onChange={ids => set({ employee_id: ids[0] ?? null })} label={L('الموظف', 'Employee')} /></Field>
        : v.level === 'job' ? <Field label={L('الموظفون', 'Employees')} className="sm:col-span-2" div><EmpSelect multiple lk={lk} value={v.employees} onChange={ids => set({ employees: ids })} /></Field> : null}
      <Field label={L('الإدارة', 'Department')}><select className="field" value={v.department_id ?? ''} onChange={x => set({ department_id: x.target.value ? Number(x.target.value) : null })} disabled={locked}><option value="">—</option>{lk.departments.map(d => <option key={d.id} value={d.id}>{bi(d, lang)}</option>)}</select></Field>
      <Field label={L('الكفاءة', 'Competency')}><select className="field" value={v.competency_id ?? ''} onChange={x => set({ competency_id: x.target.value ? Number(x.target.value) : null })} disabled={locked}><option value="">—</option>{lk.competencies.map(c => <option key={c.id} value={c.id}>{bi(c, lang)}</option>)}</select></Field>
      <div className="grid grid-cols-2 gap-2"><Field label={L('المستوى الحالي', 'Current level')}><Lv k="current_level" /></Field><Field label={L('المستوى المطلوب', 'Required level')}><Lv k="required_level" /></Field></div>
      <Field label={L('البرنامج المقترح', 'Suggested programme')}><select className="field" value={v.program_id ?? ''} onChange={x => set({ program_id: x.target.value ? Number(x.target.value) : null })} disabled={locked}><option value="">—</option>{lk.programs.map(p => <option key={p.id} value={p.id}>{p.code} — {bi(p, lang)}</option>)}</select></Field>
      <Field label={L('التكلفة التقديرية', 'Estimated cost')}><input type="number" min={0} className="field num" value={v.estimated_cost ?? ''} onChange={x => set({ estimated_cost: x.target.value ? Number(x.target.value) : null })} disabled={locked} /></Field>
      <Field label={L('التوقيت المفضل', 'Preferred timing')}><input className="field" value={v.preferred_date} onChange={x => set({ preferred_date: x.target.value })} placeholder={L('مثال: الربع الثاني', 'e.g. Q2')} disabled={locked} /></Field>
    </div>
    <div className="mt-4 rounded-xl border border-line p-3">
      <div className="font-semibold text-[13.5px]">{L('تحليل السبب — لماذا لا يُنجز العمل كما يجب؟', 'Cause analysis — why is the work not done as it should be?')}</div>
      <div className="text-[12px] text-ink-3 mb-2">{L('التدريب يحل نقص المعرفة أو المهارة فقط. الأسباب الأخرى تُغلق كتوصية إدارية ولا تدخل الخطة.', 'Training only fixes missing knowledge or skill. Other causes are closed as management recommendations.')}</div>
      <div className="grid gap-1.5 sm:grid-cols-2">{Object.entries(CAUSE).map(([k, t]) => <label key={k} className={`flex items-center gap-2 rounded-lg border px-2.5 py-2 text-[13px] ${v.cause === k ? 'border-brand bg-[color-mix(in_oklab,var(--brand)_8%,transparent)]' : 'border-line'}`}>
        <input type="radio" name="cause" checked={v.cause === k} onChange={() => set({ cause: k })} disabled={locked} />{L(t[0], t[1])}</label>)}</div>
      {e?.field('cause') && <div className="text-[12px] text-danger mt-1">{e.field('cause')}</div>}
    </div>
    <div className="mt-3 rounded-xl border border-line p-3">
      <div className="font-semibold text-[13.5px]">{L(`الأدلة (${minEv} على الأقل)`, `Evidence (at least ${minEv})`)}</div>
      <div className="flex flex-wrap gap-2 mt-2">{Object.entries(EVIDENCE).map(([k, t]) => <label key={k} className="flex items-center gap-1.5 text-[13px]"><input type="checkbox" checked={v.evidence.includes(k)} disabled={locked}
        onChange={x => set({ evidence: x.target.checked ? [...v.evidence, k] : v.evidence.filter(i => i !== k) })} />{L(t[0], t[1])}</label>)}</div>
      {v.evidence.length < minEv && <Field label={L('مبرر الاكتفاء بأدلة أقل', 'Justification for fewer pieces of evidence')} className="mt-2" error={e?.field('evidence') ?? e?.field('evidence_override')}><input className="field" value={v.evidence_override} onChange={x => set({ evidence_override: x.target.value })} disabled={locked} /></Field>}
    </div>
    <div className="mt-3 rounded-xl border border-line p-3">
      <div className="font-semibold text-[13.5px]">{L('الهدف السلوكي', 'Behavioural objective')}</div>
      <div className="grid gap-2 sm:grid-cols-3 mt-2">
        {([['behavior', 'السلوك المطلوب', 'Behaviour'], ['condition', 'الظرف', 'Condition'], ['criterion', 'معيار القبول', 'Criterion']] as const).map(([k, a, b]) => <Field key={k} label={L(a, b)}><input className="field" value={v.objective[k] ?? ''} disabled={locked} onChange={x => set({ objective: { ...v.objective, [k]: x.target.value } })} /></Field>)}
      </div>
    </div>
    <div className="mt-3 rounded-xl border border-line p-3">
      <div className="font-semibold text-[13.5px]">{L('عوامل الأولوية (1–5)', 'Priority factors (1–5)')}</div>
      <div className="grid gap-2 grid-cols-2 sm:grid-cols-4 mt-2">{([['impact', 'الأثر على العمل', 'Business impact'], ['gap', 'حجم الفجوة', 'Gap size'], ['affected', 'عدد المتأثرين', 'People affected'], ['urgency', 'الإلحاح', 'Urgency']] as const).map(([k, a, b]) =>
        <Field key={k} label={L(a, b)}><select className="field" value={v.factors[k] ?? 3} disabled={locked} onChange={x => set({ factors: { ...v.factors, [k]: Number(x.target.value) } })}>{[1, 2, 3, 4, 5].map(i => <option key={i} value={i}>{i}</option>)}</select></Field>)}</div>
    </div>
    <Field label={L('ملاحظات', 'Notes')} className="mt-3"><textarea className="field" rows={2} value={v.note} onChange={x => set({ note: x.target.value })} disabled={locked} /></Field>
    {act.error && !e?.errors && <div className="text-[13px] text-danger mt-2">{(act.error as Error).message}</div>}
  </Modal>
}

function GroupToPlan({ g, onClose }: { g: NeedGroup; onClose: () => void }) {
  const L = useL()
  const plans = usePlans()
  const act = useTdAct()
  const [planId, setPlan] = useState<number | null>(null)
  const [month, setMonth] = useState<number | ''>('')
  const [cost, setCost] = useState(g.cost || 0)
  const open = plans.data?.data.filter(p => p.status !== 'rejected') ?? []
  const pid = planId ?? open[0]?.id ?? null
  return <Modal open onClose={onClose} title={L('إضافة إلى الخطة', 'Add to the plan')} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" disabled={!pid || act.isPending} onClick={() => act.mutate({ path: `plans/${pid}/items`, v: { title: g.title, program_id: g.program?.id ?? null, path: g.path, delivery: 'internal', trainees: g.count, estimated_cost: cost, month: month || null, need_ids: g.ids } }, { onSuccess: () => { toast(L('أُضيف البند إلى الخطة', 'Added to the plan')); onClose() }, onError: fail })}>{L('إضافة', 'Add')}</Btn></>}>
    {open.length === 0 ? <Empty title={L('لا توجد خطة', 'No plan')}>{L('أنشئ الخطة السنوية أولاً من تبويب «الخطة السنوية».', 'Create the annual plan first.')}</Empty> : <div className="grid gap-3">
      <div className="font-medium">{g.title} <span className="text-ink-3 text-[12.5px]">· {L(`${g.count} متدرب`, `${g.count} trainees`)}</span></div>
      <Field label={L('الخطة', 'Plan')}><select className="field" value={pid ?? ''} onChange={e => setPlan(Number(e.target.value))}>{open.map(p => <option key={p.id} value={p.id}>{p.year} — {p.title}</option>)}</select></Field>
      <div className="grid grid-cols-2 gap-2"><Field label={L('الشهر', 'Month')}><select className="field" value={month} onChange={e => setMonth(e.target.value ? Number(e.target.value) : '')}><option value="">—</option>{Array.from({ length: 12 }, (_, i) => <option key={i} value={i + 1}>{i + 1}</option>)}</select></Field>
        <Field label={L('التكلفة التقديرية', 'Estimated cost')}><input type="number" min={0} className="field num" value={cost} onChange={e => setCost(Number(e.target.value))} /></Field></div>
    </div>}
  </Modal>
}

function Matrix({ lk }: { lk: Lookups }) {
  const L = useL(); const lang = useLang()
  const q = useRoute().query
  const f = { department: q.department, branch: q.branch }
  const { data } = useMatrix(f)
  const [cell, setCell] = useState<{ e: number; c: number; name: string; comp: string; cur: number | null } | null>(null)
  const act = useTdAct()
  return <>
    <div className="flex flex-wrap gap-2 mb-3">
      <select className="field !w-[calc(50%-4px)] sm:!w-48" value={q.department ?? ''} onChange={e => setQuery({ department: e.target.value || undefined })} aria-label={L('الإدارة', 'Department')}><option value="">{L('كل الإدارات', 'All departments')}</option>{lk.departments.map(d => <option key={d.id} value={d.id}>{bi(d, lang)}</option>)}</select>
      <select className="field !w-[calc(50%-4px)] sm:!w-44" value={q.branch ?? ''} onChange={e => setQuery({ branch: e.target.value || undefined })} aria-label={L('الفرع', 'Branch')}><option value="">{L('كل الفروع', 'All branches')}</option>{lk.branches.map(d => <option key={d.id} value={d.id}>{bi(d, lang)}</option>)}</select>
      <div className="text-[12px] text-ink-3 flex items-center gap-3"><span className="inline-flex items-center gap-1"><span className="size-3 rounded-sm bg-brand" />{L('الحالي', 'Current')}</span><span className="inline-flex items-center gap-1"><span className="size-3 rounded-sm" style={{ background: 'color-mix(in oklab, var(--danger) 45%, transparent)' }} />{L('الفجوة حتى المطلوب', 'Gap to required')}</span></div>
    </div>
    {!data ? <Spin /> : data.rows.length === 0 || data.competencies.length === 0 ? <Panel><Empty title={L('لا توجد بيانات', 'No data')}>{L('حدد الكفاءات المطلوبة لكل وظيفة من تبويب «الكفاءات» لتظهر المصفوفة.', 'Set the required competencies per job in the «Competencies» tab.')}</Empty></Panel>
      : <div className="grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(300px,1fr))]">{data.rows.map(r => <div key={r.id} className="bg-surface border border-line rounded-2xl p-3">
        <div className="font-medium">{bi(r, lang)} <span className="text-[11.5px] text-ink-3 ltr">{r.code}</span></div>
        <div className="mt-2 space-y-1.5">{data.competencies.filter(c => r.cells[c.id]).map(c => { const x = r.cells[c.id]; const gap = x.current == null || x.current < x.required
          return <button key={c.id} className="w-full flex items-center justify-between gap-2 text-[12.5px] hover:text-brand" onClick={() => setCell({ e: r.id, c: c.id, name: bi(r, lang), comp: bi(c, lang), cur: x.current })}>
            <span className={gap ? 'text-danger' : ''}>{bi(c, lang)}</span><span className="flex items-center gap-1.5"><span className="num text-ink-3">{x.current ?? '—'}/{x.required}</span><LevelBar current={x.current} required={x.required} /></span></button> })}</div>
      </div>)}</div>}
    {cell && <Modal open onClose={() => setCell(null)} title={`${cell.name} — ${cell.comp}`}>
      <div className="text-[13px] text-ink-3 mb-2">{L('سجّل المستوى الحالي بعد التحقق (مقابلة، ملاحظة، تقييم).', 'Record the current level after verification (interview, observation, appraisal).')}</div>
      <div className="flex gap-2">{[1, 2, 3, 4, 5].map(n => <Btn key={n} kind={cell.cur === n ? 'primary' : 'secondary'} onClick={() => act.mutate({ path: 'employee-competency', v: { employee_id: cell.e, competency_id: cell.c, level: n } }, { onSuccess: () => { toast(L('تم الحفظ', 'Saved')); setCell(null) }, onError: fail })}>{n}</Btn>)}</div>
    </Modal>}
  </>
}

function Campaigns({ lk, can }: { lk: Lookups; can: Can }) {
  const L = useL(); const lang = useLang()
  const { data } = useCampaigns()
  const act = useTdAct()
  const [add, setAdd] = useState(false)
  const [form, setForm] = useState<{ c: number; d: number } | null>(null)
  const [v, setV] = useState({ title: '', year: new Date().getFullYear() + 1, closes_on: '' })
  return <>
    <div className="flex mb-3"><div className="text-[12.5px] text-ink-3 flex-1">{L('حملة الجمع تطلب من رؤساء الإدارات تعبئة نموذج احتياجات إدارتهم معبأ مسبقاً بالفجوات والشهادات التي تنتهي.', 'A campaign asks department heads to fill in a needs form pre-filled with gaps and expiring certificates.')}</div>
      {can.manage && <Btn kind="primary" icon={<Plus size={15} />} onClick={() => setAdd(true)}>{L('حملة جديدة', 'New campaign')}</Btn>}</div>
    {!data ? <Spin /> : data.data.length === 0 ? <Panel><Empty title={L('لا توجد حملات', 'No campaigns')} /></Panel>
      : <div className="space-y-3">{data.data.map(c => <Panel key={c.id} title={<span>{c.title} <span className="text-ink-3 text-[12px]">· {c.year}</span></span>}
        sub={`${fmtDate(c.opens_on, lang)} — ${fmtDate(c.closes_on, lang)} · ${L(`${c.needs} احتياج`, `${c.needs} needs`)}`}
        action={<span className="flex items-center gap-2"><Badge tone={c.status === 'open' ? 'brand' : 'neutral'}>{c.status === 'open' ? L('مفتوحة', 'Open') : L('مغلقة', 'Closed')}</Badge>
          {c.status === 'open' && can.manage && <Btn size="sm" onClick={() => act.mutate({ path: `campaigns/${c.id}/close` }, { onError: fail })}>{L('إغلاق', 'Close')}</Btn>}</span>}>
        <div className="grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(220px,1fr))]">{c.departments.map(d => <button key={d.id} onClick={() => setForm({ c: c.id, d: d.id })} className="text-start rounded-xl border border-line p-2.5 hover:border-brand/50">
          <div className="font-medium text-[13px]">{bi(d, lang)}</div><div className="text-[12px] mt-0.5">{d.submitted ? <span className="text-brand">{L('أُرسل', 'Submitted')} · {fmtDate(d.submitted, lang)}</span> : <span className="text-ink-3">{L('لم يُرسل بعد', 'Not submitted yet')}</span>}</div></button>)}</div>
      </Panel>)}</div>}
    {add && <Modal open onClose={() => setAdd(false)} title={L('حملة جمع احتياجات', 'Needs campaign')} footer={<><Btn onClick={() => setAdd(false)}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" disabled={!v.title || !v.closes_on || act.isPending} onClick={() => act.mutate({ path: 'campaigns', v }, { onSuccess: () => { toast(L('أُطلقت الحملة وأُبلغ رؤساء الإدارات', 'Campaign launched; department heads notified')); setAdd(false) }, onError: fail })}>{L('إطلاق', 'Launch')}</Btn></>}>
      <div className="grid gap-3"><Field label={L('العنوان', 'Title')} req><input className="field" value={v.title} onChange={e => setV({ ...v, title: e.target.value })} /></Field>
        <div className="grid grid-cols-2 gap-2"><Field label={L('سنة الخطة', 'Plan year')}><input type="number" className="field num" value={v.year} onChange={e => setV({ ...v, year: Number(e.target.value) })} /></Field>
          <Field label={L('آخر موعد', 'Deadline')} req><input type="date" className="field" value={v.closes_on} onChange={e => setV({ ...v, closes_on: e.target.value })} /></Field></div></div>
    </Modal>}
    {form && <CampaignForm id={form.c} dept={form.d} lk={lk} onClose={() => setForm(null)} />}
  </>
}

export function CampaignForm({ id, dept, lk, onClose }: { id: number; dept: number; lk: Lookups; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const { data } = useCampaignForm(id, dept)
  const act = useTdAct()
  const [items, setItems] = useState<{ title: string; path: Path; employees: number[]; competency_id: number | null; priority: string; preferred_date: string; note: string }[] | null>(null)
  const rows = items ?? (data ? data.prefill.gaps.map(g => ({ title: bi(g.competency, lang), path: 'training' as Path, employees: [g.employee_id], competency_id: g.competency_id as number | null, priority: 'normal', preferred_date: '', note: `${bi(g.employee, lang)}: ${g.current ?? 0} → ${g.required}` }))
    .concat(data.prefill.certificates.map(c => ({ title: L(`تجديد شهادة: ${c.title}`, `Renew: ${c.title}`), path: 'training' as Path, employees: [c.employee_id], competency_id: null, priority: 'high', preferred_date: c.valid_to, note: '' }))) : [])
  const upd = (i: number, o: Partial<typeof rows[number]>) => setItems(rows.map((r, k) => k === i ? { ...r, ...o } : r))
  const deptEmps = lk.employees.filter(e => e.department_id === dept)
  return <Modal open onClose={onClose} width={860} title={data ? `${data.campaign.title} — ${bi(data.department, lang)}` : L('نموذج الاحتياجات', 'Needs form')}
    footer={data?.campaign.status === 'open' && <><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" icon={<Send size={15} />} disabled={!rows.length || act.isPending}
      onClick={() => act.mutate({ path: `campaigns/${id}/form/${dept}`, v: { items: rows } }, { onSuccess: r => { toast(L(`أُرسل ${r.count} احتياج`, `${r.count} needs sent`)); onClose() }, onError: fail })}>{L('إرسال احتياجات الإدارة', 'Send the department needs')}</Btn></>}>
    {!data ? <Spin /> : <>
      {data.needs.length > 0 && <div className="mb-3 text-[12.5px] text-ink-3">{L(`أُرسل من قبل ${data.needs.length} احتياج لهذه الإدارة؛ الإرسال الجديد يُضاف إليها.`, `${data.needs.length} needs were sent before; new ones are added.`)}</div>}
      <div className="space-y-2">{rows.map((r, i) => <div key={i} className="rounded-xl border border-line p-2.5 grid gap-2 sm:grid-cols-[1fr_140px_130px_auto] items-start">
        <div><input className="field" value={r.title} onChange={e => upd(i, { title: e.target.value })} aria-label={L('العنوان', 'Title')} />{r.note && <div className="text-[11.5px] text-ink-3 mt-1">{r.note}</div>}
          <select multiple className="field mt-1 !h-16 text-[12px]" value={r.employees.map(String)} aria-label={L('الموظفون', 'Employees')} onChange={e => upd(i, { employees: Array.from(e.target.selectedOptions).map(o => Number(o.value)) })}>{deptEmps.map(x => <option key={x.id} value={x.id}>{bi(x, lang)}</option>)}</select></div>
        <select className="field" value={r.path} onChange={e => upd(i, { path: e.target.value as Path })} aria-label={L('المسار', 'Path')}>{(Object.keys(PATH) as Path[]).map(p => <option key={p} value={p}>{L(PATH[p][0], PATH[p][1])}</option>)}</select>
        <select className="field" value={r.priority} onChange={e => upd(i, { priority: e.target.value })} aria-label={L('الأولوية', 'Priority')}>{[['low', 'منخفضة', 'Low'], ['normal', 'عادية', 'Normal'], ['high', 'عالية', 'High'], ['urgent', 'عاجلة', 'Urgent']].map(p => <option key={p[0]} value={p[0]}>{L(p[1], p[2])}</option>)}</select>
        <Btn size="sm" kind="ghost" icon={<X size={14} />} aria-label={L('حذف', 'Remove')} onClick={() => setItems(rows.filter((_, k) => k !== i))} /></div>)}</div>
      <Btn size="sm" className="mt-2" icon={<Plus size={14} />} onClick={() => setItems([...rows, { title: '', path: 'training', employees: [], competency_id: null, priority: 'normal', preferred_date: '', note: '' }])}>{L('إضافة احتياج', 'Add a need')}</Btn>
    </>}
  </Modal>
}

// ———————————————— الخطة السنوية ————————————————

function PlanTab({ lk, can }: { lk: Lookups; can: Can }) {
  const L = useL(); const lang = useLang()
  const q = useRoute().query
  const plans = usePlans()
  const id = Number(q.plan) || plans.data?.data[0]?.id || null
  const plan = usePlan(id)
  const act = useTdAct()
  const [add, setAdd] = useState(false)
  const [item, setItem] = useState<PlanItem | 'new' | null>(null)
  const [decide, setDecide] = useState<boolean | null>(null)
  if (!plans.data) return <Spin />
  const p = plan.data
  return <>
    <div className="flex flex-wrap gap-2 mb-3 items-center">
      {plans.data.data.length > 0 && <select className="field !w-full sm:!w-72" value={id ?? ''} onChange={e => setQuery({ plan: e.target.value })} aria-label={L('الخطة', 'Plan')}>{plans.data.data.map(x => <option key={x.id} value={x.id}>{x.year} — {x.title}</option>)}</select>}
      <div className="flex-1" />
      {p && can.export && <Btn icon={<Download size={15} />} onClick={() => download(`/td/plans/${p.id}/export`, `training-plan-${p.year}.xlsx`).catch(fail)}>{L('تصدير Excel', 'Export to Excel')}</Btn>}
      {can.create && <Btn kind="primary" icon={<Plus size={15} />} onClick={() => setAdd(true)}>{L('خطة جديدة', 'New plan')}</Btn>}
    </div>
    {!id ? <Panel><Empty title={L('لا توجد خطط', 'No plans')}>{L('أنشئ الخطة السنوية بميزانيتها، أو انسخ بنود خطة سابقة.', 'Create the annual plan with its budget, or copy the items of an earlier plan.')}</Empty></Panel> : !p ? <Spin /> : <>
      <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(170px,1fr))] mb-3">
        <Stat label={L('الحالة', 'Status')} value={<StatusBadge map={P_STATUS} s={p.status} />} sub={p.baseline ? L(`خط الأساس ${fmtDate(p.baseline.at, lang)}`, `Baseline ${fmtDate(p.baseline.at, lang)}`) : undefined} />
        <Stat label={L('الميزانية', 'Budget')} value={money(p.budget, p.currency, lang)} />
        <Stat label={L('التقديري', 'Planned')} value={money(p.progress.planned_cost, p.currency, lang)} tone={p.progress.planned_cost > p.budget ? 'var(--danger)' : undefined} />
        <Stat label={L('الفعلي', 'Actual')} value={money(p.progress.actual_cost, p.currency, lang)} />
        <Stat label={L('البنود المنفذة', 'Items delivered')} value={`${p.progress.done}/${p.progress.items}`} />
      </div>
      {p.checks.length > 0 && <Panel className="mb-3" title={L('فحوص الخطة', 'Plan checks')}><ul className="space-y-1">{p.checks.map(c => <li key={c.key} className="flex items-start gap-2 text-[13px]"><AlertTriangle size={15} className="text-[var(--warn)] mt-0.5 shrink-0" />{bi(c, lang)}</li>)}</ul></Panel>}
      {p.steps.length > 0 && <Panel className="mb-3" title={L('مسار الاعتماد', 'Approval path')}><StepsPath steps={p.steps} />
        {p.can_act && <div className="flex gap-2 mt-3"><Btn kind="primary" icon={<Check size={15} />} onClick={() => setDecide(true)}>{L('اعتماد', 'Approve')}</Btn><Btn kind="danger" icon={<X size={15} />} onClick={() => setDecide(false)}>{L('إعادة', 'Return')}</Btn></div>}</Panel>}
      <Panel pad={false} title={L('بنود الخطة', 'Plan items')} action={<span className="flex gap-2">
        {can.create && ['draft', 'rejected', 'approved'].includes(p.status) && <Btn size="sm" icon={<Plus size={14} />} onClick={() => setItem('new')}>{L('بند', 'Item')}</Btn>}
        {can.create && ['draft', 'rejected'].includes(p.status) && p.items.length > 0 && <Btn size="sm" kind="primary" icon={<Send size={14} />} onClick={() => act.mutate({ path: `plans/${p.id}/submit` }, { onSuccess: () => toast(L('أُرسلت الخطة للاعتماد', 'Plan sent for approval')), onError: fail })}>{L('إرسال للاعتماد', 'Send for approval')}</Btn>}</span>}>
        {p.items.length === 0 ? <div className="p-4"><Empty title={L('لا بنود بعد', 'No items yet')}>{L('أضف البنود من الاحتياجات المعتمدة (تبويب الاحتياجات) أو يدوياً.', 'Add items from the approved needs or by hand.')}</Empty></div>
          : <table className="tbl"><thead><tr><th>{L('البند', 'Item')}</th><th className="hidden md:table-cell">{L('النوع', 'Delivery')}</th><th className="hidden sm:table-cell">{L('الشهر', 'Month')}</th><th className="hidden sm:table-cell">{L('المتدربون', 'Trainees')}</th><th>{L('التكلفة', 'Cost')}</th><th className="hidden md:table-cell">{L('الحالة', 'Status')}</th></tr></thead>
            <tbody>{p.items.map(i => <tr key={i.id} className="click" onClick={() => setItem(i)}>
              <td><div className="font-medium">{i.title}</div><div className="flex flex-wrap gap-1.5 items-center text-[12px] text-ink-3"><PathBadge p={i.path} />{i.needs ? L(`${i.needs} احتياج`, `${i.needs} needs`) : ''}{i.changes?.length ? ` · ${L(`${i.changes.length} تعديل`, `${i.changes.length} changes`)}` : ''}</div></td>
              <td className="hidden md:table-cell text-[13px]">{DELIVERY[i.delivery] ? L(DELIVERY[i.delivery][0], DELIVERY[i.delivery][1]) : i.delivery}</td>
              <td className="hidden sm:table-cell num">{i.month ?? '—'}</td><td className="hidden sm:table-cell num">{i.trainees}</td>
              <td className="num text-[13px]">{money(i.estimated_cost, p.currency, lang)}{i.actual_cost > 0 && <div className="text-[11.5px] text-ink-3">{L('فعلي', 'actual')} {money(i.actual_cost, p.currency, lang)}</div>}</td>
              <td className="hidden md:table-cell"><StatusBadge map={S_STATUS} s={i.status} /></td></tr>)}</tbody></table>}
      </Panel>
    </>}
    {add && <PlanForm plans={plans.data.data} lk={lk} onClose={() => setAdd(false)} />}
    {item && p && <ItemForm p={p} i={item === 'new' ? null : item} lk={lk} onClose={() => setItem(null)} />}
    <DecisionDialog open={decide !== null} onClose={() => setDecide(null)} tone={decide ? 'primary' : 'danger'} reasonRequired={!decide} minLength={10}
      title={decide ? L('اعتماد الخطة', 'Approve the plan') : L('إعادة الخطة', 'Return the plan')} subject={p?.title} confirmLabel={decide ? L('اعتماد', 'Approve') : L('إعادة', 'Return')}
      onConfirm={r => act.mutateAsync({ path: `plans/${p!.id}/decide`, v: { approve: !!decide, note: r || null } })} />
  </>
}

function PlanForm({ plans, lk, onClose }: { plans: { id: number; year: number; title: string }[]; lk: Lookups; onClose: () => void }) {
  const L = useL()
  const act = useTdAct()
  const y = new Date().getFullYear() + 1
  const [v, setV] = useState({ year: y, title: L(`خطة التدريب ${y}`, `Training plan ${y}`), budget: 0, currency: 'USD', copy_from: '' as number | '' })
  const e = errOf(act.error)
  return <Modal open onClose={onClose} title={L('خطة سنوية جديدة', 'New annual plan')} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" disabled={act.isPending} onClick={() => act.mutate({ path: 'plans', v: { ...v, copy_from: v.copy_from || null } }, { onSuccess: r => { setQuery({ plan: String(r.id) }); onClose() } })}>{L('إنشاء', 'Create')}</Btn></>}>
    <div className="grid gap-3 sm:grid-cols-2">
      <Field label={L('السنة', 'Year')} error={e?.field('year')}><input type="number" className="field num" value={v.year} onChange={x => setV({ ...v, year: Number(x.target.value) })} /></Field>
      <Field label={L('العنوان', 'Title')} error={e?.field('title')}><input className="field" value={v.title} onChange={x => setV({ ...v, title: x.target.value })} /></Field>
      <Field label={L('الميزانية', 'Budget')} error={e?.field('budget')}><input type="number" min={0} className="field num" value={v.budget} onChange={x => setV({ ...v, budget: Number(x.target.value) })} /></Field>
      <Field label={L('العملة', 'Currency')}><select className="field" value={v.currency} onChange={x => setV({ ...v, currency: x.target.value })}>{lk.currencies.map(c => <option key={c.code} value={c.code}>{c.code}</option>)}</select></Field>
      {plans.length > 0 && <Field label={L('نسخ بنود من خطة', 'Copy items from')} className="sm:col-span-2"><select className="field" value={v.copy_from} onChange={x => setV({ ...v, copy_from: x.target.value ? Number(x.target.value) : '' })}><option value="">—</option>{plans.map(p => <option key={p.id} value={p.id}>{p.year} — {p.title}</option>)}</select></Field>}
    </div>
    {act.error && !e?.errors && <div className="text-[13px] text-danger mt-2">{(act.error as Error).message}</div>}
  </Modal>
}

function ItemForm({ p, i, lk, onClose }: { p: { id: number; status: string; baseline: unknown; currency: string }; i: PlanItem | null; lk: Lookups; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const act = useTdAct()
  const [v, setV] = useState({ title: i?.title ?? '', program_id: i?.program_id ?? null as number | null, path: (i?.path ?? 'training') as Path, delivery: i?.delivery ?? 'internal', audience: i?.audience ?? 'staff', department_id: i?.department_id ?? null as number | null,
    location_id: i?.location_id ?? null as number | null, month: i?.month ?? null as number | null, trainees: i?.trainees ?? 0, estimated_cost: i?.estimated_cost ?? 0, priority: i?.priority ?? 'normal', objective: i?.objective ?? '', status: i?.status ?? 'planned', change_reason: '' })
  const set = (o: Partial<typeof v>) => setV(x => ({ ...x, ...o }))
  const e = errOf(act.error)
  const needReason = !!i && !!p.baseline
  return <Modal open onClose={onClose} width={720} title={i ? i.title : L('بند جديد في الخطة', 'New plan item')} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" disabled={act.isPending || v.title.length < 3 || (needReason && v.change_reason.trim().length < 5)} onClick={() => act.mutate({ path: i ? `plan-items/${i.id}` : `plans/${p.id}/items`, method: i ? 'PUT' : 'POST', v: { ...v, change_reason: v.change_reason || null, objective: v.objective || null } }, { onSuccess: onClose })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid gap-3 sm:grid-cols-2">
      <Field label={L('البرنامج', 'Programme')} className="sm:col-span-2"><select className="field" value={v.program_id ?? ''} onChange={x => { const pr = lk.programs.find(y => y.id === Number(x.target.value)); set({ program_id: pr?.id ?? null, ...(pr ? { title: bi(pr, lang), path: pr.path, delivery: pr.delivery } : {}) }) }}>
        <option value="">{L('— بند دون برنامج من المكتبة —', '— Without a library programme —')}</option>{lk.programs.map(x => <option key={x.id} value={x.id}>{x.code} — {bi(x, lang)}</option>)}</select></Field>
      <Field label={L('العنوان', 'Title')} req error={e?.field('title')} className="sm:col-span-2"><input className="field" value={v.title} onChange={x => set({ title: x.target.value })} /></Field>
      <Field label={L('المسار', 'Path')}><select className="field" value={v.path} onChange={x => set({ path: x.target.value as Path })}>{(Object.keys(PATH) as Path[]).map(x => <option key={x} value={x}>{L(PATH[x][0], PATH[x][1])}</option>)}</select></Field>
      <Field label={L('نوع التدريب', 'Delivery')}><select className="field" value={v.delivery} onChange={x => set({ delivery: x.target.value })}>{Object.entries(DELIVERY).map(([k, t]) => <option key={k} value={k}>{L(t[0], t[1])}</option>)}</select></Field>
      <Field label={L('الفئة', 'Audience')}><select className="field" value={v.audience} onChange={x => set({ audience: x.target.value })}>{Object.entries(AUDIENCE).map(([k, t]) => <option key={k} value={k}>{L(t[0], t[1])}</option>)}</select></Field>
      <Field label={L('الإدارة', 'Department')}><select className="field" value={v.department_id ?? ''} onChange={x => set({ department_id: x.target.value ? Number(x.target.value) : null })}><option value="">—</option>{lk.departments.map(d => <option key={d.id} value={d.id}>{bi(d, lang)}</option>)}</select></Field>
      <Field label={L('الشهر', 'Month')}><select className="field" value={v.month ?? ''} onChange={x => set({ month: x.target.value ? Number(x.target.value) : null })}><option value="">—</option>{Array.from({ length: 12 }, (_, k) => <option key={k} value={k + 1}>{k + 1}</option>)}</select></Field>
      <Field label={L('عدد المتدربين', 'Trainees')}><input type="number" min={0} className="field num" value={v.trainees} onChange={x => set({ trainees: Number(x.target.value) })} /></Field>
      <Field label={L(`التكلفة التقديرية (${p.currency})`, `Estimated cost (${p.currency})`)} error={e?.field('estimated_cost')}><input type="number" min={0} className="field num" value={v.estimated_cost} onChange={x => set({ estimated_cost: Number(x.target.value) })} /></Field>
      <Field label={L('الأولوية', 'Priority')}><select className="field" value={v.priority} onChange={x => set({ priority: x.target.value })}>{[['low', 'منخفضة', 'Low'], ['normal', 'عادية', 'Normal'], ['high', 'عالية', 'High'], ['urgent', 'عاجلة', 'Urgent']].map(x => <option key={x[0]} value={x[0]}>{L(x[1], x[2])}</option>)}</select></Field>
      {i && <Field label={L('الحالة', 'Status')}><select className="field" value={v.status} onChange={x => set({ status: x.target.value })}>{['planned', 'confirmed', 'in_progress', 'done', 'postponed', 'cancelled'].map(s => <option key={s} value={s}>{L(S_STATUS[s][0], S_STATUS[s][1])}</option>)}</select></Field>}
      <Field label={L('الهدف المؤسسي المرتبط', 'Linked organisational goal')} className="sm:col-span-2"><input className="field" value={v.objective} onChange={x => set({ objective: x.target.value })} /></Field>
      {needReason && <Field label={L('سبب التعديل بعد اعتماد الخطة', 'Reason for changing an approved plan')} req error={e?.field('change_reason')} className="sm:col-span-2"><input className="field" value={v.change_reason} onChange={x => set({ change_reason: x.target.value })} /></Field>}
    </div>
    {i?.changes && i.changes.length > 0 && <div className="mt-3"><div className="text-[12.5px] font-semibold mb-1">{L('سجل التعديلات', 'Change log')}</div>
      <ul className="text-[12px] text-ink-3 space-y-1">{i.changes.map((c, k) => <li key={k}>{fmtDate(c.at, lang)} · {c.by} · {c.what} — {c.reason}</li>)}</ul></div>}
    {i && i.sessions.length > 0 && <div className="mt-3 flex flex-wrap gap-2">{i.sessions.map(s => <button key={s.id} className="text-[12.5px] underline" onClick={() => { onClose(); setQuery({ tab: 'sessions', s: String(s.id), plan: undefined }) }}>{s.ref}</button>)}</div>}
    {act.error && !e?.errors && <div className="text-[13px] text-danger mt-2">{(act.error as Error).message}</div>}
  </Modal>
}

export const TrainingIcon = GraduationCap
