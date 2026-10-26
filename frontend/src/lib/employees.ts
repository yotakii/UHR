import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'
import type { LogRow } from './candidates'
import type { AssetRow, EmpStatus } from './onboarding'

/** M07 — دليل الموظفين، الملف الوظيفي، الملفات الإضافية، الهيكل التنظيمي */
export type { EmpStatus }
export type DirStatus = 'current' | 'probation' | 'resigning' | 'terminated' | 'all'
export interface DeptRef extends Bi { id: number; code: string; color: string }
export interface EmpRow { id: number; code: string; name: Bi; email: string; gender: 'M' | 'F'; title: Bi; dept: DeptRef; city: Bi; hire_date: string; status: EmpStatus }
export interface Directory { data: EmpRow[]; current: number; departments: number; depts: DeptRef[]; can: { create: boolean; export: boolean } }

export type DayStatus = 'before_start' | 'leave' | 'holiday' | 'not_in' | 'weekend' | 'absent' | 'incomplete' | 'justified' | 'late' | 'present'
export interface DayRow { date: string; in: string | null; out: string | null; on_shift: boolean; worked: number; late: number; ot: number; ot_rate: number; status: DayStatus }
export interface LeaveRow { id: number; type: string; type_name: Bi; from: string; to: string; hours: number | null; reason: string; status: string }
export interface ServiceRow { id: number; kind: string; note: string; status: string; at: string }
export interface EmpDoc {
  id: number; type: string; type_name: Bi; title: string | null; note: string | null
  file: { id: number; name: string; size: number; mime: string | null }
  expires_at: string | null; expiring: boolean; sensitive: boolean; status: 'active' | 'expired' | 'archived'; uploaded_at: string; uploaded_by: Bi | null
}
export interface EmpFile extends EmpRow {
  department_id: number; location_id: number; manager_id: number | null; title_ar: string; title_en: string; name_ar: string; name_en: string
  manager: (Bi & { id: number }) | null; national_id: string | null; dob: string | null; phone: string | null; nfc_uid: string | null
  end_date: string | null; shift: string; tenure_days: number
  leave: { entitlement: number; used: number; remaining: number; sick: number }
  onboarding: boolean; resigned: boolean
  attendance: DayRow[]; leaves: LeaveRow[]; services: ServiceRow[]; assets: AssetRow[]; documents: EmpDoc[]; logs: LogRow[]
  salary: { amount: number; currency: 'USD' | 'SYP' } | null
  can: { salary: boolean; edit: boolean; sensitive: boolean; onboarding: boolean }
}
export interface ProfileInput {
  name: string; name_en: string; gender: 'M' | 'F'; title_ar: string; title_en: string; department_id: number; location_id: number; manager_id: number | null
  email: string; phone: string; national_id: string; dob: string
}
export interface DocHistory { action: 'upload' | 'view' | 'download' | 'archive' | 'restore' | 'update'; by: Bi | null; at: string }
export interface DocSettings { types: { key: string; ar: string; en: string }[]; extensions: string[]; max_mb: number; alert_days: number; sensitive_roles: string[] }
export interface OrgNode { id: number; code: string; name: Bi; title: Bi; dept: string; manager_id: number | null; open: boolean }
export interface OrgData { data: OrgNode[]; depts: (Bi & { code: string; color: string })[] }

export interface DirFilters { q: string; dept: string; status: DirStatus }
const qs = (f: Partial<DirFilters>) => new URLSearchParams(Object.entries(f).filter(([, v]) => v) as [string, string][]).toString()

export const useDirectory = (f: DirFilters) => useQuery({ queryKey: ['hr', 'employees', f], queryFn: () => api<Directory>(`/hr/employees?${qs(f)}`), placeholderData: p => p })
export const exportUrl = (f: DirFilters) => `/api/hr/employees/export?${qs(f)}`
export const useEmployee = (id: number) => useQuery({ queryKey: ['hr', 'employee', id], queryFn: () => api<{ data: EmpFile }>(`/hr/employees/${id}`).then(r => r.data) })
export const useOrg = () => useQuery({ queryKey: ['hr', 'org'], queryFn: () => api<OrgData>('/hr/org') })

const settle = (e: EmpFile) => {
  queryClient.setQueryData(['hr', 'employee', e.id], e)
  for (const k of ['employees', 'org', 'employee-lookups', 'onboarding']) queryClient.invalidateQueries({ queryKey: ['hr', k] })
}
export const useUpdateProfile = (id: number) => useMutation({
  mutationFn: (v: ProfileInput) => api<{ data: EmpFile }>(`/hr/employees/${id}`, { method: 'PUT', json: v }).then(r => r.data), onSuccess: settle,
})
export const useLinkCard = (id: number) => useMutation({
  mutationFn: (uid: string | null) => api<{ data: EmpFile }>(`/hr/employees/${id}/card`, { method: 'PUT', json: { uid } }).then(r => r.data), onSuccess: settle,
})

