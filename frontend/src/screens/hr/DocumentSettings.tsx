import { useEffect, useState } from 'react'
import { Loader2, Plus, RotateCcw, Save, Trash2 } from 'lucide-react'
import { useL, useLang, bi } from '../../lib/store'
import { can, useMe } from '../../lib/session'
import { ApiError } from '../../lib/api'
import { useDocSettings, useSaveDocSettings, type DocSettings as Cfg } from '../../lib/employees'
import { Badge, Btn, PageHead, Panel, toast } from '../../ui/kit'

/**
 * إعدادات «الملفات الإضافية» للموظف (M16 لكل شركة — SRS §10.11، D-25): أنواع المستندات، الصيغ والحجم،
 * أيام التنبيه قبل الانتهاء، والأدوار التي ترى المستندات الحساسة. الحفظ مدقَّق.
 */
export function DocumentSettings() {
  const L = useL(); const lang = useLang()
  const editable = can(useMe().data, 'settings', 'edit')
  const { data } = useDocSettings()
  const save = useSaveDocSettings()
  const [c, setC] = useState<Cfg | null>(null)
  const [ext, setExt] = useState('')
  useEffect(() => { if (data) setC(data.data) }, [data])
  if (!data || !c) return <div className="grid place-items-center py-24"><Loader2 className="animate-spin text-brand" /></div>
  const err = save.error instanceof ApiError ? save.error : null
  const dirty = JSON.stringify(c) !== JSON.stringify(data.data)
  const fieldErr = (prefix: string) => err && Object.entries(err.errors).find(([k]) => k === prefix || k.startsWith(prefix + '.'))?.[1][0]
  const submit = () => save.mutate(c, { onSuccess: () => toast(L('حُفظت الإعدادات', 'Settings saved')), onError: x => toast((x as Error).message) })
  const addExt = () => { const v = ext.trim().toLowerCase().replace(/^\./, ''); if (v && !c.extensions.includes(v)) setC({ ...c, extensions: [...c.extensions, v] }); setExt('') }

  return <div className="max-w-[1100px] mx-auto space-y-5">
    <PageHead title={L('إعدادات الملفات الإضافية', 'Employee document settings')} sub={L('أنواع مستندات الموظفين، الصيغ والحجم المسموح، التنبيه قبل الانتهاء، ومن يرى المستندات الحساسة.', 'Employee document types, allowed formats and size, the expiry alert, and who sees sensitive documents.')}
      actions={editable && <><Btn icon={<RotateCcw size={16} />} onClick={() => setC(data.defaults)}>{L('القيم الافتراضية', 'Defaults')}</Btn>
        <Btn kind="primary" icon={save.isPending ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />} disabled={!dirty || save.isPending} onClick={submit}>{L('حفظ', 'Save')}</Btn></>} />
    {!editable && <Badge>{L('عرض فقط — التعديل لمدير الإعدادات', 'Read only — settings managers can edit')}</Badge>}

    <Panel title={L('أنواع المستندات', 'Document types')} sub={L('النوع المستخدم في مستندات قائمة لا يُحذف.', 'A type used by existing documents cannot be removed.')}
      action={editable && <Btn size="sm" icon={<Plus size={14} />} onClick={() => setC({ ...c, types: [...c.types, { key: '', ar: '', en: '' }] })}>{L('نوع جديد', 'New type')}</Btn>}>
      <div className="space-y-2">{c.types.map((t, i) => <div key={i} className="grid grid-cols-[1fr_auto] sm:grid-cols-[140px_1fr_1fr_auto] gap-2 items-center min-w-0">
        <input className="field ltr text-start num !h-9 col-span-2 sm:col-span-1" placeholder="code" disabled={!editable} value={t.key} onChange={v => setC({ ...c, types: c.types.map((x, j) => j === i ? { ...x, key: v.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '') } : x) })} aria-label={L('الرمز', 'Code')} />
        <input className="field !h-9" placeholder={L('الاسم بالعربية', 'Arabic name')} disabled={!editable} value={t.ar} onChange={v => setC({ ...c, types: c.types.map((x, j) => j === i ? { ...x, ar: v.target.value } : x) })} />
        <input className="field ltr text-start !h-9 max-sm:col-start-1" placeholder="English name" disabled={!editable} value={t.en} onChange={v => setC({ ...c, types: c.types.map((x, j) => j === i ? { ...x, en: v.target.value } : x) })} />
        {editable ? <button className="p-2 rounded hover:bg-sub text-ink-3 disabled:opacity-30 max-sm:row-start-2 max-sm:col-start-2" disabled={c.types.length < 2} onClick={() => setC({ ...c, types: c.types.filter((_, j) => j !== i) })} aria-label={L('حذف', 'Remove')}><Trash2 size={15} /></button> : <span />}
      </div>)}</div>
      {fieldErr('types') && <div role="alert" className="text-[13px] text-danger mt-2">{fieldErr('types')}</div>}
    </Panel>

    <div className="grid md:grid-cols-2 gap-5">
      <Panel title={L('الصيغ والحجم', 'Formats & size')}>
        <div className="flex flex-wrap gap-1.5 mb-3">{c.extensions.map(x => <span key={x} className="inline-flex items-center gap-1 rounded-full border border-line px-2.5 h-7 text-[12.5px] ltr uppercase">{x}
          {editable && <button onClick={() => setC({ ...c, extensions: c.extensions.filter(y => y !== x) })} disabled={c.extensions.length < 2} className="text-ink-3 hover:text-danger disabled:opacity-30" aria-label={L('حذف', 'Remove')}>×</button>}</span>)}</div>
        {editable && <form className="flex gap-2 mb-3" onSubmit={v => { v.preventDefault(); addExt() }}><input className="field ltr text-start !h-9 flex-1 min-w-0" placeholder="xlsx" value={ext} onChange={v => setExt(v.target.value)} /><Btn size="sm" type="submit" icon={<Plus size={14} />}>{L('إضافة', 'Add')}</Btn></form>}
        {fieldErr('extensions') && <div role="alert" className="text-[13px] text-danger mb-2">{fieldErr('extensions')}</div>}
        <label className="flex items-center justify-between gap-3 py-1.5 text-[13.5px]"><span>{L('الحجم الأقصى للملف', 'Max file size')}</span>
          <span className="flex items-center gap-1.5"><input type="number" min={1} max={50} disabled={!editable} value={c.max_mb} onChange={v => setC({ ...c, max_mb: Number(v.target.value) })} className="field !h-9 !w-20 num text-center" /><span className="text-[12px] text-ink-3">MB</span></span></label>
        {fieldErr('max_mb') && <div role="alert" className="text-[13px] text-danger">{fieldErr('max_mb')}</div>}
      </Panel>
      <Panel title={L('التنبيه والحساسية', 'Alerts & sensitivity')}>
        <label className="flex items-center justify-between gap-3 py-1.5 text-[13.5px]"><span>{L('التنبيه قبل انتهاء المستند', 'Alert before a document expires')}</span>
          <span className="flex items-center gap-1.5"><input type="number" min={0} max={365} disabled={!editable} value={c.alert_days} onChange={v => setC({ ...c, alert_days: Number(v.target.value) })} className="field !h-9 !w-20 num text-center" /><span className="text-[12px] text-ink-3">{L('يوماً', 'days')}</span></span></label>
        {fieldErr('alert_days') && <div role="alert" className="text-[13px] text-danger">{fieldErr('alert_days')}</div>}
        <div className="text-[13.5px] mt-3 mb-1.5">{L('أدوار ترى المستندات الحساسة', 'Roles that see sensitive documents')}</div>
        <div className="flex flex-wrap gap-1.5">{data.roles.map(r => { const on = c.sensitive_roles.includes(r.key)
          return <button key={r.key} disabled={!editable} aria-pressed={on} onClick={() => setC({ ...c, sensitive_roles: on ? c.sensitive_roles.filter(x => x !== r.key) : [...c.sensitive_roles, r.key] })}
            className={`rounded-full border px-3 h-8 text-[12.5px] font-semibold ${on ? 'border-brand bg-brand-soft text-brand' : 'border-line text-ink-2'} disabled:cursor-default`}>{bi(r, lang)}</button> })}</div>
        <p className="text-[12px] text-ink-3 mt-2">{L('والموظف يرى مستنداته دائماً.', 'Employees always see their own documents.')}</p>
      </Panel>
    </div>
  </div>
}
