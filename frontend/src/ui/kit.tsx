import { useEffect, useRef, useState, type ReactNode, type ButtonHTMLAttributes } from 'react'
import { X, UserRound } from 'lucide-react'
import { useL } from '../lib/store'

type Tone = 'neutral' | 'brand' | 'brass' | 'danger' | 'info' | 'warn'
const toneCls: Record<Tone, string> = {
  neutral: 'bg-sub text-ink-2', brand: 'bg-brand-soft text-brand-2', brass: 'bg-brass-soft text-brass',
  danger: 'bg-danger-soft text-danger', info: 'bg-info-soft text-info', warn: 'bg-warn-soft text-warn',
}
export function Badge({ tone = 'neutral', children, dot }: { tone?: Tone; children: ReactNode; dot?: boolean }) {
  return <span className={`inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[12px] font-semibold whitespace-nowrap ${toneCls[tone]}`}>
    {dot && <span className="size-1.5 rounded-full bg-current" />}{children}</span>
}

type BtnKind = 'primary' | 'secondary' | 'ghost' | 'danger' | 'brass'
const btnCls: Record<BtnKind, string> = {
  primary: 'bg-brand text-on-brand hover:brightness-110 border-brand',
  secondary: 'bg-surface text-ink border-line hover:bg-sub hover:border-brand/40',
  ghost: 'bg-transparent text-ink-2 border-transparent hover:bg-sub',
  danger: 'bg-surface text-danger border-line hover:bg-danger-soft hover:border-danger/40',
  brass: 'bg-brass text-on-brand border-brass hover:brightness-110',
}
export function Btn({ kind = 'secondary', size = 'md', icon, children, className = '', ...p }: ButtonHTMLAttributes<HTMLButtonElement> & { kind?: BtnKind; size?: 'sm' | 'md' | 'lg'; icon?: ReactNode }) {
  const sz = size === 'sm' ? 'h-8 px-2.5 text-[13px]' : size === 'lg' ? 'h-11 px-5 text-[15px]' : 'h-9 px-3.5'
  return <button {...p} className={`inline-flex items-center justify-center gap-2 rounded-lg border font-semibold transition active:scale-[.97] disabled:opacity-45 disabled:pointer-events-none ${sz} ${btnCls[kind]} ${className}`}>{icon}{children}</button>
}

export function Panel({ title, action, children, className = '', pad = true, sub }: { title?: ReactNode; action?: ReactNode; children: ReactNode; className?: string; pad?: boolean; sub?: ReactNode }) {
  return <section className={`bg-surface border border-line rounded-2xl ${className}`}>
    {(title || action) && <header className="flex items-center justify-between gap-3 px-5 min-h-14 py-2 border-b border-line-2">
      <div className="min-w-0"><h3 className="font-display font-bold text-[15px] truncate">{title}</h3>{sub && <div className="text-[12px] text-ink-3 -mt-0.5">{sub}</div>}</div>{action}</header>}
    <div className={pad ? 'p-5' : ''}>{children}</div>
  </section>
}

export function PageHead({ title, sub, actions, crumbs }: { title: ReactNode; sub?: ReactNode; actions?: ReactNode; crumbs?: ReactNode }) {
  return <div className="flex flex-wrap items-end justify-between gap-3 mb-4" data-noprint>
    <div>{crumbs && <div className="text-[12px] text-ink-3 mb-1">{crumbs}</div>}
      <h1 className="text-[24px] font-bold leading-tight">{title}</h1>{sub && <p className="text-ink-2 mt-0.5">{sub}</p>}</div>
    <div className="flex flex-wrap gap-2">{actions}</div>
  </div>
}

export function Field({ label, children, hint, req, error, className = '', div }: { label: ReactNode; children: ReactNode; hint?: ReactNode; req?: boolean; error?: string; className?: string; div?: boolean }) {
  const Tag = div ? 'div' : 'label'
  return <Tag className={`block ${className}`}>
    <span className="block text-[13px] font-medium text-ink-2 mb-1">{label}{req && <span className="text-danger"> *</span>}</span>
    {children}
    {error ? <span className="block text-[12px] text-danger mt-1">{error}</span> : hint ? <span className="block text-[12px] text-ink-3 mt-1">{hint}</span> : null}
  </Tag>
}

