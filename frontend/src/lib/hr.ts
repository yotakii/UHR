import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient, type CompanyCard } from './session'
import type { Bi } from './types'
import type { Stage } from './recruitment'

/** الوظائف الشاغرة في نظام الشركة (HR ومسؤول التوظيف) — SRS §10.15 */
export type Visibility = 'internal' | 'company' | 'public'
export type PortalStatus = 'none' | 'pending' | 'approved' | 'rejected'
export interface HrJob {
  id: number; code: string; title: Bi; dept: Bi & { id: number }; city: Bi & { id: number }; type: Bi | null
  openings: number; min_years: number; degree: number; salary_min: number; salary_max: number; currency: 'USD' | 'SYP'
  status: 'draft' | 'open' | 'closed'; visibility: Visibility; portal_status: PortalStatus; portal_note: string | null
  posted_at: string | null; closes_at: string | null; hard_to_fill: boolean; requires_photo: boolean; accepting: boolean
  pipeline?: Record<Stage, number>
}
export interface HrJobFull extends HrJob { title_ar: string; title_en: string; summary_ar: string; summary_en: string; requirements: Bi[]; department_id: number; location_id: number; employment_type: string }
export interface JobInput {
  title_ar: string; title_en: string; department_id: number | ''; location_id: number | ''; employment_type: string; openings: number; min_years: number; degree: number
  salary_min: number | ''; salary_max: number | ''; currency: 'USD' | 'SYP'; summary_ar: string; summary_en: string; requirements: Bi[]
  visibility: Visibility; closes_at: string; hard_to_fill: boolean; requires_photo: boolean; status: 'draft' | 'open' | 'closed'
}
export interface Lookups { departments: (Bi & { id: number })[]; locations: (Bi & { id: number })[]; types: (Bi & { code: string })[]; degrees: Bi[] }
export type HrCompany = CompanyCard & { portal_enabled: boolean; portal_review: boolean }

export const useHrJobs = () => useQuery({ queryKey: ['hr', 'jobs'], queryFn: () => api<{ company: HrCompany; data: HrJob[] }>('/hr/jobs') })
export const useHrJob = (id: number | null) => useQuery({ queryKey: ['hr', 'job', id], queryFn: () => api<{ data: HrJobFull }>(`/hr/jobs/${id}`).then(r => r.data), enabled: !!id })
export const useLookups = () => useQuery({ queryKey: ['hr', 'lookups'], queryFn: () => api<Lookups>('/hr/jobs/lookups'), staleTime: 5 * 60_000 })

const refresh = () => { queryClient.invalidateQueries({ queryKey: ['hr'] }); queryClient.invalidateQueries({ queryKey: ['public'] }) }

export function useSaveJob(id: number | null) {
  return useMutation({
    mutationFn: (v: JobInput) => api<{ data: HrJobFull }>(id ? `/hr/jobs/${id}` : '/hr/jobs', { method: id ? 'PUT' : 'POST', json: { ...v, closes_at: v.closes_at || null } }).then(r => r.data),
    onSuccess: refresh,
  })
}
export function useJobStatus() {
  return useMutation({ mutationFn: (v: { id: number; status: 'open' | 'closed' }) => api(`/hr/jobs/${v.id}/status`, { json: { status: v.status } }), onSuccess: refresh })
}
