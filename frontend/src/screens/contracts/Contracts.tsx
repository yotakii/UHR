import { useMemo, useState } from 'react'
import { AlertTriangle, CalendarClock, FilePlus2, Loader2, Plus, Search, Upload } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { fmtDate } from '../../lib/dates'
import { ApiError } from '../../lib/api'
import { setQuery, useRoute } from '../../lib/router'
import { useLookup } from '../../lib/codes'
import {
  DECISION, RULES, uploadSigned, useAnnex, useBulk, useCancelContract, useCase, useCases, useContract, useContractList, useContractReports, useContracts, useCopy, useCreateContract,
  useDecide, useOpenCase, useReview, useSignContract, type CaseFull, type CaseRow, type ContractRow, type Decision, type RadarRow,
} from '../../lib/contracts'
import { Badge, Btn, Drawer, Empty, Field, Modal, PageHead, Panel, Seg, Tabs, toast } from '../../ui/kit'
import { ConfirmSend } from '../../ui/decision'
import { CaseBadge, ContractBadge, ContractSigs, EvalForm, EvalView, Left, PrintLink, SummaryLine } from './parts'

type Tab = 'radar' | 'renewal' | 'probation' | 'register' | 'reports'

/**
 * «العقود والتجديد» (SRS الأنظمة الأساسية، الجزء D — الدفعة 19): رادار الانتهاء، حالات التجديد، تقييمات التجربة،
 * سجل العقود، التقارير. كل حالة تفتح في لوحة جانبية بخطواتها حتى القرار والتنفيذ.
 */
export function ContractsHR() {
  const L = useL(); const route = useRoute()
  const tab = (route.query.tab as Tab) || 'radar'
  const f = { dept: route.query.dept, branch: route.query.branch, type: route.query.type, category: route.query.category }
  const { data: d } = useContracts(f)
  const caseId = Number(route.query.case) || null; const contractId = Number(route.query.contract) || null
  const [create, setCreate] = useState(false)
  if (!d) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const open = (kind: string) => d.counts.filter(c => c.kind === kind).reduce((a, c) => a + c.n, 0)
  return <div className="max-w-[1280px] mx-auto space-y-5">
    <PageHead title={L('العقود والتجديد', 'Contracts & renewals')} sub={L('رادار الانتهاء، تجديد العقود، تقييم فترة التجربة، وسجل العقود بتواقيعها ونسخها.', 'Expiry radar, renewals, probation evaluations and the contract register with signatures and copies.')}
      actions={d.can.create && <Btn kind="primary" icon={<FilePlus2 size={16} />} onClick={() => setCreate(true)}>{L('عقد جديد', 'New contract')}</Btn>} />
    <Tabs value={tab} onChange={v => setQuery({ tab: v === 'radar' ? undefined : v })} tabs={[
      { v: 'radar', label: L('رادار الانتهاء', 'Expiry radar'), count: d.radar.rows.length },
      { v: 'renewal', label: L('حالات التجديد', 'Renewal cases'), count: open('renewal') || undefined },
      { v: 'probation', label: L('تقييمات التجربة', 'Probation evaluations'), count: open('probation') || undefined },
      { v: 'register', label: L('سجل العقود', 'Contract register') }, { v: 'reports', label: L('التقارير', 'Reports') }]} />
    {(tab === 'radar' || tab === 'register') && <Filters d={d} f={f} />}
    {tab === 'radar' && <Radar rows={d.radar.rows} windows={d.radar.windows} canOpen={d.can.create} />}
    {(tab === 'renewal' || tab === 'probation') && <CaseList kind={tab} canBulk={d.can.decide} />}
    {tab === 'register' && <Register />}
    {tab === 'reports' && <Reports />}
    {caseId && <CaseDrawer id={caseId} onClose={() => setQuery({ case: undefined })} />}
    {contractId && <ContractDrawer id={contractId} onClose={() => setQuery({ contract: undefined })} />}
    {create && <NewContract employees={d.employees} onClose={() => setCreate(false)} />}
  </div>
}

function Filters({ d, f }: { d: NonNullable<ReturnType<typeof useContracts>['data']>; f: Record<string, string | undefined> }) {
  const L = useL(); const lang = useLang()
  const sel = (k: string, label: string, opts: { id: number; ar: string; en: string; code?: string }[]) => <select className="field" value={f[k] ?? ''} aria-label={label}
    onChange={e => setQuery({ [k]: e.target.value || undefined })}><option value="">{label}</option>{opts.map(o => <option key={o.id} value={o.id}>{o.code ? `${o.code} · ` : ''}{lang === 'ar' ? o.ar : o.en}</option>)}</select>
  return <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">{sel('dept', L('كل الإدارات', 'All departments'), d.departments)}{sel('branch', L('كل الفروع', 'All branches'), d.branches)}
    {sel('type', L('كل أنواع العقود', 'All contract types'), d.types)}{sel('category', L('كل فئات العاملين', 'All worker categories'), d.categories)}</div>
}

