import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'

// الدفعة 18 — دليل الترميز (SRS الأنظمة الأساسية، الجزء A)

export type AttrField = { key: string; ar: string; en: string; type: 'bool' | 'number' | 'select' | 'list' | 'text'; options?: string[]; list?: string }
export interface CodeListDef {
  key: string; ar: string; en: string; group: 'org' | 'people' | 'pay' | 'ops' | 'docs' | 'system'; owner_role: string | null; pattern: string | null; example: string | null
  hierarchical: boolean; parent_key: string | null; source: 'departments' | 'locations' | null; system: boolean; required: boolean; active: boolean; attrs_schema: AttrField[]; custom: boolean
}
export interface CodeListSummary extends CodeListDef { total: number; live: number; used: number }
export interface CodeRow {
  id: number; code: string; ar: string; en: string; parent_id: number | null; status: 'draft' | 'active' | 'inactive' | 'replaced'; live: boolean; attrs: Record<string, unknown> | null
  valid_from: string | null; valid_to: string | null; legacy_code: string | null; description: string | null; sort: number; locked: boolean; shared: boolean; bound: boolean; usage: number; replaced_by_id?: number | null
}
export type GoLive = { enabled: boolean; approved: boolean; version: string | null; approved_at: string | null; percent: number; ready: boolean; blocked: boolean
  checks: (Bi & { key: string; count: number; ok: boolean })[]; warnings: (Bi & { key: string })[] }
export type Template = { template: string; start: number; ar: string; en: string; default: string }
export type CodesSettings = { gate: boolean; change_requests: boolean; self_approve: boolean; admin_approves: boolean; approver_roles: string[]; restructure_role: string
  unused_months: number; company_code: string; templates: Record<string, Template> }
export type CodesOverview = {
  lists: CodeListSummary[]; golive: GoLive; version: { id: number; version: string; summary: string; approved_at: string } | null; direct: boolean; pending: number
  settings: CodesSettings; defaults: Omit<CodesSettings, 'templates'> & { templates: Record<string, { template: string; start: number }> }; tokens: string[]; roles: string[]
  sectors: (Bi & { key: string; count: number })[]
  can: { create: boolean; edit: boolean; decide: boolean; export: boolean; print: boolean; approve: boolean; settings: boolean }
}
export type ListDetail = { list: CodeListDef; rows: CodeRow[]; next: string | null; parents: (Bi & { id: number; code: string; live: boolean })[]
  mappings: { old_code: string; new_code: string; operation: string; effective_on: string; moved: Record<string, number> | null }[] }
export type ChangeType = 'add' | 'edit' | 'deactivate' | 'reactivate' | 'merge' | 'move'
export interface ChangeRequest {
  id: number; list: string; list_ar: string; list_en: string; type: ChangeType; item_id: number | null; payload: Record<string, unknown>; reason: string; effective_on: string
  impact: { usage: Record<string, number>; records: number; next: string | null; simulation?: Simulation } | null
  status: 'pending' | 'approved' | 'rejected' | 'applied' | 'cancelled' | 'failed'; note: string | null; version: string | null; label_ar: string; label_en: string
  requested_by: Bi | null; decided_by: Bi | null; created_at: string; decided_at: string | null; applied_at: string | null; can_decide: boolean; can_cancel: boolean
}
export type Simulation = { sources: (Bi & { id: number; code: string })[]; target: Bi & { id: number; code: string }; tables: Record<string, number>; records: number }
export type Version = { id: number; version: string; summary: string; approved_at: string; by: Bi | null; major: boolean }
export type DiffRow = { code: string; change: 'added' | 'removed' | 'changed'; fields?: string[]; from?: SnapItem; to?: SnapItem }
type SnapItem = { code: string; ar: string; en: string; status: string; parent: string | null }
export type Book = { version: string | null; approved_at: string | null; draft: boolean; lists: (CodeListDef & { items: SnapItem[] })[]; templates: Record<string, Template> }
export type LookupItem = Bi & { id: number; code: string; parent_id: number | null; attrs: Record<string, unknown> | null; live: boolean }

const changed = () => queryClient.invalidateQueries({ queryKey: ['codes'] })

