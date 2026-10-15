import type { State, Employee, Candidate, Job, Asset, DaySummary, LeaveReq, Dept, Stage, Resignation, Bi } from '../lib/types'
import { todayISO, addDays, isWeekend } from '../lib/dates'

function rng(seed: number) { return () => { seed |= 0; seed = (seed + 0x6d2b79f5) | 0; let t = Math.imul(seed ^ (seed >>> 15), 1 | seed); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296 } }
const b = (ar: string, en: string): Bi => ({ ar, en })

export const CITIES: Record<string, Bi> = {
  dam: b('دمشق', 'Damascus'), alp: b('حلب', 'Aleppo'), hom: b('حمص', 'Homs'), lat: b('اللاذقية', 'Latakia'), tar: b('طرطوس', 'Tartus'), ham: b('حماة', 'Hama'),
}

export const DEPTS: Dept[] = [
  { id: 'exec', ar: 'الإدارة التنفيذية', en: 'Executive Office' },
  { id: 'hr', ar: 'الموارد البشرية', en: 'Human Resources' },
  { id: 'fin', ar: 'الإدارة المالية', en: 'Finance' },
  { id: 'it', ar: 'تقنية المعلومات', en: 'Information Technology' },
  { id: 'sales', ar: 'المبيعات', en: 'Sales' },
  { id: 'ops', ar: 'العمليات والمشاريع', en: 'Operations & Projects' },
  { id: 'cs', ar: 'خدمة العملاء', en: 'Customer Service' },
  { id: 'admin', ar: 'الشؤون الإدارية', en: 'Administration' },
]