/** رادار الانتهاء بنوافذ 90/60/30/15 يوماً وألوان حسب القرب (FR-CR-002) */
function Radar({ rows, windows, canOpen }: { rows: RadarRow[]; windows: number[]; canOpen: boolean }) {
  const L = useL(); const lang = useLang()
  const openCase = useOpenCase()
  const [win, setWin] = useState<number | 'all'>('all')
  const shown = rows.filter(r => win === 'all' || r.bucket <= win)
  const tone = (b: number) => b <= 0 ? 'border-danger bg-danger-soft' : b <= 15 ? 'border-danger/60' : b <= 30 ? 'border-brass' : 'border-line'
  return <div className="space-y-3">
    <div className="flex flex-wrap gap-2">{[...windows.map(w => ({ w, n: rows.filter(r => r.bucket <= w).length })), { w: 'all' as const, n: rows.length }].map(x =>
      <button key={x.w} onClick={() => setWin(x.w)} className={`h-9 px-3 rounded-full border text-[13px] ${win === x.w ? 'bg-brand text-on-brand border-brand' : 'border-line hover:bg-sub'}`}>
        {x.w === 'all' ? L('الكل', 'All') : L(`خلال ${x.w} يوماً`, `Within ${x.w} days`)} <span className="num">({x.n})</span></button>)}</div>
    {shown.length === 0 ? <Panel><Empty title={L('لا عقود تنتهي خلال هذه الفترة', 'No contracts end in this window')} /></Panel> :
      <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(290px,1fr))]">{shown.map(r => <div key={`${r.kind}${r.contract.id}`} className={`rounded-2xl border-2 bg-surface p-4 ${tone(r.left)}`}>
        <div className="flex items-start gap-2"><div className="min-w-0 flex-1"><div className="font-semibold truncate">{bi(r.employee, lang)}</div>
          <div className="text-[12px] text-ink-3">{r.employee.code}{r.employee.dept ? ` · ${bi(r.employee.dept, lang)}` : ''}</div></div>
          <Badge tone={r.kind === 'probation' ? 'info' : 'neutral'}>{r.kind === 'probation' ? L('نهاية التجربة', 'Probation end') : L('نهاية العقد', 'Contract end')}</Badge></div>
        <div className="mt-2 flex items-center gap-2 text-[13px]"><CalendarClock size={15} className="text-ink-3" /><span className="num">{fmtDate(r.date, lang)}</span> · <Left d={r.left} /></div>
        <div className="mt-1 text-[12px] text-ink-3"><span className="ltr inline-block">{r.contract.ref_no}</span>{r.contract.type ? ` · ${bi(r.contract.type, lang)}` : ''}</div>
        <div className="mt-3 flex gap-2">{r.case ? <Btn size="sm" onClick={() => setQuery({ case: String(r.case!.id) })}>{L('فتح الحالة', 'Open the case')} · <span className="ltr">{r.case.ref_no}</span></Btn>
          : canOpen && <Btn size="sm" kind="primary" disabled={openCase.isPending} onClick={() => openCase.mutate({ contract_id: r.contract.id, kind: r.kind }, { onSuccess: k => setQuery({ case: String(k.id) }), onError: e => toast((e as Error).message) })}>
            {r.kind === 'probation' ? L('بدء تقييم التجربة', 'Start the evaluation') : L('بدء التجديد الآن', 'Start renewal now')}</Btn>}
          <Btn size="sm" kind="ghost" onClick={() => setQuery({ contract: String(r.contract.id) })}>{L('العقد', 'Contract')}</Btn></div>
      </div>)}</div>}
  </div>
}

