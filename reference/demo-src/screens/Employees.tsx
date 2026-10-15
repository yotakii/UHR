import { useState } from 'react'
import { ArrowRight, ArrowLeft, UserPlus } from 'lucide-react'
import { AddEmployee } from './Rewards'
import { LinkCardButton } from './Terminal'
import { useStore, useL, useLang, bi, fmtDate, todayISO, addDays, isWeekend, money, daysBetween } from '../lib/store'
import { useNav } from '../lib/nav'
import { Panel, Badge, Avatar, PageHead, Tabs, KV, Empty, Btn } from '../ui/kit'
import { Timeline, Lifecycle } from '../ui/docs'
import { calcDay, minsLabel, annualEntitlement, LEAVE_TYPES } from '../lib/rules'
import type { Employee, EmpStatus } from '../lib/types'

export const statusBadge = (s: EmpStatus, L: (a: string, b: string) => string) => ({
  onboarding: <Badge tone="info">{L('قيد المباشرة', 'Onboarding')}</Badge>, probation: <Badge tone="brass">{L('فترة التجربة', 'Probation')}</Badge>, active: <Badge tone="brand">{L('على رأس العمل', 'Active')}</Badge>,
  leave: <Badge tone="neutral">{L('إجازة', 'On leave')}</Badge>, resigning: <Badge tone="warn">{L('مستقيل — فترة إخطار', 'Resigning')}</Badge>, terminated: <Badge tone="danger">{L('منتهي الخدمة', 'Exited')}</Badge>,
}[s])

export function Employees() {
  const L = useL(); const lang = useLang(); const { go } = useNav()
  const emps = useStore(s => s.employees); const depts = useStore(s => s.depts)
  const [q, setQ] = useState(''); const [dept, setDept] = useState('all'); const [st, setSt] = useState('current'); const [add, setAdd] = useState(false)
  const list = emps.filter(e => (dept === 'all' || e.dept === dept) && (st === 'all' || (st === 'current' ? e.status !== 'terminated' : e.status === st)) && (!q || e.name.includes(q) || e.nameEn.toLowerCase().includes(q.toLowerCase()) || e.code.includes(q.toUpperCase())))
    .sort((a, b) => Number(!!b.visitor) - Number(!!a.visitor) || a.code.localeCompare(b.code))
  return <div className="max-w-[1280px] mx-auto">
    <PageHead title={L('الموظفون', 'Employees')} sub={L(`${emps.filter(e => e.status !== 'terminated').length} موظفاً على رأس العمل في ${depts.length} إدارات`, `${emps.filter(e => e.status !== 'terminated').length} staff across ${depts.length} departments`)}
      actions={<><Btn kind="primary" icon={<UserPlus size={15} />} onClick={() => setAdd(true)}>{L('إضافة موظف', 'Add employee')}</Btn><input className="field !w-60" placeholder={L('بحث بالاسم أو الرقم الوظيفي', 'Search name or ID')} value={q} onChange={e => setQ(e.target.value)} />
        <select className="field !w-48" value={dept} onChange={e => setDept(e.target.value)}><option value="all">{L('كل الإدارات', 'All departments')}</option>{depts.map(d => <option key={d.id} value={d.id}>{d[lang]}</option>)}</select>
        <select className="field !w-44" value={st} onChange={e => setSt(e.target.value)}><option value="current">{L('الحاليون', 'Current')}</option><option value="probation">{L('فترة التجربة', 'Probation')}</option><option value="resigning">{L('مستقيلون', 'Resigning')}</option><option value="terminated">{L('منتهية خدمتهم', 'Exited')}</option><option value="all">{L('الكل', 'All')}</option></select></>} />
    <Panel pad={false}><div className="scroll-x"><table className="tbl">
      <thead><tr><th>{L('الموظف', 'Employee')}</th><th>{L('الرقم', 'ID')}</th><th>{L('المسمى', 'Title')}</th><th>{L('الإدارة', 'Department')}</th><th>{L('المدينة', 'City')}</th><th>{L('المباشرة', 'Hired')}</th><th>{L('الحالة', 'Status')}</th></tr></thead>
      <tbody>{list.map(e => <tr key={e.id} className="click" onClick={() => go('employee', e.id)}>
        <td><div className="flex items-center gap-2.5"><Avatar name={e.name} size={30} tone={e.visitor ? 'brass' : 'brand'} /><div><div className="font-medium">{lang === 'ar' ? e.name : e.nameEn}</div><div className="text-[12px] text-ink-3 ltr text-start">{e.email}</div></div></div></td>
        <td className="ltr num">{e.code}</td><td>{bi(e.title, lang)}</td><td>{depts.find(d => d.id === e.dept)?.[lang]}</td><td>{bi(e.city, lang)}</td><td className="num">{fmtDate(e.hireDate, lang)}</td><td>{statusBadge(e.status, L)}</td></tr>)}</tbody>
    </table></div><AddEmployee key={String(add)} open={add} onClose={() => setAdd(false)} />{list.length === 0 && <Empty title={L('لا نتائج مطابقة', 'No matches')}>{L('غيّر عبارة البحث أو المرشحات.', 'Change the search or filters.')}</Empty>}</Panel>
  </div>
}

