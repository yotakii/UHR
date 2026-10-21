import { useState } from 'react'
import { Copy, KeyRound, Loader2, Monitor, Plus, RefreshCw, Download } from 'lucide-react'
import { useL, useLang, bi, fmtDateTime } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { useAddDevice, useDevices, useRotateDevice, useUpdateDevice, type DeviceInput, type DeviceKind, type DeviceRow } from '../../lib/attendance'
import { Badge, Btn, Empty, Field, Modal, PageHead, Panel, Seg, toast } from '../../ui/kit'

/**
 * أجهزة الحضور المسجّلة (M16 — SRS §10.10): كل جهاز بمفتاح يظهر مرة واحدة، يُلصق في شاشة الجهاز /kiosk أو في إعداد UHR-NFC-Bridge.
 * الإيقاف أو تجديد المفتاح يقطع الجهاز فوراً.
 */
export function Devices() {
  const L = useL(); const lang = useLang()
  const { data } = useDevices()
  const [edit, setEdit] = useState<DeviceRow | 'new' | null>(null)
  const [key, setKey] = useState<{ name: string; key: string } | null>(null)
  const rotate = useRotateDevice()
  const kind: Record<DeviceKind, string> = { reader: L('حاسوب + قارئ USB', 'PC + USB reader'), sunmi: L('جهاز Sunmi', 'Sunmi device'), mobile: L('جوال NFC', 'NFC phone'), bridge: L('جسر القارئ UHR-NFC-Bridge', 'UHR-NFC-Bridge') }
  return <div className="max-w-[1100px] mx-auto space-y-5">
    <PageHead title={L('أجهزة الحضور', 'Attendance devices')} sub={L('شاشة الجهاز تعمل فقط على جهاز مسجّل بمفتاحه. افتح /kiosk على الجهاز والصق المفتاح مرة واحدة.', 'The terminal screen only runs on a registered device. Open /kiosk on it and paste the key once.')}
      actions={<><a href="/downloads/UHR-NFC-Bridge.zip" download className="inline-flex items-center gap-2 h-9 px-3.5 rounded-lg border border-line bg-surface font-semibold text-[14px] hover:bg-sub"><Download size={15} />UHR-NFC-Bridge</a>
        <Btn kind="primary" icon={<Plus size={15} />} onClick={() => setEdit('new')}>{L('إضافة جهاز', 'Add device')}</Btn></>} />
    <Panel pad={false}>{!data ? <div className="grid place-items-center py-12"><Loader2 className="animate-spin text-brand" /></div> : data.data.length === 0 ? <Empty title={L('لا توجد أجهزة', 'No devices')}>{L('أضف جهاز البوابة أو جهاز Sunmi لتبدأ تسجيل الحضور بالبطاقات.', 'Add the gate PC or a Sunmi device to start card attendance.')}</Empty> :
      <table className="tbl"><thead><tr><th>{L('الجهاز', 'Device')}</th><th className="hidden md:table-cell">{L('النوع', 'Kind')}</th><th className="hidden lg:table-cell">{L('آخر اتصال', 'Last seen')}</th><th>{L('الحالة', 'Status')}</th><th /></tr></thead>
        <tbody>{data.data.map(d => <tr key={d.id} className={d.active ? '' : 'opacity-60'}>
          <td><div className="font-medium flex items-center gap-2"><Monitor size={15} className="text-ink-3" />{bi(d.name, lang)}</div><div className="text-[12px] text-ink-3">{d.location ? bi(d.location, lang) : ''}{d.key_hint && <> · <span className="ltr">…{d.key_hint}</span></>}<span className="md:hidden"> · {kind[d.kind]}</span></div></td>
          <td className="hidden md:table-cell text-[13px]">{kind[d.kind]}</td>
          <td className="hidden lg:table-cell num text-[13px]">{d.last_seen_at ? fmtDateTime(d.last_seen_at, lang) : '—'}{d.last_ip && <div className="text-ink-3 ltr">{d.last_ip}</div>}</td>
          <td>{d.active ? <Badge tone="brand">{L('فعّال', 'Active')}</Badge> : <Badge>{L('موقوف', 'Disabled')}</Badge>}</td>
          <td><div className="flex justify-end gap-1">
            <Btn size="sm" kind="ghost" onClick={() => setEdit(d)}>{L('تعديل', 'Edit')}</Btn>
            <Btn size="sm" kind="ghost" icon={<RefreshCw size={13} />} disabled={rotate.isPending} onClick={() => rotate.mutate(d.id, { onSuccess: r => setKey({ name: bi(r.data.name, lang), key: r.key }) })}>{L('مفتاح جديد', 'New key')}</Btn>
          </div></td></tr>)}</tbody></table>}</Panel>
    {edit && data && <DeviceForm d={edit === 'new' ? null : edit} locations={data.locations} kinds={kind} onClose={() => setEdit(null)} onKey={(name, k) => setKey({ name, key: k })} />}
    {key && <Modal open onClose={() => setKey(null)} title={<span className="flex items-center gap-2"><KeyRound size={17} className="text-brand" />{L('مفتاح الجهاز', 'Device key')} — {key.name}</span>} width={560}
      footer={<Btn kind="primary" onClick={() => setKey(null)}>{L('حفظته', 'I saved it')}</Btn>}>
      <p className="text-[13.5px] text-ink-2">{L('يظهر المفتاح هذه المرة فقط. افتح الرابط على الجهاز، أو الصق المفتاح في شاشة /kiosk أو في ملف إعداد UHR-NFC-Bridge.', 'The key is shown only this time. Open the link on the device, or paste the key in the /kiosk screen or the UHR-NFC-Bridge config file.')}</p>
      <div className="mt-3 rounded-lg bg-sub p-3 ltr font-mono text-[13px] break-all select-all">{key.key}</div>
      <div className="mt-3 flex flex-wrap gap-2"><Btn size="sm" icon={<Copy size={14} />} onClick={() => navigator.clipboard?.writeText(key.key).then(() => toast(L('نُسخ المفتاح', 'Key copied')))}>{L('نسخ المفتاح', 'Copy key')}</Btn></div>
    </Modal>}
  </div>
}

