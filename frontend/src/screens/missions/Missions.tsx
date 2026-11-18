import { useQuery } from '@tanstack/react-query'
import { api } from '../../lib/api'
import { AmendsList, InvitesPanel, MentionPicker, MissionCalendar, commentText, type MissionExtra } from './MissionMore'
import { useState } from 'react'
import { AlertTriangle, Check, FileSpreadsheet, LayoutGrid, List, Loader2, Paperclip, Plus, Printer, Send, Trash2 , CalendarDays } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { fmtDate, fmtDateTime, money } from '../../lib/dates'
import { ApiError } from '../../lib/api'
import { navigate, setQuery, useRoute } from '../../lib/router'
import { download } from '../../lib/admin'
import { fileUrl } from '../../lib/console'
import type { Step } from '../../lib/requests'
import {
  BOARD, ELEMENT, STATUS, uploadMissionFile, useBonuses, useGiveBonus, useMission, useMissionAct, useMissionReports, useMissions, usePayLines, useReverseLine, useSaveMission,
  type EstLine, type MissionFull, type MissionRow, type PayLineRow,
} from '../../lib/missions'
import { Badge, Btn, Drawer, Empty, Field, Modal, PageHead, Panel, Progress, Seg, Tabs, toast } from '../../ui/kit'
import { ConfirmSend } from '../../ui/decision'
import { Masked } from '../../ui/vault'
import { StepsPath } from '../requests/parts'

type Tab = 'missions' | 'bonuses' | 'lines' | 'reports'
type Data = NonNullable<ReturnType<typeof useMissions>['data']>

/**
 * «المهمات والسفر والمكافآت» (SRS الأنظمة الأساسية، الجزء B — الدفعة 20) في البوابتين: لوحة متابعة، قائمة أو لوحة كانبان،
 * المهمة بخطواتها حتى التسوية وبنود الراتب، المكافآت، بنود الراتب، والتقارير.
 */
export function MissionsApp({ base }: { base: string }) {
  const L = useL(); const route = useRoute()
  const tab = (route.query.tab as Tab) || 'missions'
  const view = route.query.view || 'mine'
  const { data: d } = useMissions({ view, status: route.query.status || 'open', type: route.query.type, q: route.query.q })
  const id = Number(route.query.m) || null
  const [form, setForm] = useState<MissionFull | 'new' | null>(null)
  if (!d) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const staff = d.can.team || d.can.manage || d.can.finance
  return <div className="max-w-[1280px] mx-auto space-y-5">
    <PageHead title={L('المهمات والسفر والمكافآت', 'Missions, travel & bonuses')} sub={L('من الطلب والاعتماد إلى التنفيذ والتسوية وبنود الراتب برقم المهمة.', 'From request and approval to execution, settlement and pay lines carrying the mission number.')}
      actions={<div className="flex flex-wrap gap-2">{d.can.create && <Btn kind="primary" icon={<Plus size={16} />} onClick={() => setForm('new')}>{L('مهمة جديدة', 'New mission')}</Btn>}
        {d.can.export && <Btn icon={<FileSpreadsheet size={16} />} onClick={() => download('/missions/export', 'UHR-missions.xlsx').catch(e => toast(e.message))}>Excel</Btn>}</div>} />
    <Tabs value={tab} onChange={v => setQuery({ tab: v === 'missions' ? undefined : v })} tabs={[{ v: 'missions', label: L('المهمات', 'Missions') }, { v: 'bonuses', label: L('المكافآت', 'Bonuses') },
      { v: 'lines', label: L('بنود الراتب', 'Pay lines') }, ...(staff ? [{ v: 'reports' as Tab, label: L('التقارير', 'Reports') }] : [])]} />
    {tab === 'missions' && <MissionList d={d} staff={staff} />}
    {tab === 'bonuses' && <BonusTab d={d} />}
    {tab === 'lines' && <LinesTab canReverse={d.can.reverse} />}
    {tab === 'reports' && staff && <ReportsTab />}
    {id && <MissionDrawer id={id} base={base} onEdit={m => setForm(m)} onClose={() => setQuery({ m: undefined })} />}
    {form && <MissionForm d={d} m={form === 'new' ? null : form} onClose={() => setForm(null)} />}
  </div>
}

function MissionList({ d, staff }: { d: Data; staff: boolean }) {
  const L = useL(); const lang = useLang(); const route = useRoute()
  const [mode, setMode] = useState<'list' | 'board' | 'cal'>('list')
  const db = d.dashboard
  const card = (label: string, n: number, tone = '') => <div className="rounded-2xl border border-line bg-surface p-3"><div className="text-[12px] text-ink-3">{label}</div><div className={`text-[24px] font-bold num ${tone}`}>{n}</div></div>
  const pend = (s: string) => s.startsWith('pending') ? 'pending' : s
  return <div className="space-y-4">
    <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(150px,1fr))]">
      {card(L('مفتوحة', 'Open'), db.open)}{card(L('بانتظار الاعتماد', 'Awaiting approval'), db.pending, 'text-brass')}{card(L('متأخرة', 'Late'), db.late, db.late ? 'text-danger' : '')}
      {card(L('في السفر الآن', 'Travelling now'), db.travelling, 'text-info')}{card(L('سلف غير مسوّاة', 'Unsettled advances'), db.unsettled, db.unsettled ? 'text-brass' : '')}{card(L('قيد التسوية', 'Settling'), db.settling)}</div>
    <div className="flex flex-wrap gap-2 items-center">
      {staff && <Seg value={route.query.view || 'mine'} onChange={v => setQuery({ view: v === 'mine' ? undefined : v })} options={[{ v: 'mine', label: L('مهماتي', 'Mine') }, { v: 'owned', label: L('أنا مسؤولها', 'I own') }, { v: 'all', label: L('كل نطاقي', 'My scope') }]} />}
      <Seg value={route.query.status || 'open'} onChange={v => setQuery({ status: v === 'open' ? undefined : v })} options={[{ v: 'open', label: L('المفتوحة', 'Open') }, { v: 'settling', label: L('للتسوية', 'To settle') }, { v: 'closed', label: L('المغلقة', 'Closed') }, { v: 'all', label: L('الكل', 'All') }]} />
      <select className="field max-w-[220px]" value={route.query.type ?? ''} onChange={e => setQuery({ type: e.target.value || undefined })} aria-label={L('النوع', 'Type')}><option value="">{L('كل الأنواع', 'All types')}</option>{d.lookups.types.map(t => <option key={t.id} value={t.id}>{t.code} · {bi(t, lang)}</option>)}</select>
      <span className="flex-1" /><Seg value={mode} onChange={setMode} options={[{ v: 'list', label: <List size={15} aria-label={L('قائمة', 'List')} /> }, { v: 'board', label: <LayoutGrid size={15} aria-label={L('لوحة', 'Board')} /> }, { v: 'cal', label: <CalendarDays size={15} aria-label={L('تقويم', 'Calendar')} /> }]} />
    </div>
    {mode === 'cal' ? <MissionCalendar view={route.query.view || 'mine'} /> : d.data.length === 0 ? <Panel><Empty title={L('لا مهمات', 'No missions')} /></Panel> : mode === 'list' ?
      <Panel pad={false}><table className="tbl"><thead><tr><th>{L('المهمة', 'Mission')}</th><th className="hidden md:table-cell">{L('المنفّذ', 'Executor')}</th><th className="hidden lg:table-cell">{L('الموعد', 'Due')}</th><th>{L('الحالة', 'Status')}</th></tr></thead>
        <tbody>{d.data.map(m => <tr key={m.id} className="cursor-pointer" onClick={() => setQuery({ m: String(m.id) })}>
          <td><div className="font-medium">{m.title}</div><div className="text-[12px] text-ink-3"><span className="ltr inline-block">{m.ref_no}</span>{m.type ? ` · ${bi(m.type, lang)}` : ''}{m.travel ? ` · ${m.travel.city || (m.travel.place ? bi(m.travel.place, lang) : m.travel.governorate || m.travel.country)}` : ''}</div>
            <div className="mt-1 max-w-[220px]"><Progress value={m.progress} /></div></td>
          <td className="hidden md:table-cell">{m.employee ? bi(m.employee, lang) : <Badge>{m.assignee_type === 'team' ? L('فريق', 'Team') : L('فرع', 'Branch')}</Badge>}</td>
          <td className="hidden lg:table-cell num text-[13px]">{m.due_on ? fmtDate(m.due_on, lang) : '—'}</td>
          <td><StatusBadge s={m.status} />{m.late && <Badge tone="danger">{L('متأخرة', 'Late')}</Badge>}</td></tr>)}</tbody></table></Panel> :
      <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(220px,1fr))]">{BOARD.map(col => {
        const items = d.data.filter(m => pend(m.status) === col)
        return <div key={col} className="rounded-2xl bg-sub p-2"><div className="px-2 py-1 text-[13px] font-semibold flex justify-between">{col === 'pending' ? L('بانتظار الاعتماد', 'Awaiting approval') : L(...STATUS[col].slice(0, 2) as [string, string])}<span className="num text-ink-3">{items.length}</span></div>
          <div className="space-y-2">{items.map(m => <button key={m.id} onClick={() => setQuery({ m: String(m.id) })} className="w-full text-start rounded-xl bg-surface border border-line p-2.5 hover:border-brand">
            <div className="text-[13px] font-medium">{m.title}</div><div className="text-[11.5px] text-ink-3 ltr">{m.ref_no}</div><div className="mt-1"><Progress value={m.progress} /></div></button>)}</div></div>
      })}</div>}
  </div>
}

