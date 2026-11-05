import { useEffect, useState } from 'react'
import { Award, Loader2, Plus, RotateCcw, Trash2 } from 'lucide-react'
import { useL, useLang } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { REACTIONS, useSavePraiseSettings, useSaveSpaceSettings, useSpaceSettings, type CelebrationSettings, type PraiseSettings, type SpaceSettingsData } from '../../lib/space'
import { dot } from '../space/parts'
import { Btn, Field, PageHead, Panel, Seg, toast } from '../../ui/kit'

/** إعدادات «الساحة» للشركة */
export function SpaceSettings() {
  const L = useL(); const lang = useLang()
  const q = useSpaceSettings(); const save = useSaveSpaceSettings()
  const [v, setV] = useState<SpaceSettingsData | null>(null)
  useEffect(() => { if (q.data && !v) setV(q.data.data) }, [q.data, v])
  if (!q.data || !v) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const e = save.error instanceof ApiError ? save.error : null
  const set = <K extends keyof SpaceSettingsData>(k: K, x: SpaceSettingsData[K]) => setV(s => s && ({ ...s, [k]: x }))
  const num = (k: 'max_images' | 'max_mb' | 'edit_minutes' | 'auto_hide_reports', label: string, hint: string) =>
    <Field label={label} hint={hint} error={e?.field(k)}><input className="field num ltr text-start" type="number" value={v[k]} onChange={x => set(k, Number(x.target.value))} /></Field>
  return <div className="max-w-[900px] mx-auto space-y-5">
    <PageHead title={L('إعدادات الساحة', 'Space settings')} sub={L('قواعد التواصل الداخلي في شركتك. تُطبَّق فوراً على الجميع.', 'Rules for internal social in your company. They apply immediately.')}
      actions={<><Btn icon={<RotateCcw size={15} />} onClick={() => setV(q.data!.defaults)}>{L('القيم الافتراضية', 'Defaults')}</Btn>
        <Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate(v, { onSuccess: () => toast(L('حُفظت الإعدادات', 'Settings saved')) })}>{L('حفظ', 'Save')}</Btn></>} />
    <Panel title={L('عام', 'General')}>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(240px,1fr))]">
        <Field label={L('الساحة', 'Space')} div><Seg value={v.enabled ? 'on' : 'off'} onChange={x => set('enabled', x === 'on')} options={[{ v: 'on', label: L('مفعّلة', 'On') }, { v: 'off', label: L('متوقفة', 'Off') }]} /></Field>
        <Field label={L('إنشاء مجتمعات الاهتمام', 'Creating interest communities')} div><Seg value={v.create_communities} onChange={x => set('create_communities', x)} options={[{ v: 'everyone', label: L('كل الموظفين', 'Everyone') }, { v: 'moderators', label: L('المشرفون فقط', 'Moderators only') }]} /></Field>
        <Field label={L('الاستطلاعات', 'Polls')} div><Seg value={v.polls ? 'on' : 'off'} onChange={x => set('polls', x === 'on')} options={[{ v: 'on', label: L('مسموحة', 'Allowed') }, { v: 'off', label: L('ممنوعة', 'Off') }]} /></Field>
        <Field label={L('الأسئلة', 'Questions')} div><Seg value={v.questions ? 'on' : 'off'} onChange={x => set('questions', x === 'on')} options={[{ v: 'on', label: L('مسموحة', 'Allowed') }, { v: 'off', label: L('ممنوعة', 'Off') }]} /></Field>
        {num('edit_minutes', L('مهلة تعديل المنشور (دقيقة)', 'Edit window (minutes)'), L('0 = لا تعديل بعد النشر', '0 = no edits after posting'))}
        {num('max_images', L('أقصى عدد للصور في المنشور', 'Max images per post'), L('0 = بلا صور', '0 = no images'))}
        {num('max_mb', L('أقصى حجم للصورة (MB)', 'Max image size (MB)'), '1–25')}
        {num('auto_hide_reports', L('إخفاء تلقائي بعد عدد بلاغات', 'Auto-hide after reports'), L('يُخفى المحتوى حتى يراجعه المشرف', 'Hidden until a moderator reviews it'))}
      </div>
    </Panel>
    <Panel title={L('التفاعلات المتاحة', 'Available reactions')}>
      <div className="flex flex-wrap gap-2">{q.data.reactions.map(r => { const on = v.reactions.includes(r)
        return <button key={r} onClick={() => set('reactions', on ? v.reactions.filter(x => x !== r) : [...v.reactions, r])} aria-pressed={on}
          className={`h-10 px-3 rounded-xl border inline-flex items-center gap-2 text-[14px] ${on ? 'border-brand bg-brand-soft font-semibold' : 'border-line text-ink-3'}`}><span className="text-[18px]">{REACTIONS[r].emoji}</span>{lang === 'ar' ? REACTIONS[r].ar : REACTIONS[r].en}</button> })}</div>
      {e?.field('reactions') && <p className="mt-2 text-danger text-[12.5px]">{e.field('reactions')}</p>}
    </Panel>
    <PraiseSettingsPanel />
  </div>
}