function DeviceForm({ d, locations, kinds, onClose, onKey }: { d: DeviceRow | null; locations: { id: number; ar: string; en: string }[]; kinds: Record<DeviceKind, string>; onClose: () => void; onKey: (name: string, key: string) => void }) {
  const L = useL(); const lang = useLang()
  const add = useAddDevice(); const upd = useUpdateDevice()
  const [v, setV] = useState<DeviceInput & { active: boolean }>({ name_ar: d?.name_ar ?? '', name_en: d?.name_en ?? '', kind: d?.kind ?? 'reader', location_id: d?.location_id ?? null, active: d?.active ?? true })
  const m = d ? upd : add
  const err = m.error instanceof ApiError ? m.error : null
  const submit = () => d ? upd.mutate({ ...v, id: d.id }, { onSuccess: () => { toast(L('حُفظ الجهاز', 'Device saved')); onClose() } })
    : add.mutate(v, { onSuccess: r => { onClose(); onKey(bi(r.data.name, lang), r.key) } })
  return <Modal open onClose={() => !m.isPending && onClose()} title={d ? L('تعديل الجهاز', 'Edit device') : L('إضافة جهاز حضور', 'Add attendance device')} width={520}
    footer={<><Btn onClick={onClose} disabled={m.isPending}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={!v.name_ar.trim() || !v.name_en.trim() || m.isPending} onClick={submit}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('الاسم', 'Name')} req error={err?.field('name_ar')}><input className="field" value={v.name_ar} onChange={e => setV({ ...v, name_ar: e.target.value })} placeholder={L('بوابة المقر — دمشق', 'HQ gate — Damascus')} /></Field>
      <Field label={L('الاسم بالإنكليزية', 'Name (EN)')} req error={err?.field('name_en')}><input className="field ltr text-start" value={v.name_en} onChange={e => setV({ ...v, name_en: e.target.value })} /></Field>
      <Field label={L('النوع', 'Kind')} className="sm:col-span-2"><select className="field" value={v.kind} onChange={e => setV({ ...v, kind: e.target.value as DeviceKind })}>{(Object.keys(kinds) as DeviceKind[]).map(k => <option key={k} value={k}>{kinds[k]}</option>)}</select></Field>
      <Field label={L('المقر', 'Location')} className="sm:col-span-2"><select className="field" value={v.location_id ?? ''} onChange={e => setV({ ...v, location_id: e.target.value ? Number(e.target.value) : null })}>
        <option value="">—</option>{locations.map(l => <option key={l.id} value={l.id}>{bi(l, lang)}</option>)}</select></Field>
      {d && <Field label={L('الحالة', 'Status')} div className="sm:col-span-2"><Seg value={v.active ? 'on' : 'off'} onChange={x => setV({ ...v, active: x === 'on' })} options={[{ v: 'on', label: L('فعّال', 'Active') }, { v: 'off', label: L('موقوف', 'Disabled') }]} /></Field>}
    </div>
  </Modal>
}
