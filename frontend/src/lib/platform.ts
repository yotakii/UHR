import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient, type CompanyCard } from './session'
import type { Bi } from './types'
import type { PublicJobFull } from './public'

/** لوحة مدير المنصة (Atmata) — SRS §10.15 */
export interface PlatformCompany extends CompanyCard {
  id: number; status: 'active' | 'suspended'; portal_enabled: boolean; portal_review: boolean; is_platform: boolean
  name_ar: string; name_en: string; short_ar: string | null; short_en: string | null; about_ar: string | null; about_en: string | null; website: string | null; about: Bi
  counts: { jobs_open: number; jobs_portal: number; jobs_pending: number; applications: number; employees: number }; created_at: string
  domain: string | null; portal_urls: string[]
}
/** تفاصيل الشركة واستخدامها وحساباتها (47/48) — بلا كلمات مرور */
export type AccountState = 'active' | 'pending' | 'expired' | 'locked' | 'inactive'
export interface CompanyAccount { id: number; email: string; name: Bi; code: string | null; roles: (Bi & { key: string })[]; state: AccountState; temp_password: boolean; mfa: boolean; passkeys: number; failed_logins: number; last_login_at: string | null; created_at: string | null }
export interface CompanyDetail {
  id: number; slug: string; name: Bi; status: 'active' | 'suspended'; is_platform: boolean; website: string | null; domain: string | null; portal_urls: string[]; created_at: string | null
  usage: {
    users: { total: number; active: number; inactive: number; locked: number; pending: number; signed_in_30d: number; last_login_at: string | null }
    employees: Record<string, number>; departments: number; jobs: { total: number; open: number }; applications: { total: number; month: number }
    attendance_30d: number; requests_30d: number; files: { count: number; bytes: number }; mail_month: { sent: number; failed: number }; cv_readings_month: number; audit_30d: number; last_activity_at: string | null
  }
  accounts: CompanyAccount[]
}
export const useCompanyDetail = (id: number, q: string) => useQuery({ queryKey: ['platform', 'company', id, q], placeholderData: p => p, queryFn: () => api<{ data: CompanyDetail }>(`/platform/companies/${id}/detail?q=${encodeURIComponent(q)}`).then(r => r.data) })
export const useAccountAction = (id: number) => useMutation({
  mutationFn: (v: { user: number; action: 'reset-password' | 'unlock' | 'deactivate' | 'activate' }) => api<{ data: CompanyAccount; sent: boolean }>(`/platform/companies/${id}/users/${v.user}/${v.action}`, { json: {} }),
  onSuccess: () => queryClient.invalidateQueries({ queryKey: ['platform', 'company', id] }),
})
export const usePortalHosts = () => useQuery({ queryKey: ['platform', 'portal-hosts'], queryFn: () => api<{ hosts: { base_domain: string } }>('/platform/portal').then(r => r.hosts) })
export const useSaveHosts = () => useMutation({ mutationFn: (v: { base_domain: string }) => api('/platform/portal/hosts', { method: 'PUT', json: v }), onSuccess: refresh })
export interface Overview {
  companies: number; active: number; portal_jobs: number; pending: number; applications: number; applications_30d: number
  by_company: (CompanyCard & { applications: number; applications_30d: number; jobs: number })[]
}
export interface ReviewJob extends PublicJobFull { id: number; status: string; portal_status: 'pending' | 'approved' | 'rejected'; portal_note: string | null; portal_reviewed_at: string | null; submitted_at: string; salary: { min: number; max: number; currency: string } }
export interface PortalTexts { brand_latin: string; hero_title_ar: string; hero_title_en: string; hero_text_ar: string; hero_text_en: string }

const refresh = () => { queryClient.invalidateQueries({ queryKey: ['platform'] }); queryClient.invalidateQueries({ queryKey: ['public'] }) }

