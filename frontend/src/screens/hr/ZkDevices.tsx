import { useState } from 'react'
import { CheckCircle2, Download, Fingerprint, Link2, Loader2, RefreshCw, AlertTriangle } from 'lucide-react'
import { useL, useLang, bi, fmtDateTime } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { downloadBridge, useAddDevice, useZk, useZkMap, useZkResync, type ZkCfg } from '../../lib/attendance'
import { useDirectory } from '../../lib/employees'
import { Badge, Btn, Empty, Field, Modal, Seg, toast } from '../../ui/kit'

export const ZK_DEFAULT: ZkCfg = { ip: '', port: 4370, comm_key: '0', machine_no: 1, card_order: 'normal' }

/** حقول البصّامة: عنوانها في شبكة الشركة، المنفذ، كلمة الاتصال، رقم الجهاز، ترتيب أرقام البطاقات */
export function ZkFields({ v, onChange, err }: { v: ZkCfg; onChange: (v: ZkCfg) => void; err?: ApiError | null }) {
  const L = useL()
  return <div className="grid sm:grid-cols-2 gap-3">
    <Field label={L('عنوان البصّامة في الشبكة (IP)', 'Device IP address')} req error={err?.field('zk.ip')} hint={L('كما في برنامج الحضور، مثل 172.16.1.188', 'As in the attendance program, e.g. 172.16.1.188')}>
      <input className="field ltr text-start num" value={v.ip} onChange={e => onChange({ ...v, ip: e.target.value.trim() })} placeholder="172.16.1.188" /></Field>
    <Field label={L('المنفذ', 'Port')} req error={err?.field('zk.port')}><input className="field ltr text-start num" type="number" value={v.port} onChange={e => onChange({ ...v, port: Number(e.target.value) })} /></Field>
    <Field label={L('كلمة الاتصال (Comm Key)', 'Comm key')} error={err?.field('zk.comm_key')} hint={L('0 إن لم تُضبط على الجهاز', '0 if not set on the device')}><input className="field ltr text-start num" value={v.comm_key} onChange={e => onChange({ ...v, comm_key: e.target.value.replace(/\D/g, '') })} /></Field>
    <Field label={L('رقم الجهاز (Machine No.)', 'Machine no.')} req error={err?.field('zk.machine_no')}><input className="field ltr text-start num" type="number" value={v.machine_no} onChange={e => onChange({ ...v, machine_no: Number(e.target.value) })} /></Field>
    <Field label={L('أرقام البطاقات', 'Card numbers')} div className="sm:col-span-2" hint={L('غيّرها إلى «معكوس» إن لم تتعرف البصّامة على بطاقات النظام.', 'Switch to “reversed” if the device does not recognise the system’s cards.')}>
      <Seg value={v.card_order} onChange={x => onChange({ ...v, card_order: x as ZkCfg['card_order'] })} options={[{ v: 'normal', label: L('عادي', 'Normal') }, { v: 'reversed', label: L('معكوس', 'Reversed') }]} /></Field>
  </div>
}

