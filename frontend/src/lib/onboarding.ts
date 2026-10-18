import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'
import type { LogRow } from './candidates'

/** M06 — التعيين والمباشرة، معالج إضافة موظف، الاستيراد، و«وثائق للتوقيع» (M14) */
export type EmpStatus = 'onboarding' | 'probation' | 'active' | 'leave' | 'resigning' | 'terminated'
export interface Joiner { id: number; code: string; name: Bi; gender: 'M' | 'F'; title: Bi; dept: Bi; hire_date: string; status: EmpStatus; done: number; total: number; probation_end: string | null }
export interface PendingAppointment { candidate_id: number; name: Bi; job: Bi; decision_no: string; done: number; total: number; waiting: Bi | null }
export interface AssetRow { id: number; tag: string; name: Bi; serial: string | null; value: number; category: Bi; status: 'available' | 'pending_ack' | 'assigned' | 'maintenance'; signed_at: string | null }
export interface Sig { signed_at: string | null; signature: string | null }
export interface OnboardFile extends Joiner {
  manager: Bi | null; city: Bi
  items: (Bi & { done: boolean; auto: 'memo' | 'f22' | 'f20' | 'custody' | 'confirm' | null })[]
  probation_at: number
  probation: { started: string | null; end: string | null; due: boolean; confirmed_at: string | null }
  memo: { sent_at: string | null; text: Bi }
  f22: Sig; f20: Sig & { mother_name: string | null }; conduct: Sig
  assets: AssetRow[]; stock: AssetRow[]; logs: LogRow[]
}

export const useOnboardingList = () => useQuery({ queryKey: ['hr', 'onboarding'], queryFn: () => api<{ joiners: Joiner[]; pending: PendingAppointment[] | null }>('/hr/onboarding') })
export const useOnboardFile = (id: number) => useQuery({ queryKey: ['hr', 'onboard', id], queryFn: () => api<{ data: OnboardFile }>(`/hr/onboarding/${id}`).then(r => r.data) })

const settle = (f: OnboardFile) => {
  queryClient.setQueryData(['hr', 'onboard', f.id], f)
  for (const k of ['onboarding', 'dashboard', 'employee-lookups']) queryClient.invalidateQueries({ queryKey: ['hr', k] })
}
const act = (id: number, path: string, method = 'POST', json?: unknown) => api<{ data: OnboardFile }>(`/hr/onboarding/${id}${path}`, { method, json: json ?? {} }).then(r => r.data)

export const useToggleItem = (id: number) => useMutation({ mutationFn: (index: number) => act(id, '/check', 'POST', { index }), onSuccess: settle })
export const useSendMemo = (id: number) => useMutation({ mutationFn: () => act(id, '/memo'), onSuccess: settle })
export const useIssueAsset = (id: number) => useMutation({ mutationFn: (asset_id: number) => act(id, '/assets', 'POST', { asset_id }), onSuccess: settle })
export const useReturnAsset = (id: number) => useMutation({ mutationFn: (assetId: number) => act(id, `/assets/${assetId}`, 'DELETE'), onSuccess: settle })
export const useConfirm = (id: number) => useMutation({ mutationFn: (note: string) => act(id, '/confirm', 'POST', { note: note || null }), onSuccess: settle })

/* ---------- معالج إضافة موظف ---------- */
export interface Lookups {
  departments: { id: number; code: string; ar: string; en: string; head: Bi | null }[]
  locations: { id: number; code: string; ar: string; en: string }[]
  managers: { id: number; code: string; ar: string; en: string; department_id: number }[]
  assets: AssetRow[]; currencies: string[]
}
export interface NewEmployeeInput {
  name: string; name_en: string; gender: 'M' | 'F'; national_id: string; dob: string; phone: string; email: string
  department_id: number | ''; title_ar: string; title_en: string; location_id: number | ''; manager_id: number | ''; salary: number | ''; currency: string; hire_date: string
}
export const useLookups = (enabled = true) => useQuery({ queryKey: ['hr', 'employee-lookups'], queryFn: () => api<Lookups>('/hr/employees/lookups'), enabled })
export const useCreateEmployee = () => useMutation({
  mutationFn: (v: NewEmployeeInput & { asset_ids: number[] }) => api<{ data: { id: number; code: string } }>('/hr/employees', { method: 'POST', json: { ...v, manager_id: v.manager_id || null } }).then(r => r.data),
  onSuccess: () => { for (const k of ['onboarding', 'dashboard', 'employee-lookups']) queryClient.invalidateQueries({ queryKey: ['hr', k] }) },
})

/* ---------- الاستيراد من Excel ---------- */
export interface ImportRow { row: number; raw: Record<string, string>; values: Record<string, unknown>; errors: Record<string, string> }
const upload = (file: File, extra: Record<string, string> = {}) => {
  const fd = new FormData(); fd.append('file', file)
  for (const [k, v] of Object.entries(extra)) fd.append(k, v)
  return fd
}
export const usePreviewImport = () => useMutation({ mutationFn: (file: File) => api<{ rows: ImportRow[]; ok: number; errors: number }>('/hr/employees/import/preview', { method: 'POST', body: upload(file) }) })
export const useCommitImport = () => useMutation({
  mutationFn: (v: { file: File; invite: boolean }) => api<{ created: number; codes: string[] }>('/hr/employees/import', { method: 'POST', body: upload(v.file, { invite: v.invite ? '1' : '0' }) }),
  onSuccess: () => { for (const k of ['onboarding', 'dashboard', 'employee-lookups']) queryClient.invalidateQueries({ queryKey: ['hr', k] }) },
})
export const IMPORT_TEMPLATE = '/api/hr/employees/import/template'

/* ---------- «وثائق للتوقيع» ---------- */
export interface MyDocs {
  employee: { name: Bi; code: string; national_id: string | null; title: Bi; status: EmpStatus }
  undertakings: { f22: Sig; f20: Sig & { mother_name: string | null }; conduct: Sig } | null
  pending: AssetRow[]; signed: AssetRow[]
}
export type DocKey = 'f22' | 'f20' | 'conduct' | 'asset'
export const useMyDocs = () => useQuery({ queryKey: ['me', 'documents'], queryFn: () => api<MyDocs>('/me/documents') })
export const useSignDoc = () => useMutation({
  mutationFn: (v: { doc: DocKey; signature: string; asset_id?: number; mother_name?: string }) => api<MyDocs>('/me/documents/sign', { method: 'POST', json: v }),
  onSuccess: d => { queryClient.setQueryData(['me', 'documents'], d); queryClient.invalidateQueries({ queryKey: ['notices'] }) },
})
/** عدد الوثائق بانتظار التوقيع (لشارة القائمة) */
export const pendingDocs = (d?: MyDocs) => !d ? 0 : d.pending.length + (d.undertakings ? (['f22', 'f20', 'conduct'] as const).filter(k => !d.undertakings![k].signed_at).length : 0)
