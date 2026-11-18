import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from './api'
import { queryClient } from './session'
import type { Bi } from './types'
import type { LookupItem } from './codes'
import type { Step } from './requests'

// الدفعة 23 — موديول التأهيل والتدريب والتطوير (SRS التدريب)

export type Path = 'qualification' | 'training' | 'development'
export type Person = Bi & { id: number; code?: string }
export interface Lookups {
  programs: (Bi & { id: number; code: string; path: Path; delivery: string; hours: number; method: string; mandatory: boolean; competencies: { id: number; level: number }[] })[]
  trainers: (Bi & { id: number; kind: string; status: string; rating: number | null })[]; centers: (Bi & { id: number; status: string; quality: number | null })[]
  competencies: (Bi & { id: number; kind: string })[]; departments: (Bi & { id: number; head_id: number | null })[]; branches: (Bi & { id: number })[]
  employees: (Person & { department_id: number; status: string })[]; jobs: LookupItem[]; currencies: LookupItem[]
  cfg: { mode: string; pass_mark: number; attendance_min: number; min_evidence: number; survey_items: string[]; impact_items: string[]; cost_types: string[]; impact_points: number[]; capacity: Record<string, [number, number]>; min_quotes: number }
}
export interface Can { manage: boolean; create: boolean; decide: boolean; export: boolean; settings: boolean; director: boolean }
export interface SessionRow {
  id: number; ref: string; title: string; program: { id: number; code: string; path: Path; delivery: string; hours: number; method: string; has_test: boolean } | null; starts_on: string; ends_on: string
  meetings: { date: string; from: string | null; to: string | null }[]; trainer: Person | null; center: string | null; center_id: number | null; location_id: number | null; place: string | null; link: string | null; capacity: number; status: string; counts: Record<string, number>
  plan_item_id: number | null; currency: string; total_cost: number
}
export interface EnrollmentRow {
  id: number; employee: (Person & { status: string }) | null; status: string; reason: string | null; exception: string | null; manager_ok: boolean | null; attendance: Record<string, string>; attendance_pct: number | null
  pre_score: number | null; post_score: number | null; result: 'passed' | 'failed' | 'withdrawn' | null; result_reason: string | null; failure: { decision: string | null; note?: string } | null; cost: number | null; apply_plan: boolean
  commitment: { id: number; status: string; months: number; cost: number } | null
}
export interface SessionFull extends SessionRow {
  costs: { type: string; amount: number; currency?: string }[]; quotes: { center: string; price: number; duration: string; prior: string; terms: string; score: number }[]; quote_choice: number | null; quote_reason: string | null
  logistics: { item: string; done: boolean }[]; warnings: (Bi & { key: string })[]; survey_url: string; checkin_url: string; surveys: { n: number; avg: number | null }
  rating: { scores: Record<string, number>; avg: number; recommendation: string; note: string | null } | null; kpi: { before?: number; after?: number; note?: string } | null; roi: { benefit?: number; note?: string } | null
  program_full: { id: number; code: string; title: string; pass_mark: number | null; attendance_min: number | null; has_test: boolean; competencies: { id: number; level: number }[] | null; hours: number; delivery: string } | null
  enrollments: EnrollmentRow[]
}
export interface Need {
  id: number; ref: string; title: string; employee: Person | null; employees: number[]; department: Person | null; competency: Person | null; current_level: number | null; required_level: number | null
  path: Path; level: string; priority: string; score: number; factors: Record<string, number> | null; source: string; cause: string | null; evidence: string[]; evidence_override: string | null
  objective: Record<string, string> | null; status: string; note: string | null; program: { id: number; code: string; title: string } | null; estimated_cost: number | null; preferred_date: string | null
  campaign_id: number | null; plan_item_id: number | null; competency_id: number | null; department_id: number | null; program_id: number | null; created_at: string
}
export interface NeedGroup { key: string; title: string; ids: number[]; count: number; program: { id: number; title: string; code: string } | null; path: Path; score: number; cost: number }
export interface PlanItem { id: number; plan_id: number; program_id: number | null; title: string; path: Path; delivery: string; audience: string; location_id: number | null; department_id: number | null; month: number | null; trainees: number
  estimated_cost: number; actual_cost: number; priority: string; objective: string | null; status: string; changes: { at: string; by: string; what: string; reason: string; cost_delta: number }[] | null; needs: number; sessions: { id: number; ref: string; status: string; starts_on: string }[] }
