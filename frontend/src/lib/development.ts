import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'

/** التعاقب (D-23) وخطط التطوير الفردية (D-24) — نفس الواجهة من بوابة الموظف ونظام الشركة */
export type Readiness = 'now' | '1y' | '3y'
export type ActKind = 'course' | 'mentoring' | 'project' | 'reading' | 'rotation' | 'other'
export type ActStatus = 'planned' | 'in_progress' | 'done' | 'cancelled'
export interface Activity { id: number; title: string; kind: ActKind; due_on: string; status: ActStatus; progress: number; note: string | null; overdue: boolean; owner: { id: number; name: Bi } | null; can_update: boolean }
export interface Plan {
  id: number; title: string; gaps: string | null; goals: { text: string; done: boolean }[]; status: 'draft' | 'active' | 'done'; source: string
  employee: { id: number; code: string; name: Bi; title: Bi }; next_review: string | null; review_due: boolean; reviews: { at: string; by: string; by_en: string; note: string; rating: number }[]
  progress: number; activities: Activity[]; can: { edit: boolean }
}
export interface Candidate { id: number; readiness: Readiness; note: string | null; gaps: string | null; employee: { id: number; code: string; name: Bi; title: Bi }; plan: { id: number; status: string; title: string } | null; nominated_by: string | null; mine: boolean }
export interface SPlan {
  id: number; title: Bi; title_ar: string; title_en: string; risk: 'low' | 'medium' | 'high'; reason: string | null; status: 'draft' | 'active' | 'reviewed'
  department: (Bi & { id: number }) | null; department_id: number | null; incumbent: { id: number; name: Bi; title: Bi } | null; incumbent_id: number | null
  competencies: (Bi & { id: number })[]; competency_ids: number[]; next_review: string | null; review_note: string | null; gap: boolean; counts: Record<Readiness, number>; candidates: Candidate[]
}
export interface DevSettings { readiness: (Bi & { key: Readiness })[]; review_months: number; succession_review_months: number }
export interface DevPage {
  plans: Plan[]; owned: { id: number; title: string; kind: ActKind; due_on: string; status: ActStatus; progress: number; overdue: boolean; employee: Bi; plan: string }[]
  succession: SPlan[]; employees: { id: number; code: string; ar: string; en: string; dept: number | null }[]; owners: { id: number; code: string; ar: string; en: string }[]
  competencies: (Bi & { id: number })[]; departments: (Bi & { id: number })[]; settings: DevSettings; kinds: ActKind[]; me: number | null
  can: { create: boolean; edit: boolean; manage: boolean; team: boolean; settings: boolean }
}
export interface PlanInput { employee_id: number | ''; title: string; gaps: string; goals: { text: string; done?: boolean }[]; activities: { id?: number; title: string; kind: ActKind; owner_employee_id: number | ''; due_on: string }[] }
export interface SPlanInput { title_ar: string; title_en: string; department_id: number | ''; incumbent_id: number | ''; risk: SPlan['risk']; reason: string; competencies: number[] }

const refresh = () => queryClient.invalidateQueries({ queryKey: ['development'] })
export const useDevelopment = () => useQuery({ queryKey: ['development'], queryFn: () => api<DevPage>('/development') })
export const useSavePlan = (id: number | null) => useMutation({
  mutationFn: (v: PlanInput) => api<{ data: Plan }>(id ? `/development/plans/${id}` : '/development/plans', { method: id ? 'PUT' : 'POST', json: { ...v, activities: v.activities.map(a => ({ ...a, owner_employee_id: a.owner_employee_id || null })) } }).then(r => r.data),
  onSuccess: refresh,
})
export const usePlanAction = () => useMutation({ mutationFn: (v: { id: number; action: 'activate' | 'review'; body?: Record<string, unknown> }) => api<{ data: Plan }>(`/development/plans/${v.id}/${v.action}`, { json: v.body ?? {} }), onSuccess: refresh })
export const useProgress = () => useMutation({ mutationFn: (v: { id: number; status: ActStatus; progress?: number; note?: string }) => api(`/development/activities/${v.id}`, { json: v }), onSuccess: refresh })
export const useSaveSPlan = (id: number | null) => useMutation({
  mutationFn: (v: SPlanInput) => api<{ data: SPlan }>(id ? `/development/succession/${id}` : '/development/succession', { method: id ? 'PUT' : 'POST', json: { ...v, department_id: v.department_id || null, incumbent_id: v.incumbent_id || null } }).then(r => r.data),
  onSuccess: refresh,
})
export const useSPlanAction = () => useMutation({ mutationFn: (v: { id: number; action: 'activate' | 'review'; note?: string }) => api(`/development/succession/${v.id}/${v.action}`, { json: { note: v.note } }), onSuccess: refresh })
export const useNominate = () => useMutation({ mutationFn: (v: { id: number; employee_id: number; readiness: Readiness; note?: string }) => api(`/development/succession/${v.id}/candidates`, { json: v }), onSuccess: refresh })
export const useAssess = () => useMutation({ mutationFn: (v: { id: number; readiness: Readiness; gaps: string; create_plan: boolean }) => api(`/development/candidates/${v.id}`, { method: 'PUT', json: v }), onSuccess: refresh })
export const useRemoveCandidate = () => useMutation({ mutationFn: (id: number) => api(`/development/candidates/${id}`, { method: 'DELETE' }), onSuccess: refresh })
export const useSaveDevSettings = () => useMutation({ mutationFn: (v: DevSettings) => api('/development/settings', { method: 'PUT', json: v }), onSuccess: refresh })
