import { useState } from 'react'
import { CheckCircle2, Loader2, PlayCircle, ShieldAlert, XCircle, Clock3, Radio, Users } from 'lucide-react'
import { useL, useLang, bi, fmtDateTime } from '../../../lib/store'
import { setQuery, useRoute } from '../../../lib/router'
import { DECISION, REASON, useNfEvents, useNfcReport, useReviewEvent, useSimulate, type NfEventRow } from '../../../lib/nfc'
import { ApiError } from '../../../lib/api'
import { Badge, Btn, Empty, Field, Modal, Panel, Seg, toast } from '../../../ui/kit'

const today = () => new Date().toISOString().slice(0, 10)
const daysAgo = (n: number) => new Date(Date.now() - n * 864e5).toISOString().slice(0, 10)

/**
 * بوابة الحضور متعددة الفروع (الدفعة 21): كل قراءة من كل جهاز كما وصلت مع القرار وسببه،
 * ومراجعة البصمات «خارج الفرع»، والتقارير (الفروع، الأجهزة، المرفوض، البصمات المتلاصقة)، ومحاكاة القرار.
 */
export function GateView() {
  const L = useL()
  const route = useRoute()
  const sub = (['report', 'simulate'] as const).find(v => v === route.query.gate) ?? 'events'
  return <div className="space-y-4">
    <Seg value={sub} onChange={v => setQuery({ gate: v === 'events' ? undefined : v })} options={[{ v: 'events', label: L('سجل الأحداث', 'Event log') }, { v: 'report', label: L('التقارير', 'Reports') }, { v: 'simulate', label: L('محاكاة القرار', 'Decision simulator') }]} />
    {sub === 'events' ? <Events /> : sub === 'report' ? <Report /> : <Simulate />}
  </div>
}

