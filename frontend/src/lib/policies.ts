import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'

// مكتبة السياسات (D-26): وثائق بإصدارات، نص فقرات للبحث، ربط الفقرة بالإعداد الذي يطبّقها، وسجل الاطلاع

export type PolicyStatus = 'draft' | 'published' | 'archived'
export interface PolicyRule extends Bi { key: string; path: string }
export interface PolicyDoc extends Bi {
  id: number; code: string | null; category: string; status: PolicyStatus; archived_at: string | null
  current: { id: number; number: number; issue: string | null; effective_from: string | null; published_at: string | null } | null
  read?: boolean; reads?: { n: number; of: number } | null; has_draft?: boolean
}
export interface PolicyVersion {
  id: number; number: number; issue: string | null; status: PolicyStatus; effective_from: string | null; note: string | null
  file: { id: number; name: string | null; size: number | null } | null; text_missing: boolean; published_at: string | null; archived_at: string | null; publisher: Bi | null
}
export interface Paragraph { id: number; n: number; text: string; rule: PolicyRule | null; rule_note: string | null }
export interface PolicyFull extends PolicyDoc {
  versions: PolicyVersion[]
  version: (PolicyVersion & { text: string | null; paragraphs: Paragraph[]; reads: { n: number; of: number } | null; read_at: string | null }) | null
}
export interface SearchHit { id: number; n: number; text: string; rule: PolicyRule | null; version_id: number; number: number; version_status: PolicyStatus; document: Bi & { id: number; code: string | null } }
type Meta = { categories: (Bi & { key: string })[]; rules: PolicyRule[]; can: { create: boolean; edit: boolean; archive: boolean } }

export const usePolicies = () => useQuery({ queryKey: ['policies'], queryFn: () => api<{ data: PolicyDoc[] } & Meta>('/policies') })
export const usePolicy = (id: number | null, version?: number) => useQuery({ queryKey: ['policies', id, version ?? 0], enabled: !!id,
  queryFn: () => api<{ data: PolicyFull } & Meta>(`/policies/${id}${version ? `?version=${version}` : ''}`) })
export const usePolicySearch = (q: string, archived: boolean) => useQuery({ queryKey: ['policies', 'search', q, archived], enabled: q.trim().length >= 2, placeholderData: p => p,
  queryFn: () => api<{ data: SearchHit[] }>(`/policies/search?q=${encodeURIComponent(q.trim())}${archived ? '&archived=1' : ''}`) })
export const usePolicyReads = (versionId: number | null) => useQuery({ queryKey: ['policies', 'reads', versionId], enabled: !!versionId,
  queryFn: () => api<{ data: (Bi & { id: number; code: string | null; read_at: string | null })[] }>(`/policies/versions/${versionId}/reads`) })

export interface DocInput { code: string; title_ar: string; title_en: string; category: string }
export interface VersionInput { issue: string; effective_from: string; note: string; text?: string | null; file?: File | null }
const form = (v: Record<string, unknown>) => {
  const f = new FormData()
  for (const [k, x] of Object.entries(v)) if (x !== null && x !== undefined && x !== '') f.append(k, x instanceof File ? x : String(x))
  return f
}
const changed = <T,>(d: T): T => { void queryClient.invalidateQueries({ queryKey: ['policies'] }); return d }

export const useCreatePolicy = () => useMutation({ mutationFn: (v: DocInput & VersionInput) => api<{ data: PolicyFull }>('/policies', { method: 'POST', body: form({ ...v }) }).then(changed) })
export const useUpdatePolicy = () => useMutation({ mutationFn: ({ id, v }: { id: number; v: DocInput }) => api<{ data: PolicyFull }>(`/policies/${id}`, { method: 'PUT', json: v }).then(changed) })
export const useNewVersion = () => useMutation({ mutationFn: ({ id, v }: { id: number; v: VersionInput }) => api<{ data: PolicyFull }>(`/policies/${id}/versions`, { method: 'POST', body: form({ ...v }) }).then(changed) })
export const useUpdateVersion = () => useMutation({ mutationFn: ({ id, v }: { id: number; v: VersionInput }) => api<{ data: PolicyFull }>(`/policies/versions/${id}`, { method: 'POST', body: form({ ...v }) }).then(changed) })
export const usePublishVersion = () => useMutation({ mutationFn: (id: number) => api<{ data: PolicyFull }>(`/policies/versions/${id}/publish`, { json: {} }).then(changed) })
export const useDeleteVersion = () => useMutation({ mutationFn: (id: number) => api<{ data: PolicyFull | null }>(`/policies/versions/${id}`, { method: 'DELETE' }).then(changed) })
export const useArchivePolicy = () => useMutation({ mutationFn: ({ id, restore }: { id: number; restore: boolean }) => api<{ data: PolicyFull }>(`/policies/${id}/archive`, { json: { restore } }).then(changed) })
export const useLinkParagraph = () => useMutation({ mutationFn: ({ id, rule_key, rule_note }: { id: number; rule_key: string | null; rule_note?: string | null }) => api<{ data: Paragraph }>(`/policies/paragraphs/${id}`, { method: 'PUT', json: { rule_key, rule_note: rule_note ?? null } }),
  onSuccess: () => queryClient.invalidateQueries({ queryKey: ['policies'] }) })

/** تمييز كلمات البحث في الفقرة (مقارنة مطبَّعة كالخادم) */
export const normAr = (s: string) => s.replace(/[ً-ٰٟـ]/g, '').replace(/[أإآٱ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').replace(/ؤ/g, 'و').replace(/ئ/g, 'ي').toLowerCase()
