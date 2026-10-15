import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { X, Pause, Play, ChevronLeft, ChevronRight, LayoutDashboard, Globe, Kanban, ClipboardCheck, Stamp, UserPlus, Users, Nfc, CalendarCheck, Package, Smartphone, DoorOpen, MousePointerClick, SlidersHorizontal, Trophy, Check, Network, Target } from 'lucide-react'
import { useStore, useLang, todayISO, fmtTime } from '../lib/store'
import { setLang } from '../lib/actions'
import { setUI } from '../lib/ui'
import { Logo, LangToggle } from '../ui/brand'
import { CountUp } from '../ui/viz'
import { calcDay, STAGES } from '../lib/rules'

const DUR = 7000
type B = [string, string]
type ModScene = { kind: 'module'; icon: ReactNode; title: B; sub: B; impact: B; chips: B[]; visual: ReactNode }
type Scene = { kind: 'intro' } | ModScene | { kind: 'journey' } | { kind: 'board' } | { kind: 'nfc' } | { kind: 'try' } | { kind: 'outro' }

export function Showcase() {
  const lang = useLang(); const ar = lang === 'ar'
  const s = useStore(x => x)
  const t = (b: B) => (ar ? b[0] : b[1])
  const today = todayISO()
  const active = s.employees.filter(e => e.status !== 'terminated')
  const present = s.days.filter(d => d.date === today && d.inAt).length
  const late = s.days.filter(d => d.date === today && d.inAt && calcDay(today, d.inAt).late > 0).length
  const pending = s.leaves.filter(l => l.status.startsWith('pending')).length
  const issued = s.assets.filter(a => a.empId).length

  const org = ar ? 'right' : 'left'
  const card = 'rounded-2xl border border-show-fg/10 bg-show-fg/[.04] p-5'
  const row = (k: number, a: ReactNode, b?: ReactNode) => <div key={k} className="flex items-center justify-between gap-3 rounded-xl border border-show-fg/10 bg-show/60 px-4 py-3 fadeup" style={{ animationDelay: `${0.5 + k * 0.25}s` }}>{a}{b}</div>
  const tick = <span className="size-5 rounded bg-brand text-on-brand grid place-items-center"><Check size={13} /></span>
  const mods: ModScene[] = [
    { kind: 'module', icon: <LayoutDashboard />, title: ['لوحة المتابعة', 'Command Center'], sub: ['كل ما ينتظر قرارك، في شاشة واحدة', 'Everything waiting on you, on one screen'], impact: ['وافق أو ارفض من مكانك — بدون فتح أي ملف', 'Approve or reject in place — without opening a single file'], chips: [['موافقات مباشرة', 'Inline approvals'], ['تنبيهات', 'Alerts'], ['بحث شامل', 'Global search']],
      visual: <div className="space-y-2.5 w-full">{([['خالد العمر', 'Khaled Al-Omar', 'مهمة رسمية — حماة', 'Official mission — Hama'], ['هبة الزعبي', 'Heba Al-Zoubi', 'إجازة سنوية — 5 أيام', 'Annual leave — 5 days'], ['يزن المصري', 'Yazan Al-Masri', 'مغادرة ساعية', 'Hourly permission']] as string[][]).map((r, k) => row(k, <span><span className="block font-semibold">{t([r[0], r[1]])}</span><span className="text-[12px] text-show-fg/55">{t([r[2], r[3]])}</span></span>, <span className="flex gap-1.5"><span className="rounded-lg bg-brand text-on-brand px-3 py-1 text-[12px] font-bold">{t(['موافقة', 'Approve'])}</span><span className="rounded-lg border border-show-fg/20 px-3 py-1 text-[12px]">{t(['رفض', 'Reject'])}</span></span>))}</div> },
    { kind: 'module', icon: <Globe />, title: ['بوابة التوظيف', 'Careers Portal'], sub: ['المتقدم يقدّم بنفسه، والملف يُبنى تلقائياً', 'Applicants apply themselves; the file builds itself'], impact: ['من أي جوال، بأربع خطوات، مع رقم متابعة فوري', 'From any phone, in four steps, with an instant reference number'], chips: [['رفع السيرة الذاتية', 'CV upload'], ['كشف التكرار', 'Duplicate check'], ['تأكيد واتساب', 'WhatsApp confirmation']],
      visual: <div className={`${card} w-full max-w-[420px]`}><div className="flex gap-1.5">{[1, 2, 3, 4].map(k => <span key={k} className={`h-1.5 flex-1 rounded-full ${k <= 3 ? 'bg-brand' : 'bg-show-fg/15'}`} style={{ animation: `growx .5s ${0.4 + k * 0.3}s both`, transformOrigin: org }} />)}</div>
        <div className="mt-4 space-y-2.5">{[t(['الاسم الثلاثي', 'Full name']), t(['الرقم الوطني', 'National ID']), t(['الراتب المتوقع', 'Expected salary'])].map((x, k) => <div key={k} className="fadeup" style={{ animationDelay: `${0.6 + k * 0.3}s` }}><div className="text-[11px] text-show-fg/50">{x}</div><div className="h-9 rounded-lg border border-show-fg/15 bg-show/60" /></div>)}</div>
        <div className="mt-4 rounded-lg border border-brand/40 bg-brand/10 px-3 py-2 text-[12px] fadeup ltr text-center" style={{ animationDelay: '1.8s' }}>UNQ-2026-APP-50417</div></div> },
    { kind: 'module', icon: <SlidersHorizontal />, title: ['قواعد الأتمتة', 'Automation Rules'], sub: ['أنت تضع القاعدة، والنظام يطبّقها', 'You set the rule; the system applies it'], impact: ['حد مطابقة للفرز التلقائي — ولا رفض بدون إنسان', 'A match gate for auto-screening — no rejection without a human'], chips: [['حد المطابقة', 'Match gate'], ['مهلة المراحل', 'Stage SLA'], ['رسائل تلقائية', 'Auto messages']],
      visual: <div className={`${card} w-full max-w-[440px]`}><div className="flex justify-between font-semibold"><span>{t(['حد المطابقة', 'Match gate'])}</span><span className="text-brand font-black font-display text-[26px]">70%</span></div>
        <div className="mt-3 h-2 rounded-full bg-show-fg/10 relative"><span className="absolute inset-y-0 start-0 rounded-full bg-brand" style={{ width: '62%', animation: 'growx 1s .5s both', transformOrigin: org }} /><span className="absolute -top-1.5 size-5 rounded-full bg-white shadow" style={{ insetInlineStart: 'calc(62% - 10px)' }} /></div>
        <div className="mt-5 space-y-2">{[t(['فرز تلقائي للطلبات الجديدة', 'Auto-screen new applications']), t(['تنبيه تجاوز المهلة', 'SLA alerts']), t(['دعوات المقابلة عبر واتساب', 'WhatsApp interview invites'])].map((x, k) => <div key={k} className="flex items-center justify-between text-[14px] fadeup" style={{ animationDelay: `${0.8 + k * 0.25}s` }}>{x}<span className="w-10 h-5 rounded-full bg-brand p-0.5"><span className="block size-4 rounded-full bg-white ms-auto" /></span></div>)}</div></div> },
    { kind: 'module', icon: <ClipboardCheck />, title: ['المقابلة وعرض العمل', 'Interview & Offer'], sub: ['تقييم موحد، وعرض يُوقَّع من الجوال', 'Standard scoring, an offer signed from a phone'], impact: ['اللجنة تقيّم بنفس المعايير، والمرشح يقبل أو يفاوض من حسابه', 'Every panel scores the same way; the candidate accepts or negotiates online'], chips: [['نموذج F-25', 'Form F-25'], ['نموذج F-29', 'Form F-29'], ['توقيع إلكتروني', 'E-signature']],
      visual: <div className={`${card} w-full max-w-[420px] sheet !bg-white !text-[#17242f]`}><div className="text-[11px] text-[#6b7680] ltr text-end">Uniqueness-HR-01-F-29</div><div className="font-display font-bold text-[18px]">{t(['عرض العمل الرسمي', 'Official job offer'])}</div>
        <div className="mt-3 space-y-1.5 text-[13px]">{[[t(['الراتب الأساسي', 'Basic']), '$1,200'], [t(['بدل السكن', 'Housing']), '$144'], [t(['بدل المواصلات', 'Transport']), '$40']].map(([k, v], i) => <div key={i} className="flex justify-between border-b border-[#e5e9ec] pb-1"><span>{k}</span><b className="num">{v}</b></div>)}</div>
        <svg viewBox="0 0 200 40" className="mt-4 h-10 w-40"><path d="M5 30 C 30 5, 45 38, 70 18 S 110 8, 125 26 S 165 30, 195 12" fill="none" stroke="#17242f" strokeWidth="2.5" strokeLinecap="round" strokeDasharray="300" strokeDashoffset="300" style={{ animation: 'sigdraw 1.6s .8s forwards' }} /></svg></div> },
    { kind: 'module', icon: <Stamp />, title: ['قرار التعيين', 'Appointment Decision'], sub: ['أربعة تواقيع، بدون ورقة واحدة', 'Four signatures, not one sheet of paper'], impact: ['الموارد البشرية ← المالية ← المدير التنفيذي ← الرئيس التنفيذي', 'HR → Finance → Executive Director → CEO'], chips: [['نموذج F-31', 'Form F-31'], ['رقم وظيفي تلقائي', 'Auto employee ID']],
      visual: <div className="grid grid-cols-2 gap-3 w-full max-w-[460px]">{([['الموارد البشرية', 'HR'], ['المالية', 'Finance'], ['المدير التنفيذي', 'Exec. Director'], ['الرئيس التنفيذي', 'CEO']] as B[]).map((r, k) => <div key={k} className={`${card} !p-4 fadeup`} style={{ animationDelay: `${0.4 + k * 0.45}s` }}><div className="text-[12px] text-show-fg/55">{k + 1}. {t(r)}</div><span className="stamp text-brand text-[12px] mt-2">{t(['معتمد', 'APPROVED'])}</span></div>)}</div> },
    { kind: 'module', icon: <UserPlus />, title: ['المباشرة والاندماج', 'Onboarding'], sub: ['اليوم الأول منظّم قبل أن يبدأ', 'Day one is organised before it begins'], impact: ['قائمة إجراءات، مذكرة ترحيب، تعهدات وعهد — كلها موقّعة إلكترونياً', 'Checklist, welcome memo, undertakings and custody — all e-signed'], chips: [['F-19', 'F-19'], ['F-32', 'F-32'], ['F-22 / F-20', 'F-22 / F-20']],
      visual: <div className="space-y-2.5 w-full max-w-[440px]">{([['إصدار البريد والحاسب', 'Email & laptop issued'], ['توقيع تعهد السرية', 'Confidentiality signed'], ['تعميم مذكرة الترحيب', 'Welcome memo sent'], ['تعيين زميل مرشد', 'Buddy assigned']] as B[]).map((r, k) => row(k, <span className="font-semibold">{t(r)}</span>, tick))}</div> },
    { kind: 'module', icon: <CalendarCheck />, title: ['الإجازات والتبريرات', 'Leave & Justifications'], sub: ['طلب من الجوال، ورصيد يُحدَّث وحده', 'Requested from a phone; the balance updates itself'], impact: ['المدير المباشر ثم الموارد البشرية — وكل خطوة موثّقة', 'Manager, then HR — every step on record'], chips: [['سنوية', 'Annual'], ['مرضية', 'Sick'], ['مغادرة ساعية', 'Hourly'], ['تبرير تأخير', 'Late justification']],
      visual: <div className={`${card} w-full max-w-[420px]`}><div className="font-semibold">{t(['إجازة سنوية', 'Annual leave'])}</div><div className="text-[13px] text-show-fg/60">{t(['سفر عائلي', 'Family travel'])}</div>
        <div className="mt-4 space-y-3">{([['المدير المباشر', 'Direct manager'], ['الموارد البشرية', 'Human Resources']] as B[]).map((r, k) => <div key={k} className="flex items-center gap-3 fadeup" style={{ animationDelay: `${0.7 + k * 0.6}s` }}>{tick}<span>{t(r)}</span></div>)}</div></div> },
    { kind: 'module', icon: <Package />, title: ['العهد', 'Asset Custody'], sub: ['كل جهاز معروف بعهدة من', 'Every device, every holder, on record'], impact: ['تسليم بتوقيع، واسترجاع تلقائي عند إبراء الذمة', 'Signed handover, automatic recovery at clearance'], chips: [['سند استلام', 'Receipt'], ['استرجاع', 'Return']],
      visual: <div className="space-y-2.5 w-full max-w-[440px]">{([['حاسب محمول Dell Latitude', 'Dell Latitude laptop', 'AST-0041'], ['هاتف Samsung Galaxy', 'Samsung Galaxy phone', 'AST-0042'], ['بطاقة NFC', 'NFC card', 'AST-0043']] as string[][]).map((r, k) => row(k, <span><span className="block font-semibold">{t([r[0], r[1]])}</span><span className="text-[11px] text-show-fg/50 ltr">{r[2]}</span></span>, <span className="text-[12px] text-brand font-bold">{t(['موقّع', 'Signed'])}</span>))}</div> },
    { kind: 'module', icon: <Trophy />, title: ['الإحالات والمكافآت', 'Referrals & Rewards'], sub: ['موظفوك أفضل من يستقطب', 'Your people are your best recruiters'], impact: ['رابط إحالة لكل موظف، ونقاط عند التعيين يستبدلها بمكافآت', 'A referral link per employee, points on hire, rewards to redeem'], chips: [['روابط تتبّع', 'Tracking links'], ['لوحة متصدرين', 'Leaderboard'], ['متجر مكافآت', 'Rewards store']],
      visual: <div className="space-y-2.5 w-full max-w-[440px]">{([['سامر الحلبي', 'Samer Al-Halabi', 'text-[#e7c27a]'], ['رشا الخطيب', 'Rasha Al-Khatib', 'text-[#c9d1d9]'], ['محمد الحمصي', 'Mohammad Al-Homsi', 'text-[#d39a6a]']] as string[][]).map((r, k) => row(k, <span className="flex items-center gap-3"><Trophy size={18} className={r[2]} /><span className="font-semibold">{t([r[0], r[1]])}</span></span>, <span className="h-1.5 w-24 rounded-full bg-show-fg/10 overflow-hidden"><span className="block h-full bg-brand rounded-full" style={{ width: `${100 - k * 28}%` }} /></span>))}</div> },
    { kind: 'module', icon: <Smartphone />, title: ['الخدمة الذاتية', 'Self-Service'], sub: ['الموظف يخدم نفسه — بدون مراجعة المكتب', 'Employees serve themselves — no office visit'], impact: ['حضور، طلبات، توقيع وثائق، إحالات واستقالة — من حساب واحد', 'Attendance, requests, signing, referrals and resignation — one account'], chips: [['حضوري', 'My attendance'], ['طلباتي', 'My requests'], ['وثائق للتوقيع', 'To sign']],
      visual: <div className="mx-auto w-[230px] rounded-[30px] border-4 border-show-fg/15 bg-show p-4"><div className="mx-auto h-1.5 w-16 rounded-full bg-show-fg/15" /><div className="mt-4 text-[12px] text-show-fg/55">{t(['صباح الخير', 'Good morning'])}</div>
        <div className="mt-3 rounded-xl bg-brand text-on-brand text-center py-3 font-bold fadeup" style={{ animationDelay: '.6s' }}>{t(['تسجيل الحضور', 'Check in'])}</div>
        <div className="mt-2 grid grid-cols-2 gap-2">{([['إجازة', 'Leave'], ['تبرير', 'Justify'], ['عهدتي', 'Custody'], ['إحالة', 'Refer']] as B[]).map((r, k) => <div key={k} className="rounded-lg border border-show-fg/10 py-3 text-center text-[12px] fadeup" style={{ animationDelay: `${0.9 + k * 0.15}s` }}>{t(r)}</div>)}</div></div> },
    { kind: 'module', icon: <DoorOpen />, title: ['الاستقالة وإنهاء الخدمة', 'Resignation & Exit'], sub: ['خروج منظم بقدر الدخول', 'An exit as organised as the entry'], impact: ['إبراء ذمة من أربع جهات، ومخالصة محسوبة من بيانات الموظف', 'Four-party clearance and a settlement calculated from the record'], chips: [['F-26', 'F-26'], ['F-33', 'F-33'], ['مقابلة الخروج', 'Exit interview']],
      visual: <div className="space-y-2.5 w-full max-w-[440px]">{([['استلام العهد', 'Custody returned'], ['إغلاق الحسابات', 'Accounts closed'], ['التسوية المالية', 'Finance settled'], ['مقابلة الخروج', 'Exit interview']] as B[]).map((r, k) => row(k, <span className="font-semibold">{t(r)}</span>, tick))}</div> },
    { kind: 'module', icon: <Network />, title: ['الهيكل التنظيمي', 'Organization Chart'], sub: ['الشركة كلها على شاشة واحدة', 'The whole company on one screen'], impact: ['يُبنى تلقائياً من بيانات الموظفين — افتح أي فريق بنقرة', 'Built automatically from employee data — open any team in a click'], chips: [['بحث فوري', 'Instant search'], ['تكبير وتصغير', 'Zoom'], ['ألوان الإدارات', 'Department colours']],
      visual: <div className="flex flex-col items-center w-full max-w-[460px]">{[[1], [1, 1, 1], [1, 1, 1, 1, 1]].map((r, k) => <div key={k} className="flex flex-col items-center w-full">{k > 0 && <span className="h-5 w-px bg-show-fg/25" />}<div className="flex justify-center gap-2 w-full">{r.map((_, i) => <div key={i} className="h-11 flex-1 max-w-[120px] rounded-xl border border-show-fg/15 bg-show-fg/[.05] fadeup grid place-items-center" style={{ animationDelay: `${0.3 + k * 0.4 + i * 0.08}s` }}><span className={`h-1.5 w-8 rounded-full ${k === 0 ? 'bg-brand' : i % 2 ? 'bg-brass' : 'bg-brand/70'}`} /></div>)}</div></div>)}</div> },
    { kind: 'module', icon: <Target />, title: ['الأداء وتقييم 360', 'Performance & 360'], sub: ['من المدير والزملاء والموظف نفسه', 'From manager, peers and the employee'], impact: ['مصفوفة الأداء والإمكانات: اسحب الموظف وعاير التقييم أمام اللجنة', 'Performance–potential grid: drag to calibrate in front of the panel'], chips: [['9-Box', '9-Box'], ['ست كفاءات', 'Six competencies'], ['معايرة', 'Calibration']],
      visual: <div className="grid grid-cols-3 gap-2 w-full max-w-[380px]">{Array.from({ length: 9 }).map((_, i) => <div key={i} className={`h-[74px] rounded-xl border border-show-fg/10 fadeup p-2 flex flex-wrap gap-1 content-start ${[0, 1, 3].includes(i) ? 'bg-brand/15' : [8].includes(i) ? 'bg-danger/15' : 'bg-show-fg/[.04]'}`} style={{ animationDelay: `${0.3 + i * 0.1}s` }}>{Array.from({ length: (i * 7) % 4 + 1 }).map((__, k) => <span key={k} className="size-4 rounded-md bg-show-fg/25" />)}</div>)}</div> },
  ]
  const scenes: Scene[] = [{ kind: 'intro' }, { kind: 'journey' }, mods[0], mods[1], { kind: 'board' }, mods[2], mods[3], mods[4], mods[5], { kind: 'nfc' }, mods[6], mods[11], mods[12], mods[7], mods[8], mods[9], mods[10], { kind: 'try' }, { kind: 'outro' }]
  const [i, setI] = useState(0); const [paused, setPaused] = useState(false)
  useEffect(() => { const start = lang; return () => setLang(start) }, []) // eslint-disable-line
  const go = useCallback((n: number) => setI(cur => { const nx = (n + scenes.length) % scenes.length; if (nx === 0 && cur === scenes.length - 1) setLang(ar ? 'en' : 'ar'); return nx }), [scenes.length, ar])
  useEffect(() => { if (paused) return; const tm = setTimeout(() => go(i + 1), DUR); return () => clearTimeout(tm) }, [i, paused, go])
  useEffect(() => {
    const k = (e: KeyboardEvent) => { if (e.key === 'Escape') setUI({ showcase: false }); if (e.key === ' ') { e.preventDefault(); setPaused(p => !p) } if (e.key === 'ArrowRight') go(ar ? i - 1 : i + 1); if (e.key === 'ArrowLeft') go(ar ? i + 1 : i - 1) }
    window.addEventListener('keydown', k); return () => window.removeEventListener('keydown', k)
  }, [i, ar, go])
  const sc = scenes[i]; const modIdx = mods.indexOf(sc as ModScene)
  const Prev = ar ? ChevronRight : ChevronLeft, Next = ar ? ChevronLeft : ChevronRight
  return <div dir={ar ? 'rtl' : 'ltr'} className="show-root fixed inset-0 z-[90] overflow-hidden" data-noprint>
    <div className="show-bg" aria-hidden><i /><i /><i /></div><div className="show-grid" aria-hidden />
    <div className="absolute inset-x-0 top-0 z-20 flex items-center justify-between gap-3 p-5 sm:p-7">
      <Logo pill h={26} />
      <div className="flex items-center gap-1">
        <LangToggle onDark />
        {[{ k: 'p', el: <Prev size={18} />, f: () => go(i - 1) }, { k: 'pp', el: paused ? <Play size={17} /> : <Pause size={17} />, f: () => setPaused(!paused) }, { k: 'n', el: <Next size={18} />, f: () => go(i + 1) }, { k: 'x', el: <X size={18} />, f: () => setUI({ showcase: false }) }].map(b =>
          <button key={b.k} onClick={b.f} className="size-9 grid place-items-center rounded-lg text-show-fg/80 hover:bg-show-fg/10 hover:text-show-fg">{b.el}</button>)}
      </div>
    </div>
    <div key={`${i}-${lang}`} className="relative z-10 flex h-full items-center px-6 pb-24 pt-24 sm:px-16">
      {sc.kind === 'intro' && <div className="w-full text-center show-in">
        <div><Logo pill h={64} /></div>
        <div className="mt-6 text-[13px] font-bold tracking-[.35em] text-brand">UNIQUENESS GROUP</div>
        <h1 className="mt-3 text-[clamp(38px,6vw,84px)] font-black leading-[1.15]">{t(['نظام الموارد البشرية المتكامل', 'The Complete People Operating System'])}</h1>
        <p className="mt-4 text-[clamp(15px,1.6vw,21px)] text-show-fg/70">{t(['رحلة الموظف كاملة — من طلب التوظيف حتى إنهاء الخدمة · عربي وإنكليزي', 'The whole employee journey — from application to exit · Arabic & English'])}</p>
      </div>}
      {sc.kind === 'module' && <ModuleScene sc={sc} n={modIdx + 1} total={mods.length} t={t} />}
      {sc.kind === 'journey' && <Journey t={t} />}
      {sc.kind === 'board' && <BoardScene t={t} />}
      {sc.kind === 'nfc' && <NfcScene t={t} />}
      {sc.kind === 'try' && <TryScene t={t} />}
      {sc.kind === 'outro' && <div className="w-full text-center show-in">
        <div><Logo pill h={56} /></div>
        <h2 className="mt-6 text-[clamp(32px,5vw,68px)] font-black leading-tight">{t(['منصة واحدة لكل رحلة الموظف', 'One platform for the entire employee journey'])}</h2>
        <div className="mt-8 flex flex-wrap justify-center gap-x-12 gap-y-4">{([[['موظف', 'employees'], active.length], [['نموذج رسمي مرقمن', 'digitised forms'], 11], [['وحدة مترابطة', 'connected modules'], mods.length], [['مستويات اعتماد', 'approval levels'], 4]] as [B, number][]).map(([k, v]) =>
          <div key={k[1]} className="flex items-baseline gap-2"><span className="text-[34px] font-black text-brand num font-display"><CountUp to={v} /></span><span className="text-show-fg/65">{t(k)}</span></div>)}</div>
        <div className="mt-10 text-brand font-semibold">{t(['زوروا جناحنا وجرّبوا النظام بأنفسكم', 'Visit our booth and try it yourself'])}</div>
      </div>}
    </div>
    <div className="absolute inset-x-0 bottom-0 z-20 px-6 pb-6 sm:px-16">
      <div className="flex gap-1">{scenes.map((_, k) => <button key={k} aria-label={`Scene ${k + 1}`} onClick={() => go(k)} className="h-1 flex-1 overflow-hidden rounded bg-show-fg/15">
        <span className={`block h-full bg-brand ${k < i ? 'w-full' : k === i ? 'show-progress' : 'w-0'}`} style={k === i ? { animationDuration: `${DUR}ms`, animationPlayState: paused ? 'paused' : 'running' } : undefined} /></button>)}</div>
      <div className="mt-2 flex justify-between text-[11px] text-show-fg/45"><span>UHR · Uniqueness Group</span><span className="num ltr">{String(i + 1).padStart(2, '0')} / {scenes.length}</span></div>
    </div>
  </div>
}

