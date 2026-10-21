import { useMutation, useQuery } from '@tanstack/react-query'
import { api, xsrf } from './api'
import { queryClient } from './session'
import type { Bi } from './types'
import type { ReqRow } from './requests'

/** الكشف الشهري للدوام والمستحقات (D-01)، السلفة، وطلبات الخدمات والشهادات (D-16) */
export type SheetStatus = 'draft' | 'calculated' | 'approved' | 'closed'
export interface MonthData {
  work_days: number; present: number; absent: number; justified_absent: number; incomplete: number; leave: number; leave_unpaid: number
  late_days: number; late: number; exempted: number; exempt_min: number; deduct: number; worked: number
  ot_raw: number; ot_assigned: number; ot_paid: number; ot_weighted: number; incomplete_dates: string[]; from: string; to: string
}
export interface MonthMoney { base: number; ot_pay: number; late: number; absent: number; leave: number; insurance: number; advance: number; net: number; day: number; hour: number; currency: 'USD' | 'SYP' }
export interface SheetRow { id: number; employee: { id: number; code: string; name: Bi; title: Bi; dept: (Bi & { id: number }) | null }; data: MonthData; money: MonthMoney; salary: number }
export interface Totals { employees: number; incomplete: number; absent: number; late: number; deduct: number; ot_paid: number; base: number; ot_pay: number; deductions: number; advance: number; net: number }
type Stamp = { at: string | null; by: Bi | null }
export interface PayrollPage {
  month: string; current_month: string; months: { month: string; status: SheetStatus }[]
  sheet: { id: number; month: string; status: SheetStatus; totals: Totals | null; note: string | null; calculated: Stamp; approved: Stamp; closed: Stamp } | null
  rows: SheetRow[]; incomplete: { employee_id: number; code: string; name: Bi; date: string }[]
  can: { edit: boolean; decide: boolean; export: boolean; attendance: boolean }
}
export const usePayroll = (month: string) => useQuery({ queryKey: ['hr', 'payroll', month], queryFn: () => api<PayrollPage>(`/hr/payroll?month=${month}`), placeholderData: p => p })
const setPage = (d: PayrollPage) => { queryClient.setQueryData(['hr', 'payroll', d.month], d); queryClient.invalidateQueries({ queryKey: ['hr', 'dashboard'] }) }
export const useOpenSheet = () => useMutation({ mutationFn: (month: string) => api<PayrollPage>('/hr/payroll', { json: { month } }), onSuccess: setPage })
export const useSheetAction = () => useMutation({
  mutationFn: (v: { id: number; action: 'calculate' | 'approve' | 'reopen' | 'close'; note?: string }) => api<PayrollPage>(`/hr/payroll/${v.id}/${v.action}`, { json: { note: v.note ?? null } }), onSuccess: setPage,
})
export async function downloadSheet(id: number, month: string) {
  const r = await fetch(`/api/hr/payroll/${id}/export`, { credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': xsrf() } })
  if (!r.ok) throw new Error(String(r.status))
  const a = document.createElement('a'); a.href = URL.createObjectURL(await r.blob()); a.download = `UHR-sheet-${month}.xlsx`; a.click(); URL.revokeObjectURL(a.href)
}

export interface Payslip { month: string; status: 'closed' | 'estimate'; data: MonthData; money: MonthMoney; closed_at?: string }
export const useMyPayslips = () => useQuery({ queryKey: ['me', 'payslips'], queryFn: () => api<{ data: Payslip[]; current: Payslip; pay_day: number }>('/me/payslips') })

export type ServiceKind = 'salary_cert' | 'experience_cert' | 'advance' | 'data_update'
export interface AdvanceInfo { month: string; earned: number; cap: number; taken: number; available: number; currency: 'USD' | 'SYP'; per_month: number; used_count: number }
export interface ServiceInput { kind: ServiceKind; note?: string; amount?: number; addressee?: { name?: string; cr_no?: string; email?: string; phone?: string }; notify_consent?: boolean; data?: { phone?: string } }
export const useMyServices = () => useQuery({ queryKey: ['me', 'services'], queryFn: () => api<{ data: ReqRow[]; advance: AdvanceInfo; kinds: (Bi & { key: ServiceKind })[] }>('/me/services') })
const refresh = () => { for (const k of [['me', 'services'], ['me', 'approvals'], ['hr', 'leaves'], ['hr', 'dashboard']]) queryClient.invalidateQueries({ queryKey: k }) }
export const useSubmitService = () => useMutation({ mutationFn: (v: ServiceInput) => api<{ data: ReqRow }>('/me/services', { json: v }), onSuccess: refresh })
export const useCancelService = () => useMutation({ mutationFn: (id: number) => api(`/me/services/${id}/cancel`, { json: {} }), onSuccess: refresh })

export interface Certificate {
  ref_no: string; kind: 'salary_cert' | 'experience_cert'; issued_at: string; addressee: { name?: string; cr_no?: string; email?: string; phone?: string } | null; notify_consent: boolean
  employee: { name: Bi; gender: 'M' | 'F'; code: string; title: Bi; national_id: string | null; hire_date: string | null }
  salary: { amount: number; currency: 'USD' | 'SYP' } | null; company: Bi; issuer: Bi | null; form: 'SAL' | 'EMP'
}
export const useCertificate = (id: number | null, hr: boolean) => useQuery({
  queryKey: ['certificate', hr, id], enabled: !!id, queryFn: () => api<{ data: Certificate }>(hr ? `/hr/services/${id}/certificate` : `/me/services/${id}/certificate`).then(r => r.data),
})
