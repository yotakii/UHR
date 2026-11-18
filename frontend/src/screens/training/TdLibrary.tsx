import { useState } from 'react'
import { Award, Check, ExternalLink, Plus, Send, ShieldCheck, Star, X } from 'lucide-react'
import { useL, useLang, bi, fmtDate, money } from '../../lib/store'
import { setQuery } from '../../lib/router'
import { fileUrl } from '../../lib/console'
import { AUDIENCE, C_STATUS, CERT, DELIVERY, PATH, useCbps, useCertificates, useCommitments, useCompetencies, useImpacts, usePrograms, useProviders, useQualifications, useRecord, useTdAct, useTdReports,
  type Can, type Cbp, type Lookups, type Path } from '../../lib/training'
import { Badge, Btn, Empty, Field, KV, Modal, Panel, Progress, Tabs, toast } from '../../ui/kit'
import { DecisionDialog } from '../../ui/decision'
import { EmpSelect, LevelBar, PathBadge, Spin, Stat, StatusBadge, TdFilePick, errOf, fail } from './parts'
import { HistoryPanel, PrintBtn } from './TdMore'

// ———————————————— البرامج ————————————————

type Prog = Record<string, unknown> & { id: number; code: string; title: string; title_en: string | null; path: Path; delivery: string; hours: number; runs: number; review_due: boolean; active: boolean }

export function ProgramsTab({ lk, can }: { lk: Lookups; can: Can }) {
  const L = useL(); const lang = useLang()
  const { data } = usePrograms()
  const [edit, setEdit] = useState<Prog | 'new' | null>(null)
  return <>
    <div className="flex mb-3"><div className="text-[12.5px] text-ink-3 flex-1">{L('مكتبة البرامج: الأهداف والمخرجات والمحاور والكفاءات المستهدفة وطريقة القياس — تُراجع سنوياً.', 'Programme library: objectives, outcomes, modules, target competencies and measurement — reviewed yearly.')}</div>
      {can.manage && <Btn kind="primary" icon={<Plus size={15} />} onClick={() => setEdit('new')}>{L('برنامج', 'Programme')}</Btn>}</div>
    {!data ? <Spin /> : data.length === 0 ? <Panel><Empty title={L('المكتبة فارغة', 'The library is empty')} /></Panel>
      : <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(280px,1fr))]">{data.map(p => <button key={p.id} onClick={() => setEdit(p)} className={`text-start bg-surface border border-line rounded-2xl p-4 hover:border-brand/50 ${p.active ? '' : 'opacity-60'}`}>
        <div className="flex items-start justify-between gap-2"><div><div className="text-[11.5px] text-ink-3 ltr text-start">{p.code}</div><div className="font-semibold">{lang === 'en' && p.title_en ? p.title_en : p.title}</div></div><PathBadge p={p.path} /></div>
        <div className="text-[12.5px] text-ink-3 mt-1">{DELIVERY[p.delivery] ? L(DELIVERY[p.delivery][0], DELIVERY[p.delivery][1]) : p.delivery} · {p.hours} {L('ساعة', 'h')} · {L(`نُفّذ ${p.runs}`, `${p.runs} runs`)}</div>
        <div className="flex flex-wrap gap-1.5 mt-2">{p.mandatory ? <Badge tone="brass">{L('إلزامي', 'Mandatory')}</Badge> : null}{p.has_test ? <Badge tone="info">{L('باختبار', 'With test')}</Badge> : null}{p.review_due && <Badge tone="warn">{L('تحتاج مراجعة', 'Review due')}</Badge>}</div>
      </button>)}</div>}
    {edit && <ProgramForm lk={lk} p={edit === 'new' ? null : edit} readOnly={!can.manage} onClose={() => setEdit(null)} />}
  </>
}

