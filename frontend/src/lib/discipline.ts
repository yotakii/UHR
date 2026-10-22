import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'
import type { Step } from './requests'

/** M17 — المخالفات والجزاءات (SRS §10.7): الحالات، الدليل، الاقتراح الآلي من الحضور، الشكاوى F-02 */
export type PenaltyKind = 'note' | 'warning' | 'deduct' | 'promotion_delay' | 'promotion_deny' | 'dismissal' | 'fine' | 'absent'
export interface Penalty { kind: PenaltyKind; days?: number; label: Bi }
export type CaseStatus = 'open' | 'investigated' | 'pending_gm' | 'decided' | 'dismissed' | 'expired'
export interface ViolationType { id: number; group: 'time' | 'work' | 'conduct'; number: number; title: Bi; article: string | null; auto: string | null; active: boolean; penalties: Penalty[]; used?: number }
export interface Person { id: number; code: string; name: Bi; title?: Bi; dept?: Bi | null }
export interface Investigation { statement?: string; witnesses?: string | null; notes?: string | null; union_notified?: boolean; at: string | null; by?: string; by_en?: string }
export interface CaseRow {
  id: number; ref_no: string; employee: Person | null; type: ViolationType | null; occurred_on: string; discovered_on: string; deadline: string; days_left: number | null
  source: 'manual' | 'attendance'; description: string; status: CaseStatus; occurrence: number; suggested: Penalty | null; penalty: Penalty | null
  deduct_days: number; capped: boolean; month: string | null; reduce_reason: string | null; has_defense: boolean; acknowledged_at: string | null; decided_at: string | null
  opened_by: Bi | null; created_at: string
  defense?: string | null; defended_at?: string | null; investigation?: Investigation | null; close_reason?: string | null; decided_by?: Bi | null; signature?: string | null
  approval?: { status: string; penalty: Penalty; steps: Step[] } | null
}
export interface Opt { key: string; ar: string; en: string }
export interface DisciplinePolicy { cap_days: number; deadline_days: number; repeat_months: number; hr_max_days: number; suggest_late: number; suggest_absent: number; suggest_early: number }
export interface DisciplinePage {
  data: CaseRow[]; counts: { open: number; due_soon: number; month: number; complaints: number | null }
  employees: (Bi & { id: number; code: string })[]; types: ViolationType[]; groups: Opt[]; kinds: Opt[]; policy: DisciplinePolicy; reject_min: number
  can: { create: boolean; decide: boolean; edit: boolean; complaints: boolean; print: boolean; catalog: boolean }
}
export interface Suggestion { employee: Person; type: ViolationType; dates: string[]; count: number; summary: Bi; occurred_on: string; occurrence: number; suggested: Penalty }
export interface ComplaintRow { id: number; ref_no: string; subject: string; body: string; against: string | null; status: 'received' | 'reviewing' | 'closed'; response: string | null; responded_by: Bi | null; responded_at: string | null; created_at: string; employee?: Person }
export interface MyDiscipline { data: CaseRow[]; complaints: ComplaintRow[]; team: (Bi & { id: number; code: string })[]; team_cases: CaseRow[]; types: ViolationType[]; company: Bi; me: { name: Bi; title: Bi; code: string; dept: Bi | null } }

const refresh = () => { queryClient.invalidateQueries({ queryKey: ['hr', 'discipline'] }); queryClient.invalidateQueries({ queryKey: ['me', 'discipline'] }); queryClient.invalidateQueries({ queryKey: ['me', 'approvals'] }) }
const qs = (f: Record<string, string>) => new URLSearchParams(Object.entries(f).filter(([, v]) => v)).toString()

