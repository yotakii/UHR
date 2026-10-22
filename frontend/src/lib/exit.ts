import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'
import type { Step } from './requests'

/** M11 — الاستقالة وإنهاء الخدمة حسب HR.01-03 (SRS §10.8) */
export type ExitStatus = 'pending_manager' | 'pending_hr' | 'pending_exec' | 'clearance' | 'closed' | 'rejected' | 'cancelled'
export interface Item { key: string; ar: string; en: string }
export interface SectionDef extends Item { role: string; items: (Item & { auto?: 'handover' | 'assets' | 'leave'; amount?: boolean })[] }
export interface ExitLists { reasons: Item[]; causes: Item[]; dismissal_reasons: Item[]; questions: Item[]; sections: SectionDef[] }
export interface ExitPolicy { notice_days: number; decision_days: number; notice_pay_months: number; death_grant_months: number; insurance_days: number }
export interface Signed { by: string; by_en: string; at: string }
export interface Task { task: string; status: 'done' | 'in_progress' | 'postponed'; files?: string | null; type?: 'paper' | 'electronic' | null; remaining?: string | null; location?: string | null }
export interface ClearanceItem extends Item {
  auto: 'handover' | 'assets' | 'leave' | null; amount: boolean; state: 'done' | 'none' | 'missing' | null; note: string | null; value: number | null
  pending?: { id: number; tag: string; name: Bi; value: number; status: string }[]; balance?: { entitlement: number; used: number; remaining: number } | null
}
export interface ClearanceSection extends Item { role: string; items: ClearanceItem[]; signed: Signed | null; ready: boolean; can_sign: boolean }
export interface Settlement {
  daily: number; days: number; pro_rata: number; leave_days: number; leave_pay: number; death_grant: number; assets: number; advances: number; finance: number
  notice_short: boolean; notice_pay: number; total: number; currency: 'USD' | 'SYP'
}
export interface ExitCard {
  id: number; ref_no: string | null; kind: 'resignation' | 'termination'; status: ExitStatus
  employee: { id: number; code: string; name: Bi; title: Bi; gender: 'M' | 'F'; dept: Bi | null; hire_date: string | null } | null
  last_day: string; notice_until: string | null; notice_waived: boolean; notice_short: boolean; reasons: Bi[]
  withdrawal: { text: string; at: string; status: 'pending' | 'accepted' | 'rejected'; note?: string; decided_by?: string; decided_by_en?: string } | null
  created_at: string; decided_at: string | null; closed_at: string | null; step_id: number | null; can_act: boolean; overdue: boolean
}
export interface ExitFull extends ExitCard {
  text: string | null; survey: Record<string, string> | null
  hr_interview: { reasons: string[]; notes: string; recommendation: 'accept' | 'retain'; at: string; by: string; by_en: string } | null
  termination: { cause: string; reasons: string[]; other: string | null } | null; rehire: boolean | null; rehire_reason: string | null; waive_note: string | null
  requested_by: Bi | null; manager: Bi | null; steps: Step[]
  handover: { tasks: Task[]; receiver: (Bi & { id: number }) | null; employee: Signed | null; receiver_signed: Signed | null; manager: Signed | null; hr: Signed | null; done: boolean } | null
  clearance: ClearanceSection[] | null; clearance_no: string | null; clearance_issued_at: string | null; service_cert_no: string | null; service_cert_at: string | null
  exit: { answers: Record<string, string>; rating: number; would_return: boolean; noncompete: boolean; rights: boolean; notes: string | null; at: string; by: string; by_en: string } | null; exit_done: boolean
  followups: { labor?: { done: boolean; at?: string | null }; insurance?: { done: boolean; due?: string; at?: string | null }; informed?: { done: boolean } } | null
  successor: (Bi & { id: number }) | null; needs_successor: boolean; account_moved_at: string | null
  notices: { id: number; to: { name?: string; email?: string }; consent: boolean; status: 'prepared' | 'sent' | 'blocked'; sent_at: string | null }[]
  settlement: Settlement | null
  can: { interview: boolean; waive: boolean; withdrawal: boolean; handover: boolean; exit: boolean; close: boolean; successor: boolean; certificate: boolean; print: boolean }
}
export interface OffboardingPage {
  data: ExitCard[]; counts: { pending: number; clearance: number; followups: number }; lists: ExitLists; policy: ExitPolicy; reject_min: number
  employees: (Bi & { id: number; code: string })[]; users: (Bi & { id: number; code: string })[]; can: { terminate: boolean; settings: boolean; report: boolean }
}
export interface MyExit {
  data: ExitFull | null; receiving: ExitFull[]; team: ExitFull[]; lists: Pick<ExitLists, 'reasons' | 'questions'>; policy: ExitPolicy
  colleagues: (Bi & { id: number; code: string; same_dept: boolean })[]; company: Bi
}

