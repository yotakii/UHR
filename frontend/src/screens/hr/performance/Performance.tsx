import { useState } from 'react'
import { Plus, Play, BellRing, CheckCheck, Download, Settings2, Pencil, X, UserPlus } from 'lucide-react'
import { useL, useLang, bi, fmtDate, money } from '../../../lib/store'
import { setQuery, useRoute } from '../../../lib/router'
import { ApiError } from '../../../lib/api'
import { BOXES, downloadPerformance, useCycleAction, useDecideAward, usePeer, usePerformance, usePlace, useReviewDetail, useSaveCycle, type Award, type Cycle, type PerfPage } from '../../../lib/performance'
import { Avatar, Badge, Btn, Drawer, Empty, Field, Modal, PageHead, Panel, Seg, Tabs, toast } from '../../../ui/kit'
import { Radar } from '../../../ui/viz'
import { DecisionDialog } from '../../../ui/decision'

const err = (e: unknown, f: string) => toast(e instanceof ApiError ? e.message : f)

export function CycleBadge({ s }: { s: Cycle['status'] }) {
  const L = useL()
  return s === 'open' ? <Badge tone="brand">{L('مفتوحة', 'Open')}</Badge> : s === 'draft' ? <Badge>{L('مسودة', 'Draft')}</Badge> : <Badge tone="info">{L('معتمدة ومغلقة', 'Approved')}</Badge>
}

export function AwardBadge({ s }: { s: Award['status'] }) {
  const L = useL()
  return ({ review: <Badge tone="warn">{L('للمراجعة', 'To review')}</Badge>, calculated: <Badge tone="info">{L('محتسبة', 'Calculated')}</Badge>, approved: <Badge tone="brand">{L('معتمدة', 'Approved')}</Badge>,
    paid: <Badge tone="brass">{L('مصروفة', 'Paid')}</Badge>, rejected: <Badge tone="danger">{L('مرفوضة', 'Rejected')}</Badge> })[s]
}

