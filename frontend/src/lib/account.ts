import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient, type SessionUser } from './session'
import type { Bi } from './types'

/** «حسابي» (ملاحظة 67) */
export interface Account {
  email: string; name: Bi; own_name: string | null; name_en: string | null; avatar: number | null; locale: 'ar' | 'en'; theme: 'slate' | 'dark' | 'light'; last_login_at: string | null
  roles: (Bi & { key: string })[]
  employee: { code: string; title: Bi; dept: Bi; manager: Bi | null; location: Bi | null; phone: string | null; hire_date: string | null } | null
  password_policy: { min: number; upper: boolean; symbol: boolean }
}
export const useAccount = () => useQuery({ queryKey: ['account'], queryFn: () => api<{ data: Account }>('/me/account').then(r => r.data) })
const done = (r: { user: SessionUser }) => { queryClient.setQueryData(['me'], r.user); queryClient.invalidateQueries({ queryKey: ['account'] }) }
export const useSaveAccount = () => useMutation({ mutationFn: (v: { locale?: 'ar' | 'en'; theme?: string; name?: string; name_en?: string }) => api<{ user: SessionUser }>('/me/account', { method: 'PUT', json: v }), onSuccess: done })
export const useAvatar = () => useMutation({ mutationFn: (f: File) => { const fd = new FormData(); fd.append('file', f); return api<{ user: SessionUser }>('/me/account/avatar', { method: 'POST', body: fd }) }, onSuccess: done })
export const useRemoveAvatar = () => useMutation({ mutationFn: () => api<{ user: SessionUser }>('/me/account/avatar', { method: 'DELETE' }), onSuccess: done })
