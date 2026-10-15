import type { Candidate, Job, Employee, Stage, State, Bi } from './types'
import { daysBetween, todayISO, isWeekend, fmtTime } from './dates'

/* ---------- recruitment ---------- */
export const STAGES: { id: Stage; ar: string; en: string; sla: number }[] = [
  { id: 'new', ar: 'طلبات جديدة', en: 'New', sla: 2 },
  { id: 'screening', ar: 'فرز أولي', en: 'Screening', sla: 3 },
  { id: 'interview', ar: 'المقابلة الأولية', en: 'HR interview', sla: 5 },
  { id: 'technical', ar: 'التقييم الفني', en: 'Technical review', sla: 5 },
  { id: 'offer', ar: 'عرض العمل', en: 'Offer', sla: 4 },
  { id: 'hired', ar: 'تم التعيين', en: 'Hired', sla: 0 },
  { id: 'rejected', ar: 'غير مقبول', en: 'Not selected', sla: 0 },
]
export const stageInfo = (s: Stage) => STAGES.find(x => x.id === s)!

export const DEGREES: Bi[] = [
  { ar: 'ثانوية', en: 'Secondary' }, { ar: 'معهد متوسط', en: 'Diploma' },
  { ar: 'إجازة جامعية', en: "Bachelor's" }, { ar: 'ماجستير', en: "Master's" }, { ar: 'دكتوراه', en: 'PhD' },
]

export const ENG: Record<string, Bi> = { excellent: { ar: 'ممتاز', en: 'Excellent' }, good: { ar: 'جيد', en: 'Good' }, fair: { ar: 'مقبول', en: 'Fair' }, weak: { ar: 'ضعيف', en: 'Weak' } }
export type MatchPart = { key: string; ar: string; en: string; weight: number; score: number; noteAr: string; noteEn: string }
export function matchScore(c: Candidate, job: Job | undefined): { total: number; parts: MatchPart[] } {
  if (!job) return { total: 0, parts: [] }
  const min = Math.max(1, job.minYears)
  const exp = c.yearsExp >= job.minYears ? 0.7 + 0.3 * Math.min(1, (c.yearsExp - job.minYears) / 3) : (c.yearsExp / min) * 0.6
  const deg = c.degreeLevel > job.degree ? 1 : c.degreeLevel === job.degree ? 0.85 : c.degreeLevel === job.degree - 1 ? 0.4 : 0.1
  const mid = (job.salaryMin + job.salaryMax) / 2
  const sal = c.salary <= mid ? 1 : c.salary <= job.salaryMax ? 0.75 : Math.max(0, 0.5 - (c.salary - job.salaryMax) / job.salaryMax * 2)
  const engMap: Record<string, number> = { excellent: 1, good: 0.7, fair: 0.4, weak: 0.1 }
  const eng = engMap[c.english] ?? 0.5
  const filled = [c.cvName, c.education.length, c.experience.length, c.refs.length, c.linkedin].filter(Boolean).length / 5
  const parts: MatchPart[] = [
    { key: 'exp', ar: 'سنوات الخبرة', en: 'Experience', weight: 30, score: exp, noteAr: `${c.yearsExp} من ${job.minYears} مطلوبة`, noteEn: `${c.yearsExp} of ${job.minYears} required` },
    { key: 'deg', ar: 'المؤهل العلمي', en: 'Qualification', weight: 25, score: deg, noteAr: DEGREES[c.degreeLevel]?.ar ?? '—', noteEn: DEGREES[c.degreeLevel]?.en ?? '—' },
    { key: 'sal', ar: 'الراتب المتوقع', en: 'Salary expectation', weight: 20, score: sal, noteAr: c.salary <= job.salaryMax ? 'ضمن النطاق' : 'أعلى من النطاق', noteEn: c.salary <= job.salaryMax ? 'Within band' : 'Above band' },
    { key: 'eng', ar: 'اللغة الإنكليزية', en: 'English', weight: 15, score: eng, noteAr: ENG[c.english]?.ar ?? c.english, noteEn: ENG[c.english]?.en ?? c.english },
    { key: 'file', ar: 'اكتمال الملف', en: 'File completeness', weight: 10, score: filled, noteAr: `${Math.round(filled * 100)}٪`, noteEn: `${Math.round(filled * 100)}%` },
  ]
  return { total: Math.round(parts.reduce((a, p) => a + p.score * p.weight, 0)), parts }
}
export function daysInStage(c: Candidate) { return daysBetween(c.stageAt, todayISO()) }
export function slaState(c: Candidate): 'ok' | 'due' | 'late' | 'none' {
  const st = stageInfo(c.stage); if (!st.sla) return 'none'
  const d = daysInStage(c); if (d > st.sla) return 'late'; if (d === st.sla) return 'due'; return 'ok'
}