function CaseList({ kind, canBulk }: { kind: 'renewal' | 'probation'; canBulk: boolean }) {
  const L = useL(); const lang = useLang()
  const [status, setStatus] = useState('open'); const [q, setQ] = useState('')
  const { data } = useCases({ kind, status, q: q || undefined })
  const bulk = useBulk()
  const [sel, setSel] = useState<number[]>([])
  const ready = (data ?? []).filter(c => c.status === 'decision' && c.can?.decide && c.suggestion && c.suggestion.decision !== 'decide')
  const groups = useMemo(() => [...new Set(ready.filter(c => sel.includes(c.id)).map(c => c.suggestion!.decision))], [ready, sel])
  return <div className="space-y-3">
    <div className="flex flex-wrap gap-2 items-center">
      <Seg value={status} onChange={setStatus} options={[{ v: 'open', label: L('المفتوحة', 'Open') }, { v: 'closed', label: L('المغلقة', 'Closed') }, { v: 'all', label: L('الكل', 'All') }]} />
      <div className="relative flex-1 min-w-[200px]"><Search size={15} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" /><input className="field !ps-9" value={q} onChange={e => setQ(e.target.value)} placeholder={L('بحث بالاسم أو الرقم', 'Search by name or reference')} /></div>
      {canBulk && sel.length > 0 && groups.length === 1 && <ConfirmSend label={<>{L('قرار جماعي', 'Bulk decision')}: {L(...DECISION[groups[0] as Decision])} ({sel.length})</>} busy={bulk.isPending}
        onConfirm={() => bulk.mutate({ ids: sel, decision: groups[0] as Decision }, { onSuccess: r => { toast(L(`نُفّذ ${r.done.length}${r.skipped.length ? `، وتُخطّي ${r.skipped.length}` : ''}`, `${r.done.length} done${r.skipped.length ? `, ${r.skipped.length} skipped` : ''}`)); setSel([]) } })} />}
    </div>
    {!data ? <Loader2 className="animate-spin text-brand" /> : data.length === 0 ? <Panel><Empty title={L('لا حالات', 'No cases')}>{kind === 'renewal' ? L('تُفتح حالة التجديد آلياً قبل انتهاء العقد بالمهلة المحددة في الإعدادات.', 'Renewal cases open automatically before the contract ends, per the settings.') : L('يُفتح تقييم التجربة آلياً قبل نهاية الفترة.', 'Probation evaluations open automatically before the period ends.')}</Empty></Panel> :
      <Panel pad={false}><table className="tbl"><thead><tr>{canBulk && <th className="w-8" />}<th>{L('الموظف', 'Employee')}</th><th>{L('الاستحقاق', 'Due')}</th><th className="hidden md:table-cell">{L('المقيِّم', 'Evaluator')}</th>
        <th className="hidden lg:table-cell">{L('النتيجة / الاقتراح', 'Result / suggestion')}</th><th>{L('الحالة', 'Status')}</th></tr></thead>
        <tbody>{data.map(c => <tr key={c.id} className="cursor-pointer" onClick={() => setQuery({ case: String(c.id) })}>
          {canBulk && <td onClick={e => e.stopPropagation()}>{ready.some(r => r.id === c.id) && <input type="checkbox" className="accent-[var(--brand)]" checked={sel.includes(c.id)} onChange={e => setSel(e.target.checked ? [...sel, c.id] : sel.filter(x => x !== c.id))} aria-label={c.ref_no} />}</td>}
          <td><div className="font-medium">{bi(c.employee, lang)}</div><div className="text-[12px] text-ink-3"><span className="ltr inline-block">{c.ref_no}</span> · {c.employee.dept ? bi(c.employee.dept, lang) : ''}</div></td>
          <td><div className="num">{fmtDate(c.due_on, lang)}</div>{c.status !== 'closed' && <Left d={c.left} />}</td>
          <td className="hidden md:table-cell">{c.evaluator ? bi(c.evaluator, lang) : <span className="text-ink-3">{L('الموارد البشرية', 'HR')}</span>}</td>
          <td className="hidden lg:table-cell text-[13px]">{c.total !== null && <span className="num">{c.total}/100 · </span>}{c.suggestion ? L(...DECISION[c.suggestion.decision]) : '—'}{c.exception && <Badge tone="danger">{L('استثناء', 'Exception')}</Badge>}</td>
          <td><div className="flex flex-wrap gap-1"><CaseBadge c={c} />{c.late && <Badge tone="danger"><AlertTriangle size={11} />{L('متأخرة', 'Late')}</Badge>}{c.lapsed && <Badge tone="danger">{L('منتهٍ دون إجراء', 'No action')}</Badge>}</div></td>
        </tr>)}</tbody></table></Panel>}
  </div>
}

