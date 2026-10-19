import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'
import type { CompanyCard } from './session'

/* ---------- ثوابت التوظيف (من rules.ts في الديمو) ---------- */
export type Stage = 'new' | 'screening' | 'interview' | 'technical' | 'offer' | 'hired' | 'rejected'

export const STAGES: Record<Stage, Bi> = {
  new: { ar: 'طلبات جديدة', en: 'New' }, screening: { ar: 'فرز أولي', en: 'Screening' }, interview: { ar: 'المقابلة الأولية', en: 'HR interview' },
  technical: { ar: 'التقييم الفني', en: 'Technical review' }, offer: { ar: 'عرض العمل', en: 'Offer' }, hired: { ar: 'تم التعيين', en: 'Hired' }, rejected: { ar: 'غير مقبول', en: 'Not selected' },
}

export const DEGREES: Bi[] = [
  { ar: 'ثانوية', en: 'Secondary' }, { ar: 'معهد متوسط', en: 'Diploma' },
  { ar: 'إجازة جامعية', en: "Bachelor's" }, { ar: 'ماجستير', en: "Master's" }, { ar: 'دكتوراه', en: 'PhD' },
]

export const ENGLISH: Record<string, Bi> = { excellent: { ar: 'ممتاز', en: 'Excellent' }, good: { ar: 'جيد', en: 'Good' }, fair: { ar: 'مقبول', en: 'Fair' }, weak: { ar: 'ضعيف', en: 'Weak' } }

/* ---------- بوابة المتقدم (M05) ---------- */
export type OfferStatus = 'sent' | 'accepted' | 'declined' | 'negotiation'
export interface MyOffer {
  title: string; contract: 'fixed' | 'open' | 'project'; currency: 'USD' | 'SYP'
  basic: number; housing: number; transport: number; fuel: number; total: number; leave_days: number; start_date: string
  issued_at: string; status: OfferStatus; responded_at: string | null; note: string | null; signature: string | null
}
export interface MyApproval { level: number; role: Bi; name: string; status: 'pending' | 'approved' | 'rejected'; at: string | null }
export interface MyAppointment { decision_no: string; issued_at: string; complete: boolean; approvals: MyApproval[] }
export interface MyApplication {
  id: number; company: CompanyCard; ref: string; created_at: string; stage: Stage; name: string; name_en: string | null; email: string; mobile: string; salary: number
  cv: { name: string; size: number } | null
  job: { code: string; title: Bi; dept: Bi }
  offer: MyOffer | null; appointment: MyAppointment | null
  test: { status: 'sent' | 'started' | 'completed' | 'reviewed' | 'expired'; minutes: number; expires_at: string; link: string | null } | null
  keep_cv: boolean; can_erase: boolean
}

/** سطر في «طلباتي» — كل طلبات الحساب لدى كل الشركات */
export interface MyApplicationRow {
  id: number; ref: string; stage: Stage; created_at: string; updated_at: string; company: CompanyCard
  job: { code: string; title: Bi }; offer: OfferStatus | null; appointment: { complete: boolean } | null
}

export function useMyApplications() {
  return useQuery({ queryKey: ['me', 'applications'], queryFn: () => api<{ data: MyApplicationRow[] }>('/me/applications').then(r => r.data) })
}

export function useMyApplication(id: number) {
  return useQuery({ queryKey: ['me', 'application', id], queryFn: () => api<{ data: MyApplication }>(`/me/applications/${id}`).then(r => r.data), retry: false })
}

export function useRespondOffer(id: number) {
  return useMutation({
    mutationFn: (v: { status: Exclude<OfferStatus, 'sent'>; signature?: string; note?: string }) => api<{ data: MyApplication }>(`/me/applications/${id}/offer-response`, { json: v }).then(r => r.data),
    onSuccess: data => { queryClient.setQueryData(['me', 'application', id], data); queryClient.invalidateQueries({ queryKey: ['me', 'applications'] }) },
  })
}
