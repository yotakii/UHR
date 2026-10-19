import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'
import type { CandFile } from './candidates'
import type { Stage } from './recruitment'

/** الاختبار الأولي (D-34) وقاعدة السير (D-02) — 06c */
export type QType = 'mcq' | 'short' | 'scenario'
export interface Question { id: string; type: QType; text: string; options: string[]; answer: number; points: number; rubric: string }
export interface TestSettings { enabled: boolean; audience: 'all' | 'passed'; minutes: number; attempts: number; valid_days: number }
export interface JobTestData {
  job: { id: number; code: string; title: Bi; status: string }
  settings: TestSettings
  test: { status: 'draft' | 'approved'; questions: Question[]; ai_generated: boolean; edited: boolean; generated_at: string | null; approved_at: string | null } | null
  ai: boolean; sent: number
}
export type TestStatus = 'sent' | 'started' | 'completed' | 'reviewed' | 'expired'
export interface CandTestDetail {
  enabled: boolean; approved: boolean; attempts: number
  current: { id: number; attempt: number; status: TestStatus; minutes: number; sent_at: string; expires_at: string; started_at: string | null; submitted_at: string | null; reviewed_at: string | null
    review_note: string | null; auto_pct: number | null; pct: number | null; questions: Question[]; answers: Record<string, number | string | null>; suggested: Record<string, { score: number; note: string }>; scores: Record<string, number> } | null
}

export const useJobTest = (id: number) => useQuery({ queryKey: ['hr', 'job-test', id], queryFn: () => api<{ data: JobTestData }>(`/hr/jobs/${id}/test`).then(r => r.data) })
const setJobTest = (d: JobTestData) => { queryClient.setQueryData(['hr', 'job-test', d.job.id], d); queryClient.invalidateQueries({ queryKey: ['hr', 'jobs'] }) }
export const useGenerateTest = (id: number) => useMutation({ mutationFn: () => api<{ data: JobTestData }>(`/hr/jobs/${id}/test/generate`, { method: 'POST', json: {} }).then(r => r.data), onSuccess: setJobTest })
export const useSaveJobTest = (id: number) => useMutation({
  mutationFn: (v: TestSettings & { questions: Question[]; approve: boolean }) => api<{ data: JobTestData }>(`/hr/jobs/${id}/test`, { method: 'PUT', json: v }).then(r => r.data), onSuccess: setJobTest,
})

export interface AiSettings { configured: boolean; source: 'company' | 'server' | null; hint: string | null; model: string }
export const useAiSettings = () => useQuery({ queryKey: ['hr', 'ai-settings'], queryFn: () => api<AiSettings>('/hr/ai-settings') })
export const useSaveAiKey = () => useMutation({
  mutationFn: (api_key: string) => api<AiSettings>('/hr/ai-settings', { method: 'PUT', json: { api_key: api_key || null } }),
  onSuccess: d => { queryClient.setQueryData(['hr', 'ai-settings'], d); queryClient.invalidateQueries({ queryKey: ['hr', 'job-test'] }) },
})

const settleCand = (c: CandFile) => { queryClient.setQueryData(['hr', 'candidate', c.id], c); queryClient.invalidateQueries({ queryKey: ['hr', 'candidates'] }) }
export const useSendTest = (id: number) => useMutation({ mutationFn: () => api<{ data: CandFile }>(`/hr/candidates/${id}/test`, { method: 'POST', json: {} }).then(r => r.data), onSuccess: settleCand })
export const useReviewTest = (id: number) => useMutation({
  mutationFn: (v: { scores: Record<string, number>; note: string }) => api<{ data: CandFile }>(`/hr/candidates/${id}/test/review`, { method: 'POST', json: v }).then(r => r.data), onSuccess: settleCand,
})

/* ---------- قاعدة السير ---------- */
export interface CvRow {
  id: number; ref: string; name: Bi; gender: 'M' | 'F'; stage: Stage; match: number; degree: number; phase: string | null; major: string | null; institute: string | null; years_exp: number
  last: { position: string; company: string } | null; address: string | null; job: { id: number; code: string; title: Bi; city: Bi }; cv: { id: number; name: string } | null
  cv_status: 'active' | 'archived'; applied_at: string; archived_at: string | null
}
export interface CvFilters { q?: string; degree?: string; years?: string; city?: string; status?: 'active' | 'archived'; page?: string }
const qs = (f: CvFilters) => new URLSearchParams(Object.entries(f).filter(([, v]) => v !== undefined && v !== '') as [string, string][]).toString()
export const useCvDb = (f: CvFilters) => useQuery({
  queryKey: ['hr', 'cv', f], placeholderData: prev => prev,
  queryFn: () => api<{ data: CvRow[]; meta: { total: number; page: number; pages: number }; cities: { code: string; ar: string; en: string }[]; degrees: Bi[] }>(`/hr/cv?${qs(f)}`),
})
export const cvExportUrl = (f: CvFilters) => `/api/hr/cv/export?${qs({ ...f, page: undefined })}`
export const useArchiveCv = () => useMutation({
  mutationFn: (v: { id: number; restore: boolean }) => api<{ data: CvRow }>(`/hr/cv/${v.id}/archive`, { method: 'POST', json: { restore: v.restore } }),
  onSuccess: () => queryClient.invalidateQueries({ queryKey: ['hr', 'cv'] }),
})

/* ---------- صفحة المتقدم للاختبار ---------- */
export interface PublicTest {
  status: TestStatus; minutes: number; count: number; expires_at: string; deadline_at: string | null; now: string; submitted_at: string | null
  job: Bi; company: Bi & { slug: string; logo: string | null }; name: string
  questions: Pick<Question, 'id' | 'type' | 'text' | 'options' | 'points'>[]
}
export const usePublicTest = (token: string) => useQuery({ queryKey: ['public-test', token], queryFn: () => api<{ data: PublicTest }>(`/public/tests/${token}`).then(r => r.data), retry: false })
const setPublic = (token: string) => (d: PublicTest) => queryClient.setQueryData(['public-test', token], d)
export const useStartTest = (token: string) => useMutation({ mutationFn: () => api<{ data: PublicTest }>(`/public/tests/${token}/start`, { method: 'POST', json: {} }).then(r => r.data), onSuccess: setPublic(token) })
export const useSubmitTest = (token: string) => useMutation({
  mutationFn: (answers: Record<string, number | string | null>) => api<{ data: PublicTest }>(`/public/tests/${token}/submit`, { method: 'POST', json: { answers } }).then(r => r.data), onSuccess: setPublic(token),
})

/** «احذف بياناتي» من بوابة المتقدم */
export const useEraseApplication = () => useMutation({
  mutationFn: (v: { id: number; password: string }) => api<{ ok: boolean }>(`/me/applications/${v.id}/erase`, { method: 'POST', json: { password: v.password } }),
  onSuccess: () => queryClient.invalidateQueries({ queryKey: ['me'] }),
})
