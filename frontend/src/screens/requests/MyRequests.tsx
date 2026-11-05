import { useEffect, useMemo, useState } from 'react'
import { History, Paperclip } from 'lucide-react'
import { useL, useLang, bi, fmtDateTime, todayISO, addDays } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { useRoute } from '../../lib/router'
import { checkLeave, useAttachDoc, useCancelLeave, useMyLeave, useSubmitLeave, type Check, type LeaveInput, type ReqRow } from '../../lib/requests'
import { Btn, Empty, Field, Modal, PageHead, Panel, toast } from '../../ui/kit'
import { ConfirmSend, DecisionDialog } from '../../ui/decision'
import { DocLink, LeaveStatus, Period, StepsPath, Warnings } from './parts'

/**
 * «إجازاتي وطلباتي» (MyRequests في الديمو) — نموذج طلب الإجازة HR.01-01-F-03:
 * فحص مباشر على الخادم (الإشعار المسبق، البديل، الرصيد، التعارض)، والمسار المتوقع قبل الإرسال، والإلغاء قبل قرار المدير، وإرفاق المستند لاحقاً.
 */
export function MyRequests() {
  const L = useL(); const lang = useLang()
  const q = useMyLeave(); const d = q.data
  const submit = useSubmitLeave(); const cancel = useCancelLeave(); const attach = useAttachDoc()
  const route = useRoute()
  const [v, setV] = useState<LeaveInput>({ type: route.query.type || 'annual', from: addDays(todayISO(), 7), to: addDays(todayISO(), 8), hours: 2, reason: '', substitute_id: '' })
  const [file, setFile] = useState<File | null>(null)
  const [chk, setChk] = useState<Check | null>(null)
  const [errs, setErrs] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)
  const [tried, setTried] = useState(false)
  const [cancelId, setCancelId] = useState<number | null>(null)
  const [hist, setHist] = useState<{ group: 'annual' | 'sick' | 'emergency' | 'unpaid'; title: string } | null>(null)
  const type = d?.types.find(t => t.key === v.type)
  const hourly = type?.unit === 'hour'
  const set = (p: Partial<LeaveInput>) => setV(x => { const n = { ...x, ...p }; if (n.to < n.from) n.to = n.from; return n })
  const input = useMemo(() => ({ ...v, to: hourly ? v.from : v.to, hours: hourly ? v.hours : undefined }), [v, hourly])

  // فحص على الخادم بعد توقف الكتابة — نفس قواعد الإرسال
  useEffect(() => {
    if (!d) return
    const t = setTimeout(() => { checkLeave(input).then(setChk).catch(() => setChk(null)) }, 350)
    return () => clearTimeout(t)
  }, [input, d])

  if (!d) return <div className="max-w-[1100px] mx-auto"><PageHead title={L('إجازاتي وطلباتي', 'My leave & requests')} /><Panel><Empty title={q.isError ? L('تعذّر التحميل', 'Could not load') : L('جارٍ التحميل…', 'Loading…')} /></Panel></div>
  const b = d.balance
  // حقول فارغة (البديل، السبب) لا تُظهر خطأها قبل محاولة الإرسال
  const live = Object.fromEntries(Object.entries(chk?.errors ?? {}).filter(([k]) => tried || !((k === 'substitute_id' && !v.substitute_id) || (k === 'reason' && !v.reason.trim()))))
  const errors = { ...live, ...errs }
  const send = async () => {
    setTried(true)
    if (chk && Object.keys(chk.errors).length) return
    setBusy(true); setErrs({})
    try {
      await submit.mutateAsync({ ...input, file })
      toast(L('أُرسل الطلب', 'Request sent')); setV(x => ({ ...x, reason: '' })); setFile(null); setTried(false)
    } catch (e) {
      if (e instanceof ApiError) setErrs(Object.keys(e.errors).length ? Object.fromEntries(Object.entries(e.errors).map(([k, m]) => [k, m[0]])) : { type: e.message })
    } finally { setBusy(false) }
  }
  const upload = (id: number) => {
    const i = document.createElement('input'); i.type = 'file'; i.accept = '.pdf,.jpg,.jpeg,.png'
    i.onchange = () => i.files?.[0] && attach.mutateAsync({ id, file: i.files[0] }).then(() => toast(L('أُرفق المستند', 'Document attached'))).catch(e => toast(e instanceof ApiError ? e.message : L('تعذّر الرفع', 'Upload failed')))
    i.click()
  }
  const notice = type?.notice && !hourly ? d.rules.notice.map(n => L(`حتى ${n.max_days} يوم: قبل ${n.days_before} يوم`, `up to ${n.max_days} d: ${n.days_before} d before`)).join(' · ') : null

  return <div className="max-w-[1200px] mx-auto">
    <PageHead title={L('إجازاتي وطلباتي', 'My leave & requests')}
      sub={L(`رصيدك من الإجازة السنوية: ${b.remaining} من ${b.entitlement} يوماً${b.held ? ` (${b.held} محجوزة لطلبات قيد الاعتماد)` : ''}`, `Annual leave balance: ${b.remaining} of ${b.entitlement} days${b.held ? ` (${b.held} held by pending requests)` : ''}`)} />
    <div className="grid [grid-template-columns:repeat(auto-fill,minmax(150px,1fr))] gap-3 mb-4">
      {([['annual', L('السنوية المتبقية', 'Annual left'), `${b.remaining}`, `/ ${b.entitlement}`], ['annual', L('المستخدم', 'Used'), `${b.used}`, L('يوم', 'days')],
        ['sick', L('مرضية هذا العام', 'Sick this year'), `${b.sick}`, b.sick_limit ? `/ ${b.sick_limit}` : L('يوم', 'days')],
        ['emergency', L('اضطرارية', 'Emergency'), `${b.emergency.used}`, `/ ${b.emergency.limit}`], ['unpaid', L('بلا راتب', 'Unpaid'), `${b.unpaid.used}`, `/ ${b.unpaid.limit}`]] as const).map(([g, k, n, s]) =>
        <button key={k} type="button" onClick={() => setHist({ group: g, title: k })} title={L('عرض سجل الطلبات', 'Show request history')}
          className="text-start rounded-2xl border border-line bg-surface px-4 py-3 hover:border-brand/50 hover:bg-sub/40 transition-colors">
          <div className="text-[12px] text-ink-3 flex items-center justify-between gap-1">{k}<History size={13} className="opacity-60" /></div>
          <div className="text-[22px] font-bold num">{n} <span className="text-[12px] font-normal text-ink-3">{s}</span></div></button>)}
    </div>
    <div className="grid lg:grid-cols-[380px_1fr] gap-4 items-start">
      <Panel title={L('طلب جديد', 'New request')} sub={b.probation ? L('أنت في فترة التجربة — الإجازة المأجورة بحسب السياسة', 'You are on probation — paid leave follows the policy') : undefined}>
        <div className="space-y-3">
          <Field label={L('نوع الإجازة', 'Leave type')} error={errors.type}><select className="field" value={v.type} onChange={x => set({ type: x.target.value })}>
            {d.types.map(t => <option key={t.key} value={t.key}>{bi(t, lang)}</option>)}</select></Field>
          {hourly ? <div className="grid grid-cols-2 gap-3">
            <Field label={L('التاريخ', 'Date')} error={errors.from}><input type="date" className="field" value={v.from} onChange={x => set({ from: x.target.value, to: x.target.value })} /></Field>
            <Field label={L('عدد الساعات', 'Hours')} error={errors.hours}><input type="number" min={1} max={d.rules.hourly_max_per_day} className="field num" value={v.hours} onChange={x => set({ hours: Number(x.target.value) })} /></Field></div>
            : <div className="grid grid-cols-2 gap-3">
            <Field label={L('من', 'From')} error={errors.from}><input type="date" className="field" value={v.from} onChange={x => set({ from: x.target.value })} /></Field>
            <Field label={L('إلى', 'To')} error={errors.to}><input type="date" className="field" value={v.to} min={v.from} onChange={x => set({ to: x.target.value })} /></Field></div>}
          {!hourly && chk && <div className="text-[13px] text-ink-3">{L(`${chk.days} أيام عمل`, `${chk.days} working days`)}{type?.max_days ? L(` · الحد ${type.max_days} يوم`, ` · max ${type.max_days} d`) : ''}{type && type.paid < 100 ? L(` · مأجورة ${type.paid}٪`, ` · paid ${type.paid}%`) : ''}</div>}
          {notice && <div className="text-[12px] text-ink-3">{L('الإشعار المسبق: ', 'Notice: ')}{notice}</div>}
          <Field label={L('الموظف البديل', 'Substitute')} req={d.rules.substitute_required && !hourly} error={errors.substitute_id}>
            <select className="field" value={v.substitute_id} onChange={x => set({ substitute_id: x.target.value ? Number(x.target.value) : '' })}>
              <option value="">{L('— اختر —', '— choose —')}</option>
              {d.colleagues.map(c => <option key={c.id} value={c.id}>{bi(c, lang)}{c.same_dept ? '' : ` (${c.code})`}</option>)}</select></Field>
          <Field label={L('السبب', 'Reason')} error={errors.reason}><textarea className="field" value={v.reason} maxLength={1000} onChange={x => set({ reason: x.target.value })} /></Field>
          {type && type.doc !== 'none' && <Field div label={type.doc === 'required' ? L('المستند (إلزامي قبل الاعتماد النهائي)', 'Document (required before final approval)') : L('مستند داعم (اختياري)', 'Supporting document (optional)')} error={errors.file}>
            <label className="flex items-center gap-2 h-10 px-3 rounded-lg border border-dashed border-line cursor-pointer text-[13px] text-ink-2 hover:border-brand/50">
              <Paperclip size={15} /><span className="truncate">{file ? file.name : L('اختر ملفاً (PDF أو صورة)', 'Choose a file (PDF or image)')}</span>
              <input type="file" className="hidden" accept=".pdf,.jpg,.jpeg,.png" onChange={x => setFile(x.target.files?.[0] ?? null)} /></label></Field>}
          {chk && <Warnings r={{ warnings: chk.warnings }} />}
          {chk?.levels.length ? <div className="text-[12px] text-ink-3">{L('المسار: ', 'Path: ')}{chk.levels.map(l => bi(l, lang)).join(' ← ')}</div> : null}
          <ConfirmSend block label={L('إرسال الطلب', 'Submit request')} busy={busy} disabled={!!(tried && chk && Object.keys(chk.errors).length)} onConfirm={send}
            summary={type ? `${bi(type, lang)} · ${hourly ? L(`${v.hours} ساعة في ${v.from}`, `${v.hours} h on ${v.from}`) : `${v.from} → ${v.to}${chk ? L(` (${chk.days} يوم)`, ` (${chk.days} d)`) : ''}`}` : undefined} />
        </div>
      </Panel>
      <Panel title={L('طلباتي', 'My requests')} pad={false}>{d.requests.length === 0 ? <Empty title={L('لا توجد طلبات بعد', 'No requests yet')} /> :
        <table className="tbl"><thead><tr><th>{L('الطلب', 'Request')}</th><th className="hidden md:table-cell">{L('المسار', 'Path')}</th><th>{L('الحالة', 'Status')}</th><th /></tr></thead><tbody>
          {d.requests.map(r => <tr key={r.id}>
            <td><div className="font-medium">{bi(r.type_name, lang)}</div><div className="text-[12.5px]"><Period r={r} /></div>
              <div className="text-[12px] text-ink-3">{fmtDateTime(r.created_at, lang)}{r.substitute ? ` · ${L('البديل', 'Sub')}: ${bi(r.substitute, lang)}` : ''}</div>
              <DocLink r={r} /><div className="md:hidden mt-1"><StepsPath steps={r.steps} /></div></td>
            <td className="hidden md:table-cell"><StepsPath steps={r.steps} /></td>
            <td><LeaveStatus s={r.status} /></td>
            <td><div className="flex flex-col gap-1 items-end">
              {r.doc_required && !r.doc && r.status.startsWith('pending') && <Btn size="sm" icon={<Paperclip size={13} />} onClick={() => upload(r.id)}>{L('إرفاق', 'Attach')}</Btn>}
              {r.can_cancel && <Btn size="sm" kind="danger" onClick={() => setCancelId(r.id)}>{L('إلغاء', 'Cancel')}</Btn>}</div></td>
          </tr>)}</tbody></table>}</Panel>
    </div>
    {hist && <Modal open onClose={() => setHist(null)} width={640} title={L(`سجل الطلبات — ${hist.title}`, `Request history — ${hist.title}`)}>
      {(() => {
        const group = (r: ReqRow) => { const t = d.types.find(x => x.key === r.type); return hist.group === 'emergency' ? r.type === 'emergency' : t?.balance === hist.group || (hist.group === 'annual' && r.type === 'annual') }
        const rows = d.requests.filter(group)
        const sick = hist.group === 'sick' && b.sick_tiers?.length ? <p className="text-[12.5px] text-ink-3 mb-3">{L(`حسب السياسة: ${b.sick_tiers.map(t => `${t.days} يوماً بأجر ${t.paid}٪`).join(' ثم ')} في السنة.`, `Per policy: ${b.sick_tiers.map(t => `${t.days} days at ${t.paid}% pay`).join(', then ')} a year.`)}</p> : null
        return <>{sick}{rows.length === 0 ? <Empty title={L('لا توجد طلبات سابقة من هذا النوع', 'No earlier requests of this kind')} /> :
          <ul className="divide-y divide-line-2 border border-line rounded-lg">{rows.map(r => <li key={r.id} className="px-3 py-2.5 flex items-center gap-3 text-[13.5px]">
            <div className="flex-1 min-w-0"><div className="font-medium">{bi(r.type_name, lang)}{r.days ? <span className="text-ink-3 font-normal"> · {L(`${r.days} يوم`, `${r.days} d`)}</span> : null}</div>
              <div className="text-[12.5px]"><Period r={r} /></div>{r.reason && <div className="text-[12px] text-ink-3 truncate">{r.reason}</div>}</div>
            <LeaveStatus s={r.status} /></li>)}</ul>}</>
      })()}
    </Modal>}
    <DecisionDialog open={cancelId !== null} onClose={() => setCancelId(null)} tone="danger" title={L('إلغاء الطلب', 'Cancel the request')} confirmLabel={L('تأكيد الإلغاء', 'Confirm cancel')}
      onConfirm={() => cancel.mutateAsync(cancelId!).then(() => toast(L('أُلغي الطلب', 'Request cancelled')))} />
  </div>
}
