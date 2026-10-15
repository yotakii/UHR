import { useState } from 'react'
import { Trophy, Link2, Copy, Gift, Coffee, Dumbbell, GraduationCap, Car, Headphones, CalendarPlus, Sparkles, Check, X } from 'lucide-react'
import { useStore, useL, useLang, bi, fmtDate, fmtDateTime, todayISO } from '../lib/store'
import { shareReferral, redeem, decideRedemption, setRules, applyInternal, addEmployee } from '../lib/actions'
import { useNav } from '../lib/nav'
import { Btn, Panel, Badge, Avatar, PageHead, Tabs, Empty, Modal, Field, Progress, toast } from '../ui/kit'
import { CountUp } from '../ui/viz'
import { F25 } from '../lib/rules'
import type { Rules } from '../lib/types'

const rewardIcon: Record<string, React.ReactNode> = { leave: <CalendarPlus size={22} />, food: <Coffee size={22} />, gym: <Dumbbell size={22} />, course: <GraduationCap size={22} />, parking: <Car size={22} />, gift: <Headphones size={22} /> }
const medal = ['text-[#e7c27a]', 'text-[#c9d1d9]', 'text-[#d39a6a]']

function Leaderboard({ limit = 6 }: { limit?: number }) {
  const L = useL(); const lang = useLang()
  const emps = useStore(s => s.employees.filter(e => (e.lifetime ?? 0) > 0).sort((a, b) => (b.lifetime ?? 0) - (a.lifetime ?? 0)).slice(0, limit))
  const refs = useStore(s => s.referrals)
  const top = emps[0]?.lifetime ?? 1
  return <ol className="space-y-2.5">{emps.map((e, i) => <li key={e.id} className="flex items-center gap-3 rounded-xl border border-line-2 bg-sub/50 px-3 py-2.5 fadeup" style={{ animationDelay: `${i * 60}ms` }}>
    <span className={`w-6 text-center font-black font-display ${medal[i] ?? 'text-ink-3'}`}>{i < 3 ? <Trophy size={18} className="inline" /> : i + 1}</span>
    <Avatar name={e.name} size={34} />
    <div className="flex-1 min-w-0"><div className="font-semibold truncate">{lang === 'ar' ? e.name : e.nameEn}</div>
      <div className="mt-1"><Progress value={(e.lifetime ?? 0) / top * 100} tone={i === 0 ? 'brass' : 'brand'} /></div></div>
    <div className="text-end"><div className="font-black font-display num">{(e.lifetime ?? 0).toLocaleString('en-US')}</div><div className="text-[11px] text-ink-3">{Math.max(refs.filter(r => r.empId === e.id && r.status === 'hired').length, Math.floor((e.lifetime ?? 0) / 500))} {L('تعيينات', 'hires')}</div></div>
  </li>)}</ol>
}

