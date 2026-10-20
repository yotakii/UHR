import { useLayoutEffect, useRef, useState } from 'react'
import { ChevronDown, Loader2, Minus, Plus, Search } from 'lucide-react'
import { useL, useLang, bi } from '../../../lib/store'
import { navigate } from '../../../lib/router'
import { deptTone, useOrg, type OrgNode } from '../../../lib/employees'
import { Avatar, Empty, PageHead } from '../../../ui/kit'

/**
 * M07 — الهيكل التنظيمي (OrgChart في الديمو): شجرة من الجذر عبر manager_id، المستويان الأولان مفتوحان، بحث يُبرز ويفتح الفروع، تكبير 60–120٪.
 * بلا تمرير أفقي: الشجرة تُصغَّر لتتسع لعرض الصفحة، وعلى الجوال تصبح قائمة متداخلة.
 */
export function OrgChart() {
  const L = useL(); const lang = useLang()
  const { data, isLoading } = useOrg()
  const [closed, setClosed] = useState<Record<number, boolean>>({}); const [q, setQ] = useState(''); const [zoom, setZoom] = useState(1)
  const box = useRef<HTMLDivElement>(null); const tree = useRef<HTMLDivElement>(null)
  const [size, setSize] = useState({ cw: 0, w: 0, h: 0 })
  useLayoutEffect(() => {
    if (!box.current || !tree.current) return
    const ro = new ResizeObserver(() => box.current && tree.current && setSize({ cw: box.current.clientWidth - 32, w: tree.current.offsetWidth, h: tree.current.offsetHeight }))
    ro.observe(box.current); ro.observe(tree.current)
    return () => ro.disconnect()
  }, [data])
  if (isLoading || !data) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>

  const emps = data.data
  const kids = (id: number) => emps.filter(e => e.manager_id === id && e.id !== id)
  const root = emps.find(e => !e.manager_id) ?? emps[0]
  const ql = q.trim().toLowerCase()
  const hit = (e: OrgNode) => !!ql && (e.name.ar.includes(q.trim()) || e.name.en.toLowerCase().includes(ql) || e.code.toLowerCase().includes(ql))
  const isOpen = (e: OrgNode, depth: number) => !closed[e.id] && (depth < 2 || !!ql || closed[e.id] === false)
  const tone = (code: string) => deptTone(data.depts.find(d => d.code === code)?.color ?? 'brand')
  const fit = size.w && size.cw ? Math.min(1, size.cw / size.w) : 1
  const scale = Math.min(zoom, fit)
  const open = (e: OrgNode) => e.open && navigate(`/hr/employees/${e.id}`)

  const Node = ({ e, depth }: { e: OrgNode; depth: number }) => {
    const ch = kids(e.id); const o = isOpen(e, depth)
    return <li className="relative flex flex-col items-center px-2">
      <div dir={lang === 'ar' ? 'rtl' : 'ltr'} className={`relative w-[188px] rounded-2xl border bg-surface p-3 text-center hoverlift ${hit(e) ? 'border-brand ring-2 ring-brand/40' : 'border-line'}`}>
        <span className={`absolute inset-x-6 top-0 h-[3px] rounded-b ${tone(e.dept)}`} />
        <button onClick={() => open(e)} className={`w-full ${e.open ? '' : 'cursor-default'}`}><Avatar size={38} />
          <div className="mt-1.5 font-semibold text-[13.5px] truncate">{bi(e.name, lang)}</div><div className="text-[11.5px] text-ink-3 truncate">{bi(e.title, lang)}</div></button>
        {ch.length > 0 && <button onClick={() => setClosed({ ...closed, [e.id]: o })} aria-expanded={o} className="absolute -bottom-3 left-1/2 -translate-x-1/2 h-6 min-w-6 px-1.5 rounded-full border border-line bg-surface text-[11px] font-bold num flex items-center gap-0.5 hover:border-brand">{ch.length}<ChevronDown size={12} className={o ? 'rotate-180' : ''} /></button>}
      </div>
      {o && ch.length > 0 && <><span className="h-6 w-px bg-line" /><ul className="relative flex pt-0">{ch.map((c, i) => <div key={c.id} className="relative pt-6">
        <span className="absolute top-0 left-1/2 h-6 w-px bg-line" />
        {ch.length > 1 && <span className={`absolute top-0 h-px bg-line ${i === 0 ? 'left-1/2 right-0' : i === ch.length - 1 ? 'left-0 right-1/2' : 'inset-x-0'}`} />}
        <Node e={c} depth={depth + 1} /></div>)}</ul></>}
    </li>
  }

  // الجوال: قائمة متداخلة بنفس منطق الفتح والبحث
  const Row = ({ e, depth }: { e: OrgNode; depth: number }) => {
    const ch = kids(e.id); const o = isOpen(e, depth)
    return <li>
      <div className={`flex items-center gap-2 rounded-xl border bg-surface p-2.5 ${hit(e) ? 'border-brand ring-2 ring-brand/40' : 'border-line'}`} style={{ marginInlineStart: Math.min(depth, 6) * 14 }}>
        <span className={`self-stretch w-1 rounded-full ${tone(e.dept)}`} />
        <button onClick={() => open(e)} className="flex-1 min-w-0 text-start"><div className="font-semibold text-[13.5px] truncate">{bi(e.name, lang)}</div><div className="text-[11.5px] text-ink-3 truncate">{bi(e.title, lang)}</div></button>
        {ch.length > 0 && <button onClick={() => setClosed({ ...closed, [e.id]: o })} aria-expanded={o} className="h-7 min-w-7 px-1.5 rounded-full border border-line text-[11px] font-bold num flex items-center gap-0.5">{ch.length}<ChevronDown size={12} className={o ? 'rotate-180' : ''} /></button>}
      </div>
      {o && ch.length > 0 && <ul className="mt-1.5 space-y-1.5">{ch.map(c => <Row key={c.id} e={c} depth={depth + 1} />)}</ul>}
    </li>
  }

  return <div className="max-w-full">
    <PageHead title={L('الهيكل التنظيمي', 'Organization chart')} sub={L('اضغط على الرقم لفتح الفريق، وعلى البطاقة لفتح ملف الموظف.', 'Click a count to expand a team, a card to open the file.')}
      actions={<><div className="relative w-full sm:w-auto"><Search size={15} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" /><input className="field !w-full sm:!w-56 !ps-9" placeholder={L('ابحث عن موظف', 'Find someone')} value={q} onChange={e => setQ(e.target.value)} /></div>
        <div className="hidden md:flex rounded-lg border border-line bg-surface"><button className="h-9 w-9 grid place-items-center disabled:opacity-40" disabled={scale <= .6} onClick={() => setZoom(Math.max(.6, scale - .1))} aria-label={L('تصغير', 'Zoom out')}><Minus size={15} /></button>
          <span className="w-12 grid place-items-center text-[12px] num">{Math.round(scale * 100)}%</span>
          <button className="h-9 w-9 grid place-items-center disabled:opacity-40" disabled={scale >= Math.min(1.2, fit)} onClick={() => setZoom(Math.min(1.2, scale + .1))} aria-label={L('تكبير', 'Zoom in')}><Plus size={15} /></button></div></>} />
    <div className="flex flex-wrap gap-3 mb-3 text-[12px] text-ink-2">{data.depts.map(d => <span key={d.code} className="flex items-center gap-1.5"><span className={`size-2.5 rounded-full ${deptTone(d.color)}`} />{bi(d, lang)}</span>)}</div>
    {!root ? <div className="bg-surface border border-line rounded-2xl"><Empty title={L('لا يوجد موظفون بعد', 'No employees yet')} /></div> : <>
      <div ref={box} className="hidden md:block rounded-2xl border border-line bg-sub/40 overflow-hidden p-4" style={{ direction: 'ltr' }}>
        <div className="relative" style={{ height: size.h ? size.h * scale + 16 : undefined }}>
          <div ref={tree} className="absolute top-2 w-max origin-top-left transition-transform" style={{ left: Math.max(0, (size.cw - size.w * scale) / 2), transform: `scale(${scale})` }}>
            <ul className="flex justify-center pb-4" style={{ direction: 'ltr' }}><Node e={root} depth={0} /></ul>
          </div>
        </div>
      </div>
      <ul className="md:hidden space-y-1.5"><Row e={root} depth={0} /></ul>
    </>}
  </div>
}