function ProgramForm({ lk, p, readOnly, onClose }: { lk: Lookups; p: Prog | null; readOnly: boolean; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const act = useTdAct()
  const g = <T,>(k: string, d: T) => (p?.[k] ?? d) as T
  const [v, setV] = useState({ code: g('code', ''), title: g('title', ''), title_en: g('title_en', ''), path: g<Path>('path', 'training'), delivery: g('delivery', 'internal'), audience: g('audience', 'staff'), method: g('method', 'general'),
    objectives: g('objectives', ''), outcomes: g('outcomes', ''), modules: g<{ title: string; hours: number }[]>('modules', []), hours: g('hours', 0), competencies: g<{ id: number; level: number }[]>('competencies', []),
    mandatory: g('mandatory', false), reviewed_on: g<string | null>('reviewed_on', null)?.slice(0, 10) ?? '', pass_mark: g<number | null>('pass_mark', null), attendance_min: g<number | null>('attendance_min', null), has_test: g('has_test', false),
    impact_items: g<string[]>('impact_items', []), active: g('active', true) })
  const set = (o: Partial<typeof v>) => setV(x => ({ ...x, ...o }))
  const e = errOf(act.error)
  return <Modal open onClose={onClose} width={780} title={p ? `${p.code} — ${p.title}` : L('برنامج جديد', 'New programme')} footer={!readOnly && <><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" disabled={act.isPending} onClick={() => act.mutate({ path: p ? `programs/${p.id}` : 'programs', method: p ? 'PUT' : 'POST', v: { ...v, reviewed_on: v.reviewed_on || null, title_en: v.title_en || null, impact_items: v.impact_items.filter(Boolean).length ? v.impact_items.filter(Boolean) : null } }, { onSuccess: onClose })}>{L('حفظ', 'Save')}</Btn></>}>
    <fieldset disabled={readOnly} className="grid gap-3 sm:grid-cols-2">
      <Field label={L('الرمز', 'Code')} req error={e?.field('code')}><input className="field ltr" value={v.code} onChange={x => set({ code: x.target.value.toUpperCase() })} /></Field>
      <Field label={L('عدد الساعات', 'Hours')} req><input type="number" min={0} className="field num" value={v.hours} onChange={x => set({ hours: Number(x.target.value) })} /></Field>
      <Field label={L('الاسم', 'Title')} req error={e?.field('title')}><input className="field" value={v.title} onChange={x => set({ title: x.target.value })} /></Field>
      <Field label={L('الاسم بالإنجليزية', 'English title')}><input className="field ltr" value={v.title_en} onChange={x => set({ title_en: x.target.value })} /></Field>
      <Field label={L('المسار', 'Path')}><select className="field" value={v.path} onChange={x => set({ path: x.target.value as Path })}>{(Object.keys(PATH) as Path[]).map(k => <option key={k} value={k}>{L(PATH[k][0], PATH[k][1])}</option>)}</select></Field>
      <Field label={L('نوع التدريب', 'Delivery')}><select className="field" value={v.delivery} onChange={x => set({ delivery: x.target.value })}>{Object.entries(DELIVERY).map(([k, t]) => <option key={k} value={k}>{L(t[0], t[1])}</option>)}</select></Field>
      <Field label={L('الفئة', 'Audience')}><select className="field" value={v.audience} onChange={x => set({ audience: x.target.value })}>{Object.entries(AUDIENCE).map(([k, t]) => <option key={k} value={k}>{L(t[0], t[1])}</option>)}</select></Field>
      <Field label={L('الأسلوب', 'Method')} hint={L('يحدد السعة المنصوح بها', 'Sets the recommended capacity')}><select className="field" value={v.method} onChange={x => set({ method: x.target.value })}>
        {[['general', 'عام', 'General'], ['workshop', 'ورشة عمل', 'Workshop'], ['lecture', 'محاضرة', 'Lecture'], ['group', 'جماعي', 'Group']].map(o => <option key={o[0]} value={o[0]}>{L(o[1], o[2])} ({lk.cfg.capacity[o[0]]?.join('–')})</option>)}</select></Field>
      <Field label={L('الأهداف', 'Objectives')} className="sm:col-span-2"><textarea className="field" rows={2} value={v.objectives} onChange={x => set({ objectives: x.target.value })} /></Field>
      <Field label={L('المخرجات المتوقعة', 'Expected outcomes')} className="sm:col-span-2"><textarea className="field" rows={2} value={v.outcomes} onChange={x => set({ outcomes: x.target.value })} /></Field>
      <label className="flex items-center gap-2 text-[13.5px]"><input type="checkbox" checked={v.has_test} onChange={x => set({ has_test: x.target.checked })} />{L('فيه اختبار قبلي وبعدي', 'Has pre/post tests')}</label>
      <label className="flex items-center gap-2 text-[13.5px]"><input type="checkbox" checked={v.mandatory} onChange={x => set({ mandatory: x.target.checked })} />{L('برنامج إلزامي', 'Mandatory programme')}</label>
      <Field label={L('علامة النجاح ٪ (فارغ = الإعداد العام)', 'Pass mark % (empty = default)')}><input type="number" min={1} max={100} className="field num" value={v.pass_mark ?? ''} placeholder={String(lk.cfg.pass_mark)} onChange={x => set({ pass_mark: x.target.value ? Number(x.target.value) : null })} /></Field>
      <Field label={L('الحد الأدنى للحضور ٪', 'Minimum attendance %')}><input type="number" min={1} max={100} className="field num" value={v.attendance_min ?? ''} placeholder={String(lk.cfg.attendance_min)} onChange={x => set({ attendance_min: x.target.value ? Number(x.target.value) : null })} /></Field>
      <Field label={L('تاريخ آخر مراجعة', 'Last reviewed')}><input type="date" className="field" value={v.reviewed_on} onChange={x => set({ reviewed_on: x.target.value })} /></Field>
      <label className="flex items-center gap-2 text-[13.5px]"><input type="checkbox" checked={v.active} onChange={x => set({ active: x.target.checked })} />{L('فعّال', 'Active')}</label>
    </fieldset>
    <div className="mt-4"><div className="font-semibold text-[13.5px] mb-2">{L('المحاور', 'Modules')}</div>
      {v.modules.map((m, i) => <div key={i} className="grid grid-cols-[1fr_90px_auto] gap-2 mb-2"><input className="field" value={m.title} disabled={readOnly} aria-label={L('المحور', 'Module')} onChange={x => set({ modules: v.modules.map((y, k) => k === i ? { ...y, title: x.target.value } : y) })} />
        <input type="number" className="field num" value={m.hours} disabled={readOnly} aria-label={L('الساعات', 'Hours')} onChange={x => set({ modules: v.modules.map((y, k) => k === i ? { ...y, hours: Number(x.target.value) } : y) })} />
        {!readOnly && <Btn size="sm" kind="ghost" icon={<X size={14} />} aria-label={L('حذف', 'Remove')} onClick={() => set({ modules: v.modules.filter((_, k) => k !== i) })} />}</div>)}
      {!readOnly && <Btn size="sm" icon={<Plus size={14} />} onClick={() => set({ modules: [...v.modules, { title: '', hours: 1 }] })}>{L('محور', 'Module')}</Btn>}</div>
    <div className="mt-4"><div className="font-semibold text-[13.5px] mb-2">{L('الكفاءات المستهدفة والمستوى بعد البرنامج', 'Target competencies and level after the programme')}</div>
      {v.competencies.map((c, i) => <div key={i} className="grid grid-cols-[1fr_90px_auto] gap-2 mb-2"><select className="field" value={c.id} disabled={readOnly} aria-label={L('الكفاءة', 'Competency')} onChange={x => set({ competencies: v.competencies.map((y, k) => k === i ? { ...y, id: Number(x.target.value) } : y) })}>{lk.competencies.map(x => <option key={x.id} value={x.id}>{bi(x, lang)}</option>)}</select>
        <select className="field" value={c.level} disabled={readOnly} aria-label={L('المستوى', 'Level')} onChange={x => set({ competencies: v.competencies.map((y, k) => k === i ? { ...y, level: Number(x.target.value) } : y) })}>{[1, 2, 3, 4, 5].map(n => <option key={n} value={n}>{n}</option>)}</select>
        {!readOnly && <Btn size="sm" kind="ghost" icon={<X size={14} />} aria-label={L('حذف', 'Remove')} onClick={() => set({ competencies: v.competencies.filter((_, k) => k !== i) })} />}</div>)}
      {!readOnly && lk.competencies.length > 0 && <Btn size="sm" icon={<Plus size={14} />} onClick={() => set({ competencies: [...v.competencies, { id: lk.competencies[0].id, level: 3 }] })}>{L('كفاءة', 'Competency')}</Btn>}</div>
    {act.error && !e?.errors && <div className="text-[13px] text-danger mt-2">{(act.error as Error).message}</div>}
  </Modal>
}

// ———————————————— المدربون والمراكز ————————————————

export function ProvidersTab({ can }: { can: Can }) {
  const L = useL(); const lang = useLang()
  const { data } = useProviders()
  const [view, setView] = useState<'trainers' | 'centers'>('trainers')
  const [edit, setEdit] = useState<Record<string, unknown> | 'new' | null>(null)
  const [st, setSt] = useState<{ kind: string; id: number; status: string } | null>(null)
  const act = useTdAct()
  const STAT: Record<string, [string, string, 'brand' | 'warn' | 'danger']> = { approved: ['معتمد', 'Approved', 'brand'], watch: ['تحت المراقبة', 'Watch', 'warn'], banned: ['محظور', 'Banned', 'danger'] }
  if (!data) return <Spin />
  const rows = view === 'trainers' ? data.data : data.centers
  return <>
    <div className="flex flex-wrap gap-2 mb-3 items-center"><Tabs value={view} onChange={setView} tabs={[{ v: 'trainers', label: L('المدربون', 'Trainers'), count: data.data.length }, { v: 'centers', label: L('المراكز', 'Centres'), count: data.centers.length }]} />
      <div className="flex-1" />{can.manage && <Btn kind="primary" icon={<Plus size={15} />} onClick={() => setEdit('new')}>{view === 'trainers' ? L('مدرب', 'Trainer') : L('مركز', 'Centre')}</Btn>}</div>
    {rows.length === 0 ? <Panel><Empty title={L('لا يوجد بعد', 'None yet')} /></Panel>
      : <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]">{rows.map(r => { const status = r.status as string; const rating = (view === 'trainers' ? r.rating : r.quality) as number | null
        return <div key={r.id as number} className="bg-surface border border-line rounded-2xl p-4">
          <div className="flex items-start justify-between gap-2"><div className="font-semibold">{view === 'trainers' ? bi(r as never, lang) : (lang === 'en' && r.name_en ? r.name_en as string : r.name as string)}</div><Badge tone={STAT[status]?.[2] ?? 'neutral'}>{STAT[status] ? L(STAT[status][0], STAT[status][1]) : status}</Badge></div>
          <div className="text-[12.5px] text-ink-3 mt-1">{view === 'trainers' ? <>{r.kind === 'internal' ? L('داخلي', 'Internal') : L('خارجي', 'External')}{r.center ? ` · ${r.center}` : ''}{r.years != null ? ` · ${r.years} ${L('سنة', 'yrs')}` : ''} · {L(`${r.hours_given} ساعة`, `${r.hours_given} h`)}</> : <>{(r.city as string) ?? ''}{r.accreditations ? ` · ${r.accreditations}` : ''}</>}</div>
          <div className="flex items-center justify-between mt-2 text-[12.5px]"><span>{rating != null ? <><Star size={12} className="inline text-brass" /> <span className="num">{rating}</span></> : <span className="text-ink-3">{L('دون تقييم', 'Not rated')}</span>}</span>
            <span className="flex gap-1">{can.manage && <Btn size="sm" kind="ghost" onClick={() => setEdit(r)}>{L('تعديل', 'Edit')}</Btn>}
              {can.director && <select className="field !h-8 !w-auto text-[12px]" value={status} aria-label={L('الحالة', 'Status')} onChange={x => setSt({ kind: view === 'trainers' ? 'trainer' : 'center', id: r.id as number, status: x.target.value })}>{Object.entries(STAT).map(([k, t]) => <option key={k} value={k}>{L(t[0], t[1])}</option>)}</select>}</span></div>
          {r.status_note ? <div className="text-[12px] text-ink-3 mt-1">{r.status_note as string}</div> : null}
        </div> })}</div>}
    {edit && <ProviderForm kind={view} r={edit === 'new' ? null : edit} experts={data.experts} onClose={() => setEdit(null)} />}
    <DecisionDialog open={!!st} onClose={() => setSt(null)} tone={st?.status === 'banned' ? 'danger' : 'primary'} reasonRequired={st?.status === 'banned'} minLength={5} title={L('تغيير حالة الجهة', 'Change the provider status')}
      confirmLabel={L('حفظ', 'Save')} onConfirm={r => act.mutateAsync({ path: `providers/${st!.kind}/${st!.id}/status`, v: { status: st!.status, note: r || null } })} />
  </>
}