export const useCodes = () => useQuery({ queryKey: ['codes', 'overview'], queryFn: () => api<CodesOverview>('/cs') })
export const useCodeList = (key: string | null) => useQuery({ queryKey: ['codes', 'list', key], queryFn: () => api<ListDetail>(`/cs/lists/${key}`), enabled: !!key, placeholderData: p => p?.list.key === key ? p : undefined })
/** واجهة القراءة الموحدة: الرموز الفعالة لقائمة — تستخدمها كل الشاشات بدل القوائم الثابتة */
export const useLookup = (key: string, all = false) => useQuery({ queryKey: ['codes', 'lookup', key, all], queryFn: () => api<{ data: LookupItem[] }>(`/cs/lists/${key}/items${all ? '?all=1' : ''}`).then(r => r.data), staleTime: 60_000 })
export const useSaveItem = () => useMutation({ mutationFn: ({ key, id, v }: { key: string; id?: number; v: Record<string, unknown> }) => id ? api(`/cs/items/${id}`, { method: 'PUT', json: v }) : api(`/cs/lists/${key}/items`, { json: v }), onSuccess: changed })
export const useItemStatus = () => useMutation({ mutationFn: ({ key, id, active }: { key: string; id: number; active: boolean }) => api(`/cs/lists/${key}/items/${id}/status`, { json: { active } }), onSuccess: changed })
export const useSaveList = () => useMutation({ mutationFn: ({ key, v }: { key?: string; v: Record<string, unknown> }) => api<CodeListDef>(key ? `/cs/lists/${key}` : '/cs/lists', { method: key ? 'PUT' : 'POST', json: v }), onSuccess: changed })
export const useRequests = (status: string) => useQuery({ queryKey: ['codes', 'requests', status], queryFn: () => api<{ data: ChangeRequest[] }>(`/cs/requests?status=${status}`).then(r => r.data) })
export const useSubmitRequest = () => useMutation({ mutationFn: (v: { list: string; type: ChangeType; item_id?: number | null; payload?: Record<string, unknown>; reason: string; effective_on?: string }) => api<ChangeRequest>('/cs/requests', { json: v }), onSuccess: changed })
export const useDecideRequest = () => useMutation({ mutationFn: ({ id, approve, note }: { id: number; approve: boolean; note?: string }) => api<ChangeRequest>(`/cs/requests/${id}/decide`, { json: { approve, note } }), onSuccess: changed })
export const useCancelRequest = () => useMutation({ mutationFn: (id: number) => api(`/cs/requests/${id}/cancel`, { json: {} }), onSuccess: changed })
export const simulate = (list: string, item_id: number, target_id: number) => api<Simulation>('/cs/simulate', { json: { list, item_id, target_id } })
export const useApproveBook = () => useMutation({ mutationFn: (note: string) => api<{ version: string }>('/cs/approve', { json: { note } }), onSuccess: changed })
export const useVersions = () => useQuery({ queryKey: ['codes', 'versions'], queryFn: () => api<{ data: Version[] }>('/cs/versions').then(r => r.data) })
export const useCompare = (a: number | null, b: number | null) => useQuery({ queryKey: ['codes', 'compare', a, b], enabled: !!a && !!b && a !== b,
  queryFn: () => api<{ a: string; b: string; lists: (Bi & { key: string; rows: DiffRow[] })[] }>(`/cs/versions/compare?a=${a}&b=${b}`) })
export const useBook = (version?: string) => useQuery({ queryKey: ['codes', 'book', version ?? ''], queryFn: () => api<Book>(`/cs/book${version ? `?version=${version}` : ''}`) })
export const logBookPrint = () => api('/cs/book/print', { json: {} })
export const useReports = () => useQuery({ queryKey: ['codes', 'reports'], queryFn: () => api<{ months: number; unused: (Bi & { list: string; list_ar: string; list_en: string; id: number; code: string })[]
  duplicates: { list: string; list_ar: string; list_en: string; name: string; items: { id: number; code: string; status: string }[] }[] }>('/cs/reports') })