export interface Progress { items: number; done: number; planned_cost: number; actual_cost: number; planned_trainees: number; trained: number; hours: number; budget: number; by_quarter: Record<string, number>; by_path: Record<string, number> }
export interface Plan { id: number; year: number; title: string; scope: string; location_id: number | null; status: string; budget: number; currency: string; budget_split: Record<string, number> | null; baseline: { at: string } | null; finance_note: string | null
  progress: Progress; checks: (Bi & { key: string; value: number })[]; items: PlanItem[]; steps: Step[]; step_id: number | null; can_act: boolean }
export interface Record_ {
  enrollments: { id: number; title: string; path: Path; delivery: string; hours: number; from: string; to: string; provider: string | null; place: string | null; status: string; result: string | null; attendance_pct: number | null; post_score: number | null; cost: number | null; currency: string; apply_plan: unknown; session_id: number; impact: number | null }[]
  certificates: { id: number; no: string | null; code: string | null; type: string; title: string; issuer: string | null; issued_on: string; valid_to: string | null; status: string; source: string; file_id: number | null }[]
  commitments: { id: number; title: string; cost: number; currency: string; months: number; ends_on: string | null; status: string; days_left: number | null; due: number | null; body: string | null; signed_at: string | null }[]
  needs: { id: number; ref: string; title: string; status: string; priority: string; path: Path }[]; competencies: (Bi & { id: number; required: number | null; current: number | null })[]
  qualification: { id: number; status: string; progress: number; stations: unknown[]; evaluation: unknown } | null
  totals: { hours_year: number; hours_all: number; cost_year: number; cost_all: number; courses: number }
}

const qs = (o: Record<string, string | number | undefined>) => { const s = new URLSearchParams(Object.entries(o).filter(([, v]) => v !== undefined && v !== '').map(([k, v]) => [k, String(v)])).toString(); return s ? `?${s}` : '' }
const changed = () => { queryClient.invalidateQueries({ queryKey: ['td'] }); queryClient.invalidateQueries({ queryKey: ['me', 'approvals'] }) }

