import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'
import type { LookupItem } from './codes'

// الدفعة 20 — المهمات والسفر والمكافآت وبنود الراتب (SRS الأنظمة الأساسية، الجزء B)

export type Person = Bi & { id: number; code?: string }
export type Travel = { scope: 'local' | 'foreign'; country: string; governorate: string | null; city: string; purpose: string; depart_on: string; return_on: string; transport: 'road' | 'air' | 'car'; km: number; legs: string[]; reclassified?: string; place?: Bi | null }
export type EstLine = { code: string; qty?: number; rate?: number; amount: number; currency: string; limit?: number | boolean | null; claimed?: number; over?: boolean; expense?: number }
export type Estimate = { lines?: EstLine[]; totals?: Record<string, number>; warnings: (Bi & { key: string })[]; days: number; zone?: string; same_gov?: boolean; grade?: string; ticket?: string; masked?: boolean }
export interface MissionRow {
  id: number; ref_no: string; title: string; status: string; progress: number; priority: string; type: (Bi & { code: string; travel: boolean; financial: boolean }) | null; category: Bi | null
  assignee_type: string; parent_id: number | null; employee: Person | null; owner: Person | null; starts_on: string | null; due_on: string | null; close_due: string | null; late: boolean
  travel: Travel | null; advance: { amount?: number; currency?: string; status: string; voucher_no?: string; masked?: boolean } | null; settlement_status: string | null; masked: boolean
}
export type Expense = { code: 'E-121' | 'E-130'; amount: number; currency: string; nights: number; file_id: number; note: string; approved: number | null; over: boolean }
export interface MissionFull extends MissionRow {
  description: string | null; stages: { name: string; en: string; weight: number; done_at: string | null }[] | null; estimate: Estimate | null
  settlement: { report?: string; actual?: { depart_on: string; return_on: string; km: number }; expenses?: Expense[]; status?: string; audit?: Record<string, { by: string; at: string; note: string | null }>; external?: boolean; masked?: boolean } | null
  entitlements: { lines: EstLine[]; advance: number; advance_currency: string | null; warnings: Estimate['warnings'] } | null
  attachments: number[]; rating: number | null; return_note: string | null; log: { what: string; by: string | null; at: string; note: string | null }[]
  comments: { id: number; text: string; files: number[]; by: string | null; at: string }[]; children: { id: number; ref_no: string; status: string; progress: number; employee: Bi | null }[]
  lines: PayLineRow[]; steps: { role: Bi; status: string; by?: Bi | null; at?: string | null; note?: string | null }[]; step_id: number | null
  can: { edit: boolean; act: boolean; progress: boolean; review: boolean; settle: boolean; audit: boolean; pay_advance: boolean; cancel: boolean; amend: boolean }
}
export type PayLineRow = { id: number; code: string; ar: string; en: string; kind: 'earning' | 'deduction'; period: string; status: string; channel: string; ref: string | null; source_type: string; source_id: number | null
  reversal_of: number | null; note: string | null; amount: number | null; currency: string; fx_rate: number | null; amount_pay: number | null; pay_currency: string; masked: boolean; employee?: Person }
export type BonusRow = { id: number; ref_no: string; bonus_type: Bi & { code: string }; employee: { id: number; code: string; name: Bi } | null; status: string; reason: string; amount: number | null; currency: string; masked: boolean
  basis: Record<string, unknown> | null; created_at: string; steps: MissionFull['steps']; step_id: number | null; can_act: boolean }
type Lookups = { types: LookupItem[]; categories: LookupItem[]; cost_centers: LookupItem[]; governorates: LookupItem[]; countries: LookupItem[]; bonus_types: LookupItem[]; currencies: LookupItem[]
  departments: (Bi & { id: number })[]; branches: (Bi & { id: number })[]; employees: Person[]; km_price: number | null; stages: { name: string; en: string; weight: number }[]; trainer_levels: string[] }
type ListRes = { data: MissionRow[]; dashboard: { open: number; pending: number; late: number; travelling: number; unsettled: number; settling: number; by_status: Record<string, number> }; lookups: Lookups
  can: { create: boolean; team: boolean; manage: boolean; finance: boolean; bonus: boolean; settings: boolean; reverse: boolean; export: boolean } }

const changed = () => queryClient.invalidateQueries({ queryKey: ['missions'] })
const qs = (o: Record<string, string | undefined>) => { const s = new URLSearchParams(Object.entries(o).filter(([, v]) => v) as [string, string][]).toString(); return s ? `?${s}` : '' }