/** لوحة الحالة: الملخص، التقييم، التواقيع، مراجعة HR، القرار والتنفيذ */
function CaseDrawer({ id, onClose }: { id: number; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const { data: c } = useCase(id)
  return <Drawer open onClose={onClose} width={720}>
    {!c ? <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div> : <div className="p-5 space-y-5">
      <div><div className="flex flex-wrap items-center gap-2"><h2 className="text-[19px] font-bold">{c.kind === 'renewal' ? L('تجديد العقد', 'Contract renewal') : L('تقييم فترة التجربة', 'Probation evaluation')}</h2><CaseBadge c={c} /></div>
        <div className="text-[13px] text-ink-2 mt-1">{bi(c.employee, lang)} · {bi(c.employee.title, lang)} · <span className="ltr inline-block">{c.ref_no}</span></div>
        <div className="text-[13px] mt-1">{L('الاستحقاق', 'Due')}: <span className="num">{fmtDate(c.due_on, lang)}</span> · <Left d={c.left} />{c.manager_due && c.status === 'manager' && <span className="text-ink-3"> · {L('مهلة المدير', 'Manager deadline')} {fmtDate(c.manager_due, lang)}</span>}</div></div>
      <SummaryLine c={c} />
      <Section n={1} title={L('تقييم المدير', 'Manager evaluation')} done={!!c.evaluation}>{c.can?.evaluate ? <EvalForm c={c} /> : <EvalView c={c} />}
        {c.review?.returned && <div className="mt-2 text-[13px] text-brass">{L('أُعيد للمدير: ', 'Returned to the manager: ')}{c.review.returned}</div>}</Section>
      {c.status === 'hr_review' && c.can?.review && <Review c={c} />}
      {(c.status === 'decision' || c.decision) && <Section n={3} title={L('القرار', 'Decision')} done={!!c.decision && c.status !== 'decision'}>{c.status === 'decision' && c.can?.decide ? <DecidePanel c={c} /> : <DecisionView c={c} />}</Section>}
      {c.new_contract && <Section n={4} title={L('العقد الجديد', 'The new contract')} done={c.new_contract.status === 'active' || c.new_contract.status === 'signed'}>
        <div className="flex flex-wrap items-center gap-2 text-[13px]"><span className="ltr inline-block font-semibold">{c.new_contract.ref_no}</span><ContractBadge s={c.new_contract.status} />
          <span className="num">{fmtDate(c.new_contract.starts_on, lang)} — {c.new_contract.ends_on ? fmtDate(c.new_contract.ends_on, lang) : L('غير محدد', 'open-ended')}</span>
          <Btn size="sm" onClick={() => setQuery({ case: undefined, contract: String(c.new_contract!.id) })}>{L('التواقيع والنسخ', 'Signatures & copies')}</Btn></div></Section>}
      <div className="flex flex-wrap gap-3 border-t border-line-2 pt-3">
        {c.evaluation && <PrintLink href={`/hr/contracts/print/evaluation/${c.id}`}>{c.kind === 'probation' ? L('نموذج تقييم التجربة', 'Probation evaluation form') : L('تقييم التجديد', 'Renewal evaluation')}</PrintLink>}
        {c.letters.map(w => <PrintLink key={w} href={`/hr/contracts/print/letter/${c.id}?which=${w}`}>{L('خطاب الإخطار', 'Notification letter')}</PrintLink>)}
        {c.resignation_id && <a className="text-brand text-[13px] font-semibold" href={`/hr/offboarding?status=all&id=${c.resignation_id}`}>{L('حالة إنهاء الخدمة', 'The exit case')}</a>}
      </div>
    </div>}
  </Drawer>
}

function Section({ n, title, done, children }: { n: number; title: string; done: boolean; children: React.ReactNode }) {
  return <section className="rounded-2xl border border-line p-4"><div className="flex items-center gap-2 mb-3"><span className={`size-6 rounded-full grid place-items-center text-[12px] font-bold ${done ? 'bg-brand text-on-brand' : 'bg-sub'}`}>{n}</span><h3 className="font-bold">{title}</h3></div>{children}</section>
}

function Review({ c }: { c: CaseFull }) {
  const L = useL(); const lang = useLang()
  const rv = useReview()
  const [checks, setChecks] = useState<Record<string, boolean>>({}); const [note, setNote] = useState('')
  const err = rv.error instanceof ApiError ? rv.error : null
  const all = c.review_checks.every(x => checks[x.key])
  return <Section n={2} title={L('مراجعة الموارد البشرية', 'HR review')} done={false}>
    <div className="space-y-2">{c.review_checks.map(x => <label key={x.key} className="flex items-center gap-2 text-[14px]"><input type="checkbox" className="accent-[var(--brand)]" checked={!!checks[x.key]} onChange={e => setChecks({ ...checks, [x.key]: e.target.checked })} />{bi(x, lang)}</label>)}</div>
    <Field label={L('ملاحظة', 'Note')} className="mt-3" error={err?.field('note')}><textarea className="field min-h-16" value={note} onChange={e => setNote(e.target.value)} /></Field>
    {err?.field('checks') && <div className="text-[12px] text-danger mt-1">{err.field('checks')}</div>}
    <div className="flex flex-wrap gap-2 mt-3"><Btn kind="primary" disabled={!all || rv.isPending} onClick={() => rv.mutate({ id: c.id, approve: true, checks, note }, { onSuccess: () => toast(L('انتقلت الحالة للقرار', 'Moved to the decision')) })}>{L('مطابقة — إلى القرار', 'Complete — to the decision')}</Btn>
      <Btn disabled={rv.isPending} onClick={() => rv.mutate({ id: c.id, approve: false, note }, { onSuccess: () => toast(L('أُعيد التقييم للمدير', 'Returned to the manager')) })}>{L('إعادة للمدير', 'Return to the manager')}</Btn></div>
  </Section>
}

/** القرار مع اقتراح النظام والقاعدة؛ ما يخالفه يحتاج مبرراً ويُرفع للاعتماد (استثناء) */
function DecidePanel({ c }: { c: CaseFull }) {
  const L = useL(); const lang = useLang()
  const dec = useDecide()
  const types = useLookup('contract_types').data ?? []
  const sug = c.suggestion
  const [d, setD] = useState<Decision | ''>(sug && sug.decision !== 'decide' ? sug.decision : '')
  const sugType = types.find(t => t.code === sug?.type_code)?.id
  const [type, setType] = useState<number | null>(null); const [months, setMonths] = useState<string>(''); const [reason, setReason] = useState('')
  const err = dec.error instanceof ApiError ? dec.error : null
  const exception = !!sug && sug.decision !== 'decide' && d !== '' && d !== sug.decision && d !== 'extend'
  return <div className="space-y-3">
    {sug && <div className="rounded-xl bg-info-soft px-3 py-2 text-[13.5px]"><b>{L('اقتراح النظام', 'System suggestion')}: {L(...DECISION[sug.decision])}</b>
      {sug.type_code && <> · <span className="ltr inline-block">{sug.type_code}</span>{sug.months ? L(` لمدة ${sug.months} شهراً`, ` for ${sug.months} months`) : ''}</>}
      <div className="text-[12.5px] text-ink-2">{RULES[sug.rule] ? L(...RULES[sug.rule]) : sug.rule}{sug.years !== undefined ? L(` · سنوات متواصلة: ${sug.years}`, ` · continuous years: ${sug.years}`) : ''}{sug.total !== undefined ? L(` · المجموع ${sug.total}`, ` · total ${sug.total}`) : ''}</div></div>}
    <Field label={L('القرار', 'Decision')} div error={err?.field('decision')}><div className="flex flex-wrap gap-2">{c.decisions.filter(x => x !== 'extend' || c.extension_max > 0).map(x =>
      <button key={x} type="button" onClick={() => setD(x)} className={`h-9 px-3 rounded-lg border text-[13px] ${d === x ? 'bg-brand text-on-brand border-brand' : 'border-line hover:bg-sub'}`}>{L(...DECISION[x])}</button>)}</div></Field>
    {(d === 'renew' || d === 'convert') && <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('نوع العقد الجديد', 'New contract type')}><select className="field" value={type ?? sugType ?? ''} onChange={e => setType(Number(e.target.value) || null)}>{types.map(t => <option key={t.id} value={t.id}>{t.code} · {bi(t, lang)}</option>)}</select></Field>
      <Field label={L('المدة بالأشهر (0 = غير محدد)', 'Months (0 = open-ended)')} error={err?.field('months')}><input type="number" min={0} className="field num" placeholder={String(sug?.months ?? '')} value={months} onChange={e => setMonths(e.target.value)} /></Field></div>}
    {d === 'extend' && <Field label={L(`مدة التمديد (حتى ${c.extension_max} أشهر، مرة واحدة)`, `Extension (up to ${c.extension_max} months, once)`)} error={err?.field('months')}><input type="number" min={1} max={c.extension_max} className="field num" value={months} onChange={e => setMonths(e.target.value)} /></Field>}
    {exception && <div className="rounded-xl bg-danger-soft text-danger px-3 py-2 text-[13px]">{L('القرار يخالف اقتراح السياسة: اكتب المبرر، ويُرفع للاعتماد الأعلى قبل التنفيذ.', 'This decision departs from the policy suggestion: write the reason; it goes to higher approval before it is carried out.')}</div>}
    <Field label={L('المبرر / الملاحظة', 'Reason / note')} req={exception || d === 'extend'} error={err?.field('reason')}><textarea className="field min-h-16" value={reason} onChange={e => setReason(e.target.value)} /></Field>
    <ConfirmSend label={exception ? L('رفع الاستثناء للاعتماد', 'Send the exception for approval') : L('اعتماد القرار', 'Confirm the decision')} busy={dec.isPending} disabled={!d}
      summary={d ? <>{L(...DECISION[d as Decision])} — {bi(c.employee, lang)}</> : undefined}
      onConfirm={() => dec.mutate({ id: c.id, decision: d, contract_type_id: type ?? sugType ?? null, months: months === '' ? null : Number(months), reason }, { onSuccess: () => toast(L('سُجّل القرار', 'Decision recorded')) })} />
    {err && !Object.keys(err.errors).length && <div role="alert" className="text-[13px] text-danger">{err.message}</div>}
  </div>
}

