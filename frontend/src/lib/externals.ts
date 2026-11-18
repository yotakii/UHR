import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'
import type { LookupItem } from './codes'
import type { Step } from './requests'

// الدفعة 22 — العاملون من غير الموظفين وقائمة الخبراء (SRS الأنظمة الأساسية، الجزء E)

export type Kind = 'volunteer' | 'trial' | 'consultant' | 'expert' | 'intern'
export interface ExtRow {
  id: number; ref: string; kind: Kind; category: (Bi & { code: string }) | null; entity: 'person' | 'firm'; ar: string; en: string; email: string | null; phone: string | null; country: string | null; city: string | null
  status: string; specialties: number[]; years: number | null; level: string | null; languages: string[]; available: 'now' | 'from' | 'no'; available_from: string | null; avg_rating: number | null; ratings: number
  last_engaged_at: string | null; review_due: string | null; supervisor: Bi | null; rate: number | null; rate_unit: string | null; rate_currency: string | null; fees_hidden: boolean; source: string; has_account: boolean; hours?: number
}
export interface LogRow { id: number; date: string; hours: number; activity: string; via: string; status: 'pending' | 'approved' | 'rejected'; note: string | null }
export interface EngagementRow {
  id: number; ref: string; title: string; tasks: string | null; starts_on: string; ends_on: string; weekly_hours: number; relation: 'replaces' | 'alongside' | 'independent'; relation_score: number
  relation_test: Record<string, boolean> | null; justification: string | null; replaces_position: string | null; replaces_employee_id: number | null; high: boolean; fee_model: string; amount: number | null; currency: string | null
  expense_cap: number | null; tax_treatment: string | null; status: string; body: string | null; pledges: Record<string, string> | null; signed_at: string | null; onboarding: (Bi & { done: boolean })[]
  evaluation: { rating: number; note: string | null; at: string; by: string } | null; certificate_no: string | null; verify_code: string | null; supervisor: (Bi & { id: number }) | null; supervisor_id: number | null
  department_id: number | null; location_id: number | null; days: string[] | null; hours: number; pending_hours: number; logs: LogRow[]; steps: Step[]; step_id: number | null; can_act: boolean
  can: { edit: boolean; approve_logs: boolean; end: boolean }
}
export interface DeliverableRow { id: number; title: string; due_on: string | null; payment: number | null; status: 'pending' | 'submitted' | 'approved' | 'returned'; file_id: number | null; submitted_at?: string | null; note: string | null; late?: boolean }
export interface AssignmentRow {
  id: number; ref: string; title: string; tor: { objective: string; outputs: string; place: string; specialties: number[] }; department_id: number | null; starts_on: string | null; ends_on: string | null
  budget: number | null; total_fee: number | null; currency: string; tax_treatment: string | null; status: string; expert: (Bi & { id: number; ref: string }) | null
  shortlist: { external_id: number; note: string | null; ar: string; en: string; rating: number | null }[]; selection_reason: string | null; relation_score: number; pledges: Record<string, string | null> | null
  contracted_at: string | null; contract_file_id: number | null; deliverables: DeliverableRow[]; rating: { score: number; quality: number; timeliness: number; communication: number; value: number; rehire: boolean; notes: string | null } | null
  fees_hidden: boolean; suggested?: (ExtRow & { score: number })[]
}
export interface PaymentRow { id: number; ref: string; external: (Bi & { id: number }) | null; source_type: 'deliverable' | 'expense'; description: string; amount: number; currency: string; file_id: number | null; status: 'pending' | 'approved' | 'paid' | 'rejected'; finance_ref: string | null; note: string | null; created_at: string; paid_at: string | null }
export interface ExtFull extends ExtRow {
  gender: string | null; dob: string | null; national_id: string | null; nationality: string | null; emergency: { name?: string; phone?: string; relation?: string } | null; areas: string[]; availability: { days?: string[]; hours?: string; note?: string } | null
  sectors: string[]; keywords: string[]; projects: string | null; work_modes: string[]; cv_file_id: number | null; documents: { type: string; file_id: number; valid_to?: string | null; note?: string }[]; notes: string | null; status_note: string | null
  worker_category_id: number; location_id: number | null; department_id: number | null; supervisor_id: number | null; converted_candidate_id: number | null
  engagements: EngagementRow[]; assignments: AssignmentRow[]; payments: PaymentRow[]
}
export interface Lookups {
  categories: LookupItem[]; specialties: LookupItem[]; countries: LookupItem[]; currencies: LookupItem[]; departments: (Bi & { id: number })[]; branches: (Bi & { id: number })[]
  employees: (Bi & { id: number; code: string })[]; jobs: (Bi & { id: number; code: string })[]; test: string[]; relations: string[]; levels: string[]
  cfg: { max_months: number; max_weekly_hours: number; relation_threshold: number; replaces_max_months: number; pledges: string[]; expense_cap: number; expense_currency: string; shortlist_min: number }
}
export interface Can { create: boolean; manage: boolean; decide: boolean; fees: boolean; pay: boolean; export: boolean; settings: boolean }

