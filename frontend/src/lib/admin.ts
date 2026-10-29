import { useMutation, useQuery } from '@tanstack/react-query'
import { api, ApiError, xsrf } from './api'
import { getPrefs } from './store'
import { queryClient } from './session'
import type { Bi } from './types'

// M16 — إدارة النظام (11a): بيانات الشركة، الإدارات، سلاسل الاعتماد، المستخدمون والأدوار، قنوات الإشعار وقوالبها

export interface CompanyProfile {
  slug: string; name_ar: string; name_en: string; short_ar: string | null; short_en: string | null; brand_latin: string | null; about_ar: string | null; about_en: string | null
  website: string | null; logo: string | null; domain: string; careers_url: string; form_prefix: string; hr_dept_ar: string; hr_dept_en: string
  site: { hero_title_ar: string; hero_title_en: string; hero_text_ar: string; hero_text_en: string }
  prefixes: Record<'employee' | 'candidate' | 'decision' | 'asset' | 'job', string>
}
type CompanyRes = { data: CompanyProfile; defaults: Pick<CompanyProfile, 'site' | 'prefixes'> }
export const useCompanyProfile = () => useQuery({ queryKey: ['admin', 'company'], queryFn: () => api<CompanyRes>('/hr/admin/company') })
export const useSaveCompany = () => useMutation({
  mutationFn: ({ v, logo }: { v: CompanyProfile; logo?: File | null }) => {
    const f = new FormData()
    const put = (k: string, x: unknown) => { if (x !== null && x !== undefined) f.append(k, String(x)) }
    for (const [k, x] of Object.entries(v)) if (!['site', 'prefixes', 'logo', 'slug'].includes(k)) put(k, x)
    for (const [k, x] of Object.entries(v.site)) put(`site[${k}]`, x)
    for (const [k, x] of Object.entries(v.prefixes)) put(`prefixes[${k}]`, x)
    if (logo) f.append('logo', logo)
    return api<CompanyRes>('/hr/admin/company', { method: 'POST', body: f })
  },
  onSuccess: d => { queryClient.setQueryData(['admin', 'company'], d); void queryClient.invalidateQueries({ queryKey: ['me'] }) },
})

export interface Dept { id: number; code: string; ar: string; en: string; headcount: number; current: number; color: string; sort: number; active: boolean; head: (Bi & { id: number; code: string }) | null }
export type DeptInput = { code: string; ar: string; en: string; headcount: number; color: string; sort?: number; active: boolean; head_id: number | null }
type DeptRes = { data: Dept[]; colors: string[]; employees: (Bi & { id: number; code: string; department_id: number | null })[] }
export const useDepartments = () => useQuery({ queryKey: ['admin', 'departments'], queryFn: () => api<DeptRes>('/hr/admin/departments') })
export const useSaveDepartment = () => useMutation({
  mutationFn: ({ id, v }: { id?: number; v: DeptInput }) => api<DeptRes>(id ? `/hr/admin/departments/${id}` : '/hr/admin/departments', { method: id ? 'PUT' : 'POST', json: v }),
  onSuccess: d => { queryClient.setQueryData(['admin', 'departments'], d); void queryClient.invalidateQueries({ queryKey: ['hr', 'org'] }) },
})

export interface ChainLevel { role_key: string; role_ar: string; role_en: string; user_id: number | null; deputy_id: number | null; user?: Bi | null; deputy?: Bi | null }
export interface Chain extends Bi { type: string; manager_allowed: boolean; levels: ChainLevel[] }
type ChainRes = { data: Chain[]; roles: (Bi & { key: string })[]; users: (Bi & { id: number; email: string; roles: string[] })[] }
export const useChains = () => useQuery({ queryKey: ['admin', 'chains'], queryFn: () => api<ChainRes>('/hr/admin/chains') })
export const useSaveChain = () => useMutation({
  mutationFn: ({ type, levels }: { type: string; levels: ChainLevel[] }) => api<ChainRes>(`/hr/admin/chains/${type}`, { method: 'PUT', json: { levels: levels.map(({ user, deputy, ...l }) => l) } }),
  onSuccess: d => queryClient.setQueryData(['admin', 'chains'], d),
})

