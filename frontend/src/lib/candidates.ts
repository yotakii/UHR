import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'
import type { Stage, OfferStatus } from './recruitment'

/** لوحة المتقدمين (M03) وملف المتقدم (M04) وقرار التعيين F-31 — من /hr/candidates و/hr/approvals */
export interface CandCard {
  id: number; ref: string; name: Bi; stage: Stage; stage_at: string; days_in_stage: number; sla: 'ok' | 'due' | 'late' | 'none'; sla_limit: number; created_at: string
  match: number; band: 'normal' | 'review' | 'reject' | null; auto_flag: string | null; internal: boolean; referred: boolean; referrer: Bi | null; offer: OfferStatus | null; appointment: boolean
  job: { id: number; code: string; title: Bi }
}
export interface MatchPart { key: string; ar: string; en: string; weight: number; score: number; note_ar: string; note_en: string }
export interface HrOffer {
  title: string; contract: 'fixed' | 'open' | 'project'; currency: 'USD' | 'SYP'; basic: number; housing: number; transport: number; fuel: number; total: number
  leave_days: number; start_date: string; issued_at: string; status: OfferStatus; responded_at: string | null; note: string | null; signature: string | null
}
export interface Approval { id: number; level: number; role: Bi; name: string; status: 'pending' | 'approved' | 'rejected'; at: string | null }
export interface HrAppointment {
  id: number; decision_no: string; issued_at: string; complete: boolean; employee_id: number | null; rejected: boolean; approvals: Approval[]
  current: { id: number; role: Bi; mine: boolean } | null; headcount: { approved: number; current: number }; degree_ok: boolean
}
export interface LogRow { at: string; ar: string; en: string; kind: 'ok' | 'info' | 'warn' | 'danger'; form: string | null }
export interface CandFile extends Omit<CandCard, 'offer' | 'appointment' | 'job' | 'referrer'> {
  name_ar: string; name_en: string | null; gender: 'M' | 'F'; marital: string | null; nationality: string | null; dob: string | null; pob: string | null; national_id: string | null; blood: string | null
  email: string; mobile: string; address: string | null; linkedin: string | null
  education: { phase: string; institute: string; major: string; year: string; grade: string }[]
  experience: { company: string; position: string; from: string; to: string; reason: string }[]
  refs: { name: string; job: string; phone: string }[]
  years_exp: number; degree_level: number; english: string; salary: number; availability: string | null; source: string
  shifts: boolean; travel: boolean; public_sector: boolean; conviction: boolean; reject_reason: string | null
  referrer: { code: string; name: Bi } | null; internal_reason: string | null; internal_employee: { code: string; name: Bi } | null
  cv: { id: number; name: string; size: number } | null; photo: { id: number } | null
  job: CandCard['job'] & { dept: Bi; city: Bi; min_years: number; degree: number; salary_min: number; salary_max: number; currency: 'USD' | 'SYP'; openings: number }
  match_parts: MatchPart[]
  interview: { ratings: Record<string, number>; notes: string | null; recommendation: 'recommended' | 'reserve' | 'rejected'; interviewer: string; date: string; pct: number } | null
  offer: HrOffer | null; appointment: HrAppointment | null; logs: LogRow[]
}
export type OfferInput = Pick<HrOffer, 'title' | 'contract' | 'currency' | 'basic' | 'housing' | 'transport' | 'fuel' | 'leave_days' | 'start_date'>
export interface Rules { match_threshold: number; review_min: number; reject_max: number; auto_reject: boolean; auto_screen: boolean; sla_alerts: boolean; whatsapp_nudge: boolean; referral_stages: number[]; hard_to_fill_x: number }
export interface MyApprovalItem { candidate: CandCard & { name_ar: string; gender: 'M' | 'F' }; job: { title: Bi; dept: Bi }; offer: (OfferInput & { total: number }) | null; appointment: HrAppointment }

export const useBoard = (job?: number, q?: string) => useQuery({
  queryKey: ['hr', 'candidates', job ?? 0, q ?? ''],
  queryFn: () => api<{ data: CandCard[]; jobs: { id: number; code: string; title: Bi; status: string }[]; sla: Record<string, number> }>(`/hr/candidates?${new URLSearchParams({ ...(job ? { job: String(job) } : {}), ...(q ? { q } : {}) })}`),
  placeholderData: prev => prev,
})
export const useCandidate = (id: number) => useQuery({ queryKey: ['hr', 'candidate', id], queryFn: () => api<{ data: CandFile }>(`/hr/candidates/${id}`).then(r => r.data) })

const settle = (c: CandFile) => { queryClient.setQueryData(['hr', 'candidate', c.id], c); queryClient.invalidateQueries({ queryKey: ['hr', 'candidates'] }); queryClient.invalidateQueries({ queryKey: ['hr', 'dashboard'] }) }
const post = <T,>(path: string, method: string, json: T) => api<{ data: CandFile }>(path, { method, json }).then(r => r.data)

export const useMove = () => useMutation({ mutationFn: (v: { id: number; stage: Stage; reason?: string }) => post(`/hr/candidates/${v.id}/move`, 'POST', { stage: v.stage, reason: v.reason }), onSuccess: settle })
export const useNote = (id: number) => useMutation({ mutationFn: (text: string) => post(`/hr/candidates/${id}/notes`, 'POST', { text }), onSuccess: settle })
export const useInterview = (id: number) => useMutation({ mutationFn: (v: { ratings: Record<string, number>; notes: string; recommendation: string }) => post(`/hr/candidates/${id}/interview`, 'PUT', v), onSuccess: settle })
export const useIssueOffer = (id: number) => useMutation({ mutationFn: (v: OfferInput) => post(`/hr/candidates/${id}/offer`, 'PUT', v), onSuccess: settle })