/* ---------------- HR ---------------- */
export function ReferralsHR() {
  const L = useL(); const lang = useLang(); const { go } = useNav()
  const s = useStore(x => x)
  const me = s.employees.find(e => e.id === s.session?.id)
  const [tab, setTab] = useState('board')
  const pend = s.redemptions.filter(r => r.status === 'pending')
  return <div className="max-w-[1200px] mx-auto">
    <PageHead title={L('الإحالات والمكافآت', 'Referrals & rewards')} sub={L(`كل تعيين عبر رابط إحالة يمنح الموظف ${s.rules.referralPoints} نقطة يستبدلها من متجر المكافآت.`, `Every hire through a referral link earns the employee ${s.rules.referralPoints} points to spend in the rewards store.`)} />
    <Tabs value={tab} onChange={setTab} tabs={[{ v: 'board', label: L('لوحة المتصدرين', 'Leaderboard') }, { v: 'links', label: L('روابط الإحالة', 'Referral links'), count: s.referrals.length }, { v: 'red', label: L('طلبات الاستبدال', 'Redemptions'), count: pend.length }, { v: 'store', label: L('المتجر', 'Store') }]} />
    <div className="mt-4">
      {tab === 'board' && <div className="grid lg:grid-cols-[1.3fr_1fr] gap-5"><Panel title={L('أكثر الموظفين استقطاباً للمواهب', 'Top talent advocates')}><Leaderboard limit={8} /></Panel>
        <Panel title={L('من الرابط إلى التعيين', 'From link to hire')}><div className="space-y-4">{[[L('روابط مشاركة', 'Links shared'), s.referrals.length], [L('تقدّموا عبر رابط', 'Applied via link'), s.referrals.filter(r => r.status !== 'shared').length], [L('عُيّنوا', 'Hired'), s.referrals.filter(r => r.status === 'hired').length]].map(([k, v], i, arr) =>
          <div key={String(k)}><div className="flex justify-between text-[13px] mb-1"><span>{k}</span><span className="font-bold num">{v}</span></div><Progress value={Number(v) / Number(arr[0][1] || 1) * 100} tone={i === 2 ? 'brass' : 'brand'} /></div>)}</div></Panel></div>}
      {tab === 'links' && <Panel pad={false}><div className="scroll-x"><table className="tbl"><thead><tr><th>{L('الموظف', 'Employee')}</th><th>{L('الوظيفة', 'Role')}</th><th>{L('الرمز', 'Code')}</th><th>{L('زيارات', 'Visits')}</th><th>{L('المرشح', 'Candidate')}</th><th>{L('الحالة', 'Status')}</th></tr></thead><tbody>
        {s.referrals.map(r => { const e = s.employees.find(x => x.id === r.empId)!; const c = s.candidates.find(x => x.id === r.candidateId); return <tr key={r.id}><td className="font-medium">{lang === 'ar' ? e.name : e.nameEn}</td><td>{bi(s.jobs.find(j => j.id === r.jobId)!.title, lang)}</td><td className="ltr num text-[13px]">{r.code}</td><td className="num">{r.clicks}</td>
          <td>{c ? <button className="text-brand hover:underline" onClick={() => go('candidate', c.id)}>{lang === 'ar' ? c.name : (c.nameEn || c.name)}</button> : '—'}</td><td>{r.status === 'hired' ? <Badge tone="brass">+{r.awarded} {L('نقطة', 'pts')}</Badge> : r.status === 'applied' ? <Badge tone="brand">{L('تقدّم', 'Applied')}</Badge> : <Badge>{L('مُشارك', 'Shared')}</Badge>}</td></tr> })}</tbody></table></div></Panel>}
      {tab === 'red' && <Panel pad={false}>{s.redemptions.length === 0 ? <Empty title={L('لا توجد طلبات استبدال', 'No redemptions')} /> : <table className="tbl"><thead><tr><th>{L('الموظف', 'Employee')}</th><th>{L('المكافأة', 'Reward')}</th><th>{L('النقاط', 'Points')}</th><th>{L('التاريخ', 'Date')}</th><th>{L('الحالة', 'Status')}</th><th /></tr></thead><tbody>
        {s.redemptions.map(r => { const e = s.employees.find(x => x.id === r.empId)!; const w = s.rewards.find(x => x.id === r.rewardId)!; return <tr key={r.id}><td className="font-medium">{lang === 'ar' ? e.name : e.nameEn}</td><td>{w.title[lang]}</td><td className="num">{r.cost}</td><td className="num">{fmtDateTime(r.at, lang)}</td>
          <td>{r.status === 'pending' ? <Badge tone="warn">{L('نقاط محجوزة', 'Points held')}</Badge> : r.status === 'approved' ? <Badge tone="brand">{L('معتمد', 'Approved')}</Badge> : <Badge tone="danger">{L('مرفوض — أُعيدت النقاط', 'Declined — refunded')}</Badge>}</td>
          <td>{r.status === 'pending' && <div className="flex gap-1.5 justify-end"><Btn size="sm" kind="primary" icon={<Check size={14} />} onClick={() => { decideRedemption(r.id, true, me?.name ?? 'HR'); toast(L('اعتُمدت المكافأة', 'Reward approved')) }}>{L('اعتماد', 'Approve')}</Btn><Btn size="sm" icon={<X size={14} />} onClick={() => decideRedemption(r.id, false, me?.name ?? 'HR')}>{L('رفض', 'Decline')}</Btn></div>}</td></tr> })}</tbody></table>}</Panel>}
      {tab === 'store' && <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">{s.rewards.map(w => <div key={w.id} className="rounded-2xl border border-line bg-surface p-5"><div className="size-12 rounded-xl grid place-items-center bg-brand-soft text-brand border border-brand/25">{rewardIcon[w.kind]}</div>
        <div className="mt-3 font-display font-bold">{w.title[lang]}</div><div className="mt-1 flex justify-between text-[13px]"><span className="text-brass font-bold num">{w.cost} {L('نقطة', 'pts')}</span><span className="text-ink-3">{L('المخزون', 'Stock')}: <span className="num">{w.stock}</span></span></div></div>)}</div>}
    </div>
  </div>
}

