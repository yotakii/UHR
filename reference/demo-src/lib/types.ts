export type Lang = 'ar' | 'en'
export type Bi = { ar: string; en: string }

export type LogEvent = { at: string; ar: string; en: string; kind?: 'info' | 'ok' | 'warn' | 'danger'; form?: string }

export type Dept = { id: string; ar: string; en: string; headId?: string }

export type Job = {
  id: string; code: string; title: Bi; dept: string; city: Bi; type: Bi
  openings: number; minYears: number; degree: number; salaryMin: number; salaryMax: number
  postedAt: string; status: 'open' | 'closed'; summary: Bi; reqs: Bi[]
}

export type Education = { phase: string; institute: string; major: string; year: string; grade: string }
export type Experience = { company: string; position: string; from: string; to: string; tasks: string; reason: string }
export type Reference = { name: string; job: string; phone: string }

export type Stage = 'new' | 'screening' | 'interview' | 'technical' | 'offer' | 'hired' | 'rejected'

export type Interview = {
  ratings: Record<string, number>; notes: string; recommendation: 'recommended' | 'reserve' | 'rejected'
  interviewer: string; date: string
}
export type Offer = {
  title: string; contract: 'fixed' | 'open' | 'project'; currency: 'USD' | 'SYP'
  basic: number; housing: number; transport: number; fuel: number; leaveDays: number; startDate: string
  issuedAt: string; status: 'sent' | 'accepted' | 'declined' | 'negotiation'; respondedAt?: string; signature?: string; note?: string
}
export type Approval = { level: number; role: Bi; name: string; status: 'pending' | 'approved'; at?: string }
export type Appointment = { decisionNo: string; issuedAt: string; approvals: Approval[]; employeeId?: string }

export type Candidate = {
  id: string; ref: string; jobId: string; createdAt: string; stageAt: string; stage: Stage
  name: string; nameEn?: string; gender: 'M' | 'F'; marital: string; nationality: string; dob: string; pob: string
  nationalId: string; blood: string; email: string; mobile: string; mobile2: string; address: string; linkedin: string
  education: Education[]; experience: Experience[]; refs: Reference[]
  english: string; arabic: string; shifts: boolean; travel: boolean; publicSector: boolean; conviction: boolean
  salary: number; availability: string; source: string; yearsExp: number; degreeLevel: number
  cvName?: string; cvSize?: number; cvData?: string
  password?: string; visitor?: boolean; referrerId?: string; refCode?: string; internal?: { empId: string; reason: string }; autoFlag?: 'below' | 'passed'
  log: LogEvent[]; interview?: Interview; offer?: Offer; appointment?: Appointment; rejectReason?: string
}

export type EmpStatus = 'onboarding' | 'probation' | 'active' | 'leave' | 'resigning' | 'terminated'
export type Employee = {
  id: string; code: string; name: string; nameEn: string; gender: 'M' | 'F'; title: Bi; dept: string; city: Bi
  email: string; phone: string; hireDate: string; dob: string; nationalId: string; salary: number; currency: 'USD' | 'SYP'
  status: EmpStatus; nfcUid: string; managerId?: string; candidateId?: string; password?: string; visitor?: boolean
  leaveUsed: number; sickUsed: number; log: LogEvent[]; points?: number; lifetime?: number
  onboarding?: { checklist: boolean[]; memoSent?: string; f22?: { sig: string; at: string }; f20?: { sig: string; at: string; mother: string } }
  endDate?: string
}

export type Scan = { id: string; empId: string; ts: string; device: Bi; type: 'in' | 'out' }
export type DaySummary = { empId: string; date: string; inAt?: string; outAt?: string; status: 'present' | 'late' | 'absent' | 'leave' | 'weekend'; justified?: boolean }

export type LeaveType = 'annual' | 'sick' | 'unpaid' | 'hourly' | 'mission' | 'justify'
export type LeaveReq = {
  id: string; empId: string; type: LeaveType; from: string; to: string; hours?: number; reason: string
  createdAt: string; status: 'pending_manager' | 'pending_hr' | 'approved' | 'rejected'; refDate?: string; minutes?: number
  chain: { role: Bi; by?: string; at?: string; decision?: 'approved' | 'rejected' }[]
}

export type Asset = {
  id: string; tag: string; name: Bi; category: Bi; serial: string; value: number
  empId?: string; status: 'available' | 'pending_ack' | 'assigned' | 'maintenance'; assignedAt?: string; signedAt?: string; sig?: string
}

export type Resignation = {
  id: string; empId: string; createdAt: string; lastDay: string; reasonKey: string; text: string
  status: 'pending_manager' | 'pending_hr' | 'clearance' | 'closed' | 'rejected'
  managerNote?: string; exit?: { rating: number; wouldReturn: boolean; feedback: string }
  clearance: { custody: boolean; it: boolean; finance: boolean; hr: boolean }
  chain: { role: Bi; by?: string; at?: string }[]
  closedAt?: string
}

export type Notice = { id: string; at: string; ar: string; en: string; to: 'hr' | string; read?: boolean; link?: string }

export type Referral = { id: string; empId: string; jobId: string; code: string; at: string; clicks: number; candidateId?: string; status: 'shared' | 'applied' | 'hired'; awarded?: number }
export type Reward = { id: string; title: Bi; cost: number; stock: number; kind: string }
export type Redemption = { id: string; empId: string; rewardId: string; cost: number; at: string; status: 'pending' | 'approved' | 'rejected'; by?: string }
export type Rules = { relaxed?: boolean; matchThreshold: number; autoScreen: boolean; slaAlerts: boolean; referralPoints: number; whatsappNudge: boolean }

export type ServiceKind = 'salary_cert' | 'experience_cert' | 'advance' | 'data_update'
export type ServiceReq = { id: string; empId: string; kind: ServiceKind; note: string; amount?: number; at: string; status: 'pending' | 'approved' | 'rejected'; by?: string; doneAt?: string }

export type Session = { role: 'hr' | 'employee' | 'candidate'; id: string } | null

export type State = {
  version: number; lang: Lang; theme?: 'dark' | 'light' | 'slate'; session: Session
  depts: Dept[]; jobs: Job[]; candidates: Candidate[]; employees: Employee[]
  scans: Scan[]; days: DaySummary[]; leaves: LeaveReq[]; assets: Asset[]; resignations: Resignation[]; notices: Notice[]
  referrals: Referral[]; rewards: Reward[]; redemptions: Redemption[]; rules: Rules; services?: ServiceReq[]; announcements?: { id: string; at: string; ar: string; en: string; tag: string }[]
  seq: number
}
