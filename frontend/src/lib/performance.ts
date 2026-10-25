import { useMutation, useQuery } from '@tanstack/react-query'
import { api, xsrf } from './api'
import { queryClient } from './session'
import type { Bi } from './types'

/** M13 — الأداء وتقييم 360 (دورات، نماذج، 9-Box، اعتماد، مكافآت D-28) والتحليلات */
export type Role = 'self' | 'manager' | 'peer'
export interface Cycle { id: number; name: Bi; name_ar: string; name_en: string; starts_on: string; ends_on: string; status: 'draft' | 'open' | 'closed'; department_id: number | null; department: Bi | null; weights: Record<string, number>; opened_at: string | null; approved_at: string | null }
export interface CompRating { self: number | null; manager: number | null; peers: number | null; total: number | null }
export interface ReviewRow {
  id: number; employee: { id: number; code: string; name: Bi; title: Bi; dept: (Bi & { id: number }) | null }
  performance: number | null; potential: number | null; score: number | null; calibrated: boolean; status: 'open' | 'approved'; progress: { done: number; total: number }
}
export interface ReviewForm { id: number; role: Role; status: 'pending' | 'submitted'; submitted_at: string | null; rater: { id: number; name: Bi } | null; has_user: boolean; comment: string | null; potential: number | null }
export interface Award { id: number; review_id: number; employee: { id: number; code: string; name: Bi } | null; score: number; band: Band | null; kind: Band['kind'] | null; amount: number; currency: 'USD' | 'SYP'; points: number; status: 'review' | 'calculated' | 'approved' | 'paid' | 'rejected'; month: string | null; note: string | null }
export interface ReviewDetail extends ReviewRow { ratings: Record<string, CompRating>; calibration_note: string | null; forms: ReviewForm[]; award: Award | null }
export interface Band { from: number; to: number; kind: 'percent' | 'amount' | 'points'; value: number }
export interface PerfConfig { weights: { manager: number; peers: number; self: number }; scale: number; peers: number; high_from: number; mid_from: number; rewards: Band[] }
export interface PerfPage {
  cycles: Cycle[]; cycle: Cycle | null; reviews: ReviewRow[]; awards: Award[]; competencies: (Bi & { id: number })[]; config: PerfConfig
  departments: (Bi & { id: number })[]; employees: { id: number; code: string; ar: string; en: string; dept: number | null }[]; reject_min: number
  can: { create: boolean; edit: boolean; decide: boolean; export: boolean; settings: boolean }
}
export interface CompetencyRow { id?: number; name_ar: string; name_en: string; active: boolean }