const qs = (o: Record<string, string | undefined>) => { const s = new URLSearchParams(Object.entries(o).filter(([, v]) => v) as [string, string][]).toString(); return s ? `?${s}` : '' }
const changed = () => { queryClient.invalidateQueries({ queryKey: ['externals'] }); queryClient.invalidateQueries({ queryKey: ['me', 'approvals'] }) }

export const useExternals = (f: Record<string, string | undefined>) => useQuery({ queryKey: ['externals', 'list', f], placeholderData: p => p, queryFn: () => api<{ data: ExtRow[]; counts: Record<string, number>; lookups: Lookups; can: Can }>(`/hr/externals${qs(f)}`) })
export const useExternal = (id: number | null) => useQuery({ queryKey: ['externals', 'one', id], enabled: !!id, queryFn: () => api<{ data: ExtFull }>(`/hr/externals/${id}`).then(r => r.data) })
export const useSaveExternal = () => useMutation({ mutationFn: ({ id, v }: { id?: number; v: Record<string, unknown> }) => api<{ id?: number }>(id ? `/hr/externals/${id}` : '/hr/externals', { method: id ? 'PUT' : 'POST', json: v }), onSuccess: changed })
export const useExtAct = () => useMutation({ mutationFn: ({ path, v, method }: { path: string; v?: Record<string, unknown>; method?: string }) => api<Record<string, unknown>>(path.startsWith('/') ? path : `/hr/externals/${path}`, { method: method ?? 'POST', json: v ?? {} }), onSuccess: () => { changed(); queryClient.invalidateQueries({ queryKey: ['ext'] }) } })
export const uploadExtFile = (file: File, portal = false) => { const f = new FormData(); f.append('file', file); return api<{ id: number; name: string }>(portal ? '/ext/files' : '/hr/externals/files', { method: 'POST', body: f }) }
export const useAssignments = (status: string) => useQuery({ queryKey: ['externals', 'assignments', status], queryFn: () => api<{ data: AssignmentRow[]; lookups: Lookups; can: Can }>(`/hr/externals/assignments${qs({ status })}`) })
export const useAssignment = (id: number | null) => useQuery({ queryKey: ['externals', 'assignment', id], enabled: !!id, queryFn: () => api<{ data: AssignmentRow }>(`/hr/externals/assignments/${id}`).then(r => r.data) })
export const usePayments = (status: string) => useQuery({ queryKey: ['externals', 'payments', status], queryFn: () => api<{ data: PaymentRow[]; can: Can }>(`/hr/externals/payments${qs({ status })}`) })
export const useOpportunities = () => useQuery({ queryKey: ['externals', 'opps'], queryFn: () => api<{ data: { id: number; title: string; title_en: string | null; description: string | null; department_id: number | null; location_id: number | null; starts_on: string | null; ends_on: string | null; slots: number; weekly_hours: number | null; status: string; url: string; applicants: number }[] }>('/hr/externals/opportunities') })
export const useExpertLink = () => useQuery({ queryKey: ['externals', 'expert-link'], queryFn: () => api<{ url: string }>('/hr/externals/expert-link') })
export const useExtReports = (from: string, to: string) => useQuery({ queryKey: ['externals', 'reports', from, to], queryFn: () => api<{ data: ExtReport; lookups: Lookups }>(`/hr/externals/reports${qs({ from, to })}`) })
export type ExtReport = {
  volunteers: { total: number; active: number; by_status: Record<string, number>; hours: number; value: number | null; value_currency: string; by_branch: Record<string, number>; by_department: Record<string, number>; by_relation: Record<string, number>; retention: number | null; high: number }
  experts: { total: number; approved: number; by_specialty: Record<string, number>; by_country: Record<string, number>; live: number; top: (Bi & { id: number; rating: number; n: number })[]
    paid: { currency: string; source_type: string; s: number }[] | null; paid_by_department: { d: number | null; c: string; s: number }[] | null }
}
export const useExtSettings = () => useQuery({ queryKey: ['externals', 'settings'], queryFn: () => api<{ data: Record<string, unknown>; defaults: Record<string, unknown>; can: { edit: boolean } }>('/hr/externals/settings') })
export const useSaveExtSettings = () => useMutation({ mutationFn: (v: Record<string, unknown>) => api('/hr/externals/settings', { method: 'PUT', json: v }), onSuccess: changed })
export const useVolunteerHours = () => useQuery({ queryKey: ['externals', 'my-hours'], queryFn: () => api<{ data: { id: number; date: string; hours: number; activity: string; external: Bi & { id: number }; ref: string }[] }>('/me/volunteer-hours') })
export const useDecideHours = () => useMutation({ mutationFn: (v: { ids: number[]; approve: boolean; note?: string }) => api<{ count: number }>('/me/volunteer-hours', { json: v }), onSuccess: changed })
export const useCertificate = (id: number, portal = false) => useQuery({ queryKey: ['externals', 'cert', id, portal], queryFn: () => api<{ data: Certificate }>(portal ? `/ext/engagements/${id}/certificate` : `/hr/externals/engagements/${id}/certificate`).then(r => r.data) })
export type Certificate = { no: string; code: string; name: Bi; title: string; tasks: string | null; from: string; to: string; hours: number; company: Bi }