/** إضافة بصّامة: إنشاء الجهاز وتنزيل الجسر جاهزاً لحاسوب الشبكة */
export function ZkSetup({ locations, onClose }: { locations: { id: number; ar: string; en: string }[]; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const add = useAddDevice()
  const [v, setV] = useState<{ name_ar: string; name_en: string; location_id: number | null }>({ name_ar: 'بصّامة المقر', name_en: 'HQ fingerprint terminal', location_id: locations[0]?.id ?? null })
  const [zk, setZk] = useState<ZkCfg>(ZK_DEFAULT)
  const [done, setDone] = useState(false); const [busy, setBusy] = useState(false)
  const err = add.error instanceof ApiError ? add.error : null
  const go = async () => {
    setBusy(true)
    try { const r = await add.mutateAsync({ ...v, kind: 'zk', zk }); await downloadBridge(r.data.id, true); setDone(true) } catch (x) { if (!(x instanceof ApiError)) toast((x as Error).message) } finally { setBusy(false) }
  }
  return <Modal open onClose={() => !busy && onClose()} width={620} title={<span className="flex items-center gap-2"><Fingerprint size={17} className="text-brand" />{L('بصّامة ZKTeco', 'ZKTeco fingerprint terminal')}</span>}
    footer={done ? <Btn kind="primary" onClick={onClose}>{L('تم', 'Done')}</Btn> : <><Btn onClick={onClose} disabled={busy}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" icon={busy ? <Loader2 size={15} className="animate-spin" /> : <Download size={15} />} disabled={busy || !zk.ip || !v.name_ar.trim() || !v.name_en.trim()} onClick={go}>{L('حفظ وتنزيل الجسر', 'Save and download the bridge')}</Btn></>}>
    {!done ? <div className="space-y-4">
      <p className="text-[13.5px] text-ink-2">{L('البصّامة في شبكة الشركة الداخلية، فيقرأ حركاتها جسر صغير على حاسوب في الشبكة نفسها (الحاسوب الذي عليه برنامج الحضور) ويرسلها للنظام، ويرسل للبصّامة أسماء الموظفين وأرقامهم وبطاقاتهم.', 'The terminal is on the company’s internal network, so a small bridge on a PC in the same network (the one with the attendance program) reads its punches and sends them to the system, and sends employees’ names, numbers and cards to the terminal.')}</p>
      <div className="grid sm:grid-cols-2 gap-3">
        <Field label={L('الاسم', 'Name')} req><input className="field" value={v.name_ar} onChange={e => setV({ ...v, name_ar: e.target.value })} /></Field>
        <Field label={L('الاسم بالإنكليزية', 'Name (EN)')} req><input className="field ltr text-start" value={v.name_en} onChange={e => setV({ ...v, name_en: e.target.value })} /></Field>
        <Field label={L('المقر', 'Location')} className="sm:col-span-2" hint={L('توقيت البصّامة يُقرأ بتوقيت هذا المقر.', 'The terminal’s time is read in this location’s time zone.')}><select className="field" value={v.location_id ?? ''} onChange={e => setV({ ...v, location_id: e.target.value ? Number(e.target.value) : null })}>
          <option value="">—</option>{locations.map(l => <option key={l.id} value={l.id}>{bi(l, lang)}</option>)}</select></Field>
      </div>
      <ZkFields v={zk} onChange={setZk} err={err} />
    </div> : <div className="space-y-3 text-[14px]">
      <div className="flex items-center gap-2 font-semibold text-brand"><CheckCircle2 size={18} />{L('نُزّل الملف UHR-ZK-Bridge.zip', 'UHR-ZK-Bridge.zip downloaded')}</div>
      <ol className="list-decimal ps-5 space-y-1.5 text-ink-2">
        <li>{L('انقله إلى الحاسوب الذي عليه برنامج الحضور، وفك الضغط (Extract).', 'Copy it to the PC with the attendance program and extract it.')}</li>
        <li>{L('دبل كليك على install.bat — مرة واحدة فقط.', 'Double-click install.bat — once.')}</li>
        <li>{L('خلال دقيقة تظهر الحالة هنا «متصل» ويظهر رقم البصّامة التسلسلي، وتُرسل أسماء الموظفين إليها.', 'Within a minute the status here turns “Online” with the terminal’s serial number, and employees are sent to it.')}</li>
        <li>{L('سجّل بصمات الموظفين على البصّامة نفسها (كل موظف برقمه الظاهر في القائمة). كل بصمة تصل للنظام خلال نصف دقيقة تقريباً.', 'Enrol fingerprints on the terminal itself (each employee under their number). Each punch reaches the system within about half a minute.')}</li>
      </ol>
      <p className="text-[12.5px] text-ink-3">{L('يجب أن يبقى الحاسوب شغّالاً. إن انقطع، تبقى الحركات في البصّامة وتصل عند عودته. لا تُبقِ برنامج الحضور «متصلاً» بالبصّامة طوال الوقت.', 'Keep the PC on. If it is off, punches stay on the terminal and arrive when it is back. Do not keep the attendance program connected to the terminal all the time.')}</p>
    </div>}
  </Modal>
}

const VERIFY: Record<number, [string, string]> = { 0: ['كلمة مرور', 'Password'], 1: ['بصمة', 'Fingerprint'], 2: ['بطاقة', 'Card'], 4: ['بطاقة', 'Card'], 15: ['وجه', 'Face'] }

/** لوحة البصّامة: حالة الاتصال، آخر الحركات، الأرقام غير المربوطة وربطها، إعادة إرسال الموظفين */
export function ZkPanel({ id, onClose }: { id: number; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const q = useZk(id); const d = q.data
  const map = useZkMap(); const resync = useZkResync()
  const emps = useDirectory({ q: '', dept: '', status: 'current' })
  const [pick, setPick] = useState<Record<string, string>>({})
  const s = d?.data.last_sync
  return <Modal open onClose={onClose} width={760} title={<span className="flex items-center gap-2"><Fingerprint size={17} className="text-brand" />{d ? bi(d.data.name, lang) : L('البصّامة', 'Terminal')}</span>}
    footer={<><Btn icon={<RefreshCw size={14} />} disabled={resync.isPending} onClick={() => resync.mutate(id, { onSuccess: () => toast(L('سيُعاد إرسال الموظفين للبصّامة خلال دقيقة', 'Employees will be re-sent to the terminal within a minute')) })}>{L('إعادة إرسال الموظفين', 'Re-send employees')}</Btn>
      <Btn icon={<Download size={14} />} onClick={() => downloadBridge(id, true).then(() => toast(L('نُزّل الجسر بمفتاح جديد — ثبّته مجدداً على الحاسوب', 'Bridge downloaded with a new key — install it again on the PC')))}>{L('تنزيل الجسر', 'Download bridge')}</Btn>
      <span className="flex-1" /><Btn kind="primary" onClick={onClose}>{L('إغلاق', 'Close')}</Btn></>}>
    {!d ? <div className="grid place-items-center py-12"><Loader2 className="animate-spin text-brand" /></div> : <div className="space-y-4">
      <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(160px,1fr))]">
        <Stat t={L('الجسر', 'Bridge')} v={d.data.online ? <Badge tone="brand" dot>{L('متصل', 'Online')}</Badge> : <Badge tone="warn">{L('غير متصل', 'Offline')}</Badge>} />
        <Stat t={L('البصّامة', 'Terminal')} v={s?.ok === false ? <Badge tone="danger">{L('تعذّر الوصول', 'Unreachable')}</Badge> : s?.ok ? <Badge tone="brand">{L('تعمل', 'Working')}</Badge> : <Badge>{L('لم تتصل بعد', 'Not yet')}</Badge>} />
        <Stat t={L('حركات اليوم', 'Punches today')} v={<span className="num font-bold text-[18px]">{d.today}</span>} />
        <Stat t={L('الرقم التسلسلي', 'Serial')} v={<span className="ltr num text-[13px]">{s?.info?.serial || '—'}</span>} />
      </div>
      <div className="text-[12.5px] text-ink-3 ltr text-start">{d.data.zk?.ip}:{d.data.zk?.port}{d.data.last_sync_at && <> · {L('آخر إرسال', 'Last upload')} {fmtDateTime(d.data.last_sync_at, lang)}</>}</div>
      {s?.ok === false && s.error && <div className="rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2 flex gap-2"><AlertTriangle size={16} className="shrink-0 mt-0.5" /><span>{L('الجسر لا يصل للبصّامة: تأكد من عنوانها ومنفذها، وأنها في شبكة الحاسوب نفسها، وأن برنامج الحضور غير متصل بها الآن.', 'The bridge cannot reach the terminal: check its IP and port, that it is on the PC’s network, and that the attendance program is not connected to it.')} <span className="ltr">({s.error})</span></span></div>}

      {d.unmatched.length > 0 && <section>
        <h4 className="font-semibold text-[14px] mb-1">{L('أرقام على البصّامة غير مربوطة بموظف', 'Terminal numbers not linked to an employee')}</h4>
        <p className="text-[12.5px] text-ink-3 mb-2">{L('اربط كل رقم بموظفه فتُسجَّل حركاته السابقة واللاحقة في حضوره.', 'Link each number to its employee: past and future punches go to their attendance.')}</p>
        <ul className="divide-y divide-line-2 border border-line rounded-xl">{d.unmatched.map(u => <li key={u.pin} className="flex flex-wrap items-center gap-2 px-3 py-2">
          <span className="font-bold ltr num min-w-[60px]">{u.pin}</span>
          <span className="text-[12.5px] text-ink-3 flex-1 min-w-[140px]">{L(`${u.count} حركة · آخرها`, `${u.count} punches · last`)} {fmtDateTime(u.last, lang)}</span>
          <select className="field !h-9 !w-auto min-w-[200px]" value={pick[u.pin] ?? ''} onChange={e => setPick({ ...pick, [u.pin]: e.target.value })}>
            <option value="">{L('اختر الموظف', 'Choose employee')}</option>{(emps.data?.data ?? []).map(e => <option key={e.id} value={e.id}>{e.code} — {bi(e.name, lang)}</option>)}</select>
          <Btn size="sm" kind="primary" icon={<Link2 size={13} />} disabled={!pick[u.pin] || map.isPending}
            onClick={() => map.mutate({ pin: u.pin, employee_id: Number(pick[u.pin]) }, { onSuccess: r => toast(L(`رُبط الرقم وسُجّلت ${r.applied} حركة`, `Linked — ${r.applied} punches recorded`)), onError: x => toast(x instanceof ApiError ? x.message : String(x)) })}>{L('ربط', 'Link')}</Btn>
        </li>)}</ul>
      </section>}

      <section>
        <h4 className="font-semibold text-[14px] mb-2">{L('آخر الحركات', 'Latest punches')}</h4>
        {d.recent.length === 0 ? <Empty title={L('لم تصل حركات بعد', 'No punches yet')} /> :
          <ul className="divide-y divide-line-2 border border-line rounded-xl">{d.recent.map((p, i) => <li key={i} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-[13px]">
            <span className="num ltr text-ink-3 min-w-[120px]">{fmtDateTime(p.at, lang)}</span>
            <span className="flex-1 min-w-[160px]">{p.employee ? <>{bi(p.employee.name, lang)} <span className="text-ink-3 ltr">{p.employee.code}</span></> : <span className="text-ink-3">{L('رقم', 'No.')} <span className="ltr">{p.pin}</span></span>}</span>
            {p.verify !== null && VERIFY[p.verify] && <span className="text-ink-3">{L(...VERIFY[p.verify])}</span>}
            {p.status === 'ok' ? <Badge tone="brand">{L('سُجّلت', 'Recorded')}</Badge> : p.status === 'dup' ? <Badge>{L('مكررة', 'Duplicate')}</Badge> : p.status === 'unmatched' ? <Badge tone="warn">{L('غير مربوط', 'Unlinked')}</Badge> : <Badge>{L('خارج الخدمة', 'Inactive')}</Badge>}
          </li>)}</ul>}
      </section>
    </div>}
  </Modal>
}

function Stat({ t, v }: { t: string; v: React.ReactNode }) {
  return <div className="rounded-xl border border-line bg-surface px-3 py-2.5"><div className="text-[12px] text-ink-3">{t}</div><div className="mt-1">{v}</div></div>
}
