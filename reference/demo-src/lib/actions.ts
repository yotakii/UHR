import { update, getState, nextId, ev } from './store'
import { todayISO, addDays } from './dates'
import type { Candidate, Stage, Interview, Offer, LeaveReq, Resignation, Bi, State, Rules, Employee } from './types'
import { stageInfo, matchScore } from './rules'
import { APPROVERS } from '../data/seed'

const now = () => new Date().toISOString()
const notify = (s: State, ar: string, en: string, to: string = 'hr', link?: string) => s.notices.unshift({ id: nextId(s, 'n'), at: now(), ar, en, to, link })

/* ---------- session ---------- */
export function login(email: string, password: string): 'hr' | 'employee' | 'candidate' | null {
  const s = getState(); const e = email.trim().toLowerCase()
  if (e === 'hr@alfarada.sy' && password === '123456') { update(x => { x.session = { role: 'hr', id: 'e1003' } }); return 'hr' }
  const emp = s.employees.find(x => x.email.toLowerCase() === e && x.password === password && x.status !== 'terminated')
  if (emp) { update(x => { x.session = { role: 'employee', id: emp.id } }); return 'employee' }
  const c = s.candidates.find(x => x.email.toLowerCase() === e && x.password === password)
  if (c) { update(x => { x.session = { role: 'candidate', id: c.id } }); return 'candidate' }
  return null
}
export function logout() { update(s => { s.session = null }) }
export function setLang(l: 'ar' | 'en') { update(s => { s.lang = l }) }
export function setTheme(t: 'dark' | 'light' | 'slate') { update(s => { s.theme = t }) }

/* ---------- application ---------- */
export function findDuplicate(nationalId: string, email: string) {
  const s = getState()
  return s.candidates.find(c => (nationalId && c.nationalId === nationalId) || c.email.toLowerCase() === email.toLowerCase())
}
export function submitApplication(data: Omit<Candidate, 'id' | 'ref' | 'createdAt' | 'stageAt' | 'stage' | 'log'>): Candidate {
  let created!: Candidate
  update(s => {
    const dup = s.candidates.find(c => (data.nationalId && c.nationalId === data.nationalId) || c.email.toLowerCase() === data.email.toLowerCase())
    const job = s.jobs.find(j => j.id === data.jobId)!
    if (dup) {
      Object.assign(dup, { ...data, jobId: data.jobId, stage: 'new' as Stage, stageAt: todayISO() })
      dup.log.push(ev(`طلب جديد على وظيفة ${job.title.ar} — أُلحق بالملف السابق (نفس الرقم الوطني/البريد)`, `New application for ${job.title.en} merged into existing file (same national ID/email)`, 'warn', 'F-28'))
      created = dup
    } else {
      const id = nextId(s, 'c')
      created = { ...data, id, ref: `UNQ-2026-APP-${Math.floor(50000 + Math.random() * 49999)}`, createdAt: now(), stageAt: todayISO(), stage: 'new',
        log: [ev('استلام طلب التوظيف عبر البوابة', 'Application received via portal', 'info', 'F-28'), ev('إرسال تأكيد الاستلام برقم المتابعة إلى البريد والواتساب', 'Confirmation with reference sent by email and WhatsApp', 'info')] }
      s.candidates.unshift(created)
    }
    // referral link
    if (data.refCode) {
      const rf = s.referrals.find(r => r.code.toUpperCase() === data.refCode!.trim().toUpperCase())
      if (rf) { rf.candidateId = created.id; rf.status = 'applied'; created.referrerId = rf.empId; const re = s.employees.find(e => e.id === rf.empId)!
        created.log.push(ev(`مرشّح عبر رابط إحالة ${re.name}`, `Referred via ${re.nameEn}'s link`, 'info'))
        notify(s, `${data.name} قدّم عبر رابط إحالتك`, `${data.name} applied through your referral link`, rf.empId) }
    }
    // automation gate
    if (s.rules.autoScreen && created.stage === 'new') {
      const m = matchScore(created, job).total
      if (m >= s.rules.matchThreshold) { created.stage = 'screening'; created.autoFlag = 'passed'; created.log.push(ev(`اجتاز حد المطابقة (${m}٪ ≥ ${s.rules.matchThreshold}٪) — نُقل تلقائياً للفرز`, `Passed match gate (${m}% ≥ ${s.rules.matchThreshold}%) — moved to screening`, 'ok')) }
      else { created.autoFlag = 'below'; created.log.push(ev(`دون حد المطابقة (${m}٪ < ${s.rules.matchThreshold}٪) — بانتظار مراجعة يدوية`, `Below match gate (${m}% < ${s.rules.matchThreshold}%) — manual review`, 'warn')) }
    }
    notify(s, `طلب توظيف جديد من ${data.name} على وظيفة ${job.title.ar}`, `New application from ${data.name} for ${job.title.en}`, 'hr', `cand:${created.id}`)
  })
  return created
}

