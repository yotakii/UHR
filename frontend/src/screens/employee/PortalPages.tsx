import { useState, type ReactNode } from 'react'
import { Smartphone, Plane, Hourglass, FileText, Banknote, Link2, Wallet, FileSignature, ChevronLeft, ChevronRight, Check, MapPin } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../lib/store'
import { navigate } from '../../lib/router'
import { ApiError } from '../../lib/api'
import { lifecycleOf, minsLabel } from '../../lib/employees'
import { position, useCheckIn, useHome, useMyFile, useSavePhone, useTeam, type TeamRow } from '../../lib/portal'
import { Avatar, Badge, Btn, Empty, Field, Panel, toast } from '../../ui/kit'
import { Ring } from '../../ui/viz'
import { Lifecycle, Timeline } from '../../ui/docs'
import { LeaveStatus } from '../requests/parts'
import { MyAnnouncements } from '../rewards/Announcements'

const SERVICE: Record<string, [string, string]> = { salary_cert: ['شهادة راتب', 'Salary certificate'], experience_cert: ['شهادة عمل', 'Employment certificate'], advance: ['سلفة', 'Advance'], data_update: ['تحديث بيانات', 'Data update'] }

/** الرئيسية (PortalHome في الديمو): الواجهة البارزة + دوام اليوم، التواقيع، الأزرار السريعة، الأرصدة، الطلبات، الإعلانات، فريقي اليوم، رحلتي */
export function PortalHome({ toSign, todos }: { toSign: number; todos: ReactNode }) {
  const L = useL(); const lang = useLang()
  const q = useHome(); const d = q.data
  const check = useCheckIn()
  const [locating, setLocating] = useState(false)
  if (!d) return <div className="min-h-[50vh]" />
  const me = d.me, t = d.today
  const h = new Date().getHours()
  const Next = lang === 'ar' ? ChevronLeft : ChevronRight
  const doCheck = async () => {
    let pos: { lat: number; lng: number } | undefined
    if (d.geo) {
      setLocating(true)
      try { pos = await position() } catch { setLocating(false); toast(L('اسمح بتحديد الموقع لتسجيل الحضور ضمن نطاق المقر.', 'Allow location access to check in within the office radius.')); return }
      setLocating(false)
    }
    check.mutate(pos ?? {}, { onSuccess: r => toast(r.result.type === 'in' ? L(`سُجّل دخولك ${r.result.time}`, `Checked in at ${r.result.time}`) : L(`سُجّل خروجك ${r.result.time}`, `Checked out at ${r.result.time}`)),
      onError: e => toast(e instanceof ApiError ? e.message : L('تعذّر التسجيل', 'Could not record')) })
  }
  const quick: { icon: ReactNode; t: string; to: string }[] = [
    { icon: <Plane size={20} />, t: L('طلب إجازة', 'Request leave'), to: '/me/requests' },
    { icon: <Hourglass size={20} />, t: L('مغادرة ساعية', 'Hourly permission'), to: '/me/requests?type=hourly' },
    { icon: <FileText size={20} />, t: L('شهادة راتب', 'Salary certificate'), to: '/me/services?new=salary_cert' },
    { icon: <Banknote size={20} />, t: L('سلفة', 'Advance'), to: '/me/services?new=advance' },
    { icon: <Link2 size={20} />, t: L('رشّح صديقاً', 'Refer a friend'), to: '/me/rewards' },
    { icon: <Wallet size={20} />, t: L('كشف الراتب', 'Payslip'), to: '/me/payslip' },
  ]
  const years = (me.tenure_days / 365).toFixed(1)
  return <div className="space-y-5">
    <section className="show-root relative overflow-hidden rounded-3xl p-6 md:p-8"><div className="show-bg" aria-hidden><i /><i /></div>
      <div className="relative grid md:grid-cols-[1.3fr_1fr] gap-6 items-center">
        <div className="min-w-0"><div className="text-[13px] text-show-fg/60">{fmtDate(t.date, lang)}</div>
          <h1 className="text-[clamp(26px,3.2vw,38px)] font-bold mt-1">{h < 12 ? L('صباح الخير', 'Good morning') : L('مساء الخير', 'Good evening')}، {bi(me.name, lang).split(' ')[0]}</h1>
          <p className="text-show-fg/65 mt-1">{bi(me.title, lang)}{me.dept && <> · {bi(me.dept, lang)}</>} · {L('مديرك', 'Manager')}: {me.manager ? bi(me.manager, lang) : '—'}</p></div>
        <div className="rounded-2xl border border-show-fg/10 bg-show-fg/[.05] p-5">
          <div className="flex items-center justify-between gap-2"><span className="text-[13px] text-show-fg/60">{L('دوام اليوم', 'Today')}</span>
            {t.in ? (t.late ? <Badge tone="warn">{L('متأخر', 'Late')} {minsLabel(t.late, lang)}</Badge> : <Badge tone="brand">{L('في الموعد', 'On time')}</Badge>)
              : t.status === 'weekend' || t.status === 'holiday' ? <Badge>{L('عطلة', 'Day off')}</Badge> : t.status === 'leave' ? <Badge tone="info">{L('في إجازة', 'On leave')}</Badge> : <Badge>{L('لم تسجّل بعد', 'Not in yet')}</Badge>}</div>
          <div className="mt-2 flex items-baseline gap-5 font-display"><span><span className="text-[12px] text-show-fg/50 me-1">{L('دخول', 'In')}</span><span className="text-[26px] font-bold ltr num">{t.in ?? '--:--'}</span></span>
            <span><span className="text-[12px] text-show-fg/50 me-1">{L('خروج', 'Out')}</span><span className="text-[26px] font-bold ltr num">{t.out ?? '--:--'}</span></span></div>
          {t.checkin && <button disabled={check.isPending || locating} onClick={doCheck} className="mt-4 w-full h-11 rounded-xl bg-brand text-on-brand font-bold flex items-center justify-center gap-2 hover:brightness-110 disabled:opacity-60">
            <Smartphone size={17} />{locating ? L('جارٍ تحديد موقعك…', 'Locating…') : t.in ? L('تسجيل الخروج', 'Check out') : L('تسجيل الحضور', 'Check in')}</button>}
          {t.checkin && d.geo && <div className="mt-2 text-[11.5px] text-show-fg/55 flex items-center gap-1"><MapPin size={12} />{L(`ضمن ${d.geo.radius} متر من المقر`, `Within ${d.geo.radius} m of the office`)}</div>}
        </div>
      </div>
    </section>

    {toSign > 0 && <a href="/me/documents" className="w-full flex items-center gap-3 rounded-2xl border border-brass/40 bg-brass-soft px-5 py-3 text-start fadeup"><FileSignature size={18} className="text-brass" />
      <span className="flex-1 font-semibold">{L(`لديك ${toSign} وثائق بانتظار توقيعك`, `${toSign} documents are waiting for your signature`)}</span><Next size={18} /></a>}

    <div className="grid grid-cols-3 sm:grid-cols-6 gap-3">{quick.map((x, i) => <a key={i} href={x.to} className="hoverlift rounded-2xl border border-line bg-surface p-4 flex flex-col items-center gap-2 text-center fadeup min-w-0" style={{ animationDelay: `${i * 50}ms` }}>
      <span className="size-11 rounded-xl grid place-items-center bg-brand-soft text-brand">{x.icon}</span><span className="text-[12.5px] font-semibold leading-tight">{x.t}</span></a>)}</div>

    {todos}

    <div className="grid lg:grid-cols-3 gap-5">
      <Panel title={L('أرصدتي', 'My balances')}><div className="space-y-5">
        <Ring v={Math.max(0, d.balance.annual.left)} max={d.balance.annual.of} label={L('إجازة سنوية متبقية', 'Annual leave left')} sub={L(`استخدمت ${d.balance.annual.used} يوماً`, `${d.balance.annual.used} days used`)} />
        {d.balance.sick.of ? <Ring v={Math.max(0, d.balance.sick.of - d.balance.sick.used)} max={d.balance.sick.of} label={L('إجازة مرضية متبقية', 'Sick leave left')} sub={L('حسب السياسة', 'Per policy')} tone="var(--user)" />
          : <div className="flex items-center gap-3"><span className="size-16 rounded-full border-[5px] border-[var(--user)]/40 grid place-items-center text-[18px] font-bold num">{d.balance.sick.used}</span>
            <div><div className="text-[13px] font-semibold">{L('أيام مرضية مستخدمة', 'Sick days used')}</div><div className="text-[11.5px] text-ink-3">{L('بلا سقف محدد في السياسة', 'No cap set in the policy')}</div></div></div>}
        <Ring v={d.balance.points} max={Math.max(2000, d.balance.points)} label={L('نقاط المكافآت', 'Reward points')} sub={L('استبدلها من المتجر', 'Redeem in the store')} tone="var(--warn)" /></div></Panel>
      <Panel title={L('طلباتي الأخيرة', 'Recent requests')} action={<a href="/me/requests" className="text-[12.5px] text-brand font-semibold">{L('الكل', 'All')}</a>} pad={false}>
        <ul className="divide-y divide-line-2">{d.recent.map(r => <li key={r.kind + r.id} className="flex items-center justify-between gap-3 px-5 py-3"><span className="min-w-0"><span className="block text-[13.5px] font-semibold truncate">{r.title ? bi(r.title, lang) : L(...(SERVICE[r.type] ?? [r.type, r.type]))}</span>
          <span className="text-[12px] text-ink-3 num">{r.date ? fmtDate(r.date, lang) : ''}</span></span><LeaveStatus s={r.status} /></li>)}
          {d.recent.length === 0 && <li className="px-5 py-8 text-center text-[13px] text-ink-3">{L('لا توجد طلبات بعد', 'No requests yet')}</li>}</ul></Panel>
      <div className="space-y-5 min-w-0">
        <MyAnnouncements panel limit={3} />
        <Panel title={L('فريقي اليوم', 'My team today')} action={<a href="/me/team" className="text-[12.5px] text-brand font-semibold">{L('الكل', 'All')}</a>}>
          <div className="flex flex-wrap gap-1.5">{d.team.rows.map(e => <span key={e.id} className="inline-flex items-center gap-1.5 rounded-full border border-line bg-sub/60 px-2.5 py-1 text-[12.5px] max-w-full"><span className={`size-2 rounded-full shrink-0 ${e.state === 'in' ? 'bg-brand' : e.state === 'leave' ? 'bg-brass' : 'bg-ink-3'}`} /><span className="truncate">{bi(e.name, lang)}</span></span>)}</div>
          <div className="text-[12.5px] text-ink-3 mt-3">{d.team.away ? L(`${d.team.away} في إجازة اليوم`, `${d.team.away} on leave today`) : L('الفريق كامل اليوم', 'Everyone is in today')}</div></Panel>
      </div>
    </div>

    <Panel title={L('رحلتي في الشركة', 'My journey')} sub={me.status === 'probation' && me.probation_end ? L(`فترة التجربة تنتهي ${fmtDate(me.probation_end, 'ar')}`, `Probation ends ${fmtDate(me.probation_end, 'en')}`) : L(`${years} سنة خدمة`, `${years} years of service`)}>
      <Lifecycle steps={lifecycleOf({ status: me.status as never, onboarding: me.onboarding, resigned: me.resigned }, L)} /></Panel>
  </div>
}

