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
  posted_at: string | null; closes_at: string | null; hard_to_fill: boolean; requires_photo: boolean; accepting: boolean; test_enabled: boolean
  pipeline?: Record<Stage, number>
}
export interface HrJobFull extends HrJob { title_ar: string; title_en: string; summary_ar: string; summary_en: string; requirements: Bi[]; department_id: number; location_id: number; employment_type: string; match_weights: MatchWeights | null; workplace_code: Workplace; interview: JobInterview }
export type Workplace = 'onsite' | 'remote' | 'hybrid'
export type JobInterview = { questions: Bi[]; skills: Bi[] }
export type MatchWeights = Record<'exp' | 'deg' | 'sal' | 'eng' | 'file', number>
export interface JobInput {
  title_ar: string; title_en: string; department_id: number | ''; location_id: number | ''; employment_type: string; workplace: Workplace; openings: number; min_years: number; degree: number
  salary_min: number | ''; salary_max: number | ''; currency: 'USD' | 'SYP'; summary_ar: string; summary_en: string; requirements: Bi[]
  visibility: Visibility; closes_at: string; hard_to_fill: boolean; requires_photo: boolean; status: 'draft' | 'open' | 'closed'
  match_weights: MatchWeights | null; interview: JobInterview
}
export interface Lookups { departments: (Bi & { id: number })[]; locations: (Bi & { id: number })[]; types: (Bi & { code: string })[]; workplaces: (Bi & { code: Workplace })[]; degrees: Bi[] }
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

/** رابط إشعار/مهمة (cand:12، emp:7، leaves…) ← مسار داخل نظام الشركة */
export function hrLink(link: string): string {
  const [k, id] = link.split(':')
  if (k === 'cand') return `/hr/candidates/${id}`
  if (k === 'emp') return `/hr/employees/${id}`
  if (k === 'job') return '/hr/jobs'
  if (k === 'job-matches') return `/hr/jobs?matches=${id}`
  if (k === 'recruitment') return '/hr/candidates'
  if (k === 'onboard') return `/hr/onboarding${id ? '/' + id : ''}`
  if (k === 'docs') return '/hr/my-documents'
  if (k === 'requests') return '/hr/my-requests'
  if (k === 'payslip') return '/hr/my-payslip'
  if (k === 'discipline') return id ? `/hr/discipline?case=${id}` : '/hr/my-discipline'
  if (k === 'complaint') return '/hr/discipline?tab=complaints'
  if (k === 'complaints') return '/hr/my-discipline'
  if (k === 'offboarding') return id ? `/hr/offboarding?status=all&id=${id}` : '/hr/offboarding'
  if (k === 'resignation' || k === 'handover') return '/hr/my-resignation'
  if (k === 'rewards' || k === 'internal') return '/hr/my-rewards'
  if (k === 'reviews') return '/hr/my-reviews'
  if (k === 'development') return '/hr/development'
  if (k === 'kpis') return '/hr/tasks?tab=kpis'
  if (k === 'referrals') return '/hr/referrals?tab=red'
  if (k === 'policy') return id ? `/hr/policies?doc=${id}` : '/hr/policies'
  if (k === 'space') return id ? `/hr/space/p/${id}` : '/hr/space'
  if (k === 'space-moderation') return '/hr/space/moderation'
  return `/hr/${k}`
}

/** مطابقة بنك السير مع شاغر ودعوة أصحابها للتقديم (ملاحظة الاختبار 46) */
export interface CvMatch { id: number; ref: string; name: Bi; score: number; years: number; degree: number; major: string | null; last_job: Bi; applied_at: string; stage: string; invited: boolean }
export type CvMatches = { data: CvMatch[]; job: { id: number; code: string; title: Bi; accepting: boolean }; min: number }
export const useCvMatches = (id: number | null) => useQuery({ queryKey: ['hr', 'cv-matches', id], enabled: !!id, queryFn: () => api<CvMatches>(`/hr/jobs/${id}/cv-matches`) })
export const useInviteCv = (id: number) => useMutation({ mutationFn: (cid: number) => api<CvMatches>(`/hr/jobs/${id}/cv-matches/${cid}/invite`, { method: 'POST', json: {} }),
  onSuccess: d => queryClient.setQueryData(['hr', 'cv-matches', id], d) })