function ProviderForm({ kind, r, experts, onClose }: { kind: 'trainers' | 'centers'; r: Record<string, unknown> | null; experts: { id: number; ar: string; en: string }[]; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const act = useTdAct()
  const [v, setV] = useState<Record<string, unknown>>(kind === 'trainers'
    ? { kind: r?.kind ?? 'internal', employee_id: (r?.employee as { id: number } | null)?.id ?? null, external_id: r?.external_id ?? null, center_id: r?.center_id ?? null, name: r?.ar ?? '', name_en: r?.en ?? '', qualifications: r?.qualifications ?? '', years: r?.years ?? null, hourly_rate: r?.hourly_rate ?? null, currency: r?.currency ?? 'USD' }
    : { name: r?.name ?? '', name_en: r?.name_en ?? '', contact: r?.contact ?? '', phone: r?.phone ?? '', email: r?.email ?? '', city: r?.city ?? '', accreditations: r?.accreditations ?? '' })
  const s = (k: string) => (v[k] ?? '') as string
  const e = errOf(act.error)
  const lk = useProviders().data
  return <Modal open onClose={onClose} title={r ? L('تعديل', 'Edit') : kind === 'trainers' ? L('مدرب جديد', 'New trainer') : L('مركز جديد', 'New centre')} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" disabled={act.isPending} onClick={() => act.mutate({ path: `${kind}${r ? `/${r.id}` : ''}`, method: r ? 'PUT' : 'POST', v: Object.fromEntries(Object.entries(v).map(([k, x]) => [k, x === '' ? null : x])) }, { onSuccess: onClose })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid gap-3 sm:grid-cols-2">
      {kind === 'trainers' && <Field label={L('النوع', 'Type')}><select className="field" value={s('kind')} onChange={x => setV({ ...v, kind: x.target.value })}><option value="internal">{L('مدرب داخلي (موظف)', 'Internal (employee)')}</option><option value="external">{L('مدرب خارجي', 'External')}</option></select></Field>}
      {kind === 'trainers' && v.kind === 'external' && experts.length > 0 && <Field label={L('من قائمة الخبراء', 'From the expert roster')}><select className="field" value={(v.external_id as number) ?? ''} onChange={x => setV({ ...v, external_id: x.target.value ? Number(x.target.value) : null })}><option value="">—</option>{experts.map(x => <option key={x.id} value={x.id}>{bi(x, lang)}</option>)}</select></Field>}
      {kind === 'trainers' && v.kind === 'external' && lk && <Field label={L('المركز', 'Centre')}><select className="field" value={(v.center_id as number) ?? ''} onChange={x => setV({ ...v, center_id: x.target.value ? Number(x.target.value) : null })}><option value="">—</option>{lk.centers.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>}
      <Field label={L('الاسم', 'Name')} error={e?.field('name')}><input className="field" value={s('name')} onChange={x => setV({ ...v, name: x.target.value })} /></Field>
      <Field label={L('الاسم بالإنجليزية', 'English name')}><input className="field ltr" value={s('name_en')} onChange={x => setV({ ...v, name_en: x.target.value })} /></Field>
      {kind === 'trainers' ? <>
        <Field label={L('سنوات الخبرة', 'Years of experience')}><input type="number" className="field num" value={(v.years as number) ?? ''} onChange={x => setV({ ...v, years: x.target.value ? Number(x.target.value) : null })} /></Field>
        <Field label={L('أجر الساعة (سري)', 'Hourly rate (confidential)')}><input type="number" className="field num" value={(v.hourly_rate as number) ?? ''} onChange={x => setV({ ...v, hourly_rate: x.target.value ? Number(x.target.value) : null })} /></Field>
        <Field label={L('المؤهلات', 'Qualifications')} className="sm:col-span-2"><textarea className="field" rows={2} value={s('qualifications')} onChange={x => setV({ ...v, qualifications: x.target.value })} /></Field>
      </> : <>
        <Field label={L('المسؤول', 'Contact')}><input className="field" value={s('contact')} onChange={x => setV({ ...v, contact: x.target.value })} /></Field>
        <Field label={L('الهاتف', 'Phone')}><input className="field ltr" value={s('phone')} onChange={x => setV({ ...v, phone: x.target.value })} /></Field>
        <Field label={L('البريد', 'Email')} error={e?.field('email')}><input className="field ltr" value={s('email')} onChange={x => setV({ ...v, email: x.target.value })} /></Field>
        <Field label={L('المدينة', 'City')}><input className="field" value={s('city')} onChange={x => setV({ ...v, city: x.target.value })} /></Field>
        <Field label={L('الاعتمادات', 'Accreditations')} className="sm:col-span-2"><input className="field" value={s('accreditations')} onChange={x => setV({ ...v, accreditations: x.target.value })} /></Field></>}
    </div>
    {kind === 'trainers' && v.kind === 'internal' && <div className="text-[12px] text-ink-3 mt-2">{L('للمدرب الداخلي: اكتب اسمه كما في ملفه؛ يُربط بحسابه ليرى دوراته ويسجّل الحضور.', 'For an internal trainer, enter the name as in the employee file.')}</div>}
    {act.error && !e?.errors && <div className="text-[13px] text-danger mt-2">{(act.error as Error).message}</div>}
  </Modal>
}

// ———————————————— الكفاءات ————————————————

export function CompetenciesTab({ can }: { can: Can }) {
  const L = useL(); const lang = useLang()
  const { data } = useCompetencies()
  const act = useTdAct()
  const [job, setJob] = useState<number | null>(null)
  const [edit, setEdit] = useState<{ id: number; kind: string; levels: string[] } | null>(null)
  const [req, setReq] = useState<Record<number, number> | null>(null)
  if (!data) return <Spin />
  const jid = job ?? data.job_list[0]?.id ?? null
  const cur = req ?? (jid ? Object.fromEntries(Object.entries(data.jobs[jid] ?? {}).map(([k, x]) => [Number(k), x])) : {})
  const KIND: Record<string, [string, string]> = { technical: ['فنية', 'Technical'], behavioral: ['سلوكية', 'Behavioural'], leadership: ['قيادية', 'Leadership'] }
  return <div className="grid gap-4 lg:grid-cols-2">
    <Panel title={L('قاموس الكفاءات', 'Competency dictionary')} sub={L('خمسة مستويات لكل كفاءة بوصف سلوكي يمكن ملاحظته.', 'Five levels per competency with observable behaviour.')}>
      <ul className="divide-y divide-line">{data.data.map(c => <li key={c.id} className="py-2 flex items-center justify-between gap-2"><div><div className="font-medium text-[13.5px]">{bi(c, lang)}</div><div className="text-[12px] text-ink-3">{KIND[c.kind] ? L(KIND[c.kind][0], KIND[c.kind][1]) : c.kind} · {L(`${(c.levels ?? []).filter(Boolean).length}/5 مستويات موصوفة`, `${(c.levels ?? []).filter(Boolean).length}/5 levels described`)}</div></div>
        {can.manage && <Btn size="sm" kind="ghost" onClick={() => setEdit({ id: c.id, kind: c.kind, levels: [0, 1, 2, 3, 4].map(i => c.levels?.[i] ?? '') })}>{L('تعديل', 'Edit')}</Btn>}</li>)}</ul>
    </Panel>
    <Panel title={L('الكفاءات المطلوبة لكل وظيفة', 'Required competencies per job')}>
      {data.job_list.length === 0 ? <div className="text-[13px] text-ink-3">{L('لا توجد وظائف في دليل الترميز.', 'No jobs in the codebook.')}</div> : <>
        <select className="field mb-3" value={jid ?? ''} onChange={e => { setJob(Number(e.target.value)); setReq(null) }} aria-label={L('الوظيفة', 'Job')}>{data.job_list.map(j => <option key={j.id} value={j.id}>{j.code} — {bi(j, lang)}</option>)}</select>
        <div className="space-y-1.5">{data.data.filter(c => c.active).map(c => <div key={c.id} className="flex items-center justify-between gap-2"><span className="text-[13px]">{bi(c, lang)}</span>
          <div className="flex gap-1">{[0, 1, 2, 3, 4, 5].map(n => <button key={n} disabled={!can.manage} onClick={() => setReq({ ...cur, [c.id]: n })} className={`size-7 rounded-md border text-[12px] num ${(cur[c.id] ?? 0) === n ? 'bg-brand text-white border-brand' : 'border-line'}`}>{n || '—'}</button>)}</div></div>)}</div>
        {can.manage && req && <Btn kind="primary" className="mt-3" disabled={act.isPending} onClick={() => act.mutate({ path: 'job-competencies', v: { job_id: jid, items: Object.entries(req).filter(([, x]) => x > 0).map(([k, x]) => ({ competency_id: Number(k), level: x })) } }, { onSuccess: () => { setReq(null); toast(L('تم الحفظ', 'Saved')) }, onError: fail })}>{L('حفظ متطلبات الوظيفة', 'Save the job requirements')}</Btn>}
      </>}
    </Panel>
    {edit && <Modal open onClose={() => setEdit(null)} width={640} title={L('مستويات الكفاءة', 'Competency levels')} footer={<><Btn onClick={() => setEdit(null)}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" disabled={act.isPending} onClick={() => act.mutate({ path: `competencies/${edit.id}`, method: 'PUT', v: { kind: edit.kind, levels: edit.levels } }, { onSuccess: () => setEdit(null), onError: fail })}>{L('حفظ', 'Save')}</Btn></>}>
      <Field label={L('النوع', 'Type')}><select className="field" value={edit.kind} onChange={e => setEdit({ ...edit, kind: e.target.value })}>{Object.entries(KIND).map(([k, t]) => <option key={k} value={k}>{L(t[0], t[1])}</option>)}</select></Field>
      {edit.levels.map((x, i) => <Field key={i} label={L(`المستوى ${i + 1}`, `Level ${i + 1}`)} className="mt-2"><input className="field" value={x} onChange={e => setEdit({ ...edit, levels: edit.levels.map((y, k) => k === i ? e.target.value : y) })} /></Field>)}
    </Modal>}
  </div>
}

// ———————————————— الشهادات ————————————————

export function CertificatesTab({ lk, can }: { lk: Lookups; can: Can }) {
  const L = useL(); const lang = useLang()
  const [status, setStatus] = useState('')
  const { data } = useCertificates(status)
  const act = useTdAct()
  const [rev, setRev] = useState<number | null>(null)
  const [up, setUp] = useState(false)
  const ST: Record<string, [string, string, 'brand' | 'brass' | 'danger' | 'neutral' | 'warn']> = { valid: ['سارية', 'Valid', 'brand'], pending: ['بانتظار التحقق', 'Awaiting verification', 'brass'], expired: ['منتهية', 'Expired', 'warn'], revoked: ['ملغاة', 'Revoked', 'danger'], rejected: ['مرفوضة', 'Rejected', 'neutral'] }
  return <>
    <div className="flex flex-wrap gap-2 mb-3"><select className="field !w-full sm:!w-48" value={status} onChange={e => setStatus(e.target.value)} aria-label={L('الحالة', 'Status')}><option value="">{L('كل الحالات', 'All statuses')}</option>{Object.entries(ST).map(([k, t]) => <option key={k} value={k}>{L(t[0], t[1])}</option>)}</select>
      <div className="flex-1" />{can.manage && <Btn kind="primary" icon={<Plus size={15} />} onClick={() => setUp(true)}>{L('شهادة خارجية', 'External certificate')}</Btn>}</div>
    {!data ? <Spin /> : data.length === 0 ? <Panel><Empty title={L('لا توجد شهادات', 'No certificates')}>{L('تصدر الشهادات تلقائياً للناجحين عند إكمال التنفيذ، برمز تحقق عام.', 'Certificates are issued to those who pass, with a public verification code.')}</Empty></Panel>
      : <Panel pad={false}><table className="tbl"><thead><tr><th>{L('الشهادة', 'Certificate')}</th><th className="hidden md:table-cell">{L('الموظف', 'Employee')}</th><th className="hidden sm:table-cell">{L('الصلاحية', 'Valid to')}</th><th>{L('الحالة', 'Status')}</th><th /></tr></thead>
        <tbody>{data.map(c => <tr key={c.id}>
          <td><div className="font-medium">{c.title}</div><div className="text-[12px] text-ink-3">{CERT[c.type] ? L(CERT[c.type][0], CERT[c.type][1]) : c.type}{c.no ? <> · <span className="ltr">{c.no}</span></> : ''}{c.issuer ? ` · ${c.issuer}` : ''}</div><div className="md:hidden text-[12px]">{c.employee ? bi(c.employee, lang) : ''}</div></td>
          <td className="hidden md:table-cell text-[13px]">{c.employee ? bi(c.employee, lang) : '—'}</td>
          <td className="hidden sm:table-cell text-[13px]">{c.valid_to ? fmtDate(c.valid_to, lang) : L('دائمة', 'No expiry')}</td>
          <td><Badge tone={ST[c.status]?.[2] ?? 'neutral'}>{ST[c.status] ? L(ST[c.status][0], ST[c.status][1]) : c.status}</Badge></td>
          <td className="whitespace-nowrap"><span className="inline-flex gap-1">
            {c.code && <a className="inline-flex items-center justify-center size-8 rounded-lg hover:bg-sub" href={`/td-verify/${c.code}`} target="_blank" rel="noreferrer" aria-label={L('التحقق العام', 'Public verification')}><ExternalLink size={14} /></a>}
            {c.file_id && <a className="inline-flex items-center justify-center size-8 rounded-lg hover:bg-sub" href={fileUrl(c.file_id)} target="_blank" rel="noreferrer" aria-label={L('الملف', 'File')}><Award size={14} /></a>}
            {c.status === 'pending' && can.manage && <><Btn size="sm" kind="ghost" icon={<Check size={14} />} aria-label={L('تحقق', 'Verify')} onClick={() => act.mutate({ path: `certificates/${c.id}/verify`, v: { ok: true } }, { onError: fail })} />
              <Btn size="sm" kind="ghost" icon={<X size={14} />} aria-label={L('رفض', 'Reject')} onClick={() => act.mutate({ path: `certificates/${c.id}/verify`, v: { ok: false, note: L('لم يُتحقق منها', 'Could not be verified') } }, { onError: fail })} /></>}
            {c.source !== 'external' && c.employee && <PrintBtn code="TDC" id={c.id} label="" />}
            {c.status === 'valid' && can.director && <Btn size="sm" kind="ghost" onClick={() => setRev(c.id)}>{L('إلغاء', 'Revoke')}</Btn>}</span></td></tr>)}</tbody></table></Panel>}
    <DecisionDialog open={rev !== null} onClose={() => setRev(null)} tone="danger" reasonRequired minLength={5} title={L('إلغاء الشهادة', 'Revoke the certificate')} confirmLabel={L('إلغاء الشهادة', 'Revoke')}
      onConfirm={r => act.mutateAsync({ path: `certificates/${rev}/revoke`, v: { reason: r } })} />
    {up && <UploadCert lk={lk} onClose={() => setUp(false)} />}
  </>
}

export function UploadCert({ lk, onClose, me = false }: { lk?: Lookups; onClose: () => void; me?: boolean }) {
  const L = useL(); const lang = useLang()
  const act = useTdAct()
  const [v, setV] = useState({ employee_id: null as number | null, title: '', issuer: '', issued_on: '', valid_to: '', file_id: null as number | null, competency_id: null as number | null })
  const e = errOf(act.error)
  return <Modal open onClose={onClose} title={L('شهادة من جهة خارجية', 'Certificate from an external body')} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" disabled={act.isPending || !v.title || !v.issued_on || !v.file_id || (!me && !v.employee_id)} onClick={() => act.mutate({ path: me ? '/me/training/certificates' : 'certificates', v: { ...v, valid_to: v.valid_to || null, issuer: v.issuer || null } }, { onSuccess: () => { toast(L('أُرسلت للتحقق', 'Sent for verification')); onClose() } })}>{L('إرسال', 'Send')}</Btn></>}>
    <div className="grid gap-3 sm:grid-cols-2">
      {!me && lk && <Field label={L('الموظف', 'Employee')} req className="sm:col-span-2"><EmpSelect lk={lk} value={v.employee_id ? [v.employee_id] : []} onChange={i => setV({ ...v, employee_id: i[0] ?? null })} label={L('الموظف', 'Employee')} /></Field>}
      <Field label={L('اسم الشهادة', 'Certificate')} req error={e?.field('title')} className="sm:col-span-2"><input className="field" value={v.title} onChange={x => setV({ ...v, title: x.target.value })} /></Field>
      <Field label={L('الجهة المانحة', 'Issuer')}><input className="field" value={v.issuer} onChange={x => setV({ ...v, issuer: x.target.value })} /></Field>
      {lk && <Field label={L('الكفاءة المرتبطة', 'Linked competency')}><select className="field" value={v.competency_id ?? ''} onChange={x => setV({ ...v, competency_id: x.target.value ? Number(x.target.value) : null })}><option value="">—</option>{lk.competencies.map(c => <option key={c.id} value={c.id}>{bi(c, lang)}</option>)}</select></Field>}
      <Field label={L('تاريخ الإصدار', 'Issued on')} req error={e?.field('issued_on')}><input type="date" className="field" value={v.issued_on} onChange={x => setV({ ...v, issued_on: x.target.value })} /></Field>
      <Field label={L('صالحة حتى', 'Valid to')} error={e?.field('valid_to')}><input type="date" className="field" value={v.valid_to} onChange={x => setV({ ...v, valid_to: x.target.value })} /></Field>
      <div className="sm:col-span-2"><TdFilePick onDone={f => setV({ ...v, file_id: f.id })} label={L('صورة الشهادة', 'Certificate copy')} /></div>
    </div>
    {act.error && !e?.errors && <div className="text-[13px] text-danger mt-2">{(act.error as Error).message}</div>}
  </Modal>
}

// ———————————————— التأهيل ————————————————

export function QualificationsTab({ lk, can }: { lk: Lookups; can: Can }) {
  const L = useL(); const lang = useLang()
  const { data } = useQualifications()
  const act = useTdAct()
  const [gen, setGen] = useState<number[]>([])
  const [ev, setEv] = useState<number | null>(null)
  const REC: Record<string, [string, string]> = { confirm: ['التثبيت', 'Confirm'], extend: ['تمديد التجربة', 'Extend probation'], not_confirm: ['عدم التثبيت', 'Do not confirm'] }
  return <>
    <div className="flex flex-wrap gap-2 mb-3 items-start"><div className="text-[12.5px] text-ink-3 flex-1">{L('برنامج التأهيل يُولَّد تلقائياً عند التعيين من قالب الوظيفة أو الإدارة: محطات في الأقسام، لكل محطة مدرب يوقّع على إتمامها، ثم تقييم المدير والتوصية.', 'A qualification programme is generated at hire from the job or department template: stations with a trainer each, then the manager evaluation.')}</div>
      {can.manage && <div className="flex gap-2 w-full sm:w-auto"><div className="flex-1 sm:w-64"><EmpSelect lk={lk} value={gen} onChange={setGen} label={L('الموظف', 'Employee')} /></div>
        <Btn disabled={!gen.length || act.isPending} onClick={() => act.mutate({ path: 'qualifications', v: { employee_id: gen[0] } }, { onSuccess: () => { setGen([]); toast(L('أُنشئ برنامج التأهيل', 'Qualification programme created')) }, onError: fail })}>{L('توليد', 'Generate')}</Btn></div>}</div>
    {!data ? <Spin /> : data.length === 0 ? <Panel><Empty title={L('لا برامج تأهيل', 'No qualification programmes')}>{L('أضف قوالب التأهيل من إعدادات التدريب لتُولَّد تلقائياً عند التعيين.', 'Add qualification templates in the training settings to generate them at hire.')}</Empty></Panel>
      : <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(320px,1fr))]">{data.map(q => <div key={q.id} className="bg-surface border border-line rounded-2xl p-4">
        <div className="flex items-start justify-between gap-2"><div><div className="font-semibold">{q.employee ? bi(q.employee, lang) : '—'}</div><div className="text-[12px] text-ink-3">{q.employee?.hire_date ? `${L('التعيين', 'Hired')} ${fmtDate(q.employee.hire_date, lang)}` : ''}</div></div>
          <span className="flex items-center gap-1">{q.kind === 'rotation' && <Badge tone="info">{L('تدوير وظيفي', 'Rotation')}</Badge>}<Badge tone={q.status === 'done' ? 'brand' : 'brass'}>{q.status === 'done' ? L('مكتمل', 'Done') : L('جارٍ', 'In progress')}</Badge><PrintBtn code="TD1" id={q.id} label="" /></span></div>
        <div className="mt-2"><Progress value={q.progress} /></div>
        <ol className="mt-2 space-y-1.5">{q.stations.map((s, i) => <li key={i} className="flex items-start gap-2 text-[12.5px]"><span className={`mt-0.5 size-4 rounded-full grid place-items-center text-[10px] ${s.signed_at ? 'bg-brand text-white' : 'border border-line'}`}>{s.signed_at ? <Check size={10} /> : i + 1}</span>
          <div className="flex-1"><div>{s.department ? bi(s.department, lang) : '—'} · {s.topics}</div><div className="text-ink-3">{s.trainer ? bi(s.trainer, lang) : ''} · {fmtDate(s.from, lang)} — {fmtDate(s.to, lang)}{s.signed_by ? ` · ${L('وقّع', 'signed')} ${s.signed_by}` : ''}</div></div>
          {!s.signed_at && can.manage && <Btn size="sm" kind="ghost" onClick={() => act.mutate({ path: `qualifications/${q.id}/sign`, v: { i } }, { onError: fail })}>{L('توقيع', 'Sign')}</Btn>}</li>)}</ol>
        {q.evaluation ? <div className="mt-2 text-[12.5px]">{L('التوصية', 'Recommendation')}: <b>{REC[q.evaluation.recommendation] ? L(REC[q.evaluation.recommendation][0], REC[q.evaluation.recommendation][1]) : q.evaluation.recommendation}</b></div>
          : q.progress === 100 && can.manage && <Btn size="sm" kind="primary" className="mt-2" onClick={() => setEv(q.id)}>{L('التقييم والتوصية', 'Evaluate')}</Btn>}
      </div>)}</div>}
    {ev && <QualEval id={ev} onClose={() => setEv(null)} />}
  </>
}

