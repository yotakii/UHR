import { useState } from 'react'
import { Printer, Plus, Check, Users, Clock, ClipboardList, UserCheck } from 'lucide-react'
import { useL, useLang, todayISO, addDays, fmtDate } from '../lib/store'
import { Btn, Badge, Panel, PageHead, Field, Seg, Avatar, Modal, Drawer, Tabs, Empty, Progress, toast, SignaturePad, KV } from '../ui/kit'
import { Paper, SigBox, Timeline, Lifecycle, OfferDoc, DecisionDoc } from '../ui/docs'
import { CountUp, Sparkline, Bars, Radar, Ring, Donut, Heatmap } from '../ui/viz'
import { Logo, LangToggle, ThemeToggle } from '../ui/brand'
import type { LogEvent } from '../lib/types'
import type { MyOffer, MyAppointment } from '../lib/recruitment'
import { PermissionGrid } from '../ui/permgrid'
import type { Cell, PermGrid } from '../lib/console'
import type { PermAction } from '../lib/session'

/**
 * مكتبة المكونات — المهمة 01.
 * صفحة مراجعة داخلية: كل مكون من ui/kit و ui/docs و ui/viz و ui/brand بحالاته،
 * للمقارنة البصرية مع ديمو v26 بالثيمات الثلاثة واللغتين. لا تظهر للمستخدمين النهائيين.
 */
const today = todayISO()
const offer: MyOffer = { title: 'محاسب', contract: 'fixed', currency: 'USD', basic: 650, housing: 80, transport: 40, fuel: 30, total: 800, leave_days: 14, start_date: addDays(today, 10), issued_at: addDays(today, -1) + 'T13:00:00', status: 'sent', responded_at: null, note: null, signature: null }
const appointment: MyAppointment = { decision_no: 'QR-2026-417', issued_at: today + 'T09:00:00', complete: false, approvals: [
  { level: 1, role: { ar: 'الموارد البشرية', en: 'Human Resources' }, name: 'رشا الخطيب', status: 'approved', at: today + 'T09:10:00' },
  { level: 2, role: { ar: 'الإدارة المالية', en: 'Finance' }, name: 'باسل الأتاسي', status: 'approved', at: today + 'T10:25:00' },
  { level: 3, role: { ar: 'المدير التنفيذي', en: 'Executive Director' }, name: 'مازن الدروبي', status: 'pending', at: null },
  { level: 4, role: { ar: 'الرئيس التنفيذي', en: 'CEO' }, name: 'هاني السمان', status: 'pending', at: null }] }
const log: LogEvent[] = [
  { at: addDays(today, -4) + 'T10:20:00', ar: 'استلام طلب التوظيف عبر البوابة', en: 'Application received via portal', kind: 'info', form: 'F-28' },
  { at: addDays(today, -3) + 'T11:00:00', ar: 'اجتياز الفرز الأولي', en: 'Passed screening', kind: 'ok' },
  { at: addDays(today, -2) + 'T12:30:00', ar: 'تقييم المقابلة الأولية: موصى به', en: 'HR interview: recommended', kind: 'ok', form: 'F-25' },
  { at: addDays(today, -1) + 'T13:00:00', ar: 'طلب تعديل بنود العرض: زيادة بدل المواصلات', en: 'Requested changes: transport allowance', kind: 'warn', form: 'F-29' },
  { at: today + 'T08:40:00', ar: 'نقل من «فرز أولي» إلى «غير مقبول»', en: 'Moved from "Screening" to "Not selected"', kind: 'danger' },
]
const cand = { name: 'لين العلي', nameEn: 'Leen Al-Ali' }