function DecisionView({ c }: { c: CaseFull }) {
  const L = useL()
  if (!c.decision) return <div className="text-[13px] text-ink-3">{L('بانتظار القرار.', 'Awaiting the decision.')}</div>
  return <div className="text-[13.5px] space-y-1"><div><b>{L(...DECISION[c.decision.decision])}</b>{c.decision.months ? L(` · ${c.decision.months} شهراً`, ` · ${c.decision.months} months`) : ''}{c.decision.exception && <> <Badge tone="danger">{L('استثناء', 'Exception')}</Badge></>}</div>
    {c.decision.reason && <div className="text-ink-2">{c.decision.reason}</div>}{c.status === 'exception' && <div className="text-brass">{L('بانتظار اعتماد الرئيس التنفيذي من صندوق الاعتمادات.', 'Awaiting the CEO approval in the approvals inbox.')}</div>}</div>
}

// ———————————————— سجل العقود ————————————————

function Register() {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const [status, setStatus] = useState('live'); const [q, setQ] = useState('')
  const { data } = useContractList({ status: status === 'all' ? undefined : status, q: q || undefined, type: route.query.type })
  return <div className="space-y-3">
    <div className="flex flex-wrap gap-2 items-center"><Seg value={status} onChange={setStatus} options={[{ v: 'live', label: L('النافذة والجاري توقيعها', 'Live & in signature') }, { v: 'expired_no_action', label: L('منتهية دون إجراء', 'Ended without action') }, { v: 'all', label: L('الكل', 'All') }]} />
      <div className="relative flex-1 min-w-[200px]"><Search size={15} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" /><input className="field !ps-9" value={q} onChange={e => setQ(e.target.value)} placeholder={L('بحث بالاسم أو رقم العقد', 'Search by name or contract no.')} /></div></div>
    {!data ? <Loader2 className="animate-spin text-brand" /> : data.length === 0 ? <Panel><Empty title={L('لا عقود', 'No contracts')} /></Panel> :
      <Panel pad={false}><table className="tbl"><thead><tr><th>{L('العقد', 'Contract')}</th><th>{L('الموظف', 'Employee')}</th><th className="hidden md:table-cell">{L('المدة', 'Term')}</th><th className="hidden lg:table-cell">{L('التجربة', 'Probation')}</th><th>{L('الحالة', 'Status')}</th></tr></thead>
        <tbody>{data.map(c => <tr key={c.id} className="cursor-pointer" onClick={() => setQuery({ contract: String(c.id) })}>
          <td><span className="ltr inline-block font-semibold num whitespace-nowrap">{c.ref_no}</span><div className="text-[12px] text-ink-3">{c.type ? bi(c.type, lang) : '—'}</div></td>
          <td>{bi(c.employee, lang)}<div className="text-[12px] text-ink-3">{c.employee.code}</div></td>
          <td className="hidden md:table-cell num text-[13px]">{fmtDate(c.starts_on, lang)} — {c.ends_on ? fmtDate(c.ends_on, lang) : L('غير محدد', 'open-ended')}</td>
          <td className="hidden lg:table-cell num text-[13px]">{c.probation_ends_on ? fmtDate(c.probation_ends_on, lang) : '—'}</td>
          <td><ContractBadge s={c.status} />{c.exception && <Badge tone="danger">{L('استثناء', 'Exception')}</Badge>}</td></tr>)}</tbody></table></Panel>}
  </div>
}