export function QualEval({ id, onClose, me = false }: { id: number; onClose: () => void; me?: boolean }) {
  const L = useL()
  const act = useTdAct()
  const [v, setV] = useState({ recommendation: 'confirm', note: '' })
  return <Modal open onClose={onClose} title={L('تقييم برنامج التأهيل', 'Qualification evaluation')} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" disabled={act.isPending} onClick={() => act.mutate({ path: me ? `/me/training/qualifications/${id}/evaluate` : `qualifications/${id}/evaluate`, v: { ...v, note: v.note || null } }, { onSuccess: () => { toast(L('حُفظ التقييم', 'Evaluation saved')); onClose() }, onError: fail })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid gap-2">{[['confirm', 'التثبيت', 'Confirm'], ['extend', 'تمديد فترة التجربة', 'Extend probation'], ['not_confirm', 'عدم التثبيت', 'Do not confirm']].map(o =>
      <label key={o[0]} className="flex items-center gap-2 text-[13.5px]"><input type="radio" checked={v.recommendation === o[0]} onChange={() => setV({ ...v, recommendation: o[0] })} />{L(o[1], o[2])}</label>)}
      <Field label={L('ملاحظات ونقاط تحتاج تطويراً', 'Notes and areas to develop')}><textarea className="field" rows={3} value={v.note} onChange={e => setV({ ...v, note: e.target.value })} /></Field></div>
  </Modal>
}

