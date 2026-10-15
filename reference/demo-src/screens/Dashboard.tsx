import { ChevronLeft, ChevronRight, Users, UserCheck, Clock, ClipboardList, Check, X, Activity, Maximize, AlertTriangle } from 'lucide-react'
import { useStore, useL, useLang, fmtDateTime, todayISO, addDays, isWeekend, fmtDate } from '../lib/store'
import { decideLeave, SERVICE_LABEL } from '../lib/actions'
import { useNav } from '../lib/nav'
import { toggleFullscreen } from '../lib/ui'
import { Panel, Avatar, Badge, Btn, toast } from '../ui/kit'
import { CountUp, Sparkline, Bars } from '../ui/viz'
import { STAGES, calcDay, LEAVE_TYPES, slaState } from '../lib/rules'

export function Dashboard() {
  const L = useL(); const lang = useLang(); const { go } = useNav()
  const s = useStore(x => x)
  const me = s.employees.find(e => e.id === s.session?.id)
  const today = todayISO()
  const active = s.employees.filter(e => e.status !== 'terminated')
  const todays = s.days.filter(d => d.date === today)
  const present = todays.filter(d => d.inAt).length
  const late = todays.filter(d => d.inAt && calcDay(today, d.inAt).late > 0).length
  const pendingLeaves = s.leaves.filter(l => l.status.startsWith('pending'))
  const Chev = lang === 'ar' ? ChevronLeft : ChevronRight
  const P = lang === 'ar' ? '٪' : '%'
  const hour = new Date().getHours()
  const greet = hour < 12 ? L('صباح الخير', 'Good morning') : L('مساء الخير', 'Good evening')

  const wd: string[] = []; for (let d = today; wd.length < 7; d = addDays(d, -1)) if (!isWeekend(d)) wd.unshift(d)
  const rate = (d: string) => { const n = s.days.filter(x => x.date === d && x.inAt).length; return Math.round(n / Math.max(1, active.length) * 100) }
  const rates = wd.map(rate)
  const minR = Math.min(...rates.slice(0, -1))
  const avg = Math.round(rates.slice(0, -1).reduce((a, b) => a + b, 0) / Math.max(1, rates.length - 1))
  const lateSeries = wd.map(d => s.days.filter(x => x.date === d && x.inAt && calcDay(d, x.inAt).late > 0).length)

  type Task = { key: string; who: string; what: string; tag?: string; at: string; open: () => void; leaveId?: string; actor?: string }
  const tasks: Task[] = []
  s.candidates.filter(c => c.appointment && c.appointment.approvals.some(a => a.status === 'pending')).forEach(c => { const p = c.appointment!.approvals.find(a => a.status === 'pending')!; tasks.push({ key: 'ap' + c.id, who: lang === 'ar' ? c.name : (c.nameEn || c.name), what: L(`قرار تعيين بانتظار اعتماد ${p.role.ar}`, `Appointment awaiting ${p.role.en}`), tag: 'F-31', open: () => go('candidate', c.id + ':decision'), at: c.offer?.respondedAt ?? c.createdAt }) })
  s.candidates.filter(c => c.offer?.status === 'negotiation').forEach(c => tasks.push({ key: 'ng' + c.id, who: lang === 'ar' ? c.name : (c.nameEn || c.name), what: L('طلب تعديل بنود عرض العمل', 'Requested offer changes'), tag: 'F-29', open: () => go('candidate', c.id + ':offer'), at: c.offer!.respondedAt! }))
  s.candidates.filter(c => c.stage === 'new').forEach(c => tasks.push({ key: 'nw' + c.id, who: lang === 'ar' ? c.name : (c.nameEn || c.name), what: L(`طلب توظيف جديد — ${s.jobs.find(j => j.id === c.jobId)?.title.ar}`, `New application — ${s.jobs.find(j => j.id === c.jobId)?.title.en}`), tag: 'F-28', open: () => go('candidate', c.id), at: c.createdAt }))
  pendingLeaves.forEach(l => { const e = s.employees.find(x => x.id === l.empId)!; const mgr = s.employees.find(x => x.id === e.managerId); tasks.push({ key: 'lv' + l.id, who: lang === 'ar' ? e.name : e.nameEn, what: `${LEAVE_TYPES[l.type][lang]} · ${l.status === 'pending_hr' ? L('اعتماد الموارد البشرية', 'HR approval') : L('اعتماد المدير المباشر', 'manager approval')}`, open: () => go('leaves'), at: l.createdAt, leaveId: l.id, actor: l.status === 'pending_manager' ? mgr?.name : me?.name }) })
  ;(s.services ?? []).filter(x => x.status === 'pending').forEach(x => { const e = s.employees.find(y => y.id === x.empId)!; tasks.push({ key: 'sv' + x.id, who: lang === 'ar' ? e.name : e.nameEn, what: L(`طلب خدمة: ${SERVICE_LABEL[x.kind].ar}`, `Service: ${SERVICE_LABEL[x.kind].en}`), open: () => go('leaves'), at: x.at }) })
  s.resignations.filter(r => r.status !== 'closed' && r.status !== 'rejected').forEach(r => { const e = s.employees.find(x => x.id === r.empId)!; tasks.push({ key: 'rs' + r.id, who: lang === 'ar' ? e.name : e.nameEn, what: r.status === 'clearance' ? L('إبراء ذمة قيد الإنجاز', 'Clearance in progress') : L('طلب استقالة بانتظار الاعتماد', 'Resignation awaiting approval'), tag: 'F-26', open: () => go('offboarding'), at: r.createdAt }) })
  s.employees.filter(e => e.status === 'onboarding').forEach(e => tasks.push({ key: 'ob' + e.id, who: lang === 'ar' ? e.name : e.nameEn, what: L(`إجراءات المباشرة ${e.onboarding!.checklist.filter(Boolean).length}/28`, `Onboarding ${e.onboarding!.checklist.filter(Boolean).length}/28`), tag: 'F-19', open: () => go('onboard', e.id), at: e.hireDate }))
  tasks.sort((a, b) => b.at.localeCompare(a.at))

  const alerts: { tone: 'danger' | 'warn' | 'info'; text: string; go: () => void }[] = []
  const slaLate = s.candidates.filter(c => slaState(c) === 'late').length
  if (slaLate) alerts.push({ tone: 'danger', text: L(`${slaLate} متقدمين تجاوزوا مهلة مرحلتهم`, `${slaLate} applicants are past their stage limit`), go: () => go('recruitment') })
  if (late) alerts.push({ tone: 'warn', text: L(`${late} موظفين متأخرون اليوم`, `${late} employees late today`), go: () => go('attendance') })
  const unsigned = s.assets.filter(a => a.status === 'pending_ack').length
  if (unsigned) alerts.push({ tone: 'info', text: L(`${unsigned} عهد بانتظار توقيع الاستلام`, `${unsigned} assets awaiting signature`), go: () => go('custody') })
  const prob = s.employees.filter(e => e.status === 'probation').length
  if (prob) alerts.push({ tone: 'info', text: L(`${prob} موظفين في فترة التجربة`, `${prob} employees on probation`), go: () => go('employees') })

  const deptMix = s.depts.map(d => ({ d, n: active.filter(e => e.dept === d.id).length })).filter(x => x.n).sort((a, b) => b.n - a.n)
  const mixColors = ['bg-brand', 'bg-brass', 'bg-danger', 'bg-info']
  const funnel = STAGES.filter(x => x.id !== 'rejected').map(st => ({ st, n: s.candidates.filter(c => c.stage === st.id).length }))
  const maxF = Math.max(1, ...funnel.map(f => f.n))
  const origin = lang === 'ar' ? 'right' : 'left'

  const kpis = [
    { k: L('إجمالي الموظفين', 'Total employees'), v: active.length, sub: L(`${s.depts.length} إدارات · ${new Set(active.map(e => e.city.en)).size} مدن`, `${s.depts.length} departments · ${new Set(active.map(e => e.city.en)).size} cities`), icon: <Users size={20} />, spark: [28, 29, 29, 30, 31, 31, active.length], go: 'employees', warn: false },
    { k: L('حاضرون اليوم', 'Present today'), v: present, sub: L(`نسبة الحضور ${rates[6]}${P}`, `${rates[6]}% attendance`), icon: <UserCheck size={20} />, spark: rates, go: 'attendance', warn: false },
    { k: L('متأخرون اليوم', 'Late today'), v: late, sub: L('سماح 15 دقيقة', '15-min grace'), icon: <Clock size={20} />, spark: lateSeries, go: 'attendance', warn: true },
    { k: L('بنود بانتظارك', 'Waiting for you'), v: tasks.length, sub: L(`${pendingLeaves.length} طلبات تحتاج موافقتك`, `${pendingLeaves.length} requests need approval`), icon: <ClipboardList size={20} />, spark: [6, 8, 7, 9, 8, 10, tasks.length], go: 'leaves', warn: true },
  ]

  return <div className="max-w-[1400px] mx-auto space-y-5">
    <div className="flex flex-wrap items-end justify-between gap-3">
      <div><div className="text-[13px] font-semibold text-brand">{fmtDate(today, lang)}</div><h1 className="text-[26px] font-bold mt-0.5">{greet}، {(lang === 'ar' ? me?.name : me?.nameEn)?.split(' ')[0]}</h1></div>
      <Btn icon={<Maximize size={16} />} onClick={toggleFullscreen}>{L('وضع المعرض', 'Expo mode')}</Btn>
    </div>

    <div className="grid sm:grid-cols-2 xl:grid-cols-4 gap-4">
      {kpis.map((x, i) => <button key={i} onClick={() => go(x.go)} className="hoverlift text-start rounded-2xl border border-line bg-surface p-5 fadeup" style={{ animationDelay: `${i * 70}ms` }}>
        <div className="flex items-start justify-between"><span className="text-[13px] font-semibold text-ink-2">{x.k}</span>
          <span className={`size-10 rounded-xl grid place-items-center border ${x.warn ? 'bg-brass-soft text-brass border-brass/30' : 'bg-brand-soft text-brand border-brand/30'}`}>{x.icon}</span></div>
        <div className="mt-2 text-[34px] font-black font-display num leading-none"><CountUp to={x.v} /></div>
        <div className="mt-3 flex items-end justify-between gap-2"><span className={`text-[12.5px] font-medium ${x.warn ? 'text-brass' : 'text-brand'}`}>{x.sub}</span><Sparkline data={x.spark} color={x.warn ? 'var(--warn)' : 'var(--primary)'} /></div>
      </button>)}
    </div>

    <div className="grid xl:grid-cols-[1.7fr_1fr] gap-5">
      <Panel title={<span className="flex items-center gap-2"><Activity size={17} className="text-brand" />{L('اتجاه الحضور الأسبوعي', 'Weekly attendance')}</span>} sub={L('بطاقات NFC · كل المواقع', 'NFC · All locations')} action={<span className="text-[13px] font-bold text-brand num">{L(`متوسط ${avg}${P}`, `${avg}% average`)}</span>}>
        <Bars height={190} onPick={() => go('attendance')} data={wd.map((d, i) => ({ label: new Date(d + 'T12:00').toLocaleDateString(lang === 'ar' ? 'ar-SY' : 'en-GB', { weekday: 'short' }), value: rates[i], tip: `${fmtDate(d, lang)} — ${rates[i]}${P}`, tone: i === 6 ? 'muted' as const : rates[i] === minR ? 'low' as const : undefined }))} />
      </Panel>
      <Panel title={L('توزيع القوى العاملة', 'Workforce mix')}>
        <div className="space-y-3.5">{deptMix.slice(0, 6).map((x, i) => <button key={x.d.id} onClick={() => go('employees')} className="w-full text-start group">
          <div className="flex justify-between text-[13px]"><span className="group-hover:text-brand">{x.d[lang]}</span><span className="font-bold num">{Math.round(x.n / active.length * 100)}{P}</span></div>
          <div className="mt-1.5 h-2 rounded-full bg-sub overflow-hidden"><div className={`h-full rounded-full ${mixColors[i % 4]}`} style={{ width: `${x.n / deptMix[0].n * 100}%`, animation: `growx .8s ${i * 80}ms both`, transformOrigin: origin }} /></div></button>)}</div>
      </Panel>
    </div>

    <div className="grid xl:grid-cols-[1.7fr_1fr] gap-5">
      <Panel title={L('بانتظار موافقتك', 'Waiting for your approval')} sub={L(`${tasks.length} بنود`, `${tasks.length} items`)} pad={false}>
        <ul className="divide-y divide-line-2 max-h-[440px] overflow-y-auto">{tasks.map(t => <li key={t.key} className="flex items-center gap-3 px-5 py-3 hover:bg-sub/60">
          <Avatar name={t.who} size={36} tone={t.leaveId ? 'brand' : 'ink'} />
          <button onClick={t.open} className="flex-1 min-w-0 text-start"><div className="text-[14px] font-semibold truncate">{t.who}</div><div className="text-[12.5px] text-ink-3 truncate">{t.what}{t.tag && <span className="ms-2 ltr border border-line rounded px-1 text-[11px]">{t.tag}</span>}</div></button>
          {t.leaveId ? <div className="flex gap-1.5"><Btn size="sm" kind="primary" icon={<Check size={14} />} onClick={() => { decideLeave(t.leaveId!, 'approved', t.actor ?? 'HR'); toast(L('تمت الموافقة', 'Approved')) }}>{L('موافقة', 'Approve')}</Btn><Btn size="sm" icon={<X size={14} />} onClick={() => { decideLeave(t.leaveId!, 'rejected', t.actor ?? 'HR'); toast(L('رُفض الطلب', 'Rejected')) }}>{L('رفض', 'Reject')}</Btn></div>
            : <button onClick={t.open} className="text-ink-3 hover:text-brand flex items-center gap-1 text-[12px]"><span className="hidden md:inline num">{fmtDateTime(t.at, lang)}</span><Chev size={16} /></button>}
        </li>)}</ul>
      </Panel>
      <div className="space-y-5">
        <Panel title={L('تنبيهات', 'Alerts')}>
          <div className="space-y-2">{alerts.length === 0 ? <div className="text-ink-3 text-[13px]">{L('لا توجد تنبيهات الآن', 'No alerts right now')}</div> : alerts.map((a, i) => <button key={i} onClick={a.go} className="w-full flex items-center gap-3 rounded-xl bg-sub px-3 py-2.5 text-start text-[13px] hover:ring-1 hover:ring-brand/40">
            <span className={`size-2 rounded-full ${a.tone === 'danger' ? 'bg-danger' : a.tone === 'warn' ? 'bg-brass' : 'bg-info'}`} />{a.text}<AlertTriangle size={14} className="ms-auto text-ink-3" /></button>)}</div>
        </Panel>
        <Panel title={L('مسار التوظيف', 'Hiring pipeline')} action={<button onClick={() => go('recruitment')} className="text-[13px] font-semibold text-brand">{L('فتح اللوحة', 'Open board')}</button>}>
          <div className="space-y-2">{funnel.map((f, i) => <div key={f.st.id} className="grid grid-cols-[100px_1fr_24px] items-center gap-3 text-[13px]">
            <span className="text-ink-2">{f.st[lang]}</span><div className="h-5 bg-sub rounded-md overflow-hidden"><div className="h-full rounded-md bg-brand/80" style={{ width: `${f.n / maxF * 100}%`, animation: `growx .7s ${i * 70}ms both`, transformOrigin: origin }} /></div><span className="num text-end font-bold">{f.n}</span></div>)}</div>
        </Panel>
      </div>
    </div>

    <Panel title={<span className="flex items-center gap-2"><span className="size-2 rounded-full bg-brand live-dot" />{L('آخر حركات الحضور', 'Latest attendance scans')}</span>} pad={false}>
      <div className="grid md:grid-cols-2 xl:grid-cols-3">{todays.filter(d => d.inAt).sort((a, b) => (b.outAt ?? b.inAt!).localeCompare(a.outAt ?? a.inAt!)).slice(0, 9).map(d => { const e = s.employees.find(x => x.id === d.empId)!; const c = calcDay(today, d.inAt, d.outAt)
        return <button key={d.empId} onClick={() => go('employee', e.id)} className="flex items-center gap-3 px-5 py-3 border-b border-line-2 text-start hover:bg-sub/60"><Avatar name={e.name} size={30} />
          <span className="flex-1 min-w-0"><span className="block text-[13.5px] font-semibold truncate">{lang === 'ar' ? e.name : e.nameEn}</span><span className="block text-[12px] text-ink-3">{e.city[lang]}</span></span>
          <span className="num ltr text-[13px] text-ink-2">{(d.outAt ?? d.inAt)!.slice(11, 16)}</span>{d.outAt ? <Badge>{L('خروج', 'Out')}</Badge> : c.late ? <Badge tone="warn">{L('متأخر', 'Late')}</Badge> : <Badge tone="brand">{L('دخول', 'In')}</Badge>}</button> })}</div>
    </Panel>
  </div>
}
