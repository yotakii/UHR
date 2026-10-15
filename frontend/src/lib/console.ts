import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient, type PermAction } from './session'
import type { Bi } from './types'
import type { Stage } from './recruitment'

/** بيانات الإطار ولوحة المتابعة (M15) ولوحات M16 العامة: الصلاحيات، سجل النماذج، الطباعة */

export interface Task { key: string; who: Bi; what: Bi; tag: string | null; at: string; link: string | null; kind: string; overdue: boolean }
export interface Alert { tone: 'danger' | 'warn' | 'info'; text: Bi; link: string }
export interface Dashboard {
  today: string; name: Bi
  people?: { total: number; departments: number; cities: number; mix: { dept: Bi; count: number }[] }
  attendance?: { series: { date: string; present: number; rate: number; late: number }[]; today_is_workday: boolean; present: number; rate: number; late: number; grace: number
    latest: { employee_id: number; name: Bi; city: Bi; at: string; kind: 'in' | 'out' | 'late' }[] }
  tasks: Task[]; alerts: Alert[]
  funnel?: { stage: Stage; count: number }[]
}
export const useDashboard = () => useQuery({ queryKey: ['hr', 'dashboard'], queryFn: () => api<{ data: Dashboard }>('/hr/dashboard').then(r => r.data), refetchInterval: 60_000 })

export interface NoticeRow { id: number; text: Bi; link: string | null; at: string; read: boolean }
export const useNotices = (enabled = true) => useQuery({ queryKey: ['notices'], queryFn: () => api<{ unread: number; data: NoticeRow[] }>('/notices'), refetchInterval: 45_000, enabled })
export function useMarkNoticesRead() {
  return useMutation({ mutationFn: () => api('/notices/read', { json: {} }), onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notices'] }) })
}

export interface SearchResult {
  employees: { id: number; code: string; name: Bi; title: Bi; dept: Bi }[]
  candidates: { id: number; ref: string; name: Bi; stage: Stage; job: Bi }[]
  jobs: { id: number; code: string; title: Bi; status: string }[]
}
export const useSearch = (q: string, enabled: boolean) => useQuery({ queryKey: ['hr', 'search', q], queryFn: () => api<SearchResult>(`/hr/search?q=${encodeURIComponent(q)}`), enabled: enabled && q.trim().length >= 2, staleTime: 15_000 })

/* ---------- الصلاحيات (M16) ---------- */
export type Scope = 'own' | 'team' | 'all'
export interface Cell { actions: PermAction[]; scope: Scope }
export type Matrix = Record<string, Record<string, Cell>>
export interface PermGrid {
  actions: { key: PermAction; letter: string; ar: string; en: string }[]
  scopes: { key: Scope; ar: string; en: string }[]
  modules: { key: string; ar: string; en: string }[]
  roles: { key: string; ar: string; en: string; users: number }[]
  matrix: Matrix
}
export const usePermGrid = () => useQuery({ queryKey: ['hr', 'permissions'], queryFn: () => api<PermGrid>('/hr/permissions') })
export function useSavePerms() {
  return useMutation({
    mutationFn: (matrix: Matrix) => api<{ matrix: Matrix; changed: number }>('/hr/permissions', { method: 'PUT', json: { matrix } }),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['hr', 'permissions'] }); queryClient.invalidateQueries({ queryKey: ['me'] }) },
  })
}

/* ---------- سجل النماذج وقالب الطباعة ---------- */
export interface FormRow { id: number; code: string; ref: string; title: Bi; module: string | null; issue: string; issued_on: string | null; sensitive: boolean }
export interface PrintTemplate { logo: boolean; logo_height: number; align: 'start' | 'center' | 'end'; margin_mm: number }
export const useForms = () => useQuery({ queryKey: ['hr', 'forms'], queryFn: () => api<{ data: FormRow[]; template: PrintTemplate }>('/hr/forms') })
export function useSaveForm() {
  return useMutation({
    mutationFn: (f: { id: number; ref: string; title_ar: string; title_en: string; issue: string; issued_on: string; sensitive: boolean }) => api<{ data: FormRow }>(`/hr/forms/${f.id}`, { method: 'PUT', json: f }),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['hr', 'forms'] }); queryClient.invalidateQueries({ queryKey: ['print'] }) },
  })
}
export function useSaveTemplate() {
  return useMutation({
    mutationFn: (t: PrintTemplate) => api<{ template: PrintTemplate }>('/hr/print-template', { method: 'PUT', json: t }),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['hr', 'forms'] }); queryClient.invalidateQueries({ queryKey: ['print'] }) },
  })
}

export interface PrintMeta {
  company: { name: Bi; dept: Bi; logo: string | null }
  form: { code: string; ref: string; title: Bi | null; issue: string; issued_on: string | null; sensitive: boolean }
  template: PrintTemplate
}
/** رأس وتذييل النموذج — company للمتقدم (الشركة التي قدّم لها) */
export const usePrintMeta = (code: string, company?: string, enabled = true) => useQuery({
  queryKey: ['print', code, company ?? ''], enabled: enabled && !!code, staleTime: 5 * 60_000, retry: false,
  queryFn: () => api<{ data: PrintMeta }>(`/print/${encodeURIComponent(code)}${company ? `?company=${encodeURIComponent(company)}` : ''}`).then(r => r.data),
})
export const logPrint = (code: string, subject?: string, company?: string) =>
  api<{ logged: boolean }>(`/print/${encodeURIComponent(code)}${company ? `?company=${encodeURIComponent(company)}` : ''}`, { json: { subject } }).catch(() => ({ logged: false }))

/** رابط ملف من المستودع (لا روابط عامة — عبر الخادم بصلاحية وتسجيل) */
export const fileUrl = (id: number, inline = false) => `/api/files/${id}${inline ? '?inline=1' : ''}`
