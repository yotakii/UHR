import { useEffect, useState } from 'react'
import { Plus, RotateCcw, Trash2 } from 'lucide-react'
import { useL, useLang } from '../../lib/store'
import { ApiError } from '../../lib/api'
import { useExitSettings, useSaveExitSettings, type ExitLists, type Item, type SectionDef } from '../../lib/exit'
import { Btn, Empty, PageHead, Panel, toast } from '../../ui/kit'

const ROLES: [string, string, string][] = [['manager', 'المدير المباشر', 'Direct manager'], ['admin', 'مدير النظام / تقانة المعلومات', 'System admin / IT'], ['executive', 'المدير التنفيذي', 'Executive'],
  ['ceo', 'المدير العام', 'General manager'], ['finance', 'المالية', 'Finance'], ['hr_director', 'الموارد البشرية', 'Human Resources']]

/**
 * إعدادات الخروج (M16، HR.01-03): أسباب الاستقالة، أنواع الإنهاء وأسباب الفصل، أسئلة الاستبيان ومقابلة الخروج،
 * وجهات تسليم العهدة F-07 وبنودها. المدد والمبالغ في «سياسات الدوام والإجازات» ← قواعد الخروج.
 */
export function ExitSettings() {
  const L = useL()
  const q = useExitSettings(); const save = useSaveExitSettings()
  const [v, setV] = useState<ExitLists | null>(null)
  useEffect(() => { if (q.data && !v) setV(q.data.data) }, [q.data, v])
  const err = save.error instanceof ApiError ? save.error : null
  if (!v || !q.data) return <div className="max-w-[1100px] mx-auto"><Panel><Empty title={q.isError ? L('تعذّر التحميل', 'Could not load') : L('جارٍ التحميل…', 'Loading…')} /></Panel></div>
  const dirty = JSON.stringify(v) !== JSON.stringify(q.data.data)
  return <div className="max-w-[1100px] mx-auto space-y-4">
    <PageHead title={L('إعدادات الخروج', 'Exit settings')} sub={L('القوائم المستخدمة في الاستقالة وإنهاء العقد ومقابلة الخروج وتسليم العهدة.', 'Lists used by resignations, terminations, exit interviews and clearance.')}
      actions={<div className="flex flex-wrap gap-2"><Btn icon={<RotateCcw size={15} />} onClick={() => setV(q.data!.defaults)}>{L('القيم الافتراضية', 'Defaults')}</Btn>
        <Btn kind="primary" disabled={!dirty || save.isPending} onClick={() => save.mutate(v, { onSuccess: r => { setV(r.data); toast(L('حُفظت الإعدادات', 'Settings saved')) } })}>{L('حفظ', 'Save')}</Btn></div>} />
    {err && <p role="alert" className="text-[13px] text-danger">{Object.values(err.errors)[0]?.[0] ?? err.message}</p>}
    <div className="grid lg:grid-cols-2 gap-4 items-start">
      <ListEditor title={L('أسباب الاستقالة', 'Resignation reasons')} rows={v.reasons} onChange={x => setV({ ...v, reasons: x })} locked={['other']} />
      <ListEditor title={L('أنواع إنهاء الخدمة (م37 وF-03)', 'End-of-service types')} rows={v.causes} onChange={x => setV({ ...v, causes: x })} locked={['dismissal']} />
      <ListEditor title={L('أسباب الفصل (F-03)', 'Dismissal reasons (F-03)')} rows={v.dismissal_reasons} onChange={x => setV({ ...v, dismissal_reasons: x })} locked={['other']} />
      <ListEditor title={L('أسئلة الاستبيان ومقابلة الخروج', 'Survey & exit-interview questions')} rows={v.questions} onChange={x => setV({ ...v, questions: x })} />
    </div>
    <Panel title={L('جهات تسليم العهدة F-07 وبنودها', 'Clearance F-07 sections and items')} sub={L('كل جهة توقّعها أدوارها (والموارد البشرية دائماً). البنود الآلية تكتمل من النظام.', 'Each section is signed by its role (and HR). Automatic items complete from the system.')}>
      <div className="space-y-3">{v.sections.map((s, i) => <SectionEditor key={i} s={s} onChange={x => setV({ ...v, sections: v.sections.map((y, j) => j === i ? x : y) })}
        onRemove={v.sections.length > 1 ? () => setV({ ...v, sections: v.sections.filter((_, j) => j !== i) }) : undefined} />)}</div>
      {v.sections.length < 12 && <Btn size="sm" className="mt-3" icon={<Plus size={14} />} onClick={() => setV({ ...v, sections: [...v.sections, { key: `section_${Date.now().toString(36)}`, ar: '', en: '', role: 'hr_director', items: [{ key: 'item_1', ar: '', en: '' }] }] })}>{L('جهة أخرى', 'Another section')}</Btn>}
    </Panel>
  </div>
}

