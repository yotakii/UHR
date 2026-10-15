import { useState } from 'react'
import { useStore, useL, useLang, bi, fmtDate, fmtDateTime, todayISO, addDays, isWeekend, money, useDisplayName } from '../lib/store'
import { decideLeave, assignAsset, returnAsset, decideService, SERVICE_LABEL } from '../lib/actions'
import { useNav } from '../lib/nav'
import { Btn, Panel, Badge, Avatar, PageHead, Tabs, Empty, Modal, Field, toast } from '../ui/kit'
import { calcDay, minsLabel, hourlyRate, LEAVE_TYPES, SHIFT, STAGES, DEGREES } from '../lib/rules'
import { LeaveStatus } from './Employees'

/* ---------------- attendance ---------------- */
export function Attendance() {
  const L = useL(); const lang = useLang(); const { go } = useNav()
  const emps = useStore(s => s.employees.filter(e => e.status !== 'terminated')); const days = useStore(s => s.days); const depts = useStore(s => s.depts)
  const [date, setDate] = useState(todayISO()); const [dept, setDept] = useState('all'); const [view, setView] = useState('day')
  const list = emps.filter(e => (dept === 'all' || e.dept === dept) && e.hireDate <= date)
  const rows = list.map(e => { const r = days.find(d => d.empId === e.id && d.date === date); return { e, r, c: calcDay(date, r?.inAt, r?.outAt) } })
  const cnt = { present: rows.filter(x => x.r?.inAt).length, late: rows.filter(x => x.c.late).length, absent: rows.filter(x => !x.r?.inAt && x.r?.status !== 'leave' && !isWeekend(date)).length, leave: rows.filter(x => x.r?.status === 'leave').length }
  // period summary
  const from = addDays(todayISO(), -13)
  const sum = list.map(e => { const ds = days.filter(d => d.empId === e.id && d.date >= from); let late = 0, ot = 0, otPay = 0, absent = 0
    ds.forEach(d => { const c = calcDay(d.date, d.inAt, d.outAt); late += c.late; ot += c.ot; otPay += c.ot / 60 * hourlyRate(e) * (1 + c.otRate); if (d.status === 'absent') absent++ })
    return { e, late, ot, otPay, absent, n: ds.filter(d => d.inAt).length } })
  return <div className="max-w-[1280px] mx-auto">
    <PageHead title={L('الحضور والدوام', 'Attendance')} sub={L('حركات بطاقات NFC محسوبة وفق سياسة الدوام: سماح 15 دقيقة، إضافي بعد 30 دقيقة من نهاية الوردية.', 'NFC scans calculated per policy: 15-min grace, overtime after 30 minutes past shift end.')}
      actions={<><select className="field !w-48" value={dept} onChange={e => setDept(e.target.value)}><option value="all">{L('كل الإدارات', 'All departments')}</option>{depts.map(d => <option key={d.id} value={d.id}>{d[lang]}</option>)}</select>
        {view === 'day' && <input type="date" className="field !w-44" value={date} max={todayISO()} onChange={e => setDate(e.target.value)} />}<Btn onClick={() => go('kiosk-sim')}>{L('جهاز الحضور NFC', 'NFC terminal')}</Btn></>} />
    <Tabs value={view} onChange={setView} tabs={[{ v: 'day', label: L('السجل اليومي', 'Daily log') }, { v: 'period', label: L('ملخص آخر أسبوعين', 'Last two weeks') }]} />
    {view === 'day' ? <>
      <div className="grid grid-cols-2 md:grid-cols-4 gap-px bg-line border border-line rounded-lg overflow-hidden my-4">
        {[[L('حاضرون', 'Present'), cnt.present, 'text-brand'], [L('متأخرون', 'Late'), cnt.late, 'text-warn'], [L('غائبون', 'Absent'), cnt.absent, 'text-danger'], [L('إجازات', 'On leave'), cnt.leave, 'text-ink-2']].map(([k, v, c]) => <div key={String(k)} className="bg-surface px-4 py-3"><div className="text-[12px] text-ink-3">{k}</div><div className={`text-[22px] font-bold num ${c}`}>{v}</div></div>)}
      </div>
      <Panel pad={false}><div className="scroll-x"><table className="tbl"><thead><tr><th>{L('الموظف', 'Employee')}</th><th>{L('الإدارة', 'Dept')}</th><th>{L('الدخول', 'In')}</th><th>{L('الخروج', 'Out')}</th><th>{L('العمل الفعلي', 'Worked')}</th><th>{L('تأخير', 'Late')}</th><th>{L('مغادرة مبكرة', 'Early')}</th><th>{L('إضافي', 'OT')}</th><th>{L('الحالة', 'Status')}</th></tr></thead>
        <tbody>{rows.sort((a, b) => (a.r?.inAt ?? 'z').localeCompare(b.r?.inAt ?? 'z')).map(({ e, r, c }) => <tr key={e.id} className="click" onClick={() => go('employee', e.id)}>
          <td><div className="flex items-center gap-2"><Avatar name={e.name} size={26} tone={e.visitor ? 'brass' : 'brand'} /><span className="font-medium">{lang === 'ar' ? e.name : e.nameEn}</span></div></td><td className="text-ink-2 text-[13px]">{depts.find(d => d.id === e.dept)?.[lang]}</td>
          <td className="num ltr">{r?.inAt?.slice(11, 16) ?? '—'}</td><td className="num ltr">{r?.outAt?.slice(11, 16) ?? '—'}</td><td className="num">{c.worked ? minsLabel(c.worked, lang) : '—'}</td>
          <td className="num">{c.late ? <span className="text-warn">{minsLabel(c.late, lang)}</span> : '—'}</td><td className="num">{c.early ? minsLabel(c.early, lang) : '—'}</td><td className="num">{c.ot ? <span className="text-brand">{minsLabel(c.ot, lang)}</span> : '—'}</td>
          <td>{r?.status === 'leave' ? <Badge>{L('إجازة', 'Leave')}</Badge> : !r?.inAt ? (isWeekend(date) ? <Badge>{L('عطلة', 'Weekend')}</Badge> : date === todayISO() ? <Badge tone="warn">{L('لم يسجل', 'Not in')}</Badge> : <Badge tone="danger">{L('غياب', 'Absent')}</Badge>) : c.late ? <Badge tone={r.justified ? 'brand' : 'warn'}>{r.justified ? L('تأخير مبرر', 'Justified') : L('متأخر', 'Late')}</Badge> : <Badge tone="brand">{L('حاضر', 'Present')}</Badge>}</td>
        </tr>)}</tbody></table></div></Panel>
    </> : <Panel pad={false} className="mt-4"><div className="scroll-x"><table className="tbl"><thead><tr><th>{L('الموظف', 'Employee')}</th><th>{L('أيام الحضور', 'Days present')}</th><th>{L('أيام الغياب', 'Absences')}</th><th>{L('مجموع التأخير', 'Total late')}</th><th>{L('ساعات إضافية', 'Overtime')}</th><th>{L('أجر الإضافي التقديري', 'Est. OT pay')}</th></tr></thead>
      <tbody>{sum.sort((a, b) => b.late - a.late).map(x => <tr key={x.e.id}><td className="font-medium">{lang === 'ar' ? x.e.name : x.e.nameEn}</td><td className="num">{x.n}</td><td className="num">{x.absent || '—'}</td><td className="num">{x.late ? <span className={x.late > 90 ? 'text-danger font-medium' : 'text-warn'}>{minsLabel(x.late, lang)}</span> : '—'}</td><td className="num">{x.ot ? minsLabel(x.ot, lang) : '—'}</td><td className="num">{x.otPay ? money(x.otPay, x.e.currency, lang) : '—'}</td></tr>)}</tbody></table></div></Panel>}
  </div>
}