function Events() {
  const L = useL(); const lang = useLang()
  const route = useRoute(); const q = route.query
  const f = { from: q.from ?? daysAgo(6), to: q.to ?? today(), decision: q.decision, branch: q.gbranch ? q.gbranch : undefined, device: q.device, q: q.q, page: q.page }
  const { data } = useNfEvents({ ...f, branch: f.branch })
  const [rev, setRev] = useState<NfEventRow | null>(null)
  const set = (o: Record<string, string | undefined>) => setQuery({ ...o, page: undefined })
  const pages = data ? Math.max(1, Math.ceil(data.total / 50)) : 1
  return <>
    <div className="flex flex-wrap gap-2">
      <select className="field !w-full sm:!w-40" value={f.decision ?? ''} onChange={e => set({ decision: e.target.value || undefined })} aria-label={L('القرار', 'Decision')}>
        <option value="">{L('كل القرارات', 'All decisions')}</option>
        <option value="pending">{L('بانتظار المراجعة', 'Awaiting review')}{data?.pending ? ` (${data.pending})` : ''}</option>
        {(Object.keys(DECISION) as (keyof typeof DECISION)[]).map(k => <option key={k} value={k}>{L(DECISION[k][0], DECISION[k][1])}</option>)}</select>
      <select className="field !w-full sm:!w-44" value={f.branch ?? ''} onChange={e => set({ gbranch: e.target.value || undefined })} aria-label={L('الفرع', 'Branch')}>
        <option value="">{L('كل الفروع', 'All branches')}</option>{data?.branches.map(b => <option key={b.id} value={b.id}>{bi(b, lang)}</option>)}</select>
      <select className="field !w-full sm:!w-44" value={f.device ?? ''} onChange={e => set({ device: e.target.value || undefined })} aria-label={L('الجهاز', 'Device')}>
        <option value="">{L('كل الأجهزة', 'All devices')}</option>{data?.devices.map(d => <option key={d.id} value={d.id}>{bi(d, lang)}</option>)}</select>
      <input className="field !w-[calc(50%-4px)] sm:!w-40" type="date" value={f.from} max={f.to} onChange={e => set({ from: e.target.value || undefined })} aria-label={L('من', 'From')} />
      <input className="field !w-[calc(50%-4px)] sm:!w-40" type="date" value={f.to} max={today()} onChange={e => set({ to: e.target.value || undefined })} aria-label={L('إلى', 'To')} />
      <input className="field !w-full sm:!w-52" placeholder={L('اسم، رمز، رقم بطاقة…', 'Name, code, card…')} defaultValue={f.q ?? ''} onKeyDown={e => { if (e.key === 'Enter') set({ q: (e.target as HTMLInputElement).value || undefined }) }} />
    </div>
    {data && <div className="grid grid-cols-2 md:grid-cols-4 gap-px bg-line border border-line rounded-lg overflow-hidden">
      {(Object.keys(DECISION) as (keyof typeof DECISION)[]).map(k => <button key={k} onClick={() => set({ decision: f.decision === k ? undefined : k })} className={`bg-surface p-3 text-start hover:bg-sub ${f.decision === k ? 'ring-2 ring-inset ring-brand' : ''}`}>
        <div className="text-[12px] text-ink-3">{L(DECISION[k][0], DECISION[k][1])}</div><div className="text-[22px] font-black num">{data.counts[k] ?? 0}</div></button>)}
    </div>}
    <Panel pad={false}>{!data ? <div className="grid place-items-center py-12"><Loader2 className="animate-spin text-brand" /></div> : data.data.length === 0 ? <Empty title={L('لا توجد أحداث', 'No events')}>{L('تظهر هنا كل قراءة تصل من أجهزة الحضور مع قرارها.', 'Every read from the attendance devices appears here with its decision.')}</Empty> :
      <table className="tbl"><thead><tr><th>{L('الوقت', 'Time')}</th><th>{L('الشخص', 'Person')}</th><th className="hidden md:table-cell">{L('الجهاز والفرع', 'Device & branch')}</th><th>{L('القرار', 'Decision')}</th><th /></tr></thead>
        <tbody>{data.data.map(e => <tr key={e.id}>
          <td className="num text-[13px]">{fmtDateTime(e.at, lang)}{e.direction && <div className="text-ink-3">{e.direction === 'in' ? L('دخول', 'In') : L('خروج', 'Out')}</div>}
            {e.late_upload && <div className="text-[11.5px] text-info flex items-center gap-1"><Clock3 size={11} />{L('وصل متأخراً', 'Uploaded late')}</div>}</td>
          <td>{e.employee ? <><div className="font-medium">{bi(e.employee, lang)}</div><div className="text-[12px] text-ink-3 ltr inline-block">{e.employee.code}</div></> : <span className="text-ink-3 ltr inline-block">{e.identifier ?? '—'}</span>}
            <div className="md:hidden text-[12px] text-ink-3">{e.device ? bi(e.device, lang) : ''}{e.branch ? ` · ${bi(e.branch, lang)}` : ''}</div></td>
          <td className="hidden md:table-cell text-[13px]">{e.device ? bi(e.device, lang) : '—'}<div className="text-ink-3">{e.branch ? bi(e.branch, lang) : ''}{e.mission && <> · <span className="ltr inline-block">{e.mission.ref}</span></>}</div></td>
          <td><Badge tone={DECISION[e.decision][2]}>{L(DECISION[e.decision][0], DECISION[e.decision][1])}</Badge>
            {e.reason && <div className="text-[12px] text-ink-3 mt-1">{REASON[e.reason] ? L(REASON[e.reason][0], REASON[e.reason][1]) : e.reason}</div>}
            {e.review && e.review !== 'pending' && <div className="text-[11.5px] text-ink-3">{e.review === 'accepted' ? L('قُبل بعد المراجعة', 'Accepted on review') : L('رُفض بعد المراجعة', 'Rejected on review')}</div>}</td>
          <td>{e.review === 'pending' && data.can.review && <Btn size="sm" onClick={() => setRev(e)}>{L('مراجعة', 'Review')}</Btn>}</td>
        </tr>)}</tbody></table>}</Panel>
    {data && pages > 1 && <div className="flex justify-center items-center gap-2 text-[13px]">
      <Btn size="sm" disabled={data.page <= 1} onClick={() => setQuery({ page: String(data.page - 1) })}>{L('السابق', 'Previous')}</Btn>
      <span className="num">{data.page} / {pages}</span>
      <Btn size="sm" disabled={data.page >= pages} onClick={() => setQuery({ page: String(data.page + 1) })}>{L('التالي', 'Next')}</Btn></div>}
    {rev && <ReviewModal e={rev} onClose={() => setRev(null)} />}
  </>
}