function ListEditor({ title, rows, onChange, locked = [] }: { title: string; rows: Item[]; onChange: (r: Item[]) => void; locked?: string[] }) {
  const L = useL()
  const set = (i: number, p: Partial<Item>) => onChange(rows.map((r, j) => j === i ? { ...r, ...p } : r))
  return <Panel title={title} pad={false}><ul className="divide-y divide-line-2">{rows.map((r, i) => <li key={i} className="p-3 grid grid-cols-[1fr_auto] gap-2">
    <div className="grid sm:grid-cols-2 gap-2"><input className="field" placeholder={L('بالعربية', 'Arabic')} value={r.ar} maxLength={300} onChange={e => set(i, { ar: e.target.value })} />
      <input className="field ltr" placeholder="English" value={r.en} maxLength={300} onChange={e => set(i, { en: e.target.value })} /></div>
    {locked.includes(r.key) ? <span className="text-[11px] text-ink-3 self-center">{L('أساسي', 'Required')}</span>
      : <button className="p-2 rounded-lg hover:bg-sub text-ink-3 self-center" onClick={() => onChange(rows.filter((_, j) => j !== i))} aria-label={L('حذف', 'Remove')}><Trash2 size={15} /></button>}
  </li>)}</ul>
    <div className="p-3 border-t border-line-2">{rows.length < 30 && <Btn size="sm" icon={<Plus size={14} />} onClick={() => onChange([...rows, { key: `new_${Date.now().toString(36)}`, ar: '', en: '' }])}>{L('إضافة', 'Add')}</Btn>}</div>
  </Panel>
}

function SectionEditor({ s, onChange, onRemove }: { s: SectionDef; onChange: (s: SectionDef) => void; onRemove?: () => void }) {
  const L = useL(); const lang = useLang()
  const setItem = (i: number, p: Partial<SectionDef['items'][number]>) => onChange({ ...s, items: s.items.map((x, j) => j === i ? { ...x, ...p } : x) })
  return <div className="rounded-xl border border-line p-3 space-y-2">
    <div className="grid sm:grid-cols-[1fr_1fr_200px_auto] gap-2">
      <input className="field" placeholder={L('اسم الجهة', 'Section name')} value={s.ar} onChange={e => onChange({ ...s, ar: e.target.value })} />
      <input className="field ltr" placeholder="English" value={s.en} onChange={e => onChange({ ...s, en: e.target.value })} />
      <select className="field" value={s.role} onChange={e => onChange({ ...s, role: e.target.value })} aria-label={L('من يوقّع', 'Signed by')}>{ROLES.map(([k, ar, en]) => <option key={k} value={k}>{lang === 'ar' ? ar : en}</option>)}</select>
      {onRemove ? <button className="p-2 rounded-lg hover:bg-sub text-ink-3" onClick={onRemove} aria-label={L('حذف', 'Remove')}><Trash2 size={15} /></button> : <span />}
    </div>
    <ul className="space-y-1.5 ps-3 border-s border-line-2">{s.items.map((it, i) => <li key={i} className="grid sm:grid-cols-[1fr_1fr_auto_auto] gap-2 items-center">
      <input className="field" value={it.ar} placeholder={L('البند', 'Item')} onChange={e => setItem(i, { ar: e.target.value })} />
      <input className="field ltr" value={it.en} placeholder="English" onChange={e => setItem(i, { en: e.target.value })} />
      {it.auto ? <span className="text-[12px] text-info whitespace-nowrap">{L('آلي', 'Auto')}</span>
        : <label className="flex items-center gap-1.5 text-[12.5px] whitespace-nowrap"><input type="checkbox" checked={!!it.amount} onChange={e => setItem(i, { amount: e.target.checked || undefined })} />{L('بمبلغ', 'Amount')}</label>}
      {s.items.length > 1 && !it.auto ? <button className="p-1.5 rounded-lg hover:bg-sub text-ink-3" onClick={() => onChange({ ...s, items: s.items.filter((_, j) => j !== i) })} aria-label={L('حذف', 'Remove')}><Trash2 size={14} /></button> : <span />}
    </li>)}</ul>
    {s.items.length < 15 && <Btn size="sm" kind="ghost" icon={<Plus size={14} />} onClick={() => onChange({ ...s, items: [...s.items, { key: `item_${Date.now().toString(36)}`, ar: '', en: '' }] })}>{L('بند', 'Item')}</Btn>}
  </div>
}
