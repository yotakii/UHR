import { useState } from 'react'
import { ChevronDown, Minus, Plus, Search } from 'lucide-react'
import { useStore, useL, useLang, todayISO, addDays, isWeekend, daysBetween, update } from '../lib/store'
import { useNav } from '../lib/nav'
import { Panel, Avatar, PageHead, Badge, Drawer, Btn, Tabs, toast } from '../ui/kit'
import { Radar } from './Rewards'
import { STAGES, calcDay, LEAVE_TYPES } from '../lib/rules'
import type { Employee } from '../lib/types'

const deptTone: Record<string, string> = { exec: 'bg-brand', hr: 'bg-info', fin: 'bg-brass', it: 'bg-brand', sales: 'bg-danger', ops: 'bg-brass', cs: 'bg-info', admin: 'bg-ink-3' }
const hash = (s: string) => { let h = 7; for (const c of s) h = (h * 31 + c.charCodeAt(0)) % 10007; return h }

/* ================= org chart ================= */
export function OrgChart() {
  const L = useL(); const lang = useLang(); const { go } = useNav()
  const emps = useStore(s => s.employees.filter(e => e.status !== 'terminated')); const depts = useStore(s => s.depts)
  const [closed, setClosed] = useState<Record<string, boolean>>({}); const [q, setQ] = useState(''); const [zoom, setZoom] = useState(1)
  const kids = (id: string) => emps.filter(e => e.managerId === id && e.id !== id)
  const root = emps.find(e => !e.managerId) ?? emps[0]
  const hit = (e: Employee) => !!q && (e.name.includes(q) || e.nameEn.toLowerCase().includes(q.toLowerCase()))
  const Node = ({ e, depth }: { e: Employee; depth: number }) => {
    const ch = kids(e.id); const open = !closed[e.id] && (depth < 2 || !!q || closed[e.id] === false)
    return <li className="relative flex flex-col items-center px-2">
      <div dir={lang === 'ar' ? 'rtl' : 'ltr'} className={`relative w-[188px] rounded-2xl border bg-surface p-3 text-center hoverlift ${hit(e) ? 'border-brand ring-2 ring-brand/40' : 'border-line'}`}>
        <span className={`absolute inset-x-6 top-0 h-[3px] rounded-b ${deptTone[e.dept]}`} />
        <button onClick={() => go('employee', e.id)} className="w-full"><Avatar name={e.name} size={38} tone={e.visitor ? 'brass' : 'brand'} />
          <div className="mt-1.5 font-semibold text-[13.5px] truncate">{lang === 'ar' ? e.name : e.nameEn}</div><div className="text-[11.5px] text-ink-3 truncate">{e.title[lang]}</div></button>
        {ch.length > 0 && <button onClick={() => setClosed({ ...closed, [e.id]: open })} className="absolute -bottom-3 left-1/2 -translate-x-1/2 h-6 min-w-6 px-1.5 rounded-full border border-line bg-surface text-[11px] font-bold num flex items-center gap-0.5 hover:border-brand">{ch.length}<ChevronDown size={12} className={open ? 'rotate-180' : ''} /></button>}
      </div>
      {open && ch.length > 0 && <><span className="h-6 w-px bg-line" /><ul className="relative flex pt-0">{ch.map((c, i) => <div key={c.id} className="relative pt-6">
        <span className="absolute top-0 left-1/2 h-6 w-px bg-line" />
        {ch.length > 1 && <span className={`absolute top-0 h-px bg-line ${i === 0 ? 'left-1/2 right-0' : i === ch.length - 1 ? 'left-0 right-1/2' : 'inset-x-0'}`} />}
        <Node e={c} depth={depth + 1} /></div>)}</ul></>}
    </li>
  }
  return <div className="max-w-full">
    <PageHead title={L('الهيكل التنظيمي', 'Organization chart')} sub={L('اضغط على الرقم لفتح الفريق، وعلى البطاقة لفتح ملف الموظف.', 'Click a count to expand a team, a card to open the file.')}
      actions={<><div className="relative"><Search size={15} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" /><input className="field !w-56 ps-9" placeholder={L('ابحث عن موظف', 'Find someone')} value={q} onChange={e => setQ(e.target.value)} /></div>
        <div className="flex rounded-lg border border-line bg-surface"><button className="h-9 w-9 grid place-items-center" onClick={() => setZoom(Math.max(.6, zoom - .1))}><Minus size={15} /></button><span className="w-12 grid place-items-center text-[12px] num">{Math.round(zoom * 100)}%</span><button className="h-9 w-9 grid place-items-center" onClick={() => setZoom(Math.min(1.2, zoom + .1))}><Plus size={15} /></button></div></>} />
    <div className="flex flex-wrap gap-3 mb-3 text-[12px] text-ink-2">{depts.map(d => <span key={d.id} className="flex items-center gap-1.5"><span className={`size-2.5 rounded-full ${deptTone[d.id]}`} />{d[lang]}</span>)}</div>
    <div className="rounded-2xl border border-line bg-sub/40 overflow-auto p-8" style={{ direction: 'ltr' }}>
      <ul className="flex justify-center min-w-max origin-top transition-transform" style={{ transform: `scale(${zoom})`, direction: 'ltr' }}>{root && <Node e={root} depth={0} />}</ul>
    </div>
  </div>
}