// ———————————————— الأثر ————————————————

export function ImpactsTab({ me = false }: { me?: boolean }) {
  const L = useL(); const lang = useLang()
  const [status, setStatus] = useState('pending')
  const { data } = useImpacts(me, status)
  const [open, setOpen] = useState<NonNullable<typeof data>[number] | null>(null)
  const KIND: Record<string, [string, string]> = { talk: ['حديث متابعة', 'Follow-up talk'], behavior: ['قياس السلوك (F-05)', 'Behaviour check (F-05)'], kpi: ['مؤشر العمل', 'Work KPI'] }
  return <>
    <div className="flex flex-wrap gap-2 mb-3 items-center"><Tabs value={status} onChange={setStatus} tabs={[{ v: 'pending', label: L('المستحقة', 'Due') }, { v: 'done', label: L('المنجزة', 'Done') }, { v: 'all', label: L('الكل', 'All') }]} />
      <div className="text-[12.5px] text-ink-3">{L('يتابع المدير المباشر أثر التدريب بعد 30 و60 و90 يوماً.', 'The line manager follows up the impact after 30, 60 and 90 days.')}</div></div>
    {!data ? <Spin /> : data.length === 0 ? <Panel><Empty title={L('لا متابعات', 'No follow-ups')} /></Panel>
      : <Panel pad={false}><table className="tbl"><thead><tr><th>{L('المتدرب والدورة', 'Trainee & course')}</th><th className="hidden sm:table-cell">{L('النقطة', 'Point')}</th><th>{L('الموعد', 'Due')}</th><th /></tr></thead>
        <tbody>{data.map(i => <tr key={i.id} className={i.status === 'pending' ? 'click' : ''} onClick={() => i.status === 'pending' && setOpen(i)}>
          <td><div className="font-medium">{i.employee ? bi(i.employee, lang) : '—'}</div><div className="text-[12px] text-ink-3">{i.session?.title}</div></td>
          <td className="hidden sm:table-cell text-[13px]">{L(`${i.point} يوماً`, `${i.point} days`)} · {KIND[i.kind] ? L(KIND[i.kind][0], KIND[i.kind][1]) : i.kind}</td>
          <td className={`text-[13px] ${i.overdue ? 'text-danger' : ''}`}>{fmtDate(i.due_on, lang)}</td>
          <td onClick={e => i.status === 'done' && e.stopPropagation()} className="whitespace-nowrap">{i.status === 'done' ? <span className="inline-flex items-center gap-1"><Badge tone="brand">{i.avg != null ? `${i.avg}%` : L('منجز', 'Done')}</Badge>{i.kind === 'behavior' && <PrintBtn code="TD5" id={i.id} me={me} label="" />}</span> : <Btn size="sm">{L('تسجيل', 'Record')}</Btn>}</td></tr>)}</tbody></table></Panel>}
    {open && <ImpactForm i={open} me={me} onClose={() => setOpen(null)} />}
  </>
}

function ImpactForm({ i, me, onClose }: { i: { id: number; kind: 'talk' | 'behavior' | 'kpi'; items: string[]; session: { title: string } | null }; me: boolean; onClose: () => void }) {
  const L = useL()
  const act = useTdAct()
  const [r, setR] = useState<number[]>(i.items.map(() => 60))
  const [v, setV] = useState({ notes: '', barriers: '', before: '', after: '' })
  return <Modal open onClose={onClose} width={680} title={i.session?.title ?? ''} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" disabled={act.isPending} onClick={() => act.mutate({ path: me ? `/me/training/impacts/${i.id}` : `impacts/${i.id}`, v: { ratings: i.kind === 'behavior' ? r : null, notes: v.notes || null, barriers: v.barriers || null, before: v.before === '' ? null : Number(v.before), after: v.after === '' ? null : Number(v.after) } },
      { onSuccess: () => { toast(L('سُجّل القياس', 'Recorded')); onClose() }, onError: fail })}>{L('حفظ', 'Save')}</Btn></>}>
    {i.kind === 'behavior' ? <div className="space-y-2">{i.items.map((t, k) => <div key={k} className="flex flex-wrap items-center justify-between gap-2"><span className="text-[13px]">{t}</span>
      <div className="flex gap-1">{[20, 40, 60, 80, 100].map(n => <button key={n} onClick={() => setR(r.map((x, j) => j === k ? n : x))} className={`rounded-md border px-2 py-0.5 text-[12px] num ${r[k] === n ? 'bg-brand text-white border-brand' : 'border-line'}`}>{n}</button>)}</div></div>)}</div>
      : i.kind === 'kpi' ? <div className="grid grid-cols-2 gap-2"><Field label={L('المؤشر قبل التدريب', 'KPI before')}><input type="number" className="field num" value={v.before} onChange={e => setV({ ...v, before: e.target.value })} /></Field>
        <Field label={L('المؤشر الآن', 'KPI now')}><input type="number" className="field num" value={v.after} onChange={e => setV({ ...v, after: e.target.value })} /></Field></div>
      : <div className="text-[13px] text-ink-3">{L('حديث قصير مع الموظف: ماذا طبّق مما تعلّمه، وما الذي يعيقه؟', 'A short talk: what has the employee applied, and what is in the way?')}</div>}
    <Field label={L('ملاحظات', 'Notes')} className="mt-3"><textarea className="field" rows={2} value={v.notes} onChange={e => setV({ ...v, notes: e.target.value })} /></Field>
    <Field label={L('عوائق التطبيق', 'Barriers')} className="mt-2"><input className="field" value={v.barriers} onChange={e => setV({ ...v, barriers: e.target.value })} /></Field>
  </Modal>
}

// ———————————————— التعهدات ————————————————

