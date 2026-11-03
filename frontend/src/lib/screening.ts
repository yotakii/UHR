import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'
import type { CvFit } from './candidates'

/** فرز السير وقراءتها جماعياً (11f) */
export type ScreenFilters = Partial<Record<'job' | 'dept' | 'major' | 'q' | 'degree' | 'years_min' | 'years_max' | 'city' | 'english' | 'match_min' | 'stage' | 'read' | 'fit' | 'from' | 'to' | 'sort', string>>
export interface ScreenReview { status: 'pending' | 'running' | 'done' | 'failed'; score: number | null; fit: CvFit | null; summary: string | null; strengths: string[]; gaps: string[]; reviewed_at: string | null }
export interface ScreenRow {
  id: number; rank: number; ref: string; name: Bi; stage: string; match: number; degree: number; major: string | null; phase: string | null; years: number; english: string; city: string | null
  last: string | null; job: { id: number; code: string; title: Bi; dept: Bi | null }; cv: boolean; applied_at: string; review: ScreenReview | null
}
export interface Batch { id: number; total: number; done: number; failed: number; left: number; running: boolean; eta_seconds: number; avg_seconds: number; force: boolean; at: string
  counts: { selected: number; queued: number; already: number; running: number; limit: number } }
export interface ScreenRes {
  data: ScreenRow[]; total: number; shown: number; batch: Batch | null
  status: { available: boolean; enabled: boolean; used: number; limit: number; avg_seconds: number; queue: number }
  jobs: (Bi & { id: number; code: string; status: string; dept: number })[]; departments: (Bi & { id: number })[]; cities: (Bi & { code: string })[]; degrees: Bi[]; majors: string[]
  can: { run: boolean; export: boolean }
}
const qs = (f: ScreenFilters) => new URLSearchParams(Object.entries(f).filter(([, v]) => v !== undefined && v !== '') as [string, string][]).toString()
export const useScreening = (f: ScreenFilters, live: boolean) => useQuery({
  queryKey: ['hr', 'screening', f], placeholderData: p => p, refetchInterval: live ? 4000 : false,
  queryFn: () => api<ScreenRes>(`/hr/cv-screening?${qs(f)}`),
})
export const screeningExportUrl = (f: ScreenFilters) => `/api/hr/cv-screening/export?${qs(f)}`
export const useRunScreening = () => useMutation({
  mutationFn: (v: { ids: number[]; force: boolean; filters: ScreenFilters }) => api<{ batch: Batch }>('/hr/cv-screening/run', { json: v }),
  onSuccess: () => queryClient.invalidateQueries({ queryKey: ['hr', 'screening'] }),
})
/** «4 د 30 ث» / «4m 30s» */
export const fmtEta = (s: number, ar: boolean) => {
  const m = Math.floor(s / 60), r = Math.round(s % 60)
  if (s < 60) return ar ? `${Math.max(1, r)} ث` : `${Math.max(1, r)}s`
  if (m >= 60) return ar ? `${Math.floor(m / 60)} س ${m % 60} د` : `${Math.floor(m / 60)}h ${m % 60}m`
  return ar ? `${m} د${r ? ` ${r} ث` : ''}` : `${m}m${r ? ` ${r}s` : ''}`
}
