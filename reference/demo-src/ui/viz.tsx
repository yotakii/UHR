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
