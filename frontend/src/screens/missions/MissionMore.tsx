import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { AtSign, ChevronLeft, ChevronRight, Plus } from 'lucide-react'
import { api } from '../../lib/api'
import { useL, useLang, bi, fmtDate } from '../../lib/store'
import { setQuery } from '../../lib/router'
import { STATUS, useMissionAct, type MissionFull, type MissionRow } from '../../lib/missions'
import { Badge, Btn, Field, Modal, Panel, toast } from '../../ui/kit'
import { StepsPath } from '../requests/parts'

// الدفعة 28 — إكمال واجهة المهمات: التقويم الشهري، دعوة العمل، طلبات التعديل، والإشارة في التعليقات

export type MissionExtra = MissionFull & {
  invites?: { id: number; guest_name: string; organization: string | null; nationality: string | null; purpose: string; from: string; to: string; status: string; letter_no: string | null; steps: MissionFull['steps'] }[]
  amends?: { id: number; depart_on: string; return_on: string; reason: string; status: string; steps: MissionFull['steps'] }[]
  external?: { id: number; name: string; name_en: string | null; ref: string } | null; ticket_class?: string | null
}

const RS: Record<string, [string, string, 'brand' | 'brass' | 'danger' | 'neutral']> = { approved: ['معتمد', 'Approved', 'brand'], rejected: ['مرفوض', 'Rejected', 'danger'], cancelled: ['ملغى', 'Cancelled', 'neutral'] }

/** FR-TM-029: عرض تقويم شهري للمهمات */
export function MissionCalendar({ view }: { view: string }) {
  const L = useL(); const lang = useLang()
  const [month, setMonth] = useState(() => new Date().toISOString().slice(0, 7))
  const { data } = useQuery({ queryKey: ['missions', 'calendar', month, view], queryFn: () => api<{ data: MissionRow[] }>(`/missions/calendar?month=${month}&view=${view}`).then(r => r.data) })
  const [y, m] = month.split('-').map(Number)
  const first = new Date(Date.UTC(y, m - 1, 1)); const days = new Date(Date.UTC(y, m, 0)).getUTCDate()
  const lead = (first.getUTCDay() + 1) % 7   // الأسبوع يبدأ السبت
  const shift = (d: number) => { const t = new Date(Date.UTC(y, m - 1 + d, 1)); setMonth(t.toISOString().slice(0, 7)) }
  const on = (day: number) => { const ds = `${month}-${String(day).padStart(2, '0')}`; return (data ?? []).filter(x => (x.starts_on ?? '') <= ds && (x.due_on ?? x.starts_on ?? '') >= ds) }
  const names = lang === 'ar' ? ['سبت', 'أحد', 'اثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة'] : ['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri']
  return <Panel title={first.toLocaleDateString(lang === 'ar' ? 'ar-SY-u-nu-latn' : 'en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' })}
    action={<span className="flex gap-1"><Btn size="sm" kind="ghost" icon={<ChevronRight size={15} />} aria-label={L('السابق', 'Previous')} onClick={() => shift(-1)} /><Btn size="sm" kind="ghost" icon={<ChevronLeft size={15} />} aria-label={L('التالي', 'Next')} onClick={() => shift(1)} /></span>}>
    <div className="grid grid-cols-7 gap-1 text-[11.5px] text-ink-3 mb-1">{names.map(n => <div key={n} className="text-center">{n}</div>)}</div>
    <div className="grid grid-cols-7 gap-1">{Array.from({ length: lead }, (_, i) => <div key={`x${i}`} />)}
      {Array.from({ length: days }, (_, i) => { const list = on(i + 1)
        return <div key={i} className="min-h-[74px] rounded-lg border border-line p-1 overflow-hidden"><div className="text-[11px] num text-ink-3">{i + 1}</div>
          {list.slice(0, 3).map(x => <button key={x.id} onClick={() => setQuery({ m: String(x.id) })} className="block w-full truncate text-start text-[10.5px] rounded px-1 mb-0.5 bg-[color-mix(in_oklab,var(--brand)_14%,transparent)] hover:bg-[color-mix(in_oklab,var(--brand)_24%,transparent)]" title={x.title}>{x.title}</button>)}
          {list.length > 3 && <div className="text-[10px] text-ink-3">+{list.length - 3}</div>}</div> })}</div>
  </Panel>
}