/** «فريقي» (Team في الديمو): زملاء الإدارة بحالتهم اليوم */
export function PortalTeam() {
  const L = useL(); const lang = useLang()
  const q = useTeam(); const d = q.data
  const state = (e: TeamRow) => e.state === 'in' ? <span className="text-brand">{L('موجود منذ', 'In since')} <span className="ltr num">{e.since}</span></span>
    : e.state === 'leave' ? <span className="text-brass">{L('في إجازة', 'On leave')}</span> : e.state === 'out' ? <span className="text-ink-3">{L('غادر', 'Left')}</span> : <span className="text-ink-3">{L('لم يصل بعد', 'Not in yet')}</span>
  return <div className="max-w-[1000px] mx-auto"><h1 className="text-[22px] font-bold mb-1">{L('فريقي', 'My team')}</h1><p className="text-ink-3 mb-4">{d?.dept ? bi(d.dept, lang) : ''}</p>
    {!d ? null : d.rows.length === 0 ? <div className="bg-surface border border-line rounded-2xl"><Empty title={L('لا زملاء في إدارتك', 'No colleagues in your department')} /></div>
      : <div className="grid [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))] gap-3">{d.rows.map(e => <div key={e.id} className={`rounded-2xl border bg-surface p-4 flex items-center gap-3 min-w-0 ${e.me ? 'border-brand/50' : 'border-line'}`}>
        <div className="relative shrink-0"><Avatar name={e.name.ar} size={44} /><span className={`absolute -bottom-0.5 -end-0.5 size-3.5 rounded-full ring-2 ring-surface ${e.state === 'in' ? 'bg-brand' : e.state === 'leave' ? 'bg-brass' : 'bg-ink-3'}`} /></div>
        <div className="flex-1 min-w-0"><div className="font-semibold truncate">{bi(e.name, lang)}{e.me && <span className="text-ink-3 text-[12px]"> ({L('أنت', 'you')})</span>}</div><div className="text-[12px] text-ink-3 truncate">{bi(e.title, lang)}</div>
          <div className="text-[11.5px] mt-0.5">{state(e)}</div></div>
        {e.phone && <span className="text-[11.5px] text-ink-3 ltr num shrink-0">…{e.phone}</span>}</div>)}</div>}
  </div>
}

