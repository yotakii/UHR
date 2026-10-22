import { useMutation, useQuery } from '@tanstack/react-query'
import { api, xsrf } from './api'
import { queryClient } from './session'
import type { Bi } from './types'

/** M08 — الحضور، سياسات الدوام بتاريخ نفاذ، العطل، أجهزة الحضور */
export type AttStatus = 'before_start' | 'leave' | 'holiday' | 'weekend' | 'not_in' | 'absent' | 'incomplete' | 'justified' | 'late' | 'present'
export interface Person { id: number; code: string; name: Bi; title: Bi; dept: (Bi & { id: number }) | null }
export interface AttRow { date: string; in: string | null; out: string | null; on_shift: boolean; worked: number; late: number; early: number; ot: number; ot_rate: number; deduct: number; status: AttStatus; source: string | null; corrected: boolean; note: string | null }
export interface Meta { depts: (Bi & { id: number })[]; can: { edit: boolean; export: boolean; devices: boolean; policies: boolean } }
export interface Tap { id: number; employee: { id: number; code: string; name: Bi }; type: 'in' | 'out'; time: string; device: Bi; via: string }
export interface Daily extends Meta { date: string; latest: Tap[]; rows: (AttRow & { employee: Person })[]; counts: { present: number; late: number; absent: number; leave: number; incomplete: number } }
export interface PeriodRow { employee: Person; present: number; absent: number; late: number; late_days: number; deduct: number; ot: number; incomplete: number; leave: number }
export interface Period extends Meta { from: string; to: string; rows: PeriodRow[] }

const qs = (o: Record<string, string | number | undefined>) => new URLSearchParams(Object.entries(o).filter(([, v]) => v !== undefined && v !== '') as [string, string][]).toString()
/** سجل اليوم: يتحدث تلقائياً كل 10 ثوانٍ لليوم الحالي حتى تظهر حركات الأجهزة فوراً */
export const useDaily = (date: string, dept: string, live = false) => useQuery({ queryKey: ['hr', 'attendance', 'day', date, dept], queryFn: () => api<Daily>(`/hr/attendance?${qs({ date, dept })}`), placeholderData: p => p, refetchInterval: live ? 10_000 : false })
export const usePeriod = (from: string, to: string, dept: string, enabled = true) => useQuery({ queryKey: ['hr', 'attendance', 'period', from, to, dept], queryFn: () => api<Period>(`/hr/attendance/period?${qs({ from, to, dept })}`), enabled, placeholderData: p => p })
export const periodExportUrl = (from: string, to: string, dept: string) => `/api/hr/attendance/export?${qs({ from, to, dept })}`
export const useCorrect = () => useMutation({
  mutationFn: (v: { employee: number; date: string; in: string; out: string; note: string }) => api<{ data: AttRow }>(`/hr/attendance/${v.employee}`, { method: 'PUT', json: { date: v.date, in: v.in || null, out: v.out || null, note: v.note } }),
  onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['hr', 'attendance'] }); queryClient.invalidateQueries({ queryKey: ['hr', 'employee'] }) },
})

/* ---------- السياسات ---------- */
export interface Shift { start: number; end: number; grace: number; break: number; break_after: number; ot_threshold: number; night_from: number }
export interface Tier { from: number; to: number; factor: number }
export interface PolicyValues {
  shift: Shift; work_days: number[]; overtime_rates: { day: number; night: number; rest: number; holiday: number }
  late_deduction: { tiers: Tier[]; exempt_upto: number; exempt_per_month: number; absent_after: number }
  overtime: { weekly_hours: number; daily_max: number; requires_assignment: boolean; max_continuous_hours: number }
  terminal: { cooldown: number; incomplete_after: number }
  annual_leave: { first_year: number; standard: number; senior: number; standard_years: number; senior_years: number; senior_age: number; prorate_first_year: boolean }
  leave_rules: {
    notice: { max_days: number; days_before: number }[]; hourly_notice_days: number; exec_after_days: number; substitute_required: boolean; conflict_note_required: boolean
    emergency_per_year: number; emergency_report_hours: number; hourly_max_per_day: number; hourly_day_hours: number; unpaid_max_per_year: number
    reject_min: number; reminder_hours: number; justify_hours: number; probation_paid: boolean
  }
  leave_types: LeaveTypePolicy[]
  payroll: { start_date: string | null; month_days: number; day_hours: number; absent_days: number; justified_absent_days: number; insurance_base: number; insurance_rate: number
    advance_earned_ratio: number; advance_max_ratio: number; advance_per_month: number }
  exit: { notice_days: number; decision_days: number; notice_pay_months: number; death_grant_months: number; insurance_days: number }
  discipline: { cap_days: number; deadline_days: number; repeat_months: number; hr_max_days: number; suggest_late: number; suggest_absent: number; suggest_early: number }
}
export interface LeaveTypePolicy {
  key: string; ar: string; en: string; paid: number; balance: 'annual' | 'sick' | 'unpaid' | null; unit: 'day' | 'hour'; max_days: number | null; notice: boolean
  doc: 'none' | 'optional' | 'required'; after_months: number; confirmed: boolean; once: boolean; calendar: boolean; exec: boolean
}
export type PolicyKey = keyof PolicyValues
export interface Version { id: number; effective_from: string; value: unknown; state: 'past' | 'current' | 'future' }
export interface Policies { versions: Record<string, Version[]>; current: PolicyValues; defaults: PolicyValues; departments: (Bi & { id: number })[]; can: { edit: boolean } }
export interface PreviewSum { late_days: number; deduct: number; ot: number; absent: number; employees: number }
export const usePolicies = () => useQuery({ queryKey: ['hr', 'policies'], queryFn: () => api<Policies>('/hr/policies') })
export const useSavePolicy = () => useMutation({
  mutationFn: (v: { key: PolicyKey; department_id?: number | null; effective_from: string; value: unknown }) => api<Policies>('/hr/policies', { json: v }),
  onSuccess: d => { queryClient.setQueryData(['hr', 'policies'], d); queryClient.invalidateQueries({ queryKey: ['hr', 'attendance'] }) },
})
export const useDeletePolicy = () => useMutation({ mutationFn: (id: number) => api<Policies>(`/hr/policies/${id}`, { method: 'DELETE' }), onSuccess: d => queryClient.setQueryData(['hr', 'policies'], d) })
export const usePreviewPolicy = () => useMutation({ mutationFn: (v: { key: PolicyKey; department_id?: number | null; value: unknown }) => api<{ from: string; to: string; current: PreviewSum; proposed: PreviewSum }>('/hr/policies/preview', { json: v }) })