export function lifecycleOf(e: Employee, hasResign: boolean, L: (a: string, b: string) => string) {
  const ob = e.onboarding
  const term = e.status === 'terminated'
  return [
    { label: L('طلب التوظيف', 'Application'), code: 'F-28', done: true },
    { label: L('المقابلة', 'Interview'), code: 'F-25', done: true },
    { label: L('عرض العمل', 'Offer'), code: 'F-29', done: true },
    { label: L('قرار التعيين', 'Appointment'), code: 'F-31', done: true },
    { label: L('المباشرة', 'Onboarding'), code: 'F-19', done: !ob || e.status !== 'onboarding', current: e.status === 'onboarding' },
    { label: L('فترة التجربة', 'Probation'), done: !ob || ['active', 'resigning', 'terminated'].includes(e.status), current: e.status === 'probation' },
    { label: L('على رأس العمل', 'Active'), done: hasResign || term, current: e.status === 'active' },
    { label: L('الاستقالة', 'Resignation'), code: 'F-26', done: term, current: e.status === 'resigning' },
    { label: L('إنهاء الخدمة', 'Exit'), code: 'F-33', done: term },
  ]
}

export function AttendanceTable({ empId, days = 14 }: { empId: string; days?: number }) {
  const L = useL(); const lang = useLang(); const P = lang === 'ar' ? '٪' : '%'
  const all = useStore(s => s.days.filter(d => d.empId === empId))
  const emp = useStore(s => s.employees.find(e => e.id === empId))!
  const rows: string[] = []; let d = todayISO()
  for (let i = 0; rows.length < days && i < 40; i++) { if (!isWeekend(d) || all.find(x => x.date === d)) rows.push(d); d = addDays(d, -1) }
  return <table className="tbl"><thead><tr><th>{L('التاريخ', 'Date')}</th><th>{L('الدخول', 'In')}</th><th>{L('الخروج', 'Out')}</th><th>{L('ساعات العمل', 'Worked')}</th><th>{L('تأخير', 'Late')}</th><th>{L('إضافي', 'Overtime')}</th><th>{L('الحالة', 'Status')}</th></tr></thead>
    <tbody>{rows.map(date => { const r = all.find(x => x.date === date); const c = calcDay(date, r?.inAt, r?.outAt); const before = new Date(emp.hireDate) > new Date(date + 'T23:00')
      return <tr key={date}><td className="num">{new Date(date + 'T12:00').toLocaleDateString(lang === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB', { weekday: 'short', day: 'numeric', month: 'short' })}</td>
        <td className="num ltr">{r?.inAt?.slice(11, 16) ?? '—'}</td><td className="num ltr">{r?.outAt?.slice(11, 16) ?? (r?.inAt && date === todayISO() ? L('بالدوام', 'on shift') : '—')}</td>
        <td className="num">{c.worked ? minsLabel(c.worked, lang) : '—'}</td><td className="num">{c.late ? <span className="text-warn">{minsLabel(c.late, lang)}</span> : '—'}</td>
        <td className="num">{c.ot ? <span className="text-brand">{minsLabel(c.ot, lang)} <span className="text-[11px] text-ink-3">+{c.otRate * 100}{P}</span></span> : '—'}</td>
        <td>{before ? <span className="text-ink-3 text-[12px]">{L('قبل المباشرة', 'Before start')}</span> : r?.status === 'leave' ? <Badge>{L('إجازة', 'Leave')}</Badge> : !r?.inAt ? (date === todayISO() ? <Badge tone="warn">{L('لم يسجل بعد', 'Not in yet')}</Badge> : c.status === 'weekend' ? <Badge>{L('عطلة', 'Weekend')}</Badge> : <Badge tone="danger">{L('غياب', 'Absent')}</Badge>) : c.late ? <Badge tone={r.justified ? 'brand' : 'warn'}>{r.justified ? L('تأخير مبرر', 'Justified') : L('متأخر', 'Late')}</Badge> : <Badge tone="brand">{L('حاضر', 'Present')}</Badge>}</td></tr> })}</tbody></table>
}

export function EmployeeFile({ id, self }: { id: string; self?: boolean }) {
  const L = useL(); const lang = useLang(); const { go } = useNav()
  const e = useStore(s => s.employees.find(x => x.id === id))
  const dept = useStore(s => s.depts.find(d => d.id === e?.dept))
  const mgr = useStore(s => s.employees.find(x => x.id === e?.managerId))
  const leaves = useStore(s => s.leaves.filter(l => l.empId === id))
  const assets = useStore(s => s.assets.filter(a => a.empId === id))
  const hasResign = useStore(s => s.resignations.some(r => r.empId === id && r.status !== 'rejected'))
  const [tab, setTab] = useState('overview')
  if (!e) return null
  const ent = annualEntitlement(e)
  const tenure = daysBetween(e.hireDate, todayISO())
  const Back = lang === 'ar' ? ArrowRight : ArrowLeft
  return <div className="max-w-[1200px] mx-auto">
    {!self && <button onClick={() => go('employees')} className="text-[13px] text-ink-3 hover:text-ink flex items-center gap-1 mb-2"><Back size={14} />{L('الموظفون', 'Employees')}</button>}
    <div className="bg-surface border border-line rounded-2xl p-5 mb-4">
      <div className="flex flex-wrap items-start gap-4">
        <Avatar name={e.name} size={56} tone={e.visitor ? 'brass' : 'brand'} />
        <div className="flex-1 min-w-[220px]">
          <div className="flex items-center gap-2 flex-wrap"><h1 className="text-[21px] font-bold">{lang === 'ar' ? e.name : e.nameEn}</h1>{statusBadge(e.status, L)}</div>
          <div className="text-ink-2">{bi(e.title, lang)} — {dept?.[lang]}</div>
          <div className="text-[12px] text-ink-3 mt-0.5"><span className="ltr">{e.code}</span> — {L('المدير المباشر', 'Manager')}: {mgr ? (lang === 'ar' ? mgr.name : mgr.nameEn) : '—'}</div>
        </div>
        <div className="grid grid-cols-3 gap-6 text-center">
          <div><div className="text-[12px] text-ink-3">{L('مدة الخدمة', 'Tenure')}</div><div className="font-bold num text-[18px]">{tenure < 0 ? L('لم يباشر', 'Not started') : tenure > 365 ? `${(tenure / 365).toFixed(1)} ${L('سنة', 'yrs')}` : `${tenure} ${L('يوماً', 'days')}`}</div></div>
          <div><div className="text-[12px] text-ink-3">{L('رصيد الإجازة', 'Leave balance')}</div><div className="font-bold num text-[18px]">{ent - e.leaveUsed} <span className="text-[12px] text-ink-3">/ {ent}</span></div></div>
          <div><div className="text-[12px] text-ink-3">{L('العهد', 'Assets')}</div><div className="font-bold num text-[18px]">{assets.length}</div></div>
        </div>
      </div>
      <div className="mt-5"><Lifecycle steps={lifecycleOf(e, hasResign, L)} /></div>
    </div>
    <Tabs value={tab} onChange={setTab} tabs={[{ v: 'overview', label: L('البيانات', 'Details') }, { v: 'attendance', label: L('الحضور', 'Attendance') }, { v: 'leaves', label: L('الإجازات والطلبات', 'Leave & requests'), count: leaves.length }, { v: 'assets', label: L('العهد', 'Custody'), count: assets.length }, { v: 'log', label: L('السجل الوظيفي', 'Career history'), count: e.log.length }]} />
    <div className="mt-4">
      {tab === 'overview' && <div className="grid lg:grid-cols-3 gap-4">
        <Panel title={L('البيانات الشخصية', 'Personal')}><KV k={L('الرقم الوطني', 'National ID')} v={<span className="ltr num">{e.nationalId}</span>} /><KV k={L('تاريخ الولادة', 'Born')} v={fmtDate(e.dob, lang)} /><KV k={L('الجوال', 'Mobile')} v={<span className="ltr">{e.phone}</span>} /><KV k={L('البريد', 'Email')} v={<span className="ltr">{e.email}</span>} /><KV k={L('بطاقة NFC', 'NFC card')} v={<span className="flex items-center gap-3"><span className="ltr num">{e.nfcUid || '—'}</span>{!self && <LinkCardButton emp={e} />}</span>} /></Panel>
        <Panel title={L('البيانات الوظيفية', 'Employment')}><KV k={L('تاريخ المباشرة', 'Start date')} v={fmtDate(e.hireDate, lang)} /><KV k={L('مقر العمل', 'Location')} v={bi(e.city, lang)} /><KV k={L('الدوام', 'Shift')} v={<span className="num ltr inline-block">08:00 – 16:00</span>} /><KV k={L('الراتب الشامل', 'Total pay')} v={money(e.salary, e.currency, lang)} />{e.endDate && <KV k={L('تاريخ انتهاء الخدمة', 'End date')} v={fmtDate(e.endDate, lang)} />}</Panel>
        <Panel title={L('الإجازات', 'Leave')}><KV k={L('الاستحقاق السنوي', 'Annual entitlement')} v={`${ent} ${L('يوماً', 'days')}`} /><KV k={L('المستخدم', 'Used')} v={e.leaveUsed} /><KV k={L('المتبقي', 'Remaining')} v={ent - e.leaveUsed} /><KV k={L('إجازات مرضية', 'Sick leave days')} v={e.sickUsed} /></Panel>
      </div>}
      {tab === 'attendance' && <Panel pad={false}><div className="scroll-x"><AttendanceTable empId={e.id} /></div></Panel>}
      {tab === 'leaves' && <Panel pad={false}>{leaves.length === 0 ? <Empty title={L('لا توجد طلبات', 'No requests')} /> : <table className="tbl"><thead><tr><th>{L('النوع', 'Type')}</th><th>{L('الفترة', 'Period')}</th><th>{L('السبب', 'Reason')}</th><th>{L('الحالة', 'Status')}</th></tr></thead><tbody>
        {leaves.map(l => <tr key={l.id}><td>{LEAVE_TYPES[l.type][lang]}</td><td className="num">{fmtDate(l.from, lang)}{l.to !== l.from && ` — ${fmtDate(l.to, lang)}`}</td><td className="text-ink-2">{l.reason}</td><td><LeaveStatus s={l.status} /></td></tr>)}</tbody></table>}</Panel>}
      {tab === 'assets' && <Panel pad={false}>{assets.length === 0 ? <Empty title={L('لا توجد عهد', 'No assets')} /> : <table className="tbl"><thead><tr><th>{L('الأصل', 'Asset')}</th><th>{L('الرمز', 'Tag')}</th><th>{L('القيمة', 'Value')}</th><th>{L('الحالة', 'Status')}</th></tr></thead><tbody>
        {assets.map(a => <tr key={a.id}><td>{a.name[lang]}</td><td className="ltr num">{a.tag}</td><td className="num">${a.value}</td><td>{a.status === 'assigned' ? <Badge tone="brand">{L('موقّع', 'Signed')}</Badge> : <Badge tone="brass">{L('بانتظار التوقيع', 'Awaiting signature')}</Badge>}</td></tr>)}</tbody></table>}</Panel>}
      {tab === 'log' && <Panel><Timeline items={e.log} /></Panel>}
    </div>
  </div>
}

export function LeaveStatus({ s }: { s: string }) {
  const L = useL()
  return s === 'approved' ? <Badge tone="brand">{L('معتمد', 'Approved')}</Badge> : s === 'rejected' ? <Badge tone="danger">{L('مرفوض', 'Rejected')}</Badge> : s === 'pending_hr' ? <Badge tone="brass">{L('بانتظار الموارد البشرية', 'Awaiting HR')}</Badge> : <Badge tone="info">{L('بانتظار المدير المباشر', 'Awaiting manager')}</Badge>
}