/* ---------------- leaves ---------------- */
export function Leaves() {
  const L = useL(); const lang = useLang(); const dn = useDisplayName()
  const leaves = useStore(s => s.leaves); const emps = useStore(s => s.employees)
  const me = useStore(s => s.employees.find(e => e.id === s.session?.id))
  const [tab, setTab] = useState('pending')
  const list = leaves.filter(l => tab === 'pending' ? l.status.startsWith('pending') : tab === 'justify' ? l.type === 'justify' : true)
  const services = useStore(s => s.services ?? [])
  return <div className="max-w-[1200px] mx-auto">
    <PageHead title={L('الإجازات والتبريرات', 'Leave & justifications')} sub={L('كل طلب يمر على المدير المباشر ثم الموارد البشرية. يُحدّث الرصيد تلقائياً عند الاعتماد النهائي.', 'Every request goes to the direct manager, then HR. Balances update on final approval.')} />
    <Tabs value={tab} onChange={setTab} tabs={[{ v: 'pending', label: L('بانتظار الاعتماد', 'Pending'), count: leaves.filter(l => l.status.startsWith('pending')).length }, { v: 'justify', label: L('تبريرات التأخير والغياب', 'Justifications') }, { v: 'svc', label: L('طلبات الخدمات', 'Service requests'), count: services.filter(x => x.status === 'pending').length }, { v: 'all', label: L('كل الطلبات', 'All requests') }]} />
    {tab === 'svc' ? <Panel pad={false} className="mt-4">{services.length === 0 ? <Empty title={L('لا توجد طلبات خدمات', 'No service requests')} /> : <table className="tbl"><thead><tr><th>{L('الموظف', 'Employee')}</th><th>{L('الخدمة', 'Service')}</th><th>{L('التفاصيل', 'Details')}</th><th>{L('التاريخ', 'Date')}</th><th>{L('الحالة', 'Status')}</th><th /></tr></thead><tbody>
      {services.map(x => { const e = emps.find(y => y.id === x.empId)!; return <tr key={x.id}><td className="font-medium">{lang === 'ar' ? e.name : e.nameEn}</td><td>{SERVICE_LABEL[x.kind][lang]}</td><td className="text-ink-2">{x.note}{x.amount ? ` — $${x.amount}` : ''}</td><td className="num">{fmtDateTime(x.at, lang)}</td><td>{x.status === 'pending' ? <Badge tone="brass">{L('بانتظار', 'Pending')}</Badge> : x.status === 'approved' ? <Badge tone="brand">{L('منجز', 'Done')}</Badge> : <Badge tone="danger">{L('مرفوض', 'Declined')}</Badge>}</td>
        <td>{x.status === 'pending' && <div className="flex gap-1.5 justify-end"><Btn size="sm" kind="primary" onClick={() => { decideService(x.id, true, me?.name ?? 'HR'); toast(L('أُنجز الطلب', 'Done')) }}>{L('إنجاز', 'Complete')}</Btn><Btn size="sm" kind="danger" onClick={() => decideService(x.id, false, me?.name ?? 'HR')}>{L('رفض', 'Decline')}</Btn></div>}</td></tr> })}</tbody></table>}</Panel> :
    <Panel pad={false} className="mt-4">{list.length === 0 ? <Empty title={L('لا توجد طلبات معلقة', 'Nothing pending')}>{L('الطلبات الجديدة من الموظفين تظهر هنا.', 'New employee requests appear here.')}</Empty> :
      <div className="scroll-x"><table className="tbl"><thead><tr><th>{L('الموظف', 'Employee')}</th><th>{L('النوع', 'Type')}</th><th>{L('الفترة', 'Period')}</th><th>{L('السبب', 'Reason')}</th><th>{L('مسار الاعتماد', 'Approval path')}</th><th>{L('الحالة', 'Status')}</th><th /></tr></thead>
        <tbody>{list.map(l => { const e = emps.find(x => x.id === l.empId)!; const mgr = emps.find(x => x.id === e.managerId)
          const actor = l.status === 'pending_manager' ? (mgr?.name ?? '') : (me?.name ?? 'HR')
          return <tr key={l.id}><td><div className="font-medium">{lang === 'ar' ? e.name : e.nameEn}</div><div className="text-[12px] text-ink-3">{fmtDateTime(l.createdAt, lang)}</div></td><td>{LEAVE_TYPES[l.type][lang]}</td>
            <td className="num">{l.type === 'hourly' ? `${l.hours} ${L('ساعة', 'h')}` : l.type === 'justify' ? `${fmtDate(l.refDate, lang)} — ${l.minutes} ${L('د', 'm')}` : <>{fmtDate(l.from, lang)}{l.to !== l.from && <> — {fmtDate(l.to, lang)}</>}</>}</td>
            <td className="text-ink-2 max-w-[220px]">{l.reason}</td>
            <td className="text-[12px]">{l.chain.map((c, i) => <div key={i} className={c.decision === 'approved' ? 'text-brand' : c.decision === 'rejected' ? 'text-danger' : 'text-ink-3'}>{i + 1}. {c.role[lang]}{c.by ? ` — ${dn(c.by)}` : ''}</div>)}</td>
            <td><LeaveStatus s={l.status} /></td>
            <td>{l.status.startsWith('pending') && <div className="flex gap-1.5 justify-end"><Btn size="sm" kind="primary" onClick={() => { decideLeave(l.id, 'approved', actor); toast(L('اعتُمد', 'Approved')) }}>{l.status === 'pending_manager' ? L('اعتماد المدير', 'Manager approve') : L('اعتماد نهائي', 'Final approve')}</Btn><Btn size="sm" kind="danger" onClick={() => decideLeave(l.id, 'rejected', actor)}>{L('رفض', 'Reject')}</Btn></div>}</td>
          </tr> })}</tbody></table></div>}</Panel>}
  </div>
}