function ContractDrawer({ id, onClose }: { id: number; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const { data: c } = useContract(id)
  const sign = useSignContract(); const copy = useCopy(); const cancel = useCancelContract()
  const [annex, setAnnex] = useState(false); const [cancelReason, setCancelReason] = useState<string | null>(null); const [busy, setBusy] = useState(false)
  return <Drawer open onClose={onClose} width={680}>
    {!c ? <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div> : <div className="p-5 space-y-4">
      <div className="flex flex-wrap items-center gap-2"><h2 className="text-[19px] font-bold ltr">{c.ref_no}</h2><ContractBadge s={c.status} />{c.exception && <Badge tone="danger">{L('استثناء', 'Exception')}</Badge>}</div>
      <div className="text-[13.5px] space-y-1"><div>{bi(c.employee, lang)} · {c.employee.code}</div><div>{c.type ? bi(c.type, lang) : '—'} · <span className="num">{fmtDate(c.starts_on, lang)} — {c.ends_on ? fmtDate(c.ends_on, lang) : L('غير محدد المدة', 'open-ended')}</span></div>
        {c.probation_ends_on && <div>{L('نهاية التجربة', 'Probation ends')}: <span className="num">{fmtDate(c.probation_ends_on, lang)}</span></div>}{c.exception_reason && <div className="text-danger">{c.exception_reason}</div>}{c.note && <div className="text-ink-3">{c.note}</div>}</div>
      <Panel title={L('التواقيع', 'Signatures')}><ContractSigs c={c} />
        <div className="flex flex-wrap gap-2 mt-3">{c.signatures.filter(s => s.can && s.role !== 'employee').map(s => <ConfirmSend key={s.role} label={<>{L('توقيعي بصفتي', 'Sign as')} {L(s.ar, s.en)}</>} busy={sign.isPending}
          onConfirm={() => sign.mutate({ id: c.id, role: s.role }, { onSuccess: () => toast(L('سُجّل التوقيع', 'Signature recorded')), onError: e => toast((e as Error).message) })} />)}
          {['pending_signatures', 'signed', 'active'].includes(c.status) && <label className="inline-flex items-center gap-2 h-9 px-3 rounded-lg border border-line cursor-pointer text-[13px] hover:bg-sub"><Upload size={15} />{busy ? L('جارٍ الرفع…', 'Uploading…') : L('رفع النسخة الموقعة', 'Upload the signed copy')}
            <input type="file" accept=".pdf,.jpg,.jpeg,.png" className="hidden" onChange={async e => { const f = e.target.files?.[0]; if (!f) return; setBusy(true); try { await uploadSigned(c.id, f); toast(L('رُفعت النسخة', 'Copy uploaded')) } catch (x) { toast((x as Error).message) } finally { setBusy(false) } }} /></label>}
        </div></Panel>
      {c.copies.length > 0 && <Panel title={L('النسخ الثلاث', 'The three copies')}><div className="space-y-2">{c.copies.map(x => <label key={x.key} className="flex items-center gap-2 text-[14px]">
        <input type="checkbox" className="accent-[var(--brand)]" checked={x.status === 'done'} onChange={e => copy.mutate({ id: c.id, key: x.key, status: e.target.checked ? 'done' : 'pending' })} />{L(x.ar, x.en)}
        {x.at && <span className="text-[12px] text-ink-3 num">{fmtDate(x.at, lang)}</span>}</label>)}</div></Panel>}
      {c.annexes.length > 0 && <Panel title={L('الملاحق', 'Annexes')}><ul className="space-y-2 text-[13px]">{c.annexes.map(a => <li key={a.id}><span className="ltr inline-block font-semibold">{a.ref_no}</span> · <span className="num">{fmtDate(a.effective_on, lang)}</span> · {a.text}
        <PrintLink href={`/hr/contracts/print/annex/${a.id}`}>{L('طباعة', 'Print')}</PrintLink></li>)}</ul></Panel>}
      <Panel title={L('عقود الموظف', "Employee's contracts")}><ul className="space-y-1 text-[13px]">{c.history.map(h => <li key={h.id} className="flex flex-wrap gap-2 items-center"><button className="ltr text-brand" onClick={() => setQuery({ contract: String(h.id) })}>{h.ref_no}</button>
        <span className="num">{fmtDate(h.starts_on, lang)} — {h.ends_on ? fmtDate(h.ends_on, lang) : '∞'}</span><ContractBadge s={h.status} /></li>)}</ul></Panel>
      <div className="flex flex-wrap gap-2 border-t border-line-2 pt-3">
        <PrintLink href={`/hr/contracts/print/contract/${c.id}`}>{L('طباعة العقد', 'Print the contract')}</PrintLink>
        {c.status === 'active' && <Btn size="sm" icon={<Plus size={14} />} onClick={() => setAnnex(true)}>{L('ملحق عقد', 'Contract annex')}</Btn>}
        {['draft', 'pending_signatures'].includes(c.status) && <Btn size="sm" kind="danger" onClick={() => setCancelReason('')}>{L('إلغاء العقد', 'Cancel the contract')}</Btn>}
      </div>
      {annex && <AnnexModal id={c.id} onClose={() => setAnnex(false)} />}
      <Modal open={cancelReason !== null} onClose={() => setCancelReason(null)} title={L('إلغاء العقد', 'Cancel the contract')}
        footer={<><Btn onClick={() => setCancelReason(null)}>{L('رجوع', 'Back')}</Btn><Btn kind="danger" disabled={(cancelReason ?? '').trim().length < 5 || cancel.isPending} onClick={() => cancel.mutate({ id: c.id, reason: cancelReason }, { onSuccess: () => setCancelReason(null) })}>{L('تأكيد الإلغاء', 'Confirm')}</Btn></>}>
        <Field label={L('السبب', 'Reason')} req><textarea className="field min-h-16" value={cancelReason ?? ''} onChange={e => setCancelReason(e.target.value)} /></Field></Modal>
    </div>}
  </Drawer>
}

function AnnexModal({ id, onClose }: { id: number; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const save = useAnnex()
  const jobs = useLookup('jobs').data ?? []; const grades = useLookup('grades').data ?? []
  const [v, setV] = useState({ kind: 'position', effective_on: new Date().toISOString().slice(0, 10), job_id: '', grade_id: '', text: '' })
  const err = save.error instanceof ApiError ? save.error : null
  return <Modal open onClose={onClose} title={L('ملحق عقد', 'Contract annex')} width={600}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><ConfirmSend label={L('إصدار الملحق', 'Issue the annex')} busy={save.isPending} onConfirm={() => save.mutate({ id, ...v, job_id: v.job_id || null, grade_id: v.grade_id || null }, { onSuccess: () => { toast(L('صدر الملحق', 'Annex issued')); onClose() } })} /></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('نوع التعديل', 'Change')} div className="sm:col-span-2"><Seg value={v.kind} onChange={k => setV({ ...v, kind: k })} options={[{ v: 'position', label: L('المنصب', 'Position') }, { v: 'grade', label: L('الدرجة', 'Grade') }, { v: 'other', label: L('أخرى', 'Other') }]} /></Field>
      {v.kind === 'position' && <Field label={L('المسمى الجديد', 'New job title')} error={err?.field('job_id')}><select className="field" value={v.job_id} onChange={e => setV({ ...v, job_id: e.target.value })}><option value="">—</option>{jobs.map(j => <option key={j.id} value={j.id}>{j.code} · {bi(j, lang)}</option>)}</select></Field>}
      {v.kind !== 'other' && <Field label={L('الدرجة', 'Grade')} error={err?.field('grade_id')}><select className="field" value={v.grade_id} onChange={e => setV({ ...v, grade_id: e.target.value })}><option value="">—</option>{grades.map(j => <option key={j.id} value={j.id}>{j.code} · {bi(j, lang)}</option>)}</select></Field>}
      <Field label={L('تاريخ السريان', 'Effective date')} error={err?.field('effective_on')}><input type="date" className="field" value={v.effective_on} onChange={e => setV({ ...v, effective_on: e.target.value })} /></Field>
      <Field label={L('نص الملحق', 'Annex text')} req className="sm:col-span-2" error={err?.field('text')}><textarea className="field min-h-20" value={v.text} onChange={e => setV({ ...v, text: e.target.value })} /></Field>
    </div>
    {err && !Object.keys(err.errors).length && <div role="alert" className="mt-3 text-[13px] text-danger">{err.message}</div>}
  </Modal>
}

function NewContract({ employees, onClose }: { employees: (ContractRow['employee'])[]; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const save = useCreateContract()
  const types = useLookup('contract_types').data ?? []
  const [v, setV] = useState({ employee_id: '', contract_type_id: '', starts_on: new Date().toISOString().slice(0, 10), months: '', probation_months: '', note: '' })
  const err = save.error instanceof ApiError ? save.error : null
  return <Modal open onClose={onClose} title={L('عقد جديد', 'New contract')} width={600}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><ConfirmSend label={L('إعداد العقد', 'Prepare the contract')} busy={save.isPending} disabled={!v.employee_id || !v.contract_type_id}
      onConfirm={() => save.mutate({ ...v, months: v.months === '' ? null : Number(v.months), probation_months: v.probation_months === '' ? null : Number(v.probation_months) }, { onSuccess: c => { toast(L('أُعدّ العقد بانتظار التواقيع', 'Contract prepared, awaiting signatures')); onClose(); setQuery({ tab: 'register', contract: String(c.id) }) } })} /></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('الموظف', 'Employee')} req className="sm:col-span-2" error={err?.field('employee_id')}><select className="field" value={v.employee_id} onChange={e => setV({ ...v, employee_id: e.target.value })}><option value="">—</option>{employees.map(e => <option key={e.id} value={e.id}>{e.code} · {bi(e, lang)}</option>)}</select></Field>
      <Field label={L('نوع العقد', 'Contract type')} req error={err?.field('contract_type_id')}><select className="field" value={v.contract_type_id} onChange={e => setV({ ...v, contract_type_id: e.target.value })}><option value="">—</option>{types.map(t => <option key={t.id} value={t.id}>{t.code} · {bi(t, lang)}</option>)}</select></Field>
      <Field label={L('تاريخ البدء', 'Start date')} error={err?.field('starts_on')}><input type="date" className="field" value={v.starts_on} onChange={e => setV({ ...v, starts_on: e.target.value })} /></Field>
      <Field label={L('المدة بالأشهر (فارغ = مدة النوع)', 'Months (empty = type default)')} error={err?.field('months')}><input type="number" min={0} className="field num" value={v.months} onChange={e => setV({ ...v, months: e.target.value })} /></Field>
      <Field label={L('فترة التجربة بالأشهر (فارغ = حسب النوع)', 'Probation months (empty = type default)')}><input type="number" min={0} className="field num" value={v.probation_months} onChange={e => setV({ ...v, probation_months: e.target.value })} /></Field>
      <Field label={L('ملاحظة', 'Note')} className="sm:col-span-2"><input className="field" value={v.note} onChange={e => setV({ ...v, note: e.target.value })} /></Field>
    </div>
    {err && !Object.keys(err.errors).length && <div role="alert" className="mt-3 text-[13px] text-danger">{err.message}</div>}
  </Modal>
}

function Reports() {
  const L = useL(); const lang = useLang()
  const y = new Date().getFullYear()
  const [from, setFrom] = useState(`${y}-01-01`); const [to, setTo] = useState(`${y}-12-31`)
  const { data } = useContractReports(from, to)
  return <div className="space-y-4">
    <div className="flex flex-wrap gap-3 items-end"><Field label={L('من', 'From')}><input type="date" className="field" value={from} onChange={e => setFrom(e.target.value)} /></Field><Field label={L('إلى', 'To')}><input type="date" className="field" value={to} onChange={e => setTo(e.target.value)} /></Field></div>
    {!data ? <Loader2 className="animate-spin text-brand" /> : <div className="grid gap-4 lg:grid-cols-2 items-start">
      <Panel pad={false} title={L('عقود تنتهي في الفترة', 'Contracts ending in the period')} sub={<span className="num">{data.ending.length}</span>}>
        {data.ending.length === 0 ? <Empty title={L('لا شيء', 'None')} /> : <ul className="divide-y divide-line-2 max-h-[360px] overflow-y-auto">{data.ending.map(c => <li key={c.id} className="px-4 py-2 text-[13px] flex gap-2"><span className="flex-1">{bi(c.employee, lang)}</span><span className="num">{fmtDate(c.ends_on ?? '', lang)}</span><ContractBadge s={c.status} /></li>)}</ul>}</Panel>
      <Panel title={L('نتائج فترات التجربة', 'Probation results')}><div className="grid grid-cols-2 gap-3 text-[14px]">
        {([['confirmed', L('ثُبِّت', 'Confirmed')], ['terminated', L('أُنهي', 'Ended')], ['extended', L('مُدِّد', 'Extended')], ['open', L('مفتوحة', 'Open')]] as const).map(([k, label]) => <div key={k} className="rounded-xl bg-sub p-3"><div className="text-ink-3 text-[12px]">{label}</div><div className="text-[22px] font-bold num">{data.probation[k]}</div></div>)}</div></Panel>
      <Panel pad={false} title={L('التجديد حسب الإدارة', 'Renewals by department')}>{data.renewal.length === 0 ? <Empty title={L('لا قرارات بعد', 'No decisions yet')} /> :
        <table className="tbl"><thead><tr><th>{L('الإدارة', 'Department')}</th><th>{L('جُدِّد', 'Renewed')}</th><th>{L('لم يُجدَّد', 'Not renewed')}</th></tr></thead><tbody>{data.renewal.map(r => <tr key={r.dept}><td>{r.dept}</td><td className="num">{r.renewed}</td><td className="num">{r.not_renewed}</td></tr>)}</tbody></table>}</Panel>
      <Panel pad={false} title={L('حالات متأخرة واستثناءات', 'Late cases & exceptions')}>{[...data.late, ...data.exceptions].length === 0 ? <Empty title={L('لا شيء', 'None')} /> :
        <ul className="divide-y divide-line-2">{[...data.late.map(c => [c, 'late'] as const), ...data.exceptions.map(c => [c, 'exc'] as const)].map(([c, k]) => <li key={k + c.id} className="px-4 py-2 text-[13px] flex gap-2 items-center">
          <button className="ltr text-brand" onClick={() => setQuery({ case: String(c.id) })}>{c.ref_no}</button><span className="flex-1">{bi(c.employee, lang)}</span><Badge tone="danger">{k === 'late' ? L('متأخرة', 'Late') : L('استثناء', 'Exception')}</Badge></li>)}</ul>}</Panel>
    </div>}
  </div>
}

export type { CaseRow }