export function DevKit() {
  const L = useL(); const lang = useLang(); const P = lang === 'ar' ? '٪' : '%'
  const [seg, setSeg] = useState<'accepted' | 'negotiation' | 'declined'>('accepted')
  const [tab, setTab] = useState('a'); const [modal, setModal] = useState(false); const [drawer, setDrawer] = useState(false)
  const [sig, setSig] = useState<string>()
  const days = [L('الأحد', 'Sun'), L('الاثنين', 'Mon'), L('الثلاثاء', 'Tue'), L('الأربعاء', 'Wed'), L('الخميس', 'Thu'), L('الأحد', 'Sun'), L('الاثنين', 'Mon')]
  const rates = [94, 97, 91, 96, 95, 86, 62]
  return <div className="min-h-full flex flex-col app-glow">
    <header className="h-[68px] border-b border-line bg-canvas/70 backdrop-blur flex items-center justify-between gap-3 px-5 md:px-8 sticky top-0 z-20" data-noprint>
      <div className="flex items-center gap-3"><Logo h={30} /><span className="hidden sm:inline text-[12px] text-ink-3 border-s border-line ps-3">{L('مكتبة المكونات — المهمة 01', 'Component library — Task 01')}</span></div>
      <div className="flex items-center gap-2"><a href="#status" className="text-[13px] text-ink-3 hover:text-ink">{L('فحص الجاهزية', 'Health')}</a><LangToggle /><ThemeToggle /></div>
    </header>
    <main className="flex-1 max-w-[1200px] w-full mx-auto px-4 md:px-6 py-6 space-y-6">
      <PageHead title={L('مكتبة المكونات', 'Component library')} sub={L('كل مكونات الواجهة بحالاتها — للمقارنة مع ديمو المعرض.', 'Every UI component in its states — compare against the exhibition demo.')}
        crumbs={L('المهمة 01 · نظام التصميم', 'Task 01 · Design system')}
        actions={<><Btn kind="primary" icon={<Plus size={15} />} onClick={() => toast(L('تمت العملية بنجاح', 'Done successfully'))}>{L('إظهار Toast', 'Show toast')}</Btn></>} />

      <Panel title={L('الأزرار', 'Buttons')} sub="Btn · primary / secondary / ghost / danger / brass · sm / md / lg">
        <div className="space-y-3">{(['sm', 'md', 'lg'] as const).map(sz => <div key={sz} className="flex flex-wrap items-center gap-2">
          <Btn size={sz} kind="primary" icon={<Check size={15} />}>{L('اعتماد', 'Approve')}</Btn><Btn size={sz}>{L('إلغاء', 'Cancel')}</Btn><Btn size={sz} kind="ghost">{L('تفاصيل', 'Details')}</Btn><Btn size={sz} kind="danger">{L('رفض', 'Reject')}</Btn><Btn size={sz} kind="brass">{L('بانتظار', 'Pending')}</Btn><Btn size={sz} kind="primary" disabled>{L('معطّل', 'Disabled')}</Btn>
        </div>)}</div>
      </Panel>

      <div className="grid lg:grid-cols-2 gap-6">
        <Panel title={L('الشارات والصور الشخصية', 'Badges & avatars')}>
          <div className="flex flex-wrap gap-2"><Badge>{L('عطلة', 'Weekend')}</Badge><Badge tone="brand">{L('على رأس العمل', 'Active')}</Badge><Badge tone="brass">{L('فترة التجربة', 'Probation')}</Badge><Badge tone="danger">{L('منتهي الخدمة', 'Exited')}</Badge><Badge tone="info">{L('قيد المباشرة', 'Onboarding')}</Badge><Badge tone="warn" dot>{L('متأخر', 'Late')}</Badge></div>
          <div className="flex items-center gap-3 mt-4"><Avatar size={52} /><Avatar size={40} tone="brass" /><Avatar size={32} tone="ink" /><span className="text-[13px] text-ink-3">{L('موظف · زائر المعرض · مهام', 'Employee · Expo visitor · Tasks')}</span></div>
        </Panel>
        <Panel title={L('المبدّلات والتبويبات', 'Segments & tabs')}>
          <Seg value={seg} onChange={setSeg} options={[{ v: 'accepted', label: L('قبول', 'Accept') }, { v: 'negotiation', label: L('طلب تعديل', 'Negotiate') }, { v: 'declined', label: L('اعتذار', 'Decline') }]} />
          <div className="mt-4"><Tabs value={tab} onChange={setTab} tabs={[{ v: 'a', label: L('بيانات الطلب', 'Application') }, { v: 'b', label: L('المطابقة', 'Match') }, { v: 'c', label: L('السجل', 'History'), count: 5 }]} /></div>
          <div className="mt-4 space-y-2"><Progress value={82} /><Progress value={55} tone="brass" /><Progress value={30} tone="danger" /><Progress value={64} tone="info" /></div>
        </Panel>
      </div>

      <Panel title={L('الحقول', 'Fields')} sub={L('الحقول الإلزامية بنجمة، والخطأ تحت الحقل', 'Required fields are starred; errors under the field')}>
        <div className="grid sm:grid-cols-3 gap-4">
          <Field label={L('الاسم الثلاثي', 'Full name')} req><input className="field" defaultValue="لين العلي" /></Field>
          <Field label={L('البريد الإلكتروني', 'Email')} req error={L('صيغة البريد غير صحيحة', 'Invalid email format')}><input className="field ltr text-start" aria-invalid defaultValue="leen.ali@" /></Field>
          <Field label={L('الرقم الوطني', 'National ID')} hint={L('يُستخدم للتحقق من عدم تكرار الطلب', 'Used to detect duplicate applications')}><input className="field ltr text-start" defaultValue="01020304050" /></Field>
          <Field label={L('الحالة الاجتماعية', 'Marital status')}><select className="field"><option>{L('أعزب/عزباء', 'Single')}</option><option>{L('متزوج/ة', 'Married')}</option></select></Field>
          <Field label={L('تاريخ المباشرة', 'Start date')}><input type="date" className="field" defaultValue={today} /></Field>
          <Field label={L('الراتب المتوقع (دولار)', 'Expected salary (USD)')}><input type="number" className="field num" defaultValue={900} /></Field>
          <Field label={L('ملاحظات', 'Notes')} className="sm:col-span-3"><textarea className="field" defaultValue={L('مرشحة متمكنة، تواصل جيد وخبرة مناسبة.', 'Capable candidate, good communication.')} /></Field>
        </div>
      </Panel>

      <div className="grid lg:grid-cols-2 gap-6">
        <Panel title={L('التوقيع الإلكتروني', 'E-signature')} sub="SignaturePad · SigBox">
          <SignaturePad value={sig} onChange={setSig} />
          <div className="grid grid-cols-2 gap-3 mt-4"><SigBox label={L('توقيع المتقدم', 'Applicant signature')} name="لين العلي" sig={sig} at={today + 'T11:42:00'} /><SigBox label={L('عن الشركة — الموارد البشرية', 'For the company — HR')} name="رشا الخطيب" /></div>
        </Panel>
        <Panel title={L('النوافذ', 'Overlays')} sub="Modal · Drawer · Toast · Empty">
          <div className="flex flex-wrap gap-2"><Btn onClick={() => setModal(true)}>{L('فتح نافذة', 'Open modal')}</Btn><Btn onClick={() => setDrawer(true)}>{L('فتح لوح جانبي', 'Open drawer')}</Btn><Btn onClick={() => toast(L('أُرسل الطلب لمديرك المباشر', 'Sent to your manager'))}>Toast</Btn></div>
          <Empty title={L('لا توجد طلبات معلقة', 'Nothing pending')}>{L('الطلبات الجديدة من الموظفين تظهر هنا.', 'New employee requests appear here.')}</Empty>
          <KV k={L('تاريخ التقديم', 'Submitted')} v={fmtDate(addDays(today, -4), lang)} /><KV k={L('المرحلة الحالية', 'Current stage')} v={L('عرض العمل', 'Offer')} />
        </Panel>
      </div>

      <Panel title={L('مسار الحياة والسجل', 'Lifecycle & history')} sub="Lifecycle · Timeline">
        <Lifecycle steps={[{ label: L('استلام الطلب', 'Received'), code: 'F-28', done: true }, { label: L('الفرز', 'Screening'), done: true }, { label: L('المقابلة', 'Interview'), code: 'F-25', done: true }, { label: L('التقييم الفني', 'Technical'), done: true }, { label: L('عرض العمل', 'Offer'), code: 'F-29', done: false, current: true }, { label: L('قرار التعيين', 'Appointment'), code: 'F-31', done: false }, { label: L('المباشرة', 'Onboarding'), code: 'F-19', done: false }]} />
        <div className="mt-5 max-w-xl"><Timeline items={log} /></div>
      </Panel>

      <Paper code="F-29" title={L('عرض العمل الرسمي', 'Official job offer')} actions={<Badge tone="info">{L('أُرسل — بانتظار رد المتقدم', 'Sent — awaiting response')}</Badge>}>
        <OfferDoc c={cand} offer={offer} jobTitle="محاسب" />
      </Paper>

      <Paper code="F-31" title={L('قرار التعيين المالي والإداري', 'Appointment decision')} sub={L('مطابقة الملاك العددي وسلسلة الاعتماد الرباعية', 'Headcount check and four-level approval')}>
        <DecisionDoc c={cand} ap={appointment} jobTitle={L('محاسب', 'Accountant')} deptName={L('الإدارة المالية', 'Finance')} />
      </Paper>

      <div className="grid sm:grid-cols-2 xl:grid-cols-4 gap-4">
        {[{ k: L('إجمالي الموظفين', 'Total employees'), v: 32, sub: L('8 إدارات · 6 مدن', '8 departments · 6 cities'), icon: <Users size={20} />, spark: [28, 29, 29, 30, 31, 31, 32], warn: false },
          { k: L('حاضرون اليوم', 'Present today'), v: 27, sub: L(`نسبة الحضور 84${P}`, '84% attendance'), icon: <UserCheck size={20} />, spark: rates, warn: false },
          { k: L('متأخرون اليوم', 'Late today'), v: 7, sub: L('سماح 15 دقيقة', '15-min grace'), icon: <Clock size={20} />, spark: [3, 5, 2, 4, 6, 3, 7], warn: true },
          { k: L('بنود بانتظارك', 'Waiting for you'), v: 9, sub: L('4 طلبات تحتاج موافقتك', '4 requests need approval'), icon: <ClipboardList size={20} />, spark: [6, 8, 7, 9, 8, 10, 9], warn: true }].map((x, i) =>
          <div key={i} className="hoverlift rounded-2xl border border-line bg-surface p-5 fadeup" style={{ animationDelay: `${i * 70}ms` }}>
            <div className="flex items-start justify-between"><span className="text-[13px] font-semibold text-ink-2">{x.k}</span><span className={`size-10 rounded-xl grid place-items-center border ${x.warn ? 'bg-brass-soft text-brass border-brass/30' : 'bg-brand-soft text-brand border-brand/30'}`}>{x.icon}</span></div>
            <div className="mt-2 text-[34px] font-black font-display num leading-none"><CountUp to={x.v} /></div>
            <div className="mt-3 flex items-end justify-between gap-2"><span className={`text-[12.5px] font-medium ${x.warn ? 'text-brass' : 'text-brand'}`}>{x.sub}</span><Sparkline data={x.spark} color={x.warn ? 'var(--warn)' : 'var(--primary)'} /></div>
          </div>)}
      </div>

      <div className="grid xl:grid-cols-[1.7fr_1fr] gap-6">
        <Panel title={L('اتجاه الحضور الأسبوعي', 'Weekly attendance')} sub={L('بطاقات NFC · كل المواقع', 'NFC · All locations')} action={<span className="text-[13px] font-bold text-brand num">{L(`متوسط 94${P}`, '94% average')}</span>}>
          <Bars height={190} data={days.map((d, i) => ({ label: d, value: rates[i], tip: `${rates[i]}${P}`, tone: i === 6 ? 'muted' as const : rates[i] === 86 ? 'low' as const : undefined }))} />
        </Panel>
        <Panel title={L('أرصدتي', 'My balances')}><div className="space-y-5">
          <Ring v={9} max={21} label={L('إجازة سنوية متبقية', 'Annual leave left')} sub={L('استخدمت 12 يوماً', '12 days used')} />
          <Ring v={30} max={30} label={L('إجازة مرضية بأجر كامل', 'Full-pay sick leave')} sub={L('حسب السياسة', 'Per policy')} tone="var(--user)" />
          <Ring v={2650} max={2650} label={L('نقاط المكافآت', 'Reward points')} sub={L('استبدلها من المتجر', 'Redeem in the store')} tone="var(--warn)" /></div></Panel>
      </div>

      <div className="grid lg:grid-cols-2 gap-6">
        <Panel title={L('الرادار', 'Radar')}><div className="flex justify-center"><Radar axes={[{ label: L('سنوات الخبرة', 'Experience'), value: .85 }, { label: L('المؤهل العلمي', 'Qualification'), value: .85 }, { label: L('الراتب المتوقع', 'Salary'), value: .75 }, { label: L('اللغة الإنكليزية', 'English'), value: .7 }, { label: L('اكتمال الملف', 'File'), value: .8 }]} /></div></Panel>
        <Panel title={L('أنواع الطلبات', 'Request types')}><Donut parts={[{ label: L('إجازة سنوية', 'Annual leave'), value: 4, color: 'var(--primary)' }, { label: L('إجازة مرضية', 'Sick leave'), value: 2, color: 'var(--warn)' }, { label: L('مهمة رسمية', 'Official mission'), value: 1, color: 'var(--danger)' }, { label: L('مغادرة ساعية', 'Hourly permission'), value: 3, color: 'var(--user)' }]} /></Panel>
      </div>

      <Panel title={L('خريطة الحضور حسب الإدارة', 'Attendance heatmap by department')} sub={L('آخر 10 أيام عمل — مرّر فوق الخلية للتفاصيل', 'Last 10 working days — hover a cell')}>
        <Heatmap pct={P} lateLabel={L('متأخرون', 'late')} cols={['29', '30', '01', '02', '05', '06', '07', '08', '09', '12']} rows={[L('الإدارة التنفيذية', 'Executive Office'), L('الموارد البشرية', 'Human Resources'), L('تقنية المعلومات', 'IT'), L('المبيعات', 'Sales')].map((r, k) => ({ label: r, cells: Array.from({ length: 10 }, (_, i) => ({ rate: [1, .92, .8, .7][(i + k) % 4], late: (i + k) % 3, tip: r })) }))} />
      </Panel>

      <Panel title={L('جدول', 'Table')} pad={false}><div className="scroll-x"><table className="tbl"><thead><tr><th>{L('الموظف', 'Employee')}</th><th>{L('الرقم', 'ID')}</th><th>{L('الإدارة', 'Department')}</th><th>{L('الحالة', 'Status')}</th></tr></thead><tbody>
        {[['سامر الحلبي', 'Samer Al-Halabi', 'UHR-1007', L('تقنية المعلومات', 'IT'), <Badge tone="brand">{L('على رأس العمل', 'Active')}</Badge>], ['حسام البيطار', 'Hussam Al-Bitar', 'UHR-1028', L('خدمة العملاء', 'Customer Service'), <Badge tone="warn">{L('مستقيل — فترة إخطار', 'Resigning')}</Badge>]].map(r => <tr key={String(r[2])} className="click" onClick={() => toast(String(r[2]))}><td><div className="flex items-center gap-2.5"><Avatar size={30} /><span className="font-medium">{lang === 'ar' ? r[0] : r[1]}</span></div></td><td className="ltr num">{r[2]}</td><td>{r[3]}</td><td>{r[4]}</td></tr>)}
      </tbody></table></div></Panel>

      <Panel title="PermissionGrid" pad={false}><KitPermissions /></Panel>

      <Panel title={L('الطباعة', 'Print')}><Btn icon={<Printer size={15} />} onClick={() => window.print()}>{L('طباعة الصفحة (ثيم فاتح، A4)', 'Print page (light, A4)')}</Btn></Panel>
    </main>

    <Modal open={modal} onClose={() => setModal(false)} title={L('سبب عدم القبول', 'Reason for not selecting')} footer={<><Btn onClick={() => setModal(false)}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="danger" onClick={() => setModal(false)}>{L('تأكيد', 'Confirm')}</Btn></>}>
      <select className="field"><option>{L('تم شغل الوظيفة', 'Position filled')}</option></select>
      <p className="text-[13px] text-ink-3 mt-2">{L('سيصل المتقدم إشعار مهذب بانتهاء مراجعة طلبه.', 'The applicant will receive a courteous notice.')}</p>
    </Modal>
    <Drawer open={drawer} onClose={() => setDrawer(false)} width={560}><div className="p-5"><div className="font-display font-bold text-[17px]">{L('سامر الحلبي', 'Samer Al-Halabi')}</div><div className="text-[12.5px] text-ink-3">{L('مطور برمجيات أول', 'Senior Software Developer')}</div></div></Drawer>
  </div>
}

