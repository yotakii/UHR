import { useSyncExternalStore } from 'react'

/**
 * توجيه بروابط حقيقية (History API): /jobs/alfarada/REQ-2026-034?ref=X → { page: 'jobs', id: 'alfarada/REQ-2026-034', parts: […], query: {ref: 'X'} }
 * روابط قابلة للمشاركة والفهرسة في محركات البحث (SRS §10.15). الروابط القديمة #/… تُحوَّل تلقائياً.
 */
export type Route = { page: string; id?: string; parts: string[]; query: Record<string, string> }

export function parse(path: string, search = ''): Route {
  const [p, qs = search.replace(/^\?/, '')] = path.includes('?') ? path.split('?') : [path, search.replace(/^\?/, '')]
  const parts = p.split('/').filter(Boolean).map(s => { try { return decodeURIComponent(s) } catch { return s } })
  const [page = '', ...rest] = parts
  return { page, id: rest.length ? rest.join('/') : undefined, parts, query: Object.fromEntries(new URLSearchParams(qs)) }
}

/** href('jobs', 'alfarada/REQ-1') → /jobs/alfarada/REQ-1 — كل مقطع يُرمَّز وحده */
export function href(page: string, id?: string, query?: Record<string, string | undefined>) {
  const clean = query ? Object.fromEntries(Object.entries(query).filter(([, v]) => v !== undefined && v !== '')) as Record<string, string> : {}
  const q = Object.keys(clean).length ? '?' + new URLSearchParams(clean).toString() : ''
  const seg = (s: string) => s.split('/').map(encodeURIComponent).join('/')
  return `/${page ? seg(page) : ''}${id ? '/' + seg(id) : ''}${q}`
}

const EVENT = 'uhr:navigate'
const notify = () => window.dispatchEvent(new Event(EVENT))

export function navigate(url: string, opts: { replace?: boolean; keepScroll?: boolean } = {}) {
  const now = location.pathname + location.search
  if (now !== url) history[opts.replace ? 'replaceState' : 'pushState'](null, '', url)
  if (!opts.keepScroll) { window.scrollTo(0, 0); document.getElementById('main-scroll')?.scrollTo(0, 0) }
  notify()
}

export function go(page: string, id?: string, query?: Record<string, string | undefined>) { navigate(href(page, id, query)) }

/** تحديث معاملات الرابط (الفلاتر) بدون سجل تنقل جديد */
export function setQuery(query: Record<string, string | undefined>) {
  const r = parse(location.pathname, location.search)
  navigate(href(r.page, r.id, { ...r.query, ...query }), { replace: true, keepScroll: true })
}

const sub = (cb: () => void) => { window.addEventListener('popstate', cb); window.addEventListener(EVENT, cb); return () => { window.removeEventListener('popstate', cb); window.removeEventListener(EVENT, cb) } }
export function useRoute(): Route {
  const url = useSyncExternalStore(sub, () => location.pathname + location.search)
  return parse(url)
}

/** روابط <a href="/…"> داخل التطبيق تُفتح بدون إعادة تحميل؛ والروابط القديمة #/… تتحول لمسارات */
/** روابط الواجهة فقط: الـ API والملفات الثابتة (/downloads، أي مسار بامتداد ملف) يفتحها المتصفح مباشرة */
export const isAppPath = (path: string) => !path.startsWith('/api/') && !path.startsWith('/downloads/') && !/\.[a-z0-9]{2,5}$/i.test(path)

export function installRouter() {
  if (location.hash.startsWith('#/')) history.replaceState(null, '', location.hash.slice(1))
  document.addEventListener('click', e => {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return
    const a = (e.target as HTMLElement).closest('a')
    if (!a || a.target === '_blank' || a.hasAttribute('download')) return
    const url = new URL(a.href, location.href)
    if (url.origin !== location.origin || !isAppPath(url.pathname)) return
    e.preventDefault()
    navigate(url.pathname + url.search)
  })
}