function ModuleScene({ sc, n, total, t }: { sc: ModScene; n: number; total: number; t: (b: B) => string }) {
  return <div className="grid w-full items-center gap-10 lg:grid-cols-[1.15fr_1fr]">
    <div className="show-in">
      <div className="flex items-center gap-3 text-[13px] font-bold text-brand num"><span>{String(n).padStart(2, '0')}</span><span className="h-px w-10 bg-brand/60" /><span className="text-show-fg/45">{total}</span></div>
      <h2 className="mt-4 text-[clamp(38px,5.4vw,76px)] font-black leading-[1.1]">{t(sc.title)}</h2>
      <p className="mt-3 text-[clamp(16px,1.7vw,22px)] text-show-fg/70">{t(sc.sub)}</p>
      <div className="mt-6 border-s-[3px] border-brand ps-4 text-[clamp(15px,1.4vw,19px)] font-semibold">{t(sc.impact)}</div>
      <div className="mt-6 flex flex-wrap gap-2">{sc.chips.map(c => <span key={c[1]} className="rounded-lg border border-show-fg/15 bg-show-fg/5 px-3 py-1.5 text-[13px]">{t(c)}</span>)}</div>
    </div>
    <div className="flex flex-col items-center gap-8">
      <div className="icon-orb size-20 rounded-full border border-brand/40 bg-[oklch(20%_.05_200)] grid place-items-center text-brand [&_svg]:size-9 fadeup">{sc.icon}</div>
      {sc.visual}
    </div>
  </div>
}