/** FR-TM-026: دعوة عمل ضمن المهمة بموافقة مستقلة */
export function InvitesPanel({ m }: { m: MissionExtra }) {
  const L = useL(); const lang = useLang()
  const act = useMissionAct()
  const [open, setOpen] = useState(false)
  const [v, setV] = useState({ guest_name: '', organization: '', nationality: '', passport: '', purpose: '', from_on: m.travel?.depart_on ?? '', to_on: m.travel?.return_on ?? '' })
  const ok = v.guest_name.trim().length >= 3 && v.purpose.trim().length >= 5 && v.from_on && v.to_on
  const live = !['cancelled', 'rejected', 'closed'].includes(m.status)
  if (!live && !(m.invites ?? []).length) return null
  return <div className="mt-4">
    <div className="flex items-center gap-2"><h4 className="font-semibold text-[14px] flex-1">{L('دعوات العمل', 'Work invitations')}</h4>{live && <Btn size="sm" icon={<Plus size={14} />} onClick={() => setOpen(true)}>{L('طلب دعوة', 'Request an invitation')}</Btn>}</div>
    {(m.invites ?? []).length === 0 ? <div className="text-[12.5px] text-ink-3 mt-1">{L('دعوة جهة أو ضيف للعمل ضمن المهمة — تُعتمد بمسار مستقل وتصدر برقم.', 'Invite a guest or organisation to work within the mission — approved separately and issued with a number.')}</div>
      : <div className="space-y-2 mt-2">{m.invites!.map(i => <div key={i.id} className="rounded-xl border border-line p-2.5 text-[13px]">
        <div className="flex flex-wrap items-center gap-2"><b>{i.guest_name}</b>{i.organization && <span className="text-ink-3">{i.organization}</span>}<span className="num text-ink-3">{fmtDate(i.from, lang)} — {fmtDate(i.to, lang)}</span>
          <span className="flex-1" /><Badge tone={RS[i.status]?.[2] ?? 'brass'}>{RS[i.status] ? L(RS[i.status][0], RS[i.status][1]) : L('بانتظار الاعتماد', 'Awaiting approval')}</Badge>{i.letter_no && <span className="ltr text-[11.5px]">{i.letter_no}</span>}</div>
        <div className="text-ink-3 mt-0.5">{i.purpose}</div><div className="mt-1.5"><StepsPath steps={i.steps as never} /></div></div>)}</div>}
    {open && <Modal open onClose={() => setOpen(false)} title={L('طلب دعوة عمل', 'Work invitation request')} footer={<><Btn onClick={() => setOpen(false)}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" disabled={!ok || act.isPending} onClick={() => act.mutate({ id: m.id, path: 'invites', v: { ...v, organization: v.organization || null, nationality: v.nationality || null, passport: v.passport || null } }, { onSuccess: () => { toast(L('أُرسل الطلب للاعتماد', 'Sent for approval')); setOpen(false) }, onError: e => toast((e as Error).message) })}>{L('إرسال', 'Send')}</Btn></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={L('اسم الضيف', 'Guest name')} req><input className="field" value={v.guest_name} onChange={e => setV({ ...v, guest_name: e.target.value })} /></Field>
        <Field label={L('الجهة', 'Organisation')}><input className="field" value={v.organization} onChange={e => setV({ ...v, organization: e.target.value })} /></Field>
        <Field label={L('الجنسية (رمز الدولة)', 'Nationality (country code)')}><input className="field ltr" maxLength={2} value={v.nationality} onChange={e => setV({ ...v, nationality: e.target.value.toUpperCase() })} /></Field>
        <Field label={L('رقم جواز السفر', 'Passport no.')}><input className="field ltr" value={v.passport} onChange={e => setV({ ...v, passport: e.target.value })} /></Field>
        <Field label={L('من', 'From')} req><input type="date" className="field" value={v.from_on} onChange={e => setV({ ...v, from_on: e.target.value })} /></Field>
        <Field label={L('إلى', 'To')} req><input type="date" className="field" value={v.to_on} onChange={e => setV({ ...v, to_on: e.target.value })} /></Field>
        <Field label={L('الغرض', 'Purpose')} req className="sm:col-span-2"><textarea className="field" rows={2} value={v.purpose} onChange={e => setV({ ...v, purpose: e.target.value })} /></Field>
      </div>
    </Modal>}
  </div>
}

/** FR-TM-024: طلبات تعديل المهمة المعتمدة وحالتها */
export function AmendsList({ m }: { m: MissionExtra }) {
  const L = useL(); const lang = useLang()
  if (!(m.amends ?? []).length) return null
  return <div className="mt-4"><h4 className="font-semibold text-[14px]">{L('طلبات تعديل التواريخ', 'Date change requests')}</h4>
    <div className="space-y-2 mt-2">{m.amends!.map(a => <div key={a.id} className="rounded-xl border border-line p-2.5 text-[13px]">
      <div className="flex flex-wrap items-center gap-2"><span className="num">{fmtDate(a.depart_on, lang)} — {fmtDate(a.return_on, lang)}</span><span className="flex-1" />
        <Badge tone={RS[a.status]?.[2] ?? 'brass'}>{RS[a.status] ? L(RS[a.status][0], RS[a.status][1]) : L('بانتظار اعتماد الفرق', 'Awaiting approval of the difference')}</Badge></div>
      <div className="text-ink-3 mt-0.5">{a.reason}</div><div className="mt-1.5"><StepsPath steps={a.steps as never} /></div></div>)}</div></div>
}

/** FR-TM-006: إشارة إلى مستخدم في التعليق — تُدرج @[الاسم](رقم) ويُبلَّغ المُشار إليه */
export function MentionPicker({ id, onPick }: { id: number; onPick: (token: string) => void }) {
  const L = useL()
  const [open, setOpen] = useState(false)
  const { data } = useQuery({ queryKey: ['missions', 'mentionable', id], enabled: open, queryFn: () => api<{ data: { id: number; name: string }[] }>(`/missions/${id}/mentionable`).then(r => r.data) })
  return <span className="relative">
    <Btn kind="ghost" icon={<AtSign size={15} />} aria-label={L('إشارة إلى مستخدم', 'Mention someone')} onClick={() => setOpen(!open)} />
    {open && <div className="absolute bottom-full end-0 mb-1 z-20 w-56 max-h-56 overflow-y-auto rounded-xl border border-line bg-surface shadow-lg p-1">
      {!data ? <div className="p-2 text-[12px] text-ink-3">…</div> : data.map(u => <button key={u.id} className="block w-full text-start px-2 py-1.5 text-[13px] rounded-lg hover:bg-sub" onClick={() => { onPick(`@[${u.name}](${u.id}) `); setOpen(false) }}>{u.name}</button>)}</div>}
  </span>
}

/** عرض نص التعليق مع الإشارات كأسماء */
export const commentText = (t: string) => t.replace(/@\[([^\]]+)\]\(\d+\)/g, '@$1')

export const statusLabel = (s: string, lang: 'ar' | 'en') => STATUS[s] ? (lang === 'ar' ? STATUS[s][0] : STATUS[s][1]) : s
export const nameOf = (x: { ar: string; en: string } | null | undefined, lang: 'ar' | 'en') => x ? bi(x, lang) : '—'