/* ---------- interview F-25 ---------- */
export const F25: { key: string; ar: string; en: string; items: { id: string; ar: string; en: string }[] }[] = [
  { key: 'c1', ar: 'المواصفات الوظيفية', en: 'Job specifications', items: [
    { id: 'readiness', ar: 'الجاهزية العامة للوظيفة', en: 'General readiness' },
    { id: 'physical', ar: 'القدرة والجاهزية الجسدية', en: 'Physical readiness' },
    { id: 'education', ar: 'المؤهل العلمي والتخصص', en: 'Education & major' },
    { id: 'experience', ar: 'سنوات الخبرة ونوعيتها', en: 'Experience depth' },
    { id: 'knowledge', ar: 'المعرفة الفنية بالتخصص', en: 'Technical knowledge' },
    { id: 'english', ar: 'إتقان اللغة الإنكليزية', en: 'English' }] },
  { key: 'c2', ar: 'المهارات المباشرة', en: 'Direct skills', items: [
    { id: 'comm', ar: 'مهارات التواصل', en: 'Communication' },
    { id: 'negotiation', ar: 'الإقناع والتفاوض', en: 'Negotiation' },
    { id: 'computer', ar: 'إتقان الحاسب والبرمجيات', en: 'Computer skills' },
    { id: 'flex', ar: 'المرونة والتكيف مع التغيير', en: 'Adaptability' },
    { id: 'learning', ar: 'استمرارية التعلم', en: 'Continuous learning' },
    { id: 'overtime', ar: 'الاستعداد للعمل الإضافي', en: 'Overtime readiness' }] },
  { key: 'c3', ar: 'الشخصية والسلوك', en: 'Personality & behaviour', items: [
    { id: 'professional', ar: 'المظهر واللباقة المهنية', en: 'Professional presence' },
    { id: 'behavior', ar: 'السلوك وحسن التصرف', en: 'Conduct' },
    { id: 'body', ar: 'لغة الجسد والحضور', en: 'Body language' },
    { id: 'confidence', ar: 'الثقة بالنفس والاتزان', en: 'Self-confidence' },
    { id: 'maturity', ar: 'النضج المهني', en: 'Professional maturity' },
    { id: 'responsibility', ar: 'تحمل المسؤولية', en: 'Accountability' }] },
  { key: 'c4', ar: 'المهارات القيادية (إن وجدت)', en: 'Leadership (if applicable)', items: [
    { id: 'problem', ar: 'حل المشكلات والتحليل', en: 'Problem solving' },
    { id: 'goals', ar: 'تحديد الأهداف وتحقيقها', en: 'Goal setting' },
    { id: 'risk', ar: 'إدارة المخاطر', en: 'Risk management' },
    { id: 'planning', ar: 'التخطيط', en: 'Planning' },
    { id: 'influence', ar: 'التأثير والإقناع', en: 'Influence' },
    { id: 'team', ar: 'قيادة وتحفيز الفريق', en: 'Team leadership' }] },
]
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

/* ---------- onboarding F-19 ---------- */
export const F19: Bi[] = [
  ['تحضير قرار التعيين وأخذ الموافقات الإدارية', 'Prepare appointment decision and approvals'],
  ['إرسال قائمة الأوراق المطلوبة والتحاليل الطبية للمرشح', 'Send required documents & medical tests list'],
  ['استلام الأوراق الثبوتية والتحقق من صحتها', 'Receive and verify identity documents'],
  ['إبلاغ المدير المباشر بتاريخ المباشرة المعتمد', 'Notify direct manager of start date'],
  ['تحضير مذكرة الترحيب وتعميمها على الأقسام', 'Prepare and circulate welcome memo'],
  ['طلب للمعلوماتية: بريد، تحويلة، حاسب، هاتف', 'IT request: email, extension, PC, phone'],
  ['تحضير مكتب ومعدات وقرطاسية', 'Prepare desk, equipment and stationery'],
  ['إضافة الموظف على الهيكل التنظيمي', 'Add employee to the org structure'],
  ['التأكد من توقيع عرض العمل والقرار', 'Confirm signed offer and decision'],
  ['توقيع عقد العمل (3 نسخ) وميثاق السلوك المهني', 'Sign contract (3 copies) and code of conduct'],
  ['توقيع تعهد المحافظة على سرية العمل', 'Sign confidentiality undertaking'],
  ['توقيع التعهد بالتدريب والاشتراك بالتأمينات', 'Sign training and insurance undertaking'],
  ['توقيع إبراء ذمة وقائي', 'Sign precautionary clearance'],
  ['إعداد الملف الورقي والرقمي ومنح الرقم الوظيفي', 'Create paper & digital file, issue employee ID'],
  ['تعريف الموظف على النظام وجهاز الحضور ورصيد الإجازات', 'Introduce HR system, attendance device and leave'],
  ['إضافة الموظف لجدول الرواتب', 'Add to payroll'],
  ['إصدار بطاقة الشركة وبطاقة العمل', 'Issue company ID and business card'],
  ['شرح السياسات العامة وتسليم الوصف الوظيفي', 'Explain policies and hand over job description'],
  ['ضم الموظف للتأمينات الاجتماعية', 'Register with social insurance'],
  ['فتح حساب بنكي للراتب إن لزم', 'Open salary bank account if needed'],
  ['جولة تعريفية في أقسام الشركة', 'Orientation tour'],
  ['تعيين زميل مرشد للموظف', 'Assign onboarding buddy'],
  ['تسليم العهدة وتوقيع نموذج الاستلام', 'Hand over custody and sign receipt'],
  ['إضافة الموظف لقنوات التواصل الرسمية', 'Add to official team channels'],
  ['تحديد أهداف فترة التجربة (3 أشهر)', 'Set probation goals (3 months)'],
  ['مراجعة أسبوعية مع مدير القسم', 'Weekly review with manager'],
  ['مراجعة شهرية من الموارد البشرية', 'Monthly HR review'],
  ['تقييم نهاية التجربة وتثبيت التعيين', 'Probation review and confirmation'],
].map(([ar, en]) => ({ ar, en }))

