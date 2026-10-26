import { useEffect, useSyncExternalStore } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'

// حماية الرواتب بخطوتين (SRS §4.4): الحالة من الخادم (/me/mfa)؛ الخادم لا يُرجع أي مبلغ والرواتب مقفلة،
// فعند الفتح أو القفل تُعاد كل الاستعلامات لتصل المبالغ أو تُخفى.

export type VaultMethod = 'phone' | 'passkey' | 'app'
export interface VaultState {
  unlocked: boolean; remaining: number; lockout: number; enrolled: boolean; backup_left: number
  passkeys: { id: number; label: string | null; created_at: string | null; last_used_at: string | null }[]
  last_ok: { at: string; method: string } | null
  methods: Record<VaultMethod, boolean>; unlock_minutes: number; otp_minutes: number
  otp: { expires_in: number; to: string | null } | null; destination: string | null
}
export interface VaultSettings { unlock_minutes: number; max_attempts: number; lockout_seconds: number; otp_minutes: number; backup_count: number; audit_keep: number; methods: Record<VaultMethod, boolean> }

const KEY = ['mfa'] as const
let fetchedAt = 0

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
let open = false
const subs = new Set<() => void>()
const emit = () => subs.forEach(f => f())
export const openVault = () => { open = true; emit() }
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
export const useVaultSettings = () => useQuery({ queryKey: ['hr', 'vault-settings'], queryFn: () => api<{ data: VaultSettings; defaults: VaultSettings }>('/hr/vault-settings') })
export const useSaveVaultSettings = () => useMutation({
  mutationFn: (v: VaultSettings) => api<{ data: VaultSettings; defaults: VaultSettings }>('/hr/vault-settings', { method: 'PUT', json: v }),
  onSuccess: d => { queryClient.setQueryData(['hr', 'vault-settings'], d); void queryClient.invalidateQueries({ queryKey: KEY }) },
})