export interface AdminUser {
  id: number; email: string; name: Bi; active: boolean; employee: { id: number; code: string; status: string; dept: Bi | null } | null
  roles: string[]; last_login_at: string | null; locked: boolean; must_change_password: boolean; mfa: boolean; passkeys: number
}
type UsersRes = { data: AdminUser[]; roles: (Bi & { key: string })[]; employees: (Bi & { id: number; code: string; email: string })[]; can: { create: boolean; edit: boolean } }
export const useAdminUsers = (q: Record<string, string | undefined>) => {
  const qs = new URLSearchParams(Object.entries(q).filter(([, v]) => v) as [string, string][]).toString()
  return useQuery({ queryKey: ['admin', 'users', qs], queryFn: () => api<UsersRes>(`/hr/admin/users${qs ? `?${qs}` : ''}`), placeholderData: p => p })
}
const usersChanged = () => queryClient.invalidateQueries({ queryKey: ['admin', 'users'] })
export const useCreateUser = () => useMutation({ mutationFn: (v: { employee_id?: number | null; email?: string; name?: string; name_en?: string; roles: string[] }) => api<{ data: AdminUser; temporary_password: string }>('/hr/admin/users', { json: v }), onSuccess: usersChanged })
export const useUpdateUser = () => useMutation({ mutationFn: ({ id, ...v }: { id: number; roles: string[]; name?: string; name_en?: string }) => api<{ data: AdminUser }>(`/hr/admin/users/${id}`, { method: 'PUT', json: v }), onSuccess: usersChanged })
export type UserAction = 'reset-password' | 'deactivate' | 'activate' | 'unlock' | 'reset-mfa'
export const useUserAction = () => useMutation({ mutationFn: ({ id, action }: { id: number; action: UserAction }) => api<{ data: AdminUser; temporary_password?: string }>(`/hr/admin/users/${id}/${action}`, { json: {} }), onSuccess: usersChanged })

export interface ChannelCfg { key: 'email' | 'sms' | 'whatsapp'; enabled: boolean; config: Record<string, { value?: string; set?: boolean; secret: boolean }> }
export interface Template { id: number; key: string; channel: string; label: Bi; ar: string; en: string }
type ChannelsRes = { channels: ChannelCfg[]; templates: Template[]; tokens: string[] }
export const useChannels = () => useQuery({ queryKey: ['admin', 'channels'], queryFn: () => api<ChannelsRes>('/hr/admin/channels') })
export const useSaveChannel = () => useMutation({ mutationFn: ({ key, enabled, config }: { key: string; enabled: boolean; config: Record<string, string> }) => api<ChannelsRes>(`/hr/admin/channels/${key}`, { method: 'PUT', json: { enabled, config } }),
  onSuccess: d => queryClient.setQueryData(['admin', 'channels'], d) })
export const useSaveTemplate = () => useMutation({ mutationFn: ({ id, ar, en }: { id: number; ar: string; en: string }) => api<ChannelsRes>(`/hr/admin/templates/${id}`, { method: 'PUT', json: { ar, en } }),
  onSuccess: d => queryClient.setQueryData(['admin', 'channels'], d) })

// ——— 11b: سجل التدقيق، النسخ الاحتياطي ———
export interface AuditRow {
  id: number; at: string; action: string; label: Bi; subject: (Bi & { id: number | null }) | null; user: (Bi & { id: number; email: string }) | null
  before: Record<string, unknown> | null; after: Record<string, unknown> | null; ip: string | null; agent: string | null
}
export type AuditFilter = { q?: string; user?: string; group?: string; from?: string; to?: string; archive?: string; page?: string }
type AuditRes = { data: AuditRow[]; total: number; page: number; per: number; settings: { visible_days: number }; defaults: { visible_days: number }; since: string
  groups: (Bi & { key: string })[]; users: (Bi & { id: number; email: string })[]; can: { export: boolean; archive: boolean; settings: boolean } }