// بوابة المتطوع / الخبير
export interface ExtHome {
  me: Bi & { ref: string; kind: Kind; email: string | null; phone: string | null; city: string | null; country: string | null; status: string; available: string; available_from: string | null; work_modes: string[]; languages: string[]; keywords: string[]; projects: string | null; review_requested: boolean }
  company: { slug: string; name: Bi; logo: string | null } | null
  engagement?: EngagementRow | null; certificates?: { id: number; ref: string; title: string; certificate_no: string; verify_code: string }[]; payments: PaymentRow[]
  opportunities?: { id: number; title: string; title_en: string | null; description: string | null; starts_on: string | null; ends_on: string | null; weekly_hours: number | null; token: string }[]; pledges?: string[]
  assignments?: (Omit<AssignmentRow, 'shortlist' | 'expert' | 'fees_hidden' | 'rating' | 'selection_reason' | 'relation_score' | 'contracted_at' | 'contract_file_id' | 'budget' | 'tax_treatment' | 'department_id'>)[]
}
export const useExtHome = () => useQuery({ queryKey: ['ext', 'home'], queryFn: () => api<ExtHome>('/ext') })
const extChanged = () => queryClient.invalidateQueries({ queryKey: ['ext'] })
export const useExtPortalAct = () => useMutation({ mutationFn: ({ path, v, method }: { path: string; v?: Record<string, unknown>; method?: string }) => api<Record<string, unknown>>(`/ext/${path}`, { method: method ?? 'POST', json: v ?? {} }), onSuccess: extChanged })