export function Seg<T extends string>({ value, onChange, options }: { value: T; onChange: (v: T) => void; options: { v: T; label: ReactNode }[] }) {
  return <div className="inline-flex rounded-lg border border-line bg-surface p-0.5">
    {options.map(o => <button type="button" key={o.v} onClick={() => onChange(o.v)} className={`h-8 px-3 rounded-md text-[13px] font-semibold ${value === o.v ? 'bg-brand text-on-brand' : 'text-ink-2 hover:bg-sub'}`}>{o.label}</button>)}
  </div>
}

export function Avatar({ size = 32, tone = 'brand' }: { name?: string; size?: number; tone?: 'brand' | 'brass' | 'ink' }) {
  const cls = tone === 'brand' ? 'bg-brand-soft text-brand border-brand/25' : tone === 'brass' ? 'bg-brass-soft text-brass border-brass/30' : 'bg-info-soft text-info border-info/30'
  return <span className={`inline-grid place-items-center rounded-full border shrink-0 ${cls}`} style={{ width: size, height: size }}><UserRound size={Math.round(size * 0.55)} strokeWidth={1.8} /></span>
}

export function Modal({ open, onClose, title, children, width = 560, footer }: { open: boolean; onClose: () => void; title: ReactNode; children: ReactNode; width?: number; footer?: ReactNode }) {
  useEffect(() => { if (!open) return; const k = (e: KeyboardEvent) => e.key === 'Escape' && onClose(); window.addEventListener('keydown', k); return () => window.removeEventListener('keydown', k) }, [open, onClose])
  if (!open) return null
  return <div className="fixed inset-0 z-50 grid place-items-center bg-black/55 p-4" onMouseDown={onClose} data-noprint>
    <div className="bg-surface rounded-2xl border border-line w-full max-h-[90vh] flex flex-col shadow-2xl fadeup" style={{ maxWidth: width }} onMouseDown={e => e.stopPropagation()} role="dialog" aria-modal>
      <header className="flex items-center justify-between px-5 h-13 py-3 border-b border-line-2"><h3 className="font-semibold text-[15px]">{title}</h3>
        <button onClick={onClose} className="p-1 rounded hover:bg-sub text-ink-3" aria-label="close"><X size={18} /></button></header>
      <div className="p-5 overflow-y-auto">{children}</div>
      {footer && <footer className="px-5 py-3 border-t border-line-2 flex justify-end gap-2">{footer}</footer>}
    </div>
  </div>
}

export function Drawer({ open, onClose, children, width = 620 }: { open: boolean; onClose: () => void; children: ReactNode; width?: number }) {
  useEffect(() => { if (!open) return; const k = (e: KeyboardEvent) => e.key === 'Escape' && onClose(); window.addEventListener('keydown', k); return () => window.removeEventListener('keydown', k) }, [open, onClose])
  if (!open) return null
  return <div className="fixed inset-0 z-40 bg-black/45" onMouseDown={onClose} data-noprint>
    <aside className="drawer absolute inset-y-0 end-0 bg-surface border-s border-line flex flex-col w-full" style={{ maxWidth: width }} onMouseDown={e => e.stopPropagation()}>{children}</aside>
  </div>
}

export function Tabs<T extends string>({ value, onChange, tabs }: { value: T; onChange: (v: T) => void; tabs: { v: T; label: ReactNode; count?: number }[] }) {
  return <div className="flex gap-1 border-b border-line overflow-x-auto" data-noprint>
    {tabs.map(t => <button key={t.v} onClick={() => onChange(t.v)} className={`h-11 px-3.5 -mb-px border-b-2 whitespace-nowrap text-[14px] transition-colors ${value === t.v ? 'border-brand text-brand font-bold' : 'border-transparent text-ink-2 hover:text-ink'}`}>
      {t.label}{t.count !== undefined && <span className="ms-1.5 text-[12px] text-ink-3 num">{t.count}</span>}</button>)}
  </div>
}