const qs = (f: Record<string, string | undefined>) => new URLSearchParams(Object.entries(f).filter(([, v]) => v) as [string, string][]).toString()
export const useAudit = (f: AuditFilter) => useQuery({ queryKey: ['admin', 'audit', qs(f)], queryFn: () => api<AuditRes>(`/hr/admin/audit?${qs(f)}`), placeholderData: p => p })
export const useAuditArchives = (on: boolean) => useQuery({ queryKey: ['admin', 'audit', 'archives'], enabled: on, queryFn: () => api<{ data: { month: string; size: number }[] }>('/hr/admin/audit/archives') })
export const useSaveAuditSettings = () => useMutation({ mutationFn: (v: { visible_days: number }) => api('/hr/admin/audit/settings', { method: 'PUT', json: v }), onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'audit'] }) })

export interface BackupRow { id: number; kind: 'full' | 'company'; trigger: 'auto' | 'manual'; status: 'running' | 'ok' | 'failed' | 'removed'; size: number | null; meta: Record<string, number | string> | null; error: string | null; name: string | null; started_at: string | null; finished_at: string | null; by: Bi | null }
type BackupRes = { data: BackupRow[]; server: { last: BackupRow | null; last_ok_at: string | null; schedule: { enabled: boolean; time: string; keep: number } }; settings: { keep: number; include_files: boolean }; defaults: { keep: number; include_files: boolean }; can: { edit: boolean } }
export const useBackups = () => useQuery({ queryKey: ['admin', 'backups'], queryFn: () => api<BackupRes>('/hr/admin/backups') })
export const useRunBackup = () => useMutation({ mutationFn: () => api<{ data: BackupRow }>('/hr/admin/backups', { json: {} }), onSettled: () => queryClient.invalidateQueries({ queryKey: ['admin', 'backups'] }) })
export const useSaveBackupSettings = () => useMutation({ mutationFn: (v: { keep: number; include_files: boolean }) => api<BackupRes>('/hr/admin/backups/settings', { method: 'PUT', json: v }), onSuccess: d => queryClient.setQueryData(['admin', 'backups'], d) })
type PlatformBackupRes = { data: BackupRow[]; settings: { enabled: boolean; time: string; keep: number }; defaults: { enabled: boolean; time: string; keep: number } }
export const usePlatformBackups = () => useQuery({ queryKey: ['platform', 'backups'], queryFn: () => api<PlatformBackupRes>('/platform/backups') })
export const useRunPlatformBackup = () => useMutation({ mutationFn: () => api<{ data: BackupRow }>('/platform/backups', { json: {} }), onSettled: () => queryClient.invalidateQueries({ queryKey: ['platform', 'backups'] }) })
export const useSavePlatformBackupSettings = () => useMutation({ mutationFn: (v: { enabled: boolean; time: string; keep: number }) => api<PlatformBackupRes>('/platform/backups/settings', { method: 'PUT', json: v }), onSuccess: d => queryClient.setQueryData(['platform', 'backups'], d) })

/** تنزيل ملف من الـ API (Excel، نسخة، أرشيف) — الخطأ يُرجع ApiError برمزه (salary_locked…) */
export async function download(path: string, name: string) {
  const r = await fetch(`/api${path}`, { credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json', 'Accept-Language': getPrefs().lang } })
  if (!r.ok) { let b: Record<string, unknown> = {}; try { b = await r.json() } catch { /* */ } throw new ApiError(r.status, (b.message as string) ?? `HTTP ${r.status}`, b.code as string | undefined, {}, b) }
  const a = document.createElement('a'); a.href = URL.createObjectURL(await r.blob()); a.download = name; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(a.href)
}
export const fmtSize = (n: number | null) => n === null ? '—' : n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(0)} KB` : `${(n / 1048576).toFixed(1)} MB`
