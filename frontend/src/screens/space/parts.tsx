import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useL, useLang, bi } from '../../lib/store'
import { parseBody, usePeople, type Person } from '../../lib/space'
import type { Bi } from '../../lib/types'

/** النص مع الإشارات والروابط */
export function Body({ text, className = '' }: { text: string; className?: string }) {
  return <div className={`whitespace-pre-wrap break-words leading-7 ${className}`}>{parseBody(text).map((c, i) => c.t === 'mention'
    ? <span key={i} className="font-semibold text-brand">@{c.name}</span>
    : c.t === 'link' ? <a key={i} href={c.v} target="_blank" rel="noreferrer noopener" className="text-info underline break-all ltr inline">{c.v}</a> : <span key={i}>{c.v}</span>)}</div>
}

/** صورة رمزية بحروف الاسم (لون ثابت لكل شخص) */
export function Initials({ p, size = 40 }: { p: { id?: number | null; name: Bi }; size?: number }) {
  const lang = useLang()
  const name = bi(p.name, lang)
  const letters = name.split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('')
  const tones = ['bg-brand-soft text-brand', 'bg-info-soft text-info', 'bg-brass-soft text-brass', 'bg-danger-soft text-danger']
  return <span className={`inline-grid place-items-center rounded-full font-bold shrink-0 ${tones[(p.id ?? 0) % tones.length]}`} style={{ width: size, height: size, fontSize: size * 0.38 }} aria-hidden>{letters}</span>
}

/** حقل نص يقترح الزملاء عند كتابة @ — يُدرج @[الاسم](رقم) */
export function MentionBox({ value, onChange, placeholder, rows = 3, autoFocus, onSubmit, className = '' }: { value: string; onChange: (v: string) => void; placeholder?: string; rows?: number; autoFocus?: boolean; onSubmit?: () => void; className?: string }) {
  const L = useL(); const lang = useLang()
  const ref = useRef<HTMLTextAreaElement>(null)
  const [q, setQ] = useState<string | null>(null)
  const [at, setAt] = useState(0)
  const [idx, setIdx] = useState(0)
  const people = usePeople(q ?? '', q !== null).data ?? []
  useEffect(() => setIdx(0), [q])
  const detect = (v: string, caret: number) => {
    const before = v.slice(0, caret)
    const m = before.match(/(^|\s)@([\p{L}\p{N}_]{0,30})$/u)
    if (m) { setQ(m[2]); setAt(caret - m[2].length - 1) } else setQ(null)
  }
  const pick = (p: Person) => {
    const caret = ref.current?.selectionStart ?? value.length
    const token = `@[${bi(p.name, lang)}](${p.id}) `
    const next = value.slice(0, at) + token + value.slice(caret)
    onChange(next); setQ(null)
    requestAnimationFrame(() => { const pos = at + token.length; ref.current?.focus(); ref.current?.setSelectionRange(pos, pos) })
  }
  return <div className="relative">
    <textarea ref={ref} rows={rows} autoFocus={autoFocus} className={`field !h-auto py-2 leading-7 resize-y ${className}`} value={value} placeholder={placeholder}
      onChange={e => { onChange(e.target.value); detect(e.target.value, e.target.selectionStart) }}
      onKeyDown={e => {
        if (q !== null && people.length) {
          if (e.key === 'ArrowDown') { e.preventDefault(); setIdx(i => (i + 1) % people.length); return }
          if (e.key === 'ArrowUp') { e.preventDefault(); setIdx(i => (i - 1 + people.length) % people.length); return }
          if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); pick(people[idx]); return }
          if (e.key === 'Escape') { setQ(null); return }
        }
        if (onSubmit && e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); onSubmit() }
      }} />
    {q !== null && people.length > 0 && <ul role="listbox" className="absolute z-30 start-0 top-full mt-1 w-72 max-w-full rounded-xl border border-line bg-surface shadow-lg py-1">
      {people.map((p, i) => <li key={p.id} role="option" aria-selected={i === idx}><button type="button" onMouseDown={e => { e.preventDefault(); pick(p) }}
        className={`w-full flex items-center gap-2 px-3 py-1.5 text-start ${i === idx ? 'bg-sub' : ''}`}><Initials p={p} size={26} /><span className="min-w-0"><span className="block text-[13px] font-semibold truncate">{bi(p.name, lang)}</span>
          {p.title && <span className="block text-[11.5px] text-ink-3 truncate">{bi(p.title, lang)}</span>}</span></button></li>)}
    </ul>}
    <p className="sr-only">{L('اكتب @ ثم اسم الزميل للإشارة إليه.', 'Type @ and a colleague’s name to mention them.')}</p>
  </div>
}

export function Pill({ children, tone = 'neutral' }: { children: ReactNode; tone?: 'neutral' | 'brand' | 'brass' | 'info' | 'danger' }) {
  const c = { neutral: 'bg-sub text-ink-2', brand: 'bg-brand-soft text-brand', brass: 'bg-brass-soft text-brass', info: 'bg-info-soft text-info', danger: 'bg-danger-soft text-danger' }[tone]
  return <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11.5px] font-semibold ${c}`}>{children}</span>
}

const TOKENS: Record<string, string> = { brand: '--primary', brass: '--warn', info: '--user', danger: '--danger', 'ink-3': '--fg3' }
export const dot = (color: string) => ({ background: `var(${TOKENS[color] ?? '--primary'})` })