/* ---------- pipeline ---------- */
export function moveStage(id: string, to: Stage, reason?: string) {
  update(s => {
    const c = s.candidates.find(x => x.id === id)!; if (c.stage === to) return
    const from = stageInfo(c.stage), t = stageInfo(to)
    c.stage = to; c.stageAt = todayISO()
    if (to === 'rejected') c.rejectReason = reason
    c.log.push(ev(`نقل من «${from.ar}» إلى «${t.ar}»${reason ? ' — ' + reason : ''}`, `Moved from "${from.en}" to "${t.en}"${reason ? ' — ' + reason : ''}`, to === 'rejected' ? 'danger' : 'info'))
    if (to === 'interview') c.log.push(ev('إرسال رسالة واتساب بموعد المقابلة', 'WhatsApp interview invitation sent', 'info'))
  })
}
export function addNote(id: string, text: string, by: string) {
  update(s => { const c = s.candidates.find(x => x.id === id)!; c.log.push(ev(`ملاحظة (${by}): ${text}`, `Note (${by}): ${text}`)) })
}
export function saveInterview(id: string, iv: Interview, pct: number) {
  update(s => {
    const c = s.candidates.find(x => x.id === id)!
    c.interview = iv
    const rec = iv.recommendation === 'recommended' ? ['موصى به', 'recommended'] : iv.recommendation === 'reserve' ? ['احتياط', 'reserve'] : ['غير موصى به', 'not recommended']
    c.log.push(ev(`اعتماد تقييم المقابلة الأولية: ${pct}٪ — ${rec[0]}`, `HR interview saved: ${pct}% — ${rec[1]}`, iv.recommendation === 'rejected' ? 'danger' : 'ok', 'F-25'))
    if (iv.recommendation === 'recommended' && (c.stage === 'interview' || c.stage === 'screening' || c.stage === 'new')) { c.stage = 'technical'; c.stageAt = todayISO(); c.log.push(ev('انتقال تلقائي إلى التقييم الفني', 'Auto-moved to technical review')) }
  })
}
export function sendOffer(id: string, o: Omit<Offer, 'issuedAt' | 'status'>) {
  update(s => {
    const c = s.candidates.find(x => x.id === id)!
    c.offer = { ...o, issuedAt: now(), status: 'sent' }
    c.stage = 'offer'; c.stageAt = todayISO()
    if (!c.password) c.password = '123456'
    c.log.push(ev('إصدار عرض العمل وإرساله للمرشح عبر البريد والبوابة', 'Offer issued and sent via email and portal', 'info', 'F-29'))
  })
}
export function respondOffer(id: string, status: 'accepted' | 'declined' | 'negotiation', signature?: string, note?: string) {
  update(s => {
    const c = s.candidates.find(x => x.id === id)!; if (!c.offer) return
    c.offer.status = status; c.offer.respondedAt = now(); c.offer.signature = signature; c.offer.note = note
    if (status === 'accepted') {
      c.log.push(ev('قبول عرض العمل وتوقيعه إلكترونياً', 'Offer accepted and e-signed', 'ok', 'F-29'))
      c.appointment = { decisionNo: `QR-2026-${Math.floor(100 + Math.random() * 800)}`, issuedAt: now(), approvals: APPROVERS.map(a => ({ ...a, status: 'pending' as const })) }
      c.log.push(ev('فتح قرار التعيين وبدء سلسلة الاعتمادات', 'Appointment decision opened for approval', 'info', 'F-31'))
      notify(s, `${c.name} وقّع عرض العمل — قرار التعيين بانتظار الاعتماد`, `${c.name} signed the offer — appointment awaits approval`, 'hr', `cand:${c.id}`)
    } else if (status === 'declined') {
      c.log.push(ev('اعتذار المرشح عن العرض', 'Candidate declined the offer', 'danger', 'F-29'))
      notify(s, `${c.name} اعتذر عن عرض العمل`, `${c.name} declined the offer`, 'hr', `cand:${c.id}`)
    } else {
      c.log.push(ev(`طلب تعديل بنود العرض: ${note ?? ''}`, `Requested changes: ${note ?? ''}`, 'warn', 'F-29'))
      notify(s, `${c.name} طلب تعديل بنود عرض العمل`, `${c.name} requested offer changes`, 'hr', `cand:${c.id}`)
    }
  })
}
export function approveAppointment(id: string) {
  let newEmpId: string | undefined
  update(s => {
    const c = s.candidates.find(x => x.id === id)!; const ap = c.appointment!; const job = s.jobs.find(j => j.id === c.jobId)!
    const step = ap.approvals.find(a => a.status === 'pending'); if (!step) return
    step.status = 'approved'; step.at = now()
    c.log.push(ev(`اعتماد قرار التعيين — ${step.role.ar}: ${step.name}`, `Appointment approved — ${step.role.en}: ${step.name}`, 'ok', 'F-31'))
    if (ap.approvals.every(a => a.status === 'approved')) {
      const n = s.employees.length + 1001
      const eid = `e${n}`; newEmpId = eid
      const o = c.offer!
      s.employees.push({
        id: eid, code: `UHR-${n}`, name: c.name, nameEn: c.nameEn || c.name, gender: c.gender, title: job.title, dept: job.dept, city: job.city,
        email: c.email, phone: c.mobile, hireDate: o.startDate, dob: c.dob, nationalId: c.nationalId, salary: o.basic + o.housing + o.transport + o.fuel, currency: o.currency,
        status: 'onboarding', nfcUid: `04:${Math.floor(Math.random() * 255).toString(16).toUpperCase().padStart(2, '0')}:B2:${Math.floor(Math.random() * 255).toString(16).toUpperCase().padStart(2, '0')}:7D`,
        managerId: s.depts.find(d => d.id === job.dept)?.headId, candidateId: c.id, password: c.password, visitor: c.visitor,
        leaveUsed: 0, sickUsed: 0,
        log: [...c.log.map(l => ({ ...l })), ev(`صدور قرار التعيين ${ap.decisionNo} ومنح الرقم الوظيفي UHR-${n}`, `Decision ${ap.decisionNo} issued, employee ID UHR-${n}`, 'ok', 'F-31')],
        onboarding: { checklist: [true, ...Array(27).fill(false)].map((v, i) => v || i === 8) },
      })
      ap.employeeId = eid
      c.stage = 'hired'; c.stageAt = todayISO()
      c.log.push(ev(`تم التعيين — الرقم الوظيفي UHR-${n}`, `Hired — employee ID UHR-${n}`, 'ok'))
      // NFC card from stock
      const card = s.assets.find(a => a.status === 'available' && a.category.en === 'Cards')
      if (card) { card.empId = eid; card.status = 'pending_ack'; card.assignedAt = now() }
      if (c.referrerId) {
        const re = s.employees.find(e => e.id === c.referrerId)!; const pts = s.rules.referralPoints
        re.points = (re.points ?? 0) + pts; re.lifetime = (re.lifetime ?? 0) + pts
        re.log.push(ev(`+${pts} نقطة: تعيين ${c.name} عبر رابط الإحالة`, `+${pts} points: ${c.name} hired via referral`, 'ok'))
        const rf = s.referrals.find(r => r.candidateId === c.id); if (rf) { rf.status = 'hired'; rf.awarded = pts }
        notify(s, `أُضيفت ${pts} نقطة لرصيدك — تعيين ${c.name}`, `${pts} points added — ${c.name} hired`, re.id)
      }
      if (c.internal) { const ie = s.employees.find(e => e.id === c.internal!.empId); if (ie) ie.log.push(ev(`قبول طلب النقل/الترقية الداخلي إلى ${job.title.ar}`, `Internal move to ${job.title.en} approved`, 'ok', 'F-27')) }
      notify(s, `اكتمل قرار تعيين ${c.name} — بدأت إجراءات التهيئة`, `${c.name} appointed — onboarding started`, 'hr', `emp:${eid}`)
    }
  })
  return newEmpId
}

