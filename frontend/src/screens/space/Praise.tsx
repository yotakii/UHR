import { useState } from 'react'
import { Award, Cake, Gift, Loader2, PartyPopper, Search, Sparkles, TrendingUp, X } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { useColleagues, useGivePraise, type Colleague, type PostRow, type PraiseInfo, type PraiseTop } from '../../lib/space'
import { Btn, Field, Modal, Panel, toast } from '../../ui/kit'
import { Initials, dot } from './parts'

const soft: Record<string, string> = { brand: 'bg-brand-soft text-brand border-brand/40', brass: 'bg-brass-soft text-brass border-brass/40', info: 'bg-info-soft text-info border-info/40', danger: 'bg-danger-soft text-danger border-danger/40', 'ink-3': 'bg-sub text-ink-2 border-line' }
export const valueTone = (c: string) => soft[c] ?? soft.brand

/** «شكر زميل» (12b): اختيار الزملاء والقيمة والرسالة */
export function PraiseModal({ info, onClose }: { info: PraiseInfo; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const give = useGivePraise()
  const [q, setQ] = useState('')
  const [picked, setPicked] = useState<Colleague[]>([])
  const [value, setValue] = useState(info.values[0]?.key ?? '')
  const [msg, setMsg] = useState('')
  const list = useColleagues(q).data ?? []
  const e = give.error instanceof ApiError ? give.error : null
  const full = picked.length >= info.max_recipients
  const ok = picked.length > 0 && !!value && msg.trim().length >= info.min_message && info.left > 0
  const send = () => give.mutate({ recipients: picked.map(p => p.id), value, message: msg }, {
    onSuccess: () => { toast(L('نُشر شكرك في الساحة', 'Your praise was posted in the Space')); onClose() } })
  return <Modal open width={640} onClose={onClose} title={<span className="flex items-center gap-2"><Award size={18} className="text-brass" />{L('شكر زميل', 'Praise a colleague')}</span>}
    footer={<><span className="me-auto text-[12.5px] text-ink-3 num">{L(`متبقٍ لك هذا الشهر: ${info.left} من ${info.limit}`, `Left this month: ${info.left} of ${info.limit}`)}</span>
      <Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn>
      <Btn kind="primary" disabled={!ok || give.isPending} onClick={send}>{give.isPending ? <Loader2 size={15} className="animate-spin" /> : L('نشر الشكر', 'Post praise')}</Btn></>}>
    <div className="space-y-5">
      <p className="text-[13px] text-ink-2">{L('يُنشر الشكر في مجتمع الشركة ويصل إشعار لكل زميل تختاره', 'The praise is posted in the company community and each colleague is notified')}
        {info.points > 0 && L(`، وتُضاف ${info.points} نقاط إلى رصيده في متجر المكافآت.`, `, and ${info.points} points are added to their rewards balance.`)}{info.points > 0 ? '' : L('.', '.')}</p>

      <Field label={L(`الزملاء (حتى ${info.max_recipients})`, `Colleagues (up to ${info.max_recipients})`)} req div error={e?.field('recipients')}>
        {picked.length > 0 && <div className="flex flex-wrap gap-1.5 mb-2">{picked.map(p => <span key={p.id} className="inline-flex items-center gap-1.5 rounded-full bg-brand-soft text-brand ps-1 pe-2 py-0.5 text-[13px] font-semibold">
          <Initials p={p} size={22} />{bi(p.name, lang)}<button onClick={() => setPicked(s => s.filter(x => x.id !== p.id))} aria-label={L('إزالة', 'Remove')}><X size={13} /></button></span>)}</div>}
        {!full && <><div className="relative"><Search size={15} className="absolute top-1/2 -translate-y-1/2 start-3 text-ink-3" />
          <input className="field ps-9" value={q} onChange={x => setQ(x.target.value)} placeholder={L('ابحث بالاسم أو المسمى أو الرمز…', 'Search by name, title or code…')} /></div>
          <ul className="mt-2 max-h-48 overflow-y-auto rounded-xl border border-line divide-y divide-line-2">
            {list.filter(c => !picked.some(p => p.id === c.id)).map(c => <li key={c.id}><button className="w-full flex items-center gap-2 px-3 py-2 text-start hover:bg-sub" onClick={() => { setPicked(s => [...s, c]); setQ('') }}>
              <Initials p={c} size={28} /><span className="min-w-0 flex-1"><span className="block text-[13.5px] font-semibold truncate">{bi(c.name, lang)}</span>
                <span className="block text-[12px] text-ink-3 truncate">{bi(c.title, lang)}{c.dept.ar && ` · ${bi(c.dept, lang)}`}</span></span></button></li>)}
            {list.length === 0 && <li className="px-3 py-3 text-[13px] text-ink-3">{L('لا نتائج', 'No results')}</li>}
          </ul></>}
      </Field>

      <Field label={L('القيمة التي تشكره عليها', 'The value you recognise')} req div error={e?.field('value')}>
        <div className="flex flex-wrap gap-2">{info.values.map(v => <button key={v.key} onClick={() => setValue(v.key)} aria-pressed={value === v.key}
          className={`h-9 px-3 rounded-full border text-[13px] inline-flex items-center gap-1.5 ${value === v.key ? `${valueTone(v.color)} font-semibold` : 'border-line text-ink-2 hover:bg-sub'}`}>
          <span className="size-2 rounded-full" style={dot(v.color)} />{lang === 'ar' ? v.ar : v.en}</button>)}</div>
      </Field>

      <Field label={L('رسالة الشكر', 'Message')} req error={e?.field('message')} hint={info.min_message > 0 ? L(`${info.min_message} أحرف على الأقل — ما الذي فعله تحديداً؟`, `At least ${info.min_message} characters — what exactly did they do?`) : undefined}>
        <textarea className="field !h-auto py-2 leading-7" rows={4} maxLength={1000} value={msg} onChange={x => setMsg(x.target.value)}
          placeholder={L('مثال: شكراً على مساعدتك في إنهاء تقرير الربع قبل موعده.', 'e.g. Thanks for helping finish the quarterly report ahead of time.')} />
      </Field>
      {info.left <= 0 && <p className="text-danger text-[13px]">{L('بلغت حد الشكر لهذا الشهر.', 'You reached this month’s praise limit.')}</p>}
      {e && !Object.keys(e.errors).length && <p className="text-danger text-[13px]">{e.message}</p>}
    </div>
  </Modal>
}

/** جزء بطاقة المنشور: القيمة والمستلمون */
export function PraiseBlock({ p }: { p: PostRow }) {
  const L = useL(); const lang = useLang()
  const v = p.meta?.value; const people = p.meta?.recipients ?? []
  if (!v) return null
  return <div className={`mx-4 mt-3 rounded-2xl border px-4 py-3 ${valueTone(v.color)}`}>
    <div className="flex flex-wrap items-center gap-2 text-[13px] font-semibold"><Award size={16} />{L('شكر على', 'Praise for')} «{lang === 'ar' ? v.ar : v.en}»
      {!!p.meta?.points && <span className="ms-auto inline-flex items-center gap-1 text-[12px] num"><Gift size={13} />+{p.meta.points} {L('نقطة لكل زميل', 'pts each')}</span>}</div>
    <div className="mt-2 flex flex-wrap gap-2">{people.map(x => <span key={x.employee_id} className="inline-flex items-center gap-2 rounded-full bg-surface/80 text-ink ps-1 pe-3 py-1 text-[13px]">
      <Initials p={{ id: x.employee_id, name: x.name }} size={26} /><span><b>{bi(x.name, lang)}</b>{x.title && <span className="text-ink-3 text-[11.5px] ms-1.5">{bi(x.title, lang)}</span>}</span></span>)}</div>
  </div>
}

const CEL = {
  birthday: { icon: Cake, tone: 'bg-brass-soft text-brass', ar: 'أعياد ميلاد اليوم', en: 'Birthdays today' },
  anniversary: { icon: Sparkles, tone: 'bg-brand-soft text-brand', ar: 'سنويات الخدمة', en: 'Work anniversaries' },
  joiner: { icon: PartyPopper, tone: 'bg-info-soft text-info', ar: 'زملاء جدد', en: 'New colleagues' },
  promotion: { icon: TrendingUp, tone: 'bg-brand-soft text-brand', ar: 'تهنئة بمسمى جديد', en: 'New role' },
} as const

export function CelebrationIcon({ p, size = 40 }: { p: PostRow; size?: number }) {
  const c = CEL[p.meta?.type ?? 'birthday']; const I = c.icon
  return <span className={`inline-grid place-items-center rounded-full shrink-0 ${c.tone}`} style={{ width: size, height: size }} aria-hidden><I size={size * 0.5} /></span>
}

/** بطاقة الاحتفال: الأشخاص ونص التهنئة باللغة المختارة */
export function CelebrationBlock({ p }: { p: PostRow }) {
  const L = useL(); const lang = useLang()
  const t = p.meta?.type ?? 'birthday'; const c = CEL[t]; const people = p.meta?.people ?? []
  const years = (n: number) => lang === 'ar' ? (n === 1 ? 'سنة واحدة' : n === 2 ? 'سنتان' : n <= 10 ? `${n} سنوات` : `${n} سنة`) : `${n} ${n === 1 ? 'year' : 'years'}`
  const line = { birthday: L('كل عام وأنتم بخير! شاركوا زملاءكم التهنئة.', 'Happy birthday! Share your wishes.'), anniversary: L('شكراً لعطائكم طوال هذه السنوات.', 'Thank you for all these years.'),
    joiner: L('مرحباً بكم — نتمنى لكم بداية موفقة.', 'Welcome aboard — wishing you a great start.'), promotion: L('تهانينا بالمسمى الجديد ومزيداً من النجاح.', 'Congratulations and continued success.') }[t]
  return <div className="px-4 pt-3">
    <div className={`rounded-2xl px-4 py-3 ${c.tone}`}><div className="font-display font-bold text-[16px]">{lang === 'ar' ? c.ar : c.en}</div><div className="text-[13px] mt-0.5 text-ink-2">{line}</div></div>
    <ul className="mt-3 grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(210px,1fr))]">{people.map(x => <li key={x.employee_id} className="flex items-center gap-2 rounded-xl border border-line px-3 py-2">
      <Initials p={{ id: x.employee_id, name: x.name }} size={34} /><span className="min-w-0"><span className="block text-[13.5px] font-semibold truncate">{bi(x.name, lang)}</span>
        <span className="block text-[12px] text-ink-3 truncate">{t === 'anniversary' && x.years ? years(x.years) : x.title ? bi(x.title, lang) : ''}</span></span></li>)}</ul>
  </div>
}

/** الأكثر شكراً هذا الشهر */
export function PraiseBoard({ top, info, onGive }: { top: PraiseTop[]; info: PraiseInfo; onGive?: () => void }) {
  const L = useL(); const lang = useLang()
  return <Panel title={<span className="flex items-center gap-2"><Award size={16} className="text-brass" />{L('الأكثر شكراً هذا الشهر', 'Most praised this month')}</span>}>
    {top.length === 0 ? <p className="text-[13px] text-ink-3">{L('لا شكر بعد هذا الشهر — كن أول من يشكر زميلاً.', 'No praise yet this month — be the first.')}</p>
      : <ol className="space-y-2">{top.map((x, i) => <li key={x.employee_id} className="flex items-center gap-2 text-[13px]"><span className="num w-4 text-ink-3">{i + 1}</span>
        <Initials p={{ id: x.employee_id, name: x.name }} size={28} /><span className="flex-1 min-w-0 truncate font-semibold">{bi(x.name, lang)}</span><span className="num text-ink-3">{x.count}</span></li>)}</ol>}
    {onGive && info.enabled && <Btn size="sm" className="w-full mt-3" icon={<Award size={14} />} disabled={info.left <= 0} onClick={onGive}>{L('شكر زميل', 'Praise a colleague')}</Btn>}
  </Panel>
}
