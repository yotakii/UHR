import { useEffect, useState } from 'react'
import { Download, History, Laptop, Plus, RotateCcw, Send } from 'lucide-react'
import { useL, useLang, bi, fmtDate } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { href } from '../../lib/router'
import { ACTION, CMD_STATUS, REASON, SOURCE, downloadAgent, useAddAgent, useAgentCommands, usePcAgents, useSaveAgentSettings, useTestAgent, useUpdateAgent, type AgentAction, type AgentSettings, type PcAgentRow } from '../../lib/pcAgents'
import { Badge, Btn, Empty, Field, Modal, PageHead, Panel, Seg, toast } from '../../ui/kit'
import { DecisionDialog } from '../../ui/decision'

const when = (iso: string | null, lang: 'ar' | 'en') => iso ? `${fmtDate(iso, lang)} ${new Date(iso).toLocaleTimeString(lang === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB', { hour: '2-digit', minute: '2-digit' })}` : '—'

/** بطاقة وكيل واحد: الحالة، آخر أمر، والإجراءات */
function AgentCard({ a, edit, showEmployee }: { a: PcAgentRow; edit: boolean; showEmployee?: boolean }) {
  const L = useL(); const lang = useLang()
  const upd = useUpdateAgent(); const test = useTestAgent()
  const [hist, setHist] = useState(false)
  const [off, setOff] = useState(false)
  const [busy, setBusy] = useState(false)
  const dl = async () => { setBusy(true); try { await downloadAgent(a.id); toast(L('نُزّلت الحزمة بمفتاح جديد — النسخة السابقة على الجهاز توقفت.', 'Package downloaded with a new key — any earlier copy has stopped.')) } catch { toast(L('تعذّر تنزيل الحزمة.', 'Could not download the package.')) } finally { setBusy(false) } }
  return <div className={`rounded-xl border border-line p-3 ${a.active ? '' : 'opacity-60'}`}>
    <div className="flex flex-wrap items-start justify-between gap-2">
      <div className="min-w-0"><div className="font-semibold flex items-center gap-2"><Laptop size={15} className="text-ink-3" />{a.name}</div>
        {showEmployee && a.employee && <a className="text-[12.5px] text-brand" href={href(`/hr/employees/${a.employee.id}?tab=attendance`)}>{bi(a.employee, lang)} <span className="ltr">{a.employee.code}</span></a>}
        <div className="text-[12px] text-ink-3">{a.host ? <span className="ltr">{a.host}{a.os_user ? ` · ${a.os_user}` : ''}</span> : L('لم يتصل بعد', 'Not connected yet')}{a.version ? ` · v${a.version}` : ''}</div></div>
      <div className="flex flex-wrap gap-1.5">{!a.active ? <Badge>{L('موقوف', 'Disabled')}</Badge> : !a.paired ? <Badge tone="brass">{L('بانتظار التثبيت', 'Awaiting install')}</Badge>
        : <Badge tone={a.online ? 'brand' : 'neutral'} dot>{a.online ? L('متصل', 'Online') : L('غير متصل', 'Offline')}</Badge>}
        {a.queued > 0 && <Badge tone="brass">{L(`${a.queued} أمر بالانتظار`, `${a.queued} queued`)}</Badge>}</div>
    </div>
    <div className="text-[12px] text-ink-3 mt-1.5">{L('آخر اتصال', 'Last seen')}: <span className="num">{when(a.last_seen_at, lang)}</span>
      {a.last && <> · {L('آخر أمر', 'Last command')}: {L(...ACTION[a.last.action])} ({L(...(REASON[a.last.reason] ?? [a.last.reason, a.last.reason]))}) — <Badge tone={CMD_STATUS[a.last.status]?.[2] ?? 'neutral'}>{CMD_STATUS[a.last.status] ? L(CMD_STATUS[a.last.status][0], CMD_STATUS[a.last.status][1]) : a.last.status}</Badge>
        {a.last.error && a.last.status === 'failed' && <span className="text-danger ltr inline-block"> {a.last.error}</span>}</>}</div>
    <div className="flex flex-wrap gap-1.5 mt-2">
      {edit && a.active && <Btn size="sm" icon={<Download size={13} />} disabled={busy} onClick={dl}>{a.paired ? L('إعادة تنزيل الحزمة', 'Download again') : L('تنزيل حزمة التثبيت', 'Download the installer')}</Btn>}
      {edit && a.active && a.paired && <Btn size="sm" kind="ghost" icon={<Send size={13} />} disabled={test.isPending} onClick={() => test.mutate({ id: a.id, action: 'lock' }, { onSuccess: () => toast(L('أُرسل أمر قفل تجريبي — سيظهر تحذير على الجهاز.', 'A test lock was sent — a warning appears on the computer.')) })}>{L('قفل تجريبي', 'Test lock')}</Btn>}
      <Btn size="sm" kind="ghost" icon={<History size={13} />} onClick={() => setHist(true)}>{L('السجل', 'History')}</Btn>
      {edit && (a.active ? <Btn size="sm" kind="ghost" onClick={() => setOff(true)}>{L('إيقاف', 'Disable')}</Btn>
        : <Btn size="sm" kind="ghost" onClick={() => upd.mutate({ id: a.id, name: a.name, active: true })}>{L('تفعيل', 'Enable')}</Btn>)}
    </div>
    {hist && <AgentHistory a={a} onClose={() => setHist(false)} />}
    <DecisionDialog open={off} onClose={() => setOff(false)} tone="danger" title={L('إيقاف الوكيل', 'Disable the agent')} subject={a.name} confirmLabel={L('إيقاف', 'Disable')}
      onConfirm={() => upd.mutateAsync({ id: a.id, name: a.name, active: false })} />
  </div>
}