export const useRules = () => useQuery({ queryKey: ['hr', 'rules'], queryFn: () => api<{ rules: Rules; stage_sla: Record<string, number> }>('/hr/recruitment-rules') })
export const useSaveRules = () => useMutation({
  mutationFn: (v: { rules: Rules; stage_sla: Record<string, number> }) => api<{ rules: Rules; stage_sla: Record<string, number> }>('/hr/recruitment-rules', { method: 'PUT', json: v }),
  onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['hr', 'rules'] }); queryClient.invalidateQueries({ queryKey: ['hr', 'candidates'] }) },
})

export const useMyApprovals = () => useQuery({ queryKey: ['hr', 'approvals'], queryFn: () => api<{ data: MyApprovalItem[] }>('/hr/approvals').then(r => r.data) })
export const useDecideApproval = () => useMutation({
  mutationFn: (v: { id: number; decision: 'approved' | 'rejected'; reason?: string }) => api<{ data: HrAppointment }>(`/hr/approvals/${v.id}`, { json: { decision: v.decision, reason: v.reason } }).then(r => r.data),
  onSuccess: () => { ['approvals', 'dashboard', 'candidates', 'candidate'].forEach(k => queryClient.invalidateQueries({ queryKey: ['hr', k] })); queryClient.invalidateQueries({ queryKey: ['notices'] }) },
})

/* ---------- F-25 (من rules.ts) ---------- */
export const F25: { key: string; ar: string; en: string; items: { id: string; ar: string; en: string }[] }[] = [
  { key: 'c1', ar: 'المواصفات الوظيفية', en: 'Job specifications', items: [['readiness', 'الجاهزية العامة للوظيفة', 'General readiness'], ['physical', 'القدرة والجاهزية الجسدية', 'Physical readiness'], ['education', 'المؤهل العلمي والتخصص', 'Education & major'], ['experience', 'سنوات الخبرة ونوعيتها', 'Experience depth'], ['knowledge', 'المعرفة الفنية بالتخصص', 'Technical knowledge'], ['english', 'إتقان اللغة الإنكليزية', 'English']].map(([id, ar, en]) => ({ id, ar, en })) },
  { key: 'c2', ar: 'المهارات المباشرة', en: 'Direct skills', items: [['comm', 'مهارات التواصل', 'Communication'], ['negotiation', 'الإقناع والتفاوض', 'Negotiation'], ['computer', 'إتقان الحاسب والبرمجيات', 'Computer skills'], ['flex', 'المرونة والتكيف مع التغيير', 'Adaptability'], ['learning', 'استمرارية التعلم', 'Continuous learning'], ['overtime', 'الاستعداد للعمل الإضافي', 'Overtime readiness']].map(([id, ar, en]) => ({ id, ar, en })) },
  { key: 'c3', ar: 'الشخصية والسلوك', en: 'Personality & behaviour', items: [['professional', 'المظهر واللباقة المهنية', 'Professional presence'], ['behavior', 'السلوك وحسن التصرف', 'Conduct'], ['body', 'لغة الجسد والحضور', 'Body language'], ['confidence', 'الثقة بالنفس والاتزان', 'Self-confidence'], ['maturity', 'النضج المهني', 'Professional maturity'], ['responsibility', 'تحمل المسؤولية', 'Accountability']].map(([id, ar, en]) => ({ id, ar, en })) },
  { key: 'c4', ar: 'المهارات القيادية (إن وجدت)', en: 'Leadership (if applicable)', items: [['problem', 'حل المشكلات والتحليل', 'Problem solving'], ['goals', 'تحديد الأهداف وتحقيقها', 'Goal setting'], ['risk', 'إدارة المخاطر', 'Risk management'], ['planning', 'التخطيط', 'Planning'], ['influence', 'التأثير والإقناع', 'Influence'], ['team', 'قيادة وتحفيز الفريق', 'Team leadership']].map(([id, ar, en]) => ({ id, ar, en })) },
]
/** معاينة فورية فقط — الحساب المعتمد على الخادم (F25.php) */
export function f25Score(r: Record<string, number>) {
  const cats = F25.map(c => (c.items.reduce((a, i) => a + (r[i.id] ?? 0), 0) / 30) * 7.5)
  const total = cats.reduce((a, b) => a + b, 0)
  return { cats, total, pct: Math.round((total / 30) * 100) }
}
export function f25Label(pct: number): Bi {
  if (pct >= 85) return { ar: 'ممتاز — موصى بالتعيين', en: 'Excellent — recommend hire' }
  if (pct >= 70) return { ar: 'جيد جداً — مقبول', en: 'Very good — acceptable' }
  if (pct >= 50) return { ar: 'متوسط — يحتاج مراجعة', en: 'Average — needs review' }
  return { ar: 'غير كافٍ', en: 'Insufficient' }
}
export const f25Axes = (r: Record<string, number>, lang: 'ar' | 'en') => F25.map(g => ({ label: g[lang].split(' ').slice(0, 2).join(' '), value: g.items.reduce((a, i) => a + (r[i.id] ?? 0), 0) / 30 }))