/** M13 — الأداء وتقييم 360 (Performance في الديمو): 9-Box بالسحب للمعايرة، القائمة بالنتيجة، اللوح الجانبي، ومكافآت التقييم */
export function Performance() {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const cycleId = route.query.cycle ? Number(route.query.cycle) : null
  const q = usePerformance(cycleId); const d = q.data
  const act = useCycleAction(); const place = usePlace()
  const [sel, setSel] = useState<number | null>(null); const [box, setBox] = useState<string | null>(null); const [drag, setDrag] = useState<number | null>(null)
  const [form, setForm] = useState<Cycle | 'new' | null>(null); const [approve, setApprove] = useState(false)
  const tab = route.query.tab === 'awards' ? 'awards' : 'grid'
  if (!d) return <div className="max-w-[1300px] mx-auto"><PageHead title={L('الأداء وتقييم 360', 'Performance & 360')} /></div>
  const c = d.cycle
  const live = c?.status === 'open' && d.can.edit
  const placed = d.reviews.filter(r => r.performance && r.potential)
  const doPlace = (id: number, p: number, qv: number) => place.mutate({ id, performance: p, potential: qv }, { onSuccess: () => toast(L('حُدّث تصنيف الموظف', 'Placement updated')), onError: e => err(e, L('تعذّر الحفظ', 'Could not save')) })
  const list = d.reviews.filter(x => !box || `${x.performance}${x.potential}` === box)
  const done = d.reviews.reduce((a, r) => a + r.progress.done, 0), total = d.reviews.reduce((a, r) => a + r.progress.total, 0)
  return <div className="max-w-[1300px] mx-auto">
    <PageHead title={L('الأداء وتقييم 360', 'Performance & 360')}
      sub={c ? <>{bi(c.name, lang)} — {c.status === 'open' && live ? L('اسحب الموظف بين المربعات لمعايرة التقييم.', 'drag people between boxes to calibrate.') : <CycleBadge s={c.status} />}</> : L('لا دورات تقييم بعد.', 'No review cycles yet.')}
      actions={<>
        {d.cycles.length > 1 && <select className="field !w-auto" value={c?.id ?? ''} onChange={e => setQuery({ cycle: e.target.value })}>{d.cycles.map(x => <option key={x.id} value={x.id}>{bi(x.name, lang)}</option>)}</select>}
        {d.can.settings && <a href="/hr/settings/performance"><Btn icon={<Settings2 size={16} />}>{L('الإعدادات', 'Settings')}</Btn></a>}
        {d.can.create && <Btn kind="primary" icon={<Plus size={16} />} onClick={() => setForm('new')}>{L('دورة جديدة', 'New cycle')}</Btn>}
      </>} />
    {c && <div className="mb-4 flex flex-wrap items-center gap-2 text-[13px]">
      <CycleBadge s={c.status} /><span className="text-ink-3 num">{fmtDate(c.starts_on, lang)} — {fmtDate(c.ends_on, lang)}</span>{c.department && <Badge>{bi(c.department, lang)}</Badge>}
      {c.status !== 'draft' && <span className="text-ink-3">· {L('النماذج المرسلة', 'Forms submitted')}: <span className="num">{done}/{total}</span></span>}
      <span className="flex-1" />
      {c.status === 'draft' && d.can.edit && <><Btn size="sm" icon={<Pencil size={14} />} onClick={() => setForm(c)}>{L('تعديل', 'Edit')}</Btn>
        <Btn size="sm" kind="primary" icon={<Play size={14} />} disabled={act.isPending} onClick={() => act.mutate({ id: c.id, action: 'open' }, { onSuccess: () => toast(L('فُتحت الدورة وأُرسلت النماذج للمقيّمين', 'Cycle opened — forms sent to raters')), onError: e => err(e, L('تعذّر الفتح', 'Could not open')) })}>{L('فتح الدورة وإرسال النماذج', 'Open & send forms')}</Btn></>}
      {c.status === 'open' && d.can.edit && <Btn size="sm" icon={<BellRing size={14} />} disabled={act.isPending} onClick={() => act.mutate({ id: c.id, action: 'remind' }, { onSuccess: r => toast(L(`ذُكّر ${r.reminded} مقيّم`, `${r.reminded} rater(s) reminded`)), onError: e => err(e, L('تعذّر التذكير', 'Could not remind')) })}>{L('تذكير المتأخرين', 'Remind')}</Btn>}
      {c.status === 'open' && d.can.decide && <Btn size="sm" kind="primary" icon={<CheckCheck size={14} />} onClick={() => setApprove(true)}>{L('اعتماد النتائج', 'Approve results')}</Btn>}
      {c.status !== 'draft' && d.can.export && <Btn size="sm" icon={<Download size={14} />} onClick={() => downloadPerformance(c.id).catch(() => toast(L('تعذّر التصدير', 'Export failed')))}>Excel</Btn>}
    </div>}
    {!c ? <div className="bg-surface border border-line rounded-2xl"><Empty title={L('لا دورات تقييم', 'No review cycles')}>{L('أنشئ دورة وافتحها لتصل نماذج التقييم للموظفين ومدرائهم وزملائهم.', 'Create and open a cycle to send forms to employees, managers and peers.')}</Empty></div>
      : c.status === 'draft' ? <div className="bg-surface border border-line rounded-2xl"><Empty title={L('الدورة مسودة — لم تصل النماذج لأحد بعد', 'Draft cycle — no forms sent yet')}
        action={d.can.edit ? <Btn kind="primary" icon={<Play size={15} />} disabled={act.isPending} onClick={() => act.mutate({ id: c.id, action: 'open' }, { onSuccess: () => toast(L('فُتحت الدورة وأُرسلت النماذج للمقيّمين', 'Cycle opened — forms sent to raters')), onError: e => err(e, L('تعذّر الفتح', 'Could not open')) })}>{L('فتح الدورة وإرسال النماذج', 'Open & send forms')}</Btn> : undefined}>
        {L('عند الفتح: نموذج ذاتي لكل موظف، ونموذج لمديره المباشر، وزملاء من نفس الإدارة حسب الإعدادات. لا تُفتح دورتان لنفس الموظفين معاً — اعتمد الدورة المفتوحة أولاً.', 'On opening: a self form for each employee, one for the direct manager, and peers from the same department per the settings. Two cycles for the same people cannot be open together — approve the open one first.')}</Empty></div>
      : <>
        {c.status === 'closed' && <Tabs value={tab} onChange={v => setQuery({ ...route.query, tab: v === 'grid' ? undefined : v })} tabs={[{ v: 'grid', label: L('المصفوفة', 'Grid') }, { v: 'awards', label: L('مكافآت التقييم', 'Review rewards'), count: d.awards.filter(a => a.status === 'review' || a.status === 'calculated').length }]} />}
        {tab === 'awards' && c.status === 'closed' ? <div className="mt-4"><Awards d={d} /></div> : <div className={`grid xl:grid-cols-[1.2fr_1fr] gap-5 ${c.status === 'closed' ? 'mt-4' : ''}`}>
          <Panel title={L('مصفوفة الأداء والإمكانات', 'Performance–potential grid')} sub="9-Box">
            <div className="grid grid-cols-[22px_1fr] gap-2"><div className="flex items-center justify-center"><span className="-rotate-90 whitespace-nowrap text-[11px] text-ink-3">{L('الإمكانات ←', 'Potential →')}</span></div>
              <div className="grid grid-cols-3 gap-2">{[3, 2, 1].flatMap(qv => [1, 2, 3].map(p => { const k = `${p}${qv}`; const inBox = placed.filter(x => x.performance === p && x.potential === qv); const strong = p + qv >= 5, weak = p + qv <= 2
                return <div key={k} role="button" tabIndex={0} onClick={() => setBox(box === k ? null : k)} onKeyDown={e => e.key === 'Enter' && setBox(box === k ? null : k)} onDragOver={ev => live && ev.preventDefault()} onDrop={() => { if (drag && live) doPlace(drag, p, qv); setDrag(null) }}
                  className={`min-h-[118px] min-w-0 rounded-xl border p-2 text-start transition cursor-pointer ${box === k ? 'border-brand ring-2 ring-brand/30' : 'border-line'} ${strong ? 'bg-brand-soft' : weak ? 'bg-danger-soft' : 'bg-sub/60'}`}>
                  <div className="flex justify-between gap-1 text-[11.5px] font-semibold"><span className="min-w-0">{BOXES[k][lang === 'ar' ? 0 : 1]}</span><span className="num text-ink-3">{inBox.length}</span></div>
                  <div className="mt-2 flex flex-wrap gap-1">{inBox.slice(0, 8).map(x => <span key={x.id} draggable={live} onDragStart={() => setDrag(x.id)} onClick={ev => { ev.stopPropagation(); setSel(x.id) }}
                    className={`${live ? 'cursor-grab' : 'cursor-pointer'} max-w-full truncate rounded-md border px-1.5 py-0.5 text-[11px] font-medium hover:border-brand border-line bg-surface`}>{bi(x.employee.name, lang)}</span>)}{inBox.length > 8 && <span className="text-[11px] text-ink-3 px-1">+{inBox.length - 8}</span>}</div>
                </div> }))}</div>
              <span /><div className="text-center text-[11px] text-ink-3">{L('الأداء ←', 'Performance →')}</div></div>
          </Panel>
          <Panel title={box ? BOXES[box][lang === 'ar' ? 0 : 1] : L('كل الموظفين', 'Everyone')} sub={box ? <button className="text-brand" onClick={() => setBox(null)}>{L('إظهار الكل', 'Show all')}</button> : undefined} pad={false}>
            {list.length === 0 ? <Empty title={L('لا أحد هنا', 'Nobody here')} /> : <ul className="divide-y divide-line-2 max-h-[470px] overflow-y-auto">{list.map(x =>
              <li key={x.id}><button onClick={() => setSel(x.id)} className="w-full flex items-center gap-3 px-5 py-2.5 hover:bg-sub/60 text-start"><Avatar name={x.employee.name.ar} size={32} />
                <span className="flex-1 min-w-0"><span className="block font-semibold text-[13.5px] truncate">{bi(x.employee.name, lang)}</span><span className="block text-[12px] text-ink-3 truncate">{bi(x.employee.title, lang)}{c.status === 'open' && <> · <span className="num">{x.progress.done}/{x.progress.total}</span></>}</span></span>
                {x.score !== null ? <span className="flex items-center gap-2"><span className="num text-[12px] text-ink-3">{x.score.toFixed(1)}</span><span className="flex gap-0.5">{[1, 2, 3, 4, 5].map(i => <span key={i} className={`h-4 w-1.5 rounded-sm ${i <= Math.round(x.score! / d.config.scale * 5) ? 'bg-brand' : 'bg-sub'}`} />)}</span></span>
                  : <span className="text-[12px] text-ink-3">{L('بانتظار النماذج', 'Awaiting forms')}</span>}</button></li>)}</ul>}
          </Panel>
        </div>}
      </>}
    {sel && <ReviewDrawer id={sel} d={d} live={!!live} onPlace={doPlace} onClose={() => setSel(null)} />}
    {form && <CycleForm cycle={form === 'new' ? null : form} d={d} onClose={() => setForm(null)} />}
    {approve && c && <DecisionDialog open onClose={() => setApprove(false)} title={L('اعتماد نتائج الدورة', 'Approve the cycle results')}
      subject={<>{bi(c.name, lang)} — {L('تُغلق الدورة، تُعتمد كل نتيجة مكتملة، وتُحتسب مكافآت التقييم حسب الجدول. النماذج غير المرسلة تُلغى.', 'The cycle closes, every scored review is approved and rewards are calculated by the table. Unsent forms are dropped.')}</>}
      confirmLabel={L('اعتماد وإغلاق', 'Approve & close')} onConfirm={() => act.mutateAsync({ id: c.id, action: 'approve' }).then(r => { const x = r.result as Record<string, number>; toast(L(`اعتُمدت ${x.approved} نتيجة`, `${x.approved} result(s) approved`)) })} />}
  </div>
}

