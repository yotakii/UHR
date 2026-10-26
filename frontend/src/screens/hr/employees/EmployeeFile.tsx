import { useState } from 'react'
import { ArrowRight, ArrowLeft, Loader2, Pencil, CreditCard, ClipboardList } from 'lucide-react'
import { useL, useLang, bi, fmtDate, fmtDateTime, money } from '../../../lib/store'
import { setQuery, useRoute } from '../../../lib/router'
import { lifecycleOf, minsLabel, tenureLabel, useEmployee, type DayRow, type EmpFile } from '../../../lib/employees'
import { Avatar, Badge, Btn, Empty, KV, Panel, Tabs } from '../../../ui/kit'
import { Lifecycle, Timeline } from '../../../ui/docs'
import { EmpBadge } from './Employees'
import { EditProfile, CardModal } from './EditProfile'
import { DocumentsTab } from './DocumentsTab'
import { Masked } from '../../../ui/vault'

type Tab = 'overview' | 'attendance' | 'leaves' | 'assets' | 'docs' | 'log'
const TABS: Tab[] = ['overview', 'attendance', 'leaves', 'assets', 'docs', 'log']

/** M07 — الملف الوظيفي (EmployeeFile في الديمو) + تبويب «الملفات الإضافية» (SRS §10.11) */
export function EmployeeFile({ id }: { id: number }) {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const { data: e, isLoading, error } = useEmployee(id)
  const [edit, setEdit] = useState(false); const [card, setCard] = useState(false)
  const tab = TABS.find(t => t === route.query.tab) ?? 'overview'
  if (isLoading) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  if (error || !e) return <div className="max-w-[1200px] mx-auto bg-surface border border-line rounded-2xl"><Empty title={L('الملف غير موجود أو خارج صلاحيتك', 'File not found or outside your access')} action={<a href="/hr/employees" className="text-brand font-semibold">{L('العودة للدليل', 'Back to the directory')}</a>} /></div>
  const Back = lang === 'ar' ? ArrowRight : ArrowLeft
  const docs = e.documents.filter(d => d.status !== 'archived')

  return <div className="max-w-[1200px] mx-auto">
    <a href="/hr/employees" className="text-[13px] text-ink-3 hover:text-ink inline-flex items-center gap-1 mb-2"><Back size={14} />{L('الموظفون', 'Employees')}</a>
    <div className="bg-surface border border-line rounded-2xl p-5 mb-4">
      <div className="flex flex-wrap items-start gap-4">
        <Avatar size={56} />
        <div className="flex-1 min-w-[220px]">
          <div className="flex items-center gap-2 flex-wrap"><h1 className="text-[21px] font-bold">{bi(e.name, lang)}</h1><EmpBadge s={e.status} /></div>
          <div className="text-ink-2">{bi(e.title, lang)} — {bi(e.dept, lang)}</div>
          <div className="text-[12px] text-ink-3 mt-0.5"><bdi className="ltr">{e.code}</bdi> — {L('المدير المباشر', 'Manager')}: {e.manager ? <a className="hover:text-brand" href={`/hr/employees/${e.manager.id}`}>{bi(e.manager, lang)}</a> : '—'}</div>
          <div className="flex flex-wrap gap-2 mt-3">
            {e.can.edit && <Btn size="sm" icon={<Pencil size={14} />} onClick={() => setEdit(true)}>{L('تعديل البيانات', 'Edit details')}</Btn>}
            {e.can.onboarding && <a href={`/hr/onboarding/${e.id}`} className="inline-flex items-center gap-2 h-8 px-2.5 rounded-lg border border-line bg-surface text-[13px] font-semibold hover:bg-sub"><ClipboardList size={14} />{L('ملف المباشرة F-19', 'Onboarding F-19')}</a>}
          </div>
        </div>
        <div className="grid grid-cols-3 gap-4 sm:gap-6 text-center w-full sm:w-auto">
          <div><div className="text-[12px] text-ink-3">{L('مدة الخدمة', 'Tenure')}</div><div className="font-bold num text-[18px]">{tenureLabel(e.tenure_days, L)}</div></div>
          <div><div className="text-[12px] text-ink-3">{L('رصيد الإجازة', 'Leave balance')}</div><div className="font-bold num text-[18px]">{e.leave.remaining} <span className="text-[12px] text-ink-3">/ {e.leave.entitlement}</span></div></div>
          <div><div className="text-[12px] text-ink-3">{L('العهد', 'Assets')}</div><div className="font-bold num text-[18px]">{e.assets.length}</div></div>
        </div>
      </div>
      <div className="mt-5"><Lifecycle steps={lifecycleOf(e, L)} /></div>
    </div>
    <Tabs value={tab} onChange={v => setQuery({ tab: v === 'overview' ? undefined : v })} tabs={[
      { v: 'overview', label: L('البيانات', 'Details') }, { v: 'attendance', label: L('الحضور', 'Attendance') },
      { v: 'leaves', label: L('الإجازات والطلبات', 'Leave & requests'), count: e.leaves.length + e.services.length }, { v: 'assets', label: L('العهد', 'Custody'), count: e.assets.length },
      { v: 'docs', label: L('الملفات الإضافية', 'Documents'), count: docs.length }, { v: 'log', label: L('السجل الوظيفي', 'Career history'), count: e.logs.length }]} />
    <div className="mt-4">
      {tab === 'overview' && <div className="grid lg:grid-cols-3 gap-4">
        <Panel title={L('البيانات الشخصية', 'Personal')}>
          <KV k={L('الرقم الوطني', 'National ID')} v={<span className="ltr num">{e.national_id || '—'}</span>} /><KV k={L('تاريخ الولادة', 'Born')} v={fmtDate(e.dob ?? undefined, lang)} />
          <KV k={L('الجوال', 'Mobile')} v={<span className="ltr">{e.phone || '—'}</span>} /><KV k={L('البريد', 'Email')} v={<span className="ltr break-all">{e.email}</span>} />
          <KV k={L('بطاقة NFC', 'NFC card')} v={<span className="flex items-center gap-3 flex-wrap justify-end"><span className="ltr num">{e.nfc_uid || '—'}</span>
            {e.can.edit && <button onClick={() => setCard(true)} className="text-brand text-[13px] font-semibold inline-flex items-center gap-1"><CreditCard size={13} />{e.nfc_uid ? L('تغيير البطاقة', 'Change card') : L('ربط بطاقة', 'Link card')}</button>}</span>} />
        </Panel>
        <Panel title={L('البيانات الوظيفية', 'Employment')}>
          <KV k={L('تاريخ المباشرة', 'Start date')} v={fmtDate(e.hire_date, lang)} /><KV k={L('مقر العمل', 'Location')} v={bi(e.city, lang)} />
          <KV k={L('الدوام', 'Shift')} v={<span className="num ltr inline-block">{e.shift}</span>} />
          {e.can.salary && <KV k={L('الراتب الشامل', 'Total pay')} v={<Masked value={e.salary}><span className="num font-semibold">{e.salary && money(e.salary.amount, e.salary.currency, lang)}</span></Masked>} />}
          {e.end_date && <KV k={L('تاريخ انتهاء الخدمة', 'End date')} v={fmtDate(e.end_date, lang)} />}
        </Panel>
        <Panel title={L('الإجازات', 'Leave')}>
          <KV k={L('الاستحقاق السنوي', 'Annual entitlement')} v={`${e.leave.entitlement} ${L('يوماً', 'days')}`} /><KV k={L('المستخدم', 'Used')} v={<span className="num">{e.leave.used}</span>} />
          <KV k={L('المتبقي', 'Remaining')} v={<span className="num">{e.leave.remaining}</span>} /><KV k={L('إجازات مرضية', 'Sick leave days')} v={<span className="num">{e.leave.sick}</span>} />
        </Panel>
      </div>}
      {tab === 'attendance' && <Panel pad={false}><AttendanceTable rows={e.attendance} /></Panel>}
      {tab === 'leaves' && <Requests e={e} />}
      {tab === 'assets' && <Panel pad={false}>{e.assets.length === 0 ? <Empty title={L('لا توجد عهد', 'No assets')} /> : <table className="tbl"><thead><tr><th>{L('الأصل', 'Asset')}</th><th>{L('الرمز', 'Tag')}</th><th className="hidden sm:table-cell">{L('القيمة', 'Value')}</th><th>{L('الحالة', 'Status')}</th></tr></thead><tbody>
        {e.assets.map(a => <tr key={a.id}><td>{bi(a.name, lang)}<div className="text-[12px] text-ink-3">{bi(a.category, lang)}</div></td><td className="ltr num">{a.tag}</td><td className="num hidden sm:table-cell">${a.value}</td>
          <td>{a.status === 'assigned' ? <Badge tone="brand">{L('موقّع', 'Signed')}</Badge> : <Badge tone="brass">{L('بانتظار التوقيع', 'Awaiting signature')}</Badge>}</td></tr>)}</tbody></table>}</Panel>}
      {tab === 'docs' && <DocumentsTab e={e} />}
      {tab === 'log' && <Panel><Timeline items={e.logs.map(l => ({ ...l, form: l.form ?? undefined }))} /></Panel>}
    </div>
    {edit && <EditProfile e={e} onClose={() => setEdit(false)} />}
    {card && <CardModal e={e} onClose={() => setCard(false)} />}
  </div>
}