/** آخر بطاقة مُرّرت على جسر/جهاز للشركة بعد since — لقارئ ACS (PC/SC) الذي لا يكتب في المتصفح */
export interface CardRead { uid: string; known: boolean; at: string; device: Bi }
export const lastCardRead = (id: number, since?: string) => api<{ data: CardRead | null; now: string }>(`/hr/employees/${id}/card/last-read${since ? `?since=${encodeURIComponent(since)}` : ''}`)

const refetchFile = (id: number) => () => queryClient.invalidateQueries({ queryKey: ['hr', 'employee', id] })
export const useUploadDoc = (id: number) => useMutation({
  mutationFn: (v: { file: File; type: string; title: string; expires_at: string; sensitive: boolean; note: string }) => {
    const fd = new FormData(); fd.append('file', v.file); fd.append('type', v.type)
    if (v.title) fd.append('title', v.title)
    if (v.expires_at) fd.append('expires_at', v.expires_at)
    if (v.note) fd.append('note', v.note)
    fd.append('sensitive', v.sensitive ? '1' : '0')
    return api<{ data: EmpDoc }>(`/hr/employees/${id}/documents`, { method: 'POST', body: fd }).then(r => r.data)
  },
  onSuccess: refetchFile(id),
})
export const useArchiveDoc = (id: number) => useMutation({
  mutationFn: (v: { doc: number; restore?: boolean }) => api<{ data: EmpDoc }>(`/hr/employees/${id}/documents/${v.doc}/archive`, { json: { restore: !!v.restore } }).then(r => r.data),
  onSuccess: refetchFile(id),
})
export const useDocHistory = (id: number, doc: number | null) => useQuery({
  queryKey: ['hr', 'employee', id, 'doc', doc], queryFn: () => api<{ data: DocHistory[] }>(`/hr/employees/${id}/documents/${doc}/log`).then(r => r.data), enabled: !!doc, staleTime: 0,
})
export const useDocSettings = () => useQuery({
  queryKey: ['hr', 'doc-settings'], queryFn: () => api<{ data: DocSettings; defaults: DocSettings; roles: (Bi & { key: string })[] }>('/hr/employee-docs/settings'), staleTime: 60_000,
})
export const useSaveDocSettings = () => useMutation({
  mutationFn: (v: DocSettings) => api<{ data: DocSettings; defaults: DocSettings; roles: (Bi & { key: string })[] }>('/hr/employee-docs/settings', { method: 'PUT', json: v }),
  onSuccess: d => queryClient.setQueryData(['hr', 'doc-settings'], d),
})

/** مدة الخدمة كما في الديمو: «لم يباشر» / N يوماً / N.N سنة */
export function tenureLabel(days: number, L: (a: string, b: string) => string) {
  if (days < 0) return L('لم يباشر', 'Not started')
  return days > 365 ? `${(days / 365).toFixed(1)} ${L('سنة', 'yrs')}` : `${days} ${L('يوماً', 'days')}`
}

/** دقائق ← «2 س 15 د» (minsLabel في الديمو) */
export function minsLabel(m: number, lang: 'ar' | 'en') {
  const h = Math.floor(m / 60), mm = m % 60
  if (lang === 'ar') return h ? `${h} س ${mm ? mm + ' د' : ''}`.trim() : `${mm} د`
  return h ? `${h}h ${mm ? mm + 'm' : ''}`.trim() : `${mm}m`
}

/** مسار الحياة بتسع خطوات (lifecycleOf في الديمو) */
export function lifecycleOf(e: Pick<EmpFile, 'status' | 'onboarding' | 'resigned'>, L: (a: string, b: string) => string) {
  const ob = e.onboarding, term = e.status === 'terminated'
  return [
    { label: L('طلب التوظيف', 'Application'), code: 'F-28', done: true },
    { label: L('المقابلة', 'Interview'), code: 'F-25', done: true },
    { label: L('عرض العمل', 'Offer'), code: 'F-29', done: true },
    { label: L('قرار التعيين', 'Appointment'), code: 'F-31', done: true },
    { label: L('المباشرة', 'Onboarding'), code: 'F-19', done: !ob || e.status !== 'onboarding', current: e.status === 'onboarding' },
    { label: L('فترة التجربة', 'Probation'), done: !ob || ['active', 'resigning', 'terminated'].includes(e.status), current: e.status === 'probation' },
    { label: L('على رأس العمل', 'Active'), done: e.resigned || term, current: e.status === 'active' },
    { label: L('الاستقالة', 'Resignation'), code: 'F-26', done: term, current: e.status === 'resigning' },
    { label: L('إنهاء الخدمة', 'Exit'), code: 'F-33', done: term },
  ]
}

/** لون الإدارة (عمود departments.color) ← صنف الشريط في الهيكل والدليل */
export const deptTone = (c: string) => ({ brand: 'bg-brand', info: 'bg-info', brass: 'bg-brass', danger: 'bg-danger', warn: 'bg-warn', 'ink-3': 'bg-ink-3' } as Record<string, string>)[c] ?? 'bg-brand'