/* ---------------- custody ---------------- */
export function Custody() {
  const L = useL(); const lang = useLang(); const { go } = useNav()
  const assets = useStore(s => s.assets); const emps = useStore(s => s.employees)
  const [f, setF] = useState('all'); const [q, setQ] = useState(''); const [assign, setAssign] = useState<string | null>(null); const [to, setTo] = useState('')
  const list = assets.filter(a => (f === 'all' || a.status === f) && (!q || a.tag.includes(q.toUpperCase()) || a.name.ar.includes(q) || a.name.en.toLowerCase().includes(q.toLowerCase())))
  const val = assets.filter(a => a.empId).reduce((x, a) => x + a.value, 0)
  return <div className="max-w-[1200px] mx-auto">
    <PageHead title={L('العهد', 'Custody')} sub={L(`${assets.filter(a => a.empId).length} أصلاً مسلّماً بقيمة $${val.toLocaleString('en-US')} — ${assets.filter(a => a.status === 'available').length} في المستودع`, `${assets.filter(a => a.empId).length} assets issued worth $${val.toLocaleString('en-US')} — ${assets.filter(a => a.status === 'available').length} in stock`)}
      actions={<><input className="field !w-56" placeholder={L('بحث بالرمز أو الاسم', 'Search tag or name')} value={q} onChange={e => setQ(e.target.value)} /><select className="field !w-48" value={f} onChange={e => setF(e.target.value)}><option value="all">{L('كل الحالات', 'All')}</option><option value="assigned">{L('مسلّمة', 'Issued')}</option><option value="pending_ack">{L('بانتظار التوقيع', 'Awaiting signature')}</option><option value="available">{L('في المستودع', 'In stock')}</option></select></>} />
    <Panel pad={false}><div className="scroll-x"><table className="tbl"><thead><tr><th>{L('الرمز', 'Tag')}</th><th>{L('الأصل', 'Asset')}</th><th>{L('الفئة', 'Category')}</th><th>{L('الرقم التسلسلي', 'Serial')}</th><th>{L('القيمة', 'Value')}</th><th>{L('بعهدة', 'Holder')}</th><th>{L('الحالة', 'Status')}</th><th /></tr></thead>
      <tbody>{list.map(a => { const e = emps.find(x => x.id === a.empId)
        return <tr key={a.id}><td className="ltr num">{a.tag}</td><td>{a.name[lang]}</td><td className="text-ink-2">{a.category[lang]}</td><td className="ltr num text-ink-3 text-[13px]">{a.serial}</td><td className="num">${a.value.toLocaleString('en-US')}</td>
          <td>{e ? <button className="hover:underline" onClick={() => go('employee', e.id)}>{lang === 'ar' ? e.name : e.nameEn}</button> : '—'}</td>
          <td>{a.status === 'assigned' ? <Badge tone="brand">{L('مسلّمة وموقّعة', 'Signed')}</Badge> : a.status === 'pending_ack' ? <Badge tone="brass">{L('بانتظار التوقيع', 'Awaiting signature')}</Badge> : <Badge>{L('في المستودع', 'In stock')}</Badge>}</td>
          <td className="text-end">{a.status === 'available' ? <Btn size="sm" onClick={() => setAssign(a.id)}>{L('تسليم', 'Issue')}</Btn> : <Btn size="sm" kind="ghost" onClick={() => { returnAsset(a.id); toast(L('أُعيدت للمستودع', 'Returned to stock')) }}>{L('استرجاع', 'Return')}</Btn>}</td></tr> })}</tbody></table></div></Panel>
    <Modal open={!!assign} onClose={() => setAssign(null)} title={L('تسليم عهدة', 'Issue asset')} footer={<><Btn onClick={() => setAssign(null)}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={!to} onClick={() => { assignAsset(assign!, to); setAssign(null); setTo(''); toast(L('سُلّمت — بانتظار توقيع الموظف', 'Issued — awaiting signature')) }}>{L('تسليم', 'Issue')}</Btn></>}>
      <Field label={L('الموظف المستلم', 'Recipient')}><select className="field" value={to} onChange={e => setTo(e.target.value)}><option value="">—</option>{emps.filter(e => e.status !== 'terminated').map(e => <option key={e.id} value={e.id}>{e.name} — {e.code}</option>)}</select></Field>
      <p className="text-[13px] text-ink-3 mt-2">{L('يصل للموظف طلب توقيع استلام إلكتروني، ولا تُعتبر العهدة مسلّمة حتى يوقّع.', 'The employee receives an e-signature request; the asset counts as issued only after signing.')}</p>
    </Modal>
  </div>
}