const refresh = () => { for (const k of [['hr', 'offboarding'], ['me', 'exit'], ['me', 'approvals'], ['hr', 'dashboard'], ['hr', 'custody']]) queryClient.invalidateQueries({ queryKey: k }) }
const setOne = (r: { data: ExitFull }) => { queryClient.setQueryData(['hr', 'offboarding', 'one', r.data.id], r.data); refresh(); return r.data }

export const useOffboarding = (status: string, q: string) => useQuery({ queryKey: ['hr', 'offboarding', 'list', status, q], queryFn: () => api<OffboardingPage>(`/hr/offboarding?status=${status}${q ? `&q=${encodeURIComponent(q)}` : ''}`), placeholderData: p => p })
export const useExit = (id: number | null) => useQuery({ queryKey: ['hr', 'offboarding', 'one', id], enabled: !!id, queryFn: () => api<{ data: ExitFull }>(`/hr/offboarding/${id}`).then(r => r.data) })
export const useExitAction = () => useMutation({
  mutationFn: (v: { id: number; path: string; body?: Record<string, unknown>; method?: 'POST' | 'PUT' }) => api<{ data: ExitFull; password?: string | null; roles?: string[] }>(`/hr/offboarding/${v.id}/${v.path}`, { method: v.method ?? 'POST', json: v.body ?? {} })
    .then(r => { setOne(r); return r }),
})
export const useTerminate = () => useMutation({ mutationFn: (v: Record<string, unknown>) => api<{ data: ExitFull }>('/hr/offboarding', { json: v }).then(setOne) })
export const useExitReport = (month: string, on: boolean) => useQuery({ queryKey: ['hr', 'offboarding', 'report', month], enabled: on, queryFn: () => api<{ data: ExitReport }>(`/hr/offboarding/report?month=${month}`).then(r => r.data) })
export interface ExitReport { count: number; terminations: number; reasons: (Bi & { key: string; count: number })[]; departments: (Bi & { count: number; reasons: Bi[] })[]; rating: number; would_return: number; rehire: number }
export const useExitSettings = () => useQuery({ queryKey: ['hr', 'offboarding', 'settings'], queryFn: () => api<{ data: ExitLists; defaults: ExitLists }>('/hr/offboarding/settings') })
export const useSaveExitSettings = () => useMutation({ mutationFn: (v: ExitLists) => api<{ data: ExitLists; defaults: ExitLists }>('/hr/offboarding/settings', { method: 'PUT', json: v }), onSuccess: r => queryClient.setQueryData(['hr', 'offboarding', 'settings'], r) })

// بوابة الموظف
export const useMyExit = () => useQuery({ queryKey: ['me', 'exit'], queryFn: () => api<MyExit>('/me/exit') })
const meDone = (r: MyExit) => { queryClient.setQueryData(['me', 'exit'], r); refresh(); return r }
export const useResign = () => useMutation({ mutationFn: (v: { last_day: string; reasons: string[]; text: string; survey: Record<string, string> }) => api<MyExit>('/me/exit', { json: v }).then(meDone) })
export const useMyExitAction = () => useMutation({
  mutationFn: (v: { id: number; path: string; body?: Record<string, unknown>; method?: 'POST' | 'PUT' }) => api<MyExit>(`/me/exit/${v.id}/${v.path}`, { method: v.method ?? 'POST', json: v.body ?? {} }).then(meDone),
})

/** حالة ← شارة */
export const EXIT_TONE: Record<ExitStatus, 'info' | 'brass' | 'warn' | 'danger' | 'neutral' | 'brand'> = {
  pending_manager: 'info', pending_hr: 'brass', pending_exec: 'brass', clearance: 'warn', closed: 'danger', rejected: 'neutral', cancelled: 'neutral',
}
