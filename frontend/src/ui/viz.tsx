import { useEffect, useRef, useState } from 'react'

export function CountUp({ to, dur = 900, decimals = 0 }: { to: number; dur?: number; decimals?: number }) {
  const [v, setV] = useState(0); const from = useRef(0)
  useEffect(() => {
    const start = performance.now(); const a = from.current; let raf = 0
    const step = (t: number) => { const p = Math.min(1, (t - start) / dur); const e = 1 - Math.pow(1 - p, 3); setV(a + (to - a) * e); if (p < 1) raf = requestAnimationFrame(step); else from.current = to }
    raf = requestAnimationFrame(step); return () => cancelAnimationFrame(raf)
  }, [to, dur])
  return <>{v.toLocaleString('en-US', { maximumFractionDigits: decimals, minimumFractionDigits: decimals })}</>
}

export function Sparkline({ data, color = 'var(--primary)', w = 96, h = 30 }: { data: number[]; color?: string; w?: number; h?: number }) {
  if (data.length < 2) return null
  const mx = Math.max(...data), mn = Math.min(...data), r = mx - mn || 1
  const pts = data.map((d, i) => [i / (data.length - 1) * w, h - 3 - (d - mn) / r * (h - 6)])
  const line = pts.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)},${p[1].toFixed(1)}`).join(' ')
  const id = 'g' + Math.round(Math.random() * 1e6)
  return <svg width={w} height={h} viewBox={`0 0 ${w} ${h}`} className="overflow-visible" style={{ direction: 'ltr' }}>
    <defs><linearGradient id={id} x1="0" x2="0" y1="0" y2="1"><stop offset="0" stopColor={color} stopOpacity=".35" /><stop offset="1" stopColor={color} stopOpacity="0" /></linearGradient></defs>
    <path d={`${line} L${w},${h} L0,${h} Z`} fill={`url(#${id})`} />
    <path d={line} fill="none" stroke={color} strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />
  </svg>
}

export function Bars({ data, height = 200, onPick }: { data: { label: string; value: number; tip: string; tone?: 'low' | 'muted' }[]; height?: number; onPick?: (i: number) => void }) {
  const [hover, setHover] = useState<number | null>(null)
  const mx = Math.max(1, ...data.map(d => d.value))
  return <div className="flex items-end gap-3" style={{ height: height + 26 }}>
    {data.map((d, i) => <button key={i} type="button" onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)} onClick={() => onPick?.(i)} className="relative flex-1 h-full flex flex-col justify-end items-center group">
      {hover === i && <span className="absolute -top-1 z-10 whitespace-nowrap rounded-lg border border-line bg-surface px-2.5 py-1 text-[12px] font-semibold shadow-xl">{d.tip}</span>}
      <span className={`grow-y w-full rounded-t-lg transition-[filter] ${d.tone === 'low' ? 'bg-danger' : d.tone === 'muted' ? 'bg-sub border border-line' : 'bg-brand'} ${hover === i ? 'brightness-125' : ''}`} style={{ height: Math.max(6, d.value / mx * height), animationDelay: `${i * 70}ms` }} />
      <span className="mt-2 text-[11px] text-ink-3">{d.label}</span>
    </button>)}
  </div>
}

/** رادار متعدد المحاور (المطابقة، F-25، كفاءات 360) */
export function Radar({ axes, size = 260 }: { axes: { label: string; value: number }[]; size?: number }) {
  const c = size / 2, R = size / 2 - 42, n = axes.length
  const pt = (i: number, v: number) => { const a = -Math.PI / 2 + i * 2 * Math.PI / n; return [c + Math.cos(a) * R * v, c + Math.sin(a) * R * v] }
  const poly = axes.map((x, i) => pt(i, x.value).join(',')).join(' ')
  return <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} style={{ direction: 'ltr' }}>
    {[.25, .5, .75, 1].map(k => <polygon key={k} points={axes.map((_, i) => pt(i, k).join(',')).join(' ')} fill="none" stroke="var(--line)" />)}
    {axes.map((_, i) => <line key={i} x1={c} y1={c} x2={pt(i, 1)[0]} y2={pt(i, 1)[1]} stroke="var(--line)" />)}
    <polygon points={poly} fill="color-mix(in oklab, var(--primary) 28%, transparent)" stroke="var(--primary)" strokeWidth="2" className="fadeup" />
    {axes.map((x, i) => { const [px, py] = pt(i, 1.2); return <text key={i} x={px} y={py} textAnchor="middle" dominantBaseline="middle" fontSize="11" fill="var(--fg2)" fontWeight="600">{x.label}</text> })}
    {axes.map((x, i) => { const [px, py] = pt(i, x.value); return <circle key={'d' + i} cx={px} cy={py} r="3.5" fill="var(--primary)" /> })}
  </svg>
}