/** عيّنة ثابتة لشبكة الصلاحيات (بلا خادم) */
function KitPermissions() {
  const grid: Pick<PermGrid, 'actions' | 'scopes' | 'modules'> = {
    actions: [['view', 'ع', 'عرض', 'View'], ['create', 'ض', 'إضافة', 'Create'], ['edit', 'ت', 'تعديل', 'Edit'], ['decide', 'ق', 'اعتماد/رفض', 'Approve'], ['export', 'ص', 'تصدير', 'Export'], ['print', 'ط', 'طباعة', 'Print'], ['archive', 'ح', 'أرشفة', 'Archive']]
      .map(([key, letter, ar, en]) => ({ key: key as PermAction, letter, ar, en })),
    scopes: [{ key: 'own', ar: 'خاصته', en: 'Own' }, { key: 'team', ar: 'فريقه', en: 'Team' }, { key: 'all', ar: 'الكل', en: 'All' }],
    modules: [{ key: 'leaves', ar: 'طلبات الإجازة', en: 'Leave requests' }, { key: 'payroll', ar: 'الرواتب والسلف', en: 'Payroll & advances' }, { key: 'jobs', ar: 'الشواغر', en: 'Job openings' }],
  }
  const [cells, setCells] = useState<Record<string, Cell>>({ leaves: { actions: ['view', 'decide', 'print'], scope: 'team' }, payroll: { actions: [], scope: 'all' }, jobs: { actions: ['view'], scope: 'all' } })
  return <PermissionGrid grid={grid} cells={cells} onChange={(m, c) => setCells(x => ({ ...x, [m]: c }))} />
}
