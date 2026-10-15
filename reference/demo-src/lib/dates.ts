/* ---------- dates & numbers ---------- */
export const DAY = 86400000
export function todayISO() { const d = new Date(); return ymd(d) }
export function ymd(d: Date) { const m = d.getMonth() + 1, dd = d.getDate(); return `${d.getFullYear()}-${m < 10 ? '0' : ''}${m}-${dd < 10 ? '0' : ''}${dd}` }
export function addDays(iso: string, n: number) { const d = new Date(iso + 'T12:00:00'); d.setDate(d.getDate() + n); return ymd(d) }
export function isWeekend(iso: string) { const g = new Date(iso + 'T12:00:00').getDay(); return g === 5 || g === 6 } // Fri, Sat
export function fmtDate(iso: string | undefined, lang: 'ar' | 'en') {
  if (!iso) return '—'
  const d = new Date(iso.length === 10 ? iso + 'T12:00:00' : iso)
  return d.toLocaleDateString(lang === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB', { year: 'numeric', month: 'short', day: 'numeric' })
}
export function fmtDateTime(iso: string | undefined, lang: 'ar' | 'en') {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleString(lang === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}
export function fmtTime(iso: string | undefined) {
  if (!iso) return '—'
  const d = new Date(iso); const h = d.getHours(), m = d.getMinutes()
  return `${h < 10 ? '0' : ''}${h}:${m < 10 ? '0' : ''}${m}`
}
export function fmtNum(n: number) { return n.toLocaleString('en-US') }
export function money(n: number, cur: 'USD' | 'SYP', lang: 'ar' | 'en') {
  if (cur === 'USD') return `$${fmtNum(Math.round(n))}`
  return lang === 'ar' ? `${fmtNum(Math.round(n))} ل.س` : `SYP ${fmtNum(Math.round(n))}`
}
export function daysBetween(a: string, b: string) { return Math.round((new Date(b.slice(0, 10) + 'T12:00:00').getTime() - new Date(a.slice(0, 10) + 'T12:00:00').getTime()) / DAY) }
export function workdaysBetween(from: string, to: string) {
  let n = 0; let d = from
  while (d <= to) { if (!isWeekend(d)) n++; d = addDays(d, 1) }
  return n
}
export function initials(name: string) {
  const p = name.trim().split(/\s+/)
  return (p[0]?.[0] ?? '') + (p.length > 1 ? ' ' + p[p.length - 1][0] : '')
}
