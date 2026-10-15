import { QueryClient, useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { navigate } from './router'
import type { Bi } from './types'

export type Portal = 'platform' | 'hr' | 'employee' | 'candidate'
export interface CompanyCard { slug: string; name: Bi; short: Bi; brand_latin: string | null; logo: string | null }
export interface SessionUser {
  id: number; email: string; name: Bi; roles: string[]; portal: Portal; must_change_password: boolean
  employee: { code: string; title: Bi; dept: Bi; gender: 'M' | 'F' } | null
  company: CompanyCard | null
  /** صلاحيات الوحدات (SRS §10.2) — للإخفاء في الواجهة فقط، والخادم يتحقق في كل طلب */
  perms?: Record<string, { actions: PermAction[]; scope: 'own' | 'team' | 'all' }>
}
export type PermAction = 'view' | 'create' | 'edit' | 'decide' | 'export' | 'print' | 'archive'
export const can = (u: SessionUser | null | undefined, module: string, action: PermAction = 'view') => !!u?.perms?.[module]?.actions.includes(action)

export const queryClient = new QueryClient({ defaultOptions: { queries: { retry: 1, refetchOnWindowFocus: false, staleTime: 30_000 } } })

export function useMe() {
  return useQuery({ queryKey: ['me'], queryFn: () => api<{ user: SessionUser | null }>('/auth/me').then(r => r.user), staleTime: Infinity })
}

// تبديل المستخدم: نُسقط كل بيانات المستخدم السابق، ونحدّث ['me'] في مكانه (لا clear حتى لا ينفصل مراقب useMe)
const setMe = (r: { user: SessionUser | null }) => { queryClient.removeQueries({ predicate: q => q.queryKey[0] !== 'me' && q.queryKey[0] !== 'public' }); queryClient.setQueryData(['me'], r.user) }

export function useLogin() {
  return useMutation({ mutationFn: (v: { email: string; password: string }) => api<{ user: SessionUser }>('/auth/login', { json: v }), onSuccess: setMe })
}
export function useLogout() {
  return useMutation({ mutationFn: () => api<{ user: null }>('/auth/logout', { json: {} }), onSettled: () => { setMe({ user: null }); navigate('/') } })
}
export function useChangePassword() {
  return useMutation({ mutationFn: (v: { current_password: string; password: string; password_confirmation: string }) => api<{ user: SessionUser }>('/auth/password', { json: v }), onSuccess: r => queryClient.setQueryData(['me'], r.user) })
}