export function CommitmentsTab({ can }: { can: Can }) {
  const L = useL(); const lang = useLang()
  const { data } = useCommitments()
  const act = useTdAct()
  const [d, setD] = useState<{ id: number; a: 'recover' | 'waive'; due: number | null } | null>(null)
  const [view, setView] = useState<number | null>(null)
  return <>
    <div className="text-[12.5px] text-ink-3 mb-3">{L('التعهد يُولَّد عند تأكيد الترشيح لدورة تتجاوز تكلفتها الحد، ومدته حسب شرائح التكلفة. عند انتهاء الخدمة قبل نهايته يُحسب المبلغ ويُعرض لقرار مدير الموارد البشرية — لا خصم آلي.', 'A commitment is generated when a costly course is confirmed; its length follows the cost tiers. If service ends early the amount awaits the HR director\'s decision — never automatic.')}</div>
    {!data ? <Spin /> : data.length === 0 ? <Panel><Empty title={L('لا تعهدات', 'No commitments')} /></Panel>
      : <Panel pad={false}><table className="tbl"><thead><tr><th>{L('الموظف والدورة', 'Employee & course')}</th><th className="hidden sm:table-cell">{L('التكلفة', 'Cost')}</th><th className="hidden md:table-cell">{L('ينتهي', 'Ends')}</th><th>{L('الحالة', 'Status')}</th><th /></tr></thead>
        <tbody>{data.map(c => <tr key={c.id} className="click" onClick={() => setView(c.id)}>
          <td><div className="font-medium">{c.employee ? bi(c.employee, lang) : '—'}</div><div className="text-[12px] text-ink-3">{c.title} · {L(`${c.months} شهراً`, `${c.months} months`)}</div></td>
          <td className="hidden sm:table-cell num text-[13px]">{money(c.cost, c.currency, lang)}{c.due != null && c.due > 0 && <div className="text-[11.5px] text-ink-3">{L('المستحق الآن', 'Due now')} {money(c.due, c.currency, lang)}</div>}</td>
          <td className="hidden md:table-cell text-[13px]">{c.ends_on ? fmtDate(c.ends_on, lang) : '—'}</td>
          <td><StatusBadge map={C_STATUS} s={c.status} /></td>
          <td onClick={e => e.stopPropagation()} className="whitespace-nowrap">{c.status === 'breached' && can.director && <span className="inline-flex gap-1">
            <Btn size="sm" kind="danger" onClick={() => setD({ id: c.id, a: 'recover', due: c.due })}>{L('استرداد', 'Recover')}</Btn><Btn size="sm" onClick={() => setD({ id: c.id, a: 'waive', due: c.due })}>{L('إعفاء', 'Waive')}</Btn></span>}</td></tr>)}</tbody></table></Panel>}
    <DecisionDialog open={!!d} onClose={() => setD(null)} tone={d?.a === 'recover' ? 'danger' : 'primary'} reasonRequired={d?.a === 'waive'} minLength={5}
      title={d?.a === 'recover' ? L('استرداد تكلفة التدريب', 'Recover the training cost') : L('إعفاء من التعهد', 'Waive the commitment')} subject={d?.due != null ? `${L('المبلغ المحسوب', 'Calculated amount')}: ${d.due}` : undefined}
      confirmLabel={d?.a === 'recover' ? L('اعتماد الاسترداد', 'Approve recovery') : L('إعفاء', 'Waive')} onConfirm={r => act.mutateAsync({ path: `commitments/${d!.id}/decide`, v: { action: d!.a, note: r || null } })} />
    {view && data && (() => { const c = data.find(x => x.id === view); return c && <Modal open onClose={() => setView(null)} width={680} title={c.title}>
      <div className="grid gap-2 sm:grid-cols-2 mb-3"><KV k={L('الموظف', 'Employee')} v={c.employee ? bi(c.employee, lang) : '—'} /><KV k={L('الحالة', 'Status')} v={<StatusBadge map={C_STATUS} s={c.status} />} />
        <KV k={L('المدة', 'Length')} v={L(`${c.months} شهراً`, `${c.months} months`)} /><KV k={L('ينتهي', 'Ends')} v={c.ends_on ? fmtDate(c.ends_on, lang) : '—'} />
        <KV k={L('طريقة الاسترداد', 'Recovery')} v={c.mode === 'full' ? L('كامل المبلغ', 'Full amount') : L('نسبة المدة المتبقية', 'Pro rata')} /><KV k={L('وقّع', 'Signed')} v={c.signed_at ? fmtDate(c.signed_at, lang) : '—'} /></div>
      {c.body && <pre className="whitespace-pre-wrap font-sans text-[13px] bg-sub rounded-xl p-3">{c.body}</pre>}
      <div className="mt-2 flex justify-end"><PrintBtn code="TD3" id={c.id} label={L('طباعة التعهد F-03', 'Print the commitment F-03')} /></div>
    </Modal> })()}
  </>
}

// ———————————————— بناء القدرات ————————————————

