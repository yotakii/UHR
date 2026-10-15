import { useState } from 'react'
import { Smartphone, MapPin, Check } from 'lucide-react'
import { useStore, useL, useLang, fmtDate, todayISO, addDays, isWeekend, fmtTime, useRelaxed, autoSig, useDisplayName } from '../lib/store'
import { nfcScan, requestLeave, ackAsset, signPledge, submitResignation } from '../lib/actions'
import { useNav } from '../lib/nav'
import { Btn, Panel, Badge, PageHead, Field, SignaturePad, Empty, Modal, Progress, toast } from '../ui/kit'
import { Paper, SigBox } from '../ui/docs'
import { EmployeeFile, AttendanceTable, LeaveStatus } from './Employees'
import { calcDay, minsLabel, LEAVE_TYPES, annualEntitlement } from '../lib/rules'
import { REASONS } from './Offboarding'
import type { LeaveType } from '../lib/types'

const useMe = () => { const id = useStore(s => s.session!.id); return useStore(s => s.employees.find(e => e.id === id))! }

export function EmpHome() {
  const L = useL(); const lang = useLang(); const { go } = useNav(); const e = useMe()
  const pendingAssets = useStore(s => s.assets.filter(a => a.empId === e.id && a.status === 'pending_ack'))
  const today = useStore(s => s.days.find(d => d.empId === e.id && d.date === todayISO()))
  const todo: { t: string; go: string }[] = []
  pendingAssets.forEach(a => todo.push({ t: L(`توقيع استلام عهدة: ${a.name.ar}`, `Sign custody receipt: ${a.name.en}`), go: 'my-docs' }))
  if (e.onboarding && !e.onboarding.f22) todo.push({ t: L('توقيع تعهد المحافظة على سرية العمل (F-22)', 'Sign confidentiality undertaking (F-22)'), go: 'my-docs' })
  if (e.onboarding && !e.onboarding.f20) todo.push({ t: L('توقيع إقرار إبراء الذمة الوقائي (F-20)', 'Sign precautionary clearance (F-20)'), go: 'my-docs' })
  if (!today?.inAt && !isWeekend(todayISO()) && e.status !== 'terminated') todo.push({ t: L('لم تسجّل حضورك اليوم', "You haven't checked in today"), go: 'my-attendance' })
  return <div className="max-w-[1200px] mx-auto space-y-4">
    {todo.length > 0 && <Panel title={L('مطلوب منك', 'Your to-dos')} sub={e.onboarding && e.status === 'onboarding' ? L(`إجراءات المباشرة ${e.onboarding.checklist.filter(Boolean).length} من 28`, `Onboarding ${e.onboarding.checklist.filter(Boolean).length} of 28`) : undefined} pad={false}>
      {e.onboarding && e.status === 'onboarding' && <div className="px-4 pt-3"><Progress value={e.onboarding.checklist.filter(Boolean).length / 28 * 100} /></div>}
      <ul className="divide-y divide-line-2">{todo.map((t, i) => <li key={i} className="flex items-center justify-between px-4 py-2.5"><span className="text-[14px]">{t.t}</span><Btn size="sm" kind="primary" onClick={() => go(t.go)}>{L('فتح', 'Open')}</Btn></li>)}</ul>
    </Panel>}
    <EmployeeFile id={e.id} self />
    <span className="hidden">{lang}</span>
  </div>
}

