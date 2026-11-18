import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'

// الدفعة 21 — NFC وتعدد الفروع (SRS الأنظمة الأساسية، الجزء C)

export type Decision = 'accepted' | 'flagged' | 'rejected' | 'duplicate'
export interface NfEventRow {
  id: number; at: string; received_at: string; decision: Decision; reason: string | null; direction: 'in' | 'out' | null; via: string; review: 'pending' | 'accepted' | 'rejected' | null; review_note: string | null
  identifier: string | null; late_upload: boolean; employee: (Bi & { id: number; code: string }) | null; device: Bi | null; branch: Bi | null; mission: { id: number; ref: string } | null
}
type EventsRes = { data: NfEventRow[]; total: number; page: number; counts: Partial<Record<Decision, number>>; pending: number; branches: (Bi & { id: number })[]; devices: (Bi & { id: number; kind: string })[]; can: { review: boolean; settings: boolean } }
export interface NfcSettings {
  off_branch: 'reject' | 'flag'; mission_access: boolean; duplicate_seconds: number; direction: 'auto' | 'sequence'; signature: 'optional' | 'required'; activation_hours: number; offline_minutes: number
  drift_seconds: number; unknown_alert: number; buddy_seconds: number; buddy_repeat: number; auto_disable: boolean; sticker: boolean; sticker_geo: boolean; card_security: 'uid' | 'memory' | 'secure'
}
export interface CredentialRow { id: number; type: 'nfc' | 'fingerprint' | 'face' | 'phone'; identifier: string; label: string | null; status: 'active' | 'lost' | 'suspended' | 'revoked' | 'replaced'; reason: string | null; valid_from: string | null; valid_to: string | null; created_at: string | null; revoked_at: string | null }
export interface AccessData {
  home: (Bi & { id: number }) | null; credentials: CredentialRow[]
  branches: { id: number; kind: 'permanent' | 'temporary' | 'secondment'; branch: (Bi & { id: number }) | null; from: string | null; to: string | null; note: string | null; live: boolean }[]
  missions: { id: number; ref: string; title: string; from: string | null; to: string | null; branches: Bi[] }[]; locations: (Bi & { id: number })[]; can: { edit: boolean }
}
type Count = Partial<Record<Decision, number>>
export interface NfcReport {
  by_branch: ({ branch: Bi | null } & Count)[]; by_device: ({ device: Bi | null } & Count)[]; rejected: Record<string, number>
  off_branch: { at: string; employee: (Bi & { code: string }) | null; branch: Bi | null; decision: Decision; review: string | null }[]; buddies: (Bi & { n: number })[]
  devices: (Bi & { id: number; status: string; active: boolean; drift: number | null; online: boolean; last_seen: string | null; branch: Bi | null })[]
}

const qs = (o: Record<string, string | undefined>) => { const s = new URLSearchParams(Object.entries(o).filter(([, v]) => v) as [string, string][]).toString(); return s ? `?${s}` : '' }
const refresh = () => queryClient.invalidateQueries({ queryKey: ['hr', 'nfc'] })