/** «ملفي الشخصي» (Profile في الديمو): الجوال، البيانات الثابتة، السجل الوظيفي */
export function PortalProfile() {
  const L = useL(); const lang = useLang()
  const q = useMyFile(); const d = q.data
  const save = useSavePhone()
  const [phone, setPhone] = useState<string | null>(null)
  if (!d) return <div className="min-h-[40vh]" />
  const me = d.me
  const val = phone ?? me.phone ?? ''
  const e = save.error instanceof ApiError ? save.error : null
  return <div className="max-w-[1000px] mx-auto grid lg:grid-cols-[1fr_1.2fr] gap-5">
    <Panel title={L('بياناتي', 'My details')}><div className="flex items-center gap-3 mb-4 min-w-0"><Avatar name={me.name.ar} size={56} /><div className="min-w-0"><div className="font-display font-bold text-[18px] truncate">{bi(me.name, lang)}</div><div className="text-[12.5px] text-ink-3 truncate">{me.code} · <span className="ltr inline-block">{me.email}</span></div></div></div>
      <Field label={L('رقم الجوال', 'Mobile')} error={e?.field('phone')}><div className="flex gap-2"><input className="field ltr text-start flex-1 min-w-0" value={val} onChange={x => setPhone(x.target.value)} />
        <Btn icon={<Check size={14} />} disabled={save.isPending || val === (me.phone ?? '')} onClick={() => save.mutate(val, { onSuccess: () => { setPhone(null); toast(L('حُفظ الرقم', 'Saved')) } })}>{L('حفظ', 'Save')}</Btn></div></Field>
      <div className="mt-4 text-[13px] space-y-2">{([[L('الرقم الوطني', 'National ID'), me.national_id ?? '—'], [L('تاريخ المباشرة', 'Start date'), me.hire_date ? fmtDate(me.hire_date, lang) : '—'],
        [L('مقر العمل', 'Location'), me.location ? bi(me.location, lang) : '—'], [L('المدير المباشر', 'Manager'), me.manager ? bi(me.manager, lang) : '—'], [L('بطاقة الحضور', 'Attendance card'), me.card ?? '—']] as [string, string][]).map(([k, v]) =>
        <div key={k} className="flex justify-between gap-3 border-b border-line-2 pb-1.5"><span className="text-ink-3">{k}</span><b className="ltr text-end min-w-0 truncate">{v}</b></div>)}</div>
      <p className="mt-4 text-[12px] text-ink-3">{L('لتعديل بيانات أخرى قدّم طلب «تحديث بيانات» من خدماتي.', 'To change other data, request a «data update» from My services.')} <button className="text-brand font-semibold" onClick={() => navigate('/me/services?new=data_update')}>{L('طلب تحديث', 'Request update')}</button></p></Panel>
    <Panel title={L('سجلي الوظيفي', 'My career history')}><div className="max-h-[480px] overflow-y-auto">{d.log.length ? <Timeline items={d.log} compact /> : <Empty title={L('لا أحداث بعد', 'No events yet')} />}</div></Panel>
  </div>
}
