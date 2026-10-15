import { useQuery } from '@tanstack/react-query'
import { api } from './api'
import type { Bi } from './types'

/** بيانات الواجهة العامة (M01/M02): نصوص الموقع والوظائف المنشورة — بلا تسجيل دخول */
export interface PublicJob {
  code: string; title: Bi; summary: Bi; dept: Bi & { code: string }; city: Bi; type: Bi; posted_at: string
  requirements?: Bi[]; openings?: number; min_years?: number; degree?: number
}
export interface Site { company: { name: Bi; short: Bi; brand_latin: string }; hero: { title: Bi; text: Bi } }

export const useSite = () => useQuery({ queryKey: ['public', 'site'], queryFn: () => api<Site>('/public/site'), staleTime: 5 * 60_000 })
export const useJobs = () => useQuery({ queryKey: ['public', 'jobs'], queryFn: () => api<{ data: PublicJob[] }>('/public/jobs').then(r => r.data) })
export const useJob = (code: string) => useQuery({ queryKey: ['public', 'job', code], queryFn: () => api<{ data: PublicJob }>(`/public/jobs/${encodeURIComponent(code)}`).then(r => r.data), retry: false })