/* ---------------- employee ---------------- */
export function MyRewards() {
  const L = useL(); const lang = useLang()
  const id = useStore(s => s.session!.id)
  const s = useStore(x => x); const e = s.employees.find(x => x.id === id)!
  const [codes, setCodes] = useState<Record<string, string>>({})
  const [internal, setInternal] = useState<string | null>(null); const [reason, setReason] = useState('')
  const mine = s.referrals.filter(r => r.empId === id)
  const reds = s.redemptions.filter(r => r.empId === id)
  const already = (jobId: string) => s.candidates.some(c => c.internal?.empId === id && c.jobId === jobId)
  return <div className="max-w-[1200px] mx-auto space-y-5">
    <section className="show-root relative overflow-hidden rounded-3xl p-7 md:p-9"><div className="show-bg" aria-hidden><i /><i /></div>
      <div className="relative flex flex-wrap items-center gap-6 justify-between">
        <div><div className="text-[13px] text-show-fg/60">{L('رصيد نقاطك', 'Your points')}</div><div className="text-[52px] font-black font-display text-brand num leading-none mt-1"><CountUp to={e.points ?? 0} /></div>
          <div className="text-[13px] text-show-fg/60 mt-2">{L(`رشّح شخصاً مناسباً، واحصل على ${s.rules.referralPoints} نقطة عند تعيينه.`, `Refer the right person and earn ${s.rules.referralPoints} points when they are hired.`)}</div></div>
        <Sparkles size={64} className="text-brand/60" />
      </div>
    </section>
    <div className="grid lg:grid-cols-[1.4fr_1fr] gap-5">
      <Panel title={L('شارك وظيفة شاغرة', 'Share an opening')} pad={false}><ul className="divide-y divide-line-2">{s.jobs.filter(j => j.status === 'open').map(j => { const ex = mine.find(r => r.jobId === j.id); const code = codes[j.id] ?? ex?.code
        return <li key={j.id} className="px-5 py-3 flex flex-wrap items-center gap-3"><div className="flex-1 min-w-[180px]"><div className="font-semibold">{bi(j.title, lang)}</div><div className="text-[12px] text-ink-3">{bi(j.city, lang)} · <span className="ltr">{j.code}</span></div></div>
          {code ? <button onClick={() => { navigator.clipboard?.writeText(`https://careers.alfarada.sy/apply/${j.id}?ref=${code}`); toast(L('نُسخ رابط الإحالة', 'Referral link copied')) }} className="flex items-center gap-2 rounded-lg border border-brand/40 bg-brand-soft px-3 h-9 text-[13px] font-semibold text-brand"><Copy size={14} /><span className="ltr num">{code}</span></button>
            : <Btn size="sm" icon={<Link2 size={14} />} onClick={() => setCodes({ ...codes, [j.id]: shareReferral(id, j.id) })}>{L('إنشاء رابط إحالة', 'Create referral link')}</Btn>}
          {!already(j.id) && j.dept !== e.dept && <Btn size="sm" kind="ghost" onClick={() => setInternal(j.id)}>{L('تقدّم داخلياً', 'Apply internally')}</Btn>}
          {already(j.id) && <Badge tone="info">{L('طلب داخلي مُقدّم', 'Internal application sent')}</Badge>}
        </li> })}</ul></Panel>
      <Panel title={L('لوحة المتصدرين', 'Leaderboard')}><Leaderboard /></Panel>
    </div>
    <Panel title={<span className="flex items-center gap-2"><Gift size={17} className="text-brand" />{L('متجر المكافآت', 'Rewards store')}</span>}>
      <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">{s.rewards.map(w => { const can = (e.points ?? 0) >= w.cost && w.stock > 0
        return <div key={w.id} className={`rounded-2xl border p-4 flex items-center gap-3 ${can ? 'border-line hoverlift' : 'border-line-2 opacity-60'}`}><div className="size-11 rounded-xl grid place-items-center bg-brand-soft text-brand">{rewardIcon[w.kind]}</div>
          <div className="flex-1 min-w-0"><div className="font-semibold text-[14px] truncate">{w.title[lang]}</div><div className="text-[12px] text-brass font-bold num">{w.cost} {L('نقطة', 'pts')}</div></div>
          <Btn size="sm" kind={can ? 'primary' : 'secondary'} disabled={!can} onClick={() => { redeem(id, w.id); toast(L('أُرسل الطلب وحُجزت النقاط بانتظار الموارد البشرية', 'Requested — points held pending HR')) }}>{L('استبدال', 'Redeem')}</Btn></div> })}</div>
      {reds.length > 0 && <div className="mt-4 space-y-1.5">{reds.map(r => <div key={r.id} className="flex justify-between text-[13px] border-t border-line-2 pt-1.5"><span>{s.rewards.find(w => w.id === r.rewardId)!.title[lang]} · <span className="text-ink-3">{fmtDate(r.at, lang)}</span></span>{r.status === 'pending' ? <Badge tone="warn">{L('بانتظار الموارد البشرية', 'Awaiting HR')}</Badge> : r.status === 'approved' ? <Badge tone="brand">{L('معتمد', 'Approved')}</Badge> : <Badge tone="danger">{L('مرفوض', 'Declined')}</Badge>}</div>)}</div>}
    </Panel>
    <Modal open={!!internal} onClose={() => setInternal(null)} title={L('طلب توظيف / ترقية داخلي', 'Internal application')}
      footer={<><Btn onClick={() => setInternal(null)}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" onClick={() => { applyInternal(id, internal!, reason || L('الرغبة في التطور المهني', 'Career growth')); setInternal(null); setReason(''); toast(L('أُرسل الطلب الداخلي للموارد البشرية', 'Internal application sent')) }}>{L('إرسال الطلب', 'Submit')}</Btn></>}>
      <div className="text-[12px] text-ink-3 mb-3 ltr text-start">Uniqueness-HR-01-F-27</div>
      <p className="text-[14px] mb-3">{internal && bi(s.jobs.find(j => j.id === internal)!.title, lang)}</p>
      <Field label={L('أسباب ودوافع التقدم لهذه الوظيفة', 'Why this role?')}><textarea className="field" value={reason} onChange={x => setReason(x.target.value)} /></Field>
    </Modal>
  </div>
}

