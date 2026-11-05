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
  ot_raw: number; ot_assigned: number; ot_paid: number; ot_weighted: number; incomplete_dates: string[]; from: string; to: string; discipline_days?: number
}
export interface MonthMoney { base: number; ot_pay: number; late: number; absent: number; leave: number; insurance: number; advance: number; discipline?: number; bonus?: number; net: number; day: number; hour: number; currency: string }
export interface PayMoney extends MonthMoney { rate: number; from: string }
type Person = { id: number; code: string; name: Bi; title: Bi; dept: (Bi & { id: number }) | null }
export interface SheetRow { id: number; employee: Person; data: MonthData; money: MonthMoney | null; pay?: PayMoney | null; salary: number | null; see?: boolean }   // المبالغ null والرواتب مقفلة (SRS §4.4)
export interface Totals { employees: number; incomplete: number; absent: number; late: number; deduct: number; ot_paid: number; base: number | null; ot_pay: number | null; deductions: number | null; advance: number | null; bonus?: number | null; net: number | null; currency?: string | null; by_currency?: Record<string, number> | null; missing_rates?: string[] }
/** الفروقات عن الكشف السابق (ملاحظة 72) */
export interface DiffRow { employee_id: number; employee: Person | null; status: 'new' | 'left' | 'same' | 'currency'; prev: number | null; net: number | null; delta: number | null; pct: number | null; currency: string | null; changes: Record<string, number>; alert: boolean }
export type CheckCode = 'rate_missing' | 'net_zero' | 'incomplete' | 'no_bank' | 'big_change' | 'new' | 'left'
export interface SheetCheck { code: CheckCode; employee_id: number | null; employee: Person | null; value: string | number | null }
export type ReportKind = 'bank' | 'insurance' | 'departments' | 'deductions' | 'diff'
type Stamp = { at: string | null; by: Bi | null }
export interface PayrollPage {
  month: string; current_month: string; months: { month: string; status: SheetStatus }[]
  sheet: { id: number; month: string; status: SheetStatus; totals: Totals | null; note: string | null; calculated: Stamp; approved: Stamp; closed: Stamp
    pay_currency: string | null; pay_date: string | null; rates: Record<string, { rate: number; date: string } | null> | null; missing_rates: string[] } | null
  masked: boolean; rows: SheetRow[]; incomplete: { employee_id: number; code: string; name: Bi; date: string }[]
  diff: { prev: string | null; alert_pct: number; rows: DiffRow[] } | null; checks: SheetCheck[]
  can: { edit: boolean; decide: boolean; export: boolean; attendance: boolean; settings: boolean }
}
export const usePayroll = (month: string) => useQuery({ queryKey: ['hr', 'payroll', month], queryFn: () => api<PayrollPage>(`/hr/payroll?month=${month}`), placeholderData: p => p })
const setPage = (d: PayrollPage) => { queryClient.setQueryData(['hr', 'payroll', d.month], d); queryClient.invalidateQueries({ queryKey: ['hr', 'dashboard'] }) }
export const useOpenSheet = () => useMutation({ mutationFn: (month: string) => api<PayrollPage>('/hr/payroll', { json: { month } }), onSuccess: setPage })
export const useSheetAction = () => useMutation({
  mutationFn: (v: { id: number; action: 'calculate' | 'approve' | 'reopen' | 'close'; note?: string }) => api<PayrollPage>(`/hr/payroll/${v.id}/${v.action}`, { json: { note: v.note ?? null } }), onSuccess: setPage,
})
export async function downloadSheet(id: number, month: string, kind?: ReportKind) {
  const r = await fetch(kind ? `/api/hr/payroll/${id}/report/${kind}` : `/api/hr/payroll/${id}/export`, { credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': xsrf() } })
  if (!r.ok) throw new Error(String(r.status))
  const a = document.createElement('a'); a.href = URL.createObjectURL(await r.blob()); a.download = kind ? `UHR-${kind}-${month}.xlsx` : `UHR-sheet-${month}.xlsx`; a.click(); URL.revokeObjectURL(a.href)
}
/** عملة الصرف وتاريخه للكشف، وأسعار الصرف المؤرخة (ملاحظتا 73/78) */
export const useSetPay = () => useMutation({ mutationFn: (v: { id: number; currency: string | null; pay_date: string }) => api<PayrollPage>(`/hr/payroll/${v.id}/pay`, { json: v }), onSuccess: setPage })
export interface Rate { id: number; date: string; from: string; to: string; rate: number; note: string | null; by: Bi | null }
export interface PaySettings { currency: string; pay_day: number; diff_alert_pct: number }
export const useRates = () => useQuery({ queryKey: ['hr', 'payroll-rates'], queryFn: () => api<{ data: Rate[]; settings: PaySettings; defaults: PaySettings; currencies: string[]; used: string[] }>('/hr/payroll/rates') })
const ratesChanged = () => queryClient.invalidateQueries({ queryKey: ['hr', 'payroll-rates'] })
export const useSaveRate = () => useMutation({ mutationFn: (v: { date: string; from: string; to: string; rate: number; note?: string }) => api('/hr/payroll/rates', { json: v }), onSuccess: ratesChanged })
export const useDeleteRate = () => useMutation({ mutationFn: (id: number) => api(`/hr/payroll/rates/${id}`, { method: 'DELETE' }), onSuccess: ratesChanged })
export const useSavePaySettings = () => useMutation({ mutationFn: (v: PaySettings) => api('/hr/payroll/settings', { method: 'PUT', json: v }), onSuccess: ratesChanged })

export interface Payslip { month: string; status: 'closed' | 'estimate'; data: MonthData; money: MonthMoney | null; pay?: PayMoney | null; closed_at?: string }
export const useMyPayslips = () => useQuery({ queryKey: ['me', 'payslips'], queryFn: () => api<{ data: Payslip[]; current: Payslip; pay_day: number; masked: boolean }>('/me/payslips') })

export type ServiceKind = 'salary_cert' | 'experience_cert' | 'advance' | 'data_update'
export interface AdvanceInfo { month: string; earned: number | null; cap: number | null; taken: number | null; available: number | null; currency: 'USD' | 'SYP'; per_month: number; used_count: number }
export interface ServiceInput { kind: ServiceKind; note?: string; amount?: number; addressee?: { name?: string; cr_no?: string; email?: string; phone?: string }; notify_consent?: boolean; data?: { phone?: string } }
export const useMyServices = () => useQuery({ queryKey: ['me', 'services'], queryFn: () => api<{ data: ReqRow[]; advance: AdvanceInfo; masked: boolean; kinds: (Bi & { key: ServiceKind })[] }>('/me/services') })
const refresh = () => { for (const k of [['me', 'services'], ['me', 'approvals'], ['hr', 'leaves'], ['hr', 'dashboard']]) queryClient.invalidateQueries({ queryKey: k }) }
export const useSubmitService = () => useMutation({ mutationFn: (v: ServiceInput) => api<{ data: ReqRow }>('/me/services', { json: v }), onSuccess: refresh })
export const useCancelService = () => useMutation({ mutationFn: (id: number) => api(`/me/services/${id}/cancel`, { json: {} }), onSuccess: refresh })

export interface Certificate {
  ref_no: string; kind: 'salary_cert' | 'experience_cert'; issued_at: string; addressee: { name?: string; cr_no?: string; email?: string; phone?: string } | null; notify_consent: boolean
  employee: { name: Bi; gender: 'M' | 'F'; code: string; title: Bi; national_id: string | null; hire_date: string | null }
  salary: { amount: number | null; currency: 'USD' | 'SYP' } | null; masked?: boolean; company: Bi; issuer: Bi | null; form: 'SAL' | 'EMP'
}
export const useCertificate = (id: number | null, hr: boolean) => useQuery({
  queryKey: ['certificate', hr, id], enabled: !!id, queryFn: () => api<{ data: Certificate }>(hr ? `/hr/services/${id}/certificate` : `/me/services/${id}/certificate`).then(r => r.data),
})
