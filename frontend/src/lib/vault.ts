import { useEffect, useSyncExternalStore } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { api, salaryHeader } from './api'
import { queryClient } from './session'
import type { Bi } from './types'

// حماية الرواتب بخطوتين (SRS §4.4): الحالة من الخادم (/me/mfa)؛ الخادم لا يُرجع أي مبلغ والرواتب مقفلة،
// فعند الفتح أو القفل تُعاد كل الاستعلامات لتصل المبالغ أو تُخفى.

export type VaultMethod = 'link' | 'passkey' | 'phone' | 'app'
export interface VaultState {
  unlocked: boolean; remaining: number; lockout: number; enrolled: boolean; backup_left: number
  passkeys: { id: number; label: string | null; created_at: string | null; last_used_at: string | null }[]
  last_ok: { at: string; method: string } | null
  methods: Record<VaultMethod, boolean>; unlock_minutes: number; max_minutes: number; otp_minutes: number; link_seconds: number
  otp: { expires_in: number; to: string | null } | null; destination: string | null
  grant: { needed: boolean; hours: number; last: { id: number; status: string; scope: 'all' | 'department'; department: Bi | null; expires_at: string | null; created_at: string | null } | null }
}
export interface VaultSettings {
  unlock_minutes: number; max_minutes: number; max_attempts: number; lockout_seconds: number; otp_minutes: number; link_seconds: number; backup_count: number; audit_keep: number
  methods: Record<VaultMethod, boolean>; grant_required: boolean; grant_hours: number; grant_exempt_roles: string[]
}

const KEY = ['mfa'] as const
let fetchedAt = 0

// كل طلب اطّلع على رواتب يمدّد النافذة على الخادم ← نحدّث العدّاد هنا
salaryHeader.fn = (sec: number) => {
  const s = queryClient.getQueryData<VaultState>(KEY)
  if (!s?.unlocked) return
  fetchedAt = Date.now()
  queryClient.setQueryData(KEY, { ...s, remaining: sec })
}

/** إذن رواتب الآخرين ساري؟ (أو غير مطلوب) */
export const grantActive = (s?: VaultState) => !!s && (!s.grant.needed || s.grant.last?.status === 'approved')

export function useVault() {
  const q = useQuery({ queryKey: KEY, queryFn: async () => { const r = await api<{ data: VaultState }>('/me/mfa'); fetchedAt = Date.now(); return r.data }, staleTime: 60_000 })
  useTick(!!q.data?.unlocked || !!q.data?.lockout)
  const d = q.data
  const elapsed = Math.floor((Date.now() - fetchedAt) / 1000)
  const remaining = d?.unlocked ? Math.max(0, d.remaining - elapsed) : 0
  const lockout = d?.lockout ? Math.max(0, d.lockout - elapsed) : 0
  // انتهت النافذة على الواجهة ← نسأل الخادم ونخفي المبالغ
  useEffect(() => { if (d?.unlocked && remaining === 0) void refreshAll() }, [d?.unlocked, remaining])
  return { state: d, unlocked: !!d?.unlocked && remaining > 0, remaining, lockout }
}

/** يحدّث الحالة بعد أي استجابة من /me/mfa ويعيد تحميل البيانات المحمية */
export function applyVault(s: VaultState) {
  fetchedAt = Date.now()
  const was = queryClient.getQueryData<VaultState>(KEY)?.unlocked
  queryClient.setQueryData(KEY, s)
  if (was !== s.unlocked) void queryClient.invalidateQueries({ predicate: q => q.queryKey[0] !== 'mfa' })
}

export async function refreshAll() {
  await queryClient.invalidateQueries({ queryKey: KEY })
  await queryClient.invalidateQueries({ predicate: q => q.queryKey[0] !== 'mfa' })
}

export async function vaultCall<T = VaultState>(path: string, json?: unknown, method = 'POST'): Promise<T> {
  const r = await api<{ data: T }>(`/me/mfa${path}`, { method, json: json ?? {} })
  if (r.data && typeof r.data === 'object' && 'unlocked' in (r.data as object)) applyVault(r.data as unknown as VaultState)
  return r.data
}

