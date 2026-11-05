import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'

/** المهام ومؤشرات الأداء والنقاط من الأداء (ملاحظات 61/62) */
export type TaskStatus = 'todo' | 'in_progress' | 'review' | 'done' | 'cancelled'
export type Priority = 'low' | 'normal' | 'high' | 'urgent'
export interface Task {
  id: number; title: string; description: string | null; priority: Priority; status: TaskStatus; progress: number; due_on: string | null; overdue: boolean
  employee: { id: number; code: string; name: Bi } | null; by: Bi | null; self: boolean; kpi: (Bi & { id: number }) | null; points: number
  created_at: string | null; completed_at: string | null; close_note: string | null; comments: { id: number; body: string; at: string | null; by: Bi | null }[] | null; comments_count: number | null
  can: { manage: boolean; progress: boolean; decide: boolean }
}
export interface Person { id: number; code: string; ar: string; en: string; dept: number | null }
export interface PointsCfg { enabled: boolean; task: Record<Priority, number>; late_factor: number; kpi_met: number; kpi_exceed: number; exceed_pct: number; review: { high: number; mid: number; low: number } }
export interface TasksPage {
  data: Task[]; counts: { mine: number; team_review: number; overdue: number }; people: Person[]; kpis: (Bi & { id: number })[]; points: PointsCfg; me: number | null
  can: { team: boolean; assign: boolean; self: boolean; settings: boolean }
}
export type TaskView = 'mine' | 'team' | 'created'
export type TaskFilter = 'open' | 'review' | 'done' | 'cancelled' | 'all'
export const useTasks = (view: TaskView, status: TaskFilter, q: string) => useQuery({ queryKey: ['work', 'tasks', view, status, q], placeholderData: p => p,
  queryFn: () => api<TasksPage>(`/work/tasks?view=${view}&status=${status}&q=${encodeURIComponent(q)}`) })
export const useTask = (id: number | null) => useQuery({ queryKey: ['work', 'task', id], enabled: !!id, queryFn: () => api<{ data: Task }>(`/work/tasks/${id}`).then(r => r.data) })
const refresh = () => { queryClient.invalidateQueries({ queryKey: ['work'] }); queryClient.invalidateQueries({ queryKey: ['me', 'rewards'] }) }
export interface TaskInput { title: string; description: string; employee_id: number; priority: Priority; due_on: string; kpi_id: number | null }
export const useSaveTask = (id: number | null) => useMutation({ mutationFn: (v: TaskInput) => api<{ data: Task }>(id ? `/work/tasks/${id}` : '/work/tasks', { method: id ? 'PUT' : 'POST', json: { ...v, due_on: v.due_on || null } }), onSuccess: refresh })
export const useTaskProgress = (id: number) => useMutation({ mutationFn: (v: { status: TaskStatus; progress?: number; note?: string }) => api<{ data: Task }>(`/work/tasks/${id}/progress`, { json: v }), onSuccess: refresh })
export const useTaskDecide = (id: number) => useMutation({ mutationFn: (v: { approve: boolean; note?: string }) => api<{ data: Task }>(`/work/tasks/${id}/decide`, { json: v }), onSuccess: refresh })
export const useTaskCancel = (id: number) => useMutation({ mutationFn: (note: string) => api<{ data: Task }>(`/work/tasks/${id}/cancel`, { json: { note } }), onSuccess: refresh })
export const useTaskComment = (id: number) => useMutation({ mutationFn: (body: string) => api<{ data: Task }>(`/work/tasks/${id}/comments`, { json: { body } }), onSuccess: refresh })

export type KpiScope = 'employee' | 'department' | 'company'
export interface Kpi {
  id: number; scope: KpiScope; name: Bi; name_ar: string; name_en: string | null; unit: string; target: number; direction: 'higher' | 'lower'; period: 'month' | 'quarter' | 'year'; weight: number; note: string | null; active: boolean
  employee: { id: number; code: string; name: Bi } | null; department: (Bi & { id: number }) | null; current_period: string
  latest: { period: string; actual: number; achievement: number } | null; entries: { period: string; actual: number; achievement: number; note: string | null }[]
  can: { manage: boolean }
}
export interface KpisPage { data: Kpi[]; people: Person[]; departments: (Bi & { id: number })[]; units: string[]; periods: string[]; can: { employee: boolean; department: boolean; company: boolean } }
export const useKpis = () => useQuery({ queryKey: ['work', 'kpis'], queryFn: () => api<KpisPage>('/work/kpis') })
export interface KpiInput { scope: KpiScope; employee_id: number | null; department_id: number | null; name_ar: string; name_en: string; unit: string; target: number; direction: 'higher' | 'lower'; period: string; weight: number; note: string; active: boolean }
export const useSaveKpi = (id: number | null) => useMutation({ mutationFn: (v: KpiInput) => api<{ data: Kpi }>(id ? `/work/kpis/${id}` : '/work/kpis', { method: id ? 'PUT' : 'POST', json: v }), onSuccess: refresh })
export const useRecordKpi = (id: number) => useMutation({ mutationFn: (v: { period: string; actual: number; note?: string }) => api<{ data: Kpi }>(`/work/kpis/${id}/entries`, { json: v }), onSuccess: refresh })
export const usePointsSettings = (enabled: boolean) => useQuery({ queryKey: ['work', 'points'], enabled, queryFn: () => api<{ data: PointsCfg; defaults: PointsCfg }>('/work/points') })
export const useSavePoints = () => useMutation({ mutationFn: (v: PointsCfg) => api<{ data: PointsCfg }>('/work/points', { method: 'PUT', json: v }), onSuccess: refresh })

/** لون نسبة التحقيق */
export const achTone = (a: number) => a >= 100 ? 'text-brand' : a >= 80 ? 'text-brass' : 'text-danger'