function ReviewModal({ e, onClose }: { e: NfEventRow; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const m = useReviewEvent()
  const [note, setNote] = useState('')
  const err = m.error instanceof ApiError ? m.error : null
  const go = (accept: boolean) => m.mutate({ id: e.id, accept, note: note || undefined }, { onSuccess: () => { toast(accept ? L('اعتُمدت البصمة', 'Punch kept') : L('أُلغيت البصمة وأُعيد حساب اليوم', 'Punch removed and the day recalculated')); onClose() } })
  return <Modal open onClose={onClose} width={520} title={L('مراجعة بصمة خارج الفرع', 'Review an off-branch punch')}
    footer={<><Btn kind="ghost" onClick={onClose}>{L('إغلاق', 'Close')}</Btn><Btn kind="danger" icon={<XCircle size={15} />} disabled={m.isPending} onClick={() => go(false)}>{L('رفض وإلغاء الحضور', 'Reject & remove')}</Btn>
      <Btn kind="primary" icon={<CheckCircle2 size={15} />} disabled={m.isPending} onClick={() => go(true)}>{L('قبول', 'Accept')}</Btn></>}>
    <div className="text-[14px] space-y-1"><div className="font-semibold">{e.employee ? bi(e.employee, lang) : '—'}</div>
      <div className="text-ink-3 num">{fmtDateTime(e.at, lang)} · {e.device ? bi(e.device, lang) : ''}{e.branch ? ` · ${bi(e.branch, lang)}` : ''}</div>
      <p className="text-ink-2 mt-2">{L('بصم الموظف في فرع ليس فرعه الأساسي ولا ضمن فروعه المصرّح بها ولا لديه مهمة معتمدة إليه. سُجّل حضوره مؤقتاً حتى تراجعه.', 'The employee punched at a branch that is not their home branch, an authorised branch or an approved mission destination. Attendance was recorded pending your review.')}</p></div>
    <Field label={L('ملاحظة (مطلوبة عند الرفض)', 'Note (required to reject)')} error={err?.field('note')}><textarea className="field" rows={2} value={note} onChange={x => setNote(x.target.value)} /></Field>
  </Modal>
}

function Report() {
  const L = useL(); const lang = useLang()
  const [from, setFrom] = useState(daysAgo(29)); const [to, setTo] = useState(today())
  const { data } = useNfcReport(from, to)
  const cnt = (o: Record<string, unknown>, k: string) => Number(o[k] ?? 0)
  return <div className="space-y-4">
    <div className="flex flex-wrap gap-2"><input className="field !w-[calc(50%-4px)] sm:!w-40" type="date" value={from} max={to} onChange={e => setFrom(e.target.value)} aria-label={L('من', 'From')} />
      <input className="field !w-[calc(50%-4px)] sm:!w-40" type="date" value={to} max={today()} onChange={e => setTo(e.target.value)} aria-label={L('إلى', 'To')} /></div>
    {!data ? <div className="grid place-items-center py-12"><Loader2 className="animate-spin text-brand" /></div> : <>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(320px,1fr))]">
        <Panel title={L('حسب الفرع', 'By branch')}>{data.by_branch.length === 0 ? <p className="text-ink-3 text-[13px]">{L('لا أحداث في الفترة.', 'No events in the period.')}</p> :
          <table className="tbl"><thead><tr><th>{L('الفرع', 'Branch')}</th><th>{L('مقبول', 'Accepted')}</th><th>{L('للمراجعة', 'Review')}</th><th>{L('مرفوض', 'Rejected')}</th></tr></thead>
            <tbody>{data.by_branch.map((r, i) => <tr key={i}><td>{r.branch ? bi(r.branch, lang) : L('دون فرع', 'No branch')}</td><td className="num">{cnt(r, 'accepted')}</td><td className="num">{cnt(r, 'flagged')}</td><td className="num">{cnt(r, 'rejected')}</td></tr>)}</tbody></table>}</Panel>
        <Panel title={L('حسب الجهاز', 'By device')}>{data.by_device.length === 0 ? <p className="text-ink-3 text-[13px]">{L('لا أحداث في الفترة.', 'No events in the period.')}</p> :
          <table className="tbl"><thead><tr><th>{L('الجهاز', 'Device')}</th><th>{L('مقبول', 'Accepted')}</th><th>{L('مرفوض', 'Rejected')}</th><th>{L('مكرر', 'Dup.')}</th></tr></thead>
            <tbody>{data.by_device.map((r, i) => <tr key={i}><td>{r.device ? bi(r.device, lang) : '—'}</td><td className="num">{cnt(r, 'accepted') + cnt(r, 'flagged')}</td><td className="num">{cnt(r, 'rejected')}</td><td className="num">{cnt(r, 'duplicate')}</td></tr>)}</tbody></table>}</Panel>
        <Panel title={L('الأحداث المرفوضة حسب السبب', 'Rejected events by reason')}>{Object.keys(data.rejected).length === 0 ? <p className="text-ink-3 text-[13px]">{L('لا يوجد.', 'None.')}</p> :
          <div className="space-y-2">{Object.entries(data.rejected).map(([k, n]) => <div key={k} className="flex justify-between text-[14px]"><span>{REASON[k] ? L(REASON[k][0], REASON[k][1]) : k}</span><span className="num font-semibold">{n}</span></div>)}</div>}</Panel>
        <Panel title={<span className="flex items-center gap-2"><Radio size={15} />{L('حالة الأجهزة', 'Device status')}</span>}>
          <div className="space-y-2">{data.devices.map(d => <div key={d.id} className="flex justify-between gap-2 text-[13.5px]"><span>{bi(d, lang)}<span className="text-ink-3">{d.branch ? ` · ${bi(d.branch, lang)}` : ''}</span></span>
            <span className="flex items-center gap-2">{d.drift !== null && Math.abs(d.drift) > 60 && <span className="text-warn num">{d.drift}s</span>}{!d.active ? <Badge>{L('موقوف', 'Disabled')}</Badge> : d.status === 'pending' ? <Badge tone="info">{L('بانتظار التفعيل', 'Awaiting activation')}</Badge> : d.online ? <Badge tone="brand" dot>{L('متصل', 'Online')}</Badge> : <Badge tone="warn">{L('منقطع', 'Offline')}</Badge>}</span></div>)}</div></Panel>
      </div>
      <Panel title={<span className="flex items-center gap-2"><ShieldAlert size={15} />{L('بصمات خارج الفرع الأساسي', 'Punches outside the home branch')}</span>} pad={false}>
        {data.off_branch.length === 0 ? <div className="p-4 text-ink-3 text-[13px]">{L('لا يوجد.', 'None.')}</div> :
          <table className="tbl"><tbody>{data.off_branch.map((r, i) => <tr key={i}><td className="num text-[13px]">{fmtDateTime(r.at, lang)}</td><td>{r.employee ? bi(r.employee, lang) : '—'}</td><td className="hidden sm:table-cell">{r.branch ? bi(r.branch, lang) : ''}</td>
            <td><Badge tone={DECISION[r.decision][2]}>{L(DECISION[r.decision][0], DECISION[r.decision][1])}</Badge></td></tr>)}</tbody></table>}</Panel>
      <Panel title={<span className="flex items-center gap-2"><Users size={15} />{L('بصمات متلاصقة متكررة', 'Repeated back-to-back punches')}</span>}>
        <p className="text-[12.5px] text-ink-3 mb-2">{L('بطاقتان مختلفتان على الجهاز نفسه خلال ثوانٍ، أكثر من مرة — قد تشير إلى بصمة زميل عن زميل.', 'Two different cards on the same device within seconds, more than once — may indicate one colleague punching for another.')}</p>
        {data.buddies.length === 0 ? <p className="text-ink-3 text-[13px]">{L('لا يوجد.', 'None.')}</p> : <div className="space-y-1.5">{data.buddies.map((b, i) => <div key={i} className="flex justify-between text-[14px]"><span>{bi(b, lang)}</span><span className="num font-semibold">{b.n}×</span></div>)}</div>}</Panel>
    </>}
  </div>
}