function Journey({ t }: { t: (b: B) => string }) {
  const ar = useLang() === 'ar'
  const steps: [B, string][] = [[['طلب التوظيف', 'Application'], 'F-28'], [['المقابلة', 'Interview'], 'F-25'], [['عرض العمل', 'Offer'], 'F-29'], [['قرار التعيين', 'Appointment'], 'F-31'], [['المباشرة', 'Onboarding'], 'F-19'], [['التعهدات', 'Undertakings'], 'F-22'], [['على رأس العمل', 'Active'], ''], [['الاستقالة', 'Resignation'], 'F-26'], [['إنهاء الخدمة', 'Exit'], 'F-33']]
  return <div className="w-full">
    <div className="show-in text-center"><div className="text-[13px] font-bold tracking-[.25em] text-brand">{t(['رحلة الموظف', 'EMPLOYEE JOURNEY'])}</div>
      <h2 className="mt-3 text-[clamp(32px,4.6vw,64px)] font-black leading-tight">{t(['من أول طلب حتى آخر يوم — في نظام واحد', 'From first application to last day — one system'])}</h2></div>
    <ol className="mt-14 grid grid-cols-3 md:grid-cols-9 gap-y-8">{steps.map(([b, code], k) =>
      <li key={k} className="relative flex flex-col items-center text-center fadeup" style={{ animationDelay: `${0.35 + k * 0.45}s` }}>
        {k < steps.length - 1 && <span className="absolute top-6 start-1/2 h-0.5 w-full bg-brand/40 hidden md:block " style={{ animation: `growx .4s ${0.55 + k * 0.45}s both`, transformOrigin: ar ? 'right' : 'left' }} />}
        <span className="relative z-10 size-12 rounded-full grid place-items-center border-2 border-brand bg-show font-black text-brand num">{k + 1}</span>
        <span className="mt-3 text-[14px] font-semibold">{t(b)}</span>{code && <span className="text-[11px] text-show-fg/50 ltr">{code}</span>}
      </li>)}</ol>
  </div>
}

