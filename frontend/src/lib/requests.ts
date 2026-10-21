import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'
import type { DayRow } from './employees'

/** المسار الموحد للطلبات (SRS §10.1) + الإجازات HR.01-01-F-03 + التبرير (D-18) + التكليف بالإضافي F-04 */
export type ReqStatus = 'pending_manager' | 'pending_hr' | 'pending_exec' | 'approved' | 'rejected' | 'cancelled'
export interface Step { id: number; level: number; role: Bi; status: 'waiting' | 'pending' | 'approved' | 'rejected' | 'skipped' | 'cancelled'; by: Bi | null; owner: Bi | null; delegate_for: Bi | null; note: string | null; at: string | null; overdue: boolean }
export interface Person { id: number; code: string; name: Bi; title: Bi }
export interface ReqRow {
  kind: 'leave' | 'justify' | 'overtime' | 'service'; id: number; type: string; type_name: Bi; employee: Person | null; from: string; to: string; hours?: number | null; minutes?: number | null
  ref_date?: string | null; days?: number | null; reason: string; status: ReqStatus
  warnings: { over_balance?: { days: number; remaining: number }; conflict?: (Bi & { from: string; to: string })[]; issue?: string }
  substitute?: Bi | null; doc?: { id: number; name: string } | null; doc_required?: boolean; paid?: number | null; requested_by?: Bi | null
  amount?: number | null; currency?: 'USD' | 'SYP'; addressee?: { name?: string; cr_no?: string; email?: string; phone?: string } | null; notify_consent?: boolean; data?: { phone?: string } | null; ref_no?: string | null; issued_at?: string | null
  created_at: string; steps: Step[]; step_id: number | null; can_act: boolean; can_cancel: boolean
}
export interface LeaveType { key: string; ar: string; en: string; unit: 'day' | 'hour'; max_days: number | null; notice: boolean; doc: 'none' | 'optional' | 'required'; paid: number; balance: string | null }
export interface Balance { entitlement: number; used: number; held: number; remaining: number; sick: number; emergency: { used: number; limit: number }; unpaid: { used: number; limit: number }; probation: boolean }
export interface MyLeave {
  balance: Balance; types: LeaveType[]; rules: { notice: { max_days: number; days_before: number }[]; hourly_notice_days: number; substitute_required: boolean; hourly_max_per_day: number; justify_hours: number; emergency_report_hours: number }
  requests: ReqRow[]; colleagues: { id: number; code: string; ar: string; en: string; same_dept: boolean }[]
}
export interface LeaveInput { type: string; from: string; to: string; hours?: number; reason: string; substitute_id: number | '' }
export interface Check { errors: Record<string, string>; warnings: ReqRow['warnings']; days: number; levels: Bi[] }
export interface Issue extends DayRow { deadline: string; open: boolean; justification: { id: number; status: ReqStatus } | null }
export interface Inbox {
  data: ReqRow[]; reject_min: number; team: { id: number; code: string; ar: string; en: string }[]
  delegate: (Bi & { id: number }) | null; delegates: (Bi & { id: number })[]; overtime_max: number; assigned: ReqRow[]
}

const refresh = () => { for (const k of [['me', 'leave'], ['me', 'attendance'], ['me', 'approvals'], ['me', 'services'], ['hr', 'leaves'], ['hr', 'dashboard'], ['hr', 'attendance'], ['hr', 'employee']]) queryClient.invalidateQueries({ queryKey: k }) }

export const useMyLeave = () => useQuery({ queryKey: ['me', 'leave'], queryFn: () => api<MyLeave>('/me/leave') })
export const checkLeave = (v: LeaveInput) => api<Check>('/me/leave/check', { json: { ...v, substitute_id: v.substitute_id || null } })
export const useSubmitLeave = () => useMutation({
  mutationFn: (v: LeaveInput & { file?: File | null }) => {
    const fd = new FormData()
    for (const [k, x] of Object.entries(v)) if (k !== 'file' && x !== '' && x !== undefined && x !== null) fd.append(k, String(x))
    if (v.file) fd.append('file', v.file)
    return api<{ data: ReqRow }>('/me/leave', { method: 'POST', body: fd }).then(r => r.data)
  }, onSuccess: refresh,
})
export const useCancelLeave = () => useMutation({ mutationFn: (id: number) => api(`/me/leave/${id}/cancel`, { json: {} }), onSuccess: refresh })
export const useAttachDoc = () => useMutation({
  mutationFn: (v: { id: number; file: File }) => { const fd = new FormData(); fd.append('file', v.file); return api(`/me/leave/${v.id}/document`, { method: 'POST', body: fd }) }, onSuccess: refresh,
})
export const useMyAttendance = () => useQuery({ queryKey: ['me', 'attendance'], queryFn: () => api<{ days: DayRow[]; issues: Issue[]; justify_hours: number }>('/me/attendance') })
export const useJustify = () => useMutation({
  mutationFn: (v: { date: string; reason: string; file?: File | null }) => { const fd = new FormData(); fd.append('date', v.date); fd.append('reason', v.reason); if (v.file) fd.append('file', v.file); return api('/me/justifications', { method: 'POST', body: fd }) },
  onSuccess: refresh,
})

export const useInbox = (enabled = true) => useQuery({ queryKey: ['me', 'approvals'], queryFn: () => api<Inbox>('/me/approvals'), enabled, refetchInterval: 60_000 })
export const useDecide = () => useMutation({
  mutationFn: (v: { step: number; decision: 'approve' | 'reject'; note: string }) => api<Inbox>(`/me/approvals/${v.step}`, { json: { decision: v.decision, note: v.note || null } }),
  onSuccess: d => { queryClient.setQueryData(['me', 'approvals'], d); refresh() },
})
export const useSetDelegate = () => useMutation({ mutationFn: (employee_id: number | null) => api<Inbox>('/me/delegate', { method: 'PUT', json: { employee_id } }), onSuccess: d => queryClient.setQueryData(['me', 'approvals'], d) })
export const useAssignOvertime = () => useMutation({
  mutationFn: (v: { employee_id: number; date: string; minutes: number; reason: string }) => api<Inbox>('/me/overtime', { json: v }),
  onSuccess: d => { queryClient.setQueryData(['me', 'approvals'], d); refresh() },
})

export type LeaveTab = 'pending' | 'justify' | 'overtime' | 'services' | 'all'
export const useHrLeaves = (tab: LeaveTab, q: string) => useQuery({ queryKey: ['hr', 'leaves', tab, q], queryFn: () => api<{ data: ReqRow[]; reject_min: number; counts: Record<'pending' | 'justify' | 'overtime' | 'services', number> }>(`/hr/leaves?tab=${tab}${q ? `&q=${encodeURIComponent(q)}` : ''}`), placeholderData: p => p })