export function CbpTab({ lk }: { lk: Lookups }) {
  const L = useL(); const lang = useLang()
  const { data } = useCbps()
  const act = useTdAct()
  const [edit, setEdit] = useState<Cbp | 'new' | null>(null)
  const [push, setPush] = useState<{ c: number; g: string; i: string } | null>(null)
  const [plan, setPlan] = useState<number | null>(null)
  if (!data) return <Spin />
  return <>
    <div className="flex mb-3"><div className="text-[12.5px] text-ink-3 flex-1">{L('خطة بناء القدرات تربط هدفاً مؤسسياً بالقدرات المطلوبة (كم شخصاً بأي مستوى) وتحسب الفجوة من مصفوفة الكفاءات، ثم تحوّل المبادرات إلى بنود في الخطة السنوية.', 'A capacity plan links a goal to the capacities needed, computes the gap from the competency matrix and pushes initiatives to the annual plan.')}</div>
      <Btn kind="primary" icon={<Plus size={15} />} onClick={() => setEdit('new')}>{L('خطة قدرات', 'Capacity plan')}</Btn></div>
    {data.data.length === 0 ? <Panel><Empty title={L('لا توجد خطط', 'No plans')} /></Panel> : <div className="space-y-3">{data.data.map(c => { const all = c.goals.flatMap(g => g.initiatives); const done = all.length ? Math.round(all.reduce((a, i) => a + (i.progress ?? 0), 0) / all.length) : 0
      const gap = c.goals.reduce((a, g) => a + Object.values((c.gaps[g.id] ?? {}) as Record<string, { gap: number }>).reduce((x, y) => x + (y.gap ?? 0), 0), 0)
      return <Panel key={c.id} title={c.title} sub={`${c.owner ?? ''}${c.starts_on ? ` · ${fmtDate(c.starts_on, lang)} — ${c.ends_on ? fmtDate(c.ends_on, lang) : ''}` : ''}`} action={<span className="flex gap-1"><PrintBtn code="CBP" id={c.id} label={L('الإطار المنطقي', 'Logframe')} /><Btn size="sm" onClick={() => setEdit(c)}>{L('تعديل', 'Edit')}</Btn></span>}>
      <div className="grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(140px,1fr))] mb-3"><Stat label={L('الأهداف', 'Goals')} value={c.goals.length} /><Stat label={L('المبادرات', 'Initiatives')} value={all.length} sub={L(`${all.filter(i => i.plan_item_id).length} في الخطة`, `${all.filter(i => i.plan_item_id).length} in the plan`)} />
        <Stat label={L('الإنجاز', 'Progress')} value={`${done}%`} /><Stat label={L('الفجوة المتبقية', 'Remaining gap')} value={gap} tone={gap ? 'var(--danger)' : undefined} /></div>
      <div className="space-y-3">{c.goals.map(g => <div key={g.id} className="rounded-xl border border-line p-3">
        <div className="font-medium">{g.title}</div>{g.kpi && <div className="text-[12px] text-ink-3">{g.kpi}: {g.baseline ?? '—'} → {g.target ?? '—'}{g.achieved ? ` (${L('المحقق', 'achieved')} ${g.achieved})` : ''}</div>}
        {g.capacities.length > 0 && <div className="mt-2 grid gap-1.5 [grid-template-columns:repeat(auto-fill,minmax(220px,1fr))]">{g.capacities.map((cap, k) => { const gp = (c.gaps[g.id] as unknown as Record<string, { required: number; have: number; gap: number }> | undefined)?.[k]; const comp = lk.competencies.find(x => x.id === cap.competency_id)
          return <div key={k} className="text-[12.5px] rounded-lg bg-sub px-2.5 py-1.5"><div>{comp ? bi(comp, lang) : '—'} ≥ {cap.level}</div><div className="text-ink-3">{L('المطلوب', 'Need')} {cap.count} · {L('المتوفر', 'Have')} {gp?.have ?? 0} · <b className={gp?.gap ? 'text-danger' : 'text-brand'}>{L('الفجوة', 'Gap')} {gp?.gap ?? 0}</b></div></div> })}</div>}
        {g.initiatives.length > 0 && <ul className="mt-2 space-y-1">{g.initiatives.map(i => <li key={i.id} className="flex flex-wrap items-center justify-between gap-2 text-[12.5px]"><span>{i.title}{i.cost ? ` · ${i.cost}` : ''}
          <span className="text-ink-3">{i.owner ? ` · ${i.owner}` : ''}{i.kpi ? ` · ${i.kpi}` : ''}{i.due ? ` · ${fmtDate(i.due, lang)}` : ''}{` · ${i.progress ?? 0}%`}</span></span>
          {i.plan_item_id ? <Badge tone="brand">{L('في الخطة', 'In the plan')}</Badge> : i.plan_change === 'pending' ? <Badge tone="brass">{L('بانتظار اعتماد تعديل الخطة', 'Awaiting plan-change approval')}</Badge> : <Btn size="sm" icon={<Send size={13} />} onClick={() => setPush({ c: c.id, g: g.id, i: i.id })}>{L('إلى الخطة السنوية', 'To the annual plan')}</Btn>}</li>)}</ul>}
      </div>)}</div></Panel> })}</div>}
    {edit && <CbpForm lk={lk} c={edit === 'new' ? null : edit} onClose={() => setEdit(null)} />}
    {push && <Modal open onClose={() => setPush(null)} title={L('إضافة المبادرة إلى الخطة', 'Add the initiative to the plan')} footer={<><Btn onClick={() => setPush(null)}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" disabled={!(plan ?? data.plans[0]?.id) || act.isPending} onClick={() => act.mutate({ path: `cbps/${push.c}/push`, v: { goal: push.g, initiative: push.i, plan_id: plan ?? data.plans[0]?.id } }, { onSuccess: () => { setPush(null); toast(L('أُضيفت إلى الخطة', 'Added to the plan')) }, onError: fail })}>{L('إضافة', 'Add')}</Btn></>}>
      {data.plans.length === 0 ? <div className="text-[13px] text-ink-3">{L('لا توجد خطة سنوية.', 'No annual plan.')}</div> : <select className="field" value={plan ?? data.plans[0].id} onChange={e => setPlan(Number(e.target.value))} aria-label={L('الخطة', 'Plan')}>{data.plans.map(p => <option key={p.id} value={p.id}>{p.year} — {p.title}</option>)}</select>}
    </Modal>}
  </>
}

function CbpForm({ lk, c, onClose }: { lk: Lookups; c: Cbp | null; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const act = useTdAct()
  const [v, setV] = useState({ title: c?.title ?? '', owner: c?.owner ?? '', description: c?.description ?? '', starts_on: c?.starts_on ?? '', ends_on: c?.ends_on ?? '', status: c?.status ?? 'active', goals: c?.goals ?? [] as Cbp['goals'] })
  const setG = (k: number, o: Partial<Cbp['goals'][number]>) => setV({ ...v, goals: v.goals.map((g, i) => i === k ? { ...g, ...o } : g) })
  return <Modal open onClose={onClose} width={860} title={c ? c.title : L('خطة بناء قدرات', 'Capacity-building plan')} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
    <Btn kind="primary" disabled={act.isPending || !v.title} onClick={() => act.mutate({ path: c ? `cbps/${c.id}` : 'cbps', method: c ? 'PUT' : 'POST', v: { ...v, starts_on: v.starts_on || null, ends_on: v.ends_on || null } }, { onSuccess: onClose, onError: fail })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid gap-3 sm:grid-cols-2">
      <Field label={L('العنوان', 'Title')} req><input className="field" value={v.title} onChange={e => setV({ ...v, title: e.target.value })} /></Field>
      <Field label={L('المسؤول', 'Owner')}><input className="field" value={v.owner} onChange={e => setV({ ...v, owner: e.target.value })} /></Field>
      <Field label={L('من', 'From')}><input type="date" className="field" value={v.starts_on} onChange={e => setV({ ...v, starts_on: e.target.value })} /></Field>
      <Field label={L('إلى', 'To')}><input type="date" className="field" value={v.ends_on} onChange={e => setV({ ...v, ends_on: e.target.value })} /></Field>
    </div>
    <div className="mt-4 space-y-3">{v.goals.map((g, k) => <div key={k} className="rounded-xl border border-line p-3">
      <div className="grid gap-2 sm:grid-cols-[1fr_1fr_100px_100px_auto]"><input className="field" placeholder={L('الهدف المؤسسي', 'Organisational goal')} value={g.title} onChange={e => setG(k, { title: e.target.value })} />
        <input className="field" placeholder={L('المؤشر', 'KPI')} value={g.kpi ?? ''} onChange={e => setG(k, { kpi: e.target.value })} />
        <input className="field" placeholder={L('الحالي', 'Baseline')} value={g.baseline ?? ''} onChange={e => setG(k, { baseline: e.target.value })} />
        <input className="field" placeholder={L('المستهدف', 'Target')} value={g.target ?? ''} onChange={e => setG(k, { target: e.target.value })} />
        <Btn size="sm" kind="ghost" icon={<X size={14} />} aria-label={L('حذف', 'Remove')} onClick={() => setV({ ...v, goals: v.goals.filter((_, i) => i !== k) })} /></div>
      <div className="text-[12.5px] font-semibold mt-2">{L('القدرات المطلوبة', 'Capacities needed')}</div>
      {g.capacities.map((cap, j) => <div key={j} className="grid gap-2 grid-cols-[1fr_80px_80px_auto] sm:grid-cols-[1fr_80px_80px_160px_140px_auto] mt-1"><select className="field" value={cap.competency_id} aria-label={L('الكفاءة', 'Competency')} onChange={e => setG(k, { capacities: g.capacities.map((x, i) => i === j ? { ...x, competency_id: Number(e.target.value) } : x) })}>{lk.competencies.map(x => <option key={x.id} value={x.id}>{bi(x, lang)}</option>)}</select>
        <input type="number" min={1} className="field num" aria-label={L('العدد', 'Count')} value={cap.count} onChange={e => setG(k, { capacities: g.capacities.map((x, i) => i === j ? { ...x, count: Number(e.target.value) } : x) })} />
        <select className="field" value={cap.level} aria-label={L('المستوى', 'Level')} onChange={e => setG(k, { capacities: g.capacities.map((x, i) => i === j ? { ...x, level: Number(e.target.value) } : x) })}>{[1, 2, 3, 4, 5].map(n => <option key={n} value={n}>{n}</option>)}</select>
        <select className="field col-span-2 sm:col-span-1" value={cap.job_id ?? ''} aria-label={L('الوظيفة', 'Job')} onChange={e => setG(k, { capacities: g.capacities.map((x, i) => i === j ? { ...x, job_id: e.target.value ? Number(e.target.value) : null } : x) })}><option value="">{L('كل الوظائف', 'Any job')}</option>{lk.jobs.map(x => <option key={x.id} value={x.id}>{bi(x, lang)}</option>)}</select>
        <input type="date" className="field col-span-2 sm:col-span-1" aria-label={L('مطلوبة قبل', 'Needed by')} value={cap.by ?? ''} onChange={e => setG(k, { capacities: g.capacities.map((x, i) => i === j ? { ...x, by: e.target.value } : x) })} />
        <Btn size="sm" kind="ghost" icon={<X size={14} />} aria-label={L('حذف', 'Remove')} onClick={() => setG(k, { capacities: g.capacities.filter((_, i) => i !== j) })} /></div>)}
      {lk.competencies.length > 0 && <Btn size="sm" className="mt-1" icon={<Plus size={13} />} onClick={() => setG(k, { capacities: [...g.capacities, { competency_id: lk.competencies[0].id, count: 1, level: 3 }] })}>{L('قدرة', 'Capacity')}</Btn>}
      <div className="text-[12.5px] font-semibold mt-2">{L('المبادرات', 'Initiatives')}</div>
      {g.initiatives.map((it, j) => <div key={it.id ?? j} className="grid gap-2 grid-cols-[1fr_110px_auto] sm:grid-cols-[1fr_120px_110px_110px_120px_70px_110px_auto] mt-1"><input className="field" value={it.title} aria-label={L('المبادرة', 'Initiative')} onChange={e => setG(k, { initiatives: g.initiatives.map((x, i) => i === j ? { ...x, title: e.target.value } : x) })} />
        <select className="field hidden sm:block" value={it.kind} aria-label={L('النوع', 'Kind')} onChange={e => setG(k, { initiatives: g.initiatives.map((x, i) => i === j ? { ...x, kind: e.target.value } : x) })}>
          {[['training', 'تدريب', 'Training'], ['hiring', 'استقطاب', 'Hiring'], ['coaching', 'إرشاد', 'Coaching'], ['system', 'نظام أو أداة', 'System / tool'], ['process', 'إجراء', 'Process']].map(o => <option key={o[0]} value={o[0]}>{L(o[1], o[2])}</option>)}</select>
        <input className="field hidden sm:block" placeholder={L('المسؤول', 'Owner')} value={it.owner ?? ''} onChange={e => setG(k, { initiatives: g.initiatives.map((x, i) => i === j ? { ...x, owner: e.target.value } : x) })} />
        <input className="field hidden sm:block" placeholder={L('المؤشر', 'KPI')} value={it.kpi ?? ''} onChange={e => setG(k, { initiatives: g.initiatives.map((x, i) => i === j ? { ...x, kpi: e.target.value } : x) })} />
        <input type="date" className="field hidden sm:block" aria-label={L('الموعد', 'Due')} value={it.due ?? ''} onChange={e => setG(k, { initiatives: g.initiatives.map((x, i) => i === j ? { ...x, due: e.target.value } : x) })} />
        <input type="number" min={0} max={100} className="field num hidden sm:block" aria-label={L('الإنجاز %', 'Progress %')} value={it.progress ?? 0} onChange={e => setG(k, { initiatives: g.initiatives.map((x, i) => i === j ? { ...x, progress: Number(e.target.value) } : x) })} />
        <input type="number" className="field num" placeholder={L('التكلفة', 'Cost')} value={it.cost ?? ''} onChange={e => setG(k, { initiatives: g.initiatives.map((x, i) => i === j ? { ...x, cost: Number(e.target.value) } : x) })} />
        <Btn size="sm" kind="ghost" icon={<X size={14} />} aria-label={L('حذف', 'Remove')} disabled={!!it.plan_item_id} onClick={() => setG(k, { initiatives: g.initiatives.filter((_, i) => i !== j) })} /></div>)}
      <Btn size="sm" className="mt-1" icon={<Plus size={13} />} onClick={() => setG(k, { initiatives: [...g.initiatives, { id: '', title: '', kind: 'training', progress: 0 } as never] })}>{L('مبادرة', 'Initiative')}</Btn>
    </div>)}</div>
    <Btn size="sm" className="mt-3" icon={<Plus size={14} />} onClick={() => setV({ ...v, goals: [...v.goals, { id: '', title: '', capacities: [], initiatives: [] } as never] })}>{L('هدف', 'Goal')}</Btn>
  </Modal>
}

// ———————————————— التقارير ————————————————

export function ReportsTab({ year, lk }: { year: number; lk: Lookups }) {
  const L = useL(); const lang = useLang()
  const { data } = useTdReports(year)
  if (!data) return <Spin />
  const k = data.kpis
  const dept = new Map(lk.departments.map(d => [String(d.id), d]))
  return <div className="space-y-4">
    <div className="flex items-center gap-2"><select className="field !w-28" value={year} onChange={e => setQuery({ year: e.target.value })} aria-label={L('السنة', 'Year')}>{[year - 1, year, year + 1].map(y => <option key={y} value={y}>{y}</option>)}</select></div>
    <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(170px,1fr))]">
      <Stat label={L('الدورات', 'Sessions')} value={k.sessions ?? 0} /><Stat label={L('المشاركون', 'Participants')} value={k.participants ?? 0} sub={`${k.participation ?? 0}% ${L('من الموظفين', 'of staff')}`} />
      <Stat label={L('ساعات التدريب', 'Training hours')} value={k.hours ?? 0} sub={`${k.hours_per_employee ?? 0} ${L('لكل موظف', 'per employee')}`} /><Stat label={L('التكلفة', 'Cost')} value={k.cost ?? 0} sub={`${k.cost_per_employee ?? 0} ${L('لكل موظف', 'per employee')}`} />
      <Stat label={L('نسبة النجاح', 'Pass rate')} value={k.pass_rate != null ? `${k.pass_rate}%` : '—'} /><Stat label={L('المستوى 1 — الرضا', 'L1 — reaction')} value={k.l1 != null ? `${k.l1}/5` : '—'} />
      <Stat label={L('المستوى 2 — التحسن', 'L2 — learning gain')} value={k.l2_gain != null ? `+${k.l2_gain}` : '—'} /><Stat label={L('المستوى 3 — السلوك', 'L3 — behaviour')} value={k.l3 != null ? `${k.l3}%` : '—'} />
    </div>
    <div className="grid gap-4 lg:grid-cols-2">
      <Panel title={L('حسب المسار', 'By path')}><ul className="space-y-2">{(Object.keys(PATH) as Path[]).map(p => <li key={p} className="flex items-center justify-between text-[13px]"><PathBadge p={p} /><span className="num">{data.by_path[p]?.sessions ?? 0} · {data.by_path[p]?.cost ?? 0}</span></li>)}</ul></Panel>
      <Panel title={L('عدالة التوزيع بين الإدارات', 'Fairness across departments')}><ul className="space-y-2">{Object.entries(data.fairness).map(([d, f]) => <li key={d} className="text-[13px]"><div className="flex justify-between"><span>{dept.get(d) ? bi(dept.get(d)!, lang) : '—'}</span><span className="num">{f.trained}/{f.headcount} · {f.pct}%</span></div><Progress value={f.pct} /></li>)}</ul></Panel>
      <Panel title={L('الاحتياجات', 'Needs')}><ul className="space-y-1 text-[13px]">{Object.entries(data.needs).map(([s, n]) => <li key={s} className="flex justify-between"><span>{s}</span><span className="num">{n}</span></li>)}</ul></Panel>
      <Panel title={L('التعهدات ومتابعة الأثر', 'Commitments & impact')}><div className="grid gap-2 grid-cols-2 text-[13px]">
        <KV k={L('تعهدات سارية', 'Active commitments')} v={data.commitments.active} /><KV k={L('المبلغ المعرض', 'At risk')} v={data.commitments.at_risk} />
        <KV k={L('مُخَلّ بها', 'Breached')} v={data.commitments.breached} /><KV k={L('متابعات مستحقة / منجزة', 'Follow-ups due / done')} v={`${data.impact_followup.due} / ${data.impact_followup.done}`} /></div></Panel>
    </div>
    {data.expiring.length > 0 && <Panel title={L('شهادات تنتهي خلال 90 يوماً', 'Certificates expiring within 90 days')}><ul className="divide-y divide-line">{data.expiring.map((c, i) => <li key={i} className="py-1.5 flex justify-between text-[13px]"><span>{c.employee ? bi(c.employee, lang) : '—'} · {c.title}</span><span>{fmtDate(c.valid_to, lang)}</span></li>)}</ul></Panel>}
  </div>
}

// ———————————————— السجل التدريبي (ملف الموظف) ————————————————

export function TrainingRecord({ id, edit = false }: { id: number; edit?: boolean }) {
  const L = useL(); const lang = useLang()
  const { data } = useRecord(id)
  if (!data) return <Spin />
  return <div className="space-y-4"><div className="flex justify-end"><PrintBtn code="TD6" id={id} label={L('طباعة السجل F-06', 'Print the record F-06')} /></div>
    <RecordView r={data} lang={lang} L={L} /><HistoryPanel employeeId={id} edit={edit} /></div>
}

export function RecordView({ r, L, lang }: { r: NonNullable<ReturnType<typeof useRecord>['data']> | import('../../lib/training').Record_; L: (a: string, b: string) => string; lang: 'ar' | 'en' }) {
  return <div className="space-y-4">
    <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(150px,1fr))]">
      <Stat label={L('دورات مكتملة', 'Courses completed')} value={r.totals.courses} /><Stat label={L('ساعات هذا العام', 'Hours this year')} value={r.totals.hours_year} /><Stat label={L('كل الساعات', 'All hours')} value={r.totals.hours_all} />
    </div>
    {r.competencies.length > 0 && <Panel title={L('الكفاءات', 'Competencies')}><div className="grid gap-2 sm:grid-cols-2">{r.competencies.map(c => <div key={c.id} className="flex items-center justify-between gap-2 text-[13px]"><span>{bi(c, lang)}</span>
      <span className="flex items-center gap-1.5"><span className="num text-ink-3">{c.current ?? '—'}/{c.required ?? '—'}</span><LevelBar current={c.current} required={c.required} /></span></div>)}</div></Panel>}
    <Panel title={L('الدورات', 'Courses')}>{r.enrollments.length === 0 ? <div className="text-[13px] text-ink-3">{L('لا دورات بعد.', 'No courses yet.')}</div>
      : <ul className="divide-y divide-line">{r.enrollments.map(e => <li key={e.id} className="py-2"><div className="flex flex-wrap items-center justify-between gap-2"><span className="font-medium text-[13.5px]">{e.title}</span>
        <span className="flex gap-1.5">{e.result && <Badge tone={e.result === 'passed' ? 'brand' : e.result === 'failed' ? 'danger' : 'neutral'}>{e.result === 'passed' ? L('ناجح', 'Passed') : e.result === 'failed' ? L('غير ناجح', 'Failed') : L('منسحب', 'Withdrawn')}</Badge>}<PathBadge p={e.path} /></span></div>
        <div className="text-[12px] text-ink-3">{e.from ? fmtDate(e.from, lang) : ''}{e.provider ? ` · ${e.provider}` : ''} · {e.hours} {L('ساعة', 'h')}{e.attendance_pct != null ? ` · ${L('حضور', 'attendance')} ${e.attendance_pct}%` : ''}{e.post_score != null ? ` · ${L('النتيجة', 'score')} ${e.post_score}` : ''}{e.impact != null ? ` · ${L('الأثر', 'impact')} ${e.impact}%` : ''}</div></li>)}</ul>}</Panel>
    {r.certificates.length > 0 && <Panel title={L('الشهادات', 'Certificates')}><ul className="divide-y divide-line">{r.certificates.map(c => <li key={c.id} className="py-2 flex flex-wrap items-center justify-between gap-2 text-[13px]"><span>{c.title}{c.issuer ? ` · ${c.issuer}` : ''}</span>
      <span className="flex items-center gap-2 text-ink-3">{c.valid_to ? `${L('حتى', 'to')} ${fmtDate(c.valid_to, lang)}` : ''}{c.code && <a className="inline-flex items-center gap-1 underline" href={`/td-verify/${c.code}`} target="_blank" rel="noreferrer"><ShieldCheck size={13} />{L('تحقق', 'Verify')}</a>}</span></li>)}</ul></Panel>}
    {r.history?.length > 0 && !('employee' in r) && <Panel title={L('تدريب سابق لاستخدام النظام', 'Training before the system')}><ul className="divide-y divide-line">{r.history.map(h => <li key={h.id} className="py-2 flex flex-wrap justify-between gap-2 text-[13px]"><span>{h.title}{h.provider ? ` · ${h.provider}` : ''}</span><span className="num text-ink-3">{h.from ? fmtDate(h.from, lang) : ''} · {h.hours} {L('ساعة', 'h')}</span></li>)}</ul></Panel>}
    {r.commitments.length > 0 && <Panel title={L('التعهدات', 'Commitments')}><ul className="divide-y divide-line">{r.commitments.map(c => <li key={c.id} className="py-2 flex flex-wrap items-center justify-between gap-2 text-[13px]"><span>{c.title} · {L(`${c.months} شهراً`, `${c.months} months`)}</span>
      <span className="flex items-center gap-2">{c.days_left != null && <span className="text-ink-3">{L(`بقي ${c.days_left} يوماً`, `${c.days_left} days left`)}</span>}<StatusBadge map={C_STATUS} s={c.status} /></span></li>)}</ul></Panel>}
  </div>
}
