import { getPrefs } from './store'

// عميل الـ API الموحد — كل الطلبات تمر من /api (Vite proxy محلياً، nginx بالإنتاج) بنفس الأصل:
// الجلسة بكوكي httpOnly، وحماية CSRF بهيدر X-XSRF-TOKEN المأخوذ من كوكي XSRF-TOKEN.
export class ApiError extends Error {
  constructor(public status: number, message: string, public code?: string, public errors: Record<string, string[]> = {}, public data: Record<string, unknown> = {}) { super(message) }
  /** أول رسالة تحقق لحقل معيّن */
  field(k: string) { return this.errors[k]?.[0] }
}

/** مستمع لهيدر X-Salary-Remaining (نافذة الرواتب تتجدد مع الاستخدام — lib/vault.ts) */
export const salaryHeader: { fn: ((seconds: number) => void) | null } = { fn: null }

export function xsrf() {
  const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)
  return m ? decodeURIComponent(m[1]) : ''
}

export async function api<T>(path: string, init?: RequestInit & { json?: unknown }): Promise<T> {
  const { json, ...rest } = init ?? {}
  // FormData (رفع ملفات): المتصفح يضبط Content-Type مع الـ boundary بنفسه
  const contentType: Record<string, string> = rest.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }
  const method = (rest.method ?? (json !== undefined ? 'POST' : 'GET')).toUpperCase()
  if (method !== 'GET' && !xsrf()) await fetch('/api/auth/me', { credentials: 'same-origin', headers: { Accept: 'application/json' } })   // يضبط كوكي CSRF
  const res = await fetch(`/api${path}`, {
    ...rest, method, credentials: 'same-origin',
    body: json !== undefined ? JSON.stringify(json) : rest.body,
    headers: { Accept: 'application/json', ...contentType, 'Accept-Language': getPrefs().lang, 'X-XSRF-TOKEN': xsrf(), ...rest.headers },
  })
  const sr = res.headers.get('X-Salary-Remaining')
  if (sr !== null && salaryHeader.fn) salaryHeader.fn(Number(sr))
  const text = await res.text()
  let body: Record<string, unknown> | null = null
  try { body = text ? JSON.parse(text) : null } catch { /* استجابة غير JSON */ }
  if (!res.ok && res.status !== 503) {
    const en = getPrefs().lang === 'en'
    const fallback = res.status === 419 ? (en ? 'Your session expired. Reload the page and try again.' : 'انتهت صلاحية الجلسة. أعد تحميل الصفحة وحاول مجدداً.')
      : res.status >= 500 ? (en ? 'Something went wrong on the server. Try again shortly.' : 'حدث خطأ في الخادم. حاول بعد قليل.') : `HTTP ${res.status}`
    throw new ApiError(res.status, (body?.message as string) ?? fallback, body?.code as string | undefined, (body?.errors as Record<string, string[]>) ?? {}, body ?? {})
  }
  if (body === null) throw new ApiError(res.status, 'استجابة غير صالحة من الخادم')
  return body as T
}

export interface ServiceCheck { ok: boolean; ms: number; version?: string; error?: string; [k: string]: unknown }
export interface Health {
  status: 'ok' | 'degraded'
  app: { name: string; env: string; php: string; laravel: string; timezone: string; time: string }
  checks: { database: ServiceCheck & { migrations?: number }; redis: ServiceCheck; storage: ServiceCheck }
}
