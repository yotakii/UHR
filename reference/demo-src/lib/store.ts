import { useSyncExternalStore } from 'react'
import type { State, LogEvent, Bi } from './types'
import { seed } from '../data/seed'

const KEY = 'uhr-demo-state-v2'
const VERSION = 2

function load(): State {
  try {
    const raw = localStorage.getItem(KEY)
    if (raw) {
      const s = JSON.parse(raw) as State
      if (s.version === VERSION) { try { s.session = JSON.parse(sessionStorage.getItem(KEY + '-session') ?? 'null') } catch { s.session = null } return s }
    }
  } catch { /* ignore */ }
  return seed()
}

let state: State = load()
const listeners = new Set<() => void>()

function save() {
  try { sessionStorage.setItem(KEY + '-session', JSON.stringify(state.session)) } catch { /* ignore */ }
  try { localStorage.setItem(KEY, JSON.stringify(state)) } catch {
    // storage full: drop stored CV files and retry once
    try {
      state = { ...state, candidates: state.candidates.map(c => ({ ...c, cvData: undefined })) }
      localStorage.setItem(KEY, JSON.stringify(state))
    } catch { /* give up silently; state still lives in memory */ }
  }
}

// keep several windows/tabs of the demo in sync (e.g. HR screen + employee screen on the same device)
if (typeof window !== 'undefined') window.addEventListener('storage', e => {
  if (e.key !== KEY || !e.newValue) return
  try { const n = JSON.parse(e.newValue) as State; if (n.version === VERSION) { state = { ...n, session: state.session, lang: state.lang, theme: state.theme }; listeners.forEach(l => l()) } } catch { /* ignore */ }
})
export function getState() { return state }
export function update(fn: (s: State) => void) {
  const next = structuredClone(state)
  fn(next)
  state = next
  save()
  listeners.forEach(l => l())
}
export function resetDemo() {
  const { lang, theme, session } = state
  const cards = Object.fromEntries(state.employees.map(e => [e.id, e.nfcUid]))
  state = seed(); state.lang = lang; state.theme = theme
  state.employees.forEach(e => { if (cards[e.id]) e.nfcUid = cards[e.id] })
  state.session = session && session.role === 'hr' ? session : null
  save(); listeners.forEach(l => l())
}
export function useStore<T>(sel: (s: State) => T): T {
  const s = useSyncExternalStore(cb => { listeners.add(cb); return () => listeners.delete(cb) }, () => state)
  return sel(s)
}

export function nextId(s: State, prefix: string) { s.seq += 1; return `${prefix}${s.seq}` }

/* ---------- i18n ---------- */
export function useLang() { return useStore(s => s.lang) }
export function useL() {
  const lang = useLang()
  return (ar: string, en: string) => (lang === 'ar' ? ar : en)
}
export function bi(b: Bi | undefined, lang: 'ar' | 'en') { return b ? b[lang] : '' }
export function ev(ar: string, en: string, kind: LogEvent['kind'] = 'info', form?: string): LogEvent {
  return { at: new Date().toISOString(), ar, en, kind, form }
}

export * from './dates'
export const useRelaxed = () => useStore(s => s.rules?.relaxed !== false)
export function autoSig(seed = 'x') {
  let h = 0; for (const c of seed) h = (h * 31 + c.charCodeAt(0)) % 997
  const y = (k: number) => 30 + Math.round(Math.sin(h + k * 1.7) * 14)
  const d = `M10 ${y(0)} C 40 ${y(1) - 20}, 60 ${y(2) + 18}, 90 ${y(3)} S 140 ${y(4) - 16}, 170 ${y(5)} S 220 ${y(6) + 10}, 250 ${y(7) - 8}`
  const svg = `<svg xmlns='http://www.w3.org/2000/svg' width='260' height='60'><path d='${d}' fill='none' stroke='#17242f' stroke-width='2.6' stroke-linecap='round'/></svg>`
  return 'data:image/svg+xml;utf8,' + encodeURIComponent(svg)
}
/** Display a person stored by Arabic name in the current language */
export function useDisplayName() {
  const lang = useStore(s => s.lang); const emps = useStore(s => s.employees)
  return (n?: string) => { if (!n || lang === 'ar') return n ?? ''; return emps.find(e => e.name === n)?.nameEn ?? n }
}
