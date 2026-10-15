import { ChevronLeft, ChevronRight, Users, UserCheck, Clock, ClipboardList, Activity, AlertTriangle, Loader2 } from 'lucide-react'
import { useL, useLang, bi, fmtDate, fmtDateTime } from '../../lib/store'
import { navigate } from '../../lib/router'
import type { SessionUser } from '../../lib/session'
import { useDashboard, type Task } from '../../lib/console'
import { STAGES } from '../../lib/recruitment'
import { Panel, Avatar, Badge } from '../../ui/kit'
import { CountUp, Sparkline, Bars } from '../../ui/viz'
import { hrLink } from '../../lib/hr'

/**
 * لوحة المتابعة (M15) — منقولة من Dashboard.tsx في الديمو بلا «وضع المعرض». البيانات من /hr/dashboard
 * وكل قسم يظهر حسب صلاحيات المستخدم. اعتماد الطلبات من هنا يأتي مع المسار الموحد في المهمة 07.
 */
export function Dashboard({ user }: { user: SessionUser }) {
  const L = useL(); const lang = useLang()
  const { data: d, isLoading } = useDashboard()
  const Chev = lang === 'ar' ? ChevronLeft : ChevronRight
  const P = lang === 'ar' ? '٪' : '%'
  const origin = lang === 'ar' ? 'right' : 'left'
  const hour = new Date().getHours()
  const greet = hour < 12 ? L('صباح الخير', 'Good morning') : L('مساء الخير', 'Good evening')
  const first = bi(d?.name ?? user.name, lang).split(' ')[0]
  const open = (link: string | null) => { if (link) navigate(hrLink(link)) }
  if (isLoading || !d) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>

  const a = d.attendance
  const series = a?.series ?? []
  const rates = series.map(x => x.rate)
  const past = a?.today_is_workday ? rates.slice(0, -1) : rates
  const minR = past.length ? Math.min(...past) : -1
  const avg = past.length ? Math.round(past.reduce((x, y) => x + y, 0) / past.length) : 0
  const leaves = d.tasks.filter(t => t.kind === 'leave').length

  const kpis = [
    d.people && { k: L('إجمالي الموظفين', 'Total employees'), v: d.people.total, sub: L(`${d.people.departments} إدارات · ${d.people.cities} مدن`, `${d.people.departments} departments · ${d.people.cities} cities`), icon: <Users size={20} />, spark: [d.people.total], to: '/hr/employees', warn: false },
    a && { k: L('حاضرون اليوم', 'Present today'), v: a.present, sub: L(`نسبة الحضور ${a.rate}${P}`, `${a.rate}% attendance`), icon: <UserCheck size={20} />, spark: rates, to: '/hr/attendance', warn: false },
    a && { k: L('متأخرون اليوم', 'Late today'), v: a.late, sub: L(`سماح ${a.grace} دقيقة`, `${a.grace}-min grace`), icon: <Clock size={20} />, spark: series.map(x => x.late), to: '/hr/attendance', warn: true },
    { k: L('بنود بانتظارك', 'Waiting for you'), v: d.tasks.length, sub: leaves ? L(`${leaves} طلبات تحتاج موافقتك`, `${leaves} requests need approval`) : L(`${d.tasks.filter(t => t.overdue).length} متأخرة عن 24 ساعة`, `${d.tasks.filter(t => t.overdue).length} older than 24h`), icon: <ClipboardList size={20} />, spark: [d.tasks.length], to: d.attendance ? '/hr/leaves' : '/hr/candidates', warn: true },
  ].filter(Boolean) as { k: string; v: number; sub: string; icon: React.ReactNode; spark: number[]; to: string; warn: boolean }[]
  const mix = d.people?.mix ?? []
  const mixColors = ['bg-brand', 'bg-brass', 'bg-danger', 'bg-info']
  const funnel = d.funnel ?? []
  const maxF = Math.max(1, ...funnel.map(f => f.count))
  const wide = d.attendance || d.people

  return <div className="max-w-[1400px] mx-auto space-y-5">
    <div><div className="text-[13px] font-semibold text-brand">{fmtDate(d.today, lang)}</div><h1 className="text-[26px] font-bold mt-0.5">{greet}، {first}</h1></div>

    <div className={`grid sm:grid-cols-2 gap-4 ${kpis.length >= 4 ? 'xl:grid-cols-4' : ''}`}>
      {kpis.map((x, i) => <button key={i} onClick={() => navigate(x.to)} className="hoverlift text-start rounded-2xl border border-line bg-surface p-5 fadeup" style={{ animationDelay: `${i * 70}ms` }}>
        <div className="flex items-start justify-between"><span className="text-[13px] font-semibold text-ink-2">{x.k}</span>
          <span className={`size-10 rounded-xl grid place-items-center border ${x.warn ? 'bg-brass-soft text-brass border-brass/30' : 'bg-brand-soft text-brand border-brand/30'}`}>{x.icon}</span></div>
        <div className="mt-2 text-[34px] font-black font-display num leading-none"><CountUp to={x.v} /></div>
        <div className="mt-3 flex items-end justify-between gap-2"><span className={`text-[12.5px] font-medium ${x.warn ? 'text-brass' : 'text-brand'}`}>{x.sub}</span>{x.spark.length > 1 && <Sparkline data={x.spark} color={x.warn ? 'var(--warn)' : 'var(--primary)'} />}</div>
      </button>)}
    </div>

    {wide && <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)] gap-5">
      {a && <Panel title={<span className="flex items-center gap-2"><Activity size={17} className="text-brand" />{L('اتجاه الحضور الأسبوعي', 'Weekly attendance')}</span>} sub={L('بطاقات NFC · كل المواقع', 'NFC · All locations')} action={<span className="text-[13px] font-bold text-brand num">{L(`متوسط ${avg}${P}`, `${avg}% average`)}</span>}>
        <Bars height={190} onPick={() => navigate('/hr/attendance')} data={series.map((x, i) => ({ label: new Date(x.date + 'T12:00').toLocaleDateString(lang === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB', { weekday: 'short' }), value: x.rate, tip: `${fmtDate(x.date, lang)} — ${x.rate}${P}`,
          tone: a.today_is_workday && i === series.length - 1 ? 'muted' as const : x.rate === minR ? 'low' as const : undefined }))} />
      </Panel>}
      {d.people && <Panel title={L('توزيع القوى العاملة', 'Workforce mix')}>
        <div className="space-y-3.5">{mix.map((x, i) => <button key={i} onClick={() => navigate('/hr/employees')} className="w-full text-start group">
          <div className="flex justify-between text-[13px]"><span className="group-hover:text-brand">{bi(x.dept, lang)}</span><span className="font-bold num">{Math.round(x.count / Math.max(1, d.people!.total) * 100)}{P}</span></div>
          <div className="mt-1.5 h-2 rounded-full bg-sub overflow-hidden"><div className={`h-full rounded-full ${mixColors[i % 4]}`} style={{ width: `${x.count / mix[0].count * 100}%`, animation: `growx .8s ${i * 80}ms both`, transformOrigin: origin }} /></div></button>)}</div>
      </Panel>}
    </div>}

    <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)] gap-5">
      <Panel title={L('بانتظار موافقتك', 'Waiting for your approval')} sub={L(`${d.tasks.length} بنود`, `${d.tasks.length} items`)} pad={false}>
        {d.tasks.length === 0 ? <div className="px-5 py-8 text-center text-[13px] text-ink-3">{L('لا شيء بانتظارك الآن', 'Nothing is waiting for you')}</div>
          : <ul className="divide-y divide-line-2 max-h-[440px] overflow-y-auto">{d.tasks.map(t => <TaskRow key={t.key} t={t} open={open} Chev={Chev} />)}</ul>}
      </Panel>
      <div className="space-y-5">
        <Panel title={L('تنبيهات', 'Alerts')}>
          <div className="space-y-2">{d.alerts.length === 0 ? <div className="text-ink-3 text-[13px]">{L('لا توجد تنبيهات الآن', 'No alerts right now')}</div> : d.alerts.map((x, i) => <button key={i} onClick={() => open(x.link)} className="w-full flex items-center gap-3 rounded-xl bg-sub px-3 py-2.5 text-start text-[13px] hover:ring-1 hover:ring-brand/40">
            <span className={`size-2 rounded-full shrink-0 ${x.tone === 'danger' ? 'bg-danger' : x.tone === 'warn' ? 'bg-brass' : 'bg-info'}`} />{bi(x.text, lang)}<AlertTriangle size={14} className="ms-auto text-ink-3 shrink-0" /></button>)}</div>
        </Panel>
        {d.funnel && <Panel title={L('مسار التوظيف', 'Hiring pipeline')} action={<a href="/hr/candidates" className="text-[13px] font-semibold text-brand">{L('فتح اللوحة', 'Open board')}</a>}>
          <div className="space-y-2">{funnel.map((f, i) => <div key={f.stage} className="grid grid-cols-[100px_1fr_24px] items-center gap-3 text-[13px]">
            <span className="text-ink-2 truncate">{bi(STAGES[f.stage], lang)}</span><div className="h-5 bg-sub rounded-md overflow-hidden"><div className="h-full rounded-md bg-brand/80" style={{ width: `${f.count / maxF * 100}%`, animation: `growx .7s ${i * 70}ms both`, transformOrigin: origin }} /></div><span className="num text-end font-bold">{f.count}</span></div>)}</div>
        </Panel>}
      </div>
    </div>

    {a && <Panel title={<span className="flex items-center gap-2"><span className="size-2 rounded-full bg-brand live-dot" />{L('آخر حركات الحضور', 'Latest attendance scans')}</span>} pad={false}>
      {a.latest.length === 0 ? <div className="px-5 py-6 text-[13px] text-ink-3">{L('لا حركات اليوم بعد', 'No scans yet today')}</div>
        : <div className="grid md:grid-cols-2 xl:grid-cols-3">{a.latest.map(x => <button key={x.employee_id} onClick={() => navigate(`/hr/employees/${x.employee_id}`)} className="flex items-center gap-3 px-5 py-3 border-b border-line-2 text-start hover:bg-sub/60"><Avatar size={30} />
          <span className="flex-1 min-w-0"><span className="block text-[13.5px] font-semibold truncate">{bi(x.name, lang)}</span><span className="block text-[12px] text-ink-3">{bi(x.city, lang)}</span></span>
          <span className="num ltr text-[13px] text-ink-2">{new Date(x.at).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}</span>{x.kind === 'out' ? <Badge>{L('خروج', 'Out')}</Badge> : x.kind === 'late' ? <Badge tone="warn">{L('متأخر', 'Late')}</Badge> : <Badge tone="brand">{L('دخول', 'In')}</Badge>}</button>)}</div>}
    </Panel>}
  </div>
}

function TaskRow({ t, open, Chev }: { t: Task; open: (l: string | null) => void; Chev: typeof ChevronLeft }) {
  const L = useL(); const lang = useLang()
  return <li className="flex items-center gap-3 px-5 py-3 hover:bg-sub/60">
    <Avatar size={36} tone={t.kind === 'leave' ? 'brand' : 'ink'} />
    <button onClick={() => open(t.link)} className="flex-1 min-w-0 text-start"><div className="text-[14px] font-semibold truncate">{bi(t.who, lang)}</div>
      <div className="text-[12.5px] text-ink-3 truncate">{bi(t.what, lang)}{t.tag && <span className="ms-2 ltr border border-line rounded px-1 text-[11px]">{t.tag}</span>}</div></button>
    {t.overdue && <Badge tone="danger">{L('متأخر', 'Overdue')}</Badge>}
    <button onClick={() => open(t.link)} className="text-ink-3 hover:text-brand flex items-center gap-1 text-[12px]"><span className="hidden md:inline num">{fmtDateTime(t.at, lang)}</span><Chev size={16} /></button>
  </li>
}