export const KIND: Record<Kind, [string, string]> = { volunteer: ['متطوع', 'Volunteer'], trial: ['عقد تجريبي قصير', 'Short trial'], consultant: ['استشاري', 'Consultant'], expert: ['خبير', 'Expert'], intern: ['متدرب', 'Intern'] }
export const STATUS: Record<string, [string, string, 'brand' | 'brass' | 'info' | 'danger' | 'neutral' | 'warn']> = {
  applicant: ['متقدم', 'Applicant', 'info'], accepted: ['مقبول', 'Accepted', 'info'], waitlist: ['قائمة انتظار', 'Waitlist', 'neutral'], pending_sign: ['بانتظار التوقيع', 'Awaiting signature', 'brass'],
  active: ['فعّال', 'Active', 'brand'], ended: ['منتهٍ', 'Ended', 'neutral'], suspended: ['موقوف', 'Suspended', 'warn'], excluded: ['مستبعد', 'Excluded', 'danger'],
  vetting: ['قيد التدقيق', 'Vetting', 'brass'], approved: ['معتمد', 'Approved', 'brand'], assigned: ['مكلَّف حالياً', 'On assignment', 'info'], inactive: ['غير نشط', 'Inactive', 'neutral'], rejected: ['مرفوض', 'Rejected', 'danger'],
}
export const ENG_STATUS: Record<string, [string, string, 'brand' | 'brass' | 'info' | 'danger' | 'neutral' | 'warn']> = {
  draft: ['مسودة', 'Draft', 'neutral'], pending_hr: ['بانتظار الموارد البشرية', 'Awaiting HR', 'brass'], pending_exec: ['بانتظار الإدارة العليا', 'Awaiting top management', 'brass'], pending_manager: ['بانتظار المدير', 'Awaiting manager', 'brass'],
  approved: ['بانتظار التوقيع', 'Awaiting signature', 'info'], active: ['فعّال', 'Active', 'brand'], ended: ['منتهٍ', 'Ended', 'neutral'], terminated: ['أُنهي مبكراً', 'Ended early', 'warn'], rejected: ['مرفوض', 'Rejected', 'danger'], cancelled: ['ملغى', 'Cancelled', 'neutral'],
}
export const ASG_STATUS: Record<string, [string, string, 'brand' | 'brass' | 'info' | 'danger' | 'neutral' | 'warn']> = {
  request: ['طلب', 'Request', 'neutral'], shortlist: ['قائمة مختصرة', 'Shortlist', 'info'], selected: ['مختار', 'Selected', 'info'], contracted: ['بانتظار التعهدات', 'Awaiting pledges', 'brass'],
  in_progress: ['قيد التنفيذ', 'In progress', 'brand'], delivered: ['المخرجات معتمدة', 'Deliverables approved', 'brand'], closed: ['مغلق', 'Closed', 'neutral'], cancelled: ['ملغى', 'Cancelled', 'neutral'], terminated: ['أُنهي مبكراً', 'Ended early', 'warn'],
}
export const DLV: Record<string, [string, string, 'brand' | 'brass' | 'info' | 'danger' | 'neutral' | 'warn']> = { pending: ['لم يُسلَّم', 'Not submitted', 'neutral'], submitted: ['بانتظار المراجعة', 'Awaiting review', 'brass'], approved: ['معتمد', 'Approved', 'brand'], returned: ['معاد', 'Returned', 'danger'] }
export const PAY: Record<string, [string, string, 'brand' | 'brass' | 'info' | 'danger' | 'neutral' | 'warn']> = { pending: ['بانتظار الاعتماد', 'Pending', 'brass'], approved: ['معتمد للدفع', 'Approved', 'info'], paid: ['مدفوع', 'Paid', 'brand'], rejected: ['مرفوض', 'Rejected', 'danger'] }
export const RELATION: Record<string, [string, string]> = { replaces: ['يحل محل موظف', 'Replaces an employee'], alongside: ['يعمل إلى جانب موظف', 'Works alongside an employee'], independent: ['ينفذ مهمة مستقلة', 'Independent task'] }
export const TEST_Q: Record<string, [string, string]> = {
  fixed_hours: ['هل يلتزم بدوام يومي ثابت تحدده المنشأة؟', 'Does he/she keep fixed daily hours set by the organisation?'], daily_orders: ['هل يتلقى تعليمات يومية من مدير مباشر حول طريقة التنفيذ؟', 'Does he/she receive daily instructions on how to work?'],
  monthly_pay: ['هل يتقاضى مبلغاً شهرياً ثابتاً غير مرتبط بمخرجات؟', 'Is there a fixed monthly amount not tied to outputs?'], exclusive: ['هل يعمل حصرياً للمنشأة ولمدة مفتوحة أو تُجدَّد تلقائياً؟', 'Is it exclusive and open-ended or auto-renewed?'],
  fills_post: ['هل يشغل موقعاً وظيفياً في الهيكل أو يحل محل موظف؟', 'Does he/she fill a post in the structure or replace an employee?'], uses_premises: ['هل يستخدم أدوات المنشأة ومقرها بشكل دائم؟', 'Does he/she use the organisation’s tools and premises permanently?'],
}
export const PLEDGE: Record<string, [string, string]> = { agreement: ['اتفاقية التطوع', 'Volunteer agreement'], confidentiality: ['تعهد السرية', 'Confidentiality pledge'], conduct: ['مدونة السلوك', 'Code of conduct'], safety: ['إجراءات السلامة والحماية', 'Safety & safeguarding'] }
export const LEVEL: Record<string, [string, string]> = { junior: ['مبتدئ', 'Junior'], mid: ['متوسط', 'Mid'], senior: ['أول', 'Senior'], expert: ['خبير', 'Expert'] }
