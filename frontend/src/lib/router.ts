import { useSyncExternalStore } from 'react'

/**
 * توجيه بالـ hash: #/jobs/REQ-2026-031?ref=X → { page: 'jobs', id: 'REQ-2026-031', query: {ref: 'X'} }
 * روابط قابلة للمشاركة والنسخ (رابط مباشر لكل وظيفة، رابط إعادة التعيين من البريد).
 */
export type Route = { page: string; id?: string; query: Record<string, string> }

export function parse(hash: string): Route {
  const h = hash.replace(/^#\/?/, '')
  const [path, qs = ''] = h.split('?')
  const [page = '', ...rest] = path.split('/').filter(Boolean).map(decodeURIComponent)
  return { page, id: rest.length ? rest.join('/') : undefined, query: Object.fromEntries(new URLSearchParams(qs)) }
}

export function href(page: string, id?: string, query?: Record<string, string>) {
  const q = query && Object.keys(query).length ? '?' + new URLSearchParams(query).toString() : ''
  return `#/${page}${id ? '/' + encodeURIComponent(id) : ''}${q}`
}

export function go(page: string, id?: string, query?: Record<string, string>) {
  const h = href(page, id, query)
  if (location.hash !== h) location.hash = h
  window.scrollTo(0, 0); document.getElementById('main-scroll')?.scrollTo(0, 0)
}

const sub = (cb: () => void) => { window.addEventListener('hashchange', cb); return () => window.removeEventListener('hashchange', cb) }
export function useRoute(): Route {
  const hash = useSyncExternalStore(sub, () => location.hash)
  return parse(hash)
}