function ReviewDrawer({ id, d, live, onPlace, onClose }: { id: number; d: PerfPage; live: boolean; onPlace: (id: number, p: number, q: number) => void; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const q = useReviewDetail(id); const r = q.data
  const peer = usePeer()
  const [add, setAdd] = useState('')
  const roleName = { self: L('ذاتي', 'Self'), manager: L('المدير', 'Manager'), peer: L('زميل', 'Peer') }
  const comp = d.competencies
  return <Drawer open onClose={onClose} width={560}>{r ? <div className="flex flex-col h-full">
    <header className="p-5 border-b border-line flex items-center gap-3"><Avatar name={r.employee.name.ar} size={44} /><div className="flex-1 min-w-0"><div className="font-display font-bold text-[17px] truncate">{bi(r.employee.name, lang)}</div><div className="text-[12.5px] text-ink-3 truncate">{bi(r.employee.title, lang)}</div></div>
      <a href={`/hr/employees/${r.employee.id}`}><Btn size="sm">{L('الملف', 'File')}</Btn></a></header>
    <div className="p-5 overflow-y-auto space-y-5">
      {r.score !== null && <div className="flex justify-center"><Radar size={300} axes={comp.map(c => ({ label: bi(c, lang), value: (r.ratings[c.id]?.manager ?? r.ratings[c.id]?.total ?? 0) / d.config.scale }))} /></div>}
      <table className="tbl"><thead><tr><th>{L('الكفاءة', 'Competency')}</th><th>{L('ذاتي', 'Self')}</th><th>{L('المدير', 'Manager')}</th><th>{L('الزملاء', 'Peers')}</th></tr></thead>
        <tbody>{comp.map(c => { const x = r.ratings[c.id]; return <tr key={c.id}><td>{bi(c, lang)}</td>{(['self', 'manager', 'peers'] as const).map(k => <td key={k} className="num">{x?.[k] != null ? x[k]!.toFixed(1) : '—'}</td>)}</tr> })}</tbody></table>
      <div className="flex flex-wrap items-center gap-2 text-[13px]"><span className="font-semibold">{L('النتيجة', 'Score')}:</span><span className="num font-bold text-brand">{r.score?.toFixed(1) ?? '—'}</span><span className="text-ink-3">/ <span className="num">{d.config.scale}</span></span>
        {r.performance && r.potential && <Badge tone="info">{BOXES[`${r.performance}${r.potential}`][lang === 'ar' ? 0 : 1]}</Badge>}{r.calibrated && <Badge>{L('معايرة', 'Calibrated')}</Badge>}</div>
      <div><div className="text-[13px] font-semibold mb-2">{L('تصنيف المعايرة', 'Calibration')}</div>
        <div className="grid grid-cols-2 gap-3 text-[13px]">{([['p', L('الأداء', 'Performance')], ['q', L('الإمكانات', 'Potential')]] as const).map(([k, t]) => { const cur = k === 'p' ? r.performance : r.potential
          return <div key={k}><div className="text-ink-3 mb-1">{t}</div><div className="flex gap-1">{[1, 2, 3].map(v => <button key={v} disabled={!live} onClick={() => onPlace(r.id, k === 'p' ? v : r.performance ?? 2, k === 'q' ? v : r.potential ?? 2)}
            className={`flex-1 h-9 rounded-lg border text-[12px] font-semibold disabled:cursor-default ${cur === v ? 'bg-brand text-on-brand border-brand' : 'border-line'}`}>{[L('منخفض', 'Low'), L('متوسط', 'Mid'), L('مرتفع', 'High')][v - 1]}</button>)}</div></div> })}</div></div>
      <div><div className="text-[13px] font-semibold mb-2">{L('المقيّمون', 'Raters')}</div>
        <ul className="space-y-1.5">{r.forms.map(f => <li key={f.id} className="rounded-lg border border-line-2 px-3 py-2 text-[13px]">
          <div className="flex items-center gap-2"><Badge>{roleName[f.role]}</Badge><span className="flex-1 min-w-0 truncate">{f.rater ? bi(f.rater.name, lang) : '—'}</span>
            {f.status === 'submitted' ? <Badge tone="brand">{L('أُرسل', 'Sent')}</Badge> : <Badge tone="warn">{f.has_user ? L('بانتظاره', 'Pending') : L('بلا حساب', 'No account')}</Badge>}
            {live && f.role === 'peer' && f.status === 'pending' && <button aria-label={L('إزالة', 'Remove')} className="text-ink-3 hover:text-danger" onClick={() => peer.mutate({ id: r.id, remove: f.id }, { onSuccess: () => q.refetch(), onError: e => err(e, L('تعذّرت الإزالة', 'Could not remove')) })}><X size={15} /></button>}</div>
          {f.comment && f.role !== 'peer' && <p className="mt-1 text-[12.5px] text-ink-2">{f.comment}</p>}</li>)}</ul>
        {live && <div className="mt-2 flex gap-2"><select className="field flex-1 min-w-0" value={add} onChange={e => setAdd(e.target.value)}><option value="">{L('إضافة زميل مقيِّم…', 'Add a peer rater…')}</option>
          {d.employees.filter(x => x.id !== r.employee.id && !r.forms.some(f => f.rater?.id === x.id)).map(x => <option key={x.id} value={x.id}>{lang === 'ar' ? x.ar : x.en} · {x.code}</option>)}</select>
          <Btn icon={<UserPlus size={15} />} disabled={!add || peer.isPending} onClick={() => peer.mutate({ id: r.id, add: Number(add) }, { onSuccess: () => { setAdd(''); q.refetch(); toast(L('أُضيف وأُرسل له النموذج', 'Added — form sent')) }, onError: e => err(e, L('تعذّرت الإضافة', 'Could not add')) })}>{L('إضافة', 'Add')}</Btn></div>}
      </div>
      {r.award && <div className="rounded-xl border border-line p-3 text-[13px] flex flex-wrap items-center gap-2"><span className="font-semibold">{L('مكافأة التقييم', 'Review reward')}:</span><AwardText a={r.award} /><AwardBadge s={r.award.status} /></div>}
    </div></div> : <div className="p-6 text-ink-3">…</div>}</Drawer>
}

function AwardText({ a }: { a: Award }) {
  const L = useL(); const lang = useLang()
  if (a.points) return <span className="num font-semibold text-brass">{a.points} {L('نقطة', 'pts')}</span>
  if (a.amount) return <span className="num font-semibold text-brand">{money(a.amount, a.currency, lang)}{a.band?.kind === 'percent' && <span className="text-ink-3 font-normal"> ({a.band.value}%)</span>}</span>
  return <span className="text-ink-3">{L('خارج جدول المكافآت', 'Outside the table')}</span>
}

/** D-28: الاستحقاقات المحتسبة واعتمادها؛ ما خارج السلّم يُحدَّد يدوياً بسبب */
function Awards({ d }: { d: PerfPage }) {
  const L = useL(); const lang = useLang()
  const decide = useDecideAward()
  const [rej, setRej] = useState<Award | null>(null); const [man, setMan] = useState<Award | null>(null)
  if (!d.awards.length) return <div className="bg-surface border border-line rounded-2xl"><Empty title={L('لا استحقاقات', 'No awards')}>{L('تُحتسب عند اعتماد النتائج حسب جدول المكافآت في الإعدادات.', 'Calculated on approval by the rewards table in settings.')}</Empty></div>
  return <Panel pad={false}><table className="tbl"><thead><tr><th>{L('الموظف', 'Employee')}</th><th className="hidden sm:table-cell">{L('النتيجة', 'Score')}</th><th>{L('الاستحقاق', 'Award')}</th><th className="hidden md:table-cell">{L('الحالة', 'Status')}</th><th /></tr></thead>
    <tbody>{[...d.awards].sort((a, b) => b.score - a.score).map(a => <tr key={a.id}>
      <td><div className="font-medium">{a.employee ? bi(a.employee.name, lang) : '—'}</div><div className="sm:hidden text-[12px] text-ink-3 num">{a.score.toFixed(1)}</div><div className="md:hidden mt-0.5"><AwardBadge s={a.status} /></div></td>
      <td className="hidden sm:table-cell num">{a.score.toFixed(1)}</td>
      <td><AwardText a={a} />{a.month && <div className="text-[12px] text-ink-3">{L('كشف', 'Sheet')} <span className="num">{a.month}</span></div>}{a.note && <div className="text-[12px] text-ink-3 max-w-[260px]">{a.note}</div>}</td>
      <td className="hidden md:table-cell"><AwardBadge s={a.status} /></td>
      <td>{d.can.decide && (a.status === 'review' || a.status === 'calculated') && <div className="flex flex-wrap gap-1.5 justify-end">
        {a.status === 'calculated' ? <Btn size="sm" kind="primary" disabled={decide.isPending} onClick={() => decide.mutate({ id: a.id, approve: true }, { onSuccess: () => toast(L('اعتُمد الاستحقاق', 'Award approved')), onError: e => err(e, L('تعذّر الاعتماد', 'Could not approve')) })}>{L('اعتماد', 'Approve')}</Btn>
          : <Btn size="sm" kind="primary" onClick={() => setMan(a)}>{L('تحديد القيمة', 'Set value')}</Btn>}
        <Btn size="sm" onClick={() => setRej(a)}>{L('رفض', 'Reject')}</Btn></div>}</td>
    </tr>)}</tbody></table>
    {rej && <DecisionDialog open onClose={() => setRej(null)} tone="danger" reasonRequired minLength={d.reject_min} title={L('رفض الاستحقاق', 'Reject the award')} subject={rej.employee ? bi(rej.employee.name, lang) : ''}
      confirmLabel={L('رفض', 'Reject')} onConfirm={note => decide.mutateAsync({ id: rej.id, approve: false, note }).then(() => toast(L('رُفض الاستحقاق', 'Award rejected')))} />}
    {man && <ManualAward a={man} onClose={() => setMan(null)} />}
  </Panel>
}

function ManualAward({ a, onClose }: { a: Award; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const decide = useDecideAward()
  const [kind, setKind] = useState<'amount' | 'points'>('points'); const [value, setValue] = useState(''); const [note, setNote] = useState('')
  const e = decide.error instanceof ApiError ? decide.error : null
  return <Modal open onClose={onClose} title={L('استحقاق خارج جدول المكافآت', 'Award outside the table')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={decide.isPending || !value || note.trim().length < 3} onClick={() => decide.mutate({ id: a.id, approve: true, kind, value: Number(value), note }, { onSuccess: () => { toast(L('اعتُمد الاستحقاق', 'Award approved')); onClose() } })}>{L('اعتماد', 'Approve')}</Btn></>}>
    <p className="text-[13px] mb-3">{a.employee ? bi(a.employee.name, lang) : ''} — {L('النتيجة', 'score')} <span className="num font-bold">{a.score.toFixed(1)}</span></p>
    <Field div label={L('النوع', 'Type')}><Seg value={kind} onChange={setKind} options={[{ v: 'points', label: L('نقاط', 'Points') }, { v: 'amount', label: L('مبلغ مع الكشف', 'Amount on the sheet') }]} /></Field>
    <Field req label={kind === 'points' ? L('النقاط', 'Points') : `${L('المبلغ', 'Amount')} (${a.currency})`} className="mt-3" error={e?.field('value')}><input className="field num" type="number" min={0} value={value} onChange={x => setValue(x.target.value)} /></Field>
    <Field req label={L('السبب', 'Reason')} className="mt-3" error={e?.field('note')}><textarea className="field" value={note} onChange={x => setNote(x.target.value)} /></Field>
    {e && !e.field('value') && !e.field('note') && <p className="text-danger text-[13px] mt-2">{e.message}</p>}
  </Modal>
}

function CycleForm({ cycle, d, onClose }: { cycle: Cycle | null; d: PerfPage; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const save = useSaveCycle(cycle?.id ?? null)
  const [v, setV] = useState({ name_ar: cycle?.name_ar ?? '', name_en: cycle?.name_en ?? '', starts_on: cycle?.starts_on ?? '', ends_on: cycle?.ends_on ?? '', department_id: (cycle?.department_id ?? '') as number | '' })
  const e = save.error instanceof ApiError ? save.error : null
  return <Modal open onClose={onClose} title={cycle ? L('تعديل الدورة', 'Edit cycle') : L('دورة تقييم جديدة', 'New review cycle')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate(v, { onSuccess: r => { toast(L('حُفظت الدورة كمسودة', 'Saved as draft')); setQuery({ cycle: String(r.id) }); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      <Field req label={L('الاسم بالعربية', 'Arabic name')} error={e?.field('name_ar')}><input className="field" value={v.name_ar} onChange={x => setV({ ...v, name_ar: x.target.value })} /></Field>
      <Field req label={L('الاسم بالإنكليزية', 'English name')} error={e?.field('name_en')}><input className="field ltr" value={v.name_en} onChange={x => setV({ ...v, name_en: x.target.value })} /></Field>
      <Field req label={L('البداية', 'Start')} error={e?.field('starts_on')}><input className="field num" type="date" value={v.starts_on} onChange={x => setV({ ...v, starts_on: x.target.value })} /></Field>
      <Field req label={L('آخر موعد للنماذج', 'Forms due')} error={e?.field('ends_on')}><input className="field num" type="date" value={v.ends_on} onChange={x => setV({ ...v, ends_on: x.target.value })} /></Field>
      <Field label={L('النطاق', 'Scope')} className="sm:col-span-2"><select className="field" value={v.department_id} onChange={x => setV({ ...v, department_id: x.target.value ? Number(x.target.value) : '' })}>
        <option value="">{L('كل الموظفين', 'Everyone')}</option>{d.departments.map(x => <option key={x.id} value={x.id}>{bi(x, lang)}</option>)}</select></Field>
    </div>
    {e && !Object.keys(e.errors).length && <p className="text-danger text-[13px] mt-2">{e.message}</p>}
  </Modal>
}