type E = [string, string, 'M' | 'F', string, string, string, string, number, string]
// name, nameEn, gender, titleAr, titleEn, dept, city, salary USD, hire (years ago as "y.m")
const EMPS: E[] = [
  ['هاني السمان', 'Hani Al-Samman', 'M', 'الرئيس التنفيذي', 'Chief Executive Officer', 'exec', 'dam', 4800, '9.2'],
  ['مازن الدروبي', 'Mazen Al-Droubi', 'M', 'المدير التنفيذي', 'Executive Director', 'exec', 'dam', 3600, '7.5'],
  ['رشا الخطيب', 'Rasha Al-Khatib', 'F', 'مديرة الموارد البشرية', 'HR Director', 'hr', 'dam', 2400, '5.1'],
  ['باسل الأتاسي', 'Basel Al-Atassi', 'M', 'المدير المالي', 'Finance Director', 'fin', 'dam', 2600, '6.3'],
  ['نور الحسن', 'Nour Al-Hassan', 'F', 'أخصائية توظيف', 'Recruitment Specialist', 'hr', 'dam', 850, '2.4'],
  ['ريم قباني', 'Reem Kabbani', 'F', 'أخصائية رواتب وشؤون موظفين', 'Payroll & Personnel Officer', 'hr', 'dam', 780, '3.7'],
  ['سامر الحلبي', 'Samer Al-Halabi', 'M', 'مطور برمجيات أول', 'Senior Software Developer', 'it', 'dam', 1450, '3.1'],
  ['علاء شيخاني', 'Alaa Sheikhani', 'M', 'مدير تقنية المعلومات', 'IT Manager', 'it', 'dam', 2100, '6.8'],
  ['لمى العظمة', 'Lama Al-Azmeh', 'F', 'مهندسة شبكات', 'Network Engineer', 'it', 'dam', 1050, '2.2'],
  ['يزن المصري', 'Yazan Al-Masri', 'M', 'مطور واجهات', 'Front-end Developer', 'it', 'dam', 900, '1.4'],
  ['كنان سليمان', 'Kinan Suleiman', 'M', 'فني دعم تقني', 'IT Support Technician', 'it', 'hom', 520, '0.9'],
  ['دانة الشامي', 'Dana Al-Shami', 'F', 'محاسبة أولى', 'Senior Accountant', 'fin', 'dam', 1100, '4.6'],
  ['عمر النابلسي', 'Omar Al-Nabulsi', 'M', 'محاسب', 'Accountant', 'fin', 'alp', 680, '1.8'],
  ['فراس الجندي', 'Firas Al-Jundi', 'M', 'مدير المبيعات', 'Sales Manager', 'sales', 'dam', 2000, '5.5'],
  ['هبة الزعبي', 'Heba Al-Zoubi', 'F', 'مسؤولة مبيعات', 'Sales Executive', 'sales', 'dam', 700, '1.2'],
  ['جود الأيوبي', 'Joud Al-Ayoubi', 'F', 'مسؤولة مبيعات', 'Sales Executive', 'sales', 'alp', 650, '0.7'],
  ['محمد الحمصي', 'Mohammad Al-Homsi', 'M', 'مسؤول حسابات كبرى', 'Key Account Manager', 'sales', 'hom', 1150, '3.3'],
  ['أنس داوود', 'Anas Dawoud', 'M', 'مندوب مبيعات', 'Sales Representative', 'sales', 'lat', 480, '0.5'],
  ['طارق الحكيم', 'Tarek Al-Hakim', 'M', 'مدير العمليات والمشاريع', 'Operations Manager', 'ops', 'dam', 2300, '6.1'],
  ['سلاف حداد', 'Sulaf Haddad', 'F', 'مديرة مشاريع', 'Project Manager', 'ops', 'dam', 1500, '3.9'],
  ['خالد العمر', 'Khaled Al-Omar', 'M', 'مهندس تنفيذ', 'Site Engineer', 'ops', 'ham', 900, '2.6'],
  ['رامي عيسى', 'Rami Issa', 'M', 'مهندس تنفيذ', 'Site Engineer', 'ops', 'tar', 880, '1.9'],
  ['إياد الصباغ', 'Iyad Al-Sabbagh', 'M', 'فني تركيب', 'Installation Technician', 'ops', 'dam', 520, '4.2'],
  ['وسيم مرعي', 'Wassim Mar\'i', 'M', 'فني تركيب', 'Installation Technician', 'ops', 'hom', 500, '1.1'],
  ['ميس الكردي', 'Mais Al-Kurdi', 'F', 'مشرفة خدمة العملاء', 'Customer Service Supervisor', 'cs', 'dam', 900, '3.4'],
  ['تالا خوري', 'Tala Khoury', 'F', 'موظفة خدمة عملاء', 'Customer Service Agent', 'cs', 'dam', 450, '0.8'],
  ['بشرى العلي', 'Bushra Al-Ali', 'F', 'موظفة خدمة عملاء', 'Customer Service Agent', 'cs', 'lat', 430, '1.6'],
  ['حسام البيطار', 'Hussam Al-Bitar', 'M', 'موظف خدمة عملاء', 'Customer Service Agent', 'cs', 'alp', 430, '2.1'],
  ['غيث المحمد', 'Ghaith Al-Mohammad', 'M', 'مدير الشؤون الإدارية', 'Administration Manager', 'admin', 'dam', 1250, '7.0'],
  ['سوسن درويش', 'Sawsan Darwish', 'F', 'سكرتيرة تنفيذية', 'Executive Secretary', 'admin', 'dam', 620, '2.9'],
  ['أيهم يوسف', 'Ayham Youssef', 'M', 'سائق', 'Driver', 'admin', 'dam', 380, '5.0'],
  ['زياد قاسم', 'Ziad Kassem', 'M', 'مسؤول مستودع', 'Warehouse Officer', 'admin', 'dam', 520, '3.0'],
]