export const useDiscipline = (f: { tab: string; q: string }) => useQuery({ queryKey: ['hr', 'discipline', 'list', f], queryFn: () => api<DisciplinePage>(`/hr/discipline?${qs(f)}`), placeholderData: p => p })
export const useCase = (id: number | null) => useQuery({ queryKey: ['hr', 'discipline', 'case', id], enabled: !!id, queryFn: () => api<{ data: CaseRow }>(`/hr/discipline/${id}`).then(r => r.data) })
export const useSuggestions = (month: string, on: boolean) => useQuery({ queryKey: ['hr', 'discipline', 'suggestions', month], enabled: on, queryFn: () => api<{ data: Suggestion[]; month: string }>(`/hr/discipline/suggestions?month=${month}`) })
export const useComplaints = (status: string, on: boolean) => useQuery({ queryKey: ['hr', 'discipline', 'complaints', status], enabled: on, queryFn: () => api<{ data: ComplaintRow[] }>(`/hr/discipline/complaints?status=${status}`).then(r => r.data) })
export const useCatalog = () => useQuery({ queryKey: ['hr', 'discipline', 'catalog'], queryFn: () => api<{ data: ViolationType[]; groups: Opt[]; kinds: Opt[]; autos: string[] }>('/hr/discipline/catalog') })
export const useWarning = (id: number, on: boolean) => useQuery({ queryKey: ['hr', 'discipline', 'warning', id], enabled: on, queryFn: () => api<{ data: CaseRow & { company: Bi } }>(`/hr/discipline/${id}/warning`).then(r => r.data) })

export interface CaseInput { employee_id: number; violation_type_id: number; occurred_on: string; description: string; source?: 'manual' | 'attendance' }
export const useOpenCase = () => useMutation({ mutationFn: (v: CaseInput) => api<{ data: CaseRow }>('/hr/discipline', { json: v }).then(r => r.data), onSuccess: refresh })
export const useCaseAction = () => useMutation({
  mutationFn: (v: { id: number; action: 'investigate' | 'decide' | 'dismiss'; body: Record<string, unknown> }) => api<{ data: CaseRow }>(`/hr/discipline/${v.id}/${v.action}`, { json: v.body }).then(r => r.data), onSuccess: refresh,
})
export const useComplaintAction = () => useMutation({
  mutationFn: (v: { id: number; action: 'review' | 'close'; response?: string }) => api<{ data: ComplaintRow }>(`/hr/discipline/complaints/${v.id}`, { json: { action: v.action, response: v.response } }).then(r => r.data), onSuccess: refresh,
})
export interface TypeInput { group: string; title_ar: string; title_en: string; article: string; auto: string; active: boolean; penalties: { kind: PenaltyKind; days?: number | '' }[] }
export const useSaveType = (id: number | null) => useMutation({
  mutationFn: (v: TypeInput) => api<{ data: ViolationType }>(id ? `/hr/discipline/catalog/${id}` : '/hr/discipline/catalog', { method: id ? 'PUT' : 'POST', json: { ...v, auto: v.auto || null, article: v.article || null } }).then(r => r.data), onSuccess: refresh,
})
export const useResetCatalog = () => useMutation({ mutationFn: () => api('/hr/discipline/catalog/reset', { method: 'POST' }), onSuccess: refresh })

// بوابة الموظف
export const useMyDiscipline = () => useQuery({ queryKey: ['me', 'discipline'], queryFn: () => api<MyDiscipline>('/me/discipline') })
const meDone = (r: MyDiscipline) => { queryClient.setQueryData(['me', 'discipline'], r); queryClient.invalidateQueries({ queryKey: ['hr', 'discipline'] }) }
export const useDefend = () => useMutation({ mutationFn: (v: { id: number; defense: string }) => api<MyDiscipline>(`/me/discipline/${v.id}/defense`, { json: { defense: v.defense } }), onSuccess: meDone })
export const useAcknowledge = () => useMutation({ mutationFn: (v: { id: number; signature: string }) => api<MyDiscipline>(`/me/discipline/${v.id}/acknowledge`, { json: { signature: v.signature } }), onSuccess: meDone })
export const useTeamCase = () => useMutation({ mutationFn: (v: CaseInput) => api<MyDiscipline>('/me/discipline', { json: v }), onSuccess: meDone })
export const useComplain = () => useMutation({ mutationFn: (v: { subject: string; body: string; against: string }) => api<MyDiscipline>('/me/complaints', { json: v }), onSuccess: meDone })

/** ترتيب الشدة (مطابق للخادم) — لعرض الخيارات المسموحة عند القرار */
export function rank(p: { kind: string; days?: number }): number {
  switch (p.kind) {
    case 'note': return 1
    case 'warning': return 2
    case 'absent': case 'fine': return 2.5
    case 'deduct': return 3 + Math.min(10, p.days ?? 0) / 10
    case 'promotion_delay': return 5
    case 'promotion_deny': return 6
    case 'dismissal': return 7
    default: return 0
  }
}