/* ---------- attendance (Syrian Labour Law 17/2010 as per company SRS) ---------- */
export const SHIFT = { start: 8 * 60, end: 16 * 60, grace: 15, breakMin: 60, otThreshold: 30 }
const mins = (iso: string) => { const d = new Date(iso); return d.getHours() * 60 + d.getMinutes() }
export function calcDay(date: string, inAt?: string, outAt?: string) {
  const weekend = isWeekend(date)
  if (!inAt) return { status: weekend ? 'weekend' : 'absent', late: 0, early: 0, worked: 0, ot: 0, otRate: 0 } as const
  const i = mins(inAt), o = outAt ? mins(outAt) : undefined
  const late = !weekend && i > SHIFT.start + SHIFT.grace ? i - SHIFT.start : 0
  const early = !weekend && o !== undefined && o < SHIFT.end ? SHIFT.end - o : 0
  let worked = o !== undefined ? o - i : 0
  if (worked > 5 * 60) worked -= SHIFT.breakMin
  let ot = 0, otRate = 0
  if (o !== undefined) {
    if (weekend) { ot = worked; otRate = 1 }
    else if (o - SHIFT.end >= SHIFT.otThreshold) { ot = o - SHIFT.end; otRate = o > 22 * 60 ? 0.5 : 0.25 }
  }
  return { status: weekend ? 'weekend' : late ? 'late' : 'present', late, early, worked, ot, otRate } as const
}
export function hourlyRate(e: Employee) { return e.salary / 30 / 8 }
export function minsLabel(m: number, lang: 'ar' | 'en') {
  const h = Math.floor(m / 60), mm = m % 60
  if (lang === 'ar') return h ? `${h} س ${mm ? mm + ' د' : ''}`.trim() : `${mm} د`
  return h ? `${h}h ${mm ? mm + 'm' : ''}`.trim() : `${mm}m`
}
export { fmtTime }

/* ---------- leave entitlement ---------- */
export function annualEntitlement(e: Employee) {
  const years = daysBetween(e.hireDate, todayISO()) / 365
  const age = daysBetween(e.dob, todayISO()) / 365
  if (years >= 10 || age >= 50) return 30
  if (years >= 1) return 21
  return 14
}
export const SICK_RULE = { full: 30, p80: 30, p75: 30 }

export function settlement(e: Employee, lastDay: string, s: State) {
  const daily = e.salary / 30
  const remaining = Math.max(0, annualEntitlement(e) - e.leaveUsed)
  const dayOfMonth = Number(lastDay.slice(8, 10))
  const proRata = daily * dayOfMonth
  const unreturned = s.assets.filter(a => a.empId === e.id && a.status !== 'available')
  const deduction = unreturned.reduce((a, x) => a + x.value, 0)
  const leavePay = remaining * daily
  return { daily, remaining, proRata, leavePay, deduction, unreturned, total: proRata + leavePay - deduction }
}

export const LEAVE_TYPES: Record<string, Bi> = {
  annual: { ar: 'إجازة سنوية', en: 'Annual leave' }, sick: { ar: 'إجازة مرضية', en: 'Sick leave' },
  unpaid: { ar: 'إجازة بلا أجر', en: 'Unpaid leave' }, hourly: { ar: 'مغادرة ساعية', en: 'Hourly permission' },
  mission: { ar: 'مهمة رسمية', en: 'Official mission' }, justify: { ar: 'تبرير تأخير / غياب', en: 'Late / absence justification' },
}
