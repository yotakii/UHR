import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'
import type { LookupItem } from './codes'

// الدفعة 19 — العقود والتجديد وتقييم فترة التجربة (SRS الأنظمة الأساسية، الجزء D)

export type ContractStatus = 'draft' | 'pending_signatures' | 'signed' | 'active' | 'renewed' | 'ended' | 'expired_no_action' | 'terminated' | 'cancelled'
export type Person = Bi & { id: number; code: string }
export interface ContractRow {
  id: number; ref_no: string; status: ContractStatus; source: string; starts_on: string; ends_on: string | null; probation_months: number; probation_ends_on: string | null
  exception: boolean; exception_reason: string | null; type: (Bi & { id: number; code: string }) | null; employee: Person
  signatures: { role: string; ar: string; en: string; user_id: number | null; at: string | null; can: boolean; paper?: boolean }[]
  copies: { key: string; ar: string; en: string; status: 'pending' | 'done'; at: string | null }[]
  signed_file_id: number | null; previous_id: number | null; case_id: number | null; note: string | null; signed_at: string | null; activated_at: string | null; body?: string
}
export type ContractFull = ContractRow & { body: string | null; body_en: string; annexes: { id: number; ref_no: string; kind: string; effective_on: string; text: string; status: string }[]; history: ContractRow[] }
export type CaseStatus = 'manager' | 'signatures' | 'hr_review' | 'decision' | 'exception' | 'preparing' | 'closed'
export type Decision = 'renew' | 'convert' | 'not_renew' | 'confirm' | 'terminate' | 'extend'
export interface Scale { key: string; ar: string; en: string; score: number }
export interface CaseRow {
  id: number; kind: 'renewal' | 'probation'; ref_no: string; status: CaseStatus; outcome: string | null; due_on: string; left: number; manager_due: string | null; hr_due: string | null
  late: boolean; lapsed: boolean; employee: Person & { title: Bi; dept: Bi | null }; contract: ContractRow | null; evaluator: (Bi & { id: number }) | null
  suggestion: { decision: Decision | 'decide'; type_code?: string; months?: number; rule: string; years?: number; grade?: string; total?: number } | null
  decision: { decision: Decision; reason: string | null; months?: number; contract_type_id?: number; exception?: boolean } | null
  total: number | null; grade: string | null; exception: boolean; new_contract_id: number | null; resignation_id: number | null
  can: { evaluate: boolean; sign: boolean; review: boolean; decide: boolean } | null
}
export interface CaseFull extends CaseRow {
  summary: { years: number; hire_date: string | null; review: { score: number; performance: number | null } | null; penalties: number; contracts: number } | null
  evaluation: { items?: Record<string, string>; justify?: Record<string, string>; total?: number; grade?: string; rating?: string; recommendation: string; notes?: string; weak?: string[]; at: string } | null
  signatures: { role: 'employee' | 'dept_head'; at: string | null; user: string | null; note: string | null }[]
  review: { checks?: Record<string, boolean>; note?: string | null; returned?: string; at: string } | null
  new_contract: ContractRow | null; letters: string[]
  form: { items?: (Bi & { key: string })[]; scale: Scale[]; justify_other_than?: string }; review_checks: (Bi & { key: string })[]; decisions: Decision[]; extension_max: number
}
export type RadarRow = { kind: 'renewal' | 'probation'; contract: { id: number; ref_no: string; type: (Bi & { code: string }) | null }; employee: Person & { dept: Bi | null }
  date: string; left: number; bucket: number; overdue: boolean; case: { id: number; ref_no: string; status: CaseStatus } | null }
export type ContractsCfg = Record<string, unknown> & { radar_days: number[]; default_months: number; extension_max_months: number; allow_extension: boolean; sign_mode: 'electronic' | 'upload' | 'both'; scale: Scale[] }
type Overview = { radar: { windows: number[]; rows: RadarRow[] }; counts: { kind: string; status: string; n: number }[]; departments: (Bi & { id: number })[]; branches: (Bi & { id: number })[]
  types: LookupItem[]; categories: LookupItem[]; employees: Person[]; settings: ContractsCfg; can: { create: boolean; edit: boolean; decide: boolean; settings: boolean; export: boolean } }

const changed = () => { void queryClient.invalidateQueries({ queryKey: ['contracts'] }); void queryClient.invalidateQueries({ queryKey: ['me', 'contracts'] }) }
const qs = (o: Record<string, string | undefined>) => { const s = new URLSearchParams(Object.entries(o).filter(([, v]) => v) as [string, string][]).toString(); return s ? `?${s}` : '' }