export interface Holiday { id: number; date: string; ar: string; en: string }
export const useHolidays = (year: number) => useQuery({ queryKey: ['hr', 'holidays', year], queryFn: () => api<{ year: number; data: Holiday[] }>(`/hr/holidays?year=${year}`) })
const setHol = (d: { year: number; data: Holiday[] }) => { queryClient.setQueryData(['hr', 'holidays', d.year], d); queryClient.invalidateQueries({ queryKey: ['hr', 'attendance'] }) }
export const useAddHoliday = () => useMutation({ mutationFn: (v: { date: string; days: number; name_ar: string; name_en: string }) => api<{ year: number; data: Holiday[] }>('/hr/holidays', { json: v }), onSuccess: setHol })
export const useFixedHolidays = () => useMutation({ mutationFn: (year: number) => api<{ year: number; data: Holiday[] }>('/hr/holidays/fixed', { json: { year } }), onSuccess: setHol })
export const useDeleteHoliday = () => useMutation({ mutationFn: (id: number) => api<{ year: number; data: Holiday[] }>(`/hr/holidays/${id}`, { method: 'DELETE' }), onSuccess: setHol })

/* ---------- الأجهزة ---------- */
export type DeviceKind = 'reader' | 'sunmi' | 'mobile' | 'bridge'
export interface DeviceRow { id: number; name: Bi; name_ar: string; name_en: string; kind: DeviceKind; location_id: number | null; location: Bi | null; active: boolean; online: boolean; key_hint: string | null; last_seen_at: string | null; last_ip: string | null }
export interface DeviceInput { name_ar: string; name_en: string; kind: DeviceKind; location_id: number | null; active?: boolean }
export const useDevices = () => useQuery({ queryKey: ['hr', 'devices'], queryFn: () => api<{ data: DeviceRow[]; locations: (Bi & { id: number })[] }>('/hr/devices') })
const devs = () => queryClient.invalidateQueries({ queryKey: ['hr', 'devices'] })
export const useAddDevice = () => useMutation({ mutationFn: (v: DeviceInput) => api<{ data: DeviceRow; key: string }>('/hr/devices', { json: v }), onSuccess: devs })
export const useUpdateDevice = () => useMutation({ mutationFn: (v: DeviceInput & { id: number; active: boolean }) => api<{ data: DeviceRow }>(`/hr/devices/${v.id}`, { method: 'PUT', json: v }), onSuccess: devs })
export const useRotateDevice = () => useMutation({ mutationFn: (id: number) => api<{ data: DeviceRow; key: string }>(`/hr/devices/${id}/rotate`, { json: {} }), onSuccess: devs })

/** تنزيل الجسر جاهزاً (zip بعنوان النظام ومفتاح جديد للجهاز) — POST لأن المفتاح يتجدد */
export async function downloadBridge(id: number) {
  if (!xsrf()) await fetch('/api/auth/me', { credentials: 'same-origin' })
  const res = await fetch(`/api/hr/devices/${id}/package`, { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': xsrf(), Accept: 'application/zip' } })
  if (!res.ok) throw new Error(`HTTP ${res.status}`)
  const url = URL.createObjectURL(await res.blob())
  const a = document.createElement('a'); a.href = url; a.download = 'UHR-NFC-Bridge.zip'; document.body.appendChild(a); a.click(); a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 5000)
  queryClient.invalidateQueries({ queryKey: ['hr', 'devices'] })
}

/** دقائق اليوم ↔ «HH:MM» */
export const hhmm = (m: number) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`
export const toMins = (s: string) => { const [h, m] = s.split(':').map(Number); return (h || 0) * 60 + (m || 0) }