function Simulate() {
  const L = useL(); const lang = useLang()
  const ev = useNfEvents({ from: today(), to: today() })
  const sim = useSimulate()
  const [dev, setDev] = useState(''); const [card, setCard] = useState(''); const [at, setAt] = useState('')
  const r = sim.data
  return <Panel title={L('محاكاة القرار', 'Decision simulator')} sub={L('اختر جهازاً واكتب رقم بطاقة لترى ما سيقرره النظام، دون تسجيل أي حضور.', 'Pick a device and type a card number to see what the system would decide — nothing is recorded.')}>
    <div className="grid gap-3 sm:grid-cols-3">
      <Field label={L('الجهاز الذي قرأ', 'Reading device')}><select className="field" value={dev} onChange={e => setDev(e.target.value)}><option value="">—</option>{ev.data?.devices.map(d => <option key={d.id} value={d.id}>{bi(d, lang)}</option>)}</select></Field>
      <Field label={L('رقم البطاقة المقروءة', 'Card number read')}><input className="field ltr" value={card} onChange={e => setCard(e.target.value)} placeholder="04:A1:B2:C3:D4" /></Field>
      <Field label={L('الوقت (اختياري)', 'Time (optional)')}><input className="field" type="datetime-local" value={at} onChange={e => setAt(e.target.value)} /></Field>
    </div>
    <Btn className="mt-3" kind="primary" icon={<PlayCircle size={15} />} disabled={!dev || !card || sim.isPending} onClick={() => sim.mutate({ device_id: Number(dev), card, at: at || undefined })}>{L('محاكاة', 'Simulate')}</Btn>
    {r && <div className={`mt-4 rounded-lg border p-4 ${r.decision === 'accepted' ? 'border-brand/40 bg-brand/5' : r.decision === 'rejected' ? 'border-danger/40 bg-danger/5' : 'border-warn/40 bg-warn/5'}`}>
      <div className="flex items-center gap-2"><Badge tone={DECISION[r.decision][2]}>{L(DECISION[r.decision][0], DECISION[r.decision][1])}</Badge>{r.reason && <span className="text-[14px]">{REASON[r.reason] ? L(REASON[r.reason][0], REASON[r.reason][1]) : r.reason}</span>}</div>
      <div className="mt-2 text-[14px]">{r.name ? <>{bi(r.name, lang)} <span className="ltr inline-block text-ink-3">{r.name.code}</span></> : <span className="text-ink-3">{L('لا يظهر أي اسم — البحث محصور ببطاقات هذه الشركة.', 'No name is shown — the search is limited to this company\'s cards.')}</span>}</div>
      {r.branch && <div className="text-[13px] text-ink-3">{L('فرع الجهاز', 'Device branch')}: {bi(r.branch, lang)}{r.mission ? ` · ${L('مهمة', 'Mission')} ${r.mission}` : ''}</div>}
    </div>}
  </Panel>
}