export const useContracts = (f: Record<string, string | undefined>) => useQuery({ queryKey: ['contracts', 'overview', f], queryFn: () => api<Overview>(`/hr/contracts${qs(f)}`), placeholderData: p => p })
export const useCases = (f: Record<string, string | undefined>) => useQuery({ queryKey: ['contracts', 'cases', f], queryFn: () => api<{ data: CaseRow[] }>(`/hr/contracts/cases${qs(f)}`).then(r => r.data), placeholderData: p => p })
export const useCase = (id: number | null) => useQuery({ queryKey: ['contracts', 'case', id], enabled: !!id, queryFn: () => api<{ data: CaseFull }>(`/hr/contracts/cases/${id}`).then(r => r.data) })
export const useContractList = (f: Record<string, string | undefined>) => useQuery({ queryKey: ['contracts', 'list', f], queryFn: () => api<{ data: ContractRow[] }>(`/hr/contracts/list${qs(f)}`).then(r => r.data), placeholderData: p => p })
export const useContract = (id: number | null) => useQuery({ queryKey: ['contracts', 'one', id], enabled: !!id, queryFn: () => api<{ data: ContractFull }>(`/hr/contracts/${id}`).then(r => r.data) })
export const useContractReports = (from: string, to: string) => useQuery({ queryKey: ['contracts', 'reports', from, to], queryFn: () => api<{ from: string; to: string; ending: ContractRow[]; renewal: { dept: string; renewed: number; not_renewed: number }[]
  probation: { confirmed: number; terminated: number; extended: number; open: number }; late: CaseRow[]; exceptions: CaseRow[] }>(`/hr/contracts/reports${qs({ from, to })}`) })

const caseMut = (path: string) => ({ id, ...v }: { id: number } & Record<string, unknown>) => api<{ data: CaseFull }>(`/hr/contracts/cases/${id}/${path}`, { json: v }).then(r => r.data)
export const useOpenCase = () => useMutation({ mutationFn: (v: { contract_id: number; kind: 'renewal' | 'probation' }) => api<{ data: CaseFull }>('/hr/contracts/cases', { json: v }).then(r => r.data), onSuccess: changed })
export const useEvaluate = (me = false) => useMutation({ mutationFn: ({ id, ...v }: { id: number } & Record<string, unknown>) => me ? api(`/me/contracts/cases/${id}/evaluate`, { json: v }) : caseMut('evaluate')({ id, ...v }), onSuccess: changed })
export const useReview = () => useMutation({ mutationFn: caseMut('review'), onSuccess: changed })
export const useDecide = () => useMutation({ mutationFn: caseMut('decide'), onSuccess: changed })
export const useBulk = () => useMutation({ mutationFn: (v: { ids: number[]; decision: Decision }) => api<{ done: string[]; skipped: string[] }>('/hr/contracts/cases/bulk', { json: v }), onSuccess: changed })
export const useCreateContract = () => useMutation({ mutationFn: (v: Record<string, unknown>) => api<{ data: ContractRow }>('/hr/contracts', { json: v }).then(r => r.data), onSuccess: changed })
const cMut = (path: string) => ({ id, ...v }: { id: number } & Record<string, unknown>) => api<{ data: ContractRow }>(`/hr/contracts/${id}/${path}`, { json: v })
export const useSignContract = () => useMutation({ mutationFn: cMut('sign'), onSuccess: changed })
export const useCopy = () => useMutation({ mutationFn: cMut('copies'), onSuccess: changed })
export const useCancelContract = () => useMutation({ mutationFn: cMut('cancel'), onSuccess: changed })
export const useAnnex = () => useMutation({ mutationFn: cMut('annexes'), onSuccess: changed })
export const uploadSigned = (id: number, file: File) => { const f = new FormData(); f.append('file', file); return api(`/hr/contracts/${id}/upload`, { method: 'POST', body: f }).then(r => { changed(); return r }) }
export const useContractSettings = () => useQuery({ queryKey: ['contracts', 'settings'], queryFn: () => api<{ settings: ContractsCfg; defaults: ContractsCfg; types: LookupItem[]; causes: (Bi & { key: string })[]
  signers: (Bi & { key: string })[]; copies: (Bi & { key: string })[] }>('/hr/contracts/settings') })
export const useSaveContractSettings = () => useMutation({ mutationFn: (v: ContractsCfg) => api('/hr/contracts/settings', { method: 'PUT', json: v }), onSuccess: changed })