const JOBS: Omit<Job, 'postedAt' | 'status'>[] = [
  { id: 'j1', code: 'REQ-2026-031', title: b('مطور برمجيات أول', 'Senior Software Developer'), dept: 'it', city: CITIES.dam, type: b('دوام كامل', 'Full-time'), openings: 2, minYears: 5, degree: 2, salaryMin: 1200, salaryMax: 1700,
    summary: b('تطوير وصيانة أنظمة الشركة الداخلية والعمل مع فريق المشاريع على حلول العملاء.', 'Build and maintain internal systems and deliver client solutions with the projects team.'),
    reqs: [b('إجازة في هندسة المعلوماتية أو ما يعادلها', 'Degree in IT engineering or equivalent'), b('خبرة 5 سنوات في تطوير تطبيقات الويب', '5 years building web applications'), b('خبرة بقواعد البيانات العلائقية', 'Relational database experience')] },
  { id: 'j2', code: 'REQ-2026-034', title: b('محاسب', 'Accountant'), dept: 'fin', city: CITIES.dam, type: b('دوام كامل', 'Full-time'), openings: 1, minYears: 2, degree: 2, salaryMin: 600, salaryMax: 850,
    summary: b('تسجيل القيود اليومية، مطابقة الحسابات المصرفية، وإعداد التقارير الشهرية.', 'Daily entries, bank reconciliations and monthly reporting.'),
    reqs: [b('إجازة في المحاسبة', 'Accounting degree'), b('إتقان برامج المحاسبة', 'Accounting software'), b('خبرة سنتين على الأقل', 'At least 2 years experience')] },
  { id: 'j3', code: 'REQ-2026-036', title: b('مسؤول مبيعات', 'Sales Executive'), dept: 'sales', city: CITIES.alp, type: b('دوام كامل', 'Full-time'), openings: 2, minYears: 1, degree: 2, salaryMin: 500, salaryMax: 800,
    summary: b('بناء علاقات مع عملاء الشركات في حلب ومتابعة العروض حتى التعاقد.', 'Build corporate client relationships in Aleppo and follow quotes to contract.'),
    reqs: [b('خبرة في مبيعات الشركات', 'B2B sales experience'), b('امتلاك رخصة قيادة', 'Driving licence'), b('مهارات تفاوض وعرض', 'Negotiation & presentation skills')] },
  { id: 'j4', code: 'REQ-2026-038', title: b('موظف خدمة عملاء', 'Customer Service Agent'), dept: 'cs', city: CITIES.dam, type: b('ورديات', 'Shifts'), openings: 3, minYears: 0, degree: 1, salaryMin: 380, salaryMax: 500,
    summary: b('الرد على اتصالات العملاء ومتابعة الشكاوى ضمن نظام ورديات.', 'Handle customer calls and follow up complaints on a shift basis.'),
    reqs: [b('مهارات تواصل ممتازة', 'Excellent communication'), b('استعداد للعمل بنظام الورديات', 'Shift availability'), b('استخدام الحاسب', 'Computer literacy')] },
  { id: 'j5', code: 'REQ-2026-040', title: b('مهندس تنفيذ مشاريع', 'Project Site Engineer'), dept: 'ops', city: CITIES.hom, type: b('دوام كامل', 'Full-time'), openings: 1, minYears: 3, degree: 2, salaryMin: 850, salaryMax: 1100,
    summary: b('الإشراف على تنفيذ مشاريع التيار الخفيف والبنية التحتية في المنطقة الوسطى.', 'Supervise low-current and infrastructure projects in the central region.'),
    reqs: [b('هندسة كهربائية أو اتصالات', 'Electrical or telecom engineering'), b('خبرة 3 سنوات بالتنفيذ', '3 years on-site'), b('استعداد للتنقل بين المحافظات', 'Willing to travel between governorates')] },
  { id: 'j6', code: 'REQ-2026-041', title: b('أخصائي موارد بشرية', 'HR Generalist'), dept: 'hr', city: CITIES.dam, type: b('دوام كامل', 'Full-time'), openings: 1, minYears: 3, degree: 2, salaryMin: 700, salaryMax: 950,
    summary: b('متابعة شؤون الموظفين والحضور والإجازات وتطبيق السياسات الداخلية.', 'Run personnel affairs, attendance, leave and internal policies.'),
    reqs: [b('خبرة 3 سنوات في الموارد البشرية', '3 years HR experience'), b('معرفة بقانون العمل السوري', 'Knowledge of Syrian labour law'), b('إتقان Excel', 'Strong Excel')] },
]

