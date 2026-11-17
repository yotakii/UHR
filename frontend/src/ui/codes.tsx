import { useL, useLang } from '../lib/store'
import { useLookup, type LookupItem } from '../lib/codes'
import { Field } from './kit'

export type CodeKey = 'job' | 'grade' | 'worker_category' | 'contract_type' | 'cost_center'
export const CODE_FIELDS: { k: CodeKey; list: string; ar: string; en: string }[] = [
  { k: 'job', list: 'jobs', ar: 'المسمى من الدليل', en: 'Job title (codebook)' }, { k: 'grade', list: 'grades', ar: 'الدرجة الوظيفية', en: 'Job grade' },
  { k: 'contract_type', list: 'contract_types', ar: 'نوع العقد', en: 'Contract type' }, { k: 'worker_category', list: 'worker_categories', ar: 'فئة العامل', en: 'Worker category' },
  { k: 'cost_center', list: 'cost_centers', ar: 'مركز الكلفة', en: 'Cost centre' },
]
export type CodeIds = Partial<Record<`${CodeKey}_id`, number | null>>

/** اختيار رمز من قائمة في دليل الترميز (لا نص حر حيث توجد قائمة — POL-CS-08) */
export function CodeSelect({ list, value, onChange, label, error, keep }: { list: string; value: number | null | undefined; onChange: (v: number | null, item?: LookupItem) => void; label: string; error?: string; keep?: { id: number; code: string; ar: string; en: string } | null }) {
  const L = useL(); const lang = useLang()
  const { data } = useLookup(list)
  const items = data ?? []
  // القيمة الحالية تبقى ظاهرة ولو عُطّل رمزها لاحقاً
  const extra = keep && !items.some(i => i.id === keep.id) ? [{ ...keep, parent_id: null, attrs: null, live: false }] : []
  return <Field label={label} error={error}><select className="field" value={value ?? ''} onChange={e => { const id = e.target.value ? Number(e.target.value) : null; onChange(id, items.find(i => i.id === id)) }}>
    <option value="">{items.length || extra.length ? '—' : L('لا رموز في القائمة', 'No codes in the list')}</option>
    {[...extra, ...items].map(i => <option key={i.id} value={i.id}>{i.code} · {lang === 'ar' ? i.ar : i.en}{i.live ? '' : ` (${L('معطّل', 'inactive')})`}</option>)}</select></Field>
}