function AgentHistory({ a, onClose }: { a: PcAgentRow; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const { data } = useAgentCommands(a.id)
  return <Modal open onClose={onClose} width={640} title={`${L('سجل أوامر', 'Command history')} — ${a.name}`}>
    {!data ? null : data.length === 0 ? <Empty title={L('لا أوامر بعد', 'No commands yet')} /> : <ul className="divide-y divide-line">{data.map(c => <li key={c.id} className="py-2 flex flex-wrap items-center justify-between gap-2 text-[13px]">
      <div><div>{L(...ACTION[c.action])} · {L(...(REASON[c.reason] ?? [c.reason, c.reason]))}{c.source && SOURCE[c.source] ? ` · ${L(...SOURCE[c.source])}` : ''}</div>
        <div className="text-[12px] text-ink-3 num">{when(c.at, lang)}{c.done_at ? ` → ${when(c.done_at, lang)}` : ''}{c.error ? <span className="ltr inline-block"> · {c.error}</span> : ''}</div></div>
      <Badge tone={CMD_STATUS[c.status]?.[2] ?? 'neutral'}>{CMD_STATUS[c.status] ? L(CMD_STATUS[c.status][0], CMD_STATUS[c.status][1]) : c.status}</Badge></li>)}</ul>}
  </Modal>
}

/** في ملف الموظف (تبويب الحضور): أجهزته المربوطة بالوكيل */
export function PcAgentsPanel({ employeeId }: { employeeId: number }) {
  const L = useL()
  const { data } = usePcAgents(employeeId)
  const add = useAddAgent()
  const [name, setName] = useState<string | null>(null)
  if (!data) return null
  const edit = data.can.edit
  if (!data.settings.enabled && data.data.length === 0) return null
  const err = add.error instanceof ApiError ? add.error.field('name') ?? add.error.message : ''
  return <Panel title={<span className="flex items-center gap-2"><Laptop size={15} />{L('جهاز الموظف — الخروج بالبطاقة', 'Employee computer — check-out lock')}</span>}
    sub={L('عند تسجيل خروجه يُقفل جهازه أو يُسجَّل خروجه من Windows حسب الإعداد، بعد تحذير لحفظ العمل.', 'On check-out the computer is locked or signed out of Windows per the setting, after a warning to save work.')}
    action={edit && <Btn size="sm" icon={<Plus size={13} />} onClick={() => setName('')}>{L('ربط جهاز', 'Link a computer')}</Btn>}>
    {data.data.length === 0 ? <p className="text-[13px] text-ink-3">{L('لا يوجد جهاز مربوط بهذا الموظف.', 'No computer is linked to this employee.')}</p>
      : <div className="grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(300px,1fr))]">{data.data.map(a => <AgentCard key={a.id} a={a} edit={edit} />)}</div>}
    {name !== null && <Modal open onClose={() => setName(null)} title={L('ربط جهاز بالوكيل', 'Link a computer')} footer={<><Btn onClick={() => setName(null)}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" disabled={add.isPending || name.trim().length < 2} onClick={() => add.mutate({ employee_id: employeeId, name: name.trim() }, { onSuccess: async r => { setName(null); try { await downloadAgent(r.data.id) } catch { /* يعيد التنزيل من البطاقة */ } } })}>{L('ربط وتنزيل الحزمة', 'Link and download')}</Btn></>}>
      <Field label={L('اسم الجهاز', 'Computer name')} hint={L('مثل: حاسوب المحاسبة 2. بعد التنزيل شغّل install.bat على جهاز الموظف وهو داخل حسابه في Windows.', 'e.g. Accounts PC 2. After downloading, run install.bat on the employee\'s computer while signed in to their Windows account.')} error={err}>
        <input className="field" autoFocus value={name} onChange={e => setName(e.target.value)} onKeyDown={e => { if (e.key === 'Enter' && name.trim().length >= 2) add.mutate({ employee_id: employeeId, name: name.trim() }, { onSuccess: async r => { setName(null); try { await downloadAgent(r.data.id) } catch { /* */ } } }) }} /></Field>
    </Modal>}
  </Panel>
}

/** صفحة الإعدادات: قاعدة الأمر عند الخروج + حالة كل الأجهزة */
export function PcAgentsPage() {
  const L = useL()
  const { data } = usePcAgents()
  const save = useSaveAgentSettings()
  const [v, setV] = useState<AgentSettings | null>(null)
  useEffect(() => { if (data && !v) setV(data.settings) }, [data, v])
  if (!data || !v) return null
  const edit = data.can.settings
  const e = save.error instanceof ApiError ? save.error : null
  const num = (k: keyof AgentSettings, label: string, unit: string, hint?: string) => <Field label={label} hint={hint} error={e?.field(k)}><div className="flex items-center gap-2">
    <input className="field ltr num !w-24" inputMode="numeric" disabled={!edit} value={String(v[k])} onChange={x => setV({ ...v, [k]: Number(x.target.value.replace(/\D/g, '')) || 0 })} /><span className="text-[13px] text-ink-3">{unit}</span></div></Field>
  const offline = data.data.filter(a => a.active && a.paired && !a.online).length
  const failed = data.data.filter(a => a.last?.status === 'failed').length
  return <div className="max-w-[1000px] mx-auto space-y-5">
    <PageHead title={L('وكلاء أجهزة الموظفين', 'Employee computer agents')} sub={L('عند تسجيل خروج الموظف من الدوام يُقفل حاسوبه أو يُسجَّل خروجه من Windows، والجهاز المطفأ ينفّذ الأمر عند أول اتصال.', 'When an employee checks out, their computer is locked or signed out of Windows; a computer that is off runs the command when it next connects.')}
      actions={edit && <><Btn icon={<RotateCcw size={15} />} onClick={() => setV(data.defaults)}>{L('القيم الافتراضية', 'Defaults')}</Btn>
        <Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate(v, { onSuccess: () => toast(L('حُفظت الإعدادات', 'Settings saved')) })}>{L('حفظ', 'Save')}</Btn></>} />
    <Panel title={L('ماذا يحدث عند الخروج', 'What happens at check-out')}>
      <label className="flex items-center gap-2 text-[13.5px] mb-3"><input type="checkbox" disabled={!edit} checked={v.enabled} onChange={x => setV({ ...v, enabled: x.target.checked })} />{L('مفعّل', 'Enabled')}</label>
      <Field label={L('الأمر', 'Command')} div><Seg value={v.action} onChange={(x: AgentAction) => edit && setV({ ...v, action: x })} options={(Object.keys(ACTION) as AgentAction[]).map(k => ({ v: k, label: L(...ACTION[k]) }))} /></Field>
      <Field label={L('مصادر الخروج التي تُقفل الجهاز', 'Check-out sources that lock the computer')} div className="mt-3"><div className="flex flex-wrap gap-3">{Object.entries(SOURCE).map(([k, t]) => <label key={k} className="flex items-center gap-2 text-[13.5px]">
        <input type="checkbox" disabled={!edit} checked={v.sources.includes(k)} onChange={x => setV({ ...v, sources: x.target.checked ? [...v.sources, k] : v.sources.filter(s => s !== k) })} />{L(t[0], t[1])}</label>)}</div></Field>
      <div className="grid gap-4 mt-4 [grid-template-columns:repeat(auto-fill,minmax(220px,1fr))]">
        {num('warn_seconds', L('مهلة التحذير لحفظ العمل', 'Warning before the command'), L('ثانية', 'seconds'))}
        {num('postpone_minutes', L('السماح بالتأجيل مرة واحدة', 'Allow one postponement'), L('دقيقة', 'minutes'), L('0 = بلا تأجيل', '0 = no postponement'))}
        {num('queue_hours', L('صلاحية الأمر للجهاز المطفأ', 'Queued command validity'), L('ساعة', 'hours'), L('ويُلغى بأي دخول جديد', 'Also cancelled by a new check-in'))}
        {num('fresh_minutes', L('تجاهل الحركات الأقدم من', 'Ignore taps older than'), L('دقيقة', 'minutes'), L('حركات وصلت متأخرة من جهاز كان منقطعاً', 'Late uploads from an offline terminal'))}
        {num('offline_minutes', L('يُعد الجهاز غير متصل بعد', 'Offline after'), L('دقيقة', 'minutes'))}
        {num('poll_seconds', L('يسأل الجهاز الخادم كل', 'The computer checks every'), L('ثانية', 'seconds'))}
      </div>
      <label className="flex items-center gap-2 text-[13.5px] mt-3"><input type="checkbox" disabled={!edit} checked={v.notify_employee} onChange={x => setV({ ...v, notify_employee: x.target.checked })} />{L('إشعار الموظف «تم تسجيل خروجك»', 'Notify the employee «You have checked out»')}</label>
      {save.error && !e?.errors && <div className="text-[13px] text-danger mt-2">{(save.error as Error).message}</div>}
    </Panel>
    <Panel title={L('الأجهزة', 'Computers')} sub={L(`${data.data.length} جهاز · ${offline} غير متصل · ${failed} آخر أمر فاشل — يُربط الجهاز من ملف الموظف (تبويب الحضور).`, `${data.data.length} computers · ${offline} offline · ${failed} failed last command — link a computer from the employee file (attendance tab).`)}>
      {data.data.length === 0 ? <Empty title={L('لا أجهزة مربوطة', 'No linked computers')}>{L('افتح ملف الموظف ← الحضور ← «ربط جهاز».', 'Open the employee file → Attendance → «Link a computer».')}</Empty>
        : <div className="grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(300px,1fr))]">{data.data.map(a => <AgentCard key={a.id} a={a} edit={data.can.edit} showEmployee />)}</div>}
    </Panel>
  </div>
}