/* ---------------- automation rules ---------------- */
export function RulesModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const L = useL(); const rules = useStore(s => s.rules)
  const [r, setR] = useState<Rules>({ ...rules, relaxed: rules.relaxed !== false })
  const tog = (k: 'autoScreen' | 'slaAlerts' | 'whatsappNudge' | 'relaxed', label: string, hint: string) => <button type="button" onClick={() => setR({ ...r, [k]: !r[k] })} className="w-full flex items-center justify-between gap-4 py-3 border-b border-line-2 text-start">
    <span><span className="block text-[14px] font-semibold">{label}</span><span className="block text-[12px] text-ink-3">{hint}</span></span>
    <span className={`w-11 h-6 rounded-full p-0.5 transition-colors ${r[k] ? 'bg-brand' : 'bg-sub border border-line'}`}><span className={`block size-5 rounded-full bg-white transition-transform ${r[k] ? 'translate-x-5 rtl:-translate-x-5' : ''}`} /></span></button>
  return <Modal open={open} onClose={onClose} title={L('قواعد الأتمتة', 'Automation rules')} width={560} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" onClick={() => { setRules(r); onClose(); toast(L('حُفظت القواعد', 'Rules saved')) }}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="rounded-xl bg-sub p-4"><div className="flex justify-between"><span className="font-semibold text-[14px]">{L('حد المطابقة للانتقال التلقائي للفرز', 'Match gate for auto-screening')}</span><span className="font-black font-display text-brand num text-[20px]">{r.matchThreshold}%</span></div>
      <input type="range" min={50} max={95} step={5} value={r.matchThreshold} onChange={e => setR({ ...r, matchThreshold: Number(e.target.value) })} className="w-full mt-3 accent-[var(--primary)]" />
      <div className="text-[12px] text-ink-3 mt-1">{L('الطلبات تحت هذا الحد تبقى في «طلبات جديدة» لمراجعة يدوية، ولا تُرفض تلقائياً.', 'Applications below stay in “New” for manual review — never auto-rejected.')}</div></div>
    {tog('relaxed', L('وضع العرض: بدون حقول إلزامية', 'Demo mode: no required fields'), L('التنقل بين الشاشات بدون تعبئة؛ تُكمَل القيم الناقصة تلقائياً', 'Move through screens without filling; missing values are completed automatically'))}
    {tog('autoScreen', L('فرز تلقائي للطلبات الجديدة', 'Auto-screen new applications'), L('تطبيق حد المطابقة لحظة استلام الطلب', 'Apply the match gate on arrival'))}
    {tog('slaAlerts', L('تنبيه تجاوز مهلة المرحلة', 'Stage SLA alerts'), L('إشعار عند بقاء المتقدم في مرحلة أكثر من المهلة', 'Notify when an applicant overstays a stage'))}
    {tog('whatsappNudge', L('رسائل واتساب تلقائية', 'Automatic WhatsApp messages'), L('دعوة المقابلة وتذكير المرشح عند الانتقال', 'Interview invites and reminders on stage change'))}
    <div className="flex items-center justify-between py-3"><span className="text-[14px] font-semibold">{L('نقاط الإحالة عند التعيين', 'Referral points per hire')}</span><input type="number" step={50} className="field !w-28 num" value={r.referralPoints} onChange={e => setR({ ...r, referralPoints: Number(e.target.value) })} /></div>
  </Modal>
}