const COLORS = ['brand', 'info', 'brass', 'danger', 'ink-3']

/** 12b: «شكر زميل» والاحتفالات التلقائية */
function PraiseSettingsPanel() {
  const L = useL()
  const q = useSpaceSettings(); const save = useSavePraiseSettings()
  const [v, setV] = useState<{ praise: PraiseSettings; celebrations: CelebrationSettings } | null>(null)
  useEffect(() => { if (q.data && !v) setV({ praise: q.data.praise, celebrations: q.data.celebrations }) }, [q.data, v])
  if (!q.data || !v) return null
  const e = save.error instanceof ApiError ? save.error : null
  const p = v.praise; const c = v.celebrations
  const setP = (x: Partial<PraiseSettings>) => setV({ ...v, praise: { ...p, ...x } })
  const setC = (x: Partial<CelebrationSettings>) => setV({ ...v, celebrations: { ...c, ...x } })
  const onOff = (val: boolean, fn: (b: boolean) => void) => <Seg value={val ? 'on' : 'off'} onChange={x => fn(x === 'on')} options={[{ v: 'on', label: L('مفعّل', 'On') }, { v: 'off', label: L('متوقف', 'Off') }]} />
  const num = (k: 'points' | 'monthly_limit' | 'min_message' | 'max_recipients', label: string, hint: string) =>
    <Field label={label} hint={hint} error={e?.field(`praise.${k}`)}><input className="field num ltr text-start" type="number" value={p[k]} onChange={x => setP({ [k]: Number(x.target.value) })} /></Field>
  const setVal = (i: number, x: Partial<PraiseSettings['values'][number]>) => setP({ values: p.values.map((o, j) => j === i ? { ...o, ...x } : o) })
  return <Panel title={<span className="flex items-center gap-2"><Award size={17} className="text-brass" />{L('شكر الزملاء والاحتفالات', 'Praise & celebrations')}</span>}
    sub={L('قيم الشركة التي يُشكر عليها الموظفون، ونقاط الشكر في متجر المكافآت، والاحتفالات التي تُنشر تلقائياً في مجتمع الشركة كل صباح.', 'Company values to praise, praise points in the rewards store, and celebrations posted automatically in the company community each morning.')}
    action={<div className="flex gap-2"><Btn size="sm" icon={<RotateCcw size={14} />} onClick={() => setV({ praise: q.data!.praise_defaults, celebrations: q.data!.celebrations_defaults })}>{L('الافتراضي', 'Defaults')}</Btn>
      <Btn size="sm" kind="primary" disabled={save.isPending} onClick={() => save.mutate(v, { onSuccess: () => toast(L('حُفظت إعدادات الشكر والاحتفالات', 'Praise & celebration settings saved')) })}>{L('حفظ', 'Save')}</Btn></div>}>
    <div className="space-y-5">
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(220px,1fr))]">
        <Field label={L('شكر زميل', 'Praise a colleague')} div>{onOff(p.enabled, b => setP({ enabled: b }))}</Field>
        {num('points', L('النقاط لكل زميل', 'Points per colleague'), L('0 = شكر بلا نقاط', '0 = no points'))}
        {num('monthly_limit', L('الحد الشهري لكل موظف', 'Monthly limit per person'), L('عدد مرات الشكر في الشهر', 'Praises per month'))}
        {num('max_recipients', L('أقصى عدد زملاء في الشكر', 'Max colleagues per praise'), '1–20')}
        {num('min_message', L('أقل طول للرسالة (حرف)', 'Minimum message length'), L('رسالة واضحة عمّا فعله', 'A clear note of what they did'))}
      </div>
      <div>
        <div className="text-[13px] font-semibold mb-2">{L('قيم الشركة', 'Company values')}</div>
        <ul className="space-y-2">{p.values.map((o, i) => <li key={i} className="grid gap-2 items-center [grid-template-columns:repeat(auto-fit,minmax(130px,1fr))] rounded-xl border border-line p-2">
          <input className="field" value={o.ar} placeholder={L('الاسم', 'Name')} onChange={x => setVal(i, { ar: x.target.value })} aria-label={L('الاسم', 'Name')} />
          <input className="field ltr text-start" value={o.en} placeholder="English" onChange={x => setVal(i, { en: x.target.value })} aria-label="English" />
          <input className="field ltr text-start num" value={o.key} placeholder="code" onChange={x => setVal(i, { key: x.target.value.toLowerCase() })} aria-label={L('الرمز', 'Code')} />
          <div className="flex items-center gap-1.5">{COLORS.map(k => <button key={k} onClick={() => setVal(i, { color: k })} aria-label={k} aria-pressed={o.color === k} className={`size-6 rounded-full border-2 ${o.color === k ? 'border-ink' : 'border-transparent'}`} style={dot(k)} />)}
            <span className="flex-1" />{p.values.length > 1 && <Btn size="sm" kind="ghost" icon={<Trash2 size={14} />} onClick={() => setP({ values: p.values.filter((_, j) => j !== i) })} aria-label={L('حذف', 'Remove')} />}</div>
        </li>)}</ul>
        {(e?.field('praise.values') || Object.keys(e?.errors ?? {}).some(k => k.startsWith('praise.values.'))) && <p className="mt-2 text-danger text-[12.5px]">{e?.field('praise.values') ?? Object.entries(e!.errors).find(([k]) => k.startsWith('praise.values.'))?.[1]?.[0]}</p>}
        {p.values.length < 12 && <button className="mt-2 text-brand font-semibold text-[13px] inline-flex items-center gap-1" onClick={() => setP({ values: [...p.values, { key: `value${p.values.length + 1}`, ar: '', en: '', color: 'brand' }] })}><Plus size={14} />{L('قيمة', 'Value')}</button>}
        <p className="mt-1 text-[12px] text-ink-3">{L('المنشورات السابقة تحتفظ باسم القيمة كما كان عند النشر.', 'Earlier posts keep the value name as it was when posted.')}</p>
      </div>
      <div>
        <div className="text-[13px] font-semibold mb-2">{L('الاحتفالات التلقائية', 'Automatic celebrations')}</div>
        <div className="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(200px,1fr))]">
          <Field label={L('الاحتفالات', 'Celebrations')} div>{onOff(c.enabled, b => setC({ enabled: b }))}</Field>
          <Field label={L('أعياد الميلاد', 'Birthdays')} hint={L('اليوم والشهر فقط، ويمكن للموظف إخفاؤه من «حسابي»', 'Day and month only; employees can hide it in My account')} div>{onOff(c.birthdays, b => setC({ birthdays: b }))}</Field>
          <Field label={L('سنويات الخدمة', 'Work anniversaries')} div>{onOff(c.anniversaries, b => setC({ anniversaries: b }))}</Field>
          <Field label={L('المنضمون الجدد', 'New joiners')} hint={L('في يوم المباشرة', 'On the start date')} div>{onOff(c.new_joiners, b => setC({ new_joiners: b }))}</Field>
          <Field label={L('الترقيات', 'Promotions')} hint={L('عند تغيير المسمى مع خيار الإعلان، وعند النقل الداخلي', 'On a title change with «announce», and internal moves')} div>{onOff(c.promotions, b => setC({ promotions: b }))}</Field>
        </div>
      </div>
      {e && !Object.keys(e.errors).length && <p className="text-danger text-[13px]">{e.message}</p>}
    </div>
  </Panel>
}