/* ---------- onboarding ---------- */
export function toggleCheck(empId: string, i: number) {
  update(s => {
    const e = s.employees.find(x => x.id === empId)!; const ob = e.onboarding!
    ob.checklist[i] = !ob.checklist[i]
    const done = ob.checklist.filter(Boolean).length
    if (done >= 14 && e.status === 'onboarding') { e.status = 'probation'; e.log.push(ev('انتهاء إجراءات المباشرة — بدء فترة التجربة (90 يوماً)', 'Onboarding done — probation started (90 days)', 'ok', 'F-19')) }
  })
}
export function sendMemo(empId: string) {
  update(s => { const e = s.employees.find(x => x.id === empId)!; e.onboarding!.memoSent = now(); e.onboarding!.checklist[4] = true; e.log.push(ev('تعميم مذكرة الترحيب على الأقسام', 'Welcome memo circulated', 'info', 'F-32')) })
}
export function signPledge(empId: string, which: 'f22' | 'f20', sig: string, mother?: string) {
  update(s => {
    const e = s.employees.find(x => x.id === empId)!; const ob = e.onboarding!
    if (which === 'f22') { ob.f22 = { sig, at: now() }; ob.checklist[10] = true; e.log.push(ev('توقيع تعهد المحافظة على سرية العمل', 'Confidentiality undertaking signed', 'ok', 'F-22')) }
    else { ob.f20 = { sig, at: now(), mother: mother ?? '' }; ob.checklist[12] = true; e.log.push(ev('توقيع إقرار إبراء الذمة الوقائي', 'Precautionary clearance signed', 'ok', 'F-20')) }
  })
}