/** آخر 14 يوم عمل (AttendanceTable في الديمو) — الحساب على الخادم بسياسة الدوام النافذة */
export function AttendanceTable({ rows }: { rows: DayRow[] }) {
  const L = useL(); const lang = useLang(); const P = lang === 'ar' ? '٪' : '%'
  const badge = (r: DayRow) => ({
    before_start: <span className="text-ink-3 text-[12px]">{L('قبل المباشرة', 'Before start')}</span>, leave: <Badge>{L('إجازة', 'Leave')}</Badge>, holiday: <Badge>{L('عطلة رسمية', 'Holiday')}</Badge>,
    not_in: <Badge tone="warn">{L('لم يسجل بعد', 'Not in yet')}</Badge>, weekend: <Badge>{L('عطلة', 'Weekend')}</Badge>, absent: <Badge tone="danger">{L('غياب', 'Absent')}</Badge>, incomplete: <Badge tone="danger">{L('ناقص — بلا خروج', 'Incomplete — no out')}</Badge>,
    justified: <Badge tone="brand">{L('مبرر', 'Justified')}</Badge>, late: <Badge tone="warn">{L('متأخر', 'Late')}</Badge>, present: <Badge tone="brand">{L('حاضر', 'Present')}</Badge>,
  }[r.status])
  return <table className="tbl"><thead><tr><th>{L('التاريخ', 'Date')}</th><th>{L('الدخول', 'In')}<span className="sm:hidden"> / {L('الخروج', 'Out')}</span></th><th className="hidden sm:table-cell">{L('الخروج', 'Out')}</th><th className="hidden sm:table-cell">{L('ساعات العمل', 'Worked')}</th>
    <th className="hidden md:table-cell">{L('تأخير', 'Late')}</th><th className="hidden md:table-cell">{L('إضافي', 'Overtime')}</th><th>{L('الحالة', 'Status')}</th></tr></thead>
    <tbody>{rows.map(r => <tr key={r.date}>
      <td className="num">{new Date(r.date + 'T12:00').toLocaleDateString(lang === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB', { weekday: 'short', day: 'numeric', month: 'short' })}</td>
      <td className="num ltr">{r.in ?? '—'}<div className="sm:hidden text-ink-3">{r.out ?? (r.on_shift ? L('بالدوام', 'on shift') : '—')}</div></td><td className="num ltr hidden sm:table-cell">{r.out ?? (r.on_shift ? L('بالدوام', 'on shift') : '—')}</td>
      <td className="num hidden sm:table-cell">{r.worked ? minsLabel(r.worked, lang) : '—'}</td>
      <td className="num hidden md:table-cell">{r.late ? <span className="text-warn">{minsLabel(r.late, lang)}</span> : '—'}</td>
      <td className="num hidden md:table-cell">{r.ot ? <span className="text-brand">{minsLabel(r.ot, lang)} <span className="text-[11px] text-ink-3">+{Math.round(r.ot_rate * 100)}{P}</span></span> : '—'}</td>
      <td>{badge(r)}</td></tr>)}</tbody></table>
}

export function LeaveStatus({ s }: { s: string }) {
  const L = useL()
  return s === 'approved' ? <Badge tone="brand">{L('معتمد', 'Approved')}</Badge> : s === 'rejected' ? <Badge tone="danger">{L('مرفوض', 'Rejected')}</Badge> : s === 'cancelled' ? <Badge>{L('ملغى', 'Cancelled')}</Badge>
    : s === 'pending_hr' ? <Badge tone="brass">{L('بانتظار الموارد البشرية', 'Awaiting HR')}</Badge> : s === 'pending_exec' ? <Badge tone="brass">{L('بانتظار الإدارة العليا', 'Awaiting executive')}</Badge>
    : <Badge tone="info">{L('بانتظار المدير المباشر', 'Awaiting manager')}</Badge>
}

function Requests({ e }: { e: EmpFile }) {
  const L = useL(); const lang = useLang()
  const kinds: Record<string, string> = { salary_cert: L('شهادة راتب', 'Salary certificate'), experience_cert: L('شهادة خبرة', 'Experience certificate'), advance: L('سلفة', 'Salary advance'), data_update: L('تحديث بيانات', 'Data update') }
  if (!e.leaves.length && !e.services.length) return <Panel><Empty title={L('لا توجد طلبات', 'No requests')} /></Panel>
  return <div className="space-y-4">
    {e.leaves.length > 0 && <Panel title={L('الإجازات', 'Leave')} pad={false}><table className="tbl"><thead><tr><th>{L('النوع', 'Type')}</th><th>{L('الفترة', 'Period')}</th><th className="hidden md:table-cell">{L('السبب', 'Reason')}</th><th>{L('الحالة', 'Status')}</th></tr></thead><tbody>
      {e.leaves.map(l => <tr key={l.id}><td>{bi(l.type_name, lang)}<div className="text-[12px] text-ink-3 md:hidden">{l.reason}</div></td>
        <td className="num">{fmtDate(l.from, lang)}{l.to !== l.from && ` — ${fmtDate(l.to, lang)}`}{l.hours ? <span className="text-ink-3"> · {l.hours} {L('س', 'h')}</span> : null}</td>
        <td className="text-ink-2 hidden md:table-cell">{l.reason}</td><td><LeaveStatus s={l.status} /></td></tr>)}</tbody></table></Panel>}
    {e.services.length > 0 && <Panel title={L('طلبات الخدمات', 'Service requests')} pad={false}><table className="tbl"><thead><tr><th>{L('الطلب', 'Request')}</th><th className="hidden sm:table-cell">{L('التاريخ', 'Date')}</th><th className="hidden md:table-cell">{L('ملاحظة', 'Note')}</th><th>{L('الحالة', 'Status')}</th></tr></thead><tbody>
      {e.services.map(s => <tr key={s.id}><td>{kinds[s.kind] ?? s.kind}<div className="text-[12px] text-ink-3 md:hidden">{s.note}</div></td><td className="num hidden sm:table-cell">{fmtDateTime(s.at, lang)}</td><td className="text-ink-2 hidden md:table-cell">{s.note}</td>
        <td>{s.status === 'done' || s.status === 'approved' ? <Badge tone="brand">{L('منجز', 'Done')}</Badge> : s.status === 'rejected' ? <Badge tone="danger">{L('مرفوض', 'Rejected')}</Badge> : <Badge tone="brass">{L('قيد المعالجة', 'In progress')}</Badge>}</td></tr>)}</tbody></table></Panel>}
  </div>
}