/** حلقة رصيد (بوابة الموظف — أرصدتي) */
export function Ring({ v, max, label, sub, tone = 'var(--primary)' }: { v: number; max: number; label: string; sub: string; tone?: string }) {
  const pct = Math.max(0, Math.min(1, v / Math.max(1, max)))
  return <div className="flex items-center gap-3"><svg viewBox="0 0 36 36" className="size-16 -rotate-90"><circle r="15.9" cx="18" cy="18" fill="none" stroke="var(--sub)" strokeWidth="3.4" /><circle r="15.9" cx="18" cy="18" fill="none" stroke={tone} strokeWidth="3.4" strokeLinecap="round" strokeDasharray={`${pct * 100} 100`} style={{ transition: 'stroke-dasharray .8s' }} /></svg>
    <div><div className="text-[22px] font-bold font-display num leading-none"><CountUp to={v} /><span className="text-[13px] text-ink-3"> / {max}</span></div><div className="text-[13px] font-semibold mt-1">{label}</div><div className="text-[11.5px] text-ink-3">{sub}</div></div></div>
}

/** حلقة توزيع (التحليلات — أنواع الطلبات) */
export function Donut({ parts, size = 176 }: { parts: { label: string; value: number; color: string }[]; size?: number }) {
  const total = parts.reduce((a, p) => a + p.value, 0) || 1
  let acc = 0
  return <div className="flex items-center gap-6"><svg viewBox="0 0 42 42" className="-rotate-90" style={{ width: size, height: size }}>{parts.map(p => { const pct = p.value / total * 100; const el = <circle key={p.label} r="15.9" cx="21" cy="21" fill="none" stroke={p.color} strokeWidth="6" strokeDasharray={`${pct} ${100 - pct}`} strokeDashoffset={-acc} />; acc += pct; return el })}</svg>
    <ul className="space-y-1.5 text-[13px]">{parts.map(p => <li key={p.label} className="flex items-center gap-2"><span className="size-2.5 rounded-full" style={{ background: p.color }} />{p.label}<span className="text-ink-3 num">{p.value}</span></li>)}</ul></div>
}

/** خريطة حرارية صفوف × أعمدة (التحليلات — الحضور حسب الإدارة) */
export function Heatmap({ cols, rows, pct = '%', lateLabel = 'late' }: { cols: string[]; rows: { label: string; cells: { rate: number; late: number; tip: string }[] }[]; pct?: string; lateLabel?: string }) {
  const tone = (r: number) => r >= .9 ? 'var(--primary)' : r >= .75 ? 'var(--warn)' : 'var(--danger)'
  return <div className="scroll-x"><table className="w-full border-separate" style={{ borderSpacing: 4 }}><thead><tr><th />{cols.map(d => <th key={d} className="text-[11px] font-normal text-ink-3 num">{d}</th>)}</tr></thead>
    <tbody>{rows.map(r => <tr key={r.label}><td className="text-[12.5px] pe-2 whitespace-nowrap">{r.label}</td>{r.cells.map((c, i) => <td key={i} title={`${c.tip} · ${Math.round(c.rate * 100)}${pct} · ${lateLabel}: ${c.late}`} className="h-9 min-w-9 rounded-md transition hover:scale-110" style={{ background: `color-mix(in oklab, ${tone(c.rate)} ${30 + c.rate * 60}%, transparent)` }} />)}</tr>)}</tbody></table></div>
}