/* ---------- custody ---------- */
export function assignAsset(assetId: string, empId: string) {
  update(s => {
    const a = s.assets.find(x => x.id === assetId)!; const e = s.employees.find(x => x.id === empId)!
    a.empId = empId; a.status = 'pending_ack'; a.assignedAt = now(); a.sig = undefined; a.signedAt = undefined
    e.log.push(ev(`تسليم عهدة: ${a.name.ar} (${a.tag}) — بانتظار توقيع الاستلام`, `Custody issued: ${a.name.en} (${a.tag}) — awaiting signature`, 'info'))
    notify(s, `سُلّمت لك عهدة: ${a.name.ar} — وقّع سند الاستلام`, `Asset issued to you: ${a.name.en} — please sign the receipt`, e.id, 'my-docs')
  })
}
export function ackAsset(assetId: string, sig: string) {
  update(s => {
    const a = s.assets.find(x => x.id === assetId)!; const e = s.employees.find(x => x.id === a.empId)!
    a.status = 'assigned'; a.signedAt = now(); a.sig = sig
    e.log.push(ev(`توقيع استلام العهدة: ${a.name.ar}`, `Custody receipt signed: ${a.name.en}`, 'ok'))
    if (e.onboarding && s.assets.filter(x => x.empId === e.id).every(x => x.status === 'assigned')) e.onboarding.checklist[22] = true
    notify(s, `${e.name} وقّع استلام ${a.name.ar}`, `${e.name} signed for ${a.name.en}`)
  })
}
export function returnAsset(assetId: string) {
  update(s => {
    const a = s.assets.find(x => x.id === assetId)!; const e = s.employees.find(x => x.id === a.empId)
    if (e) e.log.push(ev(`إعادة العهدة: ${a.name.ar}`, `Custody returned: ${a.name.en}`, 'info'))
    a.empId = undefined; a.status = 'available'; a.sig = undefined; a.signedAt = undefined
  })
}