export const useMissions = (f: Record<string, string | undefined>) => useQuery({ queryKey: ['missions', 'list', f], queryFn: () => api<ListRes>(`/missions${qs(f)}`), placeholderData: p => p })
export const useMission = (id: number | null) => useQuery({ queryKey: ['missions', 'one', id], enabled: !!id, queryFn: () => api<{ data: MissionFull }>(`/missions/${id}`).then(r => r.data) })
export const useSaveMission = () => useMutation({ mutationFn: ({ id, v }: { id?: number; v: Record<string, unknown> }) => api<{ data: MissionFull }>(id ? `/missions/${id}` : '/missions', { method: id ? 'PUT' : 'POST', json: v }).then(r => r.data), onSuccess: changed })
export const useMissionAct = () => useMutation({ mutationFn: ({ id, path, v }: { id: number; path: string; v?: Record<string, unknown> }) => api<{ data: MissionFull }>(`/missions/${id}/${path}`, { json: v ?? {} }).then(r => r.data), onSuccess: changed })
export const uploadMissionFile = (file: File) => { const f = new FormData(); f.append('file', file); return api<{ id: number; name: string }>('/missions/files', { method: 'POST', body: f }) }
export const useBonuses = (status: string) => useQuery({ queryKey: ['missions', 'bonuses', status], queryFn: () => api<{ data: BonusRow[] }>(`/missions/bonuses?status=${status}`).then(r => r.data) })
export const useGiveBonus = () => useMutation({ mutationFn: (v: Record<string, unknown>) => api<{ data: BonusRow[] }>('/missions/bonuses', { json: v }), onSuccess: changed })
export const usePayLines = (f: Record<string, string | undefined>) => useQuery({ queryKey: ['missions', 'lines', f], queryFn: () => api<{ data: PayLineRow[] }>(`/missions/pay-lines${qs(f)}`).then(r => r.data) })
export const useReverseLine = () => useMutation({ mutationFn: ({ id, reason }: { id: number; reason: string }) => api(`/missions/pay-lines/${id}/reverse`, { json: { reason } }), onSuccess: changed })
export const useMissionReports = (from: string, to: string) => useQuery({ queryKey: ['missions', 'reports', from, to], queryFn: () => api<{ by_status: Record<string, number>; by_type: Row[]; by_branch: Row[]; by_employee: Row[]
  travel: { ref_no: string; employee: string; destination: string; days: number | null; estimate: Record<string, number> | null; status: string }[]; unsettled: { ref_no: string; employee: string; amount: number | null; currency: string; days: number }[]
  anomalies: { employee: string; kind: string; n: number }[]; masked: boolean; by_cost_center?: (Row & { cost: Record<string, number> | null })[]; lines: { element_code: string; pay_currency: string; total: number; n: number }[] | null }>(`/missions/reports${qs({ from, to })}`) })
type Row = { key: string; total: number; done: number; late: number }
export const useMissionSettings = () => useQuery({ queryKey: ['missions', 'settings'], queryFn: () => api<{ settings: Record<string, unknown>; defaults: Record<string, unknown>; grades: LookupItem[]; currencies: LookupItem[]; types: LookupItem[] }>('/missions/settings') })
export const useSaveMissionSettings = () => useMutation({ mutationFn: (v: Record<string, unknown>) => api('/missions/settings', { method: 'PUT', json: v }), onSuccess: changed })
export const useMissionPrint = (id: number) => useQuery({ queryKey: ['missions', 'print', id], queryFn: () => api<{ data: MissionFull; employee: { code: string; title: Bi; grade: string | null } | null }>(`/missions/${id}/print`) })

export const STATUS: Record<string, [string, string, 'brand' | 'brass' | 'info' | 'danger' | 'neutral']> = {
  draft: ['مسودة', 'Draft', 'neutral'], pending_manager: ['بانتظار المدير', 'Awaiting manager', 'brass'], pending_hr: ['بانتظار الموارد البشرية', 'Awaiting HR', 'brass'], pending_exec: ['بانتظار الاعتماد', 'Awaiting approval', 'brass'],
  approved: ['معتمدة', 'Approved', 'info'], assigned: ['مسندة', 'Assigned', 'info'], in_progress: ['قيد التنفيذ', 'In progress', 'info'], review: ['بانتظار المراجعة', 'Awaiting review', 'brass'],
  returned: ['معادة', 'Returned', 'danger'], done: ['منجزة', 'Done', 'brand'], settling: ['قيد التسوية', 'Settling', 'brass'], posted: ['مرحّلة للراتب', 'Sent to payroll', 'brand'],
  closed: ['مغلقة', 'Closed', 'neutral'], rejected: ['مرفوضة', 'Rejected', 'danger'], cancelled: ['ملغاة', 'Cancelled', 'neutral'],
}
export const BOARD = ['draft', 'pending', 'assigned', 'in_progress', 'review', 'done', 'settling', 'posted'] as const
export const ELEMENT: Record<string, [string, string]> = { 'E-110': ['بدل سفر يومي محلي', 'Local daily allowance'], 'E-111': ['بدل سفر يومي خارجي', 'Foreign daily allowance'], 'E-120': ['انتقال بالسيارة الشخصية', 'Personal car'],
  'E-121': ['مصاريف مستردة بفواتير', 'Reimbursed expenses'], 'E-130': ['إقامة', 'Accommodation'], 'D-310': ['تسوية سلفة المهمة', 'Advance settlement'] }
