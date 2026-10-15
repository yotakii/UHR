import { useSyncExternalStore } from 'react'
import type { Bi, Lang, LogEvent } from './types'

/**
 * تفضيلات الواجهة لكل متصفح (اللغة والثيم) + أدوات الترجمة.
 * يحمل نفس أسماء دوال الديمو (useL, useLang, bi, …) حتى تُنقل الشاشات كما هي.
 * بيانات النظام نفسها تأتي من الـ API (TanStack Query) في المهام القادمة.
 */
export type Theme = 'slate' | 'dark' | 'light'
type Prefs = { lang: Lang; theme: Theme }

const KEY = 'uhr-prefs'
function load(): Prefs {
  try {
    const p = JSON.parse(localStorage.getItem(KEY) ?? '{}')
    return { lang: p.lang === 'en' ? 'en' : 'ar', theme: ['slate', 'dark', 'light'].includes(p.theme) ? p.theme : 'slate' }
  } catch { return { lang: 'ar', theme: 'slate' } }
}

let prefs: Prefs = load()
const listeners = new Set<() => void>()
function emit() { listeners.forEach(l => l()) }
function save() { try { localStorage.setItem(KEY, JSON.stringify({ lang: prefs.lang, theme: prefs.theme })) } catch { /* تخزين غير متاح */ } }

export function applyDocument() {
  const h = document.documentElement
  h.lang = prefs.lang; h.dir = prefs.lang === 'ar' ? 'rtl' : 'ltr'
  h.classList.toggle('dark', prefs.theme !== 'light'); h.classList.toggle('slate', prefs.theme === 'slate')
}

export function setLang(l: Lang) { prefs = { ...prefs, lang: l }; save(); applyDocument(); emit() }
export function setTheme(t: Theme) { prefs = { ...prefs, theme: t }; save(); applyDocument(); emit() }
export function getPrefs() { return prefs }

export function usePrefs<T>(sel: (p: Prefs) => T): T {
  const p = useSyncExternalStore(cb => { listeners.add(cb); return () => listeners.delete(cb) }, () => prefs)
  return sel(p)
}

/* ---------- i18n ---------- */
export function useLang() { return usePrefs(p => p.lang) }
export function useTheme() { return usePrefs(p => p.theme) }
export function useL() {
  const lang = useLang()
  return (ar: string, en: string) => (lang === 'ar' ? ar : en)
}
export function bi(b: Bi | undefined, lang: Lang) { return b ? b[lang] : '' }
export function ev(ar: string, en: string, kind: LogEvent['kind'] = 'info', form?: string): LogEvent {
  return { at: new Date().toISOString(), ar, en, kind, form }
}

export * from './dates'