export const previewCode = (tpl: string, template?: string, start?: number) => api<{ code: string }>(`/cs/generate/${tpl}?${new URLSearchParams({ ...(template ? { template } : {}), ...(start !== undefined ? { start: String(start) } : {}) })}`)
export const useSaveCodesSettings = () => useMutation({ mutationFn: (v: Omit<CodesSettings, 'templates'> & { templates: Record<string, { template: string; start: number }> }) => api('/cs/settings', { method: 'PUT', json: v }), onSuccess: changed })
export const useLoadSector = () => useMutation({ mutationFn: (sector: string) => api<{ added: number }>('/cs/sector', { json: { sector } }), onSuccess: changed })
export const importPreview = (key: string, file: File) => { const f = new FormData(); f.append('file', file); return api<{ rows: { row: number; values: Record<string, unknown>; errors: Record<string, string> }[]; ok: number; errors: number }>(`/cs/lists/${key}/import/preview`, { method: 'POST', body: f }) }
export const importCommit = (key: string, file: File) => { const f = new FormData(); f.append('file', file); return api<{ imported: number }>(`/cs/lists/${key}/import`, { method: 'POST', body: f }) }

export const GROUPS: Record<string, [string, string]> = { org: ['الهيكل', 'Organisation'], people: ['الوظائف والعاملون', 'Jobs & people'], pay: ['المالية', 'Finance'], ops: ['المهمات والمكافآت', 'Missions & bonuses'], docs: ['الوثائق', 'Documents'], system: ['قوائم قياسية مشتركة', 'Shared standard lists'] }
export const ROLE_NAMES: Record<string, [string, string]> = { admin: ['مدير النظام', 'System admin'], hr_director: ['مدير الموارد البشرية', 'HR director'], hr_officer: ['مسؤول الموارد البشرية', 'HR officer'],
  finance: ['المالية', 'Finance'], executive: ['المدير التنفيذي', 'Executive'], ceo: ['المدير العام', 'General manager'], manager: ['المدير المباشر', 'Manager'], employee: ['الموظف', 'Employee'], recruiter: ['مسؤول التوظيف', 'Recruiter'] }
export const TYPE_NAMES: Record<ChangeType, [string, string]> = { add: ['إضافة', 'Add'], edit: ['تعديل', 'Edit'], deactivate: ['تعطيل', 'Deactivate'], reactivate: ['إعادة تفعيل', 'Reactivate'], merge: ['دمج', 'Merge'], move: ['نقل تبعية', 'Move'] }
export const OPTION_NAMES: Record<string, [string, string]> = { earning: ['استحقاق', 'Earning'], deduction: ['استقطاع', 'Deduction'], payroll: ['مع الراتب', 'With salary'], cash: ['نقداً من المالية', 'Cash from finance'],
  employee: ['واجهة الموظف', 'Employee portal'], limited: ['واجهة محدودة', 'Limited portal'], expert: ['بوابة الخبير', 'Expert portal'], volunteer: ['بوابة المتطوع', 'Volunteer portal'], trainee: ['بوابة المتدرب', 'Trainee portal'], none: ['بلا واجهة', 'No portal'],
  fixed: ['مبلغ ثابت', 'Fixed amount'], percent: ['نسبة من الراتب', 'Percent of salary'], free: ['مبلغ حر ضمن سقف', 'Free amount within a cap'], hours: ['ساعات × أجر', 'Hours × rate'], formula: ['معادلة مؤشر', 'KPI formula'] }
export const TABLE_NAMES: Record<string, [string, string]> = { employees: ['موظفون', 'employees'], job_openings: ['شواغر', 'job openings'], kpis: ['مؤشرات أداء', 'KPIs'], development_plans: ['خطط تطوير', 'development plans'],
  succession_plans: ['خطط تعاقب', 'succession plans'], announcements: ['إعلانات', 'announcements'], performance_cycles: ['دورات تقييم', 'review cycles'], salary_grants: ['أذونات رواتب', 'salary grants'],
  devices: ['أجهزة حضور', 'devices'], code_items: ['رموز تابعة', 'child codes'], attrs: ['رموز تشير إليه', 'codes referring to it'] }
