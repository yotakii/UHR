import { useEffect, useState } from 'react'
import { Loader2, RotateCcw } from 'lucide-react'
import { useL, useLang } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { REACTIONS, useSaveSpaceSettings, useSpaceSettings, type SpaceSettingsData } from '../../lib/space'
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
  </div>
}