export function Empty({ title, children, action }: { title: ReactNode; children?: ReactNode; action?: ReactNode }) {
  return <div className="text-center py-10 px-4"><div className="font-semibold">{title}</div>{children && <div className="text-ink-3 text-[13px] mt-1 max-w-sm mx-auto">{children}</div>}{action && <div className="mt-4">{action}</div>}</div>
}

export function Progress({ value, tone = 'brand' }: { value: number; tone?: 'brand' | 'brass' | 'danger' | 'info' }) {
  const c = tone === 'brand' ? 'bg-brand' : tone === 'brass' ? 'bg-brass' : tone === 'danger' ? 'bg-danger' : 'bg-info'
  return <div className="h-1.5 bg-sub rounded-full overflow-hidden"><div className={`h-full rounded-full transition-[width] duration-700 ${c}`} style={{ width: `${Math.max(0, Math.min(100, value))}%` }} /></div>
}

/* ---------- toast ---------- */
let pushToast: (m: string) => void = () => {}
export function toast(m: string) { pushToast(m) }
export function Toaster() {
  const [items, setItems] = useState<{ id: number; m: string }[]>([])
  useEffect(() => { pushToast = (m) => { const id = Date.now() + Math.random(); setItems(x => [...x, { id, m }]); setTimeout(() => setItems(x => x.filter(i => i.id !== id)), 3200) } }, [])
  return <div className="fixed bottom-4 left-1/2 -translate-x-1/2 z-[60] flex flex-col gap-2 items-center" data-noprint>
    {items.map(i => <div key={i.id} className="bg-surface border border-brand/40 text-ink rounded-xl px-4 py-2.5 text-[14px] shadow-2xl fadeup flex items-center gap-2"><span className="size-2 rounded-full bg-brand" />{i.m}</div>)}
  </div>
}

/* ---------- signature pad ---------- */
export function SignaturePad({ value, onChange, height = 130 }: { value?: string; onChange: (d: string | undefined) => void; height?: number }) {
  const L = useL()
  const ref = useRef<HTMLCanvasElement>(null)
  const drawing = useRef(false)
  const dirty = useRef(false)
  useEffect(() => {
    const c = ref.current!; const r = c.getBoundingClientRect(); c.width = r.width * 2; c.height = height * 2
    const ctx = c.getContext('2d')!; ctx.scale(2, 2); ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#17242f'
    if (value) { const img = new Image(); img.onload = () => ctx.drawImage(img, 0, 0, r.width, height); img.src = value }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
  const pos = (e: React.PointerEvent) => { const r = ref.current!.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top] }
  return <div>
    <div className="relative border border-dashed border-ink-3 rounded-md bg-white">
      <canvas ref={ref} style={{ height, width: '100%', touchAction: 'none' }}
        onPointerDown={e => { drawing.current = true; const [x, y] = pos(e); const ctx = ref.current!.getContext('2d')!; ctx.beginPath(); ctx.moveTo(x, y); (e.target as Element).setPointerCapture(e.pointerId) }}
        onPointerMove={e => { if (!drawing.current) return; const [x, y] = pos(e); const ctx = ref.current!.getContext('2d')!; ctx.lineTo(x, y); ctx.stroke(); dirty.current = true }}
        onPointerUp={() => { drawing.current = false; if (dirty.current) onChange(ref.current!.toDataURL('image/png')) }} />
      {!value && <span className="pointer-events-none absolute inset-0 grid place-items-center text-slate-500 text-[13px]">{L('وقّع هنا بالفأرة أو باللمس', 'Sign here with mouse or touch')}</span>}
    </div>
    <button type="button" className="text-[12px] text-ink-3 hover:text-danger mt-1" onClick={() => { const c = ref.current!; c.getContext('2d')!.clearRect(0, 0, c.width, c.height); dirty.current = false; onChange(undefined) }}>{L('مسح التوقيع', 'Clear signature')}</button>
  </div>
}

export function KV({ k, v }: { k: ReactNode; v: ReactNode }) {
  return <div className="flex justify-between gap-4 py-1.5 border-b border-line-2 last:border-0 text-[13px]"><span className="text-ink-3">{k}</span><span className="font-medium text-end">{v}</span></div>
}