/* ---------------- policies ---------------- */
export function Policies() {
  const L = useL()
  const rows: [string, string][] = [
    [L('أيام العمل', 'Work days'), L('الأحد – الخميس (العطلة: الجمعة والسبت)', 'Sun – Thu (weekend Fri & Sat)')],
    [L('الوردية الصباحية', 'Day shift'), '\u200E08:00 – 16:00'],
    [L('فترة الراحة', 'Break'), L(`${SHIFT.breakMin} دقيقة غير محسوبة من العمل الفعلي`, `${SHIFT.breakMin} min, unpaid`)],
    [L('فترة السماح للدخول', 'Grace period'), L(`${SHIFT.grace} دقيقة — بعدها يُحتسب التأخير من بداية الوردية`, `${SHIFT.grace} min — late counted from shift start after that`)],
    [L('بداية احتساب الإضافي', 'Overtime threshold'), L(`${SHIFT.otThreshold} دقيقة بعد نهاية الوردية`, `${SHIFT.otThreshold} min after shift end`)],
    [L('أجر الإضافي', 'Overtime rate'), L('نهاري +25٪ — ليلي بعد 22:00 +50٪ — أيام العطل +100٪', 'Day +25% — night after 22:00 +50% — rest days +100%')],
    [L('الإجازة السنوية', 'Annual leave'), L('14 يوماً للسنة الأولى، 21 يوماً بعدها، 30 يوماً بعد 10 سنوات خدمة أو عمر 50', '14 days first year, 21 after, 30 after 10 years or age 50')],
    [L('الإجازة المرضية', 'Sick leave'), L('شهر بأجر كامل، ثم شهر 80٪، ثم شهر 75٪ خلال السنة', '1 month full pay, then 80%, then 75% per year')],
    [L('فترة التجربة', 'Probation'), L('90 يوماً', '90 days')],
    [L('مسار اعتماد الطلبات', 'Request approvals'), L('المدير المباشر ← الموارد البشرية', 'Direct manager → HR')],
    [L('مسار قرار التعيين', 'Appointment approvals'), L('الموارد البشرية ← المالية ← المدير التنفيذي ← الرئيس التنفيذي', 'HR → Finance → Executive Director → CEO')],
  ]
  return <div className="max-w-[900px] mx-auto">
    <PageHead title={L('سياسات الدوام والإجازات', 'Work & leave policies')} sub={L('القيم التي يعتمد عليها النظام في الحساب. تُضبط حسب لائحة الشركة الداخلية وقانون العمل النافذ.', 'Values the system calculates with. Set per internal regulations and applicable labour law.')} />
    <Panel pad={false}><table className="tbl"><tbody>{rows.map(([k, v]) => <tr key={k}><td className="font-medium w-[38%]">{k}</td><td className="num"><bdi>{v}</bdi></td></tr>)}</tbody></table></Panel>
  </div>
}