export const useOverview = () => useQuery({ queryKey: ['platform', 'overview'], queryFn: () => api<Overview>('/platform/overview') })
export const useCompanies = () => useQuery({ queryKey: ['platform', 'companies'], queryFn: () => api<{ data: PlatformCompany[] }>('/platform/companies').then(r => r.data) })
export const useReviews = (status: 'pending' | 'approved' | 'rejected') => useQuery({ queryKey: ['platform', 'reviews', status], queryFn: () => api<{ data: ReviewJob[] }>(`/platform/reviews?status=${status}`).then(r => r.data) })
export const usePortalTexts = () => useQuery({ queryKey: ['platform', 'portal'], queryFn: () => api<{ data: PortalTexts }>('/platform/portal').then(r => r.data) })

export function useSaveCompany(id: number | null) {
  return useMutation({
    mutationFn: (fd: FormData) => api<{ data: PlatformCompany; admin?: { email: string; link: string | null } }>(id ? `/platform/companies/${id}` : '/platform/companies', { method: 'POST', body: fd }),
    onSuccess: refresh,
  })
}
export const useCompanyStatus = () => useMutation({ mutationFn: (v: { id: number; status: 'active' | 'suspended'; reason: string }) => api(`/platform/companies/${v.id}/status`, { json: v }), onSuccess: refresh })
export const useReview = () => useMutation({ mutationFn: (v: { id: number; decision: 'approved' | 'rejected'; reason: string }) => api(`/platform/reviews/${v.id}`, { json: v }), onSuccess: refresh })
export const useSavePortal = () => useMutation({ mutationFn: (v: PortalTexts) => api<{ data: PortalTexts }>('/platform/portal', { method: 'PUT', json: v }), onSuccess: refresh })

// قراءة السير بالنموذج المحلي: الحالة، التشغيل والحد الشهري لكل شركة
export interface CvReadingCompany { id: number; ar: string; en: string; enabled: boolean; monthly_limit: number | null; used: number; failed: number }
export interface CvReading { configured: boolean; available: boolean; model: string; enabled: boolean; monthly_limit: number; queue: number; companies: CvReadingCompany[] }
export const useCvReading = () => useQuery({ queryKey: ['platform', 'cv-reading'], queryFn: () => api<{ data: CvReading }>('/platform/cv-reading').then(r => r.data), refetchInterval: 30_000 })
export const useSaveCvReading = () => useMutation({
  mutationFn: (v: { enabled: boolean; monthly_limit: number; companies: { id: number; enabled: boolean; monthly_limit: number | null }[] }) => api<{ data: CvReading }>('/platform/cv-reading', { method: 'PUT', json: v }).then(r => r.data),
  onSuccess: d => queryClient.setQueryData(['platform', 'cv-reading'], d),
})

// البريد الصادر (11e): خادم SMTP للمنصة، رسالة تجريبية
export interface MailSettings { host: string; port: number; encryption: 'tls' | 'ssl' | 'none'; username: string; password_set: boolean; from_address: string; from_name: string; log_days: number
  source: 'settings' | 'server'; server: { host: string | null; from: string | null }; stats: { sent: number; failed: number } }
export const useMailSettings = () => useQuery({ queryKey: ['platform', 'mail'], queryFn: () => api<{ data: MailSettings }>('/platform/mail').then(r => r.data) })
export const useSaveMail = () => useMutation({
  mutationFn: (v: Omit<MailSettings, 'password_set' | 'source' | 'server' | 'stats'> & { password?: string; clear_password?: boolean }) => api<{ data: MailSettings }>('/platform/mail', { method: 'PUT', json: v }).then(r => r.data),
  onSuccess: d => queryClient.setQueryData(['platform', 'mail'], d),
})
export const useTestMail = () => useMutation({ mutationFn: (to: string) => api<{ message: string }>('/platform/mail/test', { json: { to } }), onSettled: () => queryClient.invalidateQueries({ queryKey: ['mail-log'] }) })