/* ---------------- radar ---------------- */
export function Radar({ axes, size = 260 }: { axes: { label: string; value: number }[]; size?: number }) {
  const c = size / 2, R = size / 2 - 42, n = axes.length
  const pt = (i: number, v: number) => { const a = -Math.PI / 2 + i * 2 * Math.PI / n; return [c + Math.cos(a) * R * v, c + Math.sin(a) * R * v] }
  const poly = axes.map((x, i) => pt(i, x.value).join(',')).join(' ')
  return <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} style={{ direction: 'ltr' }}>
    {[.25, .5, .75, 1].map(k => <polygon key={k} points={axes.map((_, i) => pt(i, k).join(',')).join(' ')} fill="none" stroke="var(--line)" />)}
    {axes.map((_, i) => <line key={i} x1={c} y1={c} x2={pt(i, 1)[0]} y2={pt(i, 1)[1]} stroke="var(--line)" />)}
    <polygon points={poly} fill="color-mix(in oklab, var(--primary) 28%, transparent)" stroke="var(--primary)" strokeWidth="2" className="fadeup" />
    {axes.map((x, i) => { const [px, py] = pt(i, 1.2); return <text key={i} x={px} y={py} textAnchor="middle" dominantBaseline="middle" fontSize="11" fill="var(--fg2)" fontWeight="600">{x.label}</text> })}
    {axes.map((x, i) => { const [px, py] = pt(i, x.value); return <circle key={'d' + i} cx={px} cy={py} r="3.5" fill="var(--primary)" /> })}
  </svg>
}
export const f25Axes = (r: Record<string, number>, lang: 'ar' | 'en') => F25.map(g => ({ label: g[lang].split(' ')[0] + (g[lang].split(' ')[1] ? ' ' + g[lang].split(' ')[1] : ''), value: g.items.reduce((a, i) => a + (r[i.id] ?? 0), 0) / 30 }))