/* ---------------- jobs ---------------- */
export function Jobs() {
  const L = useL(); const lang = useLang(); const { go } = useNav()
  const jobs = useStore(s => s.jobs); const cands = useStore(s => s.candidates); const depts = useStore(s => s.depts)
  return <div className="max-w-[1200px] mx-auto">
    <PageHead title={L('الوظائف الشاغرة', 'Job openings')} sub={L('الوظائف المنشورة على بوابة التوظيف وعدد المتقدمين في كل مرحلة.', 'Roles published on the careers portal and applicants per stage.')} />
    <Panel pad={false}><div className="scroll-x"><table className="tbl"><thead><tr><th>{L('الوظيفة', 'Role')}</th><th>{L('الإدارة', 'Department')}</th><th>{L('المدينة', 'City')}</th><th>{L('الشواغر', 'Openings')}</th><th>{L('المتطلبات', 'Requirements')}</th><th>{L('نطاق الراتب', 'Pay band')}</th>{STAGES.slice(0, 5).map(s => <th key={s.id}>{s[lang]}</th>)}</tr></thead>
      <tbody>{jobs.map(j => <tr key={j.id} className="click" onClick={() => go('recruitment')}><td><div className="font-medium">{bi(j.title, lang)}</div><div className="text-[12px] text-ink-3 ltr text-start">{j.code}</div></td><td>{depts.find(d => d.id === j.dept)?.[lang]}</td><td>{bi(j.city, lang)}</td><td className="num">{j.openings}</td>
        <td className="text-[13px] text-ink-2">{j.minYears} {L('سنوات', 'yrs')} · {DEGREES[j.degree][lang]}</td><td className="num">${j.salaryMin}–${j.salaryMax}</td>
        {STAGES.slice(0, 5).map(s => { const n = cands.filter(c => c.jobId === j.id && c.stage === s.id).length; return <td key={s.id} className={`num text-center ${n ? 'font-semibold' : 'text-ink-3'}`}>{n || '·'}</td> })}</tr>)}</tbody></table></div></Panel>
  </div>
}
