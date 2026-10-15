import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api } from './api'
import type { Bi } from './types'
import type { CompanyCard } from './session'

/** بوابة فرص العمل العامة (SRS §10.15): البوابة، صفحة الشركة، صفحة الشاغر — بلا تسجيل دخول */
export interface PublicJob {
  code: string; company: CompanyCard; title: Bi; summary: Bi; dept: Bi & { code: string }; city: Bi & { code: string }; type: Bi; type_code: string
  min_years: number; hard_to_fill: boolean; posted_at: string; closes_at: string | null
}
export interface PublicJobFull extends PublicJob {
  requirements: Bi[]; openings: number; degree: number; degree_label: Bi | null; requires_photo: boolean; company_about: Bi
  referral?: { valid: boolean; code: string; by?: Bi }
}
export interface Portal {
  brand_latin: string; hero: { title: Bi; text: Bi }; stats: { jobs: number; companies: number; cities: number }
  companies: (CompanyCard & { jobs: number })[]
  filters: { cities: (Bi & { code: string })[]; types: (Bi & { code: string })[] }
}
export interface CompanyPage extends CompanyCard { about: Bi; website: string | null; jobs: PublicJob[] }
export type JobFilters = { company?: string; city?: string; type?: string; exp?: string; q?: string }

export const jobUrl = (j: { company: { slug: string }; code: string }) => `/jobs/${j.company.slug}/${encodeURIComponent(j.code)}`
export const applyUrl = (j: { company: { slug: string }; code: string }, ref?: string) => `/apply/${j.company.slug}/${encodeURIComponent(j.code)}${ref ? `?ref=${encodeURIComponent(ref)}` : ''}`

export const usePortal = () => useQuery({ queryKey: ['public', 'portal'], queryFn: () => api<Portal>('/public/portal'), staleTime: 60_000 })

export function useJobs(f: JobFilters = {}) {
  const qs = new URLSearchParams(Object.entries(f).filter(([, v]) => v) as [string, string][]).toString()
  return useQuery({ queryKey: ['public', 'jobs', qs], queryFn: () => api<{ data: PublicJob[] }>(`/public/jobs${qs ? '?' + qs : ''}`).then(r => r.data), placeholderData: keepPreviousData })
}

export const useCompanyPage = (slug: string) => useQuery({ queryKey: ['public', 'company', slug], queryFn: () => api<{ data: CompanyPage }>(`/public/companies/${encodeURIComponent(slug)}`).then(r => r.data), retry: false })

export const useJob = (slug: string, code: string, ref?: string) => useQuery({
  queryKey: ['public', 'job', slug, code, ref ?? ''], retry: false,
  queryFn: () => api<{ data: PublicJobFull }>(`/public/jobs/${encodeURIComponent(slug)}/${encodeURIComponent(code)}${ref ? `?ref=${encodeURIComponent(ref)}` : ''}`).then(r => r.data),
})