/* ---------------- add employee wizard ---------------- */
export function AddEmployee({ open, onClose }: { open: boolean; onClose: () => void }) {
  const L = useL(); const lang = useLang(); const { go } = useNav()
  const depts = useStore(s => s.depts); const emps = useStore(s => s.employees); const free = useStore(s => s.assets.filter(a => a.status === 'available'))
  const [step, setStep] = useState(0)
  const [d, setD] = useState({ name: '', nameEn: '', gender: 'M' as 'M' | 'F', nationalId: '', dob: '1994-01-01', phone: '+963 9', email: '', dept: 'it', titleAr: '', titleEn: '', city: 'dam', managerId: '', salary: 800, hireDate: todayISO() })
  const [assets, setAssets] = useState<string[]>([])
  const cities: Record<string, { ar: string; en: string }> = { dam: { ar: 'دمشق', en: 'Damascus' }, alp: { ar: 'حلب', en: 'Aleppo' }, hom: { ar: 'حمص', en: 'Homs' }, lat: { ar: 'اللاذقية', en: 'Latakia' } }
  const steps = [L('البيانات الشخصية', 'Personal'), L('الوظيفة والراتب', 'Job & pay'), L('العهد', 'Custody'), L('المراجعة', 'Review')]
  const ok0 = true, ok1 = true
  const set = (k: string, v: string | number) => setD({ ...d, [k]: v })
  const finish = () => { const n = Math.floor(100 + Math.random() * 900); const nm = d.name.trim() || `موظف جديد ${n}`; const id = addEmployee({ name: nm, nameEn: d.nameEn || (d.name.trim() ? nm : `New Employee ${n}`), gender: d.gender, title: { ar: d.titleAr || 'موظف إداري', en: d.titleEn || d.titleAr || 'Administrative officer' }, dept: d.dept, city: cities[d.city], email: (d.email.trim() || `new${n}@alfarada.sy`).toLowerCase(), phone: d.phone, hireDate: d.hireDate, dob: d.dob, nationalId: d.nationalId || `0${Date.now() % 1e10}`, salary: d.salary, currency: 'USD', managerId: d.managerId || depts.find(x => x.id === d.dept)?.headId }, assets)
    onClose(); setStep(0); toast(L('أُنشئ ملف الموظف وبدأت المباشرة', 'Employee created — onboarding started')); go('onboard', id) }
  return <Modal open={open} onClose={onClose} title={L('إضافة موظف جديد', 'Add new employee')} width={680}
    footer={<>{step > 0 && <Btn onClick={() => setStep(step - 1)}>{L('السابق', 'Back')}</Btn>}{step < 3 ? <Btn kind="primary" disabled={(step === 0 && !ok0) || (step === 1 && !ok1)} onClick={() => setStep(step + 1)}>{L('التالي', 'Next')}</Btn> : <Btn kind="primary" onClick={finish}>{L('إنشاء الملف', 'Create file')}</Btn>}</>}>
    <ol className="flex gap-2 mb-5">{steps.map((x, i) => <li key={i} className={`flex-1 rounded-lg px-2 py-1.5 text-[12px] font-semibold text-center border ${i === step ? 'border-brand bg-brand-soft text-brand' : i < step ? 'border-brand/30 text-brand' : 'border-line text-ink-3'}`}>{i + 1}. {x}</li>)}</ol>
    {step === 0 && <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('الاسم الكامل', 'Full name')} req><input className="field" value={d.name} onChange={e => set('name', e.target.value)} /></Field><Field label={L('الاسم بالإنكليزية', 'Name (EN)')}><input className="field ltr text-start" value={d.nameEn} onChange={e => set('nameEn', e.target.value)} /></Field>
      <Field label={L('الرقم الوطني', 'National ID')} req><input className="field ltr text-start" value={d.nationalId} onChange={e => set('nationalId', e.target.value)} /></Field><Field label={L('تاريخ الولادة', 'Birth date')}><input type="date" className="field" value={d.dob} onChange={e => set('dob', e.target.value)} /></Field>
      <Field label={L('الجوال', 'Mobile')}><input className="field ltr text-start" value={d.phone} onChange={e => set('phone', e.target.value)} /></Field><Field label={L('البريد', 'Email')} req><input className="field ltr text-start" value={d.email} onChange={e => set('email', e.target.value)} /></Field>
    </div>}
    {step === 1 && <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('الإدارة', 'Department')}><select className="field" value={d.dept} onChange={e => set('dept', e.target.value)}>{depts.map(x => <option key={x.id} value={x.id}>{x[lang]}</option>)}</select></Field>
      <Field label={L('المسمى الوظيفي', 'Job title')} req><input className="field" value={d.titleAr} onChange={e => set('titleAr', e.target.value)} /></Field>
      <Field label={L('مقر العمل', 'Location')}><select className="field" value={d.city} onChange={e => set('city', e.target.value)}>{Object.entries(cities).map(([k, v]) => <option key={k} value={k}>{v[lang]}</option>)}</select></Field>
      <Field label={L('المدير المباشر', 'Manager')}><select className="field" value={d.managerId} onChange={e => set('managerId', e.target.value)}><option value="">{L('رئيس الإدارة تلقائياً', 'Department head')}</option>{emps.filter(x => x.status !== 'terminated').map(x => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
      <Field label={L('الراتب الشامل (دولار)', 'Total pay (USD)')}><input type="number" className="field num" value={d.salary} onChange={e => set('salary', Number(e.target.value))} /></Field>
      <Field label={L('تاريخ المباشرة', 'Start date')}><input type="date" className="field" value={d.hireDate} onChange={e => set('hireDate', e.target.value)} /></Field>
    </div>}
    {step === 2 && <div className="grid sm:grid-cols-2 gap-2 max-h-[340px] overflow-y-auto">{free.map(a => <button key={a.id} type="button" onClick={() => setAssets(assets.includes(a.id) ? assets.filter(x => x !== a.id) : [...assets, a.id])} className={`flex items-center gap-3 rounded-xl border p-3 text-start ${assets.includes(a.id) ? 'border-brand bg-brand-soft' : 'border-line'}`}>
      <span className={`size-5 rounded border grid place-items-center ${assets.includes(a.id) ? 'bg-brand border-brand text-on-brand' : 'border-ink-3'}`}>{assets.includes(a.id) && <Check size={13} />}</span><span><span className="block text-[13px] font-semibold">{a.name[lang]}</span><span className="block text-[11px] text-ink-3 ltr text-start">{a.tag}</span></span></button>)}</div>}
    {step === 3 && <div className="space-y-1.5 text-[14px]">{[[L('الاسم', 'Name'), d.name], [L('الإدارة', 'Department'), depts.find(x => x.id === d.dept)?.[lang]], [L('المسمى', 'Title'), d.titleAr], [L('الراتب', 'Pay'), `$${d.salary}`], [L('المباشرة', 'Start'), fmtDate(d.hireDate, lang)], [L('العهد', 'Assets'), assets.length || '—'], [L('كلمة مرور الدخول', 'Login password'), '123456']].map(([k, v]) => <div key={String(k)} className="flex justify-between border-b border-line-2 py-1.5"><span className="text-ink-3">{k}</span><span className="font-semibold">{v}</span></div>)}
      <p className="text-[12px] text-ink-3 pt-2">{L('يُنشأ الملف بحالة «قيد المباشرة» مع قائمة F-19، وتصل العهد للموظف ليوقّع استلامها.', 'The file starts in onboarding with the F-19 checklist; assets go to the employee for signature.')}</p></div>}
  </Modal>
}