export const useTd = (year?: number) => useQuery({ queryKey: ['td', 'home', year], queryFn: () => api<{ year: number; plan: { id: number; status: string; progress: Progress; currency: string } | null; week: SessionRow[]; pending: Record<string, number>; alerts: { key: string; n: number }[]; lookups: Lookups; can: Can }>(`/td${qs({ year })}`) })
export const useTdAct = () => useMutation({ mutationFn: ({ path, v, method }: { path: string; v?: unknown; method?: string }) => api<Record<string, unknown>>(path.startsWith('/') ? path : `/td/${path}`, { method: method ?? 'POST', json: v ?? {} }), onSuccess: changed })
export const uploadTdFile = (file: File) => { const f = new FormData(); f.append('file', file); return api<{ id: number; name: string }>('/me/training/files', { method: 'POST', body: f }) }
export const useNeeds = (f: Record<string, string | undefined>) => useQuery({ queryKey: ['td', 'needs', f], placeholderData: p => p, queryFn: () => api<{ data: Need[]; groups: NeedGroup[] }>(`/td/needs${qs(f)}`) })
export const useMatrix = (f: Record<string, string | undefined>) => useQuery({ queryKey: ['td', 'matrix', f], queryFn: () => api<{ competencies: (Bi & { id: number })[]; rows: (Person & { cells: Record<string, { required: number; current: number | null }> })[] }>(`/td/matrix${qs(f)}`) })
export const useCampaigns = () => useQuery({ queryKey: ['td', 'campaigns'], queryFn: () => api<{ data: { id: number; title: string; year: number; scope: string; opens_on: string; closes_on: string; status: string; departments: (Bi & { id: number; submitted: string | null })[]; needs: number }[] }>('/td/campaigns') })
export const useCampaignForm = (id: number, dept: number) => useQuery({ queryKey: ['td', 'campaign-form', id, dept], queryFn: () => api<{ campaign: { id: number; title: string; year: number; status: string; closes_on: string }; department: Person; prefill: { employees: Person[]; gaps: { employee_id: number; employee: Bi; competency_id: number; competency: Bi; current: number | null; required: number }[]; certificates: { employee_id: number; title: string; valid_to: string }[] }; needs: Need[] }>(`/td/campaigns/${id}/form/${dept}`) })
export const usePlans = () => useQuery({ queryKey: ['td', 'plans'], queryFn: () => api<{ data: { id: number; year: number; title: string; scope: string; status: string; budget: number; currency: string; progress: Progress }[]; groups: NeedGroup[] }>('/td/plans') })
export const usePlan = (id: number | null) => useQuery({ queryKey: ['td', 'plan', id], enabled: !!id, queryFn: () => api<{ data: Plan }>(`/td/plans/${id}`).then(r => r.data) })
export const useSessions = (f: Record<string, string | undefined>) => useQuery({ queryKey: ['td', 'sessions', f], placeholderData: p => p, queryFn: () => api<{ data: SessionRow[] }>(`/td/sessions${qs(f)}`).then(r => r.data) })
export const useSession = (id: number | null, me = false) => useQuery({ queryKey: ['td', 'session', id, me], enabled: !!id, queryFn: () => api<{ data: SessionFull }>(me ? `/me/training/sessions/${id}` : `/td/sessions/${id}`).then(r => r.data) })
export const usePrograms = () => useQuery({ queryKey: ['td', 'programs'], queryFn: () => api<{ data: (Record<string, unknown> & { id: number; code: string; title: string; title_en: string | null; path: Path; delivery: string; hours: number; runs: number; review_due: boolean; active: boolean })[] }>('/td/programs').then(r => r.data) })
export const useProviders = () => useQuery({ queryKey: ['td', 'providers'], queryFn: () => api<{ data: (Record<string, unknown> & { id: number; kind: string; ar: string; en: string; status: string; rating: number | null; hours_given: number })[]; centers: (Record<string, unknown> & { id: number; name: string; status: string; quality: number | null })[]; experts: Person[] }>('/td/trainers') })
export const useCompetencies = () => useQuery({ queryKey: ['td', 'competencies'], queryFn: () => api<{ data: (Bi & { id: number; kind: string; levels: string[] | null; active: boolean })[]; jobs: Record<string, Record<string, number>>; job_list: LookupItem[] }>('/td/competencies') })
export const useCalendar = (year: number) => useQuery({ queryKey: ['td', 'calendar', year], queryFn: () => api<{ year: number; sessions: { id: number; title: string; from: string; to: string; path: Path; delivery: string; audience: string; status: string; location_id: number | null }[]; items: { id: number; title: string; month: number; path: Path; delivery: string; audience: string; status: string }[]; holidays: (Bi & { date: string })[] }>(`/td/calendar?year=${year}`) })
export const useCertificates = (status: string) => useQuery({ queryKey: ['td', 'certs', status], queryFn: () => api<{ data: (Record_['certificates'][number] & { employee: Person | null; status_note: string | null })[] }>(`/td/certificates${qs({ status })}`).then(r => r.data) })
export const useQualifications = () => useQuery({ queryKey: ['td', 'quals'], queryFn: () => api<{ data: { id: number; employee: (Person & { hire_date: string | null; status: string }) | null; stations: { department: Bi | null; trainer: Bi | null; trainer_id: number; topics: string; from: string; to: string; note: string | null; signed_at: string | null; signed_by: string | null }[]; status: string; progress: number; evaluation: { recommendation: string; note: string | null } | null; certificate_id: number | null }[] }>('/td/qualifications').then(r => r.data) })
export const useImpacts = (me = false, status = 'pending') => useQuery({ queryKey: ['td', 'impacts', me, status], queryFn: () => api<{ data: { id: number; point: number; kind: 'talk' | 'behavior' | 'kpi'; due_on: string; status: string; avg: number | null; overdue: boolean; employee: (Bi & { code: string }) | null; session: { id: number; title: string } | null; items: string[]; barriers: string | null; notes: string | null }[] }>(`${me ? '/me/training' : '/td'}/impacts?status=${status}`).then(r => r.data) })
export const useCommitments = () => useQuery({ queryKey: ['td', 'commitments'], queryFn: () => api<{ data: (Record_['commitments'][number] & { recovery: number | null; mode: string; employee: Person | null })[] }>('/td/commitments').then(r => r.data) })
export const useCbps = () => useQuery({ queryKey: ['td', 'cbps'], queryFn: () => api<{ data: Cbp[]; plans: { id: number; year: number; title: string; status: string }[] }>('/td/cbps') })
export type Cbp = { id: number; title: string; owner: string | null; description: string | null; status: string; starts_on: string | null; ends_on: string | null
  goals: { id: string; title: string; kpi?: string; baseline?: string; target?: string; achieved?: string; capacities: { competency_id: number; job_id?: number | null; count: number; level: number; by?: string }[]; initiatives: { id: string; title: string; kind: string; owner?: string; cost?: number; kpi?: string; due?: string; progress: number; plan_item_id?: number }[] }[]
  gaps: Record<string, Record<string, { required: number; have: number; gap: number }>> }