export const useNfEvents = (f: Record<string, string | undefined>) => useQuery({ queryKey: ['hr', 'nfc', 'events', f], placeholderData: p => p, queryFn: () => api<EventsRes>(`/hr/nfc/events${qs(f)}`), refetchInterval: 20_000 })
export const useReviewEvent = () => useMutation({ mutationFn: ({ id, accept, note }: { id: number; accept: boolean; note?: string }) => api(`/hr/nfc/events/${id}/review`, { json: { accept, note } }), onSuccess: () => { refresh(); queryClient.invalidateQueries({ queryKey: ['hr', 'attendance'] }) } })
export const useSimulate = () => useMutation({ mutationFn: (v: { device_id: number; card: string; at?: string }) => api<{ data: { decision: Decision; reason: string | null; name?: (Bi & { code: string }) | null; branch: Bi | null; mission?: string } }>('/hr/nfc/simulate', { json: v }).then(r => r.data) })
export const useNfcReport = (from: string, to: string) => useQuery({ queryKey: ['hr', 'nfc', 'report', from, to], queryFn: () => api<{ data: NfcReport }>(`/hr/nfc/report${qs({ from, to })}`).then(r => r.data) })
export const useNfcSettings = () => useQuery({ queryKey: ['hr', 'nfc', 'settings'], queryFn: () => api<{ data: NfcSettings; defaults: NfcSettings; can: { edit: boolean } }>('/hr/nfc/settings') })
export const useSaveNfcSettings = () => useMutation({ mutationFn: (v: NfcSettings) => api<{ data: NfcSettings }>('/hr/nfc/settings', { method: 'PUT', json: v }), onSuccess: refresh })
export const useAccess = (id: number) => useQuery({ queryKey: ['hr', 'nfc', 'access', id], queryFn: () => api<AccessData>(`/hr/employees/${id}/access`) })
const accessChanged = () => { refresh(); queryClient.invalidateQueries({ queryKey: ['hr', 'employee'] }) }
export const useAddCredential = () => useMutation({ mutationFn: ({ id, v }: { id: number; v: Record<string, unknown> }) => api(`/hr/employees/${id}/credentials`, { json: v }), onSuccess: accessChanged })
export const useCredentialStatus = () => useMutation({ mutationFn: ({ id, status, reason }: { id: number; status: string; reason?: string }) => api(`/hr/credentials/${id}/status`, { json: { status, reason } }), onSuccess: accessChanged })
export const useAddBranchAccess = () => useMutation({ mutationFn: ({ id, v }: { id: number; v: Record<string, unknown> }) => api(`/hr/employees/${id}/branches`, { json: v }), onSuccess: accessChanged })
export const useRemoveBranchAccess = () => useMutation({ mutationFn: (id: number) => api(`/hr/branch-access/${id}`, { method: 'DELETE' }), onSuccess: accessChanged })
export const useActivation = () => useMutation({ mutationFn: (id: number) => api<{ code: string; expires_at: string; device_id: string | null }>(`/hr/devices/${id}/activation`, { json: {} }), onSuccess: () => queryClient.invalidateQueries({ queryKey: ['hr', 'devices'] }) })
export const useSticker = (id: number | null) => useQuery({ queryKey: ['hr', 'nfc', 'sticker', id], enabled: !!id, queryFn: () => api<{ url: string; branch: Bi & { code: string }; geo: boolean; enabled: boolean }>(`/hr/branches/${id}/sticker`) })
export const useRenewSticker = () => useMutation({ mutationFn: (id: number) => api<{ url: string }>(`/hr/branches/${id}/sticker`, { json: {} }), onSuccess: (_, id) => queryClient.invalidateQueries({ queryKey: ['hr', 'nfc', 'sticker', id] }) })
export const useStickerInfo = (token: string) => useQuery({ queryKey: ['me', 'sticker', token], retry: false, queryFn: () => api<{ branch: Bi; geo: boolean; employee: boolean }>(`/me/checkin/branch/${token}`) })
export const stickerCheckin = (token: string, pos: { lat: number; lng: number } | null) => api<{ data: { decision: Decision; reason: string | null; direction: string | null; time?: string; branch: Bi } }>(`/me/checkin/branch/${token}`, { json: pos ?? {} })

export const DECISION: Record<Decision, [string, string, 'brand' | 'warn' | 'danger' | 'neutral']> = {
  accepted: ['مقبول', 'Accepted', 'brand'], flagged: ['للمراجعة', 'For review', 'warn'], rejected: ['مرفوض', 'Rejected', 'danger'], duplicate: ['مكرر', 'Duplicate', 'neutral'],
}
export const REASON: Record<string, [string, string]> = {
  home: ['الفرع الأساسي', 'Home branch'], permanent: ['فرع مصرّح', 'Authorised branch'], temporary: ['صلاحية مؤقتة', 'Temporary access'], secondment: ['ندب', 'Secondment'], mission: ['مهمة معتمدة', 'Approved mission'],
  any: ['جهاز بلا فرع', 'Device without branch'], unknown_card: ['بطاقة غير معروفة', 'Unknown card'], lost_card: ['بطاقة مفقودة أو مستبدلة', 'Lost or replaced card'], suspended: ['بطاقة موقوفة', 'Suspended card'],
  inactive: ['خارج الخدمة', 'Not in service'], expired: ['انتهت صلاحية الاعتماد', 'Credential expired'], off_branch: ['خارج الفرع المصرّح', 'Outside authorised branch'], duplicate: ['قراءة مكررة', 'Repeated read'], future: ['وقت مستقبلي', 'Future time'],
}
export const CRED_TYPE: Record<CredentialRow['type'], [string, string]> = { nfc: ['بطاقة NFC', 'NFC card'], fingerprint: ['بصمة', 'Fingerprint'], face: ['وجه', 'Face'], phone: ['هاتف', 'Phone'] }
export const CRED_STATUS: Record<CredentialRow['status'], [string, string, 'brand' | 'warn' | 'danger' | 'neutral']> = {
  active: ['فعّالة', 'Active', 'brand'], lost: ['مفقودة', 'Lost', 'danger'], suspended: ['موقوفة', 'Suspended', 'warn'], revoked: ['ملغاة', 'Revoked', 'neutral'], replaced: ['مستبدلة', 'Replaced', 'neutral'],
}
export const ACCESS_KIND: Record<string, [string, string]> = { permanent: ['مصرّح دائم', 'Permanent'], temporary: ['مؤقت', 'Temporary'], secondment: ['ندب', 'Secondment'] }