function BoardScene({ t }: { t: (b: B) => string }) {
  const cols: B[] = [['جديد', 'New'], ['فرز', 'Screening'], ['مقابلة', 'Interview'], ['تقييم فني', 'Technical'], ['عرض', 'Offer'], ['تعيين', 'Hired']]
  const [k, setK] = useState(0)
  useEffect(() => { const tm = setInterval(() => setK(x => Math.min(cols.length - 1, x + 1)), 1000); return () => clearInterval(tm) }, [cols.length])
  return <div className="grid w-full items-center gap-10 lg:grid-cols-[1fr_1.3fr]">
    <div className="show-in"><div className="text-[13px] font-bold tracking-[.2em] text-brand">{t(['من الطلب إلى التعيين', 'FROM APPLICATION TO HIRE'])}</div>
      <h2 className="mt-3 text-[clamp(32px,4.5vw,62px)] font-black leading-tight">{t(['كل متقدم في مكانه الصحيح، دائماً', 'Every applicant in the right place, always'])}</h2>
      <p className="mt-4 text-show-fg/70 text-[18px]">{t(['اسحب البطاقة، وتُرسل الدعوة وتُسجَّل الحركة ويُحسب التأخير تلقائياً.', 'Drag a card — invitations go out, history is logged and delays are tracked.'])}</p></div>
    <div className="grid grid-cols-6 gap-2 fadeup">{cols.map((c, ci) => <div key={c[1]} className="rounded-xl border border-show-fg/10 bg-show-fg/[.04] p-2 min-h-[260px]">
      <div className="text-[12px] font-semibold text-show-fg/70 mb-2">{t(c)}</div>
      {ci === k && <div className="rounded-lg border border-brand bg-brand/15 p-2 fadeup"><div className="text-[12px] font-bold">{t(['زائر المعرض', 'Expo visitor'])}</div><div className="text-[10px] text-show-fg/60">{t(['مطور برمجيات', 'Developer'])}</div><div className="mt-1 text-[11px] font-black text-brand num">86%</div></div>}
      {[0, 1].map(z => (ci + z) % 3 === 0 && <div key={z} className="mt-2 rounded-lg border border-show-fg/10 bg-show-fg/5 h-12" />)}
    </div>)}</div>
  </div>
}