type C = [string, 'M' | 'F', string, Stage, number, number, number, number, string, string]
const CAND_EN = ['Ahmad Al-Mansour', 'Raghad Al-Sayed', 'Mohannad Al-Khatib', 'Shahd Al-Abdullah', 'Qusay Al-Hamad', 'Nada Ismail', 'Maher Al-Shaar', 'Jana Al-Saleh', 'Leen Al-Ali', 'Bilal Hamdan']
// name, gender, jobId, stage, daysInStage, yearsExp, degree, salary, english, city
const CANDS: C[] = [
  ['أحمد المنصور', 'M', 'j1', 'new', 1, 6, 2, 1500, 'good', 'دمشق'],
  ['رغد السيد', 'F', 'j2', 'new', 0, 3, 2, 720, 'fair', 'دمشق'],
  ['مهند الخطيب', 'M', 'j3', 'new', 3, 2, 2, 650, 'good', 'حلب'],
  ['شهد العبدالله', 'F', 'j4', 'screening', 2, 1, 1, 420, 'fair', 'دمشق'],
  ['قصي الحمد', 'M', 'j1', 'screening', 4, 8, 3, 1900, 'excellent', 'دمشق'],
  ['ندى إسماعيل', 'F', 'j6', 'interview', 2, 4, 2, 880, 'good', 'دمشق'],
  ['ماهر الشعار', 'M', 'j5', 'interview', 6, 5, 2, 1050, 'fair', 'حمص'],
  ['جنى الصالح', 'F', 'j3', 'technical', 1, 3, 2, 700, 'excellent', 'حلب'],
  ['لين العلي', 'F', 'j2', 'offer', 1, 4, 2, 800, 'good', 'دمشق'],
  ['بلال حمدان', 'M', 'j4', 'rejected', 5, 0, 0, 600, 'weak', 'دمشق'],
]

const ASSET_TYPES: [string, string, string, string, number][] = [
  ['حاسب محمول Dell Latitude 5440', 'Dell Latitude 5440 laptop', 'حواسب', 'Computers', 950],
  ['حاسب مكتبي HP ProDesk', 'HP ProDesk desktop', 'حواسب', 'Computers', 650],
  ['هاتف Samsung Galaxy A55', 'Samsung Galaxy A55 phone', 'هواتف', 'Phones', 380],
  ['هاتف IP Yeastar', 'Yeastar IP phone', 'هواتف', 'Phones', 120],
  ['بطاقة NFC للحضور', 'NFC attendance card', 'بطاقات', 'Cards', 5],
  ['سيارة خدمة Kia Rio', 'Kia Rio service car', 'مركبات', 'Vehicles', 14000],
  ['شاشة Dell 24 بوصة', 'Dell 24" monitor', 'ملحقات', 'Peripherals', 160],
]

export const APPROVERS = [
  { level: 1, role: b('الموارد البشرية', 'Human Resources'), name: 'رشا الخطيب' },
  { level: 2, role: b('الإدارة المالية', 'Finance'), name: 'باسل الأتاسي' },
  { level: 3, role: b('المدير التنفيذي', 'Executive Director'), name: 'مازن الدروبي' },
  { level: 4, role: b('الرئيس التنفيذي', 'CEO'), name: 'هاني السمان' },
]