function StatusBadge({ s }: { s: string }) { const L = useL(); const x = STATUS[s] ?? [s, s, 'neutral']; return <Badge tone={x[2]}>{L(x[0], x[1])}</Badge> }

const elName = (code: string, L: ReturnType<typeof useL>) => ELEMENT[code] ? L(...ELEMENT[code]) : code

function Lines({ lines, masked }: { lines: EstLine[]; masked?: boolean }) {
  const L = useL(); const lang = useLang()
  if (!lines.length) return <div className="text-[13px] text-ink-3">{L('لا مستحقات مالية.', 'No amounts due.')}</div>
  return <table className="w-full text-[13px]"><tbody>{lines.map((l, i) => <tr key={i} className="border-b border-line-2 last:border-0">
    <td className="py-1.5"><span className="ltr inline-block text-ink-3 me-1">{l.code}</span>{elName(l.code, L)}{l.qty ? <span className="text-ink-3 num"> · {l.qty} × <Masked value={masked ? null : l.rate}>{money(l.rate ?? 0, l.currency, lang)}</Masked></span> : null}
      {l.over && <Badge tone="danger">{L('متجاوز للحد', 'Over the limit')}</Badge>}</td>
    <td className="py-1.5 text-end num font-semibold"><Masked value={masked ? null : l.amount}>{money(l.amount, l.currency, lang)}</Masked>{l.over && l.claimed ? <div className="text-[11px] text-ink-3 font-normal">{L('المطالب به', 'claimed')} {money(l.claimed, l.currency, lang)}</div> : null}</td></tr>)}</tbody></table>
}