/* ================= performance 360 ================= */
const COMP: [string, string][] = [['جودة العمل', 'Quality'], ['الالتزام', 'Reliability'], ['العمل الجماعي', 'Teamwork'], ['المبادرة', 'Initiative'], ['التواصل', 'Communication'], ['المعرفة الفنية', 'Expertise']]
export function usePerf() {
  const perf = useStore(s => (s as unknown as { perf?: Record<string, { p: number; q: number }> }).perf ?? {})
  return (e: Employee) => {
    const h = hash(e.id); const saved = perf[e.id]
    const p = saved?.p ?? Math.min(3, 1 + (h % 7 > 4 ? 2 : h % 7 > 1 ? 1 : 0))
    const q = saved?.q ?? Math.min(3, 1 + ((h >> 3) % 5 > 3 ? 2 : (h >> 3) % 5 > 1 ? 1 : 0))
    const comp = COMP.map((_, i) => ({ self: 3 + ((h >> i) % 3) * .5, mgr: 2.5 + ((h >> (i + 2)) % 4) * .55, peer: 2.8 + ((h >> (i + 4)) % 3) * .6 }))
    return { p, q, comp, score: +(comp.reduce((a, c) => a + (c.mgr * .5 + c.peer * .3 + c.self * .2), 0) / comp.length).toFixed(1) }
  }
}
export function Performance() {
  const L = useL(); const lang = useLang(); const { go } = useNav()
  const emps = useStore(s => s.employees.filter(e => e.status !== 'terminated' && e.status !== 'onboarding'))
  const perf = usePerf()
  const [sel, setSel] = useState<string | null>(null); const [box, setBox] = useState<string | null>(null); const [drag, setDrag] = useState<string | null>(null)
  const labels: Record<string, [string, string]> = { '33': ['نجوم المستقبل', 'Future stars'], '23': ['مواهب صاعدة', 'Rising talent'], '13': ['جوهرة غير مصقولة', 'Rough diamond'], '32': ['ركائز أساسية', 'Core pillars'], '22': ['أداء ثابت', 'Solid performers'], '12': ['بحاجة لتوجيه', 'Needs direction'], '31': ['خبراء متمرسون', 'Trusted experts'], '21': ['أداء مقبول', 'Effective'], '11': ['خطة تحسين', 'Improvement plan'] }
  const place = (id: string, p: number, q: number) => { update(s => { const x = s as unknown as { perf?: Record<string, { p: number; q: number }> }; x.perf = { ...(x.perf ?? {}), [id]: { p, q } } }); toast(L('حُدّث تصنيف الموظف', 'Placement updated')) }
  const e = emps.find(x => x.id === sel); const pe = e ? perf(e) : null
  const list = emps.filter(x => !box || `${perf(x).p}${perf(x).q}` === box)
  return <div className="max-w-[1300px] mx-auto">
    <PageHead title={L('الأداء وتقييم 360', 'Performance & 360')} sub={L('دورة تقييم الربع الثالث 2026 — اسحب الموظف بين المربعات لمعايرة التقييم.', 'Q3 2026 review cycle — drag people between boxes to calibrate.')} />
    <div className="grid xl:grid-cols-[1.2fr_1fr] gap-5">
      <Panel title={L('مصفوفة الأداء والإمكانات', 'Performance–potential grid')} sub={L('9-Box', '9-Box')}>
        <div className="grid grid-cols-[22px_1fr] gap-2"><div className="flex items-center justify-center"><span className="-rotate-90 whitespace-nowrap text-[11px] text-ink-3">{L('الإمكانات ←', 'Potential →')}</span></div>
          <div className="grid grid-cols-3 gap-2">{[3, 2, 1].flatMap(q => [1, 2, 3].map(p => { const k = `${p}${q}`; const inBox = emps.filter(x => perf(x).p === p && perf(x).q === q); const strong = p + q >= 5, weak = p + q <= 2
            return <button key={k} onClick={() => setBox(box === k ? null : k)} onDragOver={ev => ev.preventDefault()} onDrop={() => { if (drag) place(drag, p, q); setDrag(null) }}
              className={`min-h-[118px] rounded-xl border p-2 text-start transition ${box === k ? 'border-brand ring-2 ring-brand/30' : 'border-line'} ${strong ? 'bg-brand-soft' : weak ? 'bg-danger-soft' : 'bg-sub/60'}`}>
              <div className="flex justify-between text-[11.5px] font-semibold"><span>{labels[k][lang === 'ar' ? 0 : 1]}</span><span className="num text-ink-3">{inBox.length}</span></div>
              <div className="mt-2 flex flex-wrap gap-1">{inBox.slice(0, 8).map(x => <span key={x.id} draggable onDragStart={() => setDrag(x.id)} onClick={ev => { ev.stopPropagation(); setSel(x.id) }} className={`cursor-grab rounded-md border px-1.5 py-0.5 text-[11px] font-medium hover:border-brand ${x.visitor ? 'border-brass/40 bg-brass-soft' : 'border-line bg-surface'}`}>{lang === 'ar' ? x.name : x.nameEn}</span>)}{inBox.length > 8 && <span className="text-[11px] text-ink-3 px-1">+{inBox.length - 8}</span>}</div>
            </button> }))}</div>
          <span /><div className="text-center text-[11px] text-ink-3">{L('الأداء ←', 'Performance →')}</div></div>
      </Panel>
      <Panel title={box ? labels[box][lang === 'ar' ? 0 : 1] : L('كل الموظفين', 'Everyone')} sub={box ? <button className="text-brand" onClick={() => setBox(null)}>{L('إظهار الكل', 'Show all')}</button> : undefined} pad={false}>
        <ul className="divide-y divide-line-2 max-h-[470px] overflow-y-auto">{list.sort((a, b) => perf(b).score - perf(a).score).map(x => { const r = perf(x)
          return <li key={x.id}><button onClick={() => setSel(x.id)} className="w-full flex items-center gap-3 px-5 py-2.5 hover:bg-sub/60 text-start"><Avatar name={x.name} size={32} />
            <span className="flex-1 min-w-0"><span className="block font-semibold text-[13.5px] truncate">{lang === 'ar' ? x.name : x.nameEn}</span><span className="block text-[12px] text-ink-3 truncate">{x.title[lang]}</span></span>
            <span className="flex gap-0.5">{[1, 2, 3, 4, 5].map(i => <span key={i} className={`h-4 w-1.5 rounded-sm ${i <= Math.round(r.score) ? 'bg-brand' : 'bg-sub'}`} />)}</span></button></li> })}</ul>
      </Panel>
    </div>
    <Drawer open={!!e} onClose={() => setSel(null)} width={560}>{e && pe && <div className="flex flex-col h-full">
      <header className="p-5 border-b border-line flex items-center gap-3"><Avatar name={e.name} size={44} /><div className="flex-1"><div className="font-display font-bold text-[17px]">{lang === 'ar' ? e.name : e.nameEn}</div><div className="text-[12.5px] text-ink-3">{e.title[lang]}</div></div><Btn size="sm" onClick={() => go('employee', e.id)}>{L('الملف', 'File')}</Btn></header>
      <div className="p-5 overflow-y-auto space-y-5">
        <div className="flex justify-center"><Radar size={300} axes={COMP.map((c, i) => ({ label: c[lang === 'ar' ? 0 : 1], value: pe.comp[i].mgr / 5 }))} /></div>
        <table className="tbl"><thead><tr><th>{L('الكفاءة', 'Competency')}</th><th>{L('ذاتي', 'Self')}</th><th>{L('المدير', 'Manager')}</th><th>{L('الزملاء', 'Peers')}</th></tr></thead>
          <tbody>{COMP.map((c, i) => <tr key={i}><td>{c[lang === 'ar' ? 0 : 1]}</td>{(['self', 'mgr', 'peer'] as const).map(k => <td key={k} className="num">{pe.comp[i][k].toFixed(1)}</td>)}</tr>)}</tbody></table>
        <div><div className="text-[13px] font-semibold mb-2">{L('تصنيف المعايرة', 'Calibration')}</div>
          <div className="grid grid-cols-2 gap-3 text-[13px]">{([['p', L('الأداء', 'Performance')], ['q', L('الإمكانات', 'Potential')]] as const).map(([k, t]) => <div key={k}><div className="text-ink-3 mb-1">{t}</div><div className="flex gap-1">{[1, 2, 3].map(v => <button key={v} onClick={() => place(e.id, k === 'p' ? v : pe.p, k === 'q' ? v : pe.q)} className={`flex-1 h-9 rounded-lg border text-[12px] font-semibold ${pe[k] === v ? 'bg-brand text-on-brand border-brand' : 'border-line'}`}>{[L('منخفض', 'Low'), L('متوسط', 'Mid'), L('مرتفع', 'High')][v - 1]}</button>)}</div></div>)}</div></div>
        <Badge tone="info">{labels[`${pe.p}${pe.q}`][lang === 'ar' ? 0 : 1]}</Badge>
      </div></div>}</Drawer>
  </div>
}