export function seed(): State {
  const r = rng(20261006)
  const today = todayISO()
  const now = new Date()
  const ago = (y: number, m: number) => { const d = new Date(); d.setFullYear(d.getFullYear() - y); d.setMonth(d.getMonth() - m); return d.toISOString().slice(0, 10) }
  const emailOf = (en: string) => en.toLowerCase().split(' ').map(w => w.replace(/^al-/, '').replace(/[^a-z]/g, '')).filter(Boolean).join('.') + '@alfarada.sy'

  const employees: Employee[] = EMPS.map((e, i) => {
    const [y, m] = e[8].split('.').map(Number)
    const id = `e${1001 + i}`
    return {
      id, code: `UHR-${1001 + i}`, name: e[0], nameEn: e[1], gender: e[2], title: b(e[3], e[4]), dept: e[5], city: CITIES[e[6]],
      email: emailOf(e[1]), phone: `+963 9${3 + (i % 6)}${Math.floor(r() * 9)} ${100 + Math.floor(r() * 899)} ${100 + Math.floor(r() * 899)}`,
      hireDate: ago(y, m), dob: ago(26 + Math.floor(r() * 26), Math.floor(r() * 11)), nationalId: `0${Math.floor(1e9 + r() * 8.9e9)}`,
      salary: e[7], currency: 'USD', status: y === 0 && m < 3 ? 'probation' : 'active', nfcUid: `04:${(0x10 + i).toString(16).toUpperCase()}:A7:${Math.floor(r() * 255).toString(16).toUpperCase().padStart(2, '0')}:6C`,
      leaveUsed: Math.floor(r() * 12), sickUsed: r() < 0.25 ? Math.floor(r() * 4) : 0,
      log: [{ at: ago(y, m) + 'T09:00:00', ar: 'مباشرة العمل', en: 'Started work', kind: 'ok' }],
      password: '123456',
    }
  })
  // managers
  const headOf: Record<string, string> = { exec: 'e1001', hr: 'e1003', fin: 'e1004', it: 'e1008', sales: 'e1014', ops: 'e1019', cs: 'e1025', admin: 'e1029' }
  employees.forEach(e => { const h = headOf[e.dept]; e.managerId = h === e.id ? (e.id === 'e1001' ? undefined : 'e1002') : h })
  const depts = DEPTS.map(d => ({ ...d, headId: headOf[d.id] }))

  const jobs: Job[] = JOBS.map((j, i) => ({ ...j, status: 'open' as const, postedAt: addDays(today, -(4 + i * 3)) }))

  const firstNames: Record<string, string> = {}
  const candidates: Candidate[] = CANDS.map((c, i) => {
    const job = jobs.find(j => j.id === c[2])!
    const created = addDays(today, -(c[4] + 3 + Math.floor(r() * 6)))
    const id = `c${i + 1}`
    firstNames[id] = c[0]
    const cand: Candidate = {
      id, ref: `UNQ-2026-APP-${(48210 + i * 37).toString()}`, jobId: c[2], createdAt: created + 'T10:20:00', stageAt: addDays(today, -c[4]), stage: c[3],
      name: c[0], nameEn: CAND_EN[i], gender: c[1], marital: r() < 0.5 ? 'single' : 'married', nationality: 'سورية', dob: ago(23 + Math.floor(r() * 14), 3), pob: c[9],
      nationalId: `0${Math.floor(1e9 + r() * 8.9e9)}`, blood: ['A+', 'O+', 'B+', 'AB+'][i % 4], email: `cand${i + 1}@mail.sy`, mobile: `+963 9${4 + (i % 5)}${i} 2${10 + i}${30 + i} ${400 + i * 11}`, mobile2: '',
      address: `${c[9]}`, linkedin: r() < 0.6 ? 'linkedin.com/in/' + id : '',
      education: [{ phase: ['ثانوية', 'معهد متوسط', 'إجازة جامعية', 'ماجستير'][c[6]] ?? 'إجازة جامعية', institute: c[9] === 'حلب' ? 'جامعة حلب' : c[9] === 'حمص' ? 'جامعة البعث' : 'جامعة دمشق', major: job.dept === 'fin' ? 'محاسبة' : job.dept === 'it' ? 'هندسة معلوماتية' : job.dept === 'ops' ? 'هندسة كهربائية' : job.dept === 'hr' ? 'إدارة أعمال' : 'اقتصاد', year: String(2026 - c[5] - 1), grade: ['جيد', 'جيد جداً', 'امتياز'][i % 3] }],
      experience: c[5] ? [{ company: ['شركة الشام للتقنية', 'مجموعة قاسيون', 'بنك سورية الدولي', 'شركة البيان للتجارة', 'مؤسسة الفرات'][i % 5], position: job.title.ar, from: String(2026 - c[5]), to: '2026', tasks: '', reason: 'فرصة أفضل' }] : [],
      refs: [{ name: 'م. سليم حداد', job: 'مدير سابق', phone: '+963 944 120 330' }],
      english: c[8], arabic: 'native', shifts: r() < 0.6, travel: r() < 0.5, publicSector: false, conviction: false,
      salary: c[7], availability: addDays(today, 30), source: ['website', 'linkedin', 'referral', 'website'][i % 4], yearsExp: c[5], degreeLevel: c[6],
      cvName: `${['Ahmad_Mansour', 'Raghad_Sayed', 'Mohannad_Khatib', 'Shahd_Abdullah', 'Qusay_Hamad', 'Nada_Ismail', 'Maher_Shaar', 'Jana_Saleh', 'Leen_Ali', 'Bilal_Hamdan'][i]}_CV.pdf`, cvSize: 180000 + Math.floor(r() * 300000),
      log: [{ at: created + 'T10:20:00', ar: 'استلام طلب التوظيف عبر البوابة', en: 'Application received via portal', kind: 'info', form: 'F-28' }],
    }
    if (['interview', 'technical', 'offer'].includes(c[3])) cand.log.push({ at: addDays(today, -c[4] - 2) + 'T11:00:00', ar: 'اجتياز الفرز الأولي', en: 'Passed screening', kind: 'ok' })
    if (['technical', 'offer'].includes(c[3])) {
      const ratings: Record<string, number> = {}
      ;['readiness', 'physical', 'education', 'experience', 'knowledge', 'english', 'comm', 'negotiation', 'computer', 'flex', 'learning', 'overtime', 'professional', 'behavior', 'body', 'confidence', 'maturity', 'responsibility', 'problem', 'goals', 'risk', 'planning', 'influence', 'team'].forEach(k => (ratings[k] = 3 + Math.round(r() * 2)))
      cand.interview = { ratings, notes: 'مرشحة متمكنة، تواصل جيد وخبرة مناسبة.', recommendation: 'recommended', interviewer: 'نور الحسن', date: addDays(today, -c[4] - 1) }
      cand.log.push({ at: addDays(today, -c[4] - 1) + 'T12:30:00', ar: 'تقييم المقابلة الأولية: موصى به', en: 'HR interview: recommended', kind: 'ok', form: 'F-25' })
    }
    if (c[3] === 'offer') {
      cand.offer = { title: job.title.ar, contract: 'fixed', currency: 'USD', basic: 650, housing: 80, transport: 40, fuel: 30, leaveDays: 14, startDate: addDays(today, 10), issuedAt: addDays(today, -1) + 'T13:00:00', status: 'sent' }
      cand.password = '123456'; cand.email = 'leen.ali@mail.sy'
      cand.log.push({ at: addDays(today, -1) + 'T13:00:00', ar: 'إرسال عرض العمل للمرشحة', en: 'Offer sent', kind: 'info', form: 'F-29' })
    }
    if (c[3] === 'rejected') { cand.rejectReason = 'لا تتوفر الخبرة المطلوبة'; cand.log.push({ at: addDays(today, -c[4]) + 'T09:00:00', ar: 'اعتذار: لا تتوفر الخبرة المطلوبة', en: 'Declined: insufficient experience', kind: 'danger' }) }
    return cand
  })

  // assets
  const assets: Asset[] = []
  let ai = 1
  employees.forEach((e, i) => {
    const give: number[] = [4]
    if (['it', 'fin', 'hr', 'exec'].includes(e.dept)) give.push(0)
    if (['sales', 'ops'].includes(e.dept)) give.push(2)
    if (e.dept === 'cs') give.push(3, 1)
    if (i === 13 || i === 18) give.push(5)
    give.forEach(t => {
      const a = ASSET_TYPES[t]
      assets.push({ id: `a${ai}`, tag: `AST-${String(ai).padStart(4, '0')}`, name: b(a[0], a[1]), category: b(a[2], a[3]), serial: `SN${Math.floor(1e7 + r() * 8.9e7)}`, value: a[4], empId: e.id, status: 'assigned', assignedAt: e.hireDate, signedAt: e.hireDate })
      ai++
    })
  })
  ;[0, 0, 2, 4, 4, 4, 6, 1].forEach(t => { const a = ASSET_TYPES[t]; assets.push({ id: `a${ai}`, tag: `AST-${String(ai).padStart(4, '0')}`, name: b(a[0], a[1]), category: b(a[2], a[3]), serial: `SN${Math.floor(1e7 + r() * 8.9e7)}`, value: a[4], status: 'available' }); ai++ })

  // attendance: last 14 days + today
  const days: DaySummary[] = []
  const nowMin = now.getHours() * 60 + now.getMinutes()
  for (let k = 14; k >= 0; k--) {
    const date = addDays(today, -k)
    if (isWeekend(date)) continue
    employees.forEach((e, i) => {
      if (e.id === 'e1007' && k === 0) return // Samer: left for live demo
      const x = r()
      if (x < 0.04) { days.push({ empId: e.id, date, status: 'absent' }); return }
      if (x < 0.08) { days.push({ empId: e.id, date, status: 'leave' }); return }
      const lateBias = i % 7 === 3 ? 0.45 : 0.12
      const inM = r() < lateBias ? 8 * 60 + 16 + Math.floor(r() * 40) : 7 * 60 + 35 + Math.floor(r() * 38)
      const outM = r() < 0.18 ? 16 * 60 + 35 + Math.floor(r() * 120) : 16 * 60 + Math.floor(r() * 20)
      const t = (m: number) => `${date}T${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}:00`
      if (k === 0) {
        if (inM > nowMin) return
        days.push({ empId: e.id, date, inAt: t(inM), outAt: outM <= nowMin ? t(outM) : undefined, status: 'present' })
      } else days.push({ empId: e.id, date, inAt: t(inM), outAt: t(outM), status: 'present' })
    })
  }

  const chain = (n: number) => [b('المدير المباشر', 'Direct manager'), b('الموارد البشرية', 'Human Resources')].slice(0, n).map(role => ({ role }))
  const leaves: LeaveReq[] = [
    { id: 'l1', empId: 'e1015', type: 'annual', from: addDays(today, 5), to: addDays(today, 9), reason: 'سفر عائلي', createdAt: addDays(today, -1) + 'T10:00:00', status: 'pending_manager', chain: chain(2) },
    { id: 'l2', empId: 'e1021', type: 'mission', from: addDays(today, 1), to: addDays(today, 3), reason: 'استلام موقع مشروع حماة', createdAt: addDays(today, -1) + 'T15:20:00', status: 'pending_hr', chain: [{ role: b('المدير المباشر', 'Direct manager'), by: 'طارق الحكيم', at: addDays(today, 0) + 'T08:40:00', decision: 'approved' }, { role: b('الموارد البشرية', 'Human Resources') }] },
    { id: 'l3', empId: 'e1026', type: 'justify', from: addDays(today, -1), to: addDays(today, -1), minutes: 34, refDate: addDays(today, -1), reason: 'ازدحام على طريق المزة', createdAt: addDays(today, -1) + 'T09:10:00', status: 'pending_manager', chain: chain(2) },
    { id: 'l4', empId: 'e1013', type: 'sick', from: addDays(today, -6), to: addDays(today, -5), reason: 'تقرير طبي مرفق', createdAt: addDays(today, -6) + 'T07:30:00', status: 'approved', chain: [{ role: b('المدير المباشر', 'Direct manager'), by: 'باسل الأتاسي', at: addDays(today, -6) + 'T09:00:00', decision: 'approved' }, { role: b('الموارد البشرية', 'Human Resources'), by: 'رشا الخطيب', at: addDays(today, -6) + 'T10:00:00', decision: 'approved' }] },
    { id: 'l5', empId: 'e1010', type: 'hourly', from: today, to: today, hours: 2, reason: 'مراجعة دائرة رسمية', createdAt: today + 'T08:05:00', status: 'pending_manager', chain: chain(2) },
  ]

  const resignations: Resignation[] = [
    { id: 'r1', empId: 'e1028', createdAt: addDays(today, -3) + 'T11:00:00', lastDay: addDays(today, 27), reasonKey: 'travel', text: 'أتقدم بطلب قبول استقالتي بسبب السفر خارج القطر، مع التزامي بإتمام فترة الإخطار وتسليم المهام.', status: 'pending_hr',
      clearance: { custody: false, it: false, finance: false, hr: false }, chain: [{ role: b('المدير المباشر', 'Direct manager'), by: 'ميس الكردي', at: addDays(today, -2) + 'T09:30:00' }, { role: b('الموارد البشرية', 'Human Resources') }] },
  ]
  employees.find(e => e.id === 'e1028')!.status = 'resigning'

  // referrals, points & rewards
  const pts: Record<string, [number, number]> = { e1007: [2650, 4200], e1003: [1900, 3100], e1017: [1250, 1800], e1014: [800, 1500], e1025: [600, 900], e1009: [350, 500] }
  Object.entries(pts).forEach(([id, [p, l]]) => { const e = employees.find(x => x.id === id)!; e.points = p; e.lifetime = l })
  const referrals = [
    { id: 'rf1', empId: 'e1007', jobId: 'j1', code: 'UHR1007-J1', at: addDays(today, -9) + 'T10:00:00', clicks: 23, candidateId: 'c1', status: 'applied' as const },
    { id: 'rf2', empId: 'e1017', jobId: 'j3', code: 'UHR1017-J3', at: addDays(today, -8) + 'T12:00:00', clicks: 14, candidateId: 'c3', status: 'applied' as const },
    { id: 'rf3', empId: 'e1003', jobId: 'j6', code: 'UHR1003-J6', at: addDays(today, -6) + 'T09:30:00', clicks: 9, candidateId: 'c6', status: 'applied' as const },
    { id: 'rf4', empId: 'e1025', jobId: 'j4', code: 'UHR1025-J4', at: addDays(today, -4) + 'T15:10:00', clicks: 31, status: 'shared' as const },
    { id: 'rf5', empId: 'e1007', jobId: 'j5', code: 'UHR1007-J5', at: addDays(today, -2) + 'T11:45:00', clicks: 6, status: 'shared' as const },
  ]
  candidates.find(c => c.id === 'c1')!.referrerId = 'e1007'; candidates.find(c => c.id === 'c3')!.referrerId = 'e1017'; candidates.find(c => c.id === 'c6')!.referrerId = 'e1003'
  const rewards = [
    { id: 'w1', title: b('يوم إجازة إضافي مدفوع', 'Extra paid day off'), cost: 1500, stock: 10, kind: 'leave' },
    { id: 'w2', title: b('قسيمة مطعم لشخصين', 'Dinner voucher for two'), cost: 400, stock: 25, kind: 'food' },
    { id: 'w3', title: b('اشتراك نادي رياضي لشهر', 'One-month gym membership'), cost: 900, stock: 8, kind: 'gym' },
    { id: 'w4', title: b('دورة تدريبية معتمدة', 'Accredited training course'), cost: 2000, stock: 5, kind: 'course' },
    { id: 'w5', title: b('موقف سيارة مخصص لشهر', 'Reserved parking for a month'), cost: 700, stock: 4, kind: 'parking' },
    { id: 'w6', title: b('سماعات لاسلكية', 'Wireless headphones'), cost: 1200, stock: 6, kind: 'gift' },
  ]
  const redemptions = [{ id: 'x1', empId: 'e1017', rewardId: 'w3', cost: 900, at: addDays(today, -1) + 'T14:20:00', status: 'pending' as const }]

  return {
    services: [{ id: 'sv1', empId: 'e1012', kind: 'salary_cert' as const, note: 'لتقديمها إلى المصرف', at: addDays(today, -1) + 'T10:10:00', status: 'pending' as const }],
    announcements: [
      { id: 'an1', at: addDays(today, -1) + 'T09:00:00', ar: 'تُصرف رواتب الشهر الحالي يوم 28 عبر الحسابات المصرفية.', en: 'This month’s salaries are paid on the 28th by bank transfer.', tag: 'payroll' },
      { id: 'an2', at: addDays(today, -3) + 'T12:00:00', ar: 'دورة السلامة المهنية الإلزامية الأحد القادم في قاعة الاجتماعات.', en: 'Mandatory safety training next Sunday in the meeting room.', tag: 'training' },
      { id: 'an3', at: addDays(today, -6) + 'T08:30:00', ar: 'مرحباً بزملائنا الجدد في إدارة المبيعات — حلب.', en: 'Welcome to our new colleagues in Sales — Aleppo.', tag: 'people' },
    ],
    referrals, rewards, redemptions, rules: { relaxed: true, matchThreshold: 70, autoScreen: true, slaAlerts: true, referralPoints: 500, whatsappNudge: true },
    version: 2, lang: 'ar', session: null, depts, jobs, candidates, employees, scans: [], days, leaves, assets, resignations,
    notices: [
      { id: 'n1', at: addDays(today, -1) + 'T15:20:00', ar: 'طلب مهمة رسمية من خالد العمر بانتظار اعتماد الموارد البشرية', en: 'Mission request from Khaled Al-Omar awaits HR approval', to: 'hr', link: 'leaves' },
      { id: 'n2', at: addDays(today, -1) + 'T13:05:00', ar: 'أُرسل عرض العمل إلى لين العلي', en: 'Offer sent to Leen Al-Ali', to: 'hr', link: 'recruitment' },
    ],
    seq: 100,
  }
}
