import { Download } from 'lucide-react'
import { useL, useLang, bi, money } from '../../../lib/store'
import { setQuery, useRoute } from '../../../lib/router'
import { BOXES, downloadAnalytics, useAnalytics } from '../../../lib/performance'
import { Btn, Empty, PageHead, Panel, Tabs, toast } from '../../../ui/kit'
import { Donut } from '../../../ui/viz'
import { ProtectedBlock } from '../../../ui/vault'

const colors = ['var(--primary)', 'var(--warn)', 'var(--danger)', 'var(--user)', 'var(--fg3)', 'var(--primary-2)']
type Tab = 'people' | 'att' | 'hire' | 'perf'

/** التحليلات (Analytics في الديمو): قراءات مباشرة من بيانات النظام، وكل جدول يُصدَّر إلى Excel */
export function Analytics() {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const q = useAnalytics(); const d = q.data
  const tab = (['people', 'att', 'hire', 'perf'].includes(route.query.tab ?? '') ? route.query.tab : 'people') as Tab
  const P = lang === 'ar' ? '٪' : '%'
  if (!d) return <div className="max-w-[1300px] mx-auto"><PageHead title={L('تحليلات الموارد البشرية', 'People analytics')} /></div>
  const maxT = Math.max(1, ...d.tenure.map(t => t.n))
  const maxPay = Math.max(1, ...(d.pay ?? []).map(x => x.v))
  const tone = (r: number) => r >= .9 ? 'var(--primary)' : r >= .75 ? 'var(--warn)' : 'var(--danger)'
  return <div className="max-w-[1300px] mx-auto">
    <PageHead title={L('تحليلات الموارد البشرية', 'People analytics')} sub={L('قراءات مأخوذة مباشرة من بيانات النظام — بدون إدخال يدوي.', 'Read straight from system data — no manual input.')}
      actions={<Btn icon={<Download size={16} />} onClick={() => downloadAnalytics().catch(() => toast(L('تعذّر التصدير', 'Export failed')))}>Excel</Btn>} />
    <Tabs value={tab} onChange={v => setQuery({ tab: v === 'people' ? undefined : v })} tabs={[{ v: 'people', label: L('القوى العاملة', 'Workforce') }, { v: 'att', label: L('الحضور', 'Attendance') }, { v: 'hire', label: L('التوظيف', 'Hiring') }, { v: 'perf', label: L('الأداء', 'Performance') }]} />
    <div className="mt-4 grid lg:grid-cols-2 gap-5">
      {tab === 'people' && <>
        <Panel title={L('سنوات الخدمة', 'Tenure distribution')} sub={L(`${d.headcount.total} موظفاً على رأس عمله`, `${d.headcount.total} current employees`)}><div className="flex items-end gap-4 h-[200px]">{d.tenure.map((t, i) => <div key={t.from} className="flex-1 min-w-0 flex flex-col items-center justify-end h-full">
          <span className="text-[13px] font-bold num mb-1">{t.n}</span><span className="grow-y w-full rounded-t-lg bg-brand" style={{ height: `${t.n / maxT * 160}px`, animationDelay: `${i * 80}ms` }} />
          <span className="mt-2 text-[12px] text-ink-3 num">{t.to >= 99 ? `${t.from}+` : `${t.from}–${t.to}`} {L('سنة', 'yrs')}</span></div>)}</div></Panel>
        {d.pay ? <Panel title={L('كتلة الرواتب حسب الإدارة', 'Payroll by department')}><div className="space-y-2.5">{d.pay.map((x, i) => <div key={x.id} className="grid grid-cols-[minmax(0,120px)_1fr] gap-3 items-center text-[13px]"><span className="truncate">{bi(x, lang)}</span>
          <div className="h-6 rounded-md bg-sub overflow-hidden relative"><div className="h-full rounded-md" style={{ width: `${x.v / maxPay * 100}%`, background: colors[i % 4], animation: `growx .7s ${i * 60}ms both`, transformOrigin: lang === 'ar' ? 'right' : 'left' }} />
            <span className="absolute inset-y-0 end-2 grid place-items-center text-[11.5px] font-semibold num">{money(x.v, d.currency, lang)}</span></div></div>)}</div></Panel>
          : d.pay_masked ? <Panel title={L('كتلة الرواتب حسب الإدارة', 'Payroll by department')}><ProtectedBlock others masked>{null}</ProtectedBlock></Panel>
          : <Panel title={L('الموظفون حسب الإدارة', 'Headcount by department')}><div className="space-y-2">{d.headcount.by_dept.map(x => <div key={x.id} className="flex justify-between text-[13px]"><span>{bi(x, lang)}</span><span className="num font-semibold">{x.n}</span></div>)}</div></Panel>}
      </>}
      {tab === 'att' && <Panel title={L('خريطة الحضور حسب الإدارة', 'Attendance heatmap by department')} sub={L('آخر 10 أيام عمل — مرّر فوق الخلية للتفاصيل', 'Last 10 working days — hover a cell')} className="lg:col-span-2">
        {d.heat.rows.length === 0 ? <Empty title={L('لا بيانات حضور', 'No attendance data')} /> : <div className="grid gap-1 items-center" style={{ gridTemplateColumns: 'minmax(70px,150px) repeat(10, minmax(0,1fr))' }}>
          <span />{d.heat.days.map(x => <span key={x} className="text-center text-[11px] text-ink-3 num">{x.slice(8)}</span>)}
          {d.heat.rows.map(r => [<span key={r.id} className="text-[12.5px] pe-2 truncate">{bi(r, lang)}</span>, ...r.cells.map(c => <span key={r.id + c.day} title={`${bi(r, lang)} · ${c.day} · ${c.rate === null ? '—' : Math.round(c.rate * 100) + P} · ${L('متأخرون', 'late')}: ${c.late}`}
            className="h-9 rounded-md transition hover:scale-110" style={{ background: c.rate === null ? 'var(--line)' : `color-mix(in oklab, ${tone(c.rate)} ${30 + c.rate * 60}%, transparent)` }} />)])}
        </div>}
        <div className="flex flex-wrap gap-4 mt-3 text-[12px] text-ink-3">{[['var(--primary)', '≥ 90' + P], ['var(--warn)', '75–90' + P], ['var(--danger)', '< 75' + P]].map(([c, t]) => <span key={t} className="flex items-center gap-1.5"><span className="size-3 rounded" style={{ background: c }} />{t}</span>)}</div>
      </Panel>}
      {tab === 'hire' && <>
        <Panel title={L('معدل التحويل بين المراحل', 'Stage conversion')} sub={L(`غير المقبولين: ${d.rejected}`, `Not selected: ${d.rejected}`)}><div className="space-y-1.5">{d.funnel.map((c, i) => <div key={c.stage} className="mx-auto rounded-lg py-2 text-center text-[13px] font-semibold text-on-brand" style={{ width: `${100 - i * 12}%`, background: `color-mix(in oklab, var(--primary) ${100 - i * 11}%, var(--bg))` }}>{bi(c, lang)} · <span className="num">{c.n}</span></div>)}</div></Panel>
        <Panel title={L('أنواع الطلبات', 'Request types')}>{d.leaves.length ? <Donut parts={d.leaves.map((x, i) => ({ label: bi(x, lang), value: x.n, color: colors[i % colors.length] }))} /> : <Empty title={L('لا طلبات', 'No requests')} />}</Panel>
      </>}
      {tab === 'perf' && <Panel title={L('توزيع 9-Box', '9-Box distribution')} sub={d.performance ? bi(d.performance.cycle, lang) : undefined} className="lg:col-span-2">
        {!d.performance ? <Empty title={L('لا دورة تقييم', 'No review cycle')} /> : <div className="grid grid-cols-3 gap-2 max-w-[640px] mx-auto">{[3, 2, 1].flatMap(qv => [1, 2, 3].map(p => { const k = `${p}${qv}`; const n = d.performance!.grid[k] ?? 0; const strong = p + qv >= 5, weak = p + qv <= 2
          return <a key={k} href="/hr/performance" className={`rounded-xl border border-line p-3 min-w-0 ${strong ? 'bg-brand-soft' : weak ? 'bg-danger-soft' : 'bg-sub/60'}`}><div className="text-[12px] font-semibold truncate">{BOXES[k][lang === 'ar' ? 0 : 1]}</div><div className="text-[26px] font-black font-display num">{n}</div></a> }))}</div>}
      </Panel>}
    </div>
  </div>
}