export const useTdReports = (year: number) => useQuery({ queryKey: ['td', 'reports', year], queryFn: () => api<{ data: TdReports }>(`/td/reports?year=${year}`).then(r => r.data) })
export type TdReports = { kpis: Record<string, number | null>; by_path: Record<string, { sessions: number; cost: number }>; by_delivery: Record<string, number>; fairness: Record<string, { headcount: number; trained: number; pct: number }>
  needs: Record<string, number>; needs_source: Record<string, number>; expiring: { title: string; valid_to: string; employee: Bi | null }[]; commitments: { active: number; at_risk: number; breached: number }; impact_followup: { due: number; done: number } }
export const useTdSettings = () => useQuery({ queryKey: ['td', 'settings'], queryFn: () => api<{ data: Record<string, unknown>; defaults: Record<string, unknown>; can: { edit: boolean } }>('/td/settings') })
export const useRecord = (id: number) => useQuery({ queryKey: ['td', 'record', id], queryFn: () => api<{ data: Record_ & { employee: Person & { title: Bi; hire_date: string | null } } }>(`/td/employees/${id}/record`).then(r => r.data) })
export const useMyTraining = () => useQuery({ queryKey: ['td', 'me'], queryFn: () => api<MyTraining>('/me/training') })
export type MyTraining = { record: Record_ | null; mode: string; to_sign: { id: number; title: string; cost: number; currency: string; months: number; body: string | null }[]; upcoming: { id: number; status: string; session: SessionRow }[]
  team_nominations: { id: number; employee: Bi; session: { title: string; starts_on: string } }[]; impacts: number; stations: { q: number; i: number; employee: Bi; topics: string; from: string; to: string }[]; evaluations: { id: number; employee: Bi }[]
  trainer_sessions: SessionRow[]; competencies: (Bi & { id: number })[]; is_manager: boolean }

export const PATH: Record<Path, [string, string, string]> = { qualification: ['تأهيل', 'Qualification', 'var(--info)'], training: ['تدريب', 'Training', 'var(--brand)'], development: ['تطوير أداء', 'Development', 'var(--brass)'] }
export const DELIVERY: Record<string, [string, string]> = { internal: ['داخلي — مدرب داخلي', 'Internal — internal trainer'], internal_ext: ['داخلي — مدرب خارجي', 'Internal — external trainer'], external: ['خارجي لدى مركز', 'External centre'],
  ojt: ['تدريب أثناء العمل', 'On-the-job'], stage: ['ستاج داخلي', 'Internal rotation'], elearning: ['تعلم إلكتروني', 'E-learning'], coaching: ['كوتشينغ / إرشاد', 'Coaching'], event: ['مؤتمر / ورشة / زيارة', 'Event / visit'] }