export type MyContracts = { contracts: ContractRow[]; evaluations: CaseFull[]; team: CaseFull[] }
export const useMyContracts = () => useQuery({ queryKey: ['me', 'contracts'], queryFn: () => api<MyContracts>('/me/contracts') })
export const useSignMine = () => useMutation({ mutationFn: ({ id, signature }: { id: number; signature: string }) => api<MyContracts>(`/me/contracts/${id}/sign`, { json: { signature } }), onSuccess: d => queryClient.setQueryData(['me', 'contracts'], d) })
export const useSignEval = () => useMutation({ mutationFn: ({ id, note }: { id: number; note?: string }) => api<MyContracts>(`/me/contracts/cases/${id}/sign`, { json: { note } }), onSuccess: d => queryClient.setQueryData(['me', 'contracts'], d) })
export const usePrintData = (me: boolean, kind: string, id: number, which?: string) => useQuery({ queryKey: ['contracts', 'print', me, kind, id, which],
  queryFn: () => api<{ contract?: ContractRow; body?: string; signatures?: { role: string; at: string | null; image: string | null; name: string | null }[]; case?: CaseFull; which?: string; text?: string
    annex?: { ref_no: string; kind: string; effective_on: string; text: string } }>(`${me ? '/me/contracts' : '/hr/contracts'}/print/${kind}/${id}${which ? `?which=${which}` : ''}`) })

export const STATUS: Record<ContractStatus, [string, string, 'brand' | 'brass' | 'info' | 'danger' | 'neutral']> = {
  draft: ['مسودة', 'Draft', 'neutral'], pending_signatures: ['بانتظار التواقيع', 'Awaiting signatures', 'brass'], signed: ['موقّع — بانتظار البدء', 'Signed — not started', 'info'],
  active: ['نافذ', 'In force', 'brand'], renewed: ['منتهٍ — مجدَّد', 'Ended — renewed', 'neutral'], ended: ['منتهٍ', 'Ended', 'neutral'], expired_no_action: ['منتهٍ دون إجراء', 'Ended without action', 'danger'],
  terminated: ['مُنهى', 'Terminated', 'danger'], cancelled: ['ملغى', 'Cancelled', 'neutral'],
}
export const CASE_STATUS: Record<CaseStatus, [string, string, 'brand' | 'brass' | 'info' | 'danger' | 'neutral']> = {
  manager: ['بانتظار تقييم المدير', 'Awaiting manager', 'brass'], signatures: ['بانتظار التواقيع', 'Awaiting signatures', 'brass'], hr_review: ['مراجعة الموارد البشرية', 'HR review', 'info'],
  decision: ['بانتظار القرار', 'Awaiting decision', 'info'], exception: ['استثناء بانتظار الاعتماد', 'Exception awaiting approval', 'danger'], preparing: ['إعداد العقد الجديد', 'Preparing the new contract', 'brand'], closed: ['مغلقة', 'Closed', 'neutral'],
}
export const OUTCOME: Record<string, [string, string]> = { renewed: ['جُدِّد', 'Renewed'], converted: ['حُوِّل لغير محدد المدة', 'Converted to open-ended'], not_renewed: ['لم يُجدَّد', 'Not renewed'],
  confirmed: ['ثُبِّت', 'Confirmed'], terminated: ['أُنهي في التجربة', 'Ended during probation'], extended: ['مُدِّدت التجربة', 'Probation extended'] }
export const DECISION: Record<Decision | 'decide', [string, string]> = { renew: ['تجديد', 'Renew'], convert: ['تحويل إلى غير محدد المدة', 'Convert to open-ended'], not_renew: ['عدم التجديد', 'Do not renew'],
  confirm: ['تثبيت', 'Confirm'], terminate: ['إنهاء العقد', 'End the contract'], extend: ['تمديد فترة التجربة', 'Extend probation'], decide: ['يحتاج قراراً', 'Needs a decision'] }
export const RECOMMEND: Record<string, [string, string]> = { renew: ['تجديد', 'Renew'], permanent: ['تثبيت (غير محدد المدة)', 'Make permanent'], not_renew: ['عدم التجديد', 'Do not renew'], confirm: ['تثبيت', 'Confirm'], not_confirm: ['عدم تثبيت', 'Do not confirm'] }
export const RULES: Record<string, [string, string]> = { 'POL-CR-04': ['تقييم جيد وتوصية تجديد ← عقد محدد المدة', 'Good rating + renew recommendation → fixed-term'],
  'POL-CR-05': ['توصية بالتثبيت ← عقد غير محدد المدة', 'Permanent recommendation → open-ended'], 'BR-CR-06': ['أتم السنوات التعاقدية المتواصلة المحددة ← غير محدد المدة', 'Reached the continuous contract years → open-ended'],
  'BR-CR-07': ['التقدير أقل من الحد الأدنى للتجديد', 'Rating below the renewal minimum'], recommendation: ['توصية المدير', "Manager's recommendation"], bands: ['شرائح نتيجة تقييم التجربة', 'Probation result bands'] }