/* ================= analytics ================= */
export function Analytics() {
  const L = useL(); const lang = useLang()
  const s = useStore(x => x)
  const [tab, setTab] = useState('people')
  const active = s.employees.filter(e => e.status !== 'terminated')
  const today = todayISO()
  const P = lang === 'ar' ? '٪' : '%'
  // heatmap: depts x last 10 working days
  const wd: string[] = []; for (let d = addDays(today, -1); wd.length < 10; d = addDays(d, -1)) if (!isWeekend(d)) wd.unshift(d)
  const heat = s.depts.map(d => ({ d, cells: wd.map(day => { const ids = active.filter(e => e.dept === d.id).map(e => e.id); const pres = s.days.filter(x => x.date === day && ids.includes(x.empId) && x.inAt); const late = pres.filter(x => calcDay(day, x.inAt).late > 0).length; return { day, rate: ids.length ? pres.length / ids.length : 0, late } }) }))
  const tenure = [[0, 1], [1, 3], [3, 5], [5, 10]].map(([a, b]) => ({ k: `${a}–${b}`, n: active.filter(e => { const y = daysBetween(e.hireDate, today) / 365; return y >= a && y < b }).length }))
  const maxT = Math.max(1, ...tenure.map(t => t.n))
  const conv = STAGES.slice(0, 6).map(st => ({ st, n: s.candidates.filter(c => STAGES.findIndex(x => x.id === c.stage) >= STAGES.findIndex(x => x.id === st.id) && c.stage !== 'rejected').length }))
  const lv = Object.keys(LEAVE_TYPES).map(k => ({ k, n: s.leaves.filter(l => l.type === k).length })).filter(x => x.n)
  const totalLv = lv.reduce((a, b) => a + b.n, 0) || 1
  const colors = ['var(--primary)', 'var(--warn)', 'var(--danger)', 'var(--user)', 'var(--fg3)', 'var(--primary-2)']
  let acc = 0
  const pay = s.depts.map(d => ({ d, v: active.filter(e => e.dept === d.id).reduce((a, e) => a + e.salary, 0) })).sort((a, b) => b.v - a.v)
  const maxPay = Math.max(1, ...pay.map(x => x.v))
  return <div className="max-w-[1300px] mx-auto">
    <PageHead title={L('تحليلات الموارد البشرية', 'People analytics')} sub={L('قراءات مأخوذة مباشرة من بيانات النظام — بدون إدخال يدوي.', 'Read straight from system data — no manual input.')} />
    <Tabs value={tab} onChange={setTab} tabs={[{ v: 'people', label: L('القوى العاملة', 'Workforce') }, { v: 'att', label: L('الحضور', 'Attendance') }, { v: 'hire', label: L('التوظيف', 'Hiring') }]} />
    <div className="mt-4 grid lg:grid-cols-2 gap-5">
      {tab === 'att' && <Panel title={L('خريطة الحضور حسب الإدارة', 'Attendance heatmap by department')} sub={L('آخر 10 أيام عمل — مرّر فوق الخلية للتفاصيل', 'Last 10 working days — hover a cell')} className="lg:col-span-2">
        <div className="scroll-x"><table className="w-full border-separate" style={{ borderSpacing: 4 }}><thead><tr><th />{wd.map(d => <th key={d} className="text-[11px] font-normal text-ink-3 num">{d.slice(8)}</th>)}</tr></thead>
          <tbody>{heat.map(r => <tr key={r.d.id}><td className="text-[12.5px] pe-2 whitespace-nowrap">{r.d[lang]}</td>{r.cells.map(c => <td key={c.day} title={`${r.d[lang]} · ${c.day} · ${Math.round(c.rate * 100)}${P} · ${L('متأخرون', 'late')}: ${c.late}`} className="h-9 min-w-9 rounded-md transition hover:scale-110" style={{ background: `color-mix(in oklab, ${c.rate >= .9 ? 'var(--primary)' : c.rate >= .75 ? 'var(--warn)' : 'var(--danger)'} ${30 + c.rate * 60}%, transparent)` }} />)}</tr>)}</tbody></table></div>
        <div className="flex gap-4 mt-3 text-[12px] text-ink-3">{[['var(--primary)', '≥ 90' + P], ['var(--warn)', '75–90' + P], ['var(--danger)', '< 75' + P]].map(([c, t]) => <span key={t} className="flex items-center gap-1.5"><span className="size-3 rounded" style={{ background: c }} />{t}</span>)}</div>
      </Panel>}
      {tab === 'people' && <>
        <Panel title={L('سنوات الخدمة', 'Tenure distribution')}><div className="flex items-end gap-4 h-[200px]">{tenure.map((t, i) => <div key={t.k} className="flex-1 flex flex-col items-center justify-end h-full"><span className="text-[13px] font-bold num mb-1">{t.n}</span><span className="grow-y w-full rounded-t-lg bg-brand" style={{ height: `${t.n / maxT * 160}px`, animationDelay: `${i * 80}ms` }} /><span className="mt-2 text-[12px] text-ink-3 num">{t.k} {L('سنة', 'yrs')}</span></div>)}</div></Panel>
        <Panel title={L('كتلة الرواتب حسب الإدارة', 'Payroll by department')}><div className="space-y-2.5">{pay.map((x, i) => <div key={x.d.id} className="grid grid-cols-[120px_1fr] gap-3 items-center text-[13px]"><span className="truncate">{x.d[lang]}</span>
          <div className="h-6 rounded-md bg-sub overflow-hidden relative"><div className="h-full rounded-md" style={{ width: `${x.v / maxPay * 100}%`, background: colors[i % 4], animation: `growx .7s ${i * 60}ms both`, transformOrigin: lang === 'ar' ? 'right' : 'left' }} /><span className="absolute inset-y-0 end-2 grid place-items-center text-[11.5px] font-semibold num">${x.v.toLocaleString('en-US')}</span></div></div>)}</div></Panel>
      </>}
      {tab === 'hire' && <>
        <Panel title={L('معدل التحويل بين المراحل', 'Stage conversion')}><div className="space-y-1.5">{conv.map((c, i) => <div key={c.st.id} className="mx-auto rounded-lg py-2 text-center text-[13px] font-semibold text-on-brand" style={{ width: `${100 - i * 12}%`, background: `color-mix(in oklab, var(--primary) ${100 - i * 11}%, var(--bg))` }}>{c.st[lang]} · <span className="num">{c.n}</span></div>)}</div></Panel>
        <Panel title={L('أنواع الطلبات', 'Request types')}><div className="flex items-center gap-6"><svg viewBox="0 0 42 42" className="size-44 -rotate-90">{lv.map((x, i) => { const pct = x.n / totalLv * 100; const el = <circle key={x.k} r="15.9" cx="21" cy="21" fill="none" stroke={colors[i % colors.length]} strokeWidth="6" strokeDasharray={`${pct} ${100 - pct}`} strokeDashoffset={-acc} />; acc += pct; return el })}</svg>
          <ul className="space-y-1.5 text-[13px]">{lv.map((x, i) => <li key={x.k} className="flex items-center gap-2"><span className="size-2.5 rounded-full" style={{ background: colors[i % colors.length] }} />{LEAVE_TYPES[x.k][lang]}<span className="text-ink-3 num">{x.n}</span></li>)}</ul></div></Panel>
      </>}
    </div>
  </div>
}