function NfcScene({ t }: { t: (b: B) => string }) {
  const s = useStore(x => x); const lang = useLang()
  const rows = s.days.filter(d => d.date === todayISO() && d.inAt).sort((a, b) => b.inAt!.localeCompare(a.inAt!)).slice(0, 5)
  return <div className="grid w-full items-center gap-12 lg:grid-cols-2">
    <div className="show-in"><div className="text-[13px] font-bold tracking-[.2em] text-brand">NFC</div>
      <h2 className="mt-3 text-[clamp(32px,4.5vw,62px)] font-black leading-tight">{t(['مرّر البطاقة. والباقي على النظام.', 'Tap the card. The system does the rest.'])}</h2>
      <p className="mt-4 text-show-fg/70 text-[18px]">{t(['التأخير والإضافي وأيام العطل تُحسب لحظة المرور.', 'Lateness, overtime and rest days are calculated at the moment of the tap.'])}</p></div>
    <div className="flex items-center gap-8 fadeup">
      <div className="icon-orb size-40 shrink-0 rounded-full border border-brand/40 grid place-items-center text-brand"><Nfc size={64} /></div>
      <ul className="flex-1 space-y-2">{rows.map((d, k) => { const e = s.employees.find(x => x.id === d.empId)!; const c = calcDay(todayISO(), d.inAt)
        return <li key={d.empId} className="flex items-center justify-between rounded-lg border border-show-fg/10 bg-show-fg/[.04] px-4 py-2.5 fadeup" style={{ animationDelay: `${0.3 + k * 0.25}s` }}>
          <span className="font-semibold">{lang === 'ar' ? e.name : e.nameEn}</span><span className="flex items-center gap-3"><span className="num ltr text-show-fg/70">{fmtTime(d.inAt)}</span><span className={`text-[12px] font-bold ${c.late ? 'text-brass' : 'text-brand'}`}>{c.late ? t(['متأخر', 'Late']) : t(['في الموعد', 'On time'])}</span></span></li> })}</ul>
    </div>
  </div>
}