export function MyAttendance() {
  const L = useL(); const lang = useLang(); const e = useMe()
  const today = useStore(s => s.days.find(d => d.empId === e.id && d.date === todayISO()))
  const days = useStore(s => s.days.filter(d => d.empId === e.id))
  const leaves = useStore(s => s.leaves.filter(l => l.empId === e.id && l.type === 'justify'))
  const [justify, setJustify] = useState<{ date: string; minutes: number } | null>(null); const [reason, setReason] = useState('')
  const c = calcDay(todayISO(), today?.inAt, today?.outAt)
  const lateDays = days.filter(d => d.inAt && calcDay(d.date, d.inAt).late > 0 && !d.justified && !leaves.some(l => l.refDate === d.date)).sort((a, b) => b.date.localeCompare(a.date)).slice(0, 5)
  return <div className="max-w-[1100px] mx-auto">
    <PageHead title={L('حضوري', 'My attendance')} sub={L('الدوام 08:00 – 16:00 مع سماح 15 دقيقة.', 'Shift 08:00 – 16:00 with a 15-minute grace period.')} />
    <div className="grid md:grid-cols-[1fr_1fr] gap-4 mb-4">
      <Panel title={L('اليوم', 'Today')} sub={fmtDate(todayISO(), lang)}>
        <div className="flex items-center gap-6">
          <div><div className="text-[12px] text-ink-3">{L('الدخول', 'In')}</div><div className="text-[24px] font-bold num ltr">{today?.inAt ? fmtTime(today.inAt) : '—'}</div></div>
          <div><div className="text-[12px] text-ink-3">{L('الخروج', 'Out')}</div><div className="text-[24px] font-bold num ltr">{today?.outAt ? fmtTime(today.outAt) : '—'}</div></div>
          <div className="ms-auto">{c.late > 0 ? <Badge tone="warn">{L('تأخير', 'Late')} {minsLabel(c.late, lang)}</Badge> : today?.inAt ? <Badge tone="brand">{L('في الموعد', 'On time')}</Badge> : null}</div>
        </div>
        {e.status !== 'terminated' && (!today?.outAt) && <div className="mt-4 pt-4 border-t border-line-2 flex items-center gap-3">
          <Btn kind="primary" icon={<Smartphone size={16} />} onClick={() => { const r = nfcScan(e.id, new Date(), { ar: 'تطبيق الجوال — ضمن نطاق المقر', en: 'Mobile app — inside HQ geofence' }); toast(r.type === 'in' ? L('سُجّل دخولك', 'Checked in') : L('سُجّل خروجك', 'Checked out')) }}>{today?.inAt ? L('تسجيل الخروج', 'Check out') : L('تسجيل الحضور', 'Check in')}</Btn>
          <span className="text-[12px] text-ink-3 flex items-center gap-1"><MapPin size={13} />{L('المقر الرئيسي — دمشق، ضمن النطاق الجغرافي', 'Damascus HQ — inside geofence')}</span>
        </div>}
      </Panel>
      <Panel title={L('حالات تأخير بحاجة لتبرير', 'Late days to justify')} pad={false}>
        {lateDays.length === 0 ? <Empty title={L('لا توجد حالات بحاجة لتبرير', 'Nothing to justify')} /> : <ul className="divide-y divide-line-2">{lateDays.map(d => { const m = calcDay(d.date, d.inAt).late
          return <li key={d.date} className="flex items-center justify-between px-4 py-2.5"><span className="text-[14px]">{fmtDate(d.date, lang)} — <span className="text-warn num">{minsLabel(m, lang)}</span></span><Btn size="sm" onClick={() => setJustify({ date: d.date, minutes: m })}>{L('تقديم تبرير', 'Justify')}</Btn></li> })}</ul>}
      </Panel>
    </div>
    <Panel title={L('آخر أسبوعين', 'Last two weeks')} pad={false}><div className="scroll-x"><AttendanceTable empId={e.id} /></div></Panel>
    <Modal open={!!justify} onClose={() => setJustify(null)} title={L('تبرير تأخير', 'Late justification')} footer={<><Btn onClick={() => setJustify(null)}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" onClick={() => { requestLeave({ empId: e.id, type: 'justify', from: justify!.date, to: justify!.date, refDate: justify!.date, minutes: justify!.minutes, reason: reason || L('ازدحام مروري', 'Traffic') }); setJustify(null); setReason(''); toast(L('أُرسل التبرير لمديرك المباشر', 'Sent to your manager')) }}>{L('إرسال', 'Send')}</Btn></>}>
      <p className="text-[14px] mb-3">{justify && fmtDate(justify.date, lang)} — {L('تأخير', 'late')} {justify && minsLabel(justify.minutes, lang)}</p>
      <Field label={L('سبب التأخير', 'Reason')}><textarea className="field" value={reason} onChange={x => setReason(x.target.value)} /></Field>
    </Modal>
  </div>
}

export function MyRequests() {
  const L = useL(); const lang = useLang(); const e = useMe(); const dn = useDisplayName()
  const mine = useStore(s => s.leaves.filter(l => l.empId === e.id))
  const [t, setT] = useState<LeaveType>('annual'); const [from, setFrom] = useState(addDays(todayISO(), 3)); const [to, setTo] = useState(addDays(todayISO(), 4)); const [hours, setHours] = useState(2); const [reason, setReason] = useState('')
  const ent = annualEntitlement(e)
  const wdays = (a: string, b: string) => { let n = 0; for (let d = a; d <= b; d = addDays(d, 1)) if (!isWeekend(d)) n++; return n }
  const held = mine.filter(l => l.type === 'annual' && l.status.startsWith('pending')).reduce((a, l) => a + wdays(l.from, l.to), 0)
  const bal = ent - e.leaveUsed - held
  const wd = wdays(from, t === 'hourly' ? from : to)
  const clash = t !== 'hourly' && mine.some(l => l.type !== 'justify' && l.type !== 'hourly' && l.status !== 'rejected' && l.from <= to && l.to >= from)
  const over = (t === 'annual' && wd > bal) || clash
  return <div className="max-w-[1100px] mx-auto">
    <PageHead title={L('طلباتي', 'My requests')} sub={L(`رصيدك من الإجازة السنوية: ${bal} من ${ent} يوماً`, `Annual leave balance: ${bal} of ${ent} days`)} />
    <div className="grid lg:grid-cols-[380px_1fr] gap-4 items-start">
      <Panel title={L('طلب جديد', 'New request')}>
        <div className="space-y-3">
          <Field label={L('نوع الطلب', 'Type')}><select className="field" value={t} onChange={x => setT(x.target.value as LeaveType)}>{(['annual', 'sick', 'hourly', 'mission', 'unpaid'] as const).map(k => <option key={k} value={k}>{LEAVE_TYPES[k][lang]}</option>)}</select></Field>
          {t === 'hourly' ? <div className="grid grid-cols-2 gap-3"><Field label={L('التاريخ', 'Date')}><input type="date" className="field" value={from} onChange={x => { setFrom(x.target.value); setTo(x.target.value) }} /></Field><Field label={L('عدد الساعات', 'Hours')}><input type="number" min={1} max={4} className="field num" value={hours} onChange={x => setHours(Number(x.target.value))} /></Field></div>
            : <div className="grid grid-cols-2 gap-3"><Field label={L('من', 'From')}><input type="date" className="field" value={from} onChange={x => { setFrom(x.target.value); if (x.target.value > to) setTo(x.target.value) }} /></Field><Field label={L('إلى', 'To')}><input type="date" className="field" value={to} min={from} onChange={x => setTo(x.target.value)} /></Field></div>}
          {t !== 'hourly' && <div className={`text-[13px] ${over ? 'text-danger' : 'text-ink-3'}`}>{L(`${wd} أيام عمل`, `${wd} working days`)}{clash ? L(' — يتقاطع مع طلب آخر لديك في نفس الفترة', ' — overlaps another request of yours') : over && L(' — يتجاوز رصيدك المتاح', ' — exceeds your balance')}</div>}
          {held > 0 && t === 'annual' && <div className="text-[12px] text-ink-3">{L(`${held} أيام محجوزة لطلبات قيد الاعتماد`, `${held} days held by pending requests`)}</div>}
          <Field label={L('السبب', 'Reason')}><textarea className="field" value={reason} onChange={x => setReason(x.target.value)} /></Field>
          <Btn kind="primary" className="w-full" disabled={over} onClick={() => { requestLeave({ empId: e.id, type: t, from, to: t === 'hourly' ? from : to, hours: t === 'hourly' ? hours : undefined, reason: reason || LEAVE_TYPES[t][lang] }); setReason(''); toast(L('أُرسل الطلب لمديرك المباشر', 'Sent to your manager')) }}>{L('إرسال الطلب', 'Submit request')}</Btn>
        </div>
      </Panel>
      <Panel title={L('طلباتي السابقة', 'My requests')} pad={false}>{mine.length === 0 ? <Empty title={L('لا توجد طلبات بعد', 'No requests yet')} /> : <table className="tbl"><thead><tr><th>{L('النوع', 'Type')}</th><th>{L('الفترة', 'Period')}</th><th>{L('المسار', 'Path')}</th><th>{L('الحالة', 'Status')}</th></tr></thead><tbody>
        {mine.map(l => <tr key={l.id}><td>{LEAVE_TYPES[l.type][lang]}</td><td className="num">{fmtDate(l.from, lang)}{l.to !== l.from && ` — ${fmtDate(l.to, lang)}`}</td><td className="text-[12px]">{l.chain.map((c, i) => <div key={i} className={c.decision === 'approved' ? 'text-brand' : c.decision === 'rejected' ? 'text-danger' : 'text-ink-3'}>{c.role[lang]}{c.by ? ` — ${dn(c.by)}` : ''}</div>)}</td><td><LeaveStatus s={l.status} /></td></tr>)}</tbody></table>}</Panel>
    </div>
  </div>
}

export function MyDocs() {
  const L = useL(); const lang = useLang(); const e = useMe()
  const pending = useStore(s => s.assets.filter(a => a.empId === e.id && a.status === 'pending_ack'))
  const signed = useStore(s => s.assets.filter(a => a.empId === e.id && a.status === 'assigned'))
  const [sigs, setSigs] = useState<Record<string, string | undefined>>({}); const [mother, setMother] = useState('')
  const relaxed = useRelaxed()
  const ob = e.onboarding
  return <div className="max-w-[1000px] mx-auto space-y-5">
    <PageHead title={L('وثائق للتوقيع', 'Documents to sign')} sub={L('التوقيع الإلكتروني هنا له نفس أثر التوقيع الورقي ويُحفظ في ملفك.', 'E-signatures here are stored in your file like paper signatures.')} />
    {pending.map(a => <Paper key={a.id} code="" title={L('سند استلام عهدة', 'Custody receipt')}>
      <table className="w-full border border-line text-[14px] mb-4"><tbody>{[[L('الأصل', 'Asset'), a.name[lang]], [L('الرمز', 'Tag'), a.tag], [L('الرقم التسلسلي', 'Serial'), a.serial], [L('القيمة التقديرية', 'Value'), `$${a.value}`]].map(([k, v]) => <tr key={k}><td className="border border-line px-3 py-2 bg-sub w-1/3">{k}</td><td className="border border-line px-3 py-2 num">{v}</td></tr>)}</tbody></table>
      <p className="text-[14px] mb-3 max-w-[70ch]">{L('أقر باستلام الأصل المذكور أعلاه بحالة سليمة، وأتعهد بالمحافظة عليه وإعادته عند الطلب أو عند انتهاء خدمتي، وأتحمل قيمته في حال الفقدان أو التلف الناتج عن سوء الاستخدام.', 'I confirm receipt of the asset in good condition and undertake to keep it and return it on request or at end of service, bearing its value if lost or damaged through misuse.')}</p>
      <div className="grid sm:grid-cols-[1fr_auto] gap-4 items-end"><SignaturePad value={sigs[a.id]} onChange={v => setSigs({ ...sigs, [a.id]: v })} /><Btn kind="primary" disabled={!relaxed && !sigs[a.id]} onClick={() => { ackAsset(a.id, sigs[a.id] ?? autoSig(e.name)); toast(L('وُقّع سند الاستلام', 'Receipt signed')) }}>{L('توقيع الاستلام', 'Sign receipt')}</Btn></div>
    </Paper>)}
    {ob && <Paper code="F-22" title={L('تعهد المحافظة على سرية العمل', 'Confidentiality undertaking')}>
      <ol className="list-decimal ps-5 space-y-2 text-[14px] max-w-[72ch]">
        <li>{L('أتعهد بعدم إفشاء أي معلومات أو بيانات أو أسرار عمل خاصة بشركة الفرادة، أثناء الخدمة أو بعدها.', 'I will not disclose any company information or trade secrets during or after employment.')}</li>
        <li>{L('أوافق على اقتطاع 10٪ شهرياً لمدة 3 سنوات كضمانة لحسن إدارة العهدة والالتزام بالسرية، تُسترد بموافقة الإدارة.', 'I accept a 10% monthly retention for 3 years as a custody and confidentiality guarantee, refundable on management approval.')}</li>
        <li>{L('ألتزم باستخدام قنوات التواصل الرسمية للشركة وعدم استغلال اسمها لأغراض شخصية.', 'I will use official channels only and not use the company name for personal purposes.')}</li>
      </ol>
      <div className="mt-4">{ob.f22 ? <SigBox label={L('توقيعك', 'Your signature')} name={e.name} sig={ob.f22.sig} at={ob.f22.at} /> : <div className="grid sm:grid-cols-[1fr_auto] gap-4 items-end"><SignaturePad value={sigs.f22} onChange={v => setSigs({ ...sigs, f22: v })} /><Btn kind="primary" disabled={!relaxed && !sigs.f22} onClick={() => { signPledge(e.id, 'f22', sigs.f22 ?? autoSig(e.name)); toast(L('وُقّع التعهد', 'Signed')) }}>{L('توقيع التعهد', 'Sign')}</Btn></div>}</div>
    </Paper>}
    {ob && <Paper code="F-20" title={L('إقرار إبراء الذمة الوقائي', 'Precautionary clearance declaration')}>
      <p className="text-[14px] max-w-[72ch]">{L('أقر بكامل أهليتي القانونية بإبراء ذمة شركة الفرادة إبراءً شاملاً بعد استلام كافة حقوقي المالية والمكافآت وبدلات الإجازات عند انتهاء خدمتي.', 'I declare full release of the company upon receiving all my financial dues, bonuses and leave allowances at end of service.')}</p>
      {ob.f20 ? <div className="mt-4"><SigBox label={L('توقيعك', 'Your signature')} name={e.name} sig={ob.f20.sig} at={ob.f20.at} /></div> : <div className="mt-4 space-y-3"><Field label={L('اسم الأم الكامل', "Mother's full name")} className="max-w-sm"><input className="field" value={mother} onChange={x => setMother(x.target.value)} /></Field>
        <div className="grid sm:grid-cols-[1fr_auto] gap-4 items-end"><SignaturePad value={sigs.f20} onChange={v => setSigs({ ...sigs, f20: v })} /><Btn kind="primary" disabled={!relaxed && (!sigs.f20 || !mother.trim())} onClick={() => { signPledge(e.id, 'f20', sigs.f20 ?? autoSig(e.name), mother || '—'); toast(L('وُقّع الإقرار', 'Signed')) }}>{L('توقيع الإقرار', 'Sign')}</Btn></div></div>}
    </Paper>}
    {!ob && pending.length === 0 && <Panel><Empty title={L('لا توجد وثائق بانتظار توقيعك', 'Nothing waiting for your signature')} /></Panel>}
    {signed.length > 0 && <Panel title={L('عهد موقّعة', 'Signed custody')} pad={false}><ul className="divide-y divide-line-2">{signed.map(a => <li key={a.id} className="flex items-center gap-3 px-4 py-2.5 text-[14px]"><Check size={16} className="text-brand" /><span className="flex-1">{a.name[lang]}</span><span className="ltr num text-ink-3">{a.tag}</span></li>)}</ul></Panel>}
  </div>
}

export function MyResign() {
  const L = useL(); const lang = useLang(); const e = useMe()
  const r = useStore(s => s.resignations.find(x => x.empId === e.id && x.status !== 'rejected'))
  const [key, setKey] = useState('offer'); const [last, setLast] = useState(addDays(todayISO(), 30))
  const [text, setText] = useState(L('أتقدم إلى إدارة شركة الفرادة بطلب قبول استقالتي من منصبي لظروف خاصة، مع التزامي بكامل بنود السرية المستمرة وإكمال فترة الإخطار وتسليم المهام.', 'I hereby submit my resignation for personal reasons, committing to ongoing confidentiality, completing the notice period and handing over my tasks.'))
  const [confirm, setConfirm] = useState(false)
  if (r) return <div className="max-w-[900px] mx-auto">
    <PageHead title={L('طلب الاستقالة', 'Resignation')} sub={L(`آخر يوم عمل: ${fmtDate(r.lastDay, 'ar')}`, `Last working day: ${fmtDate(r.lastDay, 'en')}`)} />
    <Panel>
      <p className="text-[15px] leading-7 bg-canvas rounded-md p-3">{r.text}</p>
      <ol className="mt-4 space-y-2">{[...r.chain.map(c => ({ t: c.role[lang], done: !!c.at, by: c.by })), { t: L('إبراء الذمة', 'Clearance'), done: r.status === 'closed', by: '' }, { t: L('المخالصة وإنهاء الخدمة', 'Settlement & exit'), done: r.status === 'closed', by: '' }].map((s, i) =>
        <li key={i} className="flex items-center gap-3 text-[14px]"><span className={`size-6 rounded-full grid place-items-center ${s.done ? 'bg-brand text-on-brand' : 'border border-line text-ink-3'}`}>{s.done ? <Check size={13} /> : i + 1}</span>{s.t}{s.by && <span className="text-ink-3">— {s.by}</span>}</li>)}</ol>
      {r.status === 'clearance' && <p className="mt-4 text-[13px] text-ink-2">{L('يرجى تسليم العهد إلى الشؤون الإدارية وإنهاء تسليم المهام قبل آخر يوم عمل.', 'Please return your custody items and finish handover before your last day.')}</p>}
    </Panel>
  </div>
  return <div className="max-w-[900px] mx-auto">
    <PageHead title={L('الاستقالة', 'Resignation')} sub={L('يمر الطلب على مديرك المباشر ثم الموارد البشرية، ثم إجراءات إبراء الذمة.', 'Goes to your manager, then HR, then clearance.')} />
    <Paper code="F-26" title={L('نموذج طلب الاستقالة', 'Resignation request')}>
      <div className="grid sm:grid-cols-2 gap-4">
        <Field label={L('السبب الرئيسي', 'Main reason')}><select className="field" value={key} onChange={x => setKey(x.target.value)}>{Object.entries(REASONS).map(([k, v]) => <option key={k} value={k}>{v[lang === 'ar' ? 0 : 1]}</option>)}</select></Field>
        <Field label={L('آخر يوم عمل مقترح', 'Proposed last day')} hint={L('فترة الإخطار المعتادة 30 يوماً', 'Usual notice: 30 days')}><input type="date" className="field" value={last} min={todayISO()} onChange={x => setLast(x.target.value)} /></Field>
        <Field label={L('نص الاستقالة', 'Letter')} className="sm:col-span-2"><textarea className="field !min-h-[110px]" value={text} onChange={x => setText(x.target.value)} /></Field>
      </div>
      <div className="mt-4" data-noprint><Btn kind="danger" onClick={() => setConfirm(true)}>{L('تقديم الاستقالة', 'Submit resignation')}</Btn></div>
    </Paper>
    <Modal open={confirm} onClose={() => setConfirm(false)} title={L('تأكيد تقديم الاستقالة', 'Confirm resignation')} footer={<><Btn onClick={() => setConfirm(false)}>{L('تراجع', 'Go back')}</Btn><Btn kind="danger" onClick={() => { submitResignation(e.id, last, key, text); setConfirm(false) }}>{L('تقديم', 'Submit')}</Btn></>}>
      <p className="text-[14px] text-ink-2">{L('سيصل الطلب لمديرك المباشر فوراً. يمكن سحبه بالتفاهم مع الإدارة قبل اعتماده.', 'Your manager will be notified immediately. It can be withdrawn with management before approval.')}</p>
    </Modal>
  </div>
}
