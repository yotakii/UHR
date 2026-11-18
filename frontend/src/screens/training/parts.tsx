import { useRef, useState, type ReactNode } from 'react'
import { Loader2, Paperclip } from 'lucide-react'
import { ApiError } from '../../lib/api'
import { useL, useLang, bi } from '../../lib/store'
import { PATH, uploadTdFile, type Lookups, type Path } from '../../lib/training'
import { Badge, Btn, toast } from '../../ui/kit'

export const errOf = (e: unknown) => e instanceof ApiError ? e : null
export const fail = (e: unknown) => toast(e instanceof Error ? e.message : String(e))
export const Spin = () => <div className="grid place-items-center py-16"><Loader2 className="animate-spin text-brand" /></div>

export function PathBadge({ p }: { p: Path | null | undefined }) {
  const L = useL()
  if (!p || !PATH[p]) return null
  return <span className="inline-flex items-center gap-1 text-[11.5px] rounded-md px-1.5 py-0.5 bg-sub"><span className="size-2 rounded-full" style={{ background: PATH[p][2] }} />{L(PATH[p][0], PATH[p][1])}</span>
}

export function StatusBadge({ map, s }: { map: Record<string, [string, string, 'brand' | 'brass' | 'info' | 'danger' | 'neutral' | 'warn']>; s: string }) {
  const L = useL()
  const m = map[s]
  return <Badge tone={m?.[2] ?? 'neutral'}>{m ? L(m[0], m[1]) : s}</Badge>
}

export function Stat({ label, value, sub, tone }: { label: ReactNode; value: ReactNode; sub?: ReactNode; tone?: string }) {
  return <div className="bg-surface border border-line rounded-2xl p-3.5">
    <div className="text-[12px] text-ink-3">{label}</div>
    <div className="text-[22px] font-semibold num mt-0.5" style={tone ? { color: tone } : undefined}>{value}</div>
    {sub && <div className="text-[11.5px] text-ink-3 mt-0.5">{sub}</div>}
  </div>
}

/** شريط مستوى الكفاءة 1–5 مع المستوى المطلوب */
export function LevelBar({ current, required }: { current: number | null; required: number | null }) {
  return <div className="flex gap-0.5" aria-label={`${current ?? 0}/${required ?? 0}`}>
    {[1, 2, 3, 4, 5].map(n => <span key={n} className="h-2 w-4 shrink-0 rounded-sm" style={{ background: current != null && n <= current ? 'var(--brand)' : required != null && n <= required ? 'color-mix(in oklab, var(--danger) 45%, transparent)' : 'var(--line)' }} />)}
  </div>
}

export function EmpSelect({ lk, value, onChange, multiple, label }: { lk: Lookups; value: number[]; onChange: (v: number[]) => void; multiple?: boolean; label?: string }) {
  const L = useL(); const lang = useLang()
  const [q, setQ] = useState('')
  const rows = lk.employees.filter(e => !q || bi(e, lang).includes(q) || (e.code ?? '').toLowerCase().includes(q.toLowerCase())).slice(0, 60)
  if (!multiple) return <select className="field" value={value[0] ?? ''} aria-label={label} onChange={e => onChange(e.target.value ? [Number(e.target.value)] : [])}>
    <option value="">{L('— اختر —', '— Choose —')}</option>{lk.employees.map(e => <option key={e.id} value={e.id}>{bi(e, lang)} · {e.code}</option>)}</select>
  return <div className="border border-line rounded-xl">
    <input className="field !border-0 !border-b !rounded-none" placeholder={L('ابحث بالاسم أو الرقم…', 'Search by name or code…')} value={q} onChange={e => setQ(e.target.value)} />
    <div className="max-h-48 overflow-y-auto p-1">{rows.map(e => <label key={e.id} className="flex items-center gap-2 px-2 py-1 text-[13px] rounded-lg hover:bg-sub">
      <input type="checkbox" checked={value.includes(e.id)} onChange={x => onChange(x.target.checked ? [...value, e.id] : value.filter(i => i !== e.id))} />
      <span className="flex-1">{bi(e, lang)}</span><span className="text-ink-3 ltr text-[11.5px]">{e.code}</span></label>)}</div>
    <div className="px-3 py-1.5 border-t border-line text-[12px] text-ink-3">{L(`المحدد: ${value.length}`, `Selected: ${value.length}`)}</div>
  </div>
}

export function TdFilePick({ onDone, label }: { onDone: (f: { id: number; name: string }) => void; label?: string }) {
  const L = useL()
  const ref = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false); const [name, setName] = useState('')
  return <div className="flex items-center gap-2">
    <input ref={ref} type="file" className="hidden" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx,.pptx" onChange={async e => {
      const f = e.target.files?.[0]; if (!f) return
      setBusy(true)
      try { const r = await uploadTdFile(f); setName(r.name); onDone(r) } catch (x) { fail(x) } finally { setBusy(false); e.target.value = '' }
    }} />
    <Btn type="button" size="sm" icon={busy ? <Loader2 size={14} className="animate-spin" /> : <Paperclip size={14} />} onClick={() => ref.current?.click()}>{label ?? L('إرفاق ملف', 'Attach a file')}</Btn>
    {name && <span className="text-[12px] text-ink-3 truncate max-w-[200px]">{name}</span>}
  </div>
}

/** تقييم بالنجوم 1–5 */
export function Stars({ value, onChange }: { value: number; onChange: (v: number) => void }) {
  return <div className="flex gap-1">{[1, 2, 3, 4, 5].map(n => <button key={n} type="button" onClick={() => onChange(n)} className={`size-8 rounded-lg border text-[13px] num ${n <= value ? 'bg-brand text-white border-brand' : 'border-line'}`}>{n}</button>)}</div>
}
