import { useState } from 'react'
import { Loader2, Pencil, Plus } from 'lucide-react'
import { useL, useLang, bi } from '../../../lib/store'
import { can, useMe } from '../../../lib/session'
import { ApiError } from '../../../lib/api'
import { deptTone } from '../../../lib/employees'
import { useDepartments, useSaveDepartment, type Dept, type DeptInput } from '../../../lib/admin'
import { Badge, Btn, Field, Modal, PageHead, Panel, Seg, toast } from '../../../ui/kit'

/** M16 «الإدارات»: الرمز والاسم، رئيس الإدارة، الملاك العددي المعتمد مقابل الموجود، لون الهيكل، الترتيب، الإيقاف */
export function Departments() {
  const L = useL(); const lang = useLang()
  const editable = can(useMe().data, 'org', 'edit')
  const q = useDepartments(); const d = q.data
  const [edit, setEdit] = useState<Dept | 'new' | null>(null)
  if (!d) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  return <div className="max-w-[1100px] mx-auto space-y-5">
    <PageHead title={L('الإدارات', 'Departments')} sub={L('الملاك العددي المعتمد يظهر في قرار التعيين F-31، واللون في الهيكل التنظيمي.', 'The approved headcount shows on the appointment decision F-31; the colour in the org chart.')}
      actions={editable && <Btn kind="primary" icon={<Plus size={16} />} onClick={() => setEdit('new')}>{L('إدارة جديدة', 'New department')}</Btn>} />
    <Panel pad={false}>
      <table className="tbl"><thead><tr><th>{L('الإدارة', 'Department')}</th><th className="hidden md:table-cell">{L('رئيس الإدارة', 'Head')}</th><th>{L('الملاك', 'Headcount')}</th><th className="hidden sm:table-cell">{L('الحالة', 'Status')}</th><th /></tr></thead>
        <tbody>{d.data.map(x => <tr key={x.id} className={x.active ? '' : 'opacity-60'}>
          <td><div className="flex items-center gap-2"><span className={`size-2.5 rounded-full shrink-0 ${deptTone(x.color)}`} /><span className="font-semibold">{bi(x, lang)}</span></div><div className="text-[12px] text-ink-3 ltr text-start">{x.code}</div></td>
          <td className="hidden md:table-cell">{x.head ? bi(x.head, lang) : <span className="text-ink-3">—</span>}</td>
          <td className="num"><span className={x.headcount && x.current > x.headcount ? 'text-danger font-semibold' : ''}>{x.current}</span> / {x.headcount || '—'}</td>
          <td className="hidden sm:table-cell">{x.active ? <Badge tone="brand">{L('فعّالة', 'Active')}</Badge> : <Badge>{L('موقوفة', 'Inactive')}</Badge>}</td>
          <td className="text-end">{editable && <button className="p-1.5 rounded-lg hover:bg-sub text-ink-3" onClick={() => setEdit(x)} aria-label={L('تعديل', 'Edit')}><Pencil size={15} /></button>}</td>
        </tr>)}</tbody></table>
    </Panel>
    {edit && <DeptForm dept={edit === 'new' ? null : edit} colors={d.colors} employees={d.employees} onClose={() => setEdit(null)} />}
  </div>
}

function DeptForm({ dept, colors, employees, onClose }: { dept: Dept | null; colors: string[]; employees: { id: number; code: string; ar: string; en: string; department_id: number | null }[]; onClose: () => void }) {
  const L = useL(); const lang = useLang()
  const save = useSaveDepartment()
  const [v, setV] = useState<DeptInput>({ code: dept?.code ?? '', ar: dept?.ar ?? '', en: dept?.en ?? '', headcount: dept?.headcount ?? 0, color: dept?.color ?? 'brand', sort: dept?.sort, active: dept?.active ?? true, head_id: dept?.head?.id ?? null })
  const err = save.error instanceof ApiError ? save.error : null
  const heads = dept ? [...employees.filter(e => e.department_id === dept.id), ...employees.filter(e => e.department_id !== dept.id)] : employees
  return <Modal open onClose={onClose} title={dept ? L('تعديل الإدارة', 'Edit department') : L('إدارة جديدة', 'New department')}
    footer={<><Btn onClick={onClose}>{L('إلغاء', 'Cancel')}</Btn><Btn kind="primary" disabled={save.isPending} onClick={() => save.mutate({ id: dept?.id, v }, { onSuccess: () => { toast(L('حُفظت الإدارة', 'Department saved')); onClose() } })}>{L('حفظ', 'Save')}</Btn></>}>
    <div className="grid sm:grid-cols-2 gap-4">
      <Field label={L('الاسم بالعربية', 'Arabic name')} req error={err?.field('ar')}><input className="field" value={v.ar} onChange={e => setV({ ...v, ar: e.target.value })} /></Field>
      <Field label={L('الاسم بالإنكليزية', 'English name')} req error={err?.field('en')}><input className="field ltr text-start" value={v.en} onChange={e => setV({ ...v, en: e.target.value })} /></Field>
      <Field label={L('الرمز', 'Code')} req hint="a-z 0-9 _" error={err?.field('code')}><input className="field ltr text-start" value={v.code} onChange={e => setV({ ...v, code: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '') })} /></Field>
      <Field label={L('الملاك العددي المعتمد', 'Approved headcount')} error={err?.field('headcount')}><input type="number" min={0} className="field num" value={v.headcount} onChange={e => setV({ ...v, headcount: Number(e.target.value) })} /></Field>
      <Field label={L('رئيس الإدارة', 'Head of department')} className="sm:col-span-2" error={err?.field('head_id')}><select className="field" value={v.head_id ?? ''} onChange={e => setV({ ...v, head_id: e.target.value ? Number(e.target.value) : null })}>
        <option value="">—</option>{heads.map(e => <option key={e.id} value={e.id}>{bi(e, lang)} · {e.code}</option>)}</select></Field>
      <Field label={L('اللون في الهيكل', 'Org chart colour')} div><div className="flex gap-2">{colors.map(c => <button key={c} type="button" onClick={() => setV({ ...v, color: c })} aria-label={c}
        className={`size-8 rounded-full ${deptTone(c)} ${v.color === c ? 'ring-2 ring-offset-2 ring-offset-surface ring-brand' : ''}`} />)}</div></Field>
      <Field label={L('الحالة', 'Status')} div><Seg value={v.active ? 'on' : 'off'} onChange={x => setV({ ...v, active: x === 'on' })} options={[{ v: 'on', label: L('فعّالة', 'Active') }, { v: 'off', label: L('موقوفة', 'Inactive') }]} /></Field>
    </div>
    {err && !Object.keys(err.errors).length && <div role="alert" className="mt-4 rounded-lg bg-danger-soft text-danger text-[13px] px-3 py-2">{err.message}</div>}
  </Modal>
}
