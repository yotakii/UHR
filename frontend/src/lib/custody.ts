import { useMutation, useQuery } from '@tanstack/react-query'
import { api, xsrf } from './api'
import { queryClient } from './session'
import type { Bi } from './types'

/** M10 — العهد: السجل، التسليم والاسترجاع، الصيانة والاستبعاد، سجل الحركة، الاستيراد (D-13) */
export type AssetStatus = 'available' | 'pending_ack' | 'assigned' | 'maintenance' | 'retired'
export type Condition = 'good' | 'fair' | 'damaged'
export interface AssetRow {
  id: number; tag: string; name: Bi; name_ar: string; name_en: string; category: Bi & { id: number; code: string }; serial: string | null; value: number
  condition: Condition; purchased_at: string | null; notes: string | null; status: AssetStatus
  holder: (Bi & { id: number; code: string }) | null; assigned_at: string | null; signed_at: string | null; returned_at: string | null
}
export interface Movement { action: string; at: string; employee: (Bi & { id: number }) | null; condition: Condition | null; note: string | null; by: Bi | null }
export interface CustodyPage {
  summary: { issued: number; issued_value: number; pending: number; available: number; maintenance: number; retired: number; total: number }
  data: AssetRow[]; categories: (Bi & { id: number; code: string })[]; employees: (Bi & { id: number; code: string })[]
  can: { create: boolean; edit: boolean; export: boolean }
}
export interface AssetInput { name_ar: string; name_en: string; asset_category_id: number | ''; serial: string; value: number | ''; condition: Condition; purchased_at: string; notes: string }

const qs = (f: Record<string, string>) => new URLSearchParams(Object.entries(f).filter(([, v]) => v && v !== 'all')).toString()
export const useCustody = (f: { q: string; status: string; category: string }) => useQuery({ queryKey: ['hr', 'custody', f], queryFn: () => api<CustodyPage>(`/hr/custody?${qs(f)}`), placeholderData: p => p })
export const useAssetHistory = (id: number | null) => useQuery({ queryKey: ['hr', 'custody', 'asset', id], enabled: !!id, queryFn: () => api<{ data: AssetRow; history: Movement[] }>(`/hr/custody/${id}`) })
const refresh = () => { queryClient.invalidateQueries({ queryKey: ['hr', 'custody'] }); queryClient.invalidateQueries({ queryKey: ['hr', 'employee'] }) }
export const useSaveAsset = (id: number | null) => useMutation({
  mutationFn: (v: AssetInput) => api<{ data: AssetRow }>(id ? `/hr/custody/${id}` : '/hr/custody', { method: id ? 'PUT' : 'POST', json: { ...v, purchased_at: v.purchased_at || null } }).then(r => r.data), onSuccess: refresh,
})
export const useAssetAction = () => useMutation({
  mutationFn: (v: { id: number; action: 'issue' | 'return' | 'status'; body: Record<string, unknown> }) => api<{ data: AssetRow }>(`/hr/custody/${v.id}/${v.action}`, { json: v.body }).then(r => r.data), onSuccess: refresh,
})
export async function downloadCustody(f: { q: string; status: string; category: string }) {
  const r = await fetch(`/api/hr/custody/export?${qs(f)}`, { credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': xsrf() } })
  if (!r.ok) throw new Error(String(r.status))
  const a = document.createElement('a'); a.href = URL.createObjectURL(await r.blob()); a.download = 'UHR-custody.xlsx'; a.click(); URL.revokeObjectURL(a.href)
}

export interface AssetImportRow { row: number; values: Record<string, string | number | null>; errors: Record<string, string> }
const upload = (file: File, extra: Record<string, string> = {}) => { const fd = new FormData(); fd.append('file', file); for (const [k, v] of Object.entries(extra)) fd.append(k, v); return fd }
export const usePreviewAssets = () => useMutation({ mutationFn: (file: File) => api<{ rows: AssetImportRow[]; ok: number; errors: number }>('/hr/custody/import/preview', { method: 'POST', body: upload(file) }) })
export const useImportAssets = () => useMutation({
  mutationFn: (v: { file: File; paper: boolean }) => api<{ created: number; failed: number; errors: AssetImportRow[] }>('/hr/custody/import', { method: 'POST', body: upload(v.file, { paper_signed: v.paper ? '1' : '0' }) }),
  onSuccess: refresh,
})
export const ASSET_TEMPLATE = '/api/hr/custody/import/template'