const refresh = () => { queryClient.invalidateQueries({ queryKey: ['hr', 'performance'] }); queryClient.invalidateQueries({ queryKey: ['me', 'reviews'] }) }
export const usePerformance = (cycle: number | null) => useQuery({ queryKey: ['hr', 'performance', cycle], queryFn: () => api<PerfPage>(`/hr/performance${cycle ? `?cycle=${cycle}` : ''}`), placeholderData: p => p })
export const useReviewDetail = (id: number | null) => useQuery({ queryKey: ['hr', 'performance', 'review', id], enabled: !!id, queryFn: () => api<{ data: ReviewDetail }>(`/hr/performance/reviews/${id}`).then(r => r.data) })
export const useSaveCycle = (id: number | null) => useMutation({
  mutationFn: (v: { name_ar: string; name_en: string; starts_on: string; ends_on: string; department_id: number | '' }) => api<{ data: Cycle }>(id ? `/hr/performance/cycles/${id}` : '/hr/performance/cycles', { method: id ? 'PUT' : 'POST', json: { ...v, department_id: v.department_id || null } }).then(r => r.data),
  onSuccess: refresh,
})
export const useCycleAction = () => useMutation({ mutationFn: (v: { id: number; action: 'open' | 'remind' | 'approve' }) => api<Record<string, unknown>>(`/hr/performance/cycles/${v.id}/${v.action}`, { method: 'POST' }), onSuccess: refresh })
export const usePlace = () => useMutation({ mutationFn: (v: { id: number; performance: number; potential: number; note?: string }) => api<{ data: ReviewRow }>(`/hr/performance/reviews/${v.id}/place`, { json: v }), onSuccess: refresh })
export const usePeer = () => useMutation({
  mutationFn: (v: { id: number; add?: number; remove?: number }) => v.add ? api(`/hr/performance/reviews/${v.id}/peers`, { json: { employee_id: v.add } }) : api(`/hr/performance/reviews/${v.id}/peers/${v.remove}`, { method: 'DELETE' }),
  onSuccess: refresh,
})
export const useDecideAward = () => useMutation({ mutationFn: (v: { id: number; approve: boolean; note?: string; kind?: 'amount' | 'points'; value?: number }) => api<{ data: Award }>(`/hr/performance/awards/${v.id}`, { json: v }), onSuccess: refresh })
export async function downloadPerformance(id: number) {
  const r = await fetch(`/api/hr/performance/cycles/${id}/export`, { credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': xsrf() } })
  if (!r.ok) throw new Error(String(r.status))
  const a = document.createElement('a'); a.href = URL.createObjectURL(await r.blob()); a.download = `UHR-performance-${id}.xlsx`; a.click(); URL.revokeObjectURL(a.href)
}

export const usePerfSettings = () => useQuery({ queryKey: ['hr', 'performance', 'settings'], queryFn: () => api<{ config: PerfConfig; defaults: PerfConfig; competencies: CompetencyRow[] }>('/hr/performance/settings') })
export const useSavePerfSettings = () => useMutation({ mutationFn: (v: { config: PerfConfig; competencies: CompetencyRow[] }) => api<{ recomputed: number }>('/hr/performance/settings', { method: 'PUT', json: v }), onSuccess: refresh })
export const previewPerf = (v: { config: PerfConfig }) => api<{ data: { id: number; name: Bi; current: { score: number | null; p: number | null }; new: { score: number | null; p: number | null }; calibrated: boolean }[] }>('/hr/performance/settings/preview', { json: v })

// ── الموظف ──
export interface MyForm { id: number; role: Role; status: 'pending' | 'submitted'; ratings: Record<string, number>; potential: number | null; comment: string | null; cycle: { id: number; name: Bi; ends_on: string }; employee: { id: number; code: string; name: Bi; title: Bi; dept: Bi | null } }
export interface MyResult { id: number; cycle: Bi; score: number | null; performance: number | null; potential: number | null; ratings: Record<string, CompRating>; approved_at: string | null; award: { kind: string | null; amount: number; currency: 'USD' | 'SYP'; points: number; status: string; month: string | null } | null }
export interface MyReviews { forms: MyForm[]; results: MyResult[]; cycles: { open: (Bi & { ends_on: string })[]; draft: number }; competencies: (Bi & { id: number })[]; scale: number }
export const useMyReviews = () => useQuery({ queryKey: ['me', 'reviews'], queryFn: () => api<MyReviews>('/me/reviews') })
export const useFillReview = () => useMutation({
  mutationFn: (v: { id: number; ratings: Record<string, number>; potential?: number | null; comment?: string; submit: boolean }) => api<MyReviews>(`/me/reviews/${v.id}`, { json: v }),
  onSuccess: d => { queryClient.setQueryData(['me', 'reviews'], d); queryClient.invalidateQueries({ queryKey: ['hr', 'performance'] }) },
})

// ── التحليلات ──
export interface Analytics {
  headcount: { total: number; by_dept: (Bi & { id: number; n: number })[] }; tenure: { from: number; to: number; n: number }[]; pay: (Bi & { id: number; v: number })[] | null; currency: 'USD' | 'SYP'
  heat: { days: string[]; rows: (Bi & { id: number; cells: { day: string; staff: number; present: number; rate: number | null; late: number }[] })[] }
  funnel: (Bi & { stage: string; n: number })[]; rejected: number; leaves: (Bi & { key: string; n: number })[]
  performance: { cycle: Bi & { status: string }; grid: Record<string, number> } | null
}
export const useAnalytics = () => useQuery({ queryKey: ['hr', 'analytics'], queryFn: () => api<Analytics>('/hr/analytics') })
export async function downloadAnalytics() {
  const r = await fetch('/api/hr/analytics/export', { credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': xsrf() } })
  if (!r.ok) throw new Error(String(r.status))
  const a = document.createElement('a'); a.href = URL.createObjectURL(await r.blob()); a.download = 'UHR-analytics.xlsx'; a.click(); URL.revokeObjectURL(a.href)
}

/** مربعات 9-Box (SRS M13): المفتاح = الأداء ثم الإمكانات */
export const BOXES: Record<string, [string, string]> = { '33': ['نجوم المستقبل', 'Future stars'], '23': ['مواهب صاعدة', 'Rising talent'], '13': ['جوهرة غير مصقولة', 'Rough diamond'], '32': ['ركائز أساسية', 'Core pillars'], '22': ['أداء ثابت', 'Solid performers'], '12': ['بحاجة لتوجيه', 'Needs direction'], '31': ['خبراء متمرسون', 'Trusted experts'], '21': ['أداء مقبول', 'Effective'], '11': ['خطة تحسين', 'Improvement plan'] }