function TryScene({ t }: { t: (b: B) => string }) {
  const steps: B[] = [['قدّم على وظيفة من بوابة التوظيف', 'Apply for a role on the careers portal'], ['تابع طلبك ووقّع عرض العمل من حسابك', 'Track your application and sign the offer'], ['ادخل كموظف، سجّل حضورك ووقّع عهدتك', 'Sign in as an employee, check in and sign your custody']]
  return <div className="w-full text-center">
    <div className="show-in"><MousePointerClick className="mx-auto text-brand" size={44} /><h2 className="mt-4 text-[clamp(34px,5vw,70px)] font-black leading-tight">{t(['جرّب الرحلة بنفسك — الآن', 'Live the journey yourself — now'])}</h2></div>
    <div className="mt-10 grid md:grid-cols-3 gap-4 max-w-5xl mx-auto">{steps.map((b, k) => <div key={k} className="rounded-2xl border border-show-fg/10 bg-show-fg/[.04] p-6 text-start fadeup" style={{ animationDelay: `${0.4 + k * 0.3}s` }}>
      <div className="size-10 rounded-full bg-brand text-on-brand grid place-items-center font-black num">{k + 1}</div><div className="mt-4 text-[18px] font-semibold">{t(b)}</div></div>)}</div>
    <button onClick={() => setUI({ showcase: false })} className="mt-10 h-12 px-8 rounded-xl bg-brand text-on-brand font-bold text-[16px] fadeup hover:brightness-110" style={{ animationDelay: '1.4s' }}>{t(['ابدأ التجربة', 'Start the experience'])}</button>
  </div>
}