export const AUDIENCE: Record<string, [string, string]> = { staff: ['الكوادر', 'Staff'], departments: ['الأقسام', 'Departments'], managers: ['المدراء', 'Managers'] }
export const S_STATUS: Record<string, [string, string, 'brand' | 'brass' | 'info' | 'danger' | 'neutral' | 'warn']> = {
  planned: ['مخطط', 'Planned', 'neutral'], enrolling: ['قيد الترشيح', 'Enrolling', 'info'], confirmed: ['مؤكد', 'Confirmed', 'info'], in_progress: ['قيد التنفيذ', 'In progress', 'brass'],
  completed: ['منفذ', 'Completed', 'brand'], closed: ['مغلق', 'Closed', 'neutral'], cancelled: ['ملغى', 'Cancelled', 'danger'], postponed: ['مؤجل', 'Postponed', 'warn'], done: ['منفذ', 'Done', 'brand'],
}
export const E_STATUS: Record<string, [string, string, 'brand' | 'brass' | 'info' | 'danger' | 'neutral' | 'warn']> = {
  nominated: ['مرشح', 'Nominated', 'info'], pending_commitment: ['بانتظار التعهد', 'Awaiting commitment', 'brass'], confirmed: ['مؤكد', 'Confirmed', 'brand'], waitlist: ['قائمة انتظار', 'Waitlist', 'neutral'],
  withdrawn: ['منسحب', 'Withdrawn', 'neutral'], completed: ['أكمل', 'Completed', 'brand'],
}
export const N_STATUS: Record<string, [string, string, 'brand' | 'brass' | 'info' | 'danger' | 'neutral' | 'warn']> = {
  suggested: ['مقترح آلياً', 'Auto-suggested', 'info'], submitted: ['مقدَّم', 'Submitted', 'brass'], approved: ['معتمد', 'Approved', 'brand'], rejected: ['مرفوض', 'Rejected', 'danger'],
  not_training: ['ليس احتياجاً تدريبياً', 'Not a training need', 'neutral'], planned: ['في الخطة', 'In the plan', 'info'], done: ['منجز', 'Done', 'brand'],
}
export const P_STATUS: Record<string, [string, string, 'brand' | 'brass' | 'info' | 'danger' | 'neutral' | 'warn']> = { draft: ['مسودة', 'Draft', 'neutral'], pending_hr: ['بانتظار الموارد البشرية', 'Awaiting HR', 'brass'], pending_exec: ['بانتظار الاعتماد', 'Awaiting approval', 'brass'], approved: ['معتمدة', 'Approved', 'brand'], rejected: ['مرفوضة', 'Rejected', 'danger'] }
export const C_STATUS: Record<string, [string, string, 'brand' | 'brass' | 'info' | 'danger' | 'neutral' | 'warn']> = { generated: ['بانتظار التوقيع', 'Awaiting signature', 'brass'], active: ['سارٍ', 'Active', 'brand'], fulfilled: ['منتهٍ بالوفاء', 'Fulfilled', 'neutral'],
  breached: ['مُخَلّ به — بانتظار القرار', 'Breached — awaiting decision', 'danger'], recovered: ['مسترد', 'Recovered', 'warn'], waived: ['مُعفى', 'Waived', 'neutral'], cancelled: ['ملغى', 'Cancelled', 'neutral'] }
export const CAUSE: Record<string, [string, string]> = { knowledge: ['لا يعرف كيف ينفّذ (معرفة أو مهارة)', 'Does not know how (knowledge or skill)'], motivation: ['يعرف ولا يريد (دافعية)', 'Knows but does not want to (motivation)'],
  resources: ['تنقصه أدوات أو صلاحيات (موارد)', 'Lacks tools or authority (resources)'], procedures: ['الإجراءات معقدة', 'Procedures are complicated'] }
export const EVIDENCE: Record<string, [string, string]> = { survey: ['استبيان', 'Survey'], interview: ['مقابلة مدير', 'Manager interview'], observation: ['ملاحظة مباشرة', 'Direct observation'], appraisal: ['تقييم أداء', 'Appraisal'], kpi: ['مؤشرات عمل', 'Work KPIs'] }
export const CERT: Record<string, [string, string]> = { completion: ['إتمام', 'Completion'], competency: ['كفاءة', 'Competency'], excellence: ['تفوق', 'Excellence'], stage: ['ستاج', 'Internship'], professional: ['اعتماد مهني', 'Professional'], qualification: ['إتمام التأهيل', 'Qualification'] }
export const ATT: Record<string, [string, string]> = { present: ['حاضر', 'Present'], absent: ['غائب', 'Absent'], late: ['متأخر', 'Late'], excused: ['معذور', 'Excused'] }