function MissionDrawer({ id, base, onEdit, onClose }: { id: number; base: string; onEdit: (m: MissionFull) => void; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const { data: m } = useMission(id)
  const act = useMissionAct()
  const [modal, setModal] = useState<null | 'close' | 'review' | 'advance' | 'settle' | 'audit' | 'cancel' | 'amend'>(null)
  const [comment, setComment] = useState('')
  const run = (path: string, v?: Record<string, unknown>, ok?: string) => act.mutate({ id, path, v }, { onSuccess: () => { if (ok) toast(ok); setModal(null) }, onError: e => toast((e as Error).message) })
  return <Drawer open onClose={onClose} width={760}>{!m ? <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div> : <div className="p-5 space-y-4">
    <div><div className="flex flex-wrap items-center gap-2"><h2 className="text-[19px] font-bold">{m.title}</h2><StatusBadge s={m.status} />{m.late && <Badge tone="danger">{L('متأخرة', 'Late')}</Badge>}</div>
      <div className="text-[13px] text-ink-2 mt-1"><span className="ltr inline-block">{m.ref_no}</span> · {m.type ? bi(m.type, lang) : ''}{m.category ? ` · ${bi(m.category, lang)}` : ''} · {m.employee ? bi(m.employee, lang) : ''}{m.owner ? ` · ${L('المسؤول', 'Owner')}: ${bi(m.owner, lang)}` : ''}</div>
      <div className="text-[13px] mt-1 num">{m.starts_on && fmtDate(m.starts_on, lang)} — {m.due_on && fmtDate(m.due_on, lang)}{m.close_due && <span className="text-ink-3"> · {L('مهلة الإغلاق', 'Close by')} {fmtDate(m.close_due, lang)}</span>}</div></div>
    {m.description && <p className="text-[13.5px] text-ink-2 whitespace-pre-wrap">{m.description}</p>}
    {m.travel && <Panel title={L('تفاصيل السفر', 'Travel details')}><div className="text-[13.5px] space-y-1">
      <div>{m.travel.scope === 'foreign' ? L('خارجي', 'Foreign') : L('محلي', 'Local')} · {m.travel.city}{m.travel.place && bi(m.travel.place, lang) !== m.travel.city ? ` (${bi(m.travel.place, lang)})` : ''} · {L({ road: 'طرقي', air: 'جوي', car: 'سيارة شخصية' }[m.travel.transport] ?? '', { road: 'Road', air: 'Air', car: 'Personal car' }[m.travel.transport] ?? '')}{m.travel.km ? ` · ${m.travel.km} ${L('كم', 'km')}` : ''}</div>
      <div className="num">{fmtDate(m.travel.depart_on, lang)} — {fmtDate(m.travel.return_on, lang)}</div><div className="text-ink-2">{m.travel.purpose}</div>
      {m.travel.reclassified && <div className="text-brass">{L('صُنّفت مهمة ميدانية: الوجهة ضمن محافظة الفرع.', 'Reclassified as a field mission: the destination is in the branch governorate.')}</div>}</div></Panel>}
    {m.estimate && (m.estimate.lines?.length || m.estimate.warnings.length || m.estimate.masked) ? <Panel title={L('التكلفة التقديرية', 'Estimated cost')} sub={m.estimate.days ? L(`${m.estimate.days} يوم${m.estimate.ticket ? ` · تذكرة ${m.estimate.ticket === 'business' ? 'أعمال' : 'عادية'}` : ''}`, `${m.estimate.days} day(s)${m.estimate.ticket ? ` · ${m.estimate.ticket} ticket` : ''}`) : undefined}>
      {m.estimate.warnings.map(w => <div key={w.key} className="text-[13px] text-brass flex gap-1 mb-1"><AlertTriangle size={14} className="mt-0.5" />{bi(w, lang)}</div>)}
      {m.estimate.masked ? <Masked value={null}><span /></Masked> : <Lines lines={m.estimate.lines ?? []} />}
      {m.advance && <div className="mt-2 text-[13px]">{L('السلفة', 'Advance')}: <Masked value={m.advance.masked ? null : m.advance.amount}>{money(m.advance.amount ?? 0, m.advance.currency ?? 'SYP', lang)}</Masked> · {({ requested: L('مطلوبة', 'Requested'), paid: L('مصروفة', 'Paid'), settled: L('مسوّاة', 'Settled'), refunded: L('مستردة', 'Refunded') } as Record<string, string>)[m.advance.status] ?? m.advance.status}{m.advance.voucher_no && <span className="ltr inline-block text-ink-3 ms-1">{m.advance.voucher_no}</span>}</div>}
    </Panel> : null}
    {m.steps.length > 0 && <Panel title={L('مسار الاعتماد', 'Approval path')}><StepsPath steps={m.steps as unknown as Step[]} /></Panel>}
    {m.stages && m.stages.length > 0 && <Panel title={L('المراحل والإنجاز', 'Stages & progress')} sub={<span className="num">{m.progress}%</span>}>
      <div className="space-y-1.5">{m.stages.map((s, i) => <label key={i} className="flex items-center gap-2 text-[14px]"><input type="checkbox" className="accent-[var(--brand)]" disabled={!m.can.progress || act.isPending} checked={!!s.done_at}
        onChange={e => run('progress', { stages: m.stages!.map((x, j) => j === i ? e.target.checked : !!x.done_at) })} />{lang === 'ar' ? s.name : s.en}<span className="text-ink-3 num text-[12px]">{s.weight}%</span></label>)}</div></Panel>}
    {m.children.length > 0 && <Panel title={L('المهمات الفرعية', 'Sub-missions')}><ul className="space-y-1 text-[13px]">{m.children.map(c => <li key={c.id} className="flex gap-2 items-center"><button className="ltr text-brand" onClick={() => setQuery({ m: String(c.id) })}>{c.ref_no}</button>{c.employee ? bi(c.employee, lang) : ''}<span className="flex-1" /><span className="num">{c.progress}%</span><StatusBadge s={c.status} /></li>)}</ul></Panel>}
    {m.settlement?.report && <Panel title={L('تقرير الإنجاز', 'Completion report')}><p className="text-[13.5px] whitespace-pre-wrap">{m.settlement.report}</p>
      {m.attachments.length > 0 && <div className="flex flex-wrap gap-2 mt-2">{m.attachments.map(f => <a key={f} className="text-brand text-[13px] inline-flex gap-1 items-center" href={fileUrl(f)} target="_blank" rel="noreferrer"><Paperclip size={13} />{L('مرفق', 'File')} {f}</a>)}</div>}</Panel>}
    {m.entitlements && <Panel title={L('التسوية: المستحقات بالحدود', 'Settlement: entitlements within limits')} sub={m.settlement?.status ? ({ submitted: L('بانتظار تدقيق الموارد البشرية', 'Awaiting HR audit'), hr: L('بانتظار تدقيق المالية', 'Awaiting finance audit'), approved: L('معتمدة', 'Approved') } as Record<string, string>)[m.settlement.status] : undefined}>
      <Lines lines={m.entitlements.lines} />{m.entitlements.advance > 0 && <div className="mt-2 text-[13px]">{L('يُستقطع مقابل السلفة', 'Deducted against the advance')}: <b className="num">{money(m.entitlements.advance, m.entitlements.advance_currency ?? 'SYP', lang)}</b></div>}
      {m.settlement?.external && <div className="text-[13px] text-info mt-2">{L('فئة العامل لا تدخل الرواتب — مستحقاته وفق عقده.', 'Not on payroll — paid under their contract.')}</div>}</Panel>}
    {m.lines.length > 0 && <Panel title={L('بنود الراتب', 'Pay lines')}><LineTable rows={m.lines} /></Panel>}
    {(m as MissionExtra).external && <div className="text-[13px] text-info">{L('المسافر خبير خارجي', 'The traveller is an external expert')}: <b>{(m as MissionExtra).external!.name}</b> · {L('مستحقاته وفق عقده', 'paid under the contract')}</div>}
    <AmendsList m={m as MissionExtra} />
    <InvitesPanel m={m as MissionExtra} />
    <div className="flex flex-wrap gap-2">
      {m.can.edit && <Btn onClick={() => onEdit(m)}>{L('تعديل', 'Edit')}</Btn>}
      {m.can.edit && m.status !== 'returned' && <ConfirmSend label={m.type?.financial ? L('إرسال للاعتماد', 'Send for approval') : L('إسناد المهمة', 'Assign the mission')} busy={act.isPending} onConfirm={() => run('submit', {}, L('أُرسلت', 'Sent'))} />}
      {m.can.progress && <Btn kind="primary" onClick={() => setModal('close')}>{L('طلب الإغلاق', 'Request closing')}</Btn>}
      {m.can.review && <Btn kind="primary" onClick={() => setModal('review')}>{L('مراجعة الإنجاز', 'Review completion')}</Btn>}
      {m.can.pay_advance && <Btn onClick={() => setModal('advance')}>{L('تسجيل صرف السلفة', 'Record the advance payment')}</Btn>}
      {m.can.settle && <Btn kind="primary" onClick={() => setModal('settle')}>{L('تقديم التسوية', 'File the settlement')}</Btn>}
      {m.can.audit && <Btn kind="primary" onClick={() => setModal('audit')}>{L('تدقيق التسوية', 'Audit the settlement')}</Btn>}
      {m.can.amend && <Btn onClick={() => setModal('amend')}>{L('تمديد أو تقصير', 'Extend or shorten')}</Btn>}
      {m.can.cancel && <Btn kind="danger" onClick={() => setModal('cancel')}>{L('إلغاء', 'Cancel')}</Btn>}
      {m.travel && <Btn icon={<Printer size={15} />} onClick={() => navigate(`${base}/print/${m.id}`)}>{L('نموذج مهمة السفر', 'Travel mission form')}</Btn>}
    </div>
    {m.return_note && <div className="text-[13px] text-danger">{L('ملاحظة', 'Note')}: {m.return_note}</div>}
    <Panel title={L('التعليقات والنشاط', 'Comments & activity')}>
      <ul className="space-y-2 text-[13px] max-h-[260px] overflow-y-auto">{[...m.comments.map(c => ({ at: c.at, el: <><b>{c.by}</b>: {commentText(c.text)}</> })), ...m.log.map(g => ({ at: g.at, el: <span className="text-ink-3">{g.by ?? ''} · {g.what}{g.note ? ` — ${g.note}` : ''}</span> }))]
        .sort((a, b) => a.at.localeCompare(b.at)).map((x, i) => <li key={i}><span className="text-[11.5px] text-ink-3 num me-2">{fmtDateTime(x.at, lang)}</span>{x.el}</li>)}</ul>
      <div className="flex gap-2 mt-3"><input className="field flex-1" value={comment} onChange={e => setComment(e.target.value)} placeholder={L('اكتب تعليقاً (@ للإشارة)', 'Write a comment (@ to mention)')} />
        <MentionPicker id={id} onPick={t => setComment(c => c + t)} />
        <Btn icon={<Send size={15} />} disabled={!comment.trim() || act.isPending} onClick={() => act.mutate({ id, path: 'comments', v: { text: comment } }, { onSuccess: () => setComment('') })} aria-label={L('إرسال', 'Send')} /></div>
    </Panel>
    {modal === 'close' && <CloseModal id={id} onClose={() => setModal(null)} />}
    {modal === 'review' && <ReviewModal id={id} onClose={() => setModal(null)} />}
    {modal === 'advance' && <SimpleModal title={L('صرف السلفة', 'Pay the advance')} field={L('رقم سند الصرف', 'Payment voucher no.')} name="voucher_no" path="pay-advance" id={id} onClose={() => setModal(null)} />}
    {modal === 'cancel' && <SimpleModal title={L('إلغاء المهمة', 'Cancel the mission')} field={L('السبب', 'Reason')} name="reason" path="cancel" id={id} danger onClose={() => setModal(null)} />}
    {modal === 'amend' && <AmendModal m={m} onClose={() => setModal(null)} />}
    {modal === 'settle' && <SettleModal m={m} onClose={() => setModal(null)} />}
    {modal === 'audit' && <AuditModal m={m} onClose={() => setModal(null)} />}
  </div>}</Drawer>
}

function LineTable({ rows, onReverse }: { rows: PayLineRow[]; onReverse?: (l: PayLineRow) => void }) {
  const L = useL(); const lang = useLang()
  return <table className="w-full text-[13px]"><tbody>{rows.map(l => <tr key={l.id} className="border-b border-line-2 last:border-0">
    <td className="py-1.5"><span className="ltr inline-block text-ink-3 me-1">{l.code}</span>{lang === 'ar' ? l.ar : l.en}{l.employee && <span className="text-ink-3"> · {bi(l.employee, lang)}</span>}
      <div className="text-[11.5px] text-ink-3"><span className="num">{l.period}</span> · {({ pending: L('بانتظار الكشف', 'Pending'), posted: L('مرحّل', 'Posted'), reversed: L('معكوس', 'Reversed') } as Record<string, string>)[l.status] ?? l.status}{l.channel === 'cash' ? ` · ${L('نقداً من المالية', 'cash')}` : ''}</div></td>
    <td className={`py-1.5 text-end num font-semibold ${l.kind === 'deduction' ? 'text-danger' : ''}`}><Masked value={l.amount}>{l.kind === 'deduction' ? '−' : ''}{money(l.amount ?? 0, l.currency, lang)}
      {l.currency !== l.pay_currency && <div className="text-[11px] text-ink-3 font-normal">= {money(l.amount_pay ?? 0, l.pay_currency, lang)}</div>}</Masked></td>
    {onReverse && <td className="text-end">{l.status === 'posted' && !l.reversal_of && <button className="text-[12px] text-brand" onClick={() => onReverse(l)}>{L('تصحيح', 'Correct')}</button>}</td>}</tr>)}</tbody></table>
}

function SimpleModal({ title, field, name, path, id, danger, onClose }: { title: string; field: string; name: string; path: string; id: number; danger?: boolean; onClose: () => void }) {
  const L = useL(); const act = useMissionAct(); const [v, setV] = useState('')
  const err = act.error instanceof ApiError ? act.error : null
  return <Modal open onClose={onClose} title={title} footer={<><Btn onClick={onClose}>{L('رجوع', 'Back')}</Btn><Btn kind={danger ? 'danger' : 'primary'} disabled={!v.trim() || act.isPending}
    onClick={() => act.mutate({ id, path, v: { [name]: v } }, { onSuccess: () => { toast(L('تم', 'Done')); onClose() } })}>{L('تأكيد', 'Confirm')}</Btn></>}>
    <Field label={field} req error={err?.field(name)}><input className="field" value={v} onChange={e => setV(e.target.value)} autoFocus /></Field>
    {err && !Object.keys(err.errors).length && <div className="mt-2 text-[13px] text-danger">{err.message}</div>}</Modal>
}

function FilePick({ files, onChange }: { files: { id: number; name: string }[]; onChange: (f: { id: number; name: string }[]) => void }) {
  const L = useL(); const [busy, setBusy] = useState(false)
  return <div className="space-y-1">{files.map(f => <div key={f.id} className="text-[13px] flex gap-2 items-center"><Paperclip size={13} />{f.name}<button onClick={() => onChange(files.filter(x => x.id !== f.id))} aria-label={L('حذف', 'Remove')}><Trash2 size={13} /></button></div>)}
    <label className="inline-flex items-center gap-2 h-9 px-3 rounded-lg border border-line cursor-pointer text-[13px] hover:bg-sub"><Paperclip size={14} />{busy ? L('جارٍ الرفع…', 'Uploading…') : L('إرفاق ملف', 'Attach a file')}
      <input type="file" className="hidden" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx" onChange={async e => { const f = e.target.files?.[0]; if (!f) return; setBusy(true); try { onChange([...files, await uploadMissionFile(f)]) } catch (x) { toast((x as Error).message) } finally { setBusy(false) } }} /></label></div>
}

function CloseModal({ id, onClose }: { id: number; onClose: () => void }) {
  const L = useL(); const act = useMissionAct(); const [report, setReport] = useState(''); const [files, setFiles] = useState<{ id: number; name: string }[]>([])
  const err = act.error instanceof ApiError ? act.error : null
  return <Modal open onClose={onClose} title={L('طلب إغلاق المهمة', 'Request closing')} footer={<><Btn onClick={onClose}>{L('رجوع', 'Back')}</Btn><ConfirmSend label={L('إرسال للمراجعة', 'Send for review')} busy={act.isPending}
    onConfirm={() => act.mutate({ id, path: 'request-close', v: { report, files: files.map(f => f.id) } }, { onSuccess: () => { toast(L('أُرسلت للمراجعة', 'Sent for review')); onClose() } })} /></>}>
    <Field label={L('تقرير الإنجاز', 'Completion report')} req error={err?.field('report')}><textarea className="field min-h-24" value={report} onChange={e => setReport(e.target.value)} /></Field>
    <Field label={L('إثبات الإنجاز', 'Proof of completion')} div className="mt-3" error={err?.field('files')}><FilePick files={files} onChange={setFiles} /></Field></Modal>
}

function ReviewModal({ id, onClose }: { id: number; onClose: () => void }) {
  const L = useL(); const act = useMissionAct(); const [accept, setAccept] = useState('y'); const [rating, setRating] = useState(4); const [note, setNote] = useState(''); const [due, setDue] = useState('')
  const err = act.error instanceof ApiError ? act.error : null
  return <Modal open onClose={onClose} title={L('مراجعة الإنجاز', 'Review completion')} footer={<><Btn onClick={onClose}>{L('رجوع', 'Back')}</Btn><ConfirmSend label={accept === 'y' ? L('اعتماد الإنجاز', 'Accept') : L('إعادة المهمة', 'Return')} busy={act.isPending}
    onConfirm={() => act.mutate({ id, path: 'review', v: { accept: accept === 'y', rating, note, due_on: due || null } }, { onSuccess: () => { toast(L('تم', 'Done')); onClose() } })} /></>}>
    <Seg value={accept} onChange={setAccept} options={[{ v: 'y', label: L('منجزة', 'Done') }, { v: 'n', label: L('إعادة بملاحظات', 'Return with notes') }]} />
    {accept === 'y' ? <Field label={L('التقييم', 'Rating')} div className="mt-3"><div className="flex gap-1">{[1, 2, 3, 4, 5].map(n => <button key={n} type="button" onClick={() => setRating(n)} className={`size-9 rounded-lg border ${rating >= n ? 'bg-brand text-on-brand border-brand' : 'border-line'}`}>{n}</button>)}</div></Field>
      : <><Field label={L('الملاحظات', 'Notes')} req className="mt-3" error={err?.field('note')}><textarea className="field min-h-20" value={note} onChange={e => setNote(e.target.value)} /></Field>
        <Field label={L('الموعد الجديد', 'New due date')} className="mt-3"><input type="date" className="field" value={due} onChange={e => setDue(e.target.value)} /></Field></>}</Modal>
}

function AmendModal({ m, onClose }: { m: MissionFull; onClose: () => void }) {
  const L = useL(); const act = useMissionAct()
  const [v, setV] = useState({ depart_on: m.travel?.depart_on ?? '', return_on: m.travel?.return_on ?? '', reason: '' })
  const err = act.error instanceof ApiError ? act.error : null
  return <Modal open onClose={onClose} title={L('تمديد أو تقصير المهمة', 'Extend or shorten')} footer={<><Btn onClick={onClose}>{L('رجوع', 'Back')}</Btn><ConfirmSend label={L('حفظ وإعادة الحساب', 'Save and recalculate')} busy={act.isPending}
    onConfirm={() => act.mutate({ id: m.id, path: 'amend', v }, { onSuccess: () => { toast(L('أُعيد الحساب', 'Recalculated')); onClose() } })} /></>}>
    <div className="grid sm:grid-cols-2 gap-3"><Field label={L('الانطلاق', 'Departure')}><input type="date" className="field" value={v.depart_on} onChange={e => setV({ ...v, depart_on: e.target.value })} /></Field>
      <Field label={L('العودة', 'Return')} error={err?.field('return_on')}><input type="date" className="field" value={v.return_on} onChange={e => setV({ ...v, return_on: e.target.value })} /></Field>
      <Field label={L('السبب', 'Reason')} req className="sm:col-span-2" error={err?.field('reason')}><input className="field" value={v.reason} onChange={e => setV({ ...v, reason: e.target.value })} /></Field></div>
    {err && !Object.keys(err.errors).length && <div className="mt-2 text-[13px] text-danger">{err.message}</div>}</Modal>
}

function SettleModal({ m, onClose }: { m: MissionFull; onClose: () => void }) {
  const L = useL(); const act = useMissionAct()
  const [v, setV] = useState({ depart_on: m.travel?.depart_on ?? '', return_on: m.travel?.return_on ?? '', km: m.travel?.km ?? 0 })
  const [exp, setExp] = useState<{ code: 'E-121' | 'E-130'; amount: string; currency: string; nights: string; file?: { id: number; name: string }; note: string }[]>([])
  const err = act.error instanceof ApiError ? act.error : null
  return <Modal open onClose={onClose} width={720} title={L('تسوية المهمة', 'Mission settlement')} footer={<><Btn onClick={onClose}>{L('رجوع', 'Back')}</Btn><ConfirmSend label={L('تقديم التسوية', 'File the settlement')} busy={act.isPending}
    onConfirm={() => act.mutate({ id: m.id, path: 'settlement', v: { ...v, expenses: exp.map(x => ({ code: x.code, amount: Number(x.amount), currency: x.currency, nights: Number(x.nights) || 0, file_id: x.file?.id, note: x.note })) } }, { onSuccess: () => { toast(L('قُدّمت التسوية', 'Settlement filed')); onClose() } })} /></>}>
    <div className="grid sm:grid-cols-3 gap-3"><Field label={L('الانطلاق الفعلي', 'Actual departure')}><input type="date" className="field" value={v.depart_on} onChange={e => setV({ ...v, depart_on: e.target.value })} /></Field>
      <Field label={L('العودة الفعلية', 'Actual return')} error={err?.field('return_on')}><input type="date" className="field" value={v.return_on} onChange={e => setV({ ...v, return_on: e.target.value })} /></Field>
      <Field label={L('الكيلومترات', 'Kilometres')}><input type="number" min={0} className="field num" value={v.km} onChange={e => setV({ ...v, km: Number(e.target.value) })} /></Field></div>
    <div className="mt-4 space-y-3">{exp.map((x, i) => <div key={i} className="rounded-xl border border-line p-3 grid sm:grid-cols-[1fr_120px_90px_80px] gap-2 items-end">
      <Field label={L('البند', 'Item')}><select className="field" value={x.code} onChange={e => setExp(exp.map((y, j) => j === i ? { ...y, code: e.target.value as 'E-121' | 'E-130' } : y))}><option value="E-130">{L('إقامة (فندق)', 'Accommodation')}</option><option value="E-121">{L('مصاريف بفاتورة', 'Receipted expense')}</option></select></Field>
      <Field label={L('المبلغ', 'Amount')} error={err?.field(`expenses.${i}.amount`)}><input type="number" min={0} className="field num" value={x.amount} onChange={e => setExp(exp.map((y, j) => j === i ? { ...y, amount: e.target.value } : y))} /></Field>
      <Field label={L('العملة', 'Currency')}><input className="field ltr" value={x.currency} onChange={e => setExp(exp.map((y, j) => j === i ? { ...y, currency: e.target.value.toUpperCase().slice(0, 3) } : y))} /></Field>
      {x.code === 'E-130' ? <Field label={L('الليالي', 'Nights')}><input type="number" min={1} className="field num" value={x.nights} onChange={e => setExp(exp.map((y, j) => j === i ? { ...y, nights: e.target.value } : y))} /></Field> : <span />}
      <div className="sm:col-span-4 flex flex-wrap gap-2 items-center"><FilePick files={x.file ? [x.file] : []} onChange={f => setExp(exp.map((y, j) => j === i ? { ...y, file: f[f.length - 1] } : y))} />
        {err?.field(`expenses.${i}.file_id`) && <span className="text-[12px] text-danger">{err.field(`expenses.${i}.file_id`)}</span>}<span className="flex-1" /><button className="text-danger text-[13px]" onClick={() => setExp(exp.filter((_, j) => j !== i))}>{L('حذف', 'Remove')}</button></div>
    </div>)}
      <Btn size="sm" icon={<Plus size={14} />} onClick={() => setExp([...exp, { code: 'E-130', amount: '', currency: 'SYP', nights: '1', note: '' }])}>{L('فاتورة', 'Invoice')}</Btn></div>
    {err && !Object.keys(err.errors).length && <div className="mt-2 text-[13px] text-danger">{err.message}</div>}</Modal>
}

function AuditModal({ m, onClose }: { m: MissionFull; onClose: () => void }) {
  const L = useL(); const lang = useLang(); const act = useMissionAct()
  const ex = m.settlement?.expenses ?? []
  const lim = (i: number) => m.entitlements?.lines.find(l => l.expense === i)
  const [ap, setAp] = useState<Record<number, string>>(Object.fromEntries(ex.map((x, i) => [i, String(x.approved ?? lim(i)?.amount ?? x.amount)])))
  const [note, setNote] = useState('')
  return <Modal open onClose={onClose} width={680} title={m.settlement?.status === 'hr' ? L('تدقيق المالية', 'Finance audit') : L('تدقيق الموارد البشرية', 'HR audit')}
    footer={<><Btn onClick={onClose}>{L('رجوع', 'Back')}</Btn><ConfirmSend label={m.settlement?.status === 'hr' ? L('اعتماد وتوليد بنود الراتب', 'Approve and create pay lines') : L('اعتماد التدقيق', 'Approve the audit')} busy={act.isPending}
      onConfirm={() => act.mutate({ id: m.id, path: 'audit', v: { approved: ap, note } }, { onSuccess: () => { toast(L('تم', 'Done')); onClose() }, onError: e => toast((e as Error).message) })} /></>}>
    {m.entitlements && <Lines lines={m.entitlements.lines.filter(l => l.expense === undefined)} />}
    <div className="space-y-2 mt-3">{ex.map((x, i) => <div key={i} className="rounded-xl border border-line p-3 text-[13px] grid sm:grid-cols-[1fr_140px] gap-2 items-center">
      <div><b>{elName(x.code, L)}</b> · {money(x.amount, x.currency, lang)}{lim(i)?.limit ? <span className="text-ink-3"> · {L('الحد', 'limit')} {money(Number(lim(i)!.limit), x.currency, lang)}</span> : null}
        {lim(i)?.over && <Badge tone="danger">{L('متجاوز', 'Over')}</Badge>}<div><a className="text-brand" href={fileUrl(x.file_id)} target="_blank" rel="noreferrer">{L('الفاتورة', 'Invoice')}</a></div></div>
      <Field label={L('المعتمد', 'Approved')}><input type="number" min={0} className="field num" value={ap[i] ?? ''} onChange={e => setAp({ ...ap, [i]: e.target.value })} /></Field></div>)}</div>
    <Field label={L('ملاحظة', 'Note')} className="mt-3"><input className="field" value={note} onChange={e => setNote(e.target.value)} /></Field></Modal>
}

/** نموذج المهمة: عامة أو سفر (HR-11-F-05) — الأنواع والتصنيفات والمحافظات والدول من دليل الترميز */
function MissionForm({ d, m, onClose }: { d: Data; m: MissionFull | null; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const save = useSaveMission()
  const [v, setV] = useState({ type_id: m?.type ? d.lookups.types.find(t => t.code === m.type!.code)?.id ?? '' : '', category_id: '', title: m?.title ?? '', description: m?.description ?? '',
    assignee_type: m?.assignee_type ?? 'employee', employee_id: m?.employee?.id ?? '', assignee_ref: '', priority: m?.priority ?? 'normal', starts_on: m?.starts_on ?? '', due_on: m?.due_on ?? '', cost_center_id: '',
    advance: String(m?.advance?.amount ?? ''), advance_currency: m?.advance?.currency ?? 'SYP', ticket_class: (m as MissionExtra | null)?.ticket_class ?? '', external_id: String((m as MissionExtra | null)?.external?.id ?? '') })
  const experts = useQuery({ queryKey: ['missions', 'experts'], enabled: d.can.manage, queryFn: () => api<{ data: { id: number; ar: string; kind: string; status: string }[] }>('/hr/externals?tab=experts').then(r => r.data).catch(() => []) })
  const [t, setT] = useState(m?.travel ?? { scope: 'local' as const, country: 'SY', governorate: '', city: '', purpose: '', depart_on: '', return_on: '', transport: 'road' as const, km: 0, legs: [] })
  const type = d.lookups.types.find(x => x.id === Number(v.type_id))
  const travel = !!type?.attrs?.travel
  const err = save.error instanceof ApiError ? save.error : null
  const e = (k: string) => err?.field(k)
  return <Modal open onClose={onClose} width={760} title={m ? L('تعديل المهمة', 'Edit mission') : L('مهمة جديدة', 'New mission')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending} icon={<Check size={15} />}
      onClick={() => save.mutate({ id: m?.id, v: { ...v, travel: travel ? t : null, advance: v.advance ? Number(v.advance) : 0, ticket_class: v.ticket_class || null, external_id: v.external_id ? Number(v.external_id) : null } }, { onSuccess: x => { toast(L('حُفظت مسودة — راجع التقدير ثم أرسل', 'Draft saved — review the estimate, then send')); onClose(); setQuery({ m: String(x.id) }) } })}>{L('حفظ المسودة', 'Save draft')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('نوع المهمة', 'Mission type')} req error={e('type_id')}><select className="field" value={v.type_id} onChange={x => setV({ ...v, type_id: x.target.value })}><option value="">—</option>{d.lookups.types.map(x => <option key={x.id} value={x.id}>{x.code} · {bi(x, lang)}</option>)}</select></Field>
      <Field label={L('التصنيف', 'Category')}><select className="field" value={v.category_id} onChange={x => setV({ ...v, category_id: x.target.value })}><option value="">—</option>{d.lookups.categories.filter(c => !c.parent_id || c.parent_id === Number(v.type_id)).map(x => <option key={x.id} value={x.id}>{bi(x, lang)}</option>)}</select></Field>
      <Field label={L('العنوان', 'Title')} req className="sm:col-span-2" error={e('title')}><input className="field" value={v.title} onChange={x => setV({ ...v, title: x.target.value })} /></Field>
      <Field label={L('الوصف', 'Description')} className="sm:col-span-2"><textarea className="field min-h-16" value={v.description} onChange={x => setV({ ...v, description: x.target.value })} /></Field>
      {d.can.team && <Field label={L('الإسناد', 'Assign to')} div className="sm:col-span-2"><Seg value={v.assignee_type} onChange={x => setV({ ...v, assignee_type: x })} options={[{ v: 'employee', label: L('موظف', 'Employee') }, { v: 'team', label: L('فريق (إدارة)', 'Team') }, { v: 'branch', label: L('فرع', 'Branch') }]} /></Field>}
      {v.assignee_type === 'employee' ? <Field label={L('المنفّذ', 'Executor')} error={e('employee_id')}><select className="field" value={v.employee_id} onChange={x => setV({ ...v, employee_id: x.target.value })}><option value="">{L('أنا', 'Me')}</option>{d.lookups.employees.map(x => <option key={x.id} value={x.id}>{x.code} · {bi(x, lang)}</option>)}</select></Field>
        : <Field label={v.assignee_type === 'team' ? L('الإدارة', 'Department') : L('الفرع', 'Branch')} error={e('assignee_ref')}><select className="field" value={v.assignee_ref} onChange={x => setV({ ...v, assignee_ref: x.target.value })}><option value="">—</option>{(v.assignee_type === 'team' ? d.lookups.departments : d.lookups.branches).map(x => <option key={x.id} value={x.id}>{bi(x, lang)}</option>)}</select></Field>}
      <Field label={L('الأولوية', 'Priority')}><select className="field" value={v.priority} onChange={x => setV({ ...v, priority: x.target.value })}>{[['low', 'منخفضة', 'Low'], ['normal', 'عادية', 'Normal'], ['high', 'عالية', 'High'], ['urgent', 'عاجلة', 'Urgent']].map(([k, a, b]) => <option key={k} value={k}>{L(a, b)}</option>)}</select></Field>
      {!travel && <><Field label={L('البدء', 'Start')}><input type="date" className="field" value={v.starts_on} onChange={x => setV({ ...v, starts_on: x.target.value })} /></Field>
        <Field label={L('الموعد النهائي', 'Due date')}><input type="date" className="field" value={v.due_on} onChange={x => setV({ ...v, due_on: x.target.value })} /></Field></>}
      <Field label={L('مركز الكلفة', 'Cost centre')}><select className="field" value={v.cost_center_id} onChange={x => setV({ ...v, cost_center_id: x.target.value })}><option value="">—</option>{d.lookups.cost_centers.map(x => <option key={x.id} value={x.id}>{x.code} · {bi(x, lang)}</option>)}</select></Field>
    </div>
    {travel && <div className="mt-4 rounded-2xl border border-line p-4 grid sm:grid-cols-2 gap-3">
      <div className="sm:col-span-2 font-semibold">{L('تفاصيل السفر', 'Travel details')}</div>
      <Field label={L('النطاق', 'Scope')} div><Seg value={t.scope} onChange={x => setT({ ...t, scope: x as 'local' | 'foreign' })} options={[{ v: 'local', label: L('داخل البلد', 'Domestic') }, { v: 'foreign', label: L('خارج البلد', 'Abroad') }]} /></Field>
      {t.scope === 'local' ? <Field label={L('محافظة الوجهة', 'Destination governorate')} req error={e('travel.governorate')}><select className="field" value={t.governorate ?? ''} onChange={x => setT({ ...t, governorate: x.target.value })}><option value="">—</option>{d.lookups.governorates.map(g => <option key={g.code} value={g.code}>{bi(g, lang)}</option>)}</select></Field>
        : <Field label={L('الدولة', 'Country')} req error={e('travel.country')}><select className="field" value={t.country} onChange={x => setT({ ...t, country: x.target.value })}><option value="">—</option>{d.lookups.countries.map(g => <option key={g.code} value={g.code}>{bi(g, lang)}</option>)}</select></Field>}
      <Field label={L('المدينة', 'City')}><input className="field" value={t.city} onChange={x => setT({ ...t, city: x.target.value })} /></Field>
      <Field label={L('الغرض من السفر', 'Purpose')} req error={e('travel.purpose')}><input className="field" value={t.purpose} onChange={x => setT({ ...t, purpose: x.target.value })} /></Field>
      <Field label={L('الانطلاق', 'Departure')} req error={e('travel.depart_on')}><input type="date" className="field" value={t.depart_on} onChange={x => setT({ ...t, depart_on: x.target.value })} /></Field>
      <Field label={L('العودة', 'Return')} req error={e('travel.return_on')}><input type="date" className="field" value={t.return_on} onChange={x => setT({ ...t, return_on: x.target.value })} /></Field>
      {t.transport === 'air' && <Field label={L('درجة التذكرة المطلوبة', 'Requested ticket class')} hint={L('تُقارن بالمسموح لدرجة الموظف', 'Compared with the grade allowance')}><select className="field" value={v.ticket_class} onChange={x => setV({ ...v, ticket_class: x.target.value })}>
        <option value="">{L('حسب الدرجة', 'Per grade')}</option>{([['economy', 'عادية', 'Economy'], ['premium', 'مميزة', 'Premium economy'], ['business', 'أعمال', 'Business'], ['first', 'أولى', 'First']] as const).map(o => <option key={o[0]} value={o[0]}>{L(o[1], o[2])}</option>)}</select></Field>}
      {d.can.manage && <Field label={L('المسافر خبير خارجي (اختياري)', 'Traveller is an external expert (optional)')} hint={L('مستحقاته من عقده لا من جدول البدلات', 'Paid under the contract, not the allowance table')}><select className="field" value={v.external_id} onChange={x => setV({ ...v, external_id: x.target.value })}>
        <option value="">—</option>{(experts.data ?? []).filter(x => ['approved', 'assigned', 'active'].includes(x.status)).map(x => <option key={x.id} value={x.id}>{x.ar}</option>)}</select></Field>}
      <Field label={L('طريقة السفر', 'Transport')} div><Seg value={t.transport} onChange={x => setT({ ...t, transport: x as 'road' | 'air' | 'car' })} options={[{ v: 'road', label: L('طرقي', 'Road') }, { v: 'air', label: L('جوي', 'Air') }, { v: 'car', label: L('سيارة شخصية', 'Own car') }]} /></Field>
      {t.transport === 'car' && <Field label={L('الكيلومترات التقديرية', 'Estimated km')} hint={d.lookups.km_price ? L(`سعر الكيلومتر ${d.lookups.km_price}`, `km price ${d.lookups.km_price}`) : undefined}><input type="number" min={0} className="field num" value={t.km} onChange={x => setT({ ...t, km: Number(x.target.value) })} /></Field>}
      <Field label={L('خط السير (وجهات إضافية مفصولة بفاصلة)', 'Route (extra stops, comma separated)')} className="sm:col-span-2"><input className="field" value={t.legs.join('، ')} onChange={x => setT({ ...t, legs: x.target.value.split(/[,،]/).map(s => s.trim()).filter(Boolean) })} /></Field>
      <Field label={L('سلفة مطلوبة', 'Advance requested')}><input type="number" min={0} className="field num" value={v.advance} onChange={x => setV({ ...v, advance: x.target.value })} /></Field>
      <Field label={L('عملة السلفة', 'Advance currency')}><select className="field" value={v.advance_currency} onChange={x => setV({ ...v, advance_currency: x.target.value })}>{d.lookups.currencies.map(c => <option key={c.code} value={c.code}>{c.code}</option>)}</select></Field>
    </div>}
    {err && !Object.keys(err.errors).length && <div role="alert" className="mt-3 text-[13px] text-danger">{err.message}</div>}
  </Modal>
}

// ———————————————— المكافآت ————————————————

function BonusTab({ d }: { d: Data }) {
  const L = useL(); const lang = useLang()
  const [st, setSt] = useState('all'); const { data } = useBonuses(st)
  const [give, setGive] = useState(false)
  return <div className="space-y-3">
    <div className="flex flex-wrap gap-2 items-center"><Seg value={st} onChange={setSt} options={[{ v: 'all', label: L('الكل', 'All') }, { v: 'pending', label: L('بانتظار الاعتماد', 'Pending') }, { v: 'approved', label: L('معتمدة', 'Approved') }]} />
      <span className="flex-1" />{d.can.bonus && <Btn kind="primary" icon={<Plus size={15} />} onClick={() => setGive(true)}>{L('منح مكافأة', 'Grant a bonus')}</Btn>}</div>
    {!data ? <Loader2 className="animate-spin text-brand" /> : data.length === 0 ? <Panel><Empty title={L('لا مكافآت', 'No bonuses')} /></Panel> :
      <Panel pad={false}><table className="tbl"><thead><tr><th>{L('المكافأة', 'Bonus')}</th><th>{L('الموظف', 'Employee')}</th><th className="hidden md:table-cell">{L('المبلغ', 'Amount')}</th><th>{L('الحالة', 'Status')}</th></tr></thead>
        <tbody>{data.map(b => <tr key={b.id}><td><div className="font-medium">{bi(b.bonus_type, lang)}</div><div className="text-[12px] text-ink-3"><span className="ltr inline-block">{b.ref_no}</span> · {b.reason}</div></td>
          <td>{b.employee ? bi(b.employee.name, lang) : '—'}</td><td className="hidden md:table-cell num"><Masked value={b.amount}>{money(b.amount ?? 0, b.currency, lang)}</Masked></td>
          <td><StatusBadge s={b.status} /><div className="hidden md:block mt-1"><StepsPath steps={b.steps as unknown as Step[]} /></div></td></tr>)}</tbody></table></Panel>}
    {give && <GiveBonus d={d} onClose={() => setGive(false)} />}
  </div>
}

function GiveBonus({ d, onClose }: { d: Data; onClose: () => void }) {
  const L = useL(); const lang = useLang(); const save = useGiveBonus()
  const [v, setV] = useState({ type_id: '', employee_ids: [] as number[], amount: '', currency: 'USD', percent: '', hours: '', rate: '', level: '', reason: '', over_cap: false })
  const type = d.lookups.bonus_types.find(t => t.id === Number(v.type_id)); const method = String(type?.attrs?.method ?? 'free')
  const err = save.error instanceof ApiError ? save.error : null
  const [q, setQ] = useState('')
  return <Modal open onClose={onClose} width={680} title={L('منح مكافأة', 'Grant a bonus')} footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><ConfirmSend label={L('إرسال للاعتماد', 'Send for approval')} busy={save.isPending} disabled={!v.type_id || !v.employee_ids.length}
    onConfirm={() => save.mutate({ ...v, amount: v.amount ? Number(v.amount) : null, percent: v.percent ? Number(v.percent) : null, hours: v.hours ? Number(v.hours) : null, rate: v.rate ? Number(v.rate) : null, level: v.level || null }, { onSuccess: r => { toast(L(`أُرسلت ${r.data.length} مكافأة للاعتماد`, `${r.data.length} bonus(es) sent for approval`)); onClose() } })} /></>}>
    <div className="grid sm:grid-cols-2 gap-3">
      <Field label={L('نوع المكافأة', 'Bonus type')} req error={err?.field('type_id')}><select className="field" value={v.type_id} onChange={x => setV({ ...v, type_id: x.target.value })}><option value="">—</option>{d.lookups.bonus_types.map(t => <option key={t.id} value={t.id}>{t.code} · {bi(t, lang)}</option>)}</select></Field>
      {method === 'percent' ? <Field label={L('النسبة من الراتب %', 'Percent of salary')} error={err?.field('percent')}><input type="number" className="field num" value={v.percent} onChange={x => setV({ ...v, percent: x.target.value })} /></Field>
        : method === 'hours' ? <><Field label={L('الساعات', 'Hours')} error={err?.field('hours')}><input type="number" className="field num" value={v.hours} onChange={x => setV({ ...v, hours: x.target.value })} /></Field>
          <Field label={L('مستوى المدرب', 'Trainer level')}><select className="field" value={v.level} onChange={x => setV({ ...v, level: x.target.value })}><option value="">{L('أجر يدوي', 'Manual rate')}</option>{d.lookups.trainer_levels.map(l => <option key={l} value={l}>{({ beginner: L('مبتدئ', 'Beginner'), intermediate: L('متوسط', 'Intermediate'), advanced: L('متقدم', 'Advanced') } as Record<string, string>)[l] ?? l}</option>)}</select></Field>
          {!v.level && <Field label={L('أجر الساعة', 'Hourly rate')}><input type="number" className="field num" value={v.rate} onChange={x => setV({ ...v, rate: x.target.value })} /></Field>}</>
        : <><Field label={L('المبلغ', 'Amount')} error={err?.field('amount')} hint={type?.attrs?.cap ? L(`السقف ${type.attrs.cap}`, `Cap ${type.attrs.cap}`) : undefined}><input type="number" className="field num" value={v.amount} onChange={x => setV({ ...v, amount: x.target.value })} /></Field>
          <Field label={L('العملة', 'Currency')}><select className="field" value={v.currency} onChange={x => setV({ ...v, currency: x.target.value })}>{d.lookups.currencies.map(c => <option key={c.code}>{c.code}</option>)}</select></Field>
          {err?.code === 'over_cap' && <label className="sm:col-span-2 flex gap-2 text-[13px]"><input type="checkbox" checked={v.over_cap} onChange={x => setV({ ...v, over_cap: x.target.checked })} />{L('فوق السقف — يُرفع للإدارة العليا', 'Over the cap — goes to top management')}</label>}</>}
      <Field label={L('المبرر والمرجع', 'Reason & reference')} req className="sm:col-span-2" error={err?.field('reason')}><input className="field" value={v.reason} onChange={x => setV({ ...v, reason: x.target.value })} /></Field>
      <Field label={L('الموظفون', 'Employees')} div className="sm:col-span-2" error={err?.field('employee_ids')}><input className="field mb-2" value={q} onChange={x => setQ(x.target.value)} placeholder={L('بحث', 'Search')} />
        <div className="max-h-48 overflow-y-auto space-y-1">{d.lookups.employees.filter(x => !q || `${x.code} ${x.ar} ${x.en}`.toLowerCase().includes(q.toLowerCase())).map(x => <label key={x.id} className="flex gap-2 text-[13px]">
          <input type="checkbox" className="accent-[var(--brand)]" checked={v.employee_ids.includes(x.id)} onChange={c => setV({ ...v, employee_ids: c.target.checked ? [...v.employee_ids, x.id] : v.employee_ids.filter(i => i !== x.id) })} />{x.code} · {bi(x, lang)}</label>)}</div></Field>
    </div>
    {err && !Object.keys(err.errors).length && <div className="mt-2 text-[13px] text-danger">{err.message}</div>}</Modal>
}

// ———————————————— بنود الراتب والتقارير ————————————————

function LinesTab({ canReverse }: { canReverse: boolean }) {
  const L = useL()
  const [period, setPeriod] = useState('')
  const { data } = usePayLines({ period: period || undefined })
  const rev = useReverseLine(); const [r, setR] = useState<PayLineRow | null>(null); const [reason, setReason] = useState('')
  return <div className="space-y-3"><Field label={L('الفترة', 'Period')} className="max-w-[220px]"><input type="month" className="field" value={period} onChange={e => setPeriod(e.target.value)} /></Field>
    {!data ? <Loader2 className="animate-spin text-brand" /> : data.length === 0 ? <Panel><Empty title={L('لا بنود', 'No lines')}>{L('بنود الراتب تُولَّد من تسويات المهمات والمكافآت المعتمدة.', 'Pay lines come from approved mission settlements and bonuses.')}</Empty></Panel> :
      <Panel><LineTable rows={data} onReverse={canReverse ? l => { setR(l); setReason('') } : undefined} /></Panel>}
    <Modal open={!!r} onClose={() => setR(null)} title={L('تصحيح ببند معاكس', 'Correct with a reversing line')} footer={<><Btn onClick={() => setR(null)}>{L('رجوع', 'Back')}</Btn><ConfirmSend label={L('إنشاء البند المعاكس', 'Create the reversal')} busy={rev.isPending} disabled={reason.trim().length < 5}
      onConfirm={() => r && rev.mutate({ id: r.id, reason }, { onSuccess: () => { toast(L('أُنشئ بند التصحيح في الفترة المفتوحة', 'Reversal created in the open period')); setR(null) } })} /></>}>
      <p className="text-[13px] text-ink-2 mb-3">{L('البند المرحَّل لا يُعدَّل؛ يُنشأ بند معاكس بالمبلغ نفسه في الفترة المفتوحة.', 'A posted line is never edited; a reversing line of the same amount goes into the open period.')}</p>
      <Field label={L('السبب', 'Reason')} req><input className="field" value={reason} onChange={e => setReason(e.target.value)} /></Field></Modal>
  </div>
}

function ReportsTab() {
  const L = useL(); const lang = useLang()
  const y = new Date().getFullYear(); const [from, setFrom] = useState(`${y}-01-01`); const [to, setTo] = useState(new Date().toISOString().slice(0, 10))
  const { data } = useMissionReports(from, to)
  const tbl = (title: string, rows: { key: string; total: number; done: number; late: number }[]) => <Panel pad={false} title={title}>{rows.length === 0 ? <Empty title={L('لا شيء', 'None')} /> :
    <table className="tbl"><thead><tr><th>{L('البند', 'Item')}</th><th>{L('الكل', 'All')}</th><th>{L('منجزة', 'Done')}</th><th>{L('متأخرة', 'Late')}</th></tr></thead><tbody>{rows.map(r => <tr key={r.key}><td>{r.key}</td><td className="num">{r.total}</td><td className="num">{r.done}</td><td className="num">{r.late}</td></tr>)}</tbody></table>}</Panel>
  return <div className="space-y-4">
    <div className="flex flex-wrap gap-3 items-end"><Field label={L('من', 'From')}><input type="date" className="field" value={from} onChange={e => setFrom(e.target.value)} /></Field><Field label={L('إلى', 'To')}><input type="date" className="field" value={to} onChange={e => setTo(e.target.value)} /></Field>
      <Btn icon={<Printer size={15} />} onClick={() => window.print()}>{L('طباعة / PDF', 'Print / PDF')}</Btn></div>
    {!data ? <Loader2 className="animate-spin text-brand" /> : <div className="grid gap-4 lg:grid-cols-2 items-start">
      {tbl(L('حسب النوع', 'By type'), data.by_type)}{tbl(L('حسب الفرع', 'By branch'), data.by_branch)}{tbl(L('حسب الموظف', 'By employee'), data.by_employee)}
      <Panel pad={false} title={L('حسب مركز الكلفة', 'By cost centre')}>{(data.by_cost_center ?? []).length === 0 ? <Empty title={L('لا شيء', 'None')} /> : <table className="tbl"><thead><tr><th>{L('مركز الكلفة', 'Cost centre')}</th><th>{L('الكل', 'All')}</th><th>{L('منجزة', 'Done')}</th><th>{L('الكلفة التقديرية', 'Estimated cost')}</th></tr></thead>
        <tbody>{data.by_cost_center!.map(r => <tr key={r.key}><td>{r.key}</td><td className="num">{r.total}</td><td className="num">{r.done}</td><td className="num text-[12.5px]">{r.cost ? Object.entries(r.cost).map(([c, x]) => money(x, c, lang)).join(' · ') || '—' : <Masked value={null}><span /></Masked>}</td></tr>)}</tbody></table>}</Panel>
      <Panel pad={false} title={L('السلف غير المسوّاة', 'Unsettled advances')}>{data.unsettled.length === 0 ? <Empty title={L('لا شيء', 'None')} /> : <ul className="divide-y divide-line-2">{data.unsettled.map(u => <li key={u.ref_no} className="px-4 py-2 text-[13px] flex gap-2">
        <span className="ltr">{u.ref_no}</span><span className="flex-1">{u.employee}</span><Masked value={u.amount}>{money(u.amount ?? 0, u.currency, lang)}</Masked>{u.days > 0 && <Badge tone="danger">{L(`متأخرة ${u.days} يوم`, `${u.days} d late`)}</Badge>}</li>)}</ul>}</Panel>
      <Panel pad={false} title={L('مهمات السفر', 'Travel missions')}>{data.travel.length === 0 ? <Empty title={L('لا شيء', 'None')} /> : <ul className="divide-y divide-line-2 max-h-[320px] overflow-y-auto">{data.travel.map(t => <li key={t.ref_no} className="px-4 py-2 text-[13px] flex gap-2 flex-wrap">
        <span className="ltr">{t.ref_no}</span><span>{t.employee}</span><span className="text-ink-3">{t.destination}</span><span className="flex-1" /><span className="num">{t.days ?? '—'} {L('يوم', 'd')}</span><StatusBadge s={t.status} /></li>)}</ul>}</Panel>
      <Panel pad={false} title={L('تقرير الشذوذ', 'Anomaly report')}>{data.anomalies.length === 0 ? <Empty title={L('لا مؤشرات', 'Nothing flagged')} /> : <ul className="divide-y divide-line-2">{data.anomalies.map((a, i) => <li key={i} className="px-4 py-2 text-[13px]">
        <b>{a.employee}</b> · {({ at_limit: L('مبالغ عند الحد الأعلى', 'Amounts at the limit'), same_destination: L('مهمات متكررة للوجهة نفسها', 'Repeated destination'), duplicate_invoice: L('فواتير مكررة', 'Duplicate invoices') } as Record<string, string>)[a.kind]} <span className="num">({a.n})</span></li>)}</ul>}</Panel>
      {data.lines && <Panel pad={false} title={L('أثر المهمات والمكافآت على الرواتب', 'Missions & bonuses in payroll')}>{data.lines.length === 0 ? <Empty title={L('لا شيء', 'None')} /> :
        <table className="tbl"><tbody>{data.lines.map(l => <tr key={l.element_code + l.pay_currency}><td><span className="ltr inline-block me-1">{l.element_code}</span>{elName(l.element_code, L)}</td><td className="num">{l.n}</td><td className="num text-end">{money(Number(l.total), l.pay_currency, lang)}</td></tr>)}</tbody></table>}</Panel>}
    </div>}
  </div>
}

export type { MissionRow }