/* ---------- attendance ---------- */
export function nfcScan(empId: string, at: Date, device: Bi): { type: 'in' | 'out'; time: string } {
  let res = { type: 'in' as 'in' | 'out', time: '' }
  update(s => {
    const date = at.toISOString().slice(0, 10) === todayISO() ? todayISO() : `${at.getFullYear()}-${String(at.getMonth() + 1).padStart(2, '0')}-${String(at.getDate()).padStart(2, '0')}`
    const local = `${date}T${String(at.getHours()).padStart(2, '0')}:${String(at.getMinutes()).padStart(2, '0')}:00`
    let d = s.days.find(x => x.empId === empId && x.date === date)
    const type: 'in' | 'out' = d?.inAt ? 'out' : 'in'
    if (!d) { d = { empId, date, status: 'present' }; s.days.push(d) }
    if (type === 'in') { d.inAt = local; d.status = 'present' } else d.outAt = local
    s.scans.unshift({ id: nextId(s, 's'), empId, ts: local, device, type })
    const e = s.employees.find(x => x.id === empId)!
    if (e.onboarding && !e.onboarding.checklist[14]) e.onboarding.checklist[14] = true
    res = { type, time: local }
  })
  return res
}

/* ---------- leave & justification ---------- */
export function requestLeave(r: Omit<LeaveReq, 'id' | 'createdAt' | 'status' | 'chain'>) {
  update(s => {
    const e = s.employees.find(x => x.id === r.empId)!
    s.leaves.unshift({ ...r, id: nextId(s, 'l'), createdAt: now(), status: 'pending_manager', chain: [{ role: { ar: 'المدير المباشر', en: 'Direct manager' } }, { role: { ar: 'الموارد البشرية', en: 'Human Resources' } }] })
    const t = { annual: 'إجازة سنوية', sick: 'إجازة مرضية', unpaid: 'إجازة بلا أجر', hourly: 'مغادرة ساعية', mission: 'مهمة رسمية', justify: 'تبرير تأخير/غياب' }[r.type]
    e.log.push(ev(`تقديم طلب: ${t}`, `Request submitted: ${r.type}`, 'info'))
    notify(s, `طلب ${t} جديد من ${e.name}`, `New ${r.type} request from ${e.name}`, 'hr', 'leaves')
  })
}
export function decideLeave(id: string, decision: 'approved' | 'rejected', by: string) {
  update(s => {
    const l = s.leaves.find(x => x.id === id)!; const e = s.employees.find(x => x.id === l.empId)!
    const step = l.chain.find(c => !c.decision)!; step.by = by; step.at = now(); step.decision = decision
    if (decision === 'rejected') l.status = 'rejected'
    else l.status = l.status === 'pending_manager' ? 'pending_hr' : 'approved'
    const t = LEAVE_NAMES[l.type]
    if (l.status === 'pending_hr') notify(s, `وافق مديرك ${by} على طلب ${t[0]} — بانتظار اعتماد الموارد البشرية`, `${t[1]}: approved by your manager — awaiting HR`, e.id, 'my-requests')
    const dA = (d: string) => new Date(d + 'T12:00').toLocaleDateString('ar-SY-u-nu-latn', { day: 'numeric', month: 'long' }), dE = (d: string) => new Date(d + 'T12:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
    if (l.status === 'approved') notify(s, `تمت الموافقة النهائية على طلب ${t[0]}: ${dA(l.from)}${l.to !== l.from ? ' إلى ' + dA(l.to) : ''}`, `Your ${t[1].toLowerCase()} request is approved: ${dE(l.from)}${l.to !== l.from ? ' – ' + dE(l.to) : ''}`, e.id, 'my-requests')
    if (l.status === 'rejected') notify(s, `لم تتم الموافقة على طلب ${t[0]} (${by})`, `Your ${t[1].toLowerCase()} request was declined (${by})`, e.id, 'my-requests')
    if (l.status === 'approved') {
      if (l.type === 'annual') { let d = l.from; let n = 0; while (d <= l.to) { if (![5, 6].includes(new Date(d + 'T12:00').getDay())) n++; d = addDays(d, 1) } e.leaveUsed += n }
      if (l.type === 'sick') e.sickUsed += 1
      if (l.type === 'justify') { const day = s.days.find(x => x.empId === l.empId && x.date === l.refDate); if (day) day.justified = true }
      e.log.push(ev('اعتماد الطلب نهائياً', 'Request approved', 'ok'))
    } else if (l.status === 'rejected') e.log.push(ev('رفض الطلب', 'Request rejected', 'danger'))
  })
}

const LEAVE_NAMES: Record<string, [string, string]> = { annual: ['إجازة سنوية', 'Annual leave'], sick: ['إجازة مرضية', 'Sick leave'], unpaid: ['إجازة بلا أجر', 'Unpaid leave'], hourly: ['مغادرة ساعية', 'Hourly permission'], mission: ['مهمة رسمية', 'Official mission'], justify: ['تبرير التأخير', 'Late justification'] }

/* ---------- resignation ---------- */
export function submitResignation(empId: string, lastDay: string, reasonKey: string, text: string) {
  update(s => {
    const e = s.employees.find(x => x.id === empId)!
    const r: Resignation = { id: nextId(s, 'r'), empId, createdAt: now(), lastDay, reasonKey, text, status: 'pending_manager', clearance: { custody: false, it: false, finance: false, hr: false },
      chain: [{ role: { ar: 'المدير المباشر', en: 'Direct manager' } }, { role: { ar: 'الموارد البشرية', en: 'Human Resources' } }] }
    s.resignations.unshift(r); e.status = 'resigning'
    e.log.push(ev('تقديم طلب الاستقالة', 'Resignation submitted', 'warn', 'F-26'))
    notify(s, `طلب استقالة من ${e.name}`, `Resignation from ${e.name}`, 'hr', 'offboarding')
  })
}
export function advanceResignation(id: string, by: string, note?: string) {
  update(s => {
    const r = s.resignations.find(x => x.id === id)!; const e = s.employees.find(x => x.id === r.empId)!
    const step = r.chain.find(c => !c.at); if (step) { step.by = by; step.at = now() }
    if (r.status === 'pending_manager') notify(s, 'وافق مديرك على طلب الاستقالة — بانتظار الموارد البشرية', 'Your manager approved your resignation — awaiting HR', e.id, 'my-resign')
    if (r.status === 'pending_hr') notify(s, 'اعتمدت الموارد البشرية استقالتك — بدأت إجراءات إبراء الذمة', 'HR approved your resignation — clearance has started', e.id, 'my-resign')
    if (r.status === 'pending_manager') { r.status = 'pending_hr'; r.managerNote = note; e.log.push(ev(`موافقة المدير المباشر على الاستقالة${note ? ': ' + note : ''}`, 'Manager approved resignation', 'info', 'F-26')) }
    else if (r.status === 'pending_hr') { r.status = 'clearance'; e.log.push(ev('موافقة الموارد البشرية وبدء إجراءات إبراء الذمة', 'HR approved — clearance started', 'info', 'F-33')) }
  })
}
export function rejectResignation(id: string, by: string) {
  update(s => {
    const r = s.resignations.find(x => x.id === id)!; const e = s.employees.find(x => x.id === r.empId)!
    notify(s, 'سُحب طلب الاستقالة بعد التفاهم مع الإدارة', 'Your resignation was withdrawn after discussion', e.id, 'my-resign')
    r.status = 'rejected'; e.status = 'active'; e.log.push(ev(`سحب/رفض طلب الاستقالة (${by})`, `Resignation withdrawn/rejected (${by})`, 'info'))
  })
}
export function toggleClearance(id: string, k: keyof Resignation['clearance']) {
  update(s => {
    const r = s.resignations.find(x => x.id === id)!; r.clearance[k] = !r.clearance[k]
    if (k === 'custody' && r.clearance.custody) s.assets.filter(a => a.empId === r.empId).forEach(a => { a.empId = undefined; a.status = 'available'; a.sig = undefined; a.signedAt = undefined })
  })
}
export function saveExit(id: string, exit: Resignation['exit']) { update(s => { s.resignations.find(x => x.id === id)!.exit = exit }) }
export function closeResignation(id: string, total: number) {
  update(s => {
    const r = s.resignations.find(x => x.id === id)!; const e = s.employees.find(x => x.id === r.empId)!
    r.status = 'closed'; r.closedAt = now(); e.status = 'terminated'; e.endDate = r.lastDay
    s.leaves.filter(l => l.empId === e.id && l.status.startsWith('pending')).forEach(l => { l.status = 'rejected'; l.reason += ' — أُلغي لانتهاء الخدمة' })
    e.log.push(ev(`إنهاء الخدمة وصرف المخالصة النهائية ($${Math.round(total)})`, `Service ended — final settlement ($${Math.round(total)})`, 'danger', 'F-33'))
    if (s.session?.role === 'employee' && s.session.id === e.id) s.session = null
  })
}
export function markNoticesRead() { update(s => { s.notices.forEach(n => (n.read = true)) }) }

/* ---------- referrals & rewards ---------- */
export function shareReferral(empId: string, jobId: string): string {
  let code = ''
  update(s => {
    const ex = s.referrals.find(r => r.empId === empId && r.jobId === jobId)
    if (ex) { ex.clicks += 1; code = ex.code; return }
    const e = s.employees.find(x => x.id === empId)!
    code = `${e.code.replace('-', '')}-${jobId.toUpperCase()}`
    s.referrals.unshift({ id: nextId(s, 'rf'), empId, jobId, code, at: now(), clicks: 0, status: 'shared' })
    e.log.push(ev(`إنشاء رابط إحالة لوظيفة ${s.jobs.find(j => j.id === jobId)!.title.ar}`, 'Referral link created', 'info'))
  })
  return code
}
export function redeem(empId: string, rewardId: string) {
  update(s => {
    const e = s.employees.find(x => x.id === empId)!; const w = s.rewards.find(r => r.id === rewardId)!
    if ((e.points ?? 0) < w.cost || w.stock < 1) return
    e.points = (e.points ?? 0) - w.cost
    s.redemptions.unshift({ id: nextId(s, 'x'), empId, rewardId, cost: w.cost, at: now(), status: 'pending' })
    e.log.push(ev(`طلب استبدال: ${w.title.ar} (${w.cost} نقطة محجوزة)`, `Redemption requested: ${w.title.en} (${w.cost} pts held)`, 'info'))
    notify(s, `${e.name} طلب استبدال ${w.title.ar}`, `${e.nameEn} requested ${w.title.en}`, 'hr', 'rewards')
  })
}
export function decideRedemption(id: string, ok: boolean, by: string) {
  update(s => {
    const r = s.redemptions.find(x => x.id === id)!; const e = s.employees.find(x => x.id === r.empId)!; const w = s.rewards.find(x => x.id === r.rewardId)!
    r.status = ok ? 'approved' : 'rejected'; r.by = by
    if (ok) { w.stock = Math.max(0, w.stock - 1); e.log.push(ev(`اعتماد المكافأة: ${w.title.ar}`, `Reward approved: ${w.title.en}`, 'ok')) }
    else { e.points = (e.points ?? 0) + r.cost; e.log.push(ev(`رفض الاستبدال وإعادة ${r.cost} نقطة`, `Redemption declined, ${r.cost} pts refunded`, 'warn')) }
    notify(s, ok ? `اعتُمدت مكافأتك: ${w.title.ar}` : `لم تُعتمد ${w.title.ar} وأُعيدت نقاطك`, ok ? `Reward approved: ${w.title.en}` : `${w.title.en} declined, points refunded`, e.id)
  })
}
export function setRules(r: Rules) { update(s => { s.rules = r }) }

/* ---------- internal application F-27 ---------- */
export function applyInternal(empId: string, jobId: string, reason: string) {
  update(s => {
    const e = s.employees.find(x => x.id === empId)!; const job = s.jobs.find(j => j.id === jobId)!
    const id = nextId(s, 'c')
    s.candidates.unshift({ id, ref: `UNQ-2026-INT-${Math.floor(1000 + Math.random() * 8999)}`, jobId, createdAt: now(), stageAt: todayISO(), stage: 'screening',
      name: e.name, nameEn: e.nameEn, gender: e.gender, marital: '', nationality: 'سورية', dob: e.dob, pob: e.city.ar, nationalId: e.nationalId, blood: '', email: e.email, mobile: e.phone, mobile2: '', address: e.city.ar, linkedin: '',
      education: [], experience: [{ company: 'شركة الفرادة', position: e.title.ar, from: e.hireDate.slice(0, 4), to: '2026', tasks: '', reason: 'نقل/ترقية داخلية' }], refs: [],
      english: 'good', arabic: 'native', shifts: false, travel: false, publicSector: false, conviction: false, salary: e.salary, availability: todayISO(), source: 'internal',
      yearsExp: Math.max(1, Math.round((Date.now() - new Date(e.hireDate).getTime()) / 3.15e10)) + 2, degreeLevel: 2, internal: { empId, reason },
      log: [ev(`طلب توظيف داخلي من الموظف ${e.code}`, `Internal application from ${e.code}`, 'info', 'F-27')] })
    e.log.push(ev(`تقديم طلب داخلي على ${job.title.ar}`, `Applied internally for ${job.title.en}`, 'info', 'F-27'))
    notify(s, `طلب داخلي من ${e.name} على ${job.title.ar}`, `Internal application from ${e.nameEn}`, 'hr', `cand:${id}`)
  })
}

/* ---------- add employee wizard ---------- */
export function addEmployee(d: Omit<Employee, 'id' | 'code' | 'status' | 'nfcUid' | 'leaveUsed' | 'sickUsed' | 'log' | 'password' | 'onboarding'>, assetIds: string[]): string {
  let id = ''
  update(s => {
    const n = s.employees.length + 1001; id = `e${n}`
    s.employees.push({ ...d, id, code: `UHR-${n}`, status: 'onboarding', nfcUid: `04:${(n % 255).toString(16).toUpperCase().padStart(2, '0')}:C4:${Math.floor(Math.random() * 255).toString(16).toUpperCase().padStart(2, '0')}:1E`,
      leaveUsed: 0, sickUsed: 0, password: '123456', visitor: true, onboarding: { checklist: Array(28).fill(false).map((_, i) => i === 0 || i === 13) },
      log: [ev(`إنشاء ملف موظف مباشرة (معالج إضافة موظف) — UHR-${n}`, `Employee file created via wizard — UHR-${n}`, 'ok')] })
    assetIds.forEach(a => { const x = s.assets.find(y => y.id === a); if (x) { x.empId = id; x.status = 'pending_ack'; x.assignedAt = now() } })
    notify(s, `أُضيف الموظف ${d.name} — بدأت إجراءات المباشرة`, `${d.nameEn} added — onboarding started`, 'hr', `emp:${id}`)
  })
  return id
}

/* ---------- employee service requests ---------- */
export const SERVICE_LABEL: Record<string, Bi> = {
  salary_cert: { ar: 'شهادة راتب', en: 'Salary certificate' }, experience_cert: { ar: 'شهادة خبرة / تعريف', en: 'Employment letter' },
  advance: { ar: 'سلفة على الراتب', en: 'Salary advance' }, data_update: { ar: 'تحديث بيانات شخصية', en: 'Personal data update' },
}
export function requestService(empId: string, kind: import('./types').ServiceKind, note: string, amount?: number) {
  update(s => {
    s.services = s.services ?? []
    const e = s.employees.find(x => x.id === empId)!
    s.services.unshift({ id: nextId(s, 'sv'), empId, kind, note, amount, at: now(), status: 'pending' })
    e.log.push(ev(`طلب خدمة: ${SERVICE_LABEL[kind].ar}`, `Service request: ${SERVICE_LABEL[kind].en}`, 'info'))
    notify(s, `${e.name} طلب ${SERVICE_LABEL[kind].ar}`, `${e.nameEn} requested ${SERVICE_LABEL[kind].en}`, 'hr', 'leaves')
  })
}
export function decideService(id: string, ok: boolean, by: string) {
  update(s => {
    const r = (s.services ?? []).find(x => x.id === id)!; const e = s.employees.find(x => x.id === r.empId)!
    r.status = ok ? 'approved' : 'rejected'; r.by = by; r.doneAt = now()
    e.log.push(ev(`${ok ? 'إنجاز' : 'رفض'} طلب ${SERVICE_LABEL[r.kind].ar}`, `${SERVICE_LABEL[r.kind].en} ${ok ? 'issued' : 'declined'}`, ok ? 'ok' : 'warn'))
    notify(s, ok ? `جاهز: ${SERVICE_LABEL[r.kind].ar}` : `لم يُعتمد طلب ${SERVICE_LABEL[r.kind].ar}`, ok ? `Ready: ${SERVICE_LABEL[r.kind].en}` : `${SERVICE_LABEL[r.kind].en} declined`, e.id)
  })
}
export function updateContact(empId: string, phone: string) {
  update(s => { const e = s.employees.find(x => x.id === empId)!; if (phone && phone !== e.phone) { e.log.push(ev(`تحديث رقم الجوال إلى ${phone}`, `Mobile updated to ${phone}`, 'info')); e.phone = phone } })
}