export const lockVault = () => vaultCall('/lock')

// ——— فتح نافذة التحقق من أي مكان (زر «تحقق من هويتي» أو قيمة مقنّعة) ———
// others = فُتحت من بيانات موظفين آخرين ← بعد التحقق تُعرض خطوة «طلب إذن» إن لم يكن ساري
let open: false | 'self' | 'others' = false
const subs = new Set<() => void>()
const emit = () => subs.forEach(f => f())
export const openVault = (ctx: 'self' | 'others' = 'self') => { open = ctx; emit() }
export const closeVault = () => { open = false; emit() }
export const useVaultDialog = () => useSyncExternalStore(f => { subs.add(f); return () => subs.delete(f) }, () => open)

// عدّاد كل ثانية أثناء الفتح أو الإيقاف
let tick = 0
const tickSubs = new Set<() => void>()
setInterval(() => { if (tickSubs.size) { tick++; tickSubs.forEach(f => f()) } }, 1000)
function useTick(on: boolean) {
  useSyncExternalStore(f => { if (!on) return () => {}; tickSubs.add(f); return () => tickSubs.delete(f) }, () => on ? tick : 0)
}

export const mmss = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`

// ——— WebAuthn (البصمة / Windows Hello) ———
export const b64u = (b: ArrayBuffer | Uint8Array) => { const u = b instanceof Uint8Array ? b : new Uint8Array(b); let s = ''; u.forEach(c => { s += String.fromCharCode(c) }); return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '') }
export const unb64u = (s: string) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - s.length % 4) % 4)), c => c.charCodeAt(0))
export const passkeySupported = () => typeof window !== 'undefined' && !!window.PublicKeyCredential && window.isSecureContext && !!navigator.credentials

interface PasskeyOptions { challenge: string; rp: { name: string }; user: { id: string; name: string; displayName: string }; credentials: { type: 'public-key'; id: string }[]; timeout: number }

/** تسجيل هذا الجهاز (أول مرة) أو التحقق بمفتاح مسجّل — التحقق من التوقيع على الخادم */
export async function passkeyAuth(register: boolean, label?: string) {
  const o = await vaultCall<PasskeyOptions>('/passkey/options')
  if (register) {
    const c = await navigator.credentials.create({ publicKey: {
      challenge: unb64u(o.challenge), rp: o.rp, user: { id: unb64u(o.user.id), name: o.user.name, displayName: o.user.displayName },
      pubKeyCredParams: [{ type: 'public-key', alg: -7 }, { type: 'public-key', alg: -257 }],
      authenticatorSelection: { userVerification: 'required', residentKey: 'discouraged' }, timeout: o.timeout, attestation: 'none',
      excludeCredentials: o.credentials.map(x => ({ type: 'public-key' as const, id: unb64u(x.id) })),
    } }) as PublicKeyCredential | null
    if (!c) throw new Error('cancelled')
    const r = c.response as AuthenticatorAttestationResponse
    const pk = r.getPublicKey?.()
    if (!pk) throw new Error('no-public-key')
    return vaultCall('/passkey/register', { id: c.id, client_data: b64u(r.clientDataJSON), authenticator_data: b64u(r.getAuthenticatorData()), public_key: b64u(pk), alg: r.getPublicKeyAlgorithm(), label })
  }
  const c = await navigator.credentials.get({ publicKey: { challenge: unb64u(o.challenge), allowCredentials: o.credentials.map(x => ({ type: 'public-key' as const, id: unb64u(x.id) })), userVerification: 'required', timeout: o.timeout } }) as PublicKeyCredential | null
  if (!c) throw new Error('cancelled')
  const r = c.response as AuthenticatorAssertionResponse
  return vaultCall('/passkey/verify', { id: c.id, client_data: b64u(r.clientDataJSON), authenticator_data: b64u(r.authenticatorData), signature: b64u(r.signature) })
}

/** اسم افتراضي للجهاز من المتصفح */
export function deviceLabel() {
  const ua = navigator.userAgent
  const os = /Windows/.test(ua) ? 'Windows' : /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iOS' : /Mac/.test(ua) ? 'macOS' : /Linux/.test(ua) ? 'Linux' : ''
  const br = /Edg\//.test(ua) ? 'Edge' : /Chrome\//.test(ua) ? 'Chrome' : /Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : ''
  return [os, br].filter(Boolean).join(' · ') || 'Device'
}

// ——— إعدادات الشركة (M16) ———
export const useVaultSettings = () => useQuery({ queryKey: ['hr', 'vault-settings'], queryFn: () => api<{ data: VaultSettings; defaults: VaultSettings; roles: (Bi & { key: string })[] }>('/hr/vault-settings') })
export const useSaveVaultSettings = () => useMutation({
  mutationFn: (v: VaultSettings) => api<{ data: VaultSettings; defaults: VaultSettings; roles: (Bi & { key: string })[] }>('/hr/vault-settings', { method: 'PUT', json: v }),
  onSuccess: d => { queryClient.setQueryData(['hr', 'vault-settings'], d); void queryClient.invalidateQueries({ queryKey: KEY }) },
})

// ——— موافقة من الموبايل ———
export interface LinkStart { token: string; url: string; expires_in: number }
export interface LinkInfo { status: 'pending' | 'approved' | 'rejected'; device: string | null; ip: string | null; at: string; expires_in: number }
export const linkInfo = (t: string) => api<{ data: LinkInfo }>(`/me/mfa/link/${t}/info`).then(r => r.data)
export const linkDecide = (t: string, approve: boolean) => api<{ data: { status: string } }>(`/me/mfa/link/${t}`, { json: { approve } }).then(r => r.data)
export async function linkPoll(t: string) {
  const r = await api<{ data: { status: 'pending' | 'approved' | 'rejected' | 'expired'; expires_in?: number; state?: VaultState } }>(`/me/mfa/link/${t}`)
  if (r.data.state) applyVault(r.data.state)
  return r.data
}

// ——— إذن الاطلاع على رواتب الآخرين ———
export interface GrantRow {
  kind: 'salary'; id: number; status: string; scope: 'all' | 'department'; department: Bi | null; reason: string; hours: number; expires_at: string | null; created_at: string | null
  user: { id: number; ar: string; en: string; email: string } | null; revoker: Bi | null; revoked_at: string | null; revoke_note: string | null
  steps: { id: number; level: number; role: Bi; status: string; by: Bi | null; note: string | null; at: string | null }[]; can_cancel: boolean
}
export const useMyGrants = (on = true) => useQuery({ queryKey: ['mfa-grants'], enabled: on, queryFn: () => api<{ data: GrantRow[]; needed: boolean; hours: number; departments: (Bi & { id: number })[] }>('/me/salary-access') })
const grantsChanged = () => { void queryClient.invalidateQueries({ queryKey: ['mfa-grants'] }); void queryClient.invalidateQueries({ queryKey: KEY }) }
export const useRequestGrant = () => useMutation({ mutationFn: (v: { scope: 'all' | 'department'; department_id?: number | null; reason: string }) => api('/me/salary-access', { json: v }), onSuccess: grantsChanged })
export const useCancelGrant = () => useMutation({ mutationFn: (id: number) => api(`/me/salary-access/${id}/cancel`, { json: {} }), onSuccess: grantsChanged })
export const useAllGrants = () => useQuery({ queryKey: ['hr', 'salary-grants'], queryFn: () => api<{ data: GrantRow[]; can_revoke: boolean }>('/hr/vault-settings/grants') })
export const useRevokeGrant = () => useMutation({ mutationFn: (v: { id: number; note?: string }) => api<{ data: GrantRow[]; can_revoke: boolean }>(`/hr/vault-settings/grants/${v.id}/revoke`, { json: { note: v.note ?? null } }),
  onSuccess: d => queryClient.setQueryData(['hr', 'salary-grants'], d) })
